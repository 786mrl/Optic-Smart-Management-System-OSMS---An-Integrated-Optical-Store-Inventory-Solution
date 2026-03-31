<?php
// lense_price.php
session_start();

// Security check (adjust according to your login system)
if ($_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$json_file = 'data_json/lense_prices.json';

// Initialize data if file does not exist
if (!file_exists($json_file)) {
    $initial_data = ["stock" => ["Single Vision" => []], "lab" => []];
    file_put_contents($json_file, json_encode($initial_data, JSON_PRETTY_PRINT));
}

$data = json_decode(file_get_contents($json_file), true);
$message = '';

// --- Price Update & Add New Lense Logic ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['save_prices'])) {
        // Update existing prices
        foreach ($_POST['price'] as $group => $categories) {
            foreach ($categories as $category => $lenses) {
                foreach ($lenses as $lense_name => $price) {
                    $data[$group][$category][$lense_name] = (float)$price;
                }
            }
        }
        $message = "Prices updated successfully!";
    } elseif (isset($_POST['add_new_lense'])) {
        // Add new lense type
        $new_group = $_POST['new_group'];
        $new_cat = $_POST['new_category'] ?: 'General';
        $new_name = $_POST['new_lense_name'];
        
        if (!empty($new_name)) {
            $data[$new_group][$new_cat][$new_name] = 0;
            $message = "Lense $new_name added successfully!";
        }
    }
    
    file_put_contents($json_file, json_encode($data, JSON_PRETTY_PRINT));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Lense Price Configuration</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Additional styles for a cleaner price input layout */
        .price-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .price-item {
            display: flex;
            flex-direction: column;
        }
        .group-header {
            border-bottom: 2px solid var(--accent-color);
            margin-bottom: 20px;
            padding-bottom: 5px;
            color: var(--accent-color);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <header class="header-container">
            <div class="header-info">
                <h1>Lense Price Settings</h1>
                <p>Manage pricing for Stock and Lab lenses</p>
            </div>
        </header>

        <?php if ($message): ?>
            <div style="background: var(--success); color: #000; padding: 10px; border-radius: 10px; margin-bottom: 20px;">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="content-body">
            <div class="card-container" style="margin-bottom: 30px;">
                <h3 class="group-header">Add New Lense Type</h3>
                <form method="POST" class="price-grid">
                    <div class="price-item">
                        <label>Group</label>
                        <select name="new_group" class="input-field">
                            <option value="stock">Stock Lense</option>
                            <option value="lab">Lab Lense (Custom Order)</option>
                        </select>
                    </div>
                    <div class="price-item">
                        <label>Category (e.g. Single Vision)</label>
                        <input type="text" name="new_category" class="input-field" placeholder="Single Vision">
                    </div>
                    <div class="price-item">
                        <label>Lense Name</label>
                        <input type="text" name="new_lense_name" class="input-field" placeholder="SV-CRMC" required>
                    </div>
                    <div class="price-item" style="justify-content: flex-end;">
                        <button type="submit" name="add_new_lense" class="btn-save" style="width: 100%;">Add Lense</button>
                    </div>
                </form>
            </div>

            <form method="POST">
                <?php foreach ($data as $group_key => $categories): ?>
                    <div class="card-container">
                        <h3 class="group-header"><?php echo ucfirst($group_key) . " Lenses"; ?></h3>
                        
                        <?php foreach ($categories as $cat_name => $lenses): ?>
                            <h4 style="color: var(--text-muted); margin-bottom: 15px;"><?php echo $cat_name; ?></h4>
                            <div class="price-grid">
                                <?php foreach ($lenses as $name => $price): ?>
                                    <div class="price-item">
                                        <label><?php echo $name; ?></label>
                                        <input type="number" 
                                               name="price[<?php echo $group_key; ?>][<?php echo $cat_name; ?>][<?php echo $name; ?>]" 
                                               value="<?php echo $price ?: 0; ?>" 
                                               class="input-field" 
                                               step="0.01">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <div class="action-bar">
                    <button type="submit" name="save_prices" class="btn-save">Save All Prices</button>
                </div>
            </form>
        </div>

        <div class="btn-group">
            <button type="button" class="back-main" onclick="window.location.href='admin.php'">BACK TO DASHBOARD</button>
        </div>
    </div>
</body>
</html>