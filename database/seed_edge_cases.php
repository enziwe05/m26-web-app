<?php
/*
 * M26 Edge-Case Seeder — database/seed_edge_cases.php
 *
 * Forces the tricky, boundary, and "worst case" states the normal random seeders
 * won't reliably produce, so you can eyeball how the app handles them:
 *
 *   1. Maintenance Status boundaries — visits at exactly 0 / 119 / 120 / 240 / 900
 *      days old on named sites, so every bucket edge (today, just-OK, just-stale,
 *      urgent boundary, extreme) is visible on the page and the site banner.
 *   2. "Clocked in now" — one OPEN time entry so the dashboard counter is non-zero.
 *   3. Bad GPS — one off-site form with accuracy ±2500 m (the boss's integrity
 *      concern) so it shows up on the Off-site Uploads page.
 *
 * Tagged '[demo] [edge]' (visits) / 'demo edge_open' (time entry) so it is removed
 * by this script's clear AND by seed_demo.php clear.
 *
 * CLI:   php database/seed_edge_cases.php   |   php database/seed_edge_cases.php clear
 */

$cli = (PHP_SAPI === 'cli');
chdir(dirname(__DIR__));
require_once 'incl/dbconn.php';

if (!$cli) {
    require_admin();
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre style='font:13px monospace;max-width:820px;margin:40px auto;'>";
}
function out(string $l): void { echo ($l . PHP_EOL); if (PHP_SAPI !== 'cli'){ @ob_flush();@flush(); } }

$mode = $cli
    ? (in_array('clear', array_slice($argv ?? [], 1)) ? 'clear' : 'seed')
    : (($_GET['action'] ?? 'seed') === 'clear' ? 'clear' : 'seed');

// ── CLEAR ─────────────────────────────────────────────────────────────────────
if ($mode === 'clear') {
    out('=== CLEAR edge cases ===');
    $conn->query("DELETE mf FROM maintenance_forms mf JOIN visits v ON v.visit_id=mf.visit_id WHERE v.description LIKE '%[edge]%'");
    out('  forms deleted:        ' . $conn->affected_rows);
    $conn->query("DELETE FROM visits WHERE description LIKE '%[edge]%'");
    out('  visits deleted:       ' . $conn->affected_rows);
    $conn->query("DELETE FROM time_entries WHERE location_flag LIKE '%edge_open%'");
    out('  open entries deleted: ' . $conn->affected_rows);
    out('=== DONE ===');
    if (!$cli) echo "</pre>";
    exit;
}

// ── SEED ──────────────────────────────────────────────────────────────────────
out('=== EDGE-CASE SEEDER ===');

$admin_id = (int)($conn->query("SELECT MIN(user_id) a FROM users WHERE role='admin'")->fetch_assoc()['a'] ?? 1);
$emp_id   = (int)($conn->query("SELECT MIN(user_id) a FROM users WHERE role='employee' AND status='active'")->fetch_assoc()['a'] ?? 3);

// 1. Boundary-age completed visits on named sites.
$cases = [
    ['M001',   0, 'Checked today — should read "Today" and count as up to date'],
    ['M002', 119, 'Just inside the 4-month cycle — should still be up to date'],
    ['M003', 120, 'Exactly at the 4-month boundary — should flip to overdue'],
    ['M004', 240, 'Exactly at 2x cycle — should flip to Over 8 months / Urgent'],
    ['M005', 900, 'Extreme: ~2.5 years — should read "30 months ago", Urgent'],
];

$find = $conn->prepare("SELECT site_id FROM sites WHERE site_code = ?");
$delF = $conn->prepare("DELETE mf FROM maintenance_forms mf JOIN visits v ON v.visit_id=mf.visit_id WHERE v.site_id=? AND v.description LIKE '%[demo]%'");
$delV = $conn->prepare("DELETE FROM visits WHERE site_id=? AND description LIKE '%[demo]%'");
$ins  = $conn->prepare(
    "INSERT INTO visits (site_id, assigned_to_user_id, created_by_user_id,
        visit_type, maintenance_type, description, scheduled_date, status, created_at, completed_at)
     VALUES (?, ?, ?, 'Maintenance', 'active', ?, ?, 'completed', ?, ?)"
);

foreach ($cases as [$code, $days_ago, $why]) {
    $find->bind_param('s', $code);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    if (!$row) { out("  ! site $code not found — skipped"); continue; }
    $sid = (int)$row['site_id'];

    // Wipe existing demo visits for this site so the forced age is the newest.
    $delF->bind_param('i', $sid); $delF->execute();
    $delV->bind_param('i', $sid); $delV->execute();

    $ts    = time() - $days_ago * 86400 - 3600;
    $when  = date('Y-m-d H:i:s', $ts);
    $sched = date('Y-m-d', $ts);
    $desc  = "[demo] [edge] $days_ago-day case";
    $ins->bind_param('iisssss', $sid, $emp_id, $admin_id, $desc, $sched, $when, $when);
    $ins->execute();
    out(sprintf('  %-5s  %4d days ago  — %s', $code, $days_ago, $why));
}
$find->close(); $delF->close(); $delV->close(); $ins->close();

// 2. One OPEN time entry (someone clocked in right now).
$conn->query("DELETE FROM time_entries WHERE location_flag LIKE '%edge_open%'"); // avoid duplicates on re-run
$in_at = date('Y-m-d H:i:s', time() - 2 * 3600); // clocked in 2h ago
$oe = $conn->prepare(
    "INSERT INTO time_entries (user_id, shift_type, clock_in_at, clock_in_lat, clock_in_lon, clock_in_accuracy, location_flag, status)
     VALUES (?, 'day', ?, '-26.3160', '31.1360', '12', 'demo edge_open', 'open')"
);
$oe->bind_param('is', $emp_id, $in_at);
$oe->execute();
$oe->close();
out('  + 1 OPEN time entry (employee #' . $emp_id . ' clocked in ~2h ago)');

// 3. Bad-GPS off-site form: accuracy ±2500 m on the most recent off-site submission.
$bad = $conn->query(
    "SELECT mf.form_id FROM maintenance_forms mf
     WHERE mf.submit_location_status = 'away' ORDER BY mf.submitted_at DESC LIMIT 1"
)->fetch_assoc();
if ($bad) {
    $fid = (int)$bad['form_id'];
    $conn->query("UPDATE maintenance_forms SET submit_accuracy = '2500' WHERE form_id = $fid");
    out('  + Set form #' . $fid . ' GPS accuracy to 2500 m (poor-fix integrity case)');
} else {
    out('  ! no off-site form found to mark as bad-GPS (run seed_demo.php first)');
}

out('');
out('=== DONE ===  clear with: php database/seed_edge_cases.php clear');
if (!$cli) echo "</pre>";
