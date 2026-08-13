<?php
require_once 'incl/dbconn.php';
require_once 'incl/geo.php';
require_employee();
csrf_check();

if (!isset($_POST['visit_id'])) {
    header('Location: employee_dashboard.php');
    exit;
}

$visit_id = (int)$_POST['visit_id'];
$user_id  = current_user_id();

// Confirm the visit belongs to this tech and is not already completed.
// Also pull the site's stored coordinates — location is recorded automatically.
$stmt = $conn->prepare("
    SELECT v.visit_id, v.status, s.latitude, s.longitude
    FROM visits v
    JOIN sites s ON s.site_id = v.site_id
    WHERE v.visit_id = ? AND v.assigned_to_user_id = ?
");
$stmt->bind_param('ii', $visit_id, $user_id);
$stmt->execute();
$visit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$visit || $visit['status'] == 'completed') {
    header('Location: employee_dashboard.php');
    exit;
}

// ── Maintenance type declared by the technician ───────────────────────────────
$maintenance_type = in_array($_POST['maintenance_type'] ?? '', ['active', 'passive', 'housekeeping'])
    ? $_POST['maintenance_type']
    : 'active';

// ── Save Progress (draft) vs Submit & Complete (final) ────────────────────────
$is_final     = isset($_POST['submit_final']);
$now_dt       = date('Y-m-d H:i:s');
$is_submitted = $is_final ? 1 : 0;
$submitted_at = $is_final ? $now_dt : null;   // only set when finalised
$updated_at   = $now_dt;                       // every save stamps this

// ── General capture fields ────────────────────────────────────────────────────
// Access reference is the tech's note; location & time are captured automatically
// (server-side) so they cannot be manually altered.
$access_ref   = substr(trim($_POST['access_ref'] ?? ''), 0, 50);
$capture_lat  = $visit['latitude']  !== null ? (string)$visit['latitude']  : '';
$capture_lon  = $visit['longitude'] !== null ? (string)$visit['longitude'] : '';
$capture_time = date('H:i');

// ── Antenna fields ─────────────────────────────────────────────────────────────
$a = [];
foreach (['s1', 's2', 's3'] as $s) {
    foreach (['azimuth', 'etilt', 'mtilt', 'height'] as $f) {
        $a[$s . '_' . $f] = substr(trim($_POST['ant_' . $s . '_' . $f] ?? ''), 0, 30);
    }
}
$gps_lat = substr(trim($_POST['gps_lat'] ?? ''), 0, 30);
$gps_lon = substr(trim($_POST['gps_lon'] ?? ''), 0, 30);
$general_comments = substr(trim($_POST['general_comments'] ?? ''), 0, 5000);

// ── Silently captured device location (hidden inputs, admin-only visibility) ──
// Separate from the manual GPS fields above. Never blocks the submit: if the
// browser gave nothing we store NULLs and keep any previously captured point.
$submit_lat = substr(trim($_POST['submit_lat'] ?? ''), 0, 30);
$submit_lon = substr(trim($_POST['submit_lon'] ?? ''), 0, 30);
$submit_acc = substr(trim($_POST['submit_accuracy'] ?? ''), 0, 20);
if ($submit_lat === '' || $submit_lon === '') {
    $submit_lat = null; $submit_lon = null; $submit_acc = null; $submit_captured_at = null;
} else {
    $submit_captured_at = $now_dt;
}

// ── On-site distance check (Change 2 — anti-fraud location audit) ─────────────
// Compute how far the technician's device was from the tower when they submitted.
// onsite_radius_m comes from payroll_settings so the office can tune it.
$ps_row = $conn->query("SELECT onsite_radius_m, min_photos FROM payroll_settings WHERE id = 1")->fetch_assoc();
$onsite_radius_m = (int) ($ps_row['onsite_radius_m'] ?? 500);
$min_photos_req  = (int) ($ps_row['min_photos']      ?? 1);

$site_lat = $visit['latitude']  !== null && $visit['latitude']  !== '' ? (string)$visit['latitude']  : null;
$site_lon = $visit['longitude'] !== null && $visit['longitude'] !== '' ? (string)$visit['longitude'] : null;

if ($submit_lat !== null && $submit_lon !== null && $site_lat !== null && $site_lon !== null) {
    $submit_distance_m      = haversine_m($submit_lat, $submit_lon, $site_lat, $site_lon);
    $submit_location_status = classify_onsite($submit_distance_m, $onsite_radius_m);
} else {
    // No GPS from device or site has no coords — store NULLs so COALESCE
    // in the ON DUPLICATE KEY UPDATE clause preserves any previously captured value.
    $submit_distance_m      = null;
    $submit_location_status = null;
}

// ── Build JSON for each inspection section ─────────────────────────────────────
$valid_statuses = ['OK', 'Faulty', 'N/A'];
$json = [];
foreach (['equipment', 'generator', 'transmission', 'security', 'container', 'electrical'] as $sec) {
    $sec_data  = is_array($_POST[$sec] ?? null) ? $_POST[$sec] : [];
    $json[$sec] = [];
    foreach ($sec_data as $raw_field => $vals) {
        $field = preg_replace('/[^a-z0-9_]/', '', $raw_field); // sanitise key
        if ($field === '') continue;
        $st = in_array($vals['status'] ?? '', $valid_statuses) ? $vals['status'] : 'N/A';
        $json[$sec][$field] = [
            'status'  => $st,
            'remarks' => substr(trim($vals['remarks'] ?? ''), 0, 500),
        ];
    }
}

// ── Handle 4 site photo uploads ───────────────────────────────────────────────
$allowed_ext    = ['jpg', 'jpeg', 'png', 'gif'];
$photo_filenames = [];
for ($p = 1; $p <= 4; $p++) {
    $photo_filenames[$p] = '';
    if (isset($_FILES['photo_' . $p]) && $_FILES['photo_' . $p]['error'] == 0) {
        $file = $_FILES['photo_' . $p];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_ext) && $file['size'] <= 10 * 1024 * 1024) {
            $fname = time() . '_' . bin2hex(random_bytes(6)) . '_site' . $p . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], 'uploads/' . $fname)) {
                $photo_filenames[$p] = $fname;
            }
        }
    }
}

// ── Minimum photos enforcement (Change 3) — final submit only ─────────────────
// Count photos already saved for this visit plus any just uploaded this request.
// If the total is below the threshold we save as a draft instead of finalising.
if ($is_final) {
    $new_photo_count = count(array_filter($photo_filenames, fn ($f) => $f !== ''));
    $ep_stmt = $conn->prepare("SELECT COUNT(*) AS n FROM visit_photos WHERE visit_id = ? AND photo_type = 'other'");
    $ep_stmt->bind_param('i', $visit_id);
    $ep_stmt->execute();
    $existing_photo_count = (int) $ep_stmt->get_result()->fetch_assoc()['n'];
    $ep_stmt->close();
    $total_photos = $existing_photo_count + $new_photo_count;

    if ($total_photos < $min_photos_req) {
        // Downgrade to draft — photo requirement not met
        $is_final     = false;
        $is_submitted = 0;
        $submitted_at = null;
        flash("Please attach at least {$min_photos_req} photo(s) of the site before submitting.");
    }
}

// ── Upsert maintenance_forms ───────────────────────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO maintenance_forms
        (visit_id, access_ref, capture_lat, capture_lon, capture_time,
         ant_s1_azimuth, ant_s1_etilt, ant_s1_mtilt, ant_s1_height,
         ant_s2_azimuth, ant_s2_etilt, ant_s2_mtilt, ant_s2_height,
         ant_s3_azimuth, ant_s3_etilt, ant_s3_mtilt, ant_s3_height,
         gps_lat, gps_lon,
         equipment_json, generator_json, transmission_json,
         security_json, container_json, electrical_json,
         general_comments, is_submitted, submitted_at, updated_at,
         submit_lat, submit_lon, submit_accuracy, submit_captured_at,
         submit_distance_m, submit_location_status)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
        access_ref=VALUES(access_ref), capture_lat=VALUES(capture_lat),
        capture_lon=VALUES(capture_lon), capture_time=VALUES(capture_time),
        ant_s1_azimuth=VALUES(ant_s1_azimuth), ant_s1_etilt=VALUES(ant_s1_etilt),
        ant_s1_mtilt=VALUES(ant_s1_mtilt),     ant_s1_height=VALUES(ant_s1_height),
        ant_s2_azimuth=VALUES(ant_s2_azimuth), ant_s2_etilt=VALUES(ant_s2_etilt),
        ant_s2_mtilt=VALUES(ant_s2_mtilt),     ant_s2_height=VALUES(ant_s2_height),
        ant_s3_azimuth=VALUES(ant_s3_azimuth), ant_s3_etilt=VALUES(ant_s3_etilt),
        ant_s3_mtilt=VALUES(ant_s3_mtilt),     ant_s3_height=VALUES(ant_s3_height),
        gps_lat=VALUES(gps_lat), gps_lon=VALUES(gps_lon),
        equipment_json=VALUES(equipment_json), generator_json=VALUES(generator_json),
        transmission_json=VALUES(transmission_json), security_json=VALUES(security_json),
        container_json=VALUES(container_json), electrical_json=VALUES(electrical_json),
        general_comments=VALUES(general_comments),
        is_submitted=VALUES(is_submitted), submitted_at=VALUES(submitted_at), updated_at=VALUES(updated_at),
        submit_lat=COALESCE(VALUES(submit_lat), submit_lat),
        submit_lon=COALESCE(VALUES(submit_lon), submit_lon),
        submit_accuracy=COALESCE(VALUES(submit_accuracy), submit_accuracy),
        submit_captured_at=COALESCE(VALUES(submit_captured_at), submit_captured_at),
        submit_distance_m=COALESCE(VALUES(submit_distance_m), submit_distance_m),
        submit_location_status=COALESCE(VALUES(submit_location_status), submit_location_status)
");
// json_encode() results must be stored in variables — bind_param takes them by reference
$equipment_json    = json_encode($json['equipment']);
$generator_json    = json_encode($json['generator']);
$transmission_json = json_encode($json['transmission']);
$security_json     = json_encode($json['security']);
$container_json    = json_encode($json['container']);
$electrical_json   = json_encode($json['electrical']);

// visit_id(i) + 25 strings(s) + is_submitted(i) + submitted_at/updated_at(ss)
// + submit_lat/lon/acc/captured_at(ssss) + submit_distance_m(i) + submit_location_status(s)
$types = 'i' . str_repeat('s', 25) . 'iss' . 'ssss' . 'is';
$stmt->bind_param($types,
    $visit_id,
    $access_ref, $capture_lat, $capture_lon, $capture_time,
    $a['s1_azimuth'], $a['s1_etilt'], $a['s1_mtilt'], $a['s1_height'],
    $a['s2_azimuth'], $a['s2_etilt'], $a['s2_mtilt'], $a['s2_height'],
    $a['s3_azimuth'], $a['s3_etilt'], $a['s3_mtilt'], $a['s3_height'],
    $gps_lat, $gps_lon,
    $equipment_json, $generator_json, $transmission_json,
    $security_json, $container_json, $electrical_json,
    $general_comments, $is_submitted, $submitted_at, $updated_at,
    $submit_lat, $submit_lon, $submit_acc, $submit_captured_at,
    $submit_distance_m, $submit_location_status
);
$stmt->execute();
$stmt->close();

// ── Save site photos to visit_photos ──────────────────────────────────────────
for ($p = 1; $p <= 4; $p++) {
    if ($photo_filenames[$p] !== '') {
        $caption = substr(trim($_POST['photo_caption_' . $p] ?? ''), 0, 200);
        $stmt = $conn->prepare("
            INSERT INTO visit_photos (visit_id, photo_filename, photo_type, caption, uploaded_by_user_id)
            VALUES (?, ?, 'other', ?, ?)
        ");
        $stmt->bind_param('issi', $visit_id, $photo_filenames[$p], $caption, $user_id);
        $stmt->execute();
        $stmt->close();
    }
}

// ── Update visit status & save tech-declared maintenance type ─────────────────
if ($is_final) {
    // Final submit → mark the whole visit completed
    $stmt = $conn->prepare("UPDATE visits SET status = 'completed', completed_at = ?, maintenance_type = ? WHERE visit_id = ?");
    $stmt->bind_param('ssi', $now_dt, $maintenance_type, $visit_id);
    $stmt->execute();
    $stmt->close();

    flash('Maintenance form submitted. Thank you!');
    header('Location: employee_dashboard.php?submitted=1');
    exit;
}

// Draft save → keep the visit open (in progress), don't complete it
$stmt = $conn->prepare("UPDATE visits SET status = 'in_progress', maintenance_type = ? WHERE visit_id = ? AND status <> 'completed'");
$stmt->bind_param('si', $maintenance_type, $visit_id);
$stmt->execute();
$stmt->close();

header('Location: employee_visit.php?visit_id=' . $visit_id . '&saved=1');
exit;
