<?php
/*
 * Geo / location helpers (2026-07 refinements — Change 1).
 *
 * haversine_m()               — great-circle distance in metres
 * classify_onsite()           — returns 'on_site' / 'away' / 'unknown'
 * format_distance()           — human-readable distance string
 * auto_close_stale_entries()  — sweep & close forgotten clock-outs
 *
 * Requires incl/dbconn.php to be loaded first (for $conn / mysqli).
 */

/**
 * Great-circle distance in metres (Haversine formula).
 * Returns null when any coordinate is missing, empty, or (0,0) which is
 * an invalid "no GPS" sentinel.
 */
function haversine_m($lat1, $lon1, $lat2, $lon2): ?int
{
    if ($lat1 === null || $lat1 === '' || $lon1 === null || $lon1 === '' ||
        $lat2 === null || $lat2 === '' || $lon2 === null || $lon2 === '') {
        return null;
    }
    $lat1 = (float) $lat1;  $lon1 = (float) $lon1;
    $lat2 = (float) $lat2;  $lon2 = (float) $lon2;

    // (0,0) is the null-island — treat as missing GPS
    if ($lat1 === 0.0 && $lon1 === 0.0) return null;
    if ($lat2 === 0.0 && $lon2 === 0.0) return null;

    $R    = 6371000; // Earth radius in metres
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dlam = deg2rad($lon2 - $lon1);
    $a    = sin($dphi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dlam / 2) ** 2;
    $c    = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return (int) round($R * $c);
}

/**
 * Classify a distance as 'on_site', 'away', or 'unknown'.
 *   on_site  — distance is not null AND ≤ radius
 *   away     — distance is not null AND  > radius
 *   unknown  — distance is null (GPS missing)
 */
function classify_onsite(?int $distance_m, int $radius_m): string
{
    if ($distance_m === null) return 'unknown';
    return $distance_m <= $radius_m ? 'on_site' : 'away';
}

/**
 * Human-readable distance:
 *   null       → '—'
 *   < 1 000 m  → 'N m'
 *   ≥ 1 000 m  → 'N.N km'
 */
function format_distance(?int $m): string
{
    if ($m === null) return '—';
    if ($m < 1000)  return $m . ' m';
    return number_format($m / 1000, 1) . ' km';
}

/**
 * Sweep all open time_entries whose grace period has expired and close them.
 *
 * For each open entry:
 *  - Compute the scheduled shift-end datetime (clock_in date + day_end / night_end;
 *    push to the next day when the end falls before or at clock-in, as night shifts do).
 *  - Grace end = shift end + autoclose_grace_mins * 60.
 *  - If NOW > grace end: set clock_out_at = shift-end, cap worked_minutes at the
 *    shift's normal hours (so a forgotten clock-out NEVER generates overtime), append
 *    'auto_clocked_out' to location_flag (space-separated), status → 'closed'.
 *
 * Returns the count of entries closed.
 */
function auto_close_stale_entries(mysqli $conn): int
{
    // Fetch the single payroll settings row
    $res = $conn->query(
        "SELECT day_end, night_end, day_normal_hours, night_normal_hours, autoclose_grace_mins
         FROM payroll_settings WHERE id = 1"
    );
    $settings = $res ? $res->fetch_assoc() : null;
    if (!$settings) return 0;

    // TIME columns may come back as 'HH:MM:SS' — strip to 'HH:MM'
    $day_end      = substr((string) $settings['day_end'],   0, 5);
    $night_end    = substr((string) $settings['night_end'], 0, 5);
    $day_normal   = (float) $settings['day_normal_hours'];
    $night_normal = (float) $settings['night_normal_hours'];
    $grace_mins   = max(0, (int) $settings['autoclose_grace_mins']);

    // Load every open (potentially stale) entry
    $stmt = $conn->prepare(
        "SELECT entry_id, shift_type, clock_in_at, location_flag
         FROM time_entries
         WHERE status = 'open' AND clock_out_at IS NULL"
    );
    $stmt->execute();
    $open = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $now    = time();
    $closed = 0;

    foreach ($open as $entry) {
        $clock_in_ts = strtotime($entry['clock_in_at']);
        if (!$clock_in_ts) continue;

        $is_night = $entry['shift_type'] === 'night';
        $end_hm   = $is_night ? $night_end : $day_end;

        // Scheduled shift-end on the same calendar day as clock-in.
        // Night shifts end in the early hours of the NEXT day, so if end_ts ≤ clock_in_ts
        // push it forward by 24 h.
        $end_ts = strtotime(date('Y-m-d', $clock_in_ts) . ' ' . $end_hm);
        if ($end_ts <= $clock_in_ts) $end_ts += 86400;

        $grace_end_ts = $end_ts + $grace_mins * 60;
        if ($now <= $grace_end_ts) continue; // Grace period still running — skip

        // Cap worked minutes at the shift's normal hours (no accidental overtime)
        $normal_mins = (int) round(($is_night ? $night_normal : $day_normal) * 60);
        $worked      = min((int) round(($end_ts - $clock_in_ts) / 60), $normal_mins);

        // Append 'auto_clocked_out' flag (space-separated, preserve existing)
        $existing_flag = (string) ($entry['location_flag'] ?? '');
        $new_flag      = $existing_flag !== ''
            ? $existing_flag . ' auto_clocked_out'
            : 'auto_clocked_out';

        $clock_out_dt = date('Y-m-d H:i:s', $end_ts);
        $entry_id     = (int) $entry['entry_id'];

        $upd = $conn->prepare(
            "UPDATE time_entries
             SET clock_out_at = ?, worked_minutes = ?, location_flag = ?, status = 'closed'
             WHERE entry_id = ? AND status = 'open'"
        );
        $upd->bind_param('sisi', $clock_out_dt, $worked, $new_flag, $entry_id);
        $upd->execute();
        $upd->close();
        $closed++;
    }

    return $closed;
}
