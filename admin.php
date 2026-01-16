<?php
// admin.php
session_start();

$username = $_SESSION['username'] ?? 'Guest';
$current_role = $_SESSION['role'] ?? 'N/A';

include 'db_config.php';      // 1. DB Connection
include 'config_helper.php';  // 2. Fetch Global Settings (STORE_NAME, BRAND_IMAGE_PATH)

// Check if user is logged in and is an Admin
if ($_SESSION['role'] !== 'admin') {
    // Redirect non-admins or guests
    header("Location: index.php"); 
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration Module</title>
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
        <h3>Admin Tools</h3>        
        <div class="button-container">
            <a href="approve_users.php" class="menu-button">
                Approve New Staff Users
            </a>
            
            <a href="manage_roles.php" class="menu-button">
                Manage User Roles
            </a>
            <a href="system_config.php" class="menu-button">
                System Configuration
            </a>
        </div>
        
        <p style="margin-top: 40px;"><a href="index.php" class="link-back">Back to Main Menu</a></p>
    </main>

    <footer>
        <p><?php echo $COPYRIGHT_FOOTER; ?></p>
    </footer>
    
</body>
</html>