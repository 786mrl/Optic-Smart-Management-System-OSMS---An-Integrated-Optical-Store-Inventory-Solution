<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

    // ══════════════════════════════════════════════════════════════════
    //  AJAX: Return all analysis data as JSON
    // ══════════════════════════════════════════════════════════════════
    if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
        error_reporting(0);
        ob_start();
        header('Content-Type: application/json');

        // Date filter - safely parse params
        $filterYear  = (isset($_GET['year'])  && ctype_digit(strval($_GET['year']))  && (int)$_GET['year']  > 2000) ? (int)$_GET['year']  : 0;
        $filterMonth = (isset($_GET['month']) && ctype_digit(strval($_GET['month'])) && (int)$_GET['month'] >= 1 && (int)$_GET['month'] <= 12) ? (int)$_GET['month'] : 0;
        $dateWhere = '';
        if ($filterYear > 0 && $filterMonth > 0)
            $dateWhere = " AND YEAR(co.order_date)=" . $filterYear . " AND MONTH(co.order_date)=" . $filterMonth;
        elseif ($filterYear > 0)
            $dateWhere = " AND YEAR(co.order_date)=" . $filterYear;

        // Available years for dropdown
        $availableYears = [];
        $ry = $conn->query("SELECT DISTINCT YEAR(order_date) AS yr FROM customer_orders WHERE order_status=5 ORDER BY yr DESC");
        if ($ry) { while ($row=$ry->fetch_assoc()) { $availableYears[]=(int)$row['yr']; } $ry->free(); }

        // ── Lens cost map ──────────────────────────────────────────────
        $lensJsonPath = __DIR__ . '/data_json/lense_prices.json';
        $lensCostMap  = [];
        if (file_exists($lensJsonPath)) {
            $lj = json_decode(file_get_contents($lensJsonPath), true);
            foreach (['stock','lab'] as $lt) {
                if (!empty($lj[$lt])) {
                    foreach ($lj[$lt] as $cat => $types) {
                        foreach ($types as $typeName => $info) {
                            $k = strtoupper(trim($cat) . ' / ' . trim($typeName));
                            $lensCostMap[$k] = (int)($info['cost'] ?? 0);
                        }
                    }
                }
            }
        }

        // ── Frame cost maps ────────────────────────────────────────────
        $frameCostMap = [];
        $r = $conn->query("SELECT ufc, buy_price FROM frames_main WHERE buy_price IS NOT NULL AND buy_price > 0");
        if ($r) { while ($row = $r->fetch_assoc()) { $frameCostMap[strtoupper(trim($row['ufc']))] = (int)$row['buy_price']; } $r->free(); }
        $r = $conn->query("SELECT ufc, buy_price FROM frame_staging WHERE buy_price IS NOT NULL AND buy_price > 0");
        if ($r) { while ($row = $r->fetch_assoc()) { $k = strtoupper(trim($row['ufc'])); if (!isset($frameCostMap[$k])) $frameCostMap[$k] = (int)$row['buy_price']; } $r->free(); }

        $customFrameMap = [];
        $r = $conn->query("SELECT invoice_number, buy_price FROM custom_frames");
        if ($r) { while ($row = $r->fetch_assoc()) { $customFrameMap[$row['invoice_number']] = (int)$row['buy_price']; } $r->free(); }

        // ── Fetch all finished orders joined with examinations ─────────
        $sql = "
            SELECT
                co.id, co.invoice_number, co.frame_ufc, co.lens_name,
                co.total_amount, co.order_date, co.due_date, co.is_modified,
                COALESCE(co.packaging_cost, 19500) AS packaging_cost,
                ce.customer_name, ce.age, ce.gender, ce.examination_code,
                ce.new_r_sph, ce.new_r_cyl, ce.new_r_ax, ce.new_r_add,
                ce.new_l_sph, ce.new_l_cyl, ce.new_l_ax, ce.new_l_add,
                ce.new_r_visus, ce.new_l_visus,
                ce.need_distance, ce.need_near, ce.need_intermediate,
                ce.visual_habit, ce.digital_usage, ce.lens_modification,
                ce.symptoms, ce.pd_dist
            FROM customer_orders co
            LEFT JOIN customer_examinations ce
                ON co.invoice_number = ce.invoice_number AND co.invoice_number != '00'
            WHERE co.order_status = 5$dateWhere
            ORDER BY co.order_date ASC
        ";
        $result = $conn->query($sql);
        $orders = [];
        if ($result) { while ($row = $result->fetch_assoc()) { $orders[] = $row; } }

        // ── prescription_modifications ────────────────────────────────
        $rxMods = [];
        $r = $conn->query("
            SELECT pm.*, co.frame_ufc, co.lens_name,
                ce.customer_name, ce.gender, ce.age
            FROM prescription_modifications pm
            LEFT JOIN customer_orders co ON pm.invoice_number = co.invoice_number
            LEFT JOIN customer_examinations ce ON pm.invoice_number = ce.invoice_number
            ORDER BY pm.modified_at DESC
            LIMIT 15
        ");
        if ($r) { while ($row = $r->fetch_assoc()) { $rxMods[] = $row; } $r->free(); }
        $rxModCount = 0;
        $r2 = $conn->query("SELECT COUNT(*) AS cnt FROM prescription_modifications");
        if ($r2) { $rxModCount = (int)($r2->fetch_assoc()['cnt'] ?? 0); $r2->free(); }

        // ── Frame inventory analysis ───────────────────────────────────
        $frameInventory = [];
        $r = $conn->query("
            SELECT brand, material, structure, size_range, gender_category,
                stock_age, stock, buy_price, sell_price, lens_shape
            FROM frames_main
        ");
        if ($r) { while ($row = $r->fetch_assoc()) { $frameInventory[] = $row; } $r->free(); }
        $stagingCount = 0;
        $r = $conn->query("SELECT COUNT(*) AS cnt FROM frame_staging");
        if ($r) { $stagingCount = (int)($r->fetch_assoc()['cnt'] ?? 0); $r->free(); }

        // ── Frames missing buy_price ───────────────────────────────────
        $pendingFrames = [];
        $r = $conn->query("SELECT ufc, sell_price FROM frames_main WHERE buy_price = 0 OR buy_price IS NULL LIMIT 20");
        if ($r) { while ($row = $r->fetch_assoc()) { $pendingFrames[] = $row; } $r->free(); }

        // ══ COMPUTE PER-ORDER ═════════════════════════════════════════
        $totalRevenue = 0; $totalCost = 0; $totalProfit = 0;
        $monthlyData  = [];
        $lensCount    = []; $lensRevenue = []; $lensProfit = [];
        $frameCount   = []; $frameRevenue = [];
        $genderCount  = ['MALE'=>0,'FEMALE'=>0,'Unknown'=>0];
        $ageGroups    = ['0–17'=>0,'18–30'=>0,'31–45'=>0,'46–60'=>0,'61+'=>0];
        $dowCount     = array_fill(0, 7, 0);
        $processDays  = [];
        $profitMargins= [];
        $avgByAge     = [];
        $custCount    = [];
        $modifiedCount= 0;

        // Prescription analytics
        $sphBuckets   = ['≤-6'=>0,'-6 to -3'=>0,'-3 to 0'=>0,'0 to +3'=>0,'+3 to +6'=>0,'>+6'=>0,'Unknown'=>0];
        $lensRxCount  = ['singlevision'=>[],'kryptok'=>[],'flattop'=>[],'progressive'=>[]];
        $lensByCat    = ['singlevision'=>[],'kryptok'=>[],'flattop'=>[],'progressive'=>[]];
        $cylSeverity  = ['None (0)'=>0,'Mild (<-1)'=>0,'Moderate (<-2)'=>0,'Severe (≥-2)'=>0,'Unknown'=>0];
        $visionNeeds  = ['Distance Only'=>0,'Near Only'=>0,'Intermediate'=>0,'Multifocal (2+)'=>0];
        $addCount     = 0; // has ADD value
        $highMyopiaCount = 0; // SPH <= -6
        $presbyopiaCount = 0; // has ADD

        foreach ($orders as $o) {
            $amt = (int)$o['total_amount'];
            $pkg = (int)($o['packaging_cost'] ?? 19500);
            // Lens cost
            $ln  = preg_replace('/\s*[\x{2014}\x{2013}\/]\s*/u', ' / ', trim($o['lens_name'] ?? ''));
            $lk  = strtoupper($ln);
            $lc  = $lensCostMap[$lk] ?? 0;
            // Frame cost
            $ufc = strtoupper(trim($o['frame_ufc'] ?? ''));
            $fc  = 0;
            if (strlen($ufc) > 0 && is_numeric($ufc[0])) {
                $fc = $customFrameMap[$o['invoice_number'] ?? ''] ?? 0;
            } else {
                $fc = $frameCostMap[$ufc] ?? 0;
            }
            $cost   = $lc + $fc + $pkg;
            $profit = $amt - $cost;

            $totalRevenue += $amt;
            $totalCost    += $cost;
            $totalProfit  += $profit;

            // Monthly
            $ym = !empty($o['order_date']) ? date('Y-m', strtotime($o['order_date'])) : 'unknown';
            if (!isset($monthlyData[$ym])) $monthlyData[$ym] = ['revenue'=>0,'cost'=>0,'profit'=>0,'count'=>0,'label'=>''];
            $monthlyData[$ym]['revenue'] += $amt;
            $monthlyData[$ym]['cost']    += $cost;
            $monthlyData[$ym]['profit']  += $profit;
            $monthlyData[$ym]['count']++;
            $monthlyData[$ym]['label'] = !empty($o['order_date']) ? date('M Y', strtotime($o['order_date'])) : 'Unknown';

            // Lens — split into 4 separate categories
            $lnameRaw = trim($o['lens_name'] ?? '');
            $lCatKey  = ''; // reset per order
            if ($lnameRaw !== '') {
                // Detect category: check if lens_name STARTS WITH known category keyword
                // More robust than splitting — handles any separator/encoding variant
                $lUpper = strtoupper($lnameRaw);
                if (strpos($lUpper, 'SINGLE VISION') === 0) {
                    $lCatRaw = 'SINGLE VISION';
                    // Strip 'SINGLE VISION' prefix + any separator to get variant
                    $lVar = trim(preg_replace('/^SINGLE\s+VISION\s*[^A-Z0-9]*/i', '', $lnameRaw));
                } elseif (strpos($lUpper, 'PROGRESSIVE') === 0) {
                    $lCatRaw = 'PROGRESSIVE';
                    $lVar = trim(preg_replace('/^PROGRESSIVE\s*[^A-Z0-9]*/i', '', $lnameRaw));
                } elseif (strpos($lUpper, 'KRYPTOK') === 0) {
                    $lCatRaw = 'KRYPTOK';
                    $lVar = trim(preg_replace('/^KRYPTOK\s*[^A-Z0-9]*/i', '', $lnameRaw));
                } elseif (strpos($lUpper, 'FLATTOP') === 0) {
                    $lCatRaw = 'FLATTOP';
                    $lVar = trim(preg_replace('/^FLATTOP\s*[^A-Z0-9]*/i', '', $lnameRaw));
                } else {
                    $lCatRaw = strtoupper($lnameRaw);
                    $lVar = $lnameRaw;
                }
                if ($lVar === '') $lVar = $lnameRaw;
                if (strpos($lCatRaw, 'PROGRESSIVE') !== false)  $lCatKey = 'progressive';
                elseif (strpos($lCatRaw, 'KRYPTOK') !== false)  $lCatKey = 'kryptok';
                elseif (strpos($lCatRaw, 'FLATTOP') !== false)  $lCatKey = 'flattop';
                else                                              $lCatKey = 'singlevision';
                // Progressive: keep full name; others: variant only
                $lkey = ($lCatKey === 'progressive') ? $lnameRaw : $lVar;
                if (!isset($lensByCat[$lCatKey][$lkey])) {
                    $lensByCat[$lCatKey][$lkey] = ['count'=>0,'revenue'=>0,'profit'=>0];
                }
                $lensByCat[$lCatKey][$lkey]['count']++;
                $lensByCat[$lCatKey][$lkey]['revenue'] += $amt;
                $lensByCat[$lCatKey][$lkey]['profit']  += $profit;
            }
            // Keep flat lensCount for existing analytics
            $lname = ($lnameRaw !== '') ? $lnameRaw : '—';
            if (!isset($lensCount[$lname])) { $lensCount[$lname]=0; $lensRevenue[$lname]=0; $lensProfit[$lname]=0; }
            $lensCount[$lname]++;
            $lensRevenue[$lname] += $amt;
            $lensProfit[$lname]  += $profit;

            // Lens size per category — one key per ORDER (R + L combined)
            // Convention: if abs(value) > 20, stored as centesimal (e.g. -50 means -0.50)
            $rS = trim($o['new_r_sph'] ?? ''); $rC = trim($o['new_r_cyl'] ?? '');
            $lS = trim($o['new_l_sph'] ?? ''); $lC = trim($o['new_l_cyl'] ?? '');
            if (($rS !== '' || $lS !== '') && $lCatKey !== '') {
                $rxBuild = function($s, $c) {
                    if ($s === '') return null;
                    $sV = floatval($s); $cV = ($c !== '') ? floatval($c) : 0;
                    if (abs($sV) > 20) $sV /= 100;
                    if (abs($cV) > 20) $cV /= 100;
                    $sF = ($sV > 0 ? '+' : '') . number_format($sV, 2);
                    $cF = ($cV != 0) ? ' / CYL ' . ($cV > 0 ? '+' : '') . number_format($cV, 2) : '';
                    return 'SPH ' . $sF . $cF;
                };
                $rPart = $rxBuild($rS, $rC);
                $lPart = $rxBuild($lS, $lC);
                if ($rPart !== null && $lPart !== null && $rPart !== $lPart) {
                    $rxKey = 'R: ' . $rPart . '  |  L: ' . $lPart;
                } elseif ($rPart !== null && $lPart !== null) {
                    $rxKey = $rPart . ' (R & L)';
                } elseif ($rPart !== null) {
                    $rxKey = 'R: ' . $rPart;
                } else {
                    $rxKey = 'L: ' . $lPart;
                }
                if (!isset($lensRxCount[$lCatKey][$rxKey])) $lensRxCount[$lCatKey][$rxKey] = 0;
                $lensRxCount[$lCatKey][$rxKey]++;
            }

            // Frame
            $fu = trim($o['frame_ufc'] ?? '—');
            if (!isset($frameCount[$fu])) { $frameCount[$fu]=0; $frameRevenue[$fu]=0; }
            $frameCount[$fu]++;
            $frameRevenue[$fu] += $amt;

            // Gender
            $g = strtoupper(trim($o['gender'] ?? ''));
            if ($g === 'MALE')        $genderCount['MALE']++;
            elseif ($g === 'FEMALE')  $genderCount['FEMALE']++;
            else                      $genderCount['Unknown']++;

            // Age
            $age = (int)($o['age'] ?? 0);
            if ($age > 0) {
                $ag = $age <= 17 ? '0–17' : ($age <= 30 ? '18–30' : ($age <= 45 ? '31–45' : ($age <= 60 ? '46–60' : '61+')));
                $ageGroups[$ag]++;
                if (!isset($avgByAge[$ag])) $avgByAge[$ag] = ['sum'=>0,'cnt'=>0];
                $avgByAge[$ag]['sum'] += $amt;
                $avgByAge[$ag]['cnt']++;
            }

            // DOW
            if (!empty($o['order_date'])) $dowCount[(int)date('w', strtotime($o['order_date']))]++;

            // Processing days
            if (!empty($o['order_date']) && !empty($o['due_date'])) {
                $diff = (int)round((strtotime($o['due_date']) - strtotime($o['order_date'])) / 86400);
                if ($diff >= 0 && $diff <= 60) $processDays[] = $diff;
            }

            // Margin
            if ($amt > 0) $profitMargins[] = round($profit / $amt * 100);

            // Customers
            $n = trim($o['customer_name'] ?? '—');
            if (!isset($custCount[$n])) $custCount[$n] = ['count'=>0,'revenue'=>0];
            $custCount[$n]['count']++;
            $custCount[$n]['revenue'] += $amt;

            // is_modified
            if ((int)($o['is_modified'] ?? 0) === 1) $modifiedCount++;

            // ── Prescription analytics ──────────────────────────────
            $rSph = floatval($o['new_r_sph'] ?? 0);
            $lSph = floatval($o['new_l_sph'] ?? 0);
            $rCyl = floatval($o['new_r_cyl'] ?? 0);
            $lCyl = floatval($o['new_l_cyl'] ?? 0);
            $rAdd = trim($o['new_r_add'] ?? '');
            $lAdd = trim($o['new_l_add'] ?? '');

            $hasSph = !empty(trim($o['new_r_sph'] ?? '')) || !empty(trim($o['new_l_sph'] ?? ''));
            if ($hasSph) {
                $avgSph = ($rSph + $lSph) / 2;
                if ($avgSph <= -6)        $sphBuckets['≤-6']++;
                elseif ($avgSph <= -3)    $sphBuckets['-6 to -3']++;
                elseif ($avgSph < 0)      $sphBuckets['-3 to 0']++;
                elseif ($avgSph <= 3)     $sphBuckets['0 to +3']++;
                elseif ($avgSph <= 6)     $sphBuckets['+3 to +6']++;
                else                       $sphBuckets['>+6']++;
                if ($avgSph <= -6) $highMyopiaCount++;
            } else {
                $sphBuckets['Unknown']++;
            }

            $hasCyl = !empty(trim($o['new_r_cyl'] ?? '')) || !empty(trim($o['new_l_cyl'] ?? ''));
            if ($hasCyl) {
                $maxCyl = min($rCyl, $lCyl); // more negative = worse
                if ($maxCyl >= 0)         $cylSeverity['None (0)']++;
                elseif ($maxCyl > -1)     $cylSeverity['Mild (<-1)']++;
                elseif ($maxCyl > -2)     $cylSeverity['Moderate (<-2)']++;
                else                       $cylSeverity['Severe (≥-2)']++;
            } else {
                $cylSeverity['Unknown']++;
            }

            $hasAdd = (!empty($rAdd) && $rAdd !== '0') || (!empty($lAdd) && $lAdd !== '0');
            if ($hasAdd) { $presbyopiaCount++; }

            // Vision needs (flags from examination)
            $nd  = (int)($o['need_distance']     ?? 0);
            $nn  = (int)($o['need_near']         ?? 0);
            $ni  = (int)($o['need_intermediate'] ?? 0);
            $sum = $nd + $nn + $ni;
            if ($sum >= 2)        $visionNeeds['Multifocal (2+)']++;
            elseif ($ni)          $visionNeeds['Intermediate']++;
            elseif ($nn && !$nd)  $visionNeeds['Near Only']++;
            else                   $visionNeeds['Distance Only']++;
        }

        ksort($monthlyData);
        $monthlyData = array_slice($monthlyData, -18, 18, true);

        arsort($lensCount);
        $topLenses = [];
        $i = 0;
        foreach ($lensCount as $name => $cnt) {
            if ($i++ >= 8) break;
            $topLenses[] = ['name'=>$name,'count'=>$cnt,'revenue'=>$lensRevenue[$name],'profit'=>$lensProfit[$name],
                'margin'=>($lensRevenue[$name]>0 ? round($lensProfit[$name]/$lensRevenue[$name]*100) : 0)];
        }

        // Build per-category sorted lens arrays
        $lensCatOut = [];
        foreach ($lensByCat as $catKey => $items) {
            uasort($items, function($a,$b){ return $b['count'] - $a['count']; });
            $catArr = [];
            foreach ($items as $vname => $d) {
                $catArr[] = [
                    'name'   => $vname,
                    'count'  => $d['count'],
                    'margin' => ($d['revenue']>0 ? round($d['profit']/$d['revenue']*100) : 0),
                ];
            }
            $lensCatOut[$catKey] = $catArr;
        }

        // Frame shape & size analytics — join ordered UFCs back to frames_main
        $shapeCount    = [];
        $sizeCount     = [];
        $brandCount    = [];
        $structCount   = [];

        // Build lookup: ufc => [lens_shape, size_range, brand, structure] from frames_main + staging
        $frameAttrMap  = [];
        $rfa = $conn->query("SELECT ufc, lens_shape, size_range, brand, structure FROM frames_main");
        if ($rfa) { while ($row = $rfa->fetch_assoc()) { $frameAttrMap[strtoupper(trim($row['ufc']))] = $row; } $rfa->free(); }
        $rfa = $conn->query("SELECT ufc, lens_shape, size_range, brand, structure FROM frame_staging");
        if ($rfa) { while ($row = $rfa->fetch_assoc()) { $k = strtoupper(trim($row['ufc'])); if (!isset($frameAttrMap[$k])) $frameAttrMap[$k] = $row; } $rfa->free(); }

        foreach ($frameCount as $ufc => $cnt) {
            $k    = strtoupper(trim($ufc));
            $attr = $frameAttrMap[$k] ?? null;
            $shape  = trim($attr['lens_shape'] ?? 'Unknown');
            $size   = trim($attr['size_range'] ?? 'Unknown');
            $brand  = trim($attr['brand']      ?? 'Unknown');
            $struct = trim($attr['structure']  ?? 'Unknown');
            if ($shape  === '') $shape  = 'Unknown';
            if ($size   === '') $size   = 'Unknown';
            if ($brand  === '') $brand  = 'Unknown';
            if ($struct === '') $struct = 'Unknown';
            if (!isset($shapeCount[$shape]))  $shapeCount[$shape]  = 0;
            if (!isset($sizeCount[$size]))    $sizeCount[$size]    = 0;
            if (!isset($brandCount[$brand]))  $brandCount[$brand]  = 0;
            if (!isset($structCount[$struct]))$structCount[$struct] = 0;
            $shapeCount[$shape]  += $cnt;
            $sizeCount[$size]    += $cnt;
            $brandCount[$brand]  += $cnt;
            $structCount[$struct]+= $cnt;
        }
        arsort($shapeCount); arsort($sizeCount); arsort($brandCount); arsort($structCount);
        $topBrands = $brandCount; // all brands, JS handles display limit

        uasort($custCount, function($a,$b) { return $b['count'] - $a['count']; });
        $topCust = [];
        foreach (array_slice($custCount, 0, 6, true) as $n => $d2) {
            $topCust[] = ['name'=>$n,'count'=>$d2['count'],'revenue'=>$d2['revenue']];
        }

        // Avg by age
        $avgByAgeOut = [];
        foreach ($avgByAge as $ag => $d2) {
            $avgByAgeOut[$ag] = $d2['cnt'] > 0 ? (int)round($d2['sum']/$d2['cnt']) : 0;
        }

        // Frame inventory stats
        $stockAge      = ['very old'=>0,'old'=>0,'new'=>0,'null'=>0];
        $materials     = [];
        $structures    = ['full-rim'=>0,'semi-rimless'=>0,'rimless'=>0];
        $genderCat     = ['men'=>0,'female'=>0,'unisex'=>0];
        $stockByShape  = ['all'=>[],'standar'=>[],'menengah'=>[],'menengah_atas'=>[],'premium'=>[],'other'=>[]];
        $stockByGender = ['all'=>[],'standar'=>[],'menengah'=>[],'menengah_atas'=>[],'premium'=>[],'other'=>[]];
        $totalFrameStock = 0;
        $framesMissingBuy = 0;
        foreach ($frameInventory as $f) {
            $sa = strtolower($f['stock_age'] ?? '');
            if (isset($stockAge[$sa])) $stockAge[$sa] += (int)($f['stock'] ?? 0);
            else $stockAge['null'] += (int)($f['stock'] ?? 0);
            $mat = trim($f['material'] ?? 'Unknown');
            if (!isset($materials[$mat])) $materials[$mat] = 0;
            $materials[$mat]++;
            $st = strtolower($f['structure'] ?? '');
            if (isset($structures[$st])) $structures[$st]++;
            $gc2 = strtolower($f['gender_category'] ?? '');
            if (isset($genderCat[$gc2])) $genderCat[$gc2]++;
            // Price tier
            $sp = (float)($f['sell_price'] ?? 0);
            if      ($sp >= 350001)                    $tier = 'premium';
            elseif  ($sp >= 201000)                    $tier = 'menengah_atas';
            elseif  ($sp >= 101000)                    $tier = 'menengah';
            elseif  ($sp >= 50000)                     $tier = 'standar';
            else                                        $tier = 'other';

            // Stock by lens_shape — normalize to Title Case to merge duplicates
            $sh = trim($f['lens_shape'] ?? '');
            $sh = ($sh === '') ? 'Not Filled' : ucwords(strtolower($sh));
            if (!isset($stockByShape['all'][$sh]))           $stockByShape['all'][$sh]           = 0;
            if (!isset($stockByShape[$tier][$sh]))           $stockByShape[$tier][$sh]           = 0;
            $stockByShape['all'][$sh]           += (int)($f['stock'] ?? 0);
            $stockByShape[$tier][$sh]           += (int)($f['stock'] ?? 0);

            // Stock by gender_category
            $gcKey = strtolower(trim($f['gender_category'] ?? ''));
            if ($gcKey === '') $gcKey = 'unknown';
            if (!isset($stockByGender['all'][$gcKey]))       $stockByGender['all'][$gcKey]       = 0;
            if (!isset($stockByGender[$tier][$gcKey]))       $stockByGender[$tier][$gcKey]       = 0;
            $stockByGender['all'][$gcKey]       += (int)($f['stock'] ?? 0);
            $stockByGender[$tier][$gcKey]       += (int)($f['stock'] ?? 0);
            $totalFrameStock += (int)($f['stock'] ?? 0);
            if ((float)($f['buy_price'] ?? 0) == 0) $framesMissingBuy++;
        }
        arsort($materials);
        $topMaterials = array_slice($materials, 0, 6, true);

        // Margin histogram
        $marginBuckets = [];
        foreach ($profitMargins as $m) {
            $b = min(9, (int)floor(max(0,$m)/10));
            $label = ($b*10).'–'.($b*10+10).'%';
            if (!isset($marginBuckets[$label])) $marginBuckets[$label] = 0;
            $marginBuckets[$label]++;
        }

        $totalOrders    = count($orders);
        $avgOrderValue  = $totalOrders > 0 ? (int)round($totalRevenue/$totalOrders) : 0;
        $avgProcessDays = count($processDays) > 0 ? round(array_sum($processDays)/count($processDays),1) : 0;
        $avgMargin      = count($profitMargins) > 0 ? round(array_sum($profitMargins)/count($profitMargins),1) : 0;
        $thisYM         = date('Y-m');
        $lastYM         = date('Y-m', strtotime('-1 month'));
        $thisMonthRev   = $monthlyData[$thisYM]['revenue'] ?? 0;
        $lastMonthRev   = $monthlyData[$lastYM]['revenue'] ?? 0;
        $momChange      = $lastMonthRev > 0 ? round(($thisMonthRev-$lastMonthRev)/$lastMonthRev*100,1) : null;

        ob_clean();
        echo json_encode([
            'summary' => [
                'totalOrders'     => $totalOrders,
                'totalRevenue'    => $totalRevenue,
                'totalCost'       => $totalCost,
                'totalProfit'     => $totalProfit,
                'avgMargin'       => $avgMargin,
                'avgOrderValue'   => $avgOrderValue,
                'avgProcessDays'  => $avgProcessDays,
                'thisMonthRev'    => $thisMonthRev,
                'lastMonthRev'    => $lastMonthRev,
                'momChange'       => $momChange,
                'rxModCount'      => $rxModCount,
                'stagingCount'    => $stagingCount,
                'pendingFrameCount'=> count($pendingFrames),
                'modifiedOrders'  => $modifiedCount,
                'highMyopia'      => $highMyopiaCount,
                'presbyopia'      => $presbyopiaCount,
                'totalFrameStock' => $totalFrameStock,
                'framesMissingBuy'=> $framesMissingBuy,
            ],
            'availableYears' => $availableYears,
            'activeFilter'   => ['year'=>$filterYear,'month'=>$filterMonth],
            'monthly'        => array_values($monthlyData),
            'topLenses'      => $topLenses,
            'lensCatOut'     => $lensCatOut,
            'lensRxCount'    => $lensRxCount, // nested by category
            'shapeCount'     => $shapeCount,
            'sizeCount'      => $sizeCount,
            'structCount'    => $structCount,
            'topBrands'      => $topBrands,
            'genderCount'    => $genderCount,
            'ageGroups'      => $ageGroups,
            'dowCount'       => $dowCount,
            'avgByAge'       => $avgByAgeOut,
            'marginBuckets'  => $marginBuckets,
            'topCustomers'   => $topCust,
            'rxMods'         => $rxMods,
            'pendingFrames'  => $pendingFrames,
            'sphBuckets'     => $sphBuckets,
            'cylSeverity'    => $cylSeverity,
            'visionNeeds'    => $visionNeeds,
            'stockAge'       => $stockAge,
            'stockByShape'   => $stockByShape,
            'stockByGender'  => $stockByGender,
            'frameRawList'   => array_map(function($f) {
                return [
                    'shape'   => (trim($f['lens_shape']??'')==='' ? 'Not Filled' : ucwords(strtolower(trim($f['lens_shape'])))),
                    'gender'  => strtolower(trim($f['gender_category']??'unknown')),
                    'material'=> ucwords(strtolower(trim($f['material']??'Unknown'))),
                    'struct'  => strtolower(trim($f['structure']??'unknown')),
                    'stock'   => (int)($f['stock']??0),
                    'sell'    => (float)($f['sell_price']??0),
                ];
            }, $frameInventory),
            'topMaterials'   => $topMaterials,
            'structures'     => $structures,
            'genderCat'      => $genderCat,
        ]);
        exit();
    }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <?php include 'pwa_head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
    <title>Smart Analysis</title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --sa-green:  #00ff88;
            --sa-amber:  #ffaa00;
            --sa-blue:   #00cfff;
            --sa-purple: #aa88ff;
            --sa-red:    #ff6b6b;
            --sa-teal:   #00e5cc;
            --sa-pink:   #ff88cc;
        }
        .sa-body { padding: 24px 20px 60px; max-width: 1200px; margin: auto; font-family: 'Space Grotesk', sans-serif; }

        /* ── Page Header ── */
        .sa-page-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:28px; flex-wrap:wrap; gap:12px; }
        .sa-page-title  { font-size:1.5rem; font-weight:800; color:var(--text-main); letter-spacing:0.5px; }
        .sa-page-sub    { font-size:0.72rem; color:var(--text-muted); margin-top:4px; letter-spacing:0.6px; text-transform:uppercase; }
        .sa-header-right { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .sa-last-updated { font-size:0.65rem; color:var(--text-muted); font-family:'JetBrains Mono',monospace; }

        .sa-refresh-btn {
            background:var(--bg-color); border:1px solid rgba(0,255,136,0.25);
            border-radius:14px; color:var(--sa-green); font-size:0.75rem; font-weight:700;
            padding:9px 18px; cursor:pointer; font-family:inherit;
            box-shadow:4px 4px 8px var(--shadow-dark),-4px -4px 8px var(--shadow-light);
            letter-spacing:0.5px; transition:all 0.2s;
        }
        .sa-refresh-btn:hover { background:rgba(0,255,136,0.07); }
        .sa-refresh-btn:disabled { opacity:0.4; cursor:not-allowed; }

        /* ── Loading ── */
        #sa-loading {
            position:fixed; inset:0; background:rgba(0,0,0,0.6);
            display:flex; align-items:center; justify-content:center;
            z-index:9999; backdrop-filter:blur(4px); transition:opacity 0.3s;
        }
        #sa-loading.hidden { opacity:0; pointer-events:none; }
        .sa-loader-box { background:var(--bg-color); border-radius:20px; padding:32px 48px; text-align:center; box-shadow:12px 12px 28px var(--shadow-dark),-12px -12px 28px var(--shadow-light); }
        .sa-loader-spinner { width:44px; height:44px; border:3px solid rgba(0,255,136,0.12); border-top-color:var(--sa-green); border-radius:50%; animation:sa-spin 0.75s linear infinite; margin:0 auto 14px; }
        @keyframes sa-spin { to { transform:rotate(360deg); } }
        .sa-loader-text { font-size:0.78rem; color:var(--text-muted); letter-spacing:0.5px; }

        /* ── Section title ── */
        .sa-section-title {
            font-size:0.65rem; font-weight:700; letter-spacing:1.5px;
            text-transform:uppercase; color:var(--text-muted);
            margin:32px 0 12px; display:flex; align-items:center; gap:8px;
        }
        .sa-section-title::after { content:''; flex:1; height:1px; background:rgba(255,255,255,0.05); }

        /* ── Card ── */
        .sa-card {
            background:var(--bg-color); border-radius:18px; padding:18px 20px;
            box-shadow:6px 6px 14px var(--shadow-dark),-6px -6px 14px var(--shadow-light);
            border:1px solid rgba(255,255,255,0.04);
        }
        .sa-card-title {
            font-size:0.65rem; font-weight:700; letter-spacing:0.9px;
            text-transform:uppercase; color:var(--text-muted); margin-bottom:14px;
            display:flex; align-items:center; gap:6px;
        }

        /* ── KPI Grid ── */
        .sa-kpi-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(155px,1fr)); gap:12px; }
        .sa-kpi {
            background:var(--bg-color); border-radius:16px; padding:16px 18px;
            box-shadow:6px 6px 14px var(--shadow-dark),-6px -6px 14px var(--shadow-light);
            border:1px solid rgba(255,255,255,0.04);
            display:flex; flex-direction:column; gap:5px; position:relative; overflow:hidden;
        }
        .sa-kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:var(--kc,var(--sa-green)); opacity:0.55; }
        .sa-kpi-icon { font-size:1rem; }
        .sa-kpi-val  { font-size:1.25rem; font-weight:800; font-family:'JetBrains Mono',monospace; color:var(--kc,var(--sa-green)); line-height:1; }
        .sa-kpi-val.sm { font-size:0.92rem; }
        .sa-kpi-label { font-size:0.58rem; color:var(--text-muted); letter-spacing:0.8px; text-transform:uppercase; font-weight:600; }
        .sa-badge { font-size:0.6rem; font-weight:700; padding:2px 8px; border-radius:20px; width:fit-content; }
        .sa-badge.up   { background:rgba(0,255,136,0.12); color:var(--sa-green); }
        .sa-badge.down { background:rgba(255,107,107,0.12); color:var(--sa-red); }
        .sa-badge.neu  { background:rgba(255,255,255,0.06); color:var(--text-muted); }
        .sa-badge.blue { background:rgba(0,207,255,0.12); color:var(--sa-blue); }

        /* ── Layouts ── */
        .sa-2col { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .sa-3col { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; }
        .sa-4col { display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:14px; }
        @media (max-width:900px) { .sa-3col,.sa-4col { grid-template-columns:1fr 1fr; } }
        @media (max-width:600px) { .sa-2col,.sa-3col,.sa-4col { grid-template-columns:1fr; } }

        /* ── Chart ── */
        .sa-chart-wrap { position:relative; width:100%; }
        .sa-chart-wrap canvas { width:100% !important; height:100% !important; }

        /* ── Bar list ── */
        .sa-bar-list { display:flex; flex-direction:column; gap:9px; }
        .sa-bar-item { display:flex; flex-direction:column; gap:4px; }
        .sa-bar-row  { display:flex; justify-content:space-between; align-items:baseline; gap:8px; }
        .sa-bar-name { font-size:0.72rem; font-weight:600; color:var(--text-main); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:62%; }
        .sa-bar-meta { font-size:0.62rem; color:var(--text-muted); font-family:'JetBrains Mono',monospace; white-space:nowrap; }
        .sa-bar-track { height:5px; background:rgba(255,255,255,0.05); border-radius:4px; overflow:hidden; }
        .sa-bar-fill  { height:100%; border-radius:4px; background:var(--bc,var(--sa-green)); transition:width 0.7s cubic-bezier(.4,0,.2,1); }

        /* ── Donut ── */
        .sa-donut-wrap { display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
        .sa-donut-canvas { flex-shrink:0; }
        .sa-donut-legend { display:flex; flex-direction:column; gap:7px; flex:1; }
        .sa-legend-row   { display:flex; align-items:center; gap:7px; }
        .sa-legend-dot   { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
        .sa-legend-label { font-size:0.68rem; color:var(--text-muted); flex:1; }
        .sa-legend-val   { font-size:0.7rem; font-weight:700; color:var(--text-main); font-family:'JetBrains Mono',monospace; }

        /* ── DOW heatmap ── */
        .sa-dow-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:6px; }
        .sa-dow-cell { border-radius:10px; padding:10px 4px; text-align:center; background:var(--bg-color); box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light); transition:transform 0.15s; }
        .sa-dow-cell:hover { transform:translateY(-2px); }
        .sa-dow-day   { font-size:0.58rem; color:var(--text-muted); letter-spacing:0.4px; text-transform:uppercase; margin-bottom:4px; }
        .sa-dow-count { font-size:1rem; font-weight:800; font-family:'JetBrains Mono',monospace; }

        /* ── Progress ring ── */
        .sa-ring-wrap { display:flex; align-items:center; gap:16px; }
        .sa-ring-val  { font-size:1.9rem; font-weight:800; font-family:'JetBrains Mono',monospace; line-height:1; }
        .sa-ring-sub  { font-size:0.62rem; color:var(--text-muted); letter-spacing:0.4px; margin-top:4px; }

        /* ── Alert banners ── */
        .sa-alert { background:rgba(255,107,107,0.07); border:1px solid rgba(255,107,107,0.2); border-radius:14px; padding:11px 16px; font-size:0.75rem; color:var(--sa-red); font-weight:600; display:flex; align-items:flex-start; gap:10px; }
        .sa-alert.amber { background:rgba(255,170,0,0.07); border-color:rgba(255,170,0,0.2); color:var(--sa-amber); }
        .sa-alert.green { background:rgba(0,255,136,0.06); border-color:rgba(0,255,136,0.2); color:var(--sa-green); }
        .sa-alert.blue  { background:rgba(0,207,255,0.06); border-color:rgba(0,207,255,0.2); color:var(--sa-blue); }

        /* ── Table ── */
        .sa-tbl { width:100%; border-collapse:collapse; }
        .sa-tbl th { font-size:0.58rem; font-weight:700; letter-spacing:0.8px; text-transform:uppercase; color:var(--text-muted); padding:0 6px 8px 0; text-align:left; border-bottom:1px solid rgba(255,255,255,0.06); }
        .sa-tbl td { font-size:0.72rem; padding:8px 6px 8px 0; border-bottom:1px solid rgba(255,255,255,0.04); color:var(--text-main); vertical-align:middle; }
        .sa-tbl tr:last-child td { border-bottom:none; }

        /* ── Rx card ── */
        .sa-rx-grid { display:flex; flex-direction:column; gap:10px; }
        .sa-rx-card { background:rgba(255,255,255,0.025); border:1px solid rgba(255,255,255,0.06); border-radius:12px; padding:12px 14px; }
        .sa-rx-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:6px; }
        .sa-rx-name  { font-size:0.8rem; font-weight:700; }
        .sa-rx-date  { font-size:0.62rem; color:var(--text-muted); font-family:'JetBrains Mono',monospace; }
        .sa-rx-inv   { font-size:0.65rem; background:rgba(0,207,255,0.1); color:var(--sa-blue); padding:2px 8px; border-radius:10px; font-weight:700; }
        .sa-rx-table { display:grid; grid-template-columns:auto repeat(4,1fr); gap:4px 10px; font-size:0.68rem; }
        .sa-rx-table .rx-h { font-size:0.58rem; color:var(--text-muted); font-weight:700; letter-spacing:0.5px; text-align:center; padding-bottom:4px; border-bottom:1px solid rgba(255,255,255,0.05); }
        .sa-rx-table .rx-eye { color:var(--text-muted); font-weight:700; font-size:0.65rem; display:flex; align-items:center; }
        .sa-rx-table .rx-val { text-align:center; font-family:'JetBrains Mono',monospace; color:var(--text-main); padding:2px 0; }
        .sa-rx-table .rx-val.changed { color:var(--sa-green); font-weight:700; }
        .sa-rx-table .rx-val.empty { color:rgba(255,255,255,0.2); }

        /* ── Stock modal ── */
        .sa-modal-overlay {
            position:fixed; inset:0; background:rgba(0,0,0,0.65);
            display:flex; align-items:center; justify-content:center;
            z-index:9998; backdrop-filter:blur(4px);
            animation:sa-fadein 0.18s ease;
        }
        @keyframes sa-fadein { from{opacity:0} to{opacity:1} }
        .sa-modal {
            background:var(--bg-color); border-radius:20px; padding:24px 26px;
            width:90%; max-width:480px; max-height:80vh; overflow-y:auto;
            box-shadow:12px 12px 28px var(--shadow-dark),-12px -12px 28px var(--shadow-light);
            border:1px solid rgba(255,255,255,0.06); position:relative;
        }
        .sa-modal-title { font-size:1rem; font-weight:800; color:var(--text-main); margin-bottom:4px; }
        .sa-modal-sub   { font-size:0.65rem; color:var(--text-muted); letter-spacing:0.6px; text-transform:uppercase; margin-bottom:20px; }
        .sa-modal-close {
            position:absolute; top:16px; right:18px; background:none; border:none;
            color:var(--text-muted); font-size:1.2rem; cursor:pointer; line-height:1;
            padding:4px 8px; border-radius:8px; transition:color 0.15s;
        }
        .sa-modal-close:hover { color:var(--text-main); }
        .sa-modal-sec { margin-bottom:20px; }
        .sa-modal-sec-title {
            font-size:0.62rem; font-weight:700; letter-spacing:1px; text-transform:uppercase;
            color:var(--text-muted); margin-bottom:10px; padding-bottom:6px;
            border-bottom:1px solid rgba(255,255,255,0.06);
        }
        .sa-kpi.clickable { cursor:pointer; transition:transform 0.15s, box-shadow 0.15s; }
        .sa-kpi.clickable:hover { transform:translateY(-2px); box-shadow:8px 8px 18px var(--shadow-dark),-8px -8px 18px var(--shadow-light); }

        /* Modal select dropdowns */
        .sa-mselect {
            width: 100%;
            background: var(--bg-color);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 10px;
            color: var(--text-main);
            font-size: 0.72rem;
            font-weight: 600;
            padding: 8px 10px;
            cursor: pointer;
            font-family: inherit;
            outline: none;
            box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
            transition: border-color 0.15s;
            -webkit-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='rgba(255,255,255,0.3)'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 28px;
        }
        .sa-mselect:focus { border-color: rgba(0,207,255,0.4); }
        .sa-mselect option { background: #1a1a2e; color: var(--text-main); }

        /* Filter chips (kept for other uses) */
        .sa-mchip {
            background: var(--bg-color);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 20px;
            color: var(--text-muted);
            font-size: 0.67rem; font-weight: 700;
            padding: 5px 12px;
            cursor: pointer; font-family: inherit;
            box-shadow: 3px 3px 6px var(--shadow-dark), -3px -3px 6px var(--shadow-light);
            transition: all 0.15s; letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .sa-mchip em { font-style:normal; opacity:0.55; font-weight:400; margin-left:4px; font-size:0.6rem; }
        .sa-mchip:hover { border-color: rgba(255,255,255,0.22); color: var(--text-main); }
        .sa-mchip.active { background: rgba(0,207,255,0.1); border-color: rgba(0,207,255,0.35); color: var(--sa-blue); }
        .sa-modal-sec-title { font-size:0.58rem; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:var(--text-muted); }

        /* ── Expand button ── */
        .sa-expand-btn {
            margin-top: 10px;
            background: var(--bg-color);
            border: 1px solid rgba(255,255,255,0.09);
            border-radius: 20px;
            color: var(--text-muted);
            font-size: 0.67rem;
            font-weight: 700;
            padding: 5px 14px;
            cursor: pointer;
            font-family: inherit;
            letter-spacing: 0.4px;
            box-shadow: 3px 3px 6px var(--shadow-dark), -3px -3px 6px var(--shadow-light);
            transition: all 0.18s;
            display: block;
            width: 100%;
            text-align: center;
        }
        .sa-expand-btn:hover { border-color: rgba(255,255,255,0.2); color: var(--text-main); }
        .sa-expand-btn.expanded { border-color: rgba(255,107,107,0.25); color: var(--sa-red); }

        /* ── Pill tags ── */
        .sa-pill { background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.08); border-radius:20px; padding:4px 11px; font-size:0.67rem; color:var(--text-main); font-weight:600; }

        /* ── Tabs ── */
        .sa-tabs { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; }
        .sa-tab  { background:var(--bg-color); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:7px 14px; font-size:0.72rem; font-weight:700; color:var(--text-muted); cursor:pointer; transition:all 0.2s; font-family:inherit; }
        .sa-tab.active { border-color:rgba(0,255,136,0.3); color:var(--sa-green); background:rgba(0,255,136,0.06); }
        .sa-tab-panel { display:none; } .sa-tab-panel.active { display:block; }

        /* ── Collapsible sections ── */
        .sa-section-wrap { margin-bottom: 8px; }
        .sa-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            background: var(--bg-color);
            border-radius: 16px;
            cursor: pointer;
            box-shadow: 5px 5px 12px var(--shadow-dark), -5px -5px 12px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.05);
            user-select: none;
            transition: border-color 0.2s;
            margin-bottom: 0;
        }
        .sa-section-header:hover { border-color: rgba(255,255,255,0.12); }
        .sa-section-header.open  { border-radius: 16px 16px 0 0; border-color: rgba(255,255,255,0.08); border-bottom-color: transparent; }
        .sa-section-left {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: var(--text-main);
        }
        .sa-section-icon { font-size: 1.1rem; }
        .sa-section-chevron {
            font-size: 0.75rem;
            color: var(--text-muted);
            transition: transform 0.25s ease;
        }
        .sa-section-header.open .sa-section-chevron { transform: rotate(180deg); }
        .sa-section-body {
            display: none;
            padding: 14px 0 4px;
            background: var(--bg-color);
            border-radius: 0 0 16px 16px;
            border: 1px solid rgba(255,255,255,0.08);
            border-top: none;
            padding: 16px;
        }
        .sa-section-body.open { display: block; }
        /* KPI section always open on load */
        .sa-section-header.always-open { cursor: default; }
        .sa-section-header.always-open .sa-section-chevron { display: none; }

        /* Center all content */
        .sa-body { text-align: center; }
        .sa-body > * { text-align: left; }
        .sa-page-header { justify-content: center; text-align: center; }
        .sa-page-title  { text-align: center; }
        .sa-page-sub    { text-align: center; }

        @media (max-width:480px) {
            .sa-kpi-grid { grid-template-columns:1fr 1fr; }
            .sa-kpi-val  { font-size:1rem; }
            .sa-rx-table { grid-template-columns:auto repeat(4,1fr); font-size:.62rem; gap:3px 6px; }
            .sa-section-header { padding:12px 14px; }
            .sa-section-body   { padding:12px; }
            .sa-section-left   { font-size:.68rem; }
            .sa-2col, .sa-3col, .sa-4col { grid-template-columns:1fr; }
            .sa-modal { padding:18px 16px; }
        }
        @media (max-width:360px) {
            .sa-section-icon { display:none; }
        }
    </style>
</head>
<body>
<div class="main-wrapper">
    <div class="content-area" style="flex-direction:column">

        <div class="header-container" style="margin:0 auto;width:100%;">
            <button class="logout-btn" onclick="window.location.href='logout.php';"><span>Logout</span></button>
            <div class="brand-section">
                <div class="logo-box"><img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="" style="height:40px;"></div>
                <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
            </div>
        </div>

        <div class="main-card" style="margin:0 auto;width:100%;">
        <div class="sa-body">

            <!-- Page Header -->
            <div class="sa-page-header" style="justify-content:center;flex-direction:column;align-items:center;text-align:center;">
                <div>
                    <div class="sa-page-title">📊 Smart Analysis</div>
                    <div class="sa-page-sub">Business Intelligence — Completed Orders (Status 5)</div>
                </div>
                <div class="sa-header-right">
                    <span class="sa-last-updated" id="sa-timestamp"></span>
                    <button class="sa-refresh-btn" id="sa-reload-btn" onclick="saLoad()">⟳ Refresh</button>
                </div>
            </div>

            <!-- Top alerts -->
            <!-- Global date filter bar -->
            <div style="display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;margin-bottom:16px;padding:12px 18px;background:var(--bg-color);border-radius:16px;box-shadow:6px 6px 14px var(--shadow-dark),-6px -6px 14px var(--shadow-light);border:1px solid rgba(255,255,255,0.04);">
                <span style="font-size:.63rem;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-muted);white-space:nowrap;">&#128197; Filter Period</span>
                <select id="sa-filter-year" class="sa-mselect" style="width:130px;" onchange="saFilterChange()">
                    <option value="0">All Years</option>
                </select>
                <select id="sa-filter-month" class="sa-mselect" style="width:150px;" onchange="saFilterChange()">
                    <option value="0">All Months</option>
                    <option value="1">January</option><option value="2">February</option>
                    <option value="3">March</option><option value="4">April</option>
                    <option value="5">May</option><option value="6">June</option>
                    <option value="7">July</option><option value="8">August</option>
                    <option value="9">September</option><option value="10">October</option>
                    <option value="11">November</option><option value="12">December</option>
                </select>
                <div id="sa-filter-badge" style="display:none;background:rgba(0,207,255,0.1);border:1px solid rgba(0,207,255,0.3);border-radius:20px;padding:4px 12px;font-size:.68rem;font-weight:700;color:var(--sa-blue);"></div>
                <button id="sa-filter-reset" style="display:none;background:none;border:1px solid rgba(255,107,107,0.25);border-radius:20px;color:var(--sa-red);font-size:.68rem;font-weight:700;padding:5px 12px;cursor:pointer;font-family:inherit;" onclick="saFilterReset()">&#10005; Reset Filter</button>
            </div>

            <div id="sa-top-alerts"></div>


            <div class="sa-section-wrap">
                <div class="sa-section-header open" onclick="saToggleSection('sec-kpi')">
                    <div class="sa-section-left">
                        <span class="sa-section-icon">📌</span>
                        <span>Key Performance Indicators</span>
                    </div>
                    <span class="sa-section-chevron">&#9660;</span>
                </div>
                <div class="sa-section-body open" id="sec-kpi">

                <div class="sa-kpi-grid" id="sa-kpi-grid"></div>
                </div>
            </div>
            <div class="sa-section-wrap">
                <div class="sa-section-header" onclick="saToggleSection('sec-trend')">
                    <div class="sa-section-left">
                        <span class="sa-section-icon">📈</span>
                        <span>Revenue & Profit Trend</span>
                    </div>
                    <span class="sa-section-chevron">&#9660;</span>
                </div>
                <div class="sa-section-body" id="sec-trend">

                <div class="sa-card">
                <div class="sa-card-title" style="display:flex;justify-content:space-between;align-items:center;">
                <span>Monthly Revenue · COGS · Net Profit (last 18 months)</span>
                <button onclick="saTrendModalOpen()" style="background:rgba(0,207,255,0.08);border:1px solid rgba(0,207,255,0.2);border-radius:20px;color:var(--sa-blue);font-size:.62rem;font-weight:700;padding:4px 12px;cursor:pointer;font-family:inherit;letter-spacing:.4px;white-space:nowrap;">&#9432; Penjelasan</button>
                </div>
                <div class="sa-chart-wrap" data-chart="monthly" style="height:220px;"><canvas id="ch-monthly"></canvas></div>
                </div>
                </div>
            </div>
            <div class="sa-section-wrap">
                <div class="sa-section-header" onclick="saToggleSection('sec-mix')">
                    <div class="sa-section-left">
                        <span class="sa-section-icon">🔭</span>
                        <span>Product Mix</span>
                    </div>
                    <span class="sa-section-chevron">&#9660;</span>
                </div>
                <div class="sa-section-body" id="sec-mix">


                <!-- Lenses — 4 separate cards -->
                <div id="sa-lens-cards"></div>

                <!-- Frame Shape & Size — 4-col grid below lenses -->
                <div class="sa-card">
                <div class="sa-card-title">🖼 Frame Shape & Size (Purchased)</div>
                <div class="sa-4col" style="margin-top:4px;">
                <div>
                <div class="sa-card-title" style="margin-bottom:10px;">Lens Shape</div>
                <div class="sa-bar-list" id="sa-shape-bars"></div>
                </div>
                <div>
                <div class="sa-card-title" style="margin-bottom:10px;">Frame Size</div>
                <div class="sa-bar-list" id="sa-size-bars"></div>
                </div>
                <div>
                <div class="sa-card-title" style="margin-bottom:10px;">Structure</div>
                <div class="sa-bar-list" id="sa-struct-bars"></div>
                </div>
                <div>
                <div class="sa-card-title" style="margin-bottom:10px;">Top Brand</div>
                <div class="sa-bar-list" id="sa-brand-bars"></div>
                </div>
                </div>
                </div>
                </div>
            </div>
            <div class="sa-section-wrap">
                <div class="sa-section-header" onclick="saToggleSection('sec-profit')">
                    <div class="sa-section-left">
                        <span class="sa-section-icon">💹</span>
                        <span>Profitability</span>
                    </div>
                    <span class="sa-section-chevron">&#9660;</span>
                </div>
                <div class="sa-section-body" id="sec-profit">

                <div class="sa-2col">
                <div class="sa-card">
                <div class="sa-card-title">Avg Net Margin & Cost Split</div>
                <div class="sa-ring-wrap" id="sa-ring-wrap">
                <svg width="100" height="100" viewBox="0 0 100 100" style="flex-shrink:0">
                <circle cx="50" cy="50" r="38" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="10"/>
                <circle id="sa-ring-c" cx="50" cy="50" r="38" fill="none" stroke="#00ff88"
                stroke-width="10" stroke-linecap="round"
                stroke-dasharray="0 239" transform="rotate(-90 50 50)"
                style="transition:stroke-dasharray 1.1s ease"/>
                </svg>
                <div>
                <div class="sa-ring-val" id="sa-ring-val" style="color:var(--sa-green)">—%</div>
                <div class="sa-ring-sub">AVG NET MARGIN</div>
                <div class="sa-ring-sub" id="sa-ring-sub2" style="margin-top:6px;"></div>
                </div>
                </div>
                <div style="margin-top:18px;">
                <div class="sa-card-title">Revenue vs Cost vs Profit</div>
                <div class="sa-bar-list" id="sa-cost-split"></div>
                </div>
                </div>
                <div class="sa-card">
                <div class="sa-card-title" style="display:flex;justify-content:space-between;align-items:center;">
                <span>Margin Distribution per Order</span>
                <button onclick="saMarginModalOpen()" style="background:rgba(0,207,255,0.08);border:1px solid rgba(0,207,255,0.2);border-radius:20px;color:var(--sa-blue);font-size:.62rem;font-weight:700;padding:4px 12px;cursor:pointer;font-family:inherit;white-space:nowrap;">&#9432; Penjelasan</button>
                </div>
                <div class="sa-chart-wrap" style="height:150px;"><canvas id="ch-margin"></canvas></div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:12px;" id="sa-margin-pills"></div>
                </div>
                </div>
                </div>
            </div>
            <div class="sa-section-wrap">
                <div class="sa-section-header" onclick="saToggleSection('sec-rx')">
                    <div class="sa-section-left">
                        <span class="sa-section-icon">👁</span>
                        <span>Prescription Analytics</span>
                    </div>
                    <span class="sa-section-chevron">&#9660;</span>
                </div>
                <div class="sa-section-body" id="sec-rx">

                <div class="sa-tabs">
                <button class="sa-tab active" onclick="saTab(this,'rx-sph')">SPH Distribution</button>
                <button class="sa-tab" onclick="saTab(this,'rx-cyl')">Astigmatism (CYL)</button>
                <button class="sa-tab" onclick="saTab(this,'rx-need')">Vision Needs</button>
                </div>

                <div class="sa-tab-panel active" id="tab-rx-sph">
                <div class="sa-2col">
                <div class="sa-card">
                <div class="sa-card-title">Spherical Power (SPH) Buckets</div>
                <div class="sa-chart-wrap" style="height:160px;"><canvas id="ch-sph"></canvas></div>
                </div>
                <div class="sa-card">
                <div class="sa-card-title">Vision Category Insights</div>
                <div id="sa-rx-insights" class="sa-bar-list"></div>
                </div>
                </div>
                </div>

                <div class="sa-tab-panel" id="tab-rx-cyl">
                <div class="sa-2col">
                <div class="sa-card">
                <div class="sa-card-title">Astigmatism Severity (CYL)</div>
                <div class="sa-donut-wrap" style="padding-top:6px;">
                <canvas id="ch-cyl" width="110" height="110" style="flex-shrink:0;width:110px!important;height:110px!important;"></canvas>
                <div class="sa-donut-legend" id="sa-cyl-legend"></div>
                </div>
                </div>
                <div class="sa-card">
                <div class="sa-card-title" style="margin-bottom:10px;">CYL Severity Notes</div>
                <div id="sa-cyl-notes" class="sa-bar-list"></div>
                </div>
                </div>
                </div>

                <div class="sa-tab-panel" id="tab-rx-need">
                <div class="sa-2col">
                <div class="sa-card">
                <div class="sa-card-title">Vision Need Profile</div>
                <div class="sa-donut-wrap" style="padding-top:6px;">
                <canvas id="ch-need" width="110" height="110" style="flex-shrink:0;width:110px!important;height:110px!important;"></canvas>
                <div class="sa-donut-legend" id="sa-need-legend"></div>
                </div>
                </div>
                <div class="sa-card">
                <div class="sa-card-title">Lens Category Recommendations</div>
                <div id="sa-need-notes" class="sa-bar-list"></div>
                </div>
                </div>
                </div>
                </div>
            </div>
            <div class="sa-section-wrap">
                <div class="sa-section-header" onclick="saToggleSection('sec-demo')">
                    <div class="sa-section-left">
                        <span class="sa-section-icon">👥</span>
                        <span>Customer Demographics</span>
                    </div>
                    <span class="sa-section-chevron">&#9660;</span>
                </div>
                <div class="sa-section-body" id="sec-demo">

                <div class="sa-3col">
                <div class="sa-card">
                <div class="sa-card-title">Gender Split</div>
                <div class="sa-donut-wrap">
                <canvas id="ch-gender" width="100" height="100" style="flex-shrink:0;width:100px!important;height:100px!important;"></canvas>
                <div class="sa-donut-legend" id="sa-gender-legend"></div>
                </div>
                </div>
                <div class="sa-card">
                <div class="sa-card-title">Age Group Distribution</div>
                <div class="sa-bar-list" id="sa-age-bars"></div>
                </div>
                <div class="sa-card">
                <div class="sa-card-title">Avg Order Value by Age</div>
                <div class="sa-chart-wrap" style="height:150px;"><canvas id="ch-age-avg"></canvas></div>
                </div>
                </div>
                </div>
            </div>
            <div class="sa-section-wrap">
                <div class="sa-section-header" onclick="saToggleSection('sec-ops')">
                    <div class="sa-section-left">
                        <span class="sa-section-icon">⚙️</span>
                        <span>Operations</span>
                    </div>
                    <span class="sa-section-chevron">&#9660;</span>
                </div>
                <div class="sa-section-body" id="sec-ops">

                <div class="sa-2col">
                <div class="sa-card">
                <div class="sa-card-title">Busiest Days of the Week</div>
                <div class="sa-dow-grid" id="sa-dow-grid"></div>
                </div>
                <div class="sa-card">
                <div class="sa-card-title">🏆 Top Customers (Repeat Visits)</div>
                <table class="sa-tbl">
                <thead><tr><th>#</th><th>Name</th><th>Visits</th><th>Revenue</th></tr></thead>
                <tbody id="sa-top-cust"></tbody>
                </table>
                </div>
                </div>
                </div>
            </div>
            <div class="sa-section-wrap">
                <div class="sa-section-header" onclick="saToggleSection('sec-inv')">
                    <div class="sa-section-left">
                        <span class="sa-section-icon">🖼</span>
                        <span>Frame Inventory Intelligence</span>
                    </div>
                    <span class="sa-section-chevron">&#9660;</span>
                </div>
                <div class="sa-section-body" id="sec-inv">

                <div class="sa-3col">
                <div class="sa-card">
                <div class="sa-card-title">Stock Age (units)</div>
                <div class="sa-donut-wrap">
                <canvas id="ch-stockage" width="100" height="100" style="flex-shrink:0;width:100px!important;height:100px!important;"></canvas>
                <div class="sa-donut-legend" id="sa-stockage-legend"></div>
                </div>
                </div>
                <div class="sa-card">
                <div class="sa-card-title">Frame Structure</div>
                <div class="sa-bar-list" id="sa-structure-bars"></div>
                <div style="margin-top:14px;"><div class="sa-card-title">Gender Category</div>
                <div class="sa-bar-list" id="sa-gendercat-bars"></div></div>
                </div>
                <div class="sa-card">
                <div class="sa-card-title">Top Materials (SKU count)</div>
                <div class="sa-bar-list" id="sa-material-bars"></div>
                </div>
                </div>
                </div>
            </div>
            <div class="sa-section-wrap">
                <div class="sa-section-header" onclick="saToggleSection('sec-mods')">
                    <div class="sa-section-left">
                        <span class="sa-section-icon">🔬</span>
                        <span>Prescription Modifications (Rx Changes)</span>
                    </div>
                    <span class="sa-section-chevron">&#9660;</span>
                </div>
                <div class="sa-section-body" id="sec-mods">

                <div class="sa-card">
                <div class="sa-card-title" style="margin-bottom:16px;">Recent Modified Prescriptions — OD (Right) & OS (Left)</div>
                <div class="sa-rx-grid" id="sa-rx-grid"></div>
                </div>
                </div>
            </div>
            <div class="sa-section-wrap">
                <div class="sa-section-header" onclick="saToggleSection('sec-alerts')">
                    <div class="sa-section-left">
                        <span class="sa-section-icon">⚠️</span>
                        <span>Data Quality Alerts</span>
                    </div>
                    <span class="sa-section-chevron">&#9660;</span>
                </div>
                <div class="sa-section-body" id="sec-alerts">

                <div id="sa-data-alerts"></div>
                </div>
            </div>

        </div><!-- .sa-body -->
        </div><!-- .main-card -->

        <div class="btn-group">
            <button type="button" class="back-main" onclick="window.history.back()">BACK TO PREVIOUS PAGE</button>
        </div>
        <footer class="footer-container">
            <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
        </footer>
    </div>
</div>

<!-- Loading -->
<!-- Generic KPI info modal -->
<div id="sa-kpi-modal-overlay" style="display:none;" class="sa-modal-overlay" onclick="saKpiModalClose(event)">
    <div class="sa-modal" style="max-width:480px;">
        <button class="sa-modal-close" onclick="saKpiModalClose(null,true)">&times;</button>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px;">
            <div id="sa-kpi-modal-icon" style="font-size:2rem;line-height:1;"></div>
            <div>
                <div class="sa-modal-title" id="sa-kpi-modal-title"></div>
                <div class="sa-modal-sub" id="sa-kpi-modal-val"></div>
            </div>
        </div>
        <div id="sa-kpi-modal-body" style="margin-top:16px;"></div>
    </div>
</div>

<!-- Margin Distribution info modal -->
<div id="sa-margin-modal-overlay" style="display:none;" class="sa-modal-overlay" onclick="if(event.target===this)this.style.display='none'">
    <div class="sa-modal" style="max-width:500px;">
        <button class="sa-modal-close" onclick="document.getElementById('sa-margin-modal-overlay').style.display='none'">&times;</button>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <div style="font-size:2rem;">&#128202;</div>
            <div>
                <div class="sa-modal-title">Margin Distribution per Order</div>
                <div class="sa-modal-sub">Profit margin distribution histogram</div>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <div style="background:rgba(255,255,255,0.03);border-radius:12px;padding:14px 16px;border:1px solid rgba(255,255,255,0.06);">
                <div style="font-size:.75rem;color:var(--text-muted);line-height:1.7;">
                    Histogram ini mengelompokkan setiap order berdasarkan <strong style="color:var(--text-main);">persentase margin bersihnya</strong>
                    dan menampilkan berapa banyak order yang jatuh di setiap rentang margin.
                </div>
            </div>

            <div style="background:rgba(255,255,255,0.03);border-radius:12px;padding:14px 16px;border:1px solid rgba(255,255,255,0.06);">
                <div style="font-size:.72rem;font-weight:700;color:var(--text-main);margin-bottom:10px;">How to Read</div>
                <div style="display:grid;grid-template-columns:auto 1fr;gap:6px 12px;font-size:.72rem;">
                    <span style="color:var(--text-muted);white-space:nowrap;">X-Axis</span>
                    <span style="color:var(--text-main);">Rentang margin (0-10%, 10-20%, dst.)</span>
                    <span style="color:var(--text-muted);white-space:nowrap;">Y-Axis</span>
                    <span style="color:var(--text-main);">Jumlah order di rentang tersebut</span>
                    <span style="color:var(--text-muted);white-space:nowrap;">Green Bar</span>
                    <span style="color:var(--sa-green);">Margin baik (&ge; 40%)</span>
                    <span style="color:var(--text-muted);white-space:nowrap;">Yellow Bar</span>
                    <span style="color:var(--sa-amber);">Margin moderat (20&ndash;40%)</span>
                    <span style="color:var(--text-muted);white-space:nowrap;">Red Bar</span>
                    <span style="color:var(--sa-red);">Margin rendah (&lt; 20%)</span>
                </div>
            </div>

            <div style="background:rgba(255,255,255,0.03);border-radius:12px;padding:14px 16px;border:1px solid rgba(255,255,255,0.06);">
                <div style="font-size:.72rem;font-weight:700;color:var(--text-main);margin-bottom:8px;">How Margin is Calculated</div>
                <div style="background:rgba(0,0,0,0.2);border-radius:8px;padding:10px 14px;font-family:monospace;font-size:.72rem;color:var(--sa-green);line-height:2;">
                    Margin = (Revenue &minus; COGS) &divide; Revenue &times; 100%<br>
                    <span style="color:var(--text-muted);">COGS = Harga beli lensa + harga beli frame + packaging</span>
                </div>
            </div>

            <div style="background:rgba(0,207,255,0.05);border-radius:12px;padding:12px 14px;border:1px solid rgba(0,207,255,0.15);">
                <div style="font-size:.7rem;color:var(--sa-blue);line-height:1.6;">
                    &#9432; <strong>Ideal:</strong> Most bars should be in the green area (&ge; 40%). If many bars are red, review sell prices or negotiate better buy prices with suppliers.
                </div>
            </div>

            <div id="sa-margin-modal-stats" style="background:rgba(255,255,255,0.03);border-radius:12px;padding:14px 16px;border:1px solid rgba(255,255,255,0.06);"></div>
        </div>
    </div>
</div>

<!-- Revenue Trend info modal -->
<div id="sa-trend-modal-overlay" style="display:none;" class="sa-modal-overlay" onclick="if(event.target===this)this.style.display='none'">
    <div class="sa-modal" style="max-width:500px;">
        <button class="sa-modal-close" onclick="document.getElementById('sa-trend-modal-overlay').style.display='none'">&times;</button>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <div style="font-size:2rem;">&#128200;</div>
            <div>
                <div class="sa-modal-title">Revenue &amp; Profit Trend</div>
                <div class="sa-modal-sub">Monthly chart explanation</div>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <div style="background:rgba(255,255,255,0.03);border-radius:12px;padding:14px 16px;border:1px solid rgba(255,255,255,0.06);">
                <div style="font-size:.72rem;font-weight:700;color:var(--text-main);margin-bottom:6px;">&#128200; About This Chart</div>
                <div style="font-size:.75rem;color:var(--text-muted);line-height:1.7;">Chart ini menampilkan performa keuangan per bulan dari semua order yang sudah <strong style="color:var(--text-main);">selesai (status = 5)</strong>. Gunakan filter periode di atas untuk mempersempit rentang waktu.</div>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <div style="display:flex;align-items:flex-start;gap:12px;background:rgba(255,170,0,0.06);border-radius:12px;padding:12px 14px;border:1px solid rgba(255,170,0,0.15);">
                    <div style="width:12px;height:12px;border-radius:3px;background:#ffaa00;flex-shrink:0;margin-top:2px;"></div>
                    <div>
                        <div style="font-size:.72rem;font-weight:700;color:var(--sa-amber);">Revenue (Yellow Bar)</div>
                        <div style="font-size:.7rem;color:var(--text-muted);margin-top:3px;line-height:1.6;">Total pendapatan kotor dari semua order selesai di bulan tersebut. Ini adalah nilai <code>total_amount</code> dari <code>customer_orders</code>.</div>
                    </div>
                </div>
                <div style="display:flex;align-items:flex-start;gap:12px;background:rgba(255,107,107,0.06);border-radius:12px;padding:12px 14px;border:1px solid rgba(255,107,107,0.15);">
                    <div style="width:12px;height:12px;border-radius:3px;background:#ff6b6b;flex-shrink:0;margin-top:2px;"></div>
                    <div>
                        <div style="font-size:.72rem;font-weight:700;color:var(--sa-red);">COGS / Cost of Goods Sold (Red Bar)</div>
                        <div style="font-size:.7rem;color:var(--text-muted);margin-top:3px;line-height:1.6;">Total biaya yang dikeluarkan: <strong style="color:var(--text-main);">harga beli lensa + harga beli frame + biaya packaging</strong>. Diambil dari <code>lense_prices.json</code>, <code>frames_main</code>, dan <code>packaging_cost</code>.</div>
                    </div>
                </div>
                <div style="display:flex;align-items:flex-start;gap:12px;background:rgba(0,255,136,0.06);border-radius:12px;padding:12px 14px;border:1px solid rgba(0,255,136,0.15);">
                    <div style="width:3px;height:14px;border-radius:2px;background:#00ff88;flex-shrink:0;margin-top:1px;"></div>
                    <div>
                        <div style="font-size:.72rem;font-weight:700;color:var(--sa-green);">Net Profit (Green Line)</div>
                        <div style="font-size:.7rem;color:var(--text-muted);margin-top:3px;line-height:1.6;">Laba bersih = Revenue &minus; COGS. Jika line berada di bawah nol, artinya bulan tersebut merugi.</div>
                    </div>
                </div>
            </div>
            <div style="background:rgba(0,207,255,0.05);border-radius:12px;padding:12px 14px;border:1px solid rgba(0,207,255,0.15);">
                <div style="font-size:.7rem;color:var(--sa-blue);line-height:1.6;">&#9432; <strong>Note:</strong> Akurasi COGS bergantung pada kelengkapan data buy_price di tabel frame. Frame tanpa buy_price dihitung sebagai IDR 0.</div>
            </div>
        </div>
    </div>
</div>

<!-- Stock detail modal -->
<div id="sa-stock-modal-overlay" style="display:none;" class="sa-modal-overlay" onclick="saModalClose(event)">
    <div class="sa-modal" style="max-width:540px;">
        <button class="sa-modal-close" onclick="saModalClose(null,true)">&times;</button>
        <div class="sa-modal-title">&#128444; Frame Stock Detail</div>
        <div class="sa-modal-sub" id="sa-modal-total"></div>

        <!-- 3-col filter dropdowns -->
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin:16px 0 20px;">
            <div>
                <div class="sa-modal-sec-title" style="margin-bottom:6px;">Sell Price</div>
                <select id="sa-mf-price" class="sa-mselect" onchange="saModalApplyFilters()">
                    <option value="all">All</option>
                    <option value="standar">Standard (50–100K)</option>
                    <option value="menengah">Mid-Range (100–200K)</option>
                    <option value="menengah_atas">Upper-Mid (201–350K)</option>
                    <option value="premium">Premium (&gt;350K)</option>
                </select>
            </div>
            <div>
                <div class="sa-modal-sec-title" style="margin-bottom:6px;">Material</div>
                <select id="sa-mf-material" class="sa-mselect" onchange="saModalApplyFilters()">
                    <option value="all">All</option>
                </select>
            </div>
            <div>
                <div class="sa-modal-sec-title" style="margin-bottom:6px;">Structure</div>
                <select id="sa-mf-struct" class="sa-mselect" onchange="saModalApplyFilters()">
                    <option value="all">All</option>
                    <option value="full-rim">Full-Rim</option>
                    <option value="semi-rimless">Semi-Rimless</option>
                    <option value="rimless">Rimless</option>
                </select>
            </div>
        </div>

        <div class="sa-modal-sec" style="margin-bottom:16px;">
            <div class="sa-modal-sec-title" style="margin-bottom:10px;">Lens Shape</div>
            <div id="sa-modal-shape-list"></div>
        </div>
        <div class="sa-modal-sec">
            <div class="sa-modal-sec-title" style="margin-bottom:10px;">Gender Category</div>
            <div id="sa-modal-gender-list"></div>
        </div>
    </div>
</div>

<div id="sa-loading">
    <div class="sa-loader-box">
        <div class="sa-loader-spinner"></div>
        <div class="sa-loader-text">Analyzing data…</div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
// ══════════════════════════════════════════════════════════════════
//  SMART ANALYSIS — UI Script
// ══════════════════════════════════════════════════════════════════
const C = { green:'#00ff88', amber:'#ffaa00', blue:'#00cfff', purple:'#aa88ff', red:'#ff6b6b', teal:'#00e5cc', pink:'#ff88cc', muted:'rgba(255,255,255,0.2)' };
Chart.defaults.color = 'rgba(255,255,255,0.32)';
Chart.defaults.font.family = "'Space Grotesk', sans-serif";
Chart.defaults.font.size = 11;

const _charts = {};
function killChart(id) { if (_charts[id]) { _charts[id].destroy(); delete _charts[id]; } }

function saGetCtx(canvasId) {
    var el = document.getElementById(canvasId);
    if (!el) {
        // Canvas might have been replaced — try to restore from parent
        // Find wrapper by data-canvas attr
        var wrap = document.querySelector('[data-canvas="'+canvasId+'"]');
        if (wrap) {
            wrap.innerHTML = '<canvas id="'+canvasId+'"></canvas>';
            el = document.getElementById(canvasId);
        }
    }
    return el ? el.getContext('2d') : null;
}
function fmt(n)     { n=Math.abs(n); if(n>=1e9)return'IDR '+(n/1e9).toFixed(2)+'B'; if(n>=1e6)return'IDR '+(n/1e6).toFixed(2)+'M'; if(n>=1e3)return'IDR '+(n/1e3).toFixed(1)+'K'; return'IDR '+n.toLocaleString('id-ID'); }
function fmtFull(n) { return (n<0?'−':'')+'IDR '+Math.abs(n).toLocaleString('id-ID'); }

// ── Tabs ────────────────────────────────────────────────────────
function saTab(btn, id) {
    btn.closest('.sa-body').querySelectorAll('.sa-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    const prefix = id.split('-')[0]+'-'+id.split('-')[1]; // e.g. "rx"
    btn.closest('.sa-body').querySelectorAll('[id^="tab-'+prefix+'"]').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-'+id).classList.add('active');
}

// ── Main load ───────────────────────────────────────────────────
function saMarginModalOpen() {
    // Populate live stats
    var statsEl = document.getElementById('sa-margin-modal-stats');
    if (statsEl && window._kpiData && window._kpiData.summary) {
        var s = window._kpiData.summary;
        var overall = s.totalRevenue > 0 ? Math.round(s.totalProfit / s.totalRevenue * 100) : 0;
        statsEl.innerHTML =
            '<div style="font-size:.72rem;font-weight:700;color:var(--text-main);margin-bottom:10px;">Current Statistics</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">' +
            '<div style="background:rgba(0,0,0,0.15);border-radius:8px;padding:10px 12px;text-align:center;">' +
            '<div style="font-size:1.4rem;font-weight:800;font-family:monospace;color:'+(overall>=40?'var(--sa-green)':overall>=20?'var(--sa-amber)':'var(--sa-red)')+';">'+overall+'%</div>' +
            '<div style="font-size:.6rem;color:var(--text-muted);letter-spacing:.5px;text-transform:uppercase;margin-top:2px;">Overall Margin</div>' +
            '</div>' +
            '<div style="background:rgba(0,0,0,0.15);border-radius:8px;padding:10px 12px;text-align:center;">' +
            '<div style="font-size:1.4rem;font-weight:800;font-family:monospace;color:var(--sa-blue);">'+s.avgMargin+'%</div>' +
            '<div style="font-size:.6rem;color:var(--text-muted);letter-spacing:.5px;text-transform:uppercase;margin-top:2px;">Avg per Order</div>' +
            '</div>' +
            '</div>';
    } else {
        if (statsEl) statsEl.style.display = 'none';
    }
    document.getElementById('sa-margin-modal-overlay').style.display = 'flex';
}

function saTrendModalOpen() {
    document.getElementById('sa-trend-modal-overlay').style.display = 'flex';
}

function saToggleSection(id) {
    var body   = document.getElementById(id);
    var header = body ? body.previousElementSibling : null;
    if (!body || !header) return;
    var isOpen = body.classList.contains('open');
    // Close all other sections first
    if (!isOpen) {
        document.querySelectorAll('.sa-section-body.open').forEach(function(b) {
            if (b.id !== id) {
                b.classList.remove('open');
                var h = b.previousElementSibling;
                if (h) h.classList.remove('open');
            }
        });
    }
    body.classList.toggle('open', !isOpen);
    header.classList.toggle('open', !isOpen);
}

function saFilterChange() { saLoad(); }

function saFilterReset() {
    var yr = document.getElementById('sa-filter-year');
    var mo = document.getElementById('sa-filter-month');
    if (yr) yr.value = '0';
    if (mo) mo.value = '0';
    saLoad();
}

function saKpiModalClose(e, force) {
    if (force || (e && e.target === document.getElementById('sa-kpi-modal-overlay'))) {
        document.getElementById('sa-kpi-modal-overlay').style.display = 'none';
    }
}

function saKpiModalOpen(idx) {
    var k = (window._kpiDefs || [])[idx];
    if (!k || !k.modal) return;
    var m = k.modal;

    document.getElementById('sa-kpi-modal-icon').textContent  = k.icon;
    document.getElementById('sa-kpi-modal-title').textContent = m.title;
    document.getElementById('sa-kpi-modal-val').textContent   = k.label + ': ' + k.val;

    var html = '';
    // Description
    html += '<div style="font-size:.78rem;color:var(--text-main);line-height:1.6;margin-bottom:16px;padding:12px 14px;background:rgba(255,255,255,0.03);border-radius:12px;border:1px solid rgba(255,255,255,0.06);">' + m.desc + '</div>';

    // Details table
    if (m.details && m.details.length) {
        html += '<div style="display:flex;flex-direction:column;gap:0;">';
        m.details.forEach(function(d, i) {
            var bg = i % 2 === 0 ? 'rgba(255,255,255,0.02)' : 'transparent';
            html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:9px 12px;background:'+bg+';border-radius:8px;">' +
                    '<span style="font-size:.72rem;color:var(--text-muted);">'+d.label+'</span>' +
                    '<span style="font-size:.78rem;font-weight:700;font-family:monospace;color:var(--text-main);">'+d.val+'</span>' +
                    '</div>';
        });
        html += '</div>';
    }

    // Warning
    if (m.warn) {
        html += '<div style="margin-top:14px;background:rgba(255,170,0,0.07);border:1px solid rgba(255,170,0,0.2);border-radius:12px;padding:10px 14px;font-size:.72rem;color:var(--sa-amber);">' +
                '⚠️ ' + m.warn + '</div>';
    }

    document.getElementById('sa-kpi-modal-body').innerHTML = html;
    document.getElementById('sa-kpi-modal-overlay').style.display = 'flex';
}

function saModalClose(e, force) {
    if (force || (e && e.target === document.getElementById('sa-stock-modal-overlay'))) {
        document.getElementById('sa-stock-modal-overlay').style.display = 'none';
    }
}

function saRenderModalBars(listId, dataObj, color) {
    var el = document.getElementById(listId);
    if (!el) return;
    var entries = Object.entries(dataObj)
        .filter(function(e){ return e[1] > 0; })
        .sort(function(a,b){ return b[1]-a[1]; });
    if (!entries.length) {
        el.innerHTML = '<div style="font-size:.72rem;color:var(--text-muted);font-style:italic;">No frames match this filter.</div>';
        return;
    }
    var mx = entries[0][1];
    el.innerHTML = entries.map(function(e) {
        var lbl = String(e[0]).replace(/&/g,'&amp;').replace(/</g,'&lt;');
        var pct = Math.round(e[1]/mx*100);
        return '<div class="sa-bar-item" style="margin-bottom:8px;">' +
               '<div class="sa-bar-row">' +
               '<span class="sa-bar-name">'+lbl+'</span>' +
               '<span class="sa-bar-meta">'+e[1]+' units</span>' +
               '</div>' +
               '<div class="sa-bar-track"><div class="sa-bar-fill" style="--bc:'+color+';width:'+pct+'%"></div></div>' +
               '</div>';
    }).join('');
}

function saModalApplyFilters() {
    var frames = (_stockData.frameRawList || []);
    var price    = document.getElementById('sa-mf-price')    ? document.getElementById('sa-mf-price').value    : 'all';
    var material = document.getElementById('sa-mf-material') ? document.getElementById('sa-mf-material').value : 'all';
    var struct   = document.getElementById('sa-mf-struct')   ? document.getElementById('sa-mf-struct').value   : 'all';

    var filtered = frames.filter(function(fr) {
        if (price !== 'all') {
            var s = fr.sell;
            var ok = (price==='standar'       && s>=50000  && s<=100000) ||
                     (price==='menengah'       && s>=101000 && s<=200000) ||
                     (price==='menengah_atas'  && s>=201000 && s<=350000) ||
                     (price==='premium'        && s>350000);
            if (!ok) return false;
        }
        if (material !== 'all' && fr.material.toLowerCase() !== material.toLowerCase()) return false;
        if (struct   !== 'all' && fr.struct !== struct) return false;
        return true;
    });

    var byShape = {}, byGender = {}, total = 0;
    filtered.forEach(function(fr) {
        byShape[fr.shape]   = (byShape[fr.shape]   || 0) + fr.stock;
        byGender[fr.gender] = (byGender[fr.gender] || 0) + fr.stock;
        total += fr.stock;
    });

    var parts = [];
    var priceLabels = {standar:'Standard',menengah:'Mid-Range',menengah_atas:'Upper-Mid',premium:'Premium'};
    if (price    !== 'all') parts.push(priceLabels[price] || price);
    if (material !== 'all') parts.push(material);
    if (struct   !== 'all') parts.push(struct);
    var label = parts.length ? parts.join(' + ') : 'All';
    document.getElementById('sa-modal-total').textContent =
        label + ' \u2014 ' + total.toLocaleString('id-ID') + ' unitss';

    saRenderModalBars('sa-modal-shape-list',  byShape,  '#00cfff');
    saRenderModalBars('sa-modal-gender-list', byGender, '#aa88ff');
}

function saStockModalOpen() {
    var frames = (_stockData.frameRawList || []);

    // Build material dropdown options
    var matSel = document.getElementById('sa-mf-material');
    if (matSel) {
        var materials = {};
        frames.forEach(function(fr){ materials[fr.material] = (materials[fr.material]||0) + fr.stock; });
        var matEntries = Object.entries(materials).sort(function(a,b){ return b[1]-a[1]; });
        matSel.innerHTML = '<option value="all">All</option>';
        matEntries.forEach(function(e) {
            var opt = document.createElement('option');
            opt.value = e[0];
            opt.textContent = e[0];
            matSel.appendChild(opt);
        });
    }

    // Reset selects
    ['sa-mf-price','sa-mf-material','sa-mf-struct'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.value = 'all';
    });

    saModalApplyFilters();
    document.getElementById('sa-stock-modal-overlay').style.display = 'flex';
}

function saLoad() {
    var btn = document.getElementById('sa-reload-btn');
    btn.disabled = true; btn.textContent = '⟳ Loading…';
    document.getElementById('sa-loading').classList.remove('hidden');
    var yr = document.getElementById('sa-filter-year')  ? document.getElementById('sa-filter-year').value  : '0';
    var mo = document.getElementById('sa-filter-month') ? document.getElementById('sa-filter-month').value : '0';
    var qs = 'ajax=1' + (yr!=='0'?'&year='+yr:'') + (mo!=='0'?'&month='+mo:'');
    fetch('bi_report.php?' + qs)
        .then(r => r.text())
        .then(txt => {
            var data;
            try { data = JSON.parse(txt); }
            catch(e) {
                document.getElementById('sa-loading').classList.add('hidden');
                btn.disabled = false; btn.textContent = '↻ Refresh';
                document.getElementById('sa-top-alerts').innerHTML =
                    '<div class="sa-alert" style="margin-bottom:12px;">PHP/JSON Error:<br><pre style="font-size:.62rem;margin-top:6px;white-space:pre-wrap;overflow:auto;max-height:200px;">'+txt.replace(/</g,'&lt;').substring(0,800)+'</pre></div>';
                return;
            }
            renderAll(data);
            document.getElementById('sa-loading').classList.add('hidden');
            btn.disabled = false; btn.textContent = '⟳ Refresh';
            document.getElementById('sa-timestamp').textContent = 'Updated ' + new Date().toLocaleTimeString('id-ID');
        })
        .catch(err => {
            document.getElementById('sa-loading').classList.add('hidden');
            btn.disabled = false; btn.textContent = '⟳ Refresh';
            document.getElementById('sa-top-alerts').innerHTML = '<div class="sa-alert" style="margin-bottom:12px;">❌ Failed to load data. Check connection or server log.</div>';
            console.error(err);
        });
}

var _stockData = {};

function renderAll(d) {
    // Populate year dropdown from available years
    var yearSel = document.getElementById('sa-filter-year');
    if (yearSel && d.availableYears) {
        var curYr = yearSel.value;
        yearSel.innerHTML = '<option value="0">All Years</option>';
        (d.availableYears || []).forEach(function(y) {
            var opt = document.createElement('option');
            opt.value = y; opt.textContent = y;
            if (String(y) === String(curYr)) opt.selected = true;
            yearSel.appendChild(opt);
        });
    }
    // Filter badge
    var af = d.activeFilter || {};
    var badge = document.getElementById('sa-filter-badge');
    var resetBtn = document.getElementById('sa-filter-reset');
    var months = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    if (badge) {
        if (af.year > 0) {
            badge.textContent = (af.year) + (af.month > 0 ? ' - ' + months[af.month] : '');
            badge.style.display = 'inline-block';
            if (resetBtn) resetBtn.style.display = 'inline-block';
        } else {
            badge.style.display = 'none';
            if (resetBtn) resetBtn.style.display = 'none';
        }
    }
    _stockData = { byShape: d.stockByShape||{}, byGender: d.stockByGender||{}, total: (d.summary||{}).totalFrameStock||0, frameRawList: d.frameRawList||[] };
    renderTopAlerts(d);
    renderKPIs(d);
    renderMonthly(d);
    renderTopLenses(d);
    renderTopFrames(d);
    renderProfitability(d);
    renderRxAnalytics(d);
    renderDemographics(d);
    renderDOW(d);
    renderTopCustomers(d);
    renderFrameInventory(d);
    renderRxMods(d);
    renderDataAlerts(d);
}

// ── Top alert banners ───────────────────────────────────────────
function renderTopAlerts(d) {
    const s = d.summary; let html = '';
    if (s.momChange !== null) {
        const up = s.momChange >= 0;
        html += `<div class="sa-alert ${up?'green':'amber'}" style="margin-bottom:10px;">
            ${up?'📈':'📉'} Revenue this month <strong>${fmtFull(s.thisMonthRev)}</strong> —
            ${up?'up':'down'} <strong>${Math.abs(s.momChange)}%</strong> vs last month (${fmtFull(s.lastMonthRev)}).
        </div>`;
    }
    if (s.pendingFrameCount > 0)
        html += `<div class="sa-alert amber" style="margin-bottom:10px;">⚠️ <strong>${s.pendingFrameCount}</strong> frame tanpa buy_price — profit calculation mungkin kurang akurat.</div>`;
    if (s.stagingCount > 0)
        html += `<div class="sa-alert amber" style="margin-bottom:10px;">📦 <strong>${s.stagingCount}</strong> frames still in staging (not yet in frames_main).</div>`;
    document.getElementById('sa-top-alerts').innerHTML = html;
}

// ── KPIs ────────────────────────────────────────────────────────
function renderKPIs(d) {
    var s = d.summary;
    var margin = s.totalRevenue > 0 ? Math.round(s.totalProfit / s.totalRevenue * 100) : 0;
    var fmtFull2 = function(n){ return (n<0?'- ':'')+'IDR '+Math.abs(n).toLocaleString('id-ID'); };
    var kpis = [
        { icon:'🏁', label:'Total Orders', val: s.totalOrders.toLocaleString('id-ID'), color: C.blue,
            modal:{ title:'Total Orders', desc:'Total completed orders (status = 5).', details:[
                {label:'Total completed orders', val: s.totalOrders.toLocaleString('id-ID')+' order'},
                {label:'Avg revenue per order', val: fmtFull2(s.avgOrderValue)},
                {label:'Avg processing time', val: s.avgProcessDays+' hari'},
            ]}},
        { icon:'💰', label:'Total Revenue', val: fmt(s.totalRevenue), color: C.amber,
            modal:{ title:'Total Revenue', desc:'Total gross revenue from all completed orders.', details:[
                {label:'Total revenue', val: fmtFull2(s.totalRevenue)},
                {label:'This month', val: fmtFull2(s.thisMonthRev)},
                {label:'Last month', val: fmtFull2(s.lastMonthRev)},
                {label:'MoM Change', val: s.momChange !== null ? (s.momChange>0?'+':'')+s.momChange+'%' : 'N/A'},
            ]}},
        { icon:'💹', label:'Net Profit', val: fmt(s.totalProfit), color: s.totalProfit>=0?C.green:C.red,
            badge: s.totalProfit>=0?null:{cls:'down',txt:'Loss'},
            modal:{ title:'Net Profit', desc:'Net profit = Total Revenue minus COGS (lens cost + frame cost + packaging).', details:[
                {label:'Total revenue', val: fmtFull2(s.totalRevenue)},
                {label:'Total COGS', val: fmtFull2(s.totalCost)},
                {label:'Net profit', val: fmtFull2(s.totalProfit)},
                {label:'Overall margin', val: Math.round(s.totalProfit/Math.max(s.totalRevenue,1)*100)+'%'},
            ], warn: s.framesMissingBuy>0 ? s.framesMissingBuy+' frame(s) missing buy_price — profit figures may be understated.' : null }},
        { icon:'📊', label:'Overall Margin', val: margin+'%', color: margin>30?C.green:margin>15?C.amber:C.red,
            badge: margin>30?{cls:'up',txt:'✓ Healthy'}:margin>15?{cls:'neu',txt:'⚡ Moderate'}:{cls:'down',txt:'⚠ Low'},
            modal:{ title:'Overall Margin', desc:'Net profit percentage from total revenue. A healthy margin for optics is above 30%.', details:[
                {label:'Overall margin', val: Math.round(s.totalProfit/Math.max(s.totalRevenue,1)*100)+'%'},
                {label:'Avg margin per order', val: s.avgMargin+'%'},
                {label:'Healthy target', val: '> 30%'},
                {label:'Status', val: margin>30?'Sehat ✓':margin>15?'Moderat':'Rendah ⚠'},
            ]}},
        { icon:'🛒', label:'Avg Order Value', val: fmt(s.avgOrderValue), color: C.purple,
            modal:{ title:'Avg Order Value', desc:'Average transaction value per completed order.', details:[
                {label:'Avg order value', val: fmtFull2(s.avgOrderValue)},
                {label:'Total orders', val: s.totalOrders+' order'},
                {label:'Total revenue', val: fmtFull2(s.totalRevenue)},
            ]}},
        { icon:'⏱', label:'Avg Process Days', val: s.avgProcessDays+'d', color: C.teal,
            badge: s.avgProcessDays<=5?{cls:'up',txt:'Fast'}:s.avgProcessDays<=10?{cls:'neu',txt:'Normal'}:{cls:'down',txt:'Slow'},
            modal:{ title:'Avg Process Days', desc:'Average days from order date to due date.', details:[
                {label:'Avg processing time', val: s.avgProcessDays+' hari'},
                {label:'Fast target', val: '≤ 5 hari'},
                {label:'Status', val: s.avgProcessDays<=5?'Fast ✓':s.avgProcessDays<=10?'Normal':'Slow ⚠'},
            ]}},
        { icon:'👁', label:'High Myopia', val: s.highMyopia, color: C.pink,
            modal:{ title:'High Myopia', desc:'Patients with average SPH ≤ -6.00 (high myopia). These patients require high-index specialty lenses.', details:[
                {label:'High myopia patients', val: s.highMyopia+' patients'},
                {label:'Total patients examined', val: s.totalOrders+' order'},
                {label:'Percentage', val: Math.round(s.highMyopia/Math.max(s.totalOrders,1)*100)+'%'},
                {label:'Lens recommendation', val: 'High-index 1.67 / Superblock'},
            ]}},
        { icon:'🔬', label:'Presbyopia (ADD)', val: s.presbyopia, color: C.purple,
            modal:{ title:'Presbyopia', desc:'Patients with ADD value (reading addition) — indicating presbyopia, needing progressive or kryptok lenses.', details:[
                {label:'Presbyopia patients', val: s.presbyopia+' patients'},
                {label:'Total patients examined', val: s.totalOrders+' order'},
                {label:'Percentage', val: Math.round(s.presbyopia/Math.max(s.totalOrders,1)*100)+'%'},
                {label:'Lens recommendation', val: 'Progressive / Kryptok / Flattop'},
            ]}},
        { icon:'✏️', label:'Rx Modified (Log)', val: s.rxModCount, color: C.amber,
            modal:{ title:'Rx Modified', desc:'Number of times prescriptions were modified from original exam results, recorded in prescription_modifications.', details:[
                {label:'Total rx modifications', val: s.rxModCount+' times'},
                {label:'Orders with is_modified flag', val: s.modifiedOrders+' order'},
            ]}},
        { icon:'🖼', label:'Frame Stock', val: s.totalFrameStock.toLocaleString('id-ID'), color: C.blue, clickable: true, stockModal: true },
        { icon:'⚠️', label:'Frames No Cost', val: s.framesMissingBuy, color: s.framesMissingBuy>0?C.red:C.teal,
            badge: s.framesMissingBuy>0?{cls:'down',txt:'Missing'}:{cls:'up',txt:'✓ OK'},
            modal:{ title:'Frames No Cost', desc:'Frames in frames_main without buy_price (cost = 0 or NULL). This causes inaccurate profit calculations.', details:[
                {label:'Frames without buy_price', val: s.framesMissingBuy+' frame'},
                {label:'Profit accuracy', val: s.framesMissingBuy>0?'Inaccurate ⚠':'Accurate ✓'},
            ], warn: s.framesMissingBuy>0 ? 'Isi buy_price di halaman manajemen frame untuk akurasi profit.' : null }},
        { icon:'🔄', label:'Modified Orders', val: s.modifiedOrders, color: C.teal,
            modal:{ title:'Orders with Modified Rx', desc:'Orders with is_modified = 1, meaning the prescription in the order differs from the original exam result.', details:[
                {label:'Modified orders', val: s.modifiedOrders+' order'},
                {label:'Total completed orders', val: s.totalOrders+' order'},
                {label:'Percentage', val: Math.round(s.modifiedOrders/Math.max(s.totalOrders,1)*100)+'%'},
            ]}},
    ];
    // Store KPI data for modal reference
    window._kpiData = { summary: s };

    document.getElementById('sa-kpi-grid').innerHTML = kpis.map(function(k, idx) {
        var hasClick = k.stockModal || k.modal;
        var cls = 'sa-kpi' + (hasClick ? ' clickable' : '');
        var onclick = k.stockModal
            ? ' onclick="saStockModalOpen()"'
            : k.modal
                ? ' onclick="saKpiModalOpen('+idx+')"'
                : '';
        return '<div class="'+cls+'" style="--kc:'+k.color+'"'+onclick+'>' +
            '<div class="sa-kpi-icon">'+k.icon+'</div>' +
            '<div class="sa-kpi-val '+(String(k.val).length>9?'sm':'')+'">'+k.val+'</div>' +
            '<div class="sa-kpi-label">'+k.label+'</div>' +
            (k.badge?'<div class="sa-badge '+k.badge.cls+'">'+k.badge.txt+'</div>':'') +
            (hasClick?'<div style="font-size:.55rem;color:rgba(255,255,255,0.2);margin-top:2px;letter-spacing:.5px;">TAP FOR DETAIL</div>':'') +
            '</div>';
    }).join('');

    // Store kpis for modal
    window._kpiDefs = kpis;
}

// ── Monthly chart ────────────────────────────────────────────────
function renderMonthly(d) {
    killChart('monthly');
    // Always restore canvas wrapper (may have been replaced by empty-state div)
    var wrap = document.querySelector('.sa-chart-wrap[data-chart="monthly"]') ||
               (function(){
                   var el = document.getElementById('ch-monthly');
                   return el ? el.parentElement : null;
               })();
    if (wrap) {
        // Ensure canvas exists
        if (!document.getElementById('ch-monthly')) {
            wrap.innerHTML = '<canvas id="ch-monthly"></canvas>';
        }
    }
    var m = d.monthly || [];
    if (!m.length) {
        var wrapEl = document.getElementById('ch-monthly');
        if (wrapEl) wrapEl.parentElement.innerHTML =
            '<div style="display:flex;align-items:center;justify-content:center;height:220px;font-size:.75rem;color:var(--text-muted);">No data for this period.</div>';
        return;
    }
    var canvasEl = document.getElementById('ch-monthly');
    if (!canvasEl) return;
    var ctx = canvasEl.getContext('2d');
    _charts['monthly'] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: m.map(x=>x.label),
            datasets: [
                { label:'Revenue',    data:m.map(x=>x.revenue), backgroundColor:'rgba(255,170,0,0.25)', borderColor:C.amber, borderWidth:1.5, borderRadius:4, order:2, barPercentage:0.55, categoryPercentage:0.7 },
                { label:'COGS',       data:m.map(x=>x.cost),    backgroundColor:'rgba(255,107,107,0.2)', borderColor:C.red,   borderWidth:1.5, borderRadius:4, order:3, barPercentage:0.55, categoryPercentage:0.7 },
                { label:'Net Profit', data:m.map(x=>x.profit),  type:'line', borderColor:C.green, backgroundColor:'rgba(0,255,136,0.06)', pointBackgroundColor:C.green, pointRadius:m.length<5?5:3, pointHoverRadius:6, tension:0.35, fill:true, borderWidth:2, order:1 },
            ]
        },
        options: {
            responsive:true, maintainAspectRatio:false,
            interaction:{mode:'index',intersect:false},
            plugins:{
                legend:{ labels:{boxWidth:10,padding:14,color:'rgba(255,255,255,0.5)'} },
                tooltip:{ callbacks:{ label:function(c){ return ' '+c.dataset.label+': '+fmtFull(c.parsed.y); } } }
            },
            scales:{
                x:{ grid:{color:'rgba(255,255,255,0.03)'}, ticks:{maxRotation:40, color:'rgba(255,255,255,0.4)'} },
                y:{ grid:{color:'rgba(255,255,255,0.05)'}, ticks:{callback:function(v){return fmt(v);}, color:'rgba(255,255,255,0.4)'}, beginAtZero:true }
            }
        }
    });
}

// ── Top lenses + lens size ───────────────────────────────────────
var _rxExpandData = {};

function makeExpandList(containerId, entries, color, metaFn) {
    var SHOW = 5;
    var el = document.getElementById(containerId);
    if (!el) return;
    el.innerHTML = '';
    if (!entries.length) {
        el.innerHTML = '<div style="font-size:.75rem;color:var(--text-muted)">No data</div>';
        return;
    }
    var mx = entries[0][1];

    function makeRow(e) {
        var label = String(e[0]).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        var pct   = Math.round(e[1] / mx * 100);
        var meta  = metaFn ? metaFn(e) : e[1] + 'x';
        return '<div class="sa-bar-item">' +
               '<div class="sa-bar-row">' +
               '<span class="sa-bar-name" title="' + label + '">' + label + '</span>' +
               '<span class="sa-bar-meta">' + meta + '</span>' +
               '</div>' +
               '<div class="sa-bar-track">' +
               '<div class="sa-bar-fill" style="--bc:' + color + ';width:' + pct + '%"></div>' +
               '</div></div>';
    }

    var visible = entries.slice(0, SHOW);
    var hidden  = entries.slice(SHOW);

    var visDiv = document.createElement('div');
    visDiv.className = 'sa-bar-list';
    visDiv.innerHTML = visible.map(makeRow).join('');
    el.appendChild(visDiv);

    if (hidden.length > 0) {
        _rxExpandData[containerId] = hidden.length;

        var hidDiv = document.createElement('div');
        hidDiv.id  = containerId + '-hid';
        hidDiv.style.display = 'none';
        var hidList = document.createElement('div');
        hidList.className = 'sa-bar-list';
        hidList.style.marginTop = '8px';
        hidList.innerHTML = hidden.map(makeRow).join('');
        hidDiv.appendChild(hidList);
        el.appendChild(hidDiv);

        var btn = document.createElement('button');
        btn.className = 'sa-expand-btn';
        btn.textContent = 'Show ' + hidden.length + ' more';
        btn.setAttribute('data-cid', containerId);
        btn.addEventListener('click', function() {
            var cid   = this.getAttribute('data-cid');
            var hDiv  = document.getElementById(cid + '-hid');
            var isOpen = hDiv.style.display !== 'none';
            hDiv.style.display = isOpen ? 'none' : 'block';
            this.textContent = isOpen ? ('Show ' + _rxExpandData[cid] + ' more') : 'Hide';
            this.classList.toggle('open', !isOpen);
        });
        el.appendChild(btn);
    }
}

function renderTopLenses(d) {
    var cats = d.lensCatOut || {};
    var rxCounts = d.lensRxCount || {};
    var catDefs = [
        { key:'singlevision', label:'Single Vision', color:C.blue   },
        { key:'kryptok',      label:'Kryptok',       color:C.teal   },
        { key:'flattop',      label:'Flattop',       color:C.purple },
        { key:'progressive',  label:'Progressive',   color:C.amber  },
    ];
    var container = document.getElementById('sa-lens-cards');
    if (!container) return;
    container.innerHTML = '';

    catDefs.forEach(function(cat) {
        var lensEntries = (cats[cat.key] || []).map(function(l){ return [l.name, l.count, l]; });
        var rxAll       = Object.entries(rxCounts[cat.key] || {}).sort(function(a,b){ return b[1]-a[1]; });
        var rxEntries   = rxAll.filter(function(e){ return e[1] > 1; });

        // Hide card entirely if no lens data at all
        if (lensEntries.length === 0) return;

        // Build card
        var card = document.createElement('div');
        card.className = 'sa-card';
        card.style.marginBottom = '12px';

        // Card header
        var hdr = document.createElement('div');
        hdr.className = 'sa-card-title';
        hdr.style.color = cat.color;
        hdr.style.fontSize = '0.75rem';
        hdr.style.marginBottom = '12px';
        hdr.textContent = cat.label;
        card.appendChild(hdr);

        // Lens type sub-label
        var lbl1 = document.createElement('div');
        lbl1.className = 'sa-card-title';
        lbl1.style.marginBottom = '8px';
        lbl1.textContent = 'Lens Type';
        card.appendChild(lbl1);

        // Lens type list
        var lensDiv = document.createElement('div');
        lensDiv.id = 'sa-lens-' + cat.key + '-type';
        card.appendChild(lensDiv);

        // Size sub-label
        var lbl2 = document.createElement('div');
        lbl2.className = 'sa-card-title';
        lbl2.style.marginTop = '16px';
        lbl2.style.marginBottom = '8px';
        lbl2.textContent = 'Purchased Size (SPH & CYL)';
        card.appendChild(lbl2);

        // Size list
        var rxDiv = document.createElement('div');
        rxDiv.id = 'sa-lens-' + cat.key + '-rx';
        card.appendChild(rxDiv);

        // Append card to DOM FIRST so getElementById works
        container.appendChild(card);

        // Now populate lists (elements are in DOM)
        makeExpandList('sa-lens-' + cat.key + '-type', lensEntries, cat.color, function(e) {
            return e[1] + 'x \u00b7 ' + e[2].margin + '% margin';
        });

        if (rxEntries.length === 0) {
            rxDiv.innerHTML = '<div style="font-size:.72rem;color:var(--text-muted);font-style:italic;">'
                + (rxAll.length === 0
                    ? 'No orders for ' + cat.label + ' yet.'
                    : 'All sizes unique — no 2 customers share the same rx.')
                + '</div>';
        } else {
            makeExpandList('sa-lens-' + cat.key + '-rx', rxEntries, cat.color, function(e) {
                return e[1] + ' customers';
            });
        }
    });
}

// ── Frame shape / size analytics ──────────────────────────────
var _saExpandData = {};

function renderTopFrames(d) {
    var SHOW = 5;

    function barList(elId, obj, color) {
        var el = document.getElementById(elId);
        if (!el) return;
        var entries = Object.entries(obj).sort(function(a,b){ return b[1]-a[1]; });
        if (!entries.length) {
            el.innerHTML = '<div style="font-size:.72rem;color:var(--text-muted)">No data</div>';
            return;
        }
        var mx = entries[0][1];

        function makeRow(e) {
            var label = (e[0] || 'Unknown').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            var pct   = Math.round(e[1] / mx * 100);
            return '<div class="sa-bar-item">' +
                     '<div class="sa-bar-row">' +
                       '<span class="sa-bar-name">' + label + '</span>' +
                       '<span class="sa-bar-meta">' + e[1] + '\xd7</span>' +
                     '</div>' +
                     '<div class="sa-bar-track">' +
                       '<div class="sa-bar-fill" style="--bc:' + color + ';width:' + pct + '%"></div>' +
                     '</div>' +
                   '</div>';
        }

        var visible = entries.slice(0, SHOW);
        var hidden  = entries.slice(SHOW);

        el.innerHTML = '<div class="sa-bar-list" id="' + elId + '-vis">' +
                           visible.map(makeRow).join('') +
                       '</div>';

        if (hidden.length > 0) {
            _saExpandData[elId] = { rows: hidden.map(makeRow).join(''), count: hidden.length };

            var hiddenDiv = document.createElement('div');
            hiddenDiv.id = elId + '-hidden';
            hiddenDiv.style.display = 'none';
            var innerList = document.createElement('div');
            innerList.className = 'sa-bar-list';
            innerList.style.marginTop = '8px';
            innerList.innerHTML = _saExpandData[elId].rows;
            hiddenDiv.appendChild(innerList);
            el.appendChild(hiddenDiv);

            var btn = document.createElement('button');
            btn.id = elId + '-btn';
            btn.className = 'sa-expand-btn';
            btn.textContent = 'Show ' + hidden.length + ' more \u25be';
            btn.setAttribute('data-elid', elId);
            btn.onclick = function() { saToggleExpand(this.getAttribute('data-elid')); };
            el.appendChild(btn);
        }
    }

    barList('sa-shape-bars',  d.shapeCount,  C.blue);
    barList('sa-size-bars',   d.sizeCount,   C.teal);
    barList('sa-struct-bars', d.structCount, C.purple);
    barList('sa-brand-bars',  d.topBrands,   C.amber);
}

function saToggleExpand(elId) {
    var hidden = document.getElementById(elId + '-hidden');
    var btn    = document.getElementById(elId + '-btn');
    var data   = _saExpandData[elId] || {};
    if (!hidden || !btn) return;
    var isOpen = hidden.style.display !== 'none';
    hidden.style.display = isOpen ? 'none' : 'block';
    btn.textContent      = isOpen ? ('Show ' + (data.count || '') + ' more \u25be') : 'Hide \u25b4';
    btn.classList.toggle('expanded', !isOpen);
}
// ── Profitability ────────────────────────────────────────────────
function renderProfitability(d) {
    const s = d.summary;
    const margin = s.totalRevenue > 0 ? Math.round(s.totalProfit / s.totalRevenue * 100) : 0;
    const circ = 2 * Math.PI * 38;
    const dash = circ * Math.max(0, Math.min(100, margin)) / 100;
    const col  = margin > 30 ? C.green : margin > 15 ? C.amber : C.red;
    document.getElementById('sa-ring-c').setAttribute('stroke-dasharray', dash + ' ' + circ);
    document.getElementById('sa-ring-c').setAttribute('stroke', col);
    document.getElementById('sa-ring-val').textContent = margin + '%';
    document.getElementById('sa-ring-val').style.color = col;
    document.getElementById('sa-ring-sub2').textContent = 'Avg per order: ' + d.summary.avgMargin + '%';

    const items = [
        { label:'Total Revenue', val:s.totalRevenue, color:C.amber },
        { label:'Total COGS',    val:s.totalCost,    color:C.red },
        { label:'Net Profit',    val:Math.abs(s.totalProfit), color:s.totalProfit>=0?C.green:C.red },
    ];
    const mx = s.totalRevenue || 1;
    document.getElementById('sa-cost-split').innerHTML = items.map(it => `
        <div class="sa-bar-item">
            <div class="sa-bar-row"><span class="sa-bar-name">${it.label}</span><span class="sa-bar-meta">${fmtFull(it.val)}</span></div>
            <div class="sa-bar-track"><div class="sa-bar-fill" style="--bc:${it.color};width:${Math.round(it.val/mx*100)}%"></div></div>
        </div>`).join('');

    // Margin histogram
    killChart('margin');
    const mb = d.marginBuckets;
    const mL = Object.keys(mb); const mV = mL.map(k=>mb[k]);
    var _ctx = saGetCtx('ch-margin'); if (_ctx) _charts['margin'] = new Chart(_ctx, {
        type:'bar',
        data:{ labels:mL, datasets:[{ data:mV,
            backgroundColor: mL.map(l=>parseInt(l)>=40?'rgba(0,255,136,0.32)':parseInt(l)>=20?'rgba(255,170,0,0.28)':'rgba(255,107,107,0.28)'),
            borderColor: mL.map(l=>parseInt(l)>=40?C.green:parseInt(l)>=20?C.amber:C.red),
            borderWidth:1.5, borderRadius:3 }]
        },
        options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{x:{grid:{display:false}},y:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{stepSize:1}}} }
    });
    const avgM = d.summary.avgMargin;
    const pills = [];
    if (avgM < 20)  pills.push({txt:'⚠ Avg margin is low ('+avgM+'%)', cls:'down'});
    if (avgM >= 35) pills.push({txt:'✓ Margin is healthy ('+avgM+'%)', cls:'up'});
    if (d.summary.modifiedOrders > 0) pills.push({txt:'✏ '+d.summary.modifiedOrders+' order(s) had rx modified', cls:'neu'});
    document.getElementById('sa-margin-pills').innerHTML = pills.map(p=>`<span class="sa-badge ${p.cls}" style="padding:4px 10px;">${p.txt}</span>`).join('');
}

// ── Prescription analytics ───────────────────────────────────────
function renderRxAnalytics(d) {
    // SPH chart
    killChart('sph');
    const sph = d.sphBuckets;
    const sphL = Object.keys(sph), sphV = sphL.map(k=>sph[k]);
    const sphColors = sphL.map(l => {
        if (l === '≤-6') return 'rgba(255,107,107,0.7)';
        if (l.includes('-')) return 'rgba(0,207,255,0.45)';
        if (l.includes('+')) return 'rgba(255,170,0,0.45)';
        return 'rgba(255,255,255,0.2)';
    });
    var _ctx = saGetCtx('ch-sph'); if (_ctx) _charts['sph'] = new Chart(_ctx, {
        type:'bar',
        data:{ labels:sphL, datasets:[{ data:sphV, backgroundColor:sphColors, borderColor:sphColors.map(c=>c.replace('0.45','0.9').replace('0.7','1').replace('0.2','0.5')), borderWidth:1.5, borderRadius:3 }] },
        options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return ' '+c.parsed.y+' patients';}}}}, scales:{x:{grid:{display:false}},y:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{stepSize:1}}} }
    });
    // Insights
    const s = d.summary;
    const tot = Object.values(sph).reduce((a,b)=>a+b,0)||1;
    const myopia   = (sph['≤-6']||0) + (sph['-6 to -3']||0) + (sph['-3 to 0']||0);
    const hyperopia= (sph['0 to +3']||0) + (sph['+3 to +6']||0) + (sph['>+6']||0);
    const items = [
        { label:'Myopia (SPH < 0)',      val:myopia,         pct:Math.round(myopia/tot*100),   color:C.blue },
        { label:'Hyperopia (SPH > 0)',   val:hyperopia,      pct:Math.round(hyperopia/tot*100),color:C.amber },
        { label:'High Myopia (≤ -6)',    val:s.highMyopia,   pct:Math.round(s.highMyopia/tot*100), color:C.red },
        { label:'Presbyopia (has ADD)',  val:s.presbyopia,   pct:Math.round(s.presbyopia/tot*100), color:C.purple },
    ];
    const mx = Math.max(...items.map(i=>i.val), 1);
    document.getElementById('sa-rx-insights').innerHTML = items.map(it => `
        <div class="sa-bar-item">
            <div class="sa-bar-row"><span class="sa-bar-name">${it.label}</span><span class="sa-bar-meta">${it.val} (${it.pct}%)</span></div>
            <div class="sa-bar-track"><div class="sa-bar-fill" style="--bc:${it.color};width:${Math.round(it.val/mx*100)}%"></div></div>
        </div>`).join('');

    // CYL donut
    killChart('cyl');
    const cyl = d.cylSeverity;
    const cylL = Object.keys(cyl), cylV = cylL.map(k=>cyl[k]);
    const cylCols = [C.muted, C.teal, C.amber, C.red, 'rgba(255,255,255,0.1)'];
    var _ctx = saGetCtx('ch-cyl'); if (_ctx) _charts['cyl'] = new Chart(_ctx, {
        type:'doughnut',
        data:{ labels:cylL, datasets:[{ data:cylV, backgroundColor:cylCols, borderColor:'transparent', borderWidth:0, hoverOffset:4 }] },
        options:{ responsive:false, cutout:'65%', plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return ' '+c.label+': '+c.parsed;}}}} }
    });
    const cylTot = cylV.reduce((a,b)=>a+b,0)||1;
    document.getElementById('sa-cyl-legend').innerHTML = cylL.map((l,i)=>`
        <div class="sa-legend-row">
            <div class="sa-legend-dot" style="background:${cylCols[i]}"></div>
            <span class="sa-legend-label">${l}</span>
            <span class="sa-legend-val">${cylV[i]}</span>
        </div>`).join('');
    const cylNotes = [
        { label:'No Astigmatism', val:cyl['None (0)']||0, color:C.muted, note:'Lensa biasa' },
        { label:'Mild (<-1)',     val:cyl['Mild (<-1)']||0, color:C.teal, note:'Cylindrical standar' },
        { label:'Moderate (<-2)',val:cyl['Moderate (<-2)']||0, color:C.amber, note:'Perlu lab/khusus' },
        { label:'Severe (≥-2)',  val:cyl['Severe (≥-2)']||0, color:C.red, note:'High-end lens' },
    ];
    const mxC = Math.max(...cylNotes.map(n=>n.val), 1);
    document.getElementById('sa-cyl-notes').innerHTML = cylNotes.map(n => `
        <div class="sa-bar-item">
            <div class="sa-bar-row"><span class="sa-bar-name">${n.label} — ${n.note}</span><span class="sa-bar-meta">${n.val}</span></div>
            <div class="sa-bar-track"><div class="sa-bar-fill" style="--bc:${n.color};width:${Math.round(n.val/mxC*100)}%"></div></div>
        </div>`).join('');

    // Vision needs donut
    killChart('need');
    const vn = d.visionNeeds;
    const vnL = Object.keys(vn), vnV = vnL.map(k=>vn[k]);
    const vnCols = [C.blue, C.amber, C.teal, C.purple];
    var _ctx = saGetCtx('ch-need'); if (_ctx) _charts['need'] = new Chart(_ctx, {
        type:'doughnut',
        data:{ labels:vnL, datasets:[{ data:vnV, backgroundColor:vnCols, borderColor:'transparent', borderWidth:0, hoverOffset:4 }] },
        options:{ responsive:false, cutout:'65%', plugins:{legend:{display:false}} }
    });
    document.getElementById('sa-need-legend').innerHTML = vnL.map((l,i)=>`
        <div class="sa-legend-row">
            <div class="sa-legend-dot" style="background:${vnCols[i]}"></div>
            <span class="sa-legend-label">${l}</span>
            <span class="sa-legend-val">${vnV[i]}</span>
        </div>`).join('');
    const needNotes = {
        'Distance Only':  { note:'Single vision — jarak', color:C.blue },
        'Near Only':      { note:'Single vision — baca', color:C.amber },
        'Intermediate':   { note:'Computer/office lens', color:C.teal },
        'Multifocal (2+)':{ note:'Progressive / bifocal',  color:C.purple },
    };
    const mxN = Math.max(...vnV, 1);
    document.getElementById('sa-need-notes').innerHTML = vnL.map((l,i) => `
        <div class="sa-bar-item">
            <div class="sa-bar-row"><span class="sa-bar-name">${l} — ${needNotes[l]?.note||''}</span><span class="sa-bar-meta">${vnV[i]}</span></div>
            <div class="sa-bar-track"><div class="sa-bar-fill" style="--bc:${vnCols[i]};width:${Math.round(vnV[i]/mxN*100)}%"></div></div>
        </div>`).join('');
}

// ── Demographics ─────────────────────────────────────────────────
function renderDemographics(d) {
    // Gender donut
    killChart('gender');
    const gc = d.genderCount;
    const gL = ['MALE','FEMALE','Unknown'], gV = gL.map(k=>gc[k]||0), gC = [C.blue, C.pink, C.muted];
    const gTot = gV.reduce((a,b)=>a+b,0)||1;
    var _ctx = saGetCtx('ch-gender'); if (_ctx) _charts['gender'] = new Chart(_ctx, {
        type:'doughnut',
        data:{ labels:['Male 👨','Female 👩','Unknown'], datasets:[{ data:gV, backgroundColor:gC, borderColor:'transparent', borderWidth:0, hoverOffset:4 }] },
        options:{ responsive:false, cutout:'65%', plugins:{ legend:{display:false}, tooltip:{callbacks:{label:function(c){return ' '+c.label+': '+c.parsed+' ('+Math.round(c.parsed/gTot*100)+'%)'}}} } }
    });
    document.getElementById('sa-gender-legend').innerHTML = ['Male 👨','Female 👩','Unknown'].map((l,i)=>`
        <div class="sa-legend-row">
            <div class="sa-legend-dot" style="background:${gC[i]}"></div>
            <span class="sa-legend-label">${l}</span>
            <span class="sa-legend-val">${gV[i]}</span>
        </div>`).join('');

    // Age bars
    const ag = d.ageGroups;
    const mxA = Math.max(...Object.values(ag), 1);
    document.getElementById('sa-age-bars').innerHTML = Object.entries(ag).map(([k,v]) => `
        <div class="sa-bar-item">
            <div class="sa-bar-row"><span class="sa-bar-name">${k} yrs</span><span class="sa-bar-meta">${v} patients</span></div>
            <div class="sa-bar-track"><div class="sa-bar-fill" style="--bc:${C.teal};width:${Math.round(v/mxA*100)}%"></div></div>
        </div>`).join('');

    // Avg order by age
    killChart('age-avg');
    const aba = d.avgByAge, aL = Object.keys(aba), aV = aL.map(k=>aba[k]);
    var _ctx = saGetCtx('ch-age-avg'); if (_ctx) _charts['age-avg'] = new Chart(_ctx, {
        type:'bar',
        data:{ labels:aL, datasets:[{ data:aV, backgroundColor:'rgba(255,170,0,0.25)', borderColor:C.amber, borderWidth:1.5, borderRadius:4, barPercentage:0.5, categoryPercentage:0.65 }] },
        options:{ responsive:true, maintainAspectRatio:false,
            plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return ' IDR '+Math.abs(c.parsed.y).toLocaleString('id-ID');}}}},
            scales:{x:{grid:{display:false},ticks:{color:'rgba(255,255,255,0.4)'}},y:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{callback:function(v){return fmt(v);},color:'rgba(255,255,255,0.4)'},beginAtZero:true}}
        }
    });
}

// ── DOW ──────────────────────────────────────────────────────────
function renderDOW(d) {
    const days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const dow = d.dowCount, mx = Math.max(...dow, 1);
    document.getElementById('sa-dow-grid').innerHTML = days.map((day,i) => {
        const pct = dow[i]/mx;
        const bg  = `rgba(0,207,255,${(0.07 + pct*0.55).toFixed(2)})`;
        const tc  = pct > 0.55 ? C.blue : 'var(--text-main)';
        return `<div class="sa-dow-cell" style="background:${bg}">
            <div class="sa-dow-day">${day}</div>
            <div class="sa-dow-count" style="color:${tc}">${dow[i]}</div>
        </div>`;
    }).join('');
}

// ── Top customers ─────────────────────────────────────────────────
function renderTopCustomers(d) {
    const tbody = document.getElementById('sa-top-cust');
    if (!d.topCustomers.length) { tbody.innerHTML='<tr><td colspan="4" style="color:var(--text-muted);font-size:.72rem;">No data</td></tr>'; return; }
    tbody.innerHTML = d.topCustomers.map((c,i) => `
        <tr>
            <td style="color:var(--text-muted);font-family:monospace;">${i+1}</td>
            <td style="font-weight:700;">${c.name}</td>
            <td><span class="sa-badge up">${c.count}×</span></td>
            <td style="font-family:'JetBrains Mono',monospace;color:${C.amber};font-size:.68rem;">${fmt(c.revenue)}</td>
        </tr>`).join('');
}

// ── Frame inventory ───────────────────────────────────────────────
function renderFrameInventory(d) {
    // Stock age donut
    killChart('stockage');
    const sa = d.stockAge;
    const saL = ['new','old','very old'], saV = saL.map(k=>sa[k]||0);
    const saC = [C.green, C.amber, C.red];
    var _ctx = saGetCtx('ch-stockage'); if (_ctx) _charts['stockage'] = new Chart(_ctx, {
        type:'doughnut',
        data:{ labels:['New','Old','Very Old'], datasets:[{ data:saV, backgroundColor:saC, borderColor:'transparent', borderWidth:0, hoverOffset:4 }] },
        options:{ responsive:false, cutout:'65%', plugins:{legend:{display:false}} }
    });
    const saTot = saV.reduce((a,b)=>a+b,0)||1;
    document.getElementById('sa-stockage-legend').innerHTML = ['New','Old','Very Old'].map((l,i)=>`
        <div class="sa-legend-row">
            <div class="sa-legend-dot" style="background:${saC[i]}"></div>
            <span class="sa-legend-label">${l}</span>
            <span class="sa-legend-val">${saV[i]} units</span>
        </div>`).join('');

    // Structure
    const st = d.structures, stKeys = ['full-rim','semi-rimless','rimless'];
    const stMx = Math.max(...stKeys.map(k=>st[k]||0), 1);
    document.getElementById('sa-structure-bars').innerHTML = stKeys.map(k => `
        <div class="sa-bar-item">
            <div class="sa-bar-row"><span class="sa-bar-name">${k}</span><span class="sa-bar-meta">${st[k]||0} SKU</span></div>
            <div class="sa-bar-track"><div class="sa-bar-fill" style="--bc:${C.blue};width:${Math.round((st[k]||0)/stMx*100)}%"></div></div>
        </div>`).join('');

    // Gender category
    const gc = d.genderCat, gcKeys = ['men','female','unisex'];
    const gcMx = Math.max(...gcKeys.map(k=>gc[k]||0), 1);
    document.getElementById('sa-gendercat-bars').innerHTML = gcKeys.map(k => `
        <div class="sa-bar-item">
            <div class="sa-bar-row"><span class="sa-bar-name">${k}</span><span class="sa-bar-meta">${gc[k]||0} SKU</span></div>
            <div class="sa-bar-track"><div class="sa-bar-fill" style="--bc:${C.teal};width:${Math.round((gc[k]||0)/gcMx*100)}%"></div></div>
        </div>`).join('');

    // Materials
    const mat = d.topMaterials;
    const matKeys = Object.keys(mat), matMx = Math.max(...Object.values(mat), 1);
    document.getElementById('sa-material-bars').innerHTML = matKeys.map(k => `
        <div class="sa-bar-item">
            <div class="sa-bar-row"><span class="sa-bar-name">${k||'Unknown'}</span><span class="sa-bar-meta">${mat[k]} SKU</span></div>
            <div class="sa-bar-track"><div class="sa-bar-fill" style="--bc:${C.purple};width:${Math.round(mat[k]/matMx*100)}%"></div></div>
        </div>`).join('');
}

// ── Rx Modifications ──────────────────────────────────────────────
function renderRxMods(d) {
    const el = document.getElementById('sa-rx-grid');
    if (!d.rxMods.length) {
        el.innerHTML = '<div style="font-size:.75rem;color:var(--text-muted);">No prescription modifications recorded.</div>';
        return;
    }
    function rxVal(v) {
        if (!v || v === '0' || v === '') return '<span class="rx-val empty">—</span>';
        return `<span class="rx-val changed">${v}</span>`;
    }
    el.innerHTML = d.rxMods.map(m => `
        <div class="sa-rx-card">
            <div class="sa-rx-header">
                <span class="sa-rx-name">${m.customer_name||'—'} ${m.gender?'<span style="font-size:.65rem;color:var(--text-muted);">('+m.gender+')</span>':''}</span>
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                    <span class="sa-rx-inv">INV: ${m.invoice_number}</span>
                    <span class="sa-rx-date">${m.modified_at ? m.modified_at.substring(0,16) : '—'}</span>
                </div>
            </div>
            <div class="sa-rx-table">
                <div class="rx-h"></div>
                <div class="rx-h">SPH</div>
                <div class="rx-h">CYL</div>
                <div class="rx-h">AXIS</div>
                <div class="rx-h">ADD</div>
                <div class="rx-eye">OD</div>
                ${rxVal(m.od_sph)} ${rxVal(m.od_cyl)} ${rxVal(m.od_axis)} ${rxVal(m.od_add)}
                <div class="rx-eye">OS</div>
                ${rxVal(m.os_sph)} ${rxVal(m.os_cyl)} ${rxVal(m.os_axis)} ${rxVal(m.os_add)}
            </div>
            ${m.lens_name ? `<div style="margin-top:8px;font-size:.65rem;color:var(--text-muted);">Lensa: <span style="color:var(--sa-blue);">${m.lens_name}</span></div>` : ''}
        </div>`).join('');
}

// ── Data quality alerts ───────────────────────────────────────────
function renderDataAlerts(d) {
    const s = d.summary;
    let html = '';
    if (d.pendingFrames && d.pendingFrames.length > 0) {
        html += `<div class="sa-alert amber" style="flex-direction:column;align-items:flex-start;gap:8px;margin-bottom:10px;">
            <div>⚠️ <strong>${d.pendingFrames.length}</strong> frames in <code>frames_main</code> without buy_price:</div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;">${d.pendingFrames.map(f=>`<span class="sa-pill">${f.ufc}</span>`).join('')}</div>
        </div>`;
    }
    if (s.stagingCount > 0)
        html += `<div class="sa-alert amber" style="margin-bottom:10px;">📦 <strong>${s.stagingCount}</strong> frames in <code>frame_staging</code> not yet moved to <code>frames_main</code>.</div>`;
    if (s.rxModCount > 0)
        html += `<div class="sa-alert blue" style="margin-bottom:10px;">🔬 Total <strong>${s.rxModCount}</strong> prescriptions have been modified from original exam results (logged in prescription_modifications).</div>`;
    if (s.modifiedOrders > 0)
        html += `<div class="sa-alert" style="background:rgba(170,136,255,0.07);border-color:rgba(170,136,255,0.2);color:var(--sa-purple);margin-bottom:10px;">✏️ <strong>${s.modifiedOrders}</strong> orders have <code>is_modified = 1</code> flag in customer_orders.</div>`;
    if (!html) html = '<div class="sa-alert green">✅ No data quality alerts found.</div>';
    document.getElementById('sa-data-alerts').innerHTML = html;
}

// ── Init ─────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', saLoad);
</script>
</body>
</html>