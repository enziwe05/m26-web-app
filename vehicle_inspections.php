<?php
require_once 'incl/dbconn.php';
require_staff();

// Filters
$f_date   = $_GET['date']   ?? '';
$f_status = $_GET['status'] ?? '';

$where  = [];
$params = [];
$types  = '';

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) {
    $where[]  = 'vi.inspection_date = ?';
    $params[] = $f_date;
    $types   .= 's';
}
if (in_array($f_status, ['ok', 'attention', 'critical'], true)) {
    $where[]  = 'vi.overall_status = ?';
    $params[] = $f_status;
    $types   .= 's';
}
$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT vi.inspection_id, vi.inspection_date, vi.overall_status, vi.repair_request,
           vi.odometer_km, v.vehicle_id, v.registration,
           u.first_name, u.last_name
    FROM vehicle_inspections vi
    JOIN vehicles v ON v.vehicle_id = vi.vehicle_id
    JOIN users u    ON u.user_id    = vi.driver_user_id
    $where_clause
    ORDER BY vi.inspection_date DESC, v.registration ASC
    LIMIT 200
";
if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
} else {
    $result = $conn->query($sql);
}

// How many vehicles still need a check today?
$today = date('Y-m-d');
$pending = (int)$conn->query("
    SELECT COUNT(*) AS n FROM vehicles v
    WHERE v.status = 'active'
      AND NOT EXISTS (
        SELECT 1 FROM vehicle_inspections vi
        WHERE vi.vehicle_id = v.vehicle_id AND vi.inspection_date = '" . $conn->real_escape_string($today) . "'
      )
")->fetch_assoc()['n'];

function vi_badge(string $os): string {
    if ($os === 'critical')  return "<span class='badge badge-critical'>Critical</span>";
    if ($os === 'attention') return "<span class='badge badge-attention'>Attention</span>";
    return "<span class='badge badge-completed'>OK</span>";
}

$page_title = 'M26 | Daily Inspection Log';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Daily Inspection Log</h1>
            <a href='view_vehicles.php' class='btn btn-secondary'>&larr; Vehicles</a>
        </div>

        <p class='page-intro'>
            Every daily vehicle check, newest first. Use the filters to find a date or to surface
            vehicles flagged <strong>Attention</strong> or <strong>Critical</strong>. For one vehicle's
            week-by-week view, open it under <a href='view_vehicles.php'>Vehicles</a>.
        </p>

        <?php if ($pending > 0): ?>
        <div class='alert alert-error'>
            <strong><?php echo $pending; ?></strong> active vehicle<?php echo $pending == 1 ? '' : 's'; ?>
            not yet inspected today.
        </div>
        <?php else: ?>
        <div class='alert alert-success'>All active vehicles have been inspected today. &#10003;</div>
        <?php endif; ?>

        <form method='GET' action='vehicle_inspections.php' class='filter-bar'>
            <input type='date' name='date' value='<?php echo htmlspecialchars($f_date); ?>'>
            <select name='status'>
                <option value=''>All Statuses</option>
                <option value='critical'<?php  echo $f_status === 'critical'  ? ' selected' : ''; ?>>Critical</option>
                <option value='attention'<?php echo $f_status === 'attention' ? ' selected' : ''; ?>>Attention</option>
                <option value='ok'<?php        echo $f_status === 'ok'        ? ' selected' : ''; ?>>OK</option>
            </select>
            <button type='submit' class='btn btn-secondary'>Filter</button>
            <a href='vehicle_inspections.php' class='btn btn-secondary'>Clear</a>
        </form>

        <?php
        if (!$result || $result->num_rows == 0) {
            echo "<p>No inspections found.</p>";
        } else {
            echo "<div class='table-scroll'><table class='data-table'>";
            echo "<tr><th>Date</th><th>Vehicle</th><th>Driver</th><th>Status</th><th>Repair Request</th><th></th></tr>";
            while ($row = $result->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . fmt_date($row['inspection_date']) . "</td>";
                echo "<td><strong>" . htmlspecialchars($row['registration']) . "</strong></td>";
                echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
                echo "<td>" . vi_badge($row['overall_status']) . "</td>";
                echo "<td>" . htmlspecialchars($row['repair_request'] !== '' ? $row['repair_request'] : '—') . "</td>";
                echo "<td><a href='inspection_detail.php?inspection_id=" . $row['inspection_id'] . "'>View</a></td>";
                echo "</tr>";
            }
            echo "</table></div>";
        }
        ?>

<?php include 'incl/footer.php'; ?>
