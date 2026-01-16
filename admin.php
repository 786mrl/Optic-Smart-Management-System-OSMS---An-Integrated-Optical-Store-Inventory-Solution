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
        <button class="neu-button" data-url="approve_users.php" onclick="handleButtonClick(this)">
            <span class="icon">✅</span>
            Approve New Staff Users
            <div class="led"></div>
        </button>

        <button class="neu-button" data-url="manage_roles.php" onclick="handleButtonClick(this)">
            <span class="icon">🤝</span>
            Manage User Roles
            <div class="led"></div>
        </button>

        <button class="neu-button" data-url="system_config.php" onclick="handleButtonClick(this)">
            <span class="icon">⚙️</span>
            System Configuration
            <div class="led"></div>
        </button>

        <footer style="background-color: #1e2124">
            <button style="width: auto; height: auto" class="neu-button" data-url="index.php" onclick="handleButtonClick(this)">
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