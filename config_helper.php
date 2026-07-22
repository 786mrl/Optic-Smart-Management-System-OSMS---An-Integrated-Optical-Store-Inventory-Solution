<?php
// config_helper.php
// This file is responsible for fetching essential configuration data from the database
// and making it available as global variables.

// Requires a database connection (assuming db_config.php has been included before this file)
if (!isset($conn)) {
    // If $conn is not yet defined, try including db_config.php
    // Note: Ensure you call include 'db_config.php'; before calling this file in your main page.
    // Otherwise, you might insert the content of db_config.php here.
    // For this purpose, we assume db_config.php has already been called or $conn is available.
    // IF IT'S MISSING, UNCOMMENT THE LINE BELOW:
    // include 'db_config.php';
    if (!isset($conn)) {
        // If the connection is still missing (e.g., on a login/register page),
        // we need a minimal connection:
        // Attempt connection again
        // $conn = new mysqli("localhost", "root", "", "optic_pos_db");
        // if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
    }
}

$GLOBALS['SYSTEM_CONFIG'] = [];

// Array containing essential configuration keys needed throughout the system
$required_keys = [
    'store_name',
    'brand_image_location',
    'store_address', // NEW: Add store_address
    'currency_code',
    'copyright_footer'
];

if (isset($conn)) {
    $keys_string = "'" . implode("','", $required_keys) . "'";
    $sql_config = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($keys_string)";
    $result_config = $conn->query($sql_config);

    if ($result_config && $result_config->num_rows > 0) {
        while ($row = $result_config->fetch_assoc()) {
            // Populate the global SYSTEM_CONFIG array
            $GLOBALS['SYSTEM_CONFIG'][$row['setting_key']] = $row['setting_value'];
        }
    }
}

// Define easy-access variables (fallback if database errors)
$STORE_NAME = $GLOBALS['SYSTEM_CONFIG']['store_name'] ?? 'LENZA OPTIC';
$BRAND_IMAGE_PATH = $GLOBALS['SYSTEM_CONFIG']['brand_image_location'] ?? 'image/brand_image.png';
$STORE_ADDRESS = $GLOBALS['SYSTEM_CONFIG']['store_address'] ?? 'Default Store Address'; // NEW: Store Address
$COPYRIGHT_FOOTER = $GLOBALS['SYSTEM_CONFIG']['copyright_footer'] ?? '&copy; 2026 Optical Store POS'; // NEW: Add this

// GEMINI API KEY
// -----------------------------------------------------------------
// IMPORTANT: Do NOT hardcode the key here. If a key is hardcoded and
// pushed to GitLab/GitHub, Google's automated secret-scanning will
// detect it and auto-revoke it, which is why the key kept becoming
// invalid after every update.
//
// Instead, the key is now read from an environment variable
// (GEMINI_API_KEY) that lives only on the server, never in Git.
//
// HOW TO SET IT:
//   1) Local / XAMPP (Windows dev):
//      Set it once via Windows System Environment Variables,
//      then restart Apache:
//          setx GEMINI_API_KEY "AIzaSy...your-new-key..."
//
//   2) Production server (cPanel / Linux):
//      Add to your Apache/Nginx vhost config, e.g. for Apache:
//          SetEnv GEMINI_API_KEY "AIzaSy...your-new-key..."
//      or export it in your deploy script / systemd unit before
//      PHP-FPM starts.
//
//   3) Quick fallback (NOT recommended for production, but useful for
//      local testing without touching server config): create a file
//      named "local_secrets.php" in the SAME folder as this file,
//      add it to .gitignore, and put this single line inside it:
//          <?php putenv('GEMINI_API_KEY=AIzaSy...your-new-key...');
//      It will be picked up automatically below if it exists.
if (file_exists(__DIR__ . '/local_secrets.php')) {
    include __DIR__ . '/local_secrets.php';
}

$gemini_key = getenv('GEMINI_API_KEY');
if ($gemini_key !== false && $gemini_key !== '') {
    define('GEMINI_API_KEY', $gemini_key);
}
// If not set anywhere, GEMINI_API_KEY simply stays undefined and
// analyze_prescription.php will show a clear "not configured" error
// instead of silently using a dead/leaked key.

// Note: Ensure the connection is closed in the main file (e.g., index.php)
?>