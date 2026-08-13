<div class='sidebar'>
    <div class='sidebar-logo'>
        <img src='images/m26.png' alt='M26' class='sidebar-logo-img'>
        M26
    </div>
    <button type='button' class='sidebar-toggle' id='sidebar-toggle'
            aria-label='Menu' aria-expanded='false' aria-controls='sidebar-links'>
        <svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round'>
            <path d='M4 7h16M4 12h16M4 17h16'/>
        </svg>
    </button>

    <ul class='sidebar-links' id='sidebar-links'>
        <?php
        $role = current_user_role();
        $here = basename($_SERVER['PHP_SELF'] ?? '');

        // Simple inline-SVG icon set (stroke, inherits text colour)
        $icons = [
            'dashboard' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
            'sites'     => '<path d="M12 21s-7-6.5-7-11a7 7 0 0 1 14 0c0 4.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
            'visits'    => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 11h6M9 15h4"/>',
            'faults'    => '<path d="M12 3l9 16H3z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
            'truck'     => '<path d="M3 6h11v9H3z"/><path d="M14 9h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.7"/><circle cx="17.5" cy="18" r="1.7"/>',
            'checklist' => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 12l3 3 5-6"/>',
            'users'     => '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 5.5a3 3 0 0 1 0 6"/><path d="M16.5 14a6 6 0 0 1 4.5 6"/>',
            'clients'   => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
            'login'     => '<path d="M15 3h4v18h-4"/><path d="M10 12H3"/><path d="M7 8l-4 4 4 4"/>',
            'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
            'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/>',
            'money'     => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M5 9h.01M19 15h.01"/>',
            'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9L17 7M7 17l-2.1 2.1"/>',
            'gauge'     => '<path d="M4 19a8 8 0 1 1 16 0"/><path d="M12 19l4.5-5.5"/><circle cx="12" cy="19" r="1.3"/>',
        ];
        $ico = function (string $name) use ($icons) {
            $p = $icons[$name] ?? '';
            return "<span class='nav-ico'><svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'>{$p}</svg></span>";
        };

        // A link is "active" when the current page is its target OR a related sub-page,
        // so detail/edit pages still highlight the section they belong to.
        $nav = function (string $file, string $label, string $icon = '', array $related = []) use ($here, $ico) {
            $on = ($here === $file) || in_array($here, $related, true);
            echo "<li><a href='{$file}'" . ($on ? " class='active'" : '') . ">" . $ico($icon) . "<span>{$label}</span></a></li>";
        };
        // Small section label to group the menu (hidden on the mobile top bar)
        $heading = function (string $label) {
            echo "<li class='nav-heading'>{$label}</li>";
        };

        if ($role === 'admin' || $role === 'supervisor') {
            $nav('admin_dashboard.php', 'Dashboard', 'dashboard');

            $heading('Sites & Visits');
            $nav('view_sites.php',  'Sites',  'sites',  ['site_detail.php', 'add_site.php', 'upload_document.php']);
            $nav('view_visits.php', 'Visits', 'visits', ['visit_detail.php', 'create_visit.php', 'edit_visit.php', 'delete_visit.php', 'maintenance_form.php']);
            $nav('stale_sites.php', 'Maintenance Status', 'gauge');
            $nav('faults.php',      'Faults', 'faults');

            $heading('Vehicle Fleet');
            $nav('view_vehicles.php',       'Vehicles & Weekly Checks', 'truck',     ['vehicle_detail.php', 'add_vehicle.php', 'edit_vehicle.php']);
            $nav('vehicle_inspections.php', 'Daily Inspection Log',     'checklist', ['inspection_detail.php']);

            $heading('People');
            $nav('view_employees.php', 'Employees', 'users',   ['add_employee.php', 'edit_employee.php']);
            $nav('view_clients.php',   'Clients',   'clients', ['add_client.php', 'edit_client.php']);

            $heading('Workforce');
            $nav('shifts.php',     'Shifts',     'calendar',  ['assign_shift.php']);
            $nav('timesheets.php', 'Timesheets', 'clock');
            $nav('offsite_submissions.php', 'Off-site Uploads', 'faults');
            if ($role === 'admin') {
                $nav('settings.php', 'Settings', 'settings');
            }
        } elseif ($role === 'employee') {
            $nav('employee_dashboard.php', 'My Visits',           'visits', ['employee_visit.php']);
            $nav('pick_site.php',          'Start a Visit',       'sites',  ['start_visit.php']);
            $nav('clock.php',              'Clock In',            'clock');
            $nav('my_shifts.php',          'My Shifts',           'calendar');
            $nav('vehicle_inspection.php', 'Daily Vehicle Check', 'truck');
        } elseif ($role === 'client') {
            $nav('client_dashboard.php', 'My Sites', 'sites', ['client_site_detail.php', 'client_visit_detail.php']);
        } else {
            $nav('login.php', 'Login', 'login');
        }
        ?>
    </ul>

    <?php if (current_user_name() !== ''): ?>
    <div class='sidebar-footer'>
        <span class='sidebar-user'><?php echo htmlspecialchars(current_user_name()); ?></span>
        <a href='logout.php' class='logout-link'>Logout</a>
    </div>
    <?php endif; ?>
</div>

<script>
/* Mobile hamburger: toggle the menu open/closed; close after tapping a link */
(function () {
    var bar = document.querySelector('.sidebar');
    var btn = document.getElementById('sidebar-toggle');
    if (!bar || !btn) return;
    btn.addEventListener('click', function () {
        var open = bar.classList.toggle('open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    bar.querySelectorAll('.sidebar-links a').forEach(function (a) {
        a.addEventListener('click', function () { bar.classList.remove('open'); });
    });
})();
</script>
