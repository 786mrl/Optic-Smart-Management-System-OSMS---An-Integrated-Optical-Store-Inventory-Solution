<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) { 
        header("Location: index.php"); 
        exit(); 
    }

    $role = $_SESSION['role'] ?? 'staff';
    $json_path = "data_json/";

    // --- DATA PROCESSING LOGIC ---
    if (isset($_POST['action']) && $role == 'admin') {
        $file = $_POST['target_file'];
        $data = json_decode(file_get_contents($json_path . $file), true);

        if ($file == 'materials.json' || $file == 'shapes.json') {
            if ($_POST['action'] == 'add') {
                $data[] = strtoupper($_POST['new_value']);
            } elseif ($_POST['action'] == 'delete') {
                unset($data[$_POST['item_key']]);
                $data = array_values($data);
            }
        } 
        elseif ($file == 'price_rules.json') {
            // 1. UPDATE/ADD MARGIN
            if ($_POST['action'] == 'update_margin') {
                $new_margins = [];

                // GET EXISTING DATA FROM TABLE (If update form is pressed)
                if (isset($_POST['max'])) {
                    foreach ($_POST['max'] as $index => $max_val) {
                        $new_margins[] = [
                            "max" => (int)str_replace(',', '', $max_val),
                            "percent" => (int)$_POST['percent'][$index]
                        ];
                    }
                } 
                // IF "ADD NEW" FORM IS PRESSED (Data $_POST['max'] is empty)
                // Then we take current data from the JSON file so it is not lost
                else {
                    $new_margins = $data['margins'];
                }

                // ADD NEW DATA (If there is Add New input)
                if (!empty($_POST['add_max'])) {
                    $new_margins[] = [
                        "max" => (int)str_replace(',', '', $_POST['add_max']),
                        "percent" => (int)$_POST['add_percent']
                    ];
                }

                // Sort & Save
                usort($new_margins, function($a, $b) { return $a['max'] - $b['max']; });
                $data['margins'] = $new_margins;
            }
            // 2. DELETE MARGIN
            elseif ($_POST['action'] == 'delete_margin') {
                // Ensure the correct item_key is used
                if (isset($_POST['item_key'])) {
                    unset($data['margins'][$_POST['item_key']]);
                    $data['margins'] = array_values($data['margins']);
                }
            }
            // 3. UPDATE/ADD SECRET CODE
            elseif ($_POST['action'] == 'update_secret') {
                $new_map = [];

                // GET EXISTING DATA FROM TABLE
                if (isset($_POST['secret_chars'])) {
                    foreach ($_POST['secret_chars'] as $idx => $char) {
                        $char = strtoupper($char);
                        $new_map[$char] = (int)str_replace(',', '', $_POST['secret_values'][$idx]);
                    }
                } 
                // IF ADD NEW (Data $_POST['secret_chars'] is empty), use current JSON data
                else {
                    $new_map = $data['secret_map'];
                }

                // ADD NEW DATA
                if (!empty($_POST['add_char'])) {
                    $new_char = strtoupper($_POST['add_char']);
                    $new_val = (int)str_replace(',', '', $_POST['add_secret_val']);
                    $new_map[$new_char] = $new_val;
                }

                $data['secret_map'] = $new_map;
            }
            // 4. DELETE SECRET CODE
            elseif ($_POST['action'] == 'delete_secret') {
                // FIX: Your HTML uses 'item_key_secret'
                $key_to_delete = $_POST['item_key_secret'];
                if (isset($data['secret_map'][$key_to_delete])) {
                    unset($data['secret_map'][$key_to_delete]);
                }
            }
        }

        file_put_contents($json_path . $file, json_encode($data, JSON_PRETTY_PRINT));
        header("Location: manage_settings.php?status=success");
        exit();
    }

    // Load Data
    $materials = json_decode(file_get_contents($json_path . "materials.json"), true);
    $shapes = json_decode(file_get_contents($json_path . "shapes.json"), true);
    $price_rules = json_decode(file_get_contents($json_path . "price_rules.json"), true);
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>System Config - <?php echo htmlspecialchars($STORE_NAME); ?></title>
        <link rel="stylesheet" href="style.css">
        <style>
            /* Responsive card sizing for mobile devices */
            @media (max-width: 768px) {
                .main-card {
                    width: 98% !important;
                    padding: 15px !important;
                }
                
                .window-card {
                    width: 100% !important;
                    padding: 20px !important;
                    margin-left: 0 !important;
                    margin-right: 0 !important;
                }
                
                .table-container {
                    overflow-x: auto;
                }
                
                table {
                    width: 100%;
                }
                
                table th, table td {
                    padding: 12px 8px;
                    font-size: 14px;
                }
                
                input[type="text"] {
                    width: 100%;
                    padding: 10px !important;
                }
                
                .btn-delete, .btn-update {
                    padding: 8px 12px;
                    font-size: 13px;
                }
                
                .form-update {
                    padding: 15px 0;
                }
                
                .input-wrapper {
                    flex-direction: column !important;
                    gap: 10px !important;
                }
                
                .input-wrapper input {
                    width: 100%;
                }
            }
            
            /* Prominent delete button styling - matched to dark neumorphism theme */
            .btn-delete {
                background: linear-gradient(145deg, #3a3f47, #2c2f35) !important;
                color: #ff8a65 !important;
                border: none !important;
                padding: 10px 15px !important;
                font-weight: bold !important;
                border-radius: 8px !important;
                cursor: pointer !important;
                transition: all 0.3s ease !important;
                font-size: 14px !important;
                display: inline-flex !important;
                align-items: center !important;
                gap: 8px !important;
                box-shadow: 4px 4px 8px rgba(0, 0, 0, 0.4), -4px -4px 8px rgba(255, 255, 255, 0.03) !important;
            }
            
            .btn-delete:hover {
                color: #ff5722 !important;
                transform: scale(1.05) !important;
                box-shadow: 5px 5px 10px rgba(0, 0, 0, 0.5), -5px -5px 10px rgba(255, 255, 255, 0.05) !important;
            }
            
            .btn-delete:active {
                transform: scale(0.98) !important;
                box-shadow: inset 3px 3px 6px rgba(0, 0, 0, 0.5), inset -3px -3px 6px rgba(255, 255, 255, 0.03) !important;
            }
            
            .btn-delete::before {
                content: "🗑️";
                font-size: 16px;
            }
            
            /* Collapsible card styling */
            .card-header-toggle {
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: space-between;
                user-select: none;
            }
            
            .card-header-toggle .toggle-icon {
                font-size: 18px;
                transition: transform 0.3s ease;
            }
            
            .card-header-toggle.expanded .toggle-icon {
                transform: rotate(180deg);
            }
            
            .card-collapsible-content {
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.4s ease, opacity 0.3s ease, margin-top 0.3s ease;
                opacity: 0;
                margin-top: 0;
            }
            
            .card-collapsible-content.expanded {
                max-height: 3000px;
                opacity: 1;
                margin-top: 20px;
            }
            
            /* Color accents for text - matched to dark neumorphism theme */
            .page-title {
                color: #ffffff !important;
            }
            
            .card-header-toggle {
                color: #4fc3f7 !important;
            }
            
            .card-header-toggle .toggle-icon {
                color: #81d4fa !important;
            }
            
            table thead th {
                color: #ffb74d !important;
            }
            
            table tbody td {
                color: #b0bec5 !important;
            }
            
            .btn-update {
                color: #81c784 !important;
            }
            
            .form-update input::placeholder {
                color: #78909c !important;
            }
            
            /* Click-to-reveal delete button per row */
            table tbody tr td .btn-delete,
            table tbody tr td form .btn-delete {
                display: none !important;
            }
            
            table tbody tr.row-active td .btn-delete,
            table tbody tr.row-active td form .btn-delete {
                display: inline-flex !important;
            }
            
            table tbody tr {
                cursor: pointer;
            }
            
            @media (max-width: 480px) {
                .main-card {
                    width: 100% !important;
                    padding: 10px !important;
                }
                
                .window-card {
                    width: 100% !important;
                    padding: 15px !important;
                }
                
                table th, table td {
                    padding: 10px 6px;
                    font-size: 13px;
                }
                
                h2 {
                    font-size: 16px;
                }
                
                .btn-delete {
                    padding: 8px 10px !important;
                    font-size: 12px !important;
                }
                
                .btn-delete::before {
                    font-size: 14px;
                }
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
        </style>
    </head>

    <body>
        <div class="main-wrapper">
            <div class="content-area" style="flex-direction: column">
                <div class="header-container"  style="
                margin-left: auto; 
                margin-right: auto; 
                width: 100%;">
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
            
                <div class="main-card" style="
                margin-left: auto; 
                margin-right: auto; 
                width: 95%;
                max-width: 1200px;
                padding: 20px;">
                    <h1 class="page-title" style="text-align: center; margin-bottom: 40px;">System Configuration</h1>
                
                    <!-- FRAME MATERIAL LIST -->
                    <div class="window-card" style="
                    margin: auto; 
                    margin-bottom: 40px;
                    width: 100%;
                    padding: 25px;">
                        <h2 class="card-header-toggle" onclick="toggleCard('card-materials', this)">
                            FRAME MATERIAL LIST
                            <span class="toggle-icon">▼</span>
                        </h2>
                        <div class="card-collapsible-content" id="card-materials">
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Material Name</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                
                                <tbody>
                                    <?php foreach($materials as $k => $v): ?>
                                        <tr onclick="toggleRowDelete(this)">
                                            <td>
                                                <input style="text-align: center; text-transform: uppercase;" type="text" name="material_names[]" value="<?php echo $v; ?>" onclick="event.stopPropagation()" oninput="this.value = this.value.toUpperCase()">
                                            </td>

                                            <?php if($role == 'admin'): ?>
                                                <td align="center">
                                                    <form method="POST" style="display: inline;" onclick="event.stopPropagation()">
                                                        <input type="hidden" name="target_file" value="materials.json">
                                                        <input type="hidden" name="item_key" value="<?php echo $k; ?>">
                                                        <button type="submit" name="action" value="delete" class="btn-delete" 
                                                                onclick="return confirm('Delete material: <?php echo $v; ?>?')">
                                                            DELETE
                                                        </button>
                                                    </form>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <form method="POST" class="form-update">
                            <div class="input-wrapper">
                                <input type="hidden" name="target_file" value="materials.json">
                                <input type="text" name="new_value" placeholder="Add New Materials..." required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <button type="submit" name="action" value="add" class="btn-update">Update Data</button>
                        </form>
                        </div>
                    </div>
                
                    <!-- LENS SHAPE LIST -->
                    <div class="window-card" style="
                    margin: auto; 
                    margin-bottom: 40px;
                    width: 100%;
                    padding: 25px;">
                        <h2 class="card-header-toggle" onclick="toggleCard('card-shapes', this)">
                            LENS SHAPE LIST
                            <span class="toggle-icon">▼</span>
                        </h2>
                        <div class="card-collapsible-content" id="card-shapes">
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Shape Name</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                
                                <tbody>
                                    <?php foreach($shapes as $k => $v): ?>
                                        <tr onclick="toggleRowDelete(this)">
                                            <td>
                                                <input style="text-align: center; text-transform: uppercase;" type="text" name="shape_names[]" value="<?php echo $v; ?>" onclick="event.stopPropagation()" oninput="this.value = this.value.toUpperCase()">
                                            </td>

                                            <?php if($role == 'admin'): ?>
                                                <td align="center">
                                                    <form method="POST" style="margin: 0;" onclick="event.stopPropagation()">
                                                        <input type="hidden" name="target_file" value="shapes.json">
                                                        <input type="hidden" name="item_key" value="<?php echo $k; ?>">
                                                        <button type="submit" name="action" value="delete" class="btn-delete" 
                                                                onclick="return confirm('Delete shape: <?php echo $v; ?>?')">
                                                            DELETE
                                                        </button>
                                                    </form>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <form method="POST" class="form-update">
                            <div class="input-wrapper">
                                <input type="hidden" name="target_file" value="shapes.json">
                                <input type="text" name="new_value" placeholder="Add New Lense Shape..." required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <button type="submit" name="action" value="add" class="btn-update">Update Data</button>
                        </form>
                        </div>
                    </div>
                
                    <!-- PRICE MARGIN RULES -->
                    <div class="window-card" style="
                    margin: auto; 
                    margin-bottom: 40px;
                    width: 100%;
                    padding: 25px;">
                        <h2 class="card-header-toggle" onclick="toggleCard('card-price-rules', this)">
                            PRICE MARGIN RULES
                            <span class="toggle-icon">▼</span>
                        </h2>
                        <div class="card-collapsible-content" id="card-price-rules">

                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Max Buy Price (IDR)</th>
                                        <th>Margin (%)</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <form method="POST" id="update-form">
                                        <input type="hidden" name="target_file" value="price_rules.json">
                                        <input type="hidden" name="action" value="update_margin">

                                        <?php foreach($price_rules['margins'] as $index => $m): ?>
                                            <tr onclick="toggleRowDelete(this)">
                                                <td>
                                                    <input style="text-align: center" type="text" name="max[]" 
                                                        value="<?php echo number_format($m['max']); ?>" onkeyup="formatNumber(this)" onclick="event.stopPropagation()">
                                                </td>
                                                <td>
                                                    <input style="text-align: center" type="text" name="percent[]" 
                                                        value="<?php echo $m['percent']; ?>" onclick="event.stopPropagation()">
                                                </td>
                                                <td>
                                                    <button type="button" class="btn-delete" 
                                                            onclick="event.stopPropagation(); if(confirm('Delete?')) { document.getElementById('delete-form-<?php echo $index; ?>').submit(); }">
                                                        DELETE
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </form>
                                </tbody>
                            </table>

                            <?php foreach($price_rules['margins'] as $index => $m): ?>
                                <form id="delete-form-<?php echo $index; ?>" method="POST" style="display:none;">
                                    <input type="hidden" name="target_file" value="price_rules.json">
                                    <input type="hidden" name="action" value="delete_margin">
                                    <input type="hidden" name="item_key" value="<?php echo $index; ?>">
                                </form>
                            <?php endforeach; ?>

                            <div style="margin-top: 15px; text-align: center;">
                                <button type="submit" form="update-form" class="btn-update">Save & Sort Margin Rules</button>
                            </div>
                        </div>
                        
                        <form method="POST" class="form-update">
                            <input type="hidden" name="target_file" value="price_rules.json">
                            <input type="hidden" name="action" value="update_margin"> <div class="input-wrapper" style="display: flex; gap: 10px;">
                                <input type="text" name="add_max" placeholder="New Max Price..." onkeyup="formatNumber(this)" required>
                                <input type="text" name="add_percent" placeholder="New Margin %" required>
                            </div>
                            <button type="submit" class="btn-update">Add New Rule</button>
                        </form>
                        </div>
                    </div>
                
                    <!-- SECRET CODE MAPPING -->
                    <div class="window-card" style="
                    margin: auto; 
                    margin-bottom: 40px;
                    width: 100%;
                    padding: 25px;">
                        <h2 class="card-header-toggle" onclick="toggleCard('card-secret-code', this)">
                            SECRET CODE MAPPING
                            <span class="toggle-icon">▼</span>
                        </h2>
                        <div class="card-collapsible-content" id="card-secret-code">

                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Character</th>
                                        <th>Value (IDR)</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <form method="POST" id="form-update-secret">
                                        <input type="hidden" name="target_file" value="price_rules.json">
                                        <input type="hidden" name="action" value="update_secret">
                                        
                                        <?php foreach($price_rules['secret_map'] as $char => $val): ?>
                                            <tr onclick="toggleRowDelete(this)">
                                                <td>
                                                    <input style="text-align: center; text-transform: uppercase;" 
                                                        type="text" name="secret_chars[]" value="<?php echo $char; ?>" maxlength="1" onclick="event.stopPropagation()" oninput="this.value = this.value.toUpperCase()">
                                                </td>
                                                <td>
                                                    <input style="text-align: center" type="text" 
                                                        name="secret_values[]" 
                                                        value="<?php echo number_format($val); ?>" 
                                                        onkeyup="formatNumber(this)" onclick="event.stopPropagation()">
                                                </td>
                                                <td>
                                                    <button type="button" class="btn-delete" 
                                                            onclick="event.stopPropagation(); if(confirm('Delete code: <?php echo $char; ?>?')) { document.getElementById('delete-secret-<?php echo $char; ?>').submit(); }">
                                                        DELETE
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </form>
                                </tbody>
                            </table>

                            <div style="margin-top: 15px; text-align: center;">
                                <button type="submit" form="form-update-secret" class="btn-update">Save Secret Codes</button>
                            </div>

                            <?php foreach($price_rules['secret_map'] as $char => $val): ?>
                                <form id="delete-secret-<?php echo $char; ?>" method="POST" style="display:none;">
                                    <input type="hidden" name="target_file" value="price_rules.json">
                                    <input type="hidden" name="action" value="delete_secret">
                                    <input type="hidden" name="item_key_secret" value="<?php echo $char; ?>">
                                </form>
                            <?php endforeach; ?>
                        </div>
                        
                        <form method="POST" class="form-update">
                            <input type="hidden" name="target_file" value="price_rules.json">
                            <input type="hidden" name="action" value="update_secret">
                            <div class="input-wrapper" style="display: flex; gap: 10px;">
                                <input type="text" name="add_char" placeholder="Ex: M" maxlength="1" 
                                    style="text-transform: uppercase; text-align: center;" required oninput="this.value = this.value.toUpperCase()">
                                
                                <input type="text" name="add_secret_val" placeholder="Value (IDR)" 
                                    onkeyup="formatNumber(this)" style="text-align: center;" required>
                            </div>
                            <button type="submit" class="btn-update">Add New Secret Code</button>
                        </form>
                        </div>
                    </div>
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
            /**
             * Formats input numbers with thousands separators
             */
            function formatNumber(input) {
                let value = input.value.replace(/,/g, '');
                if (!isNaN(value) && value !== "") {
                    input.value = parseFloat(value).toLocaleString('en-US');
                }
            }
            
            /**
             * Toggles expand/collapse state of a card section.
             * All cards are collapsed by default.
             */
            function toggleCard(contentId, headerEl) {
                const content = document.getElementById(contentId);
                content.classList.toggle('expanded');
                headerEl.classList.toggle('expanded');
            }
            
            /**
             * Toggles visibility of the DELETE button for a specific table row.
             * Clicking a row shows its delete button; clicking it again (or another row) hides others.
             */
            function toggleRowDelete(rowEl) {
                const wasActive = rowEl.classList.contains('row-active');
                
                // Close all other active rows within the same table
                const table = rowEl.closest('table');
                table.querySelectorAll('tr.row-active').forEach(function(r) {
                    r.classList.remove('row-active');
                });
                
                // Toggle current row
                if (!wasActive) {
                    rowEl.classList.add('row-active');
                }
            }
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
                        window.location.href = 'frame_data_entry.php';
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