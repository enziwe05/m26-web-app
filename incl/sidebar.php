<div class='sidebar'>
    <div class='sidebar-logo'>
        <img src='images/m26.png' alt='M26' class='sidebar-logo-img'>
        M26
    </div>

    <ul class='sidebar-links'>
        <?php
        $role = current_user_role();
        $here = basename($_SERVER['PHP_SELF'] ?? '');

        // Helper: mark a link active when it points at the current page
        $nav = function (string $file, string $label) use ($here) {
            $active = ($here === $file) ? " class='active'" : '';
            echo "<li><a href='{$file}'{$active}>{$label}</a></li>";
        };

        if ($role === 'admin' || $role === 'supervisor') {
            $nav('admin_dashboard.php', 'Dashboard');
            $nav('view_sites.php',      'Sites');
            $nav('view_visits.php',     'All Visits');
            $nav('faults.php',          'Faults');
            $nav('view_employees.php',  'Employees');
        } elseif ($role === 'employee') {
            $nav('employee_dashboard.php', 'My Visits');
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
