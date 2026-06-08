<?php
require_once 'incl/dbconn.php';
require_employee();
csrf_check();

$vehicle_checklist = require __DIR__ . '/incl/vehicle_checklist.php';

$vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
$date       = $_POST['inspection_date'] ?? date('Y-m-d');
$odometer   = ($_POST['odometer_km'] ?? '') !== '' ? (int)$_POST['odometer_km'] : null;
$repair     = trim($_POST['repair_request'] ?? '');
$raw_items  = $_POST['items'] ?? [];
$driver_id  = current_user_id();

if ($vehicle_id <= 0) {
    header('Location: vehicle_inspection.php');
    exit;
}

// Validate date is sane (default to today on anything unexpected)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

// Build a clean, whitelisted items structure from the checklist definition.
// Anything not in the definition is ignored; statuses are validated.
$valid_statuses = array_keys(vc_statuses());   // ok, attention, critical, na
$clean = [];
$worst = 'ok';   // overall = worst item status (critical > attention > ok)

foreach ($vehicle_checklist as $sec_key => $sec) {
    foreach ($sec['fields'] as $fk => $fl) {
        $status  = $raw_items[$sec_key][$fk]['status']  ?? 'na';
        $remarks = trim($raw_items[$sec_key][$fk]['remarks'] ?? '');
        if (!in_array($status, $valid_statuses, true)) {
            $status = 'na';
        }
        $clean[$sec_key][$fk] = ['status' => $status, 'remarks' => $remarks];

        if ($status === 'critical') {
            $worst = 'critical';
        } elseif ($status === 'attention' && $worst !== 'critical') {
            $worst = 'attention';
        }
    }
}

$items_json = json_encode($clean);

// Upsert: one inspection per vehicle per day (uq_vehicle_day unique key)
$stmt = $conn->prepare("
    INSERT INTO vehicle_inspections
        (vehicle_id, driver_user_id, inspection_date, odometer_km, overall_status, repair_request, items_json)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        driver_user_id = VALUES(driver_user_id),
        odometer_km    = VALUES(odometer_km),
        overall_status = VALUES(overall_status),
        repair_request = VALUES(repair_request),
        items_json     = VALUES(items_json)
");
$stmt->bind_param('iisisss', $vehicle_id, $driver_id, $date, $odometer, $worst, $repair, $items_json);
$stmt->execute();
$stmt->close();

header('Location: vehicle_inspection.php?vehicle_id=' . $vehicle_id . '&saved=1');
exit;
