<?php
require_once 'incl/dbconn.php';

// Where each role lands after login.
// Employees start their day on the Clock In page and only move on to their
// dashboard once they've clocked in today — so the first thing they do each
// day is clock in.
function home_for_role(mysqli $conn, string $role, int $user_id): string {
    if ($role === 'client') return 'client_dashboard.php';
    if ($role !== 'employee') return 'admin_dashboard.php'; // admin & supervisor

    $stmt = $conn->prepare("SELECT 1 FROM time_entries WHERE user_id = ? AND DATE(clock_in_at) = CURDATE() LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $clocked_today = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();

    return $clocked_today ? 'employee_dashboard.php' : 'clock.php';
}

// Already logged in — send to the right landing page.
$role = current_user_role();
if ($role !== '') {
    header('Location: ' . home_for_role($conn, $role, current_user_id()));
    exit;
}

$error = "";

if (isset($_POST['username'])) {
    csrf_check();
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (login_is_blocked($conn, $ip)) {
        $error = "Too many failed attempts. Please wait " . LOGIN_WINDOW_MINS . " minutes and try again.";
    } else {
        $stmt = $conn->prepare("SELECT user_id, first_name, last_name, password_hash, role, client_id FROM users WHERE username = ? AND status != 'inactive'");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password_hash'])) {
            login_clear_failures($conn, $ip);
            session_regenerate_id(true);   // new session id on login (prevents fixation)
            $_SESSION['user_id']   = (int) $user['user_id'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['client_id'] = $user['client_id'] !== null ? (int) $user['client_id'] : null;

            // "Keep me logged in" → extend the session cookie to 7 days
            if (isset($_POST['remember']) && $_POST['remember'] === '1') {
                $p = session_get_cookie_params();
                setcookie(session_name(), session_id(), time() + 604800, $p['path'], $p['domain'], $p['secure'], true);
            }

            header('Location: ' . home_for_role($conn, $user['role'], (int) $user['user_id']));
            exit;
        }

        login_record_failure($conn, $ip, $username);
        $error = "Invalid username or password.";
    }
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
