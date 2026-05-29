<?php
if (!isset($_COOKIE['user_role']) || $_COOKIE['user_role'] != 'admin') {
    echo "Unauthorised access! <a href='login.php'>Login</a>";
    exit;
}

include_once 'incl/dbconn.php';

if (!isset($_GET['visit_id'])) {
    header('Location: view_visits.php');
    exit;
}

$visit_id = (int)$_GET['visit_id'];

// Visit info
$stmt = $conn->prepare("
    SELECT v.*, s.site_code, s.site_name, s.location,
           t.first_name AS tech_first, t.last_name AS tech_last,
           c.first_name AS creator_first, c.last_name AS creator_last
    FROM visits v
    JOIN sites s ON s.site_id = v.site_id
    JOIN users t ON t.user_id = v.assigned_to_user_id
    JOIN users c ON c.user_id = v.created_by_user_id
    WHERE v.visit_id = ?
");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$visit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$visit) {
    echo "Visit not found. <a href='view_visits.php'>Back to visits</a>";
    exit;
}

// Items for this visit
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

// Photos grouped by item
$stmt = $conn->prepare("
    SELECT p.item_id, p.photo_filename, p.photo_type, p.uploaded_at,
           u.first_name, u.last_name
    FROM visit_photos p
    JOIN users u ON u.user_id = p.uploaded_by_user_id
    WHERE p.visit_id = ?
    ORDER BY p.item_id, p.photo_type
");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$photos_result = $stmt->get_result();
$stmt->close();

// Index photos by item_id
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
    <title>M26 | Visit #<?php echo $visit_id; ?></title>
    <link rel='icon' href='images/m26.png' type='image/png'>
    <link rel='stylesheet' href='css/styles.css?v=8'/>
</head>
<body>
<div class='page-wrapper'>
    <?php include_once 'incl/sidebar.php'; ?>
    <div class='main-content'>

        <div class='page-heading'>
            <h1>Visit — <?php echo htmlspecialchars($visit['site_name']); ?></h1>
            <span class='badge badge-<?php echo $badge; ?>'><?php echo $label; ?></span>
        </div>

        <div class='card'>
            <p><strong>Type:</strong> <?php echo htmlspecialchars($visit['visit_type']); ?></p>
            <p><strong>Location:</strong> <?php echo htmlspecialchars($visit['location']); ?></p>
            <p><strong>Assigned to:</strong> <?php echo htmlspecialchars($visit['tech_first'] . ' ' . $visit['tech_last']); ?></p>
            <p><strong>Scheduled:</strong> <?php echo $visit['scheduled_date'] ? $visit['scheduled_date'] : '—'; ?></p>
            <p><strong>Created by:</strong> <?php echo htmlspecialchars($visit['creator_first'] . ' ' . $visit['creator_last']); ?> on <?php echo $visit['created_at']; ?></p>
            <?php if ($visit['completed_at']): ?>
            <p><strong>Completed:</strong> <?php echo $visit['completed_at']; ?></p>
            <?php endif; ?>
            <?php if ($visit['description'] != ''): ?>
            <p><strong>Description:</strong> <?php echo htmlspecialchars($visit['description']); ?></p>
            <?php endif; ?>
        </div>

        <h2>Items</h2>

        <?php
        while ($item = $items->fetch_assoc()) {
            $done_class = $item['is_done'] ? 'item-done' : 'item-pending';
            echo "<div class='card' style='margin-bottom:14px;'>";
            echo "<div class='item-row'>";
            if ($item['is_done']) {
                echo "<span class='item-done-icon'>&#10003;</span>";
            } else {
                echo "<span class='item-pending-icon'>&#9675;</span>";
            }
            echo "<span class='item-desc'>" . htmlspecialchars($item['item_description']) . "</span>";
            if ($item['is_done']) {
                echo "<span class='item-meta'>Done " . $item['completed_at'] . "</span>";
            } else {
                echo "<span class='item-meta'>Pending</span>";
            }
            echo "</div>";

            // Photos for this item
            if (isset($photos[$item['item_id']])) {
                echo "<div class='photo-pair'>";
                foreach ($photos[$item['item_id']] as $photo) {
                    echo "<div>";
                    echo "<img src='uploads/" . htmlspecialchars($photo['photo_filename']) . "' alt='" . $photo['photo_type'] . "'>";
                    echo "<div class='photo-label'>" . ucfirst($photo['photo_type']) . " &mdash; " . htmlspecialchars($photo['first_name']) . "</div>";
                    echo "</div>";
                }
                echo "</div>";
            }

            echo "</div>";
        }
        ?>

        <br>
        <p>
            <a href='site_detail.php?site_id=<?php echo $visit['site_id']; ?>'>&larr; Back to Site</a>
            &nbsp;|&nbsp;
            <a href='view_visits.php'>&larr; All Visits</a>
        </p>

    </div>
</div>
</body>
</html>
