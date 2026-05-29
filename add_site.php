<?php
if (!isset($_COOKIE['user_role']) || $_COOKIE['user_role'] != 'admin') {
    echo "Unauthorised access! <a href='login.php'>Login</a>";
    exit;
}

include_once 'incl/dbconn.php';

$message = "";

if (isset($_POST['site_code'])) {
    $site_code  = trim($_POST['site_code']);
    $site_name  = trim($_POST['site_name']);
    $location   = trim($_POST['location']);
    $latitude   = trim($_POST['latitude']);
    $longitude  = trim($_POST['longitude']);
    $notes      = trim($_POST['notes']);

    if ($site_name == '') {
        $message = "Site name is required.";
    } else {
        if ($site_code == '') {
            $site_code = 'SITE-' . strtoupper(substr(md5(uniqid()), 0, 6));
        }
        $lat = $latitude  != '' ? $latitude  : null;
        $lng = $longitude != '' ? $longitude : null;

        $stmt = $conn->prepare("
            INSERT INTO sites (site_code, site_name, location, latitude, longitude, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('sssdds', $site_code, $site_name, $location, $lat, $lng, $notes);

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $stmt->close();
            header('Location: site_detail.php?site_id=' . $new_id);
            exit;
        } else {
            $message = "Error adding site. Site code may already exist.";
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>M26 | Add Site</title>
    <link rel='icon' href='images/m26.png' type='image/png'>
    <link rel='stylesheet' href='css/styles.css?v=8'/>
</head>
<body>
<div class='page-wrapper'>
    <?php include_once 'incl/sidebar.php'; ?>
    <div class='main-content'>

        <div class='page-heading'>
            <h1>Add Site</h1>
        </div>

        <?php if ($message != ""): ?>
            <div class='alert alert-error'><?php echo $message; ?></div>
        <?php endif; ?>

        <div class='card'>
        <form method='POST' action='add_site.php'>

            <div class='form-group'>
                <label>Site Code</label>
                <input type='text' name='site_code' placeholder='e.g. WN012'
                       value='<?php echo isset($_POST['site_code']) ? htmlspecialchars($_POST['site_code']) : ""; ?>'>
            </div>

            <div class='form-group'>
                <label>Site Name *</label>
                <input type='text' name='site_name' placeholder='e.g. Asoc'
                       value='<?php echo isset($_POST['site_name']) ? htmlspecialchars($_POST['site_name']) : ""; ?>'>
            </div>

            <div class='form-group'>
                <label>Location</label>
                <input type='text' name='location' placeholder='e.g. Mbabane, Eswatini'
                       value='<?php echo isset($_POST['location']) ? htmlspecialchars($_POST['location']) : ""; ?>'>
            </div>

            <div class='form-group'>
                <label>Latitude</label>
                <input type='text' name='latitude' placeholder='e.g. -26.314000'
                       value='<?php echo isset($_POST['latitude']) ? htmlspecialchars($_POST['latitude']) : ""; ?>'>
            </div>

            <div class='form-group'>
                <label>Longitude</label>
                <input type='text' name='longitude' placeholder='e.g. 31.139000'
                       value='<?php echo isset($_POST['longitude']) ? htmlspecialchars($_POST['longitude']) : ""; ?>'>
            </div>

            <div class='form-group'>
                <label>Notes</label>
                <textarea name='notes' rows='3'><?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ""; ?></textarea>
            </div>

            <input type='submit' value='Add Site' class='btn btn-primary'>
            &nbsp;
            <a href='view_sites.php' class='btn btn-secondary'>Cancel</a>

        </form>
        </div>

    </div>
</div>
</body>
</html>
