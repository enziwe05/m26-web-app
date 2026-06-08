<?php
require_once 'incl/dbconn.php';
require_staff();

// Clients with how many portal logins exist for them. (Matched-site counts are
// computed in PHP because one company can span several note spellings.)
$clients = $conn->query("
    SELECT c.client_id, c.name, c.match_keyword, c.status,
           (SELECT COUNT(*) FROM users u WHERE u.client_id = c.client_id) AS login_count
    FROM clients c
    ORDER BY c.name
");

$page_title = 'M26 | Clients';
include 'incl/header.php';
?>

        <div class='page-heading'>
            <h1>Clients</h1>
            <?php if (is_admin()): ?>
            <a href='add_client.php' class='btn btn-primary'>+ Add Client</a>
            <?php endif; ?>
        </div>

        <p style='font-size:13px; color:#888; margin-bottom:14px;'>
            Clients get a read-only portal login that shows only their sites. Each client is matched to a
            <em>company</em> (e.g. <strong>MTN</strong>), which covers all of that operator's site spellings.
        </p>

        <?php
        if (!$clients || $clients->num_rows == 0) {
            echo "<p>No clients yet." . (is_admin() ? " <a href='add_client.php'>Add the first one.</a>" : "") . "</p>";
        } else {
            echo "<div class='table-scroll'><table class='data-table'>";
            echo "<tr><th>Client</th><th>Company</th><th>Matched Sites</th><th>Logins</th><th>Status</th>" . (is_admin() ? "<th></th>" : "") . "</tr>";
            while ($c = $clients->fetch_assoc()) {
                $dim = $c['status'] === 'inactive' ? " style='opacity:.55;'" : '';
                echo "<tr$dim>";
                echo "<td><strong>" . htmlspecialchars($c['name']) . "</strong></td>";
                echo "<td>" . htmlspecialchars(client_company_label($c['match_keyword'])) . "</td>";
                echo "<td>" . client_site_count($conn, $c['match_keyword']) . "</td>";
                echo "<td>" . (int)$c['login_count'] . "</td>";
                echo "<td>" . ucfirst($c['status']) . "</td>";
                if (is_admin()) {
                    echo "<td><a href='edit_client.php?client_id=" . $c['client_id'] . "'>Edit</a></td>";
                }
                echo "</tr>";
            }
            echo "</table></div>";
        }
        ?>

        <?php if (is_admin()): ?>
        <p style='margin-top:16px; font-size:13px; color:#888;'>
            Adding a client also creates its read-only portal login. Use <strong>Edit</strong> to change
            the keyword, reset the password, or deactivate access.
        </p>
        <?php endif; ?>

<?php include 'incl/footer.php'; ?>
