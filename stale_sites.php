<?php
/*
 * Sites Needing a Visit — every site grouped by how recently it was maintained.
 *
 * The cycle (payroll_settings.stale_days, default 120 = 4 months) is the boss's
 * rule: each site should be checked at least this often. Four tabs, worst first:
 *   Never checked · over 2× the cycle · behind the cycle · up to date.
 */
require_once 'incl/dbconn.php';
require_staff();

// ── The maintenance cycle (boss's intent: 4 months) ───────────────────────────
$cycle_days = (int) ($conn->query(
    "SELECT stale_days FROM payroll_settings WHERE id = 1"
)->fetch_assoc()['stale_days'] ?? 120);
if ($cycle_days < 1) $cycle_days = 120;
$cycle_months  = (int) round($cycle_days / 30);        // 4
$urgent_months = (int) round(($cycle_days * 2) / 30);  // 8

// ── Every site + when it was last checked, worst first ────────────────────────
$sql = "
    SELECT s.site_id, s.site_code, s.site_name, s.region,
           lv.last_visit,
           DATEDIFF(CURDATE(), lv.last_visit) AS days_since,
           (SELECT CONCAT(u.first_name, ' ', u.last_name)
              FROM visits v3
              JOIN users u ON u.user_id = v3.assigned_to_user_id
             WHERE v3.site_id = s.site_id AND v3.status = 'completed'
             ORDER BY v3.completed_at DESC
             LIMIT 1) AS tech_name
    FROM sites s
    LEFT JOIN (
        SELECT site_id, MAX(completed_at) AS last_visit
        FROM visits WHERE status = 'completed' GROUP BY site_id
    ) lv ON lv.site_id = s.site_id
    ORDER BY (lv.last_visit IS NULL) DESC, lv.last_visit ASC, s.site_code
";
$rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
$total_sites = count($rows);

// ── Split into the four tabs ──────────────────────────────────────────────────
$never = []; $urgent = []; $overdue = []; $good = [];
foreach ($rows as $r) {
    if     ($r['last_visit'] === null)              $never[]   = $r;
    elseif ((int)$r['days_since'] >= $cycle_days*2) $urgent[]  = $r;
    elseif ((int)$r['days_since'] >= $cycle_days)   $overdue[] = $r;
    else                                            $good[]    = $r;
}
// Up-to-date sites read best most-recently-checked first.
usort($good, fn($a, $b) => (int)$a['days_since'] <=> (int)$b['days_since']);
$behind = count($never) + count($urgent) + count($overdue);

$page_title = 'M26 | Maintenance Status';
include 'incl/header.php';

// Renders one tab's table (or a friendly empty note).
function render_group(array $rows, string $empty_msg): void {
    if (!$rows) { echo "<p style='color:#666; padding:8px 2px;'>$empty_msg</p>"; return; }
    echo "<div class='table-scroll'><table class='data-table'>";
    echo "<tr><th>Site</th><th>Region</th><th>Last Checked</th><th>Last Team</th><th></th></tr>";
    foreach ($rows as $row) {
        $ago = $row['last_visit'] === null ? 'Never' : htmlspecialchars(human_ago((int)$row['days_since']));
        echo "<tr>";
        echo "<td><strong>" . htmlspecialchars($row['site_code']) . "</strong> "
           . "<span style='color:#888; font-size:12px;'>" . htmlspecialchars($row['site_name']) . "</span></td>";
        echo "<td>" . htmlspecialchars($row['region'] ?? '') . "</td>";
        echo "<td>" . $ago . "</td>";
        echo "<td>" . htmlspecialchars($row['tech_name'] ?? '—') . "</td>";
        echo "<td><a href='site_detail.php?site_id=" . (int)$row['site_id'] . "'>Open</a></td>";
        echo "</tr>";
    }
    echo "</table></div>";
}
?>

        <style>
            .tabs { display:flex; gap:8px; flex-wrap:wrap; margin:16px 0 14px; }
            .tab-btn {
                border:1px solid #d9dee3; background:#fff; border-radius:22px;
                padding:8px 16px; font-size:14px; cursor:pointer; color:#445;
                display:inline-flex; align-items:center; gap:7px;
            }
            .tab-btn .dot { width:9px; height:9px; border-radius:50%; }
            .tab-btn.active { background:#1a3a5c; border-color:#1a3a5c; color:#fff; }
            .tab-count { font-weight:700; }
            .tab-pane[hidden] { display:none; }
        </style>

        <div class='page-heading'>
            <h1>Maintenance Status</h1>
        </div>
        <p class='page-intro'>Every site should be maintained at least once every
           <strong><?php echo $cycle_months; ?> months</strong>.
           <strong><?php echo $behind; ?> of <?php echo $total_sites; ?></strong> sites are behind;
           <strong><?php echo count($good); ?></strong> are up to date.</p>

        <div class='tabs' role='tablist'>
            <button class='tab-btn active' data-pane='never'>
                <span class='dot' style='background:#dc3545;'></span>
                Never checked <span class='tab-count'>(<?php echo count($never); ?>)</span>
            </button>
            <button class='tab-btn' data-pane='urgent'>
                <span class='dot' style='background:#dc3545;'></span>
                Over <?php echo $urgent_months; ?> months <span class='tab-count'>(<?php echo count($urgent); ?>)</span>
            </button>
            <button class='tab-btn' data-pane='overdue'>
                <span class='dot' style='background:#e0a800;'></span>
                <?php echo $cycle_months; ?>&ndash;<?php echo $urgent_months; ?> months <span class='tab-count'>(<?php echo count($overdue); ?>)</span>
            </button>
            <button class='tab-btn' data-pane='good'>
                <span class='dot' style='background:#28a745;'></span>
                Up to date <span class='tab-count'>(<?php echo count($good); ?>)</span>
            </button>
        </div>

        <div class='tab-pane' id='pane-never'>
            <?php render_group($never, 'No sites in this group — good.'); ?>
        </div>
        <div class='tab-pane' id='pane-urgent' hidden>
            <?php render_group($urgent, 'No sites more than ' . $urgent_months . ' months overdue.'); ?>
        </div>
        <div class='tab-pane' id='pane-overdue' hidden>
            <?php render_group($overdue, 'No sites in this group.'); ?>
        </div>
        <div class='tab-pane' id='pane-good' hidden>
            <?php render_group($good, 'No sites have been checked within the cycle yet.'); ?>
        </div>

        <script>
        (function () {
            var btns  = document.querySelectorAll('.tab-btn');
            var panes = { never:'pane-never', urgent:'pane-urgent', overdue:'pane-overdue', good:'pane-good' };
            btns.forEach(function (b) {
                b.addEventListener('click', function () {
                    btns.forEach(function (x) { x.classList.remove('active'); });
                    b.classList.add('active');
                    for (var key in panes) {
                        document.getElementById(panes[key]).hidden = (key !== b.dataset.pane);
                    }
                });
            });
        })();
        </script>

<?php include 'incl/footer.php'; ?>
