<?php
require_once 'incl/dbconn.php';
require_employee();   // field technicians (the drivers) fill this in

$vehicle_checklist = require __DIR__ . '/incl/vehicle_checklist.php';

$today      = date('Y-m-d');
$vehicle_id = (int)($_GET['vehicle_id'] ?? 0);

// Active vehicles to choose from
$vehicles = $conn->query("SELECT vehicle_id, registration, make, fleet_number FROM vehicles WHERE status = 'active' ORDER BY registration");

// If a vehicle is already chosen, load today's inspection (so they can edit/continue)
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

        <p class='page-intro'>
            Inspect your vehicle before leaving the office. Pick the vehicle, then mark each item.
        </p>

        <?php if (isset($_GET['saved'])): ?>
        <div class='alert alert-success'>Inspection saved. Safe travels!</div>
        <?php endif; ?>

        <!-- Step 1: pick a vehicle (reloads to preload any check already done today) -->
        <div class='card'>
            <form method='GET' action='vehicle_inspection.php' class='filter-bar' style='margin-bottom:0;'>
                <div style='flex:1; min-width:200px;'>
                    <label>Vehicle</label>
                    <select name='vehicle_id' onchange='this.form.submit()'>
                        <option value=''>-- Select a vehicle --</option>
                        <?php while ($v = $vehicles->fetch_assoc()):
                            $lbl = $v['registration'] . ($v['make'] ? ' — ' . $v['make'] : '');
                        ?>
                        <option value='<?php echo $v['vehicle_id']; ?>'<?php echo $v['vehicle_id'] == $vehicle_id ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($lbl); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </form>
        </div>

        <?php if ($vehicle_id <= 0): ?>
            <p style='color:#888;'>Select a vehicle above to start its daily check.</p>
        <?php else: ?>

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
