<?php
/*
 * Technician self-service: browse sites and start a visit without waiting for
 * the office to assign one. Requires the tech to be clocked in first (matches
 * the on-site / anti-fraud model). Starting a visit posts to start_visit.php,
 * which creates a self-assigned visit (or resumes an existing open one).
 */
require_once 'incl/dbconn.php';
require_employee();

$user_id = current_user_id();

// ── Clock-in gate ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT entry_id FROM time_entries WHERE user_id = ? AND status = 'open' LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$is_clocked_in = (bool) $stmt->get_result()->num_rows;
$stmt->close();

$page_title = 'M26 | Start a Visit';

if (!$is_clocked_in) {
    include 'incl/header.php';
    ?>
        <div class='page-heading'><h1>Start a Visit</h1></div>
        <div class='card' style='text-align:center; padding:32px 24px;'>
            <p style='font-size:15px; margin-bottom:6px;'><strong>Clock in first</strong></p>
            <p style='color:#666; font-size:14px; margin-bottom:18px;'>
                You need to be clocked in before you can pick a site and start a visit.
                Clock in when you arrive on site, then come back here.
            </p>
            <a href='clock.php' class='btn btn-primary'>Go to Clock In &rarr;</a>
        </div>
    <?php
    include 'incl/footer.php';
    exit;
}

// ── Search + pagination ───────────────────────────────────────────────────────
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where  = '';
$params = [];
$types  = '';
if ($search !== '') {
    $like  = '%' . $search . '%';
    $where = "WHERE (s.site_code LIKE ? OR s.site_name LIKE ? OR s.location LIKE ?)";
    array_push($params, $like, $like, $like);
    $types = 'sss';
}

$per_page = 25;
$page     = max(1, (int) ($_GET['page'] ?? 1));

$count_sql = "SELECT COUNT(*) AS n FROM sites s $where";
if ($params) {
    $cstmt = $conn->prepare($count_sql);
    $cstmt->bind_param($types, ...$params);
    $cstmt->execute();
    $total = (int) $cstmt->get_result()->fetch_assoc()['n'];
    $cstmt->close();
} else {
    $total = (int) $conn->query($count_sql)->fetch_assoc()['n'];
}
$total_pages = max(1, (int) ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$sql = "SELECT s.site_id, s.site_code, s.site_name, s.location
        FROM sites s $where ORDER BY s.site_code LIMIT ? OFFSET ?";
$page_params = array_merge($params, [$per_page, $offset]);
$stmt = $conn->prepare($sql);
$stmt->bind_param($types . 'ii', ...$page_params);
$stmt->execute();
$sites = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── This tech's open visits, keyed by site_id (so we can show "Resume") ───────
$open_by_site = [];
$stmt = $conn->prepare("SELECT site_id, visit_id FROM visits
                        WHERE assigned_to_user_id = ? AND status <> 'completed'");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $open_by_site[(int) $row['site_id']] = (int) $row['visit_id'];
}
$stmt->close();

function pick_page_link(int $n, string $search): string {
    $q = array_filter(['search' => $search, 'page' => $n], fn($v) => $v !== '' && $v !== null);
    return 'pick_site.php?' . http_build_query($q);
}

include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Start a Visit</h1>
            <a href='employee_dashboard.php' class='btn btn-secondary'>&larr; My Visits</a>
        </div>
        <p class='page-intro'>
            Pick the site you're working on and tap <strong>Start visit</strong> to open its
            maintenance form. You don't need to wait for the office to assign it.
        </p>

        <form method='GET' action='pick_site.php' class='filter-bar'>
            <input type='text' name='search' placeholder='Search site code, name or location...'
                   value='<?php echo htmlspecialchars($search); ?>'>
            <button type='submit' class='btn btn-secondary'>Search</button>
            <?php if ($search !== ''): ?>
                <a href='pick_site.php' class='btn btn-secondary'>Clear</a>
            <?php endif; ?>
        </form>

        <?php if (empty($sites)): ?>
            <?php echo empty_state('No sites found', 'No sites match your search. Try a different code or name.'); ?>
        <?php else: ?>
        <div class='table-scroll'>
            <table class='data-table'>
                <tr><th>Code</th><th>Name</th><th>Location</th><th></th></tr>
                <?php foreach ($sites as $s): $sid = (int) $s['site_id']; ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($s['site_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars($s['site_name']); ?></td>
                    <td><?php echo htmlspecialchars($s['location'] ?? ''); ?></td>
                    <td>
                        <?php if (isset($open_by_site[$sid])): ?>
                            <a href='employee_visit.php?visit_id=<?php echo $open_by_site[$sid]; ?>'
                               class='btn btn-secondary' style='font-size:13px; padding:6px 14px;'>Resume visit &rarr;</a>
                        <?php else: ?>
                            <form method='POST' action='start_visit.php' style='margin:0;'>
                                <?php echo csrf_field(); ?>
                                <input type='hidden' name='site_id' value='<?php echo $sid; ?>'>
                                <input type='hidden' name='visit_type' value='Maintenance'>
                                <input type='hidden' name='maintenance_type' value='active'>
                                <button type='submit' class='btn btn-primary'
                                        style='font-size:13px; padding:6px 14px;'>Start visit</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class='pager'>
            <?php if ($page > 1): ?>
                <a class='btn btn-secondary' href='<?php echo htmlspecialchars(pick_page_link($page - 1, $search)); ?>'>&larr; Prev</a>
            <?php endif; ?>
            <span class='pager-info'>Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
            <?php if ($page < $total_pages): ?>
                <a class='btn btn-secondary' href='<?php echo htmlspecialchars(pick_page_link($page + 1, $search)); ?>'>Next &rarr;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
