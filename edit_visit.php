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

function load_items(mysqli $conn, int $visit_id): array {
    $stmt = $conn->prepare("SELECT item_id, item_description, is_done, sort_order FROM visit_items WHERE visit_id = ? ORDER BY sort_order");
    $stmt->bind_param('i', $visit_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function load_dropdowns(mysqli $conn): array {
    $sites = $conn->query("SELECT site_id, site_name FROM sites ORDER BY site_name");
    $techs = $conn->query("SELECT user_id, first_name, last_name FROM users WHERE role = 'employee' AND status = 'active' ORDER BY first_name");
    return [$sites, $techs];
}

$items = load_items($conn, $visit_id);
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

        // Update / delete existing pending items
        $max_sort = count($items) > 0 ? max(array_column($items, 'sort_order')) : 0;
        foreach ($items as $item) {
            if ($item['is_done']) continue;
            $desc = trim($_POST['item_existing_' . $item['item_id']] ?? '');
            if ($desc !== '') {
                $stmt = $conn->prepare("UPDATE visit_items SET item_description=? WHERE item_id=?");
                $stmt->bind_param('si', $desc, $item['item_id']);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("DELETE FROM visit_items WHERE item_id=? AND is_done=0");
                $stmt->bind_param('i', $item['item_id']);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Add new items
        for ($i = 1; $i <= 5; $i++) {
            $desc = trim($_POST['item_new_' . $i] ?? '');
            if ($desc !== '') {
                $max_sort++;
                $stmt = $conn->prepare("INSERT INTO visit_items (visit_id, item_description, sort_order) VALUES (?,?,?)");
                $stmt->bind_param('isi', $visit_id, $desc, $max_sort);
                $stmt->execute();
                $stmt->close();
            }
        }

        $message  = "Visit updated.";
        $msg_type = 'alert-success';

        // Reload everything
        $items = load_items($conn, $visit_id);
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

        <?php if ($visit['status'] === 'in_progress'): ?>
            <div class='alert alert-error' style='background:#fff8e1;color:#856404;border-color:#ffc107;'>
                This visit is in progress — completed items cannot be changed.
            </div>
        <?php endif; ?>

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

            <hr style='margin:24px 0;border:none;border-top:1px solid #eef0f4;'>
            <h3 style='margin-bottom:16px;font-size:15px;color:#1a3a5c;'>Checklist Items</h3>

            <?php foreach ($items as $item): ?>
                <?php if ($item['is_done']): ?>
                    <div class='form-group'>
                        <label style='color:#aaa;'>
                            &#10003; <?php echo htmlspecialchars($item['item_description']); ?>
                            <span style='font-weight:400;font-size:11px;'>(completed — cannot edit)</span>
                        </label>
                    </div>
                <?php else: ?>
                    <div class='form-group'>
                        <label>Item (leave blank to remove)</label>
                        <input type='text' name='item_existing_<?php echo $item['item_id']; ?>'
                               value='<?php echo htmlspecialchars($item['item_description']); ?>'>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <p style='font-size:13px;color:#888;margin:16px 0 8px;'>Add new items:</p>
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <div class='form-group'>
                    <input type='text' name='item_new_<?php echo $i; ?>' placeholder='New item <?php echo $i; ?>'>
                </div>
            <?php endfor; ?>

            <input type='submit' value='Save Changes' class='btn btn-primary'>
            &nbsp;
            <a href='visit_detail.php?visit_id=<?php echo $visit_id; ?>' class='btn btn-secondary'>Cancel</a>
        </form>
        </div>

<?php include 'incl/footer.php'; ?>
