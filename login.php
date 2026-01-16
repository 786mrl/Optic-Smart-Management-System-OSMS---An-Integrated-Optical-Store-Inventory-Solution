<?php
// login.php
session_start();
include 'db_config.php';

$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // 1. Prepare and execute SQL statement to retrieve user
    $stmt = $conn->prepare("SELECT user_id, username, password_hash, role, is_approved FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // 2. Verify password hash
        if (password_verify($password, $user['password_hash'])) {
            
            // 3. Check for approval status
            if ($user['is_approved']) {
                // Login Success: Set session variables
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['role'] = $user['role'];
                
                // Redirect to main window (index.php)
                header("Location: welcome.php");
                exit();
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
</body>
</html>