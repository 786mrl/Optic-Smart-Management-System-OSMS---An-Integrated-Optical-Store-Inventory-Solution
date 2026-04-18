<?php
    // lense_price.php
    session_start();

    include 'db_config.php';
    include 'config_helper.php';

    if ($_SESSION['role'] !== 'admin') {
        header("Location: index.php");
        exit();
    }

    $json_file = 'data_json/lense_prices.json';

    if (!file_exists($json_file)) {
        file_put_contents($json_file, json_encode(["stock" => ["Single Vision" => []], "lab" => []], JSON_PRETTY_PRINT));
    }

    $data = json_decode(file_get_contents($json_file), true);
    $message = '';

    // Default limits template
    $DEFAULT_LIMITS = ['sph_from'=>0,'sph_to'=>0,'cyl_from'=>0,'cyl_to'=>0,'add_from'=>0,'add_to'=>0,'comb_max'=>0,'note'=>''];

    // Helper: uppercase a string safely (trim + strtoupper)
    function uc_trim($s) { return strtoupper(trim((string)$s)); }

    // Migrate old data structure + uppercase category/lens/feature keys & values.
    // If collisions happen (e.g., "Single Vision" and "SINGLE VISION"), entries are merged.
    foreach ($data as $gk => $cats) {
        $new_cats = [];
        foreach ($cats as $ck => $lenses) {
            $ck_upper = uc_trim($ck);
            if ($ck_upper === '') $ck_upper = 'GENERAL';
            if (!isset($new_cats[$ck_upper])) $new_cats[$ck_upper] = [];
            foreach ($lenses as $ln => $val) {
                $ln_upper = uc_trim($ln);
                if ($ln_upper === '') continue;
                if (!is_array($val)) {
                    $entry = ['cost'=>(float)$val,'selling'=>0.0,'features'=>[],'limits'=>$DEFAULT_LIMITS];
                } else {
                    if (!isset($val['features']) || !is_array($val['features'])) $val['features'] = [];
                    if (!isset($val['limits'])   || !is_array($val['limits']))   $val['limits']   = $DEFAULT_LIMITS;
                    // Uppercase every feature string
                    $val['features'] = array_values(array_filter(array_map('uc_trim', $val['features']), function($x){ return $x !== ''; }));
                    $entry = $val;
                }
                $new_cats[$ck_upper][$ln_upper] = $entry;
            }
        }
        $data[$gk] = $new_cats;
    }

    // Format Rx value with sign
    function fmtRx($v) {
        $v = (float)$v;
        if ($v > 0) return '+'.number_format($v,2);
        return number_format($v,2);
    }

    // Detect if category uses ADD field (bifocal/progressive)
    function catHasAdd($cat) {
        $u = strtoupper(trim($cat));
        return strpos($u,'PROGRESS') !== false || strpos($u,'KRYPTOK') !== false ||
               strpos($u,'BIFOCAL')  !== false || strpos($u,'FLATTOP') !== false;
    }

    // Detect if category uses CYL field
    function catHasCyl($cat) {
        $u = strtoupper(trim($cat));
        return strpos($u,'KRYPTOK') === false && strpos($u,'BIFOCAL') === false && strpos($u,'FLATTOP') === false;
    }

    // ── POST handlers ──────────────────────────────────────────────────
    if ($_SERVER["REQUEST_METHOD"] == "POST") {

        if (isset($_POST['save_prices'])) {
            // Rebuild entire tree so category + lens-name keys become UPPERCASE.
            $new_data = [];
            foreach ($_POST['price_cost'] as $group => $categories) {
                if (!isset($new_data[$group])) $new_data[$group] = [];
                foreach ($categories as $category => $lenses) {
                    $cat_upper = uc_trim($category);
                    if ($cat_upper === '') $cat_upper = 'GENERAL';
                    if (!isset($new_data[$group][$cat_upper])) $new_data[$group][$cat_upper] = [];
                    foreach ($lenses as $old_name => $cost) {
                        $raw_new      = $_POST['price_name'][$group][$category][$old_name] ?? $old_name;
                        $new_name     = uc_trim($raw_new);
                        if ($new_name === '') $new_name = uc_trim($old_name);
                        $selling      = (float)($_POST['price_selling'][$group][$category][$old_name] ?? 0);
                        $features_raw = $_POST['price_features'][$group][$category][$old_name] ?? '';
                        // Split by comma, trim, UPPERCASE, drop empties
                        $features     = array_values(array_filter(
                            array_map('uc_trim', explode(',', $features_raw)),
                            function($x){ return $x !== ''; }
                        ));

                        $lp = $_POST['price_limits'][$group][$category][$old_name] ?? [];
                        $limits = [
                            'sph_from' => (float)($lp['sph_from'] ?? 0),
                            'sph_to'   => (float)($lp['sph_to']   ?? 0),
                            'cyl_from' => (float)($lp['cyl_from'] ?? 0),
                            'cyl_to'   => (float)($lp['cyl_to']   ?? 0),
                            'add_from' => (float)($lp['add_from'] ?? 0),
                            'add_to'   => (float)($lp['add_to']   ?? 0),
                            'comb_max' => (float)($lp['comb_max'] ?? 0),
                            'note'     => trim($lp['note'] ?? ''),
                        ];

                        $new_data[$group][$cat_upper][$new_name] = [
                            'cost'=>(float)$cost,'selling'=>$selling,
                            'features'=>$features,'limits'=>$limits,
                        ];
                    }
                }
            }
            // Preserve groups that might not have been posted (safety)
            foreach ($data as $gk => $cats) {
                if (!isset($new_data[$gk])) $new_data[$gk] = $cats;
            }
            $data = $new_data;
            $message = "All changes saved successfully.";

        } elseif (isset($_POST['add_new_lense'])) {
            $new_group    = $_POST['new_group'];
            $new_cat      = uc_trim($_POST['new_category'] ?? '');
            if ($new_cat === '') $new_cat = 'GENERAL';
            $new_name     = uc_trim($_POST['new_lense_name'] ?? '');
            $new_cost     = (float)$_POST['new_lense_price_cost'];
            $new_selling  = (float)$_POST['new_lense_price_selling'];
            $features_raw = $_POST['new_lense_features'] ?? '';
            $new_features = array_values(array_filter(
                array_map('uc_trim', explode(',', $features_raw)),
                function($x){ return $x !== ''; }
            ));

            $lp = $_POST['new_limits'] ?? [];
            $new_limits = [
                'sph_from' => (float)($lp['sph_from'] ?? 0),
                'sph_to'   => (float)($lp['sph_to']   ?? 0),
                'cyl_from' => (float)($lp['cyl_from'] ?? 0),
                'cyl_to'   => (float)($lp['cyl_to']   ?? 0),
                'add_from' => (float)($lp['add_from'] ?? 0),
                'add_to'   => (float)($lp['add_to']   ?? 0),
                'comb_max' => (float)($lp['comb_max'] ?? 0),
                'note'     => trim($lp['note'] ?? ''),
            ];

            if (!empty($new_name)) {
                $data[$new_group][$new_cat][$new_name] = [
                    'cost'=>$new_cost,'selling'=>$new_selling,
                    'features'=>$new_features,'limits'=>$new_limits,
                ];
                $message    = "Lens \"".htmlspecialchars($new_name)."\" added successfully.";
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
            /* ── Layout ─────────────────────────────────────────────── */
            .config-window { margin:0 auto; width:100%; max-width:100%; }
            .tab-navigation { display:flex; justify-content:center; gap:16px; margin-bottom:28px; }
            .btn-group { margin-top:28px; width:100%; display:flex; justify-content:center; }
            .back-main { width:100%; max-width:400px; }
            .hidden-form { display:none !important; }
            .page-header { text-align:center; margin-bottom:24px; }
            .page-header h2 { margin:0 0 4px; font-size:18px; }
            .page-header p  { margin:0; color:#6b7280; font-size:12px; }

            /* ── Tab buttons ─────────────────────────────────────────── */
            .btn-neumorph {
                padding:11px 24px; border:none; border-radius:12px;
                background:#2a2d32; color:#b0b3b8; font-weight:600; font-size:13px;
                cursor:pointer; transition:all .25s;
                box-shadow:5px 5px 10px #1d1f23,-5px -5px 10px #373b41;
            }
            .btn-neumorph:hover  { color:#00adb5; }
            .btn-neumorph.active { box-shadow:inset 4px 4px 8px #1d1f23,inset -4px -4px 8px #373b41; color:#00adb5; }

            /* ── Filter bar ──────────────────────────────────────────── */
            .filter-bar {
                background:#252830; border:1px solid #33363d; border-radius:12px;
                padding:16px 20px; display:flex; gap:16px; align-items:flex-end; margin-bottom:20px;
            }
            .filter-bar > div { flex:1; }
            .filter-bar label { display:block; font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.6px; margin-bottom:6px; }

            /* ── Lens group ──────────────────────────────────────────── */
            .lense-group-wrapper { width:100%; margin-bottom:8px; }
            .lense-details summary {
                list-style:none; cursor:pointer; outline:none;
                display:flex; align-items:center; justify-content:space-between;
                padding:12px 16px; background:#1e2127; border:1px solid #2e3138;
                border-radius:10px; color:#c9cdd4; font-size:13px; font-weight:600;
                transition:all .2s; user-select:none;
            }
            .lense-details summary::-webkit-details-marker { display:none; }
            .lense-details summary:hover { border-color:#00adb5; color:#00adb5; }
            .lense-details[open] summary {
                color:#00adb5; border-color:#00adb5;
                border-bottom-left-radius:0; border-bottom-right-radius:0; border-bottom-color:transparent;
            }
            .summary-arrow { font-size:10px; transition:transform .25s; color:#4b5563; }
            .lense-details[open] .summary-arrow { transform:rotate(180deg); color:#00adb5; }
            .lense-panel {
                border:1px solid #00adb5; border-top:none;
                border-bottom-left-radius:10px; border-bottom-right-radius:10px;
                overflow:hidden;
                background:#181a1f; /* darker than card → card borders pop */
                padding:10px 10px 12px;
                animation:slideDown .2s ease-out;
            }
            @keyframes slideDown { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }

            /* ── Lens card ───────────────────────────────────────────── */
            .lens-card {
                background:#262932;
                border:1px solid #343842;
                border-radius:10px;
                padding:16px 18px;
                margin:10px 4px;
                box-shadow:0 2px 4px rgba(0,0,0,0.25);
                position:relative;
                transition:border-color .2s, box-shadow .2s;
            }
            .lens-card:hover { border-color:#4b5563; box-shadow:0 3px 8px rgba(0,0,0,0.35); }
            .lens-card:first-child { margin-top:4px; }
            .lens-card:last-child  { margin-bottom:4px; }
            .lens-card-index {
                position:absolute; top:-9px; left:14px;
                background:#00adb5; color:#0f1115;
                font-size:9px; font-weight:800; letter-spacing:.8px;
                padding:2px 8px; border-radius:10px;
                text-transform:uppercase;
            }

            .lens-name-row { display:flex; align-items:center; gap:8px; margin-bottom:14px; }
            .lens-name-icon { color:#4b5563; font-size:12px; flex-shrink:0; }
            .lens-name-input {
                flex:1; background:transparent; border:none; border-bottom:1px dashed #3a3d44;
                border-radius:0; color:#e5e7eb; font-size:14px; font-weight:700;
                padding:4px 2px; outline:none; transition:border-color .2s;
            }
            .lens-name-input:focus { border-bottom-color:#00adb5; color:#fff; }
            .lens-name-badge { font-size:10px; color:#374151; font-style:italic; white-space:nowrap; }

            /* Force UPPERCASE display for text inputs that must store uppercase */
            .uppercase-input { text-transform:uppercase; }
            .uppercase-input::placeholder { text-transform:none; letter-spacing:normal; }

            .lens-prices-row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
            .price-col { flex:1; min-width:160px; }
            .price-col label { display:block; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px; }
            .price-col.cost-col label { color:#6b7280; }
            .price-col.sell-col label { color:#00adb5; }

            /* ── Feature tags ────────────────────────────────────────── */
            .lens-section-label { font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#6b7280; margin-bottom:8px; }
            .features-tag-wrapper { display:flex; flex-wrap:wrap; gap:6px; min-height:24px; }
            .feature-tag { display:inline-flex; align-items:center; gap:5px; background:#162829; color:#2dd4bf; border:1px solid #1e4a4d; border-radius:20px; padding:3px 8px 3px 10px; font-size:11px; font-weight:600; }
            .feature-tag-dot { width:4px; height:4px; border-radius:50%; background:#2dd4bf; flex-shrink:0; }
            .btn-remove-tag { display:inline-flex; align-items:center; justify-content:center; background:none; border:none; color:#4b7a7c; font-size:14px; line-height:1; cursor:pointer; padding:0; width:14px; height:14px; border-radius:50%; transition:color .15s; }
            .btn-remove-tag:hover { color:#f87171; }
            .no-features-text { font-size:11px; color:#3a3d44; font-style:italic; }
            .feature-add-row { display:flex; gap:8px; margin-top:10px; align-items:center; }
            .feature-add-input { flex:1; background:#1a1d22 !important; border:1px solid #2e3138 !important; border-radius:8px !important; color:#c9cdd4 !important; font-size:12px !important; padding:7px 11px !important; outline:none !important; min-width:0; }
            .feature-add-input:focus { border-color:#00adb5 !important; }
            .feature-add-input::placeholder { color:#3d4149 !important; }
            .btn-add-feature { background:#1a3a3c; color:#00adb5; border:1px solid #1e4a4d; border-radius:8px; padding:7px 14px; font-size:12px; font-weight:600; cursor:pointer; white-space:nowrap; flex-shrink:0; transition:all .2s; }
            .btn-add-feature:hover { background:#00adb5; color:#fff; border-color:#00adb5; }

            /* ── Divider between sections inside card ────────────────── */
            .card-divider { border:none; border-top:1px solid #2e3138; margin:14px 0; }

            /* ── Rx Limits (collapsible inside card) ─────────────────── */
            .rx-limits-details summary {
                list-style:none; cursor:pointer; outline:none;
                display:flex; align-items:center; justify-content:space-between;
                padding:8px 10px;
                background:#1c1f25;
                border:1px solid #2a2d34;
                border-radius:8px;
                color:#6b7280;
                font-size:11px; font-weight:700;
                text-transform:uppercase; letter-spacing:.6px;
                user-select:none; transition:all .2s;
            }
            .rx-limits-details summary::-webkit-details-marker { display:none; }
            .rx-limits-details summary:hover { border-color:#374151; color:#9ca3af; }
            .rx-limits-details[open] summary { border-color:#374151; color:#9ca3af; border-bottom-left-radius:0; border-bottom-right-radius:0; border-bottom-color:transparent; }

            .rx-limits-arrow { font-size:9px; transition:transform .2s; }
            .rx-limits-details[open] .rx-limits-arrow { transform:rotate(180deg); }

            .rx-limits-body {
                border:1px solid #2a2d34; border-top:none;
                border-bottom-left-radius:8px; border-bottom-right-radius:8px;
                padding:14px; background:#1c1f25;
                display:flex; flex-direction:column; gap:12px;
            }

            /* Rx field groups */
            .rx-group { display:flex; flex-direction:column; gap:4px; }
            .rx-group-label {
                font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
            }
            .rx-group-label.sph  { color:#60a5fa; }
            .rx-group-label.cyl  { color:#a78bfa; }
            .rx-group-label.add  { color:#34d399; }
            .rx-group-label.comb { color:#fb923c; }
            .rx-group-label.note { color:#f59e0b; }

            .rx-row { display:flex; gap:8px; align-items:flex-end; }
            .rx-subfield { flex:1; }
            .rx-subfield label { display:block; font-size:9px; color:#4b5563; margin-bottom:4px; text-transform:uppercase; letter-spacing:.3px; }
            .rx-input {
                width:100%; background:#13151a; border:1px solid #2a2d34;
                border-radius:6px; color:#d1d5db; font-size:13px; font-weight:600;
                padding:7px 8px; outline:none; text-align:center;
                transition:border-color .2s; box-sizing:border-box;
            }
            .rx-input:focus { border-color:#00adb5; }
            .rx-arrow { font-size:11px; color:#374151; padding-bottom:7px; flex-shrink:0; }

            .rx-input-full { width:100%; background:#13151a; border:1px solid #2a2d34; border-radius:6px; color:#9ca3af; font-size:12px; padding:7px 10px; outline:none; resize:none; box-sizing:border-box; transition:border-color .2s; line-height:1.5; }
            .rx-input-full:focus { border-color:#00adb5; }

            .rx-hint { font-size:10px; color:#374151; font-style:italic; margin-top:2px; }

            /* ── Add lens form ───────────────────────────────────────── */
            #form-add-lense { display:none; width:100%; flex-direction:column; align-items:center; padding:20px 0; }
            #form-add-lense:not(.hidden-form) { display:flex !important; }
            .add-form-card { background:#23262d; border:1px solid #2e3138; border-radius:14px; padding:24px; width:100%; max-width:580px; }
            .add-form-title { font-size:13px; font-weight:700; color:#00adb5; text-transform:uppercase; letter-spacing:1px; margin-bottom:20px; text-align:center; }
            .add-form-grid { display:flex; flex-direction:column; gap:14px; }
            .form-field label { display:block; font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; }

            /* ── Save bar ────────────────────────────────────────────── */
            .save-bar { margin-top:20px; padding:14px 20px; background:#1e2127; border:1px solid #2e3138; border-radius:12px; display:flex; justify-content:flex-end; }
            #form-price-list { width:100%; display:flex; flex-direction:column; align-items:center; }

            /* ── Mobile ──────────────────────────────────────────────── */
            @media (max-width:600px) {
                .config-window   { padding:0 8px; box-sizing:border-box; }
                .content-area    { padding:5px !important; width:100% !important; }
                .tab-navigation  { gap:8px; width:100%; }
                .btn-neumorph    { flex:1; padding:11px 6px; font-size:12px; }
                .filter-bar      { flex-direction:column; }
                .lens-prices-row { flex-direction:column; }
                .add-form-card   { padding:16px; }
                .rx-row          { flex-wrap:wrap; }
            }
        </style>
    </head>

    <body>
        <div class="main-wrapper">
            <div class="content-area" style="flex-direction:column;">

                <!-- Header -->
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

                    <div class="page-header">
                        <h2>Lens Price Settings</h2>
                        <p>Manage pricing, features, and prescription limits per lens</p>
                    </div>

                    <?php if ($message): ?>
                    <div style="background:#065f46;color:#6ee7b7;border:1px solid #047857;padding:10px 16px;border-radius:8px;margin-bottom:16px;text-align:center;font-size:13px;">
                        <?php echo $message; ?>
                    </div>
                    <?php endif; ?>

                    <div class="tab-navigation">
                        <button type="button" id="btn-price" class="btn-neumorph active" onclick="showTab('price')">&#9776;&ensp;Price List</button>
                        <button type="button" id="btn-add"   class="btn-neumorph"        onclick="showTab('add')">&#43;&ensp;Add New Lens</button>
                    </div>

                    <!-- ════════════════════════════════════
                        ADD NEW LENS
                    ════════════════════════════════════ -->
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
                                    <input type="text" class="input-field uppercase-input" name="new_category"
                                        oninput="this.value=this.value.toUpperCase()"
                                        placeholder="e.g. SINGLE VISION">
                                </div>
                                <div class="form-field">
                                    <label>Lens Name</label>
                                    <input type="text" name="new_lense_name" class="input-field uppercase-input"
                                        oninput="this.value=this.value.toUpperCase()"
                                        placeholder="e.g. SV-CRMC" required>
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

                                <!-- Features -->
                                <div class="form-field">
                                    <label>Features</label>
                                    <div class="features-tag-wrapper" id="tags-new_lense" style="margin-bottom:8px;min-height:20px;"></div>
                                    <div class="feature-add-row">
                                        <input type="text" id="feat-input-new_lense" class="feature-add-input uppercase-input"
                                            placeholder="Type features, separate with comma (e.g. UV, ANTI-GLARE)"
                                            onkeydown="handleFeatureKeydown(event,'new_lense')"
                                            oninput="handleFeatureInput(this,'new_lense')">
                                        <button type="button" class="btn-add-feature" onclick="addFeatureTag('new_lense')">+ Add</button>
                                    </div>
                                    <input type="hidden" name="new_lense_features" id="feat-hidden-new_lense">
                                </div>

                                <!-- Rx Limits for new lens (all fields shown) -->
                                <div class="form-field" style="margin-top:4px;">
                                    <label style="margin-bottom:10px;">Rx Limits</label>
                                    <div style="display:flex;flex-direction:column;gap:10px;background:#1c1f25;border:1px solid #2a2d34;border-radius:8px;padding:14px;">

                                        <div class="rx-group">
                                            <div class="rx-group-label sph">SPH — Sphere</div>
                                            <div class="rx-row">
                                                <div class="rx-subfield"><label>From</label><input type="number" step="0.25" class="rx-input" name="new_limits[sph_from]" value="0.00" placeholder="0.00"></div>
                                                <div class="rx-arrow">→</div>
                                                <div class="rx-subfield"><label>To</label><input type="number" step="0.25" class="rx-input" name="new_limits[sph_to]" value="0.00" placeholder="0.00"></div>
                                            </div>
                                        </div>

                                        <div class="rx-group">
                                            <div class="rx-group-label cyl">CYL — Cylinder</div>
                                            <div class="rx-row">
                                                <div class="rx-subfield"><label>From</label><input type="number" step="0.25" class="rx-input" name="new_limits[cyl_from]" value="0.00" placeholder="0.00"></div>
                                                <div class="rx-arrow">→</div>
                                                <div class="rx-subfield"><label>To</label><input type="number" step="0.25" class="rx-input" name="new_limits[cyl_to]" value="0.00" placeholder="0.00"></div>
                                            </div>
                                        </div>

                                        <div class="rx-group">
                                            <div class="rx-group-label add">ADD — Reading Addition</div>
                                            <div class="rx-row">
                                                <div class="rx-subfield"><label>From</label><input type="number" step="0.25" class="rx-input" name="new_limits[add_from]" value="0.00" placeholder="0.00"></div>
                                                <div class="rx-arrow">→</div>
                                                <div class="rx-subfield"><label>To</label><input type="number" step="0.25" class="rx-input" name="new_limits[add_to]" value="0.00" placeholder="0.00"></div>
                                            </div>
                                        </div>

                                        <div class="rx-group">
                                            <div class="rx-group-label comb">COMB — Max Combination</div>
                                            <input type="number" step="0.25" class="rx-input" style="max-width:130px;" name="new_limits[comb_max]" value="0.00" placeholder="0.00">
                                            <div class="rx-hint">|SPH| + |CYL| limit. Leave 0 if not applicable.</div>
                                        </div>

                                        <div class="rx-group">
                                            <div class="rx-group-label note">&#9888; Note / Condition</div>
                                            <textarea class="rx-input-full" name="new_limits[note]" rows="2" placeholder="e.g. Reading power must not exceed distance SPH..."></textarea>
                                        </div>

                                    </div>
                                </div>

                            </div>
                            <div style="margin-top:20px;">
                                <button type="submit" name="add_new_lense" class="btn-save" style="width:100%;">Add Lens</button>
                            </div>
                        </div>
                    </form>

                    <!-- ════════════════════════════════════
                        PRICE LIST
                    ════════════════════════════════════ -->
                    <form id="form-price-list" action="lense_price.php" method="POST">
                        <input type="hidden" name="last_group"    id="last_group"    value="<?php echo htmlspecialchars($selected_group); ?>">
                        <input type="hidden" name="last_category" id="last_category" value="<?php echo htmlspecialchars($selected_cat); ?>">

                        <div class="filter-bar">
                            <div>
                                <label>Group</label>
                                <select id="filter-group" class="input-field" onchange="updateCategoryFilter()">
                                    <?php foreach (array_keys($data) as $g): ?>
                                        <option value="<?php echo $g; ?>"><?php echo ucfirst($g); ?> Lenses</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Category</label>
                                <select id="filter-category" class="input-field" onchange="filterLenses()"></select>
                            </div>
                        </div>

                        <div id="lense-display-container" style="width:100%;">
                        <?php
                            $lens_counter = 0;
                            foreach ($data as $group_key => $categories):
                                foreach ($categories as $cat_name => $lenses):
                                    $has_add  = catHasAdd($cat_name);
                                    $has_cyl  = catHasCyl($cat_name);
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
                                            <?php echo count($lenses); ?> lens<?php echo count($lenses)!==1?'es':''; ?>
                                        </span>
                                    </span>
                                    <span class="summary-arrow">&#9660;</span>
                                </summary>

                                <div class="lense-panel">
                                <?php $card_index = 0; foreach ($lenses as $name => $prices):
                                    $lens_counter++;
                                    $card_index++;
                                    $sk       = 'lens_'.$lens_counter;
                                    $cost     = is_array($prices) ? ($prices['cost']     ?? 0)  : (float)$prices;
                                    $selling  = is_array($prices) ? ($prices['selling']  ?? 0)  : 0.0;
                                    $features = is_array($prices) ? ($prices['features'] ?? []) : [];
                                    $lim      = is_array($prices) ? ($prices['limits']   ?? $DEFAULT_LIMITS) : $DEFAULT_LIMITS;
                                    $lim      = array_merge($DEFAULT_LIMITS, $lim); // fill any missing keys
                                ?>
                                <div class="lens-card">
                                    <span class="lens-card-index">#<?php echo str_pad($card_index, 2, '0', STR_PAD_LEFT); ?></span>

                                    <!-- Name -->
                                    <div class="lens-name-row">
                                        <span class="lens-name-icon">&#9998;</span>
                                        <input type="text" class="lens-name-input uppercase-input"
                                            oninput="this.value=this.value.toUpperCase()"
                                            name="price_name[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>]"
                                            value="<?php echo htmlspecialchars($name);?>" title="Click to rename">
                                        <span class="lens-name-badge">click to rename</span>
                                    </div>

                                    <!-- Prices -->
                                    <div class="lens-prices-row">
                                        <div class="price-col cost-col">
                                            <label>Cost Price</label>
                                            <input type="text" class="input-field currency-display"
                                                value="IDR <?php echo number_format($cost,0,',','.');?>"
                                                oninput="formatMultipleCurrency(this)" onfocus="this.select()" autocomplete="off">
                                            <input type="hidden" name="price_cost[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>]" value="<?php echo $cost?:0;?>">
                                        </div>
                                        <div class="price-col sell-col">
                                            <label>Selling Price</label>
                                            <input type="text" class="input-field currency-display"
                                                value="IDR <?php echo number_format($selling,0,',','.');?>"
                                                oninput="formatMultipleCurrency(this)" onfocus="this.select()" autocomplete="off">
                                            <input type="hidden" name="price_selling[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>]" value="<?php echo $selling?:0;?>">
                                        </div>
                                    </div>

                                    <hr class="card-divider">

                                    <!-- Features -->
                                    <div class="lens-section-label">Features</div>
                                    <div class="features-tag-wrapper"
                                        id="tags-<?php echo $sk;?>"
                                        data-safe-key="<?php echo $sk;?>"
                                        data-features='<?php echo htmlspecialchars(json_encode($features),ENT_QUOTES);?>'>
                                    </div>
                                    <div class="feature-add-row">
                                        <input type="text" id="feat-input-<?php echo $sk;?>" class="feature-add-input uppercase-input"
                                            placeholder="Type features, separate with comma (e.g. UV, ANTI-GLARE)"
                                            onkeydown="handleFeatureKeydown(event,'<?php echo $sk;?>')"
                                            oninput="handleFeatureInput(this,'<?php echo $sk;?>')">
                                        <button type="button" class="btn-add-feature" onclick="addFeatureTag('<?php echo $sk;?>')">+ Add</button>
                                    </div>
                                    <input type="hidden" id="feat-hidden-<?php echo $sk;?>"
                                        name="price_features[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>]"
                                        value="<?php echo htmlspecialchars(implode(', ',$features));?>"
                                        class="features-hidden-input">

                                    <hr class="card-divider">

                                    <!-- Rx Limits (collapsible) -->
                                    <details class="rx-limits-details">
                                        <summary>
                                            <span>
                                                &#9655;&ensp;Rx Limits
                                                <?php
                                                    // Build a quick preview string
                                                    $preview = [];
                                                    if ($lim['sph_from'] != 0 || $lim['sph_to'] != 0)
                                                        $preview[] = 'SPH '.fmtRx($lim['sph_from']).' ~ '.fmtRx($lim['sph_to']);
                                                    if ($has_cyl && ($lim['cyl_from'] != 0 || $lim['cyl_to'] != 0))
                                                        $preview[] = 'CYL '.fmtRx($lim['cyl_from']).' ~ '.fmtRx($lim['cyl_to']);
                                                    if ($has_add && ($lim['add_from'] != 0 || $lim['add_to'] != 0))
                                                        $preview[] = 'ADD '.fmtRx($lim['add_from']).' ~ '.fmtRx($lim['add_to']);
                                                    if ($lim['comb_max'] != 0)
                                                        $preview[] = 'COMB '.fmtRx($lim['comb_max']);
                                                    if (!empty($preview)):
                                                ?>
                                                <span style="font-size:10px;color:#4b5563;font-weight:400;margin-left:8px;font-style:italic;text-transform:none;letter-spacing:0;">
                                                    <?php echo implode(' &nbsp;|&nbsp; ', $preview); ?>
                                                </span>
                                                <?php else: ?>
                                                <span style="font-size:10px;color:#374151;font-weight:400;margin-left:8px;font-style:italic;text-transform:none;letter-spacing:0;">not set</span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="rx-limits-arrow">&#9660;</span>
                                        </summary>
                                        <div class="rx-limits-body">

                                            <!-- SPH -->
                                            <div class="rx-group">
                                                <div class="rx-group-label sph">SPH — Sphere</div>
                                                <div class="rx-row">
                                                    <div class="rx-subfield">
                                                        <label>From</label>
                                                        <input type="number" step="0.25" class="rx-input"
                                                            name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][sph_from]"
                                                            value="<?php echo fmtRx($lim['sph_from']);?>">
                                                    </div>
                                                    <div class="rx-arrow">→</div>
                                                    <div class="rx-subfield">
                                                        <label>To</label>
                                                        <input type="number" step="0.25" class="rx-input"
                                                            name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][sph_to]"
                                                            value="<?php echo fmtRx($lim['sph_to']);?>">
                                                    </div>
                                                </div>
                                            </div>

                                            <?php if ($has_cyl): ?>
                                            <!-- CYL -->
                                            <div class="rx-group">
                                                <div class="rx-group-label cyl">CYL — Cylinder</div>
                                                <div class="rx-row">
                                                    <div class="rx-subfield">
                                                        <label>From</label>
                                                        <input type="number" step="0.25" class="rx-input"
                                                            name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][cyl_from]"
                                                            value="<?php echo fmtRx($lim['cyl_from']);?>">
                                                    </div>
                                                    <div class="rx-arrow">→</div>
                                                    <div class="rx-subfield">
                                                        <label>To</label>
                                                        <input type="number" step="0.25" class="rx-input"
                                                            name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][cyl_to]"
                                                            value="<?php echo fmtRx($lim['cyl_to']);?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <!-- Hidden CYL (preserve zeros) -->
                                            <input type="hidden" name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][cyl_from]" value="0">
                                            <input type="hidden" name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][cyl_to]"   value="0">
                                            <?php endif; ?>

                                            <?php if ($has_add): ?>
                                            <!-- ADD -->
                                            <div class="rx-group">
                                                <div class="rx-group-label add">ADD — Reading Addition</div>
                                                <div class="rx-row">
                                                    <div class="rx-subfield">
                                                        <label>From</label>
                                                        <input type="number" step="0.25" class="rx-input"
                                                            name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][add_from]"
                                                            value="<?php echo fmtRx($lim['add_from']);?>">
                                                    </div>
                                                    <div class="rx-arrow">→</div>
                                                    <div class="rx-subfield">
                                                        <label>To</label>
                                                        <input type="number" step="0.25" class="rx-input"
                                                            name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][add_to]"
                                                            value="<?php echo fmtRx($lim['add_to']);?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <input type="hidden" name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][add_from]" value="0">
                                            <input type="hidden" name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][add_to]"   value="0">
                                            <?php endif; ?>

                                            <!-- COMB -->
                                            <div class="rx-group">
                                                <div class="rx-group-label comb">COMB — Max Combination</div>
                                                <input type="number" step="0.25" class="rx-input" style="max-width:130px;"
                                                    name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][comb_max]"
                                                    value="<?php echo fmtRx($lim['comb_max']);?>">
                                                <div class="rx-hint">|SPH| + |CYL| limit. Leave 0 if not applicable.</div>
                                            </div>

                                            <!-- Note -->
                                            <div class="rx-group">
                                                <div class="rx-group-label note">&#9888; Note / Condition</div>
                                                <textarea class="rx-input-full" rows="2"
                                                    name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][note]"
                                                    placeholder="e.g. Reading power must not exceed distance SPH..."><?php echo htmlspecialchars($lim['note']??'');?></textarea>
                                            </div>

                                        </div><!-- /.rx-limits-body -->
                                    </details>

                                </div><!-- /.lens-card -->
                                <?php endforeach; ?>
                                </div><!-- /.lense-panel -->
                            </details>
                        </div>
                        <?php endforeach; endforeach; ?>
                        </div><!-- /#lense-display-container -->

                        <div class="save-bar">
                            <button type="submit" name="save_prices" class="btn-save" style="min-width:180px;">Save All Changes</button>
                        </div>
                    </form>

                    <div class="btn-group">
                        <button type="button" class="back-main" onclick="window.location.href='inventory.php'">&larr; Back to Previous Page</button>
                    </div>

                </div><!-- /.config-window -->
            </div><!-- /.content-area -->

            <footer class="footer-container">
                <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
            </footer>
        </div><!-- /.main-wrapper -->

        <script>
            // ─── Tabs ─────────────────────────────────────────────────────
            function showTab(tabName) {
                ['price','add'].forEach(k => {
                    document.getElementById('form-'+k+'-'+(k==='price'?'list':'lense')).classList.add('hidden-form');
                    document.getElementById('btn-'+k).classList.remove('active');
                });
                const formId = tabName === 'price' ? 'form-price-list' : 'form-add-lense';
                document.getElementById(formId).classList.remove('hidden-form');
                document.getElementById('btn-'+tabName).classList.add('active');
            }

            // ─── Currency ────────────────────────────────────────────────
            function formatCurrencyAdd(input, hiddenId) {
                const v = input.value.replace(/\D/g,'');
                if (v) { input.value = 'IDR ' + new Intl.NumberFormat('id-ID').format(v); document.getElementById(hiddenId).value = v; }
                else   { input.value = ''; document.getElementById(hiddenId).value = ''; }
            }
            function formatMultipleCurrency(input) {
                const v = input.value.replace(/\D/g,'');
                if (v) { input.value = 'IDR ' + new Intl.NumberFormat('id-ID').format(v); if (input.nextElementSibling) input.nextElementSibling.value = v; }
                else   { input.value = ''; if (input.nextElementSibling) input.nextElementSibling.value = '0'; }
            }

            // ─── Feature tags ─────────────────────────────────────────────
            const lenseFeatures = {};
            function initFeatureTags(k, f) {
                // Normalize existing features to UPPERCASE on load
                lenseFeatures[k] = Array.isArray(f) ? f.map(t=>String(t).trim().toUpperCase()).filter(t=>t) : [];
                renderFeatureTags(k);
            }
            function renderFeatureTags(k) {
                const c = document.getElementById('tags-'+k); if(!c) return;
                const f = lenseFeatures[k]||[];
                c.innerHTML = f.length===0
                    ? '<span class="no-features-text">No features added yet</span>'
                    : f.map((t,i)=>`<span class="feature-tag"><span class="feature-tag-dot"></span>${escapeHtml(t)}<button type="button" class="btn-remove-tag" onclick="removeFeatureTag('${k}',${i})" title="Remove">&#215;</button></span>`).join('');
                syncFeaturesHidden(k);
            }
            function removeFeatureTag(k,i) { if(lenseFeatures[k]) { lenseFeatures[k].splice(i,1); renderFeatureTags(k); } }
            function addFeatureTag(k) {
                const inp = document.getElementById('feat-input-'+k); if(!inp) return;
                const v = inp.value.trim(); if(!v) return;
                if(!lenseFeatures[k]) lenseFeatures[k]=[];
                v.split(',').map(t=>t.trim().toUpperCase()).filter(t=>t).forEach(t=>{
                    // Avoid duplicates (case-insensitive since everything is uppercased)
                    if (!lenseFeatures[k].includes(t)) lenseFeatures[k].push(t);
                });
                renderFeatureTags(k); inp.value=''; inp.focus();
            }
            // Auto-split when user types a comma — no need to click +Add
            function handleFeatureInput(inp, k) {
                // Force uppercase as user types
                inp.value = inp.value.toUpperCase();
                if (inp.value.indexOf(',') !== -1) {
                    const parts = inp.value.split(',');
                    const tail  = parts.pop();                 // text after the last comma stays in input
                    const toAdd = parts.map(t=>t.trim()).filter(t=>t);
                    if (toAdd.length) {
                        if(!lenseFeatures[k]) lenseFeatures[k]=[];
                        toAdd.forEach(t=>{
                            if (!lenseFeatures[k].includes(t)) lenseFeatures[k].push(t);
                        });
                        renderFeatureTags(k);
                    }
                    inp.value = tail.trim();                   // keep the unfinished tail
                }
            }
            function handleFeatureKeydown(e,k) { if(e.key==='Enter'){e.preventDefault();addFeatureTag(k);} }
            function syncFeaturesHidden(k) { const h=document.getElementById('feat-hidden-'+k); if(h) h.value=(lenseFeatures[k]||[]).join(', '); }
            function escapeHtml(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

            // ─── Filter ───────────────────────────────────────────────────
            const lenseData = <?php echo json_encode($data); ?>;
            function updateCategoryFilter() {
                const gs = document.getElementById('filter-group');
                const cs = document.getElementById('filter-category');
                const g  = gs.value;
                document.getElementById('last_group').value = g;
                cs.innerHTML = '';
                if (lenseData[g]) {
                    const last = "<?php echo addslashes($selected_cat); ?>";
                    Object.keys(lenseData[g]).forEach(cat => {
                        const o = document.createElement('option');
                        o.value = cat; o.textContent = cat;
                        if (cat===last) o.selected=true;
                        cs.appendChild(o);
                    });
                }
                filterLenses();
            }
            function filterLenses() {
                const g = document.getElementById('filter-group').value;
                const c = document.getElementById('filter-category').value;
                document.getElementById('last_category').value = c;
                document.querySelectorAll('.lense-group-wrapper').forEach(w => {
                    w.style.display = (w.dataset.group===g && w.dataset.category===c) ? 'block' : 'none';
                });
            }

            // ─── Init ─────────────────────────────────────────────────────
            document.addEventListener('DOMContentLoaded', () => {
                <?php if (isset($active_tab) && $active_tab==='add'): ?>showTab('add');<?php else: ?>updateCategoryFilter();<?php endif; ?>
                document.querySelectorAll('.currency-display').forEach(el => { if(el.value&&!el.value.includes('IDR')) formatMultipleCurrency(el); });
                document.querySelectorAll('.features-tag-wrapper[data-safe-key]').forEach(el => {
                    initFeatureTags(el.getAttribute('data-safe-key'), JSON.parse(el.getAttribute('data-features')||'[]'));
                });
                initFeatureTags('new_lense',[]);
            });
        </script>
    </body>
</html>