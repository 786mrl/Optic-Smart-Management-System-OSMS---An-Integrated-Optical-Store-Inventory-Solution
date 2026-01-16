<?php
// index.php
session_start();
$current_role = $_SESSION['role'];
$username = $_SESSION['username'];

include 'db_config.php';      // 1. DB Connection
include 'config_helper.php';  // 2. Fetch Global Settings (STORE_NAME, BRAND_IMAGE_PATH)

// 1. Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    // If not logged in, redirect to login page
    header("Location: login.php");
    exit();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Optical Store System - Main Menu</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="header-container">
        <button class="logout-btn" onclick="alert('Logging out...'); window.location.href='logout.php';">
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

    <div class="selection-container">
        <button class="neu-button" onclick="selectBtn(this); window.location.href='inventory.php';">
            <span class="icon">👓</span>
            Inventory Management
            <div class="led"></div>
        </button>

        <button class="neu-button" onclick="selectBtn(this); window.location.href='customer.php';">
            <span class="icon">📇</span>
            Customer Data Management
            <div class="led"></div>
        </button>

        <?php if ($current_role === 'admin'): ?>
            <button class="neu-button active" onclick="selectBtn(this); window.location.href='admin.php';">
                <span class="icon">⚙️</span>
                Administration
                <div class="led"></div>
            </button>

            <button class="neu-button" onclick="selectBtn(this); window.location.href='bi_report.php';">
                <span class="icon">📊</span>
                Business Intelligence Report
                <div class="led"></div>
            </button>
        <?php endif; ?>
        
        <footer class="footer-container">
            <div class="footer-text">
                <?php echo $COPYRIGHT_FOOTER; ?>
            </div>
        </footer>  
    </div>

    <script>
        function selectBtn(element) {
            document.querySelectorAll('.neu-button').forEach(btn => {
                btn.classList.remove('active');
            });
            element.classList.add('active');
        }
    </script>
    
</body>
</html>