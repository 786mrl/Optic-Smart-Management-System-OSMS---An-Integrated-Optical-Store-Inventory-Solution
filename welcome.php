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
    <?php include 'pwa_head.php'; ?>
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
        // Tunggu sync selesai, baru redirect
        // Maksimal tunggu 6 detik, kalau belum selesai tetap redirect
        const MAX_WAIT = 6000;
        const start = Date.now();

        function checkAndRedirect() {
            const elapsed = Date.now() - start;
            
            // Cek apakah sync sudah selesai atau timeout
            if (typeof SyncManager === 'undefined' || elapsed >= MAX_WAIT) {
                window.location.href = 'index.php';
                return;
            }

            SyncManager.autoSync(false).then(() => {
                window.location.href = 'index.php';
            }).catch(() => {
                window.location.href = 'index.php';
            });
        }

        // Mulai setelah 1.5 detik (biar welcome screen keliatan)
        setTimeout(checkAndRedirect, 1500);
    </script>
</body>
</html>