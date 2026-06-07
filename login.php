<?php
require_once 'incl/dbconn.php';

// Already logged in — send to the right dashboard.
$role = current_user_role();
if ($role !== '') {
    header('Location: ' . ($role === 'employee' ? 'employee_dashboard.php' : 'admin_dashboard.php'));
    exit;
}

$error = "";

if (isset($_POST['username'])) {
    csrf_check();
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT user_id, first_name, last_name, password_hash, role FROM users WHERE username = ? AND status != 'inactive'");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);   // new session id on login (prevents fixation)
        $_SESSION['user_id']   = (int) $user['user_id'];
        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['user_role'] = $user['role'];

        // "Keep me logged in" → extend the session cookie to 7 days
        if (isset($_POST['remember']) && $_POST['remember'] === '1') {
            $p = session_get_cookie_params();
            setcookie(session_name(), session_id(), time() + 604800, $p['path'], $p['domain'], $p['secure'], true);
        }

        header('Location: ' . (in_array($user['role'], ['admin', 'supervisor'], true) ? 'admin_dashboard.php' : 'employee_dashboard.php'));
        exit;
    }
    $error = "Invalid username or password.";
}
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>M26</title>
    <link rel='icon' href='images/m26.png' type='image/png'>
    <link rel='stylesheet' href='css/styles.css?v=13'/>
</head>
<body>
<div class='login-page'>
    <div class='login-card'>
        <div class='logo-block'>
            <img src='images/m26.png' alt='M26' class='login-logo'>
            <h1>M26</h1>
            <p>Power &amp; Telecoms Specialists</p>
        </div>

        <?php if ($error != ""): ?>
            <div class='alert alert-error'><?php echo $error; ?></div>
        <?php endif; ?>

        <form method='POST' action='login.php'>
            <?php echo csrf_field(); ?>
            <div class='form-group'>
                <label>Username</label>
                <input type='text' name='username' value='<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ""; ?>'>
            </div>
            <div class='form-group'>
                <label>Password</label>
                <input type='password' name='password'>
            </div>
            <div class='form-group' style='display:flex;align-items:center;gap:8px;'>
                <input type='checkbox' name='remember' value='1' id='remember' style='width:auto;'>
                <label for='remember' style='margin:0;font-weight:400;color:#555;'>Keep me logged in for 7 days</label>
            </div>
            <input type='submit' value='Login' class='btn btn-primary btn-full'>
        </form>
    </div>
</div>
</body>
</html>
