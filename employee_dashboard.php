<?php
require_once 'incl/dbconn.php';
require_employee();

$user_id = current_user_id();

// ── Clock status — is this employee currently clocked in? ─────────────────────
$oe_stmt = $conn->prepare(
    "SELECT entry_id, clock_in_at, shift_type
     FROM time_entries
     WHERE user_id = ? AND status = 'open'
     ORDER BY clock_in_at DESC LIMIT 1"
);
$oe_stmt->bind_param('i', $user_id);
$oe_stmt->execute();
$open_entry = $oe_stmt->get_result()->fetch_assoc();
$oe_stmt->close();

// ── User's own shift type + payroll settings window ───────────────────────────
$u_stmt = $conn->prepare("SELECT shift_type FROM users WHERE user_id = ?");
$u_stmt->bind_param('i', $user_id);
$u_stmt->execute();
$u_row = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();
$my_shift = $u_row['shift_type'] ?? 'day';

$ps = $conn->query(
    "SELECT day_start, day_end, night_start, night_end FROM payroll_settings WHERE id = 1"
)->fetch_assoc();

// ── Hours worked this week (Mon–today, closed entries) ────────────────────────
$hw_stmt = $conn->prepare(
    "SELECT COALESCE(SUM(worked_minutes), 0) AS mins
     FROM time_entries
     WHERE user_id = ? AND status = 'closed'
     AND clock_in_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)"
);
$hw_stmt->bind_param('i', $user_id);
$hw_stmt->execute();
$hours_week = round((float)$hw_stmt->get_result()->fetch_assoc()['mins'] / 60, 1);
$hw_stmt->close();

// ── Visits assigned to this employee ─────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT v.visit_id, s.site_code, s.site_name, v.visit_type, v.status, v.scheduled_date
    FROM visits v
    JOIN sites s ON s.site_id = v.site_id
    WHERE v.assigned_to_user_id = ?
    ORDER BY FIELD(v.status, 'in_progress', 'assigned', 'completed'), v.scheduled_date ASC
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$all_visits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$active    = array_filter($all_visits, fn($r) => $r['status'] !== 'completed');
$completed = array_filter($all_visits, fn($r) => $r['status'] === 'completed');
// Reindex
$active    = array_values($active);
$completed = array_values($completed);

$completed_total = count($completed);
// Show only the 5 most recent completed visits on the dashboard
$completed_shown = array_slice($completed, -5);

// ── Render a mini visit table ─────────────────────────────────────────────────
function emp_visit_table(array $rows): void {
    echo "<table class='data-table'>";
    echo "<tr><th>Site</th><th>Type</th><th>Scheduled</th><th>Status</th><th></th></tr>";
    foreach ($rows as $r) {
        echo "<tr>";
        echo "<td><strong>" . htmlspecialchars($r['site_code']) . "</strong>"
           . " <span style='color:#888;font-size:12px;'>" . htmlspecialchars($r['site_name']) . "</span></td>";
        echo "<td>"  . htmlspecialchars($r['visit_type']) . "</td>";
        echo "<td>"  . fmt_date($r['scheduled_date'])     . "</td>";
        echo "<td>"  . status_badge($r['status'])         . "</td>";
        echo "<td><a href='employee_visit.php?visit_id=" . (int)$r['visit_id'] . "'>Open</a></td>";
        echo "</tr>";
    }
    echo "</table>";
}

// ── Shift label helpers ───────────────────────────────────────────────────────
$shift_label  = $my_shift === 'night' ? 'Night Shift' : 'Day Shift';
$shift_window = $my_shift === 'night'
    ? substr((string)($ps['night_start'] ?? '20:00'), 0, 5)
      . ' – ' . substr((string)($ps['night_end'] ?? '05:00'), 0, 5)
    : substr((string)($ps['day_start']   ?? '08:00'), 0, 5)
      . ' – ' . substr((string)($ps['day_end']   ?? '17:00'), 0, 5);
$badge_shift = $my_shift === 'night' ? 'badge-shift-night' : 'badge-shift-day';

$page_title = 'M26 | My Visits';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>My Visits</h1>
            <div style='display:flex; gap:12px; align-items:center;'>
                <a href='pick_site.php' class='btn btn-primary no-print'>&#43; Start a visit</a>
                <span>Hi, <?php echo htmlspecialchars(current_user_name()); ?></span>
            </div>
        </div>

        <!-- ── Clock status strip ────────────────────────────────────────────── -->
        <div class='emp-clock-strip <?php echo $open_entry ? 'in' : ''; ?>'>
            <div class='emp-clock-ico'>
                <svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'>
                    <circle cx='12' cy='12' r='9'/><path d='M12 7v5l3 3'/>
                </svg>
            </div>
            <div class='emp-clock-body'>
                <div class='emp-clock-label'><?php echo $open_entry ? 'Currently Clocked In' : 'Not Clocked In'; ?></div>
                <?php if ($open_entry): ?>
                    <div class='emp-clock-val'>Since <?php echo date('H:i', strtotime($open_entry['clock_in_at'])); ?></div>
                    <div class='emp-clock-sub'><?php echo fmt_date($open_entry['clock_in_at']); ?></div>
                <?php else: ?>
                    <div class='emp-clock-val'>—</div>
                    <div class='emp-clock-sub'>Clock in when you arrive on site.</div>
                <?php endif; ?>
            </div>
            <a href='clock.php'
               class='btn <?php echo $open_entry ? 'btn-danger' : 'btn-primary'; ?> no-print'>
                <?php echo $open_entry ? 'Clock Out' : 'Clock In'; ?>
            </a>
        </div>

        <!-- ── Shift info strip ──────────────────────────────────────────────── -->
        <div class='emp-shift-strip'>
            <span class='shift-label'>My Shift</span>
            <span class='badge <?php echo $badge_shift; ?>'><?php echo $shift_label; ?></span>
            <span class='shift-window'><?php echo htmlspecialchars($shift_window); ?></span>
            <span class='hrs-chip'><?php echo $hours_week; ?>h this week</span>
            <a href='my_shifts.php'
               style='margin-left:auto; font-size:13px; color:#1a5ca8; white-space:nowrap;'>
                My schedule &rarr;
            </a>
        </div>

        <!-- ── Active (assigned + in-progress) visits ────────────────────────── -->
        <?php if (empty($active) && empty($completed)): ?>
            <?php echo empty_state(
                'No visits yet',
                'Pick a site and start a visit yourself, or wait for the office to assign one. Remember to clock in first.',
                'pick_site.php',
                '+ Start a visit'
            ); ?>

        <?php else: ?>

            <?php if (!empty($active)): ?>
                <div class='section-heading'>Active Visits</div>
                <div class='card card-table'>
                    <?php emp_visit_table($active); ?>
                </div>
            <?php else: ?>
                <p style='color:#888; font-size:13px; margin-bottom:16px;'>
                    No active visits right now &mdash; check back later.
                </p>
            <?php endif; ?>

            <?php if (!empty($completed_shown)): ?>
                <div class='section-heading'>Recently Completed</div>
                <div class='card card-table'>
                    <?php emp_visit_table($completed_shown); ?>
                </div>
                <?php if ($completed_total > 5): ?>
                    <p style='font-size:13px; color:#888; margin-top:-8px;'>
                        Showing 5 of <?php echo $completed_total; ?> completed visits.
                    </p>
                <?php endif; ?>
            <?php endif; ?>

        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
