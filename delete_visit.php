<?php
require_once 'incl/dbconn.php';
require_admin();

$visit_id = (int)($_GET['visit_id'] ?? 0);
if (!$visit_id) { header('Location: view_visits.php'); exit; }

$stmt = $conn->prepare("
    SELECT v.*, s.site_name, u.first_name, u.last_name
    FROM visits v
    JOIN sites s ON s.site_id = v.site_id
    JOIN users u ON u.user_id = v.assigned_to_user_id
    WHERE v.visit_id = ?
");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$visit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$visit) { header('Location: view_visits.php'); exit; }

$stmt = $conn->prepare("SELECT COUNT(*) AS n FROM visit_items WHERE visit_id = ?");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$item_count = $stmt->get_result()->fetch_assoc()['n'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS n FROM visit_photos WHERE visit_id = ?");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$photo_count = $stmt->get_result()->fetch_assoc()['n'];
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    csrf_check();
    // Delete photo files from disk
    $stmt = $conn->prepare("SELECT photo_filename FROM visit_photos WHERE visit_id = ?");
    $stmt->bind_param('i', $visit_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($p = $res->fetch_assoc()) {
        $file = 'uploads/' . $p['photo_filename'];
        if (file_exists($file)) @unlink($file);
    }
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM visits WHERE visit_id = ?");
    $stmt->bind_param('i', $visit_id);
    $stmt->execute();
    $stmt->close();

    header('Location: view_visits.php');
    exit;
}

$badge = $visit['status'] === 'in_progress' ? 'in-progress' : $visit['status'];
$label = str_replace('_', ' ', $visit['status']);
$page_title = 'M26 | Delete Visit';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Delete Visit</h1>
        </div>

        <div class='card'>
            <p>You are about to permanently delete this visit and all its data.</p>
            <br>
            <table class='data-table' style='margin-bottom:20px;'>
                <tr><th>Site</th><td><?php echo htmlspecialchars($visit['site_name']); ?></td></tr>
                <tr><th>Type</th><td><?php echo htmlspecialchars($visit['visit_type']); ?></td></tr>
                <tr><th>Assigned to</th><td><?php echo htmlspecialchars($visit['first_name'] . ' ' . $visit['last_name']); ?></td></tr>
                <tr><th>Scheduled</th><td><?php echo fmt_date($visit['scheduled_date']); ?></td></tr>
                <tr><th>Status</th><td><span class='badge badge-<?php echo $badge; ?>'><?php echo $label; ?></span></td></tr>
                <tr><th>Checklist items</th><td><?php echo $item_count; ?></td></tr>
                <tr><th>Photos</th><td><?php echo $photo_count; ?></td></tr>
            </table>

            <?php if ($visit['status'] === 'completed'): ?>
                <div class='alert alert-error'>
                    This visit is <strong>completed</strong>. Deleting it will permanently remove all records, photos, and completion timestamps. Are you sure?
                </div>
            <?php endif; ?>

            <form method='POST'>
                <?php echo csrf_field(); ?>
                <input type='hidden' name='confirm' value='1'>
                <button type='submit' class='btn btn-danger'>Yes, Delete Permanently</button>
                &nbsp;
                <a href='visit_detail.php?visit_id=<?php echo $visit_id; ?>' class='btn btn-secondary'>Cancel</a>
            </form>
        </div>

<?php include 'incl/footer.php'; ?>
