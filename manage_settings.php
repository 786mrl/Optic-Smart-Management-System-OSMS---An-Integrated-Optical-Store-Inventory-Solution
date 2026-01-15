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
            if (isset($_POST['max'])) {
                foreach ($_POST['max'] as $index => $max_val) {
                    $clean_max = str_replace(',', '', $max_val);
                    $new_margins[] = [
                        "max" => (int)$clean_max,
                        "percent" => (int)$_POST['percent'][$index]
                    ];
                }
            }
            // Add new entry if input exists
            if (!empty($_POST['add_max'])) {
                $new_margins[] = [
                    "max" => (int)str_replace(',', '', $_POST['add_max']),
                    "percent" => (int)$_POST['add_percent']
                ];
            }
            // Auto-sort by max price to maintain calculation logic consistency
            usort($new_margins, function($a, $b) { return $a['max'] - $b['max']; });
            $data['margins'] = $new_margins;
        } 
        // 2. DELETE MARGIN
        elseif ($_POST['action'] == 'delete_margin') {
            unset($data['margins'][$_POST['item_key']]);
            $data['margins'] = array_values($data['margins']);
        }
        // 3. UPDATE/ADD SECRET CODE
        elseif ($_POST['action'] == 'update_secret') {
            if (isset($_POST['secret'])) {
                foreach ($_POST['secret'] as $char => $val) {
                    $data['secret_map'][$char] = (int)str_replace(',', '', $val);
                }
            }
            // Add new code mapping (e.g., M)
            if (!empty($_POST['add_char'])) {
                $char = strtoupper($_POST['add_char']);
                $val = (int)str_replace(',', '', $_POST['add_secret_val']);
                $data['secret_map'][$char] = $val;
            }
        }
        // 4. DELETE SECRET CODE
        elseif ($_POST['action'] == 'delete_secret') {
            unset($data['secret_map'][$_POST['item_key']]);
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

<div class="container">
    <h1>System Configuration</h1>

    <div class="settings-card">
        <h3 class="h3_display">1. Frame Material List</h3>

        <table style="margin-left: auto; margin-right: auto;">
            <thead>
                <tr>
                    <th style="text-align: center; padding: 12px; vertical-align: middle">Material Name</th>
                    <th style="text-align: center; padding: 12px; vertical-align: middle; visibility: hidden;">Action</th>
                    <th style="text-align: center; padding: 12px; vertical-align: middle;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($materials as $k => $v): ?>
                    <tr>
                        <td><input style="text-align: center" type="text" name="secret_chars[]" value="<?php echo $v; ?>"></td>
                        
                        <?php if($role == 'admin'): ?>
                        <td>
                            <form method="POST">
                                <input type="hidden" name="target_file" value="materials.json">
                                <input type="hidden" name="item_key" value="<?php echo $k; ?>">
                                <td>
                                <button type="submit" name="action" value="delete" class="btn-del-row" onclick="return confirm('Delete material: <?php echo $v; ?>?')">x</button>
                                </td>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <form method="POST">
            <input type="hidden" name="target_file" value="materials.json">
            <input type="text" name="new_value" placeholder="Add New Materials..." required style="width: 70%;">
            <button type="submit" name="action" value="add" class="btn-save" style="width:25%; margin:0;">Add</button>
        </form>
    </div>

    <hr style="border: 1px solid #4d5459; margin: 30px 0;">

    <div class="settings-card">
        <h3 class="h3_display">2. Lens Shape List</h3>
        
        <table style="margin-left: auto; margin-right: auto;">
            <thead>
                <tr>
                    <th style="text-align: center; padding: 12px; vertical-align: middle">Shape Name</th>
                    <th style="text-align: center; padding: 12px; vertical-align: middle; visibility: hidden;">Action</th>
                    <th style="text-align: center; padding: 12px; vertical-align: middle;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($shapes as $k => $v): ?>
                    <tr>
                        <td> <input style="text-align: center" type="text" name="secret_chars[]" value="<?php echo $v; ?>"></td>

                        <?php if($role == 'admin'): ?>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="target_file" value="shapes.json">
                                <input type="hidden" name="item_key" value="<?php echo $k; ?>">
                                <td>
                                <button type="submit" name="action" value="delete" class="btn-del-row" onclick="return confirm('Delete shape: <?php echo $v; ?>?')">x</button>
                                </td>
                    
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <form method="POST" class="add-form">
            <input type="hidden" name="target_file" value="shapes.json">
            <input type="text" name="new_value" placeholder="Add New Lense Shape" required style="width: 70%;">
            <button type="submit" name="action" value="add" class="btn-save" style="width:25%; margin:0;">Add</button>
        </form>
    </div>

    <div class="settings-card">
        <h3 class="h3_display">Price Margin Rules</h3>
        <form method="POST">
            <input type="hidden" name="target_file" value="price_rules.json">
            <table style="margin-left: auto; margin-right: auto;">
                <tr><th>Max Buy Price (IDR)</th><th>Margin (%)</th><th>Action</th></tr>
                <?php foreach($price_rules['margins'] as $index => $m): ?>
                <tr>
                    <td><input style="text-align: center" type="text" name="max[]" value="<?php echo number_format($m['max']); ?>" onkeyup="formatNumber(this)"></td>
                    <td><input style="text-align: center" type="text" name="percent[]" value="<?php echo $m['percent']; ?>"></td>
                    <td align="center">
                        <button type="submit" name="action" value="delete_margin" onclick="document.getElementsByName('item_key')[0].value='<?php echo $index; ?>'; return confirm('Delete this row?')" class="btn-del-row">×</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="add-row">
                    <td><input type="text" name="add_max" placeholder="New Max Price..." onkeyup="formatNumber(this)" style="text-align: center"></td>
                    <td><input type="text" name="add_percent" placeholder="New Margin %" style="text-align: center"></td>
                    <td align="center" style="width: 100px;" ><small>New</small></td>
                </tr>
            </table>
            <input type="hidden" name="item_key" value="">
            <button type="submit" name="action" value="update_margin" class="btn-save">Save & Sort Margin Rules</button>
        </form>
    </div>

    <div class="settings-card">
        <h3 class="h3_display">Secret Code Mapping</h3>
        <form method="POST">
            <input type="hidden" name="target_file" value="price_rules.json">
            <table style="margin-left: auto; margin-right: auto;">
                <tr><th>Character</th><th>Value (IDR)</th><th>Action</th></tr>
                <?php foreach($price_rules['secret_map'] as $char => $val): ?>
                <tr>
                    <td><input style="text-align: center" type="text" name="secret_chars[]" value="<?php echo $char; ?>"></td>
                    <td><input style="text-align: center" type="text" name="secret[<?php echo $char; ?>]" value="<?php echo number_format($val); ?>" onkeyup="formatNumber(this)"></td>
                    <td align="center">
                        <button type="submit" name="action" value="delete_secret" onclick="document.getElementsByName('item_key_secret')[0].value='<?php echo $char; ?>'; return confirm('Delete this code?')" class="btn-del-row">×</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="add-row">
                    <td><input type="text" name="add_char" placeholder="Ex: M" maxlength="1" style="text-transform: uppercase;text-align: center"></td>
                    <td><input type="text" name="add_secret_val" placeholder="Value (Ex: 500,000)" onkeyup="formatNumber(this)" style="text-align: center"></td>
                    <td align="center" style="width: 100px;" ><small>New</small></td>
                </tr>
            </table>
            <input type="hidden" name="item_key_secret" value="">
            <button type="submit" name="action" value="update_secret" class="btn-save">Save Secret Codes</button>
        </form>
    </div>

    <p style="margin-top: 40px;"><a href="frame_data_entry.php" class="link-back">Back to Frame Entry</a></p>
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

<footer>
    <p><?php echo $COPYRIGHT_FOOTER; ?></p>
</footer>

</body>
</html>