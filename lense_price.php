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
            $new_price = (float)$_POST['new_lense_price'];
            
            if (!empty($new_name)) {
                $data[$new_group][$new_cat][$new_name] = $new_price;
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
            .section-header {
                text-align: center;
                width: 100%;
                margin-bottom: 20px;
                grid-column: 1 / -1;
            }

            /* Wrapper box for inputs to make them look like a header/card */
            .input-container-box {
                background: #2a2d32;
                padding: 25px;
                border-radius: 15px;
                box-shadow: inset 4px 4px 8px #1d1f23, inset -4px -4px 8px #373b41; /* Inset effect (concave) */
                margin-top: 10px;
            }

            .config-section .section-header {
                font-weight: bold;
                color: #00adb5; /* Adds an accent color to the header title */
                text-transform: uppercase;
                letter-spacing: 1px;
            }

            /* Additional styles for a cleaner price input layout */
            .price-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }

            /* Navigation buttons container */
            .tab-navigation {
                display: flex;
                justify-content: center;
                gap: 20px;
                margin-bottom: 30px;
            }


            /* Dark Neumorphism button style */
            .btn-neumorph {
                padding: 12px 25px;
                border: none;
                border-radius: 12px;
                background: #2a2d32; /* Dark base color */
                color: #e0e0e0;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 6px 6px 12px #1d1f23, -6px -6px 12px #373b41; /* Embossed effect */
            }

            .btn-neumorph:hover {
                color: #00adb5; /* Accent color on hover */
            }

            /* Active state (Pressed-in effect) */
            .btn-neumorph.active {
                box-shadow: inset 4px 4px 8px #1d1f23, inset -4px -4px 8px #373b41;
                color: #00adb5;
            }

            /* Hide form by default */
            .hidden-form {
                display: none !important;
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
                            <h2 style="text-align: center;">Lense Price Settings</h2>
                            <p style="text-align: center; color: var(--text-muted); font-size: 13px;">Manage pricing for Stock and Lab lenses</p>
                        </div>

                        <div class="tab-navigation">
                            <button type="button" id="btn-price" class="btn-neumorph active" onclick="showTab('price')">Lens Price List</button>
                            <button type="button" id="btn-add" class="btn-neumorph" onclick="showTab('add')">Add New Lense</button>
                        </div>
                
                        <form id="form-add-lense" action="lense_price.php" method="POST" class="price-grid hidden-form" enctype="multipart/form-data">
                            <input type="hidden" name="update_settings" value="1">
                            
                            <div class="config-section">
                                <div class="section-header">Add New Lense Type</div>
                                
                                <div class="input-container-box">
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
                                        
                                        <div class="input-group full-width">
                                            <label>Lense Price</label>
                                            <input type="text" 
                                                id="display_price" 
                                                class="input-field" 
                                                placeholder="IDR 0" 
                                                oninput="formatCurrency(this)" 
                                                autocomplete="off" 
                                                required>
                                            
                                            <input type="hidden" name="new_lense_price" id="real_price">
                                        </div>      
                                    </div>
    
                                    <div class="action-bar">
                                        <button type="submit" name="add_new_lense" class="btn-save" style="width: 100%;">Add Lense</button>
                                    </div>
                                </div>
                            </div>
                            
                        </form>
                
                        <form id="form-price-list" action="lense_price.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="update_settings" value="1">
                            
                            <div class="config-section">
                                <?php foreach ($data as $group_key => $categories): ?>
                                    <div class="section-header"><?php echo ucfirst($group_key) . " Lenses"; ?></div>
                                    
                                    <div class="input-container-box">
                                        <?php foreach ($categories as $cat_name => $lenses): ?>
                                            <h4 style="color: var(--text-muted); margin-bottom: 15px;"><?php echo $cat_name; ?></h4>
    
                                            <div class="input-grid">
                                                <div class="input-group full-width">
                                                <?php foreach ($lenses as $name => $price): ?>
                                                    <label><?php echo $name; ?></label>
                                                    <input type="text" 
                                                        class="input-field currency-display" 
                                                        value="IDR <?php echo number_format($price, 0, ',', '.'); ?>" 
                                                        oninput="formatMultipleCurrency(this)"
                                                        autocomplete="off">
                                                    
                                                    <input type="hidden" 
                                                        name="price[<?php echo $group_key; ?>][<?php echo $cat_name; ?>][<?php echo $name; ?>]" 
                                                        value="<?php echo $price ?: 0; ?>">
                                                <?php endforeach; ?>
                                                </div>                                
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>

                                <div class="action-bar">
                                    <button type="submit" name="save_prices" class="btn-save" style="width: 100%;">Save All Prices</button>
                                </div>
                            </div>
                            
                        </form>
                    </div>

                    <div class="btn-group">
                        <button type="button" class="back-main" onclick="window.location.href='customer.php'">BACK TO PREVIOUS PAGE</button>
                    </div>
                </div>                
            </div>

            <footer class="footer-container">
                <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
            </footer>
        </div>

        <script>
            function showTab(tabName) {
                // Element definitions
                const formPrice = document.getElementById('form-price-list');
                const formAdd = document.getElementById('form-add-lense');
                const btnPrice = document.getElementById('btn-price');
                const btnAdd = document.getElementById('btn-add');

                if (tabName === 'price') {
                    // Show Price, Hide Add
                    formPrice.classList.remove('hidden-form');
                    formAdd.classList.add('hidden-form');
                    
                    // Set button states
                    btnPrice.classList.add('active');
                    btnAdd.classList.remove('active');
                } else {
                    // Show Add, Hide Price
                    formAdd.classList.remove('hidden-form');
                    formPrice.classList.add('hidden-form');
                    
                    // Set button states
                    btnAdd.classList.add('active');
                    btnPrice.classList.remove('active');
                }
            }

            function formatCurrency(input) {
                // Ambil angka saja dari input
                let value = input.value.replace(/\D/g, "");
                
                if (value) {
                    // Format angka dengan pemisah ribuan (titik untuk locale Indonesia)
                    let formattedNumber = new Intl.NumberFormat('id-ID').format(value);
                    
                    // Gabungkan dengan teks "IDR "
                    input.value = "IDR " + formattedNumber;
                    
                    // Simpan angka asli ke hidden input untuk dikirim ke PHP
                    document.getElementById('real_price').value = value;
                } else {
                    input.value = "";
                    document.getElementById('real_price').value = "";
                }
            }

            function formatMultipleCurrency(input) {
                // Get numeric values only
                let value = input.value.replace(/\D/g, "");
                
                if (value) {
                    // Format number to Indonesian thousands format
                    let formattedNumber = new Intl.NumberFormat('id-ID').format(value);
                    input.value = "IDR " + formattedNumber;
                    
                    // Update the hidden input located immediately after this input
                    // This ensures $_POST['price'] in PHP still receives the raw number
                    if (input.nextElementSibling) {
                        input.nextElementSibling.value = value;
                    }
                } else {
                    input.value = "";
                    if (input.nextElementSibling) {
                        input.nextElementSibling.value = "0";
                    }
                }
            }

            // Additional function to ensure correct formatting when the page is first loaded
            document.addEventListener("DOMContentLoaded", function() {
                const displays = document.querySelectorAll('.currency-display');
                displays.forEach(display => {
                    if(display.value && !display.value.includes('IDR')) {
                        formatMultipleCurrency(display);
                    }
                });
            });
        </script>
    </body>
</html>