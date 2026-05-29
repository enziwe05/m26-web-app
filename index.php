<?php
if (isset($_COOKIE['user_role'])) {
    if ($_COOKIE['user_role'] == 'admin') {
        header('Location: admin_dashboard.php');
    } else {
        header('Location: employee_dashboard.php');
    }
    exit;
}
header('Location: login.php');
exit;
