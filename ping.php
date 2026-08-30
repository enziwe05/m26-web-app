<?php
/*
 * Session keep-alive.
 *
 * The maintenance form can take a long time to fill in on site (typing,
 * photos, poor signal). A page left open would otherwise have its session
 * garbage-collected server-side and the tech gets "logged out" mid-task.
 *
 * A small heartbeat on the form page pings this endpoint every few minutes.
 * Loading dbconn.php calls session_start(), which touches the session file
 * and keeps it alive. We only refresh — never create — a login here.
 */
require_once __DIR__ . '/incl/dbconn.php';

header('Cache-Control: no-store');
// 204 = logged in & session refreshed; 401 = session already gone (client can react)
http_response_code(current_user_id() > 0 ? 204 : 401);
