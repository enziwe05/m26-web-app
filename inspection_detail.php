<?php
require_once 'incl/dbconn.php';
require_staff();

$vehicle_checklist = require __DIR__ . '/incl/vehicle_checklist.php';

$inspection_id = (int)($_GET['inspection_id'] ?? 0);

$stmt = $conn->prepare("
    SELECT vi.*, v.registration, v.make, v.fleet_number,
           u.first_name, u.last_name
    FROM vehicle_inspections vi
    JOIN vehicles v ON v.vehicle_id = vi.vehicle_id
    JOIN users u    ON u.user_id    = vi.driver_user_id
    WHERE vi.inspection_id = ?
");
$stmt->bind_param('i', $inspection_id);
$stmt->execute();
$insp = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$insp) {
    $page_title = 'M26 | Inspection';
    include 'incl/header.php';
    echo "<div class='alert alert-error'>Inspection not found.</div>";
    include 'incl/footer.php';
    exit;
}

$decoded = json_decode($insp['items_json'] ?? '', true) ?: [];

$os = $insp['overall_status'];
$overall = $os === 'critical'  ? "<span class='badge badge-critical'>Critical</span>"
         : ($os === 'attention' ? "<span class='badge badge-attention'>Attention</span>"
         : "<span class='badge badge-completed'>OK</span>");

$page_title = 'M26 | Inspection';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Inspection &mdash; <?php echo htmlspecialchars($insp['registration']); ?></h1>
            <a href='vehicle_detail.php?vehicle_id=<?php echo $insp['vehicle_id']; ?>' class='btn btn-secondary'>Back to Vehicle</a>
        </div>

        <div class='card'>
            <p><strong>Date:</strong> <?php echo fmt_date($insp['inspection_date']); ?></p>
            <p><strong>Driver:</strong> <?php echo htmlspecialchars($insp['first_name'] . ' ' . $insp['last_name']); ?></p>
            <p><strong>Odometer:</strong> <?php echo $insp['odometer_km'] !== null ? number_format((int)$insp['odometer_km']) . ' km' : '—'; ?></p>
            <p><strong>Overall:</strong> <?php echo $overall; ?></p>
        </div>

        <?php foreach ($vehicle_checklist as $sec_key => $sec): ?>
        <div class='mf-section card'>
            <h2><?php echo htmlspecialchars($sec['label']); ?></h2>
            <table class='mf-table'>
                <tr><th style='width:240px;'>Item</th><th style='width:150px;'>Status</th><th>Remarks</th></tr>
                <?php foreach ($sec['fields'] as $fk => $fl):
                    $st  = $decoded[$sec_key][$fk]['status']  ?? 'na';
                    $rm  = $decoded[$sec_key][$fk]['remarks'] ?? '';
                    $lbl = vc_statuses()[$st] ?? $st;
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($fl); ?></td>
                    <td class='<?php echo vc_status_class($st); ?>'><?php echo htmlspecialchars($lbl); ?></td>
                    <td><?php echo htmlspecialchars($rm !== '' ? $rm : '—'); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endforeach; ?>

        <?php if (trim($insp['repair_request'] ?? '') !== ''): ?>
        <div class='mf-section card'>
            <h2>Repair Request / Notes</h2>
            <p><?php echo nl2br(htmlspecialchars($insp['repair_request'])); ?></p>
        </div>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
