<?php
require_once 'incl/dbconn.php';
require_admin();

$message = "";

// Fetch admins for the supervisor dropdown
$supervisors = $conn->query("SELECT user_id, first_name, last_name FROM users WHERE role IN ('admin','supervisor') AND status = 'active' ORDER BY first_name");

// Active clients for the client-portal-login dropdown
$clients = $conn->query("SELECT client_id, name FROM clients WHERE status = 'active' ORDER BY name");

if (isset($_POST['username'])) {
    csrf_check();
    $first_name   = trim($_POST['first_name']);
    $last_name    = trim($_POST['last_name']);
    $username     = trim($_POST['username']);
    $password     = $_POST['password'];
    $role         = $_POST['role'];
    $phone        = trim($_POST['phone']);
    $email        = trim($_POST['email']);
    $team         = trim($_POST['team']);
    $supervisor_id = $_POST['supervisor_id'] != '' ? $_POST['supervisor_id'] : null;
    // client_id only applies to client-portal logins
    $client_id    = ($role === 'client' && ($_POST['client_id'] ?? '') != '') ? (int)$_POST['client_id'] : null;

    if ($first_name == '' || $last_name == '' || $username == '' || $password == '') {
        $message = "First name, last name, username and password are required.";
    } elseif ($role === 'client' && $client_id === null) {
        $message = "Please pick which client this portal login belongs to.";
    } else {
        // Check username is not already taken
        $check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $check->bind_param('s', $username);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $message = "Username already exists. Please choose a different one.";
            $check->close();
        } else {
            $check->close();
            $hash = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $conn->prepare("
                INSERT INTO users (first_name, last_name, username, password_hash, role, phone, email, team, supervisor_id, client_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('ssssssssii', $first_name, $last_name, $username, $hash, $role, $phone, $email, $team, $supervisor_id, $client_id);

            try {
                $stmt->execute();
                $message = "Employee added successfully!";
            } catch (mysqli_sql_exception $e) {
                $message = "Error adding employee. Please try again.";
            }
            $stmt->close();
        }
    }
}
$page_title = 'M26 | Add Employee';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Add Employee</h1>
        </div>

        <?php if ($message != ""): ?>
            <div class='alert <?php echo strpos($message, "successfully") !== false ? "alert-success" : "alert-error"; ?>'>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class='card'>
        <form method='POST' action='add_employee.php'>
            <?php echo csrf_field(); ?>

            <div class='form-group'>
                <label>First Name *</label>
                <input type='text' name='first_name' value='<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ""; ?>'>
            </div>

            <div class='form-group'>
                <label>Last Name *</label>
                <input type='text' name='last_name' value='<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ""; ?>'>
            </div>

            <div class='form-group'>
                <label>Username *</label>
                <input type='text' name='username' value='<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ""; ?>'>
            </div>

            <div class='form-group'>
                <label>Password *</label>
                <input type='password' name='password'>
            </div>

            <div class='form-group'>
                <label>Role *</label>
                <?php $r = $_POST['role'] ?? 'employee'; ?>
                <select name='role' id='role-select' onchange='toggleClient()'>
                    <option value='employee'<?php   echo $r === 'employee'   ? ' selected' : ''; ?>>Employee (Field Tech)</option>
                    <option value='supervisor'<?php echo $r === 'supervisor' ? ' selected' : ''; ?>>Supervisor</option>
                    <option value='admin'<?php      echo $r === 'admin'      ? ' selected' : ''; ?>>Admin</option>
                    <option value='client'<?php     echo $r === 'client'     ? ' selected' : ''; ?>>Client (Portal)</option>
                </select>
            </div>

            <div class='form-group' id='client-group' style='display:none;'>
                <label>Client *</label>
                <select name='client_id'>
                    <option value=''>-- Select client --</option>
                    <?php
                    if ($clients) {
                        $sel_client = $_POST['client_id'] ?? '';
                        while ($cl = $clients->fetch_assoc()) {
                            $sel = ((string)$cl['client_id'] === (string)$sel_client) ? ' selected' : '';
                            echo "<option value='" . $cl['client_id'] . "'$sel>" . htmlspecialchars($cl['name']) . "</option>";
                        }
                    }
                    ?>
                </select>
                <small style='color:#888; display:block; margin-top:4px;'>
                    This login will see only that client's sites (read-only). Manage clients under
                    <a href='view_clients.php'>Clients</a>.
                </small>
            </div>

            <div class='form-group'>
                <label>Phone</label>
                <input type='text' name='phone' value='<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ""; ?>'>
            </div>

            <div class='form-group'>
                <label>Email</label>
                <input type='text' name='email' value='<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ""; ?>'>
            </div>

            <div class='form-group'>
                <label>Team</label>
                <input type='text' name='team' placeholder='e.g. Field Team A' value='<?php echo isset($_POST['team']) ? htmlspecialchars($_POST['team']) : ""; ?>'>
            </div>

            <div class='form-group'>
                <label>Supervisor</label>
                <select name='supervisor_id'>
                    <option value=''>-- None --</option>
                    <?php
                    while ($sup = $supervisors->fetch_assoc()) {
                        echo "<option value='" . $sup['user_id'] . "'>" . htmlspecialchars($sup['first_name'] . ' ' . $sup['last_name']) . "</option>";
                    }
                    ?>
                </select>
            </div>

            <input type='submit' value='Add Employee' class='btn btn-primary'>
            &nbsp;
            <a href='view_employees.php' class='btn btn-secondary'>Cancel</a>

        </form>
        </div>

        <script>
        function toggleClient() {
            var role = document.getElementById('role-select').value;
            document.getElementById('client-group').style.display = (role === 'client') ? 'block' : 'none';
        }
        toggleClient(); // set correct state on load (e.g. after a validation error)
        </script>

<?php include 'incl/footer.php'; ?>
