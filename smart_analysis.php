<?php
session_start();
include 'db_config.php';
include 'config_helper.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

// ══════════════════════════════════════════════════════════════════
//  AJAX: Return all analysis data as JSON
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');

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
        WHERE co.order_status = 5
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
               stock_age, stock, buy_price, sell_price
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

        // Lens
        $lname = trim($o['lens_name'] ?? '—');
        if (!isset($lensCount[$lname])) { $lensCount[$lname]=0; $lensRevenue[$lname]=0; $lensProfit[$lname]=0; }
        $lensCount[$lname]++;
        $lensRevenue[$lname] += $amt;
        $lensProfit[$lname]  += $profit;

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

    arsort($frameCount);
    $topFrames = [];
    $i = 0;
    foreach ($frameCount as $ufc => $cnt) {
        if ($i++ >= 8) break;
        $topFrames[] = ['ufc'=>$ufc,'count'=>$cnt,'revenue'=>$frameRevenue[$ufc]];
    }

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
    $stockAge   = ['very old'=>0,'old'=>0,'new'=>0,'null'=>0];
    $materials  = [];
    $structures = ['full-rim'=>0,'semi-rimless'=>0,'rimless'=>0];
    $genderCat  = ['men'=>0,'female'=>0,'unisex'=>0];
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
        'monthly'        => array_values($monthlyData),
        'topLenses'      => $topLenses,
        'topFrames'      => $topFrames,
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

        /* ── Pill tags ── */
        .sa-pill { background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.08); border-radius:20px; padding:4px 11px; font-size:0.67rem; color:var(--text-main); font-weight:600; }

        /* ── Tabs ── */
        .sa-tabs { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; }
        .sa-tab  { background:var(--bg-color); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:7px 14px; font-size:0.72rem; font-weight:700; color:var(--text-muted); cursor:pointer; transition:all 0.2s; font-family:inherit; }
        .sa-tab.active { border-color:rgba(0,255,136,0.3); color:var(--sa-green); background:rgba(0,255,136,0.06); }
        .sa-tab-panel { display:none; } .sa-tab-panel.active { display:block; }

        @media (max-width:480px) {
            .sa-kpi-grid { grid-template-columns:1fr 1fr; }
            .sa-kpi-val  { font-size:1rem; }
            .sa-rx-table { grid-template-columns:auto repeat(4,1fr); font-size:0.62rem; gap:3px 6px; }
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
            <div class="sa-page-header">
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
            <div id="sa-top-alerts"></div>

            <!-- ══ KPIs ══════════════════════════════════════════ -->
            <div class="sa-section-title">📌 Key Performance Indicators</div>
            <div class="sa-kpi-grid" id="sa-kpi-grid"></div>

            <!-- ══ REVENUE TREND ════════════════════════════════ -->
            <div class="sa-section-title">📈 Revenue & Profit Trend</div>
            <div class="sa-card">
                <div class="sa-card-title">Monthly Revenue · COGS · Net Profit (last 18 months)</div>
                <div class="sa-chart-wrap" style="height:220px;"><canvas id="ch-monthly"></canvas></div>
            </div>

            <!-- ══ PRODUCT MIX ════════════════════════════════════ -->
            <div class="sa-section-title">🔭 Product Mix</div>
            <div class="sa-2col">
                <div class="sa-card">
                    <div class="sa-card-title">🔭 Top Lenses — Volume & Margin</div>
                    <div class="sa-bar-list" id="sa-top-lenses"></div>
                </div>
                <div class="sa-card">
                    <div class="sa-card-title">🖼 Top Frames — Volume & Revenue</div>
                    <div class="sa-bar-list" id="sa-top-frames"></div>
                </div>
            </div>

            <!-- ══ PROFITABILITY ══════════════════════════════════ -->
            <div class="sa-section-title">💹 Profitability</div>
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
                    <div class="sa-card-title">Margin Distribution per Order</div>
                    <div class="sa-chart-wrap" style="height:150px;"><canvas id="ch-margin"></canvas></div>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:12px;" id="sa-margin-pills"></div>
                </div>
            </div>

            <!-- ══ PRESCRIPTION ANALYTICS ════════════════════════ -->
            <div class="sa-section-title">👁 Prescription Analytics</div>
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

            <!-- ══ DEMOGRAPHICS ═══════════════════════════════════ -->
            <div class="sa-section-title">👥 Customer Demographics</div>
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

            <!-- ══ OPERATIONS ══════════════════════════════════════ -->
            <div class="sa-section-title">⚙️ Operations</div>
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

            <!-- ══ FRAME INVENTORY ═════════════════════════════════ -->
            <div class="sa-section-title">🖼 Frame Inventory Intelligence</div>
            <div class="sa-3col">
                <div class="sa-card">
                    <div class="sa-card-title">Stock Age (units)</div>
                    <div class="sa-donut-wrap">
                        <canvas id="ch-stockage" width="100" height="100" style="flex-shrink:0;width:100px!important;height:100px!important;"></canvas>
                        <div class="sa-donut-legend" id="sa-stockage-legend"></div>
                    </div>
                </div>
                <div class="sa-card">
                    <div class="sa-card-title">Frame Structure Split</div>
                    <div class="sa-bar-list" id="sa-structure-bars"></div>
                    <div style="margin-top:14px;"><div class="sa-card-title">Gender Category</div>
                    <div class="sa-bar-list" id="sa-gendercat-bars"></div></div>
                </div>
                <div class="sa-card">
                    <div class="sa-card-title">Top Materials (SKU count)</div>
                    <div class="sa-bar-list" id="sa-material-bars"></div>
                </div>
            </div>

            <!-- ══ PRESCRIPTION MODIFICATIONS ═══════════════════ -->
            <div class="sa-section-title">🔬 Prescription Modifications (Rx Changes)</div>
            <div class="sa-card">
                <div class="sa-card-title" style="margin-bottom:16px;">Recent Modified Prescriptions — OD (Right) & OS (Left)</div>
                <div class="sa-rx-grid" id="sa-rx-grid"></div>
            </div>

            <!-- ══ DATA ALERTS ════════════════════════════════════ -->
            <div class="sa-section-title">⚠️ Data Quality Alerts</div>
            <div id="sa-data-alerts"></div>

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
function fmt(n)     { n=Math.abs(n); if(n>=1e9)return'IDR '+(n/1e9).toFixed(1)+'M'; if(n>=1e6)return'IDR '+(n/1e6).toFixed(1)+'jt'; if(n>=1e3)return'IDR '+Math.round(n/1e3)+'rb'; return'IDR '+n.toLocaleString('id-ID'); }
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
function saLoad() {
    const btn = document.getElementById('sa-reload-btn');
    btn.disabled = true; btn.textContent = '⟳ Loading…';
    document.getElementById('sa-loading').classList.remove('hidden');
    fetch('smart_analysis.php?ajax=1')
        .then(r => r.json())
        .then(data => {
            renderAll(data);
            document.getElementById('sa-loading').classList.add('hidden');
            btn.disabled = false; btn.textContent = '⟳ Refresh';
            document.getElementById('sa-timestamp').textContent = 'Updated ' + new Date().toLocaleTimeString('id-ID');
        })
        .catch(err => {
            document.getElementById('sa-loading').classList.add('hidden');
            btn.disabled = false; btn.textContent = '⟳ Refresh';
            document.getElementById('sa-top-alerts').innerHTML = '<div class="sa-alert" style="margin-bottom:12px;">❌ Gagal memuat data. Periksa koneksi atau log server.</div>';
            console.error(err);
        });
}

function renderAll(d) {
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
            ${up?'📈':'📉'} Revenue bulan ini <strong>${fmtFull(s.thisMonthRev)}</strong> —
            ${up?'naik':'turun'} <strong>${Math.abs(s.momChange)}%</strong> vs bulan lalu (${fmtFull(s.lastMonthRev)}).
        </div>`;
    }
    if (s.pendingFrameCount > 0)
        html += `<div class="sa-alert amber" style="margin-bottom:10px;">⚠️ <strong>${s.pendingFrameCount}</strong> frame tanpa buy_price — profit calculation mungkin kurang akurat.</div>`;
    if (s.stagingCount > 0)
        html += `<div class="sa-alert amber" style="margin-bottom:10px;">📦 <strong>${s.stagingCount}</strong> frame masih di staging (belum masuk frames_main).</div>`;
    document.getElementById('sa-top-alerts').innerHTML = html;
}

// ── KPIs ────────────────────────────────────────────────────────
function renderKPIs(d) {
    const s = d.summary;
    const margin = s.totalRevenue > 0 ? Math.round(s.totalProfit / s.totalRevenue * 100) : 0;
    const kpis = [
        { icon:'🏁', label:'Total Orders',      val: s.totalOrders.toLocaleString('id-ID'), color: C.blue },
        { icon:'💰', label:'Total Revenue',     val: fmt(s.totalRevenue),                   color: C.amber },
        { icon:'💹', label:'Net Profit',        val: fmt(s.totalProfit),                    color: s.totalProfit>=0?C.green:C.red,
            badge: s.totalProfit>=0?null:{cls:'down',txt:'Rugi'} },
        { icon:'📊', label:'Overall Margin',    val: margin+'%',                            color: margin>30?C.green:margin>15?C.amber:C.red,
            badge: margin>30?{cls:'up',txt:'✓ Sehat'}:margin>15?{cls:'neu',txt:'⚡ Moderat'}:{cls:'down',txt:'⚠ Rendah'} },
        { icon:'🛒', label:'Avg Order Value',   val: fmt(s.avgOrderValue),                  color: C.purple },
        { icon:'⏱',  label:'Avg Process Days',  val: s.avgProcessDays+'d',                  color: C.teal,
            badge: s.avgProcessDays<=5?{cls:'up',txt:'Cepat'}:s.avgProcessDays<=10?{cls:'neu',txt:'Normal'}:{cls:'down',txt:'Lambat'} },
        { icon:'👁',  label:'High Myopia (≤-6)', val: s.highMyopia,                          color: C.pink },
        { icon:'🔬', label:'Presbyopia (ADD)',  val: s.presbyopia,                          color: C.purple },
        { icon:'✏️', label:'Rx Modified',       val: s.rxModCount,                          color: C.amber },
        { icon:'🖼', label:'Frame Stock (SKU)', val: s.totalFrameStock.toLocaleString('id-ID'), color: C.blue },
        { icon:'⚠️', label:'Frames No Cost',    val: s.framesMissingBuy,                    color: s.framesMissingBuy>0?C.red:C.teal,
            badge: s.framesMissingBuy>0?{cls:'down',txt:'Missing'}:{cls:'up',txt:'✓ OK'} },
        { icon:'🔄', label:'Resep Diubah',      val: s.modifiedOrders,                      color: C.teal },
    ];
    document.getElementById('sa-kpi-grid').innerHTML = kpis.map(k => `
        <div class="sa-kpi" style="--kc:${k.color}">
            <div class="sa-kpi-icon">${k.icon}</div>
            <div class="sa-kpi-val ${String(k.val).length>9?'sm':''}">${k.val}</div>
            <div class="sa-kpi-label">${k.label}</div>
            ${k.badge?`<div class="sa-badge ${k.badge.cls}">${k.badge.txt}</div>`:''}
        </div>`).join('');
}

// ── Monthly chart ────────────────────────────────────────────────
function renderMonthly(d) {
    killChart('monthly');
    const m = d.monthly;
    if (!m.length) {
        document.getElementById('ch-monthly').parentElement.innerHTML =
            '<div style="display:flex;align-items:center;justify-content:center;height:220px;font-size:.75rem;color:var(--text-muted);">Belum ada data order selesai.</div>';
        return;
    }
    const ctx = document.getElementById('ch-monthly').getContext('2d');
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

// ── Top lenses ───────────────────────────────────────────────────
function renderTopLenses(d) {
    const el = document.getElementById('sa-top-lenses');
    if (!d.topLenses.length) { el.innerHTML='<div style="font-size:.75rem;color:var(--text-muted)">No data</div>'; return; }
    const mx = d.topLenses[0].count;
    el.innerHTML = d.topLenses.map(l => `
        <div class="sa-bar-item">
            <div class="sa-bar-row">
                <span class="sa-bar-name" title="${l.name}">${l.name}</span>
                <span class="sa-bar-meta">${l.count}× · ${l.margin}% margin</span>
            </div>
            <div class="sa-bar-track"><div class="sa-bar-fill" style="--bc:${C.blue};width:${Math.round(l.count/mx*100)}%"></div></div>
        </div>`).join('');
}

// ── Top frames ───────────────────────────────────────────────────
function renderTopFrames(d) {
    const el = document.getElementById('sa-top-frames');
    if (!d.topFrames.length) { el.innerHTML='<div style="font-size:.75rem;color:var(--text-muted)">No data</div>'; return; }
    const mx = d.topFrames[0].count;
    el.innerHTML = d.topFrames.map(f => `
        <div class="sa-bar-item">
            <div class="sa-bar-row">
                <span class="sa-bar-name" title="${f.ufc}">${f.ufc}</span>
                <span class="sa-bar-meta">${f.count}× · ${fmt(f.revenue)}</span>
            </div>
            <div class="sa-bar-track"><div class="sa-bar-fill" style="--bc:${C.purple};width:${Math.round(f.count/mx*100)}%"></div></div>
        </div>`).join('');
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
    _charts['margin'] = new Chart(document.getElementById('ch-margin').getContext('2d'), {
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
    if (avgM < 20)  pills.push({txt:'⚠ Rata-rata margin rendah ('+avgM+'%)', cls:'down'});
    if (avgM >= 35) pills.push({txt:'✓ Margin sehat ('+avgM+'%)', cls:'up'});
    if (d.summary.modifiedOrders > 0) pills.push({txt:'✏ '+d.summary.modifiedOrders+' order resepnya diubah', cls:'neu'});
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
    _charts['sph'] = new Chart(document.getElementById('ch-sph').getContext('2d'), {
        type:'bar',
        data:{ labels:sphL, datasets:[{ data:sphV, backgroundColor:sphColors, borderColor:sphColors.map(c=>c.replace('0.45','0.9').replace('0.7','1').replace('0.2','0.5')), borderWidth:1.5, borderRadius:3 }] },
        options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return ' '+c.parsed.y+' pasien';}}}}, scales:{x:{grid:{display:false}},y:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{stepSize:1}}} }
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
    _charts['cyl'] = new Chart(document.getElementById('ch-cyl').getContext('2d'), {
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
    _charts['need'] = new Chart(document.getElementById('ch-need').getContext('2d'), {
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
        'Intermediate':   { note:'Lensa komputer/office', color:C.teal },
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
    _charts['gender'] = new Chart(document.getElementById('ch-gender').getContext('2d'), {
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
            <div class="sa-bar-row"><span class="sa-bar-name">${k} tahun</span><span class="sa-bar-meta">${v} pasien</span></div>
            <div class="sa-bar-track"><div class="sa-bar-fill" style="--bc:${C.teal};width:${Math.round(v/mxA*100)}%"></div></div>
        </div>`).join('');

    // Avg order by age
    killChart('age-avg');
    const aba = d.avgByAge, aL = Object.keys(aba), aV = aL.map(k=>aba[k]);
    _charts['age-avg'] = new Chart(document.getElementById('ch-age-avg').getContext('2d'), {
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
    _charts['stockage'] = new Chart(document.getElementById('ch-stockage').getContext('2d'), {
        type:'doughnut',
        data:{ labels:['New','Old','Very Old'], datasets:[{ data:saV, backgroundColor:saC, borderColor:'transparent', borderWidth:0, hoverOffset:4 }] },
        options:{ responsive:false, cutout:'65%', plugins:{legend:{display:false}} }
    });
    const saTot = saV.reduce((a,b)=>a+b,0)||1;
    document.getElementById('sa-stockage-legend').innerHTML = ['New','Old','Very Old'].map((l,i)=>`
        <div class="sa-legend-row">
            <div class="sa-legend-dot" style="background:${saC[i]}"></div>
            <span class="sa-legend-label">${l}</span>
            <span class="sa-legend-val">${saV[i]} unit</span>
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
        el.innerHTML = '<div style="font-size:.75rem;color:var(--text-muted);">Belum ada modifikasi resep tercatat.</div>';
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
            <div>⚠️ <strong>${d.pendingFrames.length}</strong> frame di <code>frames_main</code> tidak punya buy_price:</div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;">${d.pendingFrames.map(f=>`<span class="sa-pill">${f.ufc}</span>`).join('')}</div>
        </div>`;
    }
    if (s.stagingCount > 0)
        html += `<div class="sa-alert amber" style="margin-bottom:10px;">📦 <strong>${s.stagingCount}</strong> frame di <code>frame_staging</code> belum dipindah ke <code>frames_main</code>.</div>`;
    if (s.rxModCount > 0)
        html += `<div class="sa-alert blue" style="margin-bottom:10px;">🔬 Total <strong>${s.rxModCount}</strong> resep telah dimodifikasi dari hasil pemeriksaan asli (tercatat di prescription_modifications).</div>`;
    if (s.modifiedOrders > 0)
        html += `<div class="sa-alert" style="background:rgba(170,136,255,0.07);border-color:rgba(170,136,255,0.2);color:var(--sa-purple);margin-bottom:10px;">✏️ <strong>${s.modifiedOrders}</strong> order memiliki flag <code>is_modified = 1</code> pada customer_orders.</div>`;
    if (!html) html = '<div class="sa-alert green">✅ Tidak ada data quality alert ditemukan.</div>';
    document.getElementById('sa-data-alerts').innerHTML = html;
}

// ── Init ─────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', saLoad);
</script>
</body>
</html>