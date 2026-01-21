<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';
    include 'phpqrcode/qrlib.php'; 

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

    $role = $_SESSION['role'] ?? 'staff';

    function loadJson($file) {
        $path = "data_json/$file";
        if (!file_exists($path)) return []; 
        return json_decode(file_get_contents($path), true);
    }

    if (isset($_POST['submit_frame'])) {
        $brand = strtoupper($_POST['brand']);
        $f_code = !empty($_POST['frame_code']) ? $_POST['frame_code'] : "lz-786";
        $f_size = !empty($_POST['frame_size']) ? $_POST['frame_size'] : "00-00-786";
        
        // color
        if ($_POST['has_color_code'] == 'no') {
            $colors = loadJson('colors.json');
            $input_color = strtolower($_POST['color_name'] ?? '');
            if (!isset($colors[$input_color])) {
                $next_col = "col." . (count($colors) + 1);
                $colors[$input_color] = $next_col;
                file_put_contents("data_json/colors.json", json_encode($colors));
            }
            $color_code = $colors[$input_color];
        } else {
            $color_code = $_POST['color_manual_code'];
        }

        // ufc (unique frame code)
        $ufc = str_replace(' ', '', "$brand-$f_code-$f_size-$color_code");

        // stock, default 1
        $input_stock = !empty($_POST['total_frame']) ? (int)$_POST['total_frame'] : 1;

        // price & secret selling price code
        $buy_price = ($role === 'admin') ? (float)$_POST['buy_price'] : 0;
        $sell_price = 0;
        $secret_code = "";

        if ($buy_price > 0) {
            $rules = loadJson('price_rules.json');
            foreach ($rules['margins'] as $m) {
                if ($buy_price <= $m['max']) {
                    $sell_price = $buy_price + ($buy_price * ($m['percent'] / 100));
                    break;
                }
            }
            $sell_price = ceil($sell_price / 5000) * 5000;

            $temp_sell = $sell_price;
            $secret_code = "";
            $map = $rules['secret_map'];
            arsort($map); 
            
            foreach ($map as $char => $val) {
                if ($temp_sell >= $val) {
                    $secret_code .= $char;
                    $temp_sell -= $val;
                }
            }
            $secret_code .= str_pad(($temp_sell / 1000), 2, "0", STR_PAD_LEFT);
            $secret_code .= "LZ";
        }

        // stock age
        $stock_age = !empty($_POST['stock_age']) ? $_POST['stock_age'] : "new";

        // query: insert or update stoce also overwrite
        $stmt = $conn->prepare("INSERT INTO frame_staging 
            (ufc, brand, frame_code, frame_size, color_code, material, lens_shape, structure, size_range, buy_price, sell_price, price_secret_code, stock, stock_age) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            brand=VALUES(brand), 
            frame_code=VALUES(frame_code), 
            frame_size=VALUES(frame_size), 
            color_code=VALUES(color_code), 
            material=VALUES(material), 
            lens_shape=VALUES(lens_shape), 
            structure=VALUES(structure), 
            size_range=VALUES(size_range), 
            buy_price=VALUES(buy_price), 
            sell_price=VALUES(sell_price), 
            price_secret_code=VALUES(price_secret_code), 
            stock=stock+VALUES(stock),
            stock_age=VALUES(stock_age)");
        
        $stmt->bind_param("sssssssssddsis", 
        $ufc, 
        $brand, 
        $f_code, 
        $f_size, 
        $color_code, 
        $_POST['material'], 
        $_POST['lens_shape'], 
        $_POST['structure'], 
        $_POST['size_range'], 
        $buy_price, 
        $sell_price, 
        $secret_code, 
        $input_stock,
        $stock_age);
        
        if ($stmt->execute()) {
            // --- QR CODE CHECK LOGIC STARTS HERE ---
            $main_qr_path = "main_qrcodes/$ufc.png";
            $staging_qr_path = "qrcodes/$ufc.png";

            // Ensure the staging folder exists
            if (!file_exists('qrcodes')) mkdir('qrcodes', 0777, true);

            // Check if the QR Code already exists in the main folder (main_qrcodes)
            if (file_exists($main_qr_path)) {
                // If it exists in main, we can copy it to staging or leave it as is
                // Here I assume the staging system only needs to know the data is saved
                $msg_extra = "(Existing QR Code found in main storage)";
            } else {
                // If NOT in main, check if it already exists in staging
                if (!file_exists($staging_qr_path)) {
                    // Generate new one if it truly does not exist anywhere
                    QRcode::png($ufc, $staging_qr_path, QR_ECLEVEL_L, 4);
                    $msg_extra = "(New QR Code generated)";
                } else {
                    $msg_extra = "(QR Code already exists in staging)";
                }
            }
            // --- QR CODE CHECK LOGIC ENDS HERE ---

            $_SESSION['success_msg'] = "Data Saved Successfully! UFC: $ufc | Stock Added: $input_stock $msg_extra";
            
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Frame Entry - <?php echo htmlspecialchars($STORE_NAME); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        h2 {
            text-align: center;
            margin-bottom: 35px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
                <h2>FRAME DATA ENTRY</h2>
        
                <form method="POST" action="">
                    <div class="form-grid">
                        <!-- FRAME NAME -->
                        <div class="input-group">
                            <label for="brand">Frame Brand</label>
                            <input type="text" id="brand" name="brand" required placeholder="e.g. RAYBAN" style="text-transform: uppercase;">
                        </div>
        
                        <!-- FRAME CODE -->
                        <div class="input-group">
                            <label for="frame_code">Frame Code</label>
                            <input type="text" id="frame_code" name="frame_code" pattern="[A-Za-z0-9\-]+" placeholder="lz-786" style="text-transform: uppercase;">
                        </div>
        
                        <!-- FRAME SIZE -->
                        <div class="input-group">
                            <label for="frame_size">Frame Size</label>
                            <input type="text" id="frame_size" name="frame_size" placeholder="00-00-786" inputmode="decimal" pattern="[0-9\+\-\*\/]*">
                        </div>
        
                        <!-- HAS COLOR CODE? -->
                        <div class="input-group">
                            <label style="width: 100%; text-align: center; margin-bottom: 0;">Has Color Code?</label>
                            <input type="hidden" name="has_color_code" id="has_color_code_input" value="no">
                            <div id="color_opt" class="selection-wrapper">
                                <button value="no" type="button" class="neu-btn active" onclick="toggleNeu(this, 'has_color_code_input', true)">
                                    <span>NO</span>
                                    <div class="led"></div>
                                </button>
                                <button value="yes" type="button" class="neu-btn" onclick="toggleNeu(this, 'has_color_code_input', true)">
                                    <span>YES</span>
                                    <div class="led"></div>
                                </button>
                            </div>
                        </div>    
        
                        <!-- FRAME COLOR, CODE GENERATE -->
                        <div id="col_name_box" class="input-group">
                            <label for="color_code_generate">Frame Color</label>
                            <input type="text" id="color_code_generate" name="color_name" placeholder="BLACK GOLD" style="text-transform: uppercase;">
                        </div>
        
                        <!-- FRAME COLOR, MANUAL -->
                        <div id="col_manual_box" class="input-group hidden">
                            <label for="color_code_manual">Frame Color</label>
                            <input type="text" id="color_code_manual" name="color_manual_code" placeholder="C1" style="text-transform: uppercase;">
                        </div>
        
                        <!-- MATERIAL -->
                        <div class="input-group">
                            <label>Material</label>
                            <select name="material">
                                <?php foreach(loadJson('materials.json') as $m) echo "<option value='$m'>$m</option>"; ?>
                            </select>
                        </div>
        
                        <!-- LENS SHAPE -->
                        <div class="input-group">
                            <label>Lens Shape</label>
                            <select name="lens_shape">
                                <?php foreach(loadJson('shapes.json') as $s) echo "<option value='$s'>$s</option>"; ?>
                            </select>
                        </div>
        
                        <!-- FRAME STRUCTURE -->
                        <div class="input-group">
                            <label style="width: 100%; text-align: center; margin-bottom: 0;">FRAME STRUCTURE</label>
                            <input type="hidden" name="structure" id="frame_structure_input" value="full-rim">
                            <div class="selection-wrapper">
                                <button style="min-width: 100px;" value="full-rim" type="button" class="neu-btn active"onclick="toggleNeu(this, 'frame_structure_input')">
                                    <span>FULL RIM</span>
                                    <div class="led"></div>
                                </button>
                                <button style="min-width: 100px;" value="semi-rimless" type="button" class="neu-btn"onclick="toggleNeu(this, 'frame_structure_input')">
                                    <span>SEMI RIMLESS</span>
                                    <div class="led"></div>
                                </button>
                                <button style="min-width: 100px;" value="rimless" type="button" class="neu-btn"onclick="toggleNeu(this, 'frame_structure_input')">
                                    <span>RIMLESS</span>
                                    <div class="led"></div>
                                </button>
                            </div>
                        </div>
        
                        <!-- FRAME SIZE RANGE -->
                        <div class="input-group">
                            <label style="width: 100%; text-align: center; margin-bottom: 0;">SIZE RANGE</label>
                            <input type="hidden" name="size_range" id="frame_size_range_input" value="small">
                            <div class="selection-wrapper">
                                <button style="min-width: 100px;" value="small" type="button" class="neu-btn active"onclick="toggleNeu(this, 'frame_size_range_input')">
                                    <span>SMALL</span>
                                    <div class="led"></div>
                                </button>
                                <button style="min-width: 100px;" value="medium" type="button" class="neu-btn"onclick="toggleNeu(this, 'frame_size_range_input')">
                                    <span>MEDIUM</span>
                                    <div class="led"></div>
                                </button>
                                <button style="min-width: 100px;" value="large" type="button" class="neu-btn"onclick="toggleNeu(this, 'frame_size_range_input')">
                                    <span>LARGE</span>
                                    <div class="led"></div>
                                </button>
                            </div>
                        </div>
        
                        <!-- TOTAL FRAME -->
                        <div class="input-group">
                            <label for="total_frame">Total Frame (Stock)</label>
                            <input type="number" id="total_frame" name="total_frame" value="1" min="1" required>
                        </div>

                        <!-- STOCK AGE -->
                        <div class="input-group">
                            <label style="width: 100%; text-align: center; margin-bottom: 0;">STOCK AGE</label>
                            <input type="hidden" name="stock_age" id="stock_age_input" value="new">
                            <div class="selection-wrapper">
                                <button style="min-width: 100px;" value="very old" type="button" class="neu-btn" onclick="toggleNeu(this, 'stock_age_input')">
                                    <span>VERY OLD</span>
                                    <div class="led"></div>
                                </button>
                                <button style="min-width: 100px;" value="old" type="button" class="neu-btn" onclick="toggleNeu(this, 'stock_age_input')">
                                    <span>OLD</span>
                                    <div class="led"></div>
                                </button>
                                <button style="min-width: 100px;" value="new" type="button" class="neu-btn active" onclick="toggleNeu(this, 'stock_age_input')">
                                    <span>NEW</span>
                                    <div class="led"></div>
                                </button>
                            </div>
                        </div>
        
                        <!-- COST PRICE -->
                        <?php if ($role === 'admin'): ?>
                            <div class="input-group">
                                <label for="buy_price">Cost Price (IDR)</label>
                                <input type="password" id="buy_price" name="buy_price" oninput="calculatePrice()" inputmode="numeric" autocomplete="off">
                            </div>
                            <div class="submit-main" id="sell_display">Selling Price: IDR 0</div>
                        <?php endif; ?>
                        
                        <!-- Submit and Update Settings -->
                        <div class="btn-group">
                            <button type="submit" name="submit_frame" class="submit-main">SAVE DATA</button>
                            <button type="button" class="submit-main" onclick="window.location.href='manage_settings.php'">UPDATE SETTINGS</button>
                            <!-- Alert if success -->
                            <?php if(isset($_SESSION['success_msg'])): ?>
                                <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
                                <script>
                                    Swal.fire({
                                        title: 'SUCCESS',
                                        text: '<?php echo $_SESSION['success_msg']; ?>',
                                        icon: 'success',
                                        iconColor: '#00ff88',
                                        background: '#2e3133',
                                        confirmButtonText: 'GREAT',
                                        customClass: {
                                            popup: 'neumorph-alert',
                                            title: 'neumorph-title',
                                            htmlContainer: 'neumorph-content',
                                            confirmButton: 'neumorph-button'
                                        },
                                        buttonsStyling: false
                                    });
                                </script>
                                <?php unset($_SESSION['success_msg']); // Delete message after it is displayed ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="btn-group">
            <button type="button" class="back-main" onclick="window.location.href='frame_management.php'">BACK TO PREVIOUS PAGE</button>
        </div>
    
        <footer class="footer-container">
            <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
        </footer>
    </div>

    <script>
        // Dynamically fetching margin data from PHP to JS
        const priceRules = <?php echo file_get_contents("data_json/price_rules.json"); ?>;
        const margins = priceRules.margins;

        function calculatePrice() {
            let buy = parseFloat(document.getElementById('buy_price').value);
            let sell = 0;

            if (!isNaN(buy) && buy > 0) {
                // Find the appropriate margin rule from the JSON data
                let rule = margins.find(m => buy <= m.max);
                
                // If price exceeds the highest max, use the percentage from the last rule
                if (!rule) {
                    rule = margins[margins.length - 1];
                }

                // Calculation: cost price + (cost price * percentage / 100)
                sell = buy + (buy * (rule.percent / 100));

                // Round up to the nearest multiple of 5,000 (matching your PHP logic)
                sell = Math.ceil(sell / 5000) * 5000;
            }

            document.getElementById('sell_display').innerText = "Selling Price: IDR " + sell.toLocaleString('id-ID');
        }

        // 1. Primary Toggle Function
        function toggleNeu(el, hiddenInputId, isColorToggle = false) {
            const val = el.value;
            
            // Update button visuals
            const parent = el.parentElement;
            parent.querySelectorAll('.neu-btn').forEach(b => b.classList.remove('active'));
            el.classList.add('active');
            
            // Save value to hidden input for form submission
            document.getElementById(hiddenInputId).value = val;

            if (isColorToggle) {
                const colNameBox = document.getElementById('col_name_box');
                const colManualBox = document.getElementById('col_manual_box');
                
                if (val === 'yes') {
                    colNameBox.classList.add('hidden');
                    colManualBox.classList.remove('hidden');
                } else {
                    colNameBox.classList.remove('hidden');
                    colManualBox.classList.add('hidden');
                }
            }
        }

        // 2. Execution on Page Load (Place at the bottom of the script)
        document.addEventListener('DOMContentLoaded', function() {
            // Execute for all button groups that have the 'active' class by default
            document.querySelectorAll('.neu-btn.active').forEach(btn => {
                
                // Find the associated hidden input ID (from the onclick attribute)
                // or execute manually for specific cases
                if (btn.closest('#color_opt')) {
                    toggleNeu(btn, 'has_color_code_input', true);
                } else {
                    // Logic for 'structure' and 'size_range' groups
                    const parent = btn.closest('.input-group');
                    const hiddenInput = parent.querySelector('input[type="hidden"]');
                    
                    if(hiddenInput) toggleNeu(btn, hiddenInput.id, false);
                }
            });
        });
    </script>

</body>
</html>