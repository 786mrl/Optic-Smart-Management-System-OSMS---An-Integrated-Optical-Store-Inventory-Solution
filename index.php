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

    <header class="main-header">
        <div class="header-content">
            <div class="brand-info">
                <img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" class="brand-logo">
                
                <h1 class="brand-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                
                <p class="store-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p> 
            </div>            
            <a href="logout.php" class="logout-button">Logout</a>
        </div>
    </header>

    <main class="main-content">
        <h3>Select Module:</h3>
        <div class="button-container">
            
            <a href="inventory.php" class="menu-button" id="btn-inventory">
                Inventory Management
            </a>
            
            <a href="customer.php" class="menu-button" id="btn-customer">
                Customer Data Management
            </a>
            
            <?php if ($current_role === 'admin'): ?>
            <a href="admin.php" class="menu-button" id="btn-admin">
                Administration
            </a>
            <?php endif; ?>
            
            <?php if ($current_role === 'admin'): ?>
            <a href="bi_report.php" class="menu-button" id="btn-bi">
                Business Intelligence Report
            </a>
            <?php endif; ?>
            
            <?php if ($current_role === 'staff'): ?>
                <div></div>
                <div></div>
            <?php endif; ?>

        </div>
    </main>

    <footer>
        <p><?php echo $COPYRIGHT_FOOTER; ?></p>
    </footer>
    
</body>
</html>