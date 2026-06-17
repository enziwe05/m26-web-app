<?php
require_once 'incl/dbconn.php';
require_admin();

$vehicle_id = (int)($_GET['vehicle_id'] ?? $_POST['vehicle_id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM vehicles WHERE vehicle_id = ?");
$stmt->bind_param('i', $vehicle_id);
$stmt->execute();
$vehicle = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$vehicle) {
    $page_title = 'M26 | Vehicle';
    include 'incl/header.php';
    echo "<div class='alert alert-error'>Vehicle not found.</div>";
    include 'incl/footer.php';
    exit;
}

$message = "";

if (isset($_POST['registration'])) {
    csrf_check();
    $make         = trim($_POST['make'] ?? '');
    $fleet_number = trim($_POST['fleet_number'] ?? '');
    $registration = trim($_POST['registration'] ?? '');
    $status       = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($registration == '') {
        $message = "Registration (number plate) is required.";
    } else {
        $stmt = $conn->prepare("UPDATE vehicles SET make=?, fleet_number=?, registration=?, status=? WHERE vehicle_id=?");
        $stmt->bind_param('ssssi', $make, $fleet_number, $registration, $status, $vehicle_id);
        try {
            $stmt->execute();
            $stmt->close();
            flash('Vehicle saved.');
            header('Location: vehicle_detail.php?vehicle_id=' . $vehicle_id);
            exit;
        } catch (mysqli_sql_exception $e) {
            $stmt->close();
            $message = "Error saving — that registration already exists on another vehicle.";
        }
    }
    // keep edited values on error
    $vehicle = array_merge($vehicle, [
        'make' => $make, 'fleet_number' => $fleet_number,
        'registration' => $registration, 'status' => $status,
    ]);
}

$page_title = 'M26 | Edit Vehicle';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Edit Vehicle</h1>
        </div>

        <?php if ($message != ""): ?>
            <div class='alert alert-error'><?php echo $message; ?></div>
        <?php endif; ?>

        <div class='card'>
        <form method='POST' action='edit_vehicle.php'>
            <?php echo csrf_field(); ?>
            <input type='hidden' name='vehicle_id' value='<?php echo $vehicle_id; ?>'>

            <div class='form-group'>
                <label>Registration (Number Plate) *</label>
                <input type='text' name='registration' value='<?php echo htmlspecialchars($vehicle['registration']); ?>'>
            </div>

            <div class='form-group'>
                <label>Vehicle Make</label>
                <input type='text' name='make' value='<?php echo htmlspecialchars($vehicle['make'] ?? ''); ?>'>
            </div>

            <div class='form-group'>
                <label>Fleet Number</label>
                <input type='text' name='fleet_number' value='<?php echo htmlspecialchars($vehicle['fleet_number'] ?? ''); ?>'>
            </div>

            <div class='form-group'>
                <label>Status</label>
                <select name='status'>
                    <option value='active'<?php   echo $vehicle['status'] === 'active'   ? ' selected' : ''; ?>>Active</option>
                    <option value='inactive'<?php echo $vehicle['status'] === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
                </select>
            </div>

            <input type='submit' value='Save Changes' class='btn btn-primary'>
            &nbsp;
            <a href='vehicle_detail.php?vehicle_id=<?php echo $vehicle_id; ?>' class='btn btn-secondary'>Cancel</a>
        </form>
        </div>

<?php include 'incl/footer.php'; ?>
