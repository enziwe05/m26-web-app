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

function is_admin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'admin';
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
