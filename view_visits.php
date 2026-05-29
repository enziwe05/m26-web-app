<?php
if (!isset($_COOKIE['user_role']) || $_COOKIE['user_role'] != 'admin') {
    echo "Unauthorised access! <a href='login.php'>Login</a>";
    exit;
}

include_once 'incl/dbconn.php';

$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_tech   = isset($_GET['tech_id']) ? (int)$_GET['tech_id'] : 0;

$techs = $conn->query("SELECT user_id, first_name, last_name FROM users WHERE role = 'employee' AND status = 'active' ORDER BY first_name");

// Build query with optional filters
$where = array();
$params = array();
$types  = '';

if ($filter_status != '') {
    $where[]  = "v.status = ?";
    $params[] = $filter_status;
    $types   .= 's';
}
if ($filter_tech > 0) {
    $where[]  = "v.assigned_to_user_id = ?";
    $params[] = $filter_tech;
    $types   .= 'i';
}

$where_clause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT v.visit_id, s.site_code, s.site_name, v.visit_type, v.status,
           v.scheduled_date, v.completed_at,
           u.first_name, u.last_name,
           (SELECT COUNT(*) FROM visit_items WHERE visit_id = v.visit_id) AS total_items,
           (SELECT COUNT(*) FROM visit_items WHERE visit_id = v.visit_id AND is_done = 1) AS done_items
    FROM visits v
    JOIN sites s ON s.site_id = v.site_id
    JOIN users u ON u.user_id = v.assigned_to_user_id
    $where_clause
    ORDER BY v.scheduled_date DESC
";

if (count($params) > 0) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
} else {
    $result = $conn->query($sql);
}
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>M26 | All Visits</title>
    <link rel='icon' href='images/m26.png' type='image/png'>
    <link rel='stylesheet' href='css/styles.css?v=8'/>
</head>
<body>
<div class='page-wrapper'>
    <?php include_once 'incl/sidebar.php'; ?>
    <div class='main-content'>

        <div class='page-heading'>
            <h1>All Visits</h1>
            <a href='create_visit.php' class='btn btn-primary'>+ Create Visit</a>
        </div>

        <form method='GET' action='view_visits.php' class='filter-bar'>
            <select name='status'>
                <option value=''>All Statuses</option>
                <option value='assigned'    <?php if ($filter_status == 'assigned')    echo 'selected'; ?>>Assigned</option>
                <option value='in_progress' <?php if ($filter_status == 'in_progress') echo 'selected'; ?>>In Progress</option>
                <option value='completed'   <?php if ($filter_status == 'completed')   echo 'selected'; ?>>Completed</option>
            </select>
            <select name='tech_id'>
                <option value=''>All Technicians</option>
                <?php
                while ($t = $techs->fetch_assoc()) {
                    $sel = ($t['user_id'] == $filter_tech) ? 'selected' : '';
                    echo "<option value='" . $t['user_id'] . "' $sel>" . htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) . "</option>";
                }
                ?>
            </select>
            <button type='submit' class='btn btn-secondary'>Filter</button>
            <a href='view_visits.php' class='btn btn-secondary'>Clear</a>
        </form>

        <?php
        if ($result->num_rows == 0) {
            echo "<p>No visits found.</p>";
        } else {
            echo "<div class='table-scroll'>";
            echo "<table class='data-table'>";
            echo "<tr><th>Site</th><th>Type</th><th>Tech</th><th>Scheduled</th><th>Progress</th><th>Status</th><th></th></tr>";
            while ($row = $result->fetch_assoc()) {
                $badge    = $row['status'] == 'in_progress' ? 'in-progress' : $row['status'];
                $label    = str_replace('_', ' ', $row['status']);
                $progress = $row['done_items'] . '/' . $row['total_items'];
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['site_name']) . "</td>";
                echo "<td>" . htmlspecialchars($row['visit_type']) . "</td>";
                echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
                echo "<td>" . $row['scheduled_date'] . "</td>";
                echo "<td>" . $progress . "</td>";
                echo "<td><span class='badge badge-$badge'>$label</span></td>";
                echo "<td><a href='visit_detail.php?visit_id=" . $row['visit_id'] . "'>View</a></td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        ?>

    </div>
</div>
</body>
</html>
