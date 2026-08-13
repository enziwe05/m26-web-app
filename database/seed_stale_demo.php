<?php
/*
 * M26 Stale-Site Demo Seeder — database/seed_stale_demo.php
 *
 * Gives every site a realistic "last completed visit" date so the Stale Sites
 * page and dashboard widget show a believable mix instead of "everything stale".
 *
 * Distribution (relative to today):
 *   ~45% visited recently  → completed 5–110 days ago   (NOT stale at 120d)
 *   ~30% visited long ago  → completed 130–400 days ago  (stale)
 *   ~25% never visited      → no completed visit          (stale)
 *
 * Each seeded visit is tagged '[demo] [stale-seed]' in its description, so it is
 * removed by BOTH this script's clear and the main seed_demo.php clear.
 *
 * CLI:
 *   php database/seed_stale_demo.php          — seed
 *   php database/seed_stale_demo.php clear     — remove only these seeded visits
 *
 * Browser (admin only):
 *   /database/seed_stale_demo.php?action=seed
 *   /database/seed_stale_demo.php?action=clear
 */

$cli = (PHP_SAPI === 'cli');
chdir(dirname(__DIR__));
require_once 'incl/dbconn.php';

if (!$cli) {
    require_admin();
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>M26 Stale Seed</title>"
       . "<link rel='stylesheet' href='../css/styles.css?v=24'></head>"
       . "<body><div style='max-width:800px;margin:40px auto;padding:0 20px;font-family:monospace;font-size:13px;'>\n";
}

function out(string $line): void {
    if (PHP_SAPI === 'cli') { echo $line . PHP_EOL; }
    else { echo htmlspecialchars($line) . "<br>\n"; @ob_flush(); @flush(); }
}

$MARKER = '[stale-seed]';

// ── Mode ────────────────────────────────────────────────────────────────────
$mode = 'seed';
if ($cli) {
    $mode = in_array('clear', array_slice($argv ?? [], 1)) ? 'clear' : 'seed';
} else {
    $mode = ($_GET['action'] ?? 'seed') === 'clear' ? 'clear' : 'seed';
}

// ══════════════════════════════════════════════════════════════════════════════
//  CLEAR
// ══════════════════════════════════════════════════════════════════════════════
if ($mode === 'clear') {
    out('=== CLEAR: removing stale-seed visits ===');
    // maintenance_forms first (FK), though this seeder creates none — safe anyway.
    $conn->query(
        "DELETE mf FROM maintenance_forms mf
           JOIN visits v ON v.visit_id = mf.visit_id
          WHERE v.description LIKE '%$MARKER%'"
    );
    out('  maintenance_forms deleted: ' . $conn->affected_rows);
    $conn->query("DELETE FROM visits WHERE description LIKE '%$MARKER%'");
    out('  visits deleted:            ' . $conn->affected_rows);
    out('=== CLEAR COMPLETE ===');
    if (!$cli) echo "</div></body></html>\n";
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
//  SEED
// ══════════════════════════════════════════════════════════════════════════════
out('=== M26 STALE-SITE SEEDER — starting ===');
mt_srand(20260731);   // reproducible

// Valid FK targets
$employee_ids = array_column(
    $conn->query("SELECT user_id FROM users WHERE role='employee' AND status='active' ORDER BY user_id")
         ->fetch_all(MYSQLI_ASSOC), 'user_id'
);
$admin_id = (int)($conn->query("SELECT MIN(user_id) AS a FROM users WHERE role='admin'")
                        ->fetch_assoc()['a'] ?? 1);
if (empty($employee_ids)) { out('ERROR: no active employees to assign visits to.'); exit(1); }
out('  ' . count($employee_ids) . ' employees available; admin #' . $admin_id . '.');

// All sites
$site_ids = array_column(
    $conn->query("SELECT site_id FROM sites ORDER BY site_id")->fetch_all(MYSQLI_ASSOC),
    'site_id'
);
out('  ' . count($site_ids) . ' sites total.');

$visit_types = ['Maintenance', 'Site Inspection', 'Emergency Repair', 'Installation'];
$maint_types = ['active', 'passive', 'housekeeping'];

$vi = $conn->prepare(
    "INSERT INTO visits
     (site_id, assigned_to_user_id, created_by_user_id,
      visit_type, maintenance_type, description,
      scheduled_date, status, created_at, completed_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?)"
);

$n_recent = 0; $n_old = 0; $n_never = 0;

foreach ($site_ids as $sid) {
    $roll = mt_rand(1, 100);
    if     ($roll <= 45) { $days_ago = mt_rand(5, 110);   $bucket = 'recent'; }
    elseif ($roll <= 75) { $days_ago = mt_rand(130, 400); $bucket = 'old'; }
    else                 { $bucket = 'never'; }

    if ($bucket === 'never') { $n_never++; continue; }

    $uid    = $employee_ids[mt_rand(0, count($employee_ids) - 1)];
    $vtype  = $visit_types[mt_rand(0, count($visit_types) - 1)];
    $mtype  = $maint_types[mt_rand(0, count($maint_types) - 1)];

    $completed_ts = time() - $days_ago * 86400 - mt_rand(0, 86400);
    $completed_at = date('Y-m-d H:i:s', $completed_ts);
    // Scheduled a little before completion; created a little before that.
    $sched_date   = date('Y-m-d', $completed_ts - mt_rand(0, 2 * 86400));
    $created_at   = date('Y-m-d H:i:s', $completed_ts - mt_rand(0, 3 * 86400));

    $desc = "[demo] $MARKER $vtype";

    $vi->bind_param(
        'iiissssss',
        $sid, $uid, $admin_id,
        $vtype, $mtype, $desc,
        $sched_date, $created_at, $completed_at
    );
    $vi->execute();

    if ($bucket === 'recent') $n_recent++; else $n_old++;
}
$vi->close();

$total = $n_recent + $n_old + $n_never;
out('');
out('=== SEED COMPLETE ===');
out("  Recently visited (not stale): $n_recent");
out("  Visited long ago (stale):     $n_old");
out("  Never visited (stale):        $n_never");
out("  Sites processed:              $total");
out('');
out("  Expected stale at 120 days:   " . ($n_old + $n_never));
out('');
out('To clear:  php database/seed_stale_demo.php clear');

if (!$cli) echo "<br><a href='../stale_sites.php' class='btn btn-primary'>View Stale Sites</a></div></body></html>\n";
