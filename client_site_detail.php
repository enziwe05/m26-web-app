<?php
require_once 'incl/dbconn.php';
require_client();

$client = current_client($conn);
if (!$client) {
    header('Location: client_dashboard.php');
    exit;
}
$site_id = (int)($_GET['site_id'] ?? 0);

// Scope check: the site must belong to this client's company
$f      = client_notes_filter($client['match_keyword'], 'notes');
$params = array_merge([$site_id], $f['params']);
$types  = 'i' . str_repeat('s', count($f['params']));
$stmt = $conn->prepare("SELECT * FROM sites WHERE site_id = ? AND " . $f['cond']);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$site = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$site) {
    $page_title = 'M26 | Site';
    include 'incl/header.php';
    echo "<div class='alert alert-error'>Site not found, or it isn't linked to your account.</div>";
    echo "<p><a href='client_dashboard.php'>&larr; Back to my sites</a></p>";
    include 'incl/footer.php';
    exit;
}

// Visit history for this site (no internal staff identities exposed)
$stmt = $conn->prepare("
    SELECT v.visit_id, v.visit_type, v.status, v.scheduled_date, v.completed_at,
           (SELECT COUNT(*) FROM maintenance_forms WHERE visit_id = v.visit_id AND is_submitted = 1) AS has_report
    FROM visits v
    WHERE v.site_id = ?
    ORDER BY v.scheduled_date DESC
");
$stmt->bind_param('i', $site_id);
$stmt->execute();
$visits = $stmt->get_result();
$stmt->close();

$page_title = 'M26 | ' . $site['site_code'];
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1><?php echo htmlspecialchars($site['site_name']); ?></h1>
            <a href='client_dashboard.php' class='btn btn-secondary'>&larr; My Sites</a>
        </div>
        <p class='page-intro'>Site details and the maintenance visits M26 has carried out here.</p>

        <div class='card'>
            <p><strong>Site Code:</strong> <?php echo htmlspecialchars($site['site_code']); ?></p>
            <p><strong>Region:</strong> <?php echo htmlspecialchars($site['region'] ?? '—'); ?></p>
            <p><strong>Location:</strong> <?php echo htmlspecialchars($site['location'] ?? '—'); ?></p>
            <?php if ($site['latitude'] != ''): ?>
            <p><strong>Coordinates:</strong> <?php echo htmlspecialchars($site['latitude'] . ', ' . $site['longitude']); ?></p>
            <?php endif; ?>
        </div>

        <div class='section-heading'>Maintenance &amp; Visit History</div>
        <?php if ($visits->num_rows == 0): ?>
            <p>No visits recorded for this site yet.</p>
        <?php else: ?>
            <div class='table-scroll'><table class='data-table'>
                <tr><th>Type</th><th>Scheduled</th><th>Status</th><th>Completed</th><th></th></tr>
                <?php while ($v = $visits->fetch_assoc()):
                    $badge = $v['status'] == 'in_progress' ? 'in-progress' : $v['status'];
                    $label = str_replace('_', ' ', $v['status']);
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($v['visit_type']); ?></td>
                    <td><?php echo fmt_date($v['scheduled_date']); ?></td>
                    <td><span class='badge badge-<?php echo $badge; ?>'><?php echo $label; ?></span></td>
                    <td><?php echo $v['completed_at'] ? fmt_datetime($v['completed_at']) : '—'; ?></td>
                    <td>
                        <?php if ($v['has_report']): ?>
                            <a href='client_visit_detail.php?visit_id=<?php echo $v['visit_id']; ?>'>View report</a>
                        <?php else: ?>
                            <span style='color:#bbb;'>—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </table></div>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
