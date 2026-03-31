<?php
    // lense_price.php
    session_start();

    include 'db_config.php';
    include 'config_helper.php';

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
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                            <h2>Lense Price Settings</h2>
                            <p style="color: var(--text-muted); font-size: 13px;">Manage pricing for Stock and Lab lenses</p>
                        </div>

                        <?php if ($message): ?>
                            <div class="message-container">
                                <?php echo $message; ?>
                            </div>
                        <?php endif; ?>
                
                        <form action="lense_price.php" method="POST" class="price-grid" enctype="multipart/form-data">
                            <input type="hidden" name="update_settings" value="1">
                            
                            <div class="config-section">
                                <div class="section-header">Add New Lense Type</div>
                                
                                <div class="input-grid">
                                    <div class="input-group full-width">
                                        <label>Group</label>
                                        <select name="new_group" class="input-field">
                                            <option value="stock">Stock Lense</option>
                                            <option value="lab">Lab Lense (Custom Order)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="input-group full-width">
                                        <label>Category (e.g. Single Vision)</label>
                                        <input type="text" class="input-field" name="new_category" placeholder="Single Vision">
                                    </div>
                                    
                                    <div class="input-group full-width">
                                        <label>Lense Name</label>
                                        <input type="text" name="new_lense_name" class="input-field" placeholder="SV-CRMC" required>
                                    </div>              
                                </div>
                            </div>
                            
                            <div class="action-bar">
                                <button type="submit" name="add_new_lense" class="btn-save" style="width: 100%;">Add Lense</button>
                            </div>
                        </form>
                
                        <form action="lense_price.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="update_settings" value="1">
                            
                            <?php foreach ($data as $group_key => $categories): ?>
                                <div class="config-section">
                                    <div class="section-header"><?php echo ucfirst($group_key) . " Lenses"; ?></div>
                                    
                                    <?php foreach ($categories as $cat_name => $lenses): ?>
                                        <h4 style="color: var(--text-muted); margin-bottom: 15px;"><?php echo $cat_name; ?></h4>

                                        <div class="input-grid">
                                            <div class="input-group full-width">
                                                <?php foreach ($lenses as $name => $price): ?>
                                                    <label><?php echo $name; ?></label>
                                                    <input type="number" 
                                                        name="price[<?php echo $group_key; ?>][<?php echo $cat_name; ?>][<?php echo $name; ?>]" 
                                                        value="<?php echo $price ?: 0; ?>" 
                                                        class="input-field" 
                                                        step="0.01">
                                                <?php endforeach; ?>
                                            </div>         
                                            
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                            
                            <div class="action-bar">
                                <button type="submit" name="save_prices" class="btn-save" style="width: 100%;">Save All Prices</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="btn-group">
                <button type="button" class="back-main" onclick="window.location.href='customer.php'">BACK TO PREVIOUS PAGE</button>
            </div>

            <footer class="footer-container">
                <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
            </footer>
        </div>
    </body>
</html>