<?php
if (!isset($_COOKIE['user_role']) || $_COOKIE['user_role'] != 'admin') {
    echo "Unauthorised access! <a href='login.php'>Login</a>";
    exit;
}

include_once 'incl/dbconn.php';

$message = "";

$sites     = $conn->query("SELECT site_id, site_code, site_name FROM sites ORDER BY site_code");
$employees = $conn->query("SELECT user_id, first_name, last_name FROM users WHERE role = 'employee' AND status = 'active' ORDER BY first_name");

// Pre-select site if coming from site_detail.php
$preselect_site = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;

if (isset($_POST['visit_type'])) {
    $site_id        = $_POST['site_id'];
    $assigned_to    = $_POST['assigned_to_user_id'];
    $visit_type     = trim($_POST['visit_type']);
    $description    = trim($_POST['description']);
    $scheduled_date = $_POST['scheduled_date'];
    $created_by     = $_COOKIE['user_id'];

    if ($site_id == '' || $assigned_to == '' || $visit_type == '') {
        $message = "Site, technician and visit type are required.";
    } else {
        // Collect non-empty item descriptions
        $items = array();
        for ($i = 1; $i <= 10; $i++) {
            if (isset($_POST['item_' . $i]) && trim($_POST['item_' . $i]) != '') {
                $items[] = trim($_POST['item_' . $i]);
            }
        }

        if (count($items) == 0) {
            $message = "Please add at least one item.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO visits (site_id, assigned_to_user_id, created_by_user_id, visit_type, description, scheduled_date, status)
                VALUES (?, ?, ?, ?, ?, ?, 'assigned')
            ");
            $stmt->bind_param('iiisss', $site_id, $assigned_to, $created_by, $visit_type, $description, $scheduled_date);
            $stmt->execute();
            $visit_id = $stmt->insert_id;
            $stmt->close();

            $sort = 1;
            foreach ($items as $desc) {
                $si = $conn->prepare("INSERT INTO visit_items (visit_id, item_description, sort_order) VALUES (?, ?, ?)");
                $si->bind_param('isi', $visit_id, $desc, $sort);
                $si->execute();
                $si->close();
                $sort++;
            }

            header('Location: visit_detail.php?visit_id=' . $visit_id);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>M26 | Create Visit</title>
    <link rel='icon' href='images/m26.png' type='image/png'>
    <link rel='stylesheet' href='css/styles.css?v=8'/>
</head>
<body>
<div class='page-wrapper'>
    <?php include_once 'incl/sidebar.php'; ?>
    <div class='main-content'>

        <div class='page-heading'>
            <h1>Create Visit</h1>
        </div>

        <?php if ($message != ""): ?>
            <div class='alert alert-error'><?php echo $message; ?></div>
        <?php endif; ?>

        <div class='card'>
        <form method='POST' action='create_visit.php'>

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
                <label>Visit Type *</label>
                <input type='text' name='visit_type' placeholder='e.g. Generator service, Battery check'
                       value='<?php echo isset($_POST['visit_type']) ? htmlspecialchars($_POST['visit_type']) : ""; ?>'>
            </div>

            <div class='form-group'>
                <label>Description</label>
                <textarea name='description' rows='3'><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ""; ?></textarea>
            </div>

            <div class='form-group'>
                <label>Scheduled Date</label>
                <input type='date' name='scheduled_date' value='<?php echo isset($_POST['scheduled_date']) ? htmlspecialchars($_POST['scheduled_date']) : ""; ?>'>
            </div>

            <hr>
            <h3>Items (what the tech needs to do)</h3>
            <p style='font-size:13px; color:#666;'>Fill in as many items as needed. Leave the rest blank.</p>

            <?php for ($i = 1; $i <= 10; $i++): ?>
            <div class='form-group'>
                <label>Item <?php echo $i; ?></label>
                <input type='text' name='item_<?php echo $i; ?>'
                       value='<?php echo isset($_POST['item_' . $i]) ? htmlspecialchars($_POST['item_' . $i]) : ""; ?>'
                       placeholder='<?php echo $i == 1 ? "e.g. Inspect generator engine" : ""; ?>'>
            </div>
            <?php endfor; ?>

            <input type='submit' value='Create Visit' class='btn btn-primary'>
            &nbsp;
            <a href='view_visits.php' class='btn btn-secondary'>Cancel</a>

        </form>
        </div>

    </div>
</div>
</body>
</html>
