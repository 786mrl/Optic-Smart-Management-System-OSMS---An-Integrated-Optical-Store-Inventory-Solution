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
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_settings'])) {
        $success = true;
        
        // WHITE-LIST: Define keys allowed to be updated through this form
        $allowed_keys = [
            'store_name', 'store_phone', 'store_address', 'brand_image_location', 
            'copyright_footer', 'currency_code', 'timezone', 'tax_rate_percent', 
            'uom_frame_default', 'uom_lens_default', 'uom_other_default', 
            'low_stock_threshold', 'starting_invoice_number', 'receipt_footer_msg', 
            'invoice_format_prefix'
        ];

        $conn->begin_transaction(); // Use transaction for data consistency

        // --- File Upload Handling Logic ---
        if (isset($_FILES['logo_upload']) && $_FILES['logo_upload']['error'] === 0) {
            // Matches your existing 'image' folder
            $target_dir = "image/"; 
            
            // Ensure the 'image' directory exists; if not, create it automatically
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

            $file_extension = pathinfo($_FILES["logo_upload"]["name"], PATHINFO_EXTENSION);
            
            // Generate a unique filename to prevent overwriting existing logo files
            $new_filename = "brand_logo_" . time() . "." . $file_extension;
            $target_file = $target_dir . $new_filename;

            $allowed_types = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array(strtolower($file_extension), $allowed_types)) {
                // Delete the old file if it exists (before uploading the new one)
                $old_logo = $settings['brand_image_location']['value'] ?? '';
                if (!empty($old_logo) && file_exists($old_logo)) {
                    unlink($old_logo);
                }
                if (move_uploaded_file($_FILES["logo_upload"]["tmp_name"], $target_file)) {
                    // This path will be stored in the database (e.g., image/brand_logo_12345.png)
                    $_POST['brand_image_location'] = $target_file;
                }
            }
        }

        try {
            foreach ($_POST as $key => $value) {
                if (in_array($key, $allowed_keys)) {
                    $sanitized_value = trim($value); // htmlspecialchars is best applied during output
                    $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                    $stmt->bind_param("ss", $sanitized_value, $key);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            $conn->commit();
            $message = "<div class='alert-box success'>Settings successfully updated.</div>";
        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='alert-box error'>Error updating settings: " . $e->getMessage() . "</div>";
        }
        
        // Refresh settings data after update so the form displays the latest values
        // (Optional: perform a redirect to prevent form resubmission on page refresh)
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
    <style>
        label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            margin-left: 5px;
        }
        .alert-box {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            /* Inset Neumorphic Effect */
            box-shadow: inset 4px 4px 8px var(--shadow-dark), 
                        inset -4px -4px 8px var(--shadow-light);
        }

        .success { color: #00ff88; border: 1px solid rgba(0, 255, 136, 0.2); }
        .error { color: #ff3131; border: 1px solid rgba(255, 49, 49, 0.2); }

        /* Distinguish readonly inputs visually */
        .read-only-section input {
            opacity: 0.6;
            cursor: not-allowed;
            background: rgba(255, 255, 255, 0.02);
        }
    </style>
</head>
<body>
    <div class="main-wrapper">
        <div class="content-area" style="flex-direction: column">
            <div class="header-container" style="
            margin-left: auto; 
            margin-right: auto; 
            width: 100%;">
                <button class="logout-btn" onclick="window.location.href='logout.php';">
                    <span>Logout</span>
                </button>
        
                <div class="brand-section">
                    <div class="logo-box">
                        <img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>?t=<?php echo time(); ?>" alt="Brand Logo" style="height: 40px;">
                </div>
                    <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                    <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
                </div>
            </div>
            
            <div class="config-window" style="
            margin-left: auto; 
            margin-right: auto; 
            width: 100%; max-width: none;">
                <div class="window-card" style="max-width: none">
                    <div class="header-title">
                        <h2>System Configuration</h2>
                        <p style="color: var(--text-muted); font-size: 13px;">Manage your global system variables and defaults.</p>
                    </div>

                    <?php if ($message): ?>
                        <div class="message-container">
                            <?php echo $message; ?>
                        </div>
                    <?php endif; ?>
            
                    <form action="system_config.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="update_settings" value="1">
                        
                        <div class="config-section">
                            <div class="section-header">🏢 Company & Store Details</div>
                            
                            <div class="input-grid">
                                <div class="input-group">
                                    <label>Store Name</label>
                                    <input type="text" class="input-field" name="store_name" value="<?php echo htmlspecialchars($settings['store_name']['value'] ?? ''); ?>" required>
                                    <p class="description"><?php echo $settings['store_name']['description'] ?? ''; ?></p>
                                </div>
                                
                                <div class="input-group">
                                    <label>Store Phone</label>
                                    <input type="text" class="input-field" name="store_phone" value="<?php echo htmlspecialchars($settings['store_phone']['value'] ?? ''); ?>">
                                    <p class="description"><?php echo $settings['store_phone']['description'] ?? ''; ?></p>
                                </div>
                                
                                <div class="input-group full-width">
                                    <label>Store Address</label>
                                    <input type="text" name="store_address" class="input-field" value="<?php echo htmlspecialchars($settings['store_address']['value'] ?? ''); ?>">
                                    <p class="description"><?php echo $settings['store_address']['description'] ?? ''; ?></p>
                                </div>
                                
                                <div class="input-group full-width">
                                    <label>Company Logo (Brand Image)</label>
                                    <div class="upload-wrapper" style="display: flex; gap: 10px; align-items: center;">
                                        <img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>?t=<?php echo time(); ?>" id="previewLogo" style="height: 40px; border-radius: 5px; box-shadow: 2px 2px 5px var(--shadow-dark);">
                                        
                                        <input type="file" name="logo_upload" id="logoInput" class="input-field" accept="image/*" style="padding: 5px;">
                                    </div>
                                    <p class="description">Upload your store logo (Formats: JPG, PNG, WEBP).</p>
                                    
                                    <input type="hidden" name="brand_image_location" value="<?php echo htmlspecialchars($settings['brand_image_location']['value'] ?? ''); ?>">
                                </div>
                
                                <div class="input-group full-width">
                                    <label>Copyright Footer Message</label>
                                    <input type="text" class="input-field" name="copyright_footer" value="<?php echo htmlspecialchars($settings['copyright_footer']['value'] ?? ''); ?>">
                                    <p class="description"><?php echo $settings['copyright_footer']['description'] ?? 'The copyright message displayed in the footer of all application pages.'; ?></p>
                                </div>                
                            </div>
                        </div>
            
                        <div class="config-section">
                            <div class="section-header">🌍 Localization</div>
                            
                            <div class="input-grid">
                                <div class="input-group">
                                    <label>Currency Code (e.g., IDR)</label>
                                    <input type="text" class="input-field" name="currency_code" value="<?php echo htmlspecialchars($settings['currency_code']['value'] ?? ''); ?>" maxlength="3">
                                    <p class="description"><?php echo $settings['currency_code']['description'] ?? ''; ?></p>
                                </div>
                
                                <div class="input-group">
                                    <label>Timezone (e.g., Asia/Jakarta)</label>
                                    <input type="text" class="input-field" name="timezone" value="<?php echo htmlspecialchars($settings['timezone']['value'] ?? ''); ?>">
                                    <p class="description"><?php echo $settings['timezone']['description'] ?? ''; ?></p>
                                </div>
                            </div>
                        </div>
            
                        <div class="config-section">
                            <div class="section-header">💰 Financial & Tax</div>
                            
                            <div class="input-grid">
                                <div class="input-group">
                                    <label>Tax Rate Percentage</label>
                                    <input type="number" class="input-field" step="0.01" name="tax_rate_percent" value="<?php echo htmlspecialchars($settings['tax_rate_percent']['value'] ?? ''); ?>" required>
                                    <p class="description"><?php echo $settings['tax_rate_percent']['description'] ?? ''; ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="config-section">
                            <div class="section-header">📦 Inventory Defaults</div>
                            
                            <div class="input-grid">
                                <div class="input-group">
                                    <label>UOM Default - Frame</label>
                                    <input type="text" class="input-field" name="uom_frame_default" value="<?php echo htmlspecialchars($settings['uom_frame_default']['value'] ?? ''); ?>">
                                    <p class="description"><?php echo $settings['uom_frame_default']['description'] ?? 'Default Unit of Measure (UOM) for Frame Category'; ?></p>
                                </div>
                
                                <div class="input-group">
                                    <label>UOM Default - Lensa</label>
                                    <input type="text" class="input-field" name="uom_lens_default" value="<?php echo htmlspecialchars($settings['uom_lens_default']['value'] ?? ''); ?>">
                                    <p class="description"><?php echo $settings['uom_lens_default']['description'] ?? 'Default Unit of Measure (UOM) for Lens Category'; ?></p>
                                </div>
                
                                <div class="input-group">
                                    <label>UOM Default - Lainnya (Other)</label>
                                    <input type="text" class="input-field" name="uom_other_default" value="<?php echo htmlspecialchars($settings['uom_other_default']['value'] ?? ''); ?>">
                                    <p class="description"><?php echo $settings['uom_other_default']['description'] ?? 'Default Unit of Measure (UOM) for Other Product Categories'; ?></p>
                                </div>
                
                                <div class="input-group">
                                    <label>Low Stock Threshold (Global)</label>
                                    <input type="number" class="input-field" name="low_stock_threshold" value="<?php echo htmlspecialchars($settings['low_stock_threshold']['value'] ?? ''); ?>" required>
                                    <p class="description"><?php echo $settings['low_stock_threshold']['description'] ?? 'Global Low Stock Warning Limit (Units)'; ?></p>
                                </div>
                
                                <div class="input-group full-width">
                                    <label>Starting Invoice Number (Sequence)</label>
                                    <input type="text" class="input-field" name="starting_invoice_number" value="<?php echo htmlspecialchars($settings['starting_invoice_number']['value'] ?? ''); ?>" required> 
                                    <p class="description"><?php echo $settings['starting_invoice_number']['description'] ?? 'The starting sequence number for daily/monthly invoices (resets automatically).'; ?></p>
                                </div>          
                            </div>                
                        </div>
            
                        <div class="config-section">
                            <div class="section-header">🧾 Receipt & Invoice</div>
                            <div class="input-grid">
                                <div class="input-group">
                                    <label>Receipt Footer Message</label>
                                    <input type="text" class="input-field" name="receipt_footer_msg" value="<?php echo htmlspecialchars($settings['receipt_footer_msg']['value'] ?? ''); ?>">
                                    <p class="description"><?php echo $settings['receipt_footer_msg']['description'] ?? ''; ?></p>
                                </div>
                
                                <div class="input-group">
                                    <label>Invoice Format Prefix</label>
                                    <input type="text" class="input-field" name="invoice_format_prefix" value="<?php echo htmlspecialchars($settings['invoice_format_prefix']['value'] ?? ''); ?>">
                                    <p class="description"><?php echo $settings['invoice_format_prefix']['description'] ?? ''; ?></p>
                                </div>
                            </div>
            
                        </div>
            
                        <div class="config-section read-only-section">
                            <div class="section-header">🛡️ Database Backup (View Only)</div>
                            
                            <div class="input-grid">
                                <div class="input-group">
                                    <label>Last Backup Date</label>
                                    <input type="text" class="input-field"  value="<?php echo htmlspecialchars($settings['last_backup_date']['value'] ?? ''); ?>" readonly>
                                    <p class="description"><?php echo $settings['last_backup_date']['description'] ?? ''; ?></p>
                                </div>
                
                                <div class="input-group">
                                    <label>Backup Location (Server Path)</label>
                                    <input type="text" class="input-field"  value="<?php echo htmlspecialchars($settings['backup_location']['value'] ?? ''); ?>" readonly>
                                    <p class="description"><?php echo $settings['backup_location']['description'] ?? ''; ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="action-bar">
                            <button type="submit" class="btn-save">Save All Configuration</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="btn-group">
            <button type="button" class="back-main" onclick="window.location.href='admin.php'">BACK TO PREVIOUS PAGE</button>
        </div>

        <footer class="footer-container">
            <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
        </footer>
    </div>    

    <script>
        document.getElementById('logoInput').onchange = function (evt) {
            const [file] = this.files;
            if (file) {
                // Creates a temporary URL to display the image preview before it is uploaded to the server
                document.getElementById('previewLogo').src = URL.createObjectURL(file);
            }
        };
    </script>
</body>
</html>