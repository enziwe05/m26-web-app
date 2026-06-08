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

        <?php
        if (!$result || $result->num_rows == 0) {
            echo "<p>No vehicles yet." . (is_admin() ? " <a href='add_vehicle.php'>Add the first one.</a>" : "") . "</p>";
        } else {
            echo "<div class='table-scroll'><table class='data-table'>";
            echo "<tr><th>Registration</th><th>Make</th><th>Fleet #</th><th>Last Check</th><th>Status</th><th></th></tr>";
            while ($row = $result->fetch_assoc()) {
                // "Checked today?" indicator
                $last = $row['last_date'];
                if ($last === $today) {
                    $check = "<span style='color:#1a6b1a;'>&#10003; Today</span>";
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

                $dim = $row['status'] === 'inactive' ? " style='opacity:.55;'" : '';
                echo "<tr$dim>";
                echo "<td><strong>" . htmlspecialchars($row['registration']) . "</strong>" .
                     ($row['status'] === 'inactive' ? " <span style='font-size:11px;color:#888;'>(inactive)</span>" : "") . "</td>";
                echo "<td>" . htmlspecialchars($row['make'] ?? '—') . "</td>";
                echo "<td>" . htmlspecialchars($row['fleet_number'] ?? '—') . "</td>";
                echo "<td>" . $check . "</td>";
                echo "<td>" . $sb . "</td>";
                echo "<td><a href='vehicle_detail.php?vehicle_id=" . $row['vehicle_id'] . "'>Weekly checklist &rarr;</a></td>";
                echo "</tr>";
            }
            echo "</table></div>";
        }
        ?>

<?php include 'incl/footer.php'; ?>
