<?php
require_once 'incl/dbconn.php';
require_client();

$client = current_client($conn);
if (!$client) {
    $page_title = 'M26 | Portal';
    include 'incl/header.php';
    echo "<div class='alert alert-error'>Your client account is not active. Please contact M26.</div>";
    include 'incl/footer.php';
    exit;
}

// All scoping is by the client's company matched against sites.notes
$f      = client_site_filter($client['match_keyword'], 's');
$cond   = $f['cond'];
$params = $f['params'];
$types  = str_repeat('s', count($params));

// ── Headline numbers ───────────────────────────────────────────────────────────
$total_sites = client_site_count($conn, $client['match_keyword']);

$stmt = $conn->prepare("
    SELECT SUM(v.status = 'in_progress')                                          AS in_progress,
           SUM(v.status = 'completed')                                            AS completed,
           SUM(v.status = 'completed'
               AND YEAR(v.completed_at)=YEAR(CURDATE())
               AND MONTH(v.completed_at)=MONTH(CURDATE()))                        AS completed_month
    FROM visits v JOIN sites s ON s.site_id = v.site_id
    WHERE $cond
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$vs = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Recent activity (the "what's happening" feed) — show last 10 ──────────────
$stmt = $conn->prepare("
    SELECT v.visit_id, v.visit_type, v.status, v.scheduled_date,
           s.site_id, s.site_name
    FROM visits v JOIN sites s ON s.site_id = v.site_id
    WHERE $cond
    ORDER BY v.created_at DESC
    LIMIT 10
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$recent = $stmt->get_result();
$stmt->close();

// ── Sites list (searchable + capped so it never becomes a wall of rows) ─────────
$q = trim($_GET['q'] ?? '');
$site_cond = $cond; $site_params = $params; $site_types = $types;
if ($q !== '') {
    $site_cond  .= " AND (s.site_code LIKE ? OR s.site_name LIKE ? OR s.location LIKE ?)";
    $like = '%' . $q . '%';
    array_push($site_params, $like, $like, $like);
    $site_types .= 'sss';
}
$CAP = 25;
$stmt = $conn->prepare("
    SELECT s.site_id, s.site_code, s.site_name, s.region,
           (SELECT MAX(scheduled_date) FROM visits WHERE site_id = s.site_id) AS last_visit
    FROM sites s
    WHERE $site_cond
    ORDER BY s.site_code
    LIMIT " . ($CAP + 1) . "
");
$stmt->bind_param($site_types, ...$site_params);
$stmt->execute();
$site_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$has_more = count($site_rows) > $CAP;
$site_rows = array_slice($site_rows, 0, $CAP);

$page_title = 'M26 | ' . $client['name'];
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1><?php echo htmlspecialchars($client['name']); ?></h1>
        </div>
        <p class='page-intro'>
            Welcome to your M26 portal &mdash; track site visits, view inspection reports, and stay up to date with maintenance across your sites.
        </p>

        <div class='stat-row'>
            <div class='stat-box'>
                <div class='stat-label'>Your Sites</div>
                <div class='stat-value'><?php echo $total_sites; ?></div>
            </div>
            <div class='stat-box<?php echo (int)($vs['in_progress'] ?? 0) > 0 ? ' stat-box--green' : ''; ?>'>
                <div class='stat-label'>In Progress</div>
                <div class='stat-value'><?php echo (int)($vs['in_progress'] ?? 0); ?></div>
            </div>
            <div class='stat-box'>
                <div class='stat-label'>Completed This Month</div>
                <div class='stat-value'><?php echo (int)($vs['completed_month'] ?? 0); ?></div>
            </div>
            <div class='stat-box'>
                <div class='stat-label'>All Completed</div>
                <div class='stat-value'><?php echo (int)($vs['completed'] ?? 0); ?></div>
            </div>
        </div>

        <div class='section-heading'>Recent Activity</div>
        <?php if ($recent->num_rows == 0): ?>
            <p style='color:#888; font-size:13px;'>No visit activity on your sites yet &mdash; M26 will update this when work begins.</p>
        <?php else: ?>
            <div class='card card-table'><table class='data-table'>
                <tr><th>Site</th><th>Visit Type</th><th>Scheduled</th><th>Status</th><th></th></tr>
                <?php while ($r = $recent->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['site_name']); ?></td>
                    <td><?php echo htmlspecialchars($r['visit_type']); ?></td>
                    <td><?php echo fmt_date($r['scheduled_date']); ?></td>
                    <td><?php echo status_badge($r['status']); ?></td>
                    <td><a href='client_site_detail.php?site_id=<?php echo (int)$r['site_id']; ?>'>View &rarr;</a></td>
                </tr>
                <?php endwhile; ?>
            </table></div>
        <?php endif; ?>

        <div class='section-heading'>Your Sites</div>

        <form method='GET' action='client_dashboard.php' class='filter-bar'>
            <input type='text' name='q' value='<?php echo htmlspecialchars($q); ?>'
                   placeholder='Search by site code, name or area'>
            <button type='submit' class='btn btn-secondary'>Search</button>
            <?php if ($q !== ''): ?><a href='client_dashboard.php' class='btn btn-secondary'>Clear</a><?php endif; ?>
        </form>

        <?php if (empty($site_rows)): ?>
            <p style='color:#888;'>
                <?php echo $q !== '' ? 'No sites match your search.' : 'No sites are linked to your account yet — please contact M26.'; ?>
            </p>
        <?php else: ?>
            <div class='card card-table'><table class='data-table'>
                <tr><th>Code</th><th>Name</th><th>Region</th><th>Last Visit</th><th></th></tr>
                <?php foreach ($site_rows as $s): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($s['site_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars($s['site_name']); ?></td>
                    <td><?php echo htmlspecialchars($s['region'] ?? '—'); ?></td>
                    <td><?php echo $s['last_visit'] ? fmt_date($s['last_visit']) : '—'; ?></td>
                    <td><a href='client_site_detail.php?site_id=<?php echo $s['site_id']; ?>'>View &rarr;</a></td>
                </tr>
                <?php endforeach; ?>
            </table></div>
            <?php if ($has_more): ?>
            <p style='font-size:13px; color:#888; margin-top:10px;'>
                Showing the first <?php echo $CAP; ?> of <?php echo $total_sites; ?> sites — use search above to find a specific site.
            </p>
            <?php endif; ?>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
