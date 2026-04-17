<?php
    // lense_price.php
    session_start();

    include 'db_config.php';
    include 'config_helper.php';

    // Security check
    if ($_SESSION['role'] !== 'admin') {
        header("Location: index.php");
        exit();
    }

    $json_file = 'data_json/lense_prices.json';

    // Initialize file if it does not exist
    if (!file_exists($json_file)) {
        $initial_data = ["stock" => ["Single Vision" => []], "lab" => []];
        file_put_contents($json_file, json_encode($initial_data, JSON_PRETTY_PRINT));
    }

    $data = json_decode(file_get_contents($json_file), true);
    $message = '';

    // --- Migrate old data to {cost, selling, features} structure ---
    foreach ($data as $gk => $cats) {
        foreach ($cats as $ck => $lenses) {
            foreach ($lenses as $ln => $val) {
                if (!is_array($val)) {
                    $data[$gk][$ck][$ln] = ['cost' => (float)$val, 'selling' => 0.0, 'features' => []];
                } elseif (!isset($val['features'])) {
                    $data[$gk][$ck][$ln]['features'] = [];
                }
            }
        }
    }

    // --- POST Handlers ---
    if ($_SERVER["REQUEST_METHOD"] == "POST") {

        if (isset($_POST['save_prices'])) {
            // Rebuild each category to support lens renaming
            foreach ($_POST['price_cost'] as $group => $categories) {
                foreach ($categories as $category => $lenses) {
                    $rebuilt = [];
                    foreach ($lenses as $old_name => $cost) {
                        $new_name     = trim($_POST['price_name'][$group][$category][$old_name] ?? $old_name);
                        if (empty($new_name)) $new_name = $old_name;
                        $selling      = (float)($_POST['price_selling'][$group][$category][$old_name] ?? 0);
                        $features_raw = $_POST['price_features'][$group][$category][$old_name] ?? '';
                        $features     = array_values(array_filter(array_map('trim', explode(',', $features_raw))));
                        $rebuilt[$new_name] = [
                            'cost'     => (float)$cost,
                            'selling'  => $selling,
                            'features' => $features,
                        ];
                    }
                    $data[$group][$category] = $rebuilt;
                }
            }
            $message = "All changes saved successfully.";

        } elseif (isset($_POST['add_new_lense'])) {
            $new_group    = $_POST['new_group'];
            $new_cat      = trim($_POST['new_category']) ?: 'General';
            $new_name     = trim($_POST['new_lense_name']);
            $new_cost     = (float)$_POST['new_lense_price_cost'];
            $new_selling  = (float)$_POST['new_lense_price_selling'];
            $features_raw = $_POST['new_lense_features'] ?? '';
            $new_features = array_values(array_filter(array_map('trim', explode(',', $features_raw))));

            if (!empty($new_name)) {
                $data[$new_group][$new_cat][$new_name] = [
                    'cost'     => $new_cost,
                    'selling'  => $new_selling,
                    'features' => $new_features,
                ];
                $message    = "Lens \"" . htmlspecialchars($new_name) . "\" added successfully.";
                $active_tab = 'add';
            }
        }

        file_put_contents($json_file, json_encode($data, JSON_PRETTY_PRINT));
    }

    $selected_group = $_POST['last_group'] ?? 'stock';
    $selected_cat   = $_POST['last_category'] ?? '';
    if (empty($selected_cat) && isset($data[$selected_group])) {
        $selected_cat = array_key_first($data[$selected_group]);
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lens Price Configuration</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* ============================================================
           LAYOUT
        ============================================================ */
        .config-window { margin: 0 auto; width: 100%; max-width: 100%; }

        .tab-navigation {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-bottom: 28px;
        }

        .btn-group {
            margin-top: 28px;
            width: 100%;
            display: flex;
            justify-content: center;
        }

        .back-main { width: 100%; max-width: 400px; }

        /* ============================================================
           TAB BUTTONS
        ============================================================ */
        .btn-neumorph {
            padding: 11px 24px;
            border: none;
            border-radius: 12px;
            background: #2a2d32;
            color: #b0b3b8;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 5px 5px 10px #1d1f23, -5px -5px 10px #373b41;
        }
        .btn-neumorph:hover  { color: #00adb5; }
        .btn-neumorph.active {
            box-shadow: inset 4px 4px 8px #1d1f23, inset -4px -4px 8px #373b41;
            color: #00adb5;
        }

        /* ============================================================
           FILTER BAR
        ============================================================ */
        .filter-bar {
            background: #252830;
            border: 1px solid #33363d;
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            gap: 16px;
            align-items: flex-end;
            margin-bottom: 20px;
        }
        .filter-bar > div { flex: 1; }
        .filter-bar label {
            display: block;
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 6px;
        }

        /* ============================================================
           LENS GROUP (details/summary)
        ============================================================ */
        .lense-group-wrapper { width: 100%; margin-bottom: 8px; }

        .lense-details summary {
            list-style: none;
            cursor: pointer;
            outline: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: #1e2127;
            border: 1px solid #2e3138;
            border-radius: 10px;
            color: #c9cdd4;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s ease;
            user-select: none;
        }
        .lense-details summary::-webkit-details-marker { display: none; }
        .lense-details summary:hover  { border-color: #00adb5; color: #00adb5; }
        .lense-details[open] summary  {
            color: #00adb5;
            border-color: #00adb5;
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
            border-bottom-color: transparent;
        }

        .summary-arrow {
            font-size: 10px;
            transition: transform 0.25s ease;
            color: #4b5563;
        }
        .lense-details[open] .summary-arrow { transform: rotate(180deg); color: #00adb5; }

        .lense-panel {
            border: 1px solid #00adb5;
            border-top: none;
            border-bottom-left-radius: 10px;
            border-bottom-right-radius: 10px;
            overflow: hidden;
            animation: slideDown 0.2s ease-out;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ============================================================
           LENS CARD
        ============================================================ */
        .lens-card {
            background: #23262d;
            border-bottom: 1px solid #2e3138;
            padding: 16px 20px;
        }
        .lens-card:last-child { border-bottom: none; }

        /* Editable name row */
        .lens-name-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
        }
        .lens-name-icon { color: #4b5563; font-size: 12px; flex-shrink: 0; }
        .lens-name-input {
            flex: 1;
            background: transparent;
            border: none;
            border-bottom: 1px dashed #3a3d44;
            border-radius: 0;
            color: #e5e7eb;
            font-size: 14px;
            font-weight: 700;
            padding: 4px 2px;
            outline: none;
            transition: border-color 0.2s;
        }
        .lens-name-input:focus { border-bottom-color: #00adb5; color: #fff; }
        .lens-name-badge {
            font-size: 10px;
            color: #374151;
            font-style: italic;
            white-space: nowrap;
        }

        /* Prices row */
        .lens-prices-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }
        .price-col           { flex: 1; min-width: 160px; }
        .price-col label     {
            display: block;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        .price-col.cost-col label { color: #6b7280; }
        .price-col.sell-col label { color: #00adb5; }

        /* Features section */
        .lens-features-label {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin-bottom: 8px;
        }

        /* Feature tags */
        .features-tag-wrapper {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            min-height: 24px;
        }
        .feature-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #162829;
            color: #2dd4bf;
            border: 1px solid #1e4a4d;
            border-radius: 20px;
            padding: 3px 8px 3px 10px;
            font-size: 11px;
            font-weight: 600;
        }
        .feature-tag-dot {
            width: 4px; height: 4px;
            border-radius: 50%;
            background: #2dd4bf;
            flex-shrink: 0;
        }
        .btn-remove-tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            color: #4b7a7c;
            font-size: 14px;
            line-height: 1;
            cursor: pointer;
            padding: 0;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            transition: color 0.15s;
        }
        .btn-remove-tag:hover { color: #f87171; }
        .no-features-text { font-size: 11px; color: #3a3d44; font-style: italic; }

        /* Add feature input row */
        .feature-add-row {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            align-items: center;
        }
        .feature-add-input {
            flex: 1;
            background: #1a1d22 !important;
            border: 1px solid #2e3138 !important;
            border-radius: 8px !important;
            color: #c9cdd4 !important;
            font-size: 12px !important;
            padding: 7px 11px !important;
            outline: none !important;
            transition: border-color 0.2s !important;
            min-width: 0;
        }
        .feature-add-input:focus       { border-color: #00adb5 !important; }
        .feature-add-input::placeholder { color: #3d4149 !important; }

        .btn-add-feature {
            background: #1a3a3c;
            color: #00adb5;
            border: 1px solid #1e4a4d;
            border-radius: 8px;
            padding: 7px 14px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            flex-shrink: 0;
            transition: all 0.2s;
        }
        .btn-add-feature:hover { background: #00adb5; color: #fff; border-color: #00adb5; }

        /* ============================================================
           ADD LENS FORM
        ============================================================ */
        #form-add-lense {
            display: none;
            width: 100%;
            flex-direction: column;
            align-items: center;
            padding: 20px 0;
        }
        #form-add-lense:not(.hidden-form) { display: flex !important; }

        .add-form-card {
            background: #23262d;
            border: 1px solid #2e3138;
            border-radius: 14px;
            padding: 24px;
            width: 100%;
            max-width: 560px;
        }
        .add-form-title {
            font-size: 13px;
            font-weight: 700;
            color: #00adb5;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 20px;
            text-align: center;
        }
        .add-form-grid    { display: flex; flex-direction: column; gap: 14px; }
        .form-field label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        /* ============================================================
           SAVE BAR
        ============================================================ */
        .save-bar {
            margin-top: 20px;
            padding: 14px 20px;
            background: #1e2127;
            border: 1px solid #2e3138;
            border-radius: 12px;
            display: flex;
            justify-content: flex-end;
        }

        /* ============================================================
           PAGE HEADER
        ============================================================ */
        .page-header { text-align: center; margin-bottom: 24px; }
        .page-header h2 { margin: 0 0 4px; font-size: 18px; }
        .page-header p  { margin: 0; color: #6b7280; font-size: 12px; }

        /* Misc */
        .hidden-form { display: none !important; }
        #form-price-list { width: 100%; display: flex; flex-direction: column; align-items: center; }

        /* ============================================================
           MOBILE
        ============================================================ */
        @media (max-width: 600px) {
            .config-window  { padding: 0 8px; box-sizing: border-box; }
            .content-area   { padding: 5px !important; width: 100% !important; }
            .tab-navigation { gap: 8px; width: 100%; }
            .btn-neumorph   { flex: 1; padding: 13px 6px; font-size: 13px; }
            .filter-bar     { flex-direction: column; }
            .lens-prices-row { flex-direction: column; }
            .add-form-card  { padding: 16px; }
        }
    </style>
</head>
<body>
<div class="main-wrapper">
<div class="content-area" style="flex-direction:column;">

    <!-- ── Header ─────────────────────────────────────────────── -->
    <div class="header-container" style="margin:0 auto;width:100%;">
        <button class="logout-btn" onclick="window.location.href='logout.php';"><span>Logout</span></button>
        <div class="brand-section">
            <div class="logo-box">
                <img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>?t=<?php echo time(); ?>" alt="Brand Logo" style="height:40px;">
            </div>
            <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
            <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
        </div>
    </div>

    <div class="config-window">

        <!-- Page title -->
        <div class="page-header">
            <h2>Lens Price Settings</h2>
            <p>Manage pricing and features for Stock and Lab lenses</p>
        </div>

        <!-- Success message -->
        <?php if ($message): ?>
        <div style="background:#065f46;color:#6ee7b7;border:1px solid #047857;padding:10px 16px;border-radius:8px;margin-bottom:16px;text-align:center;font-size:13px;">
            <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <!-- Tab navigation -->
        <div class="tab-navigation">
            <button type="button" id="btn-price" class="btn-neumorph active" onclick="showTab('price')">&#9776;&ensp;Price List</button>
            <button type="button" id="btn-add"   class="btn-neumorph"        onclick="showTab('add')">&#43;&ensp;Add New Lens</button>
        </div>

        <!-- ══════════════════════════════════════════════════════
             ADD NEW LENS FORM
        ══════════════════════════════════════════════════════ -->
        <form id="form-add-lense" action="lense_price.php" method="POST" class="hidden-form">

            <div class="add-form-card">
                <div class="add-form-title">Add New Lens</div>
                <div class="add-form-grid">

                    <div class="form-field">
                        <label>Group</label>
                        <select name="new_group" class="input-field">
                            <option value="stock">Stock Lens</option>
                            <option value="lab">Lab Lens (Custom Order)</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label>Category</label>
                        <input type="text" class="input-field" name="new_category" placeholder="e.g. Single Vision">
                    </div>

                    <div class="form-field">
                        <label>Lens Name</label>
                        <input type="text" name="new_lense_name" class="input-field" placeholder="e.g. SV-CRMC" required>
                    </div>

                    <div class="lens-prices-row" style="margin:0;">
                        <div class="price-col cost-col form-field" style="margin:0;">
                            <label>Cost Price</label>
                            <input type="text" id="add_display_cost" class="input-field" placeholder="IDR 0"
                                oninput="formatCurrencyAdd(this,'add_real_cost')" onfocus="this.select()" autocomplete="off" required>
                            <input type="hidden" name="new_lense_price_cost" id="add_real_cost">
                        </div>
                        <div class="price-col sell-col form-field" style="margin:0;">
                            <label>Selling Price</label>
                            <input type="text" id="add_display_selling" class="input-field" placeholder="IDR 0"
                                oninput="formatCurrencyAdd(this,'add_real_selling')" onfocus="this.select()" autocomplete="off" required>
                            <input type="hidden" name="new_lense_price_selling" id="add_real_selling">
                        </div>
                    </div>

                    <div class="form-field">
                        <label>Features</label>
                        <!-- Tag preview -->
                        <div class="features-tag-wrapper" id="tags-new_lense" style="margin-bottom:8px; min-height:20px;"></div>
                        <!-- Add input -->
                        <div class="feature-add-row">
                            <input type="text" id="feat-input-new_lense" class="feature-add-input"
                                placeholder="Type a feature and press Enter or click Add"
                                onkeydown="handleFeatureKeydown(event,'new_lense')">
                            <button type="button" class="btn-add-feature" onclick="addFeatureTag('new_lense')">+ Add</button>
                        </div>
                        <input type="hidden" name="new_lense_features" id="feat-hidden-new_lense">
                    </div>

                </div>
                <div style="margin-top:20px;">
                    <button type="submit" name="add_new_lense" class="btn-save" style="width:100%;">Add Lens</button>
                </div>
            </div>

        </form>

        <!-- ══════════════════════════════════════════════════════
             PRICE LIST FORM
        ══════════════════════════════════════════════════════ -->
        <form id="form-price-list" action="lense_price.php" method="POST">
            <input type="hidden" name="last_group"    id="last_group"    value="<?php echo htmlspecialchars($selected_group); ?>">
            <input type="hidden" name="last_category" id="last_category" value="<?php echo htmlspecialchars($selected_cat); ?>">

            <!-- Filter bar -->
            <div class="filter-bar">
                <div>
                    <label>Group</label>
                    <select id="filter-group" class="input-field" onchange="updateCategoryFilter()">
                        <?php foreach (array_keys($data) as $group): ?>
                            <option value="<?php echo $group; ?>"><?php echo ucfirst($group); ?> Lenses</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Category</label>
                    <select id="filter-category" class="input-field" onchange="filterLenses()"></select>
                </div>
            </div>

            <!-- Lens groups -->
            <div id="lense-display-container" style="width:100%;">
                <?php
                    $lens_counter = 0;
                    foreach ($data as $group_key => $categories):
                        foreach ($categories as $cat_name => $lenses):
                ?>
                <div class="lense-group-wrapper"
                     data-group="<?php echo htmlspecialchars($group_key); ?>"
                     data-category="<?php echo htmlspecialchars($cat_name); ?>">

                    <details class="lense-details">
                        <summary>
                            <span>
                                <span style="color:#6b7280;font-weight:400;margin-right:4px;"><?php echo ucfirst($group_key); ?> /</span>
                                <?php echo htmlspecialchars($cat_name); ?>
                                <span style="font-size:11px;font-weight:400;color:#4b5563;margin-left:8px;">
                                    <?php echo count($lenses); ?> lens<?php echo count($lenses) !== 1 ? 'es' : ''; ?>
                                </span>
                            </span>
                            <span class="summary-arrow">&#9660;</span>
                        </summary>

                        <div class="lense-panel">
                            <?php foreach ($lenses as $name => $prices):
                                $lens_counter++;
                                $sk       = 'lens_' . $lens_counter;
                                $cost     = is_array($prices) ? ($prices['cost']     ?? 0)  : (float)$prices;
                                $selling  = is_array($prices) ? ($prices['selling']  ?? 0)  : 0.0;
                                $features = is_array($prices) ? ($prices['features'] ?? []) : [];
                            ?>
                            <div class="lens-card">

                                <!-- Editable lens name -->
                                <div class="lens-name-row">
                                    <span class="lens-name-icon">&#9998;</span>
                                    <input type="text"
                                        class="lens-name-input"
                                        name="price_name[<?php echo $group_key; ?>][<?php echo $cat_name; ?>][<?php echo $name; ?>]"
                                        value="<?php echo htmlspecialchars($name); ?>"
                                        title="Click to rename this lens">
                                    <span class="lens-name-badge">click to rename</span>
                                </div>

                                <!-- Cost + Selling -->
                                <div class="lens-prices-row">
                                    <div class="price-col cost-col">
                                        <label>Cost Price</label>
                                        <input type="text"
                                            class="input-field currency-display"
                                            value="IDR <?php echo number_format($cost, 0, ',', '.'); ?>"
                                            oninput="formatMultipleCurrency(this)"
                                            onfocus="this.select()" autocomplete="off">
                                        <input type="hidden"
                                            name="price_cost[<?php echo $group_key; ?>][<?php echo $cat_name; ?>][<?php echo $name; ?>]"
                                            value="<?php echo $cost ?: 0; ?>">
                                    </div>
                                    <div class="price-col sell-col">
                                        <label>Selling Price</label>
                                        <input type="text"
                                            class="input-field currency-display"
                                            value="IDR <?php echo number_format($selling, 0, ',', '.'); ?>"
                                            oninput="formatMultipleCurrency(this)"
                                            onfocus="this.select()" autocomplete="off">
                                        <input type="hidden"
                                            name="price_selling[<?php echo $group_key; ?>][<?php echo $cat_name; ?>][<?php echo $name; ?>]"
                                            value="<?php echo $selling ?: 0; ?>">
                                    </div>
                                </div>

                                <!-- Features -->
                                <div>
                                    <div class="lens-features-label">Features</div>

                                    <!-- Tags with X button -->
                                    <div class="features-tag-wrapper"
                                         id="tags-<?php echo $sk; ?>"
                                         data-safe-key="<?php echo $sk; ?>"
                                         data-features='<?php echo htmlspecialchars(json_encode($features), ENT_QUOTES); ?>'>
                                    </div>

                                    <!-- Add new feature -->
                                    <div class="feature-add-row">
                                        <input type="text"
                                            id="feat-input-<?php echo $sk; ?>"
                                            class="feature-add-input"
                                            placeholder="Type a feature and press Enter or Add"
                                            onkeydown="handleFeatureKeydown(event,'<?php echo $sk; ?>')">
                                        <button type="button" class="btn-add-feature"
                                            onclick="addFeatureTag('<?php echo $sk; ?>')">+ Add</button>
                                    </div>

                                    <input type="hidden"
                                        id="feat-hidden-<?php echo $sk; ?>"
                                        name="price_features[<?php echo $group_key; ?>][<?php echo $cat_name; ?>][<?php echo $name; ?>]"
                                        value="<?php echo htmlspecialchars(implode(', ', $features)); ?>"
                                        class="features-hidden-input">
                                </div>

                            </div><!-- /.lens-card -->
                            <?php endforeach; ?>
                        </div><!-- /.lense-panel -->
                    </details>
                </div><!-- /.lense-group-wrapper -->
                <?php endforeach; endforeach; ?>
            </div>

            <!-- Save bar -->
            <div class="save-bar">
                <button type="submit" name="save_prices" class="btn-save" style="min-width:180px;">
                    Save All Changes
                </button>
            </div>

        </form>

        <div class="btn-group">
            <button type="button" class="back-main" onclick="window.location.href='customer.php'">
                &larr; Back to Previous Page
            </button>
        </div>

    </div><!-- /.config-window -->
</div><!-- /.content-area -->

<footer class="footer-container">
    <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
</footer>
</div><!-- /.main-wrapper -->

<script>
// ─── Tab switching ────────────────────────────────────────────
function showTab(tabName) {
    const formPrice = document.getElementById('form-price-list');
    const formAdd   = document.getElementById('form-add-lense');
    const btnPrice  = document.getElementById('btn-price');
    const btnAdd    = document.getElementById('btn-add');
    if (tabName === 'price') {
        formPrice.classList.remove('hidden-form');
        formAdd.classList.add('hidden-form');
        btnPrice.classList.add('active');
        btnAdd.classList.remove('active');
    } else {
        formAdd.classList.remove('hidden-form');
        formPrice.classList.add('hidden-form');
        btnAdd.classList.add('active');
        btnPrice.classList.remove('active');
    }
}

// ─── Currency formatting ──────────────────────────────────────
function formatCurrencyAdd(input, hiddenId) {
    const value = input.value.replace(/\D/g, '');
    if (value) {
        input.value = 'IDR ' + new Intl.NumberFormat('id-ID').format(value);
        document.getElementById(hiddenId).value = value;
    } else {
        input.value = '';
        document.getElementById(hiddenId).value = '';
    }
}

function formatMultipleCurrency(input) {
    const value = input.value.replace(/\D/g, '');
    if (value) {
        input.value = 'IDR ' + new Intl.NumberFormat('id-ID').format(value);
        if (input.nextElementSibling) input.nextElementSibling.value = value;
    } else {
        input.value = '';
        if (input.nextElementSibling) input.nextElementSibling.value = '0';
    }
}

// ─── Feature tags ─────────────────────────────────────────────
const lenseFeatures = {}; // safeKey -> string[]

function initFeatureTags(safeKey, features) {
    lenseFeatures[safeKey] = Array.isArray(features) ? features.slice() : [];
    renderFeatureTags(safeKey);
}

function renderFeatureTags(safeKey) {
    const container = document.getElementById('tags-' + safeKey);
    if (!container) return;
    const features = lenseFeatures[safeKey] || [];
    if (features.length === 0) {
        container.innerHTML = '<span class="no-features-text">No features added yet</span>';
    } else {
        container.innerHTML = features.map((f, i) =>
            `<span class="feature-tag">
                <span class="feature-tag-dot"></span>
                ${escapeHtml(f)}
                <button type="button" class="btn-remove-tag"
                    onclick="removeFeatureTag('${safeKey}',${i})"
                    title="Remove">&#215;</button>
            </span>`
        ).join('');
    }
    syncFeaturesHidden(safeKey);
}

function removeFeatureTag(safeKey, index) {
    if (!lenseFeatures[safeKey]) return;
    lenseFeatures[safeKey].splice(index, 1);
    renderFeatureTags(safeKey);
}

function addFeatureTag(safeKey) {
    const input = document.getElementById('feat-input-' + safeKey);
    if (!input) return;
    const val = input.value.trim();
    if (!val) return;
    if (!lenseFeatures[safeKey]) lenseFeatures[safeKey] = [];
    // Support comma-separated bulk add
    const newFeats = val.split(',').map(t => t.trim()).filter(t => t.length > 0);
    lenseFeatures[safeKey].push(...newFeats);
    renderFeatureTags(safeKey);
    input.value = '';
    input.focus();
}

function handleFeatureKeydown(event, safeKey) {
    if (event.key === 'Enter') { event.preventDefault(); addFeatureTag(safeKey); }
}

function syncFeaturesHidden(safeKey) {
    const hidden = document.getElementById('feat-hidden-' + safeKey);
    if (hidden) hidden.value = (lenseFeatures[safeKey] || []).join(', ');
}

function escapeHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ─── Filter (Group + Category) ────────────────────────────────
const lenseData = <?php echo json_encode($data); ?>;

function updateCategoryFilter() {
    const groupSelect   = document.getElementById('filter-group');
    const catSelect     = document.getElementById('filter-category');
    const selectedGroup = groupSelect.value;
    document.getElementById('last_group').value = selectedGroup;
    catSelect.innerHTML = '';
    if (lenseData[selectedGroup]) {
        const lastCat = "<?php echo addslashes($selected_cat); ?>";
        Object.keys(lenseData[selectedGroup]).forEach(cat => {
            const opt = document.createElement('option');
            opt.value = cat; opt.textContent = cat;
            if (cat === lastCat) opt.selected = true;
            catSelect.appendChild(opt);
        });
    }
    filterLenses();
}

function filterLenses() {
    const selectedGroup = document.getElementById('filter-group').value;
    const selectedCat   = document.getElementById('filter-category').value;
    document.getElementById('last_category').value = selectedCat;
    document.querySelectorAll('.lense-group-wrapper').forEach(wrapper => {
        wrapper.style.display =
            (wrapper.dataset.group === selectedGroup && wrapper.dataset.category === selectedCat)
            ? 'block' : 'none';
    });
}

// ─── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    <?php if (isset($active_tab) && $active_tab === 'add'): ?>
        showTab('add');
    <?php else: ?>
        updateCategoryFilter();
    <?php endif; ?>

    // Format currency on existing inputs
    document.querySelectorAll('.currency-display').forEach(el => {
        if (el.value && !el.value.includes('IDR')) formatMultipleCurrency(el);
    });

    // Initialize feature tags from data-features attribute
    document.querySelectorAll('.features-tag-wrapper[data-safe-key]').forEach(el => {
        const key      = el.getAttribute('data-safe-key');
        const features = JSON.parse(el.getAttribute('data-features') || '[]');
        initFeatureTags(key, features);
    });

    // Init add-form tag container
    initFeatureTags('new_lense', []);
});
</script>
</body>
</html>