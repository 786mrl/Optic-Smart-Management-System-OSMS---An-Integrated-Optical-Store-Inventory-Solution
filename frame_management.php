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
        <button class="neu-button" onclick="selectBtn(this); window.location.href='frame_data_entry.php';">
            <span class="icon">📥</span>
            Frame Data Entry
            <div class="led"></div>
        </button>

        <button class="neu-button" onclick="selectBtn(this); window.location.href='pending_records_frame.php';">
            <span class="icon">⏳</span>
            Pending Records (Staging)
            <div class="led"></div>
        </button>

        <?php if ($role === 'admin'): ?>
            <button class="neu-button active" onclick="selectBtn(this); window.location.href='frame_master_database.php';">
                <span class="icon">🗄️</span>
                Frame Master Database
                <div class="led"></div>
            </button>

            <button class="neu-button" onclick="selectBtn(this); window.location.href='customer_frame_purchase.php';">
                <span class="icon">📜</span>
                Customer Purchase History
                <div class="led"></div>
            </button>
        <?php endif; ?>
        
        <footer style="background-color: #1e2124">
            <button style="width: auto; height: auto" class="neu-button" onclick="selectBtn(this); window.location.href='inventory.php';">
                BACK TO PREVIOUS PAGE
            </button>
        </footer>
        
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