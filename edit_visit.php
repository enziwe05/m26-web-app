<?php
require_once 'incl/dbconn.php';
require_staff();

$visit_id = (int)($_GET['visit_id'] ?? $_POST['visit_id'] ?? 0);
if (!$visit_id) { header('Location: view_visits.php'); exit; }

// Load visit
$stmt = $conn->prepare("
    SELECT v.*, s.site_name
    FROM visits v
    JOIN sites s ON s.site_id = v.site_id
    WHERE v.visit_id = ?
");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$visit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$visit) { header('Location: view_visits.php'); exit; }

if ($visit['status'] === 'completed') {
    header('Location: visit_detail.php?visit_id=' . $visit_id);
    exit;
}

function load_dropdowns(mysqli $conn): array {
    $sites = $conn->query("SELECT site_id, site_name FROM sites ORDER BY site_name");
    $techs = $conn->query("SELECT user_id, first_name, last_name FROM users WHERE role = 'employee' AND status = 'active' ORDER BY first_name");
    return [$sites, $techs];
}

[$sites, $techs] = load_dropdowns($conn);

$message  = '';
$msg_type = 'alert-error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $site_id        = (int)$_POST['site_id'];
    $assigned_to    = (int)$_POST['assigned_to_user_id'];
    $visit_type     = trim($_POST['visit_type']);
    $description    = trim($_POST['description']);
    $scheduled_date = $_POST['scheduled_date'] !== '' ? $_POST['scheduled_date'] : null;

    if (!$site_id || !$assigned_to || $visit_type === '') {
        $message = "Site, technician and visit type are required.";
    } else {
        $stmt = $conn->prepare("UPDATE visits SET site_id=?, assigned_to_user_id=?, visit_type=?, description=?, scheduled_date=? WHERE visit_id=?");
        $stmt->bind_param('iisssi', $site_id, $assigned_to, $visit_type, $description, $scheduled_date, $visit_id);
        $stmt->execute();
        $stmt->close();

        $message  = "Visit updated.";
        $msg_type = 'alert-success';

        // Reload everything
        [$sites, $techs] = load_dropdowns($conn);
        $stmt = $conn->prepare("SELECT v.*, s.site_name FROM visits v JOIN sites s ON s.site_id = v.site_id WHERE v.visit_id = ?");
        $stmt->bind_param('i', $visit_id);
        $stmt->execute();
        $visit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
$page_title = 'M26 | Edit Visit';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Edit Visit — <?php echo htmlspecialchars($visit['site_name']); ?></h1>
            <a href='visit_detail.php?visit_id=<?php echo $visit_id; ?>' class='btn btn-secondary'>&larr; Back</a>
        </div>

        <?php if ($message): ?>
            <div class='alert <?php echo $msg_type; ?>'><?php echo $message; ?></div>
        <?php endif; ?>

        <div class='card'>
        <form method='POST' action='edit_visit.php'>
            <?php echo csrf_field(); ?>
            <input type='hidden' name='visit_id' value='<?php echo $visit_id; ?>'>

            <div class='form-group'>
                <label>Site *</label>
                <select name='site_id'>
                    <option value=''>-- Select Site --</option>
                    <?php while ($s = $sites->fetch_assoc()): ?>
                        <option value='<?php echo $s['site_id']; ?>'
                            <?php echo $s['site_id'] == $visit['site_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['site_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class='form-group'>
                <label>Assigned Technician *</label>
                <select name='assigned_to_user_id'>
                    <option value=''>-- Select Technician --</option>
                    <?php while ($t = $techs->fetch_assoc()): ?>
                        <option value='<?php echo $t['user_id']; ?>'
                            <?php echo $t['user_id'] == $visit['assigned_to_user_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($t['first_name'] . ' ' . $t['last_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class='form-group'>
                <label>Visit Type *</label>
                <input type='text' name='visit_type' value='<?php echo htmlspecialchars($visit['visit_type']); ?>'>
            </div>

            <div class='form-group'>
                <label>Description</label>
                <textarea name='description' rows='3'><?php echo htmlspecialchars($visit['description'] ?? ''); ?></textarea>
            </div>

            <div class='form-group'>
                <label>Scheduled Date</label>
                <input type='date' name='scheduled_date' value='<?php echo htmlspecialchars($visit['scheduled_date'] ?? ''); ?>'>
            </div>

            <input type='submit' value='Save Changes' class='btn btn-primary'>
            &nbsp;
            <a href='visit_detail.php?visit_id=<?php echo $visit_id; ?>' class='btn btn-secondary'>Cancel</a>
        </form>
        </div>

<?php include 'incl/footer.php'; ?>
