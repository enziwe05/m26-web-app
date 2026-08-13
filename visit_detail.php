<?php
require_once 'incl/dbconn.php';
require_staff();

if (!isset($_GET['visit_id'])) {
    header('Location: view_visits.php');
    exit;
}

$visit_id = (int)$_GET['visit_id'];

// Visit info
$stmt = $conn->prepare("
    SELECT v.*, s.site_code, s.site_name, s.location,
           t.first_name AS tech_first, t.last_name AS tech_last,
           c.first_name AS creator_first, c.last_name AS creator_last
    FROM visits v
    JOIN sites s ON s.site_id = v.site_id
    JOIN users t ON t.user_id = v.assigned_to_user_id
    JOIN users c ON c.user_id = v.created_by_user_id
    WHERE v.visit_id = ?
");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$visit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$visit) {
    error_page('Visit not found', 'That visit may have been deleted or the link is wrong.', 'view_visits.php', 'Back to visits');
}

// Has the technician submitted the inspection form? Count any faulty items.
$stmt = $conn->prepare("SELECT * FROM maintenance_forms WHERE visit_id = ?");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$mform = $stmt->get_result()->fetch_assoc();
$stmt->close();

$fault_count = 0;
if ($mform) {
    foreach (['equipment', 'generator', 'transmission', 'security', 'container', 'electrical'] as $sec) {
        $data = $mform[$sec . '_json'] ? json_decode($mform[$sec . '_json'], true) : [];
        if (is_array($data)) {
            foreach ($data as $v) {
                if (($v['status'] ?? '') === 'Faulty') $fault_count++;
            }
        }
    }
}

// ── Photos uploaded for this visit, grouped into upload batches ───────────────
$photo_groups = []; // uploaded_at => ['count'=>n, 'caps'=>[...], 'by'=>uploader name]
$pstmt = $conn->prepare("
    SELECT p.uploaded_at, p.caption, up.first_name AS up_first, up.last_name AS up_last
    FROM visit_photos p
    JOIN users up ON up.user_id = p.uploaded_by_user_id
    WHERE p.visit_id = ?
    ORDER BY p.uploaded_at
");
$pstmt->bind_param('i', $visit_id);
$pstmt->execute();
$pres = $pstmt->get_result();
while ($p = $pres->fetch_assoc()) {
    $k = $p['uploaded_at'];
    if (!isset($photo_groups[$k])) {
        $photo_groups[$k] = ['count' => 0, 'caps' => [], 'by' => trim($p['up_first'] . ' ' . $p['up_last'])];
    }
    $photo_groups[$k]['count']++;
    if (trim((string) $p['caption']) !== '') $photo_groups[$k]['caps'][] = trim($p['caption']);
}
$pstmt->close();

// ── Build a richer activity log from the visit's timestamped events ───────────
$creator_name = trim($visit['creator_first'] . ' ' . $visit['creator_last']);
$tech_name    = trim($visit['tech_first'] . ' ' . $visit['tech_last']);
$self_started = ((int) $visit['created_by_user_id'] === (int) $visit['assigned_to_user_id']);

$events = [];

// 1. Visit created / self-started
if ($self_started) {
    $events[] = ['t' => $visit['created_at'], 'type' => 'start',
                 'title' => 'Visit started by technician', 'by' => $tech_name,
                 'detail' => 'Technician picked this site — no office assignment needed.'];
} else {
    $events[] = ['t' => $visit['created_at'], 'type' => 'created',
                 'title' => 'Visit created & assigned to ' . $tech_name, 'by' => $creator_name,
                 'detail' => ($visit['description'] ?? '') !== '' ? 'Instructions: ' . $visit['description'] : ''];
}

// 2. Photo upload batches
foreach ($photo_groups as $t => $g) {
    $n = $g['count'];
    $events[] = ['t' => $t, 'type' => 'photo',
                 'title' => $n . ' site photo' . ($n === 1 ? '' : 's') . ' uploaded',
                 'by' => $g['by'],
                 'detail' => !empty($g['caps']) ? 'Captions: ' . implode('; ', $g['caps']) : ''];
}

// 3. Maintenance report draft save + final submission
if ($mform) {
    $submitted = !empty($mform['is_submitted']) && !empty($mform['submitted_at']);
    // Draft save, only when it's distinct from the final submit
    if (!empty($mform['updated_at']) && (!$submitted || $mform['updated_at'] < $mform['submitted_at'])) {
        $events[] = ['t' => $mform['updated_at'], 'type' => 'draft',
                     'title' => 'Maintenance report saved as draft', 'by' => $tech_name, 'detail' => ''];
    }
    if ($submitted) {
        $fdetail = $fault_count > 0
            ? $fault_count . ' faulty item' . ($fault_count === 1 ? '' : 's') . ' flagged'
            : 'No faults flagged';
        $events[] = ['t' => $mform['submitted_at'], 'type' => 'submitted',
                     'title' => 'Maintenance report submitted — visit completed',
                     'by' => $tech_name, 'detail' => $fdetail];
    }
}

// newest first, so the most recent activity is at the top
usort($events, fn($a, $b) => strcmp($b['t'], $a['t']));

$page_title = 'M26 | Visit #' . $visit_id;
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Visit — <?php echo htmlspecialchars($visit['site_name']); ?></h1>
            <div style='display:flex;gap:8px;align-items:center;'>
<?php echo status_badge($visit['status']); ?>
                <?php if ($visit['status'] !== 'completed'): ?>
                    <a href='edit_visit.php?visit_id=<?php echo $visit_id; ?>' class='btn btn-secondary' style='font-size:13px;padding:5px 14px;'>Edit</a>
                <?php endif; ?>
                <?php if (is_admin()): ?>
                    <a href='delete_visit.php?visit_id=<?php echo $visit_id; ?>' class='btn btn-danger' style='font-size:13px;padding:5px 14px;'>Delete</a>
                <?php endif; ?>
            </div>
        </div>

        <div class='card'>
            <p><strong>Type:</strong> <?php echo htmlspecialchars($visit['visit_type']); ?></p>
            <p><strong>Location:</strong> <?php echo htmlspecialchars($visit['location']); ?></p>
            <p><strong>Assigned to:</strong> <?php echo htmlspecialchars($visit['tech_first'] . ' ' . $visit['tech_last']); ?></p>
            <p><strong>Origin:</strong> <?php echo visit_origin_badge((int)$visit['created_by_user_id'], (int)$visit['assigned_to_user_id']); ?></p>
            <p><strong>Scheduled:</strong> <?php echo fmt_date($visit['scheduled_date']); ?></p>
            <p><strong>Created by:</strong> <?php echo htmlspecialchars($visit['creator_first'] . ' ' . $visit['creator_last']); ?> on <?php echo fmt_datetime($visit['created_at']); ?></p>
            <?php if ($visit['completed_at']): ?>
            <p><strong>Completed:</strong> <?php echo fmt_datetime($visit['completed_at']); ?></p>
            <?php endif; ?>
            <?php if ($visit['description'] != ''): ?>
            <p><strong>Description:</strong> <?php echo htmlspecialchars($visit['description']); ?></p>
            <?php endif; ?>
        </div>

        <!-- Maintenance inspection report (what the technician filled in) -->
        <div class='card'>
            <h2 style='font-size:15px; color:#1a3a5c; margin-bottom:10px;'>Maintenance Report</h2>
            <?php
                $fault_note = $fault_count > 0
                    ? "<span class='status-faulty'>$fault_count faulty item" . ($fault_count == 1 ? '' : 's') . " flagged</span>"
                    : "<span class='status-ok'>No faults flagged</span>";
            ?>
            <?php if ($mform && $mform['is_submitted']): ?>
                <p style='margin-bottom:12px;'>
                    &#10003; Submitted on <strong><?php echo fmt_datetime($mform['submitted_at']); ?></strong>
                    &nbsp;·&nbsp; <?php echo $fault_note; ?>
                </p>
                <a href='maintenance_form.php?visit_id=<?php echo $visit_id; ?>' class='btn btn-primary'>View Full Report</a>
                &nbsp;
                <a href='report_print.php?visit_id=<?php echo $visit_id; ?>' target='_blank' class='btn btn-secondary'>Save as PDF</a>
            <?php elseif ($mform): ?>
                <p style='margin-bottom:12px;'>
                    <span class='badge badge-in-progress'>In progress</span>
                    &nbsp; The technician saved a draft — last updated
                    <strong><?php echo fmt_datetime($mform['updated_at'] ?? $mform['submitted_at']); ?></strong>
                    &nbsp;·&nbsp; <?php echo $fault_note; ?> so far
                </p>
                <a href='maintenance_form.php?visit_id=<?php echo $visit_id; ?>' class='btn btn-primary'>View Progress So Far</a>
            <?php else: ?>
                <p style='color:#888; margin-bottom:12px;'>The technician hasn't started the inspection form yet.</p>
                <a href='maintenance_form.php?visit_id=<?php echo $visit_id; ?>' class='btn btn-secondary'>Fill on their behalf</a>
            <?php endif; ?>
        </div>

        <!-- Activity Log — when each thing was logged, newest first -->
        <div class='card'>
            <h2 style='font-size:15px; color:#1a3a5c; margin-bottom:12px;'>Activity Log</h2>
            <?php if (empty($events)): ?>
                <p style='color:#888;'>No activity recorded yet.</p>
            <?php else: ?>
                <div class='timeline'>
                    <?php foreach ($events as $i => $ev): ?>
                    <div class='timeline-row<?php echo $i === 0 ? ' latest' : ''; ?>'>
                        <div class='timeline-when'><?php echo fmt_datetime($ev['t']); ?></div>
                        <div class='timeline-what'>
                            <span class='tl-dot tl-<?php echo htmlspecialchars($ev['type'] ?? 'created'); ?>'></span>
                            <?php echo htmlspecialchars($ev['title']); ?>
                            <?php if ($ev['by']): ?><span class='timeline-who'>— <?php echo htmlspecialchars($ev['by']); ?></span><?php endif; ?>
                            <?php if ($i === 0): ?><span class='timeline-latest'>Latest</span><?php endif; ?>
                            <?php if (!empty($ev['detail'])): ?><div class='timeline-detail'><?php echo htmlspecialchars($ev['detail']); ?></div><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <br>
        <p>
            <a href='site_detail.php?site_id=<?php echo $visit['site_id']; ?>'>&larr; Back to Site</a>
            &nbsp;|&nbsp;
            <a href='view_visits.php'>&larr; All Visits</a>
        </p>

<?php include 'incl/footer.php'; ?>
