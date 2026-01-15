<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$username = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? 'staff';
$redirect_time_ms = 3000;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Lenza Optic POS</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-body">
    <div class="welcome-box">
        <h1>Welcome!</h1>
        <p>You have successfully logged in as:</p>
        
        <h2><?php echo htmlspecialchars(ucfirst($username)); ?> (<?php echo htmlspecialchars(ucfirst($role)); ?>)</h2>
        
        <div class="loading-bar">
            <div id="loading-progress" class="loading-progress" 
                 style="transition: width <?php echo $redirect_time_ms / 1000; ?>s linear;">
            </div>
        </div>
        <p style="margin-top: 10px; font-size: 0.9em; color: #777;">Redirecting to Dashboard...</p>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var progressBar = document.getElementById('loading-progress');
            progressBar.style.width = '100%';
        });
        
        setTimeout(function() {
            window.location.href = 'index.php';
        }, <?php echo $redirect_time_ms; ?>);
    </script>
</body>
</html>