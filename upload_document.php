<?php
if (!isset($_COOKIE['user_role']) || $_COOKIE['user_role'] != 'admin') {
    echo "Unauthorised access! <a href='login.php'>Login</a>";
    exit;
}

include_once 'incl/dbconn.php';

if (!isset($_GET['site_id'])) {
    header('Location: view_sites.php');
    exit;
}

$site_id = (int)$_GET['site_id'];

$stmt = $conn->prepare("SELECT site_code, site_name FROM sites WHERE site_id = ?");
$stmt->bind_param('i', $site_id);
$stmt->execute();
$site = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$site) {
    echo "Site not found. <a href='view_sites.php'>Back</a>";
    exit;
}

$message = "";

if (isset($_POST['doc_name'])) {
    $doc_name  = trim($_POST['doc_name']);
    $uploaded_by = $_COOKIE['user_id'];

    if ($doc_name == '') {
        $message = "Document name is required.";
    } elseif (!isset($_FILES['doc_file']) || $_FILES['doc_file']['error'] != 0) {
        $message = "Please select a file to upload.";
    } else {
        $file     = $_FILES['doc_file'];
        $allowed  = array('pdf', 'jpg', 'jpeg', 'png', 'gif');
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $max_size = 10 * 1024 * 1024; // 10 MB

        if (!in_array($ext, $allowed)) {
            $message = "Only PDF, JPG, PNG and GIF files are allowed.";
        } elseif ($file['size'] > $max_size) {
            $message = "File must be under 10 MB.";
        } else {
            $filename = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dest     = 'uploads/' . $filename;

            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $stmt = $conn->prepare("
                    INSERT INTO site_documents (site_id, doc_name, doc_filename, uploaded_by_user_id)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->bind_param('issi', $site_id, $doc_name, $filename, $uploaded_by);
                $stmt->execute();
                $stmt->close();

                header('Location: site_detail.php?site_id=' . $site_id);
                exit;
            } else {
                $message = "File upload failed. Check that the uploads/ folder exists and is writable.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>M26 | Upload Document</title>
    <link rel='icon' href='images/m26.png' type='image/png'>
    <link rel='stylesheet' href='css/styles.css?v=8'/>
</head>
<body>
<div class='page-wrapper'>
    <?php include_once 'incl/sidebar.php'; ?>
    <div class='main-content'>

        <div class='page-heading'>
            <h1>Upload Document</h1>
            <span><?php echo htmlspecialchars($site['site_name']); ?></span>
        </div>

        <?php if ($message != ""): ?>
            <div class='alert alert-error'><?php echo $message; ?></div>
        <?php endif; ?>

        <div class='card'>
        <form method='POST' action='upload_document.php?site_id=<?php echo $site_id; ?>' enctype='multipart/form-data'>

            <div class='form-group'>
                <label>Document Name *</label>
                <input type='text' name='doc_name' placeholder='e.g. Site drawing, Contract, Lease'
                       value='<?php echo isset($_POST['doc_name']) ? htmlspecialchars($_POST['doc_name']) : ""; ?>'>
            </div>

            <div class='form-group'>
                <label>File * (PDF, JPG, PNG â€” max 10 MB)</label>
                <input type='file' name='doc_file' accept='.pdf,.jpg,.jpeg,.png,.gif'>
            </div>

            <input type='submit' value='Upload' class='btn btn-primary'>
            &nbsp;
            <a href='site_detail.php?site_id=<?php echo $site_id; ?>' class='btn btn-secondary'>Cancel</a>

        </form>
        </div>

    </div>
</div>
</body>
</html>
