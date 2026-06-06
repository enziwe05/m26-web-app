<?php
require_once 'incl/dbconn.php';
require_staff();


$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($search != '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare("
        SELECT u.user_id, u.first_name, u.last_name, u.username, u.role,
               u.phone, u.email, u.team, u.status,
               s.first_name AS sup_first, s.last_name AS sup_last
        FROM users u
        LEFT JOIN users s ON s.user_id = u.supervisor_id
        WHERE (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ?)
        ORDER BY u.role, u.first_name
    ");
    $stmt->bind_param('sss', $like, $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
} else {
    $result = $conn->query("
        SELECT u.user_id, u.first_name, u.last_name, u.username, u.role,
               u.phone, u.email, u.team, u.status,
               s.first_name AS sup_first, s.last_name AS sup_last
        FROM users u
        LEFT JOIN users s ON s.user_id = u.supervisor_id
        ORDER BY u.role, u.first_name
    ");
}
$page_title = 'M26 | Employees';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Employees</h1>
            <a href='add_employee.php' class='btn btn-primary'>+ Add Employee</a>
        </div>

        <form method='GET' action='view_employees.php' class='filter-bar'>
            <input type='text' name='search' placeholder='Search by name or username...'
                   value='<?php echo htmlspecialchars($search); ?>'>
            <button type='submit' class='btn btn-secondary'>Search</button>
            <?php if ($search != ''): ?>
                <a href='view_employees.php' class='btn btn-secondary'>Clear</a>
            <?php endif; ?>
        </form>

        <?php
        if ($result->num_rows == 0) {
            echo "<p>No employees found.</p>";
        } else {
            echo "<div class='table-scroll'>";
            echo "<table class='data-table'>";
            echo "<tr><th>Name</th><th>Username</th><th>Role</th><th>Team</th><th>Supervisor</th><th>Phone</th><th>Status</th>" . (is_admin() ? "<th></th>" : "") . "</tr>";
            $status_labels = ['active' => 'Active', 'on_leave' => 'On Leave', 'inactive' => 'Inactive'];
            while ($row = $result->fetch_assoc()) {
                $status = $row['status'] ?? 'active';
                $sup    = $row['sup_first'] ? htmlspecialchars($row['sup_first'] . ' ' . $row['sup_last']) : '—';
                echo "<tr>";
                echo "<td><strong>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</strong></td>";
                echo "<td>" . htmlspecialchars($row['username']) . "</td>";
                echo "<td>" . $row['role'] . "</td>";
                echo "<td>" . htmlspecialchars($row['team'] ?? '') . "</td>";
                echo "<td>" . $sup . "</td>";
                echo "<td>" . htmlspecialchars($row['phone'] ?? '') . "</td>";
                echo "<td>";
                echo "<form method='POST' action='change_employee_status.php' style='margin:0'>";
                echo csrf_field();
                echo "<input type='hidden' name='user_id' value='" . $row['user_id'] . "'>";
                echo "<select name='status' class='status-select status-" . $status . "' onchange=\"this.className='status-select status-'+this.value; this.form.submit()\">";
                foreach ($status_labels as $val => $label) {
                    $sel = ($status === $val) ? " selected" : "";
                    echo "<option value='$val'$sel>$label</option>";
                }
                echo "</select>";
                echo "</form>";
                echo "</td>";
                if (is_admin()) {
                    echo "<td style='white-space:nowrap'>";
                    echo "<a href='edit_employee.php?user_id=" . $row['user_id'] . "' class='btn btn-secondary' style='font-size:12px;padding:4px 10px;'>Edit</a> ";
                    echo "<a href='delete_employee.php?user_id=" . $row['user_id'] . "' class='btn btn-danger' style='font-size:12px;padding:4px 10px;'>Delete</a>";
                    echo "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        ?>

<?php include 'incl/footer.php'; ?>
