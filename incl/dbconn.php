<?php
date_default_timezone_set('Africa/Johannesburg'); // UTC+2 — covers Eswatini & South Africa

// ── Session (server-side auth — cannot be forged in the browser) ──────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 604800);   // keep sessions ~7 days server-side
    session_set_cookie_params([
        'lifetime' => 0,            // browser-session cookie by default (login extends it)
        'path'     => '/',
        'httponly' => true,         // JS can't read the session cookie
        'samesite' => 'Lax',
    ]);
    session_start();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // DB errors throw, never silent
$conn = new mysqli("localhost", "root", "root", "m26");

function fmt_date($val): string {
    if (!$val) return '—';
    return date('d M Y', strtotime($val));
}

function fmt_datetime($val): string {
    if (!$val) return '—';
    return date('d M Y, H:i', strtotime($val));
}

// ── Authentication helpers (read the server-side session, not cookies) ────────
function deny(): void {
    echo "Unauthorised access! <a href='login.php'>Login</a>";
    exit;
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
 * Build a WHERE fragment that matches a client's sites against $col (a notes
 * column expression, e.g. 'notes' or 's.notes'). Returns:
 *   ['cond' => '(col LIKE ? OR col LIKE ? ...)', 'params' => ['%MTN%', ...]]
 * Bind the params as strings, in order.
 */
function client_notes_filter(string $stored, string $col = 'notes'): array {
    $parts = [];
    $params = [];
    foreach (client_match_keywords($stored) as $kw) {
        $parts[]  = "$col LIKE ?";
        $params[] = '%' . $kw . '%';
    }
    if (!$parts) { $parts[] = "$col LIKE ?"; $params[] = '%'; }
    return ['cond' => '(' . implode(' OR ', $parts) . ')', 'params' => $params];
}

// Count how many sites a stored company value currently matches
function client_site_count(mysqli $conn, string $stored): int {
    $f    = client_notes_filter($stored, 'notes');
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM sites WHERE " . $f['cond']);
    $stmt->bind_param(str_repeat('s', count($f['params'])), ...$f['params']);
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
