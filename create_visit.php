<?php
require_once 'incl/dbconn.php';
require_staff();

$message = "";

$sites     = $conn->query("SELECT site_id, site_code, site_name FROM sites ORDER BY site_code");
$employees = $conn->query("SELECT user_id, first_name, last_name FROM users WHERE role = 'employee' AND status = 'active' ORDER BY first_name");

// Pre-select site if coming from site_detail.php
$preselect_site = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $site_id          = (int)($_POST['site_id'] ?? 0);
    $assigned_to      = (int)($_POST['assigned_to_user_id'] ?? 0);
    $visit_type       = in_array($_POST['visit_type'] ?? '', visit_types(), true)
                        ? $_POST['visit_type'] : 'Maintenance';
    $description      = trim($_POST['description'] ?? '');
    $scheduled_date   = ($_POST['scheduled_date'] ?? '') !== '' ? $_POST['scheduled_date'] : null;
    $created_by       = current_user_id();

    // The logged-in account must still exist. A stale cookie (e.g. from an older
    // database) would otherwise fail the foreign key and silently create nothing.
    $chk = $conn->prepare("SELECT 1 FROM users WHERE user_id = ?");
    $chk->bind_param('i', $created_by);
    $chk->execute();
    $creator_ok = (bool) $chk->get_result()->num_rows;
    $chk->close();

    if (!$creator_ok) {
        header('Location: logout.php');   // session points at a missing user — re-login
        exit;
    }

    if (!$site_id || !$assigned_to) {
        $message = "Site and technician are required.";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO visits (site_id, assigned_to_user_id, created_by_user_id, visit_type, description, scheduled_date, status)
            VALUES (?, ?, ?, ?, ?, ?, 'assigned')
        ");
        $stmt->bind_param('iiisss', $site_id, $assigned_to, $created_by, $visit_type, $description, $scheduled_date);

        // Only redirect on a genuinely successful insert — never to visit_id 0
        try {
            $stmt->execute();
            $visit_id = $stmt->insert_id;
            $stmt->close();
            flash('Visit created.');
            header('Location: visit_detail.php?visit_id=' . $visit_id);
            exit;
        } catch (mysqli_sql_exception $e) {
            $stmt->close();
            $message = "Could not create the visit. Please check the selected site and technician, then try again.";
        }
    }
}
$page_title = 'M26 | Create Visit';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Create Visit</h1>
        </div>

        <?php if ($message != ""): ?>
            <div class='alert alert-error'><?php echo $message; ?></div>
        <?php endif; ?>

        <div class='card'>
        <form method='POST' action='create_visit.php'>
            <?php echo csrf_field(); ?>

            <div class='form-group'>
                <label>Site *</label>
                <select name='site_id'>
                    <option value=''>-- Select Site --</option>
                    <?php
                    while ($s = $sites->fetch_assoc()) {
                        $sel = ($s['site_id'] == $preselect_site) ? "selected" : "";
                        echo "<option value='" . $s['site_id'] . "' $sel>" . htmlspecialchars($s['site_name']) . "</option>";
                    }
                    ?>
                </select>
            </div>

            <div class='form-group'>
                <label>Visit Type *</label>
                <select name='visit_type'>
                    <?php foreach (visit_types() as $vt): ?>
                        <option value='<?php echo htmlspecialchars($vt); ?>'<?php echo (($_POST['visit_type'] ?? '') === $vt) ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($vt); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class='form-group'>
                <label>Assign To (Technician) *</label>
                <select name='assigned_to_user_id'>
                    <option value=''>-- Select Technician --</option>
                    <?php
                    while ($e = $employees->fetch_assoc()) {
                        echo "<option value='" . $e['user_id'] . "'>" . htmlspecialchars($e['first_name'] . ' ' . $e['last_name']) . "</option>";
                    }
                    ?>
                </select>
            </div>

            <div class='form-group'>
                <label>Description / Instructions for Technician</label>
                <textarea name='description' rows='3'><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ""; ?></textarea>
            </div>

            <div class='form-group'>
                <label>Scheduled Date</label>
                <input type='date' name='scheduled_date' value='<?php echo isset($_POST['scheduled_date']) ? htmlspecialchars($_POST['scheduled_date']) : ""; ?>'>
            </div>

            <input type='submit' value='Create Visit' class='btn btn-primary'>
            &nbsp;
            <a href='view_visits.php' class='btn btn-secondary'>Cancel</a>

        </form>
        </div>

<?php include 'incl/footer.php'; ?>
