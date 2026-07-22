<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    $role = $_SESSION['role'] ?? 'staff';

    // 1. Load & Normalize Color Mapping (Original Logic)
    $color_map = [];
    $json_path = "data_json/colors.json";
    if (file_exists($json_path)) {
        $raw_json = json_decode(file_get_contents($json_path), true);
        if (is_array($raw_json)) {
            foreach ($raw_json as $colorName => $colorCode) {
                $color_map[strtoupper(trim($colorCode))] = strtoupper(trim($colorName));
            }
        }
    }

    $filter_command = isset($_GET['cmd']) ? strtolower(trim($_GET['cmd'])) : 'all';
    $show_data = isset($_GET['display']); 

    $result = null;
    $where_clause = "";
    $title_display = "Please click Display to load data";

    // configuration for input command rules to search data
    if ($show_data) {
        $cmd = $filter_command;
        $parts = explode('.', $cmd);
        $cmd_type = $parts[0];

        if ($cmd_type === 'delete' && isset($parts[1])) {
            $time_input = mysqli_real_escape_string($conn, $parts[1]); 
            
            // 1. Fetch UFC data to delete physical files
            $select_to_delete = "SELECT ufc FROM frames_main 
                                 WHERE stock <= 0 
                                 AND updated_at <= DATE_SUB(NOW(), INTERVAL $time_input)";
            $res_to_delete = mysqli_query($conn, $select_to_delete);
            
            if ($res_to_delete && mysqli_num_rows($res_to_delete) > 0) {
                while ($row_del = mysqli_fetch_assoc($res_to_delete)) {
                    $ufc_name = $row_del['ufc'];
                    $file_path = "main_qrcodes/" . $ufc_name . ".png";
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                }
            }

            // 2. Execute DELETE query
            $delete_query = "DELETE FROM frames_main 
                             WHERE stock <= 0 
                             AND updated_at <= DATE_SUB(NOW(), INTERVAL $time_input)";
            
            if (mysqli_query($conn, $delete_query)) {
                $affected = mysqli_affected_rows($conn);
                header("Location: ?msg=deleted&count=$affected");
                exit;
            }
        } elseif (in_array($cmd_type, ['brand', 'material', 'shape', 'structure', 'size', 'gender'])) {
            $val_main = mysqli_real_escape_string($conn, trim($parts[1] ?? ''));
            $extra_sql = "";
            $labels = [];

            // List of size keywords the user might input
            $size_keywords = ['small', 'medium', 'large', 'extra large', 'extra small'];

            for ($i = 2; $i < count($parts); $i++) {
                $p = strtolower(trim($parts[$i]));
                
                if ($p === 'available') {
                    $extra_sql .= " AND stock > 0";
                    $labels[] = "AVAILABLE";
                } elseif (in_array($p, ['new', 'old', 'very old'])) {
                    $extra_sql .= " AND stock_age = '$p'";
                    $labels[] = strtoupper($p);
                } elseif (in_array($p, $size_keywords)) {
                    // New Feature: Size filter within any command
                    $extra_sql .= " AND size_range LIKE '%$p%'";
                    $labels[] = "SIZE: " . strtoupper($p);
                }
            }

            $column_map = [
                'brand'     => 'brand',
                'material'  => 'material',
                'shape'     => 'lens_shape',
                'structure' => 'structure',
                'size'      => 'size_range',
                'gender'    => 'gender_category'
            ];
            
            $col = $column_map[$cmd_type];
            $operator = ($cmd_type === 'structure' || $cmd_type === 'size') ? "= '$val_main'" : "LIKE '%$val_main%'";
            $where_clause = " WHERE $col $operator" . $extra_sql;
            $title_display = strtoupper($cmd_type) . ": " . strtoupper($val_main) . ($labels ? " (" . implode(" & ", $labels) . ")" : "");

        } elseif ($cmd_type === 'all') {
            $age = isset($parts[1]) ? trim($parts[1]) : null;
            $where_clause = $age ? " WHERE stock_age = '$age'" : "";
            $title_display = $age ? "Stock Filter: " . strtoupper($age) : "Showing All Main Data";

        } else {
            $search = mysqli_real_escape_string($conn, $cmd);
            $where_clause = " WHERE brand LIKE '%$search%' OR ufc LIKE '%$search%' OR lens_shape LIKE '%$search%'";
            $title_display = "Search Results: " . strtoupper($search);
        }

        $query = "SELECT * FROM frames_main" . $where_clause . " ORDER BY ufc ASC";
        $result = mysqli_query($conn, $query);
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Database - Cyber View</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --bg-color: #0f1113;
            --card-bg: #16181b;
            --accent: linear-gradient(135deg, #00d4ff 0%, #0055ff 100%);
            --accent-solid: #00d4ff;
            --shadow-dark: #08090a;
            --shadow-light: #1f2226;
            --text-main: #ffffff;
            --text-muted: #808b96;
            --success: #2ecc71;
            --warning: #f1c40f;
            --danger: #e74c3c;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0; padding: 40px 0px;
            display: flex; justify-content: center;
        }

        .container { width: 100%; max-width: none; }

        /* --- HEADER --- */
        .header-area { margin-bottom: 30px; }
        .header-area h1 { font-weight: 800; font-size: 24px; letter-spacing: 2px; margin: 0; }
        .status-text { color: var(--accent-solid); font-size: 13px; font-weight: 600; text-transform: uppercase; margin-top: 5px; }

        /* --- INPUT BAR --- */
        .input-bar-container {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 25px;
            box-shadow: 15px 15px 35px var(--shadow-dark), -10px -10px 30px var(--shadow-light);
            display: flex; gap: 12px; margin-bottom: 40px;
            align-items: center; border: 1px solid rgba(255,255,255,0.02);
        }

        .cmd-input {
            flex: 1; background: var(--bg-color); border: 1px solid rgba(255,255,255,0.05);
            padding: 15px 20px; border-radius: 15px; color: var(--accent-solid);
            font-family: 'Courier New', monospace; outline: none;
            box-shadow: inset 6px 6px 12px var(--shadow-dark);
            text-transform: uppercase;
        }

        .btn-display {
            background: var(--accent); border: none; padding: 0 30px; height: 50px;
            border-radius: 15px; color: white; font-weight: 800; cursor: pointer;
            box-shadow: 0 5px 15px rgba(0, 85, 255, 0.3); transition: 0.3s;
        }

        .btn-help {
            background: var(--card-bg); border: none; width: 50px; height: 50px;
            border-radius: 15px; color: var(--accent-solid); cursor: pointer;
            box-shadow: 4px 4px 10px var(--shadow-dark), -2px -2px 8px var(--shadow-light);
        }

        /* --- TABLE AREA --- */
        .table-responsive {
            background: var(--card-bg); border-radius: 30px; padding: 10px;
            box-shadow: 20px 20px 60px var(--shadow-dark); overflow-x: auto;
        }

        table { width: 100%; border-collapse: separate; border-spacing: 0 10px; min-width: 1000px; }
        th { padding: 15px 20px; font-size: 11px; color: var(--text-muted); text-transform: uppercase; text-align: left; }
        td { padding: 18px 20px; background: #1a1d21; font-size: 13px; white-space: nowrap; }

        tr td:first-child { border-radius: 15px 0 0 15px; border-left: 3px solid var(--accent-solid); }
        tr td:last-child { border-radius: 0 15px 15px 0; }

        .ufc-badge { font-family: 'Courier New', monospace; font-weight: 800; color: #fff; }
        .color-code { font-size: 11px; color: var(--text-muted); }
        
        /* AGE DOTS */
        .age-dot { height: 20px; width: 20px; border-radius: 50%; display: inline-block; box-shadow: 0 0 8px currentColor; }
        .dot-new { color: var(--success); background: currentColor; }
        .dot-old { color: var(--warning); background: currentColor; }
        .dot-veryold { color: var(--danger); background: currentColor; }

        /* EMPTY STATE */
        .empty-state {
            text-align: center; padding: 80px; background: var(--card-bg);
            border-radius: 30px; box-shadow: 20px 20px 60px var(--shadow-dark);
            display: block;
        }
        .price-box {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .price-value {
            display: none; /* Hidden by default */
        }
        .price-hidden {
            color: var(--text-muted);
            font-style: italic;
            font-size: 11px;
        }
        .btn-reveal {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            color: var(--accent-solid);
            padding: 4px 8px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 10px;
            transition: 0.2s;
        }
        .btn-reveal:hover {
            background: var(--accent-solid);
            color: white;
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

            <div class="selection-container" style="
            margin-left: auto; 
            margin-right: auto; 
            width: 100%; max-width: none;">
                <div class="container">
                    <div class="header-area">
                            <h1>MASTER DATABASE</h1>
                            <div class="status-text"><?= $title_display ?></div>
                        </div>

                        <form method="GET" action="" class="input-bar-container">
                            <input type="text" name="cmd" class="cmd-input" value="<?= htmlspecialchars($filter_command) ?>" placeholder="e.g: brand.takeyama.available">
                            <button type="submit" name="display" value="true" class="btn-display" style="font-size: 22px; width: 50px; height: 50px; padding: 0;">🔍</button>
                            <button type="button" class="btn-help" onclick="showHelp()"><i class="fas fa-question"></i></button>
                        </form>

                        <?php if ($show_data && $result): ?>
                            <?php if (mysqli_num_rows($result) > 0): ?>
                                <div class="table-responsive">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>No</th><th>Brand</th><th>UFC</th><th>Color Details</th><th>Material</th><th>Shape</th>
                                                <th>Size</th><th>Gender Category</th><?php if($role == 'admin'): ?><th>Buy</th><?php endif; ?>
                                                <th>Sell</th><th>Secret</th><th>Stock</th><th>Age</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $no = 1; while($row = mysqli_fetch_assoc($result)): 
                                                $code = strtoupper(trim($row['color_code']));
                                                $name = $color_map[$code] ?? $code;
                                            ?>
                                            <tr>
                                                <td><?= $no++ ?></td>
                                                <td style="font-weight: 800; color: var(--accent-solid); letter-spacing: 1px;">
                                                    <?= strtoupper($row['brand']) ?>
                                                </td>
                                                <td class="ufc-badge">
                                                    <div class="price-box">
                                                        <span class="price-hidden" style="font-family: sans-serif;"></span>
                                                        <span class="price-value"><?= $row['ufc'] ?></span>
                                                        <button type="button" class="btn-reveal" onclick="revealPrice(this)"><i class="fas fa-eye"></i></button>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span><?= $name ?></span>
                                                </td>
                                                <td><?= $row['material'] ?></td>
                                                <td><?= $row['lens_shape'] ?></td>
                                                <td><?= $row['size_range'] ?></td>
                                                <td><?= $row['gender_category'] ?></td>
                                                <?php if($role == 'admin'): ?>
                                                    <td style="color: var(--success);">
                                                        <div class="price-box">
                                                            <span class="price-hidden"></span>
                                                            <span class="price-value">IDR <?= number_format($row['buy_price'],0,',','.') ?></span>
                                                            <button type="button" class="btn-reveal" onclick="revealPrice(this)"><i class="fas fa-eye"></i></button>
                                                        </div>
                                                    </td>
                                                <?php endif; ?>

                                                <td style="font-weight: 700;">
                                                    <div class="price-box">
                                                        <span class="price-hidden"></span>
                                                        <span class="price-value">IDR <?= number_format($row['sell_price'],0,',','.') ?></span>
                                                        <button type="button" class="btn-reveal" onclick="revealPrice(this)"><i class="fas fa-eye"></i></button>
                                                    </div>
                                                </td>
                                                <td style="color: var(--danger); font-size: 14px; font-weight: bold; font-family: monospace;"><?= $row['price_secret_code'] ?></td>
                                                <td style="text-align: center;"><strong><?= $row['stock'] ?></strong></td>
                                                <td style="text-align: center;">
                                                    <span class="age-dot dot-<?= str_replace(' ', '', $row['stock_age']) ?>" title="<?= strtoupper($row['stock_age']) ?>"></span>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-database" style="font-size: 50px; opacity: 0.2; margin-bottom: 20px;"></i>
                                    <h2 style="color: var(--danger);">⚠️ NO DATA FOUND</h2>
                                    <p style="color: var(--text-muted);">No frames matched the given command.</p>
                                    <button class="btn-display" onclick="window.location.href='?'" style="margin-top: 20px;">RESET FILTER</button>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
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
        // Check if there is a success message in the URL
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('msg') === 'deleted') {
            const count = urlParams.get('count') || 0;
            Swal.fire({
                title: 'CLEANUP SUCCESS',
                text: count + ' data frames with 0 stock have been deleted.',
                icon: 'success',
                background: '#16181b',
                color: '#fff',
                confirmButtonColor: '#2ecc71'
            }).then(() => {
                window.history.replaceState({}, document.title, window.location.pathname);
            });
        }

        function showHelp() {
            Swal.fire({
                title: '<span style="color: #00d4ff">SEARCH COMMAND GUIDE</span>',
                html: `
                    <div style="text-align: left; color: #ccc; font-size: 14px; line-height: 1.6;">
                        <p>Format: <b>category.value.extra</b></p>
                        <hr style="border: 0; border-top: 1px solid #333; margin: 10px 0;">
                        • <b>all</b> : Show all data<br>
                        • <b>brand.takeyama</b> : Filter by brand<br>
                        • <b>shape.square</b> : Filter by lens shape<br>
                        • <b>gender.men</b> : Filter by gender (men/female/unisex)<br>
                        • <b>size.50-18</b> : Filter by specific size<br>
                        • <b>.small / .medium / .large</b> : Filter by size range
                        <hr style="border: 0; border-top: 1px solid #333; margin: 10px 0;">
                        <b>Extras:</b><br>
                        • <b>.available</b> : Stock > 0 only<br>
                        • <b>.new / .old / .very old</b> : Filter by stock age
                        <hr style="border: 0; border-top: 1px solid #333; margin: 10px 0;">
                        <b style="color: #e74c3c;">Maintenance Command:</b><br>
                        • <b>delete.1 year</b> : Delete 0 stock (updated > 1 yr ago)<br>
                        • <b>delete.5 month</b> : Delete 0 stock (updated > 5 mos ago)<br>
                        <small style="color: #888">*Only deletes data with 0 stock.</small>
                    </div>
                `,
                background: '#16181b',
                confirmButtonColor: '#0055ff',
                confirmButtonText: 'UNDERSTOOD'
            });
        }

        function revealPrice(btn) {
            const box = btn.parentElement;
            const hiddenText = box.querySelector('.price-hidden');
            const valueText = box.querySelector('.price-value');
            const icon = btn.querySelector('i');

            if (valueText.style.display === 'none' || valueText.style.display === '') {
                valueText.style.display = 'inline';
                hiddenText.style.display = 'none';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
                btn.style.opacity = '0.5'; // Mark as revealed
            } else {
                valueText.style.display = 'none';
                hiddenText.style.display = 'inline';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
                btn.style.opacity = '1';
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
</body>
</html>