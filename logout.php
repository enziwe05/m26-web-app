<?php
require_once 'incl/dbconn.php';

// Clear the session
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

// Also clear the old cookie-based auth (from before the session migration)
foreach (['user_id', 'user_name', 'user_role'] as $c) {
    setcookie($c, '', time() - 3600, '/');
}

header('Location: login.php');
exit;
