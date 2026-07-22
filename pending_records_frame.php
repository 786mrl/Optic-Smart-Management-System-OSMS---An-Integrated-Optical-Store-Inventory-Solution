<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';
    include 'auth_check.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }
    $role = $_SESSION['role'] ?? 'staff';

    // Action Process (Delete only for Admin)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && $role === 'admin') {
        $target_ufc = basename($_POST['ufc']);

        // 1. Get the QR Code filename based on UFC
        // Filename: ufc.png (e.g., LENZA-xxx.png)
        $qrCodePath = "qrcodes/" . $target_ufc . ".png";

        // 2. Delete the image file from the folder if it exists
        if (!empty($target_ufc) && file_exists($qrCodePath)) {
            unlink($qrCodePath);
        }

        // 3. Delete record from the database
        $ufc_to_reject = $_POST['ufc'] ?? '';
        $stmt = $conn->prepare("DELETE FROM frame_staging WHERE ufc = ?");
        $stmt->bind_param("s", $target_ufc);
        
        if ($stmt->execute()) {
            $_SESSION['success_msg'] = "Record $target_ufc has been deleted.";
            header("Location: pending_records_frame.php");
            exit();
        }
    }
?>

<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Pending Records</title>
        <link rel="stylesheet" href="style.css">    
        <style>
            /* Main container must have the same color as buttons for the neumorphic effect to appear */
            :root {
                --bg-dark: #23272a;
                --shadow-light: #2d3236; /* Slightly lighter than BG */
                --shadow-dark: #191c1e;  /* Slightly darker than BG */
                --accent-gold: #f1c40f;
                --accent-red: #e74c3c;
                --accent-green: #81C784;
            }

            .action-btn-container { 
                display: flex; 
                gap: 15px; 
                justify-content: center;
            }

            /* Neumorphism Base Button Style */
            .btn-table { 
                padding: 10px 20px; 
                border: none; 
                border-radius: 12px; /* Large radius characteristic of neumorphism */
                cursor: pointer; 
                font-weight: bold; 
                font-size: 11px;
                background: var(--bg-dark);
                color: #fff;
                transition: all 0.2s ease;
                
                /* Embossed Effect (Extruded) */
                box-shadow: 5px 5px 10px var(--shadow-dark), 
                        -5px -5px 10px var(--shadow-light);
                outline: none;
            }

            /* Pressed effect (Appears sunken/inset) */
            .btn-table:active {
                box-shadow: inset 5px 5px 10px var(--shadow-dark), 
                            inset -5px -5px 10px var(--shadow-light);
                transform: scale(0.98);
            }

            /* Color variations and thin borders for accent */
            .btn-set-price { 
                color: var(--accent-gold);
                border: 1px solid rgba(241, 196, 15, 0.1);
            }

            .btn-set-price:hover {
                text-shadow: 0 0 8px rgba(241, 196, 15, 0.5);
            }

            .btn-delete-row { 
                color: var(--accent-red);
                border: 1px solid rgba(231, 76, 60, 0.1);
            }

            .btn-delete-row:hover {
                text-shadow: 0 0 8px rgba(231, 76, 60, 0.5);
            }

            /* Table row styles for consistency */
            .table-wrapper table tr {
                background: transparent;
            }
            
            .table-wrapper td {
                border-bottom: 1px solid #2d3236;
                padding: 15px 10px;
                font-size: 12px;
                text-align: center;
            }

            th {
                text-align: center;
            }
            #emptyMessage { display: none; text-align: center; padding: 40px; }
            input[type="checkbox"] {
                cursor: pointer;
                width: 18px;
                height: 18px;
                accent-color: var(--accent-green);
            }
            
            .neumorph-alert {
                border-radius: 20px !important;
                box-shadow: 10px 10px 20px #1a1d20, -10px -10px 20px #2c3134 !important;
            }
            /* Collapsible Card Styles */
            .card-toggle-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                cursor: pointer;
                user-select: none;
                margin-bottom: 25px;
                transition: margin-bottom 0.25s ease, padding 0.25s ease;
            }
            .card-toggle-header.is-collapsed {
                margin-bottom: 0;
                padding-top: 4px;
                padding-bottom: 4px;
            }
            /* Smaller title text while the card is collapsed */
            .card-toggle-header.is-collapsed h2 {
                font-size: 14px !important;
                transition: font-size 0.25s ease;
            }
            .card-toggle-header h2 {
                margin-bottom: 0 !important;
            }
            .card-toggle-icon {
                font-size: 22px;
                color: var(--accent-green);
                transition: transform 0.25s ease, font-size 0.25s ease;
                margin-left: 15px;
                flex-shrink: 0;
            }
            .card-toggle-header.is-collapsed .card-toggle-icon {
                transform: rotate(-90deg);
            }
            .card-collapsible-body {
                overflow: hidden;
                max-height: 5000px;
                opacity: 1;
                transition: max-height 0.3s ease, opacity 0.25s ease, margin-top 0.25s ease;
            }
            .card-collapsible-body.is-collapsed {
                max-height: 0;
                opacity: 0;
                pointer-events: none;
            }
            /* Shrink the wrapper's own padding while collapsed, on top of the header/body already collapsing */
            .table-responsive_approve_user {
                transition: padding 0.25s ease;
            }
            .table-responsive_approve_user.section-is-collapsed {
                padding-top: 7.5px !important;
                padding-bottom: 7.5px !important;
            }

            /* Widen the cards so they use the available horizontal space
               instead of leaving empty margins on the sides (overrides the
               narrower max-width coming from style.css for these 3 cards only) */
            .main-card {
                max-width: 1400px !important;
                width: 96% !important;
            }

            /* .glass-window is the inner wrapper defined in style.css and was
               still capping the width even after .main-card was widened above.
               Force it to fill its parent so the table can actually expand. */
            .main-card > .glass-window {
                max-width: none !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }

            /* Reduce the large left/right outer gap around the cards
               (these paddings come from style.css on .content-area /
               .main-wrapper) so the cards sit closer to the screen edges. */
            .main-wrapper {
                padding-left: 12px !important;
                padding-right: 12px !important;
            }
            .content-area {
                padding-left: 12px !important;
                padding-right: 12px !important;
            }

            /* Reduce the large left/right inner gap around the card content
               (padding comes from .glass-window in style.css) so the table
               and text use more of the card's width. */
            .main-card > .glass-window {
                padding-left: 12px !important;
                padding-right: 12px !important;
            }

            /* ---- Nicer table look ---- */
            .table-wrapper {
                border-radius: 14px;
                overflow: hidden;
                background: var(--bg-dark);
                box-shadow: inset 2px 2px 6px var(--shadow-dark),
                            inset -2px -2px 6px var(--shadow-light);
            }

            .table-wrapper table {
                border-collapse: separate;
                border-spacing: 0;
            }

            .table-wrapper thead th {
                background: #1d2023;
                color: #9aa0a6;
                font-size: 11px;
                letter-spacing: 0.5px;
                padding: 14px 10px;
                border-bottom: 2px solid #33383c;
                position: sticky;
                top: 0;
            }

            .table-wrapper tbody tr {
                transition: background 0.15s ease;
            }

            .table-wrapper tbody tr:nth-child(even) {
                background: rgba(255, 255, 255, 0.02);
            }

            .table-wrapper tbody tr:hover {
                background: rgba(129, 199, 132, 0.06);
            }

            .table-wrapper td {
                border-bottom: 1px solid #2d3236;
                vertical-align: middle;
            }

            .table-wrapper tbody tr:last-child td {
                border-bottom: none;
            }

            /* ---- Fullscreen "fly window" overlay for viewing a table at full width ---- */
            .expand-table-btn {
                background: transparent;
                border: 1px solid rgba(129, 199, 132, 0.4);
                color: var(--accent-green);
                border-radius: 8px;
                padding: 8px 16px;
                font-size: 13px;
                font-weight: bold;
                cursor: pointer;
                margin-left: 12px;
                transition: all 0.2s ease;
                flex-shrink: 0;
            }
            .expand-table-btn:hover {
                background: rgba(129, 199, 132, 0.1);
                color: #fff;
            }

            .table-flyout-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.75);
                z-index: 9999;
                padding: 30px;
                box-sizing: border-box;
                overflow: auto;
            }
            .table-flyout-overlay.is-open {
                display: block;
            }
            .table-flyout-panel {
                background: var(--bg-dark);
                border-radius: 16px;
                max-width: 1600px;
                width: 100%;
                margin: 0 auto;
                padding: 25px;
                box-sizing: border-box;
                box-shadow: 10px 10px 25px var(--shadow-dark), -10px -10px 25px var(--shadow-light);
            }
            .table-flyout-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 20px;
            }
            .table-flyout-header h2 {
                margin: 0;
                font-size: 18px;
            }
            .table-flyout-close {
                background: var(--bg-dark);
                border: none;
                color: #fff;
                width: 36px;
                height: 36px;
                border-radius: 10px;
                font-size: 16px;
                cursor: pointer;
                box-shadow: 5px 5px 10px var(--shadow-dark), -5px -5px 10px var(--shadow-light);
            }
            .table-flyout-close:active {
                box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 6px var(--shadow-light);
            }
            /* Inside the flyout, the table is free to use the full panel width */
            .table-flyout-panel .table-wrapper table {
                table-layout: auto !important;
                width: 100% !important;
            }

            /* Responsif Mobile Fix */
            /* Center the header block (logout button + logo/name/address group)
               on PC to match how it already appears centered on mobile.
               Only the container's own horizontal position is changed here —
               the internal layout (logout pinned at the top-right corner of
               the logo group) is left exactly as in the original code. */
            .header-container {
                margin-left: auto !important;
                margin-right: auto !important;
                width: fit-content !important;
            }

            @media (max-width: 600px) {
                .main-card {
                    width: 100% !important;
                    margin-left: 0 !important;
                    margin-right: 0 !important;
                }
                /* These outer wrappers come from style.css and were adding side
                   padding/margin that left visible empty space on phone screens. */
                .content-area {
                    padding-left: 8px !important;
                    padding-right: 8px !important;
                }
                .main-wrapper {
                    padding-left: 0 !important;
                    padding-right: 0 !important;
                }
                .table-wrapper {
                    overflow-x: auto;
                    border-radius: 10px;
                }
                table {
                    table-layout: auto !important;
                    min-width: 600px;
                }
                .table-wrapper td[data-label="SELECT"], 
                .table-wrapper td[data-label="NO"] {
                    text-align: right !important;
                }
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
        </style>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    </head>

    <body>

        <div class="main-wrapper">
            <div class="content-area" style="flex-direction: column">
                <div class="header-container">
                    <button type="button" class="logout-btn neu-pill-btn logout-variant" id="logoutBtn" onclick="handleLogoutClick(this)">
                        <span class="neu-pill-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                <polyline points="16 17 21 12 16 7"></polyline>
                                <line x1="21" y1="12" x2="9" y2="12"></line>
                            </svg>
                        </span>
                        <span class="neu-pill-text">
                            <span class="line1">LOGOUT</span>
                        </span>
                    </button>
                
                    <div class="brand-section">
                        <div class="logo-box">
                            <img id="storeLogo" src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" style="height: 40px;" onclick="zoomInLogo(this)" ondblclick="zoomOutLogo(this)">
                        </div>
                        <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                        <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
                    </div>
                </div>
                
                <!-- PRICE ENTRY QUEUE -->
                <div class="main-card" style="margin-left: auto; margin-right: auto;">                    
                    <div class="glass-window">
                        <?php
                            $query = "SELECT ufc, brand, stock, created_by 
                                    FROM frame_staging 
                                    WHERE buy_price = 0 
                                    AND sell_price = 0 
                                    AND (price_secret_code = '' OR price_secret_code IS NULL)";
                            $result = $conn->query($query);
                            $hasData = ($result && $result->num_rows > 0);
                        ?>

                        <!-- Card always renders (header + toggle stay visible); only the body content switches between table and empty state -->
                        <div id="admin-display-section" class="table-responsive_approve_user">
                            <div class="card-toggle-header is-collapsed" onclick="toggleCard(this, 'pendingRequestBody')">
                                <h2 style="font-size: 18px;">FRAME ENTRY QUEUE</h2>
                                <div style="display:flex; align-items:center;">
                                    <button type="button" class="expand-table-btn" onclick="event.stopPropagation(); openTableFlyout('pendingRequestBody', 'FRAME ENTRY QUEUE');">⛶ EXPAND</button>
                                    <span class="card-toggle-icon">▼</span>
                                </div>
                            </div>
                            <div id="pendingRequestBody" class="card-collapsible-body is-collapsed">
                            <?php if ($hasData): ?>
                                <?php if ($role === 'admin'): ?>
                                <p style="font-size: 13px; color: #ccc; margin-bottom: 20px;">
                                    List of frames recently entered by staff that <strong>require price assignment</strong> by an Admin.
                                </p>
                                <?php endif; ?>
                                <?php if ($role === 'staff'): ?>
                                <p style="font-size: 13px; color: #ccc; margin-bottom: 20px;">
                                    List of frames recently added.
                                </p>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($hasData): ?>
                            <div class="table-wrapper">
                                <table style="table-layout: fixed; width: 100%;">
                                    <colgroup>
                                        <col style="width: 200px;">
                                        <col style="width: 150px;">
                                        <col style="width: 80px;">
                                        <col style="width: 150px;">
                                        <col style="width: 250px;">
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th>UFC</th>
                                            <th>BRAND</th>
                                            <th>STOCK</th>
                                            <th>CREATED BY</th>
                                            <th>ACTIONS</th>
                                        </tr>
                                    </thead>
    
                                    <tbody>
                                        <?php while($row = $result->fetch_assoc()): ?>
                                                <tr>
                                                    <td><strong style="color: var(--accent);"><?php echo $row['ufc']; ?></strong></td>
                                                    <td><?php echo $row['brand']; ?></td>
                                                    <td><?php echo $row['stock']; ?></td>
                                                    <td><?php
                                                        $raw = trim($row['created_by'] ?? '');
                                                        if ($raw === '') {
                                                            echo '-';
                                                        } else {
                                                            $entries = array_map('trim', explode(',', $raw));
                                                            $lines = [];
                                                            foreach ($entries as $entry) {
                                                                if (preg_match('/^(.+?)\s*\((\d+)\)$/', $entry, $m)) {
                                                                    $lines[] = htmlspecialchars($m[1]) . ' &rarr; ' . $m[2];
                                                                } else {
                                                                    $lines[] = htmlspecialchars($entry);
                                                                }
                                                            }
                                                            echo implode('<br>', $lines);
                                                        }
                                                    ?></td>
                                                    <td>
                                                        <div class="action-btn-container">
                                                            <button type="button" class="btn-table btn-set-price" 
                                                                    onclick="window.location.href='edit_frame.php?ufc=<?php echo urlencode($row['ufc']); ?>'">
                                                                <?php echo ($role === 'admin') ? 'SET PRICE' : 'EDIT DATA'; ?>
                                                            </button>
                                                            
                                                            <form method="POST" action="pending_records_frame.php">
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="ufc" value="<?php echo htmlspecialchars($row['ufc']); ?>">
                                                                <button type="submit" class="btn-table btn-delete-row" 
                                                                        onclick="return confirm('Remove this from queue?')">
                                                                    DELETE
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <!-- Empty state now lives inside the card body so the card itself stays visible -->
                            <div class="empty-state" id="emptyMessage" style="display:block;">
                                <div class="empty-icon">📂</div>
                                <p style="font-weight: 600; margin-bottom: 10px;">No pending requests</p>
                                <p class="subtitle">
                                    <?php echo ($role === 'admin') ? 'NO RECENT INPUT DATA FROM STAFF' : ''; ?>
                                </p>
                            </div>
                            <?php endif; ?>
                            </div>
                        </div>
                    </div>                
                </div>
                
                <?php if ($role === 'admin'): ?>
                    <!-- CORRUPTED DATA -->
                    <div class="main-card" style="margin-left: auto; margin-right: auto;">                    
                        <div class="glass-window">
                            <?php
                                $queryCorrupt = "SELECT ufc, brand, stock, created_by 
                                                FROM frame_staging 
                                                WHERE 
                                                (
                                                (ufc = '' OR ufc IS NULL) OR
                                                (brand = '' OR brand IS NULL) OR
                                                (frame_code = '' OR frame_code IS NULL) OR
                                                (frame_size = '' OR frame_size IS NULL) OR
                                                (color_code = '' OR color_code IS NULL) OR
                                                (stock < 0)
                                                )
                                                OR 
                                                (
                                                NOT (buy_price = 0 AND sell_price = 0 AND (price_secret_code = '' OR price_secret_code IS NULL))
                                                AND 
                                                (
                                                    (buy_price > 0 AND sell_price <= 0 AND TRIM(price_secret_code) = 'LZ00') 
                                                    OR 
                                                    (sell_price > 0 AND TRIM(price_secret_code) = 'LZ00') 
                                                )
                                                )";
                                $resultCorrupt = $conn->query($queryCorrupt);
                                
                                // DEFINE THIS VARIABLE FOR USE BELOW
                                $hasCorruptedData = ($resultCorrupt && $resultCorrupt->num_rows > 0);
                            ?>
    
                            <?php if ($role === 'admin'): ?>
                                <!-- Card always renders for admin; only the body content switches between table and empty state -->
                                <div id="corrupt-display-section" class="table-responsive_approve_user">
                                    <div class="card-toggle-header is-collapsed" onclick="toggleCard(this, 'systemIntegrityBody')">
                                        <h2 style="font-size: 18px; color: var(--accent-red);">CORRUPTED DATA</h2>
                                        <div style="display:flex; align-items:center;">
                                            <button type="button" class="expand-table-btn" onclick="event.stopPropagation(); openTableFlyout('systemIntegrityBody', 'CORRUPTED DATA');">⛶ EXPAND</button>
                                            <span class="card-toggle-icon">▼</span>
                                        </div>
                                    </div>
                                    <div id="systemIntegrityBody" class="card-collapsible-body is-collapsed">
                                    <?php if ($hasCorruptedData): ?>
                                    <p style="font-size: 13px; color: #ccc; margin-bottom: 20px;">
                                        List of records with <strong>missing identity</strong>, <strong>negative stock</strong>, or <strong>price encryption errors</strong>.
                                    </p>
                                    <?php endif; ?>
    
                                    <?php if ($hasCorruptedData): ?>
                                    <div class="table-wrapper">
                                        <table style="table-layout: fixed; width: 100%;">
                                            <colgroup>
                                                <col style="width: 200px;">
                                                <col style="width: 150px;">
                                                <col style="width: 80px;">
                                                <col style="width: 150px;">
                                                <col style="width: 250px;">
                                            </colgroup>
                                            <thead>
                                                <tr>
                                                    <th>UFC</th>
                                                    <th>BRAND</th>
                                                    <th>STOCK</th>
                                                    <th>CREATED BY</th>
                                                    <th>ACTIONS</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while($rowCorrupt = $resultCorrupt->fetch_assoc()): ?>
                                                        <tr>
                                                            <td><strong style="color: var(--accent-red);"><?php echo $rowCorrupt['ufc'] ?: 'MISSING UFC'; ?></strong></td>
                                                            <td><?php echo $rowCorrupt['brand']; ?></td>
                                                            <td><?php echo $rowCorrupt['stock']; ?></td>
                                                            <td><?php
                                                                $raw = trim($rowCorrupt['created_by'] ?? '');
                                                                if ($raw === '') {
                                                                    echo '-';
                                                                } else {
                                                                    $entries = array_map('trim', explode(',', $raw));
                                                                    $lines = [];
                                                                    foreach ($entries as $entry) {
                                                                        if (preg_match('/^(.+?)\s*\((\d+)\)$/', $entry, $m)) {
                                                                            $lines[] = htmlspecialchars($m[1]) . ' &rarr; ' . $m[2];
                                                                        } else {
                                                                            $lines[] = htmlspecialchars($entry);
                                                                        }
                                                                    }
                                                                    echo implode('<br>', $lines);
                                                                }
                                                            ?></td>
                                                                <div class="action-btn-container">
                                                                    <button type="button" class="btn-table btn-set-price" 
                                                                            onclick="window.location.href='edit_frame.php?ufc=<?php echo urlencode($rowCorrupt['ufc']); ?>'">
                                                                        FIX DATA
                                                                    </button>
                                                                    
                                                                    <form method="POST" action="pending_records_frame.php">
                                                                        <input type="hidden" name="action" value="delete">
                                                                        <input type="hidden" name="ufc" value="<?php echo htmlspecialchars($rowCorrupt['ufc']); ?>">
                                                                        <button type="submit" class="btn-table btn-delete-row" 
                                                                                onclick="return confirm('Permanently delete this corrupted record?')">
                                                                            DELETE
                                                                        </button>
                                                                    </form>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php else: ?>
                                    <!-- Empty state now lives inside the card body so the card itself stays visible -->
                                    <div class="empty-state" id="corruptedDataMessage" style="display:block;">
                                        <div class="empty-icon">🛡️</div> 
                                        <p style="font-weight: 600; margin-bottom: 10px;">System Integrity Clear</p>
                                        <p class="subtitle">NO CORRUPTED DATA DETECTED IN THE SYSTEM</p>
                                    </div>
                                    <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>                
                    </div>
    
                    <!-- STAGING TABLE -->
                    <div class="main-card" style="margin-left: auto; margin-right: auto;">                    
                        <div class="glass-window">
                            <?php
                                $queryStaging = "SELECT ufc, brand, gender_category, stock, price_secret_code, created_by 
                                                FROM frame_staging 
                                                WHERE NOT (
                                                    (buy_price = 0 AND sell_price = 0 AND (price_secret_code = '' OR price_secret_code IS NULL))
                                                    OR 
                                                    (
                                                        (ufc = '' OR ufc IS NULL) OR
                                                        (brand = '' OR brand IS NULL) OR
                                                        (frame_code = '' OR frame_code IS NULL) OR
                                                        (frame_size = '' OR frame_size IS NULL) OR
                                                        (color_code = '' OR color_code IS NULL) OR
                                                        (stock < 0) OR
                                                        (
                                                            NOT (buy_price = 0 AND sell_price = 0 AND (price_secret_code = '' OR price_secret_code IS NULL))
                                                            AND 
                                                            (
                                                                (buy_price > 0 AND sell_price <= 0 AND TRIM(price_secret_code) = 'LZ00') 
                                                                OR 
                                                                (sell_price > 0 AND TRIM(price_secret_code) = 'LZ00') 
                                                            )
                                                        )
                                                    )
                                                )";

                                $resultStaging = $conn->query($queryStaging);
                                $hasStagingData = ($resultStaging && $resultStaging->num_rows > 0);
                            ?>
    
                            <?php if ($role === 'admin'): ?>
                                <!-- Card always renders for admin; only the body content switches between the form/table and empty state -->
                                <div id="staging-display-section" class="table-responsive_approve_user">
                                    <div class="card-toggle-header is-collapsed" onclick="toggleCard(this, 'stagingTableBody')">
                                        <h2 style="font-size: 18px; color: var(--accent-green);">STAGING</h2>
                                        <div style="display:flex; align-items:center;">
                                            <button type="submit" id="btnPrint" form="printForm" class="expand-table-btn" onclick="event.stopPropagation();" title="Print Selected QR" style="font-size: 18px;">🖨️</button>
                                            <button type="button" class="expand-table-btn" onclick="event.stopPropagation(); openTableFlyout('stagingTableBody', 'STAGING TABLE');">⛶ EXPAND</button>
                                            <span class="card-toggle-icon">▼</span>
                                        </div>
                                    </div>
                                    <div id="stagingTableBody" class="card-collapsible-body is-collapsed">
    
                                    <?php if ($hasStagingData): ?>
                                    <form id="printForm" action="print_qrcodes.php" method="POST">
                                        <div class="form-grid">
                                            <div class="table-wrapper">
                                                <table style="table-layout: fixed; width: 100%;">
                                                    <colgroup>
                                                        <col style="width: 50px;"> 
                                                        <col style="width: 50px;"> 
                                                        <col style="width: 200px;"> 
                                                        <col style="width: 120px;">
                                                         <col style="width: 100px;">  
                                                         <col style="width: 80px;"> 
                                                         <col style="width: 100px;">
                                                         <col style="width: 130px;">  
                                                         <col style="width: 200px;">
                                                    </colgroup>
                                                    <thead>
                                                        <tr>
                                                            <th><input type="checkbox" id="selectAllStaging" onclick="toggleSelectAll(this)" checked></th>
                                                            <th>NO</th>
                                                            <th>UFC</th>
                                                            <th>BRAND</th>
                                                            <th>GENDER CATEGORY</th>
                                                            <th>STOCK</th>
                                                            <th>SECRET CODE</th>
                                                            <th>CREATED BY</th>
                                                            <th>ACTIONS</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php 
                                                            $no = 1;
                                                            while($rowStaging = $resultStaging->fetch_assoc()): ?>
                                                                <tr>
                                                                    <td data-label="SELECT">
                                                                        <input type="checkbox" class="staging-checkbox" name="selected_ufc[]" 
                                                                        value="<?php echo htmlspecialchars($rowStaging['ufc']); ?>" checked>
                                                                    </td>
                                                                    <td data-label="NO"><?php echo $no++; ?></td>
                                                                    <td data-label="UFC"><strong style="color: var(--accent-green);"><?php echo $rowStaging['ufc'] ?: 'MISSING UFC'; ?></strong></td>
                                                                    <td data-label="BRAND"><?php echo $rowStaging['brand']; ?></td>
                                                                    <td data-label="GENDER CATEGORY"><?php echo $rowStaging['gender_category']; ?></td>
                                                                    <td data-label="STOCK"><?php echo $rowStaging['stock']; ?></td>
                                                                    <td data-label="SECRET CODE"><?php echo $rowStaging['price_secret_code']; ?></td>
                                                                    <td data-label="CREATED BY"><?php
                                                                        $raw = trim($rowStaging['created_by'] ?? '');
                                                                        if ($raw === '') {
                                                                            echo '-';
                                                                        } else {
                                                                            $entries = array_map('trim', explode(',', $raw));
                                                                            $lines = [];
                                                                            foreach ($entries as $entry) {
                                                                                if (preg_match('/^(.+?)\s*\((\d+)\)$/', $entry, $m)) {
                                                                                    $lines[] = htmlspecialchars($m[1]) . ' &rarr; ' . $m[2];
                                                                                } else {
                                                                                    $lines[] = htmlspecialchars($entry);
                                                                                }
                                                                            }
                                                                            echo implode('<br>', $lines);
                                                                        }
                                                                    ?></td>
                                                                    <td data-label="ACTIONS">
                                                                        <div class="action-btn-container">
                                                                            <button type="button" class="btn-table btn-set-price" 
                                                                                    onclick="window.location.href='edit_frame.php?ufc=<?php echo urlencode($rowStaging['ufc']); ?>'">
                                                                                EDIT
                                                                            </button>
                                                                            
                                                                            <!-- DELETE button uses a standalone form submitted via JS to avoid nesting inside #printForm -->
                                                                            <button type="button" class="btn-table btn-delete-row"
                                                                                    onclick="deleteStagingRecord('<?php echo htmlspecialchars($rowStaging['ufc'], ENT_QUOTES); ?>')">
                                                                                DELETE
                                                                            </button>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            <?php endwhile; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            
                                            <div class="btn-group">
                                                <button type="button"
                                                        id="btnSave"
                                                        name="submit_to_main" 
                                                        class="submit-main" 
                                                        style="background: var(--accent-green); color: #191c1e;"
                                                        onclick="confirmSaveToMain()">
                                                    SAVE DATA TO MAIN DATABASE
                                                </button>                                    
                                            </div>
                                        </div>
                                    </form>
                                    <?php else: ?>
                                    <!-- Empty state now lives inside the card body so the card itself stays visible -->
                                    <div class="empty-state" id="stagingEmptyMessage" style="display:block;">
                                        <div class="empty-icon">📥</div> 
                                        <p style="font-weight: 600; margin-bottom: 10px;">Staging Area Empty</p>
                                        <p class="subtitle">NO PENDING DATA READY TO BE UPLOADED</p>
                                    </div>
                                    <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>                
                    </div>
                <?php endif; ?>

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

        <!-- Standalone hidden form for staging DELETE — kept outside #printForm to avoid nested form conflict -->
        <form id="stagingDeleteForm" method="POST" action="pending_records_frame.php" style="display:none;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="ufc" id="stagingDeleteUfc" value="">
        </form>

        <!-- Shared "fly window" overlay: the real table element is moved here (not cloned),
             so all form controls, checkboxes, and buttons keep working exactly as in the card -->
        <div id="tableFlyoutOverlay" class="table-flyout-overlay">
            <div class="table-flyout-panel">
                <div class="table-flyout-header">
                    <h2 id="tableFlyoutTitle"></h2>
                    <button type="button" class="table-flyout-close" onclick="closeTableFlyout()">✕</button>
                </div>
                <div id="tableFlyoutContent"></div>
            </div>
        </div>

        <script>
            // Keeps track of where the moved table came from, so it can be moved back on close.
            let flyoutOriginParent = null;
            let flyoutOriginNextSibling = null;
            let flyoutMovedEl = null;

            // Open a fullscreen "fly window" that holds the ACTUAL table element (moved, not cloned)
            // from the given card body, so it can be viewed at full width while staying fully
            // interactive — checkboxes, edit/delete buttons, and form submission all keep working
            // because it's still the same DOM node with the same event listeners.
            function openTableFlyout(bodyId, title) {
                const bodyEl = document.getElementById(bodyId);
                const overlay = document.getElementById('tableFlyoutOverlay');
                const titleEl = document.getElementById('tableFlyoutTitle');
                const contentEl = document.getElementById('tableFlyoutContent');
                if (!bodyEl || !overlay || !contentEl) return;

                // The element to move: prefer the form (Staging Table wraps its table-wrapper
                // in a <form>) so the print/save buttons travel together with the table;
                // otherwise just move the table-wrapper itself.
                const moveTarget = bodyEl.querySelector('#printForm') || bodyEl.querySelector('.table-wrapper');
                if (!moveTarget) return;

                // Remember exactly where this element came from so we can put it back later.
                flyoutOriginParent = moveTarget.parentElement;
                flyoutOriginNextSibling = moveTarget.nextSibling;
                flyoutMovedEl = moveTarget;

                titleEl.textContent = title;
                contentEl.innerHTML = '';
                contentEl.appendChild(moveTarget);
                overlay.classList.add('is-open');
            }

            // Move the table back to its original place in the card and close the overlay.
            function closeTableFlyout() {
                const overlay = document.getElementById('tableFlyoutOverlay');
                if (flyoutMovedEl && flyoutOriginParent) {
                    flyoutOriginParent.insertBefore(flyoutMovedEl, flyoutOriginNextSibling);
                }
                flyoutOriginParent = null;
                flyoutOriginNextSibling = null;
                flyoutMovedEl = null;
                if (overlay) overlay.classList.remove('is-open');
            }

            // Toggle expand/collapse state of a card section
            function toggleCard(headerEl, bodyId) {
                const bodyEl = document.getElementById(bodyId);
                if (!bodyEl) return;

                headerEl.classList.toggle('is-collapsed');
                bodyEl.classList.toggle('is-collapsed');
                // Also toggle on the wrapper so its padding can shrink when collapsed
                const wrapperEl = headerEl.parentElement;
                if (wrapperEl) wrapperEl.classList.toggle('section-is-collapsed');
            }

            function deleteStagingRecord(ufc) {
                if (!confirm('Permanently delete this record?')) return;
                document.getElementById('stagingDeleteUfc').value = ufc;
                document.getElementById('stagingDeleteForm').submit();
            }

            function updateButtonState() {
                const btnPrint = document.getElementById('btnPrint');
                const btnSave = document.getElementById('btnSave');
                const checkedCount = document.querySelectorAll('.staging-checkbox:checked').length;

                // If nothing is checked, set disabled = true
                const isDisabled = checkedCount === 0;
                
                if(btnPrint) btnPrint.disabled = isDisabled;
                if(btnSave) btnSave.disabled = isDisabled;
            }

            function toggleSelectAll(source) {
                const checkboxes = document.querySelectorAll('.staging-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = source.checked;
                });
                updateButtonState(); // Update buttons after Select All
            }

            document.addEventListener('DOMContentLoaded', function() {
                const masterCheckbox = document.getElementById('selectAllStaging');
                const itemCheckboxes = document.querySelectorAll('.staging-checkbox');

                // Cards start collapsed by default, so apply the shrunk wrapper padding on load
                document.querySelectorAll('.card-toggle-header.is-collapsed').forEach(headerEl => {
                    if (headerEl.parentElement) headerEl.parentElement.classList.add('section-is-collapsed');
                });

                // Run initial check when page loads
                updateButtonState();

                itemCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        const totalChecked = document.querySelectorAll('.staging-checkbox:checked').length;
                        if(masterCheckbox) {
                            masterCheckbox.checked = (totalChecked === itemCheckboxes.length);
                        }
                        updateButtonState(); // Update buttons whenever a checkbox changes
                    });
                });
            });

            async function confirmSaveToMain() {
                // CHECK IF ANY CHECKBOXES ARE SELECTED
                const checkedBoxes = document.querySelectorAll('.staging-checkbox:checked');
                if (checkedBoxes.length === 0) {
                    Swal.fire('Opps!', 'Please select at least one record to save.', 'info');
                    return; // Stop execution here if nothing is selected
                }

                // 1. Show SweetAlert to request password
                const { value: password } = await Swal.fire({
                    title: 'VERIFICATION REQUIRED',
                    text: 'Please enter your login password to authorize data migration to Main Database.',
                    input: 'password',
                    inputPlaceholder: 'Enter your password',
                    icon: 'warning',
                    background: '#23272a',
                    color: '#ffffff',
                    showCancelButton: true,
                    confirmButtonText: 'AUTHORIZE & SAVE',
                    cancelButtonText: 'CANCEL',
                    customClass: {
                        popup: 'neumorph-alert',
                        confirmButton: 'btn-table'
                    }
                });

                // 2. If user enters a password (does not click cancel)
                if (password) {
                    const form = document.getElementById('printForm');
                    
                    // Add hidden input for password
                    const pwdInput = document.createElement('input');
                    pwdInput.type = 'hidden';
                    pwdInput.name = 'admin_password_verify';
                    pwdInput.value = password;
                    form.appendChild(pwdInput);
                    
                    // Add hidden input to indicate the submit button was clicked
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'submit_to_main';
                    actionInput.value = '1';
                    form.appendChild(actionInput);
                    
                    // Set form destination manually
                    form.action = 'process_upload_main.php';
                    form.method = 'POST';
                    
                    // 3. Submit Form
                    form.submit();
                }
            }
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
                        window.location.href = 'frame_management.php';
                    }
                }
                requestAnimationFrame(step);
            }

            // Animate the new pill-style Logout button before logging out
            function handleLogoutClick(element) {
                element.classList.add('pressed');
                setTimeout(() => {
                    window.location.href = 'logout.php';
                }, 220);
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

        <!-- ALERT IF SUCCESS TO UPLOAD TO MAIN DATABASE -->
        <?php if(isset($_SESSION['success_msg'])): ?>
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
            <script>
                Swal.fire({
                    title: 'SUCCESS',
                    text: '<?php echo $_SESSION['success_msg']; ?>',
                    icon: 'success',
                    iconColor: '#00ff88',
                    background: '#23272a', /* Matches your neumorphic theme */
                    color: '#ffffff',
                    confirmButtonText: 'GREAT',
                    customClass: {
                        popup: 'neumorph-alert',
                        confirmButton: 'btn-table' 
                    }
                });
            </script>
            <?php unset($_SESSION['success_msg']); ?>
        <?php endif; ?>

        <!-- ALERT FOR ERROR -->
        <?php if(isset($_SESSION['error_msg'])): ?>
            <script>
                Swal.fire({
                    title: 'FAILED!',
                    text: '<?php echo $_SESSION['error_msg']; ?>',
                    icon: 'error',
                    background: '#23272a',
                    color: '#ffffff',
                    confirmButtonText: 'OK'
                });
            </script>
            <?php unset($_SESSION['error_msg']); ?>
        <?php endif; ?>

    </body>
</html>