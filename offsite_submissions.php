<?php
require_once 'incl/dbconn.php';
require_once 'incl/geo.php';
require_staff();

/*
 * Off-site Submissions review page.
 * Lists every maintenance form submitted more than onsite_radius_m metres
 * from the site so supervisors can catch techs who upload forms from home.
 */

// ── Onsite radius from settings ────────────────────────────────────────────────
$r_res = $conn->query("SELECT onsite_radius_m FROM payroll_settings WHERE id=1");
$onsite_radius_m = $r_res ? (int)($r_res->fetch_assoc()['onsite_radius_m'] ?? 200) : 200;

// ── Filters ────────────────────────────────────────────────────────────────────
$filter_month = trim($_GET['month'] ?? '');
$filter_q     = trim($_GET['q'] ?? '');

// Month dropdown — distinct months present in off-site submissions
$month_rows = $conn->query("
    SELECT DISTINCT DATE_FORMAT(mf.submitted_at, '%Y-%m') AS ym,
                    DATE_FORMAT(mf.submitted_at, '%M %Y') AS label
    FROM maintenance_forms mf
    WHERE mf.submit_location_status = 'away'
      AND mf.submitted_at IS NOT NULL
    ORDER BY ym DESC
");

// ── Build WHERE ────────────────────────────────────────────────────────────────
$where  = ["mf.submit_location_status = 'away'"];
$params = [];
$types  = '';

if ($filter_month !== '') {
    $where[]  = "DATE_FORMAT(mf.submitted_at, '%Y-%m') = ?";
    $params[] = $filter_month;
    $types   .= 's';
}
if ($filter_q !== '') {
    $where[]  = "(s.site_code LIKE ? OR s.site_name LIKE ?)";
    $like      = '%' . $filter_q . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}
$where_clause = 'WHERE ' . implode(' AND ', $where);

// ── Pagination ─────────────────────────────────────────────────────────────────
$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));

$count_sql = "
    SELECT COUNT(*) AS n
    FROM maintenance_forms mf
    JOIN visits v ON v.visit_id = mf.visit_id
    JOIN sites  s ON s.site_id  = v.site_id
    JOIN users  u ON u.user_id  = v.assigned_to_user_id
    $where_clause
";

if ($params) {
    $cstmt = $conn->prepare($count_sql);
    $cstmt->bind_param($types, ...$params);
    $cstmt->execute();
    $total = (int)$cstmt->get_result()->fetch_assoc()['n'];
    $cstmt->close();
} else {
    $total = (int)$conn->query($count_sql)->fetch_assoc()['n'];
}

$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

// ── Main query ─────────────────────────────────────────────────────────────────
$sql = "
    SELECT mf.form_id, mf.submitted_at,
           mf.submit_lat, mf.submit_lon,
           mf.submit_accuracy, mf.submit_distance_m,
           mf.visit_id,
           s.site_code, s.site_name,
           u.first_name, u.last_name
    FROM maintenance_forms mf
    JOIN visits v ON v.visit_id = mf.visit_id
    JOIN sites  s ON s.site_id  = v.site_id
    JOIN users  u ON u.user_id  = v.assigned_to_user_id
    $where_clause
    ORDER BY mf.submitted_at DESC
    LIMIT ? OFFSET ?
";

$page_params = array_merge($params, [$per_page, $offset]);
$stmt = $conn->prepare($sql);
$stmt->bind_param($types . 'ii', ...$page_params);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

// Build a page link preserving all current filters
function offsite_page_link(int $n, string $month, string $q): string {
    $parts = array_filter(
        ['month' => $month, 'q' => $q, 'page' => $n],
        fn($v) => $v !== '' && $v !== null
    );
    return 'offsite_submissions.php?' . http_build_query($parts);
}

$page_title = 'M26 | Off-site Submissions';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Off-site Submissions</h1>
        </div>
        <p class='page-intro'>
            These maintenance forms were submitted more than <?php echo (int)$onsite_radius_m; ?> m
            from the site — review for possible off-site uploads.
        </p>

        <form method='GET' action='offsite_submissions.php' class='filter-bar'>
            <input type='text' name='q'
                   value='<?php echo htmlspecialchars($filter_q); ?>'
                   placeholder='Search site code or name&hellip;'
                   style='min-width:180px;'>
            <select name='month'>
                <option value=''>All Months</option>
                <?php
                if ($month_rows) {
                    while ($mr = $month_rows->fetch_assoc()) {
                        $sel = ($mr['ym'] === $filter_month) ? 'selected' : '';
                        echo "<option value='" . htmlspecialchars($mr['ym']) . "' $sel>"
                           . htmlspecialchars($mr['label']) . "</option>";
                    }
                }
                ?>
            </select>
            <button type='submit' class='btn btn-secondary'>Filter</button>
            <a href='offsite_submissions.php' class='btn btn-secondary'>Clear</a>
        </form>

        <?php if ($total === 0): ?>
            <?php echo empty_state('No off-site submissions', 'Nice — every recent form was submitted on site.'); ?>
        <?php else: ?>
            <p style='font-size:13px; color:#888; margin-bottom:10px;'>
                Showing <?php echo ($offset + 1); ?>–<?php echo ($offset + $result->num_rows); ?>
                of <?php echo $total; ?> off-site submission<?php echo $total !== 1 ? 's' : ''; ?>
            </p>
            <div class='table-scroll'>
            <table class='data-table'>
                <tr>
                    <th>Submitted</th>
                    <th>Site</th>
                    <th>Technician</th>
                    <th>Distance from Site</th>
                    <th>GPS Accuracy</th>
                    <th>Map</th>
                    <th>Form</th>
                </tr>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo fmt_datetime($row['submitted_at']); ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars($row['site_code']); ?></strong>
                        <span style='color:#888; font-size:12px;'>
                            <?php echo htmlspecialchars($row['site_name']); ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name'])); ?></td>
                    <td>
                        <strong style='color:#dc3545;'>
                            <?php
                            $dist = $row['submit_distance_m'] !== null
                                ? (int)$row['submit_distance_m']
                                : null;
                            echo htmlspecialchars(format_distance($dist));
                            ?>
                        </strong>
                    </td>
                    <td>
                        <?php
                        $acc = $row['submit_accuracy'] !== null
                            ? (int)round((float)$row['submit_accuracy'])
                            : null;
                        echo $acc !== null ? ('&plusmn;' . $acc . ' m') : '&mdash;';
                        ?>
                    </td>
                    <td>
                        <?php if (!empty($row['submit_lat']) && !empty($row['submit_lon'])): ?>
                        <a href='https://maps.google.com/?q=<?php echo urlencode($row['submit_lat'] . ',' . $row['submit_lon']); ?>'
                           target='_blank' rel='noopener' class='btn-map btn-secondary'>Map</a>
                        <?php else: ?>
                        &mdash;
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href='maintenance_form.php?visit_id=<?php echo (int)$row['visit_id']; ?>'>View form</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class='pager'>
                <?php if ($page > 1): ?>
                    <a class='btn btn-secondary'
                       href='<?php echo htmlspecialchars(offsite_page_link($page - 1, $filter_month, $filter_q)); ?>'>
                        &larr; Prev
                    </a>
                <?php endif; ?>
                <span class='pager-info'>Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                <?php if ($page < $total_pages): ?>
                    <a class='btn btn-secondary'
                       href='<?php echo htmlspecialchars(offsite_page_link($page + 1, $filter_month, $filter_q)); ?>'>
                        Next &rarr;
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
