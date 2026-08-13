<?php
/*
 * Staff: assign a standing day or night shift to each employee.
 * Shifts are now a standing property of the user (users.shift_type), not a
 * per-date roster. This page lets the office set them all in one go.
 * The shift_assignments table is preserved for historical time_entry links.
 */
require_once 'incl/dbconn.php';
require_staff();

$message = '';
$saved   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // $_POST['shifts'] = [ user_id => 'day'|'night'|'' ]
    $shifts = is_array($_POST['shifts'] ?? null) ? $_POST['shifts'] : [];

    // Load all active employees so we can validate the posted user_ids
    $valid_ids_res = $conn->query("SELECT user_id FROM users WHERE role = 'employee' AND status = 'active'");
    $valid_ids = [];
    while ($row = $valid_ids_res->fetch_assoc()) $valid_ids[(int)$row['user_id']] = true;

    $stmt = $conn->prepare("UPDATE users SET shift_type = ? WHERE user_id = ? AND role = 'employee'");
    $count = 0;
    foreach ($shifts as $uid_raw => $shift_raw) {
        $uid   = (int) $uid_raw;
        $shift = in_array($shift_raw, ['day', 'night'], true) ? $shift_raw : null;
        if (!isset($valid_ids[$uid])) continue;
        $stmt->bind_param('si', $shift, $uid);
        $stmt->execute();
        $count++;
    }
    $stmt->close();

    flash("Shift assignments saved for {$count} employee(s).");
    header('Location: assign_shift.php');
    exit;
}

// Load all active employees with their current shift
$employees = $conn->query("
    SELECT user_id, first_name, last_name, shift_type
    FROM users
    WHERE role = 'employee' AND status = 'active'
    ORDER BY first_name, last_name
")->fetch_all(MYSQLI_ASSOC);

$page_title = 'M26 | Assign Shifts';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Assign Shifts</h1>
            <a href='shifts.php' class='btn btn-secondary'>&larr; Roster</a>
        </div>
        <p class='page-intro'>Set whether each employee works the day or night shift. Changes take effect on their next clock-in. Leave an employee "Unassigned" and their shift will be inferred from the hour they clock in.</p>

        <?php if (empty($employees)): ?>
            <?php echo empty_state('No active employees', 'Add employees first before assigning shifts.', 'add_employee.php', 'Add employee'); ?>
        <?php else: ?>
        <div class='card'>
        <form method='POST' action='assign_shift.php'>
            <?php echo csrf_field(); ?>

            <table class='data-table' style='margin-bottom:20px;'>
                <tr>
                    <th>Employee</th>
                    <th>Day shift <span style='font-weight:400;color:#888;font-size:11px;'>(08:00–17:00)</span></th>
                    <th>Night shift <span style='font-weight:400;color:#888;font-size:11px;'>(20:00–05:00)</span></th>
                    <th>Unassigned</th>
                </tr>
                <?php foreach ($employees as $e):
                    $uid      = (int) $e['user_id'];
                    $current  = $e['shift_type'] ?? '';   // 'day', 'night', or null/''
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?></strong></td>
                    <td style='text-align:center;'>
                        <input type='radio' name='shifts[<?php echo $uid; ?>]' value='day'
                               <?php echo $current === 'day' ? 'checked' : ''; ?>>
                    </td>
                    <td style='text-align:center;'>
                        <input type='radio' name='shifts[<?php echo $uid; ?>]' value='night'
                               <?php echo $current === 'night' ? 'checked' : ''; ?>>
                    </td>
                    <td style='text-align:center;'>
                        <input type='radio' name='shifts[<?php echo $uid; ?>]' value=''
                               <?php echo ($current === null || $current === '') ? 'checked' : ''; ?>>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>

            <button type='submit' class='btn btn-primary'>Save</button>
            &nbsp;
            <a href='shifts.php' class='btn btn-secondary'>Cancel</a>
        </form>
        </div>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
