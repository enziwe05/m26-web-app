<?php
require_once 'incl/dbconn.php';
require_employee();   // field technicians (the drivers) fill this in

$vehicle_checklist = require __DIR__ . '/incl/vehicle_checklist.php';

$today      = date('Y-m-d');
$vehicle_id = (int)($_GET['vehicle_id'] ?? 0);

// Active vehicles to choose from
$vehicles = $conn->query("SELECT vehicle_id, registration, make, fleet_number FROM vehicles WHERE status = 'active' ORDER BY registration")
                 ->fetch_all(MYSQLI_ASSOC);

// Which vehicles already have a check logged today (shown in the list)
$checked_today = [];
$res = $conn->prepare("SELECT vehicle_id FROM vehicle_inspections WHERE inspection_date = ?");
$res->bind_param('s', $today);
$res->execute();
$rs = $res->get_result();
while ($r = $rs->fetch_assoc()) $checked_today[(int)$r['vehicle_id']] = true;
$res->close();

// Resolve the chosen vehicle (ignore an unknown id)
$selected = null;
foreach ($vehicles as $v) {
    if ((int)$v['vehicle_id'] === $vehicle_id) { $selected = $v; break; }
}
if (!$selected) $vehicle_id = 0;

// If a vehicle is chosen, load today's inspection (so they can edit/continue)
$existing = null;
$decoded  = [];
if ($vehicle_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM vehicle_inspections WHERE vehicle_id = ? AND inspection_date = ?");
    $stmt->bind_param('is', $vehicle_id, $today);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($existing && $existing['items_json']) {
        $decoded = json_decode($existing['items_json'], true) ?: [];
    }
}

$page_title = 'M26 | Daily Vehicle Check';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Daily Vehicle Check</h1>
            <span><?php echo fmt_date($today); ?></span>
        </div>

        <?php if (isset($_GET['saved'])): ?>
        <div class='alert alert-success'>&#10003; Inspection saved. Safe travels!</div>
        <?php endif; ?>

        <?php if ($vehicle_id <= 0): ?>
        <!-- Step 1: choose a car from the list -->
        <p class='page-intro'>Choose your vehicle from the list, then inspect it before leaving the office.</p>

        <?php if (empty($vehicles)): ?>
            <?php echo empty_state('No vehicles available', 'Ask the office to add a vehicle before doing a check.'); ?>
        <?php else: ?>
            <div class='card card-table'>
            <table class='data-table'>
                <tr><th>Vehicle</th><th>Make</th><th>Today</th><th></th></tr>
                <?php foreach ($vehicles as $v): $done = !empty($checked_today[(int)$v['vehicle_id']]); ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($v['registration']); ?></strong></td>
                    <td><?php echo htmlspecialchars($v['make'] ?: '—'); ?></td>
                    <td><?php echo $done ? "<span style='color:#1a6b1a;'>&#10003; Checked</span>" : "<span style='color:#bbb;'>Not yet</span>"; ?></td>
                    <td><a href='vehicle_inspection.php?vehicle_id=<?php echo $v['vehicle_id']; ?>' class='btn btn-primary' style='font-size:13px; padding:6px 16px;'>Inspect car</a></td>
                </tr>
                <?php endforeach; ?>
            </table>
            </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- Step 2: inspect the chosen vehicle -->
        <div class='card' style='display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;'>
            <div>
                <strong style='font-size:17px; color:#1a3a5c;'><?php echo htmlspecialchars($selected['registration']); ?></strong>
                <?php if ($selected['make']): ?><span style='color:#888;'> &mdash; <?php echo htmlspecialchars($selected['make']); ?></span><?php endif; ?>
            </div>
            <a href='vehicle_inspection.php' class='btn btn-secondary'>&larr; Back to vehicle list</a>
        </div>

            <?php if ($existing): ?>
            <div class='info-banner'>
                &#9998; You already logged a check for this vehicle today
                (<?php echo fmt_datetime($existing['updated_at'] ?? $existing['created_at']); ?>).
                Your entries are loaded below — update and re-submit if anything changed.
            </div>
            <?php endif; ?>

            <form method='POST' action='submit_inspection.php'>
                <?php echo csrf_field(); ?>
                <input type='hidden' name='vehicle_id' value='<?php echo $vehicle_id; ?>'>
                <input type='hidden' name='inspection_date' value='<?php echo $today; ?>'>

                <div class='mf-section card'>
                    <h2>Odometer</h2>
                    <div class='form-group' style='max-width:260px; margin-bottom:0;'>
                        <label>Current Reading (Km)</label>
                        <input type='number' name='odometer_km' placeholder='e.g. 84230'
                               value='<?php echo htmlspecialchars($existing['odometer_km'] ?? ''); ?>'>
                    </div>
                </div>

                <p style='font-size:13px; color:#666; margin:0 0 14px;'>
                    Each item defaults to <strong>N/A</strong> &mdash; set it to <strong>No Problem</strong>,
                    <strong>Attention Needed</strong> or <strong>Critical</strong> as you check it.
                </p>

                <?php foreach ($vehicle_checklist as $sec_key => $sec): ?>
                <div class='mf-section card'>
                    <h2><?php echo htmlspecialchars($sec['label']); ?></h2>
                    <table class='mf-table'>
                        <tr>
                            <th style='width:230px;'>Item</th>
                            <th style='width:150px;'>Status</th>
                            <th>Remarks</th>
                        </tr>
                        <?php foreach ($sec['fields'] as $fk => $fl):
                            $sv = $decoded[$sec_key][$fk]['status']  ?? 'na';
                            $rv = $decoded[$sec_key][$fk]['remarks'] ?? '';
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($fl); ?></td>
                            <td>
                                <select name='items[<?php echo $sec_key; ?>][<?php echo $fk; ?>][status]'>
                                    <?php foreach (vc_statuses() as $val => $label): ?>
                                    <option value='<?php echo $val; ?>'<?php echo $sv === $val ? ' selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type='text' name='items[<?php echo $sec_key; ?>][<?php echo $fk; ?>][remarks]'
                                       placeholder='Optional note' value='<?php echo htmlspecialchars($rv); ?>'>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <?php endforeach; ?>

                <div class='mf-section card'>
                    <h2>Repair Request / Notes</h2>
                    <textarea name='repair_request' rows='3'
                              placeholder='Describe anything that needs repair or attention'><?php
                        echo htmlspecialchars($existing['repair_request'] ?? ''); ?></textarea>
                </div>

                <input type='submit' value='Submit Inspection' class='btn btn-primary btn-full'>
            </form>

        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
