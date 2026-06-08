<?php
require_once 'incl/dbconn.php';
require_admin();

$client_id = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM clients WHERE client_id = ?");
$stmt->bind_param('i', $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$client) {
    $page_title = 'M26 | Client';
    include 'incl/header.php';
    echo "<div class='alert alert-error'>Client not found.</div>";
    include 'incl/footer.php';
    exit;
}

// The (first) portal login linked to this client, if any
function load_client_login(mysqli $conn, int $client_id): ?array {
    $stmt = $conn->prepare("SELECT user_id, username, first_name FROM users WHERE client_id = ? AND role = 'client' ORDER BY user_id LIMIT 1");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
$login = load_client_login($conn, $client_id);

$message = "";

if (isset($_POST['name'])) {
    csrf_check();
    $name     = trim($_POST['name'] ?? '');
    $keyword  = trim($_POST['match_keyword'] ?? '');
    $status   = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $username = trim($_POST['username'] ?? '');
    $new_pass = $_POST['new_password'] ?? '';
    $display  = trim($_POST['display_name'] ?? '');
    if ($display === '') $display = $name;

    if ($name == '' || $keyword == '') {
        $message = "Client name and match keyword are both required.";
    } elseif ($username == '') {
        $message = "Login username is required.";
    } elseif (!$login && $new_pass == '') {
        $message = "Set a password to create this client's portal login.";
    } else {
        // Username unique (ignore this client's own login row)
        $own_id = $login['user_id'] ?? 0;
        $chk = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
        $chk->bind_param('si', $username, $own_id);
        $chk->execute();
        $chk->store_result();
        $taken = $chk->num_rows > 0;
        $chk->close();

        if ($taken) {
            $message = "That login username is already taken.";
        } else {
            // Update the client record
            $stmt = $conn->prepare("UPDATE clients SET name=?, match_keyword=?, status=? WHERE client_id=?");
            $stmt->bind_param('sssi', $name, $keyword, $status, $client_id);
            $stmt->execute();
            $stmt->close();

            if ($login) {
                // Update existing login (password only if a new one was typed)
                if ($new_pass !== '') {
                    $hash = password_hash($new_pass, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare("UPDATE users SET username=?, first_name=?, password_hash=? WHERE user_id=?");
                    $stmt->bind_param('sssi', $username, $display, $hash, $login['user_id']);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET username=?, first_name=? WHERE user_id=?");
                    $stmt->bind_param('ssi', $username, $display, $login['user_id']);
                }
                $stmt->execute();
                $stmt->close();
            } else {
                // No login yet — create one
                $hash = password_hash($new_pass, PASSWORD_BCRYPT);
                $last = '';
                $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, username, password_hash, role, client_id) VALUES (?, ?, ?, ?, 'client', ?)");
                $stmt->bind_param('ssssi', $display, $last, $username, $hash, $client_id);
                $stmt->execute();
                $stmt->close();
            }

            header('Location: view_clients.php');
            exit;
        }
    }
    // Keep edited values + refresh login state on validation error
    $client = array_merge($client, ['name' => $name, 'match_keyword' => $keyword, 'status' => $status]);
    $login  = load_client_login($conn, $client_id);
}

// How many sites the current company matches
$match_count = client_site_count($conn, $client['match_keyword']);

$cur_username = htmlspecialchars($login['username']   ?? '');
$cur_display  = htmlspecialchars($login['first_name'] ?? '');

$page_title = 'M26 | Edit Client';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Edit Client</h1>
        </div>

        <?php if ($message != ""): ?>
            <div class='alert alert-error'><?php echo $message; ?></div>
        <?php endif; ?>

        <div class='alert alert-success'>
            <?php echo htmlspecialchars(client_company_label($client['match_keyword'])); ?> currently matches
            <strong><?php echo $match_count; ?></strong> site(s).
        </div>

        <div class='card'>
        <form method='POST' action='edit_client.php'>
            <?php echo csrf_field(); ?>
            <input type='hidden' name='client_id' value='<?php echo $client_id; ?>'>

            <div class='section-heading' style='margin-top:0;'>Client</div>

            <div class='form-group'>
                <label>Client Name *</label>
                <input type='text' name='name' value='<?php echo htmlspecialchars($client['name']); ?>'>
            </div>

            <div class='form-group'>
                <label>Company *</label>
                <?php $sel_company = client_resolve_company_key($client['match_keyword']); ?>
                <select name='match_keyword'>
                    <option value=''>-- Select company --</option>
                    <?php foreach (client_companies() as $key => $def): ?>
                        <option value='<?php echo htmlspecialchars($key); ?>'<?php echo $sel_company === $key ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($def['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style='color:#888; display:block; margin-top:4px;'>Save to recalculate the matched-site count above.</small>
            </div>

            <div class='form-group'>
                <label>Status</label>
                <select name='status'>
                    <option value='active'<?php   echo $client['status'] === 'active'   ? ' selected' : ''; ?>>Active</option>
                    <option value='inactive'<?php echo $client['status'] === 'inactive' ? ' selected' : ''; ?>>Inactive (blocks portal login)</option>
                </select>
            </div>

            <div class='section-heading'>Portal Login</div>
            <?php if (!$login): ?>
            <p style='font-size:13px; color:#b35900; margin:0 0 12px;'>
                This client has no login yet — fill in a username and password to create one.
            </p>
            <?php endif; ?>

            <div class='form-group'>
                <label>Login Username *</label>
                <input type='text' name='username' value='<?php echo $cur_username; ?>'>
            </div>

            <div class='form-group'>
                <label><?php echo $login ? 'New Password' : 'Password *'; ?>
                    <?php if ($login): ?><span style='font-weight:400;color:#888;font-size:12px;'>(leave blank to keep current)</span><?php endif; ?>
                </label>
                <input type='password' name='new_password'>
            </div>

            <div class='form-group'>
                <label>Display Name</label>
                <input type='text' name='display_name' value='<?php echo $cur_display; ?>' placeholder='Defaults to the client name'>
            </div>

            <input type='submit' value='Save Changes' class='btn btn-primary'>
            &nbsp;
            <a href='view_clients.php' class='btn btn-secondary'>Cancel</a>
        </form>
        </div>

<?php include 'incl/footer.php'; ?>
