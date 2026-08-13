<?php
require_once 'incl/dbconn.php';
require_staff();

// Each vehicle with its most recent inspection (date + overall status)
$sql = "
    SELECT v.vehicle_id, v.make, v.fleet_number, v.registration, v.status,
           li.inspection_date AS last_date,
           li.overall_status  AS last_status
    FROM vehicles v
    LEFT JOIN (
        SELECT vi.vehicle_id, vi.inspection_date, vi.overall_status
        FROM vehicle_inspections vi
        JOIN (
            SELECT vehicle_id, MAX(inspection_date) AS md
            FROM vehicle_inspections GROUP BY vehicle_id
        ) m ON m.vehicle_id = vi.vehicle_id AND m.md = vi.inspection_date
    ) li ON li.vehicle_id = v.vehicle_id
    ORDER BY v.status ASC, v.registration ASC
";
$result = $conn->query($sql);

$today = date('Y-m-d');

// Pull rows once so we can show an at-a-glance summary before the table.
$vehicles = [];
if ($result) while ($r = $result->fetch_assoc()) $vehicles[] = $r;

$c_active = $c_checked = $c_flagged = $c_notchecked = 0;
foreach ($vehicles as $r) {
    $active = $r['status'] !== 'inactive';
    if (!$active) continue;
    $c_active++;
    if ($r['last_status'] === 'critical' || $r['last_status'] === 'attention') $c_flagged++;
    if ($r['last_date'] === $today) $c_checked++; else $c_notchecked++;
}

$page_title = 'M26 | Vehicles';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Vehicles &amp; Weekly Checks</h1>
            <?php if (is_admin()): ?>
            <a href='add_vehicle.php' class='btn btn-primary'>+ Add Vehicle</a>
            <?php endif; ?>
        </div>

        <p class='page-intro'>
            Your vehicle fleet. <strong>Open a vehicle</strong> to see its weekly inspection checklist (Mon&ndash;Sun)
            and full history. Want today's checks across every vehicle instead?
            <a href='vehicle_inspections.php'>Go to the Daily Inspection Log &rarr;</a>
        </p>

        <?php if (!$vehicles): ?>
            <?php echo empty_state(
                'No vehicles yet',
                'Add your company vehicles here, then drivers can run their daily checks.',
                is_admin() ? 'add_vehicle.php' : '',
                is_admin() ? '+ Add Vehicle' : ''
            ); ?>
        <?php else: ?>

        <!-- At-a-glance fleet summary -->
        <div class='stat-row'>
            <div class='stat-box'>
                <div class='stat-label'>Fleet Size</div>
                <div class='stat-value'><?php echo $c_active; ?></div>
            </div>
            <a class='stat-box<?php echo $c_notchecked === 0 ? ' stat-box--green' : ''; ?>' href='vehicle_inspections.php'>
                <div class='stat-label'>Checked Today</div>
                <div class='stat-value'><?php echo $c_checked; ?> / <?php echo $c_active; ?></div>
            </a>
            <a class='stat-box<?php echo $c_flagged > 0 ? ' stat-box--amber' : ''; ?>' href='vehicle_inspections.php?status=attention'>
                <div class='stat-label'>&#9888; Needs Attention</div>
                <div class='stat-value'><?php echo $c_flagged; ?></div>
            </a>
            <a class='stat-box<?php echo $c_notchecked > 0 ? ' stat-box--red' : ''; ?>' href='vehicle_inspections.php'>
                <div class='stat-label'>Not Checked Today</div>
                <div class='stat-value'><?php echo $c_notchecked; ?></div>
            </a>
        </div>

        <?php
            echo "<div class='table-scroll'><table class='data-table'>";
            echo "<tr><th>Vehicle</th><th>Last Check</th><th>Condition</th><th></th></tr>";
            foreach ($vehicles as $row) {
                // "Checked today?" indicator
                $last = $row['last_date'];
                if ($last === $today) {
                    $check = "<span style='color:#1a6b1a; font-weight:600;'>&#10003; Today</span>";
                } elseif ($last) {
                    $check = "<span style='color:#b35900;'>" . fmt_date($last) . "</span>";
                } else {
                    $check = "<span style='color:#bbb;'>Never</span>";
                }

                // Overall status badge of the latest inspection
                $os = $row['last_status'];
                if ($os === 'critical')      { $sb = "<span class='badge badge-critical'>Critical</span>"; }
                elseif ($os === 'attention') { $sb = "<span class='badge badge-attention'>Attention</span>"; }
                elseif ($os === 'ok')        { $sb = "<span class='badge badge-completed'>OK</span>"; }
                else                         { $sb = "<span style='color:#bbb;'>&mdash;</span>"; }

                // Vehicle: registration over a muted make · fleet sub-line
                $sub = trim(($row['make'] ?? '') . ($row['fleet_number'] ? ' · ' . $row['fleet_number'] : ''));
                if ($row['status'] === 'inactive') $sub = ($sub !== '' ? $sub . ' · ' : '') . 'inactive';

                $dim = $row['status'] === 'inactive' ? " style='opacity:.55;'" : '';
                echo "<tr$dim>";
                echo "<td><div class='cell-name'>" . htmlspecialchars($row['registration']) . "</div>"
                   . "<div class='cell-sub'>" . htmlspecialchars($sub !== '' ? $sub : '—') . "</div></td>";
                echo "<td>" . $check . "</td>";
                echo "<td>" . $sb . "</td>";
                echo "<td><a href='vehicle_detail.php?vehicle_id=" . (int)$row['vehicle_id'] . "'>Weekly checklist &rarr;</a></td>";
                echo "</tr>";
            }
            echo "</table></div>";
        ?>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
