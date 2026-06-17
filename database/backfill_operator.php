<?php
/*
 * One-off: populate sites.operator from the existing free-text sites.notes,
 * using the company keyword definitions. Run after migrate_operator.sql:
 *     php database/backfill_operator.php
 *
 * Sites whose notes don't match any known operator are left NULL — an admin can
 * set them on the site page. Safe to re-run (it just recomputes).
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli('localhost', 'root', 'root', 'm26');
$conn->set_charset('utf8');

$companies = require __DIR__ . '/../incl/client_companies.php';

$res = $conn->query("SELECT site_id, notes FROM sites");
$upd = $conn->prepare("UPDATE sites SET operator = ? WHERE site_id = ?");

$counts = [];
while ($r = $res->fetch_assoc()) {
    $notes = (string)($r['notes'] ?? '');
    $key = null;
    if (trim($notes) !== '') {
        foreach ($companies as $k => $def) {
            foreach ($def['match'] as $kw) {
                if (stripos($notes, $kw) !== false) { $key = $k; break 2; }
            }
        }
    }
    $sid = (int)$r['site_id'];
    $upd->bind_param('si', $key, $sid);   // null $key → NULL operator
    $upd->execute();
    $label = $key ?? '(unmatched)';
    $counts[$label] = ($counts[$label] ?? 0) + 1;
}
$upd->close();

echo "Operator backfill complete:\n";
ksort($counts);
foreach ($counts as $k => $n) {
    printf("  %-12s %d sites\n", $k, $n);
}
$conn->close();
