<?php
    session_start();
    include 'db_config.php';
include 'activity_helper.php';
    include 'config_helper.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }


    // ── Handle AJAX: update custom_frames buy_price ─────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'update_frame_cost') {
        header('Content-Type: application/json');
        $invoice  = $conn->real_escape_string($_POST['invoice'] ?? '');
        $buy_price = (int)$_POST['buy_price'];
        if (empty($invoice)) {
            echo json_encode(['success' => false, 'error' => 'Invalid invoice']);
            exit();
        }
        $stmt = $conn->prepare("UPDATE custom_frames SET buy_price = ? WHERE invoice_number = ?");
        $stmt->bind_param("is", $buy_price, $invoice);
        if ($stmt->execute()) {
            log_activity($conn, 'custom_frames', $invoice, 'UPDATE', $_SESSION['username'] ?? 'system');
            echo json_encode(['success' => true, 'buy_price' => $buy_price]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        $stmt->close();
        exit();
    }

    // ── Handle AJAX: update packaging cost ──────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'update_packaging') {
        header('Content-Type: application/json');
        $order_id = (int)$_POST['order_id'];
        $box      = (int)$_POST['box'];
        $flanel   = (int)$_POST['flanel'];
        $faset    = (int)$_POST['faset'];
        $wrapping = (int)$_POST['wrapping'];
        $cleaner  = (int)$_POST['cleaner'];

        if ($order_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid order id']);
            exit();
        }

        $packaging_cost = $box + $flanel + $faset + $wrapping + $cleaner;

        $stmt = $conn->prepare("UPDATE customer_orders SET packaging_cost = ? WHERE id = ?");
        $stmt->bind_param("ii", $packaging_cost, $order_id);
        if ($stmt->execute()) {
            log_activity($conn, 'customer_orders', $order_id, 'UPDATE', $_SESSION['username'] ?? 'system');
            echo json_encode(['success' => true, 'packaging_cost' => $packaging_cost]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        $stmt->close();
        exit();
    }

    // ── Handle AJAX: update total_amount with password verification ──────
    if (isset($_POST['action']) && $_POST['action'] === 'update_total') {
        header('Content-Type: application/json');
        $order_id  = (int)$_POST['order_id'];
        $new_total = (int)$_POST['new_total'];
        $password  = $_POST['password'] ?? '';
        $user_id   = (int)$_SESSION['user_id'];

        if ($order_id <= 0 || $new_total < 0 || empty($password)) {
            echo json_encode(['success' => false, 'error' => 'Invalid input']);
            exit();
        }

        // Verify password against current logged-in user
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            echo json_encode(['success' => false, 'error' => 'Incorrect password']);
            exit();
        }

        // Update total_amount
        $stmt2 = $conn->prepare("UPDATE customer_orders SET total_amount = ? WHERE id = ? AND order_status = 5");
        $stmt2->bind_param("ii", $new_total, $order_id);
        if ($stmt2->execute()) {
            log_activity($conn, 'customer_orders', $order_id, 'UPDATE', $_SESSION['username'] ?? 'system');
            echo json_encode(['success' => true, 'new_total' => $new_total]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        $stmt2->close();
        exit();
    }


    // ── Auto-update packaging_cost based on total_amount ─────────────
    // Rules:
    //   total  50,000 –  89,999 → packaging 14,500
    //   total  90,000 – 499,999 → packaging 19,500
    //   total 500,000+          → packaging 26,500
    // Only update if packaging_cost == 19500 (old default) OR within ±1000 of any tier
    // Tier values — packaging that matches exactly one of these = auto-managed
    $tierValues = [14500, 19500, 26500];

    $autoResult = $conn->query("SELECT id, total_amount, packaging_cost FROM customer_orders WHERE order_status = 5");
    if ($autoResult) {
        while ($autoRow = $autoResult->fetch_assoc()) {
            $curPkg   = (int)$autoRow['packaging_cost'];
            $curTotal = (int)$autoRow['total_amount'];

            // Only auto-manage if packaging_cost is exactly one of the tier values
            // (meaning it hasn't been manually customised to something else)
            if (!in_array($curPkg, $tierValues)) continue;

            // Determine correct tier based on total_amount
            if ($curTotal >= 500000) {
                $newPkg = 26500;
            } elseif ($curTotal >= 90000) {
                $newPkg = 19500;
            } elseif ($curTotal >= 50000) {
                $newPkg = 14500;
            } else {
                continue; // below range — skip
            }

            if ($newPkg !== $curPkg) {
                $updStmt = $conn->prepare("UPDATE customer_orders SET packaging_cost = ? WHERE id = ?");
                $updStmt->bind_param("ii", $newPkg, $autoRow['id']);
                $updStmt->execute();
                $updStmt->close();
            }
        }
        $autoResult->free();
    }

    // ── Auto-set custom_frames buy_price if column missing/zero ────────
    // Add buy_price column if not exists (safe to run every time)
    $conn->query("ALTER TABLE custom_frames ADD COLUMN IF NOT EXISTS buy_price DECIMAL(12,2) NOT NULL DEFAULT 0");

    // Function to get default buy_price from sell_price tier
    function getCustomFrameBuyPrice($sellPrice) {
        $sp = (int)$sellPrice;
        if ($sp <= 90000)          return 20000;
        elseif ($sp <= 150000)     return 30000;
        elseif ($sp <= 180000)     return 36000;
        elseif ($sp <= 200000)     return 42000;
        elseif ($sp <= 250000)     return 54000;
        elseif ($sp <= 300000)     return 62000;
        elseif ($sp <= 350000)     return 70000;
        elseif ($sp <= 400000)     return 92000;
        else                       return 100000;
    }

    // Update custom_frames rows where buy_price = 0 (not yet set)
    $cfResult = $conn->query("SELECT id, sell_price, buy_price FROM custom_frames WHERE buy_price = 0 OR buy_price IS NULL");
    if ($cfResult) {
        while ($cfRow = $cfResult->fetch_assoc()) {
            $defaultBuy = getCustomFrameBuyPrice($cfRow['sell_price']);
            $cfId = (int)$cfRow['id'];
            $conn->query("UPDATE custom_frames SET buy_price = $defaultBuy WHERE id = $cfId");
        }
        $cfResult->free();
    }

    // ── Fetch all finished orders (status 5) ─────────────────────────
    $sql = "
        SELECT
            co.id,
            co.customer_number,
            co.invoice_number,
            co.frame_ufc,
            co.lens_name,
            co.customer_phone,
            co.customer_address,
            co.total_amount,
            co.order_date,
            co.due_date,
            co.order_status,
            COALESCE(co.packaging_cost, 19500) AS packaging_cost,
            ce.customer_name  AS patient_name,
            ce.age,
            ce.gender,
            ce.examination_code
        FROM customer_orders co
        LEFT JOIN customer_examinations ce
            ON co.invoice_number = ce.invoice_number
            AND co.invoice_number != '00'
        WHERE co.order_status = 5
        ORDER BY co.order_date DESC, co.id DESC
    ";
    $result = $conn->query($sql);

    $orders = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
    }

    // ── Build month list from orders ──────────────────────────────────
    $monthList = [];
    foreach ($orders as $o) {
        if (!empty($o['order_date'])) {
            $mk = date('Y-m', strtotime($o['order_date']));
            $ml = date('F Y', strtotime($o['order_date']));
            if (!isset($monthList[$mk])) $monthList[$mk] = $ml;
        }
    }
    krsort($monthList); // latest first

    // ── Build faktur (invoice book) list from customer_number ─────────
    // Format: 1/LZ-C/16.31/001/V/26  → segment[2] = "16.31" → faktur = 16
    $fakturList = [];
    foreach ($orders as $o) {
        $cn = $o['customer_number'] ?? '';
        if (!empty($cn)) {
            $parts = explode('/', $cn);
            if (isset($parts[2])) {
                $sub = explode('.', $parts[2]);
                $fNum = (int)$sub[0];
                if ($fNum > 0 && !in_array($fNum, $fakturList)) {
                    $fakturList[] = $fNum;
                }
            }
        }
    }
    sort($fakturList);

    // ── Summary stats ─────────────────────────────────────────────────
    $totalOrders   = count($orders);
    $totalRevenue  = array_sum(array_column($orders, 'total_amount'));

    // Count this month
    $thisMonth     = date('Y-m');
    $thisMonthCount = 0;
    foreach ($orders as $o) {
        if (!empty($o['order_date']) && date('Y-m', strtotime($o['order_date'])) === $thisMonth) {
            $thisMonthCount++;
        }
    }

    // ── Total net profit (all orders) ────────────────────────────────
    $lensJsonPathEarly = __DIR__ . '/data_json/lense_prices.json';
    $lensCostMapEarly  = [];
    if (file_exists($lensJsonPathEarly)) {
        $ljEarly = json_decode(file_get_contents($lensJsonPathEarly), true);
        foreach (['stock', 'lab'] as $lt) {
            if (!empty($ljEarly[$lt])) {
                foreach ($ljEarly[$lt] as $cat => $types) {
                    foreach ($types as $type => $info) {
                        $k = strtoupper(trim($cat) . ' / ' . trim($type));
                        $lensCostMapEarly[$k] = (int)($info['cost'] ?? 0);
                    }
                }
            }
        }
    }
    $frameCostMapEarly = [];
    $r1 = $conn->query("SELECT ufc, buy_price FROM frames_main WHERE buy_price IS NOT NULL");
    if ($r1) { while ($r = $r1->fetch_assoc()) { $frameCostMapEarly[strtoupper(trim($r['ufc']))] = (int)$r['buy_price']; } $r1->free(); }
    $r2 = $conn->query("SELECT ufc, buy_price FROM frame_staging WHERE buy_price IS NOT NULL");
    if ($r2) { while ($r = $r2->fetch_assoc()) { $k2 = strtoupper(trim($r['ufc'])); if (!isset($frameCostMapEarly[$k2])) $frameCostMapEarly[$k2] = (int)$r['buy_price']; } $r2->free(); }
    $customMapEarly = [];
    $r3 = $conn->query("SELECT invoice_number, buy_price FROM custom_frames");
    if ($r3) { while ($r = $r3->fetch_assoc()) { $customMapEarly[$r['invoice_number']] = (int)$r['buy_price']; } $r3->free(); }

    $totalNetProfit = 0;
    $totalCost      = 0;
    foreach ($orders as $o) {
        $oAmt = (int)$o['total_amount'];
        $oPkg = (int)($o['packaging_cost'] ?? 19500);
        $oLnNorm = preg_replace('/\s*[\x{2014}\x{2013}\/]\s*/u', ' / ', trim($o['lens_name'] ?? ''));
        $oLc  = $lensCostMapEarly[strtoupper($oLnNorm)] ?? 0;
        $oUfc = strtoupper(trim($o['frame_ufc'] ?? ''));
        $oFc  = (strlen($oUfc) > 0 && is_numeric($oUfc[0]))
            ? ($customMapEarly[$o['invoice_number'] ?? ''] ?? 0)
            : ($frameCostMapEarly[$oUfc] ?? 0);
        $totalNetProfit += ($oAmt - $oLc - $oFc - $oPkg);
        $totalCost      += ($oLc + $oFc + $oPkg);
    }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
    <title>Purchase History — Finished Orders</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* ── Page Layout ─────────────────────────────────────── */
        .cs-body {
            padding: 20px;
            max-width: 1100px;
            margin: auto;
        }

        .cs-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .cs-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: 1px;
        }

        .cs-subtitle {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 3px;
            letter-spacing: 0.5px;
        }

        /* ── Smart Filter Bar ────────────────────────────────── */
        .ph-filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            align-items: center;
        }

        .ph-filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .ph-filter-label {
            font-size: 0.58rem;
            color: var(--text-muted);
            letter-spacing: 0.8px;
            text-transform: uppercase;
            padding-left: 4px;
        }

        .ph-select {
            background: var(--bg-color);
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 14px;
            color: var(--text-main);
            font-size: 0.78rem;
            font-weight: 600;
            padding: 8px 14px;
            font-family: inherit;
            box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 6px var(--shadow-light);
            outline: none;
            cursor: pointer;
            transition: border-color 0.2s;
            min-width: 140px;
        }

        .ph-select:focus {
            border-color: rgba(0,255,136,0.3);
        }

        .ph-select option {
            background: var(--bg-color);
        }

        /* ── Search bar ──────────────────────────────────────── */
        .cs-search-wrap {
            position: relative;
            flex: 1;
            min-width: 200px;
            max-width: 320px;
        }

        .cs-search {
            width: 100%;
            background: var(--bg-color);
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 20px;
            color: var(--text-main);
            font-size: 0.8rem;
            padding: 9px 16px 9px 38px;
            font-family: inherit;
            box-shadow: inset 4px 4px 8px var(--shadow-dark), inset -4px -4px 8px var(--shadow-light);
            outline: none;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }

        .cs-search:focus { border-color: rgba(0,255,136,0.3); }

        .cs-search-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.9rem;
            pointer-events: none;
        }

        .ph-filter-reset {
            background: var(--bg-color);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            color: var(--text-muted);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            padding: 8px 16px;
            cursor: pointer;
            font-family: inherit;
            box-shadow: 4px 4px 8px var(--shadow-dark), -4px -4px 8px var(--shadow-light);
            transition: all 0.2s;
            align-self: flex-end;
        }

        .ph-filter-reset:hover {
            color: var(--text-main);
            border-color: rgba(255,255,255,0.18);
        }

        /* ── Active filter chips ─────────────────────────────── */
        .ph-active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 16px;
            min-height: 0;
        }

        .ph-active-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(0,255,136,0.08);
            border: 1px solid rgba(0,255,136,0.25);
            border-radius: 20px;
            color: #00ff88;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            padding: 3px 10px 3px 12px;
        }

        .ph-active-chip button {
            background: none;
            border: none;
            color: #00ff88;
            cursor: pointer;
            font-size: 0.75rem;
            padding: 0;
            line-height: 1;
            opacity: 0.7;
        }

        .ph-active-chip button:hover { opacity: 1; }

        /* ── Summary stat cards ──────────────────────────────── */
        .cs-stats-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .cs-stat-card {
            flex: 1;
            min-width: 120px;
            background: var(--bg-color);
            border-radius: 16px;
            padding: 14px 16px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.04);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .cs-stat-num {
            font-size: 1.6rem;
            font-weight: 900;
            line-height: 1;
        }

        .cs-stat-label {
            font-size: 0.62rem;
            color: var(--text-muted);
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }

        /* ── Result count ────────────────────────────────────── */
        .ph-result-count {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-bottom: 14px;
            letter-spacing: 0.5px;
        }

        .ph-result-count span {
            color: #00ff88;
            font-weight: 700;
        }

        /* ── Order card ──────────────────────────────────────── */
        .cs-card {
            background: var(--bg-color);
            border-radius: 20px;
            padding: 20px 22px;
            margin-bottom: 14px;
            box-shadow: 8px 8px 20px var(--shadow-dark), -8px -8px 20px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.04);
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .cs-card:hover {
            transform: translateY(-1px);
            box-shadow: 10px 10px 24px var(--shadow-dark), -10px -10px 24px var(--shadow-light);
        }

        .cs-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .cs-patient-info { display: flex; flex-direction: column; gap: 3px; }

        .cs-patient-name {
            font-size: 1rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: 0.5px;
        }

        .cs-meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 4px;
        }

        .cs-chip {
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.6px;
            padding: 3px 10px;
            border-radius: 20px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            color: var(--text-muted);
        }

        .cs-chip.inv   { color: #00cfff; border-color: rgba(0,207,255,0.25);    background: rgba(0,207,255,0.07); }
        .cs-chip.cust  { color: #aa88ff; border-color: rgba(170,136,255,0.25);  background: rgba(170,136,255,0.07); }
        .cs-chip.age   { color: #ffaa00; border-color: rgba(255,170,0,0.25);    background: rgba(255,170,0,0.07); }
        .cs-chip.done  { color: #00ff88; border-color: rgba(0,255,136,0.25);    background: rgba(0,255,136,0.07); }

        /* ── Finished badge ──────────────────────────────────── */
        .cs-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.8px;
            border: 1px solid;
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* ── Details grid ────────────────────────────────────── */
        .cs-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid rgba(255,255,255,0.05);
        }

        .cs-detail-item { display: flex; flex-direction: column; gap: 3px; }

        .cs-detail-label {
            font-size: 0.62rem;
            color: var(--text-muted);
            letter-spacing: 0.7px;
            text-transform: uppercase;
        }

        .cs-detail-value {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .cs-detail-value.price { color: #ffaa00; font-family: monospace; }
        .cs-detail-value.paid  { color: #00ff88; }

        /* ── Collapsible card body ───────────────────────────── */
        .cs-card-header {
            cursor: pointer;
            user-select: none;
        }

        .cs-card-header:hover .cs-patient-name {
            color: #00ff88;
            transition: color 0.2s;
        }

        .cs-card-body {
            overflow: hidden;
            max-height: 0;
            transition: max-height 0.35s ease, opacity 0.3s ease;
            opacity: 0;
        }

        .cs-card.expanded .cs-card-body {
            max-height: 600px;
            opacity: 1;
        }

        .cs-chevron {
            font-size: 0.7rem;
            color: var(--text-muted);
            transition: transform 0.3s;
            flex-shrink: 0;
        }

        .cs-card.expanded .cs-chevron { transform: rotate(180deg); }

        /* ── Empty state ─────────────────────────────────────── */
        .cs-empty {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .cs-empty-icon  { font-size: 2.5rem; margin-bottom: 12px; }
        .cs-empty-title { font-size: 1rem; font-weight: 700; color: var(--text-main); }
        .cs-empty-sub   { font-size: 0.75rem; margin-top: 5px; }

        /* ── Toast ───────────────────────────────────────────── */
        #ph-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: var(--bg-color);
            border: 1px solid rgba(0,255,136,0.35);
            border-radius: 14px;
            color: #00ff88;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 12px 20px;
            box-shadow: 0 0 20px rgba(0,255,136,0.15);
            z-index: 9999;
            opacity: 0;
            transform: translateY(12px);
            transition: all 0.3s;
            pointer-events: none;
        }

        #ph-toast.show { opacity: 1; transform: translateY(0); }

        /* ── Month divider ───────────────────────────────────── */
        .ph-month-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0 12px;
        }

        .ph-month-divider-label {
            font-size: 0.68rem;
            font-weight: 800;
            color: var(--text-muted);
            letter-spacing: 1px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .ph-month-divider-line {
            flex: 1;
            height: 1px;
            background: rgba(255,255,255,0.06);
        }

        /* ── Responsive ──────────────────────────────────────── */
        @media (max-width: 600px) {
            .cs-body { padding: 10px; }

            .cs-header {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                margin-bottom: 16px;
            }
            .cs-title { font-size: 1.1rem; }

            .ph-filter-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .cs-search-wrap { max-width: 100%; }

            .ph-select { width: 100%; }

            .cs-stats-row { gap: 8px; }
            .cs-stat-card { min-width: 0; padding: 10px 12px; }
            .cs-stat-num  { font-size: 1.3rem; }
            .cs-stat-label { font-size: 0.58rem; }

            .cs-card { padding: 14px; border-radius: 16px; }

            .cs-card-header.cs-card-top {
                flex-direction: row;
                align-items: center;
                gap: 10px;
            }

            .cs-card-header .cs-patient-info { flex: 1; min-width: 0; }
            .cs-card-header .cs-patient-name { font-size: 0.9rem; }
            .cs-status-badge { font-size: 0.6rem; padding: 4px 9px; }
            .cs-chip { font-size: 0.6rem; padding: 2px 8px; }

            .cs-details-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }

            #ph-toast { left: 12px; right: 12px; bottom: 16px; text-align: center; }

            .btn-group { padding: 0 10px; }
            .btn-group .back-main { width: 100%; box-sizing: border-box; }
        }




        /* ── Net profit highlight ────────────────────────────── */
        .ph-net-profit {
            display: inline-flex;
            align-items: baseline;
            gap: 4px;
        }

        /* ── Edit total button ───────────────────────────────── */
        .ph-edit-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.75rem;
            padding: 0 0 0 5px;
            vertical-align: middle;
            opacity: 0.5;
            transition: opacity 0.2s;
            line-height: 1;
        }
        .ph-edit-btn:hover { opacity: 1; }

        /* ── Edit modal ──────────────────────────────────────── */
        .ph-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.75);
            z-index: 9000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .ph-modal-overlay.open { display: flex; }

        .ph-modal {
            background: var(--bg-color);
            border-radius: 24px;
            padding: 28px;
            max-width: 420px;
            width: 100%;
            box-shadow: 20px 20px 60px var(--shadow-dark), -20px -20px 60px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.07);
        }

        .ph-modal-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .ph-modal-sub {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-bottom: 20px;
        }

        .ph-modal-field {
            margin-bottom: 14px;
        }

        .ph-modal-field label {
            display: block;
            font-size: 0.62rem;
            color: var(--text-muted);
            letter-spacing: 0.7px;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .ph-modal-input {
            width: 100%;
            background: var(--bg-color);
            border: 1px solid rgba(255,255,255,0.09);
            border-radius: 14px;
            color: var(--text-main);
            font-size: 0.85rem;
            font-weight: 600;
            padding: 10px 14px;
            font-family: inherit;
            box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 6px var(--shadow-light);
            outline: none;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }

        .ph-modal-input:focus { border-color: rgba(0,255,136,0.35); }

        .ph-modal-input.password-input { letter-spacing: 2px; }

        .ph-modal-preview {
            font-size: 0.72rem;
            color: #ffaa00;
            font-weight: 700;
            margin-top: 6px;
            min-height: 18px;
            font-family: monospace;
        }

        .ph-modal-preview.error { color: #ff6b6b; }

        .ph-modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .ph-modal-btn {
            flex: 1;
            padding: 10px 16px;
            border-radius: 14px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.6px;
            cursor: pointer;
            font-family: inherit;
            border: 1px solid;
            transition: all 0.2s;
        }

        .ph-modal-btn.cancel {
            background: var(--bg-color);
            border-color: rgba(255,255,255,0.1);
            color: var(--text-muted);
            box-shadow: 4px 4px 8px var(--shadow-dark), -4px -4px 8px var(--shadow-light);
        }

        .ph-modal-btn.cancel:hover { color: var(--text-main); }

        .ph-modal-btn.confirm {
            background: rgba(0,255,136,0.1);
            border-color: rgba(0,255,136,0.35);
            color: #00ff88;
        }

        .ph-modal-btn.confirm:hover {
            background: rgba(0,255,136,0.2);
            box-shadow: 0 0 12px rgba(0,255,136,0.2);
        }

        .ph-modal-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        @media (max-width: 600px) {
            .ph-modal { padding: 20px 18px 28px; border-radius: 24px 24px 0 0; }
            .ph-modal-overlay { align-items: flex-end; padding: 0; }
            .ph-modal-actions { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="main-wrapper">
        <div class="content-area" style="flex-direction: column">
            <div class="header-container" style="margin-left: auto; margin-right: auto; width: 100%;">
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

            <div class="main-card" style="margin-left: auto; margin-right: auto; width: 100%;">
    <div class="cs-body">

        <!-- ── Page Header ─────────────────────────────────────── -->
        <div class="cs-header">
            <div>
                <div class="cs-title">🏁 Purchase History</div>
                <div class="cs-subtitle">COMPLETED ORDERS — STATUS 5 (FINISHED)</div>
            </div>
        </div>

        <!-- ── Summary Stats ───────────────────────────────────── -->
        <div class="cs-stats-row">
            <div class="cs-stat-card">
                <div class="cs-stat-num" id="ph-finished-display" style="color:#00ff88;"><?php echo $totalOrders; ?></div>
                <div class="cs-stat-label">🏁 Total Finished</div>
            </div>
            <div class="cs-stat-card">
                <div class="cs-stat-num" id="ph-revenue-display" style="color:#ffaa00;font-size:1.1rem;">Rp <?php echo number_format($totalRevenue, 0, ',', '.'); ?></div>
                <div class="cs-stat-label">💰 Total Revenue</div>
            </div>
            <div class="cs-stat-card">
                <div class="cs-stat-num" id="ph-month-display" style="color:#00cfff;"><?php echo $thisMonthCount; ?></div>
                <div class="cs-stat-label">📅 This Month</div>
            </div>
            <div class="cs-stat-card">
                <div class="cs-stat-num" id="ph-profit-display" style="color:<?php echo $totalNetProfit >= 0 ? '#00ff88' : '#ff6b6b'; ?>;font-size:1.1rem;">
                    <?php echo ($totalNetProfit >= 0 ? '' : '-') . 'Rp ' . number_format(abs($totalNetProfit), 0, ',', '.'); ?>
                </div>
                <div class="cs-stat-label">💹 Total Net Profit</div>
            </div>
            <div class="cs-stat-card">
                <div class="cs-stat-num" id="ph-cost-display" style="color:#ff6b6b;font-size:1.1rem;">
                    Rp <?php echo number_format($totalCost, 0, ',', '.'); ?>
                </div>
                <div class="cs-stat-label">💸 Total Cost</div>
            </div>

        </div>

        <!-- ── Smart Filter Bar ────────────────────────────────── -->
        <div class="ph-filter-bar">

            <!-- Search -->
            <div class="ph-filter-group" style="flex:1;min-width:200px;">
                <div class="ph-filter-label">🔍 Search</div>
                <div class="cs-search-wrap" style="max-width:100%;">
                    <span class="cs-search-icon">🔍</span>
                    <input type="text" class="cs-search" id="ph-search"
                           placeholder="Name, invoice, phone, frame…"
                           oninput="phApplyFilters()">
                </div>
            </div>

            <!-- Month filter -->
            <div class="ph-filter-group">
                <div class="ph-filter-label">📅 Month</div>
                <select class="ph-select" id="ph-filter-month" onchange="phApplyFilters()">
                    <option value="">All Months</option>
                    <?php foreach ($monthList as $mk => $ml): ?>
                    <option value="<?php echo $mk; ?>"><?php echo $ml; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Gender filter -->
            <div class="ph-filter-group">
                <div class="ph-filter-label">👤 Gender</div>
                <select class="ph-select" id="ph-filter-gender" onchange="phApplyFilters()">
                    <option value="">All</option>
                    <option value="male">Male 👨</option>
                    <option value="female">Female 👩</option>
                </select>
            </div>

            <!-- Faktur filter -->
            <div class="ph-filter-group">
                <div class="ph-filter-label">📒 Invoice Book</div>
                <select class="ph-select" id="ph-filter-faktur" onchange="phApplyFilters()">
                    <option value="">All Books</option>
                    <?php foreach ($fakturList as $fNum): ?>
                    <option value="<?php echo $fNum; ?>">Book #<?php echo $fNum; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

<!-- Sort -->
            <div class="ph-filter-group">
                <div class="ph-filter-label">↕ Sort</div>
                <select class="ph-select" id="ph-sort" onchange="phApplyFilters()">
                    <option value="date_desc">Newest First</option>
                    <option value="date_asc">Oldest First</option>
                    <option value="name_asc">Name A–Z</option>
                    <option value="name_desc">Name Z–A</option>
                    <option value="total_desc">Highest Total</option>
                    <option value="total_asc">Lowest Total</option>
                </select>
            </div>

            <button class="ph-filter-reset" onclick="phResetFilters()" title="Reset all filters">✕ Reset</button>
        </div>

        <!-- ── Active filter chips ──────────────────────────────── -->
        <div class="ph-active-filters" id="ph-active-filters"></div>

        <!-- ── Result count ─────────────────────────────────────── -->
        <div class="ph-result-count" id="ph-result-count"></div>

        <!-- ── Order Cards ─────────────────────────────────────── -->
        <?php if (empty($orders)): ?>
        <div class="cs-empty">
            <div class="cs-empty-icon">📭</div>
            <div class="cs-empty-title">No finished orders yet</div>
            <div class="cs-empty-sub">Completed orders will appear here once their status is set to Finished.</div>
        </div>
        <?php else: ?>

        <div id="ph-cards-container">
        <?php
    // ── Build lens cost lookup from lense_prices.json ──────────────────
    $lensJsonPath = __DIR__ . '/data_json/lense_prices.json';
    $lensCostMap  = []; // key: "CATEGORY / TYPE" (uppercase) => cost
    if (file_exists($lensJsonPath)) {
        $lensJson = json_decode(file_get_contents($lensJsonPath), true);
        foreach (['stock', 'lab'] as $lensType) {
            if (!empty($lensJson[$lensType])) {
                foreach ($lensJson[$lensType] as $cat => $types) {
                    foreach ($types as $type => $info) {
                        $key = strtoupper(trim($cat) . ' / ' . trim($type));
                        $lensCostMap[$key] = ['cost' => (int)($info['cost'] ?? 0), 'type' => $lensType];
                    }
                }
            }
        }
    }

    // ── Build frame cost lookup ───────────────────────────────────────
    $frameCostMap = [];
    $fmRes = $conn->query("SELECT ufc, buy_price FROM frames_main WHERE buy_price IS NOT NULL");
    if ($fmRes) { while ($r = $fmRes->fetch_assoc()) { $frameCostMap[strtoupper(trim($r['ufc']))] = (int)$r['buy_price']; } $fmRes->free(); }
    $fsRes = $conn->query("SELECT ufc, buy_price FROM frame_staging WHERE buy_price IS NOT NULL");
    if ($fsRes) { while ($r = $fsRes->fetch_assoc()) { $key = strtoupper(trim($r['ufc'])); if (!isset($frameCostMap[$key])) $frameCostMap[$key] = (int)$r['buy_price']; } $fsRes->free(); }

    // ── Pre-fetch custom_frames keyed by invoice_number ───────────────
    $customFrameMap = [];
    $cfRes = $conn->query("SELECT id, invoice_number, sell_price, buy_price FROM custom_frames");
    if ($cfRes) { while ($r = $cfRes->fetch_assoc()) { $customFrameMap[$r['invoice_number']] = $r; } $cfRes->free(); }
        ?>

        <?php foreach ($orders as $o):
            $name      = trim($o['patient_name'] ?? '—');
            $age       = (int)($o['age'] ?? 0);
            $gender    = strtolower(trim($o['gender'] ?? ''));
            $genderIcon = ($gender === 'male' || $gender === 'laki-laki' || $gender === 'm') ? '👨' : '👩';
            $phone     = $o['customer_phone'] ?? '';
            $lensName  = $o['lens_name'] ?? '—';
            $frameUfc  = $o['frame_ufc'] ?? '—';
            $totalAmt  = (int)$o['total_amount'];
            $orderDate = $o['order_date'] ? date('d/m/Y', strtotime($o['order_date'])) : '—';
            $orderMonth = $o['order_date'] ? date('Y-m', strtotime($o['order_date'])) : '';
            $dueDate   = $o['due_date']   ? date('d/m/Y', strtotime($o['due_date']))   : '—';
            $genderNorm = ($gender === 'male' || $gender === 'laki-laki' || $gender === 'm') ? 'male' : 'female';
            $pkgTotal   = (int)($o['packaging_cost'] ?? 19500);
            // Default breakdown for modal
            $pkgBox      = 3000;
            $pkgFlanel   = 500;
            $pkgFaset    = 10000;
            $pkgWrapping = 3000;

            // ── Extract faktur number from customer_number ────────────
            // Format: 1/LZ-C/16.31/001/V/26 → parts[2] = "16.31" → faktur = 16
            $fakturNum = 0;
            $_cnParts = explode('/', $o['customer_number'] ?? '');
            if (isset($_cnParts[2])) {
                $_cnSub = explode('.', $_cnParts[2]);
                $fakturNum = (int)$_cnSub[0];
            }

            // ── Lens cost ─────────────────────────────────────────────
            // Detect stock vs lab: diff <= 3 days = stock, >= 10 days = lab
            // Normalize lens_name: DB uses em dash (SINGLE VISION — ONE-DRIVE)
            // JSON keys use slash (SINGLE VISION / ONE-DRIVE)
            $lensNameNorm = preg_replace('/\s*[\x{2014}\x{2013}\/]\s*/u', ' / ', trim($lensName));
            $lensKey      = strtoupper($lensNameNorm);
            $diffDays    = 0;
            if (!empty($o['order_date']) && !empty($o['due_date'])) {
                $diffDays = (int)round((strtotime(date('Y-m-d', strtotime($o['due_date']))) - strtotime(date('Y-m-d', strtotime($o['order_date'])))) / 86400);
            }
            $lensType    = ($diffDays >= 10) ? 'lab' : 'stock';
            $lensCost    = 0;
            $lensSource  = '—';
            // Try exact key match first
            if (isset($lensCostMap[$lensKey]) && $lensCostMap[$lensKey]['type'] === $lensType) {
                $lensCost   = $lensCostMap[$lensKey]['cost'];
                $lensSource = $lensKey;
            } else {
                // Try any type (fallback)
                if (isset($lensCostMap[$lensKey])) {
                    $lensCost   = $lensCostMap[$lensKey]['cost'];
                    $lensSource = $lensKey;
                }
            }

            // ── Frame cost ────────────────────────────────────────────
            $ufcUpper    = strtoupper(trim($frameUfc));
            $frameCost   = 0;
            $frameSource = '—';
            $isCustom    = false;
            $customFrameData = null;

            // Detect custom frame: UFC starts with digit
            if (strlen($ufcUpper) > 0 && is_numeric($ufcUpper[0])) {
                $isCustom = true;
                $inv      = $o['invoice_number'] ?? '';
                if (isset($customFrameMap[$inv])) {
                    $customFrameData = $customFrameMap[$inv];
                    $frameCost   = (int)$customFrameData['buy_price'];
                    $frameSource = 'custom';
                }
            } else {
                // Look up in frames_main then frame_staging
                if (isset($frameCostMap[$ufcUpper])) {
                    $frameCost   = $frameCostMap[$ufcUpper];
                    $frameSource = 'catalog';
                }
            }

            // ── Net profit ────────────────────────────────────────────
            $netProfit   = $totalAmt - $lensCost - $frameCost - $pkgTotal;
        ?>
        <div class="cs-card"
             data-name="<?php echo htmlspecialchars(strtolower($name)); ?>"
             data-inv="<?php echo htmlspecialchars(strtolower($o['invoice_number'] ?? '')); ?>"
             data-phone="<?php echo htmlspecialchars(strtolower($phone)); ?>"
             data-custnum="<?php echo htmlspecialchars(strtolower($o['customer_number'] ?? '')); ?>"
             data-frame="<?php echo htmlspecialchars(strtolower($frameUfc)); ?>"
             data-lens="<?php echo htmlspecialchars(strtolower($lensName)); ?>"
             data-month="<?php echo $orderMonth; ?>"
             data-gender="<?php echo $genderNorm; ?>"
             data-id="<?php echo $o['id']; ?>"
             data-total="<?php echo $totalAmt; ?>"
             data-fullname="<?php echo htmlspecialchars($name); ?>"
             data-orderdate-raw="<?php echo htmlspecialchars($o['order_date'] ?? ''); ?>"
             data-pkg-total="<?php echo $pkgTotal; ?>"
             data-total-amount="<?php echo $totalAmt; ?>"
             data-net-profit="<?php echo $netProfit; ?>"
             data-cost="<?php echo $lensCost + $frameCost + $pkgTotal; ?>"
             data-lens-cost="<?php echo $lensCost; ?>"
             data-frame-cost="<?php echo $frameCost; ?>"
             data-is-custom="<?php echo $isCustom ? '1' : '0'; ?>"
             data-invoice="<?php echo htmlspecialchars($o['invoice_number'] ?? ''); ?>"
             data-faktur="<?php echo $fakturNum; ?>">

            <!-- Header (clickable) -->
            <div class="cs-card-header cs-card-top" onclick="csToggleCard(this)">
                <div class="cs-patient-info">
                    <div class="cs-patient-name"><?php echo htmlspecialchars($name); ?> <?php echo $genderIcon; ?></div>
                    <div class="cs-meta-row">
                        <span class="cs-chip inv">INV: <?php echo htmlspecialchars($o['invoice_number'] ?? '—'); ?></span>
                        <span class="cs-chip cust"><?php echo htmlspecialchars($o['customer_number'] ?? '—'); ?></span>
                        <?php if ($age > 0): ?>
                        <span class="cs-chip age"><?php echo $age; ?> yrs</span>
                        <?php endif; ?>
                        <span class="cs-chip done">🏁 FINISHED</span>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
                    <div style="text-align:right;">
                        <div class="ph-header-total" style="font-size:0.72rem;font-weight:800;color:#ffaa00;font-family:monospace;">
                            Rp <?php echo number_format($totalAmt, 0, ',', '.'); ?>
                        </div>
                        <div style="font-size:0.62rem;color:#00ff88;font-weight:700;margin-top:2px;">PAID ✓</div>
                    </div>
                    <span class="cs-chevron">▼</span>
                </div>
            </div>

            <!-- Collapsible body -->
            <div class="cs-card-body">
                <div class="cs-details-grid">
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Lens</span>
                        <span class="cs-detail-value"><?php echo htmlspecialchars($lensName); ?></span>
                    </div>
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Frame (UFC)</span>
                        <span class="cs-detail-value"><?php echo htmlspecialchars($frameUfc); ?></span>
                    </div>
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Order Date</span>
                        <span class="cs-detail-value"><?php echo $orderDate; ?></span>
                    </div>
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Due Date</span>
                        <span class="cs-detail-value"><?php echo $dueDate; ?></span>
                    </div>
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Total
                            <button class="ph-edit-btn" onclick="phOpenEditModal(this)" title="Edit total amount">✏️</button>
                        </span>
                        <span class="ph-total-display cs-detail-value price" data-raw="<?php echo $totalAmt; ?>">Rp <?php echo number_format($totalAmt, 0, ',', '.'); ?></span>
                    </div>

                    <!-- Packaging Cost -->
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">
                            Packaging
                            <button class="ph-edit-btn" onclick="phOpenPkgModal(this)" title="Edit packaging cost">✏️</button>
                        </span>
                        <span class="ph-pkg-total cs-detail-value price">Rp <?php echo number_format($pkgTotal, 0, ',', '.'); ?></span>
                    </div>

                    <!-- Frame Cost (custom = editable) -->
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">
                            Frame Cost
                            <?php if ($isCustom): ?>
                            <button class="ph-edit-btn" onclick="phOpenFrameCostModal(this)" title="Edit frame cost">✏️</button>
                            <?php endif; ?>
                        </span>
                        <span class="ph-frame-cost-display cs-detail-value price" style="color:#aa88ff;">
                            <?php echo $frameCost > 0 ? 'Rp ' . number_format($frameCost, 0, ',', '.') : '<span style="color:#555;font-size:0.72rem;">Not found</span>'; ?>
                        </span>
                    </div>

                    <!-- Lens Cost -->
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Lens Cost <span style="font-size:0.58rem;color:#555;">(<?php echo $lensType; ?>)</span></span>
                        <span class="cs-detail-value price" style="color:#aa88ff;">
                            <?php echo $lensCost > 0 ? 'Rp ' . number_format($lensCost, 0, ',', '.') : '<span style="color:#555;font-size:0.72rem;">Not found</span>'; ?>
                        </span>
                    </div>

                    <!-- Net Profit -->
                    <div class="cs-detail-item" style="grid-column: 1 / -1;">
                        <span class="cs-detail-label">Net Profit</span>
                        <span class="ph-net-profit cs-detail-value" style="font-size:1rem;font-weight:900;color:<?php echo $netProfit >= 0 ? '#00ff88' : '#ff6b6b'; ?>;">
                            <?php echo ($netProfit >= 0 ? '' : '−') . 'Rp ' . number_format(abs($netProfit), 0, ',', '.'); ?>
                            <span style="font-size:0.65rem;font-weight:600;color:var(--text-muted);margin-left:6px;">
                                (<?php echo $totalAmt > 0 ? round($netProfit / $totalAmt * 100) : 0; ?>%)
                            </span>
                        </span>
                    </div>

                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Phone No.</span>
                        <span class="cs-detail-value"><?php echo htmlspecialchars($phone ?: '—'); ?></span>
                    </div>
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Address</span>
                        <span class="cs-detail-value" style="font-size:0.75rem;"><?php echo htmlspecialchars($o['customer_address'] ?? '—'); ?></span>
                    </div>
                </div>
            </div>

        </div>
        <?php endforeach; ?>
        </div>

        <?php endif; ?>

    </div><!-- .cs-body -->
            </div><!-- .main-card -->

            <div class="btn-group">
                <button type="button" class="back-main" onclick="window.history.back()">BACK TO PREVIOUS PAGE</button>
            </div>

            <footer class="footer-container">
                <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
            </footer>

        </div><!-- .content-area -->
    </div><!-- .main-wrapper -->


    <!-- ── Packaging Cost Modal ─────────────────────────────────────── -->
    <div class="ph-modal-overlay" id="ph-pkg-modal-overlay">
        <div class="ph-modal">
            <div class="ph-modal-title">📦 Edit Packaging Cost</div>
            <div class="ph-modal-sub" id="ph-pkg-modal-sub">Order —</div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:8px;">
                <div class="ph-modal-field">
                    <label>📦 Box</label>
                    <input type="text" class="ph-modal-input" id="ph-pkg-box" oninput="phPkgFormat(this);phPkgUpdateTotal()" onfocus="this.select()" inputmode="numeric">
                </div>
                <div class="ph-modal-field">
                    <label>🧣 Flanel</label>
                    <input type="text" class="ph-modal-input" id="ph-pkg-flanel" oninput="phPkgFormat(this);phPkgUpdateTotal()" onfocus="this.select()" inputmode="numeric">
                </div>
                <div class="ph-modal-field">
                    <label>💎 Faset</label>
                    <input type="text" class="ph-modal-input" id="ph-pkg-faset" oninput="phPkgFormat(this);phPkgUpdateTotal()" onfocus="this.select()" inputmode="numeric">
                </div>
                <div class="ph-modal-field">
                    <label>🎁 Wrapping</label>
                    <input type="text" class="ph-modal-input" id="ph-pkg-wrapping" oninput="phPkgFormat(this);phPkgUpdateTotal()" onfocus="this.select()" inputmode="numeric">
                </div>
                <div class="ph-modal-field">
                    <label>🧴 Lens Cleaner</label>
                    <input type="text" class="ph-modal-input" id="ph-pkg-cleaner" oninput="phPkgFormat(this);phPkgUpdateTotal()" onfocus="this.select()" inputmode="numeric">
                </div>
            </div>

            <div style="background:rgba(255,170,0,0.07);border:1px solid rgba(255,170,0,0.2);border-radius:12px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <span style="font-size:0.72rem;color:var(--text-muted);font-weight:700;letter-spacing:0.5px;">TOTAL PACKAGING</span>
                <span id="ph-pkg-total-preview" style="font-size:0.88rem;font-weight:900;color:#ffaa00;font-family:monospace;">Rp 0</span>
            </div>

            <div class="ph-modal-actions">
                <button class="ph-modal-btn cancel" onclick="phClosePkgModal()">Cancel</button>
                <button class="ph-modal-btn confirm" id="ph-pkg-confirm" onclick="phSubmitPkg()">Save</button>
            </div>
        </div>
    </div>

    <!-- ── Edit Frame Cost Modal (custom frames only) ──────────────────── -->
    <div class="ph-modal-overlay" id="ph-frame-modal-overlay">
        <div class="ph-modal">
            <div class="ph-modal-title">🖼 Edit Frame Cost</div>
            <div class="ph-modal-sub" id="ph-frame-modal-sub">Custom Frame —</div>

            <div class="ph-modal-field">
                <label>Buy Price (Modal Frame)</label>
                <input type="text" class="ph-modal-input" id="ph-frame-cost-input"
                       onfocus="this.select()"
                       oninput="phFrameCostFormat(this)"
                       inputmode="numeric" autocomplete="off">
                <div class="ph-modal-preview" id="ph-frame-cost-preview"></div>
            </div>

            <div class="ph-modal-actions">
                <button class="ph-modal-btn cancel" onclick="phCloseFrameModal()">Cancel</button>
                <button class="ph-modal-btn confirm" id="ph-frame-confirm" onclick="phSubmitFrameCost()">Save</button>
            </div>
        </div>
    </div>

    <!-- ── Edit Total Modal ───────────────────────────────────────── -->
    <div class="ph-modal-overlay" id="ph-modal-overlay">
        <div class="ph-modal">
            <div class="ph-modal-title">✏️ Edit Total Amount</div>
            <div class="ph-modal-sub" id="ph-modal-sub">Order — —</div>

            <div class="ph-modal-field">
                <label>New Amount (or expression e.g. 600000-50000)</label>
                <input type="text" class="ph-modal-input" id="ph-modal-amount"
                       placeholder="e.g. 550000 or 600000-50000"
                       autocomplete="off">
                <div class="ph-modal-preview" id="ph-modal-preview"></div>
            </div>

            <div class="ph-modal-field">
                <label>Your Password (verification)</label>
                <input type="password" class="ph-modal-input password-input" id="ph-modal-password"
                       placeholder="Enter your password" autocomplete="current-password">
            </div>

            <div class="ph-modal-actions">
                <button class="ph-modal-btn cancel" onclick="phCloseModal()">Cancel</button>
                <button class="ph-modal-btn confirm" id="ph-modal-confirm" onclick="phSubmitEdit()">Save Changes</button>
            </div>
        </div>
    </div>

    <div id="ph-toast"></div>

    <script>
    // ── Filter state ──────────────────────────────────────────────────
    var _phFilters = {
        search:  '',
        month:   '',
        gender:  '',
        faktur:  '',
        sort:    'date_desc'
    };

    // ── Toggle card expand/collapse ───────────────────────────────────
    function csToggleCard(headerEl) {
        headerEl.closest('.cs-card').classList.toggle('expanded');
    }

    // ── Apply all filters + sort ──────────────────────────────────────
    function phApplyFilters() {
        _phFilters.search  = (document.getElementById('ph-search').value || '').toLowerCase().trim();
        _phFilters.month   = document.getElementById('ph-filter-month').value;
        _phFilters.gender  = document.getElementById('ph-filter-gender').value;
        _phFilters.faktur  = document.getElementById('ph-filter-faktur').value;
        _phFilters.sort    = document.getElementById('ph-sort').value;

        var container = document.getElementById('ph-cards-container');
        if (!container) return;
        var cards = Array.prototype.slice.call(container.querySelectorAll('.cs-card'));

        // ── Filter ────────────────────────────────────────────────────
        var visible = [];
        cards.forEach(function(card) {
            var show = true;

            // Search: name, invoice, phone, custnum, frame, lens
            if (_phFilters.search) {
                var q = _phFilters.search;
                var haystack = [
                    card.getAttribute('data-name')    || '',
                    card.getAttribute('data-inv')      || '',
                    card.getAttribute('data-phone')    || '',
                    card.getAttribute('data-custnum')  || '',
                    card.getAttribute('data-frame')    || '',
                    card.getAttribute('data-lens')     || ''
                ].join(' ');
                if (haystack.indexOf(q) === -1) show = false;
            }

            // Month
            if (show && _phFilters.month) {
                if (card.getAttribute('data-month') !== _phFilters.month) show = false;
            }

            // Gender
            if (show && _phFilters.gender) {
                if (card.getAttribute('data-gender') !== _phFilters.gender) show = false;
            }

            // Faktur
            if (show && _phFilters.faktur) {
                if (card.getAttribute('data-faktur') !== _phFilters.faktur) show = false;
            }

            card.style.display = show ? '' : 'none';
            if (show) visible.push(card);
        });

        // ── Sort ──────────────────────────────────────────────────────
        visible.sort(function(a, b) {
            switch (_phFilters.sort) {
                case 'date_asc':
                    return new Date(a.getAttribute('data-orderdate-raw')||'').getTime()
                         - new Date(b.getAttribute('data-orderdate-raw')||'').getTime();
                case 'date_desc':
                    return new Date(b.getAttribute('data-orderdate-raw')||'').getTime()
                         - new Date(a.getAttribute('data-orderdate-raw')||'').getTime();
                case 'name_asc':
                    return (a.getAttribute('data-name')||'').localeCompare(b.getAttribute('data-name')||'');
                case 'name_desc':
                    return (b.getAttribute('data-name')||'').localeCompare(a.getAttribute('data-name')||'');
                case 'total_desc':
                    return parseInt(b.getAttribute('data-total')||0) - parseInt(a.getAttribute('data-total')||0);
                case 'total_asc':
                    return parseInt(a.getAttribute('data-total')||0) - parseInt(b.getAttribute('data-total')||0);
                default:
                    return 0;
            }
        });

        // Re-append in sorted order
        visible.forEach(function(card) { container.appendChild(card); });

        // ── Month dividers (only when sorted by date and no month filter) ──
        phRenderDividers(visible);

        // ── Update result count ───────────────────────────────────────
        var countEl = document.getElementById('ph-result-count');
        if (countEl) {
            countEl.innerHTML = 'Showing <span>' + visible.length + '</span> of <span>' + cards.length + '</span> orders';
        }

        // ── Active filter chips ───────────────────────────────────────
        phRenderChips();

        // ── Recalculate stat cards based on visible cards ─────────────
        var isFiltered = (_phFilters.search || _phFilters.month || _phFilters.gender || _phFilters.faktur);
        var thisMonthKey = (function() {
            var now = new Date();
            return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
        })();

        if (!isFiltered) {
            // No filter: restore original totals from PHP-rendered values
            var finishedEl = document.getElementById('ph-finished-display');
            var revenueEl  = document.getElementById('ph-revenue-display');
            var monthEl    = document.getElementById('ph-month-display');
            var profitEl   = document.getElementById('ph-profit-display');
            var costEl     = document.getElementById('ph-cost-display');
            if (finishedEl) finishedEl.textContent = _phOrigFinished;
            if (revenueEl)  { revenueEl.style.color = '#ffaa00'; revenueEl.textContent = 'Rp ' + _phOrigRevenue.toLocaleString('id-ID'); }
            if (monthEl)    monthEl.textContent = _phOrigMonth;
            if (profitEl)   { profitEl.style.color = _phOrigNetProfit >= 0 ? '#00ff88' : '#ff6b6b'; profitEl.textContent = (_phOrigNetProfit >= 0 ? '' : '-') + 'Rp ' + Math.abs(_phOrigNetProfit).toLocaleString('id-ID'); }
            if (costEl)     costEl.textContent = 'Rp ' + _phOrigCost.toLocaleString('id-ID');
        } else {
            // Filtered: sum only visible cards
            var filtTotal    = 0;
            var filtRevenue  = 0;
            var filtMonth    = 0;
            var filtProfit   = 0;
            var filtCost     = 0;
            visible.forEach(function(card) {
                filtTotal++;
                filtRevenue += parseInt(card.getAttribute('data-total') || 0);
                var cardMonth = card.getAttribute('data-month') || '';
                if (cardMonth === thisMonthKey) filtMonth++;
                filtProfit  += parseInt(card.getAttribute('data-net-profit') || 0);
                filtCost    += parseInt(card.getAttribute('data-cost') || 0);
            });
            var finishedEl = document.getElementById('ph-finished-display');
            var revenueEl  = document.getElementById('ph-revenue-display');
            var monthEl    = document.getElementById('ph-month-display');
            var profitEl   = document.getElementById('ph-profit-display');
            var costEl     = document.getElementById('ph-cost-display');
            if (finishedEl) finishedEl.textContent = filtTotal;
            if (revenueEl)  { revenueEl.style.color = '#ffaa00'; revenueEl.textContent = 'Rp ' + filtRevenue.toLocaleString('id-ID'); }
            if (monthEl)    monthEl.textContent = filtMonth;
            if (profitEl)   { profitEl.style.color = filtProfit >= 0 ? '#00ff88' : '#ff6b6b'; profitEl.textContent = (filtProfit >= 0 ? '' : '-') + 'Rp ' + Math.abs(filtProfit).toLocaleString('id-ID'); }
            if (costEl)     costEl.textContent = 'Rp ' + filtCost.toLocaleString('id-ID');
        }
    }

    // ── Month dividers ────────────────────────────────────────────────
    function phRenderDividers(visible) {
        // Remove existing dividers
        document.querySelectorAll('.ph-month-divider').forEach(function(d) { d.remove(); });

        var showDividers = (_phFilters.sort === 'date_desc' || _phFilters.sort === 'date_asc') && !_phFilters.month;
        if (!showDividers || visible.length === 0) return;

        var lastMonth = null;
        var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

        visible.forEach(function(card) {
            var raw = card.getAttribute('data-orderdate-raw') || '';
            if (!raw) return;
            var d  = new Date(raw);
            var mk = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
            var ml = monthNames[d.getMonth()] + ' ' + d.getFullYear();

            if (mk !== lastMonth) {
                lastMonth = mk;
                var divider = document.createElement('div');
                divider.className = 'ph-month-divider';
                divider.innerHTML = '<div class="ph-month-divider-line"></div>'
                                  + '<div class="ph-month-divider-label">' + ml + '</div>'
                                  + '<div class="ph-month-divider-line"></div>';
                card.parentNode.insertBefore(divider, card);
            }
        });
    }

    // ── Active filter chips ───────────────────────────────────────────
    function phRenderChips() {
        var container = document.getElementById('ph-active-filters');
        if (!container) return;
        container.innerHTML = '';

        var labels = {
            month:   { label: '📅 ' + (document.getElementById('ph-filter-month').options[document.getElementById('ph-filter-month').selectedIndex] || {}).text, clear: function() { document.getElementById('ph-filter-month').value = ''; phApplyFilters(); } },
            gender:  { label: '👤 ' + (document.getElementById('ph-filter-gender').options[document.getElementById('ph-filter-gender').selectedIndex] || {}).text, clear: function() { document.getElementById('ph-filter-gender').value = ''; phApplyFilters(); } },
            faktur:  { label: '📒 ' + (document.getElementById('ph-filter-faktur').options[document.getElementById('ph-filter-faktur').selectedIndex] || {}).text, clear: function() { document.getElementById('ph-filter-faktur').value = ''; phApplyFilters(); } },
        };

        if (_phFilters.search) {
            var chip = document.createElement('div');
            chip.className = 'ph-active-chip';
            chip.innerHTML = '🔍 "' + _phFilters.search + '" <button onclick="document.getElementById(\'ph-search\').value=\'\';phApplyFilters()">✕</button>';
            container.appendChild(chip);
        }

        ['month','gender','faktur'].forEach(function(key) {
            if (_phFilters[key]) {
                var chip = document.createElement('div');
                chip.className = 'ph-active-chip';
                var lbl = labels[key];
                chip.innerHTML = lbl.label + ' <button>✕</button>';
                chip.querySelector('button').addEventListener('click', lbl.clear);
                container.appendChild(chip);
            }
        });
    }

    // ── Reset all filters ─────────────────────────────────────────────
    function phResetFilters() {
        document.getElementById('ph-search').value          = '';
        document.getElementById('ph-filter-month').value   = '';
        document.getElementById('ph-filter-gender').value  = '';
        document.getElementById('ph-filter-faktur').value  = '';
        document.getElementById('ph-sort').value           = 'date_desc';
        phApplyFilters();
    }


    var _phTotalRevenue   = <?php echo (int)$totalRevenue; ?>;
    var _phTotalNetProfit = <?php echo (int)$totalNetProfit; ?>;
    var _phTotalCost      = <?php echo (int)$totalCost; ?>;

    // Original (unfiltered) values for stat card restoration
    var _phOrigFinished   = <?php echo (int)$totalOrders; ?>;
    var _phOrigRevenue    = <?php echo (int)$totalRevenue; ?>;
    var _phOrigMonth      = <?php echo (int)$thisMonthCount; ?>;
    var _phOrigNetProfit  = <?php echo (int)$totalNetProfit; ?>;
    var _phOrigCost       = <?php echo (int)$totalCost; ?>;

    // ── Edit Total Modal ──────────────────────────────────────────────
    var _phEditState = { orderId: null, currentTotal: 0, displayEl: null, headerEl: null };

    function phOpenEditModal(btnEl) {
        var card     = btnEl.closest('.cs-card');
        var totalEl  = card.querySelector('.ph-total-display');
        var headerAmt = card.querySelector('.ph-header-total');
        var orderId  = card.getAttribute('data-id');
        var current  = parseInt(card.getAttribute('data-total')) || 0;
        var name     = card.getAttribute('data-fullname') || '—';
        var inv      = card.getAttribute('data-inv') || '—';

        _phEditState = { orderId: orderId, currentTotal: current, displayEl: totalEl, headerEl: headerAmt, card: card };

        document.getElementById('ph-modal-sub').textContent  = name + '  |  INV: ' + inv.toUpperCase() + '  |  Current: Rp ' + current.toLocaleString('id-ID');
        document.getElementById('ph-modal-amount').value     = phFormatNumber(String(current));
        phUpdatePreview(phFormatNumber(String(current)));
        document.getElementById('ph-modal-password').value   = '';
        document.getElementById('ph-modal-preview').className   = 'ph-modal-preview';
        document.getElementById('ph-modal-overlay').classList.add('open');
        setTimeout(function() { var inp = document.getElementById('ph-modal-amount'); inp.focus(); inp.select(); }, 100);
    }

    function phCloseModal() {
        document.getElementById('ph-modal-overlay').classList.remove('open');
    }

    // Close on overlay click
    document.getElementById('ph-modal-overlay').addEventListener('click', function(e) {
        if (e.target === this) phCloseModal();
    });

    // ── Parse raw digits from a value (strip thousand separators) ──────
    function phStripFormat(val) {
        return val.replace(/\./g, '').replace(/[^0-9+\-*\/\s().]/g, '');
    }

    // ── Format plain number with dot thousand separators ─────────────
    function phFormatNumber(digits) {
        if (!digits) return '';
        return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    // ── Real-time input handler: format digits as typed ──────────────
    function phHandleAmountInput(e) {
        var inp  = e.target;
        var raw  = inp.value;

        // If expression (has operator other than leading minus) — skip formatting
        var isExpr = /[+*\/]/.test(raw) || /\d[\-]/.test(raw);
        if (isExpr) {
            phUpdatePreview(raw);
            return;
        }

        // Plain number mode: strip non-digits, reformat, restore cursor
        var cursorPos   = inp.selectionStart;
        var beforeCursor = raw.slice(0, cursorPos);
        var digitsBeforeCursor = beforeCursor.replace(/\./g, '').replace(/[^0-9]/g, '');
        var allDigits   = raw.replace(/\./g, '').replace(/[^0-9]/g, '');

        var formatted   = phFormatNumber(allDigits);
        inp.value       = formatted;

        // Restore cursor: count how many digits are before cursor, find new position
        var newPos = 0, digitCount = 0;
        for (var i = 0; i < formatted.length; i++) {
            if (digitCount >= digitsBeforeCursor.length) break;
            if (formatted[i] !== '.') digitCount++;
            newPos = i + 1;
        }
        inp.setSelectionRange(newPos, newPos);

        phUpdatePreview(formatted);
    }

    // ── Update preview line below input ──────────────────────────────
    function phUpdatePreview(raw) {
        var preview = document.getElementById('ph-modal-preview');
        if (!raw || !raw.trim()) { preview.textContent = ''; return; }

        var sanitized = phStripFormat(raw);
        if (!sanitized.trim()) { preview.textContent = ''; return; }

        var result;
        try {
            result = Function('"use strict"; return (' + sanitized + ')')();
        } catch(e) {
            preview.textContent = 'Invalid expression';
            preview.className   = 'ph-modal-preview error';
            return;
        }

        result = Math.round(result);
        if (isNaN(result) || result < 0) {
            preview.textContent = 'Invalid value';
            preview.className   = 'ph-modal-preview error';
        } else {
            var diff    = result - (_phEditState.currentTotal || 0);
            var diffStr = diff === 0 ? '' : (diff > 0
                ? '  (+Rp ' + diff.toLocaleString('id-ID') + ')'
                : '  (−Rp ' + Math.abs(diff).toLocaleString('id-ID') + ')');
            preview.textContent = '→ Rp ' + result.toLocaleString('id-ID') + diffStr;
            preview.className   = 'ph-modal-preview';
        }
    }

    // Bind input handler after DOM ready (see DOMContentLoaded below)
    function phBindAmountInput() {
        var inp = document.getElementById('ph-modal-amount');
        inp.addEventListener('input', phHandleAmountInput);
    }

    // ── Submit edit ───────────────────────────────────────────────────
    function phSubmitEdit() {
        var raw      = document.getElementById('ph-modal-amount').value.trim();
        var password = document.getElementById('ph-modal-password').value;
        var confirm  = document.getElementById('ph-modal-confirm');

        if (!raw || !password) {
            phShowToast('⚠ Fill in amount and password');
            return;
        }

        var sanitized = phStripFormat(raw);
        var newTotal;
        try {
            newTotal = Math.round(Function('"use strict"; return (' + sanitized + ')')());
        } catch(e) {
            phShowToast('⚠ Invalid amount expression');
            return;
        }

        if (isNaN(newTotal) || newTotal < 0) {
            phShowToast('⚠ Invalid amount');
            return;
        }

        confirm.disabled    = true;
        confirm.textContent = 'Saving…';

        var fd = new FormData();
        fd.append('action',    'update_total');
        fd.append('order_id',  _phEditState.orderId);
        fd.append('new_total', newTotal);
        fd.append('password',  password);

        fetch('purchase_history.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                confirm.disabled    = false;
                confirm.textContent = 'Save Changes';

                if (data.success) {
                    var oldTotal  = _phEditState.currentTotal;
                    var formatted = 'Rp ' + newTotal.toLocaleString('id-ID');
                    _phEditState.currentTotal = newTotal; // update state for next edit
                    if (_phEditState.displayEl) {
                        _phEditState.displayEl.textContent = formatted;
                        _phEditState.displayEl.setAttribute('data-raw', newTotal);
                    }
                    if (_phEditState.headerEl) {
                        _phEditState.headerEl.textContent = formatted;
                    }
                    _phEditState.card.setAttribute('data-total', newTotal);

                    // Update card header total
                    var headerTotal = _phEditState.card.querySelector('.ph-header-total');
                    if (headerTotal) headerTotal.textContent = formatted;

                    // Update Total Revenue stat card live
                    _phTotalRevenue = _phTotalRevenue - oldTotal + newTotal;
                    var revEl = document.getElementById('ph-revenue-display');
                    if (revEl) revEl.textContent = 'Rp ' + _phTotalRevenue.toLocaleString('id-ID');

                    phCloseModal();
                    phShowToast('✅ Total updated');
                } else {
                    phShowToast('❌ ' + (data.error || 'Failed'));
                }
            })
            .catch(function() {
                confirm.disabled    = false;
                confirm.textContent = 'Save Changes';
                phShowToast('❌ Connection error');
            });
    }

    // Enter key submits
    document.getElementById('ph-modal-password').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') phSubmitEdit();
    });

    // ── Packaging Modal ──────────────────────────────────────────────
    var _phPkgCard = null;

    // Return packaging breakdown defaults based on total_amount tier
    function phPkgTierDefaults(totalAmount) {
        if (totalAmount >= 500000) {
            return { box: 10000, flanel: 500, faset: 10000, wrapping: 3000, cleaner: 3000 }; // 26,500
        } else if (totalAmount >= 90000) {
            return { box: 3000,  flanel: 500, faset: 10000, wrapping: 3000, cleaner: 3000 }; // 19,500
        } else {
            return { box: 3000,  flanel: 500, faset: 10000, wrapping: 1000, cleaner: 0    }; // 14,500
        }
    }

    function phOpenPkgModal(btnEl) {
        _phPkgCard = btnEl.closest('.cs-card');
        var name     = _phPkgCard.getAttribute('data-fullname') || '—';
        var inv      = _phPkgCard.getAttribute('data-inv') || '—';
        var pkgTotal = parseInt(_phPkgCard.getAttribute('data-pkg-total')) || 16500;

        document.getElementById('ph-pkg-modal-sub').textContent = name + '  |  INV: ' + inv.toUpperCase() + '  |  Current: Rp ' + pkgTotal.toLocaleString('id-ID');

        // Pre-fill with tier defaults based on total_amount
        var totalAmt  = parseInt(_phPkgCard.getAttribute('data-total-amount')) || 0;
        var tierDefs  = phPkgTierDefaults(totalAmt);
        ['box','flanel','faset','wrapping','cleaner'].forEach(function(key) {
            document.getElementById('ph-pkg-' + key).value = phFormatNumber(String(tierDefs[key]));
        });

        phPkgUpdateTotal();
        document.getElementById('ph-pkg-modal-overlay').classList.add('open');
        setTimeout(function() { document.getElementById('ph-pkg-box').focus(); document.getElementById('ph-pkg-box').select(); }, 100);
    }

    function phClosePkgModal() {
        document.getElementById('ph-pkg-modal-overlay').classList.remove('open');
    }

    document.getElementById('ph-pkg-modal-overlay').addEventListener('click', function(e) {
        if (e.target === this) phClosePkgModal();
    });

    // Format a packaging input (digits only, dot thousand sep)
    function phPkgFormat(inp) {
        var pos    = inp.selectionStart;
        var before = inp.value.length;
        var beforeCursorDigits = inp.value.slice(0, pos).replace(/\./g, '').replace(/[^0-9]/g, '');
        var allDigits = inp.value.replace(/\./g, '').replace(/[^0-9]/g, '');
        var formatted = phFormatNumber(allDigits);
        inp.value = formatted;
        // Restore cursor
        var newPos = 0, digitCount = 0;
        for (var i = 0; i < formatted.length; i++) {
            if (digitCount >= beforeCursorDigits.length) break;
            if (formatted[i] !== '.') digitCount++;
            newPos = i + 1;
        }
        inp.setSelectionRange(newPos, newPos);
    }

    function phPkgRawVal(id) {
        return parseInt(document.getElementById(id).value.replace(/\./g, '')) || 0;
    }

    function phPkgUpdateTotal() {
        var total = phPkgRawVal('ph-pkg-box') + phPkgRawVal('ph-pkg-flanel')
                  + phPkgRawVal('ph-pkg-faset') + phPkgRawVal('ph-pkg-wrapping')
                  + phPkgRawVal('ph-pkg-cleaner');
        document.getElementById('ph-pkg-total-preview').textContent = 'Rp ' + total.toLocaleString('id-ID');
    }

    function phSubmitPkg() {
        if (!_phPkgCard) return;
        var btn     = document.getElementById('ph-pkg-confirm');
        var orderId = _phPkgCard.getAttribute('data-id');
        var box     = phPkgRawVal('ph-pkg-box');
        var flanel  = phPkgRawVal('ph-pkg-flanel');
        var faset   = phPkgRawVal('ph-pkg-faset');
        var wrapping= phPkgRawVal('ph-pkg-wrapping');
        var cleaner = phPkgRawVal('ph-pkg-cleaner');
        var total   = box + flanel + faset + wrapping + cleaner;

        btn.disabled    = true;
        btn.textContent = 'Saving…';

        var fd = new FormData();
        fd.append('action',   'update_packaging');
        fd.append('order_id', orderId);
        fd.append('box',      box);
        fd.append('flanel',   flanel);
        fd.append('faset',    faset);
        fd.append('wrapping', wrapping);
        fd.append('cleaner', cleaner);

        fetch('purchase_history.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled    = false;
                btn.textContent = 'Save';
                if (data.success) {
                    // Update card data attribute + displayed total
                    _phPkgCard.setAttribute('data-pkg-total', data.packaging_cost);
                    var totEl = _phPkgCard.querySelector('.ph-pkg-total');
                    if (totEl) totEl.textContent = 'Rp ' + data.packaging_cost.toLocaleString('id-ID');

                    // Recalculate net profit
                    phUpdateNetProfit(_phPkgCard);

                    phClosePkgModal();
                    phShowToast('✅ Packaging updated');
                } else {
                    phShowToast('❌ ' + (data.error || 'Failed'));
                }
            })
            .catch(function() {
                btn.disabled    = false;
                btn.textContent = 'Save';
                phShowToast('❌ Connection error');
            });
    }

    // ── Frame Cost Modal ─────────────────────────────────────────────
    var _phFrameCard = null;

    function phOpenFrameCostModal(btnEl) {
        _phFrameCard = btnEl.closest('.cs-card');
        var name     = _phFrameCard.getAttribute('data-fullname') || '—';
        var inv      = _phFrameCard.getAttribute('data-invoice') || '—';
        var current  = parseInt(_phFrameCard.getAttribute('data-frame-cost')) || 0;

        document.getElementById('ph-frame-modal-sub').textContent = name + '  |  INV: ' + inv.toUpperCase() + '  |  Current: Rp ' + current.toLocaleString('id-ID');
        document.getElementById('ph-frame-cost-input').value      = phFormatNumber(String(current));
        document.getElementById('ph-frame-cost-preview').textContent = '';
        document.getElementById('ph-frame-modal-overlay').classList.add('open');
        setTimeout(function() { var inp = document.getElementById('ph-frame-cost-input'); inp.focus(); inp.select(); }, 100);
    }

    function phCloseFrameModal() {
        document.getElementById('ph-frame-modal-overlay').classList.remove('open');
    }

    document.getElementById('ph-frame-modal-overlay').addEventListener('click', function(e) {
        if (e.target === this) phCloseFrameModal();
    });

    function phFrameCostFormat(inp) {
        var pos    = inp.selectionStart;
        var beforeCursorDigits = inp.value.slice(0, pos).replace(/\./g, '').replace(/[^0-9]/g, '');
        var allDigits = inp.value.replace(/\./g, '').replace(/[^0-9]/g, '');
        var formatted = phFormatNumber(allDigits);
        inp.value = formatted;
        var newPos = 0, digitCount = 0;
        for (var i = 0; i < formatted.length; i++) {
            if (digitCount >= beforeCursorDigits.length) break;
            if (formatted[i] !== '.') digitCount++;
            newPos = i + 1;
        }
        inp.setSelectionRange(newPos, newPos);
        // Update preview
        var val = parseInt(allDigits) || 0;
        document.getElementById('ph-frame-cost-preview').textContent = val > 0 ? '→ Rp ' + val.toLocaleString('id-ID') : '';
    }

    function phSubmitFrameCost() {
        if (!_phFrameCard) return;
        var btn      = document.getElementById('ph-frame-confirm');
        var invoice  = _phFrameCard.getAttribute('data-invoice') || '';
        var rawVal   = document.getElementById('ph-frame-cost-input').value.replace(/\./g, '');
        var buyPrice = parseInt(rawVal) || 0;

        btn.disabled    = true;
        btn.textContent = 'Saving…';

        var fd = new FormData();
        fd.append('action',    'update_frame_cost');
        fd.append('invoice',   invoice);
        fd.append('buy_price', buyPrice);

        fetch('purchase_history.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled    = false;
                btn.textContent = 'Save';
                if (data.success) {
                    // Update card display + data attr
                    _phFrameCard.setAttribute('data-frame-cost', buyPrice);
                    var dispEl = _phFrameCard.querySelector('.ph-frame-cost-display');
                    if (dispEl) dispEl.innerHTML = 'Rp ' + buyPrice.toLocaleString('id-ID');

                    // Recalculate net profit display
                    phUpdateNetProfit(_phFrameCard);

                    phCloseFrameModal();
                    phShowToast('✅ Frame cost updated');
                } else {
                    phShowToast('❌ ' + (data.error || 'Failed'));
                }
            })
            .catch(function() {
                btn.disabled    = false;
                btn.textContent = 'Save';
                phShowToast('❌ Connection error');
            });
    }

    // ── Recalculate net profit on card after any cost edit ────────────
    function phUpdateNetProfit(card) {
        var total    = parseInt(card.getAttribute('data-total'))      || 0;
        var lensCost = parseInt(card.getAttribute('data-lens-cost'))   || 0;
        var frameCost= parseInt(card.getAttribute('data-frame-cost'))  || 0;
        var pkgCost  = parseInt(card.getAttribute('data-pkg-total'))   || 0;
        var profit   = total - lensCost - frameCost - pkgCost;
        var pct      = total > 0 ? Math.round(profit / total * 100) : 0;
        var el       = card.querySelector('.ph-net-profit');
        if (!el) return;
        el.style.color = profit >= 0 ? '#00ff88' : '#ff6b6b';
        var sign = profit >= 0 ? '' : '−';
        // Update total net profit stat card
        var oldProfit = parseInt(card.getAttribute('data-net-profit') || '0');
        var oldCost   = parseInt(card.getAttribute('data-cost') || '0');
        var newCost   = lensCost + frameCost + pkgCost;
        card.setAttribute('data-net-profit', profit);
        card.setAttribute('data-cost', newCost);
        _phTotalNetProfit += (profit - oldProfit);
        _phTotalCost      += (newCost - oldCost);
        var profEl = document.getElementById('ph-profit-display');
        if (profEl) {
            profEl.style.color = _phTotalNetProfit >= 0 ? '#00ff88' : '#ff6b6b';
            profEl.textContent = (_phTotalNetProfit >= 0 ? '' : '-') + 'Rp ' + Math.abs(_phTotalNetProfit).toLocaleString('id-ID');
        }
        var costEl = document.getElementById('ph-cost-display');
        if (costEl) costEl.textContent = 'Rp ' + _phTotalCost.toLocaleString('id-ID');

        el.innerHTML = sign + 'Rp ' + Math.abs(profit).toLocaleString('id-ID')
            + ' <span style="font-size:0.65rem;font-weight:600;color:var(--text-muted);margin-left:6px;">(' + pct + '%)</span>';
    }

    // ── Toast ─────────────────────────────────────────────────────────
    var _phToastTimer = null;
    function phShowToast(msg) {
        var el = document.getElementById('ph-toast');
        el.textContent = msg;
        el.classList.add('show');
        clearTimeout(_phToastTimer);
        _phToastTimer = setTimeout(function() { el.classList.remove('show'); }, 2800);
    }

    // ── Init on load ──────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function() { phApplyFilters(); phBindAmountInput(); });
    </script>
</body>
</html>
