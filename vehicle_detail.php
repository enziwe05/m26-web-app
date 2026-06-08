<?php
require_once 'incl/dbconn.php';
require_staff();

$vehicle_checklist = require __DIR__ . '/incl/vehicle_checklist.php';

$vehicle_id = (int)($_GET['vehicle_id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM vehicles WHERE vehicle_id = ?");
$stmt->bind_param('i', $vehicle_id);
$stmt->execute();
$vehicle = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$vehicle) {
    $page_title = 'M26 | Vehicle';
    include 'incl/header.php';
    echo "<div class='alert alert-error'>Vehicle not found.</div>";
    include 'incl/footer.php';
    exit;
}

// ── Which week to show (Mon–Sun). ?week=YYYY-MM-DD picks any day in that week ──
$ref      = $_GET['week'] ?? date('Y-m-d');
$ref_ts   = strtotime($ref) ?: time();
$dow      = (int)date('N', $ref_ts);                 // 1 (Mon) .. 7 (Sun)
$monday   = strtotime('-' . ($dow - 1) . ' days', $ref_ts);
$sunday   = strtotime('+6 days', $monday);
$week_days = [];
for ($i = 0; $i < 7; $i++) {
    $week_days[] = date('Y-m-d', strtotime("+$i days", $monday));
}
$prev_week = date('Y-m-d', strtotime('-7 days', $monday));
$next_week = date('Y-m-d', strtotime('+7 days', $monday));

// Inspections for this vehicle in the visible week, keyed by date
$stmt = $conn->prepare("
    SELECT * FROM vehicle_inspections
    WHERE vehicle_id = ? AND inspection_date BETWEEN ? AND ?
");
$ws = date('Y-m-d', $monday);
$we = date('Y-m-d', $sunday);
$stmt->bind_param('iss', $vehicle_id, $ws, $we);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$by_day = [];
while ($r = $res->fetch_assoc()) {
    $by_day[$r['inspection_date']] = [
        'overall' => $r['overall_status'],
        'items'   => json_decode($r['items_json'] ?? '', true) ?: [],
    ];
}

// Recent inspection history (latest 14)
$stmt = $conn->prepare("
    SELECT vi.inspection_id, vi.inspection_date, vi.odometer_km, vi.overall_status,
           vi.repair_request, u.first_name, u.last_name
    FROM vehicle_inspections vi
    JOIN users u ON u.user_id = vi.driver_user_id
    WHERE vi.vehicle_id = ?
    ORDER BY vi.inspection_date DESC
    LIMIT 14
");
$stmt->bind_param('i', $vehicle_id);
$stmt->execute();
$history = $stmt->get_result();
$stmt->close();

function overall_badge(?string $os): string {
    if ($os === 'critical')  return "<span class='badge badge-critical'>Critical</span>";
    if ($os === 'attention') return "<span class='badge badge-attention'>Attention</span>";
    if ($os === 'ok')        return "<span class='badge badge-completed'>OK</span>";
    return "<span style='color:#bbb;'>&mdash;</span>";
}

$page_title = 'M26 | ' . $vehicle['registration'];
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1><?php echo htmlspecialchars($vehicle['registration']); ?></h1>
            <div style='display:flex; gap:8px;'>
                <a href='view_vehicles.php' class='btn btn-secondary'>&larr; All Vehicles</a>
                <?php if (is_admin()): ?>
                <a href='edit_vehicle.php?vehicle_id=<?php echo $vehicle_id; ?>' class='btn btn-secondary'>Edit</a>
                <?php endif; ?>
            </div>
        </div>

        <p class='page-intro'>
            Weekly inspection checklist and history for this vehicle.
        </p>

        <div class='card'>
            <p><strong>Make:</strong> <?php echo htmlspecialchars($vehicle['make'] ?? '—'); ?></p>
            <p><strong>Fleet #:</strong> <?php echo htmlspecialchars($vehicle['fleet_number'] ?? '—'); ?></p>
            <p><strong>Status:</strong> <?php echo ucfirst($vehicle['status']); ?></p>
        </div>

        <!-- ── Weekly inspection grid (the paper sheet) ── -->
        <div class='page-heading' style='margin-top:24px;'>
            <h2>Weekly Inspection Checklist</h2>
            <span style='font-size:13px;'>
                <a href='?vehicle_id=<?php echo $vehicle_id; ?>&week=<?php echo $prev_week; ?>'>&larr; Prev</a>
                &nbsp;<?php echo fmt_date($ws) . ' – ' . fmt_date($we); ?>&nbsp;
                <a href='?vehicle_id=<?php echo $vehicle_id; ?>&week=<?php echo $next_week; ?>'>Next &rarr;</a>
            </span>
        </div>

        <p style='font-size:12px; color:#888; margin-bottom:8px;'>
            Key: &#10003; No Problem &nbsp; &#10007; Attention &nbsp; &bull;&bull; Critical &nbsp; &ndash; Not checked
        </p>

        <div class='table-scroll'>
        <table class='data-table' style='font-size:12px;'>
            <tr>
                <th>Item</th>
                <?php foreach ($week_days as $d): ?>
                <th style='text-align:center;'><?php echo date('D', strtotime($d)); ?><br>
                    <span style='font-weight:400;color:#888;'><?php echo date('j', strtotime($d)); ?></span></th>
                <?php endforeach; ?>
            </tr>
            <?php foreach ($vehicle_checklist as $sec_key => $sec): ?>
                <tr><td colspan='8' style='background:#f0f4ff; font-weight:700; text-transform:uppercase; font-size:11px; letter-spacing:.04em;'>
                    <?php echo htmlspecialchars($sec['label']); ?>
                </td></tr>
                <?php foreach ($sec['fields'] as $fk => $fl): ?>
                <tr>
                    <td><?php echo htmlspecialchars($fl); ?></td>
                    <?php foreach ($week_days as $d):
                        $st = $by_day[$d]['items'][$sec_key][$fk]['status'] ?? null;
                        $cls = $st ? vc_status_class($st) : '';
                        $mark = $st ? vc_status_mark($st) : '&ndash;';
                    ?>
                    <td class='<?php echo $cls; ?>' style='text-align:center;'><?php echo $mark; ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <tr>
                <td style='font-weight:700;'>Overall</td>
                <?php foreach ($week_days as $d): ?>
                <td style='text-align:center;'><?php echo overall_badge($by_day[$d]['overall'] ?? null); ?></td>
                <?php endforeach; ?>
            </tr>
        </table>
        </div>

        <!-- ── Recent inspections ── -->
        <div class='section-heading'>Recent Inspections</div>
        <?php if ($history->num_rows == 0): ?>
            <p>No inspections recorded yet.</p>
        <?php else: ?>
            <div class='table-scroll'><table class='data-table'>
                <tr><th>Date</th><th>Driver</th><th>Odometer</th><th>Status</th><th>Repair Request</th><th></th></tr>
                <?php while ($h = $history->fetch_assoc()): ?>
                <tr>
                    <td><?php echo fmt_date($h['inspection_date']); ?></td>
                    <td><?php echo htmlspecialchars($h['first_name'] . ' ' . $h['last_name']); ?></td>
                    <td><?php echo $h['odometer_km'] !== null ? number_format((int)$h['odometer_km']) . ' km' : '—'; ?></td>
                    <td><?php echo overall_badge($h['overall_status']); ?></td>
                    <td><?php echo htmlspecialchars($h['repair_request'] !== '' ? $h['repair_request'] : '—'); ?></td>
                    <td><a href='inspection_detail.php?inspection_id=<?php echo $h['inspection_id']; ?>'>View</a></td>
                </tr>
                <?php endwhile; ?>
            </table></div>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
