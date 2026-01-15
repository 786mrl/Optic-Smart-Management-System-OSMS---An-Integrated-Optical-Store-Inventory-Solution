<?php
// create_user.php
session_start();
include 'db_config.php';

$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password']; // New input field

    // --- 1. NEW: Password Confirmation Check ---
    if ($password !== $confirm_password) {
        $message = "<p style='color: red;'>Error: Passwords do not match. Please re-enter your password.</p>";
    } else if (strlen($password) < 6) { // Basic length check
        $message = "<p style='color: red;'>Error: Password must be at least 6 characters long.</p>";
    } else {
        // Validation Passed: Continue with user creation logic

        // Hash the password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // Check if any user exists to determine the role
        $check_sql = "SELECT COUNT(*) AS user_count FROM users";
        $result = $conn->query($check_sql);
        $row = $result->fetch_assoc();
        $user_count = $row['user_count'];

        if ($user_count == 0) {
            // First user: Admin and Approved
            $role = 'admin';
            $is_approved = 1;
            $message_success = "Admin account created successfully. You can now log in.";
        } else {
            // Subsequent users: Staff and Pending Approval
            $role = 'staff';
            $is_approved = 0;
            $message_success = "Staff account created successfully. Please wait for an Admin to approve your account before logging in.";
        }

        // Insert user into database
        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role, is_approved) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sssi", $username, $password_hash, $role, $is_approved);

        if ($stmt->execute()) {
            $message = "<p style='color: green; font-weight: bold;'>$message_success</p>";
        } else {
            // Check for duplicate username error (common MySQL error code for UNIQUE constraint)
            if ($conn->errno == 1062) { 
                $message = "<p style='color: red;'>Error: Username '$username' is already taken.</p>";
            } else {
                $message = "<p style='color: red;'>Error creating user: " . $stmt->error . "</p>";
            }
        }
        $stmt->close();
    }
}

close_db_connection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New User</title>
    <link rel="stylesheet" href="style.css">
    <script>
        function validatePassword() {
            var password = document.getElementById("password").value;
            var confirmPassword = document.getElementById("confirm_password").value;
            var messageElement = document.getElementById("password_error");
            
            if (password !== confirmPassword) {
                messageElement.textContent = "Error: Passwords do not match.";
                messageElement.style.color = "red";
                return false;
            } else if (password.length < 6) {
                messageElement.textContent = "Error: Password must be at least 6 characters long.";
                messageElement.style.color = "red";
                return false;
            } else {
                messageElement.textContent = "";
                return true;
            }
        }
    </script>
</head>
<body class="login-body">
    <div class="login-container">
        <h2>Create New User</h2>
        <?php echo $message; ?>
        <form action="create_user.php" method="POST" class="login-form" onsubmit="return validatePassword()"> 
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" id="password" placeholder="Password" required>
            <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm Password" required onkeyup="validatePassword()">
            <div id="password_error" style="margin-bottom: 15px;"></div>
            <button type="submit" class="login-button">Register</button>
        </form>
        <p><a href="login.php" class="link-back">Back to Login</a></p>
    </div>
</body>
</html>