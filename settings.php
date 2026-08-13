<?php
/*
 * Admin: app settings — shift windows, normal hours per shift, on-site radius,
 * minimum photos required, and auto clock-out grace period.
 */
require_once 'incl/dbconn.php';
require_admin();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $day_start   = $_POST['day_start'] ?? '';
    $day_end     = $_POST['day_end'] ?? '';
    $night_start = $_POST['night_start'] ?? '';
    $night_end   = $_POST['night_end'] ?? '';
    $time_ok = function ($t) { return preg_match('/^\d{2}:\d{2}$/', $t); };

    $day_normal      = (float) ($_POST['day_normal_hours']    ?? 0);
    $night_normal    = (float) ($_POST['night_normal_hours']  ?? 0);
    $onsite_radius   = (int)   ($_POST['onsite_radius_m']     ?? 500);
    $min_photos      = (int)   ($_POST['min_photos']          ?? 1);
    $autoclose_grace = (int)   ($_POST['autoclose_grace_mins'] ?? 120);
    $stale_days      = (int)   ($_POST['stale_days']          ?? 120);

    if (!$time_ok($day_start) || !$time_ok($day_end) || !$time_ok($night_start) || !$time_ok($night_end)) {
        $message = 'Shift times must be in HH:MM format.';
    } elseif ($day_normal <= 0 || $night_normal <= 0) {
        $message = 'Normal hours must be greater than zero.';
    } elseif ($onsite_radius < 1 || $min_photos < 1 || $autoclose_grace < 1 || $stale_days < 1) {
        $message = 'On-site radius, minimum photos, auto clock-out grace, and stale-site window must each be at least 1.';
    } else {
        $stmt = $conn->prepare("
            UPDATE payroll_settings
            SET day_start = ?, day_end = ?, day_normal_hours = ?,
                night_start = ?, night_end = ?, night_normal_hours = ?,
                onsite_radius_m = ?, min_photos = ?, autoclose_grace_mins = ?,
                stale_days = ?
            WHERE id = 1
        ");
        $stmt->bind_param('ssdssdiiii',
            $day_start, $day_end, $day_normal,
            $night_start, $night_end, $night_normal,
            $onsite_radius, $min_photos, $autoclose_grace, $stale_days
        );
        $stmt->execute();
        $stmt->close();

        flash('Settings saved.');
        header('Location: settings.php');
        exit;
    }
}

// Load current settings (kept columns only)
$s = $conn->query(
    "SELECT day_start, day_end, day_normal_hours,
            night_start, night_end, night_normal_hours,
            onsite_radius_m, min_photos, autoclose_grace_mins, stale_days
     FROM payroll_settings WHERE id = 1"
)->fetch_assoc();

// TIME columns come back as HH:MM:SS — the inputs want HH:MM
$t = fn ($v) => substr($v ?? '', 0, 5);

$page_title = 'M26 | Settings';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>App Settings</h1>
        </div>
        <p class='page-intro'>Configure shift windows, normal hours per shift, and location &amp; automation rules used by clock-in, form submissions, and auto clock-out.</p>

        <?php if ($message !== ''): ?>
            <div class='alert alert-error'><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class='card'>
        <form method='POST' action='settings.php'>
            <?php echo csrf_field(); ?>

            <div class='section-heading' style='margin-top:0;'>Shift Windows</div>
            <div class='settings-grid'>
                <div class='form-group'>
                    <label>Day shift start</label>
                    <input type='time' name='day_start' value='<?php echo htmlspecialchars($t($s['day_start'])); ?>'>
                </div>
                <div class='form-group'>
                    <label>Day shift end</label>
                    <input type='time' name='day_end' value='<?php echo htmlspecialchars($t($s['day_end'])); ?>'>
                </div>
                <div class='form-group'>
                    <label>Day normal hours</label>
                    <input type='number' step='0.25' min='1' name='day_normal_hours' value='<?php echo htmlspecialchars($s['day_normal_hours']); ?>'>
                </div>
                <div class='form-group'>
                    <label>Night shift start</label>
                    <input type='time' name='night_start' value='<?php echo htmlspecialchars($t($s['night_start'])); ?>'>
                </div>
                <div class='form-group'>
                    <label>Night shift end</label>
                    <input type='time' name='night_end' value='<?php echo htmlspecialchars($t($s['night_end'])); ?>'>
                </div>
                <div class='form-group'>
                    <label>Night normal hours</label>
                    <input type='number' step='0.25' min='1' name='night_normal_hours' value='<?php echo htmlspecialchars($s['night_normal_hours']); ?>'>
                </div>
            </div>

            <div class='section-heading'>Location &amp; Automation</div>
            <div class='settings-grid'>
                <div class='form-group'>
                    <label>On-site radius (metres)</label>
                    <input type='number' step='1' min='1' name='onsite_radius_m' value='<?php echo htmlspecialchars((string)(int)$s['onsite_radius_m']); ?>'>
                    <small style='color:#888;'>Distance from the site tower within which a form submission counts as "on site". Default 500 m.</small>
                </div>
                <div class='form-group'>
                    <label>Minimum photos to submit a form</label>
                    <input type='number' step='1' min='1' name='min_photos' value='<?php echo htmlspecialchars((string)(int)$s['min_photos']); ?>'>
                    <small style='color:#888;'>Technicians must attach at least this many photos before they can finalise a maintenance form.</small>
                </div>
                <div class='form-group'>
                    <label>Auto clock-out grace period (minutes)</label>
                    <input type='number' step='1' min='1' name='autoclose_grace_mins' value='<?php echo htmlspecialchars((string)(int)$s['autoclose_grace_mins']); ?>'>
                    <small style='color:#888;'>Minutes after the scheduled shift end before a forgotten clock-out is auto-closed. Default 120 (2 hours).</small>
                </div>
                <div class='form-group'>
                    <label>Stale-site window (days)</label>
                    <input type='number' step='1' min='1' name='stale_days' value='<?php echo htmlspecialchars((string)(int)$s['stale_days']); ?>'>
                    <small style='color:#888;'>A site is flagged on the <a href='stale_sites.php'>Stale Sites</a> page and dashboard when its last completed visit is older than this. Default 120 (the 4-month cycle).</small>
                </div>
            </div>

            <input type='submit' value='Save Settings' class='btn btn-primary'>
        </form>
        </div>

<?php include 'incl/footer.php'; ?>
