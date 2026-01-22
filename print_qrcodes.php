<?php
    include 'db_config.php';
    include 'config_helper.php';

    session_start(); 

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['selected_ufc'])) {
        $_SESSION['print_ufc_list'] = $_POST['selected_ufc'];
    }

    // Helper to determine QR Code location
    function getQRCodePath($ufc) {
        $staging_path = "qrcodes/" . $ufc . ".png";
        $main_path = "main_qrcodes/" . $ufc . ".png";

        // Prioritize checking in main_qrcodes according to your instructions
        if (file_exists($main_path)) {
            return $main_path;
        } 
        // If not found in main, use staging
        return $staging_path;
    }

    $selected_ufcs = isset($_SESSION['print_ufc_list']) ? $_SESSION['print_ufc_list'] : [];
    $start_row = isset($_GET['start_row']) ? (int)$_GET['start_row'] : 1;
    $max_rows = 17;
    $cols = 7;

    // NEW FEATURE: Check if start row is restricted (more than 12)
    $is_restricted = ($start_row > 12);

    if (!empty($selected_ufcs)) {
        $placeholders = implode(',', array_fill(0, count($selected_ufcs), '?'));
        $stmt = $conn->prepare("SELECT ufc, brand, price_secret_code, stock_age FROM frame_staging WHERE ufc IN ($placeholders)");
        $stmt->bind_param(str_repeat('s', count($selected_ufcs)), ...$selected_ufcs);
        $stmt->execute();
        $result = $stmt->get_result();

        $all_data = [];
        while ($row = $result->fetch_assoc()) { $all_data[] = $row; }
        $all_data = array_reverse($all_data);

        // Global Indicator Logic
        // 1. Calculate Rows on Page 1
        $total_label = count($all_data);
        $rows_used_global = [];
        $available_rows_pg1 = ($max_rows - $start_row) + 1;
        $labels_pg1_count = min($total_label, $available_rows_pg1 * $cols);
        $rows_pg1_count = ceil($labels_pg1_count / $cols);
        for($i = $start_row; $i < ($start_row + $rows_pg1_count); $i++) { 
            $rows_used_global[] = $i;
        }
        // 2. Calculate Rows on Page 2 (If there is remaining data)
        if ($total_label > ($available_rows_pg1 * $cols)) {
            $remaining_labels = $total_label - ($available_rows_pg1 * $cols);
            $rows_pg2_count = ceil($remaining_labels / $cols);
            
            for($i = 1; $i <= $rows_pg2_count; $i++) {
                // Use array_unique logic: if the row already exists (due to overlapping logic), avoid duplicates
                if (!in_array($i, $rows_used_global)) {
                    $rows_used_global[] = $i;
                }
            }
        }
        
        // Data Splitting
        $capacity_pg1 = $available_rows_pg1 * $cols;
        $page1_data = array_slice($all_data, 0, $capacity_pg1);
        $page2_data = array_slice($all_data, $capacity_pg1);
        $empty_slots_pg1 = array_fill(0, ($start_row - 1) * $cols, null);
        $render_pg1 = array_merge($page1_data, $empty_slots_pg1);
        $render_pg2 = array_reverse($page2_data); 
    } else {
        die("No data selected.");
    }
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Label Print - Dark Neumorphism</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* CSS REMAINS THE SAME AS YOUR ORIGINAL CODE */
        :root {
            --bg-color: #1e2124;
            --neu-shadow-dark: #141618;
            --neu-shadow-light: #282c30;
            --accent-color: #0984e3;
            --text-main: #e0e0e0;
            --text-dim: #a0a0a0;
        }

        /* Additional styles for disabled buttons */
        .btn-disabled {
            opacity: 0.4;
            cursor: not-allowed !important;
            filter: grayscale(1);
            box-shadow: inset 2px 2px 5px var(--neu-shadow-dark) !important;
        }
        
        .warning-text {
            color: #ff4757;
            font-size: 0.8rem;
            margin-top: 10px;
            display: <?php echo $is_restricted ? 'block' : 'none'; ?>;
        }

        @page { size: A4 portrait; margin: 0; }
        body { font-family: 'Segoe UI', sans-serif; margin: 0; padding: 0; background-color: var(--bg-color); color: var(--text-main); -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .no-print { 
            padding: 20px; 
            background: var(--bg-color); 
            box-shadow: 10px 10px 20px var(--neu-shadow-dark), -10px -10px 20px var(--neu-shadow-light); 
            margin-bottom: 30px; 
            text-align: center;
            border-radius: 30px;
        }
        .header-container { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding: 0 50px; }
        .brand-section { text-align: center; flex-grow: 1; }
        .company-name { margin: 5px 0 0 0; font-size: 1.5rem; letter-spacing: 1px; }
        .company-address { margin: 0; font-size: 0.8rem; color: var(--text-dim); }
        .logout-btn { background: var(--bg-color); border: none; padding: 10px 20px; border-radius: 10px; color: var(--text-main); box-shadow: 5px 5px 10px var(--neu-shadow-dark), -5px -5px 10px var(--neu-shadow-light); cursor: pointer; transition: 0.3s; display: flex; align-items: center; font-weight: bold; }
        .control-panel { display: flex; justify-content: center; align-items: center; gap: 20px; margin-top: 20px; flex-direction: column; }
        .neu-input { background: var(--bg-color); border: none; padding: 8px 15px; border-radius: 8px; color: var(--accent-color); box-shadow: inset 3px 3px 6px var(--neu-shadow-dark), inset -3px -3px 6px var(--neu-shadow-light); width: 50px; text-align: center; font-weight: bold; }
        .neu-btn { background: var(--bg-color); border: none; padding: 10px 25px; border-radius: 10px; color: var(--text-main); box-shadow: 5px 5px 10px var(--neu-shadow-dark), -5px -5px 10px var(--neu-shadow-light); cursor: pointer; font-weight: bold; }
        .neu-btn-print { color: #2ecc71; }
        .checklist-container { display: flex; justify-content: center; gap: 8px; margin-top: 20px; padding: 10px; }
        .check-item { width: 25px; height: 25px; display: flex; align-items: center; justify-content: center; border-radius: 6px; font-size: 0.7rem; box-shadow: 3px 3px 6px var(--neu-shadow-dark), -3px -3px 6px var(--neu-shadow-light); color: var(--text-dim); }
        .check-item.active { color: var(--accent-color); box-shadow: inset 2px 2px 5px var(--neu-shadow-dark), inset -2px -2px 5px var(--neu-shadow-light); font-weight: bold; }
        .page-break { height: 297mm; display: flex; flex-direction: column; justify-content: flex-end; page-break-after: always; background: #fff; }
        .wrapper { position: relative; width: fit-content; margin: 0 auto; display: flex; align-items: flex-end; padding-bottom: 7mm; background: #fff; }
        .row-numbers { display: grid; grid-template-rows: repeat(<?php echo $max_rows; ?>, 16.5mm); margin-right: 4mm; }
        .row-num { display: flex; align-items: center; justify-content: center; font-size: 10pt; font-weight: bold; width: 8mm; border-right: 0.2mm solid #000; padding-right: 2mm; color: #000; }
        .print-container { display: grid; grid-template-columns: repeat(7, 25mm); grid-auto-rows: 15mm; row-gap: 1.5mm; column-gap: 1.5mm; }
        .label-box { 
            width: 25mm; 
            height: 15mm; 
            border: 1pt solid #000 !important; 
            position: relative; 
            display: flex; 
            flex-direction: row; 
            align-items: center; 
            justify-content: center; 
            padding: 1mm 0.5mm; 
            box-sizing: border-box; 
            background: #fff; 
            overflow: hidden; 
        }
        .empty-slot { border: none !important; }
        .brand-header { 
            font-size: 4pt; 
            font-weight: bold; 
            text-transform: uppercase; 
            color: #000; 
            /* margin-bottom: 0.2mm;  */
            text-align: left; 
            width: 100%; 
            white-space: nowrap;
            overflow: hidden;
        }
        .age-indicator {
            /* position: absolute; 
            top: 0.8mm; 
            right: 0.8mm;  */
            width: 3.5mm; 
            height: 3.5mm; 
            border-radius: 50%; 
            border: 0.15mm solid #000;
            margin-bottom: 0.5mm;
        }
        .bg-red { background-color: #ff4757 !important; }
        .bg-yellow { background-color: #ffa502 !important; }
        .bg-green { background-color: #2ed573 !important; }
        .qr-img { 
            height: 12mm; 
            width: 12mm;
            flex-shrink: 0;
        }
        .box-shifted { transform: translateY(-7.3mm); }
        .secret-code { 
            font-size: 8pt; 
            font-weight: bold; 
            color: #ff0000 !important; 
            /* margin-top: 0.3mm;  */
            line-height: 1;
            margin-bottom: 0.3mm;
        }
        .label-details {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
            padding-left: 1.5mm;
            flex-grow: 1;
            overflow: hidden;
        }
        .main-wrapper {
            background-color: var(--bg-color);
            padding: 30px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center; /* Ensures content is centered */
        }
        .neumorphic-card {
            background: var(--bg-color);
            border-radius: 30px;
            padding: 40px;
            box-shadow: 20px 20px 60px var(--neu-shadow-dark), 
                    -20px -20px 60px var(--neu-shadow-light);
            width: fit-content; /* Follows the width of the label content */
            margin: 20px auto;
        }
        @media print {
            .main-wrapper { padding: 0; background: #fff; }
            .neumorphic-card { 
                box-shadow: none; 
                padding: 0; 
                margin: 0; 
                border-radius: 0;
            }
            .no-print { display: none; }
            body { background: #fff; }
        }
    </style>
</head>
<body>
    <div class="main-wrapper">
        <div class="no-print">
                <div class="header-container" style="
                margin-left: auto; 
                margin-right: auto; 
                width: 100%;">        
                    <div class="brand-section">
                        <div class="logo-box">
                            <img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" style="height: 40px;">
                        </div>
                        <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                        <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
                    </div>
                </div>
    
            <div class="control-panel">
                <div style="display: flex; gap: 20px; align-items: center;">
                    <form method="GET" style="display: flex; gap: 10px; align-items: center;">
                        <span>Start Row:</span>
                        <input type="number" name="start_row" style="width: auto" class="neu-input" value="<?php echo $start_row; ?>" min="1" max="17">
                        <button type="submit" class="neu-btn">Set Position</button>
                    </form>
    
                    <button onclick="confirmPrint()" 
                            class="neu-btn neu-btn-print <?php echo ($is_restricted) ? 'btn-disabled' : ''; ?>" 
                            <?php echo ($is_restricted) ? 'disabled' : ''; ?>>
                        Print Labels
                    </button>
                </div>
                
                <?php if ($is_restricted): ?>
                    <div class="warning-text">⚠️ Maximum start row is 12. Please change the position.</div>
                <?php endif; ?>
            </div>
    
            <div class="checklist-container">
                <?php 
                for ($r = 1; $r <= $max_rows; $r++) {
                    $isActive = in_array($r, $rows_used_global);
                    echo "<div class='check-item ".($isActive ? 'active' : '')."'>".($isActive ? "✓" : $r)."</div>";
                }
                ?>
            </div>
            
    
            <div class="btn-group">
                <button type="button" class="back-main" onclick="window.location.href='pending_records_frame.php'">BACK TO PREVIOUS PAGE</button>
            </div>
        </div>

        <div class="neumorphic-card">
            <div class="page-break">
                <div class="wrapper">
                    <div class="row-numbers" style="visibility: <?php echo ($start_row === 1) ? 'visible' : 'hidden'; ?>;">
                        <?php for ($i = $max_rows; $i >= 1; $i--): ?><div class="row-num"><?php echo $i; ?></div><?php endfor; ?>
                    </div>
                    <div class="print-container">
                    <?php foreach ($render_pg1 as $item): ?>
                        <div class="label-box <?php 
                            echo $item === null ? 'empty-slot' : ''; 
                            echo ($start_row > 1 && $item !== null) ? ' box-shifted' : ''; 
                        ?>">
                            <?php if ($item): ?>
                                <img src="<?php echo getQRCodePath($item['ufc']); ?>" class="qr-img">

                                <div class="label-details">
                                    <div class="age-indicator <?php echo ($item['stock_age'] === 'very old' ? 'bg-red' : ($item['stock_age'] === 'old' ? 'bg-yellow' : 'bg-green')); ?>"></div>
                                    <span class="secret-code"><?php echo htmlspecialchars($item['price_secret_code']); ?></span>
                                    <span class="brand-header"><?php echo htmlspecialchars($item['brand']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
        
            <?php if (!empty($page2_data)): ?>
                <div class="page-break">
                    <div class="wrapper">
                        <div class="row-numbers" style="visibility: visible;">
                            <?php for ($i = $max_rows; $i >= 1; $i--): ?><div class="row-num"><?php echo $i; ?></div><?php endfor; ?>
                        </div>
                        <div class="print-container">
                            <?php 
                            $pg2_empty_slots = ($max_rows * $cols) - count($page2_data);
                            for ($i = 0; $i < $pg2_empty_slots; $i++) echo '<div class="label-box" style="border:none !important;"></div>';
                            foreach ($render_pg2 as $item): ?>
                                <div class="label-box">
                                    <img src="<?php echo getQRCodePath($item['ufc']); ?>" class="qr-img">

                                    <div class="label-details">
                                        <div class="age-indicator <?php echo ($item['stock_age'] === 'very old' ? 'bg-red' : ($item['stock_age'] === 'old' ? 'bg-yellow' : 'bg-green')); ?>"></div>
                                        <span class="secret-code"><?php echo htmlspecialchars($item['price_secret_code']); ?></span>
                                        <span class="brand-header"><?php echo htmlspecialchars($item['brand']); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>    
    </div>

    <script>
        function confirmPrint() {
            // Additional protection on the client side
            const startRow = parseInt(document.getElementsByName('start_row')[0].value);
            if (startRow > 12) {
                alert("Sorry, printing is not allowed if the start row is greater than 12.");
                return;
            }
            
            const isMultiPage = <?php echo !empty($page2_data) ? 'true' : 'false'; ?>;
            if (isMultiPage) {
                if (confirm("⚠️ Data overflows to the second page! Prepare 2 label sheets. Continue?")) {
                    window.print();
                }
            } else {
                window.print();
            }
        }
    </script>
</body>
</html>