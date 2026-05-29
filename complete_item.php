<?php
if (!isset($_COOKIE['user_role']) || $_COOKIE['user_role'] != 'employee') {
    echo "Unauthorised access! <a href='login.php'>Login</a>";
    exit;
}

include_once 'incl/dbconn.php';

if (!isset($_GET['item_id']) || !isset($_GET['visit_id'])) {
    header('Location: employee_dashboard.php');
    exit;
}

$item_id  = (int)$_GET['item_id'];
$visit_id = (int)$_GET['visit_id'];
$user_id  = (int)$_COOKIE['user_id'];

// Confirm this item belongs to a visit assigned to this tech
$stmt = $conn->prepare("
    SELECT i.item_description, i.is_done, v.status
    FROM visit_items i
    JOIN visits v ON v.visit_id = i.visit_id
    WHERE i.item_id = ? AND i.visit_id = ? AND v.assigned_to_user_id = ?
");
$stmt->bind_param('iii', $item_id, $visit_id, $user_id);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$item) {
    echo "Item not found or not assigned to you. <a href='employee_dashboard.php'>Back</a>";
    exit;
}

if ($item['is_done']) {
    header('Location: employee_visit.php?visit_id=' . $visit_id);
    exit;
}

$message = "";

if (isset($_POST['mark_done'])) {
    $errors  = array();
    $allowed = array('jpg', 'jpeg', 'png', 'gif');
    $max_size = 10 * 1024 * 1024;

    $before_filename = '';
    $after_filename  = '';

    // Handle before photo (optional during dev â€” remove the check to enforce later)
    if (isset($_FILES['before_photo']) && $_FILES['before_photo']['error'] == 0) {
        $file = $_FILES['before_photo'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $errors[] = "Before photo must be JPG, PNG or GIF.";
        } elseif ($file['size'] > $max_size) {
            $errors[] = "Before photo must be under 10 MB.";
        } else {
            $before_filename = time() . '_' . bin2hex(random_bytes(6)) . '_before.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], 'uploads/' . $before_filename)) {
                $errors[] = "Failed to save before photo.";
                $before_filename = '';
            }
        }
    }

    // Handle after photo
    if (isset($_FILES['after_photo']) && $_FILES['after_photo']['error'] == 0) {
        $file = $_FILES['after_photo'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $errors[] = "After photo must be JPG, PNG or GIF.";
        } elseif ($file['size'] > $max_size) {
            $errors[] = "After photo must be under 10 MB.";
        } else {
            $after_filename = time() . '_' . bin2hex(random_bytes(6)) . '_after.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], 'uploads/' . $after_filename)) {
                $errors[] = "Failed to save after photo.";
                $after_filename = '';
            }
        }
    }

    if (count($errors) > 0) {
        $message = implode(' ', $errors);
    } else {
        // Mark item done
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("UPDATE visit_items SET is_done = 1, completed_at = ? WHERE item_id = ?");
        $stmt->bind_param('si', $now, $item_id);
        $stmt->execute();
        $stmt->close();

        // Save before photo row if uploaded
        if ($before_filename != '') {
            $stmt = $conn->prepare("INSERT INTO visit_photos (visit_id, item_id, photo_filename, photo_type, uploaded_by_user_id) VALUES (?, ?, ?, 'before', ?)");
            $stmt->bind_param('iisi', $visit_id, $item_id, $before_filename, $user_id);
            $stmt->execute();
            $stmt->close();
        }

        // Save after photo row if uploaded
        if ($after_filename != '') {
            $stmt = $conn->prepare("INSERT INTO visit_photos (visit_id, item_id, photo_filename, photo_type, uploaded_by_user_id) VALUES (?, ?, ?, 'after', ?)");
            $stmt->bind_param('iisi', $visit_id, $item_id, $after_filename, $user_id);
            $stmt->execute();
            $stmt->close();
        }

        // If visit was still 'assigned', move it to 'in_progress'
        if ($item['status'] == 'assigned') {
            $stmt = $conn->prepare("UPDATE visits SET status = 'in_progress' WHERE visit_id = ?");
            $stmt->bind_param('i', $visit_id);
            $stmt->execute();
            $stmt->close();
        }

        header('Location: employee_visit.php?visit_id=' . $visit_id);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>M26 | Complete Item</title>
    <link rel='icon' href='images/m26.png' type='image/png'>
    <link rel='stylesheet' href='css/styles.css?v=8'/>
</head>
<body>
<div class='page-wrapper'>
    <?php include_once 'incl/sidebar.php'; ?>
    <div class='main-content'>

        <div class='page-heading'>
            <h1>Complete Item</h1>
        </div>

        <?php if ($message != ""): ?>
            <div class='alert alert-error'><?php echo $message; ?></div>
        <?php endif; ?>

        <div class='card'>
            <p><strong>Task:</strong> <?php echo htmlspecialchars($item['item_description']); ?></p>
        </div>

        <div class='card'>
        <form method='POST' action='complete_item.php?item_id=<?php echo $item_id; ?>&visit_id=<?php echo $visit_id; ?>' enctype='multipart/form-data'>

            <div class='file-upload-group'>
                <div class='file-upload-box'>
                    <strong>Before Photo</strong>
                    <p style='font-size:12px; color:#888;'>Photo before the work</p>
                    <input type='file' name='before_photo' accept='image/*'>
                </div>
                <div class='file-upload-box'>
                    <strong>After Photo</strong>
                    <p style='font-size:12px; color:#888;'>Photo after the work</p>
                    <input type='file' name='after_photo' accept='image/*'>
                </div>
            </div>

            <br>
            <p style='font-size:13px; color:#666;'>
                Recorded automatically: <?php echo htmlspecialchars($_COOKIE['user_name']); ?> &mdash; <?php echo date('d M Y, H:i'); ?>
            </p>

            <input type='submit' name='mark_done' value='Mark Item Done' class='btn btn-primary'>
            &nbsp;
            <a href='employee_visit.php?visit_id=<?php echo $visit_id; ?>' class='btn btn-secondary'>Cancel</a>

        </form>
        </div>

    </div>
</div>
</body>
</html>
