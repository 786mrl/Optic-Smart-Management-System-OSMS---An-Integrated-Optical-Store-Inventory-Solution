<?php
// index.php theme: Dark Neumorphism
session_start();
$current_role = $_SESSION['role'];
$username = $_SESSION['username'];

include 'db_config.php';
include 'config_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include 'pwa_head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Optical Store System - Main Menu</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="main-wrapper">
    <div class="content-area">
        <div class="header-container">
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
        
        <div class="selection-container">
            <div class="button-grid">
                <button class="neu-button" data-url="inventory.php" onclick="handleButtonClick(this)">
                    <span class="icon">🏬</span>
                    Inventory Management
                    <div class="led"></div>
                </button>
            
                <button class="neu-button" data-url="customer.php" onclick="handleButtonClick(this)">
                    <span class="icon">📇</span>
                    Customer Data Management
                    <div class="led"></div>
                </button>
            
                <?php if ($current_role === 'admin'): ?>
                    <button class="neu-button" data-url="admin.php" onclick="handleButtonClick(this)">
                        <span class="icon">⚙️</span>
                        Administration
                        <div class="led"></div>
                    </button>
            
                    <button class="neu-button" data-url="bi_report.php" onclick="handleButtonClick(this)">
                        <span class="icon">📊</span>
                        Business Intelligence Report
                        <div class="led"></div>
                    </button>

                    <button class="neu-button" data-url="sync_to_supabase.php" onclick="handleButtonClick(this)">
                        <span class="icon">☁️</span>
                        Sync to Supabase
                        <div class="led"></div>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <footer class="footer-container">
        <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
    </footer>
</div>

    <script>
        function handleButtonClick(element) {
            const targetUrl = element.getAttribute('data-url');
            localStorage.setItem('activeMenuUrl', targetUrl);
            document.querySelectorAll('.neu-button').forEach(btn => btn.classList.remove('active'));
            element.classList.add('active');
            window.location.href = targetUrl;
        }

        window.addEventListener('DOMContentLoaded', () => {
            const activeUrl = localStorage.getItem('activeMenuUrl');
            if (activeUrl) {
                document.querySelectorAll('.neu-button').forEach(btn => {
                    if (btn.getAttribute('data-url') === activeUrl) {
                        btn.classList.add('active');
                    }
                });
            }
        });
    </script>
    
</body>
</html>