<?php
/*
 * Canonical Motor Vehicle Checklist definition (the M26 paper form).
 * Single source of truth used by vehicle_inspection.php, submit_inspection.php,
 * vehicle_detail.php and inspection_detail.php.
 *
 * Each section maps field keys → human labels. Driver marks each item with one
 * of the status values below.
 *
 * Usage:  $vehicle_checklist = require __DIR__ . '/vehicle_checklist.php';
 */

/*
 * Status key (matches the paper form's legend):
 *   ok        → ✓  No Problem
 *   attention → X  Attention Needed
 *   critical  → •• Critical
 *   na        → —  Not Applicable
 */
if (!function_exists('vc_statuses')) {
    function vc_statuses(): array {
        return [
            'ok'        => 'No Problem',
            'attention' => 'Attention Needed',
            'critical'  => 'Critical',
            'na'        => 'N/A',
        ];
    }

    // CSS class for colouring a status (reuses existing status-* colours)
    function vc_status_class(string $status): string {
        switch ($status) {
            case 'ok':        return 'status-ok';
            case 'attention': return 'status-attention';
            case 'critical':  return 'status-faulty';
            default:          return 'status-na';
        }
    }

    // Short mark for the compact weekly grid
    function vc_status_mark(string $status): string {
        switch ($status) {
            case 'ok':        return '&#10003;';   // ✓
            case 'attention': return '&#10007;';   // ✗
            case 'critical':  return '&bull;&bull;'; // ••
            default:          return '&ndash;';
        }
    }
}

return [
    'lights' => [
        'label' => 'Lights',
        'fields' => [
            'headlights'     => 'Headlights',
            'indicators'     => 'Indicators',
            'hazards'        => 'Hazards',
            'brake_lights'   => 'Brake Lights',
            'reverse_lights' => 'Reverse Lights',
            'rear_lights'    => 'Rear Lights',
        ],
    ],
    'tyres' => [
        'label' => 'Tyres',
        'fields' => [
            'pressure'          => 'Pressure',
            'tread_wheel_studs' => 'Tread & Wheel Studs',
            'valve_caps'        => 'Valve Caps',
            'wheel_spanner'     => 'Wheel Spanner',
            'jack_handle'       => 'Jack & Handle',
        ],
    ],
    'brakes' => [
        'label' => 'Brakes',
        'fields' => [
            'uniform_braking'   => 'Uniform Braking',
            'brake_fluid_level' => 'Brake Fluid Level',
            'hand_brake'        => 'Hand Brake Operation',
        ],
    ],
    'vision' => [
        'label' => 'Vision',
        'fields' => [
            'windscreen_license' => 'Windscreen & License Disc',
            'windscreen_wipers'  => 'Windscreen Wipers',
            'rear_view_mirrors'  => 'Rear View Mirrors',
        ],
    ],
    'other' => [
        'label' => 'Other',
        'fields' => [
            'hooter'              => 'Hooter',
            'radiator_water'      => 'Radiator Water Level',
            'oil_level'           => 'Oil Level',
            'safety_belts'        => 'Safety Belts',
            'seating'             => 'Seating',
            'dents_scratches'     => 'Dents / Scratches',
            'first_aid_kit'       => 'First Aid Kit',
            'triangular_plates'   => 'Triangular Plates',
            'fire_extinguisher'   => 'Fire Extinguisher',
            'repair_logbook'      => 'Repair Requisition & Logbook',
            'general_cleanliness' => 'General Cleanliness',
        ],
    ],
];
