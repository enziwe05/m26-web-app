<?php
if (!isset($_COOKIE['user_role']) || $_COOKIE['user_role'] != 'employee') {
    echo "Unauthorised access! <a href='login.php'>Login</a>";
    exit;
}

include_once 'incl/dbconn.php';

if (!isset($_GET['visit_id'])) {
    header('Location: employee_dashboard.php');
    exit;
}

$visit_id = (int)$_GET['visit_id'];
$user_id  = (int)$_COOKIE['user_id'];

// Only allow the assigned tech to open this visit
$stmt = $conn->prepare("
    SELECT v.*, s.site_code, s.site_name, s.location
    FROM visits v
    JOIN sites s ON s.site_id = v.site_id
    WHERE v.visit_id = ? AND v.assigned_to_user_id = ?
");
$stmt->bind_param('ii', $visit_id, $user_id);
$stmt->execute();
$visit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$visit) {
    echo "Visit not found or not assigned to you. <a href='employee_dashboard.php'>Back</a>";
    exit;
}

// Items
$stmt = $conn->prepare("
    SELECT i.item_id, i.item_description, i.is_done, i.completed_at
    FROM visit_items i
    WHERE i.visit_id = ?
    ORDER BY i.sort_order
");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$items = $stmt->get_result();
$stmt->close();

// Count done vs total to decide if submit is available
$stmt = $conn->prepare("SELECT COUNT(*) AS n FROM visit_items WHERE visit_id = ? AND is_done = 0");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$pending_count = $stmt->get_result()->fetch_assoc()['n'];
$stmt->close();

// Photos indexed by item_id
$stmt = $conn->prepare("SELECT item_id, photo_filename, photo_type FROM visit_photos WHERE visit_id = ? ORDER BY photo_type");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$photos_result = $stmt->get_result();
$stmt->close();

$photos = array();
while ($p = $photos_result->fetch_assoc()) {
    $photos[$p['item_id']][] = $p;
}

$badge = $visit['status'] == 'in_progress' ? 'in-progress' : $visit['status'];
$label = str_replace('_', ' ', $visit['status']);
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>M26 | <?php echo htmlspecialchars($visit['site_code']); ?></title>
    <link rel='icon' href='images/m26.png' type='image/png'>
    <link rel='stylesheet' href='css/styles.css?v=8'/>
</head>
<body>
<div class='page-wrapper'>
    <?php include_once 'incl/sidebar.php'; ?>
    <div class='main-content'>

        <div class='page-heading'>
            <h1><?php echo htmlspecialchars($visit['site_name']); ?></h1>
            <span class='badge badge-<?php echo $badge; ?>'><?php echo $label; ?></span>
        </div>

        <div class='card'>
            <p><strong>Type:</strong> <?php echo htmlspecialchars($visit['visit_type']); ?></p>
            <p><strong>Location:</strong> <?php echo htmlspecialchars($visit['location']); ?></p>
            <?php if ($visit['description'] != ''): ?>
            <p><strong>Notes:</strong> <?php echo htmlspecialchars($visit['description']); ?></p>
            <?php endif; ?>
            <?php if ($visit['scheduled_date']): ?>
            <p><strong>Scheduled:</strong> <?php echo $visit['scheduled_date']; ?></p>
            <?php endif; ?>
        </div>

        <h2>Items</h2>

        <?php
        while ($item = $items->fetch_assoc()) {
            echo "<div class='card' style='margin-bottom:12px;'>";
            echo "<div class='item-row'>";

            if ($item['is_done']) {
                echo "<span class='item-done-icon'>&#10003;</span>";
                echo "<span class='item-desc'>" . htmlspecialchars($item['item_description']) . "</span>";
                echo "<span class='item-meta'>Done " . $item['completed_at'] . "</span>";
            } else {
                echo "<span class='item-pending-icon'>&#9675;</span>";
                echo "<span class='item-desc'>" . htmlspecialchars($item['item_description']) . "</span>";
                if ($visit['status'] != 'completed') {
                    echo "<a href='complete_item.php?item_id=" . $item['item_id'] . "&visit_id=$visit_id' class='btn btn-primary' style='font-size:13px;'>Mark Done</a>";
                }
            }

            echo "</div>";

            // Show photos if any
            if (isset($photos[$item['item_id']])) {
                echo "<div class='photo-pair'>";
                foreach ($photos[$item['item_id']] as $photo) {
                    echo "<div>";
                    echo "<img src='uploads/" . htmlspecialchars($photo['photo_filename']) . "' alt='" . $photo['photo_type'] . "'>";
                    echo "<div class='photo-label'>" . ucfirst($photo['photo_type']) . "</div>";
                    echo "</div>";
                }
                echo "</div>";
            }

            echo "</div>";
        }
        ?>

        <?php if ($pending_count == 0 && $visit['status'] != 'completed'): ?>
        <div class='card' style='background:#f0fff0; border-color:#a8d5a8;'>
            <p>All items are done. Submit the visit to mark it complete.</p>
            <form method='POST' action='submit_visit.php'>
                <input type='hidden' name='visit_id' value='<?php echo $visit_id; ?>'>
                <input type='submit' value='Submit Visit' class='btn btn-primary'>
            </form>
        </div>
        <?php endif; ?>

        <br>
        <p><a href='employee_dashboard.php'>&larr; My Visits</a></p>

    </div>
</div>
</body>
</html>
