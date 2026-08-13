<?php
/*
 * Employee view: my standing shift and recent clock history.
 * Shift times come from app settings (payroll_settings table).
 * Captured locations are never shown here.
 */
require_once 'incl/dbconn.php';
require_employee();

$user_id = current_user_id();

// Standing shift from the user record
$stmt = $conn->prepare("SELECT shift_type FROM users WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$standing_shift = $user_row['shift_type'] ?? null;

// Shift window times from payroll settings (for display)
$ps = $conn->query("SELECT day_start, day_end, night_start, night_end FROM payroll_settings WHERE id = 1")->fetch_assoc();
$day_window   = $ps ? (substr($ps['day_start'], 0, 5) . ' to ' . substr($ps['day_end'], 0, 5))     : '08:00 to 17:00';
$night_window = $ps ? (substr($ps['night_start'], 0, 5) . ' to ' . substr($ps['night_end'], 0, 5)) : '20:00 to 05:00';

// Recent clock history (last 30 entries — times only, no locations)
$stmt = $conn->prepare("
    SELECT shift_type, clock_in_at, clock_out_at, worked_minutes, status
    FROM time_entries
    WHERE user_id = ?
    ORDER BY clock_in_at DESC
    LIMIT 30
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$page_title = 'M26 | My Shifts';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>My Shifts</h1>
            <a href='clock.php' class='btn btn-primary'>Clock In / Out</a>
        </div>

        <!-- Standing shift card -->
        <div class='card' style='margin-bottom:22px;'>
            <?php if ($standing_shift === 'day'): ?>
                <p style='font-size:15px; margin:0 0 6px;'>
                    You are on the <span class='badge badge-shift-day' style='font-size:14px; padding:4px 14px;'>Day shift</span>
                </p>
                <p style='color:#666; font-size:13px; margin:0;'>Your shift runs from <strong><?php echo htmlspecialchars($day_window); ?></strong>. Clock in each day when you start work.</p>
            <?php elseif ($standing_shift === 'night'): ?>
                <p style='font-size:15px; margin:0 0 6px;'>
                    You are on the <span class='badge badge-shift-night' style='font-size:14px; padding:4px 14px;'>Night shift</span>
                </p>
                <p style='color:#666; font-size:13px; margin:0;'>Your shift runs from <strong><?php echo htmlspecialchars($night_window); ?></strong>. Clock in when your shift starts.</p>
            <?php else: ?>
                <p style='font-size:15px; margin:0 0 6px;'>
                    <span class='badge' style='background:#e9ecef;color:#6c757d;font-size:14px;padding:4px 14px;'>No shift assigned</span>
                </p>
                <p style='color:#666; font-size:13px; margin:0;'>The office has not yet set your standing shift. Your clock-in will be marked as day or night based on the time of day.</p>
            <?php endif; ?>
        </div>

        <div class='section-heading'>Recent Clock History</div>
        <?php if (empty($history)): ?>
            <?php echo empty_state(
                'No clock entries yet',
                'Your clock-ins and clock-outs will appear here once you start using the clock.',
                'clock.php',
                'Go to the clock'
            ); ?>
        <?php else: ?>
        <div class='card card-table'>
            <table class='data-table'>
                <tr>
                    <th>Date</th><th>Shift</th><th>Clock In</th><th>Clock Out</th><th>Hours</th>
                </tr>
                <?php foreach ($history as $r): ?>
                <tr>
                    <td><?php echo fmt_date($r['clock_in_at']); ?></td>
                    <td><span class='badge badge-shift-<?php echo htmlspecialchars($r['shift_type']); ?>'><?php echo ucfirst(htmlspecialchars($r['shift_type'])); ?></span></td>
                    <td><?php echo date('H:i', strtotime($r['clock_in_at'])); ?></td>
                    <td>
                        <?php if ($r['status'] === 'open'): ?>
                            <span class='badge badge-in-progress'>on shift</span>
                        <?php else: ?>
                            <?php echo $r['clock_out_at'] ? date('H:i', strtotime($r['clock_out_at'])) : '—'; ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $r['worked_minutes'] !== null ? number_format($r['worked_minutes'] / 60, 2) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
