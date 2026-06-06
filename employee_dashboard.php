<?php
require_once 'incl/dbconn.php';
require_employee();

$user_id = current_user_id();

$stmt = $conn->prepare("
    SELECT v.visit_id, s.site_code, s.site_name, v.visit_type, v.status, v.scheduled_date,
           (SELECT COUNT(*) FROM visit_items WHERE visit_id = v.visit_id) AS total_items,
           (SELECT COUNT(*) FROM visit_items WHERE visit_id = v.visit_id AND is_done = 1) AS done_items
    FROM visits v
    JOIN sites s ON s.site_id = v.site_id
    WHERE v.assigned_to_user_id = ?
    ORDER BY FIELD(v.status, 'in_progress', 'assigned', 'completed'), v.scheduled_date ASC
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

$active    = [];
$completed = [];
while ($row = $result->fetch_assoc()) {
    if ($row['status'] === 'completed') {
        $completed[] = $row;
    } else {
        $active[] = $row;
    }
}

function render_table(array $rows): void {
    echo "<table class='data-table'>";
    echo "<tr><th>Site</th><th>Type</th><th>Scheduled</th><th>Progress</th><th>Status</th><th></th></tr>";
    foreach ($rows as $row) {
        $badge    = $row['status'] === 'in_progress' ? 'in-progress' : $row['status'];
        $label    = str_replace('_', ' ', $row['status']);
        $progress = $row['done_items'] . '/' . $row['total_items'];
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['site_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['visit_type']) . "</td>";
        echo "<td>" . fmt_date($row['scheduled_date']) . "</td>";
        echo "<td>" . $progress . "</td>";
        echo "<td><span class='badge badge-$badge'>$label</span></td>";
        echo "<td><a href='employee_visit.php?visit_id=" . $row['visit_id'] . "'>Open</a></td>";
        echo "</tr>";
    }
    echo "</table>";
}
$page_title = 'M26 | My Visits';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>My Visits</h1>
            <span>Hi, <?php echo htmlspecialchars(current_user_name()); ?></span>
        </div>

        <?php if (empty($active) && empty($completed)): ?>
            <p>You have no visits assigned to you.</p>
        <?php else: ?>

            <?php if (!empty($active)): ?>
                <div class='section-heading'>Active Visits</div>
                <div class='card card-table'>
                    <?php render_table($active); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($completed)): ?>
                <div class='section-heading'>Completed Visits</div>
                <div class='card card-table'>
                    <?php render_table($completed); ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
