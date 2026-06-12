<?php
    session_start();
    include 'db_config.php';
    include 'activity_helper.php';
    include 'config_helper.php';
    include 'phpqrcode/qrlib.php';
    include 'auth_check.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

    $role = $_SESSION['role'] ?? 'staff';

    function loadJson($file) {
        $path = "data_json/$file";
        if (!file_exists($path)) return []; 
        return json_decode(file_get_contents($path), true);
    }
    

    if (isset($_POST['submit_frame'])) {
        $brand = strtoupper($_POST['brand']);
        $f_code = !empty($_POST['frame_code']) ? strtoupper($_POST['frame_code']) : "lZ-786";
        $f_size = !empty($_POST['frame_size']) ? $_POST['frame_size'] : "00-00-786";
        
        // color
        if ($_POST['has_color_code'] == 'no') {
            $colors = loadJson('colors.json');
            $input_color = strtoupper(trim($_POST['color_name'] ?? ''));
            if (!isset($colors[$input_color])) {
                $next_col = "COL." . (count($colors) + 1);
                $colors[$input_color] = $next_col;
                file_put_contents("data_json/colors.json", json_encode($colors, JSON_PRETTY_PRINT));
            }
            $color_code = $colors[$input_color];
        } else {
            $color_code = strtoupper($_POST['color_manual_code']);
        }

        // ufc (unique frame code)
        $ufc = str_replace(' ', '', "$brand-$f_code-$f_size-$color_code");

        // stock, default 1
        $input_stock = !empty($_POST['total_frame']) ? (int)$_POST['total_frame'] : 1;

        // gender category
        $gender_cat = strtoupper($_POST['gender_category'] ?? 'unisex');

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

        // created by
            $created_by_input = $_SESSION['username'] ?? 'unknown';

            // Get the existing created_by in the database for this ufc
            $check = $conn->prepare("SELECT created_by FROM frame_staging WHERE ufc = ?");
            $check->bind_param("s", $ufc);
            $check->execute();
            $check->bind_result($existing_created_by);
            $check->fetch();
            $check->close();

            // Parse existing created_by
            $entries = [];
            if (!empty($existing_created_by)) {
                foreach (explode(', ', $existing_created_by) as $entry) {
                    // Separate name and stock: "LenZa786 (1)" -> ["LenZa786", 1]
                    preg_match('/^(.+?) \((\d+)\)$/', trim($entry), $m);
                    if ($m) $entries[$m[1]] = (int)$m[2];
                }
            }

            // If user already exists, increase their stock, otherwise append
            if (isset($entries[$created_by_input])) {
                $entries[$created_by_input] += $input_stock;
            } else {
                $entries[$created_by_input] = $input_stock;
            }

            // Rebuild string
            $created_by = implode(', ', array_map(function($name, $qty) { 
                return "$name ($qty)"; 
            }, array_keys($entries), array_values($entries)));

        // query: insert or update stoce also overwrite
        $stmt = $conn->prepare("INSERT INTO frame_staging 
            (ufc, brand, frame_code, frame_size, color_code, material, lens_shape, structure, size_range, gender_category, buy_price, sell_price, price_secret_code, stock, stock_age, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            brand=VALUES(brand), 
            frame_code=VALUES(frame_code), 
            frame_size=VALUES(frame_size), 
            color_code=VALUES(color_code), 
            material=VALUES(material), 
            lens_shape=VALUES(lens_shape), 
            structure=VALUES(structure), 
            size_range=VALUES(size_range),
            gender_category=VALUES(gender_category),
            buy_price=VALUES(buy_price), 
            sell_price=VALUES(sell_price), 
            price_secret_code=VALUES(price_secret_code), 
            stock=stock+VALUES(stock),
            stock_age=VALUES(stock_age),
            created_by=VALUES(created_by)"
        );
        
        $stmt->bind_param("ssssssssssddsiss", 
        $ufc, 
        $brand, 
        $f_code, 
        $f_size, 
        $color_code, 
        $_POST['material'], 
        $_POST['lens_shape'], 
        $_POST['structure'], 
        $_POST['size_range'], 
        $gender_cat,
        $buy_price, 
        $sell_price, 
        $secret_code, 
        $input_stock,
        $stock_age,
        $created_by);
        
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

            log_activity($conn, 'frame_staging', $ufc, 'INSERT', $_SESSION['username'] ?? 'staff');

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

            /* ── Card Selection Style ── */
            .card-select-wrapper {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                justify-content: center;
                width: 100%;
                margin-top: 8px;
            }

            .card-opt {
                position: relative;
                flex: 1 1 100px;
                min-width: 90px;
                max-width: 160px;
                background: var(--card-bg, #2a2d2f);
                border: 2px solid var(--border-color, #3a3d3f);
                border-radius: 14px;
                padding: 16px 10px 14px;
                cursor: pointer;
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 8px;
                transition:
                    transform 0.18s cubic-bezier(.34,1.56,.64,1),
                    border-color 0.18s ease,
                    box-shadow 0.18s ease,
                    background 0.18s ease;
                user-select: none;
                -webkit-tap-highlight-color: transparent;
            }

            .card-opt:hover {
                transform: translateY(-3px) scale(1.03);
                border-color: var(--accent, #00c9a7);
                box-shadow: 0 6px 22px rgba(0,201,167,0.18);
            }

            .card-opt.active {
                border-color: var(--accent, #00c9a7);
                background: var(--card-active-bg, #1e2e2c);
                box-shadow: 0 0 0 3px rgba(0,201,167,0.20), 0 6px 24px rgba(0,201,167,0.18);
                transform: translateY(-2px) scale(1.02);
            }

            .card-opt .card-icon {
                font-size: 1.7rem;
                line-height: 1;
                transition: transform 0.25s cubic-bezier(.34,1.56,.64,1);
                filter: grayscale(0.4);
            }

            .card-opt.active .card-icon {
                transform: scale(1.2);
                filter: grayscale(0);
            }

            .card-opt .card-label {
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: var(--text-muted, #aaa);
                transition: color 0.18s ease;
                text-align: center;
            }

            .card-opt.active .card-label {
                color: var(--accent, #00c9a7);
            }

            .card-opt .card-sub {
                font-size: 0.62rem;
                color: var(--text-faint, #777);
                text-align: center;
                letter-spacing: 0.03em;
            }

            /* Active dot indicator */
            .card-opt::after {
                content: '';
                position: absolute;
                bottom: 7px;
                left: 50%;
                transform: translateX(-50%) scale(0);
                width: 5px;
                height: 5px;
                border-radius: 50%;
                background: var(--accent, #00c9a7);
                transition: transform 0.2s cubic-bezier(.34,1.56,.64,1), opacity 0.2s ease;
                opacity: 0;
            }

            .card-opt.active::after {
                transform: translateX(-50%) scale(1);
                opacity: 1;
            }

            /* Ripple on click */
            .card-opt .ripple {
                position: absolute;
                border-radius: 50%;
                background: rgba(0,201,167,0.25);
                transform: scale(0);
                animation: rippleAnim 0.45s linear;
                pointer-events: none;
            }

            @keyframes rippleAnim {
                to { transform: scale(4); opacity: 0; }
            }

            /* ── Live Preview Panel ── */
            #live-preview {
                width: 100%;
                background: linear-gradient(135deg, #1a1d1f 0%, #1e2b28 100%);
                border: 1.5px solid rgba(0,201,167,0.30);
                border-radius: 18px;
                padding: 20px 22px 18px;
                margin-bottom: 18px;
                box-shadow: 0 0 0 1px rgba(0,201,167,0.08), 0 8px 32px rgba(0,0,0,0.35);
                position: relative;
                overflow: hidden;
            }

            #live-preview::before {
                content: '';
                position: absolute;
                top: 0; left: 0; right: 0;
                height: 3px;
                background: linear-gradient(90deg, #00c9a7, #00a8ff, #00c9a7);
                background-size: 200% 100%;
                animation: shimmerBar 3s linear infinite;
            }

            @keyframes shimmerBar {
                0%   { background-position: 0% 0%; }
                100% { background-position: 200% 0%; }
            }

            .preview-title {
                font-size: 0.7rem;
                font-weight: 800;
                letter-spacing: 0.18em;
                text-transform: uppercase;
                color: var(--accent, #00c9a7);
                margin-bottom: 14px;
                display: flex;
                align-items: center;
                gap: 7px;
            }

            .preview-title::after {
                content: '';
                flex: 1;
                height: 1px;
                background: linear-gradient(90deg, rgba(0,201,167,0.3), transparent);
            }

            /* UFC Badge */
            .ufc-badge {
                background: rgba(0,201,167,0.10);
                border: 1px solid rgba(0,201,167,0.35);
                border-radius: 10px;
                padding: 10px 16px;
                margin-bottom: 14px;
                text-align: center;
            }

            .ufc-badge .ufc-label {
                font-size: 0.62rem;
                color: #888;
                text-transform: uppercase;
                letter-spacing: 0.1em;
                margin-bottom: 3px;
            }

            .ufc-badge .ufc-value {
                font-size: 1.05rem;
                font-weight: 800;
                color: #00c9a7;
                letter-spacing: 0.06em;
                word-break: break-all;
                font-family: monospace;
            }

            /* Grid rows */
            .preview-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px 14px;
            }

            @media (max-width: 480px) {
                .preview-grid { grid-template-columns: 1fr; }
            }

            .preview-item {
                display: flex;
                flex-direction: column;
                gap: 2px;
                padding: 8px 10px;
                background: rgba(255,255,255,0.03);
                border-radius: 9px;
                border-left: 2px solid rgba(0,201,167,0.20);
                transition: border-color 0.25s ease, background 0.25s ease;
            }

            .preview-item.changed {
                border-left-color: #00c9a7;
                background: rgba(0,201,167,0.06);
            }

            .preview-item .pi-label {
                font-size: 0.60rem;
                font-weight: 700;
                color: #666;
                text-transform: uppercase;
                letter-spacing: 0.09em;
            }

            .preview-item .pi-value {
                font-size: 0.82rem;
                font-weight: 600;
                color: #ddd;
                word-break: break-word;
            }

            .preview-item .pi-value.empty {
                color: #444;
                font-style: italic;
                font-weight: 400;
            }

            /* Pulse animation on value change */
            @keyframes valueFlash {
                0%   { color: #00c9a7; }
                100% { color: #ddd; }
            }

            .pi-value.flash {
                animation: valueFlash 0.5s ease-out forwards;
            }

            /* ── Collapsible Preview Wrapper ── */
            #preview-wrapper {
                flex: 0 0 100%;
                max-width: 100%;
                grid-column: 1 / -1;
                width: 100% !important;
                margin-top: 8px;
                display: none; /* hidden until there is input */
            }

            #preview-wrapper.has-data {
                display: block;
                animation: fadeSlideIn 0.35s ease-out;
            }

            @keyframes fadeSlideIn {
                from { opacity: 0; transform: translateY(-8px); }
                to   { opacity: 1; transform: translateY(0); }
            }

            /* Toggle header button */
            #preview-toggle-btn {
                width: 100%;
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: rgba(0,201,167,0.08);
                border: 1.5px solid rgba(0,201,167,0.28);
                border-radius: 12px;
                padding: 10px 16px;
                cursor: pointer;
                color: #00c9a7;
                font-size: 0.72rem;
                font-weight: 800;
                letter-spacing: 0.14em;
                text-transform: uppercase;
                transition: background 0.2s ease, border-color 0.2s ease;
                margin-bottom: 0;
            }

            #preview-toggle-btn:hover {
                background: rgba(0,201,167,0.14);
                border-color: rgba(0,201,167,0.50);
            }

            #preview-toggle-btn .toggle-arrow {
                font-size: 1rem;
                transition: transform 0.3s cubic-bezier(.34,1.56,.64,1);
                line-height: 1;
            }

            #preview-toggle-btn.collapsed .toggle-arrow {
                transform: rotate(-90deg);
            }

            /* Preview body: animated open/close */
            #preview-body {
                overflow: hidden;
                max-height: 1000px;
                transition: max-height 0.4s ease, opacity 0.3s ease, margin-top 0.3s ease;
                opacity: 1;
                margin-top: 8px;
            }

            #preview-body.collapsed {
                max-height: 0;
                opacity: 0;
                margin-top: 0;
            }

            .badge {
                display: inline-block;
                width: 28px;
                height: 28px;
                line-height: 28px;
                text-align: center;
                font-weight: bold;
                border-radius: 6px; /* Kotak agak membulat seperti 🅂 */
                color: #fff;
                font-size: 14px;
            }
            .size-s { background-color: #2ecc71; } /* Hijau untuk Small */
            .size-m { background-color: #f1c40f; color: #333; } /* Kuning untuk Medium */
            .size-l { background-color: #e74c3c; } /* Merah untuk Large */
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
                                <input type="text" id="frame_code" name="frame_code" placeholder="lZ-786" style="text-transform: uppercase;">
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
                                <select name="lens_shape" id="lens_shape_select" onchange="autoSetGender(this.value)">
                                    <?php foreach(loadJson('shapes.json') as $s) echo "<option value='$s'>$s</option>"; ?>
                                </select>
                            </div>
            
                            <!-- FRAME STRUCTURE -->
                            <div class="input-group" style="flex: 0 0 100%; max-width: 100%; grid-column: 1 / -1; width: 100% !important;">
                                <label style="width: 100%; text-align: center; margin-bottom: 0;">FRAME STRUCTURE</label>
                                <input type="hidden" name="structure" id="frame_structure_input" value="full-rim">
                                <div class="card-select-wrapper" id="card_structure">
                                    <button type="button" class="card-opt active" value="full-rim" onclick="selectCard(this,'frame_structure_input','card_structure')">
                                        <span class="card-icon">
                                            <img src="image/frame_data_entry/full_rim.png" alt="Kacamata" style="width: 60px; height: auto; vertical-align: middle;">
                                        </span>
                                        <span class="card-label">FULL RIM</span>
                                        <span class="card-sub">Full frame</span>
                                    </button>
                                    <button type="button" class="card-opt" value="semi-rimless" onclick="selectCard(this,'frame_structure_input','card_structure')">
                                        <span class="card-icon">
                                            <img src="image/frame_data_entry/semi_rimless.png" alt="Kacamata" style="width: 60px; height: auto; vertical-align: middle;">
                                        </span>
                                        <span class="card-label">SEMI RIMLESS</span>
                                        <span class="card-sub">Half frame</span>
                                    </button>
                                    <button type="button" class="card-opt" value="rimless" onclick="selectCard(this,'frame_structure_input','card_structure')">
                                    <span class="card-icon">
                                        <img src="image/frame_data_entry/rimless.png" alt="Kacamata" style="width: 60px; height: auto; vertical-align: middle;">
                                    </span>
                                        <span class="card-label">RIMLESS</span>
                                        <span class="card-sub">No frame</span>
                                    </button>
                                </div>
                            </div>
            
                            <!-- FRAME SIZE RANGE -->
                            <div class="input-group" style="flex: 0 0 100%; max-width: 100%; grid-column: 1 / -1; width: 100% !important;">
                                <label style="width: 100%; text-align: center; margin-bottom: 0;">SIZE RANGE</label>
                                <input type="hidden" name="size_range" id="frame_size_range_input" value="small">
                                <div class="card-select-wrapper" id="card_size_range">
                                    <button type="button" class="card-opt active" value="small" onclick="selectCard(this,'frame_size_range_input','card_size_range')">
                                        <span class="badge size-s">S</span>
                                        <span class="card-label">SMALL</span>
                                        <span class="card-sub">Narrow fit</span>
                                    </button>
                                    <button type="button" class="card-opt" value="medium" onclick="selectCard(this,'frame_size_range_input','card_size_range')">
                                        <span class="badge size-m">M</span>
                                        <span class="card-label">MEDIUM</span>
                                        <span class="card-sub">Standard fit</span>
                                    </button>
                                    <button type="button" class="card-opt" value="large" onclick="selectCard(this,'frame_size_range_input','card_size_range')">
                                        <span class="badge size-l">L</span>
                                        <span class="card-label">LARGE</span>
                                        <span class="card-sub">Wide fit</span>
                                    </button>
                                </div>
                            </div>

                            <!-- GENDER CATEGORY -->
                            <div class="input-group" style="flex: 0 0 100%; max-width: 100%; grid-column: 1 / -1; width: 100% !important;">
                                <label style="width: 100%; text-align: center; margin-bottom: 0;">GENDER CATEGORY</label>
                                <input type="hidden" name="gender_category" id="gender_category_input" value="unisex">
                                <div class="card-select-wrapper" id="card_gender">
                                    <button type="button" class="card-opt" value="men" onclick="selectCard(this,'gender_category_input','card_gender')">
                                        <span class="card-icon">♂️</span>
                                        <span class="card-label">MEN</span>
                                        <span class="card-sub">Masculine</span>
                                    </button>
                                    <button type="button" class="card-opt" value="female" onclick="selectCard(this,'gender_category_input','card_gender')">
                                        <span class="card-icon">♀️</span>
                                        <span class="card-label">FEMALE</span>
                                        <span class="card-sub">Feminine</span>
                                    </button>
                                    <button type="button" class="card-opt active" value="unisex" onclick="selectCard(this,'gender_category_input','card_gender')">
                                        <span class="card-icon">⚧️</span>
                                        <span class="card-label">UNISEX</span>
                                        <span class="card-sub">For all</span>
                                    </button>
                                </div>
                            </div>
            
                            <!-- TOTAL FRAME -->
                            <div class="input-group">
                                <label for="total_frame">Total Frame (Stock)</label>
                                <input type="number" id="total_frame" name="total_frame" value="1" min="1" required>
                            </div>

                            <!-- STOCK AGE -->
                            <div class="input-group" style="flex: 0 0 100%; max-width: 100%; grid-column: 1 / -1; width: 100% !important;">
                                <label style="width: 100%; text-align: center; margin-bottom: 0;">STOCK AGE</label>
                                <input type="hidden" name="stock_age" id="stock_age_input" value="new">
                                <div class="card-select-wrapper" id="card_stock_age">
                                    <button type="button" class="card-opt" value="very old" onclick="selectCard(this,'stock_age_input','card_stock_age')">
                                        <span class="card-icon">🦕</span>
                                        <span class="card-label">VERY OLD</span>
                                        <span class="card-sub">Long stocked</span>
                                    </button>
                                    <button type="button" class="card-opt" value="old" onclick="selectCard(this,'stock_age_input','card_stock_age')">
                                        <span class="card-icon">📦</span>
                                        <span class="card-label">OLD</span>
                                        <span class="card-sub">In shelf a while</span>
                                    </button>
                                    <button type="button" class="card-opt active" value="new" onclick="selectCard(this,'stock_age_input','card_stock_age')">
                                        <span class="card-icon">✨</span>
                                        <span class="card-label">NEW</span>
                                        <span class="card-sub">Fresh stock</span>
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
                            
                            <!-- ── LIVE PREVIEW PANEL ── -->
                            <div id="preview-wrapper">

                                <!-- Toggle Header -->
                                <button type="button" id="preview-toggle-btn" onclick="togglePreview()">
                                    <span>📋 Review Input</span>
                                    <span class="toggle-arrow">▼</span>
                                </button>

                                <!-- Preview Body (collapsible) -->
                                <div id="preview-body">
                                    <div id="live-preview">

                                        <!-- UFC Badge -->
                                        <div class="ufc-badge">
                                            <div class="ufc-label">Unique Frame Code (UFC)</div>
                                            <div class="ufc-value" id="prev-ufc">—</div>
                                        </div>

                                        <!-- Detail Grid -->
                                        <div class="preview-grid">
                                            <div class="preview-item" id="pi-brand">
                                                <span class="pi-label">Brand</span>
                                                <span class="pi-value empty" id="prev-brand">—</span>
                                            </div>
                                            <div class="preview-item" id="pi-code">
                                                <span class="pi-label">Frame Code</span>
                                                <span class="pi-value empty" id="prev-code">—</span>
                                            </div>
                                            <div class="preview-item" id="pi-size">
                                                <span class="pi-label">Frame Size</span>
                                                <span class="pi-value empty" id="prev-size">—</span>
                                            </div>
                                            <div class="preview-item" id="pi-color">
                                                <span class="pi-label">Color</span>
                                                <span class="pi-value empty" id="prev-color">—</span>
                                            </div>
                                            <div class="preview-item" id="pi-material">
                                                <span class="pi-label">Material</span>
                                                <span class="pi-value" id="prev-material">—</span>
                                            </div>
                                            <div class="preview-item" id="pi-shape">
                                                <span class="pi-label">Lens Shape</span>
                                                <span class="pi-value" id="prev-shape">—</span>
                                            </div>
                                            <div class="preview-item" id="pi-structure">
                                                <span class="pi-label">Structure</span>
                                                <span class="pi-value" id="prev-structure">FULL-RIM</span>
                                            </div>
                                            <div class="preview-item" id="pi-sizerange">
                                                <span class="pi-label">Size Range</span>
                                                <span class="pi-value" id="prev-sizerange">SMALL</span>
                                            </div>
                                            <div class="preview-item" id="pi-gender">
                                                <span class="pi-label">Gender</span>
                                                <span class="pi-value" id="prev-gender">UNISEX</span>
                                            </div>
                                            <div class="preview-item" id="pi-stock">
                                                <span class="pi-label">Stock (Qty)</span>
                                                <span class="pi-value" id="prev-stock">1</span>
                                            </div>
                                            <div class="preview-item" id="pi-stockage">
                                                <span class="pi-label">Stock Age</span>
                                                <span class="pi-value" id="prev-stockage">NEW ✨</span>
                                            </div>
                                            <?php if ($role === 'admin'): ?>
                                            <div class="preview-item" id="pi-price">
                                                <span class="pi-label">Sell Price (Est.)</span>
                                                <span class="pi-value" id="prev-price">—</span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div><!-- /#preview-body -->
                            </div><!-- /#preview-wrapper -->

                            <!-- Submit and Update Settings -->
                            <div class="btn-group" style="<?= ($role === 'staff') ? 'width: 100%' : 'width: 50%' ?>">
                                <?php if ($role === 'admin' || $role === 'staff'): ?>
                                    <button type="submit" name="submit_frame" class="submit-main" >SAVE DATA</button>
                                <?php endif; ?>
                                <?php if ($role === 'admin'): ?>
                                    <button type="button" class="submit-main" onclick="window.location.href='manage_settings.php'">UPDATE SETTINGS</button>
                                <?php endif; ?>
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

            // ── Card Selection Function (for card-opt groups) ──
            function selectCard(el, hiddenInputId, wrapperId) {
                // Ripple effect
                const ripple = document.createElement('span');
                ripple.classList.add('ripple');
                const rect = el.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                ripple.style.cssText = `width:${size}px;height:${size}px;left:${(rect.width-size)/2}px;top:${(rect.height-size)/2}px;`;
                el.appendChild(ripple);
                ripple.addEventListener('animationend', () => ripple.remove());

                // Toggle active
                document.getElementById(wrapperId).querySelectorAll('.card-opt').forEach(b => b.classList.remove('active'));
                el.classList.add('active');

                // Save value
                document.getElementById(hiddenInputId).value = el.value;
            }

            // 1. Primary Toggle Function (still used by color / has_color_code sections)
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

            // 2. Auto-set gender category based on lens shape
                const shapeGenderMap = {
                    // Men
                    'RECTANGLE': 'men',
                    'BROWLINE': 'men',
                    // Female
                    'CAT-EYE': 'female',
                    'BUTTERFLY': 'female',
                    'OVAL': 'female',
                    // Unisex
                    'AVIATOR': 'unisex',
                    'WAYFARER': 'unisex',
                    'ROUND': 'unisex',
                    'SQUARE': 'unisex',
                    'GEOMETRIC': 'unisex'
                };

                function autoSetGender(shapeValue) {
                    const gender = shapeGenderMap[shapeValue.toUpperCase()];
                    if (!gender) return;

                    // Target card-opt buttons in the gender wrapper
                    const genderCards = document.querySelectorAll('#card_gender .card-opt');
                    genderCards.forEach(btn => {
                        if (btn.value === gender) {
                            selectCard(btn, 'gender_category_input', 'card_gender');
                        }
                    });
                }

            // 3. Execution on Page Load
            document.addEventListener('DOMContentLoaded', function() {
                // Execute for neu-btn groups that have 'active' class by default (color toggle)
                document.querySelectorAll('.neu-btn.active').forEach(btn => {
                    if (btn.closest('#color_opt')) {
                        toggleNeu(btn, 'has_color_code_input', true);
                    }
                });

                // Auto-set gender sesuai shape default di dropdown
                const shapeSelect = document.getElementById('lens_shape_select');
                if (shapeSelect) autoSetGender(shapeSelect.value);
            });

            // ── PREVIEW TOGGLE ──
            function togglePreview() {
                const btn  = document.getElementById('preview-toggle-btn');
                const body = document.getElementById('preview-body');
                const isCollapsed = body.classList.contains('collapsed');
                if (isCollapsed) {
                    body.classList.remove('collapsed');
                    btn.classList.remove('collapsed');
                } else {
                    body.classList.add('collapsed');
                    btn.classList.add('collapsed');
                }
            }

            // ── LIVE PREVIEW LOGIC ──
            function setPreviewVal(id, value, fallback) {
                const el = document.getElementById(id);
                if (!el) return;
                const display = value && value.trim() !== '' ? value : null;
                const piEl = el.closest('.preview-item');
                if (display) {
                    el.textContent = display;
                    el.classList.remove('empty');
                    el.classList.remove('flash');
                    void el.offsetWidth;
                    el.classList.add('flash');
                    if (piEl) piEl.classList.add('changed');
                } else {
                    el.textContent = fallback || '—';
                    el.classList.add('empty');
                    if (piEl) piEl.classList.remove('changed');
                }
            }

            const stockAgeEmoji = { 'new': '✨', 'old': '📦', 'very old': '🦕' };

            function updatePreview() {
                const brand     = (document.getElementById('brand')?.value || '').toUpperCase().trim();
                const code      = (document.getElementById('frame_code')?.value || '').toUpperCase().trim() || 'LZ-786';
                const size      = (document.getElementById('frame_size')?.value || '').trim() || '00-00-786';
                const hasCode   = document.getElementById('has_color_code_input')?.value || 'no';
                const colorRaw  = hasCode === 'yes'
                    ? (document.getElementById('color_code_manual')?.value || '').toUpperCase().trim()
                    : (document.getElementById('color_code_generate')?.value || '').toUpperCase().trim();
                const material  = document.querySelector('select[name="material"]')?.value || '';
                const shape     = document.getElementById('lens_shape_select')?.value || '';
                const structure = document.getElementById('frame_structure_input')?.value || 'full-rim';
                const sizeRange = document.getElementById('frame_size_range_input')?.value || 'small';
                const gender    = document.getElementById('gender_category_input')?.value || 'unisex';
                const stock     = document.getElementById('total_frame')?.value || '1';
                const stockAge  = document.getElementById('stock_age_input')?.value || 'new';
                const colorDisplay = colorRaw || (hasCode === 'yes' ? '(enter color code)' : '(enter color name)');

                // UFC Preview
                const ufcRaw = brand ? `${brand}-${code}-${size}-${colorRaw || '???'}` : '—';
                const ufc = ufcRaw.replace(/\s/g, '');
                const ufcEl = document.getElementById('prev-ufc');
                if (ufcEl) {
                    ufcEl.textContent = ufc;
                    ufcEl.classList.remove('flash');
                    void ufcEl.offsetWidth;
                    ufcEl.classList.add('flash');
                }

                setPreviewVal('prev-brand', brand);
                setPreviewVal('prev-code', code);

                // Show preview wrapper only when brand has been filled
                const wrapper = document.getElementById('preview-wrapper');
                if (wrapper) {
                    if (brand) {
                        wrapper.classList.add('has-data');
                    } else {
                        wrapper.classList.remove('has-data');
                    }
                }
                setPreviewVal('prev-size', size);
                setPreviewVal('prev-color', colorDisplay);
                setPreviewVal('prev-material', material, '—');
                setPreviewVal('prev-shape', shape.toUpperCase(), '—');
                setPreviewVal('prev-structure', structure.toUpperCase(), '—');
                setPreviewVal('prev-sizerange', sizeRange.toUpperCase(), '—');
                setPreviewVal('prev-gender', gender.toUpperCase(), '—');
                setPreviewVal('prev-stock', stock, '1');

                const ageLabel = stockAge.toUpperCase() + ' ' + (stockAgeEmoji[stockAge] || '');
                setPreviewVal('prev-stockage', ageLabel.trim(), '—');

                // Sell price (admin only)
                const priceEl = document.getElementById('prev-price');
                if (priceEl) {
                    const sellEl = document.getElementById('sell_display');
                    const text = sellEl ? sellEl.innerText : '';
                    const match = text.match(/IDR\s*([\d.,]+)/);
                    const num = match ? match[1] : null;
                    setPreviewVal('prev-price', num && num !== '0' ? 'IDR ' + num : null, '—');
                }
            }

            // Attach watchers on DOM ready
            document.addEventListener('DOMContentLoaded', function() {
                ['brand','frame_code','frame_size','color_code_generate','color_code_manual','total_frame','buy_price'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.addEventListener('input', updatePreview);
                });

                const matEl = document.querySelector('select[name="material"]');
                if (matEl) matEl.addEventListener('change', updatePreview);

                const shapeSelEl = document.getElementById('lens_shape_select');
                if (shapeSelEl) shapeSelEl.addEventListener('change', updatePreview);

                ['frame_structure_input','frame_size_range_input','gender_category_input','stock_age_input','has_color_code_input'].forEach(id => {
                    const el = document.getElementById(id);
                    if (!el) return;
                    new MutationObserver(updatePreview).observe(el, { attributes: true });
                    el.addEventListener('change', updatePreview);
                });

                updatePreview();
            });

            // Patch selectCard & toggleNeu to also trigger preview
            const _origSelectCard = selectCard;
            window.selectCard = function(el, hiddenInputId, wrapperId) {
                _origSelectCard(el, hiddenInputId, wrapperId);
                setTimeout(updatePreview, 10);
            };
            const _origToggleNeu = toggleNeu;
            window.toggleNeu = function(el, hiddenInputId, isColorToggle) {
                _origToggleNeu(el, hiddenInputId, isColorToggle);
                setTimeout(updatePreview, 10);
            };
        </script>

    </body>
</html>