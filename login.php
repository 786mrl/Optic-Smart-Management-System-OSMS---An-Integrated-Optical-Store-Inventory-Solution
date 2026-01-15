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
<body class="login-body">
    <div class="login-container">
        <h2>System Login</h2>
        <?php echo $message; ?>
        <form action="login.php" method="POST" class="login-form">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit" class="login-button">Login</button>
        </form>
        <p>No account? <a href="create_user.php" class="link-create-user">Create User</a></p>
    </div>
</body>
</html>