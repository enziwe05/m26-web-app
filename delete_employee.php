<?php
require_once 'incl/dbconn.php';
require_admin();

$user_id = (int)($_GET['user_id'] ?? 0);
if (!$user_id) { header('Location: view_employees.php'); exit; }

// Load the user
$stmt = $conn->prepare("SELECT first_name, last_name, username FROM users WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { header('Location: view_employees.php'); exit; }

// Count visits assigned to or created by this user
$stmt = $conn->prepare("SELECT COUNT(*) AS n FROM visits WHERE assigned_to_user_id = ? OR created_by_user_id = ?");
$stmt->bind_param('ii', $user_id, $user_id);
$stmt->execute();
$visit_count = $stmt->get_result()->fetch_assoc()['n'];
$stmt->close();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    csrf_check();
    if ($visit_count > 0) {
        $error = "Cannot delete — this person has $visit_count visit(s) on record. Set them to Inactive instead.";
    } else {
        $conn->query("UPDATE users SET supervisor_id = NULL WHERE supervisor_id = $user_id");
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
        flash('Employee deleted.');
        header('Location: view_employees.php');
        exit;
    }
}
$page_title = 'M26 | Delete Employee';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Delete Employee</h1>
        </div>

        <?php if ($error): ?>
            <div class='alert alert-error'><?php echo $error; ?></div>
            <p><a href='view_employees.php' class='btn btn-secondary'>&larr; Back to Employees</a></p>
        <?php elseif ($visit_count > 0): ?>
            <div class='alert alert-error'>
                <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong>
                has <?php echo $visit_count; ?> visit(s) on record and cannot be deleted.
                Set their status to <strong>Inactive</strong> from the Employees list instead.
            </div>
            <p><a href='view_employees.php' class='btn btn-secondary'>&larr; Back to Employees</a></p>
        <?php else: ?>
            <div class='card'>
                <p>Are you sure you want to permanently delete
                   <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong>
                   (<?php echo htmlspecialchars($user['username']); ?>)?
                   This cannot be undone.
                </p>
                <br>
                <form method='POST'>
                    <?php echo csrf_field(); ?>
                    <input type='hidden' name='confirm' value='1'>
                    <button type='submit' class='btn btn-danger'>Yes, Delete</button>
                    &nbsp;
                    <a href='view_employees.php' class='btn btn-secondary'>Cancel</a>
                </form>
            </div>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
