<?php
require_once 'incl/dbconn.php';
require_employee();

if (!isset($_GET['visit_id'])) {
    header('Location: employee_dashboard.php');
    exit;
}

$visit_id = (int)$_GET['visit_id'];
$user_id  = current_user_id();

// Load visit (must be assigned to this tech)
$stmt = $conn->prepare("
    SELECT v.*, s.site_code, s.site_name, s.location, s.latitude, s.longitude,
           u.first_name AS tech_first
    FROM visits v
    JOIN sites s ON s.site_id = v.site_id
    JOIN users u ON u.user_id = v.assigned_to_user_id
    WHERE v.visit_id = ? AND v.assigned_to_user_id = ?
");
$stmt->bind_param('ii', $visit_id, $user_id);
$stmt->execute();
$visit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$visit) {
    echo "Visit not found or not assigned to you. <a href='employee_dashboard.php'>Back</a>";
    exit;
}

// Check if maintenance form has already been submitted
$stmt = $conn->prepare("SELECT * FROM maintenance_forms WHERE visit_id = ?");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$existing_form = $stmt->get_result()->fetch_assoc();
$stmt->close();

// A form is locked (read-only) only once it has been finally submitted.
// A draft (is_submitted = 0) stays editable so the tech can resume later.
$is_locked = $existing_form && (int)($existing_form['is_submitted'] ?? 0) === 1;

// Site photos already uploaded for this visit (so the tech sees what's saved)
$stmt = $conn->prepare("SELECT photo_filename, caption FROM visit_photos WHERE visit_id = ? AND photo_type = 'other' ORDER BY uploaded_at");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$site_photos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$is_completed = ($visit['status'] == 'completed');

// Section definitions — each key maps to a human label
$form_sections = require __DIR__ . "/incl/form_sections.php";

// Decode existing JSON data if form was already submitted
$decoded = [];
if ($existing_form) {
    foreach (['equipment','generator','transmission','security','container','electrical'] as $sec) {
        $decoded[$sec] = $existing_form[$sec . '_json'] ? json_decode($existing_form[$sec . '_json'], true) : [];
    }
}

// Helper: get saved status/remarks for read-only view or pre-fill
function saved_val($decoded, $section, $field, $key) {
    return htmlspecialchars($decoded[$section][$field][$key] ?? '');
}

$mtype_label = $visit['maintenance_type'] === 'passive' ? 'Passive Maintenance' : 'Active Maintenance';
$mtype_badge = $visit['maintenance_type'] === 'passive' ? 'background:#6c757d;' : 'background:#0d6efd;';
$page_title = 'M26 | ' . $visit['site_code'] . ' Maintenance Form';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1><?php echo htmlspecialchars($visit['site_name']); ?></h1>
<?php echo status_badge($visit['status']); ?>
            <span class='badge-mtype' style='<?php echo $mtype_badge; ?>'><?php echo $mtype_label; ?></span>
        </div>

        <!-- Visit summary -->
        <div class='card' style='margin-bottom:20px;'>
            <p><strong>Site:</strong> <?php echo htmlspecialchars($visit['site_code'] . ' — ' . $visit['site_name']); ?></p>
            <p><strong>Visit Type:</strong> <?php echo htmlspecialchars($visit['visit_type']); ?></p>
            <?php if ($visit['description']): ?>
            <p><strong>Instructions:</strong> <?php echo htmlspecialchars($visit['description']); ?></p>
            <?php endif; ?>
            <?php if ($visit['scheduled_date']): ?>
            <p><strong>Scheduled:</strong> <?php echo htmlspecialchars($visit['scheduled_date']); ?></p>
            <?php endif; ?>
        </div>

        <?php if ($is_locked): ?>
        <!-- ===================== READ-ONLY COMPLETED VIEW ===================== -->
        <div class='card' style='background:#f0fff4; border-color:#a8d5a8; margin-bottom:20px;'>
            <p><strong>&#10003; Maintenance form submitted</strong> on
               <?php echo htmlspecialchars($existing_form['submitted_at']); ?></p>
        </div>

        <!-- General Capture Info -->
        <div class='mf-section card'>
            <h2>General Information</h2>
            <table class='mf-table'>
                <tr><th style='width:220px;'>Item</th><th>Value</th></tr>
                <tr><td>Access Reference</td><td><?php echo htmlspecialchars($existing_form['access_ref'] ?? '—'); ?></td></tr>
                <tr><td>Latitude (on-site)</td><td><?php echo htmlspecialchars($existing_form['capture_lat'] ?? '—'); ?></td></tr>
                <tr><td>Longitude (on-site)</td><td><?php echo htmlspecialchars($existing_form['capture_lon'] ?? '—'); ?></td></tr>
                <tr><td>Data Capture Time</td><td><?php echo htmlspecialchars($existing_form['capture_time'] ?? '—'); ?></td></tr>
            </table>
        </div>

        <!-- Inspection sections read-only -->
        <?php foreach ($form_sections as $sec_key => $sec): ?>
        <div class='mf-section card'>
            <h2><?php echo htmlspecialchars($sec['label']); ?></h2>
            <table class='mf-table'>
                <tr>
                    <th style='width:260px;'>Item</th>
                    <th style='width:100px;'>Status</th>
                    <th>Remarks</th>
                </tr>
                <?php foreach ($sec['fields'] as $fk => $fl):
                    $status  = $decoded[$sec_key][$fk]['status']  ?? 'N/A';
                    $remarks = $decoded[$sec_key][$fk]['remarks'] ?? '';
                    $sc = $status == 'OK' ? 'status-ok' : ($status == 'Faulty' ? 'status-faulty' : 'status-na');
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

        <!-- Antenna read-only -->
        <div class='mf-section card'>
            <h2>Antenna Information</h2>
            <table class='mf-table'>
                <tr>
                    <th style='width:160px;'>Sector</th>
                    <th>Azimuth</th><th>E-Tilt</th><th>M-Tilt</th><th>Antenna Height</th>
                </tr>
                <?php for ($s = 1; $s <= 3; $s++): ?>
                <tr>
                    <td>Sector <?php echo $s; ?></td>
                    <td><?php echo htmlspecialchars($existing_form['ant_s'.$s.'_azimuth'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($existing_form['ant_s'.$s.'_etilt']   ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($existing_form['ant_s'.$s.'_mtilt']   ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($existing_form['ant_s'.$s.'_height']  ?? '—'); ?></td>
                </tr>
                <?php endfor; ?>
                <tr>
                    <td><strong>GPS</strong></td>
                    <td colspan='2'>Lat: <?php echo htmlspecialchars($existing_form['gps_lat'] ?? '—'); ?></td>
                    <td colspan='2'>Lon: <?php echo htmlspecialchars($existing_form['gps_lon'] ?? '—'); ?></td>
                </tr>
            </table>
        </div>

        <!-- Comments read-only -->
        <?php if (!empty($existing_form['general_comments'])): ?>
        <div class='mf-section card'>
            <h2>Final Comments</h2>
            <p><?php echo nl2br(htmlspecialchars($existing_form['general_comments'])); ?></p>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- ===================== EDITABLE FORM ===================== -->

        <?php if (isset($_GET['saved'])): ?>
        <div class='alert alert-success'>Progress saved. You can close this and continue later, or submit when finished.</div>
        <?php endif; ?>

        <?php if ($existing_form): ?>
        <div class='info-banner'>
            &#9998; Draft last saved on
            <strong><?php echo fmt_datetime($existing_form['updated_at'] ?? $existing_form['submitted_at']); ?></strong>.
            Your previous entries are loaded below — keep working and click <strong>Submit &amp; Complete</strong> when done.
        </div>
        <?php endif; ?>

        <form method='POST' action='submit_visit.php' enctype='multipart/form-data'>
            <?php echo csrf_field(); ?>
            <input type='hidden' name='visit_id' value='<?php echo $visit_id; ?>'>

            <!-- ── Maintenance Type ── -->
            <div class='mf-section card'>
                <h2>Maintenance Type</h2>
                <p style='font-size:13px; color:#666; margin:0 0 10px;'>
                    Confirm the type of maintenance you are performing at this site.
                </p>
                <div style='display:flex; gap:24px;'>
                    <?php $mt = $visit['maintenance_type'] ?? 'active'; ?>
                    <label style='display:flex; align-items:center; gap:6px; font-weight:600; cursor:pointer;'>
                        <input type='radio' name='maintenance_type' value='active'
                               <?php echo $mt == 'active' ? 'checked' : ''; ?>>
                        Active Maintenance
                    </label>
                    <label style='display:flex; align-items:center; gap:6px; font-weight:600; cursor:pointer;'>
                        <input type='radio' name='maintenance_type' value='passive'
                               <?php echo $mt == 'passive' ? 'checked' : ''; ?>>
                        Passive Maintenance
                    </label>
                </div>
                <small style='color:#666; display:block; margin-top:8px;'>
                    Active = hands-on work performed &nbsp;|&nbsp; Passive = observation / monitoring only
                </small>
            </div>

            <!-- ── General Information ── -->
            <div class='mf-section card'>
                <h2>General Information</h2>
                <p style='font-size:12px; color:#888; margin:-4px 0 10px;'>
                    Location and capture time are recorded automatically and cannot be edited.
                </p>
                <table class='mf-table'>
                    <tr><th style='width:220px;'>Item</th><th>Value</th></tr>
                    <tr>
                        <td>Access Reference</td>
                        <td><input type='text' name='access_ref' placeholder='e.g. None / Noted'
                                   value='<?php echo htmlspecialchars($existing_form['access_ref'] ?? ''); ?>'></td>
                    </tr>
                    <tr>
                        <td>Latitude</td>
                        <td><input type='text' readonly
                                   style='background:#f1f3f5; color:#555;'
                                   value='<?php echo $visit['latitude'] !== null ? htmlspecialchars($visit['latitude']) : 'Not set for this site'; ?>'></td>
                    </tr>
                    <tr>
                        <td>Longitude</td>
                        <td><input type='text' readonly
                                   style='background:#f1f3f5; color:#555;'
                                   value='<?php echo $visit['longitude'] !== null ? htmlspecialchars($visit['longitude']) : 'Not set for this site'; ?>'></td>
                    </tr>
                    <tr>
                        <td>Data Capture Time</td>
                        <td><input type='text' readonly
                                   style='background:#f1f3f5; color:#555;'
                                   value='<?php echo date('H:i'); ?>'></td>
                    </tr>
                </table>
            </div>

            <!-- ── Site Photos ── -->
            <div class='mf-section card'>
                <h2>Site Photos</h2>
                <?php if (!empty($site_photos)): ?>
                <p style='font-size:12px; color:#666; margin-bottom:8px;'>
                    <?php echo count($site_photos); ?> photo(s) already saved. Upload below to add more.
                </p>
                <div class='photo-grid' style='margin-bottom:12px;'>
                    <?php foreach ($site_photos as $ph): ?>
                    <div class='photo-thumb'>
                        <img src='uploads/<?php echo htmlspecialchars($ph['photo_filename']); ?>' alt=''>
                        <?php if ($ph['caption']): ?>
                        <div class='pt-cap'><?php echo htmlspecialchars($ph['caption']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class='photo-upload-row'>
                    <?php for ($p = 1; $p <= 4; $p++): ?>
                    <div class='photo-upload-box'>
                        <label>Status Photo <?php echo $p; ?></label>
                        <input type='file' name='photo_<?php echo $p; ?>' accept='image/*'
                               style='margin-bottom:6px; display:block; font-size:12px;'>
                        <input type='text' name='photo_caption_<?php echo $p; ?>'
                               placeholder='Caption for photo <?php echo $p; ?>'>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- ── Inspection Sections ── -->
            <?php foreach ($form_sections as $sec_key => $sec): ?>
            <div class='mf-section card'>
                <h2><?php echo htmlspecialchars($sec['label']); ?></h2>
                <table class='mf-table'>
                    <tr>
                        <th style='width:260px;'>Item</th>
                        <th style='width:120px;'>Status</th>
                        <th>Remarks</th>
                    </tr>
                    <?php foreach ($sec['fields'] as $fk => $fl):
                        $sv = $decoded[$sec_key][$fk]['status']  ?? 'N/A';
                        $rv = $decoded[$sec_key][$fk]['remarks'] ?? '';
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($fl); ?></td>
                        <td>
                            <select name='<?php echo $sec_key; ?>[<?php echo $fk; ?>][status]'>
                                <?php foreach (['N/A', 'OK', 'Faulty'] as $opt): ?>
                                <option value='<?php echo $opt; ?>'<?php echo $sv === $opt ? ' selected' : ''; ?>><?php echo $opt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type='text' name='<?php echo $sec_key; ?>[<?php echo $fk; ?>][remarks]'
                                   placeholder='Remarks' value='<?php echo htmlspecialchars($rv); ?>'>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endforeach; ?>

            <!-- ── Antenna Information ── -->
            <div class='mf-section card'>
                <h2>Antenna Information</h2>
                <?php for ($s = 1; $s <= 3; $s++): ?>
                <p style='font-weight:600; margin:12px 0 6px;'>Sector <?php echo $s; ?></p>
                <div class='antenna-grid'>
                    <div>
                        <label>Azimuth</label>
                        <input type='text' name='ant_s<?php echo $s; ?>_azimuth' placeholder='e.g. 120°'
                               value='<?php echo htmlspecialchars($existing_form['ant_s'.$s.'_azimuth'] ?? ''); ?>'>
                    </div>
                    <div>
                        <label>E-Tilt</label>
                        <input type='text' name='ant_s<?php echo $s; ?>_etilt' placeholder='e.g. 4'
                               value='<?php echo htmlspecialchars($existing_form['ant_s'.$s.'_etilt'] ?? ''); ?>'>
                    </div>
                    <div>
                        <label>M-Tilt</label>
                        <input type='text' name='ant_s<?php echo $s; ?>_mtilt' placeholder='e.g. 2'
                               value='<?php echo htmlspecialchars($existing_form['ant_s'.$s.'_mtilt'] ?? ''); ?>'>
                    </div>
                    <div>
                        <label>Antenna Height</label>
                        <input type='text' name='ant_s<?php echo $s; ?>_height' placeholder='e.g. 30m'
                               value='<?php echo htmlspecialchars($existing_form['ant_s'.$s.'_height'] ?? ''); ?>'>
                    </div>
                </div>
                <?php endfor; ?>
                <p style='font-weight:600; margin:12px 0 6px;'>GPS Coordinates</p>
                <div class='antenna-grid' style='grid-template-columns: 1fr 1fr 3fr;'>
                    <div>
                        <label>GPS Latitude</label>
                        <input type='text' name='gps_lat' placeholder='e.g. -26.3259'
                               value='<?php echo htmlspecialchars($existing_form['gps_lat'] ?? ''); ?>'>
                    </div>
                    <div>
                        <label>GPS Longitude</label>
                        <input type='text' name='gps_lon' placeholder='e.g. 31.1436'
                               value='<?php echo htmlspecialchars($existing_form['gps_lon'] ?? ''); ?>'>
                    </div>
                </div>
            </div>

            <!-- ── Tech Comments ── -->
            <div class='mf-section card'>
                <h2>Comments &amp; Issues</h2>
                <div class='form-group'>
                    <label style='font-weight:600;'>General Comments / Issues Encountered</label>
                    <textarea name='general_comments' rows='5'
                              placeholder='Describe overall site condition, actions taken, and any issues or problems encountered at the site...'
                              style='width:100%; padding:8px; border:1px solid #ced4da; border-radius:4px; font-size:13px;'><?php echo htmlspecialchars($existing_form['general_comments'] ?? ''); ?></textarea>
                </div>
            </div>

            <!-- Save / Submit -->
            <div style='margin: 20px 0; display:flex; gap:10px; flex-wrap:wrap;'>
                <button type='submit' name='save_draft' value='1' class='btn btn-secondary'>Save Progress</button>
                <button type='submit' name='submit_final' value='1' class='btn btn-primary'
                        onclick="return confirm('Submit and complete this visit? You will not be able to edit the form afterwards.');">
                    Submit &amp; Complete
                </button>
                <a href='employee_dashboard.php' class='btn btn-secondary'>Back</a>
            </div>
            <p style='font-size:12px; color:#888;'>
                <strong>Save Progress</strong> keeps the visit open so you can finish it on another day.
                <strong>Submit &amp; Complete</strong> finalises the report and closes the visit.
            </p>

        </form>
        <?php endif; ?>

        <p style='margin-top:16px;'><a href='employee_dashboard.php'>&larr; My Visits</a></p>

<?php include 'incl/footer.php'; ?>
