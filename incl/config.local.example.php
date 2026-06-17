<?php
/*
 * LIVE-SERVER CONFIG TEMPLATE.
 *
 * 1. Copy this file to:  incl/config.local.php
 * 2. Fill in the database credentials your host gave you.
 * 3. Set 'app_env' to 'production'.
 *
 * config.local.php is git-ignored and overrides the defaults in config.php,
 * so your real password is never committed to the codebase.
 */
return [
    'db_host' => 'localhost',
    'db_user' => 'CHANGE_ME',
    'db_pass' => 'CHANGE_ME',
    'db_name' => 'CHANGE_ME',
    'app_env' => 'production',
];
