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

    // Format Rx value with sign (integer format, value × 100; e.g. -25 = -0.25)
    function fmtRx($v) {
        $v = (int)round((float)$v);
        if ($v > 0) return '+'.$v;
        return (string)$v;
    }

    // Detect if category uses ADD field (bifocal/progressive)
    function catHasAdd($cat) {
        $u = strtoupper(trim($cat));
        return strpos($u,'PROGRESS') !== false || strpos($u,'KRYPTOK') !== false ||
               strpos($u,'BIFOCAL')  !== false || strpos($u,'FLATTOP') !== false;
    }

    // Detect if category uses CYL field
    // Rules:
    //   - LAB group: always has CYL (all categories).
    //   - STOCK group: only Single Vision has CYL.
    //   - Other/unknown groups: Kryptok/Bifocal/Flattop excluded (legacy behavior).
    function catHasCyl($cat, $group = '') {
        $u = strtoupper(trim($cat));
        if ($group === 'lab')   return true;
        if ($group === 'stock') return ($u === 'SINGLE VISION' || $u === 'SV');
        return strpos($u,'KRYPTOK') === false
            && strpos($u,'BIFOCAL') === false
            && strpos($u,'FLATTOP') === false;
    }

    // ── POST handlers ──────────────────────────────────────────────────
    if ($_SERVER["REQUEST_METHOD"] == "POST") {

        // ── AJAX: save ONE lens at a time (per-lens save) ────────────────
        // Avoids the "Save All" lost-update problem where a stale form would
        // overwrite other lenses that had been edited in another tab.
        // Expects POST keys: save_single_lens=1, group, old_category, old_name,
        // new_name, cost, selling, features (comma-separated), limits[*].
        // Responds with JSON; does NOT render the page.
        if (isset($_POST['save_single_lens'])) {
            header('Content-Type: application/json; charset=utf-8');
            $resp = ['ok' => false, 'message' => '', 'saved_name' => '', 'limits' => [], 'renamed' => false];

            $group    = $_POST['group']        ?? '';
            $old_cat  = uc_trim($_POST['old_category'] ?? '');
            $old_name = uc_trim($_POST['old_name']     ?? '');
            $new_cat  = uc_trim($_POST['new_category'] ?? $old_cat);
            if ($new_cat === '') $new_cat = $old_cat;
            $raw_new  = $_POST['new_name'] ?? $old_name;
            $new_name = uc_trim($raw_new);
            if ($new_name === '') $new_name = $old_name;

            if ($group === '' || $old_cat === '' || $old_name === '') {
                $resp['message'] = 'Invalid request: missing group / category / name.';
                echo json_encode($resp); exit;
            }
            if (!isset($data[$group][$old_cat][$old_name])) {
                $resp['message'] = 'Lens not found on server. Please refresh the page.';
                echo json_encode($resp); exit;
            }

            $cost     = (float)($_POST['cost']    ?? 0);
            $selling  = (float)($_POST['selling'] ?? 0);
            $features = array_values(array_filter(
                array_map('uc_trim', explode(',', $_POST['features'] ?? '')),
                function($x){ return $x !== ''; }
            ));

            $lp = $_POST['limits'] ?? [];
            $limits = [
                'sph_from' => (int)round((float)($lp['sph_from'] ?? 0)),
                'sph_to'   => (int)round((float)($lp['sph_to']   ?? 0)),
                'cyl_from' => (int)round((float)($lp['cyl_from'] ?? 0)),
                'cyl_to'   => (int)round((float)($lp['cyl_to']   ?? 0)),
                'add_from' => (int)round((float)($lp['add_from'] ?? 0)),
                'add_to'   => (int)round((float)($lp['add_to']   ?? 0)),
                'comb_max' => (int)round((float)($lp['comb_max'] ?? 0)),
                'note'     => trim($lp['note'] ?? ''),
            ];

            // Handle rename / category change with collision suffix (same rule
            // as the add_new_lense handler, for consistency).
            $save_name = $new_name;
            $is_rename_or_move = ($new_name !== $old_name) || ($new_cat !== $old_cat);
            if ($is_rename_or_move
                && isset($data[$group][$new_cat][$save_name])
                && !($new_cat === $old_cat && $save_name === $old_name)) {
                $suffix = 2;
                while (isset($data[$group][$new_cat][$new_name.' ('.$suffix.')'])) $suffix++;
                $save_name = $new_name.' ('.$suffix.')';
                $resp['renamed']  = true;
                $resp['message']  = "\"$new_name\" sudah ada di \"$new_cat\" — disimpan sebagai \"$save_name\".";
            }

            // Remove old entry (rename / move)
            if ($is_rename_or_move || $save_name !== $old_name) {
                unset($data[$group][$old_cat][$old_name]);
                if (empty($data[$group][$old_cat])) unset($data[$group][$old_cat]);
            }
            if (!isset($data[$group][$new_cat])) $data[$group][$new_cat] = [];

            $data[$group][$new_cat][$save_name] = [
                'cost' => $cost, 'selling' => $selling,
                'features' => $features, 'limits' => $limits,
            ];

            $ok = @file_put_contents($json_file, json_encode($data, JSON_PRETTY_PRINT));
            if ($ok === false) {
                $resp['message'] = 'Failed to write JSON file (check permissions).';
                echo json_encode($resp); exit;
            }

            // Append to save_log for audit trail (same format as bulk save)
            $log_file = 'data_json/save_log.txt';
            $log_line = "[".date('Y-m-d H:i:s')."] save_single_lens by user="
                      . ($_SESSION['username'] ?? $_SESSION['user'] ?? 'admin')
                      . " — $group / $old_cat / $old_name"
                      . ($is_rename_or_move ? " → $new_cat / $save_name" : "")
                      . "  [sph {$limits['sph_from']}..{$limits['sph_to']}"
                      . "  cyl {$limits['cyl_from']}..{$limits['cyl_to']}"
                      . "  add {$limits['add_from']}..{$limits['add_to']}"
                      . "  comb {$limits['comb_max']}]\n";
            @file_put_contents($log_file, $log_line, FILE_APPEND);

            $resp['ok']         = true;
            $resp['saved_name'] = $save_name;
            $resp['saved_cat']  = $new_cat;
            $resp['limits']     = $limits;
            $resp['cost']       = $cost;
            $resp['selling']    = $selling;
            $resp['features']   = $features;
            if ($resp['message'] === '') {
                $resp['message'] = "Saved: $save_name";
            }
            echo json_encode($resp); exit;
        }

        if (isset($_POST['save_prices'])) {
            // Rebuild entire tree so category + lens-name keys become UPPERCASE.
            $new_data        = [];
            $rename_warnings = []; // collect rename-collision messages for user feedback
            $save_log_rows   = []; // collect rows for debug log (see end of handler)

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
                            'sph_from' => (int)round((float)($lp['sph_from'] ?? 0)),
                            'sph_to'   => (int)round((float)($lp['sph_to']   ?? 0)),
                            'cyl_from' => (int)round((float)($lp['cyl_from'] ?? 0)),
                            'cyl_to'   => (int)round((float)($lp['cyl_to']   ?? 0)),
                            'add_from' => (int)round((float)($lp['add_from'] ?? 0)),
                            'add_to'   => (int)round((float)($lp['add_to']   ?? 0)),
                            'comb_max' => (int)round((float)($lp['comb_max'] ?? 0)),
                            'note'     => trim($lp['note'] ?? ''),
                        ];

                        // ── FIX #1: Anti-overwrite on rename collision ─────────
                        // If $new_name already exists in this category AND it's
                        // different from $old_name (i.e. a rename just produced
                        // a collision with a sibling lens), don't silently wipe
                        // the existing entry. Append (2), (3)… until unique.
                        $save_name     = $new_name;
                        $old_name_uc   = uc_trim($old_name);
                        $is_rename     = ($save_name !== $old_name_uc);
                        if ($is_rename && isset($new_data[$group][$cat_upper][$save_name])) {
                            $suffix = 2;
                            while (isset($new_data[$group][$cat_upper][$save_name.' ('.$suffix.')'])) {
                                $suffix++;
                            }
                            $save_name = $new_name.' ('.$suffix.')';
                            $rename_warnings[] = "\"".htmlspecialchars($old_name_uc)."\" renamed to \"".htmlspecialchars($new_name)."\" but that name was already taken — saved as \"".htmlspecialchars($save_name)."\" instead.";
                        }

                        $new_data[$group][$cat_upper][$save_name] = [
                            'cost'=>(float)$cost,'selling'=>$selling,
                            'features'=>$features,'limits'=>$limits,
                        ];

                        $save_log_rows[] = "  $group / $cat_upper / $old_name_uc"
                                         . ($is_rename ? " → $save_name" : "")
                                         . "  [sph {$limits['sph_from']}..{$limits['sph_to']}"
                                         . "  cyl {$limits['cyl_from']}..{$limits['cyl_to']}"
                                         . "  add {$limits['add_from']}..{$limits['add_to']}"
                                         . "  comb {$limits['comb_max']}]";
                    }
                }
            }

            // ── FIX #2: Preserve per-lens, not just per-group ─────────────
            // If a group, category, or individual lens was missing from the
            // POST (e.g. a browser quirk dropped some inputs, or a future UI
            // change only submits visible cards), keep the old value instead
            // of silently deleting it.
            foreach ($data as $gk => $cats) {
                if (!isset($new_data[$gk])) { $new_data[$gk] = $cats; continue; }
                foreach ($cats as $ck => $lenses) {
                    if (!isset($new_data[$gk][$ck])) { $new_data[$gk][$ck] = $lenses; continue; }
                    foreach ($lenses as $ln => $entry) {
                        if (!isset($new_data[$gk][$ck][$ln])) {
                            $new_data[$gk][$ck][$ln] = $entry;
                        }
                    }
                }
            }

            $data = $new_data;

            // ── FIX #3: Debug log ─────────────────────────────────────────
            // Append to data_json/save_log.txt so we can diagnose any future
            // "the other lens reverted" reports. Keeps last ~200 KB only.
            $log_file = 'data_json/save_log.txt';
            $log_line = "[".date('Y-m-d H:i:s')."] save_prices by user="
                      . ($_SESSION['username'] ?? $_SESSION['user'] ?? 'admin')
                      . " — ".count($save_log_rows)." lens rows posted:\n"
                      . implode("\n", $save_log_rows) . "\n\n";
            @file_put_contents($log_file, $log_line, FILE_APPEND);
            if (file_exists($log_file) && filesize($log_file) > 200000) {
                // Truncate: keep only the last 150 KB
                $tail = @file_get_contents($log_file, false, null, -150000);
                if ($tail !== false) @file_put_contents($log_file, "... (earlier entries trimmed)\n".$tail);
            }

            if (!empty($rename_warnings)) {
                $message = "Saved with warnings: " . implode(" | ", $rename_warnings);
            } else {
                $message = "All changes saved successfully.";
            }

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
            // Default comb_max depends on group: stock = -1000, lab = -1100
            $default_comb = ($new_group === 'lab') ? -1100 : -1000;
            $comb_max_val = isset($lp['comb_max']) && $lp['comb_max'] !== ''
                            ? (int)round((float)$lp['comb_max'])
                            : $default_comb;
            $new_limits = [
                'sph_from' => (int)round((float)($lp['sph_from'] ?? 0)),
                'sph_to'   => (int)round((float)($lp['sph_to']   ?? 0)),
                'cyl_from' => (int)round((float)($lp['cyl_from'] ?? 0)),
                'cyl_to'   => (int)round((float)($lp['cyl_to']   ?? 0)),
                'add_from' => (int)round((float)($lp['add_from'] ?? 0)),
                'add_to'   => (int)round((float)($lp['add_to']   ?? 0)),
                'comb_max' => $comb_max_val,
                'note'     => trim($lp['note'] ?? ''),
            ];

            if (!empty($new_name)) {
                // Check if a lens with the same name already exists in this group+category
                $save_name = $new_name;
                if (isset($data[$new_group][$new_cat][$new_name])) {
                    $existing = $data[$new_group][$new_cat][$new_name];
                    $cost_diff    = abs(($existing['cost']    ?? 0) - $new_cost)    > 0.001;
                    $selling_diff = abs(($existing['selling'] ?? 0) - $new_selling) > 0.001;
                    // Compare limits field by field
                    $limits_diff  = false;
                    foreach (['sph_from','sph_to','cyl_from','cyl_to','add_from','add_to','comb_max'] as $lk) {
                        if ((int)($existing['limits'][$lk] ?? 0) !== (int)($new_limits[$lk] ?? 0)) {
                            $limits_diff = true; break;
                        }
                    }

                    if ($cost_diff || $selling_diff || $limits_diff) {
                        // Different price or limits → save as a new entry with a unique suffix
                        $suffix = 2;
                        while (isset($data[$new_group][$new_cat][$new_name.' ('.$suffix.')'])) {
                            $suffix++;
                        }
                        $save_name = $new_name.' ('.$suffix.')';
                        $message = "Lens \"".htmlspecialchars($new_name)."\" already exists with different price/Rx limits. "
                                 . "Saved as \"".htmlspecialchars($save_name)."\".";
                    } else {
                        // Exactly the same cost, selling, and limits → skip saving
                        $message    = "Lens \"".htmlspecialchars($new_name)."\" already exists with the same price and Rx limits. No changes made.";
                        $active_tab = 'add';
                        goto skip_save;
                    }
                } else {
                    $message = "Lens \"".htmlspecialchars($save_name)."\" added successfully.";
                }

                $data[$new_group][$new_cat][$save_name] = [
                    'cost'=>$new_cost,'selling'=>$new_selling,
                    'features'=>$new_features,'limits'=>$new_limits,
                ];
                $active_tab = 'add';
                skip_save:;
            }

        } elseif (isset($_POST['delete_lense'])) {
            $dg = $_POST['del_group']    ?? '';
            $dc = uc_trim($_POST['del_category'] ?? '');
            $dn = uc_trim($_POST['del_lense']    ?? '');
            if ($dg !== '' && $dc !== '' && $dn !== '' && isset($data[$dg][$dc][$dn])) {
                unset($data[$dg][$dc][$dn]);
                // Clean up empty category so dropdown stays tidy
                if (empty($data[$dg][$dc])) {
                    unset($data[$dg][$dc]);
                }
                $message = "Lens \"".htmlspecialchars($dn)."\" deleted successfully.";
            } else {
                $message = "Could not delete: lens not found.";
            }
        }

        file_put_contents($json_file, json_encode($data, JSON_PRETTY_PRINT));
    }

    // After add_new_lense: point filter to the newly added group+category
    if (isset($_POST['add_new_lense']) && !empty($new_group) && !empty($new_cat)) {
        $selected_group = $new_group;
        $selected_cat   = $new_cat;
    } else {
        $selected_group = $_POST['last_group'] ?? 'stock';
        $selected_cat   = $_POST['last_category'] ?? '';
    }
    // Fallback if the remembered category no longer exists (e.g. emptied by delete)
    if ((empty($selected_cat) || !isset($data[$selected_group][$selected_cat])) && isset($data[$selected_group])) {
        $first_cat = array_key_first($data[$selected_group]);
        $selected_cat = $first_cat ?? '';
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
            .btn-delete-lens {
                position:absolute; top:-10px; right:12px;
                background:#2a1e1e; color:#f87171;
                border:1px solid #4a2525; border-radius:50%;
                width:22px; height:22px; padding:0;
                font-size:13px; font-weight:700; line-height:1;
                cursor:pointer;
                display:inline-flex; align-items:center; justify-content:center;
                transition:all .2s;
            }
            .btn-delete-lens:hover {
                background:#f87171; color:#fff; border-color:#f87171;
                box-shadow:0 0 0 3px rgba(248,113,113,0.15);
            }

            /* ── ALL-view (read-only) mode ───────────────────────────── */
            .all-view-badge {
                display:inline-block; font-size:9px; font-weight:700; letter-spacing:.8px;
                background:#1e3a5f; color:#60a5fa; border:1px solid #2563eb;
                border-radius:6px; padding:2px 8px; margin-left:10px;
                text-transform:uppercase; vertical-align:middle;
            }
            .all-view-group-label {
                font-size:9px; font-weight:700; letter-spacing:.6px; text-transform:uppercase;
                color:#4b5563; margin-right:6px;
            }
            /* ALL view card — read-only look */
            #all-view-container .lens-card { cursor:pointer; border-color:#2a2d34; }
            #all-view-container .lens-card:hover { border-color:#374151; }
            #all-view-container .lens-card.collapsed .lens-card-body { display:none; }
            #all-view-container .lens-card:not(.collapsed) .lens-card-body { animation:slideDown .2s ease-out; display:block; }
            #all-view-container .lens-card-index { background:#374151; color:#9ca3af; }
            /* Mute all inputs in ALL view */
            #all-view-container input, #all-view-container textarea,
            #all-view-container select { pointer-events:none; opacity:.75; }
            #all-view-container .btn-delete-lens,
            #all-view-container .btn-add-feature,
            #all-view-container .btn-remove-tag,
            #all-view-container .preset-feat-btn,
            #all-view-container .feature-add-row { display:none !important; }
            #all-view-container .lens-name-badge { display:none !important; }
            #all-view-container .lens-name-input { border-bottom-color:transparent !important; cursor:default; }
            #all-view-container .rx-limits-details summary { pointer-events:none; }
            #all-view-container .card-divider + .lens-section-label,
            #all-view-container .card-divider + .lens-section-label ~ * { }
            /* Clickable row — clicking anywhere on collapsed card opens it */
            #all-view-container .lens-card.collapsed { cursor:pointer; }
            #all-view-container .lens-name-row { cursor:pointer; }
            /* readonly badge on name row */
            .all-view-readonly-hint {
                font-size:9px; color:#374151; font-style:italic; white-space:nowrap; margin-left:auto; padding-right:4px;
            }

            /* ── Collapse toggle ─────────────────────────────────────── */
            .btn-toggle-lens {
                background:transparent; border:none; cursor:pointer;
                color:#6b7280; padding:0; flex-shrink:0;
                width:20px; height:20px;
                display:inline-flex; align-items:center; justify-content:center;
                border-radius:4px; font-size:9px;
                transition:background .15s, color .15s;
            }
            .btn-toggle-lens:hover { background:#2e3138; color:#00adb5; }
            .btn-toggle-lens .toggle-arrow { display:inline-block; transition:transform .2s ease; }
            .lens-card:not(.collapsed) .btn-toggle-lens .toggle-arrow { transform:rotate(90deg); color:#00adb5; }

            /* Collapsible body */
            .lens-card.collapsed .lens-card-body { display:none; }
            .lens-card:not(.collapsed) .lens-card-body { animation:slideDown .2s ease-out; }

            /* Compact card when collapsed */
            .lens-card.collapsed { padding:10px 14px 10px 18px; }
            .lens-card.collapsed .lens-name-row { margin-bottom:0; }
            .lens-card.collapsed .lens-name-badge { display:none; }

            /* Preview summary — only shown when card is collapsed */
            .lens-preview-summary { display:none; font-size:10.5px; font-weight:500; margin-left:12px; flex-shrink:0; white-space:nowrap; padding-right:28px; }
            .lens-card.collapsed .lens-preview-summary { display:inline-flex; gap:8px; align-items:center; }
            .lens-preview-summary .sum-price      { color:#2dd4bf; font-weight:700; }
            .lens-preview-summary .sum-feat-count { color:#6b7280; font-style:italic; }
            .lens-preview-summary .sum-dot        { color:#3a3d44; font-weight:700; }

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

            /* ── Preset feature quick-select ─────────────────────────── */
            .features-preset-label { font-size:9px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#4b5563; margin:2px 0 6px; }
            .features-selected-label { font-size:9px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#4b5563; margin:12px 0 6px; }
            .preset-features-grid {
                display:flex; flex-wrap:wrap; gap:5px;
                padding:10px; background:#1c1f25;
                border:1px solid #2a2d34; border-radius:8px;
                margin-bottom:4px;
            }
            .preset-feat-btn {
                display:inline-flex; align-items:center; gap:4px;
                background:#1e2127; color:#6b7280;
                border:1px solid #2e3138; border-radius:16px;
                padding:4px 10px; font-size:11px; font-weight:500;
                cursor:pointer; transition:all .15s; line-height:1.3;
            }
            .preset-feat-btn:hover { border-color:#4b5563; color:#9ca3af; }
            .preset-feat-btn.active {
                background:#162829; color:#2dd4bf;
                border-color:#1e4a4d; font-weight:600;
            }
            .preset-feat-btn.active:hover { background:#1a3537; border-color:#2a6668; }
            .preset-feat-check { font-size:10px; color:#2dd4bf; }

            /* ── Last-opened / previously-opened card markers ────────── */
            /* Last-opened: golden glow + golden index badge + "LAST EDITED" badge */
            .lens-card.lens-last-opened {
                border-color:#fbbf24 !important;
                box-shadow:0 0 0 1px rgba(251,191,36,0.35), 0 3px 12px rgba(251, 191, 36, 0.18) !important;
            }
            .lens-card.lens-last-opened .lens-card-index {
                background:#fbbf24; color:#1f1611;
            }
            .lens-last-opened-badge {
                display:none; position:absolute;
                top:-9px; left:58px;
                background:linear-gradient(90deg,#fbbf24,#f59e0b);
                color:#1f1611; font-size:9px; font-weight:800;
                letter-spacing:.7px; padding:2px 8px;
                border-radius:10px; text-transform:uppercase;
                white-space:nowrap; z-index:2;
                box-shadow:0 1px 3px rgba(251,191,36,0.35);
            }
            .lens-card.lens-last-opened .lens-last-opened-badge { display:inline-block; }

            /* Previously-opened: subtle grey dot at top-left */
            .lens-prev-opened-dot {
                display:none; position:absolute;
                top:-4px; left:58px;
                width:7px; height:7px; border-radius:50%;
                background:#9ca3af; z-index:2;
                box-shadow:0 0 0 2px #262932;
            }
            .lens-card.lens-previously-opened .lens-prev-opened-dot { display:inline-block; }

            /* Jump-to-last-edited button */
            .jump-last-btn {
                background:#3a2f12; color:#fbbf24;
                border:1px solid #5a4a1e; border-radius:8px;
                padding:6px 12px; font-size:11px; font-weight:600;
                cursor:pointer; white-space:nowrap;
                display:none; align-items:center; gap:6px;
                transition:all .2s;
            }
            .jump-last-btn:hover { background:#fbbf24; color:#1f1611; border-color:#fbbf24; }
            .jump-last-btn.visible { display:inline-flex; }
            .jump-last-btn-dot { width:6px; height:6px; border-radius:50%; background:#fbbf24; }
            .jump-last-btn:hover .jump-last-btn-dot { background:#1f1611; }

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

            /* Locked rx input (readonly). .rx-locked allows double-click to unlock, .rx-locked-hard stays locked. */
            .rx-input.rx-locked,
            .rx-input.rx-locked-hard {
                background:#15181e !important;
                color:#6b7280 !important;
                border-color:#242830 !important;
                -webkit-text-fill-color:#6b7280;
            }
            .rx-input.rx-locked      { cursor:pointer; }
            .rx-input.rx-locked-hard { cursor:not-allowed; opacity:0.55; }
            .rx-input.rx-locked:hover { border-color:#2e3138 !important; }

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

            /* ── Per-lens save button (inside each lens card) ────────── */
            .lens-save-row {
                display:flex; align-items:center; justify-content:flex-end;
                gap:12px; margin-top:14px; padding-top:12px;
                border-top:1px dashed #2e3138;
            }
            .lens-save-status {
                font-size:11px; font-style:italic;
                opacity:0; transition:opacity .25s;
                min-height:14px;
            }
            .lens-save-status.show     { opacity:1; }
            .lens-save-status.ok       { color:#34d399; font-style:normal; }
            .lens-save-status.err      { color:#f87171; font-style:normal; }
            .lens-save-status.pending  { color:#9ca3af; }
            .btn-save-lens {
                padding:8px 16px; border:1px solid #00adb5;
                background:#0f2b2d; color:#00adb5;
                border-radius:8px; cursor:pointer;
                font-size:12px; font-weight:700; letter-spacing:.4px;
                transition:all .2s; white-space:nowrap;
            }
            .btn-save-lens:hover  { background:#00adb5; color:#0f1115; box-shadow:0 0 0 3px rgba(0,173,181,.15); }
            .btn-save-lens:active { transform:translateY(1px); }
            .btn-save-lens:disabled {
                opacity:.55; cursor:not-allowed;
                background:#1e2127; color:#6b7280; border-color:#2e3138; box-shadow:none;
            }
            .btn-save-lens.is-dirty {
                background:#2d2410; color:#fbbf24; border-color:#fbbf24;
            }
            .btn-save-lens.is-dirty:hover {
                background:#fbbf24; color:#0f1115;
            }
            /* Read-only "all view" doesn't need the save row */
            #all-view-container .lens-save-row { display:none !important; }

            /* Banner that replaces the old "Save All Changes" bar */
            .save-info-banner {
                flex:1; text-align:center;
                color:#9ca3af; font-size:12px; line-height:1.55;
                padding:4px 12px;
            }
            .save-info-banner b { color:#00adb5; font-weight:700; }

            /* ── Toast (slides in from bottom-right on save result) ── */
            #save-toast-container {
                position:fixed; bottom:24px; right:24px;
                display:flex; flex-direction:column; gap:8px;
                z-index:9999; pointer-events:none;
            }
            .save-toast {
                min-width:240px; max-width:380px;
                padding:12px 16px; border-radius:10px;
                font-size:13px; font-weight:500; line-height:1.4;
                box-shadow:0 8px 24px rgba(0,0,0,.45);
                animation:toastIn .25s ease-out;
                pointer-events:auto;
            }
            .save-toast.ok  { background:#052e24; color:#34d399; border:1px solid #065f46; }
            .save-toast.err { background:#2a1210; color:#f87171; border:1px solid #7f1d1d; }
            .save-toast.warn{ background:#2a1e0c; color:#fbbf24; border:1px solid #78350f; }
            .save-toast.fade-out { animation:toastOut .3s ease-in forwards; }
            @keyframes toastIn  { from { opacity:0; transform:translateX(20px); } to { opacity:1; transform:translateX(0); } }
            @keyframes toastOut { to   { opacity:0; transform:translateX(20px); } }

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
                    <div id="status-message" style="background:#065f46;color:#6ee7b7;border:1px solid #047857;padding:10px 16px;border-radius:8px;margin-bottom:16px;text-align:center;font-size:13px;transition:opacity .4s ease, margin .4s ease, padding .4s ease, max-height .4s ease;overflow:hidden;max-height:100px;">
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
                        <input type="hidden" name="last_group"    id="add_last_group"    value="<?php echo htmlspecialchars($selected_group); ?>">
                        <input type="hidden" name="last_category" id="add_last_category" value="<?php echo htmlspecialchars($selected_cat); ?>">
                        <div class="add-form-card">
                            <div class="add-form-title">Add New Lens</div>
                            <div class="add-form-grid">

                                <div class="form-field">
                                    <label>Group</label>
                                    <select name="new_group" id="new_group_select" class="input-field" onchange="updateRxLimitsDefault()">
                                        <option value="stock">STOCK LENS</option>
                                        <option value="lab">LAB LENS (CUSTOM ORDER)</option>
                                    </select>
                                </div>
                                <div class="form-field">
                                    <label>Category</label>
                                    <?php
                                        // Collect all unique categories that already exist, plus common defaults
                                        $all_cats = [];
                                        foreach ($data as $g => $cats) {
                                            foreach (array_keys($cats) as $c) $all_cats[$c] = true;
                                        }
                                        foreach (['SINGLE VISION','KRYPTOK','FLATTOP','PROGRESSIVE','BIFOCAL'] as $dc) {
                                            $all_cats[$dc] = true;
                                        }
                                        $all_cats = array_keys($all_cats);
                                        sort($all_cats);
                                    ?>
                                    <select id="new_category_select" class="input-field" onchange="handleCategorySelect(this)">
                                        <?php foreach ($all_cats as $c): ?>
                                            <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                                        <?php endforeach; ?>
                                        <option value="__custom__">&#43; Other (Custom)&hellip;</option>
                                    </select>
                                    <input type="text" id="new_category_custom" class="input-field"
                                        oninput="updateCustomCat(this)"
                                        placeholder="e.g. ASPHERIC"
                                        style="display:none; margin-top:8px;" autocomplete="off">
                                    <input type="hidden" name="new_category" id="new_category_hidden" value="<?php echo htmlspecialchars($all_cats[0] ?? 'SINGLE VISION'); ?>">
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
                                    <div class="features-preset-label" style="margin-top:0;">Quick select &mdash; click to toggle:</div>
                                    <div class="preset-features-grid" id="preset-new_lense"></div>
                                    <div class="features-selected-label">Selected features:</div>
                                    <div class="features-tag-wrapper" id="tags-new_lense" style="margin-bottom:8px;min-height:20px;"></div>
                                    <div class="feature-add-row">
                                        <input type="text" id="feat-input-new_lense" class="feature-add-input uppercase-input"
                                            placeholder="Or type custom feature, separate with comma"
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
                                            <div class="rx-group-label sph">SPH &mdash; Sphere <span style="color:#374151;font-weight:400;font-size:9px;margin-left:4px;">(value &times;100, e.g. -25 = -0.25)</span></div>
                                            <div class="rx-row">
                                                <div class="rx-subfield"><label>From</label><input type="number" step="25" id="new_sph_from" class="rx-input" name="new_limits[sph_from]" value="0" placeholder="0"></div>
                                                <div class="rx-arrow">&rarr;</div>
                                                <div class="rx-subfield"><label>To</label><input type="number" step="25" id="new_sph_to" class="rx-input" name="new_limits[sph_to]" value="-800" placeholder="0"></div>
                                            </div>
                                        </div>

                                        <div class="rx-group">
                                            <div class="rx-group-label cyl">CYL &mdash; Cylinder</div>
                                            <div class="rx-row">
                                                <div class="rx-subfield"><label>From</label><input type="number" step="25" id="new_cyl_from" class="rx-input" name="new_limits[cyl_from]" value="-25" placeholder="0"></div>
                                                <div class="rx-arrow">&rarr;</div>
                                                <div class="rx-subfield"><label>To</label><input type="number" step="25" id="new_cyl_to" class="rx-input" name="new_limits[cyl_to]" value="-200" placeholder="0"></div>
                                            </div>
                                        </div>

                                        <div class="rx-group">
                                            <div class="rx-group-label add">ADD &mdash; Reading Addition <span style="color:#374151;font-weight:400;font-size:9px;margin-left:4px;">(double-click to edit)</span></div>
                                            <div class="rx-row">
                                                <div class="rx-subfield"><label>From</label><input type="number" step="25" id="new_add_from" class="rx-input rx-locked" name="new_limits[add_from]" value="100" placeholder="0" readonly title="Double-click to edit"></div>
                                                <div class="rx-arrow">&rarr;</div>
                                                <div class="rx-subfield"><label>To</label><input type="number" step="25" id="new_add_to" class="rx-input rx-locked" name="new_limits[add_to]" value="300" placeholder="0" readonly title="Double-click to edit"></div>
                                            </div>
                                        </div>

                                        <div class="rx-group">
                                            <div class="rx-group-label comb">COMB &mdash; Max Combination</div>
                                            <input type="number" step="25" class="rx-input" id="new_comb_max" style="max-width:130px;" name="new_limits[comb_max]" value="-1000" placeholder="-1000">
                                            <div class="rx-hint">|SPH| + |CYL| limit. Default: Stock=-1000, Lab=-1100.</div>
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
                    <form id="form-price-list" action="lense_price.php" method="POST" onsubmit="return false;">
                        <input type="hidden" name="last_group"    id="last_group"    value="<?php echo htmlspecialchars($selected_group); ?>">
                        <input type="hidden" name="last_category" id="last_category" value="<?php echo htmlspecialchars($selected_cat); ?>">

                        <div class="filter-bar">
                            <div>
                                <label>Group</label>
                                <select id="filter-group" class="input-field" onchange="updateCategoryFilter()">
                                    <?php foreach (array_keys($data) as $g): ?>
                                        <option value="<?php echo $g; ?>"<?php if($g===$selected_group) echo ' selected'; ?>><?php echo ucfirst($g); ?> Lenses</option>
                                    <?php endforeach; ?>
                                    <option value="__all__">&#9776; All Groups</option>
                                </select>
                            </div>
                            <div>
                                <label>Category</label>
                                <select id="filter-category" class="input-field" onchange="filterLenses()"></select>
                            </div>
                            <div style="flex:0 0 auto;">
                                <label style="visibility:hidden;">Jump</label>
                                <button type="button" id="jump-last-btn" class="jump-last-btn" onclick="jumpToLastEdited()" title="Jump to the last lens you edited">
                                    <span class="jump-last-btn-dot"></span>
                                    Jump to Last Edited
                                </button>
                            </div>
                        </div>

                        <div id="lense-display-container" style="width:100%;">
                        <?php
                            $lens_counter = 0;
                            foreach ($data as $group_key => $categories):
                                foreach ($categories as $cat_name => $lenses):
                                    $has_add  = catHasAdd($cat_name);
                                    $has_cyl  = catHasCyl($cat_name, $group_key);
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
                                    // Show CYL/ADD row if category qualifies OR saved data is non-zero
                                    $show_cyl = $has_cyl || ($lim['cyl_from'] != 0 || $lim['cyl_to'] != 0);
                                    $show_add = $has_add || ($lim['add_from'] != 0 || $lim['add_to'] != 0);
                                ?>
                                <div class="lens-card collapsed"
                                    data-group="<?php echo htmlspecialchars($group_key);?>"
                                    data-category="<?php echo htmlspecialchars($cat_name);?>"
                                    data-name="<?php echo htmlspecialchars($name);?>">
                                    <span class="lens-card-index">#<?php echo str_pad($card_index, 2, '0', STR_PAD_LEFT); ?></span>
                                    <span class="lens-prev-opened-dot" title="You've opened this lens before"></span>
                                    <span class="lens-last-opened-badge">Last Edited</span>
                                    <button type="button" class="btn-delete-lens"
                                        data-group="<?php echo htmlspecialchars($group_key);?>"
                                        data-category="<?php echo htmlspecialchars($cat_name);?>"
                                        data-name="<?php echo htmlspecialchars($name);?>"
                                        title="Delete this lens">&times;</button>

                                    <!-- Name + toggle -->
                                    <div class="lens-name-row">
                                        <button type="button" class="btn-toggle-lens" onclick="toggleLensCard(this)" title="Show / hide details">
                                            <span class="toggle-arrow">&#9654;</span>
                                        </button>
                                        <span class="lens-name-icon">&#9998;</span>
                                        <input type="text" class="lens-name-input uppercase-input"
                                            oninput="this.value=this.value.toUpperCase()"
                                            name="price_name[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>]"
                                            value="<?php echo htmlspecialchars($name);?>" title="Click to rename">
                                        <span class="lens-name-badge">click to rename</span>
                                        <span class="lens-preview-summary">
                                            <span class="sum-price">IDR <?php echo number_format($selling ?: $cost, 0, ',', '.'); ?></span>
                                            <?php if (count($features)): ?>
                                            <span class="sum-dot">&bull;</span>
                                            <span class="sum-feat-count"><?php echo count($features); ?> feature<?php echo count($features)!==1?'s':''; ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </div>

                                    <div class="lens-card-body">

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
                                    <div class="features-preset-label">Quick select &mdash; click to toggle:</div>
                                    <div class="preset-features-grid" id="preset-<?php echo $sk;?>"></div>
                                    <div class="features-selected-label">Selected features:</div>
                                    <div class="features-tag-wrapper"
                                        id="tags-<?php echo $sk;?>"
                                        data-safe-key="<?php echo $sk;?>"
                                        data-features='<?php echo htmlspecialchars(json_encode($features),ENT_QUOTES);?>'>
                                    </div>
                                    <div class="feature-add-row">
                                        <input type="text" id="feat-input-<?php echo $sk;?>" class="feature-add-input uppercase-input"
                                            placeholder="Or type custom feature, separate with comma"
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
                                                    if ($show_cyl && ($lim['cyl_from'] != 0 || $lim['cyl_to'] != 0))
                                                        $preview[] = 'CYL '.fmtRx($lim['cyl_from']).' ~ '.fmtRx($lim['cyl_to']);
                                                    if ($show_add && ($lim['add_from'] != 0 || $lim['add_to'] != 0))
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
                                                <div class="rx-group-label sph">SPH &mdash; Sphere <span style="color:#374151;font-weight:400;font-size:9px;margin-left:4px;">(value &times;100, e.g. -25 = -0.25)</span></div>
                                                <div class="rx-row">
                                                    <div class="rx-subfield">
                                                        <label>From</label>
                                                        <input type="number" step="25" class="rx-input"
                                                            name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][sph_from]"
                                                            value="<?php echo fmtRx($lim['sph_from']);?>">
                                                    </div>
                                                    <div class="rx-arrow">&rarr;</div>
                                                    <div class="rx-subfield">
                                                        <label>To</label>
                                                        <input type="number" step="25" class="rx-input"
                                                            name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][sph_to]"
                                                            value="<?php echo fmtRx($lim['sph_to']);?>">
                                                    </div>
                                                </div>
                                            </div>

                                            <?php if ($show_cyl): ?>
                                            <!-- CYL -->
                                            <div class="rx-group">
                                                <div class="rx-group-label cyl">CYL &mdash; Cylinder</div>
                                                <div class="rx-row">
                                                    <div class="rx-subfield">
                                                        <label>From</label>
                                                        <input type="number" step="25" class="rx-input"
                                                            name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][cyl_from]"
                                                            value="<?php echo fmtRx($lim['cyl_from']);?>">
                                                    </div>
                                                    <div class="rx-arrow">&rarr;</div>
                                                    <div class="rx-subfield">
                                                        <label>To</label>
                                                        <input type="number" step="25" class="rx-input"
                                                            name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][cyl_to]"
                                                            value="<?php echo fmtRx($lim['cyl_to']);?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <!-- Hidden CYL: preserve saved value so it doesn't get silently zeroed -->
                                            <input type="hidden" name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][cyl_from]" value="<?php echo (int)$lim['cyl_from']; ?>">
                                            <input type="hidden" name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][cyl_to]"   value="<?php echo (int)$lim['cyl_to']; ?>">
                                            <?php endif; ?>

                                            <?php if ($show_add): ?>
                                            <!-- ADD -->
                                            <div class="rx-group">
                                                <div class="rx-group-label add">ADD &mdash; Reading Addition</div>
                                                <div class="rx-row">
                                                    <div class="rx-subfield">
                                                        <label>From</label>
                                                        <input type="number" step="25" class="rx-input"
                                                            name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][add_from]"
                                                            value="<?php echo fmtRx($lim['add_from']);?>">
                                                    </div>
                                                    <div class="rx-arrow">&rarr;</div>
                                                    <div class="rx-subfield">
                                                        <label>To</label>
                                                        <input type="number" step="25" class="rx-input"
                                                            name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][add_to]"
                                                            value="<?php echo fmtRx($lim['add_to']);?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <!-- Hidden ADD: preserve saved value so it doesn't get silently zeroed -->
                                            <input type="hidden" name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][add_from]" value="<?php echo (int)$lim['add_from']; ?>">
                                            <input type="hidden" name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][add_to]"   value="<?php echo (int)$lim['add_to']; ?>">
                                            <?php endif; ?>

                                            <!-- COMB -->
                                            <div class="rx-group">
                                                <div class="rx-group-label comb">COMB &mdash; Max Combination</div>
                                                <input type="number" step="25" class="rx-input" style="max-width:130px;"
                                                    name="price_limits[<?php echo $group_key;?>][<?php echo $cat_name;?>][<?php echo $name;?>][comb_max]"
                                                    value="<?php echo fmtRx($lim['comb_max']);?>">
                                                <div class="rx-hint">|SPH| + |CYL| limit. Default: Stock=-1000, Lab=-1100.</div>
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

                                    <!-- Per-lens save button (AJAX, avoids "Save All" lost-update) -->
                                    <div class="lens-save-row">
                                        <span class="lens-save-status" aria-live="polite"></span>
                                        <button type="button" class="btn-save-lens"
                                            data-group="<?php echo htmlspecialchars($group_key);?>"
                                            data-category="<?php echo htmlspecialchars($cat_name);?>"
                                            data-name="<?php echo htmlspecialchars($name);?>">
                                            <span class="lens-save-label">&#128190; Save this lens</span>
                                        </button>
                                    </div>

                                    </div><!-- /.lens-card-body -->

                                </div><!-- /.lens-card -->
                                <?php endforeach; ?>
                                </div><!-- /.lense-panel -->
                            </details>
                        </div>
                        <?php endforeach; endforeach; ?>
                        </div><!-- /#lense-display-container -->

                        <!-- ── ALL VIEW (read-only) container ── -->
                        <div id="all-view-container" style="display:none; width:100%;">
                        <?php
                            $all_counter = 0;
                            foreach ($data as $av_group => $av_cats):
                                foreach ($av_cats as $av_cat => $av_lenses):
                                    $av_has_add = catHasAdd($av_cat);
                                    $av_has_cyl = catHasCyl($av_cat, $av_group);
                        ?>
                        <div class="lense-group-wrapper">
                            <details class="lense-details" open>
                                <summary>
                                    <span>
                                        <span class="all-view-group-label"><?php echo ucfirst($av_group); ?> /</span>
                                        <?php echo htmlspecialchars($av_cat); ?>
                                        <span style="font-size:11px;font-weight:400;color:#4b5563;margin-left:8px;"><?php echo count($av_lenses); ?> lens<?php echo count($av_lenses)!==1?'es':''; ?></span>
                                        <span class="all-view-badge">Read Only</span>
                                    </span>
                                    <span class="summary-arrow">&#9660;</span>
                                </summary>
                                <div class="lense-panel">
                                <?php $av_idx=0; foreach ($av_lenses as $av_name => $av_prices):
                                    $all_counter++;
                                    $av_idx++;
                                    $av_cost     = is_array($av_prices) ? ($av_prices['cost']     ?? 0) : (float)$av_prices;
                                    $av_selling  = is_array($av_prices) ? ($av_prices['selling']  ?? 0) : 0.0;
                                    $av_features = is_array($av_prices) ? ($av_prices['features'] ?? []) : [];
                                    $av_lim      = is_array($av_prices) ? ($av_prices['limits']   ?? $DEFAULT_LIMITS) : $DEFAULT_LIMITS;
                                    $av_lim      = array_merge($DEFAULT_LIMITS, $av_lim);
                                    $av_show_cyl = $av_has_cyl || ($av_lim['cyl_from'] != 0 || $av_lim['cyl_to'] != 0);
                                    $av_show_add = $av_has_add || ($av_lim['add_from'] != 0 || $av_lim['add_to'] != 0);
                                ?>
                                <div class="lens-card collapsed" onclick="toggleLensCardEl(this)">
                                    <span class="lens-card-index">#<?php echo str_pad($av_idx,2,'0',STR_PAD_LEFT);?></span>
                                    <div class="lens-name-row" style="margin-bottom:0;">
                                        <span class="lens-name-icon" style="color:#374151;">&#9654;</span>
                                        <span style="flex:1;color:#e5e7eb;font-size:14px;font-weight:700;padding:4px 2px;text-transform:uppercase;"><?php echo htmlspecialchars($av_name);?></span>
                                        <span class="lens-preview-summary" style="display:inline-flex;">
                                            <span class="sum-price">IDR <?php echo number_format($av_selling ?: $av_cost,0,',','.');?></span>
                                            <?php if(count($av_features)):?>
                                            <span class="sum-dot">&bull;</span>
                                            <span class="sum-feat-count"><?php echo count($av_features);?> feature<?php echo count($av_features)!==1?'s':'';?></span>
                                            <?php endif;?>
                                        </span>
                                    </div>
                                    <div class="lens-card-body" style="margin-top:12px;">
                                        <div class="lens-prices-row">
                                            <div class="price-col cost-col">
                                                <label>Cost Price</label>
                                                <input type="text" class="input-field" readonly value="IDR <?php echo number_format($av_cost,0,',','.');?>">
                                            </div>
                                            <div class="price-col sell-col">
                                                <label>Selling Price</label>
                                                <input type="text" class="input-field" readonly value="IDR <?php echo number_format($av_selling,0,',','.');?>">
                                            </div>
                                        </div>
                                        <?php if(count($av_features)):?>
                                        <hr class="card-divider">
                                        <div class="lens-section-label">Features</div>
                                        <div class="features-tag-wrapper" style="pointer-events:none;">
                                            <?php foreach($av_features as $ft):?>
                                            <span class="feature-tag"><span class="feature-tag-dot"></span><?php echo htmlspecialchars($ft);?></span>
                                            <?php endforeach;?>
                                        </div>
                                        <?php endif;?>
                                        <?php
                                            $av_preview=[];
                                            if($av_lim['sph_from']!=0||$av_lim['sph_to']!=0) $av_preview[]='SPH '.fmtRx($av_lim['sph_from']).' ~ '.fmtRx($av_lim['sph_to']);
                                            if($av_show_cyl&&($av_lim['cyl_from']!=0||$av_lim['cyl_to']!=0)) $av_preview[]='CYL '.fmtRx($av_lim['cyl_from']).' ~ '.fmtRx($av_lim['cyl_to']);
                                            if($av_show_add&&($av_lim['add_from']!=0||$av_lim['add_to']!=0)) $av_preview[]='ADD '.fmtRx($av_lim['add_from']).' ~ '.fmtRx($av_lim['add_to']);
                                            if($av_lim['comb_max']!=0) $av_preview[]='COMB '.fmtRx($av_lim['comb_max']);
                                        ?>
                                        <?php if(!empty($av_preview)):?>
                                        <hr class="card-divider">
                                        <div class="lens-section-label">Rx Limits</div>
                                        <div style="font-size:11px;color:#4b5563;font-style:italic;"><?php echo implode('&nbsp;&nbsp;|&nbsp;&nbsp;',$av_preview);?></div>
                                        <?php if(!empty($av_lim['note'])):?>
                                        <div style="font-size:11px;color:#6b7280;margin-top:6px;">&#9888; <?php echo htmlspecialchars($av_lim['note']);?></div>
                                        <?php endif;?>
                                        <?php endif;?>
                                    </div>
                                </div>
                                <?php endforeach;?>
                                </div>
                            </details>
                        </div>
                        <?php endforeach; endforeach;?>
                        </div><!-- /#all-view-container -->

                        <div class="save-bar" id="save-bar">
                            <div class="save-info-banner">
                                &#8505;&ensp;Each lens is saved individually using the <b>&#128190; Save this lens</b> button within its card.
                                Unsaved changes will be lost if you navigate away from the page.
                            </div>
                        </div>
                    </form>

                    <!-- Hidden form for lens deletion -->
                    <form id="delete-lense-form" action="lense_price.php" method="POST" style="display:none;">
                        <input type="hidden" name="delete_lense" value="1">
                        <input type="hidden" name="del_group"    id="del_group">
                        <input type="hidden" name="del_category" id="del_category">
                        <input type="hidden" name="del_lense"    id="del_lense">
                        <input type="hidden" name="last_group"    id="del_last_group">
                        <input type="hidden" name="last_category" id="del_last_category">
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
            // Preset feature options (order matters — shown as quick-select chips)
            const PRESET_FEATURES = [
                'UV Protection',
                'High-Index UV400 Protection',
                'Scratch-Resistant Coating',
                'Anti-Reflective (AR) Coating',
                'Smudge-Resistant',
                'Hydrophobic',
                'Super Hydrophobic',
                'Anti-Static',
                'Blue Light Blocking',
                'Impact-Resistant',
                'Photochromic',
                'Night Drive Coating'
            ];
            const lenseFeatures = {};
            function initFeatureTags(k, f) {
                // Normalize existing features to UPPERCASE on load
                lenseFeatures[k] = Array.isArray(f) ? f.map(t=>String(t).trim().toUpperCase()).filter(t=>t) : [];
                renderFeatureTags(k);
            }
            function renderPresetFeatures(k) {
                const c = document.getElementById('preset-'+k); if(!c) return;
                const current = (lenseFeatures[k]||[]).map(t=>t.toUpperCase());
                c.innerHTML = PRESET_FEATURES.map(feat => {
                    const upper = feat.toUpperCase();
                    const active = current.includes(upper);
                    const safe = upper.replace(/'/g,"\\'");
                    return `<button type="button" class="preset-feat-btn${active?' active':''}" onclick="togglePresetFeature('${k}','${safe}')" title="${active?'Click to remove':'Click to add'}">${escapeHtml(feat)}${active?'<span class="preset-feat-check">&#10003;</span>':''}</button>`;
                }).join('');
            }
            function togglePresetFeature(k, feat) {
                if (!lenseFeatures[k]) lenseFeatures[k] = [];
                const upper = String(feat).toUpperCase();
                const idx = lenseFeatures[k].findIndex(f => String(f).toUpperCase() === upper);
                if (idx >= 0) {
                    lenseFeatures[k].splice(idx, 1);
                } else {
                    lenseFeatures[k].push(upper);
                }
                renderFeatureTags(k);
            }
            function renderFeatureTags(k) {
                const c = document.getElementById('tags-'+k); if(!c) return;
                const f = lenseFeatures[k]||[];
                const presetUppers = PRESET_FEATURES.map(p => p.toUpperCase());
                // Only show custom features (not in preset list) in "Selected Features" section
                const customFeats = f.map((t,i)=>({t,i})).filter(({t}) => !presetUppers.includes(t.toUpperCase()));
                if (customFeats.length === 0) {
                    c.innerHTML = '<span class="no-features-text">No custom features — use Quick Select above or type below</span>';
                } else {
                    c.innerHTML = customFeats.map(({t,i})=>`<span class="feature-tag"><span class="feature-tag-dot"></span>${escapeHtml(t)}<button type="button" class="btn-remove-tag" onclick="removeFeatureTag('${k}',${i})" title="Remove">&#215;</button></span>`).join('');
                }
                syncFeaturesHidden(k);
                renderPresetFeatures(k);
            }
            // Toggle for ALL-view read-only cards (click anywhere on card)
            function toggleLensCardEl(card) {
                card.classList.toggle('collapsed');
                const arrow = card.querySelector('.lens-name-icon');
                if (arrow) arrow.style.transform = card.classList.contains('collapsed') ? '' : 'rotate(90deg)';
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
                document.getElementById('last_group').value = g === '__all__' ? '' : g;
                cs.innerHTML = '';
                const mainContainer = document.getElementById('lense-display-container');
                const allContainer  = document.getElementById('all-view-container');
                const saveBar       = document.getElementById('save-bar');
                if (g === '__all__') {
                    // Show ALL view, hide normal editable view + save bar
                    mainContainer.style.display = 'none';
                    allContainer.style.display  = 'block';
                    if (saveBar) saveBar.style.display = 'none';
                    const o = document.createElement('option');
                    o.value = '__all__'; o.textContent = 'All Categories';
                    cs.appendChild(o);
                    return;
                }
                // Normal group selected
                mainContainer.style.display = '';
                allContainer.style.display  = 'none';
                if (saveBar) saveBar.style.display = '';
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
                if (g === '__all__') return;
                const c = document.getElementById('filter-category').value;
                document.getElementById('last_category').value = c;
                document.querySelectorAll('#lense-display-container .lense-group-wrapper').forEach(w => {
                    w.style.display = (w.dataset.group===g && w.dataset.category===c) ? 'block' : 'none';
                });
            }

            // ─── Collapse / expand lens card + track last-opened ─────────
            const LAST_OPENED_KEY    = 'lense_price_last_opened';
            const OPENED_HISTORY_KEY = 'lense_price_opened_history';

            function cardIdFromEl(card) {
                const g = card.dataset.group || '';
                const c = card.dataset.category || '';
                const n = card.dataset.name || '';
                if (!g || !c || !n) return null;
                return { id: g+'||'+c+'||'+n, group: g, category: c, name: n };
            }
            function readLast() {
                try { return JSON.parse(localStorage.getItem(LAST_OPENED_KEY) || 'null'); }
                catch(e) { return null; }
            }
            function readHistory() {
                try { return JSON.parse(localStorage.getItem(OPENED_HISTORY_KEY) || '{}'); }
                catch(e) { return {}; }
            }
            function markCardAsOpened(card) {
                const meta = cardIdFromEl(card); if (!meta) return;
                const now = Date.now();
                try {
                    localStorage.setItem(LAST_OPENED_KEY, JSON.stringify({...meta, timestamp: now}));
                    const hist = readHistory();
                    hist[meta.id] = now;
                    localStorage.setItem(OPENED_HISTORY_KEY, JSON.stringify(hist));
                } catch(e) {}
                applyOpenedMarkers();
            }
            function applyOpenedMarkers() {
                const last = readLast();
                const hist = readHistory();
                document.querySelectorAll('.lens-card').forEach(card => {
                    const meta = cardIdFromEl(card); if (!meta) return;
                    card.classList.remove('lens-last-opened','lens-previously-opened');
                    if (last && last.id === meta.id) {
                        card.classList.add('lens-last-opened');
                    } else if (hist[meta.id]) {
                        card.classList.add('lens-previously-opened');
                    }
                });
                // Toggle visibility of the "Jump to Last Edited" button
                const jumpBtn = document.getElementById('jump-last-btn');
                if (jumpBtn) {
                    if (last) jumpBtn.classList.add('visible');
                    else      jumpBtn.classList.remove('visible');
                }
            }
            function jumpToLastEdited() {
                const last = readLast(); if (!last) return;
                // Switch filters to the correct group/category
                const gs = document.getElementById('filter-group');
                const cs = document.getElementById('filter-category');
                if (gs && gs.value !== last.group) {
                    gs.value = last.group;
                    updateCategoryFilter();
                }
                if (cs) {
                    // Give updateCategoryFilter a beat to repopulate options
                    setTimeout(() => {
                        if (cs.value !== last.category) {
                            cs.value = last.category;
                            filterLenses();
                        }
                        // Open the parent <details> and scroll to the card
                        const card = document.querySelector(
                            '.lens-card[data-group="'+cssEscape(last.group)+'"][data-category="'+cssEscape(last.category)+'"][data-name="'+cssEscape(last.name)+'"]'
                        );
                        if (card) {
                            const details = card.closest('details.lense-details');
                            if (details) details.open = true;
                            setTimeout(() => {
                                card.scrollIntoView({behavior:'smooth', block:'center'});
                            }, 50);
                        }
                    }, 30);
                }
            }
            // Minimal CSS.escape polyfill for attr selector use
            function cssEscape(s) {
                return String(s).replace(/(["\\])/g, '\\$1');
            }

            function toggleLensCard(btn) {
                const card = btn.closest('.lens-card');
                if (!card) return;
                const wasCollapsed = card.classList.contains('collapsed');
                card.classList.toggle('collapsed');
                // Mark as opened only when expanding (not when collapsing)
                if (wasCollapsed) markCardAsOpened(card);
            }

            // ─── Delete lens ─────────────────────────────────────────────
            function confirmDeleteLens(group, category, name) {
                const msg = 'Delete lens "' + name + '" from ' + category + '?\n\nThis action cannot be undone.\nNote: Any unsaved price edits will be discarded.';
                if (!confirm(msg)) return;
                document.getElementById('del_group').value       = group;
                document.getElementById('del_category').value    = category;
                document.getElementById('del_lense').value       = name;
                // Preserve filter so user stays on the same view after reload
                const fg = document.getElementById('filter-group');
                const fc = document.getElementById('filter-category');
                document.getElementById('del_last_group').value    = fg ? fg.value : (group || 'stock');
                document.getElementById('del_last_category').value = fc ? fc.value : (category || '');
                document.getElementById('delete-lense-form').submit();
            }

            // ─── Category dropdown / custom input ────────────────────────
            function handleCategorySelect(sel) {
                const custom = document.getElementById('new_category_custom');
                const hidden = document.getElementById('new_category_hidden');
                if (sel.value === '__custom__') {
                    custom.style.display = 'block';
                    hidden.value = (custom.value || '').trim().toUpperCase();
                    setTimeout(() => { custom.focus(); custom.select(); }, 0);
                } else {
                    custom.style.display = 'none';
                    hidden.value = sel.value;
                }
                updateAddDefaults();
            }
            function updateCustomCat(inp) {
                inp.value = inp.value.toUpperCase();
                document.getElementById('new_category_hidden').value = inp.value.trim();
                updateAddDefaults();
            }

            // ─── Rx input helpers: +sign formatting, step, arrow-key nav ──
            // Parse any rx-input string to an integer (strip "+", spaces, junk)
            function rxParse(s) {
                s = String(s == null ? '' : s).trim();
                if (s === '' || s === '-' || s === '+') return 0;
                const n = parseInt(s.replace(/[^\-0-9]/g, ''), 10);
                return isNaN(n) ? 0 : n;
            }
            // Format integer → "+100" / "-100" / "0"
            function rxFormat(n) {
                n = parseInt(n, 10) || 0;
                if (n > 0) return '+' + n;
                return String(n);
            }
            // Increment / decrement value by its step (default 25)
            function rxStep(input, delta) {
                if (input.readOnly || input.disabled) return;
                const step = parseInt(input.getAttribute('step') || input.dataset.step || '25', 10);
                input.value = rxFormat(rxParse(input.value) + delta * step);
                try { input.select(); } catch(e) {}
            }
            // List of navigable rx-inputs within the same rx-limits-body or add-form-card
            function rxGetNavList(fromInput) {
                const scope = fromInput.closest('.rx-limits-body')
                           || fromInput.closest('.add-form-card')
                           || document.body;
                return Array.from(scope.querySelectorAll('input.rx-input'))
                    .filter(el => !el.disabled && !el.readOnly
                               && !el.classList.contains('rx-locked')
                               && !el.classList.contains('rx-locked-hard')
                               && el.offsetParent !== null);
            }
            // Move focus to previous (dir=-1) or next (dir=1) rx-input
            function rxMoveFocus(fromInput, dir) {
                const list = rxGetNavList(fromInput);
                const idx  = list.indexOf(fromInput);
                if (idx < 0) return false;
                const target = list[idx + dir];
                if (!target) return false;
                target.focus();
                setTimeout(() => { try { target.select(); } catch(e) {} }, 0);
                return true;
            }
            // Normalize stored value to proper "+N / -N / 0" format
            function rxNormalize(input) {
                if (!input) return;
                if (input.value === '' && !input.readOnly) return; // keep placeholder
                input.value = rxFormat(rxParse(input.value));
            }
            // Convert rx-input from type=number to type=text so the "+" sign stays visible.
            // Called once on DOM ready — after this, up/down/left/right are handled by our keydown listener below.
            function rxConvertToText() {
                document.querySelectorAll('input.rx-input').forEach(el => {
                    const step = el.getAttribute('step') || '25';
                    if (el.type === 'number') el.type = 'text';
                    el.setAttribute('inputmode', 'numeric');
                    el.setAttribute('autocomplete', 'off');
                    if (!el.dataset.step) el.dataset.step = step;
                    rxNormalize(el);
                });
            }
            // Global keydown: Up/Down steps the value; Left/Right jumps between rx-inputs
            // (besides Tab). Left/Right only jumps when the caret is at a boundary OR the
            // text is fully selected — otherwise normal caret movement is preserved.
            document.addEventListener('keydown', function(e) {
                const t = e.target;
                if (!t || !t.classList || !t.classList.contains('rx-input')) return;
                if (t.tagName !== 'INPUT') return;
                const key = e.key;
                if (key === 'ArrowUp') {
                    e.preventDefault();
                    rxStep(t, 1);
                } else if (key === 'ArrowDown') {
                    e.preventDefault();
                    rxStep(t, -1);
                } else if (key === 'ArrowLeft') {
                    const len = t.value.length;
                    const fullSel = (t.selectionStart === 0 && t.selectionEnd === len && len > 0);
                    const atStart = (t.selectionStart === 0 && t.selectionEnd === 0);
                    if (fullSel || atStart) {
                        if (rxMoveFocus(t, -1)) e.preventDefault();
                    }
                } else if (key === 'ArrowRight') {
                    const len = t.value.length;
                    const fullSel = (t.selectionStart === 0 && t.selectionEnd === len && len > 0);
                    const atEnd   = (t.selectionStart === len && t.selectionEnd === len);
                    if (fullSel || atEnd) {
                        if (rxMoveFocus(t, 1)) e.preventDefault();
                    }
                }
            });
            // Re-format value on blur so "100" typed by user becomes "+100"
            document.addEventListener('focusout', function(e) {
                const t = e.target;
                if (!t || !t.classList || !t.classList.contains('rx-input')) return;
                if (t.tagName !== 'INPUT') return;
                rxNormalize(t);
            });

            // ─── CYL show/hide based on Group × Category ─────────────────
            // Rules (match PHP catHasCyl):
            //   - LAB group: always show CYL.
            //   - STOCK group: only Single Vision has CYL.
            //   - Fallback: Kryptok/Bifocal/Flattop never have CYL.
            function updateCylVisibility() {
                const grp       = document.getElementById('new_group_select');
                const catHidden = document.getElementById('new_category_hidden');
                const cylFrom   = document.getElementById('new_cyl_from');
                const cylTo     = document.getElementById('new_cyl_to');
                if (!grp || !catHidden || !cylFrom || !cylTo) return;
                const cat  = (catHidden.value || '').toUpperCase().trim();
                const isSV = (cat === 'SINGLE VISION' || cat === 'SV');
                let hide;
                if (grp.value === 'lab') {
                    hide = false;
                } else if (grp.value === 'stock') {
                    hide = !isSV;
                } else {
                    hide = cat.indexOf('KRYPTOK') !== -1
                         || cat.indexOf('BIFOCAL') !== -1
                         || cat.indexOf('FLATTOP') !== -1;
                }
                const cylGroup = cylFrom.closest('.rx-group');
                if (cylGroup) cylGroup.style.display = hide ? 'none' : '';
                if (hide) {
                    cylFrom.value = rxFormat(0);
                    cylTo.value   = rxFormat(0);
                }
            }

            // ─── ADD field lock / defaults based on Category ─────────────
            // SINGLE VISION → ADD forced to 0, fully locked (no double-click)
            // Other categories → ADD default +100 → +300, locked but double-click unlocks
            function updateAddDefaults() {
                const catHidden = document.getElementById('new_category_hidden');
                const addFrom   = document.getElementById('new_add_from');
                const addTo     = document.getElementById('new_add_to');
                if (!catHidden || !addFrom || !addTo) return;
                const cat  = (catHidden.value || '').toUpperCase().trim();
                const isSV = (cat === 'SINGLE VISION' || cat === 'SV');
                [addFrom, addTo].forEach(el => {
                    el.classList.remove('rx-locked', 'rx-locked-hard');
                    el.readOnly = true;
                });
                if (isSV) {
                    addFrom.value = rxFormat(0);
                    addTo.value   = rxFormat(0);
                    addFrom.classList.add('rx-locked-hard');
                    addTo.classList.add('rx-locked-hard');
                    addFrom.title = 'Not applicable for Single Vision';
                    addTo.title   = 'Not applicable for Single Vision';
                } else {
                    addFrom.value = rxFormat(100);
                    addTo.value   = rxFormat(300);
                    addFrom.classList.add('rx-locked');
                    addTo.classList.add('rx-locked');
                    addFrom.title = 'Double-click to edit';
                    addTo.title   = 'Double-click to edit';
                }
                updateCylVisibility();
            }

            // ─── Default Rx limits based on Group ────────────────────────
            // stock: SPH  0 → -800,  CYL -25 → -200,  COMB -1000
            // lab:   SPH +850 → -1100, CYL -25 → -400, COMB -1100
            const RX_DEFAULTS = {
                stock: { sph_from: 0,   sph_to: -800,  cyl_from: -25, cyl_to: -200, comb_max: -1000 },
                lab:   { sph_from: 850, sph_to: -1100, cyl_from: -25, cyl_to: -400, comb_max: -1100 }
            };
            function updateRxLimitsDefault() {
                const grp = document.getElementById('new_group_select');
                if (!grp) return;
                const d = RX_DEFAULTS[grp.value] || RX_DEFAULTS.stock;
                const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = rxFormat(v); };
                set('new_sph_from', d.sph_from);
                set('new_sph_to',   d.sph_to);
                set('new_cyl_from', d.cyl_from);
                set('new_cyl_to',   d.cyl_to);
                set('new_comb_max', d.comb_max);
                updateCylVisibility();
            }

            // ─── Thousand shortcut: typing "180" becomes "180,000" on blur ──
            // Applies only if raw value is > 0 and < 1000
            function maybeMultiplyThousand(raw) {
                const n = parseInt(raw || '0', 10);
                return (n > 0 && n < 1000) ? n * 1000 : n;
            }
            function applyThousandShortcutAdd(input, hiddenId) {
                const hidden = document.getElementById(hiddenId);
                if (!hidden) return;
                const newVal = maybeMultiplyThousand(hidden.value);
                if (newVal > 0) {
                    hidden.value = newVal;
                    input.value = 'IDR ' + new Intl.NumberFormat('id-ID').format(newVal);
                }
            }
            function applyThousandShortcutMulti(input) {
                const hidden = input.nextElementSibling;
                if (!hidden) return;
                const newVal = maybeMultiplyThousand(hidden.value);
                if (newVal > 0) {
                    hidden.value = newVal;
                    input.value = 'IDR ' + new Intl.NumberFormat('id-ID').format(newVal);
                }
            }

            // ════════════════════════════════════════════════════════════════
            //  PER-LENS SAVE — replaces the old "Save All Changes" flow.
            //  Each lens card has its own Save button. Only that lens's data
            //  is sent to the server (AJAX). Avoids the lost-update problem
            //  where a stale form would overwrite other lenses.
            // ════════════════════════════════════════════════════════════════

            // Show a transient toast message in the bottom-right corner
            function showToast(message, kind) {
                const cont = document.getElementById('save-toast-container');
                if (!cont) return;
                const t = document.createElement('div');
                t.className = 'save-toast ' + (kind || 'ok');
                t.textContent = message;
                cont.appendChild(t);
                const lifetime = (kind === 'err') ? 6000 : 3500;
                setTimeout(() => {
                    t.classList.add('fade-out');
                    setTimeout(() => t.remove(), 320);
                }, lifetime);
            }

            // Set the small inline status label next to each Save button
            function setLensStatus(card, kind, text) {
                const status = card.querySelector('.lens-save-status');
                if (!status) return;
                status.className = 'lens-save-status show ' + (kind || '');
                status.textContent = text || '';
                if (kind === 'ok') {
                    setTimeout(() => status.classList.remove('show'), 2500);
                }
            }

            // Collect everything needed to save ONE lens from its card DOM
            function collectLensCardData(card) {
                const group     = card.dataset.group;
                const oldCat    = card.dataset.category;
                const oldName   = card.dataset.name;

                // Rename input (lens name)
                const nameInput = card.querySelector('.lens-name-input');
                const newName   = nameInput ? nameInput.value.trim() : oldName;

                // Cost & selling — hidden inputs hold the raw numeric value
                const costHidden    = card.querySelector('input[name^="price_cost["]');
                const sellingHidden = card.querySelector('input[name^="price_selling["]');
                const cost    = costHidden    ? parseFloat(costHidden.value    || '0') : 0;
                const selling = sellingHidden ? parseFloat(sellingHidden.value || '0') : 0;

                // Features
                const featHidden = card.querySelector('input[name^="price_features["]');
                const features  = featHidden ? (featHidden.value || '') : '';

                // Rx limits — collect all 8 keys. Hidden inputs exist for
                // CYL/ADD when the category doesn't show them.
                const limitKeys = ['sph_from','sph_to','cyl_from','cyl_to','add_from','add_to','comb_max','note'];
                const limits = {};
                limitKeys.forEach(k => {
                    // Match the name attribute containing [k]
                    const input = card.querySelector('[name$="[' + k + ']"]');
                    limits[k] = input ? input.value : '';
                });

                return { group, oldCat, oldName, newName, cost, selling, features, limits };
            }

            // Send one lens save to the server via fetch()
            async function saveLensCard(card) {
                const btn    = card.querySelector('.btn-save-lens');
                const data   = collectLensCardData(card);

                if (!data.group || !data.oldCat || !data.oldName) {
                    setLensStatus(card, 'err', 'Missing lens ID');
                    return;
                }

                // Build form-encoded body
                const body = new URLSearchParams();
                body.append('save_single_lens', '1');
                body.append('group',        data.group);
                body.append('old_category', data.oldCat);
                body.append('old_name',     data.oldName);
                body.append('new_category', data.oldCat); // category change not supported from card UI
                body.append('new_name',     data.newName);
                body.append('cost',         String(data.cost));
                body.append('selling',      String(data.selling));
                body.append('features',     data.features);
                Object.keys(data.limits).forEach(k => {
                    body.append('limits[' + k + ']', data.limits[k]);
                });

                // UI: pending state
                if (btn) btn.disabled = true;
                setLensStatus(card, 'pending', 'Menyimpan...');

                try {
                    const resp = await fetch('lense_price.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString(),
                        credentials: 'same-origin',
                    });

                    // Server returns JSON; if it returns HTML (e.g. login redirect),
                    // this will throw and we'll catch it below.
                    const json = await resp.json();

                    if (!json.ok) {
                        setLensStatus(card, 'err', json.message || 'Gagal');
                        showToast(json.message || 'Gagal menyimpan lens', 'err');
                        return;
                    }

                    // Success: sync the card with what the server actually saved
                        if (json.saved_name && json.saved_name !== data.oldName) {
                            // The lens was renamed (collision suffix). Update card identity
                            // so a second save from the same card targets the new key.
                            card.dataset.name = json.saved_name;
                            const nameInput2 = card.querySelector('.lens-name-input');
                            if (nameInput2) nameInput2.value = json.saved_name;
                            // Update all inputs with name="...[OLDNAME]..." / "...[OLDNAME][...]"
                            const oldKey = '[' + data.oldName + ']';
                            const newKey = '[' + json.saved_name + ']';
                            card.querySelectorAll('input[name], textarea[name]').forEach(el => {
                                if (el.name.indexOf(oldKey) !== -1) {
                                    el.name = el.name.split(oldKey).join(newKey);
                                }
                            });
                            // Also update the delete button's data-name
                            const delBtn = card.querySelector('.btn-delete-lens');
                            if (delBtn) delBtn.dataset.name = json.saved_name;
                        }

                    // Clear dirty indicator on the button
                    if (btn) btn.classList.remove('is-dirty');

                    setLensStatus(card, 'ok', '✓ Saved');
                    showToast(json.message || ('Saved: ' + (json.saved_name || data.newName)),
                              json.renamed ? 'warn' : 'ok');

                } catch (err) {
                    console.error('save lens failed:', err);
                    setLensStatus(card, 'err', 'Network / server error');
                    showToast('Gagal menyimpan: ' + (err.message || err), 'err');
                } finally {
                    if (btn) btn.disabled = false;
                }
            }

            // Mark a card as "dirty" (unsaved changes) whenever any input inside
            // changes. The user sees the Save button turn amber as a hint.
            function markCardDirty(card) {
                const btn = card.querySelector('.btn-save-lens');
                if (btn) btn.classList.add('is-dirty');
                const status = card.querySelector('.lens-save-status');
                if (status) { status.classList.remove('show'); }
            }

            // ─── Init ─────────────────────────────────────────────────────
            document.addEventListener('DOMContentLoaded', () => {
                updateCategoryFilter();
                <?php if (isset($active_tab) && $active_tab==='add'): ?>showTab('add');<?php endif; ?>
                document.querySelectorAll('.currency-display').forEach(el => { if(el.value&&!el.value.includes('IDR')) formatMultipleCurrency(el); });
                document.querySelectorAll('.features-tag-wrapper[data-safe-key]').forEach(el => {
                    initFeatureTags(el.getAttribute('data-safe-key'), JSON.parse(el.getAttribute('data-features')||'[]'));
                });
                initFeatureTags('new_lense',[]);
                // Apply last-opened / previously-opened markers
                applyOpenedMarkers();
                // Convert all .rx-input from type=number to type=text so the "+" sign
                // for positive values stays visible. Must run before rx default setters.
                rxConvertToText();
                updateRxLimitsDefault();
                updateAddDefaults();
                // Double-click on a soft-locked rx input unlocks it for editing
                document.addEventListener('dblclick', (e) => {
                    const t = e.target;
                    if (t && t.classList && t.classList.contains('rx-locked')) {
                        t.readOnly = false;
                        t.classList.remove('rx-locked');
                        t.title = '';
                        setTimeout(() => { try { t.focus(); t.select(); } catch(ex) {} }, 0);
                    }
                });
                // Wire up delete buttons
                document.querySelectorAll('.btn-delete-lens').forEach(btn => {
                    btn.addEventListener('click', () => {
                        confirmDeleteLens(btn.dataset.group, btn.dataset.category, btn.dataset.name);
                    });
                });

                // ─── Wire up per-lens Save buttons (AJAX) ───────────────────
                document.querySelectorAll('.btn-save-lens').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        e.preventDefault();
                        const card = btn.closest('.lens-card');
                        if (!card) return;
                        saveLensCard(card);
                    });
                });

                // ─── Dirty-tracking: mark card amber when any input changes ─
                document.querySelectorAll('#lense-display-container .lens-card').forEach(card => {
                    const onChange = () => markCardDirty(card);
                    card.querySelectorAll('input, textarea').forEach(el => {
                        el.addEventListener('input',  onChange);
                        el.addEventListener('change', onChange);
                    });
                });
                // Thousand-shortcut blur handlers for currency inputs
                const addCost = document.getElementById('add_display_cost');
                const addSell = document.getElementById('add_display_selling');
                if (addCost) addCost.addEventListener('blur', () => applyThousandShortcutAdd(addCost, 'add_real_cost'));
                if (addSell) addSell.addEventListener('blur', () => applyThousandShortcutAdd(addSell, 'add_real_selling'));
                document.querySelectorAll('.currency-display').forEach(el => {
                    el.addEventListener('blur', () => applyThousandShortcutMulti(el));
                });
                // Select-all-on-focus for every text / number input and textarea
                document.querySelectorAll(
                    '.content-area input[type=text], .content-area input[type=number], .content-area textarea'
                ).forEach(el => {
                    el.addEventListener('focus', function() {
                        if (this.readOnly || this.disabled) return;
                        // setTimeout so the browser places the caret first, then we override with select
                        setTimeout(() => { try { this.select(); } catch(e) {} }, 0);
                    });
                });
                // Auto-dismiss status message after 5 seconds
                const statusMsg = document.getElementById('status-message');
                if (statusMsg) {
                    setTimeout(() => {
                        statusMsg.style.opacity = '0';
                        statusMsg.style.maxHeight = '0';
                        statusMsg.style.marginBottom = '0';
                        statusMsg.style.paddingTop = '0';
                        statusMsg.style.paddingBottom = '0';
                        setTimeout(() => statusMsg.remove(), 400);
                    }, 5000);
                }
            });
        </script>

        <!-- Toast container for per-lens save feedback -->
        <div id="save-toast-container"></div>
    </body>
</html>