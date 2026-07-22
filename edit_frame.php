<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';
    include 'phpqrcode/qrlib.php';
    include 'auth_check.php'; 

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
        $f_code = !empty($_POST['frame_code']) ? strtoupper($_POST['frame_code']) : "lZ-786";
        $f_size = !empty($_POST['frame_size']) ? $_POST['frame_size'] : "00-00-786";
        
        $material = $_POST['material'] ?? $current_data['material'];
        $lens_shape = $_POST['lens_shape'] ?? $current_data['lens_shape'];
        $structure = $_POST['structure'] ?? $current_data['structure'];
        $size_range = $_POST['size_range'] ?? $current_data['size_range'];
        $gender_category = strtoupper($_POST['gender_category'] ?? $current_data['gender_category']);
        
        // Color Logic
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

        // Generate New UFC
        $new_ufc = str_replace(' ', '', "$brand-$f_code-$f_size-$color_code");

        $input_stock = (int)$_POST['total_frame'];
        $buy_price = ($role === 'admin') ? (float)$_POST['buy_price'] : (float)$current_data['buy_price'];

        // --- CREATED_BY STOCK TRACKING ---
        // Compare new stock vs old stock to detect changes made by this user
        $old_stock = (int)$current_data['stock'];
        $stock_delta = $input_stock - $old_stock;
        $current_editor = $_SESSION['username'] ?? 'unknown';

        $existing_created_by = trim($current_data['created_by'] ?? '');

        if ($stock_delta !== 0) {
            // Format: "username (delta)" — positive delta has no sign, negative has minus sign
            $delta_label = ($stock_delta > 0)
                ? $current_editor . ' (' . $stock_delta . ')'
                : $current_editor . ' (' . $stock_delta . ')';

            $new_created_by = ($existing_created_by !== '')
                ? $existing_created_by . ', ' . $delta_label
                : $delta_label;
        } else {
            // No stock change — keep created_by as-is
            $new_created_by = $existing_created_by;
        }
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
            // log after execute below
                $del->bind_param("s", $old_ufc);
                $del->execute();
                
                // Delete the old QR in the staging folder if it exists (since the UFC is no longer valid)
                if (file_exists("qrcodes/$old_ufc.png")) unlink("qrcodes/$old_ufc.png");
            }

            // Save or Update data to frame_staging (including created_by stock tracking)
            $stmt = $conn->prepare("INSERT INTO frame_staging 
                (ufc, brand, frame_code, frame_size, color_code, material, lens_shape, structure, size_range, gender_category, buy_price, sell_price, price_secret_code, stock, stock_age, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                brand=VALUES(brand), frame_code=VALUES(frame_code), frame_size=VALUES(frame_size), color_code=VALUES(color_code), 
                material=VALUES(material), lens_shape=VALUES(lens_shape), structure=VALUES(structure), size_range=VALUES(size_range), 
                gender_category=VALUES(gender_category), buy_price=VALUES(buy_price), sell_price=VALUES(sell_price), price_secret_code=VALUES(price_secret_code), 
                stock=VALUES(stock), stock_age=VALUES(stock_age), created_by=VALUES(created_by)");
            
            $stmt->bind_param("ssssssssssddsiss", $new_ufc, $brand, $f_code, $f_size, $color_code, $_POST['material'], 
                            $_POST['lens_shape'], $_POST['structure'], $_POST['size_range'], $gender_category, $buy_price, $sell_price, 
                            $secret_code, $input_stock, $stock_age, $new_created_by);
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
            header("Location: pending_records_frame.php");
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
        <title>Edit Frame - <?php echo htmlspecialchars($old_ufc); ?></title>
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
                min-height: 140px;
                background: var(--card-bg, #2a2d2f);
                border: 2px solid var(--border-color, #3a3d3f);
                border-radius: 14px;
                padding: 18px 10px 26px;
                justify-content: center;
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
                color: #00ff88;
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

            /* ── AUTO-SCROLL "DONE" BUTTON (matches frame_data_entry.php) ── */
            /* Prevent the browser's automatic scroll anchoring from fighting
               our manual scrollIntoView() calls whenever a card expands or
               collapses (which shifts the layout above the fold). */
            html, body {
                overflow-anchor: none;
            }
            .cs-group-body {
                overflow-anchor: none;
            }

            /* Wraps an input + its Done button. Default: Done button sits to the right of the input. */
            .input-done-wrap {
                position: relative;
                display: flex;
                align-items: center;
                gap: 8px;
                width: 100%;
            }

            .input-done-wrap input {
                flex: 1 1 auto;
                min-width: 0; /* prevent flex overflow */
            }

            /* Variant: Done button placed below the input, with extra spacing (used for Frame Color) */
            .input-done-wrap.done-below {
                flex-direction: column;
                align-items: stretch;
            }

            .input-done-wrap.done-below .done-btn {
                margin-top: 12px;
                align-self: center;
            }

            /* The Done button itself: hidden by default, only shown once the user types something */
            .done-btn {
                display: none;
                flex-shrink: 0;
                border: none;
                padding: 9px 16px;
                border-radius: 10px;
                background: linear-gradient(135deg, #00ff88, #00e0a8);
                color: #06231b;
                font-size: 0.72rem;
                font-weight: 800;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                cursor: pointer;
                white-space: nowrap;
                box-shadow: 0 4px 16px rgba(0,255,136,0.40), 0 0 0 1px rgba(0,255,136,0.25);
                transition: transform 0.15s cubic-bezier(.34,1.56,.64,1), box-shadow 0.15s ease, background 0.15s ease;
            }

            .done-btn.show {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                animation: doneBtnPop 0.22s cubic-bezier(.34,1.56,.64,1);
            }

            .done-btn:hover {
                transform: translateY(-2px) scale(1.04);
                box-shadow: 0 6px 20px rgba(0,255,136,0.55), 0 0 0 1px rgba(0,255,136,0.35);
            }

            .done-btn:active {
                transform: translateY(0) scale(0.97);
            }

            @keyframes doneBtnPop {
                from { opacity: 0; transform: scale(0.75); }
                to   { opacity: 1; transform: scale(1); }
            }

            /* Brief highlight pulse applied to whatever the Done button scrolls to,
               so the user's eye is drawn to the newly-focused field/card */
            .scroll-focus-highlight {
                animation: scrollFocusPulse 0.9s ease;
            }

            @keyframes scrollFocusPulse {
                0%   { box-shadow: 0 0 0 3px rgba(0,201,167,0.55); }
                100% { box-shadow: 0 0 0 0 rgba(0,201,167,0); }
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
                display: block;
            }

            #preview-wrapper.has-data {
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

            /* ── Collapsible Card-Select Groups (Structure/Size/Gender/StockAge) ── */
            .cs-group {
                flex: 0 0 100%;
                max-width: 100%;
                grid-column: 1 / -1;
                width: 100% !important;
                border: 1px solid var(--border-color, #3a3d3f);
                border-radius: 14px;
                background: var(--card-bg, #2a2d2f);
                overflow: hidden;
                transition: border-color 0.2s ease;
            }

            .cs-group-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                width: 100%;
                padding: 12px 16px;
                background: transparent;
                border: none;
                cursor: pointer;
                -webkit-tap-highlight-color: transparent;
                user-select: none;
            }

            .cs-group-header:hover {
                background: rgba(0,201,167,0.06);
            }

            .cs-group-title {
                font-size: 0.78rem;
                font-weight: 800;
                letter-spacing: 0.14em;
                text-transform: uppercase;
                color: var(--text-main, #e8e8e8);
            }

            .cs-group-current {
                font-size: 0.68rem;
                font-weight: 700;
                letter-spacing: 0.05em;
                color: #00ff88;
                text-transform: uppercase;
                margin-left: 8px;
                white-space: nowrap;
            }

            .cs-group-right {
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .cs-group-arrow {
                font-size: 1rem;
                color: var(--text-muted, #aaa);
                transition: transform 0.3s cubic-bezier(.34,1.56,.64,1);
                line-height: 1;
            }

            .cs-group.expanded .cs-group-arrow {
                transform: rotate(180deg);
            }

            .cs-group-body {
                overflow: hidden;
                max-height: 0;
                opacity: 0;
                transition: max-height 0.35s ease, opacity 0.25s ease, padding 0.35s ease;
                padding: 0 14px;
            }

            .cs-group.expanded .cs-group-body {
                max-height: 600px;
                opacity: 1;
                padding: 14px 14px 16px;
            }

            .cs-group .card-select-wrapper {
                margin-top: 0;
            }

            /* ── Frame Color Card (Has Color Code? + Frame Color input) ── */
            .frame-color-input {
                margin-top: 14px;
            }

            @media (max-width: 480px) {
                .cs-group-title {
                    font-size: 0.7rem;
                }
                .cs-group-current {
                    font-size: 0.62rem;
                }
                .cs-group-header {
                    padding: 10px 12px;
                }
                .cs-group.expanded .cs-group-body {
                    padding: 12px 8px 14px;
                }
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
                border-radius: 6px;
                color: #fff;
                font-size: 14px;
            }
            .size-s { background-color: #2ecc71; }
            .size-m { background-color: #f1c40f; color: #333; }
            .size-l { background-color: #e74c3c; }

            /* ── Material and Lens Shape Options Styling ── */
            .material-options,
            .lens-shape-options {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                padding: 12px;
            }

            .material-opt,
            .lens-shape-opt {
                flex: 1 1 auto;
                min-width: 80px;
                padding: 8px 12px;
                background-color: rgba(42, 45, 47, 0.6);
                border: 1.5px solid rgba(58, 61, 63, 0.8);
                border-radius: 8px;
                color: #aaa;
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                cursor: pointer;
                transition: all 0.18s ease;
                white-space: nowrap;
                text-align: center;
            }

            .material-opt:hover,
            .lens-shape-opt:hover {
                border-color: #00c9a7;
                color: #00c9a7;
                background-color: rgba(0, 201, 167, 0.1);
            }

            .material-opt.active,
            .lens-shape-opt.active {
                border-color: #00c9a7;
                background-color: rgba(0, 201, 167, 0.15);
                color: #00ff88;
                font-weight: 700;
            }

            /* Special Style for Neumorphic Cancel Button */
            .back-main {
                padding: 15px 30px;
                border: none;
                border-radius: 12px;
                background: var(--bg-dark); /* Same color as the background */
                color: var(--text-faint, #777); /* Same color as the footer text */
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
                color: var(--text-muted, #aaa);
                /* Glow effect on hover */
                text-shadow: 0 0 10px rgba(255, 255, 255, 0.15);
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
                color: #007bff !important;
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

            /* ── Settings icon shortcut placed next to the header ── */
            .main-card-header {
                display: grid;
                grid-template-columns: 1fr auto 1fr;
                align-items: center;
                gap: 10px;
            }

            .main-card-header h2 {
                grid-column: 2;
                text-align: center;
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

            /* Center the header block (logout button + logo/name/address group)
               on PC to match how it already appears centered on mobile. Only
               the container's own horizontal position is changed here — the
               internal layout is left exactly as in the original code. */
            .header-container {
                margin-left: auto !important;
                margin-right: auto !important;
                width: fit-content !important;
            }
        </style>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    </head>

    <body>
        <div class="main-wrapper">
            <div class="content-area" style="flex-direction: column">
                <div class="header-container">               
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
                width: 100%;">
                    <div class="main-card-header">
                        <h2>EDIT FRAME DATA</h2>
                    </div>

                    <form method="POST" id="editFrameForm">
                        <div class="form-grid">
                            <!-- FRAME NAME -->
                            <div class="input-group">
                                <label for="brand">Frame Brand</label>
                                <div class="input-done-wrap">
                                    <input type="text" id="brand" name="brand" required value="<?php echo htmlspecialchars($current_data['brand']); ?>" style="text-transform: uppercase;">
                                </div>
                            </div>

                            <!-- FRAME SIZE -->
                            <div class="input-group">
                                <label for="frame_size">Frame Size</label>
                                <div class="input-done-wrap">
                                    <input type="text" id="frame_size" name="frame_size" value="<?php echo htmlspecialchars($current_data['frame_size']); ?>" inputmode="decimal" pattern="[0-9\+\-\*\/]*">
                                </div>
                            </div>

                            <!-- FRAME CODE -->
                            <div class="input-group">
                                <label for="frame_code">Frame Code</label>
                                <div class="input-done-wrap">
                                    <input type="text" id="frame_code" name="frame_code" value="<?php echo htmlspecialchars($current_data['frame_code']); ?>" style="text-transform: uppercase;">
                                </div>
                            </div>

                            <!-- FRAME COLOR (Has Color Code? + Frame Color, collapsible card) -->
                            <div class="cs-group" id="csgroup_frame_color">
                                <button type="button" class="cs-group-header" onclick="toggleCsGroup('csgroup_frame_color')">
                                    <span class="cs-group-title">Frame Color</span>
                                    <span class="cs-group-right">
                                        <span class="cs-group-current" id="cscurrent_frame_color">
                                            <?php echo htmlspecialchars($has_manual_code == 'yes' ? $current_data['color_code'] : $display_color_name); ?>
                                        </span>
                                        <span class="cs-group-arrow">▾</span>
                                    </span>
                                </button>
                                <div class="cs-group-body">
                                    <div class="input-group">
                                        <label style="width: 100%; text-align: center; margin-bottom: 0;">Has Color Code?</label>
                                        <input type="hidden" name="has_color_code" id="has_color_code_input" value="<?php echo htmlspecialchars($has_manual_code); ?>">
                                        <div id="color_opt" class="card-select-wrapper">
                                            <button value="no" type="button" class="card-opt neu-btn <?php echo ($has_manual_code == 'no') ? 'active' : ''; ?>" onclick="toggleNeu(this, 'has_color_code_input', true)">
                                                <span class="card-label">NO</span>
                                                <span class="card-sub">MANUALLY INPUT FRAME COLOR</span>
                                            </button>
                                            <button value="yes" type="button" class="card-opt neu-btn <?php echo ($has_manual_code == 'yes') ? 'active' : ''; ?>" onclick="toggleNeu(this, 'has_color_code_input', true)">
                                                <span class="card-label">YES</span>
                                                <span class="card-sub">FRAME HAD COLOR CODE</span>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- FRAME COLOR, CODE GENERATE -->
                                    <div id="col_name_box" class="input-group frame-color-input <?php echo ($has_manual_code == 'yes') ? 'hidden' : ''; ?>">
                                        <label for="color_code_generate">Frame Color</label>
                                        <div class="input-done-wrap done-below">
                                            <input type="text" id="color_code_generate" name="color_name" value="<?php echo htmlspecialchars($display_color_name); ?>" placeholder="BLACK GOLD" style="text-transform: uppercase;">
                                        </div>
                                    </div>

                                    <!-- FRAME COLOR, MANUAL -->
                                    <div id="col_manual_box" class="input-group hidden frame-color-input <?php echo ($has_manual_code == 'no') ? 'hidden' : ''; ?>">
                                        <label for="color_code_manual">Frame Color</label>
                                        <div class="input-done-wrap done-below">
                                            <input type="text" id="color_code_manual" name="color_manual_code" value="<?php echo htmlspecialchars($current_data['color_code']); ?>" placeholder="C1" style="text-transform: uppercase;">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- MATERIAL -->
                            <div class="cs-group" id="csgroup_material">
                                <button type="button" class="cs-group-header" onclick="toggleCsGroup('csgroup_material')">
                                    <span class="cs-group-title">Material</span>
                                    <span class="cs-group-right">
                                        <span class="cs-group-current" id="cscurrent_material" style="color: #00ff88;"><?php echo htmlspecialchars($current_data['material']); ?></span>
                                        <span class="cs-group-arrow">▾</span>
                                    </span>
                                </button>
                                <div class="cs-group-body">
                                    <input type="hidden" name="material" id="material_input" value="<?php echo htmlspecialchars($current_data['material']); ?>">
                                    <div class="material-options">
                                        <?php
                                        $materials = loadJson('materials.json');
                                        foreach($materials as $m) {
                                            $isActive = ($m === $current_data['material']) ? 'active' : '';
                                            echo "<button type='button' class='material-opt $isActive' value='" . htmlspecialchars($m) . "' onclick=\"selectMaterialOption(this)\">" . htmlspecialchars($m) . "</button>";
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>

                            <!-- LENS SHAPE -->
                            <div class="cs-group" id="csgroup_lens_shape">
                                <button type="button" class="cs-group-header" onclick="toggleCsGroup('csgroup_lens_shape')">
                                    <span class="cs-group-title">Lens Shape</span>
                                    <span class="cs-group-right">
                                        <span class="cs-group-current" id="cscurrent_lens_shape" style="color: #00ff88;"><?php echo htmlspecialchars($current_data['lens_shape']); ?></span>
                                        <span class="cs-group-arrow">▾</span>
                                    </span>
                                </button>
                                <div class="cs-group-body">
                                    <input type="hidden" name="lens_shape" id="lens_shape_input" value="<?php echo htmlspecialchars($current_data['lens_shape']); ?>">
                                    <div class="lens-shape-options">
                                        <?php
                                        $shapes = loadJson('shapes.json');
                                        foreach($shapes as $s) {
                                            $isActive = ($s === $current_data['lens_shape']) ? 'active' : '';
                                            echo "<button type='button' class='lens-shape-opt $isActive' value='" . htmlspecialchars($s) . "' onclick=\"selectLensShapeOption(this)\">" . htmlspecialchars($s) . "</button>";
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>

                            <!-- FRAME STRUCTURE -->
                            <div class="cs-group" id="csgroup_structure">
                                <button type="button" class="cs-group-header" onclick="toggleCsGroup('csgroup_structure')">
                                    <span class="cs-group-title">Frame Structure</span>
                                    <span class="cs-group-right">
                                        <span class="cs-group-current" id="cscurrent_structure"><?php echo htmlspecialchars(strtoupper(str_replace('-', ' ', $current_data['structure']))); ?></span>
                                        <span class="cs-group-arrow">▾</span>
                                    </span>
                                </button>
                                <div class="cs-group-body">
                                <input type="hidden" name="structure" id="frame_structure_input" value="<?php echo htmlspecialchars($current_data['structure']); ?>">
                                <div class="card-select-wrapper" id="card_structure">
                                    <button type="button" class="card-opt <?php echo ($current_data['structure']=='full-rim')?'active':''; ?>" value="full-rim" onclick="selectCard(this,'frame_structure_input','card_structure')">
                                        <span class="card-icon">
                                            <img src="image/frame_data_entry/full_rim.png" alt="Kacamata" style="width: 72px; height: auto; vertical-align: middle;">
                                        </span>
                                        <span class="card-label">FULL RIM</span>
                                        <span class="card-sub">Full frame</span>
                                    </button>
                                    <button type="button" class="card-opt <?php echo ($current_data['structure']=='semi-rimless')?'active':''; ?>" value="semi-rimless" onclick="selectCard(this,'frame_structure_input','card_structure')">
                                        <span class="card-icon">
                                            <img src="image/frame_data_entry/semi_rimless.png" alt="Kacamata" style="width: 72px; height: auto; vertical-align: middle;">
                                        </span>
                                        <span class="card-label">SEMI RIMLESS</span>
                                        <span class="card-sub">Half frame</span>
                                    </button>
                                    <button type="button" class="card-opt <?php echo ($current_data['structure']=='rimless')?'active':''; ?>" value="rimless" onclick="selectCard(this,'frame_structure_input','card_structure')">
                                    <span class="card-icon">
                                        <img src="image/frame_data_entry/rimless.png" alt="Kacamata" style="width: 72px; height: auto; vertical-align: middle;">
                                    </span>
                                        <span class="card-label">RIMLESS</span>
                                        <span class="card-sub">No frame</span>
                                    </button>
                                </div>
                                </div>
                            </div>

                            <!-- FRAME SIZE RANGE -->
                            <div class="cs-group" id="csgroup_size_range">
                                <button type="button" class="cs-group-header" onclick="toggleCsGroup('csgroup_size_range')">
                                    <span class="cs-group-title">Size Range</span>
                                    <span class="cs-group-right">
                                        <span class="cs-group-current" id="cscurrent_size_range"><?php echo htmlspecialchars(strtoupper($current_data['size_range'])); ?></span>
                                        <span class="cs-group-arrow">▾</span>
                                    </span>
                                </button>
                                <div class="cs-group-body">
                                <input type="hidden" name="size_range" id="frame_size_range_input" value="<?php echo htmlspecialchars($current_data['size_range']); ?>">
                                <div class="card-select-wrapper" id="card_size_range">
                                    <button type="button" class="card-opt <?php echo ($current_data['size_range']=='small')?'active':''; ?>" value="small" onclick="selectCard(this,'frame_size_range_input','card_size_range')">
                                        <span class="badge size-s">S</span>
                                        <span class="card-label">SMALL</span>
                                        <span class="card-sub">Narrow fit</span>
                                    </button>
                                    <button type="button" class="card-opt <?php echo ($current_data['size_range']=='medium')?'active':''; ?>" value="medium" onclick="selectCard(this,'frame_size_range_input','card_size_range')">
                                        <span class="badge size-m">M</span>
                                        <span class="card-label">MEDIUM</span>
                                        <span class="card-sub">Standard fit</span>
                                    </button>
                                    <button type="button" class="card-opt <?php echo ($current_data['size_range']=='large')?'active':''; ?>" value="large" onclick="selectCard(this,'frame_size_range_input','card_size_range')">
                                        <span class="badge size-l">L</span>
                                        <span class="card-label">LARGE</span>
                                        <span class="card-sub">Wide fit</span>
                                    </button>
                                </div>
                                </div>
                            </div>

                            <!-- GENDER CATEGORY -->
                            <div class="cs-group" id="csgroup_gender">
                                <button type="button" class="cs-group-header" onclick="toggleCsGroup('csgroup_gender')">
                                    <span class="cs-group-title">Gender Category</span>
                                    <span class="cs-group-right">
                                        <span class="cs-group-current" id="cscurrent_gender"><?php echo htmlspecialchars(strtoupper($current_data['gender_category'])); ?></span>
                                        <span class="cs-group-arrow">▾</span>
                                    </span>
                                </button>
                                <div class="cs-group-body">
                                <input type="hidden" name="gender_category" id="gender_category_input" value="<?php echo htmlspecialchars($current_data['gender_category']); ?>">
                                <div class="card-select-wrapper" id="card_gender">
                                    <button type="button" class="card-opt <?php echo ($current_data['gender_category']=='men')?'active':''; ?>" value="men" onclick="selectCard(this,'gender_category_input','card_gender')">
                                        <span class="card-icon">♂️</span>
                                        <span class="card-label">MEN</span>
                                        <span class="card-sub">Masculine</span>
                                    </button>
                                    <button type="button" class="card-opt <?php echo ($current_data['gender_category']=='female')?'active':''; ?>" value="female" onclick="selectCard(this,'gender_category_input','card_gender')">
                                        <span class="card-icon">♀️</span>
                                        <span class="card-label">FEMALE</span>
                                        <span class="card-sub">Feminine</span>
                                    </button>
                                    <button type="button" class="card-opt <?php echo ($current_data['gender_category']=='unisex')?'active':''; ?>" value="unisex" onclick="selectCard(this,'gender_category_input','card_gender')">
                                        <span class="card-icon">⚧️</span>
                                        <span class="card-label">UNISEX</span>
                                        <span class="card-sub">For all</span>
                                    </button>
                                </div>
                                </div>
                            </div>

                            <!-- TOTAL FRAME -->
                            <div class="input-group">
                                <label for="total_frame">Total Frame (Stock)</label>
                                <div class="input-done-wrap">
                                    <input type="number" id="total_frame" name="total_frame" value="<?php echo htmlspecialchars($current_data['stock']); ?>" min="0" required autocomplete="off">
                                </div>
                            </div>

                            <!-- STOCK AGE -->
                            <div class="cs-group" id="csgroup_stock_age">
                                <button type="button" class="cs-group-header" onclick="toggleCsGroup('csgroup_stock_age')">
                                    <span class="cs-group-title">Stock Age</span>
                                    <span class="cs-group-right">
                                        <span class="cs-group-current" id="cscurrent_stock_age"><?php echo htmlspecialchars(strtoupper($current_data['stock_age'])); ?></span>
                                        <span class="cs-group-arrow">▾</span>
                                    </span>
                                </button>
                                <div class="cs-group-body">
                                <input type="hidden" name="stock_age" id="stock_age_input" value="<?php echo htmlspecialchars($current_data['stock_age']); ?>">
                                <div class="card-select-wrapper" id="card_stock_age">
                                    <button type="button" class="card-opt <?php echo ($current_data['stock_age']=='very old')?'active':''; ?>" value="very old" onclick="selectCard(this,'stock_age_input','card_stock_age')">
                                        <span class="card-icon">🦕</span>
                                        <span class="card-label">VERY OLD</span>
                                        <span class="card-sub">Long stocked</span>
                                    </button>
                                    <button type="button" class="card-opt <?php echo ($current_data['stock_age']=='old')?'active':''; ?>" value="old" onclick="selectCard(this,'stock_age_input','card_stock_age')">
                                        <span class="card-icon">📦</span>
                                        <span class="card-label">OLD</span>
                                        <span class="card-sub">In shelf a while</span>
                                    </button>
                                    <button type="button" class="card-opt <?php echo ($current_data['stock_age']=='new')?'active':''; ?>" value="new" onclick="selectCard(this,'stock_age_input','card_stock_age')">
                                        <span class="card-icon">✨</span>
                                        <span class="card-label">NEW</span>
                                        <span class="card-sub">Fresh stock</span>
                                    </button>
                                </div>
                                </div>
                            </div>

                            <!-- COST PRICE -->
                            <?php if ($role === 'admin'): ?>
                                <div class="input-group">
                                    <label for="buy_price">Cost Price (IDR)</label>
                                    <div class="input-done-wrap">
                                        <input type="password" id="buy_price" name="buy_price" value="<?php echo htmlspecialchars($current_data['buy_price']); ?>" oninput="calculatePrice()" inputmode="numeric" autocomplete="off">
                                    </div>
                                </div>
                                <div class="submit-main" id="sell_display">Selling Price: IDR <?php echo number_format($current_data['sell_price'], 0, ',', '.'); ?></div>
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
                                            <div class="ufc-value" id="prev-ufc"><?php echo htmlspecialchars($old_ufc); ?></div>
                                        </div>

                                        <!-- Detail Grid -->
                                        <div class="preview-grid">
                                            <div class="preview-item" id="pi-brand">
                                                <span class="pi-label">Brand</span>
                                                <span class="pi-value" id="prev-brand"><?php echo htmlspecialchars($current_data['brand']); ?></span>
                                            </div>
                                            <div class="preview-item" id="pi-code">
                                                <span class="pi-label">Frame Code</span>
                                                <span class="pi-value" id="prev-code"><?php echo htmlspecialchars($current_data['frame_code']); ?></span>
                                            </div>
                                            <div class="preview-item" id="pi-size">
                                                <span class="pi-label">Frame Size</span>
                                                <span class="pi-value" id="prev-size"><?php echo htmlspecialchars($current_data['frame_size']); ?></span>
                                            </div>
                                            <div class="preview-item" id="pi-color">
                                                <span class="pi-label">Color</span>
                                                <span class="pi-value" id="prev-color"><?php echo htmlspecialchars($current_data['color_code']); ?></span>
                                            </div>
                                            <div class="preview-item" id="pi-material">
                                                <span class="pi-label">Material</span>
                                                <span class="pi-value" id="prev-material"><?php echo htmlspecialchars($current_data['material']); ?></span>
                                            </div>
                                            <div class="preview-item" id="pi-shape">
                                                <span class="pi-label">Lens Shape</span>
                                                <span class="pi-value" id="prev-shape"><?php echo htmlspecialchars($current_data['lens_shape']); ?></span>
                                            </div>
                                            <div class="preview-item" id="pi-structure">
                                                <span class="pi-label">Structure</span>
                                                <span class="pi-value" id="prev-structure"><?php echo htmlspecialchars(strtoupper($current_data['structure'])); ?></span>
                                            </div>
                                            <div class="preview-item" id="pi-sizerange">
                                                <span class="pi-label">Size Range</span>
                                                <span class="pi-value" id="prev-sizerange"><?php echo htmlspecialchars(strtoupper($current_data['size_range'])); ?></span>
                                            </div>
                                            <div class="preview-item" id="pi-gender">
                                                <span class="pi-label">Gender</span>
                                                <span class="pi-value" id="prev-gender"><?php echo htmlspecialchars(strtoupper($current_data['gender_category'])); ?></span>
                                            </div>
                                            <div class="preview-item" id="pi-stock">
                                                <span class="pi-label">Stock (Qty)</span>
                                                <span class="pi-value" id="prev-stock"><?php echo htmlspecialchars($current_data['stock']); ?></span>
                                            </div>
                                            <div class="preview-item" id="pi-stockage">
                                                <span class="pi-label">Stock Age</span>
                                                <span class="pi-value" id="prev-stockage"><?php echo htmlspecialchars(strtoupper($current_data['stock_age'])); ?></span>
                                            </div>
                                            <?php if ($role === 'admin'): ?>
                                            <div class="preview-item" id="pi-price">
                                                <span class="pi-label">Sell Price (Est.)</span>
                                                <span class="pi-value" id="prev-price">IDR <?php echo number_format($current_data['sell_price'], 0, ',', '.'); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div><!-- /#preview-body -->
                            </div><!-- /#preview-wrapper -->

                            <!-- SUBMIT -->
                            <div class="btn-group">
                                <button type="button" onclick="confirmUpdate()" class="submit-main">UPDATE DATA</button>
                            </div>

                        </div>
                    </form>
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

                    // Round up to the nearest multiple of 5,000 (matching PHP logic)
                    sell = Math.ceil(sell / 5000) * 5000;
                }

                document.getElementById('sell_display').innerText = "Selling Price: IDR " + sell.toLocaleString('id-ID');
                updatePreview();
            }

            // ── Select Material Option ──
            function selectMaterialOption(el) {
                const value = el.value;
                const materialInput = document.getElementById('material_input');
                const currentDisplay = document.getElementById('cscurrent_material');

                // Remove active class from all options
                document.querySelectorAll('.material-opt').forEach(btn => btn.classList.remove('active'));

                // Add active class to clicked option
                el.classList.add('active');

                // Update hidden input and display
                if (materialInput) materialInput.value = value;
                if (currentDisplay) currentDisplay.textContent = value;

                // Trigger preview update
                updatePreview();
            }

            // ── Select Lens Shape Option ──
            function selectLensShapeOption(el) {
                const value = el.value;
                const lensShapeInput = document.getElementById('lens_shape_input');
                const currentDisplay = document.getElementById('cscurrent_lens_shape');

                // Remove active class from all options
                document.querySelectorAll('.lens-shape-opt').forEach(btn => btn.classList.remove('active'));

                // Add active class to clicked option
                el.classList.add('active');

                // Update hidden input and display
                if (lensShapeInput) lensShapeInput.value = value;
                if (currentDisplay) currentDisplay.textContent = value;

                // Trigger preview update
                updatePreview();
            }

            // ── Card Selection Function (for card-opt groups) ──
            function selectCard(el, hiddenInputId, wrapperId, triggerChain = true) {
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

                // Update collapsible group "current value" badge (if applicable)
                const csCurrentMap = {
                    'card_structure':  'cscurrent_structure',
                    'card_size_range': 'cscurrent_size_range',
                    'card_gender':     'cscurrent_gender',
                    'card_stock_age':  'cscurrent_stock_age'
                };
                const csCurrentId = csCurrentMap[wrapperId];
                if (csCurrentId) {
                    const csCurrentEl = document.getElementById(csCurrentId);
                    if (csCurrentEl) {
                        const labelEl = el.querySelector('.card-label');
                        csCurrentEl.textContent = labelEl ? labelEl.textContent.trim() : el.value;
                    }
                }

                // Trigger preview update
                updatePreview();
            }

            // ── CARD GROUP COLLAPSE/EXPAND (Frame Color, Material, Lens Shape, Structure, Size Range, Gender, Stock Age) ──
            // Only one card group can be open at a time: opening a group
            // always closes every other group first.
            function toggleCsGroup(groupId) {
                const group = document.getElementById(groupId);
                if (!group) return;
                const wasExpanded = group.classList.contains('expanded');
                document.querySelectorAll('.cs-group.expanded').forEach(g => g.classList.remove('expanded'));
                if (!wasExpanded) group.classList.add('expanded');
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

                    updateFrameColorCurrent();
                    updatePreview();
                }
            }

            // Update the green "current value" badge on the collapsed Frame Color card header
            function updateFrameColorCurrent() {
                const hasCode = document.getElementById('has_color_code_input')?.value || 'no';
                const activeEl = hasCode === 'yes'
                    ? document.getElementById('color_code_manual')
                    : document.getElementById('color_code_generate');
                const val = (activeEl?.value || '').trim();
                const currentEl = document.getElementById('cscurrent_frame_color');
                if (currentEl) currentEl.textContent = val ? val.toUpperCase() : '—';
            }

            // 2. Execution on Page Load
            document.addEventListener('DOMContentLoaded', function() {
                updateFrameColorCurrent();
                updatePreview();
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
                const material  = document.getElementById('material_input')?.value || '';
                const shape     = document.getElementById('lens_shape_input')?.value || '';
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

                ['color_code_generate','color_code_manual'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.addEventListener('input', updateFrameColorCurrent);
                });

                ['frame_structure_input','frame_size_range_input','gender_category_input','stock_age_input','has_color_code_input'].forEach(id => {
                    const el = document.getElementById(id);
                    if (!el) return;
                    new MutationObserver(updatePreview).observe(el, { attributes: true });
                    el.addEventListener('change', updatePreview);
                });
            });

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
                        // back direction
                        window.location.href = 'pending_records_frame.php';
                    }
                }
                requestAnimationFrame(step);
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