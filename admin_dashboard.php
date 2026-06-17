<?php
require_once 'incl/dbconn.php';
require_staff();

$total_sites     = $conn->query("SELECT COUNT(*) AS n FROM sites")->fetch_assoc()['n'];
$total_employees = $conn->query("SELECT COUNT(*) AS n FROM users WHERE role = 'employee' AND status = 'active'")->fetch_assoc()['n'];
$in_progress     = $conn->query("SELECT COUNT(*) AS n FROM visits WHERE status = 'in_progress'")->fetch_assoc()['n'];
$today           = $conn->query("SELECT COUNT(*) AS n FROM visits WHERE scheduled_date = CURDATE()")->fetch_assoc()['n'];

$recent = $conn->query("
    SELECT v.visit_id, s.site_code, s.site_name, v.visit_type, v.status,
           v.scheduled_date, u.first_name, u.last_name
    FROM visits v
    JOIN sites s ON s.site_id = v.site_id
    JOIN users u ON u.user_id = v.assigned_to_user_id
    ORDER BY v.created_at DESC
    LIMIT 10
");
$page_title = 'M26 | Dashboard';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Dashboard</h1>
            <span>Welcome, <?php echo htmlspecialchars(current_user_name()); ?></span>
        </div>

        <div class='stat-row'>
            <div class='stat-box'>
                <div class='stat-label'>Total Sites</div>
                <div class='stat-value'><?php echo $total_sites; ?></div>
            </div>
            <div class='stat-box'>
                <div class='stat-label'>Active Staff</div>
                <div class='stat-value'><?php echo $total_employees; ?></div>
            </div>
            <div class='stat-box'>
                <div class='stat-label'>In Progress</div>
                <div class='stat-value'><?php echo $in_progress; ?></div>
            </div>
            <div class='stat-box'>
                <div class='stat-label'>Scheduled Today</div>
                <div class='stat-value'><?php echo $today; ?></div>
            </div>
        </div>

        <div class='page-heading'>
            <h2>Recent Visits</h2>
            <a href='create_visit.php' class='btn btn-primary'>+ Create Visit</a>
        </div>

        <?php
        if ($recent->num_rows == 0) {
            echo "<p>No visits yet. <a href='create_visit.php'>Create the first one.</a></p>";
        } else {
            echo "<div class='table-scroll'>";
            echo "<table class='data-table'>";
            echo "<tr><th>Site</th><th>Type</th><th>Tech</th><th>Scheduled</th><th>Status</th><th></th></tr>";
            while ($row = $recent->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['site_name']) . "</td>";
                echo "<td>" . htmlspecialchars($row['visit_type']) . "</td>";
                echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
                echo "<td>" . fmt_date($row['scheduled_date']) . "</td>";
                echo "<td>" . status_badge($row['status']) . "</td>";
                echo "<td><a href='visit_detail.php?visit_id=" . $row['visit_id'] . "'>View</a></td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        ?>

        <br>
        <h2>Quick Links</h2>
        <p>
            <a href='view_sites.php' class='btn btn-secondary'>View Sites</a>
            &nbsp;
            <a href='view_visits.php' class='btn btn-secondary'>All Visits</a>
            &nbsp;
            <a href='view_employees.php' class='btn btn-secondary'>Employees</a>
            &nbsp;
            <a href='view_vehicles.php' class='btn btn-secondary'>Vehicles</a>
            &nbsp;
            <a href='add_site.php' class='btn btn-secondary'>Add Site</a>
        </p>

<?php include 'incl/footer.php'; ?>
