<?php
require_once 'incl/dbconn.php';
require_admin();
csrf_check();

$user_id = (int)($_POST['user_id'] ?? 0);
$status  = $_POST['status'] ?? '';
$allowed = ['active', 'on_leave', 'inactive'];

if ($user_id < 1 || !in_array($status, $allowed, true)) {
    header('Location: view_employees.php');
    exit;
}

$stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ?");
$stmt->bind_param('si', $status, $user_id);
$stmt->execute();
$stmt->close();

flash('Employee status updated.');
header('Location: view_employees.php');
exit;
