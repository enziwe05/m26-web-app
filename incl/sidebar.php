<div class='sidebar'>
    <div class='sidebar-logo'>
        <img src='images/m26.png' alt='M26' class='sidebar-logo-img'>
        M26
    </div>

    <ul class='sidebar-links'>
        <?php if (isset($_COOKIE['user_role']) && $_COOKIE['user_role'] == 'admin'): ?>
            <li><a href='admin_dashboard.php'>Dashboard</a></li>
            <li><a href='view_sites.php'>Sites</a></li>
            <li><a href='view_visits.php'>All Visits</a></li>
            <li><a href='view_employees.php'>Employees</a></li>
        <?php elseif (isset($_COOKIE['user_role']) && $_COOKIE['user_role'] == 'employee'): ?>
            <li><a href='employee_dashboard.php'>My Visits</a></li>
        <?php else: ?>
            <li><a href='login.php'>Login</a></li>
        <?php endif; ?>
    </ul>

    <?php if (isset($_COOKIE['user_name'])): ?>
    <div class='sidebar-footer'>
        <span class='sidebar-user'><?php echo htmlspecialchars($_COOKIE['user_name']); ?></span>
        <a href='logout.php' class='logout-link'>Logout</a>
    </div>
    <?php endif; ?>
</div>
