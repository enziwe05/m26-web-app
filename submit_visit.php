<?php
if (!isset($_COOKIE['user_role']) || $_COOKIE['user_role'] != 'employee') {
    echo "Unauthorised access! <a href='login.php'>Login</a>";
    exit;
}

include_once 'incl/dbconn.php';

if (!isset($_POST['visit_id'])) {
    header('Location: employee_dashboard.php');
    exit;
}

$visit_id = (int)$_POST['visit_id'];
$user_id  = (int)$_COOKIE['user_id'];

// Confirm the visit belongs to this tech and is not already completed
$stmt = $conn->prepare("SELECT visit_id, status FROM visits WHERE visit_id = ? AND assigned_to_user_id = ?");
$stmt->bind_param('ii', $visit_id, $user_id);
$stmt->execute();
$visit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$visit || $visit['status'] == 'completed') {
    header('Location: employee_dashboard.php');
    exit;
}

// Double-check all items are done
$stmt = $conn->prepare("SELECT COUNT(*) AS n FROM visit_items WHERE visit_id = ? AND is_done = 0");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$pending = $stmt->get_result()->fetch_assoc()['n'];
$stmt->close();

if ($pending > 0) {
    // Not all done — send back
    header('Location: employee_visit.php?visit_id=' . $visit_id);
    exit;
}

// Mark visit complete
$now = date('Y-m-d H:i:s');
$stmt = $conn->prepare("UPDATE visits SET status = 'completed', completed_at = ? WHERE visit_id = ?");
$stmt->bind_param('si', $now, $visit_id);
$stmt->execute();
$stmt->close();

header('Location: employee_dashboard.php');
exit;
