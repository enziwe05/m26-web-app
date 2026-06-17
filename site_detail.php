<?php
require_once 'incl/dbconn.php';
require_staff();

$site_id = (int)($_GET['site_id'] ?? $_POST['site_id'] ?? 0);
if (!$site_id) {
    header('Location: view_sites.php');
    exit;
}

// Admin can (re)assign which operator/client owns this site (fixes any mistag)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_operator']) && is_admin()) {
    csrf_check();
    $op = ($_POST['operator'] !== '' && array_key_exists($_POST['operator'], client_companies()))
          ? $_POST['operator'] : null;
    $u = $conn->prepare("UPDATE sites SET operator = ? WHERE site_id = ?");
    $u->bind_param('si', $op, $site_id);
    $u->execute();
    $u->close();
    flash('Operator updated.');
    header('Location: site_detail.php?site_id=' . $site_id);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM sites WHERE site_id = ?");
$stmt->bind_param('i', $site_id);
$stmt->execute();
$site = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$site) {
    error_page('Site not found', 'That site may have been removed or the link is wrong.', 'view_sites.php', 'Back to sites');
}

// Visit history for this site
$stmt = $conn->prepare("
    SELECT v.visit_id, v.visit_type, v.status, v.scheduled_date, v.completed_at,
           u.first_name, u.last_name
    FROM visits v
    JOIN users u ON u.user_id = v.assigned_to_user_id
    WHERE v.site_id = ?
    ORDER BY v.scheduled_date DESC
");
$stmt->bind_param('i', $site_id);
$stmt->execute();
$visits = $stmt->get_result();
$stmt->close();

// Documents
$stmt = $conn->prepare("
    SELECT d.document_id, d.doc_name, d.doc_filename, d.uploaded_at,
           u.first_name, u.last_name
    FROM site_documents d
    JOIN users u ON u.user_id = d.uploaded_by_user_id
    WHERE d.site_id = ?
    ORDER BY d.uploaded_at DESC
");
$stmt->bind_param('i', $site_id);
$stmt->execute();
$documents = $stmt->get_result();
$stmt->close();

// Antenna / cell configuration (reference data from the LTE export)
$stmt = $conn->prepare("
    SELECT cell_name, sector, azimuth, mech_tilt, e_tilt, antenna_height, tower_height, frequency_band, cell_status
    FROM site_cells
    WHERE site_id = ?
    ORDER BY CAST(sector AS UNSIGNED), frequency_band
");
$stmt->bind_param('i', $site_id);
$stmt->execute();
$cells = $stmt->get_result();
$stmt->close();

$page_title = 'M26 | ' . $site['site_code'];
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1><?php echo htmlspecialchars($site['site_name']); ?></h1>
            <a href='create_visit.php?site_id=<?php echo $site_id; ?>' class='btn btn-primary'>+ Create Visit</a>
        </div>

        <div class='card'>
            <p><strong>Region:</strong> <?php echo htmlspecialchars($site['region'] ?? '—'); ?></p>
            <p style='display:flex; align-items:center; gap:8px; flex-wrap:wrap;'>
                <strong>Operator / Client:</strong>
                <?php if (is_admin()): ?>
                <form method='POST' action='site_detail.php' style='margin:0; display:inline-flex; gap:6px;'>
                    <?php echo csrf_field(); ?>
                    <input type='hidden' name='site_id' value='<?php echo $site_id; ?>'>
                    <input type='hidden' name='set_operator' value='1'>
                    <select name='operator' onchange='this.form.submit()' style='width:auto; padding:4px 8px;'>
                        <option value=''>— None —</option>
                        <?php foreach (client_companies() as $key => $def): ?>
                            <option value='<?php echo htmlspecialchars($key); ?>'<?php echo ($site['operator'] ?? '') === $key ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($def['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php else: ?>
                    <?php echo htmlspecialchars($site['operator'] ? client_company_label($site['operator']) : '—'); ?>
                <?php endif; ?>
            </p>
            <p><strong>Location:</strong> <?php echo htmlspecialchars($site['location'] ?? '—'); ?></p>
            <?php if ($site['latitude'] != ''): ?>
            <p><strong>Coordinates:</strong> <?php echo $site['latitude'] . ', ' . $site['longitude']; ?></p>
            <?php endif; ?>
            <?php if ($site['notes'] != ''): ?>
            <p><strong>Notes:</strong> <?php echo htmlspecialchars($site['notes']); ?></p>
            <?php endif; ?>
        </div>

        <div class='section-heading'>Documents</div>
        <p><a href='upload_document.php?site_id=<?php echo $site_id; ?>' class='btn btn-secondary'>+ Upload Document</a></p>

        <?php
        if ($documents->num_rows == 0) {
            echo "<p>No documents uploaded for this site.</p>";
        } else {
            echo "<div class='table-scroll'>";
            echo "<table class='data-table'>";
            echo "<tr><th>Name</th><th>Uploaded</th><th>By</th><th></th></tr>";
            while ($doc = $documents->fetch_assoc()) {
                $ext = strtolower(pathinfo($doc['doc_filename'], PATHINFO_EXTENSION));
                echo "<tr>";
                echo "<td><a href='uploads/" . htmlspecialchars($doc['doc_filename']) . "' target='_blank'>" . htmlspecialchars($doc['doc_name']) . "</a></td>";
                echo "<td>" . fmt_datetime($doc['uploaded_at']) . "</td>";
                echo "<td>" . htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']) . "</td>";
                echo "<td><a href='delete_document.php?document_id=" . $doc['document_id'] . "&site_id=$site_id' onclick='return confirm(\"Delete this document?\")'>Delete</a></td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        ?>

        <div class='section-heading'>Visit History</div>

        <?php
        if ($visits->num_rows == 0) {
            echo "<p>No visits recorded for this site.</p>";
        } else {
            echo "<div class='table-scroll'>";
            echo "<table class='data-table'>";
            echo "<tr><th>Type</th><th>Tech</th><th>Scheduled</th><th>Status</th><th>Completed</th><th></th></tr>";
            while ($row = $visits->fetch_assoc()) {
                $completed = fmt_datetime($row['completed_at']);
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['visit_type']) . "</td>";
                echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
                echo "<td>" . fmt_date($row['scheduled_date']) . "</td>";
                echo "<td>" . status_badge($row['status']) . "</td>";
                echo "<td>" . $completed . "</td>";
                echo "<td><a href='visit_detail.php?visit_id=" . $row['visit_id'] . "'>View</a></td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        ?>

        <?php if ($cells->num_rows > 0): ?>
        <hr class='section-divider'>
        <div class='section-heading'>Antenna Configuration</div>
        <div class='table-scroll'>
            <table class='data-table'>
                <tr><th>Sector</th><th>Cell</th><th>Band</th><th>Azimuth</th><th>M-Tilt</th><th>E-Tilt</th><th>Ant. Height</th><th>Status</th></tr>
                <?php while ($cl = $cells->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($cl['sector']); ?></td>
                    <td><?php echo htmlspecialchars($cl['cell_name']); ?></td>
                    <td><?php echo htmlspecialchars($cl['frequency_band']); ?></td>
                    <td><?php echo htmlspecialchars($cl['azimuth']); ?><?php echo $cl['azimuth'] !== '' ? '&deg;' : ''; ?></td>
                    <td><?php echo htmlspecialchars($cl['mech_tilt']); ?></td>
                    <td><?php echo htmlspecialchars($cl['e_tilt']); ?></td>
                    <td><?php echo htmlspecialchars($cl['antenna_height']); ?><?php echo $cl['antenna_height'] !== '' ? 'm' : ''; ?></td>
                    <td><?php echo htmlspecialchars($cl['cell_status']); ?></td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>
        <?php endif; ?>

        <br>
        <p><a href='view_sites.php'>&larr; Back to Sites</a></p>

<?php include 'incl/footer.php'; ?>
