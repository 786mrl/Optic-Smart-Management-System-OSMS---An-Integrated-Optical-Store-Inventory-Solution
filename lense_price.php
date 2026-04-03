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
                $active_tab = 'add';
            }
        }
        
        file_put_contents($json_file, json_encode($data, JSON_PRETTY_PRINT));
    }

    $selected_group = $_POST['last_group'] ?? 'stock';
    $selected_cat = $_POST['last_category'] ?? '';

    if (empty($selected_cat) && isset($data[$selected_group])) {
        $selected_cat = array_key_first($data[$selected_group]);
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

            .window-card {
                display: flex;
                flex-direction: column;
                align-items: center; /* Ensures all internal elements are centered */
                width: 100%;
            }

            /* Navigation buttons container */
            .tab-navigation {
                display: flex;
                justify-content: center;
                gap: 20px;
                margin-bottom: 30px;
            }

            /* Update on the CSS section */
            .btn-group {
                margin-top: 30px; /* Provides spacing to prevent overlapping */
                width: 100%;
                display: flex;
                justify-content: center;
            }

            .back-main {
                /* Ensure your back button styling remains consistent */
                width: 100%;
                max-width: 400px;
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

            /* Ensure the main add lens form container centers its content */
            #form-add-lense {
                display: none; /* Will be managed via JS (showTab) */
                width: 100%;
                flex-direction: column;
                align-items: center; /* This centers the form horizontally */
                justify-content: center;
                padding: 20px 0;
            }

            /* When form is displayed (via JS class), use flex */
            #form-add-lense:not(.hidden-form) {
                display: flex !important;
            }

            /* Limit input box width to prevent excessive stretching when zooming out */
            #form-add-lense .config-section, 
            #form-price-list .config-section {
                width: 100%;
                max-width: 100%; /* Ideal size for single input forms */
                margin: 0 auto;
            }

            /* Fix for Price List to keep it tidy */
            #form-price-list {
                width: 100%;
                display: flex;
                flex-direction: column;
                align-items: center;
            }

            .price-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); /* auto-fit is more responsive */
                gap: 20px;
                width: 100%;
                justify-content: center;
            }
            .config-window {
                margin-left: auto; 
                margin-right: auto; 
                width: 100%; 
                max-width: 100%; /* Set this value (e.g., 600px) for consistent width and visual comfort */
            }

            .lense-group-wrapper {
                width: 100%;
            }

            /* Remove the browser's default arrow on summary */
            .lense-details summary::-webkit-details-marker {
                display: none;
            }

            .lense-details summary {
                outline: none;
                transition: color 0.3s ease;
                padding: 10px;
                background: #1d1f23;
                border-radius: 8px;
            }

            .lense-details[open] summary {
                color: #00adb5;
                margin-bottom: 10px;
            }

            /* Simple animation when opened */
            .lense-details[open] .input-container-box {
                animation: slideDown 0.3s ease-out;
            }

            @keyframes slideDown {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }

            /* --- Adjustments for Mobile View --- */
            @media screen and (max-width: 600px) {
                /* 1. Remove side margins and set width to 100% */
                .config-window {
                    width: 100% !important;
                    max-width: 100% !important;
                    padding-left: 10px;  /* Add slight padding to prevent touching the edges */
                    padding-right: 10px;
                    box-sizing: border-box;
                }

                /* 2. Ensure the main content area does not restrict width */
                .content-area {
                    padding: 5px !important;
                    width: 100% !important;
                }

                /* 3. Enlarge navigation buttons for better touch targets */
                .tab-navigation {
                    gap: 10px;
                    width: 100%;
                }

                .btn-neumorph {
                    flex: 1; /* Buttons will evenly distribute across the screen width */
                    padding: 15px 5px;
                    font-size: 14px;
                }

                /* 4. Adjust input boxes to fill the mobile screen */
                .input-container-box {
                    padding: 15px; /* Reduce inner padding to allow wider content */
                    border-radius: 10px;
                }

                /* 5. Slightly reduce header title size to save space */
                .header-title h2 {
                    font-size: 1.2rem;
                }
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
                
                <div class="config-window">
                    <div class="header-title">
                        <h2 style="text-align: center;">Lense Price Settings</h2>
                        <p style="text-align: center; color: var(--text-muted); font-size: 13px;">Manage pricing for Stock and Lab lenses</p>
                    </div>

                    <?php if ($message): ?>
                        <div style="background: #00adb5; color: white; padding: 10px; border-radius: 8px; margin-bottom: 15px; text-align: center;">
                            <?php echo $message; ?>
                        </div>
                    <?php endif; ?>

                    <div class="tab-navigation">
                        <button type="button" id="btn-price" class="btn-neumorph active" onclick="showTab('price')">Lense Price List</button>
                        <button type="button" id="btn-add" class="btn-neumorph" onclick="showTab('add')">Add New Lense</button>
                    </div>
            
                    <form id="form-add-lense" action="lense_price.php" method="POST" class="hidden-form" enctype="multipart/form-data">
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
                                            onfocus="this.select()" 
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
                        <input type="hidden" name="last_group" id="last_group" value="<?php echo $selected_group; ?>">
                        <input type="hidden" name="last_category" id="last_category" value="<?php echo $selected_cat; ?>">

                        <div class="config-section">
                            <div class="input-container-box" style="margin-bottom: 20px; display: flex; gap: 15px; align-items: center;">
                                <div style="flex: 1;">
                                    <label style="font-size: 12px; color: var(--text-muted);">Select Group:</label>
                                    <select id="filter-group" class="input-field" onchange="updateCategoryFilter()">
                                        <?php foreach (array_keys($data) as $group): ?>
                                            <option value="<?php echo $group; ?>"><?php echo ucfirst($group); ?> Lenses</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div style="flex: 1;">
                                    <label style="font-size: 12px; color: var(--text-muted);">Select Category:</label>
                                    <select id="filter-category" class="input-field" onchange="filterLenses()"></select>
                                </div>
                            </div>

                            <div id="lense-display-container">
                                <?php foreach ($data as $group_key => $categories): ?>
                                    <?php foreach ($categories as $cat_name => $lenses): ?>
                                        <div class="lense-group-wrapper" data-group="<?php echo $group_key; ?>" data-category="<?php echo $cat_name; ?>">
                                            
                                            <details class="lense-details">
                                                <summary class="section-header" style="cursor: pointer; list-style: none;">
                                                    <?php echo ucfirst($group_key) . " - " . $cat_name; ?> 
                                                    <span style="font-size: 12px; color: #00adb5;"> (Click to view prices)</span>
                                                </summary>
                                                
                                                <div class="input-container-box">
                                                    <div class="input-grid">
                                                        <?php foreach ($lenses as $name => $price): ?>
                                                        <div class="input-group full-width">
                                                            <label><?php echo $name; ?></label>
                                                            <input type="text" 
                                                                class="input-field currency-display" 
                                                                value="IDR <?php echo number_format($price, 0, ',', '.'); ?>" 
                                                                oninput="formatMultipleCurrency(this)"
                                                                onfocus="this.select()"
                                                                autocomplete="off">
                                                            <input type="hidden" name="price[<?php echo $group_key; ?>][<?php echo $cat_name; ?>][<?php echo $name; ?>]" value="<?php echo $price ?: 0; ?>">
                                                        </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </details>
                                            
                                        </div>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </div>

                            <div class="action-bar" style="margin-top: 20px;">
                                <button type="submit" name="save_prices" class="btn-save" style="width: 100%;">Save All Prices</button>
                            </div>
                        </div>
                    </form>

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
                // Check if PHP is sending a command to open the 'add' tab
                <?php if (isset($active_tab) && $active_tab === 'add'): ?>
                    showTab('add');
                <?php else: ?>
                    updateCategoryFilter();
                <?php endif; ?>
                
                // Existing currency formatting code
                const displays = document.querySelectorAll('.currency-display');
                displays.forEach(display => {
                    if(display.value && !display.value.includes('IDR')) {
                        formatMultipleCurrency(display);
                    }
                });
            });

            // Data kategori dari PHP ke JS
            const lenseData = <?php echo json_encode($data); ?>;

            function updateCategoryFilter() {
                const groupSelect = document.getElementById('filter-group');
                const catSelect = document.getElementById('filter-category');
                const selectedGroup = groupSelect.value;
                
                // Update hidden input for Group
                document.getElementById('last_group').value = selectedGroup;
                
                catSelect.innerHTML = "";
                
                if (lenseData[selectedGroup]) {
                    // Retrieve the last category value from PHP (only during initial load)
                    const lastCat = "<?php echo $selected_cat; ?>";
                    
                    Object.keys(lenseData[selectedGroup]).forEach(cat => {
                        let option = document.createElement('option');
                        option.value = cat;
                        option.textContent = cat;
                        // If category matches the recently saved one, mark as selected
                        if (cat === lastCat) option.selected = true;
                        catSelect.appendChild(option);
                    });
                }
                filterLenses();
            }

            function filterLenses() {
                const selectedGroup = document.getElementById('filter-group').value;
                const selectedCat = document.getElementById('filter-category').value;
                
                // Update hidden input for Category
                document.getElementById('last_category').value = selectedCat;
                
                const wrappers = document.querySelectorAll('.lense-group-wrapper');
                wrappers.forEach(wrapper => {
                    const group = wrapper.getAttribute('data-group');
                    const cat = wrapper.getAttribute('data-category');
                    
                    if (group === selectedGroup && cat === selectedCat) {
                        wrapper.style.display = 'block';
                    } else {
                        wrapper.style.display = 'none';
                    }
                });
            }

            // Inisialisasi filter saat halaman dimuat
            document.addEventListener("DOMContentLoaded", function() {
                updateCategoryFilter();
                
                // Kode format currency yang sudah ada tetap di sini...
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