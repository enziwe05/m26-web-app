<?php
require_once 'incl/dbconn.php';
require_staff();

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$region = isset($_GET['region']) ? trim($_GET['region']) : '';

// Build optional search + region filters
$where  = [];
$params = [];
$types  = '';
if ($search != '') {
    $like = '%' . $search . '%';
    $where[] = "(s.site_code LIKE ? OR s.site_name LIKE ? OR s.location LIKE ?)";
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
if ($region != '') {
    $where[]  = "s.region = ?";
    $params[] = $region;
    $types   .= 's';
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));

$count_sql = "SELECT COUNT(*) AS n FROM sites s $where_sql";
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

$sql = "
    SELECT s.site_id, s.site_code, s.site_name, s.location, s.region,
           COUNT(v.visit_id) AS total_visits
    FROM sites s
    LEFT JOIN visits v ON v.site_id = s.site_id
    $where_sql
    GROUP BY s.site_id
    ORDER BY s.site_code
    LIMIT ? OFFSET ?
";
$page_params = array_merge($params, [$per_page, $offset]);
$stmt = $conn->prepare($sql);
$stmt->bind_param($types . 'ii', ...$page_params);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

$region_options = ['Hhohho', 'Lubombo', 'Manzini', 'Shiselweni'];

// Build a page link that preserves the current filters
function sites_page_link(int $n, string $search, string $region): string {
    $q = array_filter(['search' => $search, 'region' => $region, 'page' => $n],
                      fn($v) => $v !== '' && $v !== null);
    return 'view_sites.php?' . http_build_query($q);
}

$page_title = 'M26 | Sites';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Sites</h1>
            <a href='add_site.php' class='btn btn-primary'>+ Add Site</a>
        </div>
        <p class='page-intro'>All M26-maintained sites. Open a site for its visit history, documents and operator.</p>

        <form method='GET' action='view_sites.php' class='filter-bar'>
            <input type='text' name='search' placeholder='Search site code, name or location...'
                   value='<?php echo htmlspecialchars($search); ?>'>
            <select name='region'>
                <option value=''>All Regions</option>
                <?php foreach ($region_options as $r): ?>
                    <option value='<?php echo $r; ?>'<?php echo $region === $r ? ' selected' : ''; ?>><?php echo $r; ?></option>
                <?php endforeach; ?>
            </select>
            <button type='submit' class='btn btn-secondary'>Filter</button>
            <?php if ($search != '' || $region != ''): ?>
                <a href='view_sites.php' class='btn btn-secondary'>Clear</a>
            <?php endif; ?>
        </form>

        <?php
        if ($result->num_rows == 0) {
            echo empty_state('No sites found', 'No sites match your search or filters.', 'add_site.php', '+ Add Site');
        } else {
            $showing_from = $offset + 1;
            $showing_to   = $offset + $result->num_rows;
            echo "<p style='font-size:13px; color:#888; margin-bottom:10px;'>Showing $showing_from–$showing_to of $total sites</p>";
            echo "<div class='table-scroll'>";
            echo "<table class='data-table'>";
            echo "<tr><th>Code</th><th>Name</th><th>Region</th><th>Location</th><th>Total Visits</th><th></th></tr>";
            while ($row = $result->fetch_assoc()) {
                echo "<tr>";
                echo "<td><strong>" . htmlspecialchars($row['site_code']) . "</strong></td>";
                echo "<td>" . htmlspecialchars($row['site_name']) . "</td>";
                echo "<td>" . htmlspecialchars($row['region'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($row['location'] ?? '') . "</td>";
                echo "<td>" . $row['total_visits'] . "</td>";
                echo "<td><a href='site_detail.php?site_id=" . $row['site_id'] . "'>View</a></td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        ?>

        <?php if ($total_pages > 1): ?>
        <div class='pager'>
            <?php if ($page > 1): ?>
                <a class='btn btn-secondary' href='<?php echo htmlspecialchars(sites_page_link($page - 1, $search, $region)); ?>'>&larr; Prev</a>
            <?php endif; ?>
            <span class='pager-info'>Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
            <?php if ($page < $total_pages): ?>
                <a class='btn btn-secondary' href='<?php echo htmlspecialchars(sites_page_link($page + 1, $search, $region)); ?>'>Next &rarr;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
