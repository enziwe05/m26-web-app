<div class='sidebar'>
    <div class='sidebar-logo'>
        <img src='images/m26.png' alt='M26' class='sidebar-logo-img'>
        M26
    </div>

    <ul class='sidebar-links'>
        <?php
        $role = current_user_role();
        $here = basename($_SERVER['PHP_SELF'] ?? '');

        // A link is "active" when the current page is its target OR a related sub-page,
        // so detail/edit pages still highlight the section they belong to.
        $nav = function (string $file, string $label, array $related = []) use ($here) {
            $on = ($here === $file) || in_array($here, $related, true);
            echo "<li><a href='{$file}'" . ($on ? " class='active'" : '') . ">{$label}</a></li>";
        };
        // Small section label to group the menu (hidden on the mobile top bar)
        $heading = function (string $label) {
            echo "<li class='nav-heading'>{$label}</li>";
        };

        if ($role === 'admin' || $role === 'supervisor') {
            $nav('admin_dashboard.php', 'Dashboard');

            $heading('Sites & Visits');
            $nav('view_sites.php',  'Sites',  ['site_detail.php', 'add_site.php', 'upload_document.php']);
            $nav('view_visits.php', 'Visits', ['visit_detail.php', 'create_visit.php', 'edit_visit.php', 'delete_visit.php', 'maintenance_form.php']);
            $nav('faults.php',      'Faults');

            $heading('Vehicle Fleet');
            $nav('view_vehicles.php',       'Vehicles & Weekly Checks', ['vehicle_detail.php', 'add_vehicle.php', 'edit_vehicle.php']);
            $nav('vehicle_inspections.php', 'Daily Inspection Log',     ['inspection_detail.php']);

            $heading('People');
            $nav('view_employees.php', 'Employees', ['add_employee.php', 'edit_employee.php']);
            $nav('view_clients.php',   'Clients',   ['add_client.php', 'edit_client.php']);
        } elseif ($role === 'employee') {
            $nav('employee_dashboard.php', 'My Visits',           ['employee_visit.php']);
            $nav('vehicle_inspection.php', 'Daily Vehicle Check');
        } elseif ($role === 'client') {
            $nav('client_dashboard.php', 'My Sites', ['client_site_detail.php', 'client_visit_detail.php']);
        } else {
            $nav('login.php', 'Login');
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
