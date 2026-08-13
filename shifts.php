<?php
/*
 * Staff view: shift roster overview.
 * Shows each active employee's standing day / night / unassigned shift with a
 * link to the assignment page. The old per-date roster has been replaced with
 * standing shift types stored directly on the users table (users.shift_type).
 * The shift_assignments table is kept for historical time_entry foreign keys.
 */
require_once 'incl/dbconn.php';
require_staff();

// Load all active employees with their standing shift
$employees = $conn->query("
    SELECT user_id, first_name, last_name, shift_type
    FROM users
    WHERE role = 'employee' AND status = 'active'
    ORDER BY first_name, last_name
")->fetch_all(MYSQLI_ASSOC);

$page_title = 'M26 | Shift Roster';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Shift Roster</h1>
            <a href='assign_shift.php' class='btn btn-primary'>Assign Shifts</a>
        </div>
        <p class='page-intro'>Each employee's standing shift — day, night, or unassigned. Employees with an unassigned shift are placed on a shift based on the hour they clock in. Click "Assign Shifts" to update.</p>

        <?php if (empty($employees)): ?>
            <?php echo empty_state(
                'No active employees',
                'Add employees and then assign their shifts.',
                'add_employee.php',
                'Add employee'
            ); ?>
        <?php else: ?>
        <div class='card card-table'>
            <table class='data-table'>
                <tr>
                    <th>Employee</th>
                    <th>Standing shift</th>
                </tr>
                <?php foreach ($employees as $e): ?>
                <tr>
                    <td><?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?></td>
                    <td>
                        <?php if ($e['shift_type'] === 'day'): ?>
                            <span class='badge badge-shift-day'>Day</span>
                            <span style='color:#888; font-size:12px; margin-left:6px;'>08:00–17:00</span>
                        <?php elseif ($e['shift_type'] === 'night'): ?>
                            <span class='badge badge-shift-night'>Night</span>
                            <span style='color:#888; font-size:12px; margin-left:6px;'>20:00–05:00</span>
                        <?php else: ?>
                            <span class='badge' style='background:#e9ecef;color:#6c757d;'>Unassigned</span>
                            <span style='color:#aaa; font-size:12px; margin-left:6px;'>inferred at clock-in</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
