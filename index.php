<?php
require_once 'incl/dbconn.php';

$role = current_user_role();
if ($role === 'admin' || $role === 'supervisor') {
    header('Location: admin_dashboard.php');
} elseif ($role === 'employee') {
    header('Location: employee_dashboard.php');
} else {
    header('Location: login.php');
}
exit;
