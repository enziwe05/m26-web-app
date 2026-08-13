<?php
require_once 'incl/dbconn.php';
require_staff();

$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_tech   = isset($_GET['tech_id']) ? (int)$_GET['tech_id'] : 0;
$filter_q      = trim($_GET['q'] ?? '');

$techs = $conn->query("SELECT user_id, first_name, last_name FROM users WHERE role = 'employee' AND status = 'active' ORDER BY first_name");

// Build WHERE with optional filters
$where  = [];
$params = [];
$types  = '';

if ($filter_status !== '') {
    $where[]  = "v.status = ?";
    $params[] = $filter_status;
    $types   .= 's';
}
if ($filter_tech > 0) {
    $where[]  = "v.assigned_to_user_id = ?";
    $params[] = $filter_tech;
    $types   .= 'i';
}
if ($filter_q !== '') {
    $where[]  = "(s.site_code LIKE ? OR s.site_name LIKE ?)";
    $like      = '%' . $filter_q . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

$where_clause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));

$count_sql = "SELECT COUNT(*) AS n FROM visits v JOIN sites s ON s.site_id=v.site_id $where_clause";
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
    SELECT v.visit_id, s.site_code, s.site_name, v.visit_type, v.status,
           v.scheduled_date, v.completed_at,
           v.created_by_user_id, v.assigned_to_user_id,
           u.first_name, u.last_name
    FROM visits v
    JOIN sites s ON s.site_id = v.site_id
    JOIN users u ON u.user_id = v.assigned_to_user_id
    $where_clause
    ORDER BY v.scheduled_date DESC
    LIMIT ? OFFSET ?
";
$page_params = array_merge($params, [$per_page, $offset]);
$stmt = $conn->prepare($sql);
$stmt->bind_param($types . 'ii', ...$page_params);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

// Build a page link preserving all current filters
function visits_page_link(int $n, string $status, int $tech, string $q): string {
    $parts = array_filter(
        ['status' => $status, 'tech_id' => $tech ?: '', 'q' => $q, 'page' => $n],
        fn($v) => $v !== '' && $v !== null
    );
    return 'view_visits.php?' . http_build_query($parts);
}

$page_title = 'M26 | All Visits';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>All Visits</h1>
            <a href='create_visit.php' class='btn btn-primary'>+ Create Visit</a>
        </div>
        <p class='page-intro'>Maintenance visits across all sites. Filter by status, technician, or site name — then open a visit for its full report.</p>

        <form method='GET' action='view_visits.php' class='filter-bar'>
            <input type='text' name='q'
                   value='<?php echo htmlspecialchars($filter_q); ?>'
                   placeholder='Search site code or name&hellip;'
                   style='min-width:180px;'>
            <select name='status'>
                <option value=''>All Statuses</option>
                <option value='assigned'    <?php echo $filter_status === 'assigned'    ? 'selected' : ''; ?>>Assigned</option>
                <option value='in_progress' <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                <option value='completed'   <?php echo $filter_status === 'completed'   ? 'selected' : ''; ?>>Completed</option>
            </select>
            <select name='tech_id'>
                <option value=''>All Technicians</option>
                <?php
                while ($t = $techs->fetch_assoc()) {
                    $sel = ($t['user_id'] == $filter_tech) ? 'selected' : '';
                    echo "<option value='" . (int)$t['user_id'] . "' $sel>"
                       . htmlspecialchars($t['first_name'] . ' ' . $t['last_name'])
                       . "</option>";
                }
                ?>
            </select>
            <button type='submit' class='btn btn-secondary'>Filter</button>
            <a href='view_visits.php' class='btn btn-secondary'>Clear</a>
        </form>

        <?php
        if ($result->num_rows === 0) {
            echo empty_state(
                'No visits found',
                'Try clearing the filters above, or schedule a new site visit.',
                'create_visit.php',
                '+ Create Visit'
            );
        } else {
            echo "<p style='font-size:13px; color:#888; margin-bottom:10px;'>Showing "
               . ($offset + 1) . "–" . ($offset + $result->num_rows)
               . " of $total visits</p>";
            echo "<div class='table-scroll'>";
            echo "<table class='data-table'>";
            echo "<tr><th>Site</th><th>Type</th><th>Tech</th><th>Scheduled</th><th>Status</th><th></th></tr>";
            while ($row = $result->fetch_assoc()) {
                $self_selected = (int)$row['created_by_user_id'] === (int)$row['assigned_to_user_id'];
                $origin = $self_selected ? 'Self-selected' : 'Office-assigned';
                echo "<tr>";
                echo "<td><div class='cell-name'>" . htmlspecialchars($row['site_code']) . "</div>"
                   . "<div class='cell-sub'>" . htmlspecialchars($row['site_name']) . "</div></td>";
                echo "<td>" . htmlspecialchars($row['visit_type']) . "</td>";
                echo "<td><div class='cell-name'>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</div>"
                   . "<div class='cell-sub'>" . $origin . "</div></td>";
                echo "<td>" . fmt_date($row['scheduled_date']) . "</td>";
                echo "<td>" . status_badge($row['status']) . "</td>";
                echo "<td><a href='visit_detail.php?visit_id=" . (int)$row['visit_id'] . "'>View</a></td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        ?>

        <?php if ($total_pages > 1): ?>
        <div class='pager'>
            <?php if ($page > 1): ?>
                <a class='btn btn-secondary'
                   href='<?php echo htmlspecialchars(visits_page_link($page - 1, $filter_status, $filter_tech, $filter_q)); ?>'>
                    &larr; Prev
                </a>
            <?php endif; ?>
            <span class='pager-info'>Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
            <?php if ($page < $total_pages): ?>
                <a class='btn btn-secondary'
                   href='<?php echo htmlspecialchars(visits_page_link($page + 1, $filter_status, $filter_tech, $filter_q)); ?>'>
                    Next &rarr;
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
