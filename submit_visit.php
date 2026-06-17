<?php
require_once 'incl/dbconn.php';
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
$maintenance_type = in_array($_POST['maintenance_type'] ?? '', ['active', 'passive'])
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
         general_comments, is_submitted, submitted_at, updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
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
        is_submitted=VALUES(is_submitted), submitted_at=VALUES(submitted_at), updated_at=VALUES(updated_at)
");
// json_encode() results must be stored in variables — bind_param takes them by reference
$equipment_json    = json_encode($json['equipment']);
$generator_json    = json_encode($json['generator']);
$transmission_json = json_encode($json['transmission']);
$security_json     = json_encode($json['security']);
$container_json    = json_encode($json['container']);
$electrical_json   = json_encode($json['electrical']);

$types = 'i' . str_repeat('s', 25) . 'iss';   // visit_id + 25 strings + is_submitted, submitted_at, updated_at
$stmt->bind_param($types,
    $visit_id,
    $access_ref, $capture_lat, $capture_lon, $capture_time,
    $a['s1_azimuth'], $a['s1_etilt'], $a['s1_mtilt'], $a['s1_height'],
    $a['s2_azimuth'], $a['s2_etilt'], $a['s2_mtilt'], $a['s2_height'],
    $a['s3_azimuth'], $a['s3_etilt'], $a['s3_mtilt'], $a['s3_height'],
    $gps_lat, $gps_lon,
    $equipment_json, $generator_json, $transmission_json,
    $security_json, $container_json, $electrical_json,
    $general_comments, $is_submitted, $submitted_at, $updated_at
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
