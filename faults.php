<?php
require_once 'incl/dbconn.php';
require_staff();

/*
 * Faults & Issues overview.
 * Scans submitted maintenance forms, pulls out items marked "Faulty"
 * plus any free-text issue notes, and lists them newest-first so office staff
 * can see open problems across all sites at a glance.
 *
 * Faults live inside per-section JSON columns, so SQL-level filters narrow
 * by site/month; fault extraction and pagination happen in PHP.
 */

$filter_q     = trim($_GET['q'] ?? '');
$filter_month = trim($_GET['month'] ?? '');

// Month dropdown — distinct months present across all maintenance forms
$month_rows = $conn->query("
    SELECT DISTINCT DATE_FORMAT(m.submitted_at, '%Y-%m') AS ym,
                    DATE_FORMAT(m.submitted_at, '%M %Y') AS label
    FROM maintenance_forms m
    JOIN visits v ON v.visit_id = m.visit_id
    JOIN sites  s ON s.site_id  = v.site_id
    WHERE m.submitted_at IS NOT NULL
    ORDER BY ym DESC
");

// ── SQL-level filters (site search + month) ────────────────────────────────────
$where  = [];
$params = [];
$types  = '';

if ($filter_month !== '') {
    $where[]  = "DATE_FORMAT(m.submitted_at, '%Y-%m') = ?";
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
$where_clause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT m.equipment_json, m.generator_json, m.transmission_json,
           m.security_json, m.container_json, m.electrical_json,
           m.general_comments, m.submitted_at,
           v.visit_id, v.visit_type, v.maintenance_type, v.status,
           s.site_code, s.site_name,
           u.first_name, u.last_name
    FROM maintenance_forms m
    JOIN visits v ON v.visit_id = m.visit_id
    JOIN sites  s ON s.site_id  = v.site_id
    JOIN users  u ON u.user_id  = v.assigned_to_user_id
    $where_clause
    ORDER BY m.submitted_at DESC
";

if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $forms = $stmt->get_result();
    $stmt->close();
} else {
    $forms = $conn->query($sql);
}

$form_sections = require __DIR__ . "/incl/form_sections.php";
$sections      = array_keys($form_sections);

// Flat machine-key -> human-label map, derived from canonical definitions
$field_labels = [];
foreach ($form_sections as $sec_def) {
    foreach ($sec_def["fields"] as $fk => $flabel) { $field_labels[$fk] = $flabel; }
}

// Collect only the visits that actually have one or more faulty items
$all_rows     = [];
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
    if (empty($faults)) {
        continue; // no faults flagged — don't list on the Faults page
    }
    $total_faults += count($faults);
    $all_rows[] = [
        'visit_id' => $f['visit_id'],
        'site'     => $f['site_code'] . ' — ' . $f['site_name'],
        'tech'     => trim($f['first_name'] . ' ' . $f['last_name']),
        'type'     => $f['visit_type'],
        'status'   => $f['status'],
        'when'     => $f['submitted_at'],
        'faults'   => $faults,
        'comment'  => trim($f['general_comments'] ?? ''),
    ];
}

// ── Pagination over PHP-collected fault rows ───────────────────────────────────
$per_page    = 25;
$page        = max(1, (int)($_GET['page'] ?? 1));
$total       = count($all_rows);
$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;
$rows        = array_slice($all_rows, $offset, $per_page);

function faults_page_link(int $n, string $q, string $month): string {
    $parts = array_filter(
        ['q' => $q, 'month' => $month, 'page' => $n],
        fn($v) => $v !== '' && $v !== null
    );
    return 'faults.php?' . http_build_query($parts);
}

$page_title = 'M26 | Faults & Issues';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Faults &amp; Issues</h1>
        </div>
        <p class='page-intro'>Every item technicians flagged as faulty, newest first — so you can action open problems across all sites.</p>

        <div class='stat-row'>
            <div class='stat-box'>
                <div class='stat-label'>Visits With Issues</div>
                <div class='stat-value'><?php echo $total; ?></div>
            </div>
            <div class='stat-box'>
                <div class='stat-label'>Total Faulty Items</div>
                <div class='stat-value'><?php echo $total_faults; ?></div>
            </div>
        </div>

        <form method='GET' action='faults.php' class='filter-bar'>
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
            <a href='faults.php' class='btn btn-secondary'>Clear</a>
        </form>

        <?php if (empty($rows)): ?>
            <?php echo empty_state('No faults flagged', 'Great news — every submitted maintenance report is clean.'); ?>
        <?php else: ?>
            <?php if ($total > $per_page || $filter_q !== '' || $filter_month !== ''): ?>
            <p style='font-size:13px; color:#888; margin-bottom:10px;'>
                Showing <?php echo ($offset + 1); ?>–<?php echo ($offset + count($rows)); ?>
                of <?php echo $total; ?> visit<?php echo $total !== 1 ? 's' : ''; ?> with faults
            </p>
            <?php endif; ?>

            <?php foreach ($rows as $r): ?>
            <div class='card'>
                <div style='display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px; margin-bottom:6px;'>
                    <h2 style='font-size:16px; color:#1a3a5c;'><?php echo htmlspecialchars($r['site']); ?></h2>
                    <span style='font-size:12px; color:#888;'>
                        <?php echo htmlspecialchars($r['type']); ?> &middot;
                        <?php echo htmlspecialchars($r['tech']); ?> &middot;
                        <?php echo fmt_datetime($r['when']); ?>
                        &nbsp;<?php echo status_badge($r['status']); ?>
                        &nbsp;<span class='badge badge-critical'><?php echo count($r['faults']); ?> fault<?php echo count($r['faults']) !== 1 ? 's' : ''; ?></span>
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
                    <a href='maintenance_form.php?visit_id=<?php echo (int)$r['visit_id']; ?>'>View full form &rarr;</a>
                </p>
            </div>
            <?php endforeach; ?>

            <?php if ($total_pages > 1): ?>
            <div class='pager'>
                <?php if ($page > 1): ?>
                    <a class='btn btn-secondary'
                       href='<?php echo htmlspecialchars(faults_page_link($page - 1, $filter_q, $filter_month)); ?>'>
                        &larr; Prev
                    </a>
                <?php endif; ?>
                <span class='pager-info'>Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                <?php if ($page < $total_pages): ?>
                    <a class='btn btn-secondary'
                       href='<?php echo htmlspecialchars(faults_page_link($page + 1, $filter_q, $filter_month)); ?>'>
                        Next &rarr;
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
