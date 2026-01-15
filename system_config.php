<?php
// system_config.php
session_start();

$username = $_SESSION['username'] ?? 'Guest';
$current_role = $_SESSION['role'] ?? 'N/A';

include 'db_config.php';      // 1. DB Connection
include 'config_helper.php';  // 2. Fetch Global Settings (STORE_NAME, BRAND_IMAGE_PATH)

// Security check: Must be Admin
if ($_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$message = '';
$settings = [];

// --- Logic to Handle Configuration Update ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $success = true;
    
    // Loop through POST data to update each setting
    foreach ($_POST as $key => $value) {
        // Skip hidden field or specific actions if any
        if ($key === 'update_settings') continue;

        // Sanitize and trim the value
        $sanitized_value = trim(htmlspecialchars($value));
        
        // Use prepared statement to update the setting_value based on setting_key
        $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->bind_param("ss", $sanitized_value, $key);
        
        if (!$stmt->execute()) {
            $success = false;
            break; // Stop loop on first error
        }
        $stmt->close();
    }

    if ($success) {
        $message = "<p class='success-message'>Settings successfully updated.</p>";
    } else {
        $message = "<p class='error-message'>Error updating settings.</p>";
    }
}

// --- Logic to Fetch Current Settings ---
$sql_fetch = "SELECT setting_key, setting_value, description FROM settings";
$result = $conn->query($sql_fetch);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = [
            'value' => $row['setting_value'],
            'description' => $row['description']
        ];
    }
}

close_db_connection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Configuration</title>
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
        <h3>System-Wide Settings</h3>
        <?php echo $message; ?>

        <form action="system_config.php" method="POST" class="config-form">
            <input type="hidden" name="update_settings" value="1">
            
            <div class="config-section">
                <h4>1. Company/Store Details</h4>
                <div class="input-group">
                    <label>Store Name</label>
                    <input type="text" name="store_name" value="<?php echo htmlspecialchars($settings['store_name']['value'] ?? ''); ?>" required>
                    <small><?php echo $settings['store_name']['description'] ?? ''; ?></small>
                </div>
                <div class="input-group">
                    <label>Store Address</label>
                    <textarea name="store_address"><?php echo htmlspecialchars($settings['store_address']['value'] ?? ''); ?></textarea>
                    <small><?php echo $settings['store_address']['description'] ?? ''; ?></small>
                </div>
                <div class="input-group">
                    <label>Store Phone</label>
                    <input type="text" name="store_phone" value="<?php echo htmlspecialchars($settings['store_phone']['value'] ?? ''); ?>">
                    <small><?php echo $settings['store_phone']['description'] ?? ''; ?></small>
                </div>
                
                <div class="input-group">
                    <label>Brand Image Location (Path)</label>
                    <input type="text" name="brand_image_location" value="<?php echo htmlspecialchars($settings['brand_image_location']['value'] ?? ''); ?>">
                    <small><?php echo $settings['brand_image_location']['description'] ?? 'File path or URL for the company brand logo image.'; ?></small>
                </div>

                <div class="input-group">
                    <label>Copyright Footer Message</label>
                    <input type="text" name="copyright_footer" value="<?php echo htmlspecialchars($settings['copyright_footer']['value'] ?? ''); ?>">
                    <small><?php echo $settings['copyright_footer']['description'] ?? 'The copyright message displayed in the footer of all application pages.'; ?></small>
                </div>
                
            </div>

            <div class="config-section">
                <h4>2. Currency and Timezone</h4>
                <div class="input-group">
                    <label>Currency Code (e.g., IDR)</label>
                    <input type="text" name="currency_code" value="<?php echo htmlspecialchars($settings['currency_code']['value'] ?? ''); ?>" maxlength="3">
                    <small><?php echo $settings['currency_code']['description'] ?? ''; ?></small>
                </div>
                <div class="input-group">
                    <label>Timezone (e.g., Asia/Jakarta)</label>
                    <input type="text" name="timezone" value="<?php echo htmlspecialchars($settings['timezone']['value'] ?? ''); ?>">
                    <small><?php echo $settings['timezone']['description'] ?? ''; ?></small>
                </div>
            </div>

            <div class="config-section">
                <h4>3. Financial and Tax Settings</h4>
                <div class="input-group">
                    <label>Tax Rate Percentage</label>
                    <input type="number" step="0.01" name="tax_rate_percent" value="<?php echo htmlspecialchars($settings['tax_rate_percent']['value'] ?? ''); ?>" required>
                    <small><?php echo $settings['tax_rate_percent']['description'] ?? ''; ?></small>
                </div>
            </div>
            
            <div class="config-section">
                <h4>4. Inventory Defaults</h4>
                <div class="input-group">
                    <label>UOM Default - Frame</label>
                    <input type="text" name="uom_frame_default" value="<?php echo htmlspecialchars($settings['uom_frame_default']['value'] ?? ''); ?>">
                    <small><?php echo $settings['uom_frame_default']['description'] ?? 'Default Unit of Measure (UOM) for Frame Category'; ?></small>
                </div>

                <div class="input-group">
                    <label>UOM Default - Lensa</label>
                    <input type="text" name="uom_lens_default" value="<?php echo htmlspecialchars($settings['uom_lens_default']['value'] ?? ''); ?>">
                    <small><?php echo $settings['uom_lens_default']['description'] ?? 'Default Unit of Measure (UOM) for Lens Category'; ?></small>
                </div>

                <div class="input-group">
                    <label>UOM Default - Lainnya (Other)</label>
                    <input type="text" name="uom_other_default" value="<?php echo htmlspecialchars($settings['uom_other_default']['value'] ?? ''); ?>">
                    <small><?php echo $settings['uom_other_default']['description'] ?? 'Default Unit of Measure (UOM) for Other Product Categories'; ?></small>
                </div>

                <div class="input-group">
                    <label>Low Stock Threshold (Global)</label>
                    <input type="number" name="low_stock_threshold" value="<?php echo htmlspecialchars($settings['low_stock_threshold']['value'] ?? ''); ?>" required>
                    <small><?php echo $settings['low_stock_threshold']['description'] ?? 'Global Low Stock Warning Limit (Units)'; ?></small>
                </div>

                <div class="input-group">
                    <label>Starting Invoice Number (Sequence)</label>
                    <input type="text" name="starting_invoice_number" value="<?php echo htmlspecialchars($settings['starting_invoice_number']['value'] ?? ''); ?>" required> 
                    <small><?php echo $settings['starting_invoice_number']['description'] ?? 'The starting sequence number for daily/monthly invoices (resets automatically).'; ?></small>
                </div>
                
            </div>

            <div class="config-section">
                <h4>5. Receipt & Invoice Settings</h4>
                <div class="input-group">
                    <label>Receipt Footer Message</label>
                    <input type="text" name="receipt_footer_msg" value="<?php echo htmlspecialchars($settings['receipt_footer_msg']['value'] ?? ''); ?>">
                    <small><?php echo $settings['receipt_footer_msg']['description'] ?? ''; ?></small>
                </div>
                <div class="input-group">
                    <label>Invoice Format Prefix</label>
                    <input type="text" name="invoice_format_prefix" value="<?php echo htmlspecialchars($settings['invoice_format_prefix']['value'] ?? ''); ?>">
                    <small><?php echo $settings['invoice_format_prefix']['description'] ?? ''; ?></small>
                </div>
            </div>

            <div class="config-section read-only-section">
                <h4>6. Database Backup Settings (Read-Only)</h4>
                <div class="input-group">
                    <label>Last Backup Date</label>
                    <input type="text" value="<?php echo htmlspecialchars($settings['last_backup_date']['value'] ?? ''); ?>" readonly>
                    <small><?php echo $settings['last_backup_date']['description'] ?? ''; ?></small>
                </div>
                <div class="input-group">
                    <label>Backup Location (Server Path)</label>
                    <input type="text" value="<?php echo htmlspecialchars($settings['backup_location']['value'] ?? ''); ?>" readonly>
                    <small><?php echo $settings['backup_location']['description'] ?? ''; ?></small>
                </div>
            </div>
            
            <button type="submit" class="submit-config-button">Save Configuration</button>
        </form>

        <p style="margin-top: 40px;"><a href="admin.php" class="link-back">Back to Administration</a></p>
    </main>

    <footer>
        <p><?php echo $COPYRIGHT_FOOTER; ?></p>
    </footer>
    
</body>
</html>