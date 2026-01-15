<?php
// inventory.php
session_start();
include 'db_config.php';
include 'config_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}
$username = $_SESSION['username'] ?? 'User';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - <?php echo htmlspecialchars($STORE_NAME); ?></title>
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
        <h3>Select Module</h3>
        
        <div class="second_layer-button-container">
            <a href="frame_management.php" class="second_layer-tool-button second_layer-button">
                Frame Management
            </a>
            
            <a href="lense_management.php" class="second_layer-tool-button second_layer-button">
                Lense Management
            </a>
            <a href="other_management.php" class="second_layer-tool-button second_layer-button">
                Other
            </a>
        </div>
        
        <p style="margin-top: 40px;"><a href="index.php" class="link-back">Back to Main Menu</a></p>
    </main>

    <footer>
        <p><?php echo $COPYRIGHT_FOOTER; ?></p>
    </footer>

</body>
</html>