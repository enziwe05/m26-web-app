<?php
require_once 'incl/dbconn.php';
require_staff();

// ── Metric queries ─────────────────────────────────────────────────────────────
$total_sites = (int)$conn->query(
    "SELECT COUNT(*) AS n FROM sites"
)->fetch_assoc()['n'];

$total_employees = (int)$conn->query(
    "SELECT COUNT(*) AS n FROM users WHERE role='employee' AND status='active'"
)->fetch_assoc()['n'];

// Active visits = assigned + in_progress
$active_visits = (int)$conn->query(
    "SELECT COUNT(*) AS n FROM visits WHERE status IN ('assigned','in_progress')"
)->fetch_assoc()['n'];

// Completed this calendar month
$completed_month = (int)$conn->query(
    "SELECT COUNT(*) AS n FROM visits
     WHERE status='completed'
     AND YEAR(completed_at)=YEAR(CURDATE()) AND MONTH(completed_at)=MONTH(CURDATE())"
)->fetch_assoc()['n'];

// Employees currently clocked in (at least one open time_entry)
$clocked_in_now = (int)$conn->query(
    "SELECT COUNT(DISTINCT user_id) AS n FROM time_entries WHERE status='open'"
)->fetch_assoc()['n'];

// Maintenance forms submitted this week (Monday → today)
$forms_week = (int)$conn->query(
    "SELECT COUNT(*) AS n FROM maintenance_forms
     WHERE is_submitted=1
     AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)"
)->fetch_assoc()['n'];

// Off-site submissions this calendar month
$offsite_month = (int)$conn->query(
    "SELECT COUNT(*) AS n FROM maintenance_forms
     WHERE submit_location_status='away'
     AND YEAR(submitted_at)=YEAR(CURDATE()) AND MONTH(submitted_at)=MONTH(CURDATE())"
)->fetch_assoc()['n'];

// ── Maintenance-status buckets (drives the donut + the stat card) ──────────────
$stale_days = (int)($conn->query(
    "SELECT stale_days FROM payroll_settings WHERE id=1"
)->fetch_assoc()['stale_days'] ?? 120);
if ($stale_days < 1) $stale_days = 120;
$cd  = $stale_days;       // int under our control — safe to inline
$cd2 = $stale_days * 2;

$mb = $conn->query("
    SELECT
      SUM(lv.last_visit IS NULL)                                                             AS never_c,
      SUM(lv.last_visit IS NOT NULL AND DATEDIFF(CURDATE(),lv.last_visit) >= $cd2)           AS urgent_c,
      SUM(lv.last_visit IS NOT NULL AND DATEDIFF(CURDATE(),lv.last_visit) >= $cd
                                    AND DATEDIFF(CURDATE(),lv.last_visit) <  $cd2)           AS overdue_c,
      SUM(lv.last_visit IS NOT NULL AND DATEDIFF(CURDATE(),lv.last_visit) <  $cd)            AS good_c,
      COUNT(*)                                                                               AS total_c
    FROM sites s
    LEFT JOIN (
        SELECT site_id, MAX(completed_at) AS last_visit
        FROM visits WHERE status='completed' GROUP BY site_id
    ) lv ON lv.site_id = s.site_id
")->fetch_assoc();

$mb_never   = (int)$mb['never_c'];
$mb_urgent  = (int)$mb['urgent_c'];
$mb_overdue = (int)$mb['overdue_c'];
$mb_good    = (int)$mb['good_c'];
$mb_total   = max(1, (int)$mb['total_c']);
$stale_sites = $mb_never + $mb_urgent + $mb_overdue;     // "behind the cycle"
$pct_good    = (int) round($mb_good / $mb_total * 100);

// ── Completed visits per month, last 6 months (zero-filled) ───────────────────
$raw = [];
$rs = $conn->query("
    SELECT DATE_FORMAT(completed_at,'%Y-%m') AS ym, COUNT(*) AS n
    FROM visits
    WHERE status='completed' AND completed_at >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 5 MONTH)
    GROUP BY ym
");
while ($r = $rs->fetch_assoc()) $raw[$r['ym']] = (int)$r['n'];
$months6 = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("first day of -$i month"));
    $months6[] = ['label' => date('M', strtotime($key . '-01')), 'n' => $raw[$key] ?? 0];
}
$month_max = max(1, max(array_column($months6, 'n')));

// ── Sites behind the cycle, by region ─────────────────────────────────────────
$regions = [];
$rr = $conn->query("
    SELECT COALESCE(NULLIF(s.region,''),'—') AS region,
           SUM(lv.last_visit IS NULL OR DATEDIFF(CURDATE(),lv.last_visit) >= $cd) AS behind
    FROM sites s
    LEFT JOIN (
        SELECT site_id, MAX(completed_at) AS last_visit
        FROM visits WHERE status='completed' GROUP BY site_id
    ) lv ON lv.site_id = s.site_id
    GROUP BY region HAVING behind > 0 ORDER BY behind DESC
");
while ($r = $rr->fetch_assoc()) $regions[] = ['region' => $r['region'], 'behind' => (int)$r['behind']];
$region_max = max(1, max(array_column($regions, 'behind') ?: [1]));

// ── Mini visits list (last 10, with optional site search) ─────────────────────
$q        = trim($_GET['q'] ?? '');
$v_where  = '1=1';
$v_params = [];
$v_types  = '';

if ($q !== '') {
    $v_where   .= ' AND (s.site_code LIKE ? OR s.site_name LIKE ?)';
    $like       = '%' . $q . '%';
    $v_params[] = $like;
    $v_params[] = $like;
    $v_types   .= 'ss';
}

$v_sql = "
    SELECT v.visit_id, s.site_code, s.site_name, v.visit_type, v.status,
           v.scheduled_date, u.first_name, u.last_name
    FROM visits v
    JOIN sites s ON s.site_id  = v.site_id
    JOIN users u ON u.user_id  = v.assigned_to_user_id
    WHERE $v_where
    ORDER BY v.created_at DESC
    LIMIT 10
";

if ($v_params) {
    $vstmt = $conn->prepare($v_sql);
    $vstmt->bind_param($v_types, ...$v_params);
    $vstmt->execute();
    $recent = $vstmt->get_result();
    $vstmt->close();
} else {
    $recent = $conn->query($v_sql);
}

$page_title = 'M26 | Dashboard';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Dashboard</h1>
            <span>Welcome, <?php echo htmlspecialchars(current_user_name()); ?></span>
        </div>

        <!-- ── Metric cards ──────────────────────────────────────────────────── -->
        <div class='stat-row'>
            <a class='stat-box' href='view_sites.php'>
                <div class='stat-label'>Total Sites</div>
                <div class='stat-value'><?php echo $total_sites; ?></div>
            </a>
            <a class='stat-box' href='view_employees.php'>
                <div class='stat-label'>Active Staff</div>
                <div class='stat-value'><?php echo $total_employees; ?></div>
            </a>
            <a class='stat-box' href='view_visits.php?status=assigned'>
                <div class='stat-label'>Active Visits</div>
                <div class='stat-value'><?php echo $active_visits; ?></div>
            </a>
            <a class='stat-box' href='view_visits.php?status=completed'>
                <div class='stat-label'>Completed This Month</div>
                <div class='stat-value'><?php echo $completed_month; ?></div>
            </a>
            <a class='stat-box<?php echo $clocked_in_now > 0 ? ' stat-box--green' : ''; ?>' href='timesheets.php'>
                <div class='stat-label'>Clocked In Now</div>
                <div class='stat-value'><?php echo $clocked_in_now; ?></div>
            </a>
            <a class='stat-box' href='view_visits.php?status=completed'>
                <div class='stat-label'>Forms This Week</div>
                <div class='stat-value'><?php echo $forms_week; ?></div>
            </a>
            <a class='stat-box<?php echo $offsite_month > 0 ? ' stat-box--amber' : ''; ?>' href='offsite_submissions.php'>
                <div class='stat-label'>&#9888; Off-Site Submissions (month)</div>
                <div class='stat-value'><?php echo $offsite_month; ?></div>
            </a>
            <a class='stat-box<?php echo $stale_sites > 0 ? ' stat-box--red' : ''; ?>' href='stale_sites.php'>
                <div class='stat-label'>&#9888; Maintenance Status</div>
                <div class='stat-value'><?php echo $stale_sites; ?></div>
            </a>
        </div>

        <!-- ── Charts ────────────────────────────────────────────────────────── -->
        <?php
        $cycle_months  = (int) round($cd / 30);
        $urgent_months = (int) round($cd2 / 30);
        $donut_segs = [
            ['#28a745', $mb_good,    'Up to date'],
            ['#e0a800', $mb_overdue, $cycle_months . '–' . $urgent_months . ' months'],
            ['#e74c3c', $mb_urgent,  'Over ' . $urgent_months . ' months'],
            ['#c0392b', $mb_never,   'Never checked'],
        ];
        $C = 2 * M_PI * 52;   // donut circumference
        ?>
        <div class='dash-charts'>

            <!-- Donut: maintenance status -->
            <div class='chart-card'>
                <h3>Maintenance Status</h3>
                <p class='chart-sub'><?php echo $stale_sites; ?> of <?php echo $mb_total; ?> sites behind the <?php echo $cycle_months; ?>-month cycle</p>
                <div class='donut-wrap'>
                    <div class='donut'>
                        <svg viewBox='0 0 120 120' width='132' height='132'>
                            <circle cx='60' cy='60' r='52' fill='none' stroke='#eef1f6' stroke-width='16'/>
                            <?php $acc = 0; foreach ($donut_segs as [$color, $val, $lbl]):
                                if ($val <= 0) continue;
                                $len = $val / $mb_total * $C; ?>
                                <circle cx='60' cy='60' r='52' fill='none' stroke='<?php echo $color; ?>'
                                        stroke-width='16'
                                        stroke-dasharray='<?php echo round($len, 2) . ' ' . round($C - $len, 2); ?>'
                                        stroke-dashoffset='<?php echo round(-$acc, 2); ?>'
                                        transform='rotate(-90 60 60)'/>
                                <?php $acc += $len; endforeach; ?>
                        </svg>
                        <div class='donut-center'>
                            <span class='donut-pct'><?php echo $pct_good; ?>%</span>
                            <span class='donut-cap'>on cycle</span>
                        </div>
                    </div>
                    <ul class='donut-legend'>
                        <?php foreach ($donut_segs as [$color, $val, $lbl]): ?>
                        <li><span class='sw' style='background:<?php echo $color; ?>;'></span>
                            <?php echo $lbl; ?> <span class='lv'><?php echo $val; ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <!-- Bars: completed visits per month -->
            <div class='chart-card'>
                <h3>Completed Visits</h3>
                <p class='chart-sub'>Last 6 months</p>
                <div class='bars'>
                    <?php foreach ($months6 as $m):
                        $h = (int) round($m['n'] / $month_max * 100); ?>
                    <div class='bar-col'>
                        <span class='bar-val'><?php echo $m['n']; ?></span>
                        <div class='bar' style='height:<?php echo max(3, $h); ?>%;'></div>
                        <span class='bar-lbl'><?php echo $m['label']; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Horizontal bars: sites behind by region -->
            <div class='chart-card'>
                <h3>Sites Behind by Region</h3>
                <p class='chart-sub'>Overdue or never visited</p>
                <?php if (!$regions): ?>
                    <p style='color:#28a745; font-size:14px;'>&#9989; No regions behind — all caught up.</p>
                <?php else: ?>
                <div class='hbars'>
                    <?php foreach ($regions as $rg):
                        $w = (int) round($rg['behind'] / $region_max * 100); ?>
                    <div class='hbar-row'>
                        <span class='hbar-name'><?php echo htmlspecialchars($rg['region']); ?></span>
                        <div class='hbar-track'><div class='hbar-fill' style='width:<?php echo max(2, $w); ?>%;'></div></div>
                        <span class='hbar-val'><?php echo $rg['behind']; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- ── Recent Visits ─────────────────────────────────────────────────── -->
        <div class='page-heading'>
            <h2>Recent Visits</h2>
            <a href='create_visit.php' class='btn btn-primary'>+ Create Visit</a>
        </div>

        <form method='GET' action='admin_dashboard.php' class='mini-visit-bar'>
            <input type='text' name='q'
                   value='<?php echo htmlspecialchars($q); ?>'
                   placeholder='Search by site code or name&hellip;'>
            <button type='submit' class='btn btn-secondary'>Search</button>
            <?php if ($q !== ''): ?>
                <a href='admin_dashboard.php' class='btn btn-secondary'>Clear</a>
            <?php endif; ?>
            <a href='view_visits.php' class='btn btn-secondary view-all-link'>View all visits &rarr;</a>
        </form>

        <?php
        if ($recent->num_rows === 0) {
            if ($q !== '') {
                echo "<p style='color:#888;'>No visits match <strong>" . htmlspecialchars($q)
                   . "</strong>. <a href='admin_dashboard.php'>Clear search</a>.</p>";
            } else {
                echo empty_state(
                    'No visits yet',
                    'Create the first site visit to get started.',
                    'create_visit.php',
                    '+ Create Visit'
                );
            }
        } else {
            echo "<div class='table-scroll'>";
            echo "<table class='data-table'>";
            echo "<tr><th>Site</th><th>Type</th><th>Tech</th><th>Scheduled</th><th>Status</th><th></th></tr>";
            while ($row = $recent->fetch_assoc()) {
                echo "<tr>";
                echo "<td><strong>" . htmlspecialchars($row['site_code']) . "</strong>"
                   . " <span style='color:#888;font-size:12px;'>" . htmlspecialchars($row['site_name']) . "</span></td>";
                echo "<td>" . htmlspecialchars($row['visit_type']) . "</td>";
                echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
                echo "<td>" . fmt_date($row['scheduled_date']) . "</td>";
                echo "<td>" . status_badge($row['status']) . "</td>";
                echo "<td><a href='visit_detail.php?visit_id=" . (int)$row['visit_id'] . "'>View</a></td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
            echo "<p style='font-size:13px; color:#888; margin-top:6px;'>Showing up to 10 most recent. "
               . "<a href='view_visits.php'>View all visits &rarr;</a></p>";
        }
        ?>

        <hr class='section-divider'>

        <h2 style='font-size:16px; color:#1a3a5c; margin:20px 0 12px;'>Quick Links</h2>
        <p style='display:flex; gap:10px; flex-wrap:wrap;'>
            <a href='view_sites.php'    class='btn btn-secondary'>View Sites</a>
            <a href='view_visits.php'   class='btn btn-secondary'>All Visits</a>
            <a href='view_employees.php' class='btn btn-secondary'>Employees</a>
            <a href='view_vehicles.php' class='btn btn-secondary'>Vehicles</a>
            <a href='timesheets.php'    class='btn btn-secondary'>Timesheets</a>
            <a href='add_site.php'      class='btn btn-secondary'>Add Site</a>
        </p>

<?php include 'incl/footer.php'; ?>
