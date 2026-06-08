<?php
/*
 * M26 — Demo data seeder (for walkthroughs).
 *
 * Safe to re-run: it removes its own previously-seeded rows first (visits are
 * tagged "[DEMO]" in the description; vehicle/client rows are matched by a known
 * registration/name) and recreates a consistent demo dataset.
 *
 * Run from a terminal:   php database/seed_demo.php
 * (Requires MySQL/MAMP running.)
 *
 * Demo logins it creates (password for all:  demo1234):
 *     mtn  → MTN client portal
 *     esm  → Eswatini Mobile client portal
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli('localhost', 'root', 'root', 'm26');
$conn->set_charset('utf8');

$BASE = dirname(__DIR__);
$form_sections     = require $BASE . '/incl/form_sections.php';
$vehicle_checklist = require $BASE . '/incl/vehicle_checklist.php';

function say($m) { echo $m . PHP_EOL; }

// ── People ──────────────────────────────────────────────────────────────────────
$admin_id = (int)$conn->query("SELECT user_id FROM users WHERE role='admin' ORDER BY user_id LIMIT 1")->fetch_assoc()['user_id'];
$techs = [];
$r = $conn->query("SELECT user_id FROM users WHERE role='employee' AND status='active' ORDER BY user_id LIMIT 6");
while ($row = $r->fetch_assoc()) $techs[] = (int)$row['user_id'];
if (!$admin_id || count($techs) < 2) { exit("Need at least 1 admin and 2 employees in the database first.\n"); }

// A known-password demo technician (so the field-tech flow can be shown live).
// Prepended to $techs so it receives several demo visits + inspections.
function ensure_tech(mysqli $conn): int {
    $hash = password_hash('demo1234', PASSWORD_BCRYPT);
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username='tech'");
    $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($row) {
        $uid = (int)$row['user_id'];
        $u = $conn->prepare("UPDATE users SET password_hash=?, role='employee', status='active' WHERE user_id=?");
        $u->bind_param('si', $hash, $uid); $u->execute(); $u->close();
        return $uid;
    }
    $u = $conn->prepare("INSERT INTO users (first_name,last_name,username,password_hash,role,team) VALUES ('Demo','Tech','tech',?,'employee','Field Team A')");
    $u->bind_param('s', $hash); $u->execute(); $uid = $u->insert_id; $u->close();
    return $uid;
}
$demo_tech = ensure_tech($conn);
array_unshift($techs, $demo_tech);   // demo tech becomes index 0
say("Using admin #$admin_id and " . count($techs) . " technicians (incl. demo 'tech').");

// ── Site pools per operator ─────────────────────────────────────────────────────
function site_pool(mysqli $conn, string $cond, int $limit): array {
    $ids = [];
    $r = $conn->query("SELECT site_id FROM sites WHERE $cond ORDER BY site_id LIMIT $limit");
    while ($row = $r->fetch_assoc()) $ids[] = (int)$row['site_id'];
    return $ids;
}
$mtn_sites = site_pool($conn, "notes LIKE '%MTN%'", 6);
$esm_sites = site_pool($conn, "(notes LIKE '%Eswatini Mobile%' OR notes LIKE '%Swazi Mobile%' OR notes LIKE '%ESM%')", 6);
say("MTN sites: " . count($mtn_sites) . " | ESM sites: " . count($esm_sites));

// ════════════════════════════════════════════════════════════════════════════════
// 1) CLIENTS  (MTN + Eswatini Mobile) with read-only portal logins
// ════════════════════════════════════════════════════════════════════════════════
function ensure_client(mysqli $conn, string $name, string $key): int {
    $stmt = $conn->prepare("SELECT client_id FROM clients WHERE name=?");
    $stmt->bind_param('s', $name); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($row) {
        $cid = (int)$row['client_id'];
        $u = $conn->prepare("UPDATE clients SET match_keyword=?, status='active' WHERE client_id=?");
        $u->bind_param('si', $key, $cid); $u->execute(); $u->close();
        return $cid;
    }
    $stmt = $conn->prepare("INSERT INTO clients (name, match_keyword) VALUES (?, ?)");
    $stmt->bind_param('ss', $name, $key); $stmt->execute();
    $cid = $stmt->insert_id; $stmt->close();
    return $cid;
}
function ensure_login(mysqli $conn, string $username, string $display, int $client_id): void {
    $hash = password_hash('demo1234', PASSWORD_BCRYPT);
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username=?");
    $stmt->bind_param('s', $username); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($row) {
        $u = $conn->prepare("UPDATE users SET password_hash=?, role='client', client_id=?, first_name=?, status='active' WHERE user_id=?");
        $uid = (int)$row['user_id'];
        $u->bind_param('sisi', $hash, $client_id, $display, $uid); $u->execute(); $u->close();
    } else {
        $last = '';
        $u = $conn->prepare("INSERT INTO users (first_name,last_name,username,password_hash,role,client_id) VALUES (?,?,?,?,'client',?)");
        $u->bind_param('ssssi', $display, $last, $username, $hash, $client_id); $u->execute(); $u->close();
    }
}
$mtn_cid = ensure_client($conn, 'MTN Eswatini', 'mtn');
$esm_cid = ensure_client($conn, 'Eswatini Mobile', 'esm');
ensure_login($conn, 'mtn', 'MTN Eswatini', $mtn_cid);
ensure_login($conn, 'esm', 'Eswatini Mobile', $esm_cid);
say("Clients ready: MTN (login mtn) + Eswatini Mobile (login esm) — password demo1234");

// ════════════════════════════════════════════════════════════════════════════════
// 2) VEHICLES + daily inspections
// ════════════════════════════════════════════════════════════════════════════════
$vehicles = [
    ['SD 411 AM', 'Toyota Hilux',        'M26-01'],
    ['SD 087 BG', 'Ford Ranger',         'M26-02'],
    ['SD 762 CH', 'Toyota Land Cruiser', 'M26-03'],
    ['SD 233 DM', 'Isuzu D-Max',         'M26-04'],
];
$veh_ids = [];
foreach ($vehicles as [$reg, $make, $fleet]) {
    $stmt = $conn->prepare("SELECT vehicle_id FROM vehicles WHERE registration=?");
    $stmt->bind_param('s', $reg); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($row) { $veh_ids[] = (int)$row['vehicle_id']; continue; }
    $stmt = $conn->prepare("INSERT INTO vehicles (make, fleet_number, registration) VALUES (?,?,?)");
    $stmt->bind_param('sss', $make, $fleet, $reg); $stmt->execute();
    $veh_ids[] = $stmt->insert_id; $stmt->close();
}
say("Vehicles ready: " . count($veh_ids));

// Build a vehicle-inspection items_json with optional issues, returns [json, overall]
function vehicle_items(array $vc, array $issues): array {
    $out = []; $worst = 'ok';
    foreach ($vc as $sk => $sec) {
        foreach ($sec['fields'] as $fk => $fl) {
            $st = $issues[$sk][$fk]['status']  ?? 'ok';
            $rm = $issues[$sk][$fk]['remarks'] ?? '';
            $out[$sk][$fk] = ['status' => $st, 'remarks' => $rm];
            if ($st === 'critical') $worst = 'critical';
            elseif ($st === 'attention' && $worst !== 'critical') $worst = 'attention';
        }
    }
    return [json_encode($out), $worst];
}

function upsert_inspection(mysqli $conn, int $vid, int $driver, string $date, int $odo, array $vc, array $issues): void {
    [$json, $overall] = vehicle_items($vc, $issues);
    // Compose a human repair note from any item remarks
    $notes = [];
    foreach ($issues as $sk => $fields) foreach ($fields as $fk => $d) if (!empty($d['remarks'])) $notes[] = $d['remarks'];
    $repair = $notes ? implode('; ', $notes) : '';
    $stmt = $conn->prepare("
        INSERT INTO vehicle_inspections (vehicle_id, driver_user_id, inspection_date, odometer_km, overall_status, repair_request, items_json)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE driver_user_id=VALUES(driver_user_id), odometer_km=VALUES(odometer_km),
            overall_status=VALUES(overall_status), repair_request=VALUES(repair_request), items_json=VALUES(items_json)
    ");
    $stmt->bind_param('iisisss', $vid, $driver, $date, $odo, $overall, $repair, $json);
    $stmt->execute(); $stmt->close();
}

// Issue presets to vary the week
$clean      = [];
$tyre_warn  = ['tyres'  => ['pressure'      => ['status' => 'attention', 'remarks' => 'Front-left tyre low, needs topping up']]];
$brake_crit = ['brakes' => ['brake_fluid_level' => ['status' => 'critical', 'remarks' => 'Brake fluid below minimum — book service']]];
$light_warn = ['lights' => ['brake_lights'  => ['status' => 'attention', 'remarks' => 'Right brake light intermittent']]];

$odo = [84230, 102400, 56120, 73900];
for ($d = 4; $d >= 0; $d--) {
    $date = date('Y-m-d', strtotime("-$d days"));
    foreach ($veh_ids as $i => $vid) {
        // Leave the 4th vehicle UN-inspected today so the "not inspected today" alert shows
        if ($d === 0 && $i === 3) continue;
        $driver = $techs[$i % count($techs)];
        $issues = $clean;
        if     ($i === 1 && $d === 2) $issues = $tyre_warn;
        elseif ($i === 0 && $d === 0) $issues = $light_warn;
        elseif ($i === 2 && $d === 1) $issues = $brake_crit;
        upsert_inspection($conn, $vid, $driver, $date, $odo[$i] + (4 - $d) * 35, $vehicle_checklist, $issues);
    }
}
say("Vehicle inspections seeded for the last 5 days (one vehicle left unchecked today).");

// ════════════════════════════════════════════════════════════════════════════════
// 3) VISITS (+ checklist items + submitted maintenance reports with faults)
// ════════════════════════════════════════════════════════════════════════════════
// Remove any prior demo visits (cascade clears their items/forms/photos)
$conn->query("DELETE FROM visits WHERE description LIKE '[DEMO]%'");

// Build all six section JSONs: everything OK, then override given faults.
// $faults = ['security' => ['fence_locks' => 'Padlock broken'], ...]
function section_jsons(array $form_sections, array $faults): array {
    $cols = [];
    foreach ($form_sections as $sec_key => $sec) {
        $data = [];
        foreach ($sec['fields'] as $fk => $fl) $data[$fk] = ['status' => 'OK', 'remarks' => ''];
        foreach (($faults[$sec_key] ?? []) as $fk => $rem) {
            if (isset($data[$fk])) $data[$fk] = ['status' => 'Faulty', 'remarks' => $rem];
        }
        $cols[$sec_key] = json_encode($data);
    }
    return $cols;
}

function add_maint_form(mysqli $conn, array $form_sections, int $visit_id, array $faults, string $comments, string $submitted_at): void {
    $c = section_jsons($form_sections, $faults);
    $stmt = $conn->prepare("
        INSERT INTO maintenance_forms
            (visit_id, access_ref, capture_lat, capture_lon, capture_time,
             ant_s1_azimuth, ant_s1_etilt, ant_s1_mtilt, ant_s1_height,
             equipment_json, generator_json, transmission_json, security_json, container_json, electrical_json,
             general_comments, is_submitted, submitted_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)
    ");
    $access = 'AR-' . rand(100, 999);
    $lat = '-26.' . rand(100000, 999999); $lon = '31.' . rand(100000, 999999); $ctime = sprintf('%02d:%02d', rand(8, 16), rand(0, 59));
    $az = (string)rand(0, 350); $et = (string)rand(0, 8); $mt = (string)rand(0, 4); $ht = (string)rand(20, 45);
    // types: visit_id(i) + 17 strings
    $stmt->bind_param('i' . str_repeat('s', 17),
        $visit_id, $access, $lat, $lon, $ctime, $az, $et, $mt, $ht,
        $c['equipment'], $c['generator'], $c['transmission'], $c['security'], $c['container'], $c['electrical'],
        $comments, $submitted_at, $submitted_at);
    $stmt->execute(); $stmt->close();
}

/*
 * Visit plan: [site_id, tech_index, status, days_offset, faults[], comments]
 * status: assigned | in_progress | completed
 */
$plan = [];
// MTN completed visits (with faults → show on Faults page + MTN portal report)
if (isset($mtn_sites[0])) $plan[] = [$mtn_sites[0], 0, 'completed', -9, ['security'=>['fence_locks'=>'Perimeter gate padlock broken'], 'transmission'=>['fiber'=>'Fiber patch cord damaged']], 'Padlock and fiber patch flagged for follow-up.'];
if (isset($mtn_sites[1])) $plan[] = [$mtn_sites[1], 1, 'completed', -5, ['equipment'=>['presence_of_dust'=>'Heavy dust build-up in cabinet']], 'Cabinet cleaned; recommend more frequent visits.'];
if (isset($mtn_sites[2])) $plan[] = [$mtn_sites[2], 2, 'in_progress', -1, [], ''];
if (isset($mtn_sites[3])) $plan[] = [$mtn_sites[3], 0, 'assigned',   3, [], ''];
// ESM completed + in-progress
if (isset($esm_sites[0])) $plan[] = [$esm_sites[0], 1, 'completed', -7, ['generator'=>['battery_charger'=>'Charger not holding voltage']], 'Generator charger faulty — quoted for replacement.'];
if (isset($esm_sites[1])) $plan[] = [$esm_sites[1], 2, 'completed', -3, [], 'All systems healthy.'];
if (isset($esm_sites[2])) $plan[] = [$esm_sites[2], 0, 'in_progress', 0, [], ''];
if (isset($esm_sites[3])) $plan[] = [$esm_sites[3], 1, 'assigned',   2, [], ''];

$mtypes = ['active', 'passive'];
$made = 0;
foreach ($plan as $p) {
    [$site_id, $ti, $status, $off, $faults, $comments] = $p;
    $tech = $techs[$ti % count($techs)];
    $sched = date('Y-m-d', strtotime("$off days"));
    $vtype = 'Preventive Maintenance';
    $mtype = $mtypes[$made % 2];
    $created = date('Y-m-d H:i:s', strtotime("$off days -2 hours"));
    $completed_at = $status === 'completed' ? date('Y-m-d H:i:s', strtotime("$off days +6 hours")) : null;
    $desc = '[DEMO] Routine maintenance';

    $stmt = $conn->prepare("
        INSERT INTO visits (site_id, assigned_to_user_id, created_by_user_id, visit_type, description, scheduled_date, maintenance_type, status, created_at, completed_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ");
    // types: site_id(i) tech(i) admin(i) + 7 strings = 10
    $stmt->bind_param('iii' . str_repeat('s', 7), $site_id, $tech, $admin_id, $vtype, $desc, $sched, $mtype, $status, $created, $completed_at);
    $stmt->execute();
    $vid = $stmt->insert_id;
    $stmt->close();

    if ($status === 'completed') {
        add_maint_form($conn, $form_sections, $vid, $faults, $comments, date('Y-m-d H:i:s', strtotime("$off days +6 hours")));
    }
    $made++;
}
say("Visits seeded: $made (with maintenance reports).");

say("");
say("✔ Demo data ready.  All demo passwords:  demo1234");
say("  Technician login:  tech");
say("  Client logins:     mtn  |  esm");
$conn->close();
