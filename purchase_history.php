<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

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
            co.amount_paid,
            co.order_date,
            co.due_date,
            co.order_status,
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

    // ── Summary stats ─────────────────────────────────────────────────
    $totalOrders   = count($orders);
    $totalRevenue  = array_sum(array_column($orders, 'total_amount'));
    $totalPaid     = array_sum(array_column($orders, 'amount_paid'));
    $totalUnpaid   = $totalRevenue - $totalPaid;

    // Count this month
    $thisMonth     = date('Y-m');
    $thisMonthCount = 0;
    foreach ($orders as $o) {
        if (!empty($o['order_date']) && date('Y-m', strtotime($o['order_date'])) === $thisMonth) {
            $thisMonthCount++;
        }
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
                <div class="cs-stat-num" style="color:#00ff88;"><?php echo $totalOrders; ?></div>
                <div class="cs-stat-label">🏁 Total Finished</div>
            </div>
            <div class="cs-stat-card">
                <div class="cs-stat-num" style="color:#ffaa00;font-size:1.1rem;">Rp <?php echo number_format($totalRevenue, 0, ',', '.'); ?></div>
                <div class="cs-stat-label">💰 Total Revenue</div>
            </div>
            <div class="cs-stat-card">
                <div class="cs-stat-num" style="color:#00cfff;"><?php echo $thisMonthCount; ?></div>
                <div class="cs-stat-label">📅 This Month</div>
            </div>
            <?php if ($totalUnpaid > 0): ?>
            <div class="cs-stat-card">
                <div class="cs-stat-num" style="color:#ff6b6b;font-size:1.1rem;">Rp <?php echo number_format($totalUnpaid, 0, ',', '.'); ?></div>
                <div class="cs-stat-label">⚠ Unpaid Balance</div>
            </div>
            <?php endif; ?>
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

            <!-- Payment filter -->
            <div class="ph-filter-group">
                <div class="ph-filter-label">💳 Payment</div>
                <select class="ph-select" id="ph-filter-payment" onchange="phApplyFilters()">
                    <option value="">All</option>
                    <option value="paid">Fully Paid ✓</option>
                    <option value="unpaid">Has Balance ⚠</option>
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
        <?php foreach ($orders as $o):
            $name      = trim($o['patient_name'] ?? '—');
            $age       = (int)($o['age'] ?? 0);
            $gender    = strtolower(trim($o['gender'] ?? ''));
            $genderIcon = ($gender === 'male' || $gender === 'laki-laki' || $gender === 'm') ? '👨' : '👩';
            $phone     = $o['customer_phone'] ?? '';
            $lensName  = $o['lens_name'] ?? '—';
            $frameUfc  = $o['frame_ufc'] ?? '—';
            $totalAmt  = (int)$o['total_amount'];
            $paidAmt   = (int)$o['amount_paid'];
            $remaining = $totalAmt - $paidAmt;
            $orderDate = $o['order_date'] ? date('d/m/Y', strtotime($o['order_date'])) : '—';
            $orderMonth = $o['order_date'] ? date('Y-m', strtotime($o['order_date'])) : '';
            $dueDate   = $o['due_date']   ? date('d/m/Y', strtotime($o['due_date']))   : '—';
            $genderNorm = ($gender === 'male' || $gender === 'laki-laki' || $gender === 'm') ? 'male' : 'female';
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
             data-payment="<?php echo ($remaining <= 0 ? 'paid' : 'unpaid'); ?>"
             data-total="<?php echo $totalAmt; ?>"
             data-fullname="<?php echo htmlspecialchars($name); ?>"
             data-orderdate-raw="<?php echo htmlspecialchars($o['order_date'] ?? ''); ?>">

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
                        <div style="font-size:0.72rem;font-weight:800;color:#ffaa00;font-family:monospace;">
                            Rp <?php echo number_format($totalAmt, 0, ',', '.'); ?>
                        </div>
                        <div style="font-size:0.62rem;color:<?php echo $remaining <= 0 ? '#00ff88' : '#ff6b6b'; ?>;font-weight:700;margin-top:2px;">
                            <?php echo $remaining <= 0 ? 'PAID ✓' : 'Balance: Rp ' . number_format($remaining, 0, ',', '.'); ?>
                        </div>
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
                        <span class="cs-detail-label">Total</span>
                        <span class="cs-detail-value price">Rp <?php echo number_format($totalAmt, 0, ',', '.'); ?></span>
                    </div>
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Amount Paid</span>
                        <span class="cs-detail-value price">Rp <?php echo number_format($paidAmt, 0, ',', '.'); ?></span>
                    </div>
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Remaining Balance</span>
                        <span class="cs-detail-value <?php echo $remaining <= 0 ? 'paid' : 'price'; ?>"
                              style="<?php echo $remaining > 0 ? 'color:#ff6b6b' : ''; ?>">
                            <?php echo $remaining <= 0 ? 'PAID ✓' : 'Rp ' . number_format($remaining, 0, ',', '.'); ?>
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
        </div><!-- .content-area -->
    </div><!-- .main-wrapper -->

    <div id="ph-toast"></div>

    <script>
    // ── Filter state ──────────────────────────────────────────────────
    var _phFilters = {
        search:  '',
        month:   '',
        gender:  '',
        payment: '',
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
        _phFilters.payment = document.getElementById('ph-filter-payment').value;
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

            // Payment
            if (show && _phFilters.payment) {
                if (card.getAttribute('data-payment') !== _phFilters.payment) show = false;
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
            payment: { label: '💳 ' + (document.getElementById('ph-filter-payment').options[document.getElementById('ph-filter-payment').selectedIndex] || {}).text, clear: function() { document.getElementById('ph-filter-payment').value = ''; phApplyFilters(); } }
        };

        if (_phFilters.search) {
            var chip = document.createElement('div');
            chip.className = 'ph-active-chip';
            chip.innerHTML = '🔍 "' + _phFilters.search + '" <button onclick="document.getElementById(\'ph-search\').value=\'\';phApplyFilters()">✕</button>';
            container.appendChild(chip);
        }

        ['month','gender','payment'].forEach(function(key) {
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
        document.getElementById('ph-filter-payment').value = '';
        document.getElementById('ph-sort').value           = 'date_desc';
        phApplyFilters();
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
    document.addEventListener('DOMContentLoaded', function() { phApplyFilters(); });
    </script>
</body>
</html>
