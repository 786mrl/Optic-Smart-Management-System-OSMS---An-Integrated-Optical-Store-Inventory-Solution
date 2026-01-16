<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$username = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? 'staff';
$redirect_time_ms = 3500;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Lenza Optic POS</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="welcome-container">
        <div class="profile-circle">
            👤
        </div>

        <h1>Welcome,</h1>
        <span class="user-name"><?php echo htmlspecialchars(ucfirst($username)); ?></span>
        
        <p class="status-msg">Preparing your Dashboard...</p>

        <div class="loader-track">
            <div class="loader-fill"></div>
        </div>
    </div>
    <script>        
        // Simulate redirect to main page after 3.5 seconds
        setTimeout(function() {
            console.log("Redirecting to Dashboard...");
            window.location.href = 'index.php';
        }, <?php echo $redirect_time_ms; ?>);
    </script>
</body>
</html>