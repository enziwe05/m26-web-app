<?php
/*
 * Staff view: all clock in/out entries, WITH the silently captured locations
 * (Google Maps links). This is the only place clock locations are shown —
 * employees never see them. Admin can manually close a stuck open entry.
 */
require_once 'incl/dbconn.php';
require_once 'incl/geo.php';
require_staff();

auto_close_stale_entries($conn); // sweep forgotten clock-outs before displaying

$message = '';

// ── Admin: manually close a stuck open entry ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close_entry') {
    csrf_check();
    if (!is_admin()) deny();

    $entry_id  = (int) ($_POST['entry_id'] ?? 0);
    $out_raw   = trim($_POST['clock_out_at'] ?? '');           // from <input type=datetime-local>
    $out_ts    = $out_raw !== '' ? strtotime($out_raw) : false;

    // Load the open entry to validate the close time against its clock-in
    $stmt = $conn->prepare("SELECT entry_id, clock_in_at FROM time_entries WHERE entry_id = ? AND status = 'open'");
    $stmt->bind_param('i', $entry_id);
    $stmt->execute();
    $entry = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$entry) {
        $message = 'That entry is no longer open.';
    } elseif ($out_ts === false || $out_ts <= strtotime($entry['clock_in_at'])) {
        $message = 'The clock-out time must be after the clock-in time.';
    } else {
        $out_dt = date('Y-m-d H:i:s', $out_ts);
        $stmt = $conn->prepare("
            UPDATE time_entries
            SET clock_out_at = ?, worked_minutes = TIMESTAMPDIFF(MINUTE, clock_in_at, ?),
                location_flag = CONCAT(COALESCE(location_flag, ''), CASE WHEN location_flag IS NULL OR location_flag = '' THEN '' ELSE ',' END, 'closed_by_admin'),
                status = 'closed'
            WHERE entry_id = ? AND status = 'open'
        ");
        $stmt->bind_param('ssi', $out_dt, $out_dt, $entry_id);
        $stmt->execute();
        $stmt->close();

        flash('Entry closed manually.');
        $qs = http_build_query(array_intersect_key($_POST, array_flip(['from', 'to', 'employee_id'])));
        header('Location: timesheets.php' . ($qs ? '?' . $qs : ''));
        exit;
    }
}

// ── Filters (default: current month) ──────────────────────────────────────────
$from        = $_GET['from'] ?? date('Y-m-01');
$to          = $_GET['to']   ?? date('Y-m-t');
$employee_id = (int) ($_GET['employee_id'] ?? $_POST['employee_id'] ?? 0);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-t');

$employees = $conn->query("SELECT user_id, first_name, last_name FROM users WHERE role = 'employee' ORDER BY first_name");

$sql = "
    SELECT te.*, u.first_name, u.last_name
    FROM time_entries te
    JOIN users u ON u.user_id = te.user_id
    WHERE DATE(te.clock_in_at) BETWEEN ? AND ?
";
$types  = 'ss';
$params = [$from, $to];
if ($employee_id > 0) {
    $sql    .= " AND te.user_id = ?";
    $types  .= 'i';
    $params[] = $employee_id;
}
$sql .= " ORDER BY te.clock_in_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Attendance overview: one query — all active employees ─────────────────────
// Night-shift entries crossing midnight are attributed to their clock-in calendar
// day (DATE(clock_in_at) = CURDATE()). Acceptable simplification: a shift that
// starts at 22:00 on 22 Jul counts as clocked-in on 22 Jul.
$att_stmt = $conn->prepare("
    SELECT
        u.user_id,
        u.first_name,
        u.last_name,
        u.shift_type,
        MAX(CASE WHEN te.status = 'open'              THEN te.clock_in_at  END) AS open_since,
        MAX(CASE WHEN DATE(te.clock_in_at) = CURDATE() THEN 1 ELSE 0      END) AS clocked_today,
        MAX(te.clock_in_at)  AS last_clock_in,
        MAX(te.clock_out_at) AS last_clock_out
    FROM users u
    LEFT JOIN time_entries te ON te.user_id = u.user_id
    WHERE u.role = 'employee' AND u.status = 'active'
    GROUP BY u.user_id, u.first_name, u.last_name, u.shift_type
");
$att_stmt->execute();
$att_rows = $att_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$att_stmt->close();

// Tag each row: absent → in → out, then sort absent-first
foreach ($att_rows as &$att) {
    if ($att['open_since'] !== null) {
        $att['_status'] = 'in';     // open entry exists right now
        $att['_sort']   = 1;
    } elseif ((int)$att['clocked_today'] > 0) {
        $att['_status'] = 'out';    // clocked in today but no longer open
        $att['_sort']   = 2;
    } else {
        $att['_status'] = 'absent'; // no entry today at all
        $att['_sort']   = 0;
    }
}
unset($att);
usort($att_rows, fn($a, $b) => $a['_sort'] <=> $b['_sort'] ?: strcmp($a['first_name'], $b['first_name']));

$att_absent = count(array_filter($att_rows, fn($r) => $r['_status'] === 'absent'));
$att_total  = count($att_rows);

// Friendly map button for a captured GPS point (or a "not captured" note)
function maps_link($lat, $lon, $acc): string {
    if ($lat === null || $lat === '' || $lon === null || $lon === '') {
        return '<span class="badge badge-loc-none">No GPS</span>';
    }
    $q   = rawurlencode($lat . ',' . $lon);
    $acc_html = ($acc !== null && $acc !== '')
        ? " <span class='loc-acc'>GPS &plusmn;" . htmlspecialchars((string)$acc) . " m</span>"
        : '';
    return "<a href='https://maps.google.com/?q={$q}' target='_blank' class='btn btn-secondary btn-map'>View on map</a>{$acc_html}";
}

// Translate a space-or-comma-separated location_flag string into readable badges
function location_flag_badges(?string $flag): string {
    if ($flag === null || $flag === '') return '—';
    // Flags may be space-separated (auto_clocked_out) or comma-separated (closed_by_admin)
    $tokens = preg_split('/[\s,]+/', trim($flag), -1, PREG_SPLIT_NO_EMPTY);
    $html = '';
    foreach ($tokens as $t) {
        switch ($t) {
            case 'in_unavailable':
            case 'out_unavailable':
            case 'in_out_unavailable':
                $label = str_replace('_', ' ', $t);
                $html .= "<span class='badge badge-loc-none' title='Location not captured'>" . htmlspecialchars($label) . "</span> ";
                break;
            case 'auto_clocked_out':
                $html .= "<span class='badge badge-attention'>Auto clock-out &mdash; verify</span> ";
                break;
            case 'closed_by_admin':
                $html .= "<span class='badge badge-assigned'>Closed by admin</span> ";
                break;
            default:
                $html .= "<span class='badge badge-attention'>" . htmlspecialchars($t) . "</span> ";
        }
    }
    return trim($html);
}

$page_title = 'M26 | Timesheets';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Timesheets</h1>
        </div>
        <p class='page-intro'>Clock-in and clock-out records for all employees. Captured locations are for the office record only — employees never see them.</p>

        <?php if ($message !== ''): ?>
            <div class='alert alert-error'><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class='section-heading'>Attendance Today (<?php echo date('d M Y'); ?>)</div>
        <p style='font-size:14px; margin-bottom:14px;'>
            <?php if ($att_absent > 0): ?>
                <strong style='color:#b35900;'><?php echo $att_absent; ?> of <?php echo $att_total; ?></strong>
            <?php else: ?>
                <strong style='color:#1a6b1a;'>0 of <?php echo $att_total; ?></strong>
            <?php endif; ?>
            employee<?php echo $att_absent !== 1 ? 's have' : ' has'; ?> not clocked in today.
        </p>

        <div class='card card-table' style='overflow-x:auto; margin-bottom:28px;'>
            <table class='data-table'>
                <tr>
                    <th>Employee</th><th>Shift</th><th>Today</th><th>Last clock-in</th><th>Last clock-out</th>
                </tr>
                <?php foreach ($att_rows as $att): ?>
                <tr class='<?php echo $att['_status'] === 'absent' ? 'att-row-absent' : ''; ?>'>
                    <td><?php echo htmlspecialchars($att['first_name'] . ' ' . $att['last_name']); ?></td>
                    <td>
                        <?php if ($att['shift_type'] === 'day' || $att['shift_type'] === 'night'): ?>
                            <span class='badge badge-shift-<?php echo $att['shift_type']; ?>'><?php echo ucfirst($att['shift_type']); ?></span>
                        <?php else: ?>
                            <span style='color:#aaa;'>—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($att['_status'] === 'in'): ?>
                            <span class='badge badge-clocked-in'>Clocked in</span>
                            <span style='color:#666; font-size:12px;'> since <?php echo date('H:i', strtotime($att['open_since'])); ?></span>
                        <?php elseif ($att['_status'] === 'out'): ?>
                            <span class='badge badge-clocked-out'>Clocked out</span>
                        <?php else: ?>
                            <span class='badge badge-not-clocked'>Not clocked in today</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo fmt_datetime($att['last_clock_in']); ?></td>
                    <td><?php echo $att['last_clock_out'] ? fmt_datetime($att['last_clock_out']) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <hr class='section-divider'>

        <form method='GET' action='timesheets.php' class='filter-bar'>
            <div class='filter-field'>
                <label for='f-from'>From</label>
                <input type='date' id='f-from' name='from' value='<?php echo htmlspecialchars($from); ?>'>
            </div>
            <div class='filter-field'>
                <label for='f-to'>To</label>
                <input type='date' id='f-to' name='to' value='<?php echo htmlspecialchars($to); ?>'>
            </div>
            <div class='filter-field'>
                <label for='f-emp'>Employee</label>
                <select id='f-emp' name='employee_id'>
                    <option value='0'>All employees</option>
                    <?php while ($e = $employees->fetch_assoc()): ?>
                    <option value='<?php echo $e['user_id']; ?>'<?php echo $employee_id === (int) $e['user_id'] ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <button type='submit' class='btn btn-secondary'>Filter</button>
        </form>

        <?php if (empty($entries)): ?>
            <?php echo empty_state(
                'No clock entries in this period',
                'Entries appear here as soon as employees start clocking in from the Clock page.'
            ); ?>
        <?php else: ?>
        <div class='card card-table' style='overflow-x:auto;'>
            <table class='data-table'>
                <tr>
                    <th>Employee</th><th>Date</th><th>Shift</th><th>In</th><th>Out</th><th>Hours</th>
                    <th>Clock-in location</th><th>Clock-out location</th><th>Flag</th><th></th>
                </tr>
                <?php foreach ($entries as $te): ?>
                <tr>
                    <td><?php echo htmlspecialchars($te['first_name'] . ' ' . $te['last_name']); ?></td>
                    <td><?php echo fmt_date($te['clock_in_at']); ?></td>
                    <td><span class='badge badge-shift-<?php echo $te['shift_type']; ?>'><?php echo ucfirst($te['shift_type']); ?></span></td>
                    <td><?php echo date('H:i', strtotime($te['clock_in_at'])); ?></td>
                    <td>
                        <?php if ($te['status'] === 'open'): ?>
                            <span class='badge badge-in-progress'>open</span>
                        <?php else: ?>
                            <?php echo $te['clock_out_at'] ? date('H:i', strtotime($te['clock_out_at'])) : '—'; ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $te['worked_minutes'] !== null ? number_format($te['worked_minutes'] / 60, 2) : '—'; ?></td>
                    <td><?php echo maps_link($te['clock_in_lat'], $te['clock_in_lon'], $te['clock_in_accuracy']); ?></td>
                    <td><?php echo maps_link($te['clock_out_lat'], $te['clock_out_lon'], $te['clock_out_accuracy']); ?></td>
                    <td><?php echo location_flag_badges($te['location_flag']); ?></td>
                    <td>
                        <?php if ($te['status'] === 'open' && is_admin()): ?>
                        <form method='POST' action='timesheets.php' style='display:flex; gap:4px; align-items:center;'
                              onsubmit="return confirm('Close this open entry at the chosen time?');">
                            <?php echo csrf_field(); ?>
                            <input type='hidden' name='action' value='close_entry'>
                            <input type='hidden' name='entry_id' value='<?php echo $te['entry_id']; ?>'>
                            <input type='hidden' name='from' value='<?php echo htmlspecialchars($from); ?>'>
                            <input type='hidden' name='to' value='<?php echo htmlspecialchars($to); ?>'>
                            <input type='hidden' name='employee_id' value='<?php echo $employee_id; ?>'>
                            <input type='datetime-local' name='clock_out_at'
                                   value='<?php echo date('Y-m-d\TH:i'); ?>'
                                   style='font-size:12px; padding:3px 5px;'>
                            <button type='submit' class='btn btn-danger' style='font-size:12px; padding:4px 10px;'>Close</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
