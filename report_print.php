<?php
/*
 * Printable / Save-as-PDF maintenance report for a single visit.
 * Self-contained page (no sidebar) so it prints cleanly to A4. Use the browser's
 * "Save as PDF" from the Print dialog. Staff only.
 */
require_once 'incl/dbconn.php';
require_staff();

$visit_id = (int)($_GET['visit_id'] ?? 0);

$stmt = $conn->prepare("
    SELECT v.*, s.site_code, s.site_name, s.location, s.region,
           t.first_name AS tech_first, t.last_name AS tech_last
    FROM visits v
    JOIN sites s ON s.site_id = v.site_id
    JOIN users t ON t.user_id = v.assigned_to_user_id
    WHERE v.visit_id = ?
");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$visit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$visit) { error_page('Visit not found', 'That visit may have been deleted or the link is wrong.', 'view_visits.php', 'Back to visits'); }

$stmt = $conn->prepare("SELECT * FROM maintenance_forms WHERE visit_id = ?");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$mform = $stmt->get_result()->fetch_assoc();
$stmt->close();

$form_sections = require __DIR__ . '/incl/form_sections.php';
$decoded = [];
if ($mform) {
    foreach (array_keys($form_sections) as $sec) {
        $col = $sec . '_json';
        $decoded[$sec] = ($mform[$col] ?? '') ? json_decode($mform[$col], true) : [];
    }
}

// Faulty items (for the summary line)
$fault_n = 0;
foreach ($decoded as $items) {
    if (is_array($items)) foreach ($items as $v) if (($v['status'] ?? '') === 'Faulty') $fault_n++;
}

$stmt = $conn->prepare("SELECT photo_filename, caption FROM visit_photos WHERE visit_id = ? ORDER BY uploaded_at");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$photos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function st_class($s) { return $s === 'OK' ? 'ok' : ($s === 'Faulty' ? 'bad' : 'na'); }
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <title>M26 Report — <?php echo htmlspecialchars($visit['site_code']); ?></title>
    <link rel='icon' href='images/m26.png' type='image/png'>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #222; margin: 0; padding: 28px 32px; font-size: 13px; }
        .toolbar { margin-bottom: 18px; }
        .btn { display: inline-block; padding: 9px 18px; border: none; border-radius: 5px; font-size: 14px;
               font-weight: 600; cursor: pointer; background: #1a5ca8; color: #fff; }
        .btn.sec { background: #e8ecf1; color: #333; margin-left: 6px; text-decoration: none; }
        .rep-head { display: flex; align-items: center; gap: 14px; border-bottom: 3px solid #1a3a5c; padding-bottom: 12px; margin-bottom: 16px; }
        .rep-head img { width: 54px; height: 54px; object-fit: contain; }
        .rep-head h1 { font-size: 20px; margin: 0; color: #1a3a5c; }
        .rep-head .sub { font-size: 12px; color: #777; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; margin-bottom: 16px; }
        .meta p { margin: 0; }
        .summary { padding: 10px 14px; border-radius: 5px; font-weight: 600; margin-bottom: 18px; }
        .summary.clean { background: #d4edda; color: #155724; }
        .summary.issues { background: #f8d7da; color: #721c24; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #1a3a5c;
             border-bottom: 1px solid #1a3a5c; padding-bottom: 4px; margin: 20px 0 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 6px; }
        th, td { border: 1px solid #cfd6df; padding: 5px 8px; text-align: left; vertical-align: top; }
        th { background: #f0f4ff; }
        td.ok  { color: #198754; font-weight: 600; }
        td.bad { color: #dc3545; font-weight: 700; }
        td.na  { color: #888; }
        .photos { display: flex; flex-wrap: wrap; gap: 10px; }
        .photos figure { margin: 0; width: 180px; }
        .photos img { width: 180px; height: 130px; object-fit: cover; border: 1px solid #cfd6df; border-radius: 4px; }
        .photos figcaption { font-size: 11px; color: #666; }
        .sign { display: flex; gap: 40px; margin-top: 34px; }
        .sign div { flex: 1; border-top: 1px solid #555; padding-top: 4px; font-size: 11px; color: #555; }
        section { page-break-inside: avoid; }
        @media print { .toolbar { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class='toolbar'>
        <button class='btn' onclick='window.print()'>Save as PDF</button>
        <a class='btn sec' href='maintenance_form.php?visit_id=<?php echo $visit_id; ?>'>Back</a>
    </div>
    <script>
        // Open the "Save as PDF" dialog automatically once images have loaded
        window.addEventListener('load', function () { window.print(); });
    </script>

    <div class='rep-head'>
        <img src='images/m26.png' alt='M26'>
        <div>
            <h1>Site Maintenance Report</h1>
            <div class='sub'>M26 — Power &amp; Telecoms Specialists</div>
        </div>
    </div>

    <div class='meta'>
        <p><strong>Site:</strong> <?php echo htmlspecialchars($visit['site_code'] . ' — ' . $visit['site_name']); ?></p>
        <p><strong>Region:</strong> <?php echo htmlspecialchars($visit['region'] ?? '—'); ?></p>
        <p><strong>Visit Type:</strong> <?php echo htmlspecialchars($visit['visit_type']); ?></p>
        <p><strong>Maintenance Type:</strong> <?php echo ucfirst(htmlspecialchars($visit['maintenance_type'] ?? 'active')); ?></p>
        <p><strong>Technician:</strong> <?php echo htmlspecialchars($visit['tech_first'] . ' ' . $visit['tech_last']); ?></p>
        <p><strong>Scheduled:</strong> <?php echo fmt_date($visit['scheduled_date']); ?></p>
        <?php if ($visit['completed_at']): ?>
        <p><strong>Completed:</strong> <?php echo fmt_datetime($visit['completed_at']); ?></p>
        <?php endif; ?>
        <?php if ($mform && $mform['submitted_at']): ?>
        <p><strong>Report Submitted:</strong> <?php echo fmt_datetime($mform['submitted_at']); ?></p>
        <?php endif; ?>
    </div>

    <?php if (!$mform): ?>
        <p>No maintenance form has been submitted for this visit yet.</p>
    <?php else: ?>

        <div class='summary <?php echo $fault_n ? 'issues' : 'clean'; ?>'>
            <?php echo $fault_n
                ? '&#9888; ' . $fault_n . ' faulty item' . ($fault_n === 1 ? '' : 's') . ' flagged.'
                : '&#10003; No faults flagged — all inspected items OK.'; ?>
        </div>

        <?php if (!empty($mform['access_ref']) || !empty($mform['capture_time'])): ?>
        <section>
            <h2>General Capture</h2>
            <table>
                <tr><th style='width:200px;'>Access Reference</th><td><?php echo htmlspecialchars($mform['access_ref'] ?? '—'); ?></td></tr>
                <tr><th>On-site Latitude</th><td><?php echo htmlspecialchars($mform['capture_lat'] ?? '—'); ?></td></tr>
                <tr><th>On-site Longitude</th><td><?php echo htmlspecialchars($mform['capture_lon'] ?? '—'); ?></td></tr>
                <tr><th>Capture Time</th><td><?php echo htmlspecialchars($mform['capture_time'] ?? '—'); ?></td></tr>
            </table>
        </section>
        <?php endif; ?>

        <?php foreach ($form_sections as $sec_key => $sec): ?>
        <section>
            <h2><?php echo htmlspecialchars($sec['label']); ?></h2>
            <table>
                <tr><th style='width:42%;'>Item</th><th style='width:90px;'>Status</th><th>Remarks</th></tr>
                <?php foreach ($sec['fields'] as $fk => $fl):
                    $status  = $decoded[$sec_key][$fk]['status']  ?? 'N/A';
                    $remarks = $decoded[$sec_key][$fk]['remarks'] ?? '';
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($fl); ?></td>
                    <td class='<?php echo st_class($status); ?>'><?php echo htmlspecialchars($status); ?></td>
                    <td><?php echo htmlspecialchars($remarks); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </section>
        <?php endforeach; ?>

        <section>
            <h2>Antenna Information</h2>
            <table>
                <tr><th>Sector</th><th>Azimuth</th><th>E-Tilt</th><th>M-Tilt</th><th>Height</th></tr>
                <?php for ($s = 1; $s <= 3; $s++): ?>
                <tr>
                    <td>Sector <?php echo $s; ?></td>
                    <td><?php echo htmlspecialchars($mform['ant_s'.$s.'_azimuth'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($mform['ant_s'.$s.'_etilt'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($mform['ant_s'.$s.'_mtilt'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($mform['ant_s'.$s.'_height'] ?? '—'); ?></td>
                </tr>
                <?php endfor; ?>
            </table>
        </section>

        <?php if (!empty($mform['general_comments'])): ?>
        <section>
            <h2>Comments</h2>
            <p><?php echo nl2br(htmlspecialchars($mform['general_comments'])); ?></p>
        </section>
        <?php endif; ?>

        <?php if (!empty($photos)): ?>
        <section>
            <h2>Site Photos</h2>
            <div class='photos'>
                <?php foreach ($photos as $ph): ?>
                <figure>
                    <img src='uploads/<?php echo htmlspecialchars($ph['photo_filename']); ?>' alt=''>
                    <?php if (!empty($ph['caption'])): ?><figcaption><?php echo htmlspecialchars($ph['caption']); ?></figcaption><?php endif; ?>
                </figure>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <div class='sign'>
            <div>Technician signature</div>
            <div>Supervisor signature</div>
            <div>Date</div>
        </div>

    <?php endif; ?>
</body>
</html>
