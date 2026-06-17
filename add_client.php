<?php
require_once 'incl/dbconn.php';
require_admin();

$message = "";

// Live helper: how many sites does the chosen company match? (shown for both
// the "Preview match" button and a failed save, so the count is always visible)
$preview     = null;
$preview_key = trim($_POST['match_keyword'] ?? '');
if ($preview_key !== '') {
    $preview = client_site_count($conn, $preview_key);
}

if (isset($_POST['name']) && !isset($_POST['preview_only'])) {
    csrf_check();
    $name     = trim($_POST['name'] ?? '');
    $keyword  = trim($_POST['match_keyword'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $display  = trim($_POST['display_name'] ?? '');
    if ($display === '') $display = $name;   // default the portal display name to the client name

    if ($name == '' || $keyword == '' || $username == '' || $password == '') {
        $message = "Client name, company, login username and password are all required.";
    } else {
        // Username must be unique across all users
        $chk = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $chk->bind_param('s', $username);
        $chk->execute();
        $chk->store_result();
        $taken = $chk->num_rows > 0;
        $chk->close();

        if ($taken) {
            $message = "That login username is already taken. Please choose another.";
        } else {
            // 1) create the client
            $stmt = $conn->prepare("INSERT INTO clients (name, match_keyword) VALUES (?, ?)");
            $stmt->bind_param('ss', $name, $keyword);
            $stmt->execute();
            $client_id = $stmt->insert_id;
            $stmt->close();

            // 2) create its read-only portal login
            $hash      = password_hash($password, PASSWORD_BCRYPT);
            $last_name = '';
            $stmt = $conn->prepare("
                INSERT INTO users (first_name, last_name, username, password_hash, role, client_id)
                VALUES (?, ?, ?, ?, 'client', ?)
            ");
            $stmt->bind_param('ssssi', $display, $last_name, $username, $hash, $client_id);
            $stmt->execute();
            $stmt->close();

            flash('Client and portal login created.');
            header('Location: view_clients.php');
            exit;
        }
    }
}

$v_name    = isset($_POST['name'])          ? htmlspecialchars($_POST['name'])          : '';
$v_kw      = isset($_POST['match_keyword']) ? htmlspecialchars($_POST['match_keyword']) : '';
$v_display = isset($_POST['display_name'])  ? htmlspecialchars($_POST['display_name'])  : '';
$v_user    = isset($_POST['username'])      ? htmlspecialchars($_POST['username'])      : '';

$page_title = 'M26 | Add Client';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Add Client</h1>
        </div>

        <?php if ($message != ""): ?>
            <div class='alert alert-error'><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if ($preview !== null): ?>
            <div class='alert alert-success'><?php echo htmlspecialchars(client_company_label($preview_key)); ?> currently matches <strong><?php echo $preview; ?></strong> site(s).</div>
        <?php endif; ?>

        <div class='card'>
        <form method='POST' action='add_client.php'>
            <?php echo csrf_field(); ?>

            <div class='section-heading' style='margin-top:0;'>Client</div>

            <div class='form-group'>
                <label>Client Name *</label>
                <input type='text' name='name' placeholder='e.g. MTN Eswatini' value='<?php echo $v_name; ?>'>
            </div>

            <div class='form-group'>
                <label>Company *</label>
                <?php $sel_company = $_POST['match_keyword'] ?? ''; ?>
                <select name='match_keyword'>
                    <option value=''>-- Select company --</option>
                    <?php foreach (client_companies() as $key => $def): ?>
                        <option value='<?php echo htmlspecialchars($key); ?>'<?php echo $sel_company === $key ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($def['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style='color:#888; display:block; margin-top:4px;'>
                    The operator that owns these sites. Their sites become visible to the client.
                    Use “Preview match” to check how many sites it covers.
                </small>
            </div>

            <p style='margin-bottom:12px;'>
                <button type='submit' name='preview_only' value='1' class='btn btn-secondary'>Preview match</button>
            </p>

            <div class='section-heading'>Portal Login</div>
            <p style='font-size:13px; color:#666; margin:0 0 12px;'>
                Read-only login the client uses to see only their sites.
            </p>

            <div class='form-group'>
                <label>Login Username *</label>
                <input type='text' name='username' placeholder='e.g. mtn' value='<?php echo $v_user; ?>'>
            </div>

            <div class='form-group'>
                <label>Password *</label>
                <input type='password' name='password' placeholder='Set a password for the client'>
            </div>

            <div class='form-group'>
                <label>Display Name</label>
                <input type='text' name='display_name' placeholder='Defaults to the client name' value='<?php echo $v_display; ?>'>
                <small style='color:#888; display:block; margin-top:4px;'>Shown to the client when they log in.</small>
            </div>

            <input type='submit' value='Add Client &amp; Login' class='btn btn-primary'>
            &nbsp;
            <a href='view_clients.php' class='btn btn-secondary'>Cancel</a>
        </form>
        </div>

<?php include 'incl/footer.php'; ?>
