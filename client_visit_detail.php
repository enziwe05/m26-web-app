<?php
require_once 'incl/dbconn.php';
require_client();

$client = current_client($conn);
if (!$client) {
    header('Location: client_dashboard.php');
    exit;
}
$visit_id = (int)($_GET['visit_id'] ?? 0);

// Load the visit, but ONLY if its site belongs to this client's company
$f      = client_notes_filter($client['match_keyword'], 's.notes');
$params = array_merge([$visit_id], $f['params']);
$types  = 'i' . str_repeat('s', count($f['params']));
$stmt = $conn->prepare("
    SELECT v.visit_id, v.visit_type, v.status, v.scheduled_date, v.completed_at,
           v.maintenance_type, s.site_id, s.site_code, s.site_name
    FROM visits v
    JOIN sites s ON s.site_id = v.site_id
    WHERE v.visit_id = ? AND " . $f['cond'] . "
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$visit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$visit) {
    $page_title = 'M26 | Report';
    include 'incl/header.php';
    echo "<div class='alert alert-error'>Report not found, or it isn't linked to your account.</div>";
    echo "<p><a href='client_dashboard.php'>&larr; Back to my sites</a></p>";
    include 'incl/footer.php';
    exit;
}

// Maintenance form (read-only; only show submitted reports to clients)
$stmt = $conn->prepare("SELECT * FROM maintenance_forms WHERE visit_id = ? AND is_submitted = 1");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$mform = $stmt->get_result()->fetch_assoc();
$stmt->close();

$form_sections = require __DIR__ . '/incl/form_sections.php';
$decoded = [];
if ($mform) {
    foreach (array_keys($form_sections) as $sec) {
        $col           = $sec . '_json';
        $decoded[$sec] = ($mform[$col] ?? '') ? json_decode($mform[$col], true) : [];
    }
}

// Flat field-key → label map, and the list of faulty items (the headline info)
$field_labels = [];
foreach ($form_sections as $sec) {
    foreach ($sec['fields'] as $fk => $fl) $field_labels[$fk] = $fl;
}
$faulty = [];
foreach ($decoded as $sec_key => $items) {
    if (!is_array($items)) continue;
    foreach ($items as $fk => $vals) {
        if (($vals['status'] ?? '') === 'Faulty') {
            $faulty[] = [
                'label'   => $field_labels[$fk] ?? ucwords(str_replace('_', ' ', $fk)),
                'remarks' => trim($vals['remarks'] ?? ''),
            ];
        }
    }
}

// Site photos attached to this visit
$stmt = $conn->prepare("SELECT photo_filename, caption FROM visit_photos WHERE visit_id = ? ORDER BY uploaded_at");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$photos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$page_title = 'M26 | Report — ' . $visit['site_code'];
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Maintenance Report</h1>
            <a href='client_site_detail.php?site_id=<?php echo $visit['site_id']; ?>' class='btn btn-secondary'>&larr; Site</a>
        </div>

        <div class='card' style='font-size:13px;'>
            <p><strong>Site:</strong> <?php echo htmlspecialchars($visit['site_code'] . ' — ' . $visit['site_name']); ?></p>
            <p><strong>Visit Type:</strong> <?php echo htmlspecialchars($visit['visit_type']); ?></p>
            <p><strong>Maintenance Type:</strong> <?php echo ucfirst(htmlspecialchars($visit['maintenance_type'] ?? 'active')); ?></p>
            <p><strong>Scheduled:</strong> <?php echo fmt_date($visit['scheduled_date']); ?></p>
            <?php if ($visit['completed_at']): ?>
            <p><strong>Completed:</strong> <?php echo fmt_datetime($visit['completed_at']); ?></p>
            <?php endif; ?>
        </div>

        <?php if (!$mform): ?>
            <div class='info-banner'>The detailed report for this visit hasn't been published yet.</div>
        <?php else: ?>

            <!-- Headline result -->
            <?php $fault_n = count($faulty); ?>
            <?php if ($fault_n === 0): ?>
            <div class='alert alert-success'>
                &#10003; <strong>No issues found.</strong> All inspected items were OK.
            </div>
            <?php else: ?>
            <div class='alert alert-error'>
                &#9888; <strong><?php echo $fault_n; ?> issue<?php echo $fault_n === 1 ? '' : 's'; ?> found</strong>
                during this maintenance visit. See details below.
            </div>
            <?php endif; ?>

            <p style='font-size:12px; color:#888; margin:-8px 0 18px;'>
                Report submitted <?php echo htmlspecialchars(fmt_datetime($mform['submitted_at'])); ?>.
            </p>

            <!-- Issues (only the faulty items — the part clients care about) -->
            <?php if ($fault_n > 0): ?>
            <div class='card'>
                <h2 style='font-size:15px; color:#1a3a5c; margin-bottom:12px;'>Issues Found</h2>
                <div class='table-scroll'><table class='data-table'>
                    <tr><th>Item</th><th>Details</th></tr>
                    <?php foreach ($faulty as $ft): ?>
                    <tr>
                        <td class='status-faulty'><?php echo htmlspecialchars($ft['label']); ?></td>
                        <td><?php echo htmlspecialchars($ft['remarks'] !== '' ? $ft['remarks'] : '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table></div>
            </div>
            <?php endif; ?>

            <!-- Comments from the technician -->
            <?php if (!empty($mform['general_comments'])): ?>
            <div class='card'>
                <h2 style='font-size:15px; color:#1a3a5c; margin-bottom:8px;'>Notes</h2>
                <p><?php echo nl2br(htmlspecialchars($mform['general_comments'])); ?></p>
            </div>
            <?php endif; ?>

            <!-- Site photos -->
            <?php if (!empty($photos)): ?>
            <div class='card'>
                <h2 style='font-size:15px; color:#1a3a5c; margin-bottom:10px;'>Site Photos</h2>
                <div class='photo-grid'>
                    <?php foreach ($photos as $ph): ?>
                    <div class='photo-thumb'>
                        <img src='uploads/<?php echo htmlspecialchars($ph['photo_filename']); ?>'
                             alt='<?php echo htmlspecialchars($ph['caption'] ?? ''); ?>'>
                        <?php if (!empty($ph['caption'])): ?>
                        <div class='pt-cap'><?php echo htmlspecialchars($ph['caption']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Full checklist tucked away so the page stays clean -->
            <details class='detail-toggle'>
                <summary>View full inspection checklist</summary>
                <?php foreach ($form_sections as $sec_key => $sec): ?>
                <div class='mf-section card' style='margin-top:14px;'>
                    <h2><?php echo htmlspecialchars($sec['label']); ?></h2>
                    <table class='mf-table'>
                        <tr><th style='width:260px;'>Item</th><th style='width:100px;'>Status</th><th>Remarks</th></tr>
                        <?php foreach ($sec['fields'] as $fk => $fl):
                            $status  = $decoded[$sec_key][$fk]['status']  ?? 'N/A';
                            $remarks = $decoded[$sec_key][$fk]['remarks'] ?? '';
                            $sc = $status === 'OK' ? 'status-ok' : ($status === 'Faulty' ? 'status-faulty' : 'status-na');
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($fl); ?></td>
                            <td class='<?php echo $sc; ?>'><?php echo htmlspecialchars($status); ?></td>
                            <td><?php echo htmlspecialchars($remarks); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <?php endforeach; ?>
            </details>

        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
