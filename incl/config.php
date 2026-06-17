<?php
/*
 * Application configuration.
 *
 * The defaults below are the LIVE-SERVER credentials, so the app works on the
 * production host with no extra setup.
 *
 * For LOCAL development, create incl/config.local.php (copy config.local.example.php)
 * with your local DB settings and 'app_env' => 'local'. That file overrides the
 * defaults here and is git-ignored, so it never ships in the deploy zip.
 */

$override = __DIR__ . '/config.local.php';
$cfg = file_exists($override) ? (require $override) : [];

$pick = function (string $key, string $env, string $default) use ($cfg) {
    if (array_key_exists($key, $cfg) && $cfg[$key] !== '') return (string) $cfg[$key];
    $v = getenv($env);
    return ($v !== false && $v !== '') ? $v : $default;
};

// Defaults = production database (so the live site connects out of the box)
define('DB_HOST', $pick('db_host', 'M26_DB_HOST', 'localhost'));
define('DB_USER', $pick('db_user', 'M26_DB_USER', 'm26technologies'));
define('DB_PASS', $pick('db_pass', 'M26_DB_PASS', 'hl691CSpe[ubz71q'));
define('DB_NAME', $pick('db_name', 'M26_DB_NAME', 'm26technologies'));

// 'local'  → show errors on screen (development)
// 'production' → hide errors, log them, enable HTTPS-only cookies
define('APP_ENV', $pick('app_env', 'M26_APP_ENV', 'production'));
