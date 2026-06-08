<?php
require_once 'incl/dbconn.php';
require_staff();

/*
 * Faults & Issues overview.
 * Scans every submitted maintenance form, pulls out the items marked "Faulty"
 * plus any free-text issue notes, and lists them newest-first so office staff
 * can see open problems across all sites at a glance.
 */

$forms = $conn->query("
    SELECT m.equipment_json, m.generator_json, m.transmission_json,
           m.security_json, m.container_json, m.electrical_json,
           m.general_comments, m.submitted_at,
           v.visit_id, v.visit_type, v.maintenance_type,
           s.site_code, s.site_name,
           u.first_name, u.last_name
    FROM maintenance_forms m
    JOIN visits v ON v.visit_id = m.visit_id
    JOIN sites  s ON s.site_id  = v.site_id
    JOIN users  u ON u.user_id  = v.assigned_to_user_id
    ORDER BY m.submitted_at DESC
");

$form_sections = require __DIR__ . "/incl/form_sections.php";
$sections      = array_keys($form_sections);

// Flat machine-key -> human-label map, derived from the canonical definitions
$field_labels = [];
foreach ($form_sections as $sec_def) {
    foreach ($sec_def["fields"] as $fk => $flabel) { $field_labels[$fk] = $flabel; }
}

// Collect only the visits that actually have one or more faulty items
$rows = [];
$total_faults = 0;
while ($f = $forms->fetch_assoc()) {
    $faults = [];
    foreach ($sections as $sec) {
        $data = $f[$sec . '_json'] ? json_decode($f[$sec . '_json'], true) : [];
        if (!is_array($data)) continue;
        foreach ($data as $key => $vals) {
            if (($vals['status'] ?? '') === 'Faulty') {
                $faults[] = [
                    'label'   => $field_labels[$key] ?? ucwords(str_replace('_', ' ', $key)),
                    'remarks' => trim($vals['remarks'] ?? ''),
                ];
            }
        }
    }
    $comment = trim($f['general_comments'] ?? '');
    if (empty($faults)) {
        continue; // no faults flagged — don't list on the Faults page
    }
    $total_faults += count($faults);
    $rows[] = [
        'visit_id' => $f['visit_id'],
        'site'     => $f['site_code'] . ' — ' . $f['site_name'],
        'tech'     => trim($f['first_name'] . ' ' . $f['last_name']),
        'type'     => $f['visit_type'],
        'when'     => $f['submitted_at'],
        'faults'   => $faults,
        'comment'  => $comment,
    ];
}

$page_title = 'M26 | Faults & Issues';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Faults &amp; Issues</h1>
        </div>

        <div class='stat-row'>
            <div class='stat-box'>
                <div class='stat-label'>Visits With Issues</div>
                <div class='stat-value'><?php echo count($rows); ?></div>
            </div>
            <div class='stat-box'>
                <div class='stat-label'>Total Faulty Items</div>
                <div class='stat-value'><?php echo $total_faults; ?></div>
            </div>
        </div>

        <?php if (empty($rows)): ?>
            <div class='card'>No faults or issue notes reported. All submitted forms are clean.</div>
        <?php else: ?>
            <?php foreach ($rows as $r): ?>
            <div class='card'>
                <div style='display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px; margin-bottom:6px;'>
                    <h2 style='font-size:16px; color:#1a3a5c;'><?php echo htmlspecialchars($r['site']); ?></h2>
                    <span style='font-size:12px; color:#888;'>
                        <?php echo htmlspecialchars($r['type']); ?> ·
                        <?php echo htmlspecialchars($r['tech']); ?> ·
                        <?php echo fmt_datetime($r['when']); ?>
                    </span>
                </div>

                <?php if (!empty($r['faults'])): ?>
                <table class='mf-table' style='margin-top:8px;'>
                    <tr><th style='width:260px;'>Faulty Item</th><th>Remarks</th></tr>
                    <?php foreach ($r['faults'] as $fault): ?>
                    <tr>
                        <td class='status-faulty'><?php echo htmlspecialchars($fault['label']); ?></td>
                        <td><?php echo htmlspecialchars($fault['remarks'] !== '' ? $fault['remarks'] : '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php endif; ?>

                <?php if ($r['comment'] !== ''): ?>
                <p style='margin-top:10px; font-size:13px;'>
                    <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($r['comment'])); ?>
                </p>
                <?php endif; ?>

                <p style='margin-top:10px;'>
                    <a href='maintenance_form.php?visit_id=<?php echo $r['visit_id']; ?>'>View full form &rarr;</a>
                </p>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
