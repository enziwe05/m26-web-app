<?php
/*
 * One-off / re-runnable importer for the Eswatini Mobile LTE export.
 *
 *   php database/import_lte.php "C:\path\to\ESM Sites LTE_EP_20250611.xlsx"
 *
 * Does two things:
 *   1. Refreshes the sites table — region + GPS from the export, matched by code,
 *      inserting any sites that don't exist yet.
 *   2. Loads the per-sector antenna configuration into the site_cells table
 *      (azimuth / tilt / height / band / status), as on-site reference data.
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$file = $argv[1] ?? '';
if (!is_file($file)) { fwrite(STDERR, "File not found: $file\n"); exit(1); }

$conn = new mysqli('localhost', 'root', 'root', 'm26');

// ── Read the xlsx (ZipArchive + SimpleXML are built into PHP) ─────────────────
$zip = new ZipArchive;
$zip->open($file);
$ss = simplexml_load_string($zip->getFromName('xl/sharedStrings.xml'));
$strings = [];
foreach ($ss->si as $si) { $strings[] = (string) strip_tags($si->asXML()); }
$sheet = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
$zip->close();

$colLetter = fn($ref) => preg_replace('/[0-9]/', '', $ref);
$clean_coord = function ($v) {
    $v = trim(str_replace(',', '.', $v));
    return preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $v) ? round((float)$v, 6) : null;
};

$sites = [];   // code => [name, region, lat, lng, city]
$cells = [];   // each sector/cell row
$rownum = 0;
foreach ($sheet->sheetData->row as $row) {
    if (++$rownum === 1) continue; // header
    $c = [];
    foreach ($row->c as $cell) {
        $v = (string) $cell->v;
        if ((string) $cell['t'] === 's') $v = $strings[(int)$v] ?? '';
        $c[$colLetter((string) $cell['r'])] = $v;
    }
    $ne = trim($c['A'] ?? '');
    if ($ne === '') continue;
    $code = preg_split('/[_ ]/', $ne)[0];          // M001_Switch_Room -> M001
    if (!preg_match('/^M\d+$/i', $code)) continue;  // skip anything not an M-code

    $region = trim($c['AC'] ?? '');
    if ($region === '#N/A') $region = '';
    $lat = $clean_coord($c['U'] ?? '');
    $lng = $clean_coord($c['V'] ?? '');
    $city = trim($c['AD'] ?? '');

    if (!isset($sites[$code])) {
        $name = trim(str_replace('_', ' ', (string) strstr($ne, '_'))); // text after first _
        if ($name === '') $name = $code;
        $sites[$code] = ['name' => $name, 'region' => $region, 'lat' => $lat, 'lng' => $lng, 'city' => $city];
    } else {
        // fill any gaps from later rows of the same site
        if ($sites[$code]['region'] === '' && $region !== '') $sites[$code]['region'] = $region;
        if ($sites[$code]['lat'] === null && $lat !== null)    $sites[$code]['lat'] = $lat;
        if ($sites[$code]['lng'] === null && $lng !== null)    $sites[$code]['lng'] = $lng;
        if ($sites[$code]['city'] === '' && $city !== '')      $sites[$code]['city'] = $city;
    }

    $cells[] = [
        'code'   => $code,
        'cell'   => trim($c['E'] ?? ''),
        'sector' => trim($c['R'] ?? ''),
        'az'     => trim($c['S'] ?? ''),
        'mtilt'  => trim($c['P'] ?? ''),
        'etilt'  => trim($c['Q'] ?? ''),
        'height' => trim($c['W'] ?? ''),
        'tower'  => trim($c['X'] ?? ''),
        'band'   => trim($c['T'] ?? ''),
        'cstat'  => trim($c['AI'] ?? ''),
        'audit'  => trim($c['AH'] ?? ''),
    ];
}

echo "Parsed " . count($sites) . " sites, " . count($cells) . " cells.\n";

// ── 1. Refresh / insert sites ─────────────────────────────────────────────────
$find = $conn->prepare("SELECT site_id FROM sites WHERE site_code = ?");
$upd  = $conn->prepare("UPDATE sites SET region = COALESCE(?, region), latitude = COALESCE(?, latitude), longitude = COALESCE(?, longitude) WHERE site_id = ?");
$ins  = $conn->prepare("INSERT INTO sites (site_code, site_name, location, latitude, longitude, region) VALUES (?,?,?,?,?,?)");

$site_id = [];
$updated = 0; $inserted = 0;
foreach ($sites as $code => $s) {
    $find->bind_param('s', $code);
    $find->execute();
    $r = $find->get_result()->fetch_assoc();
    $region = $s['region'] !== '' ? $s['region'] : null;
    if ($r) {
        $id = (int) $r['site_id'];
        $upd->bind_param('sddi', $region, $s['lat'], $s['lng'], $id);
        $upd->execute();
        $updated++;
    } else {
        $loc = $s['city'] !== '' ? $s['city'] : null;
        $ins->bind_param('sssdds', $code, $s['name'], $loc, $s['lat'], $s['lng'], $region);
        $ins->execute();
        $id = $conn->insert_id;
        $inserted++;
    }
    $site_id[$code] = $id;
}
echo "Sites: $updated updated, $inserted inserted.\n";

// ── 2. Load antenna/cell reference data ───────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS site_cells (
        cell_id        INT AUTO_INCREMENT PRIMARY KEY,
        site_id        INT NOT NULL,
        cell_name      VARCHAR(40),
        sector         VARCHAR(10),
        azimuth        VARCHAR(20),
        mech_tilt      VARCHAR(20),
        e_tilt         VARCHAR(20),
        antenna_height VARCHAR(20),
        tower_height   VARCHAR(20),
        frequency_band VARCHAR(30),
        cell_status    VARCHAR(30),
        audit_status   VARCHAR(30),
        CONSTRAINT fk_cell_site FOREIGN KEY (site_id) REFERENCES sites(site_id) ON DELETE CASCADE,
        INDEX ix_cells_site (site_id)
    ) ENGINE=InnoDB
");
$conn->query("DELETE FROM site_cells");   // re-runnable

$cins = $conn->prepare("INSERT INTO site_cells
    (site_id, cell_name, sector, azimuth, mech_tilt, e_tilt, antenna_height, tower_height, frequency_band, cell_status, audit_status)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)");
$loaded = 0;
foreach ($cells as $cl) {
    if (!isset($site_id[$cl['code']])) continue;
    $sid = $site_id[$cl['code']];
    $cins->bind_param('issssssssss', $sid, $cl['cell'], $cl['sector'], $cl['az'], $cl['mtilt'],
        $cl['etilt'], $cl['height'], $cl['tower'], $cl['band'], $cl['cstat'], $cl['audit']);
    $cins->execute();
    $loaded++;
}
echo "Antenna cells loaded: $loaded.\n";
echo "Done.\n";
