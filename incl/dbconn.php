<?php
require_once __DIR__ . '/config.php';

date_default_timezone_set('Africa/Johannesburg'); // UTC+2 — covers Eswatini & South Africa

// ── Error handling (driven by APP_ENV) ────────────────────────────────────────
error_reporting(E_ALL);
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');   // never leak stack traces / SQL to visitors
    ini_set('log_errors', '1');
    // Any uncaught error → friendly page + logged detail (no white screen / trace)
    set_exception_handler(function ($e) {
        error_log('M26 uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) http_response_code(500);
        if (function_exists('error_page')) {
            error_page('Something went wrong',
                'Sorry — an unexpected error occurred. Please try again, or contact M26 if it keeps happening.',
                'login.php', 'Back to login');
        } else {
            echo 'An unexpected error occurred. Please try again later.';
        }
        exit;
    });
} else {
    ini_set('display_errors', '1');   // local development: show errors on screen
}

// A few cheap, safe security headers on every page response
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

// ── Session (server-side auth — cannot be forged in the browser) ──────────────
if (session_status() === PHP_SESSION_NONE) {
    // HTTPS-only cookie when the request is actually served over HTTPS
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.use_strict_mode', '1');      // reject attacker-supplied session ids
    ini_set('session.gc_maxlifetime', 604800);    // keep sessions ~7 days server-side
    session_set_cookie_params([
        'lifetime' => 0,            // browser-session cookie by default (login extends it)
        'path'     => '/',
        'secure'   => $https,       // only sent over HTTPS in production
        'httponly' => true,         // JS can't read the session cookie
        'samesite' => 'Lax',
    ]);
    session_start();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // DB errors throw, never silent
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8');

function fmt_date($val): string {
    if (!$val) return '—';
    return date('d M Y', strtotime($val));
}

function fmt_datetime($val): string {
    if (!$val) return '—';
    return date('d M Y, H:i', strtotime($val));
}

// Everyday-language "time since" from a day count.
//   null → 'Never'   |   <14 → 'N days ago'   |   <60 → 'N weeks ago'   |   else 'N months ago'
function human_ago(?int $days): string {
    if ($days === null) return 'Never';
    if ($days < 1)   return 'Today';
    if ($days < 14)  return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    if ($days < 60)  return round($days / 7) . ' weeks ago';
    $m = (int) round($days / 30);
    return $m . ' month' . ($m === 1 ? '' : 's') . ' ago';
}

// ── Shared view helpers ───────────────────────────────────────────────────────
// Standard visit types (single source of truth for the create/edit dropdowns)
function visit_types(): array {
    return [
        'Maintenance',
        'Emergency Repair',
        'Installation',
        'Site Inspection',
        'Other',
    ];
}

// A coloured status badge for a visit status (assigned / in_progress / completed)
function status_badge(string $status): string {
    $cls   = $status === 'in_progress' ? 'in-progress' : $status;
    $label = str_replace('_', ' ', $status);
    return "<span class='badge badge-" . htmlspecialchars($cls) . "'>" . htmlspecialchars($label) . "</span>";
}

// Where a visit came from. A tech who self-selects a site is BOTH the creator
// and the assignee; office-assigned visits always have a different creator.
function visit_origin_badge(int $created_by, int $assigned_to): string {
    if ($created_by === $assigned_to) {
        return "<span class='badge badge-attention' title='The technician picked this site themselves'>Self-selected by tech</span>";
    }
    return "<span class='badge badge-assigned' title='Scheduled by the office'>Assigned by office</span>";
}

// Searchable site picker (a type-to-filter combobox) — far friendlier than a
// native <select> with hundreds of sites. Renders a text input backed by a
// <datalist> plus a hidden `site_id` field that JS keeps in sync. Lets the user
// find a site by CODE or name. Pass $selected_id to pre-fill (edit/preselect).
function site_picker_field(mysqli $conn, int $selected_id = 0): string {
    $res  = $conn->query("SELECT site_id, site_code, site_name FROM sites ORDER BY site_code");
    $opts = '';
    $map  = [];
    $selected_label = '';
    while ($s = $res->fetch_assoc()) {
        $label = $s['site_code'] . ' — ' . $s['site_name'];
        $opts .= "<option value=\"" . htmlspecialchars($label) . "\"></option>";
        $map[$label] = (int) $s['site_id'];
        if ((int) $s['site_id'] === $selected_id) $selected_label = $label;
    }
    $json = htmlspecialchars(json_encode($map, JSON_UNESCAPED_UNICODE), ENT_QUOTES);

    $h  = "<input type='text' class='site-picker-input' id='site-picker-input'
                  list='site-picker-list' autocomplete='off'
                  placeholder='Type a site code or name…'
                  value=\"" . htmlspecialchars($selected_label) . "\">";
    $h .= "<datalist id='site-picker-list'>$opts</datalist>";
    $h .= "<input type='hidden' name='site_id' id='site-picker-id' value='" . ($selected_id ?: '') . "'>";
    $h .= "<div class='site-picker-hint' id='site-picker-hint'>Pick a site from the list.</div>";
    $h .= "<script>(function(){
        var map = $json;
        var input = document.getElementById('site-picker-input');
        var hidden = document.getElementById('site-picker-id');
        var hint = document.getElementById('site-picker-hint');
        function sync(){
            var v = (input.value || '').trim();
            if (Object.prototype.hasOwnProperty.call(map, v)) { hidden.value = map[v]; hint.style.display='none'; }
            else { hidden.value = ''; }
        }
        input.addEventListener('input', sync);
        input.addEventListener('change', sync);
        var form = input.closest('form');
        if (form) form.addEventListener('submit', function(e){
            sync();
            if (!hidden.value){ hint.style.display='block'; input.focus(); e.preventDefault(); }
        });
    })();</script>";
    return $h;
}

// A friendly "nothing here yet" block with an optional call-to-action button,
// so a lost user always sees what to do next.
function empty_state(string $title, string $subtitle = '', string $cta_href = '', string $cta_label = ''): string {
    $h  = "<div class='empty-state'>";
    $h .= "<p class='empty-state-title'>" . htmlspecialchars($title) . "</p>";
    if ($subtitle !== '') $h .= "<p class='empty-state-sub'>" . htmlspecialchars($subtitle) . "</p>";
    if ($cta_href !== '' && $cta_label !== '') {
        $h .= "<a href='" . htmlspecialchars($cta_href) . "' class='btn btn-primary'>" . htmlspecialchars($cta_label) . "</a>";
    }
    $h .= "</div>";
    return $h;
}

// A self-contained, styled message page (used for access-denied / not-found),
// so users never see bare unstyled error text.
function error_page(string $heading, string $message, string $href = 'login.php', string $label = 'Go to login'): void {
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'>"
       . "<meta name='viewport' content='width=device-width, initial-scale=1.0'>"
       . "<title>M26</title><link rel='stylesheet' href='css/styles.css?v=18'></head>"
       . "<body><div class='login-page'><div class='login-card' style='text-align:center;'>"
       . "<img src='images/m26.png' alt='M26' class='login-logo'>"
       . "<h1 style='font-size:19px;color:#1a3a5c;margin:6px 0 6px;'>" . htmlspecialchars($heading) . "</h1>"
       . "<p style='color:#666;font-size:14px;margin:0 0 18px;line-height:1.5;'>" . htmlspecialchars($message) . "</p>"
       . "<a href='" . htmlspecialchars($href) . "' class='btn btn-primary btn-full'>" . htmlspecialchars($label) . "</a>"
       . "</div></div></body></html>";
    exit;
}

// ── One-time "flash" success messages (survive a redirect) ────────────────────
function flash(string $msg): void { $_SESSION['flash'] = $msg; }
function flash_get(): string {
    $m = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    return $m;
}

// ── Login throttling (brute-force protection) ─────────────────────────────────
const LOGIN_MAX_FAILS   = 10;   // failures allowed per IP …
const LOGIN_WINDOW_MINS = 15;   // … within this many minutes, then it's blocked

function login_is_blocked(mysqli $conn, string $ip): bool {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL " . LOGIN_WINDOW_MINS . " MINUTE)");
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $n = (int) $stmt->get_result()->fetch_assoc()['n'];
        $stmt->close();
        return $n >= LOGIN_MAX_FAILS;
    } catch (Throwable $e) {
        return false;   // never let throttling itself break login
    }
}

function login_record_failure(mysqli $conn, string $ip, string $username): void {
    try {
        $stmt = $conn->prepare("INSERT INTO login_attempts (ip, username) VALUES (?, ?)");
        $stmt->bind_param('ss', $ip, $username);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) { /* ignore */ }
}

function login_clear_failures(mysqli $conn, string $ip): void {
    try {
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE ip = ?");
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) { /* ignore */ }
}

// ── Authentication helpers (read the server-side session, not cookies) ────────
function deny(): void {
    error_page(
        'Access denied',
        "You don't have permission to view this page, or your session has expired. Please log in again.",
        'login.php',
        'Go to login'
    );
}

// Pages accessible by admin and supervisor
function require_staff(): void {
    if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'supervisor'], true)) deny();
}

// Pages restricted to admin only
function require_admin(): void {
    if (($_SESSION['user_role'] ?? '') !== 'admin') deny();
}

// Pages restricted to field technicians (employees)
function require_employee(): void {
    if (($_SESSION['user_role'] ?? '') !== 'employee') deny();
}

// Pages restricted to client (operator) portal logins
function require_client(): void {
    if (($_SESSION['user_role'] ?? '') !== 'client') deny();
}

function is_admin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

// The client a logged-in client user belongs to (0 if not a client)
function current_user_client_id(): int {
    return (int)($_SESSION['client_id'] ?? 0);
}

// ── Client → company (operator) matching ──────────────────────────────────────
// Companies map a single dropdown choice to all of its note spellings.
function client_companies(): array {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/client_companies.php';
    return $c;
}

// Human label for a stored company key (falls back to the raw value)
function client_company_label(string $stored): string {
    $c = client_companies();
    return $c[$stored]['label'] ?? $stored;
}

// The note substrings to match for a stored value. Known keys expand to their
// list; an unknown (legacy) value is treated as a single literal keyword.
function client_match_keywords(string $stored): array {
    $c = client_companies();
    if (isset($c[$stored])) return $c[$stored]['match'];
    return [$stored];
}

// Best-effort: resolve a stored value to a company key (for pre-selecting the
// dropdown when editing). Returns '' if it can't be matched.
function client_resolve_company_key(string $stored): string {
    $c = client_companies();
    if (isset($c[$stored])) return $stored;
    foreach ($c as $key => $def) {
        foreach ($def['match'] as $kw) {
            if (strcasecmp($kw, $stored) === 0) return $key;
        }
    }
    return '';
}

/*
 * Build a WHERE fragment scoping to a client's sites via the normalized
 * sites.operator column (an exact, indexed match — no fragile text matching).
 * $alias is the table alias used in the query ('s' for "FROM sites s", or ''
 * for "FROM sites"). Returns ['cond' => 'operator = ?', 'params' => ['mtn']].
 */
function client_site_filter(string $key, string $alias = 's'): array {
    $col = $alias !== '' ? "$alias.operator" : 'operator';
    return ['cond' => "$col = ?", 'params' => [$key]];
}

// Count how many sites are tagged to a client's operator
function client_site_count(mysqli $conn, string $key): int {
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM sites WHERE operator = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n;
}

/*
 * Load the logged-in client's row (id, name, match_keyword), or null.
 * Used by the read-only client portal to scope every query to that client's
 * sites via client_notes_filter().
 */
function current_client(mysqli $conn): ?array {
    $cid = current_user_client_id();
    if ($cid <= 0) return null;
    $stmt = $conn->prepare("SELECT client_id, name, match_keyword FROM clients WHERE client_id = ? AND status = 'active'");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Logged-in user helpers — single source of truth for identity
function current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function current_user_name(): string {
    return $_SESSION['user_name'] ?? '';
}

function current_user_role(): string {
    return $_SESSION['user_role'] ?? '';
}

// ── CSRF protection ───────────────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

// Hidden field to drop inside every POST <form>
function csrf_field(): string {
    return "<input type='hidden' name='csrf' value='" . csrf_token() . "'>";
}

// Verify the token on a POST request; abort if missing/wrong
function csrf_check(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
            http_response_code(403);
            echo "Invalid or expired form submission. <a href='javascript:history.back()'>Go back</a> and try again.";
            exit;
        }
    }
}
