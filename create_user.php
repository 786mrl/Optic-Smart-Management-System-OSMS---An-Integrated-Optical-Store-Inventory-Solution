<?php
    // create_user.php
    session_start();
    include 'db_config.php';

    $message = '';

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $username = $_POST['username'];
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        if ($password !== $confirm_password) {
            $message = "<p style='color: red;'>Error: Passwords do not match.</p>";
        } else if (strlen($password) < 6) {
            $message = "<p style='color: red;'>Error: Password must be at least 6 characters long.</p>";
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            // --- ROLE & APPROVAL LOGIC REFINEMENT ---
            $check_sql = "SELECT COUNT(*) AS user_count FROM users";
            $result = $conn->query($check_sql);
            $row = $result->fetch_assoc();
            
            // Force to integer to ensure accurate check ( === 0 )
            $user_count = (int)$row['user_count'];

            if ($user_count === 0) {
                $role = 'admin';
                $is_approved = 1; // The first user is automatically an admin & active
                $msg_text = "Admin account created successfully. You can now log in.";
            } else {
                $role = 'staff';
                $is_approved = 0; // Subsequent users must wait for approval
                $msg_text = "Staff account created successfully. Please wait for an Admin to approve your account.";
            }

            $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role, is_approved) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssi", $username, $password_hash, $role, $is_approved);

            if ($stmt->execute()) {
                // Use SESSION so the message persists after redirection
                $_SESSION['success_msg'] = $msg_text;
                header("Location: create_user.php"); // Refresh page to clear the form
                exit();
            } else {
                if ($conn->errno == 1062) { 
                    $message = "<p style='color: red;'>Error: Username '$username' is already taken.</p>";
                } else {
                    $message = "<p style='color: red;'>Error: " . $stmt->error . "</p>";
                }
            }
            $stmt->close();
        }
    }

    // Retrieve success message from session if it exists
    if (isset($_SESSION['success_msg'])) {
        $message = "<p style='color: #00ff88; font-weight: bold; background: rgba(0,255,136,0.1); padding: 10px; border-radius: 8px;'>" . $_SESSION['success_msg'] . "</p>";
        unset($_SESSION['success_msg']); // Delete after displaying
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
<body  style="flex-direction: column">
    
    <div class="user-window">
        <div class="header-section">
            <div class="avatar-box">👤</div>
            <h2>New Operator</h2>
            <p class="subtitle">System access account registration</p>
        </div>

        
        <?php echo $message; ?>
        <form action="create_user.php" method="POST" onsubmit="return validatePassword()">
                <div class="form-group">
                    <label>Username</label>
                    <input class="input-neu" type="text" name="username" placeholder="Username" required>
                </div>    
    
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" class="input-neu" name="password" id="password"  placeholder="••••••••" required>
                </div>
    
                <div class="form-group">
                    <label>Repeat Password</label>
                    <input type="password" class="input-neu"  name="confirm_password" id="confirm_password" placeholder="••••••••" required onkeyup="validatePassword()">
                </div>
                
                <div id="password_error" style="margin-bottom: 15px;"></div>
    
                <div class="action-area">
                    <button type="submit" class="btn-submit">CREATE NEW ACCOUNT</button>
                    <button type="button" class="btn-back" onclick="window.location.href='login.php'">Cancel & Go Back</button>
                </div>
        </form>
    </div>

</body>
</html>