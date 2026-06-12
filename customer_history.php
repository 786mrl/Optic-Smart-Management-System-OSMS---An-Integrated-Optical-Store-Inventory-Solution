<?php
// customer_history.php
session_start();
include 'db_config.php';
include 'config_helper.php';
include 'auth_check.php';

// ── Search / resolve customer identity ──────────────────────────────────────
$search_input = trim($_GET['q'] ?? '');
$customer_data = null;
$examinations  = [];
$orders        = [];
$error_msg     = '';

if ($search_input !== '') {
    $candidates = [];

    // Search in examinations (by name or invoice)
    $stmt = $conn->prepare("
        SELECT DISTINCT invoice_number, customer_name
        FROM customer_examinations
        WHERE invoice_number = ? OR customer_name LIKE ?
        LIMIT 5
    ");
    $like = '%' . $search_input . '%';
    $stmt->bind_param('ss', $search_input, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $candidates[$row['invoice_number']] = $row['customer_name'];
    }
    $stmt->close();

    // Search in orders (by phone or invoice)
    $stmt2 = $conn->prepare("
        SELECT DISTINCT invoice_number, customer_phone
        FROM customer_orders
        WHERE invoice_number = ? OR customer_phone LIKE ?
        LIMIT 5
    ");
    $stmt2->bind_param('ss', $search_input, $like);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $order_phones = [];
    while ($row = $res2->fetch_assoc()) {
        if (!isset($candidates[$row['invoice_number']])) $candidates[$row['invoice_number']] = null;
        $order_phones[$row['invoice_number']] = $row['customer_phone'];
    }
    $res2->free(); $stmt2->close();

    if (empty($candidates)) {
        $error_msg = 'Data tidak ditemukan untuk: <strong>' . htmlspecialchars($search_input) . '</strong>';
    } else {
        $pivot_invoice = array_key_first($candidates);

        $stmt3 = $conn->prepare("SELECT customer_name FROM customer_examinations WHERE invoice_number = ? LIMIT 1");
        $stmt3->bind_param('s', $pivot_invoice);
        $stmt3->execute();
        $r3 = $stmt3->get_result()->fetch_assoc();
        $stmt3->close();
        $canon_name = $r3['customer_name'] ?? null;

        $stmt4 = $conn->prepare("SELECT customer_phone FROM customer_orders WHERE invoice_number = ? LIMIT 1");
        $stmt4->bind_param('s', $pivot_invoice);
        $stmt4->execute();
        $r4 = $stmt4->get_result()->fetch_assoc();
        $stmt4->close();
        $canon_phone = $r4['customer_phone'] ?? ($order_phones[$pivot_invoice] ?? null);

        // Fetch all examinations by name
        if ($canon_name) {
            $stmt5 = $conn->prepare("SELECT * FROM customer_examinations WHERE customer_name = ? ORDER BY examination_date ASC");
            $stmt5->bind_param('s', $canon_name);
            $stmt5->execute();
            $examinations = $stmt5->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt5->close();
        }

        // Fetch all orders by phone, fallback to invoice list
        if ($canon_phone && $canon_phone !== '') {
            $stmt6 = $conn->prepare("SELECT * FROM customer_orders WHERE customer_phone = ? ORDER BY order_date ASC");
            $stmt6->bind_param('s', $canon_phone);
            $stmt6->execute();
            $orders = $stmt6->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt6->close();
        } elseif (!empty($examinations)) {
            $inv_list = array_filter(array_column($examinations, 'invoice_number'), fn($v) => $v && $v !== '00');
            if (!empty($inv_list)) {
                $placeholders = implode(',', array_fill(0, count($inv_list), '?'));
                $types = str_repeat('s', count($inv_list));
                $stmt6b = $conn->prepare("SELECT * FROM customer_orders WHERE invoice_number IN ($placeholders) ORDER BY order_date ASC");
                $stmt6b->bind_param($types, ...$inv_list);
                $stmt6b->execute();
                $orders = $stmt6b->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt6b->close();
            }
        }

        $customer_data = [
            'name'          => $canon_name  ?? '—',
            'phone'         => $canon_phone ?? '—',
            'pivot_invoice' => $pivot_invoice,
        ];
    }
}

// ── Analytics helpers ────────────────────────────────────────────────────────
function fmt_idr($v) { return 'Rp ' . number_format((float)$v, 0, ',', '.'); }
function fmt_rx($v) {
    if ($v === null || $v === '' || $v === '0') return '—';
    $f = (float)$v;
    return $f > 0 ? '+' . $v : $v;
}
function rx_float($v) { return ($v === null || $v === '') ? null : (float)$v; }
function visit_gap_label($days) {
    if ($days < 30)  return $days . ' hari';
    if ($days < 365) return round($days/30) . ' bln';
    return round($days/365,1) . ' thn';
}
function order_status_label($s) {
    return [1=>'Proses',2=>'Selesai',3=>'Diambil',4=>'Batal'][$s] ?? '?';
}
function order_status_color($s) {
    return [1=>'#f59e0b',2=>'#00ffaa',3=>'#00d4ff',4=>'#ff4d4d'][$s] ?? '#718096';
}

// Pre-compute analytics
$total_spent = $total_paid = $unpaid_amount = 0;
$paid_orders = $unpaid_orders = $partial_orders = 0;
$exam_count  = count($examinations);
$order_count = count($orders);

foreach ($orders as $o) {
    $diff = (float)$o['total_amount'] - (float)$o['amount_paid'];
    $total_spent += (float)$o['total_amount'];
    $total_paid  += (float)$o['amount_paid'];
    if ($diff <= 0)                          $paid_orders++;
    elseif ((float)$o['amount_paid'] > 0) { $partial_orders++; $unpaid_amount += $diff; }
    else                                  { $unpaid_orders++;   $unpaid_amount += $diff; }
}

$exam_dates = array_column($examinations, 'examination_date');
sort($exam_dates);
$visit_gaps = [];
for ($i = 1; $i < count($exam_dates); $i++) {
    $visit_gaps[] = (int)round((strtotime($exam_dates[$i]) - strtotime($exam_dates[$i-1])) / 86400);
}
$avg_gap_days = count($visit_gaps) ? (int)round(array_sum($visit_gaps) / count($visit_gaps)) : null;

$rx_trend = [];
foreach ($examinations as $e) {
    $rx_trend[] = [
        'date'  => $e['examination_date'],
        'r_sph' => rx_float($e['new_r_sph']),
        'r_cyl' => rx_float($e['new_r_cyl']),
        'l_sph' => rx_float($e['new_l_sph']),
        'l_cyl' => rx_float($e['new_l_cyl']),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer History — <?php echo htmlspecialchars($STORE_NAME ?? 'Lenza Optic'); ?></title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* ── Override content-area to single-column (same as lense_price) ── */
        .content-area { flex-direction: column !important; }
        .header-container { margin: 0 auto; width: 100%; max-width: 100%; }

        /* ── config-window wrapper (same as lense_price) ──────────────── */
        .config-window { width: 100%; }

        /* ── Search bar ──────────────────────────────────────────────── */
        .ch-search-bar {
            background: var(--bg-color);
            border-radius: 20px;
            padding: 20px 24px;
            margin-bottom: 24px;
            box-shadow: 10px 10px 25px var(--shadow-dark), -10px -10px 25px var(--shadow-light);
        }
        .ch-search-label {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1px; color: var(--text-muted); margin-bottom: 12px;
            display: block; margin-left: 0;
        }
        .ch-search-row { display: flex; gap: 12px; align-items: center; }
        .ch-search-input {
            flex: 1; background: var(--bg-color); border: none; outline: none;
            border-radius: 15px; color: var(--text-main); font-size: 14px;
            padding: 14px 18px;
            box-shadow: inset 6px 6px 12px var(--shadow-dark), inset -6px -6px 12px var(--shadow-light);
            transition: 0.3s;
        }
        .ch-search-input:focus { color: var(--accent-solid); }
        .ch-search-input::placeholder { color: #404448; }
        .ch-search-btn {
            padding: 14px 28px; border: none; border-radius: 15px;
            background: var(--bg-color); color: var(--accent-solid);
            font-size: 13px; font-weight: 700; cursor: pointer;
            box-shadow: 6px 6px 12px var(--shadow-dark), -6px -6px 12px var(--shadow-light);
            transition: all 0.2s;
        }
        .ch-search-btn:hover  { color: #fff; }
        .ch-search-btn:active { box-shadow: inset 4px 4px 8px var(--shadow-dark), inset -4px -4px 8px var(--shadow-light); }

        /* ── State boxes ─────────────────────────────────────────────── */
        .ch-state {
            text-align: center; padding: 50px 20px;
            background: var(--bg-color); border-radius: 20px;
            box-shadow: 10px 10px 25px var(--shadow-dark), -10px -10px 25px var(--shadow-light);
        }
        .ch-state-icon { font-size: 42px; margin-bottom: 14px; }
        .ch-state p { color: var(--text-muted); font-size: 13px; margin: 0; }
        .ch-state.error p { color: #ff4d4d; }

        /* ── Identity card ───────────────────────────────────────────── */
        .ch-identity {
            background: var(--bg-color); border-radius: 20px; margin-bottom: 20px;
            padding: 20px 24px; display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
            box-shadow: 10px 10px 25px var(--shadow-dark), -10px -10px 25px var(--shadow-light);
        }
        .ch-avatar {
            width: 52px; height: 52px; border-radius: 50%;
            background: linear-gradient(135deg, var(--accent-solid), #0055ff);
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; font-weight: 800; color: #fff; flex-shrink: 0;
            text-transform: uppercase;
            box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
        }
        .ch-id-info { flex: 1; min-width: 140px; }
        .ch-id-name { font-size: 17px; font-weight: 700; color: var(--text-main); margin-bottom: 4px; }
        .ch-id-sub  { font-size: 12px; color: var(--text-muted); }
        .ch-id-sub span { color: var(--text-color); }
        .ch-id-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-left: auto; }
        .ch-badge {
            display: inline-flex; align-items: center; gap: 5px;
            border-radius: 10px; padding: 5px 13px; font-size: 11px; font-weight: 700;
            box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 6px var(--shadow-light);
        }
        .ch-badge.exam   { color: var(--accent-solid); }
        .ch-badge.order  { color: var(--success); }
        .ch-badge.debt   { color: var(--danger); }

        /* ── KPI grid ────────────────────────────────────────────────── */
        .ch-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 14px; margin-bottom: 20px;
        }
        .ch-kpi {
            background: var(--bg-color); border-radius: 18px; padding: 16px 18px;
            box-shadow: 8px 8px 16px var(--shadow-dark), -8px -8px 16px var(--shadow-light);
            position: relative; overflow: hidden;
        }
        .ch-kpi::after {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            border-radius: 18px 18px 0 0;
        }
        .ch-kpi.c-teal::after   { background: var(--accent-solid); }
        .ch-kpi.c-green::after  { background: var(--success); }
        .ch-kpi.c-red::after    { background: var(--danger); }
        .ch-kpi.c-blue::after   { background: #3b82f6; }
        .ch-kpi.c-amber::after  { background: var(--warning); }
        .ch-kpi.c-purple::after { background: #a855f7; }
        .ch-kpi-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: var(--text-muted); margin-bottom: 8px; }
        .ch-kpi-val   { font-size: 22px; font-weight: 800; color: var(--text-main); line-height: 1; }
        .ch-kpi-val.sm { font-size: 14px; }
        .ch-kpi-sub   { font-size: 11px; color: var(--text-muted); margin-top: 5px; }

        /* ── Section header ──────────────────────────────────────────── */
        .ch-section {
            display: flex; align-items: center; justify-content: space-between;
            margin: 28px 0 14px;
        }
        .ch-section-title {
            font-size: 12px; font-weight: 700; letter-spacing: 1px;
            text-transform: uppercase; color: var(--text-color);
            display: flex; align-items: center; gap: 8px;
        }
        .ch-section-title::before {
            content: ''; width: 3px; height: 16px;
            background: var(--accent-solid); border-radius: 2px; display: inline-block;
        }
        .ch-section-count {
            font-size: 11px; color: var(--text-muted);
            background: var(--bg-color); border-radius: 20px; padding: 3px 12px;
            box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 6px var(--shadow-light);
        }

        /* ── Chart cards ─────────────────────────────────────────────── */
        .ch-chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 4px; }
        @media(max-width:680px) { .ch-chart-grid { grid-template-columns: 1fr; } }
        .ch-chart-card {
            background: var(--bg-color); border-radius: 18px; padding: 18px 20px;
            box-shadow: 8px 8px 16px var(--shadow-dark), -8px -8px 16px var(--shadow-light);
        }
        .ch-chart-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--text-muted); margin-bottom: 14px; }
        .ch-chart-wrap  { position: relative; height: 180px; }

        /* ── Analysis cards ──────────────────────────────────────────── */
        .ch-analysis-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; margin-bottom: 4px; }
        .ch-analysis-card {
            background: var(--bg-color); border-radius: 18px; padding: 18px 20px;
            box-shadow: 8px 8px 16px var(--shadow-dark), -8px -8px 16px var(--shadow-light);
        }
        .ch-analysis-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--text-muted); margin-bottom: 12px; }
        .ch-analysis-row   { display: flex; justify-content: space-between; align-items: center; padding: 7px 0; border-bottom: 1px solid rgba(255,255,255,0.03); }
        .ch-analysis-row:last-child { border-bottom: none; }
        .ch-a-key { font-size: 12px; color: var(--text-muted); }
        .ch-a-val { font-size: 12px; font-weight: 700; color: var(--text-main); }
        .ch-a-val.good  { color: var(--success); }
        .ch-a-val.warn  { color: var(--warning); }
        .ch-a-val.bad   { color: var(--danger); }
        .ch-a-val.teal  { color: var(--accent-solid); }

        /* Rx change table */
        .ch-rx-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
        .ch-rx-tbl th { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: var(--text-muted); padding: 5px 6px; text-align: center; }
        .ch-rx-tbl td { padding: 6px; text-align: center; color: var(--text-color); border-bottom: 1px solid rgba(255,255,255,0.03); }
        .ch-rx-tbl td:first-child { text-align: left; }
        .ch-rx-tbl tr:last-child td { border-bottom: none; }

        /* ── Timeline ────────────────────────────────────────────────── */
        .ch-timeline { position: relative; padding-left: 18px; }
        .ch-timeline::before { content: ''; position: absolute; left: 5px; top: 0; bottom: 0; width: 2px; background: rgba(255,255,255,0.05); border-radius: 2px; }
        .ch-tl-item { position: relative; margin-bottom: 12px; }
        .ch-tl-dot  { position: absolute; left: -15px; top: 7px; width: 8px; height: 8px; border-radius: 50%; border: 2px solid var(--bg-color); }
        .ch-tl-box  {
            background: var(--card-bg); border-radius: 12px; padding: 9px 14px;
            box-shadow: 4px 4px 8px var(--shadow-dark), -4px -4px 8px var(--shadow-light);
        }
        .ch-tl-date { font-size: 10px; color: var(--text-muted); margin-bottom: 1px; }
        .ch-tl-text { font-size: 12px; color: var(--text-main); font-weight: 600; }
        .ch-tl-sub  { font-size: 11px; color: var(--text-muted); margin-top: 1px; }

        /* ── Accordion cards (exam / order) ──────────────────────────── */
        .ch-acc {
            background: var(--bg-color); border-radius: 20px; margin-bottom: 12px;
            box-shadow: 8px 8px 16px var(--shadow-dark), -8px -8px 16px var(--shadow-light);
            overflow: hidden;
        }
        .ch-acc-header {
            display: flex; align-items: center; gap: 10px; padding: 14px 18px;
            cursor: pointer; user-select: none; transition: background 0.2s; flex-wrap: wrap;
        }
        .ch-acc-header:hover { background: rgba(255,255,255,0.02); }
        .ch-acc-arrow { margin-left: auto; font-size: 10px; color: var(--text-muted); transition: transform .2s; flex-shrink: 0; }
        .ch-acc.open .ch-acc-arrow { transform: rotate(180deg); color: var(--accent-solid); }
        .ch-acc-body { display: none; border-top: 1px solid rgba(255,255,255,0.04); padding: 18px; }
        .ch-acc.open .ch-acc-body { display: block; animation: chSlide .2s ease-out; }
        @keyframes chSlide { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }

        /* date badge */
        .ch-date-badge {
            background: var(--card-bg); border-radius: 10px; padding: 4px 12px;
            font-size: 11px; font-weight: 700; color: var(--text-color); flex-shrink: 0;
            box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 6px var(--shadow-light);
        }
        .ch-code { font-size: 11px; color: var(--text-muted); font-family: monospace; }
        .ch-inv  { font-size: 12px; font-weight: 700; color: var(--text-color); font-family: monospace; }

        /* Rx table */
        .ch-rx-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 14px; }
        .ch-rx-table th {
            font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
            color: var(--text-muted); padding: 7px 8px;
            background: var(--card-bg); border-bottom: 1px solid rgba(255,255,255,0.04); text-align: center;
        }
        .ch-rx-table th:first-child { text-align: left; border-radius: 8px 0 0 0; }
        .ch-rx-table th:last-child  { border-radius: 0 8px 0 0; }
        .ch-rx-table td { padding: 8px; text-align: center; color: var(--text-color); border-bottom: 1px solid rgba(255,255,255,0.025); }
        .ch-rx-table td:first-child { text-align: left; font-weight: 700; color: var(--text-muted); font-size: 11px; }
        .ch-rx-table tr:last-child td { border-bottom: none; }
        .rx-p { color: var(--success); font-weight: 700; }
        .rx-n { color: var(--danger);  font-weight: 700; }
        .rx-z { color: #444; }
        .rx-arr { color: #333; }

        /* meta chips */
        .ch-meta-wrap { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
        .ch-meta-chip {
            background: var(--card-bg); border-radius: 10px; padding: 7px 13px;
            box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 6px var(--shadow-light);
        }
        .ch-meta-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); display: block; margin-bottom: 2px; }
        .ch-meta-val   { font-size: 12px; font-weight: 600; color: var(--text-color); }

        /* need badges */
        .ch-need-wrap { display: flex; gap: 8px; flex-wrap: wrap; }
        .ch-need {
            display: inline-flex; align-items: center; gap: 4px;
            border-radius: 20px; padding: 4px 11px; font-size: 10px; font-weight: 700;
            box-shadow: inset 2px 2px 4px var(--shadow-dark), inset -2px -2px 4px var(--shadow-light);
        }
        .ch-need.on  { color: var(--accent-solid); }
        .ch-need.off { color: #333; }

        /* notes */
        .ch-note-box {
            background: var(--card-bg); border-radius: 12px; padding: 12px 14px;
            margin-top: 12px; font-size: 12px; color: var(--text-muted);
            font-style: italic; line-height: 1.6;
            box-shadow: inset 4px 4px 8px var(--shadow-dark), inset -4px -4px 8px var(--shadow-light);
        }
        .ch-note-label { font-style: normal; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #444; display: block; margin-bottom: 4px; }

        /* order detail grid */
        .ch-order-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
        @media(max-width:500px) { .ch-order-grid { grid-template-columns: 1fr; } }
        .ch-od-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); display: block; margin-bottom: 3px; }
        .ch-od-val   { font-size: 13px; font-weight: 600; color: var(--text-color); }

        /* payment bar */
        .ch-pay-wrap { margin: 10px 0 4px; }
        .ch-pay-labels { display: flex; justify-content: space-between; font-size: 10px; color: var(--text-muted); margin-bottom: 6px; }
        .ch-pay-track  { background: var(--card-bg); border-radius: 6px; height: 8px; overflow: hidden; box-shadow: inset 2px 2px 4px var(--shadow-dark); }
        .ch-pay-fill   { height: 100%; border-radius: 6px; transition: width .5s ease; }

        /* status pill */
        .ch-status-pill {
            display: inline-block; border-radius: 8px; padding: 3px 10px;
            font-size: 10px; font-weight: 700;
            box-shadow: inset 2px 2px 4px var(--shadow-dark), inset -2px -2px 4px var(--shadow-light);
        }

        /* total highlight */
        .ch-order-total { font-size: 14px; font-weight: 800; color: var(--accent-solid); margin-left: auto; white-space: nowrap; }
    </style>
</head>
<body>
<div class="main-wrapper">
    <div class="content-area">

        <!-- ── Header (identical to lense_price.php) ───────────────── -->
        <div class="header-container">
            <button class="logout-btn" onclick="window.location.href='logout.php';">Logout</button>
            <div class="brand-section">
                <div class="logo-box">
                    <img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH ?? 'assets/logo.png'); ?>?t=<?php echo time(); ?>" alt="Brand Logo" style="height:40px;">
                </div>
                <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME ?? 'LENZA OPTIC'); ?></h1>
                <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS ?? ''); ?></p>
            </div>
        </div>

        <!-- ── Main content window ─────────────────────────────────── -->
        <div class="config-window">

            <div class="page-header" style="text-align:center;margin-bottom:24px;">
                <h2 style="margin:0 0 4px;font-size:18px;">📋 Customer History</h2>
                <p style="margin:0;color:var(--text-muted);font-size:12px;">Riwayat pemeriksaan, pembelian, dan analisa per customer</p>
            </div>

            <!-- ── Search bar ─────────────────────────────────────── -->
            <div class="ch-search-bar">
                <span class="ch-search-label">🔍 Cari Customer — nama, nomor HP, atau nomor invoice</span>
                <form method="get" action="customer_history.php">
                    <div class="ch-search-row">
                        <input
                            type="text" name="q"
                            class="ch-search-input"
                            placeholder="Contoh: ANDI / 0812xxxx / INV-20240101-001"
                            value="<?= htmlspecialchars($search_input) ?>"
                            autocomplete="off" autofocus
                        >
                        <button type="submit" class="ch-search-btn">Cari</button>
                    </div>
                </form>
            </div>

            <?php if ($search_input === ''): ?>
            <!-- ── Welcome state ───────────────────────────────────── -->
            <div class="ch-state">
                <div class="ch-state-icon">🔎</div>
                <p>Masukkan nama customer, nomor telepon, atau nomor invoice<br>untuk melihat riwayat lengkapnya.</p>
            </div>

            <?php elseif ($error_msg !== ''): ?>
            <!-- ── Not found ───────────────────────────────────────── -->
            <div class="ch-state error">
                <div class="ch-state-icon">⚠️</div>
                <p><?= $error_msg ?></p>
            </div>

            <?php else: ?>
            <!-- ═══════════════════════════════════════════════════════
                 CUSTOMER FOUND
            ══════════════════════════════════════════════════════════ -->

            <!-- ── Identity card ──────────────────────────────────── -->
            <div class="ch-identity">
                <div class="ch-avatar"><?= mb_substr($customer_data['name'], 0, 1) ?></div>
                <div class="ch-id-info">
                    <div class="ch-id-name"><?= htmlspecialchars($customer_data['name']) ?></div>
                    <div class="ch-id-sub">📞 <span><?= htmlspecialchars($customer_data['phone']) ?></span> &nbsp;·&nbsp; Ref: <span><?= htmlspecialchars($customer_data['pivot_invoice']) ?></span></div>
                </div>
                <div class="ch-id-badges">
                    <span class="ch-badge exam">👁 <?= $exam_count ?> pemeriksaan</span>
                    <span class="ch-badge order">🧾 <?= $order_count ?> order</span>
                    <?php if ($unpaid_amount > 0): ?>
                    <span class="ch-badge debt">⚠ <?= fmt_idr($unpaid_amount) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── KPI row ─────────────────────────────────────────── -->
            <div class="ch-kpi-grid">
                <div class="ch-kpi c-teal">
                    <div class="ch-kpi-label">Total Transaksi</div>
                    <div class="ch-kpi-val sm"><?= fmt_idr($total_spent) ?></div>
                    <div class="ch-kpi-sub"><?= $order_count ?> order</div>
                </div>
                <div class="ch-kpi c-green">
                    <div class="ch-kpi-label">Total Dibayar</div>
                    <div class="ch-kpi-val sm"><?= fmt_idr($total_paid) ?></div>
                    <div class="ch-kpi-sub"><?= $total_spent > 0 ? round($total_paid/$total_spent*100) : 0 ?>% dari total</div>
                </div>
                <div class="ch-kpi <?= $unpaid_amount > 0 ? 'c-red' : 'c-green' ?>">
                    <div class="ch-kpi-label">Sisa Hutang</div>
                    <div class="ch-kpi-val sm"><?= fmt_idr($unpaid_amount) ?></div>
                    <div class="ch-kpi-sub"><?= $unpaid_orders ?> belum · <?= $partial_orders ?> partial</div>
                </div>
                <div class="ch-kpi c-blue">
                    <div class="ch-kpi-label">Kunjungan</div>
                    <div class="ch-kpi-val"><?= $exam_count ?></div>
                    <div class="ch-kpi-sub">pemeriksaan tercatat</div>
                </div>
                <div class="ch-kpi c-amber">
                    <div class="ch-kpi-label">Rata-rata Interval</div>
                    <div class="ch-kpi-val"><?= $avg_gap_days !== null ? visit_gap_label($avg_gap_days) : '—' ?></div>
                    <div class="ch-kpi-sub">antar kunjungan</div>
                </div>
                <div class="ch-kpi c-purple">
                    <div class="ch-kpi-label">Periksa Terakhir</div>
                    <div class="ch-kpi-val sm"><?php
                        if (!empty($examinations)) {
                            $last = end($examinations);
                            echo date('d M Y', strtotime($last['examination_date']));
                        } else echo '—';
                    ?></div>
                    <div class="ch-kpi-sub"><?php
                        if (!empty($examinations)) {
                            $last = end($examinations);
                            $days_ago = (int)round((time() - strtotime($last['examination_date'])) / 86400);
                            echo $days_ago . ' hari lalu';
                        }
                    ?></div>
                </div>
            </div>

            <!-- ── Charts ─────────────────────────────────────────── -->
            <?php if (count($rx_trend) >= 2): ?>
            <div class="ch-section">
                <span class="ch-section-title">Tren Resep</span>
                <span class="ch-section-count">OD &amp; OS · New Rx</span>
            </div>
            <div class="ch-chart-grid">
                <div class="ch-chart-card">
                    <div class="ch-chart-title">SPH — Kanan (OD) &amp; Kiri (OS)</div>
                    <div class="ch-chart-wrap"><canvas id="chartSph"></canvas></div>
                </div>
                <div class="ch-chart-card">
                    <div class="ch-chart-title">CYL — Kanan (OD) &amp; Kiri (OS)</div>
                    <div class="ch-chart-wrap"><canvas id="chartCyl"></canvas></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Analysis ───────────────────────────────────────── -->
            <?php if (!empty($examinations) || !empty($orders)): ?>
            <div class="ch-section">
                <span class="ch-section-title">Ringkasan Analisa</span>
            </div>
            <div class="ch-analysis-grid">

                <!-- Visit pattern -->
                <div class="ch-analysis-card">
                    <div class="ch-analysis-title">📅 Pola Kunjungan</div>
                    <?php
                    $first_visit = !empty($exam_dates) ? date('d M Y', strtotime(reset($exam_dates))) : '—';
                    $last_visit  = !empty($exam_dates) ? date('d M Y', strtotime(end($exam_dates)))   : '—';
                    ?>
                    <div class="ch-analysis-row"><span class="ch-a-key">Kunjungan pertama</span><span class="ch-a-val teal"><?= $first_visit ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Kunjungan terakhir</span><span class="ch-a-val teal"><?= $last_visit ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Rata-rata interval</span><span class="ch-a-val"><?= $avg_gap_days !== null ? visit_gap_label($avg_gap_days) : '—' ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Interval terpendek</span><span class="ch-a-val"><?= !empty($visit_gaps) ? visit_gap_label(min($visit_gaps)) : '—' ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Interval terpanjang</span><span class="ch-a-val"><?= !empty($visit_gaps) ? visit_gap_label(max($visit_gaps)) : '—' ?></span></div>
                </div>

                <!-- Payment summary -->
                <div class="ch-analysis-card">
                    <div class="ch-analysis-title">💰 Status Pembayaran</div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Total tagihan</span><span class="ch-a-val"><?= fmt_idr($total_spent) ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Total dibayar</span><span class="ch-a-val good"><?= fmt_idr($total_paid) ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Sisa belum bayar</span><span class="ch-a-val <?= $unpaid_amount>0?'bad':'good' ?>"><?= fmt_idr($unpaid_amount) ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Order lunas</span><span class="ch-a-val good"><?= $paid_orders ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Partial / belum bayar</span><span class="ch-a-val <?= ($partial_orders+$unpaid_orders)>0?'warn':'good' ?>"><?= $partial_orders + $unpaid_orders ?></span></div>
                </div>

                <!-- Rx progression -->
                <?php if (count($rx_trend) >= 2):
                    $fr = $rx_trend[0]; $lr = $rx_trend[count($rx_trend)-1];
                    function rdelta($a,$b){ return ($a===null||$b===null)?null:round($b-$a,2); }
                    function dcls($d){ if($d===null)return''; if($d<0)return'bad'; if($d>0)return'warn'; return'good'; }
                    function dstr($d){ if($d===null)return'—'; return $d>0?'+'.$d:(string)$d; }
                ?>
                <div class="ch-analysis-card">
                    <div class="ch-analysis-title">👁 Perubahan Resep</div>
                    <table class="ch-rx-tbl">
                        <thead><tr><th>Mata</th><th>Komp.</th><th>Awal</th><th></th><th>Akhir</th><th>Δ</th></tr></thead>
                        <tbody>
                        <?php foreach ([
                            ['OD','SPH',$fr['r_sph'],$lr['r_sph']],
                            ['OD','CYL',$fr['r_cyl'],$lr['r_cyl']],
                            ['OS','SPH',$fr['l_sph'],$lr['l_sph']],
                            ['OS','CYL',$fr['l_cyl'],$lr['l_cyl']],
                        ] as [$eye,$comp,$from,$to]):
                            $d = rdelta($from,$to);
                        ?>
                        <tr>
                            <td><?= $eye ?></td><td><?= $comp ?></td>
                            <td><?= fmt_rx($from) ?></td><td class="rx-arr">→</td>
                            <td><?= fmt_rx($to) ?></td>
                            <td class="ch-a-val <?= dcls($d) ?>"><?= dstr($d) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Timeline -->
                <?php
                $events = [];
                foreach ($examinations as $e) $events[] = ['date'=>$e['examination_date'],'type'=>'exam','label'=>'Pemeriksaan','sub'=>$e['examination_code']];
                foreach ($orders as $o)       $events[] = ['date'=>$o['order_date'],'type'=>'order','label'=>$o['invoice_number'],'sub'=>fmt_idr($o['total_amount'])];
                usort($events, fn($a,$b)=>strcmp($b['date'],$a['date']));
                $events = array_slice($events, 0, 6);
                ?>
                <div class="ch-analysis-card">
                    <div class="ch-analysis-title">🕐 Aktivitas Terkini</div>
                    <div class="ch-timeline">
                        <?php foreach ($events as $ev): ?>
                        <div class="ch-tl-item">
                            <div class="ch-tl-dot" style="background:<?= $ev['type']==='exam'?'#3b82f6':'var(--accent-solid)' ?>"></div>
                            <div class="ch-tl-box">
                                <div class="ch-tl-date"><?= date('d M Y', strtotime($ev['date'])) ?></div>
                                <div class="ch-tl-text"><?= htmlspecialchars($ev['label']) ?></div>
                                <div class="ch-tl-sub"><?= htmlspecialchars($ev['sub']) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════
                 EXAMINATION RECORDS
            ══════════════════════════════════════════════════════════ -->
            <?php if (!empty($examinations)): ?>
            <div class="ch-section">
                <span class="ch-section-title">Riwayat Pemeriksaan</span>
                <span class="ch-section-count"><?= $exam_count ?> data</span>
            </div>
            <?php
            function rx_td_ch($v) {
                if ($v===null||$v===''||$v==='0') return '<td class="rx-z">—</td>';
                $f=(float)$v; $cls=$f>0?'rx-p':($f<0?'rx-n':'rx-z');
                $disp=$f>0?'+'.$v:$v;
                return "<td class=\"$cls\">$disp</td>";
            }
            ?>
            <?php foreach (array_reverse($examinations) as $idx => $e):
                $has_notes    = !empty(trim($e['exam_notes'] ?? ''));
                $has_symptoms = !empty(trim($e['symptoms']  ?? ''));
            ?>
            <div class="ch-acc" id="ecard-<?= $idx ?>">
                <div class="ch-acc-header" onclick="toggle('ecard-<?= $idx ?>')">
                    <span class="ch-date-badge"><?= date('d M Y', strtotime($e['examination_date'])) ?></span>
                    <span class="ch-code"><?= htmlspecialchars($e['examination_code']) ?></span>
                    <?php if ($e['invoice_number'] && $e['invoice_number'] !== '00'): ?>
                        <span style="font-size:10px;color:#333;font-family:monospace">#<?= htmlspecialchars($e['invoice_number']) ?></span>
                    <?php endif; ?>
                    <div class="ch-need-wrap" style="margin-left:auto;margin-right:6px">
                        <?php if ($e['need_distance']): ?><span class="ch-need on">Jauh</span><?php endif; ?>
                        <?php if ($e['need_intermediate']): ?><span class="ch-need on">Mid</span><?php endif; ?>
                        <?php if ($e['need_near']): ?><span class="ch-need on">Dekat</span><?php endif; ?>
                    </div>
                    <span class="ch-acc-arrow">▼</span>
                </div>
                <div class="ch-acc-body">
                    <table class="ch-rx-table">
                        <thead>
                            <tr>
                                <th>Mata</th>
                                <th>Lama SPH</th><th>Lama CYL</th><th>Lama AX</th>
                                <th style="color:#333">→</th>
                                <th>Baru SPH</th><th>Baru CYL</th><th>Baru AX</th><th>ADD</th><th>Visus</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>OD (Kanan)</td>
                                <?= rx_td_ch($e['old_r_sph']) ?><?= rx_td_ch($e['old_r_cyl']) ?>
                                <td><?= htmlspecialchars($e['old_r_ax'] ?: '—') ?></td>
                                <td class="rx-arr">→</td>
                                <?= rx_td_ch($e['new_r_sph']) ?><?= rx_td_ch($e['new_r_cyl']) ?>
                                <td><?= htmlspecialchars($e['new_r_ax'] ?: '—') ?></td>
                                <?= rx_td_ch($e['new_r_add']) ?>
                                <td><?= htmlspecialchars($e['new_r_visus'] ?: '—') ?></td>
                            </tr>
                            <tr>
                                <td>OS (Kiri)</td>
                                <?= rx_td_ch($e['old_l_sph']) ?><?= rx_td_ch($e['old_l_cyl']) ?>
                                <td><?= htmlspecialchars($e['old_l_ax'] ?: '—') ?></td>
                                <td class="rx-arr">→</td>
                                <?= rx_td_ch($e['new_l_sph']) ?><?= rx_td_ch($e['new_l_cyl']) ?>
                                <td><?= htmlspecialchars($e['new_l_ax'] ?: '—') ?></td>
                                <?= rx_td_ch($e['new_l_add']) ?>
                                <td><?= htmlspecialchars($e['new_l_visus'] ?: '—') ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="ch-meta-wrap">
                        <?php if ($e['pd_dist']): ?><div class="ch-meta-chip"><span class="ch-meta-label">PD Distance</span><span class="ch-meta-val"><?= htmlspecialchars($e['pd_dist']) ?></span></div><?php endif; ?>
                        <?php if ($e['ucva_r']||$e['ucva_l']): ?><div class="ch-meta-chip"><span class="ch-meta-label">UCVA OD/OS</span><span class="ch-meta-val"><?= htmlspecialchars($e['ucva_r']?:'—') ?> / <?= htmlspecialchars($e['ucva_l']?:'—') ?></span></div><?php endif; ?>
                        <?php if ($e['age']): ?><div class="ch-meta-chip"><span class="ch-meta-label">Usia periksa</span><span class="ch-meta-val"><?= (int)$e['age'] ?> tahun</span></div><?php endif; ?>
                        <div class="ch-meta-chip"><span class="ch-meta-label">Kebiasaan</span><span class="ch-meta-val"><?= $e['visual_habit'] ? 'Dekat' : 'Jauh' ?></span></div>
                        <?php if ($e['digital_usage']): ?><div class="ch-meta-chip"><span class="ch-meta-label">Digital</span><span class="ch-meta-val" style="color:var(--warning)">Tinggi</span></div><?php endif; ?>
                        <?php if ($e['lens_modification']): ?><div class="ch-meta-chip"><span class="ch-meta-label">Modifikasi</span><span class="ch-meta-val" style="color:var(--warning)">Ya</span></div><?php endif; ?>
                        <?php if ($e['created_by']): ?><div class="ch-meta-chip"><span class="ch-meta-label">Pemeriksa</span><span class="ch-meta-val"><?= htmlspecialchars($e['created_by']) ?></span></div><?php endif; ?>
                    </div>

                    <div style="margin-top:14px">
                        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:8px">Kebutuhan Lensa</div>
                        <div class="ch-need-wrap">
                            <span class="ch-need <?= $e['need_distance']?'on':'off' ?>">👁 Jarak Jauh</span>
                            <span class="ch-need <?= $e['need_intermediate']?'on':'off' ?>">💻 Intermediate</span>
                            <span class="ch-need <?= $e['need_near']?'on':'off' ?>">📖 Jarak Dekat</span>
                        </div>
                    </div>

                    <?php if ($has_symptoms): ?>
                    <div class="ch-note-box"><span class="ch-note-label">Keluhan</span><?= nl2br(htmlspecialchars($e['symptoms'])) ?></div>
                    <?php endif; ?>
                    <?php if ($has_notes): ?>
                    <div class="ch-note-box"><span class="ch-note-label">Catatan Pemeriksa</span><?= nl2br(htmlspecialchars($e['exam_notes'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════
                 ORDER RECORDS
            ══════════════════════════════════════════════════════════ -->
            <?php if (!empty($orders)): ?>
            <div class="ch-section">
                <span class="ch-section-title">Riwayat Order</span>
                <span class="ch-section-count"><?= $order_count ?> transaksi</span>
            </div>
            <?php foreach (array_reverse($orders) as $oi => $o):
                $o_total = (float)$o['total_amount'];
                $o_paid  = (float)$o['amount_paid'];
                $o_sisa  = $o_total - $o_paid;
                $o_pct   = $o_total > 0 ? round($o_paid/$o_total*100) : 0;
                $s_color = order_status_color($o['order_status']);
                $s_label = order_status_label($o['order_status']);
                $fill_color = $o_pct>=100 ? 'var(--success)' : ($o_pct>0 ? 'var(--warning)' : 'var(--danger)');
            ?>
            <div class="ch-acc" id="ocard-<?= $oi ?>">
                <div class="ch-acc-header" onclick="toggle('ocard-<?= $oi ?>')">
                    <div style="width:8px;height:8px;border-radius:50%;background:<?= $s_color ?>;flex-shrink:0;box-shadow:0 0 6px <?= $s_color ?>"></div>
                    <span class="ch-inv"><?= htmlspecialchars($o['invoice_number']) ?></span>
                    <span style="font-size:11px;color:var(--text-muted)"><?= date('d M Y', strtotime($o['order_date'])) ?></span>
                    <span class="ch-status-pill" style="color:<?= $s_color ?>"><?= $s_label ?></span>
                    <span class="ch-order-total"><?= fmt_idr($o_total) ?></span>
                    <span class="ch-acc-arrow">▼</span>
                </div>
                <div class="ch-acc-body">
                    <div class="ch-order-grid">
                        <?php if ($o['lens_name']): ?><div><span class="ch-od-label">Lensa</span><span class="ch-od-val" style="font-size:12px"><?= htmlspecialchars($o['lens_name']) ?></span></div><?php endif; ?>
                        <?php if ($o['frame_ufc']): ?><div><span class="ch-od-label">Frame UFC</span><span class="ch-od-val" style="font-size:12px"><?= htmlspecialchars($o['frame_ufc']) ?></span></div><?php endif; ?>
                        <div><span class="ch-od-label">Total</span><span class="ch-od-val" style="color:var(--accent-solid)"><?= fmt_idr($o_total) ?></span></div>
                        <div><span class="ch-od-label">Dibayar</span><span class="ch-od-val" style="color:var(--success)"><?= fmt_idr($o_paid) ?></span></div>
                        <div><span class="ch-od-label">Sisa</span><span class="ch-od-val" style="color:<?= $o_sisa>0?'var(--danger)':'var(--success)' ?>"><?= fmt_idr(max(0,$o_sisa)) ?></span></div>
                        <?php if ($o['due_date']): ?><div><span class="ch-od-label">Jatuh Tempo</span><span class="ch-od-val" style="font-size:12px"><?= date('d M Y', strtotime($o['due_date'])) ?></span></div><?php endif; ?>
                        <?php if ($o['created_by']): ?><div><span class="ch-od-label">Dibuat oleh</span><span class="ch-od-val" style="font-size:12px"><?= htmlspecialchars($o['created_by']) ?></span></div><?php endif; ?>
                        <?php if ($o['customer_address']): ?><div style="grid-column:1/-1"><span class="ch-od-label">Alamat</span><span class="ch-od-val" style="font-size:12px"><?= nl2br(htmlspecialchars($o['customer_address'])) ?></span></div><?php endif; ?>
                    </div>
                    <div class="ch-pay-wrap">
                        <div class="ch-pay-labels"><span>Pembayaran</span><span><?= $o_pct ?>%</span></div>
                        <div class="ch-pay-track"><div class="ch-pay-fill" style="width:<?= $o_pct ?>%;background:<?= $fill_color ?>"></div></div>
                    </div>
                    <div style="font-size:11px;margin-top:6px;color:<?= $o_sisa>0?'var(--danger)':'var(--success)' ?>">
                        <?= $o_sisa>0 ? '⚠ Belum lunas: '.fmt_idr($o_sisa) : '✓ Lunas' ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($customer_data && empty($examinations) && empty($orders)): ?>
            <div class="ch-state">
                <div class="ch-state-icon">📭</div>
                <p>Customer ditemukan tetapi belum ada data pemeriksaan atau order.</p>
            </div>
            <?php endif; ?>

            <?php endif; // end customer found ?>

            <!-- ── Back button ─────────────────────────────────────── -->
            <div class="btn-group" style="margin-top:30px">
                <button class="back-main" onclick="window.location.href='javascript:history.back()'">← Back to Previous Page</button>
            </div>

        </div><!-- /.config-window -->

        <!-- ── Footer ──────────────────────────────────────────────── -->
        <div class="footer-container">
            <span class="footer-text">© <?= date('Y') ?> <?= htmlspecialchars($STORE_NAME ?? 'LENZA OPTIC') ?>. All Rights Reserved.</span>
        </div>

    </div><!-- /.content-area -->
</div><!-- /.main-wrapper -->

<!-- ── Charts ────────────────────────────────────────────────────────── -->
<?php if ($customer_data && count($rx_trend) >= 2): ?>
<script>
(function(){
    const trend = <?= json_encode($rx_trend) ?>;
    const labels = trend.map(t => {
        const d = new Date(t.date);
        return d.toLocaleDateString('id-ID', {day:'2-digit',month:'short',year:'2-digit'});
    });
    const grid  = 'rgba(255,255,255,0.04)';
    const ticks = '#555';
    const defaults = {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { labels: { color: '#a0aec0', font: {size:11} } },
            tooltip: { backgroundColor: '#2a2e32', borderColor: '#3a3e42', borderWidth: 1, titleColor: '#e2e8f0', bodyColor: '#a0aec0' }
        },
        scales: {
            x: { grid:{color:grid}, ticks:{color:ticks,font:{size:10}}, border:{color:grid} },
            y: { grid:{color:grid}, ticks:{color:ticks,font:{size:11}}, border:{color:grid} }
        }
    };
    new Chart(document.getElementById('chartSph'), {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label:'OD SPH', data:trend.map(t=>t.r_sph), borderColor:'#00d4ff', backgroundColor:'rgba(0,212,255,0.08)', pointBackgroundColor:'#00d4ff', tension:.35, borderWidth:2 },
                { label:'OS SPH', data:trend.map(t=>t.l_sph), borderColor:'#a855f7', backgroundColor:'rgba(168,85,247,0.08)', pointBackgroundColor:'#a855f7', tension:.35, borderWidth:2 }
            ]
        },
        options: JSON.parse(JSON.stringify(defaults))
    });
    new Chart(document.getElementById('chartCyl'), {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label:'OD CYL', data:trend.map(t=>t.r_cyl), borderColor:'#00ffaa', backgroundColor:'rgba(0,255,170,0.08)', pointBackgroundColor:'#00ffaa', tension:.35, borderWidth:2 },
                { label:'OS CYL', data:trend.map(t=>t.l_cyl), borderColor:'#f1c40f', backgroundColor:'rgba(241,196,15,0.08)', pointBackgroundColor:'#f1c40f', tension:.35, borderWidth:2 }
            ]
        },
        options: JSON.parse(JSON.stringify(defaults))
    });
})();
</script>
<?php endif; ?>

<script>
function toggle(id) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('open');
}
document.addEventListener('DOMContentLoaded', function(){
    // Auto-open most recent exam and order
    const e0 = document.getElementById('ecard-0');
    const o0 = document.getElementById('ocard-0');
    if (e0) e0.classList.add('open');
    if (o0) o0.classList.add('open');
});
</script>
</body>
</html>
