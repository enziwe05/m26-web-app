<?php
if (!isset($_COOKIE['user_role']) || $_COOKIE['user_role'] != 'admin') {
    echo "Unauthorised access! <a href='login.php'>Login</a>";
    exit;
}

include_once 'incl/dbconn.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($search != '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare("
        SELECT s.site_id, s.site_code, s.site_name, s.location,
               COUNT(v.visit_id) AS total_visits
        FROM sites s
        LEFT JOIN visits v ON v.site_id = s.site_id
        WHERE s.site_code LIKE ? OR s.site_name LIKE ? OR s.location LIKE ?
        GROUP BY s.site_id
        ORDER BY s.site_code
    ");
    $stmt->bind_param('sss', $like, $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
} else {
    $result = $conn->query("
        SELECT s.site_id, s.site_code, s.site_name, s.location,
               COUNT(v.visit_id) AS total_visits
        FROM sites s
        LEFT JOIN visits v ON v.site_id = s.site_id
        GROUP BY s.site_id
        ORDER BY s.site_code
    ");
}
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>M26 | Sites</title>
    <link rel='icon' href='images/m26.png' type='image/png'>
    <link rel='stylesheet' href='css/styles.css?v=8'/>
</head>
<body>
<div class='page-wrapper'>
    <?php include_once 'incl/sidebar.php'; ?>
    <div class='main-content'>

        <div class='page-heading'>
            <h1>Sites</h1>
            <a href='add_site.php' class='btn btn-primary'>+ Add Site</a>
        </div>

        <form method='GET' action='view_sites.php' class='filter-bar'>
            <input type='text' name='search' placeholder='Search site code, name or location...'
                   value='<?php echo htmlspecialchars($search); ?>'>
            <button type='submit' class='btn btn-secondary'>Search</button>
            <?php if ($search != ''): ?>
                <a href='view_sites.php' class='btn btn-secondary'>Clear</a>
            <?php endif; ?>
        </form>

        <?php
        if ($result->num_rows == 0) {
            echo "<p>No sites found.</p>";
        } else {
            echo "<div class='table-scroll'>";
            echo "<table class='data-table'>";
            echo "<tr><th>Code</th><th>Name</th><th>Location</th><th>Total Visits</th><th></th></tr>";
            while ($row = $result->fetch_assoc()) {
                echo "<tr>";
                echo "<td><strong>" . htmlspecialchars($row['site_code']) . "</strong></td>";
                echo "<td>" . htmlspecialchars($row['site_name']) . "</td>";
                echo "<td>" . htmlspecialchars($row['location']) . "</td>";
                echo "<td>" . $row['total_visits'] . "</td>";
                echo "<td><a href='site_detail.php?site_id=" . $row['site_id'] . "'>View</a></td>";
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
