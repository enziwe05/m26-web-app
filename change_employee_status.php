<?php
if (!isset($_COOKIE['user_role']) || $_COOKIE['user_role'] != 'admin') {
    header('Location: view_employees.php');
    exit;
}

include_once 'incl/dbconn.php';

$user_id = (int)($_POST['user_id'] ?? 0);
$status  = $_POST['status'] ?? '';
$allowed = ['active', 'on_leave', 'inactive'];

if ($user_id < 1 || !in_array($status, $allowed)) {
    header('Location: view_employees.php');
    exit;
}

$is_active = ($status === 'active') ? 1 : 0;

$stmt = $conn->prepare("UPDATE users SET status = ?, is_active = ? WHERE user_id = ?");
$stmt->bind_param('sii', $status, $is_active, $user_id);
$stmt->execute();
$stmt->close();

header('Location: view_employees.php');
exit;
