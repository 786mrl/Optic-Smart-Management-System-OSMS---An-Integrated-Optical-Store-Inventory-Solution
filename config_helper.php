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

if (isset($conn) && $conn->ping()) {
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

// Note: Ensure the connection is closed in the main file (e.g., index.php)
?>