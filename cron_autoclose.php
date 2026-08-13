<?php
// Auto clock-out cron — closes stale open time_entries whose grace period has passed.
// CLI ONLY.
// Virtualmin cron: */30 * * * * php /home/m26technologies/public_html/ops-app/cron_autoclose.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/incl/dbconn.php';
require_once __DIR__ . '/incl/geo.php';

$n = auto_close_stale_entries($conn);
echo "Auto-closed {$n} stale time entr" . ($n === 1 ? 'y' : 'ies') . ".\n";
