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
            $data[] = $_POST['new_value'];
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
</head>
<body>
    <div class="main-wrapper">
        <div class="content-area" style="flex-direction: column">
            <div class="header-container"  style="
            margin-left: auto; 
            margin-right: auto; 
            width: 100%;">
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
        
            <div class="main-card" style="
            margin-left: auto; 
            margin-right: auto; 
            width: 100%;">
                <h1 style="text-align: center; margin-bottom: 40px;">System Configuration</h1>
            
                <!-- FRAME MATERIAL LIST -->
                <div class="window-card" style="margin: auto; margin-bottom: 40px;">
                    <h2>FRAME MATERIAL LIST</h2>
            
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
                                    <tr>
                                        <td>
                                            <input style="text-align: center" type="text" name="material_names[]" value="<?php echo $v; ?>">
                                        </td>

                                        <?php if($role == 'admin'): ?>
                                            <td align="center">
                                                <form method="POST" style="display: inline;">
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
                            <input type="text" name="new_value" placeholder="Add New Materials..." required>
                        </div>
                        <button type="submit" name="action" value="add" class="btn-update">Update Data</button>
                    </form>
                </div>
            
                <!-- LENS SHAPE LIST -->
                <div class="window-card" style="margin: auto; margin-bottom: 40px;">
                    <h2>LENS SHAPE LIST</h2>
            
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
                                    <tr>
                                        <td>
                                            <input style="text-align: center" type="text" name="shape_names[]" value="<?php echo $v; ?>">
                                        </td>

                                        <?php if($role == 'admin'): ?>
                                            <td align="center">
                                                <form method="POST" style="margin: 0;">
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
                            <input type="text" name="new_value" placeholder="Add New Lense Shape..." required>
                        </div>
                        <button type="submit" name="action" value="add" class="btn-update">Update Data</button>
                    </form>
                </div>
            
                <!-- PRICE MARGIN RULES -->
                <div class="window-card" style="margin: auto; margin-bottom: 40px;">
                    <h2>PRICE MARGIN RULES</h2>

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
                                        <tr>
                                            <td>
                                                <input style="text-align: center" type="text" name="max[]" 
                                                    value="<?php echo number_format($m['max']); ?>" onkeyup="formatNumber(this)">
                                            </td>
                                            <td>
                                                <input style="text-align: center" type="text" name="percent[]" 
                                                    value="<?php echo $m['percent']; ?>">
                                            </td>
                                            <td>
                                                <button type="button" class="btn-delete" 
                                                        onclick="if(confirm('Delete?')) { document.getElementById('delete-form-<?php echo $index; ?>').submit(); }">
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
            
                <!-- SECRET CODE MAPING -->
                <div class="window-card" style="margin: auto; margin-bottom: 40px;">
                    <h2>SECRET CODE MAPPING</h2>

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
                                        <tr>
                                            <td>
                                                <input style="text-align: center; text-transform: uppercase;" 
                                                    type="text" name="secret_chars[]" value="<?php echo $char; ?>" maxlength="1">
                                            </td>
                                            <td>
                                                <input style="text-align: center" type="text" 
                                                    name="secret_values[]" 
                                                    value="<?php echo number_format($val); ?>" 
                                                    onkeyup="formatNumber(this)">
                                            </td>
                                            <td>
                                                <button type="button" class="btn-delete" 
                                                        onclick="if(confirm('Delete code: <?php echo $char; ?>?')) { document.getElementById('delete-secret-<?php echo $char; ?>').submit(); }">
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
                                style="text-transform: uppercase; text-align: center;" required>
                            
                            <input type="text" name="add_secret_val" placeholder="Value (IDR)" 
                                onkeyup="formatNumber(this)" style="text-align: center;" required>
                        </div>
                        <button type="submit" class="btn-update">Add New Secret Code</button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="btn-group">
            <button type="button" class="back-main" onclick="window.location.href='frame_data_entry.php'">BACK TO PREVIOUS PAGE</button>
        </div>

        <footer class="footer-container">
            <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
        </footer>
    </div>

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
    </script>
</body>
</html>