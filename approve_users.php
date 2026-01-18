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
    $status = '';

    // --- Logic to Handle Approval Action ---
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['approve_id'])) {
        $user_to_approve_id = $_POST['approve_id'];
        $stmt = $conn->prepare("UPDATE users SET is_approved = 1 WHERE user_id = ? AND role = 'staff'");
        $stmt->bind_param("i", $user_to_approve_id);
    
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = "User ID #$user_to_approve_id approved successfully!";
            $status = "success";
        } else {
            $message = "Failed to approve user. It might be already approved or not found.";
            $status = "error";
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
    <script>let hasData;</script>
    <style>
        /* Adjust table so it doesn't stick to the edges */
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 15px; /* Row spacing */
            min-width: 600px; /* Prevents columns from being too cramped on small screens */
        }

        th {
            padding: 10px 20px;
            color: var(--text-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-align: left;
        }

        td {
            padding: 20px;
            background: var(--bg-color);
            font-size: 14px;
            /* Use slightly smaller shadows for safety */
            box-shadow: 4px 4px 10px var(--shadow-dark), 
                        -4px -4px 10px var(--shadow-light);
            border: none;
        }

        /* Smooth out row corners */
        tr td:first-child { 
            border-radius: 20px 0 0 20px; 
            padding-left: 25px;
        }

        tr td:last-child { 
            border-radius: 0 20px 20px 0; 
            padding-right: 25px;
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
                <?php if ($message !== ''): ?>
                    <div class="neu-alert <?php echo $status; ?>" id="alertBox">
                        <span class="alert-icon">
                            <?php echo ($status === 'success') ? '✅' : '⚠️'; ?>
                        </span>
                        <span><?php echo $message; ?></span>
                    </div>
                    
                    <script>
                        // Automatically remove alert after 4 seconds
                        setTimeout(() => {
                            const alert = document.getElementById('alertBox');
                            alert.style.transition = "opacity 0.5s ease";
                            alert.style.opacity = "0";
                            setTimeout(() => alert.remove(), 500);
                        }, 4000);
                    </script>
                <?php endif; ?>

                <div class="window-card" style="max-width: none">
                    <!-- Determine empty or not -->
                    <?php if (count($pending_users) > 0): ?>
                        <script>
                            hasData = true;
                        </script>
                    <?php else: ?>
                        <script>
                            hasData = false;
                        </script>
                    <?php endif; ?>
            
                    <div class="header-title">
                        <h2>Pending Staff Accounts</h2>
                        <p class="subtitle">List of accounts waiting for administrator approval</p>
                    </div>
            
                    <div class="table-responsive_approve_user">
                        <table>
                            <thead>
                                <tr>
                                    <th>User ID</th>
                                    <th>Username</th>
                                    <th>Registered Date</th>
                                    <th style="text-align: center;">Action</th>
                                </tr>
                            </thead>
            
                            <tbody>
                                <?php foreach ($pending_users as $user): ?>
                                    <tr>
                                        <td style="color: var(--accent-color); font-weight: 700;"><?php echo $user['user_id']; ?></td>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td><?php echo $user['created_at']; ?></td>
                                        <td style="text-align: center;">
                                            <form action="approve_users.php" method="POST">
                                                <input type="hidden" name="approve_id" value="<?php echo $user['user_id']; ?>">
                                                <button type="submit"class="btn-approve">Approve</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
            
                    <div class="empty-state" id="emptyMessage">
                        <div class="empty-icon">📂</div>
                        <p style="font-weight: 600;">No pending staff accounts</p>
                        <p class="subtitle">All account requests have been processed.</p>
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