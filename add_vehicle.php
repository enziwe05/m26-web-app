<?php
require_once 'incl/dbconn.php';
require_admin();

$message = "";

if (isset($_POST['registration'])) {
    csrf_check();
    $make         = trim($_POST['make'] ?? '');
    $fleet_number = trim($_POST['fleet_number'] ?? '');
    $registration = trim($_POST['registration'] ?? '');

    if ($registration == '') {
        $message = "Registration (number plate) is required.";
    } else {
        $stmt = $conn->prepare("INSERT INTO vehicles (make, fleet_number, registration) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $make, $fleet_number, $registration);
        try {
            $stmt->execute();
            $new_id = $stmt->insert_id;
            $stmt->close();
            header('Location: vehicle_detail.php?vehicle_id=' . $new_id);
            exit;
        } catch (mysqli_sql_exception $e) {
            $stmt->close();
            $message = "Error adding vehicle — that registration already exists.";
        }
    }
}

$v_make  = isset($_POST['make'])         ? htmlspecialchars($_POST['make'])         : '';
$v_fleet = isset($_POST['fleet_number']) ? htmlspecialchars($_POST['fleet_number']) : '';
$v_reg   = isset($_POST['registration']) ? htmlspecialchars($_POST['registration']) : '';

$page_title = 'M26 | Add Vehicle';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Add Vehicle</h1>
        </div>

        <?php if ($message != ""): ?>
            <div class='alert alert-error'><?php echo $message; ?></div>
        <?php endif; ?>

        <div class='card'>
        <form method='POST' action='add_vehicle.php'>
            <?php echo csrf_field(); ?>

            <div class='form-group'>
                <label>Registration (Number Plate) *</label>
                <input type='text' name='registration' placeholder='e.g. SD 123 AM' value='<?php echo $v_reg; ?>'>
                <small style='color:#888; display:block; margin-top:4px;'>Must be unique.</small>
            </div>

            <div class='form-group'>
                <label>Vehicle Make</label>
                <input type='text' name='make' placeholder='e.g. Toyota Hilux' value='<?php echo $v_make; ?>'>
            </div>

            <div class='form-group'>
                <label>Fleet Number</label>
                <input type='text' name='fleet_number' placeholder='e.g. M26-04' value='<?php echo $v_fleet; ?>'>
            </div>

            <input type='submit' value='Add Vehicle' class='btn btn-primary'>
            &nbsp;
            <a href='view_vehicles.php' class='btn btn-secondary'>Cancel</a>
        </form>
        </div>

<?php include 'incl/footer.php'; ?>
