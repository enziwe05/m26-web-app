<?php
require_once 'incl/dbconn.php';
require_admin();

$message = "";
$regions = ['Hhohho', 'Lubombo', 'Manzini', 'Shiselweni'];

// Suggest the next site code in the existing M### format (e.g. M342)
$next_code = '';
$res = $conn->query("SELECT MAX(CAST(SUBSTRING(site_code, 2) AS UNSIGNED)) AS n FROM sites WHERE site_code REGEXP '^M[0-9]+$'");
if ($res && ($row = $res->fetch_assoc())) {
    $next_code = 'M' . str_pad((int)$row['n'] + 1, 3, '0', STR_PAD_LEFT);
}

if (isset($_POST['site_code'])) {
    csrf_check();
    $site_code  = trim($_POST['site_code'] ?? '');
    $site_name  = trim($_POST['site_name'] ?? '');
    $region     = trim($_POST['region'] ?? '');
    $location   = trim($_POST['location'] ?? '');
    $latitude   = trim($_POST['latitude'] ?? '');
    $longitude  = trim($_POST['longitude'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');
    $operator   = (isset($_POST['operator']) && array_key_exists($_POST['operator'], client_companies()))
                  ? $_POST['operator'] : null;

    // Required fields — a site isn't valid without these
    if ($site_code == '' || $site_name == '' || !in_array($region, $regions)) {
        $message = "Site code, site name and region are required.";
    } else {
        $lat = $latitude  != '' ? $latitude  : null;
        $lng = $longitude != '' ? $longitude : null;

        $stmt = $conn->prepare("
            INSERT INTO sites (site_code, site_name, location, latitude, longitude, notes, region, operator)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('sssddsss', $site_code, $site_name, $location, $lat, $lng, $notes, $region, $operator);

        try {
            $stmt->execute();
            $new_id = $stmt->insert_id;
            $stmt->close();
            flash('Site added.');
            header('Location: site_detail.php?site_id=' . $new_id);
            exit;
        } catch (mysqli_sql_exception $e) {
            $stmt->close();
            $message = "Error adding site — that site code already exists.";
        }
    }
}

// Re-fill values after a validation error, otherwise sensible defaults
$v_code   = isset($_POST['site_code']) ? htmlspecialchars($_POST['site_code']) : htmlspecialchars($next_code);
$v_name   = isset($_POST['site_name']) ? htmlspecialchars($_POST['site_name']) : '';
$v_region = $_POST['region']    ?? '';
$v_loc    = isset($_POST['location'])  ? htmlspecialchars($_POST['location'])  : '';
$v_lat    = isset($_POST['latitude'])  ? htmlspecialchars($_POST['latitude'])  : '';
$v_lng    = isset($_POST['longitude']) ? htmlspecialchars($_POST['longitude']) : '';
$v_notes  = isset($_POST['notes'])     ? htmlspecialchars($_POST['notes'])     : '';
$v_oper   = $_POST['operator'] ?? '';

$page_title = 'M26 | Add Site';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Add Site</h1>
        </div>

        <?php if ($message != ""): ?>
            <div class='alert alert-error'><?php echo $message; ?></div>
        <?php endif; ?>

        <div class='card'>
        <form method='POST' action='add_site.php'>
            <?php echo csrf_field(); ?>

            <div class='form-group'>
                <label>Site Code *</label>
                <input type='text' name='site_code' placeholder='e.g. M342' value='<?php echo $v_code; ?>'>
                <small style='color:#888; display:block; margin-top:4px;'>Format: M followed by a number (e.g. M342). Must be unique.</small>
            </div>

            <div class='form-group'>
                <label>Site Name *</label>
                <input type='text' name='site_name' placeholder='e.g. Mbabane Exchange' value='<?php echo $v_name; ?>'>
            </div>

            <div class='form-group'>
                <label>Region *</label>
                <select name='region'>
                    <option value=''>-- Select Region --</option>
                    <?php foreach ($regions as $r): ?>
                        <option value='<?php echo $r; ?>'<?php echo $v_region === $r ? ' selected' : ''; ?>><?php echo $r; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class='form-group'>
                <label>Operator / Client</label>
                <select name='operator'>
                    <option value=''>— None / other —</option>
                    <?php foreach (client_companies() as $key => $def): ?>
                        <option value='<?php echo htmlspecialchars($key); ?>'<?php echo $v_oper === $key ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($def['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style='color:#888; display:block; margin-top:4px;'>Which operator owns this site (controls what their client portal sees).</small>
            </div>

            <div class='form-group'>
                <label>Location / Coverage</label>
                <input type='text' name='location' placeholder='e.g. Mbabane CBD, Msunduza' value='<?php echo $v_loc; ?>'>
            </div>

            <div class='form-group'>
                <label>Latitude</label>
                <input type='text' name='latitude' placeholder='e.g. -26.314000' value='<?php echo $v_lat; ?>'>
            </div>

            <div class='form-group'>
                <label>Longitude</label>
                <input type='text' name='longitude' placeholder='e.g. 31.139000' value='<?php echo $v_lng; ?>'>
            </div>

            <div class='form-group'>
                <label>Notes</label>
                <textarea name='notes' rows='3' placeholder='e.g. site owner / access details'><?php echo $v_notes; ?></textarea>
            </div>

            <input type='submit' value='Add Site' class='btn btn-primary'>
            &nbsp;
            <a href='view_sites.php' class='btn btn-secondary'>Cancel</a>

        </form>
        </div>

<?php include 'incl/footer.php'; ?>
