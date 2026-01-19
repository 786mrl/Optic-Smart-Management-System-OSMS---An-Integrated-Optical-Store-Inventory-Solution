<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    // Pastikan user sudah login
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit();
    }

    $username = $_SESSION['username'] ?? 'User';
    $role = $_SESSION['role'] ?? 'staff'; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Frame Management - <?php echo htmlspecialchars($STORE_NAME); ?></title>
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
                    <button class="neu-button" data-url="frame_data_entry.php" onclick="handleButtonClick(this)">
                        <span class="icon">📥</span>
                        Frame Data Entry
                        <div class="led"></div>
                    </button>
                
                    <button class="neu-button" data-url="pending_records_frame.php" onclick="handleButtonClick(this)">
                        <span class="icon">⏳</span>
                        Pending Records (Staging)
                        <div class="led"></div>
                    </button>
                
                    <button class="neu-button" data-url="scan_frame.php" onclick="handleButtonClick(this)">
                        <span class="icon">📷</span>
                        Scan Frame (Preview)
                        <div class="led"></div>
                    </button>
                
                    <?php if ($role === 'admin'): ?>
                        <button class="neu-button" data-url="frame_master_database.php" onclick="handleButtonClick(this)">
                            <span class="icon">🗄️</span>
                            Frame Master Database
                            <div class="led"></div>
                        </button>
                
                        <button class="neu-button" data-url="customer_frame_purchase.php" onclick="handleButtonClick(this)">
                            <span class="icon">📜</span>
                            Customer Purchase History
                            <div class="led"></div>
                        </button>
                    <?php endif; ?>
                </div>            
            </div>
        </div>

        <div class="btn-group">
            <button type="button" class="back-main" onclick="window.location.href='inventory.php'">BACK TO PREVIOUS PAGE</button>
        </div>

        <footer class="footer-container">
            <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
        </footer>
    </div>

    <script>
        // Function executed when a button is clicked
        function handleButtonClick(element) {
            // 1. Get the URL from the data-url attribute
            const targetUrl = element.getAttribute('data-url');
            
            // 2. Save this URL to localStorage as the active button identity
            localStorage.setItem('activeMenuUrl', targetUrl);
            
            // 3. Add the active class immediately (for an instant visual effect)
            document.querySelectorAll('.neu-button').forEach(btn => btn.classList.remove('active'));
            element.classList.add('active');

            // 4. Navigate to the page
            window.location.href = targetUrl;
        }

        // Function that runs automatically when the page is refreshed or returned to (Back)
        window.addEventListener('DOMContentLoaded', () => {
            const activeUrl = localStorage.getItem('activeMenuUrl');
            
            if (activeUrl) {
                document.querySelectorAll('.neu-button').forEach(btn => {
                    // If the button's data-url matches the one in memory, activate it!
                    if (btn.getAttribute('data-url') === activeUrl) {
                        btn.classList.add('active');
                    }
                });
            }
        });
    </script>
</body>
</html>