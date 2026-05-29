<?php
if (!isset($_COOKIE['user_role']) || $_COOKIE['user_role'] != 'admin') {
    echo "Unauthorised access! <a href='login.php'>Login</a>";
    exit;
}

include_once 'incl/dbconn.php';

if (!isset($_GET['site_id'])) {
    header('Location: view_sites.php');
    exit;
}

$site_id = $_GET['site_id'];

$stmt = $conn->prepare("SELECT * FROM sites WHERE site_id = ?");
$stmt->bind_param('i', $site_id);
$stmt->execute();
$site = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$site) {
    echo "Site not found. <a href='view_sites.php'>Back to sites</a>";
    exit;
}

// Visit history for this site
$stmt = $conn->prepare("
    SELECT v.visit_id, v.visit_type, v.status, v.scheduled_date, v.completed_at,
           u.first_name, u.last_name,
           (SELECT COUNT(*) FROM visit_items WHERE visit_id = v.visit_id) AS total_items,
           (SELECT COUNT(*) FROM visit_items WHERE visit_id = v.visit_id AND is_done = 1) AS done_items
    FROM visits v
    JOIN users u ON u.user_id = v.assigned_to_user_id
    WHERE v.site_id = ?
    ORDER BY v.scheduled_date DESC
");
$stmt->bind_param('i', $site_id);
$stmt->execute();
$visits = $stmt->get_result();
$stmt->close();

// Documents
$stmt = $conn->prepare("
    SELECT d.document_id, d.doc_name, d.doc_filename, d.uploaded_at,
           u.first_name, u.last_name
    FROM site_documents d
    JOIN users u ON u.user_id = d.uploaded_by_user_id
    WHERE d.site_id = ?
    ORDER BY d.uploaded_at DESC
");
$stmt->bind_param('i', $site_id);
$stmt->execute();
$documents = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>M26 | <?php echo htmlspecialchars($site['site_code']); ?></title>
    <link rel='icon' href='images/m26.png' type='image/png'>
    <link rel='stylesheet' href='css/styles.css?v=8'/>
</head>
<body>
<div class='page-wrapper'>
    <?php include_once 'incl/sidebar.php'; ?>
    <div class='main-content'>

        <div class='page-heading'>
            <h1><?php echo htmlspecialchars($site['site_name']); ?></h1>
            <a href='create_visit.php?site_id=<?php echo $site_id; ?>' class='btn btn-primary'>+ Create Visit</a>
        </div>

        <div class='card'>
            <p><strong>Location:</strong> <?php echo htmlspecialchars($site['location']); ?></p>
            <?php if ($site['latitude'] != ''): ?>
            <p><strong>Coordinates:</strong> <?php echo $site['latitude'] . ', ' . $site['longitude']; ?></p>
            <?php endif; ?>
            <?php if ($site['notes'] != ''): ?>
            <p><strong>Notes:</strong> <?php echo htmlspecialchars($site['notes']); ?></p>
            <?php endif; ?>
        </div>

        <h2>Documents</h2>
        <p><a href='upload_document.php?site_id=<?php echo $site_id; ?>' class='btn btn-secondary'>+ Upload Document</a></p>

        <?php
        if ($documents->num_rows == 0) {
            echo "<p>No documents uploaded for this site.</p>";
        } else {
            echo "<div class='table-scroll'>";
            echo "<table class='data-table'>";
            echo "<tr><th>Name</th><th>Uploaded</th><th>By</th><th></th></tr>";
            while ($doc = $documents->fetch_assoc()) {
                $ext = strtolower(pathinfo($doc['doc_filename'], PATHINFO_EXTENSION));
                echo "<tr>";
                echo "<td><a href='uploads/" . htmlspecialchars($doc['doc_filename']) . "' target='_blank'>" . htmlspecialchars($doc['doc_name']) . "</a></td>";
                echo "<td>" . $doc['uploaded_at'] . "</td>";
                echo "<td>" . htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']) . "</td>";
                echo "<td><a href='delete_document.php?document_id=" . $doc['document_id'] . "&site_id=$site_id' onclick='return confirm(\"Delete this document?\")'>Delete</a></td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        ?>

        <br>
        <h2>Visit History</h2>

        <?php
        if ($visits->num_rows == 0) {
            echo "<p>No visits recorded for this site.</p>";
        } else {
            echo "<div class='table-scroll'>";
            echo "<table class='data-table'>";
            echo "<tr><th>Type</th><th>Tech</th><th>Scheduled</th><th>Progress</th><th>Status</th><th>Completed</th><th></th></tr>";
            while ($row = $visits->fetch_assoc()) {
                $badge = $row['status'] == 'in_progress' ? 'in-progress' : $row['status'];
                $label = str_replace('_', ' ', $row['status']);
                $progress = $row['done_items'] . '/' . $row['total_items'];
                $completed = $row['completed_at'] ? $row['completed_at'] : '—';
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['visit_type']) . "</td>";
                echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
                echo "<td>" . $row['scheduled_date'] . "</td>";
                echo "<td>" . $progress . "</td>";
                echo "<td><span class='badge badge-$badge'>$label</span></td>";
                echo "<td>" . $completed . "</td>";
                echo "<td><a href='visit_detail.php?visit_id=" . $row['visit_id'] . "'>View</a></td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        ?>

        <br>
        <p><a href='view_sites.php'>&larr; Back to Sites</a></p>

    </div>
</div>
</body>
</html>
