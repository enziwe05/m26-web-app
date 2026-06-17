<?php
require_once 'incl/dbconn.php';
require_staff();

if (!isset($_GET['visit_id'])) {
    header('Location: view_visits.php');
    exit;
}

$visit_id = (int)$_GET['visit_id'];

// ── Load visit ─────────────────────────────────────────────────────────────────
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

// ── Check for existing maintenance form ────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM maintenance_forms WHERE visit_id = ?");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$mform = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Section definitions (mirrors employee_visit.php) ──────────────────────────
$form_sections = require __DIR__ . "/incl/form_sections.php";

// ── Decode saved JSON ──────────────────────────────────────────────────────────
$decoded = [];
if ($mform) {
    foreach (array_keys($form_sections) as $sec) {
        $col          = $sec . '_json';
        $decoded[$sec] = ($mform[$col] ?? '') ? json_decode($mform[$col], true) : [];
    }
}

// ── Handle admin POST (fill on behalf) ────────────────────────────────────────
$save_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_save'])) {
    csrf_check();

    $maintenance_type = in_array($_POST['maintenance_type'] ?? '', ['active', 'passive'])
        ? $_POST['maintenance_type']
        : ($visit['maintenance_type'] ?? 'active');

    $access_ref   = substr(trim($_POST['access_ref']   ?? ''), 0, 50);
    $capture_lat  = substr(trim($_POST['capture_lat']  ?? ''), 0, 30);
    $capture_lon  = substr(trim($_POST['capture_lon']  ?? ''), 0, 30);
    $capture_time = substr(trim($_POST['capture_time'] ?? ''), 0, 20);

    $a = [];
    foreach (['s1', 's2', 's3'] as $s) {
        foreach (['azimuth', 'etilt', 'mtilt', 'height'] as $f) {
            $a[$s . '_' . $f] = substr(trim($_POST['ant_' . $s . '_' . $f] ?? ''), 0, 30);
        }
    }
    $gps_lat          = substr(trim($_POST['gps_lat'] ?? ''), 0, 30);
    $gps_lon          = substr(trim($_POST['gps_lon'] ?? ''), 0, 30);
    $general_comments = substr(trim($_POST['general_comments'] ?? ''), 0, 5000);

    $valid_statuses = ['OK', 'Faulty', 'N/A'];
    $json = [];
    foreach (array_keys($form_sections) as $sec) {
        $sec_data  = is_array($_POST[$sec] ?? null) ? $_POST[$sec] : [];
        $json[$sec] = [];
        foreach ($sec_data as $raw_field => $vals) {
            $field = preg_replace('/[^a-z0-9_]/', '', $raw_field);
            if ($field === '') continue;
            $st = in_array($vals['status'] ?? '', $valid_statuses) ? $vals['status'] : 'N/A';
            $json[$sec][$field] = [
                'status'  => $st,
                'remarks' => substr(trim($vals['remarks'] ?? ''), 0, 500),
            ];
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO maintenance_forms
            (visit_id, access_ref, capture_lat, capture_lon, capture_time,
             ant_s1_azimuth, ant_s1_etilt, ant_s1_mtilt, ant_s1_height,
             ant_s2_azimuth, ant_s2_etilt, ant_s2_mtilt, ant_s2_height,
             ant_s3_azimuth, ant_s3_etilt, ant_s3_mtilt, ant_s3_height,
             gps_lat, gps_lon,
             equipment_json, generator_json, transmission_json,
             security_json, container_json, electrical_json,
             general_comments, is_submitted, submitted_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            access_ref=VALUES(access_ref), capture_lat=VALUES(capture_lat),
            capture_lon=VALUES(capture_lon), capture_time=VALUES(capture_time),
            ant_s1_azimuth=VALUES(ant_s1_azimuth), ant_s1_etilt=VALUES(ant_s1_etilt),
            ant_s1_mtilt=VALUES(ant_s1_mtilt),     ant_s1_height=VALUES(ant_s1_height),
            ant_s2_azimuth=VALUES(ant_s2_azimuth), ant_s2_etilt=VALUES(ant_s2_etilt),
            ant_s2_mtilt=VALUES(ant_s2_mtilt),     ant_s2_height=VALUES(ant_s2_height),
            ant_s3_azimuth=VALUES(ant_s3_azimuth), ant_s3_etilt=VALUES(ant_s3_etilt),
            ant_s3_mtilt=VALUES(ant_s3_mtilt),     ant_s3_height=VALUES(ant_s3_height),
            gps_lat=VALUES(gps_lat), gps_lon=VALUES(gps_lon),
            equipment_json=VALUES(equipment_json), generator_json=VALUES(generator_json),
            transmission_json=VALUES(transmission_json), security_json=VALUES(security_json),
            container_json=VALUES(container_json), electrical_json=VALUES(electrical_json),
            general_comments=VALUES(general_comments),
            is_submitted=VALUES(is_submitted), submitted_at=VALUES(submitted_at), updated_at=VALUES(updated_at)
    ");
    // json_encode() results must be variables — bind_param takes them by reference
    $equipment_json    = json_encode($json['equipment']);
    $generator_json    = json_encode($json['generator']);
    $transmission_json = json_encode($json['transmission']);
    $security_json     = json_encode($json['security']);
    $container_json    = json_encode($json['container']);
    $electrical_json   = json_encode($json['electrical']);

    // Admin "fill on behalf" counts as a final submission
    $now_dt       = date('Y-m-d H:i:s');
    $is_submitted = 1;
    $submitted_at = $now_dt;
    $updated_at   = $now_dt;

    $types = 'i' . str_repeat('s', 25) . 'iss';   // visit_id + 25 strings + is_submitted, submitted_at, updated_at
    $stmt->bind_param($types,
        $visit_id,
        $access_ref, $capture_lat, $capture_lon, $capture_time,
        $a['s1_azimuth'], $a['s1_etilt'], $a['s1_mtilt'], $a['s1_height'],
        $a['s2_azimuth'], $a['s2_etilt'], $a['s2_mtilt'], $a['s2_height'],
        $a['s3_azimuth'], $a['s3_etilt'], $a['s3_mtilt'], $a['s3_height'],
        $gps_lat, $gps_lon,
        $equipment_json, $generator_json, $transmission_json,
        $security_json, $container_json, $electrical_json,
        $general_comments, $is_submitted, $submitted_at, $updated_at
    );
    $stmt->execute();
    $stmt->close();

    // Save maintenance type, and mark visit completed if it wasn't already
    if ($visit['status'] !== 'completed') {
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("UPDATE visits SET status='completed', completed_at=?, maintenance_type=? WHERE visit_id=?");
        $stmt->bind_param('ssi', $now, $maintenance_type, $visit_id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("UPDATE visits SET maintenance_type=? WHERE visit_id=?");
        $stmt->bind_param('si', $maintenance_type, $visit_id);
        $stmt->execute();
        $stmt->close();
    }

    header('Location: maintenance_form.php?visit_id=' . $visit_id . '&saved=1');
    exit;
}

// ── Load site photos for this visit ───────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT photo_filename, photo_type, caption, uploaded_at
    FROM visit_photos
    WHERE visit_id = ? AND photo_type = 'other'
    ORDER BY uploaded_at
");
$stmt->bind_param('i', $visit_id);
$stmt->execute();
$site_photos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Show read-only unless an admin explicitly asked to edit (?edit=1)
$edit_mode    = isset($_GET['edit']) && is_admin();
$is_read_only = ($mform !== null) && !$edit_mode;
$page_title = 'M26 | Maintenance Form — ' . $visit['site_code'];
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Maintenance Form</h1>
            <div style='display:flex; gap:8px; align-items:center;'>
<?php echo status_badge($visit['status']); ?>
                <a href='report_print.php?visit_id=<?php echo $visit_id; ?>' target='_blank' class='btn btn-secondary' style='font-size:13px; padding:5px 14px;'>
                    Save as PDF
                </a>
                <a href='visit_detail.php?visit_id=<?php echo $visit_id; ?>' class='btn btn-secondary' style='font-size:13px; padding:5px 14px;'>
                    &larr; Visit
                </a>
            </div>
        </div>

        <?php if (isset($_GET['saved'])): ?>
        <div class='alert alert-success' style='background:#d1fae5; border:1px solid #6ee7b7; color:#065f46; padding:12px 16px; border-radius:6px; margin-bottom:16px;'>
            Maintenance form saved successfully.
        </div>
        <?php endif; ?>

        <!-- Visit summary card -->
        <div class='card' style='margin-bottom:20px; font-size:13px;'>
            <div style='display:grid; grid-template-columns:1fr 1fr; gap:6px;'>
                <p style='margin:0;'><strong>Site:</strong> <?php echo htmlspecialchars($visit['site_code'] . ' — ' . $visit['site_name']); ?></p>
                <p style='margin:0;'><strong>Visit Type:</strong> <?php echo htmlspecialchars($visit['visit_type']); ?></p>
                <p style='margin:0;'><strong>Technician:</strong> <?php echo htmlspecialchars($visit['tech_first'] . ' ' . $visit['tech_last']); ?></p>
                <p style='margin:0;'><strong>Scheduled:</strong> <?php echo fmt_date($visit['scheduled_date']); ?></p>
            </div>
        </div>

        <?php if ($is_read_only): ?>
        <!-- ═══════════════════════════════════════════════════════════
             READ-ONLY VIEW — form was already submitted
             ═══════════════════════════════════════════════════════════ -->

        <div class='info-banner'>
            <?php if (!empty($mform['is_submitted'])): ?>
                ✔ Form submitted on <strong><?php echo htmlspecialchars(fmt_datetime($mform['submitted_at'])); ?></strong>
            <?php else: ?>
                &#9998; Draft in progress — last saved by the technician on
                <strong><?php echo htmlspecialchars(fmt_datetime($mform['updated_at'] ?? $mform['submitted_at'])); ?></strong>
            <?php endif; ?>
            <?php if (is_admin()): ?>
                &nbsp;|&nbsp; <a href='maintenance_form.php?visit_id=<?php echo $visit_id; ?>&edit=1'>Edit Form</a>
            <?php endif; ?>
        </div>

        <!-- General capture info -->
        <div class='mf-section card'>
            <h2>General Information</h2>
            <table class='mf-table'>
                <tr><th style='width:220px;'>Field</th><th>Value</th></tr>
                <tr><td>Site</td><td><?php echo htmlspecialchars($visit['site_code'] . ' — ' . $visit['site_name']); ?></td></tr>
                <tr><td>Visit Type</td><td><?php echo htmlspecialchars($visit['visit_type']); ?></td></tr>
                <tr><td>Maintenance Type</td><td><?php echo ucfirst(htmlspecialchars($visit['maintenance_type'] ?? 'active')); ?> Maintenance</td></tr>
                <tr><td>Technician</td><td><?php echo htmlspecialchars($visit['tech_first'] . ' ' . $visit['tech_last']); ?></td></tr>
                <tr><td>Maintenance Date</td><td><?php echo htmlspecialchars($visit['scheduled_date'] ?? '—'); ?></td></tr>
                <tr><td>Access Reference</td><td><?php echo htmlspecialchars($mform['access_ref'] ?? '—'); ?></td></tr>
                <tr><td>Latitude (on-site)</td><td><?php echo htmlspecialchars($mform['capture_lat'] ?? '—'); ?></td></tr>
                <tr><td>Longitude (on-site)</td><td><?php echo htmlspecialchars($mform['capture_lon'] ?? '—'); ?></td></tr>
                <tr><td>Data Capture Time</td><td><?php echo htmlspecialchars($mform['capture_time'] ?? '—'); ?></td></tr>
            </table>
        </div>

        <!-- Site photos -->
        <?php if (!empty($site_photos)): ?>
        <div class='mf-section card'>
            <h2>Site Photos</h2>
            <div class='photo-grid'>
                <?php foreach ($site_photos as $ph): ?>
                <div class='photo-thumb'>
                    <img src='uploads/<?php echo htmlspecialchars($ph['photo_filename']); ?>'
                         alt='<?php echo htmlspecialchars($ph['caption']); ?>'>
                    <?php if ($ph['caption']): ?>
                    <div class='pt-cap'><?php echo htmlspecialchars($ph['caption']); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Inspection sections -->
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

        <!-- Antenna -->
        <div class='mf-section card'>
            <h2>Antenna Information</h2>
            <div class='ant-grid'>
                <div class='ag-header'>Sector</div>
                <div class='ag-header'>Azimuth</div>
                <div class='ag-header'>E-Tilt</div>
                <div class='ag-header'>M-Tilt</div>
                <div class='ag-header'>Antenna Height</div>
                <?php for ($s = 1; $s <= 3; $s++): ?>
                <div class='ag-cell'><strong>Sector <?php echo $s; ?></strong></div>
                <div class='ag-cell'><?php echo htmlspecialchars($mform['ant_s'.$s.'_azimuth'] ?? '—'); ?></div>
                <div class='ag-cell'><?php echo htmlspecialchars($mform['ant_s'.$s.'_etilt']   ?? '—'); ?></div>
                <div class='ag-cell'><?php echo htmlspecialchars($mform['ant_s'.$s.'_mtilt']   ?? '—'); ?></div>
                <div class='ag-cell'><?php echo htmlspecialchars($mform['ant_s'.$s.'_height']  ?? '—'); ?></div>
                <?php endfor; ?>
                <div class='ag-cell'><strong>GPS</strong></div>
                <div class='ag-cell' colspan='2'>Lat: <?php echo htmlspecialchars($mform['gps_lat'] ?? '—'); ?></div>
                <div class='ag-cell' colspan='2'>Lon: <?php echo htmlspecialchars($mform['gps_lon'] ?? '—'); ?></div>
            </div>
        </div>

        <!-- Comments -->
        <?php if (!empty($mform['general_comments'])): ?>
        <div class='mf-section card'>
            <h2>Final Comments</h2>
            <p style='font-size:13px; line-height:1.6; white-space:pre-wrap;'><?php echo htmlspecialchars($mform['general_comments']); ?></p>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- ═══════════════════════════════════════════════════════════
             EDITABLE FORM — no form submitted yet (or admin edit mode)
             ═══════════════════════════════════════════════════════════ -->

        <form method='POST' action='maintenance_form.php?visit_id=<?php echo $visit_id; ?>' enctype='multipart/form-data'>
        <?php echo csrf_field(); ?>
        <input type='hidden' name='admin_save' value='1'>

            <!-- ── General Information ── -->
            <div class='mf-section card'>
                <h2>General Information</h2>
                <table class='mf-table'>
                    <tr><th style='width:220px;'>Field</th><th>Value</th></tr>
                    <tr><td>Site</td><td><?php echo htmlspecialchars($visit['site_code'] . ' — ' . $visit['site_name']); ?></td></tr>
                    <tr><td>Visit Type</td><td><?php echo htmlspecialchars($visit['visit_type']); ?></td></tr>
                    <tr>
                        <td>Maintenance Type</td>
                        <td>
                            <?php $mt = $visit['maintenance_type'] ?? 'active'; ?>
                            <label style='display:inline-flex; align-items:center; gap:6px; margin-right:20px; cursor:pointer;'>
                                <input type='radio' name='maintenance_type' value='active' <?php echo $mt == 'active' ? 'checked' : ''; ?>> Active
                            </label>
                            <label style='display:inline-flex; align-items:center; gap:6px; cursor:pointer;'>
                                <input type='radio' name='maintenance_type' value='passive' <?php echo $mt == 'passive' ? 'checked' : ''; ?>> Passive
                            </label>
                        </td>
                    </tr>
                    <tr><td>Technician</td>
                        <td><?php echo htmlspecialchars($visit['tech_first'] . ' ' . $visit['tech_last']); ?></td></tr>
                    <tr><td>Maintenance Date</td>
                        <td><?php echo htmlspecialchars($visit['scheduled_date'] ?? date('Y-m-d')); ?></td></tr>
                    <tr>
                        <td>Access Reference</td>
                        <td><input type='text' name='access_ref' placeholder='e.g. None / Noted'
                                   value='<?php echo htmlspecialchars($mform['access_ref'] ?? ''); ?>'></td>
                    </tr>
                    <tr>
                        <td>Latitude (on-site)</td>
                        <td><input type='text' name='capture_lat' placeholder='e.g. -26.3259'
                                   value='<?php echo htmlspecialchars($mform['capture_lat'] ?? ''); ?>'></td>
                    </tr>
                    <tr>
                        <td>Longitude (on-site)</td>
                        <td><input type='text' name='capture_lon' placeholder='e.g. 31.1436'
                                   value='<?php echo htmlspecialchars($mform['capture_lon'] ?? ''); ?>'></td>
                    </tr>
                    <tr>
                        <td>Data Capture Time</td>
                        <td><input type='text' name='capture_time'
                                   value='<?php echo htmlspecialchars($mform['capture_time'] ?? date("H:i")); ?>'></td>
                    </tr>
                </table>
            </div>

            <!-- ── Site Photos ── -->
            <div class='mf-section card'>
                <h2>Site Photos</h2>
                <?php if (!empty($site_photos)): ?>
                <p style='font-size:12px; color:#666; margin-bottom:8px;'>
                    <?php echo count($site_photos); ?> photo(s) already uploaded. Upload below to add more.
                </p>
                <div class='photo-grid' style='margin-bottom:12px;'>
                    <?php foreach ($site_photos as $ph): ?>
                    <div class='photo-thumb'>
                        <img src='uploads/<?php echo htmlspecialchars($ph['photo_filename']); ?>'
                             alt='<?php echo htmlspecialchars($ph['caption']); ?>'>
                        <?php if ($ph['caption']): ?>
                        <div class='pt-cap'><?php echo htmlspecialchars($ph['caption']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class='upload-row'>
                    <?php for ($p = 1; $p <= 4; $p++): ?>
                    <div class='upload-box'>
                        <label>Status Photo <?php echo $p; ?></label>
                        <input type='file' name='photo_<?php echo $p; ?>' accept='image/*'
                               style='display:block; font-size:12px; margin-bottom:6px;'>
                        <input type='text' name='photo_caption_<?php echo $p; ?>'
                               placeholder='Caption'
                               style='width:100%; padding:4px 6px; border:1px solid #ced4da; border-radius:4px; font-size:12px;'>
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
                        <th style='width:130px;'>Status</th>
                        <th>Remarks</th>
                    </tr>
                    <?php foreach ($sec['fields'] as $fk => $fl):
                        $saved_status  = $decoded[$sec_key][$fk]['status']  ?? 'N/A';
                        $saved_remarks = $decoded[$sec_key][$fk]['remarks'] ?? '';
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($fl); ?></td>
                        <td>
                            <select name='<?php echo $sec_key; ?>[<?php echo $fk; ?>][status]'>
                                <?php foreach (['N/A', 'OK', 'Faulty'] as $opt): ?>
                                <option value='<?php echo $opt; ?>'<?php echo $saved_status === $opt ? ' selected' : ''; ?>>
                                    <?php echo $opt; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type='text'
                                   name='<?php echo $sec_key; ?>[<?php echo $fk; ?>][remarks]'
                                   placeholder='Remarks'
                                   value='<?php echo htmlspecialchars($saved_remarks); ?>'>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endforeach; ?>

            <!-- ── Antenna Information ── -->
            <div class='mf-section card'>
                <h2>Antenna Information</h2>
                <div class='ant-grid'>
                    <div class='ag-header'>Sector</div>
                    <div class='ag-header'>Azimuth</div>
                    <div class='ag-header'>E-Tilt</div>
                    <div class='ag-header'>M-Tilt</div>
                    <div class='ag-header'>Antenna Height</div>
                    <?php for ($s = 1; $s <= 3; $s++): ?>
                    <div class='ag-cell'><strong>Sector <?php echo $s; ?></strong></div>
                    <?php foreach (['azimuth'=>'e.g. 120°','etilt'=>'e.g. 4','mtilt'=>'e.g. 2','height'=>'e.g. 30m'] as $fld => $ph): ?>
                    <div class='ag-cell'>
                        <input type='text' name='ant_s<?php echo $s; ?>_<?php echo $fld; ?>'
                               placeholder='<?php echo $ph; ?>'
                               value='<?php echo htmlspecialchars($mform['ant_s'.$s.'_'.$fld] ?? ''); ?>'>
                    </div>
                    <?php endforeach; ?>
                    <?php endfor; ?>
                    <div class='ag-cell'><strong>GPS Lat</strong></div>
                    <div class='ag-cell' style='grid-column:span 2'>
                        <input type='text' name='gps_lat' placeholder='e.g. -26.3259'
                               value='<?php echo htmlspecialchars($mform['gps_lat'] ?? ''); ?>'>
                    </div>
                    <div class='ag-cell'><strong>GPS Lon</strong></div>
                    <div class='ag-cell'>
                        <input type='text' name='gps_lon' placeholder='e.g. 31.1436'
                               value='<?php echo htmlspecialchars($mform['gps_lon'] ?? ''); ?>'>
                    </div>
                </div>
            </div>

            <!-- ── Final Comments ── -->
            <div class='mf-section card'>
                <h2>Final Comments</h2>
                <textarea name='general_comments' rows='5'
                          placeholder='Overall site condition, actions taken, pending issues...'
                          style='width:100%; padding:8px; border:1px solid #ced4da; border-radius:4px; font-size:13px; resize:vertical;'><?php echo htmlspecialchars($mform['general_comments'] ?? ''); ?></textarea>
            </div>

            <!-- Submit -->
            <div style='margin:20px 0 40px;'>
                <button type='submit' class='btn btn-primary'>Save Maintenance Form</button>
                &nbsp;
                <a href='visit_detail.php?visit_id=<?php echo $visit_id; ?>' class='btn btn-secondary'>Cancel</a>
            </div>

        </form>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
