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
    $main_admin_error = '';

    // --- Fetch Current Settings FIRST (needed to get old file paths before update) ---
    $sql_fetch = "SELECT setting_key, setting_value, description FROM settings";
    $result_pre = $conn->query($sql_fetch);
    if ($result_pre && $result_pre->num_rows > 0) {
        while ($row = $result_pre->fetch_assoc()) {
            $settings[$row['setting_key']] = [
                'value' => $row['setting_value'],
                'description' => $row['description']
            ];
        }
    }

    // --- Determine if the currently logged-in user IS the Main Admin (used for both POST handling and HTML rendering) ---
    $current_main_admin_username = $settings['main_admin_username']['value'] ?? '';
    $is_main_admin_user = ($current_main_admin_username !== '' && ($_SESSION['username'] ?? '') === $current_main_admin_username);

    // --- Logic to Handle Configuration Update ---
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_settings'])) {
        $success = true;
        
        // WHITE-LIST: Define keys allowed to be updated through this form
        $allowed_keys = [
            'store_name', 'store_phone', 'store_address', 'brand_image_location', 
            'copyright_footer', 
            'starting_invoice_number', 
            'invoice_format_prefix', 'barcode_guide_image_location',
            'lens_stock_lead_time_days', 'lens_lab_lead_time_days'
        ];

        // --- Login Shortcut keys: only allowed if the current logged-in user IS the Main Admin ---
        // AND the Main Admin's password is re-verified for this submission.
        if ($is_main_admin_user) {
            $login_shortcut_verify_password = $_POST['login_shortcut_verify'] ?? '';
            $login_shortcut_fields_present = isset($_POST['main_admin_shortcut_username'])
                || isset($_POST['main_admin_shortcut_username_init'])
                || isset($_POST['main_admin_shortcut_password'])
                || isset($_POST['main_admin_shortcut_password_init']);

            if ($login_shortcut_fields_present && $login_shortcut_verify_password !== '') {
                $stmt = $conn->prepare("SELECT password_hash FROM users WHERE username = ? AND role = 'admin'");
                $stmt->bind_param("s", $current_main_admin_username);
                $stmt->execute();
                $res = $stmt->get_result();
                $login_shortcut_admin_row = $res->fetch_assoc();
                $stmt->close();

                if ($login_shortcut_admin_row && password_verify($login_shortcut_verify_password, $login_shortcut_admin_row['password_hash'])) {
                    $allowed_keys = array_merge($allowed_keys, [
                        'main_admin_shortcut_username',
                        'main_admin_shortcut_username_init',
                        'main_admin_shortcut_password',
                        'main_admin_shortcut_password_init'
                    ]);
                } else {
                    $main_admin_error = "Incorrect Main Admin password. The login shortcut settings were not changed.";
                }
            }
        }

        // --- Main Admin Username: separate protected key, handled outside the white-list loop ---
        $main_admin_key = 'main_admin_username';
        $current_main_admin_username = $settings[$main_admin_key]['value'] ?? '';
        $new_main_admin_username_input = isset($_POST['main_admin_username_new']) ? trim($_POST['main_admin_username_new']) : '';
        $verify_password = $_POST['main_admin_username_verify'] ?? '';

        // Only process if the value was actually changed AND a verify password was supplied
        if ($new_main_admin_username_input !== '' && $new_main_admin_username_input !== $current_main_admin_username && $verify_password !== '') {
            // Look up the current Main Admin's password hash
            $stmt = $conn->prepare("SELECT password_hash FROM users WHERE username = ? AND role = 'admin'");
            $stmt->bind_param("s", $current_main_admin_username);
            $stmt->execute();
            $res = $stmt->get_result();
            $main_admin_row = $res->fetch_assoc();
            $stmt->close();

            if ($main_admin_row && password_verify($verify_password, $main_admin_row['password_hash'])) {
                $new_main_admin_username = $new_main_admin_username_input;

                // Ensure the new username exists and is an admin
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND role = 'admin'");
                $stmt->bind_param("s", $new_main_admin_username);
                $stmt->execute();
                $res2 = $stmt->get_result();
                $new_main_admin_row = $res2->fetch_assoc();
                $stmt->close();

                if ($new_main_admin_row) {
                    $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                    $stmt->bind_param("ss", $new_main_admin_username, $main_admin_key);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $main_admin_error = "The new Main Admin username does not exist or is not an admin. The Main Admin was not changed.";
                }
            } else {
                $main_admin_error = "Incorrect Main Admin password. The Main Admin was not changed.";
            }
        }

        // --- Database Backup Blocking Time: protected key, editable only after verifying the Main Admin's password ---
        $backup_blocking_verify_password = $_POST['db_backup_blocking_time_verify'] ?? '';
        if (isset($_POST['db_backup_blocking_time']) && $backup_blocking_verify_password !== '') {
            $stmt = $conn->prepare("SELECT password_hash FROM users WHERE username = ? AND role = 'admin'");
            $stmt->bind_param("s", $current_main_admin_username);
            $stmt->execute();
            $res = $stmt->get_result();
            $backup_blocking_admin_row = $res->fetch_assoc();
            $stmt->close();

            if ($backup_blocking_admin_row && password_verify($backup_blocking_verify_password, $backup_blocking_admin_row['password_hash'])) {
                $allowed_keys[] = 'db_backup_blocking_time';
            } else {
                $main_admin_error = "Incorrect Main Admin password. The Database Backup Blocking Time was not changed.";
            }
        }

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

        // --- Barcode Guide Image Upload Handling Logic ---
        if (isset($_FILES['barcode_guide_upload']) && $_FILES['barcode_guide_upload']['error'] === 0) {
            $target_dir = "image/"; 
            
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

            $file_extension = pathinfo($_FILES["barcode_guide_upload"]["name"], PATHINFO_EXTENSION);
            
            // Generate a unique filename to prevent overwriting existing barcode guide files
            $new_filename = "barcode_guide_" . time() . "." . $file_extension;
            $target_file = $target_dir . $new_filename;

            $allowed_types = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array(strtolower($file_extension), $allowed_types)) {
                // Delete the old file if it exists (before uploading the new one)
                $old_barcode = $settings['barcode_guide_image_location']['value'] ?? '';
                if (!empty($old_barcode) && file_exists($old_barcode)) {
                    unlink($old_barcode);
                }
                if (move_uploaded_file($_FILES["barcode_guide_upload"]["tmp_name"], $target_file)) {
                    // Normalize path separators for Windows (XAMPP) compatibility
                    $_POST['barcode_guide_image_location'] = str_replace('\\', '/', $target_file);
                }            }
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

    // --- Re-fetch Settings after update so form shows latest values ---
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_settings'])) {
        $settings = []; // reset
        $sql_fetch2 = "SELECT setting_key, setting_value, description FROM settings";
        $result2 = $conn->query($sql_fetch2);
        if ($result2 && $result2->num_rows > 0) {
            while ($row = $result2->fetch_assoc()) {
                $settings[$row['setting_key']] = [
                    'value' => $row['setting_value'],
                    'description' => $row['description']
                ];
            }
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

        /* Database Backup Blocking Time is editable, so exclude it from the read-only dimming above */
        .read-only-section #dbBackupBlockingTimeInput {
            opacity: 1;
            cursor: text;
            background: inherit;
        }

        /* Perkecil gap luar (antara config-window dengan window-card) - khusus halaman ini */
        .config-window {
            padding-left: 2px !important;
            padding-right: 2px !important;
        }

        /* Perkecil gap dalam (antara section-header dengan konten di bawahnya) - khusus halaman ini */
        .section-header {
            margin-bottom: 10px !important;
        }

        /* --- Collapsible config-section cards --- */
        .collapsible-section > .section-header {
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            user-select: none;
        }
        .collapsible-section > .section-header:focus-visible {
            outline: 2px solid var(--text-muted);
            outline-offset: 2px;
        }
        .section-toggle-icon {
            display: inline-block;
            font-size: 12px;
            color: var(--text-muted);
            transition: transform 0.2s ease;
            margin-left: 10px;
        }
        .collapsible-section.is-open > .section-header .section-toggle-icon {
            transform: rotate(90deg);
        }
        .config-body {
            display: none;
            overflow: hidden;
        }
        .collapsible-section.is-open > .config-body {
            display: block;
        }
    </style>
    <!-- button logout, back animation for logo -->
    <style>
        .neu-button.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
            filter: grayscale(1);
        }

        /* ===== New neumorphic style for Back & Logout buttons ===== */
        .neu-pill-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #1c1e22;
            border: none;
            border-radius: 32px;
            padding: 6px 16px 6px 6px;
            cursor: pointer;
            box-shadow:
                6px 6px 14px rgba(0, 0, 0, 0.55),
                -6px -6px 14px rgba(255, 255, 255, 0.03);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            font-family: inherit;
        }

        .neu-pill-btn:hover {
            box-shadow:
                6px 6px 16px rgba(0, 0, 0, 0.6),
                -6px -6px 16px rgba(255, 255, 255, 0.04);
        }

        .neu-pill-btn:active {
            transform: scale(0.96);
        }

        /* Overflow hidden so the icon can slide across without spilling out */
        .neu-pill-btn {
            overflow: hidden;
        }

        .neu-pill-icon {
            width: 32px;
            height: 32px;
            min-width: 32px;
            border-radius: 50%;
            background: #17181b;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 10px rgba(103, 232, 249, 0.35);
            transition: box-shadow 0.15s ease, transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Pressed state: icon slides to the right, text fades and slides out */
        .neu-pill-btn.pressed {
            box-shadow:
                inset 4px 4px 10px rgba(0, 0, 0, 0.6),
                inset -4px -4px 10px rgba(255, 255, 255, 0.03);
        }

        .neu-pill-btn.pressed .neu-pill-icon {
            transform: translateX(calc(100% + 24px));
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 18px rgba(103, 232, 249, 0.7);
        }

        .neu-pill-btn.pressed .neu-pill-text {
            opacity: 0;
            transform: translateX(15px);
        }

        .neu-pill-btn.pressed .neu-pill-icon,
        .neu-pill-btn:active .neu-pill-icon {
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 18px rgba(103, 232, 249, 0.7);
        }

        .neu-pill-icon svg {
            width: 15px;
            height: 15px;
            stroke: #7fe3f0;
            filter: drop-shadow(0 0 4px rgba(103, 232, 249, 0.8));
        }

        .neu-pill-text {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
            text-align: left;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }

        .neu-pill-text .line1 {
            font-weight: 700;
            font-size: 10px;
            letter-spacing: 0.4px;
            color: #f2f2f2;
        }

        .neu-pill-text .line2 {
            font-weight: 400;
            font-size: 9px;
            letter-spacing: 0.4px;
            color: #9a9da1;
        }

        /* Logout variant: warm amber/orange tone instead of cyan */
        .neu-pill-btn.logout-variant .neu-pill-icon {
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 10px rgba(255, 138, 101, 0.4);
        }

        .neu-pill-btn.logout-variant.pressed .neu-pill-icon {
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 18px rgba(255, 138, 101, 0.75);
        }

        .neu-pill-btn.logout-variant .neu-pill-icon svg {
            stroke: #ff8a65;
            filter: drop-shadow(0 0 4px rgba(255, 138, 101, 0.8));
        }

        /* ===== Logo zoom (fly window) effect ===== */
        .logo-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0);
            backdrop-filter: blur(0px);
            -webkit-backdrop-filter: blur(0px);
            z-index: 999;
            opacity: 0;
            pointer-events: none;
            transition: background 0.3s ease, opacity 0.3s ease, backdrop-filter 0.3s ease;
        }

        .logo-backdrop.active {
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            opacity: 1;
            pointer-events: auto;
        }

        .logo-box img {
            cursor: pointer;
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        top 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        left 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .logo-box img.zoomed {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(2.8);
            z-index: 1000;
        }

        /* Center the header block (logout button + logo/name/address group)
           on PC to match how it already appears centered on mobile. Only
           the container's own horizontal position is changed here — the
           internal layout is left exactly as in the original code. */
        .header-container {
            margin-left: auto !important;
            margin-right: auto !important;
            width: fit-content !important;
        }
    </style>
</head>
<body>
    <div class="main-wrapper">
        <div class="content-area" style="flex-direction: column">
            <div class="header-container">
                <button type="button" class="logout-btn neu-pill-btn logout-variant" id="logoutBtn" onclick="handleLogoutClick(this)">
                    <span class="neu-pill-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                            <polyline points="16 17 21 12 16 7"></polyline>
                            <line x1="21" y1="12" x2="9" y2="12"></line>
                        </svg>
                    </span>
                    <span class="neu-pill-text">
                        <span class="line1">LOGOUT</span>
                    </span>
                </button>
            
                <div class="brand-section">
                    <div class="logo-box">
                        <img id="storeLogo" src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" style="height: 40px;" onclick="zoomInLogo(this)" ondblclick="zoomOutLogo(this)">
                    </div>
                    <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                    <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
                </div>
            </div>
            
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
                    
                    <div class="config-section collapsible-section">
                        <div class="section-header" role="button" tabindex="0" aria-expanded="false">
                            <span>🏢 Company & Store Details</span>
                            <span class="section-toggle-icon">▸</span>
                        </div>
                        <div class="config-body">
                        
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
                                <label>Barcode (Comprehensive Guide Image)</label>
                                <div class="upload-wrapper" style="display: flex; gap: 10px; align-items: center;">
                                    <img src="<?php echo htmlspecialchars(str_replace('\\', '/', $settings['barcode_guide_image_location']['value'] ?? '')); ?>?t=<?php echo time(); ?>" id="previewBarcode" style="height: 40px; border-radius: 5px; box-shadow: 2px 2px 5px var(--shadow-dark);">
                                    
                                    <input type="file" name="barcode_guide_upload" id="barcodeInput" class="input-field" accept="image/*" style="padding: 5px;">
                                </div>
                                <p class="description">Upload the barcode image containing the comprehensive guide (Formats: JPG, PNG, WEBP).</p>
                                
                                <input type="hidden" name="barcode_guide_image_location" value="<?php echo htmlspecialchars(str_replace('\\', '/', $settings['barcode_guide_image_location']['value'] ?? '')); ?>">
                            </div>

                            <div class="input-group full-width">
                                <label>Copyright Footer Message</label>
                                <input type="text" class="input-field" name="copyright_footer" value="<?php echo htmlspecialchars($settings['copyright_footer']['value'] ?? ''); ?>">
                                <p class="description"><?php echo $settings['copyright_footer']['description'] ?? 'The copyright message displayed in the footer of all application pages.'; ?></p>
                            </div>                
                        </div>
                        </div>
                    </div>
        
                    
                    <div class="config-section collapsible-section">
                        <div class="section-header" role="button" tabindex="0" aria-expanded="false">
                            <span>📦 Inventory Defaults</span>
                            <span class="section-toggle-icon">▸</span>
                        </div>
                        <div class="config-body">
                        
                        <div class="input-grid">
                            <div class="input-group full-width">
                                <label>Starting Invoice Number (Sequence)</label>
                                <input type="text" class="input-field" name="starting_invoice_number" value="<?php echo htmlspecialchars($settings['starting_invoice_number']['value'] ?? ''); ?>" required> 
                                <p class="description"><?php echo $settings['starting_invoice_number']['description'] ?? 'The starting sequence number for daily/monthly invoices (resets automatically).'; ?></p>
                            </div>

                            <div class="input-group">
                                <label>Lens Order Lead Time - Stock (Days)</label>
                                <input type="number" class="input-field" min="0" name="lens_stock_lead_time_days" value="<?php echo htmlspecialchars($settings['lens_stock_lead_time_days']['value'] ?? '2'); ?>" required>
                                <p class="description"><?php echo $settings['lens_stock_lead_time_days']['description'] ?? 'Default estimated waiting time (in days) for stock lens orders'; ?></p>
                            </div>

                            <div class="input-group">
                                <label>Lens Order Lead Time - Lab (Days)</label>
                                <input type="number" class="input-field" min="0" name="lens_lab_lead_time_days" value="<?php echo htmlspecialchars($settings['lens_lab_lead_time_days']['value'] ?? '10'); ?>" required>
                                <p class="description"><?php echo $settings['lens_lab_lead_time_days']['description'] ?? 'Default estimated waiting time (in days) for lab-order lens orders'; ?></p>
                            </div>          
                        </div>                
                        </div>
                    </div>
        
                    <div class="config-section collapsible-section">
                        <div class="section-header" role="button" tabindex="0" aria-expanded="false">
                            <span>🧾 Receipt & Invoice</span>
                            <span class="section-toggle-icon">▸</span>
                        </div>
                        <div class="config-body">
                        <div class="input-grid">
                            <div class="input-group full-width">
                                <label>Invoice Format Prefix</label>
                                <input type="text" class="input-field" name="invoice_format_prefix" value="<?php echo htmlspecialchars($settings['invoice_format_prefix']['value'] ?? ''); ?>">
                                <p class="description"><?php echo $settings['invoice_format_prefix']['description'] ?? ''; ?></p>
                            </div>
                        </div>
        
                        </div>
                    </div>
        
                    <div class="config-section collapsible-section">
                        <div class="section-header" role="button" tabindex="0" aria-expanded="false">
                            <span>🔒 Main Admin</span>
                            <span class="section-toggle-icon">▸</span>
                        </div>
                        <div class="config-body">

                        <?php if (!empty($main_admin_error)): ?>
                            <div class="alert-box error" style="margin-bottom: 15px;"><?php echo htmlspecialchars($main_admin_error); ?></div>
                        <?php endif; ?>

                        <div class="input-grid">
                            <div class="input-group full-width" id="mainAdminLockedView">
                                <label>Main Admin</label>
                                <div class="upload-wrapper" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                    <input type="password" class="input-field" id="mainAdminUnlockInput" placeholder="Enter current Main Admin password" autocomplete="off" style="flex: 1; min-width: 150px;">
                                    <button type="button" class="btn-save" id="mainAdminUnlockBtn" style="white-space: nowrap;">Unlock</button>
                                </div>
                                <p class="description" id="mainAdminUnlockMsg">This field is hidden. Enter the current Main Admin's password to view and change the Main Admin.</p>
                            </div>

                            <div class="input-group full-width" id="mainAdminUnlockedView" style="display: none;">
                                <label>Main Admin Username</label>
                                <input type="text" class="input-field" name="main_admin_username_new" id="mainAdminNewUsername" autocomplete="off" value="<?php echo htmlspecialchars($settings['main_admin_username']['value'] ?? ''); ?>" placeholder="Enter new Main Admin username">
                                <p class="description">Must be an existing user with the Admin role. Changing this requires the current Main Admin's password (entered above).</p>

                                <input type="hidden" name="main_admin_username_verify" id="mainAdminVerifyHidden" value="">
                            </div>
                        </div>
                        </div>
                    </div>

                    <?php if ($is_main_admin_user): ?>
                    <div class="config-section collapsible-section">
                        <div class="section-header" role="button" tabindex="0" aria-expanded="false">
                            <span>🔑 Login Shortcut (Main Admin Only)</span>
                            <span class="section-toggle-icon">▸</span>
                        </div>
                        <div class="config-body">
                        <p class="description" style="margin-bottom: 15px;">
                            Configure the quick-login shortcut. When the shortcut "Username Trigger" is typed into the username field, it is automatically translated to the Main Admin username below. If the shortcut "Password Trigger" is then typed into the password field, it is automatically translated to the Main Admin password below. This section is visible only when logged in as the current Main Admin.
                        </p>

                        <div class="input-grid">
                            <div class="input-group full-width" id="loginShortcutLockedView">
                                <label>Login Shortcut Settings</label>
                                <div class="upload-wrapper" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                    <input type="password" class="input-field" id="loginShortcutUnlockInput" placeholder="Enter current Main Admin password" autocomplete="off" style="flex: 1; min-width: 150px;">
                                    <button type="button" class="btn-save" id="loginShortcutUnlockBtn" style="white-space: nowrap;">Unlock</button>
                                </div>
                                <p class="description" id="loginShortcutUnlockMsg">These fields are hidden. Enter the current Main Admin's password to view and change the login shortcut settings.</p>
                            </div>

                            <div class="input-group full-width" id="loginShortcutUnlockedView" style="display: none;">
                                <div class="input-grid">
                                    <div class="input-group">
                                        <label>Main Admin Username (Shortcut Target)</label>
                                        <input type="text" class="input-field" name="main_admin_shortcut_username" value="<?php echo htmlspecialchars($settings['main_admin_shortcut_username']['value'] ?? ''); ?>" autocomplete="off" placeholder="e.g. LenZa786">
                                        <p class="description">The real Main Admin username that the shortcut translates to.</p>
                                    </div>

                                    <div class="input-group">
                                        <label>Username Trigger</label>
                                        <input type="text" class="input-field" name="main_admin_shortcut_username_init" value="<?php echo htmlspecialchars($settings['main_admin_shortcut_username_init']['value'] ?? ''); ?>" autocomplete="off" placeholder="e.g. 1">
                                        <p class="description">Typing this value in the username field triggers the username shortcut.</p>
                                    </div>

                                    <div class="input-group">
                                        <label>Main Admin Password (Shortcut Target)</label>
                                        <input type="text" class="input-field" name="main_admin_shortcut_password" value="<?php echo htmlspecialchars($settings['main_admin_shortcut_password']['value'] ?? ''); ?>" autocomplete="off" placeholder="Current Main Admin password">
                                        <p class="description">Must always match the Main Admin's current actual password. Update this manually whenever the Main Admin's password is changed.</p>
                                    </div>

                                    <div class="input-group">
                                        <label>Password Trigger</label>
                                        <input type="text" class="input-field" name="main_admin_shortcut_password_init" value="<?php echo htmlspecialchars($settings['main_admin_shortcut_password_init']['value'] ?? ''); ?>" autocomplete="off" placeholder="e.g. 1">
                                        <p class="description">Typing this value in the password field (after the username shortcut is used) triggers the password shortcut.</p>
                                    </div>
                                </div>

                                <input type="hidden" name="login_shortcut_verify" id="loginShortcutVerifyHidden" value="">
                            </div>
                        </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="config-section read-only-section collapsible-section">
                        <div class="section-header" role="button" tabindex="0" aria-expanded="false">
                            <span>🛡️ Database Backup</span>
                            <span class="section-toggle-icon">▸</span>
                        </div>
                        <div class="config-body">
                        
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

                            <div class="input-group full-width" id="dbBackupBlockingTimeLockedView">
                                <label>Backup Blocking Time</label>
                                <div class="upload-wrapper" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                    <input type="password" class="input-field" id="dbBackupBlockingTimeUnlockInput" placeholder="Enter Main Admin password" autocomplete="off" style="flex: 1; min-width: 150px;">
                                    <button type="button" class="btn-save" id="dbBackupBlockingTimeUnlockBtn" style="white-space: nowrap;">Unlock</button>
                                </div>
                                <p class="description" id="dbBackupBlockingTimeUnlockMsg">This field is locked. Enter the Main Admin's password (see the Main Admin card above) to view and change the backup blocking time.</p>
                            </div>

                            <div class="input-group full-width" id="dbBackupBlockingTimeUnlockedView" style="display: none;">
                                <label>Backup Blocking Time</label>
                                <input type="time" class="input-field" name="db_backup_blocking_time" id="dbBackupBlockingTimeInput" autocomplete="off" value="<?php echo htmlspecialchars($settings['db_backup_blocking_time']['value'] ?? '20:30'); ?>">
                                <p class="description"><?php echo $settings['db_backup_blocking_time']['description'] ?? "Time of day after which database backup is blocked. Changing this requires the Main Admin's password (entered above)."; ?></p>

                                <input type="hidden" name="db_backup_blocking_time_verify" id="dbBackupBlockingTimeVerifyHidden" value="">
                            </div>
                        </div>
                        </div>
                    </div>
                    
                    <div class="action-bar">
                        <button type="submit" class="btn-save">Save All Configuration</button>
                    </div>
                </form>
            </div>
        </div>
            
        <div class="btn-group">
            <button type="button" class="neu-pill-btn" id="backBtn" onclick="handleBackClick(this)">
                <span class="neu-pill-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                </span>
                <span class="neu-pill-text">
                    <span class="line1">RETURN TO</span>
                    <span class="line2">PREVIOUS PAGE</span>
                </span>
            </button>
        </div>

        <footer class="footer-container">
            <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
        </footer>
    </div>    
    <div class="logo-backdrop" id="logoBackdrop" ondblclick="zoomOutLogo(document.getElementById('storeLogo'))"></div>
        
    <script>
        document.getElementById('logoInput').onchange = function (evt) {
            const [file] = this.files;
            if (file) {
                // Creates a temporary URL to display the image preview before it is uploaded to the server
                document.getElementById('previewLogo').src = URL.createObjectURL(file);
            }
        };

        document.getElementById('barcodeInput').onchange = function (evt) {
            const [file] = this.files;
            if (file) {
                const preview = document.getElementById('previewBarcode');
                preview.src = URL.createObjectURL(file);
                preview.style.display = 'inline-block';
            }
        };

        // --- Collapsible config-section accordion logic ---
        // Default: all sections start collapsed (no .is-open class in markup).
        // Opening one section closes all the others.
        (function () {
            var sections = document.querySelectorAll('.collapsible-section');

            sections.forEach(function (section) {
                var header = section.querySelector(':scope > .section-header');
                if (!header) return;

                function toggleSection() {
                    var willOpen = !section.classList.contains('is-open');

                    sections.forEach(function (other) {
                        other.classList.remove('is-open');
                        var otherHeader = other.querySelector(':scope > .section-header');
                        if (otherHeader) otherHeader.setAttribute('aria-expanded', 'false');
                    });

                    if (willOpen) {
                        section.classList.add('is-open');
                        header.setAttribute('aria-expanded', 'true');
                    }
                }

                header.addEventListener('click', toggleSection);
                header.addEventListener('keydown', function (evt) {
                    if (evt.key === 'Enter' || evt.key === ' ') {
                        evt.preventDefault();
                        toggleSection();
                    }
                });
            });
        })();

        // --- Main Admin unlock logic ---
        document.getElementById('mainAdminUnlockBtn').onclick = function () {
            const inputVal = document.getElementById('mainAdminUnlockInput').value;
            const msg = document.getElementById('mainAdminUnlockMsg');

            if (inputVal === '') {
                msg.textContent = 'Please enter the current Main Admin password.';
                msg.style.color = '#ff3131';
                return;
            }

            // Reveal the edit field and store the entered password for server-side verification on submit
            document.getElementById('mainAdminLockedView').style.display = 'none';
            document.getElementById('mainAdminUnlockedView').style.display = 'block';
            document.getElementById('mainAdminVerifyHidden').value = inputVal;
        };

        // --- Login Shortcut unlock logic ---
        var loginShortcutUnlockBtn = document.getElementById('loginShortcutUnlockBtn');
        if (loginShortcutUnlockBtn) {
            loginShortcutUnlockBtn.onclick = function () {
                const inputVal = document.getElementById('loginShortcutUnlockInput').value;
                const msg = document.getElementById('loginShortcutUnlockMsg');

                if (inputVal === '') {
                    msg.textContent = 'Please enter the current Main Admin password.';
                    msg.style.color = '#ff3131';
                    return;
                }

                // Reveal the edit fields and store the entered password for server-side verification on submit
                document.getElementById('loginShortcutLockedView').style.display = 'none';
                document.getElementById('loginShortcutUnlockedView').style.display = 'block';
                document.getElementById('loginShortcutVerifyHidden').value = inputVal;
            };
        }

        // --- Database Backup Blocking Time unlock logic ---
        document.getElementById('dbBackupBlockingTimeUnlockBtn').onclick = function () {
            const inputVal = document.getElementById('dbBackupBlockingTimeUnlockInput').value;
            const msg = document.getElementById('dbBackupBlockingTimeUnlockMsg');

            if (inputVal === '') {
                msg.textContent = 'Please enter the Main Admin password.';
                msg.style.color = '#ff3131';
                return;
            }

            // Reveal the edit field and store the entered password for server-side verification on submit
            document.getElementById('dbBackupBlockingTimeLockedView').style.display = 'none';
            document.getElementById('dbBackupBlockingTimeUnlockedView').style.display = 'block';
            document.getElementById('dbBackupBlockingTimeVerifyHidden').value = inputVal;
        };

    </script>
    <!-- button logout, back animation for logo -->
    <script>
        // Single tap/click on the logo zooms it in (only if not already zoomed).
        function zoomInLogo(imgEl) {
            if (imgEl.classList.contains('zoomed')) return;
            imgEl.classList.add('zoomed');
            document.getElementById('logoBackdrop').classList.add('active');
        }

        // Double tap/click zooms it back out.
        function zoomOutLogo(imgEl) {
            imgEl.classList.remove('zoomed');
            document.getElementById('logoBackdrop').classList.remove('active');
        }

        // Animate the new pill-style Back button before navigating
        function handleBackClick(element) {
            const icon = element.querySelector('.neu-pill-icon');
            const text = element.querySelector('.neu-pill-text');

            // Make sure nothing else fights with our manual animation.
            element.style.transition = 'none';
            text.style.transition = 'none';

            const startWidth = element.offsetWidth;
            // Target: just the round icon left, with the button's own
            // left/right padding preserved (6px left, 6px right when collapsed).
            const targetWidth = icon.offsetWidth + 12;

            // Hide the text immediately so only the shrinking pill is visible.
            text.style.opacity = '0';

            const duration = 400; // ms
            const startTime = performance.now();

            function step(now) {
                const elapsed = now - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);

                const currentWidth = startWidth - (startWidth - targetWidth) * eased;
                element.style.width = currentWidth + 'px';

                if (progress < 1) {
                    requestAnimationFrame(step);
                } else {
                    // back direction
                    window.location.href = 'admin.php';
                }
            }
            requestAnimationFrame(step);
        }

        // Animate the new pill-style Logout button before logging out
        function handleLogoutClick(element) {
            element.classList.add('pressed');
            setTimeout(() => {
                window.location.href = 'logout.php';
            }, 220);
        }

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