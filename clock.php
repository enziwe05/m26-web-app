<?php
/*
 * Employee clock in / clock out.
 * Location is captured silently in the background at both clock actions and
 * is NEVER shown to the employee — it is recorded for the office only.
 * If GPS is denied or times out the clock action still goes through.
 */
require_once 'incl/dbconn.php';
require_once 'incl/geo.php';
require_employee();

auto_close_stale_entries($conn); // sweep forgotten clock-outs before any read

$user_id = current_user_id();

// ── POST: perform the clock action ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['clock_action'] ?? '';
    $lat = substr(trim($_POST['lat'] ?? ''), 0, 30);
    $lon = substr(trim($_POST['lon'] ?? ''), 0, 30);
    $acc = substr(trim($_POST['accuracy'] ?? ''), 0, 20);
    // HARD LOCATION REQUIREMENT: a real GPS fix is mandatory to clock in or out.
    // Half-captured or missing coordinates are rejected below (server-side guard,
    // so it holds even if someone bypasses the browser JavaScript).
    $geo_missing = ($lat === '' || $lon === '');
    if ($geo_missing) { $lat = null; $lon = null; $acc = null; }
    if (($action === 'in' || $action === 'out') && $geo_missing) {
        flash('Location is required to clock in or out. Please turn on your phone\'s location, allow it for this site, then try again.');
        header('Location: clock.php');
        exit;
    }

    // Current open entry (if any) decides what action is valid
    $stmt = $conn->prepare("SELECT entry_id, location_flag FROM time_entries WHERE user_id = ? AND status = 'open' ORDER BY clock_in_at DESC LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $open = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($action === 'in' && !$open) {
        // Shift type: the employee's standing shift (users.shift_type) wins.
        // If they haven't been assigned a standing shift yet, fall back to the
        // hour-of-day heuristic (evening/early-morning → night, otherwise day).
        $stmt = $conn->prepare("SELECT shift_type FROM users WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $user_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $assignment_id = null; // shift_assignments table no longer drives clock-in
        if (!empty($user_row['shift_type'])) {
            $shift_type = $user_row['shift_type'];
        } else {
            $hour       = (int) date('G');
            $shift_type = ($hour >= 17 || $hour < 8) ? 'night' : 'day';
        }

        $now  = date('Y-m-d H:i:s');
        $flag = null; // location is guaranteed present (hard requirement above)
        $stmt = $conn->prepare("
            INSERT INTO time_entries (user_id, assignment_id, shift_type, clock_in_at,
                                      clock_in_lat, clock_in_lon, clock_in_accuracy, location_flag, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open')
        ");
        $stmt->bind_param('iissssss', $user_id, $assignment_id, $shift_type, $now, $lat, $lon, $acc, $flag);
        $stmt->execute();
        $stmt->close();

        flash('Clocked in at ' . date('H:i') . '. Have a good shift!');
        header('Location: clock.php');
        exit;
    }

    if ($action === 'out' && $open) {
        // Location is guaranteed present (hard requirement above); just keep any
        // existing flag (e.g. an auto_clocked_out marker) untouched.
        $flag = $open['location_flag'];

        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            UPDATE time_entries
            SET clock_out_at = ?, worked_minutes = TIMESTAMPDIFF(MINUTE, clock_in_at, ?),
                clock_out_lat = ?, clock_out_lon = ?, clock_out_accuracy = ?,
                location_flag = ?, status = 'closed'
            WHERE entry_id = ? AND user_id = ? AND status = 'open'
        ");
        $entry_id = (int) $open['entry_id'];
        $stmt->bind_param('ssssssii', $now, $now, $lat, $lon, $acc, $flag, $entry_id, $user_id);
        $stmt->execute();
        $stmt->close();

        flash('Clocked out at ' . date('H:i') . '. Well done today!');
        header('Location: clock.php');
        exit;
    }

    // Action didn't match the current state (double-submit etc.) — just reload
    header('Location: clock.php');
    exit;
}

// ── Load state for the page ───────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT entry_id, shift_type, clock_in_at FROM time_entries WHERE user_id = ? AND status = 'open' ORDER BY clock_in_at DESC LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$open_entry = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Standing shift from the employee's user record (replaces per-date shift_assignments)
$stmt = $conn->prepare("SELECT shift_type FROM users WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user_row       = $stmt->get_result()->fetch_assoc();
$stmt->close();
$standing_shift = $user_row['shift_type'] ?? null; // 'day', 'night', or null

// Last few closed entries (times only — no locations, ever)
$stmt = $conn->prepare("
    SELECT shift_type, clock_in_at, clock_out_at, worked_minutes
    FROM time_entries
    WHERE user_id = ? AND status = 'closed'
    ORDER BY clock_in_at DESC LIMIT 5
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$page_title = 'M26 | Clock In / Out';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Clock In / Out</h1>
            <span>Hi, <?php echo htmlspecialchars(current_user_name()); ?></span>
        </div>

        <div class='card clock-card'>
            <?php if ($standing_shift): ?>
            <p class='clock-shift-note'>
                You are on the
                <span class='badge badge-shift-<?php echo $standing_shift; ?>'><?php echo $standing_shift === 'night' ? 'Night shift' : 'Day shift'; ?></span>
            </p>
            <?php endif; ?>

            <?php if ($open_entry): ?>
                <p class='clock-status-label'>You are clocked in</p>
                <p class='clock-since'>Since <strong><?php echo fmt_datetime($open_entry['clock_in_at']); ?></strong>
                   (<?php echo $open_entry['shift_type'] === 'night' ? 'night' : 'day'; ?> shift)</p>

                <form method='POST' action='clock.php' id='clock-form'>
                    <?php echo csrf_field(); ?>
                    <input type='hidden' name='clock_action' value='out'>
                    <input type='hidden' name='lat' id='geo-lat' value=''>
                    <input type='hidden' name='lon' id='geo-lon' value=''>
                    <input type='hidden' name='accuracy' id='geo-acc' value=''>
                    <p class='clock-geo-status' id='geo-status'></p>
                    <button type='submit' class='btn btn-danger clock-btn' id='clock-btn'>Clock Out</button>
                </form>

                <a href='employee_dashboard.php' class='btn btn-primary btn-full' style='margin-top:16px;'>Continue to my visits &rarr;</a>
                <p style='margin-top:10px;color:#666;font-size:13px;text-align:center;'>You're clocked in — you can now go to your site visits. Remember to clock out at the end of your shift.</p>
            <?php else: ?>
                <p class='clock-status-label'>You are not clocked in</p>
                <div class='clock-elapsed' id='clock-now'><?php echo date('H:i'); ?></div>

                <form method='POST' action='clock.php' id='clock-form'>
                    <?php echo csrf_field(); ?>
                    <input type='hidden' name='clock_action' value='in'>
                    <input type='hidden' name='lat' id='geo-lat' value=''>
                    <input type='hidden' name='lon' id='geo-lon' value=''>
                    <input type='hidden' name='accuracy' id='geo-acc' value=''>
                    <p class='clock-geo-status' id='geo-status'></p>
                    <button type='submit' class='btn btn-primary clock-btn' id='clock-btn'>Clock In</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!empty($recent)): ?>
        <div class='section-heading'>Recent Clock History</div>
        <div class='card card-table'>
            <table class='data-table'>
                <tr><th>Date</th><th>Shift</th><th>In</th><th>Out</th><th>Hours</th></tr>
                <?php foreach ($recent as $r): ?>
                <tr>
                    <td><?php echo fmt_date($r['clock_in_at']); ?></td>
                    <td><span class='badge badge-shift-<?php echo $r['shift_type']; ?>'><?php echo ucfirst($r['shift_type']); ?></span></td>
                    <td><?php echo date('H:i', strtotime($r['clock_in_at'])); ?></td>
                    <td><?php echo $r['clock_out_at'] ? date('H:i', strtotime($r['clock_out_at'])) : '—'; ?></td>
                    <td><?php echo $r['worked_minutes'] !== null ? number_format($r['worked_minutes'] / 60, 2) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <p style='margin-top:10px;'><a href='my_shifts.php'>View my full shift roster &amp; history &rarr;</a></p>
        <?php endif; ?>

        <script>
        /*
         * Location handling for clock in / out.
         *
         * Requirement: a real GPS fix is MANDATORY. The button is blocked until
         * the browser returns a location (server also enforces this).
         *
         * "Ask once": the browser only shows the permission popup the first time
         * (right after login, on this page). Once the employee taps Allow, the
         * grant is remembered by the browser and every later read happens
         * silently — no popup ever again. We use the Permissions API to read
         * silently when already granted and only actively prompt when the
         * decision hasn't been made yet.
         */
        (function () {
            var form   = document.getElementById('clock-form');
            var btn    = document.getElementById('clock-btn');
            var status = document.getElementById('geo-status');
            if (!form || !btn) return;

            var actionIn = form.querySelector('[name=clock_action]').value === 'in';
            var readyLabel = actionIn ? 'Clock In' : 'Clock Out';
            var submitted = false;

            function setStatus(msg, kind) {
                if (!status) return;
                status.textContent = msg;
                status.className = 'clock-geo-status geo-' + (kind || 'info');
            }

            function failMessage(code) {
                if (code === 1) // PERMISSION_DENIED
                    return 'Location is blocked for this site. Open your browser settings, allow location for this page, then reload.';
                if (code === 'unsupported')
                    return 'This browser cannot provide location. Please use the work phone browser.';
                return 'Can’t get your location. Turn ON your phone’s location / GPS, then tap the button again.';
            }

            // Ask the browser for a position. ok() on success, fail(code) otherwise.
            function getFix(ok, fail) {
                if (!navigator.geolocation) { fail('unsupported'); return; }
                navigator.geolocation.getCurrentPosition(function (pos) {
                    document.getElementById('geo-lat').value = pos.coords.latitude.toFixed(7);
                    document.getElementById('geo-lon').value = pos.coords.longitude.toFixed(7);
                    document.getElementById('geo-acc').value = Math.round(pos.coords.accuracy);
                    ok();
                }, function (err) {
                    fail(err ? err.code : 0);
                }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
            }

            // On page load: get an early fix so it's ready when they tap the button.
            // If permission is already granted this is silent (no popup). If it's
            // never been decided, this is the single login-time popup.
            setStatus('Checking your location…', 'info');
            if (navigator.permissions && navigator.permissions.query) {
                navigator.permissions.query({ name: 'geolocation' }).then(function (p) {
                    if (p.state === 'denied') { setStatus(failMessage(1), 'warn'); return; }
                    getFix(
                        function () { setStatus('Location on ✓ Ready.', 'ok'); },
                        function (code) { setStatus(failMessage(code), 'warn'); }
                    );
                }).catch(function () {
                    getFix(
                        function () { setStatus('Location on ✓ Ready.', 'ok'); },
                        function (code) { setStatus(failMessage(code), 'warn'); }
                    );
                });
            } else {
                getFix(
                    function () { setStatus('Location on ✓ Ready.', 'ok'); },
                    function (code) { setStatus(failMessage(code), 'warn'); }
                );
            }

            // On submit: HARD BLOCK — only go through with a fresh, valid fix.
            form.addEventListener('submit', function (e) {
                if (submitted) return;          // second pass after a good fix — allow it
                e.preventDefault();
                btn.disabled = true;
                btn.textContent = 'Getting your location…';
                setStatus('Getting your location…', 'info');

                getFix(function () {
                    submitted = true;
                    form.submit();
                }, function (code) {
                    btn.disabled = false;
                    btn.textContent = readyLabel;
                    setStatus(failMessage(code), 'warn');
                });
            });
        })();
        </script>

<?php include 'incl/footer.php'; ?>
