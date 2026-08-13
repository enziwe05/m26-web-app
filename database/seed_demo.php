<?php
/*
 * M26 Demo Data Seeder v2 — database/seed_demo.php
 *
 * CLI:
 *   php database/seed_demo.php          — seed ~250 visits, time entries, vehicles
 *   php database/seed_demo.php clear    — remove ONLY demo data (FK-safe order)
 *
 * Browser (admin only):
 *   /database/seed_demo.php?action=seed   — seed
 *   /database/seed_demo.php?action=clear  — clear
 *
 * Demo row markers:
 *   visits.description           LIKE '%[demo]%'
 *   time_entries.location_flag   LIKE 'demo%'
 *   vehicles.fleet_number        LIKE 'FL-%'  (inspections cascade)
 */

$cli = (PHP_SAPI === 'cli');

// Ensure cwd = app root so require_once paths resolve correctly
chdir(dirname(__DIR__));

require_once 'incl/dbconn.php';
require_once 'incl/geo.php';

if (!$cli) {
    // Browser: admin-only gate
    require_admin();
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>M26 Seed</title>"
       . "<link rel='stylesheet' href='../css/styles.css?v=24'></head>"
       . "<body><div style='max-width:800px;margin:40px auto;padding:0 20px;font-family:monospace;font-size:13px;'>\n";
}

// ── Output helper ──────────────────────────────────────────────────────────────
function out(string $line): void
{
    if (PHP_SAPI === 'cli') {
        echo $line . PHP_EOL;
    } else {
        echo htmlspecialchars($line) . "<br>\n";
        @ob_flush(); @flush();
    }
}

// ── Mode ───────────────────────────────────────────────────────────────────────
$mode = 'seed';
if ($cli) {
    $mode = in_array('clear', array_slice($argv ?? [], 1)) ? 'clear' : 'seed';
} else {
    $mode = ($_GET['action'] ?? 'seed') === 'clear' ? 'clear' : 'seed';
}

// ══════════════════════════════════════════════════════════════════════════════
//  CLEAR MODE
// ══════════════════════════════════════════════════════════════════════════════
if ($mode === 'clear') {
    out('=== CLEAR: removing demo data ===');

    // 1. Photos attached to demo visits
    $conn->query(
        "DELETE vp FROM visit_photos vp
           JOIN visits v ON v.visit_id = vp.visit_id
          WHERE v.description LIKE '%[demo]%'"
    );
    out('  visit_photos deleted:       ' . $conn->affected_rows);

    // 2. Maintenance forms for demo visits
    $conn->query(
        "DELETE mf FROM maintenance_forms mf
           JOIN visits v ON v.visit_id = mf.visit_id
          WHERE v.description LIKE '%[demo]%'"
    );
    out('  maintenance_forms deleted:  ' . $conn->affected_rows);

    // 3. Demo visits (cascade keeps real visit 31/33)
    $conn->query("DELETE FROM visits WHERE description LIKE '%[demo]%'");
    out('  visits deleted:             ' . $conn->affected_rows);

    // 4. Demo time entries (tagged location_flag LIKE 'demo%')
    $conn->query("DELETE FROM time_entries WHERE location_flag LIKE 'demo%'");
    out('  time_entries deleted:       ' . $conn->affected_rows);

    // 5. Demo vehicles (fleet FL-*) — inspections removed first
    $conn->query(
        "DELETE vi FROM vehicle_inspections vi
           JOIN vehicles v ON v.vehicle_id = vi.vehicle_id
          WHERE v.fleet_number LIKE 'FL-%'"
    );
    out('  vehicle_inspections deleted:' . $conn->affected_rows);
    $conn->query("DELETE FROM vehicles WHERE fleet_number LIKE 'FL-%'");
    out('  vehicles deleted:           ' . $conn->affected_rows);

    out('=== CLEAR COMPLETE ===');
    if (!$cli) echo "</div></body></html>\n";
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
//  SEED MODE
// ══════════════════════════════════════════════════════════════════════════════
out('=== M26 DEMO SEEDER v2 — starting ===');

// Reproducible randomness
mt_srand(20260722);

// ── Fixed date constants ───────────────────────────────────────────────────────
// "Today" fixed at 2026-07-22
$today_ts     = mktime(0, 0, 0, 7, 22, 2026);
$today_str    = '2026-07-22';

// Visit window: 9 weeks back
$visit_start_ts = $today_ts - (9 * 7 * 86400);  // 2026-05-20

// Time-entry window: 8 weeks back
$te_start_ts    = $today_ts - (8 * 7 * 86400);  // 2026-05-27

$june_start = '2026-06-01';
$june_end   = '2026-06-30';

// ── Users ──────────────────────────────────────────────────────────────────────
$employee_ids = [3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 20];
$admin_id     = 1;

// 4 night-shift workers
$night_ids    = [3, 9, 14, 18];

$visit_types  = ['Maintenance', 'Emergency Repair', 'Installation', 'Site Inspection', 'Other'];
$maint_types  = ['active', 'passive'];   // DB enum; 'housekeeping' not in enum yet

// ──────────────────────────────────────────────────────────────────────────────
// STEP 1: Set shift_types on all 17 employees
// ──────────────────────────────────────────────────────────────────────────────
out('');
out('STEP 1 — Employee shift types');

$emp_shift  = [];  // uid => 'day'|'night'

foreach ($employee_ids as $uid) {
    $shift  = in_array($uid, $night_ids, true) ? 'night' : 'day';

    $emp_shift[$uid]  = $shift;

    $s = $conn->prepare("UPDATE users SET shift_type=? WHERE user_id=?");
    $s->bind_param('si', $shift, $uid);
    $s->execute();
    $s->close();
}
out('  Updated ' . count($employee_ids) . ' employees.');

// ──────────────────────────────────────────────────────────────────────────────
// STEP 2: Demo photo stubs — copy images/m26.png to uploads/demo_site_N.jpg
// ──────────────────────────────────────────────────────────────────────────────
out('');
out('STEP 2 — Demo photo stubs');

$demo_photos = [];
for ($i = 1; $i <= 5; $i++) {
    $fn   = "demo_site_{$i}.jpg";
    $dest = 'uploads/' . $fn;
    if (file_exists('images/m26.png') && !file_exists($dest)) {
        copy('images/m26.png', $dest);
    }
    if (file_exists($dest)) {
        $demo_photos[] = $fn;
    }
}
out('  Photo stubs ready: ' . count($demo_photos));

// ──────────────────────────────────────────────────────────────────────────────
// STEP 3: ~250 demo visits over 9 weeks
// ──────────────────────────────────────────────────────────────────────────────
out('');
out('STEP 3 — Creating ~250 demo visits');

// All sites that have valid coordinates
$site_rows = $conn->query(
    "SELECT site_id, latitude, longitude FROM sites
     WHERE latitude  IS NOT NULL AND latitude  != ''
       AND longitude IS NOT NULL AND longitude != ''
       AND CAST(latitude AS DECIMAL(10,7)) != 0
     ORDER BY site_id"
)->fetch_all(MYSQLI_ASSOC);

if (empty($site_rows)) {
    out('ERROR: No sites with coordinates. Aborting.');
    exit(1);
}
out('  ' . count($site_rows) . ' sites with coordinates available.');

// Status spread: 70 % completed, 15 % in_progress, 15 % assigned
$statuses = array_merge(
    array_fill(0, 175, 'completed'),
    array_fill(0,  38, 'in_progress'),
    array_fill(0,  37, 'assigned')
);
shuffle($statuses);

// Visit INSERT (10 params: iiisssssss)
$vi = $conn->prepare(
    "INSERT INTO visits
     (site_id, assigned_to_user_id, created_by_user_id,
      visit_type, maintenance_type, description,
      scheduled_date, status, created_at, completed_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);

$visit_meta  = [];  // vid => [site_lat, site_lon, assigned_uid, status, completed_at]
$count_v     = 0;
$n_target    = count($statuses);   // 250

for ($i = 0; $i < $n_target; $i++) {
    $site   = $site_rows[mt_rand(0, count($site_rows) - 1)];
    $uid    = $employee_ids[mt_rand(0, count($employee_ids) - 1)];
    $vtype  = $visit_types[mt_rand(0, count($visit_types) - 1)];
    $mtype  = $maint_types[mt_rand(0, 1)];
    $status = $statuses[$i];

    // Scheduled between visit_start and (today - 1 day)
    $sched_ts   = mt_rand($visit_start_ts, $today_ts - 86400);
    $sched_date = date('Y-m-d', $sched_ts);
    $created_at = date('Y-m-d H:i:s', $sched_ts - mt_rand(0, 3 * 86400));

    $completed_at = null;
    if ($status === 'completed') {
        $ct = min($sched_ts + mt_rand(3600, 3 * 86400), $today_ts);
        $completed_at = date('Y-m-d H:i:s', $ct);
    }

    $desc = '[demo] ' . $vtype . ' (site ' . $site['site_id'] . ')';

    $vi->bind_param(
        'iiisssssss',
        $site['site_id'], $uid, $admin_id,
        $vtype, $mtype, $desc,
        $sched_date, $status, $created_at, $completed_at
    );
    $vi->execute();
    $vid = $conn->insert_id;

    $visit_meta[$vid] = [
        'site_lat'     => (float)$site['latitude'],
        'site_lon'     => (float)$site['longitude'],
        'assigned_uid' => $uid,
        'status'       => $status,
        'completed_at' => $completed_at,
    ];
    $count_v++;
}
$vi->close();
out("  Inserted $count_v visits.");

// ──────────────────────────────────────────────────────────────────────────────
// STEP 4: maintenance_forms for completed visits
// ──────────────────────────────────────────────────────────────────────────────
out('');
out('STEP 4 — Maintenance forms');

$form_sections  = require 'incl/form_sections.php';
$onsite_radius  = (int)($conn->query(
    "SELECT onsite_radius_m FROM payroll_settings WHERE id=1"
)->fetch_assoc()['onsite_radius_m'] ?? 500);

$statuses_chk   = ['OK', 'N/A', 'Faulty'];
$photo_captions = ['Overview of site', 'Equipment cabinet',
                   'Antenna array', 'Generator area', 'Site entrance',
                   'Cable management'];

// 34 parameters — type string built from str_repeat to avoid counting errors:
// i(visit_id) + s×4(access/lat/lon/time) + s×4(ant1) + s×4(ant2) + s×4(ant3)
// + s×2(gps) + s×6(JSON) + s(comments)
// + i(is_submitted) + s×2(submitted/updated) + s×3(sub_lat/lon/acc)
// + i(dist_m) + s(loc_status)
$form_types = 'i'
    . str_repeat('s', 4)   // access_ref capture_lat capture_lon capture_time
    . str_repeat('s', 4)   // ant_s1_azimuth etilt mtilt height
    . str_repeat('s', 4)   // ant_s2
    . str_repeat('s', 4)   // ant_s3
    . str_repeat('s', 2)   // gps_lat gps_lon
    . str_repeat('s', 6)   // 6 JSON cols
    . 's'                  // general_comments
    . 'i'                  // is_submitted
    . str_repeat('s', 2)   // submitted_at updated_at
    . str_repeat('s', 3)   // submit_lat submit_lon submit_accuracy
    . 'i'                  // submit_distance_m
    . 's';                 // submit_location_status
// Total: 1+4+4+4+4+2+6+1+1+2+3+1+1 = 34 ✓

$fi = $conn->prepare(
    "INSERT INTO maintenance_forms
     (visit_id, access_ref, capture_lat, capture_lon, capture_time,
      ant_s1_azimuth, ant_s1_etilt, ant_s1_mtilt, ant_s1_height,
      ant_s2_azimuth, ant_s2_etilt, ant_s2_mtilt, ant_s2_height,
      ant_s3_azimuth, ant_s3_etilt, ant_s3_mtilt, ant_s3_height,
      gps_lat, gps_lon,
      equipment_json, generator_json, transmission_json,
      security_json, container_json, electrical_json,
      general_comments,
      is_submitted, submitted_at, updated_at,
      submit_lat, submit_lon, submit_accuracy,
      submit_distance_m, submit_location_status)
     VALUES " . '(' . str_repeat('?,', 33) . '?)'
);

// 5 params: visit_id(i) filename(s) caption(s) user_id(i) uploaded_at(s)
$pi = $conn->prepare(
    "INSERT INTO visit_photos
     (visit_id, photo_filename, photo_type, caption, uploaded_by_user_id, uploaded_at)
     VALUES (?, ?, 'other', ?, ?, ?)"
);

$count_forms   = 0;
$count_offsite = 0;
$count_photos  = 0;

foreach ($visit_meta as $vid => $vm) {
    if ($vm['status'] !== 'completed') continue;

    $slat  = $vm['site_lat'];
    $slon  = $vm['site_lon'];
    $subat = $vm['completed_at'];

    // ── Random JSON for each inspection section ──
    $jsons = [];
    foreach ($form_sections as $sec_key => $sec) {
        $d = [];
        foreach ($sec['fields'] as $fk => $fl) {
            $st      = $statuses_chk[mt_rand(0, 2)];
            $d[$fk]  = ['status' => $st, 'remarks' => $st === 'Faulty' ? 'Requires attention' : ''];
        }
        $jsons[$sec_key] = json_encode($d);
    }

    // ── Submit location: 85% on-site, 15% away ──
    $is_away = (mt_rand(1, 100) <= 15);
    // on-site points stay well within the configured radius so they classify as on_site
    $offset_m = $is_away ? mt_rand(2000, 8000) : mt_rand(0, (int) max(5, round($onsite_radius * 0.6)));
    if ($is_away) $count_offsite++;

    $angle = (mt_rand(0, 359)) * (M_PI / 180.0);
    $dlat  = ($offset_m / 111000.0) * cos($angle);
    $dlon  = ($offset_m / (111000.0 * cos(deg2rad($slat)))) * sin($angle);
    $sublat = round($slat + $dlat, 7);
    $sublon = round($slon + $dlon, 7);
    $subacc = mt_rand(5, 40);

    $dist_m    = haversine_m($slat, $slon, $sublat, $sublon) ?? 0;
    $loc_stat  = classify_onsite($dist_m, $onsite_radius);

    // ── Antenna values ──
    $az1  = (string)mt_rand(0, 359);
    $az2  = (string)(((int)$az1 + 120) % 360);
    $az3  = (string)(((int)$az1 + 240) % 360);
    $ht   = mt_rand(25, 45) . 'm';
    $et   = (string)mt_rand(0, 6);
    $mt_v = (string)mt_rand(0, 4);

    $access_ref   = 'None';
    $cap_lat      = (string)$slat;
    $cap_lon      = (string)$slon;
    $cap_time     = date('H:i', strtotime($subat));
    $gps_lat      = $cap_lat;
    $gps_lon      = $cap_lon;
    $gen_comments = 'Site inspection completed. All findings documented.';
    $is_sub       = 1;
    $sublat_s     = (string)$sublat;
    $sublon_s     = (string)$sublon;
    $subacc_s     = (string)$subacc;

    $fi->bind_param(
        $form_types,
        $vid,
        $access_ref, $cap_lat, $cap_lon, $cap_time,
        $az1, $et, $mt_v, $ht,
        $az2, $et, $mt_v, $ht,
        $az3, $et, $mt_v, $ht,
        $gps_lat, $gps_lon,
        $jsons['equipment'], $jsons['generator'], $jsons['transmission'],
        $jsons['security'],  $jsons['container'],  $jsons['electrical'],
        $gen_comments,
        $is_sub, $subat, $subat,
        $sublat_s, $sublon_s, $subacc_s,
        $dist_m, $loc_stat
    );
    $fi->execute();
    $count_forms++;

    // ── 1–3 photos per completed visit ──
    if (!empty($demo_photos)) {
        $n_photos = mt_rand(1, 3);
        for ($p = 0; $p < $n_photos; $p++) {
            $fn  = $demo_photos[mt_rand(0, count($demo_photos) - 1)];
            $cap = $photo_captions[mt_rand(0, count($photo_captions) - 1)];
            $uid = $vm['assigned_uid'];
            $pi->bind_param('issis', $vid, $fn, $cap, $uid, $subat);
            $pi->execute();
            $count_photos++;
        }
    }
}
$fi->close();
$pi->close();
out("  Inserted $count_forms forms ($count_offsite off-site).");
out("  Inserted $count_photos visit photos.");

// ──────────────────────────────────────────────────────────────────────────────
// STEP 5: time_entries — ~8 weeks for all employees
// ──────────────────────────────────────────────────────────────────────────────
out('');
out('STEP 5 — Time entries (~8 weeks per employee)');

// Collect weekdays and Sundays in the 8-week window
$all_weekdays = [];
$all_sundays  = [];
for ($ts = $te_start_ts; $ts <= $today_ts; $ts += 86400) {
    $dow = (int)date('w', $ts);
    if ($dow >= 1 && $dow <= 5) $all_weekdays[] = $ts;
    elseif ($dow === 0)          $all_sundays[]  = $ts;
}

// Helper: random Eswatini-area coordinate
function rand_sz_coord(): array {
    return [
        (string)round(-26.32 + (mt_rand(-300, 300) / 10000.0), 6),
        (string)round( 31.13 + (mt_rand(-300, 300) / 10000.0), 6),
    ];
}

// 9 params: i s s s s s s i s  (user_id, shift, in, out, lat, lon, acc, worked_mins, flag)
$tei = $conn->prepare(
    "INSERT INTO time_entries
     (user_id, shift_type, clock_in_at, clock_out_at,
      clock_in_lat, clock_in_lon, clock_in_accuracy,
      worked_minutes, location_flag, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'closed')"
);

$count_te    = 0;
$auto_budget = 3;
$auto_done   = 0;

foreach ($employee_ids as $uid) {
    $shift = $emp_shift[$uid];

    // 88% attendance on weekdays
    $my_days = array_values(array_filter($all_weekdays, fn() => mt_rand(1, 100) <= 88));

    // ~10% chance of working each Sunday (premium day)
    $my_sundays = array_values(array_filter($all_sundays, fn() => mt_rand(1, 100) <= 10));

    $all_days = array_merge($my_days, $my_sundays);

    foreach ($all_days as $day_ts) {
        // Skip seeding for today if it's after 09:00 SAST (keep one open entry realistic)
        // (Omit this check — seed all days for clean data)

        if ($shift === 'night') {
            $in_ts  = mktime(20, 0, 0, date('n', $day_ts), date('j', $day_ts), date('Y', $day_ts))
                      + mt_rand(-15, 15) * 60;
            // Overtime: 20% chance of 30–90 min extra
            $ot_extra = (mt_rand(1, 100) <= 20) ? mt_rand(30, 90) * 60 : 0;
            $out_ts = $in_ts + (9 * 3600) + mt_rand(-20, 45) * 60 + $ot_extra;
        } else {
            $in_ts  = mktime(8, 0, 0, date('n', $day_ts), date('j', $day_ts), date('Y', $day_ts))
                      + mt_rand(-15, 15) * 60;
            // Overtime: 25% chance of 1–2 h extra
            $ot_extra = (mt_rand(1, 100) <= 25) ? mt_rand(60, 120) * 60 : 0;
            $out_ts = $in_ts + (8 * 3600) + mt_rand(-20, 45) * 60 + $ot_extra;
        }

        $worked_mins = max(1, (int)round(($out_ts - $in_ts) / 60));
        $clock_in    = date('Y-m-d H:i:s', $in_ts);
        $clock_out   = date('Y-m-d H:i:s', $out_ts);

        // GPS coords on ~30% of entries
        $has_gps = (mt_rand(1, 100) <= 30);
        $in_lat = $in_lon = $in_acc = '';
        if ($has_gps) {
            [$in_lat, $in_lon] = rand_sz_coord();
            $in_acc = (string)mt_rand(8, 50);
        }

        // location_flag — always prefixed 'demo'
        $flag_parts = ['demo'];
        if ($has_gps)  $flag_parts[] = 'gps';
        if ($auto_done < $auto_budget && mt_rand(1, 200) === 1) {
            $flag_parts[] = 'auto_clocked_out';
            $auto_done++;
        }
        $flag = implode(' ', $flag_parts);

        $tei->bind_param(
            'issssssis',
            $uid, $shift, $clock_in, $clock_out,
            $in_lat, $in_lon, $in_acc,
            $worked_mins, $flag
        );
        $tei->execute();
        $count_te++;
    }
}
$tei->close();
out("  Inserted $count_te time entries ($auto_done flagged auto_clocked_out).");

// ──────────────────────────────────────────────────────────────────────────────
// STEP 6: Vehicles + inspection checklists (~6 weeks)
// ──────────────────────────────────────────────────────────────────────────────
out('');
out('STEP 6 — Vehicles & inspections');

$checklist = require 'incl/vehicle_checklist.php';

// Fleet numbers FL-01.. are the demo marker (clear removes fleet_number LIKE 'FL-%')
$veh_defs = [
    ['Toyota Hilux 2.4',      'SD 421 MG'],
    ['Isuzu D-Max 250',       'SD 118 KH'],
    ['Ford Ranger XL',        'SD 903 BM'],
    ['Nissan NP300 Hardbody', 'SD 337 TS'],
    ['Toyota Land Cruiser',   'SD 552 LC'],
    ['Toyota Hilux 2.8 GD',   'SD 274 HX'],
    ['Isuzu KB250',           'SD 689 KB'],
    ['VW Amarok 2.0',         'SD 145 AM'],
    ['Ford Ranger 3.2',       'SD 812 FR'],
    ['Nissan Navara',         'SD 506 NV'],
    ['Mahindra Bolero',       'SD 233 MB'],
    ['Toyota Hilux D4D',      'SD 977 DX'],
];

$veh_ins = $conn->prepare("INSERT INTO vehicles (make, fleet_number, registration, status) VALUES (?, ?, ?, 'active')");
$veh_ids = [];
$veh_odo = [];
foreach ($veh_defs as $i => $vd) {
    $fleet = sprintf('FL-%02d', $i + 1);
    $veh_ins->bind_param('sss', $vd[0], $fleet, $vd[1]);
    $veh_ins->execute();
    $vid = (int)$conn->insert_id;
    $veh_ids[]       = $vid;
    $veh_odo[$vid]   = mt_rand(35000, 145000);
}
$veh_ins->close();
$count_veh = count($veh_ids);
out("  Vehicles inserted: $count_veh");

// Inspection window: 6 weeks of weekdays
$insp_start_ts = $today_ts - (6 * 7 * 86400);
$status_pool   = ['ok','ok','ok','ok','ok','ok','ok','attention','attention','critical']; // mostly ok
$attn_remarks  = ['Needs topping up', 'Slightly worn', 'Booked for service', 'Monitor next inspection'];
$crit_remarks  = ['Replace urgently', 'Not working', 'Failed check — report to workshop'];

$ii = $conn->prepare(
    "INSERT INTO vehicle_inspections
     (vehicle_id, driver_user_id, inspection_date, odometer_km, overall_status, repair_request, items_json)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);

$count_insp = 0;
foreach ($veh_ids as $vid) {
    $driver = $employee_ids[array_rand($employee_ids)];
    for ($ts = $insp_start_ts; $ts <= $today_ts; $ts += 86400) {
        $dow = (int)date('w', $ts);
        if ($dow === 0 || $dow === 6) continue;   // weekdays only
        if (mt_rand(1, 100) > 45)     continue;   // ~45% of weekdays inspected
        if (mt_rand(1, 100) <= 30) $driver = $employee_ids[array_rand($employee_ids)];

        // Decide the inspection outcome first, then mark items to match — most
        // daily checks pass clean (~70% OK, ~22% attention, ~8% critical).
        $roll   = mt_rand(1, 100);
        $target = $roll <= 70 ? 'ok' : ($roll <= 92 ? 'attention' : 'critical');

        // Flatten field keys, start everything OK
        $all_fields = [];
        $items = [];
        foreach ($checklist as $sec_key => $sec) {
            if (!is_array($sec) || empty($sec['fields'])) continue;
            $items[$sec_key] = [];
            foreach ($sec['fields'] as $fk => $fl) {
                $items[$sec_key][$fk] = ['status' => 'ok', 'remarks' => ''];
                $all_fields[] = [$sec_key, $fk];
            }
        }

        $has_attn = false; $has_crit = false;
        if ($target === 'attention') {
            $k = mt_rand(1, 2);
            for ($x = 0; $x < $k; $x++) {
                [$sk, $fk] = $all_fields[array_rand($all_fields)];
                $items[$sk][$fk] = ['status' => 'attention', 'remarks' => $attn_remarks[array_rand($attn_remarks)]];
                $has_attn = true;
            }
        } elseif ($target === 'critical') {
            [$sk, $fk] = $all_fields[array_rand($all_fields)];
            $items[$sk][$fk] = ['status' => 'critical', 'remarks' => $crit_remarks[array_rand($crit_remarks)]];
            $has_crit = true;
            if (mt_rand(1, 100) <= 40) { // sometimes an extra attention item alongside
                [$sk2, $fk2] = $all_fields[array_rand($all_fields)];
                if ($items[$sk2][$fk2]['status'] === 'ok') {
                    $items[$sk2][$fk2] = ['status' => 'attention', 'remarks' => $attn_remarks[array_rand($attn_remarks)]];
                    $has_attn = true;
                }
            }
        }
        $overall = $has_crit ? 'critical' : ($has_attn ? 'attention' : 'ok');
        $repair     = $has_crit ? 'Critical items flagged — see checklist.'
                    : ($has_attn ? 'Minor items need attention.' : '');
        $odo        = ($veh_odo[$vid] += mt_rand(20, 180));
        $date       = date('Y-m-d', $ts);
        $items_json = json_encode($items);

        $ii->bind_param('iisisss', $vid, $driver, $date, $odo, $overall, $repair, $items_json);
        $ii->execute();
        $count_insp++;
    }
}
$ii->close();
out("  Vehicle inspections inserted: $count_insp");

// ── Summary ───────────────────────────────────────────────────────────────────
out('');
out('=== SEED COMPLETE ===');
out('  Employees updated (shift types):     ' . count($employee_ids));
out('  Demo photo stubs in uploads/:        ' . count($demo_photos));
out('  Visits inserted:                     ' . $count_v);
out('  Maintenance forms:                   ' . $count_forms . '  (off-site: ' . $count_offsite . ')');
out('  Visit photos:                        ' . $count_photos);
out('  Time entries:                        ' . $count_te);
out('  Vehicles:                            ' . $count_veh);
out('  Vehicle inspections:                 ' . $count_insp);
out('');
out('To clear demo data:  php database/seed_demo.php clear');

if (!$cli) echo "<br><a href='../admin_dashboard.php' class='btn btn-primary'>Back to Dashboard</a></div></body></html>\n";
