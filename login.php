<?php
// login.php
session_start();
include 'db_config.php';

$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // 1. Prepare and execute SQL statement to retrieve user
    $stmt = $conn->prepare("SELECT user_id, username, password_hash, role, is_approved, session_token, session_expires FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // 2. Verify password hash
        if (password_verify($password, $user['password_hash'])) {
            
            // 3. Check for approval status
            if ($user['is_approved']) {
                // Check if an active session already exists
                $existing_token = $user['session_token'];
                $existing_expires = $user['session_expires'];
            
                if ($existing_token && $existing_expires && strtotime($existing_expires) > time()) {
                    // Active session on another device — reject login
                    $message = "<p style='color: orange;'>This account is currently active on another device. Please log out first.</p>";
                } else {
                    // No active session — proceed with login
                    $token = bin2hex(random_bytes(32)); // 64 unique characters
                    $expires = date('Y-m-d H:i:s', time() + 8 * 3600); // Active for 8 hours
                    $now = date('Y-m-d H:i:s');
                    $uid = (int)$user['user_id'];
            
                    $conn->query("UPDATE users SET 
                        last_login = '$now', 
                        session_token = '$token', 
                        session_expires = '$expires' 
                        WHERE user_id = $uid");
            
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['session_token'] = $token; // Store token in session
            
                    header("Location: welcome.php");
                    exit();
                }
            } else {
                $message = "<p style='color: orange;'>Login failed. Your account is pending admin approval.</p>";
            }
        } else {
            $message = "<p style='color: red;'>Invalid username or password.</p>";
        }
    } else {
        $message = "<p style='color: red;'>Invalid username or password.</p>";
    }

    $stmt->close();
}

close_db_connection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- Sembunyikan badge sync di halaman login -->
    <style>
    #sync-status-badge { display: none !important; }
    </style>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>User Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body style="overflow: hidden; margin: 0;">
    <div class="login-card">
        <div class="login-logo">🔐</div>

        <h2>Welcome</h2>
        <p class="subtitle">Please login to access your dashboard</p>

        
        <?php echo $message; ?>
        <form action="login.php" method="POST" >
            <div class="input-group">
                <div class="input-wrapper">
                    <input type="text" name="username" placeholder="Enter your username" required>
                </div>
            </div>

            <div class="input-group">
                <div class="input-wrapper">
                    <input type="password" name="password" placeholder="Enter your password" required>
                </div>
            </div>

            <button type="submit" class="login-btn">LOGIN TO SYSTEM</button>
        </form>
        
        <a href="create_user.php" class="forgot-pass">No Account? Create Account</a>
    </div>

    <script>
        // 1. Replace the current page history so the user cannot return to the admin page
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // 2. Prevent back navigation by pushing a new state
        window.history.pushState(null, null, window.location.href);
        window.onpopstate = function() {
            window.location.replace('session_ended.php');
        };
        window.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
            var badge = document.getElementById('sync-status-badge');
            if (badge) badge.style.display = 'none';
            }, 100);
        });
    </script>
</body>
</html>