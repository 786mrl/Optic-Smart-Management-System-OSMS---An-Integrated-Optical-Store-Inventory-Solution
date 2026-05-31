<?php
// index.php theme: Dark Neumorphism
error_reporting(0);
ini_set('display_errors', 0);
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$current_role = $_SESSION['role'];
$username = $_SESSION['username'];

include 'db_config.php';
include 'config_helper.php';

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

                    <button class="neu-button" data-url="activity_log.php" onclick="handleButtonClick(this)">
                        <span class="icon">📋</span>
                        Activity Log & Sync
                        <?php
                        $pending_count = 0;
                        $al = $conn->query("SELECT COUNT(*) as c FROM activity_log WHERE sync_flag = 1");
                        if ($al) $pending_count = (int)$al->fetch_assoc()['c'];
                        ?>
                        <?php if ($pending_count > 0): ?>
                        <span style="
                            position:absolute; top:8px; right:8px;
                            background:#f6a623; color:#1e2022;
                            border-radius:10px; padding:2px 7px;
                            font-size:10px; font-weight:700;
                        "><?= $pending_count ?></span>
                        <?php endif; ?>
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
