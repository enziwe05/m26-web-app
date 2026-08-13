<?php
/*
 * Technician self-service: start (or resume) a visit at a site the tech picked
 * themselves — no office assignment needed. A self-started visit is an ordinary
 * `visits` row where the tech is BOTH the creator and the assignee, so the whole
 * downstream flow (employee_visit.php, submit_visit.php, photos, admin views)
 * works unchanged.
 *
 * Guards:
 *   - employee only, CSRF checked
 *   - the tech must be clocked in (mirrors the on-site / anti-fraud model)
 *   - if an open (non-completed) visit already exists for this tech + site,
 *     reuse it instead of creating a duplicate
 */
require_once 'incl/dbconn.php';
require_employee();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pick_site.php');
    exit;
}
csrf_check();

$user_id = current_user_id();

// ── Must be clocked in (server-side enforcement — never trust the page) ───────
$stmt = $conn->prepare("SELECT entry_id FROM time_entries WHERE user_id = ? AND status = 'open' LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$is_clocked_in = (bool) $stmt->get_result()->num_rows;
$stmt->close();

if (!$is_clocked_in) {
    flash('Please clock in first — then you can start a visit.');
    header('Location: clock.php');
    exit;
}

// ── Validate inputs ───────────────────────────────────────────────────────────
$site_id          = (int) ($_POST['site_id'] ?? 0);
$visit_type       = in_array($_POST['visit_type'] ?? '', visit_types(), true)
                    ? $_POST['visit_type'] : 'Maintenance';
$maintenance_type = in_array($_POST['maintenance_type'] ?? '', ['active', 'passive', 'housekeeping'], true)
                    ? $_POST['maintenance_type'] : 'active';

// Site must exist
$stmt = $conn->prepare("SELECT site_id FROM sites WHERE site_id = ?");
$stmt->bind_param('i', $site_id);
$stmt->execute();
$site_ok = (bool) $stmt->get_result()->num_rows;
$stmt->close();

if (!$site_id || !$site_ok) {
    flash('That site could not be found. Please pick a site from the list.');
    header('Location: pick_site.php');
    exit;
}

// ── Reuse an existing open visit for this tech + site (no duplicates) ──────────
$stmt = $conn->prepare("
    SELECT visit_id FROM visits
    WHERE assigned_to_user_id = ? AND site_id = ? AND status <> 'completed'
    ORDER BY created_at DESC LIMIT 1
");
$stmt->bind_param('ii', $user_id, $site_id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    header('Location: employee_visit.php?visit_id=' . (int) $existing['visit_id']);
    exit;
}

// ── Create a new self-assigned visit ──────────────────────────────────────────
$today = date('Y-m-d');
$stmt = $conn->prepare("
    INSERT INTO visits (site_id, assigned_to_user_id, created_by_user_id, visit_type,
                        description, scheduled_date, maintenance_type, status)
    VALUES (?, ?, ?, ?, '', ?, ?, 'in_progress')
");
$stmt->bind_param('iiisss', $site_id, $user_id, $user_id, $visit_type, $today, $maintenance_type);

try {
    $stmt->execute();
    $visit_id = $stmt->insert_id;
    $stmt->close();
    flash('Visit started. Fill in the maintenance form below.');
    header('Location: employee_visit.php?visit_id=' . $visit_id);
    exit;
} catch (mysqli_sql_exception $e) {
    $stmt->close();
    flash('Could not start the visit. Please try again.');
    header('Location: pick_site.php');
    exit;
}
