<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';
    include 'phpqrcode/qrlib.php'; 

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

    $role = $_SESSION['role'] ?? 'staff';
    $old_ufc = $_GET['ufc'] ?? '';

    if (empty($old_ufc)) { header("Location: frame_management.php"); exit(); }

    // Retrieve existing data
    $stmt_get = $conn->prepare("SELECT * FROM frame_staging WHERE ufc = ?");
    $stmt_get->bind_param("s", $old_ufc);
    $stmt_get->execute();
    $current_data = $stmt_get->get_result()->fetch_assoc();

    if (!$current_data) { die("Data not found!"); }

    // ... after fetching $current_data ...
    $colors_json = loadJson('colors.json');
    $display_color_name = ""; 
    $has_manual_code = ($_POST['has_color_code'] ?? 'yes'); // Default status from previous data

    // Check if the color code in DB is generated (format: col.N)
    if (strpos($current_data['color_code'], 'col.') !== false) {
        // Search for "Key" (Color Name) based on "Value" (Code col.N)
        $found_name = array_search($current_data['color_code'], $colors_json);
        if ($found_name !== false) {
            $display_color_name = strtoupper($found_name);
            $has_manual_code = 'no'; // Set status to 'no' so the Auto box is displayed
        }
    } else {
        $has_manual_code = 'yes'; // Manual color (direct code)
    }

    function loadJson($file) {
        $path = "data_json/$file";
        return file_exists($path) ? json_decode(file_get_contents($path), true) : [];
    }

    if (isset($_POST['update_frame'])) {
        $brand = strtoupper($_POST['brand']);
        $f_code = !empty($_POST['frame_code']) ? $_POST['frame_code'] : "lz-786";
        $f_size = !empty($_POST['frame_size']) ? $_POST['frame_size'] : "00-00-786";
        
        $material = $_POST['material'] ?? $current_data['material'];
        $lens_shape = $_POST['lens_shape'] ?? $current_data['lens_shape'];
        $structure = $_POST['structure'] ?? $current_data['structure'];
        $size_range = $_POST['size_range'] ?? $current_data['size_range'];
        // Color Logic
        if ($_POST['has_color_code'] == 'no') {
            $colors = loadJson('colors.json');
            $input_color = strtolower($_POST['color_name'] ?? '');
            if (!isset($colors[$input_color])) {
                $next_col = "col." . (count($colors) + 1);
                $colors[$input_color] = $next_col;
                file_put_contents("data_json/colors.json", json_encode($colors, JSON_PRETTY_PRINT));
            }
            $color_code = $colors[$input_color];
        } else {
            $color_code = $_POST['color_manual_code'];
        }

        // Generate New UFC
        $new_ufc = str_replace(' ', '', "$brand-$f_code-$f_size-$color_code");

        $input_stock = (int)$_POST['total_frame'];
        $buy_price = ($role === 'admin') ? (float)$_POST['buy_price'] : (float)$current_data['buy_price'];
        $stock_age = $_POST['stock_age'] ?? 'new';

        // Re-calculate Price
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

        $conn->begin_transaction();
        try {
            if ($new_ufc !== $old_ufc) {
                // If UFC changes, delete the old record because UFC is the Primary Key
                $del = $conn->prepare("DELETE FROM frame_staging WHERE ufc = ?");
                $del->bind_param("s", $old_ufc);
                $del->execute();
                
                // Delete the old QR in the staging folder if it exists (since the UFC is no longer valid)
                if (file_exists("qrcodes/$old_ufc.png")) unlink("qrcodes/$old_ufc.png");
            }

            // Save or Update data to frame_staging
            $stmt = $conn->prepare("INSERT INTO frame_staging 
                (ufc, brand, frame_code, frame_size, color_code, material, lens_shape, structure, size_range, buy_price, sell_price, price_secret_code, stock, stock_age) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                brand=VALUES(brand), frame_code=VALUES(frame_code), frame_size=VALUES(frame_size), color_code=VALUES(color_code), 
                material=VALUES(material), lens_shape=VALUES(lens_shape), structure=VALUES(structure), size_range=VALUES(size_range), 
                buy_price=VALUES(buy_price), sell_price=VALUES(sell_price), price_secret_code=VALUES(price_secret_code), 
                stock=VALUES(stock), stock_age=VALUES(stock_age)");
            
            $stmt->bind_param("sssssssssddsis", $new_ufc, $brand, $f_code, $f_size, $color_code, $_POST['material'], 
                            $_POST['lens_shape'], $_POST['structure'], $_POST['size_range'], $buy_price, $sell_price, 
                            $secret_code, $input_stock, $stock_age);
            $stmt->execute();

            // --- QR CODE CHECK LOGIC ---
            $qr_filename = "$new_ufc.png";
            $staging_path = "qrcodes/" . $qr_filename;
            $main_path = "main_qrcodes/" . $qr_filename; // Target folder for checking

            // Only generate if it doesn't exist in the main_qrcodes folder 
            // AND it also doesn't exist in the qrcodes (staging) folder
            if (!file_exists($main_path) && !file_exists($staging_path)) {
                if (!file_exists('qrcodes')) mkdir('qrcodes', 0777, true);
                QRcode::png($new_ufc, $staging_path, QR_ECLEVEL_L, 4);
            }

            $conn->commit();
            $_SESSION['success_msg'] = "Data Updated Successfully! UFC: $new_ufc";
            header("Location: edit_frame.php?ufc=" . urlencode($new_ufc));
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            echo "Error: " . $e->getMessage();
        }
    }
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Frame - <?php echo $old_ufc; ?></title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Special Style for Neumorphic Cancel Button */
        .back-main {
            padding: 15px 30px;
            border: none;
            border-radius: 12px;
            background: var(--bg-dark); /* Same color as the background */
            color: #e74c3c; /* Soft red color for cancel indication */
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            
            /* Soft Embossed Neumorphic Effect */
            box-shadow: 6px 6px 12px var(--shadow-dark), 
                    -6px -6px 12px var(--shadow-light);
            
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .back-main:hover {
            color: #ff5e4d;
            /* Glow effect on hover */
            text-shadow: 0 0 10px rgba(231, 76, 60, 0.3);
        }

        .back-main:active {
            /* Sunken/Inset effect when pressed */
            box-shadow: inset 4px 4px 8px var(--shadow-dark), 
                        inset -4px -4px 8px var(--shadow-light);
            transform: scale(0.98);
        }

        /* Custom SweetAlert2 Neumorphism Design */
        .swal2-popup-neu {
            background: var(--bg-dark) !important;
            border-radius: 30px !important;
            box-shadow: 10px 10px 20px var(--shadow-dark), 
                    -10px -10px 20px var(--shadow-light) !important;
            color: #ffffff !important;
        }

        .swal2-title-neu {
            color: #ffffff !important;
            font-size: 1.5rem !important;
            text-shadow: 2px 2px 4px var(--shadow-dark);
        }

        .swal2-confirm-neu {
            background: var(--bg-dark) !important;
            color: #007bff !important; /* Warna biru untuk update */
            border-radius: 15px !important;
            font-weight: bold !important;
            box-shadow: 5px 5px 10px var(--shadow-dark), 
                    -5px -5px 10px var(--shadow-light) !important;
            border: none !important;
            margin: 10px !important;
        }

        .swal2-confirm-neu:active {
            box-shadow: inset 3px 3px 6px var(--shadow-dark), 
                        inset -3px -3px 6px var(--shadow-light) !important;
        }

        .swal2-cancel-neu {
            background: var(--bg-dark) !important;
            color: #e74c3c !important;
            border-radius: 15px !important;
            box-shadow: 5px 5px 10px var(--shadow-dark), 
                    -5px -5px 10px var(--shadow-light) !important;
            margin: 10px !important;
        }

        .swal2-confirm-neu:hover {
            color: #00d4ff !important;
            box-shadow: 8px 8px 15px var(--shadow-dark), 
                        -8px -8px 15px var(--shadow-light) !important;
        }

        .swal2-cancel-neu:hover {
            color: #ff7675 !important;
            box-shadow: 8px 8px 15px var(--shadow-dark), 
                        -8px -8px 15px var(--shadow-light) !important;
        }
    </style>
</head>
<body>
    <div class="main-wrapper">
        
    <div class="content-area" style="flex-direction: column">
        <div class="content-area" style="flex-direction: column">
            <div class="header-container" style="
            margin-left: auto; 
            margin-right: auto; 
            width: 100%;">
                <button style="display:none" class="logout-btn" onclick="window.location.href='logout.php';">
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
                <h2>EDIT FRAME DATA</h2>

                <form method="POST" id="editFrameForm">
                    <div class="form-grid">
                        <!-- FRAME NAME -->
                        <div class="input-group">
                            <label>Frame Brand</label>
                            <input type="text" name="brand" required value="<?php echo htmlspecialchars($current_data['brand']); ?>" style="text-transform: uppercase;">
                        </div>

                        <!-- FRAME CODE -->
                        <div class="input-group">
                            <label>Frame Code</label>
                            <input type="text" name="frame_code" value="<?php echo htmlspecialchars($current_data['frame_code']); ?>" style="text-transform: uppercase;">
                        </div>

                        <!-- FRAME SIZE -->
                        <div class="input-group">
                            <label>Frame Size</label>
                            <input type="text" name="frame_size" value="<?php echo htmlspecialchars($current_data['frame_size']); ?>">
                        </div>

                        <!-- HAS COLOR CODE? -->
                        <div class="input-group">
                            <label style="width: 100%; text-align: center;">Manual Color Code?</label>
                            <input type="hidden" name="has_color_code" id="has_color_code_input" value="<?php echo $has_manual_code; ?>">
                            <div id="color_opt" class="selection-wrapper">
                                <button value="no" type="button" class="neu-btn <?php echo ($has_manual_code == 'no') ? 'active' : ''; ?>" onclick="toggleNeu(this, 'has_color_code_input', true)"><span>NO</span><div class="led"></div></button>
                                <button value="yes" type="button" class="neu-btn <?php echo ($has_manual_code == 'yes') ? 'active' : ''; ?>" onclick="toggleNeu(this, 'has_color_code_input', true)"><span>YES</span><div class="led"></div></button>
                            </div>
                        </div>

                        <!-- FRAME COLOR, CODE GENERATE -->
                        <div id="col_name_box" class="input-group <?php echo ($has_manual_code == 'yes') ? 'hidden' : ''; ?>">
                            <label>Frame Color</label>
                            <input type="text" name="color_name" value="<?php echo htmlspecialchars($display_color_name); ?>" placeholder="e.g. BLACK GOLD" style="text-transform: uppercase;">
                        </div>
                        
                        <!-- FRAME COLOR, MANUAL -->
                        <div id="col_manual_box" class="input-group <?php echo ($has_manual_code == 'no') ? 'hidden' : ''; ?>">
                            <label>Color Code</label>
                            <input type="text" name="color_manual_code" value="<?php echo htmlspecialchars($current_data['color_code']); ?>" style="text-transform: uppercase;">
                        </div>

                        <!-- MATERIAL -->
                        <div class="input-group">
                            <label>Material</label>
                            <select name="material">
                                <?php foreach(loadJson('materials.json') as $m): ?>
                                    <option value="<?php echo $m; ?>" <?php if($m==$current_data['material']) echo 'selected'; ?>><?php echo $m; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- LENS SHAPE -->
                        <div class="input-group">
                            <label>Lens Shape</label>
                            <select name="lens_shape">
                                <?php foreach(loadJson('shapes.json') as $s): ?>
                                    <option value="<?php echo $s; ?>" <?php if($s==$current_data['lens_shape']) echo 'selected'; ?>><?php echo $s; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- FRAME STRUCTURE -->
                        <div class="input-group">
                            <label style="width: 100%; text-align: center;">STRUCTURE</label>
                            <input type="hidden" name="structure" id="frame_structure_input" value="<?php echo $current_data['structure']; ?>">
                            <div class="selection-wrapper">
                                <?php $structs = ['full-rim', 'semi-rimless', 'rimless']; 
                                foreach($structs as $st): ?>
                                    <button value="<?php echo $st; ?>" type="button" class="neu-btn <?php echo ($current_data['structure']==$st)?'active':''; ?>" onclick="toggleNeu(this, 'frame_structure_input')">
                                        <span><?php echo strtoupper(str_replace('-', ' ', $st)); ?></span><div class="led"></div>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- FRAME SIZE RANGE -->
                        <div class="input-group">
                            <label style="width: 100%; text-align: center;">SIZE RANGE</label>
                            <input type="hidden" name="size_range" id="frame_size_range_input" value="<?php echo $current_data['size_range']; ?>">
                            <div class="selection-wrapper">
                                <?php $sizes = ['small', 'medium', 'large']; 
                                foreach($sizes as $sz): ?>
                                    <button value="<?php echo $sz; ?>" type="button" class="neu-btn <?php echo ($current_data['size_range']==$sz)?'active':''; ?>" onclick="toggleNeu(this, 'frame_size_range_input')">
                                        <span><?php echo strtoupper($sz); ?></span><div class="led"></div>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- TOTAL FRAME -->
                        <div class="input-group">
                            <label>Total Frame (Stock)</label>
                            <input type="number" name="total_frame" value="<?php echo htmlspecialchars($current_data['stock']); ?>" required>
                        </div>

                        <!-- STOCK AGE -->
                        <div class="input-group">
                            <label style="width: 100%; text-align: center;">STOCK AGE</label>
                            <input type="hidden" name="stock_age" id="stock_age_input" value="<?php echo htmlspecialchars($current_data['stock_age']); ?>">
                            <div class="selection-wrapper">
                                <?php $ages = ['very old', 'old', 'new']; 
                                foreach($ages as $ag): ?>
                                    <button value="<?php echo $ag; ?>" type="button" class="neu-btn <?php echo ($current_data['stock_age']==$ag)?'active':''; ?>" onclick="toggleNeu(this, 'stock_age_input')">
                                        <span><?php echo strtoupper($ag); ?></span><div class="led"></div>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- COST PRICE -->
                        <?php if ($role === 'admin'): ?>
                            <div class="input-group">
                                <label>Cost Price (IDR)</label>
                                <input type="password" id="buy_price" name="buy_price" value="<?php echo $current_data['buy_price']; ?>" oninput="calculatePrice()" autocomplete="off">
                            </div>
                            <div class="submit-main" id="sell_display">Selling Price: IDR <?php echo number_format($current_data['sell_price'], 0, ',', '.'); ?></div>
                        <?php endif; ?>

                        <!-- SUBMIT -->
                        <div class="btn-group">
                            <button type="button" onclick="confirmUpdate()" class="submit-main">UPDATE DATA</button>
                        </div>

                    </div>
                </form>
            </div>
        </div>

        <div class="btn-group">
            <button type="button" class="back-main" onclick="window.location.href='pending_records_frame.php'">BACK TO PREVIOUS PAGE</button>
        </div>
    
        <footer class="footer-container">
            <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
        </footer>
    </div>

    <script>
        const priceRules = <?php echo file_get_contents("data_json/price_rules.json"); ?>;
        const margins = priceRules.margins;

        function calculatePrice() {
            let buy = parseFloat(document.getElementById('buy_price').value);
            let sell = 0;
            if (!isNaN(buy) && buy > 0) {
                let rule = margins.find(m => buy <= m.max) || margins[margins.length - 1];
                sell = Math.ceil((buy + (buy * (rule.percent / 100))) / 5000) * 5000;
            }
            document.getElementById('sell_display').innerText = "Selling Price: IDR " + sell.toLocaleString('id-ID');
        }

        function toggleNeu(el, hiddenInputId, isColorToggle = false) {
            const val = el.value;
            const parent = el.parentElement;
            parent.querySelectorAll('.neu-btn').forEach(b => b.classList.remove('active'));
            el.classList.add('active');
            document.getElementById(hiddenInputId).value = val;

            if (isColorToggle) {
                document.getElementById('col_name_box').classList.toggle('hidden', val === 'yes');
                document.getElementById('col_manual_box').classList.toggle('hidden', val === 'no');
                
                // Additional tip: Clear hidden inputs to make 'required' validation easier
                if(val === 'yes') {
                    document.querySelector('input[name="color_name"]').value = '';
                } else {
                    document.querySelector('input[name="color_manual_code"]').value = '';
                }
            }
        }

        function confirmUpdate() {
            Swal.fire({
                title: 'Confirm Update?',
                text: "System will update data and regenerate QR Code.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'YES, UPDATE',
                cancelButtonText: 'CANCEL',
                customClass: {
                    popup: 'swal2-popup-neu',
                    title: 'swal2-title-neu',
                    confirmButton: 'swal2-confirm-neu',
                    cancelButton: 'swal2-cancel-neu'
                },
                buttonsStyling: false,
                background: '#23272a'
            }).then((result) => {
                if (result.isConfirmed) {
                    // 1. Display a Dark-themed loading spinner
                    Swal.fire({
                        title: 'Processing...',
                        html: 'Updating database and QR Code',
                        allowOutsideClick: false,
                        showConfirmButton: false,
                        background: '#23272a',
                        color: '#fff',
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    // 2. Execute form submission process
                    const form = document.getElementById('editFrameForm');
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'update_frame';
                    hiddenInput.value = '1';
                    form.appendChild(hiddenInput);
                    form.submit();
                }
            });
        }

        <?php if (isset($_SESSION['success_msg'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?php echo $_SESSION['success_msg']; ?>',
                timer: 2000,
                showConfirmButton: false,
                background: '#23272a',
                color: '#fff',
                customClass: { 
                    popup: 'swal2-popup-neu',
                    title: 'swal2-title-neu' 
                }
            });
            <?php unset($_SESSION['success_msg']); ?>
        <?php endif; ?>
    </script>
</body>
</html>