<?php
require_once 'incl/dbconn.php';
require_admin();

$user_id = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
if (!$user_id) { header('Location: view_employees.php'); exit; }

// Load existing record
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { header('Location: view_employees.php'); exit; }

$supervisors = $conn->query("SELECT user_id, first_name, last_name FROM users WHERE role IN ('admin','supervisor') AND status = 'active' AND user_id != $user_id ORDER BY first_name");
$clients     = $conn->query("SELECT client_id, name FROM clients WHERE status = 'active' ORDER BY name");

$message = '';
$msg_type = 'alert-error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $first_name    = trim($_POST['first_name']);
    $last_name     = trim($_POST['last_name']);
    $username      = trim($_POST['username']);
    $role          = $_POST['role'];
    $phone         = trim($_POST['phone']);
    $email         = trim($_POST['email']);
    $team          = trim($_POST['team']);
    $supervisor_id = $_POST['supervisor_id'] !== '' ? (int)$_POST['supervisor_id'] : null;
    $client_id     = ($role === 'client' && ($_POST['client_id'] ?? '') !== '') ? (int)$_POST['client_id'] : null;
    $new_password  = $_POST['new_password'];

    if ($first_name === '' || $last_name === '' || $username === '') {
        $message = "First name, last name and username are required.";
    } elseif ($role === 'client' && $client_id === null) {
        $message = "Please pick which client this portal login belongs to.";
    } else {
        // Check username not taken by another user
        $check = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
        $check->bind_param('si', $username, $user_id);
        $check->execute();
        $check->store_result();
        $taken = $check->num_rows > 0;
        $check->close();

        if ($taken) {
            $message = "That username is already taken.";
        } else {
            if ($new_password !== '') {
                $hash = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, username=?, password_hash=?, role=?, phone=?, email=?, team=?, supervisor_id=?, client_id=? WHERE user_id=?");
                $stmt->bind_param('ssssssssiii', $first_name, $last_name, $username, $hash, $role, $phone, $email, $team, $supervisor_id, $client_id, $user_id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, username=?, role=?, phone=?, email=?, team=?, supervisor_id=?, client_id=? WHERE user_id=?");
                $stmt->bind_param('sssssssiii', $first_name, $last_name, $username, $role, $phone, $email, $team, $supervisor_id, $client_id, $user_id);
            }
            try {
                $stmt->execute();
                $stmt->close();
                $message  = "Employee updated successfully.";
                $msg_type = 'alert-success';
                // Reload updated record
                $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            } catch (mysqli_sql_exception $e) {
                $message = "Could not update the employee. Please try again.";
            }
        }
    }
}
$page_title = 'M26 | Edit Employee';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Edit Employee</h1>
            <a href='view_employees.php' class='btn btn-secondary'>&larr; Back</a>
        </div>

        <?php if ($message): ?>
            <div class='alert <?php echo $msg_type; ?>'><?php echo $message; ?></div>
        <?php endif; ?>

        <div class='card'>
        <form method='POST' action='edit_employee.php'>
            <?php echo csrf_field(); ?>
            <input type='hidden' name='user_id' value='<?php echo $user_id; ?>'>

            <div class='form-group'>
                <label>First Name *</label>
                <input type='text' name='first_name' value='<?php echo htmlspecialchars($user['first_name']); ?>'>
            </div>
            <div class='form-group'>
                <label>Last Name *</label>
                <input type='text' name='last_name' value='<?php echo htmlspecialchars($user['last_name']); ?>'>
            </div>
            <div class='form-group'>
                <label>Username *</label>
                <input type='text' name='username' value='<?php echo htmlspecialchars($user['username']); ?>'>
            </div>
            <div class='form-group'>
                <label>New Password <span style='font-weight:400;color:#888;font-size:12px;'>(leave blank to keep current)</span></label>
                <input type='password' name='new_password' placeholder=''>
            </div>
            <div class='form-group'>
                <label>Role *</label>
                <select name='role' id='role-select' onchange='toggleClient()'>
                    <option value='employee'   <?php echo $user['role']==='employee'   ? 'selected':''?>>Employee (Field Tech)</option>
                    <option value='supervisor' <?php echo $user['role']==='supervisor' ? 'selected':''?>>Supervisor</option>
                    <option value='admin'      <?php echo $user['role']==='admin'      ? 'selected':''?>>Admin</option>
                    <option value='client'     <?php echo $user['role']==='client'     ? 'selected':''?>>Client (Portal)</option>
                </select>
            </div>

            <div class='form-group' id='client-group' style='display:none;'>
                <label>Client *</label>
                <select name='client_id'>
                    <option value=''>-- Select client --</option>
                    <?php while ($clients && $cl = $clients->fetch_assoc()): ?>
                        <option value='<?php echo $cl['client_id']; ?>'
                            <?php echo (string)$cl['client_id'] === (string)($user['client_id'] ?? '') ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cl['name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <small style='color:#888; display:block; margin-top:4px;'>This login sees only that client's sites (read-only).</small>
            </div>
            <div class='form-group'>
                <label>Phone</label>
                <input type='text' name='phone' value='<?php echo htmlspecialchars($user['phone'] ?? ''); ?>'>
            </div>
            <div class='form-group'>
                <label>Email</label>
                <input type='text' name='email' value='<?php echo htmlspecialchars($user['email'] ?? ''); ?>'>
            </div>
            <div class='form-group'>
                <label>Team</label>
                <input type='text' name='team' value='<?php echo htmlspecialchars($user['team'] ?? ''); ?>'>
            </div>
            <div class='form-group'>
                <label>Supervisor</label>
                <select name='supervisor_id'>
                    <option value=''>-- None --</option>
                    <?php while ($sup = $supervisors->fetch_assoc()): ?>
                        <option value='<?php echo $sup['user_id']; ?>'
                            <?php echo $sup['user_id'] == $user['supervisor_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sup['first_name'] . ' ' . $sup['last_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <input type='submit' value='Save Changes' class='btn btn-primary'>
            &nbsp;
            <a href='view_employees.php' class='btn btn-secondary'>Cancel</a>
        </form>
        </div>

        <script>
        function toggleClient() {
            var role = document.getElementById('role-select').value;
            document.getElementById('client-group').style.display = (role === 'client') ? 'block' : 'none';
        }
        toggleClient();
        </script>

<?php include 'incl/footer.php'; ?>
