<?php
    // manage_roles.php
    session_start();

    $username = $_SESSION['username'] ?? 'Guest';
    $current_role = $_SESSION['role'] ?? 'N/A';

    include 'db_config.php';      // 1. DB Connection
    include 'config_helper.php';  // 2. Fetch Global Settings (STORE_NAME, BRAND_IMAGE_PATH)

    // Security check: Must be Admin
    if ($_SESSION['role'] !== 'admin') {
        header("Location: index.php");
        exit();
    }

    $message = '';
    $current_admin_id = $_SESSION['user_id'];

    // --- 1. Handle POST Actions (Delete, Promote, Deactivate/Activate) ---
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
        $action = $_POST['action'];
        $target_user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        
        // PREVENT ADMIN FROM MANAGING HIMSELF/HERSELF
        if ($target_user_id === $current_admin_id) {
            $message = "<p style='color: red;'>Error: You cannot manage your own account from this panel.</p>";
        } else if ($target_user_id > 0) {
            
            if ($action === 'delete') {
                $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->bind_param("i", $target_user_id);
                if ($stmt->execute()) {
                    $message = "<p style='color: green;'>User ID $target_user_id successfully deleted.</p>";
                } else {
                    $message = "<p style='color: red;'>Error deleting user.</p>";
                }
                $stmt->close();

            } else if ($action === 'promote' || $action === 'demote') {
                $new_role = ($action === 'promote') ? 'admin' : 'staff';
                $stmt = $conn->prepare("UPDATE users SET role = ? WHERE user_id = ?");
                $stmt->bind_param("si", $new_role, $target_user_id);
                if ($stmt->execute()) {
                    $message = "<p style='color: green;'>User ID $target_user_id successfully set to role '$new_role'.</p>";
                } else {
                    $message = "<p style='color: red;'>Error changing user role.</p>";
                }
                $stmt->close();

            } else if ($action === 'toggle_active') {
                // Re-using the 'is_approved' column for Activate/Deactivate function
                $current_status_sql = "SELECT is_approved FROM users WHERE user_id = ?";
                $stmt_status = $conn->prepare($current_status_sql);
                $stmt_status->bind_param("i", $target_user_id);
                $stmt_status->execute();
                $result_status = $stmt_status->get_result();
                $current_status = $result_status->fetch_assoc()['is_approved'];
                $new_status = $current_status ? 0 : 1; // Toggle the status (0=Deactivated, 1=Active/Approved)
                $action_name = $new_status ? 'Activated' : 'Deactivated';

                $stmt_update = $conn->prepare("UPDATE users SET is_approved = ? WHERE user_id = ?");
                $stmt_update->bind_param("ii", $new_status, $target_user_id);
                
                if ($stmt_update->execute()) {
                    $message = "<p style='color: green;'>User ID $target_user_id successfully $action_name.</p>";
                } else {
                    $message = "<p style='color: red;'>Error changing user status.</p>";
                }
                $stmt_status->close();
                $stmt_update->close();
            }
        }
    }
    // --- END Handle POST Actions ---

    // --- 2. Handle Create New User (from Admin panel) ---
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_new_user'])) {
        $new_username = $_POST['new_username'];
        $new_password = $_POST['new_password'];
        $new_role = $_POST['new_role']; 
        
        // Simple validation
        if (empty($new_username) || empty($new_password)) {
            $message = "<p style='color: red;'>Username and password cannot be empty.</p>";
        } else {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $is_approved = 1; // Admin created users are automatically approved

            $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role, is_approved) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssi", $new_username, $password_hash, $new_role, $is_approved);

            if ($stmt->execute()) {
                $message = "<p style='color: green;'>New user '$new_username' ($new_role) created successfully and activated.</p>";
            } else {
                if ($conn->errno == 1062) {
                    $message = "<p style='color: red;'>Error: Username '$new_username' is already taken.</p>";
                } else {
                    $message = "<p style='color: red;'>Error creating user: " . $stmt->error . "</p>";
                }
            }
            $stmt->close();
        }
    }
    // --- END Create New User ---

    // --- 3. Fetch All Users (Excluding the currently logged-in Admin) ---
    $all_users = [];
    $sql_users = "SELECT user_id, username, role, is_approved, created_at FROM users WHERE user_id != ?";
    $stmt = $conn->prepare($sql_users);
    $stmt->bind_param("i", $current_admin_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $all_users[] = $row;
        }
    }
    $stmt->close();
    close_db_connection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage User Roles</title>
    <link rel="stylesheet" href="style.css">
    <script>let hasData;</script>
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 15px;
            font-size: 11px;
            text-transform: uppercase;
            color: #6a6e73;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        td {
            padding: 20px 15px;
            border-bottom: 1px solid rgba(255,255,255,0.02);
        }

        tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }
    </style>
</head>
<body>
    <div class="main-wrapper">
        <div class="content-area" style="flex-direction: column">
            <div class="header-container" style="
            margin-left: auto; 
            margin-right: auto; 
            width: 100%;">
                <button class="logout-btn" onclick="window.location.href='logout.php';">
                    <span>Logout</span>
                </button>
        
                <div class="brand-section">
                    <div class="logo-box">
                        <img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" style="height: 40px;">
                </div>
                    <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                    <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
                </div>
            </div>

            <div class="selection-container" style="
            margin-left: auto; 
            margin-right: auto; 
            width: 100%; max-width: none;">
                <div class="glass-window">
                    <!-- Determine empty or not -->
                    <?php if (count($all_users) > 0): ?>
                        <script>
                            hasData = true;
                        </script>
                    <?php else: ?>
                        <script>
                            hasData = false;
                        </script>
                    <?php endif; ?>

                    <h2 style="margin-bottom: 25px; font-size: 18px;">User Management Panel</h2>
                    
                    <div class="quick-add-bar">
                        <form action="manage_roles.php" method="POST">
                            <div class="input-row">
                                <input type="text" class="input-minimal" name="new_username" placeholder="Username" required>
                                <input type="text" class="input-minimal" name="new_password" placeholder="Password" required>
                                <select name="new_role" class="select-minimal" required>
                                    <option value="" disabled selected>Select Role</option>
                                    <option value="staff">Staff</option>
                                    <option value="admin">Admin</option>
                                    <option value="viewer">Viewer</option>
                                </select>            
                            </div>

                            <div class="button-row">
                                <button type="submit" name="create_new_user"  class="btn-glow">CREATE ACCOUNT</button>
                            </div>
                        </form>
                    </div>

                    <div class="table-responsive_approve_user">
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>User ID</th>
                                        <th>Account Name</th>
                                        <th>Access</th>
                                        <th>Status</th>
                                        <th>Registered</th>
                                        <th>Actions Control</th>
                                    </tr>
                                </thead>
                                
                                <tbody>
                                    <?php foreach ($all_users as $user): ?>
                                        <tr>
                                            <td style="color: var(--accent);"><?php echo $user['user_id']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                            <td><span><?php echo ucfirst($user['role']); ?></span></td>
                                            <td>
                                                <span class="status-<?php echo $user['is_approved'] ? 'active' : 'inactive'; ?>">
                                                    <?php echo $user['is_approved'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <form action="manage_roles.php" method="POST">
                                                    <div class="action-group">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                        <?php if ($user['role'] === 'staff'): ?>
                                                            <button type="submit" name="action" value="promote" class="action-promote" 
                                                                    onclick="return confirm('Promote <?php echo $user['username']; ?> to Admin? This grants full access.')">Promote</button>
                                                        <?php else: /* role is admin or viewer */ ?>
                                                            <button type="submit" name="action" value="demote" class="action-demote" 
                                                                    onclick="return confirm('Demote <?php echo $user['username']; ?> to Staff?')">Demote</button>
                                                        <?php endif; ?>
    
                                                        <button type="submit" name="action" value="toggle_active" class="action-<?php echo $user['is_approved'] ? 'deactivate' : 'activate'; ?>">
                                                            <?php echo $user['is_approved'] ? 'Deactivate' : 'Activate'; ?>
                                                        </button>
    
                                                        <button type="submit" name="action" value="delete" class="action-delete" 
                                                                onclick="return confirm('WARNING! Delete user <?php echo $user['username']; ?> permanently?')">Delete</button>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?> 
                                </tbody>
                            </table>
                        </div>
                    </div>
            
                    <div class="empty-state" id="emptyMessage">
                        <div class="empty-icon">👥</div>
                        <p style="font-weight: 600;">No other accounts registered</p>
                        <p class="subtitle">You are currently the only user in the system.</p>
                    </div>                    
                </div>
            </div>
        </div>

        <div class="btn-group">
            <button type="button" class="back-main" onclick="window.location.href='admin.php'">BACK TO PREVIOUS PAGE</button>
        </div>

        <footer class="footer-container">
            <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
        </footer>
    </div>    

    <script>
        if(!hasData) {
            document.querySelector('.table-responsive_approve_user').style.display = 'none';
            document.getElementById('emptyMessage').style.display = 'block';
        }
    </script>

    
    
</body>
</html>