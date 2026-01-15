<?php
// approve_users.php
session_start();

$username = $_SESSION['username'] ?? 'Guest';
$current_role = $_SESSION['role'] ?? 'N/A';

include 'db_config.php';      // 1. DB Connection
include 'config_helper.php';  // 2. Fetch Global Settings (STORE_NAME, BRAND_IMAGE_PATH)

// Security check: must be Admin
if ($_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$message = '';

// --- Logic to Handle Approval Action ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['approve_id'])) {
    $user_to_approve_id = $_POST['approve_id'];

    // SQL to update is_approved status to TRUE (1) only for 'staff' role
    $stmt = $conn->prepare("UPDATE users SET is_approved = 1 WHERE user_id = ? AND role = 'staff'");
    $stmt->bind_param("i", $user_to_approve_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $message = "<p style='color: green; font-weight: bold;'>User ID $user_to_approve_id has been successfully approved. They can now log in.</p>";
    } else {
        $message = "<p style='color: red;'>Error approving user or user not found/is not staff.</p>";
    }
    $stmt->close();
}
// ----------------------------------------

// --- Logic to Fetch Pending Users ---
$pending_users = [];
// Select all staff users who are not yet approved (is_approved = 0)
$sql_pending = "SELECT user_id, username, created_at FROM users WHERE role = 'staff' AND is_approved = 0";
$result = $conn->query($sql_pending);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $pending_users[] = $row;
    }
}

close_db_connection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Staff Users</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="main-header">
        <div class="header-content">
            <div class="brand-info">
                <img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" class="brand-logo">
                
                <h1 class="brand-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                
                <p class="store-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p> 
            </div>
            
            <h2>Welcome, <?php echo htmlspecialchars($username ?? 'Guest'); ?></h2>
            
            <a href="logout.php" class="logout-button">Logout</a>
        </div>
    </header>


    <main class="main-content">
        <h3>Pending Staff Accounts</h3>
        <?php echo $message; ?>

        <?php if (count($pending_users) > 0): ?>
            <table class="user-approval-table">
                <thead>
                    <tr>
                        <th>User ID</th>
                        <th>Username</th>
                        <th>Registered Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_users as $user): ?>
                        <tr>
                            <td><?php echo $user['user_id']; ?></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo $user['created_at']; ?></td>
                            <td>
                                <form action="approve_users.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="approve_id" value="<?php echo $user['user_id']; ?>">
                                    <button type="submit" class="action-approve-button">Approve</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="no-pending-users">No new staff accounts require approval.</p>
        <?php endif; ?>

        <p style="margin-top: 40px;"><a href="admin.php" class="link-back">Back to Administration</a></p>
    </main>

    <footer>
        <p><?php echo $COPYRIGHT_FOOTER; ?></p>
    </footer>
    
</body>
</html>