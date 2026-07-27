<?php
// customer_history.php
session_start();
include 'db_config.php';
include 'config_helper.php';
include 'auth_check.php';

// ── Search / resolve customer identity ──────────────────────────────────────
// Customers are grouped by PHONE NUMBER — the only field that stays consistent
// across orders (name and customer_number can both change over time). The
// canonical name/number/phone for a customer live in `customer_list`, which is
// kept in sync automatically by DB triggers on customer_orders.
$search_input = strtoupper(trim($_GET['q'] ?? ''));
$customer_data = null;
$examinations  = [];
$orders        = [];
$error_msg     = '';
$all_customers = null; // populated only when search is the "ALL" keyword

if (strtoupper($search_input) === 'ALL') {
    $stmt_all = $conn->prepare("SELECT customer_phone, customer_name, last_customer_number FROM customer_list ORDER BY customer_name ASC");
    $stmt_all->execute();
    $all_customers = $stmt_all->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_all->close();

    // Visit count per customer — same invoice-based matching logic used in the
    // main resolution below (name-only fallback ONLY when there are no orders
    // at all), so counts stay consistent with what "open detail" will show.
    foreach ($all_customers as &$c) {
        $stmt_inv = $conn->prepare("SELECT invoice_number FROM customer_orders WHERE customer_phone = ? AND invoice_number IS NOT NULL AND invoice_number <> '00'");
        $stmt_inv->bind_param('s', $c['customer_phone']);
        $stmt_inv->execute();
        $invs = array_column($stmt_inv->get_result()->fetch_all(MYSQLI_ASSOC), 'invoice_number');
        $stmt_inv->close();

        if (!empty($invs)) {
            $ph = implode(',', array_fill(0, count($invs), '?'));
            $types = str_repeat('s', count($invs));
            $stmt_cnt = $conn->prepare("SELECT COUNT(*) AS cnt FROM customer_examinations WHERE invoice_number IN ($ph)");
            $stmt_cnt->bind_param($types, ...$invs);
        } else {
            $stmt_cnt = $conn->prepare("SELECT COUNT(*) AS cnt FROM customer_examinations WHERE customer_name = ?");
            $stmt_cnt->bind_param('s', $c['customer_name']);
        }
        $stmt_cnt->execute();
        $c['visit_count'] = (int)($stmt_cnt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt_cnt->close();
    }
    unset($c);
} elseif ($search_input !== '') {
    $like = '%' . $search_input . '%';
    $canon_phone = null;

    // 1) Try to resolve a phone number directly: from customer_list (name or
    //    phone match), or from customer_orders (phone or invoice match).
    $stmt = $conn->prepare("
        SELECT customer_phone FROM customer_list
        WHERE customer_phone = ? OR customer_name LIKE ?
        ORDER BY updated_at DESC LIMIT 1
    ");
    $stmt->bind_param('ss', $search_input, $like);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($r) $canon_phone = $r['customer_phone'];

    if (!$canon_phone) {
        $stmt2 = $conn->prepare("
            SELECT customer_phone FROM customer_orders
            WHERE invoice_number = ? OR customer_phone LIKE ?
            ORDER BY order_date DESC LIMIT 1
        ");
        $stmt2->bind_param('ss', $search_input, $like);
        $stmt2->execute();
        $r2 = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        if ($r2) $canon_phone = $r2['customer_phone'];
    }

    // 2) Fall back to customer_examinations (by name or invoice) for customers
    //    who only have an exam record so far (no order/phone yet), then try to
    //    resolve their phone via that exam's invoice_number if one exists.
    $canon_name    = null;
    $pivot_invoice = null;

    if (!$canon_phone) {
        $stmt3 = $conn->prepare("
            SELECT invoice_number, customer_name FROM customer_examinations
            WHERE invoice_number = ? OR customer_name LIKE ?
            ORDER BY examination_date DESC LIMIT 1
        ");
        $stmt3->bind_param('ss', $search_input, $like);
        $stmt3->execute();
        $r3 = $stmt3->get_result()->fetch_assoc();
        $stmt3->close();
        if ($r3) {
            $canon_name    = $r3['customer_name'];
            $pivot_invoice = $r3['invoice_number'];
            if ($pivot_invoice && $pivot_invoice !== '00') {
                $stmt4 = $conn->prepare("SELECT customer_phone FROM customer_orders WHERE invoice_number = ? LIMIT 1");
                $stmt4->bind_param('s', $pivot_invoice);
                $stmt4->execute();
                $r4 = $stmt4->get_result()->fetch_assoc();
                $stmt4->close();
                if ($r4) $canon_phone = $r4['customer_phone'];
            }
        }
    }

    if (!$canon_phone && !$canon_name) {
        $error_msg = 'Data not found for: <strong>' . htmlspecialchars($search_input) . '</strong>';
    } else {
        $canon_number = null;

        // 3) Once we have a phone, pull the canonical name/number from
        //    customer_list (source of truth) — falls back to whatever name we
        //    already found if this phone isn't in customer_list yet.
        if ($canon_phone) {
            $stmt5 = $conn->prepare("SELECT customer_name, last_customer_number FROM customer_list WHERE customer_phone = ? LIMIT 1");
            $stmt5->bind_param('s', $canon_phone);
            $stmt5->execute();
            $r5 = $stmt5->get_result()->fetch_assoc();
            $stmt5->close();
            if ($r5) {
                $canon_name   = $r5['customer_name'];
                $canon_number = $r5['last_customer_number'];
            }
        }

        // 4) All orders for this phone (the reliable grouping key for orders).
        $order_invoices = [];
        if ($canon_phone) {
            $stmt6 = $conn->prepare("SELECT * FROM customer_orders WHERE customer_phone = ? ORDER BY order_date ASC");
            $stmt6->bind_param('s', $canon_phone);
            $stmt6->execute();
            $orders = $stmt6->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt6->close();
            $order_invoices = array_filter(array_column($orders, 'invoice_number'), fn($v) => $v && $v !== '00');
        }

        // 5) Examinations: name is NOT unique (two different customers can share
        //    the same name), so we must NOT blanket-match by customer_name when
        //    this phone already has orders — that would merge a different
        //    customer's exams in just because the name matches. Instead:
        //      - If this phone has order invoices, match ONLY by invoice_number
        //        tied to those orders (plus the original pivot invoice, if any,
        //        in case it's an exam-only visit with no purchase yet).
        //      - Only fall back to a name-based match when this phone has NO
        //        orders/invoices at all to disambiguate by.
        $exam_conditions = [];
        $exam_types  = '';
        $exam_params = [];

        $linked_invoices = $order_invoices;
        if ($pivot_invoice && $pivot_invoice !== '00' && !in_array($pivot_invoice, $linked_invoices, true)) {
            $linked_invoices[] = $pivot_invoice;
        }

        if (!empty($linked_invoices)) {
            $placeholders = implode(',', array_fill(0, count($linked_invoices), '?'));
            $exam_conditions[] = "invoice_number IN ($placeholders)";
            foreach ($linked_invoices as $inv) { $exam_types .= 's'; $exam_params[] = $inv; }
        } elseif ($canon_name) {
            // No orders/invoices at all for this phone — name match is the
            // only option we have left (best-effort for exam-only customers).
            $exam_conditions[] = 'customer_name = ?';
            $exam_types .= 's';
            $exam_params[] = $canon_name;
        }

        if (!empty($exam_conditions)) {
            $sql7 = "SELECT * FROM customer_examinations WHERE " . implode(' OR ', $exam_conditions) . " ORDER BY examination_date ASC";
            $stmt7 = $conn->prepare($sql7);
            $stmt7->bind_param($exam_types, ...$exam_params);
            $stmt7->execute();
            $examinations = $stmt7->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt7->close();
        }

        $customer_data = [
            'name'          => $canon_name   ?? '—',
            'phone'         => $canon_phone  ?? '—',
            'number'        => $canon_number ?? '—',
            'pivot_invoice' => $pivot_invoice ?? ($order_invoices[0] ?? '—'),
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
    if ($days < 30)  return $days . ' days';
    if ($days < 365) return round($days/30) . ' mos';
    return round($days/365,1) . ' yrs';
}
function order_status_label($s) {
    return [1=>'Process',2=>'Completed',3=>'Picked Up',4=>'Cancelled'][$s] ?? '?';
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
    $is_settled = ((int)$o['order_status'] === 5); // 5 = completed, item picked up, fully paid
    $diff = $is_settled ? 0.0 : ((float)$o['total_amount'] - (float)$o['amount_paid']);
    $total_spent += (float)$o['total_amount'];
    $total_paid  += $is_settled ? (float)$o['total_amount'] : (float)$o['amount_paid'];
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

// ── Build the prescription trend, including OLD prescription values ────────
// Each exam can contribute up to 2 points: its "old" Rx (what the customer had
// coming in) and its "new" Rx (what was prescribed that visit). We only add
// the "old" point if it actually has a value AND it differs from the
// previously plotted point — this avoids double-counting when, e.g., visit 2's
// old Rx is identical to visit 1's new Rx (same prescription carried over).
function rx_vals_equal($a, $b) {
    if ($a === null || $b === null) return false;
    foreach ($a as $i => $v) {
        $v  = $v  === null ? 0.0 : (float)$v;
        $bv = $b[$i] === null ? 0.0 : (float)$b[$i];
        if (abs($v - $bv) > 0.01) return false;
    }
    return true;
}

$rx_trend  = [];
$last_vals = null;
foreach ($examinations as $e) {
    $old_vals = [rx_float($e['old_r_sph']), rx_float($e['old_r_cyl']), rx_float($e['old_l_sph']), rx_float($e['old_l_cyl'])];
    $new_vals = [rx_float($e['new_r_sph']), rx_float($e['new_r_cyl']), rx_float($e['new_l_sph']), rx_float($e['new_l_cyl'])];

    $old_has_value = array_filter($old_vals, fn($v) => $v !== null && $v != 0.0);
    if (!empty($old_has_value) && !rx_vals_equal($old_vals, $last_vals)) {
        $rx_trend[] = [
            'date' => $e['examination_date'], 'label' => 'old',
            'r_sph' => $old_vals[0], 'r_cyl' => $old_vals[1], 'l_sph' => $old_vals[2], 'l_cyl' => $old_vals[3],
        ];
        $last_vals = $old_vals;
    }

    $rx_trend[] = [
        'date' => $e['examination_date'], 'label' => 'new',
        'r_sph' => $new_vals[0], 'r_cyl' => $new_vals[1], 'l_sph' => $new_vals[2], 'l_cyl' => $new_vals[3],
    ];
    $last_vals = $new_vals;
}

// ── Payload for the full-history / trend-based AI analysis ──────────────────
// Sent to analyze_customer_history.php when the user clicks "Generate AI Analysis"
// in the Customer card. Unlike customer_prescription.php's single-visit analysis,
// this looks at the whole examination history to spot patterns over time.
$ai_history_payload = [
    'customer_name' => $customer_data['name'] ?? '',
    'exam_count'    => $exam_count,
    'avg_gap_days'  => $avg_gap_days,
    'visits'        => array_map(function($e) {
        return [
            'date'              => $e['examination_date'],
            'age'               => $e['age'] ?? null,
            'old_r_sph'         => rx_float($e['old_r_sph']),
            'old_r_cyl'         => rx_float($e['old_r_cyl']),
            'old_l_sph'         => rx_float($e['old_l_sph']),
            'old_l_cyl'         => rx_float($e['old_l_cyl']),
            'r_sph'             => rx_float($e['new_r_sph']),
            'r_cyl'             => rx_float($e['new_r_cyl']),
            'r_ax'              => $e['new_r_ax'] ?? null,
            'r_add'             => rx_float($e['new_r_add']),
            'l_sph'             => rx_float($e['new_l_sph']),
            'l_cyl'             => rx_float($e['new_l_cyl']),
            'l_ax'              => $e['new_l_ax'] ?? null,
            'l_add'             => rx_float($e['new_l_add']),
            'need_distance'     => (bool)($e['need_distance'] ?? false),
            'need_intermediate' => (bool)($e['need_intermediate'] ?? false),
            'need_near'         => (bool)($e['need_near'] ?? false),
            'visual_habit'      => !empty($e['visual_habit']) ? 'near' : 'distance',
            'digital_usage'     => (bool)($e['digital_usage'] ?? false),
            'lens_modification' => (bool)($e['lens_modification'] ?? false),
            'symptoms'          => trim($e['symptoms'] ?? ''),
            'exam_notes'        => trim($e['exam_notes'] ?? ''),
        ];
    }, $examinations),
    // Deduplicated old+new Rx timeline (see $rx_trend above) — gives the AI the
    // actual distinct prescription data points to reason about, already
    // collapsed where an "old" value duplicates the previous plotted point.
    'rx_timeline' => $rx_trend,
];
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
            padding: 20px 12px;
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

        /* ── ALL customers list ──────────────────────────────────────────── */
        .ch-all-list { display: flex; flex-direction: column; gap: 8px; margin-top: 4px; }
        .ch-all-row {
            display: flex; align-items: center; gap: 12px;
            background: var(--card-bg); border-radius: 14px; padding: 10px 14px;
            text-decoration: none; color: inherit;
            transition: transform .15s, background .15s;
        }
        .ch-all-row:hover { background: rgba(255,255,255,0.04); transform: translateX(2px); }
        .ch-all-avatar {
            width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: var(--accent-solid); color: #fff; font-weight: 800; font-size: 14px;
        }
        .ch-all-info { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .ch-all-name { font-size: 13px; font-weight: 700; color: var(--text-main); }
        .ch-all-sub  { font-size: 11px; color: var(--text-muted); margin-top: 1px; }
        .ch-all-arrow { font-size: 20px; color: var(--text-muted); }

        /* ── Group wrapper cards (Business vs Customer) ─────────────────── */
        .ch-group-header {
            display: flex; align-items: center; gap: 10px;
            margin: 30px 0 12px;
            cursor: pointer; user-select: none;
            background: var(--card-bg); border-radius: 16px; padding: 12px 16px;
            transition: background .15s;
        }
        .ch-group-header:hover { background: rgba(255,255,255,0.05); }
        .ch-group-header:first-of-type { margin-top: 8px; }
        .ch-group-arrow {
            font-size: 13px; color: var(--text-muted); flex-shrink: 0;
            transition: transform .25s;
        }
        .ch-group-header.open .ch-group-arrow { transform: rotate(180deg); }
        .ch-group-icon {
            width: 34px; height: 34px; border-radius: 12px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 16px;
            background: var(--bg-color);
            box-shadow: 6px 6px 12px var(--shadow-dark), -6px -6px 12px var(--shadow-light);
        }
        .ch-group-titles { flex: 1; min-width: 0; }
        .ch-group-title { font-size: 13px; font-weight: 800; letter-spacing: .4px; color: var(--text-main); }
        .ch-group-sub   { font-size: 11px; color: var(--text-muted); margin-top: 1px; }
        .ch-card-business, .ch-card-customer {
            background: rgba(255,255,255,0.012);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 22px; padding: 18px 16px 6px;
            margin-bottom: 24px;
            overflow: hidden;
        }
        .ch-card-business.ch-collapsed, .ch-card-customer.ch-collapsed {
            display: none;
        }
        .ch-card-business .ch-section:first-child,
        .ch-card-customer .ch-section:first-child { margin-top: 4px; }

        /* ── AI Analysis panel (customer card) ──────────────────────────── */
        .ch-ai-wrap {
            background: var(--bg-color); border-radius: 18px; padding: 18px 20px;
            box-shadow: 8px 8px 16px var(--shadow-dark), -8px -8px 16px var(--shadow-light);
            margin-bottom: 4px;
        }
        .ch-ai-btn {
            display: inline-flex; align-items: center; gap: 8px;
            background: linear-gradient(135deg, var(--accent-solid), #7c3aed);
            border: none; border-radius: 14px; padding: 12px 22px;
            color: #fff; font-size: 12px; font-weight: 700; letter-spacing: .6px;
            cursor: pointer; transition: all .2s;
        }
        .ch-ai-btn:hover  { filter: brightness(1.1); }
        .ch-ai-btn:disabled { opacity: .6; cursor: not-allowed; }
        .ch-ai-spinner {
            width: 13px; height: 13px; border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.35); border-top-color: #fff;
            animation: chSpin .7s linear infinite; display: inline-block;
        }
        @keyframes chSpin { to { transform: rotate(360deg); } }
        .ch-ai-empty { font-size: 12px; color: var(--text-muted); text-align: center; padding: 6px 0 10px; }
        .ch-ai-result { margin-top: 16px; }
        .ch-ai-condition {
            background: var(--card-bg); border-radius: 14px; padding: 14px 16px; margin-bottom: 10px;
        }
        .ch-ai-badge {
            display: inline-block; font-size: 11px; font-weight: 800; letter-spacing: .4px;
            padding: 4px 12px; border-radius: 20px; margin-bottom: 8px;
        }
        .ch-ai-badge.normal   { background: rgba(0,255,170,.12);  color: var(--success); }
        .ch-ai-badge.mild     { background: rgba(59,130,246,.14); color: #3b82f6; }
        .ch-ai-badge.moderate { background: rgba(241,196,15,.14); color: #f1c40f; }
        .ch-ai-badge.high,
        .ch-ai-badge.severe   { background: rgba(255,77,77,.14);  color: var(--danger); }
        .ch-ai-text  { font-size: 12.5px; color: var(--text-color); line-height: 1.6; }
        .ch-ai-text ul { margin: 6px 0 0; padding-left: 18px; }
        .ch-ai-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); margin: 10px 0 4px; }
        .ch-ai-summary-box {
            background: var(--card-bg); border-radius: 14px; padding: 14px 16px; margin-top: 10px;
        }
        .ch-ai-referral {
            background: rgba(255,77,77,.1); border: 1px solid rgba(255,77,77,.35);
            border-radius: 14px; padding: 12px 16px; margin-bottom: 12px; color: #ff9a9a; font-size: 12px;
        }
        .ch-ai-error {
            background: rgba(255,77,77,.1); border: 1px solid rgba(255,77,77,.35);
            border-radius: 14px; padding: 12px 16px; color: #ff9a9a; font-size: 12px;
        }
        .ch-ai-meta { text-align: center; font-size: 10px; color: #555; margin-top: 10px; }

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
</head>
<body>
<div class="main-wrapper">
    <div class="content-area">

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

        <div class="config-window">

            <div class="page-header" style="text-align:center;margin-bottom:24px;">
                <h2 style="margin:0 0 4px;font-size:18px;">📋 Customer History</h2>
                <p style="margin:0;color:var(--text-muted);font-size:12px;">Examination, purchase history, and analytics per customer</p>
            </div>

            <div class="ch-search-bar">
                <span class="ch-search-label">🔍 Search Customer — name, phone number, or invoice number</span>
                <form method="get" action="customer_history.php">
                    <div class="ch-search-row">
                        <input
                            type="text" name="q"
                            class="ch-search-input"
                            style="text-transform:uppercase"
                            placeholder="Example: ANDI / 0812xxxx / INV-20240101-001"
                            value="<?= htmlspecialchars($search_input) ?>"
                            autocomplete="off" autofocus
                            oninput="this.value = this.value.toUpperCase()"
                        >
                        <button type="submit" class="ch-search-btn">Search</button>
                    </div>
                </form>
            </div>

            <?php if ($all_customers !== null): ?>
            <div class="ch-section">
                <span class="ch-section-title">All Customers</span>
                <span class="ch-section-count"><?= count($all_customers) ?> total</span>
            </div>
            <?php if (empty($all_customers)): ?>
            <div class="ch-state">
                <div class="ch-state-icon">📭</div>
                <p>No customers in customer_list yet.</p>
            </div>
            <?php else: ?>
            <div class="ch-all-list">
                <?php foreach ($all_customers as $c): ?>
                <a class="ch-all-row" href="customer_history.php?q=<?= urlencode($c['customer_phone']) ?>">
                    <span class="ch-all-avatar"><?= mb_substr($c['customer_name'] ?: '?', 0, 1) ?></span>
                    <span class="ch-all-info">
                        <span class="ch-all-name"><?= htmlspecialchars($c['customer_name'] ?: '(no name)') ?></span>
                        <span class="ch-all-sub">📞 <?= htmlspecialchars($c['customer_phone']) ?> &nbsp;·&nbsp; No. Customer: <?= htmlspecialchars($c['last_customer_number'] ?: '—') ?> &nbsp;·&nbsp; 👁 <?= $c['visit_count'] ?> kunjungan</span>
                    </span>
                    <span class="ch-all-arrow">›</span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php elseif ($search_input === ''): ?>
            <div class="ch-state">
                <div class="ch-state-icon">🔎</div>
                <p>Enter a customer name, phone number, or invoice number<br>to view their complete history, or type <strong>ALL</strong> to list every customer.</p>
            </div>

            <?php elseif ($error_msg !== ''): ?>
            <div class="ch-state error">
                <div class="ch-state-icon">⚠️</div>
                <p><?= $error_msg ?></p>
            </div>

            <?php else: ?>
            <div class="ch-identity">
                <div class="ch-avatar"><?= mb_substr($customer_data['name'], 0, 1) ?></div>
                <div class="ch-id-info">
                    <div class="ch-id-name"><?= htmlspecialchars($customer_data['name']) ?></div>
                    <div class="ch-id-sub">📞 <span><?= htmlspecialchars($customer_data['phone']) ?></span> &nbsp;·&nbsp; No. Customer: <span><?= htmlspecialchars($customer_data['number']) ?></span></div>
                </div>
                <div class="ch-id-badges">
                    <span class="ch-badge exam">👁 <?= $exam_count ?> examinations</span>
                    <span class="ch-badge order">🧾 <?= $order_count ?> orders</span>
                    <?php if ($unpaid_amount > 0): ?>
                    <span class="ch-badge debt">⚠ <?= fmt_idr($unpaid_amount) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ch-group-header" id="bizHeader" onclick="toggleGroup('bizCard','bizHeader')">
                <div class="ch-group-icon">🏢</div>
                <div class="ch-group-titles">
                    <div class="ch-group-title">Business Overview</div>
                    <div class="ch-group-sub">Ringkasan transaksi, pembayaran, dan riwayat pesanan</div>
                </div>
                <span class="ch-group-arrow">▼</span>
            </div>
            <div class="ch-card-business ch-collapsed" id="bizCard">

            <div class="ch-kpi-grid">
                <div class="ch-kpi c-teal">
                    <div class="ch-kpi-label">Total Transactions</div>
                    <div class="ch-kpi-val sm"><?= fmt_idr($total_spent) ?></div>
                    <div class="ch-kpi-sub"><?= $order_count ?> orders</div>
                </div>
                <div class="ch-kpi c-green">
                    <div class="ch-kpi-label">Total Paid</div>
                    <div class="ch-kpi-val sm"><?= fmt_idr($total_paid) ?></div>
                    <div class="ch-kpi-sub"><?= $total_spent > 0 ? round($total_paid/$total_spent*100) : 0 ?>% of total</div>
                </div>
                <div class="ch-kpi <?= $unpaid_amount > 0 ? 'c-red' : 'c-green' ?>">
                    <div class="ch-kpi-label">Remaining Balance</div>
                    <div class="ch-kpi-val sm"><?= fmt_idr($unpaid_amount) ?></div>
                    <div class="ch-kpi-sub"><?= $unpaid_orders ?> unpaid · <?= $partial_orders ?> partial</div>
                </div>
                <div class="ch-kpi c-blue">
                    <div class="ch-kpi-label">Visits</div>
                    <div class="ch-kpi-val"><?= $exam_count ?></div>
                    <div class="ch-kpi-sub">recorded examinations</div>
                </div>
                <div class="ch-kpi c-amber">
                    <div class="ch-kpi-label">Average Interval</div>
                    <div class="ch-kpi-val"><?= $avg_gap_days !== null ? visit_gap_label($avg_gap_days) : '—' ?></div>
                    <div class="ch-kpi-sub">between visits</div>
                </div>
                <div class="ch-kpi c-purple">
                    <div class="ch-kpi-label">Last Exam</div>
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
                            echo $days_ago . ' days ago';
                        }
                    ?></div>
                </div>
            </div>

            <?php if (!empty($examinations) || !empty($orders)): ?>
            <div class="ch-section">
                <span class="ch-section-title">Analysis Summary</span>
            </div>
            <div class="ch-analysis-grid">

                <div class="ch-analysis-card">
                    <div class="ch-analysis-title">📅 Visit Patterns</div>
                    <?php
                    $first_visit = !empty($exam_dates) ? date('d M Y', strtotime(reset($exam_dates))) : '—';
                    $last_visit  = !empty($exam_dates) ? date('d M Y', strtotime(end($exam_dates)))   : '—';
                    ?>
                    <div class="ch-analysis-row"><span class="ch-a-key">First visit</span><span class="ch-a-val teal"><?= $first_visit ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Last visit</span><span class="ch-a-val teal"><?= $last_visit ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Average interval</span><span class="ch-a-val"><?= $avg_gap_days !== null ? visit_gap_label($avg_gap_days) : '—' ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Shortest interval</span><span class="ch-a-val"><?= !empty($visit_gaps) ? visit_gap_label(min($visit_gaps)) : '—' ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Longest interval</span><span class="ch-a-val"><?= !empty($visit_gaps) ? visit_gap_label(max($visit_gaps)) : '—' ?></span></div>
                </div>

                <div class="ch-analysis-card">
                    <div class="ch-analysis-title">💰 Payment Status</div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Total amount</span><span class="ch-a-val"><?= fmt_idr($total_spent) ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Total paid</span><span class="ch-a-val good"><?= fmt_idr($total_paid) ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Remaining balance</span><span class="ch-a-val <?= $unpaid_amount>0?'bad':'good' ?>"><?= fmt_idr($unpaid_amount) ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Fully paid orders</span><span class="ch-a-val good"><?= $paid_orders ?></span></div>
                    <div class="ch-analysis-row"><span class="ch-a-key">Partial / unpaid</span><span class="ch-a-val <?= ($partial_orders+$unpaid_orders)>0?'warn':'good' ?>"><?= $partial_orders + $unpaid_orders ?></span></div>
                </div>

                <?php if (count($rx_trend) >= 2):
                    $fr = $rx_trend[0]; $lr = $rx_trend[count($rx_trend)-1];
                    function rdelta($a,$b){ return ($a===null||$b===null)?null:round($b-$a,2); }
                    function dcls($d){ if($d===null)return''; if($d<0)return'bad'; if($d>0)return'warn'; return'good'; }
                    function dstr($d){ if($d===null)return'—'; return $d>0?'+'.$d:(string)$d; }
                ?>
                <div class="ch-analysis-card">
                    <div class="ch-analysis-title">👁 Prescription Changes</div>
                    <table class="ch-rx-tbl">
                        <thead><tr><th>Eye</th><th>Comp.</th><th>Initial</th><th></th><th>Final</th><th>Δ</th></tr></thead>
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

                <?php
                $events = [];
                foreach ($examinations as $e) $events[] = ['date'=>$e['examination_date'],'type'=>'exam','label'=>'Examination','sub'=>$e['examination_code']];
                foreach ($orders as $o)       $events[] = ['date'=>$o['order_date'],'type'=>'order','label'=>$o['invoice_number'],'sub'=>fmt_idr($o['total_amount'])];
                usort($events, fn($a,$b)=>strcmp($b['date'],$a['date']));
                $events = array_slice($events, 0, 6);
                ?>
                <div class="ch-analysis-card">
                    <div class="ch-analysis-title">🕐 Recent Activities</div>
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

            <?php if (!empty($orders)): ?>
            <div class="ch-section">
                <span class="ch-section-title">Order History</span>
                <span class="ch-section-count"><?= $order_count ?> transactions</span>
            </div>
            <?php foreach (array_reverse($orders) as $oi => $o):
                $o_total = (float)$o['total_amount'];
                $o_is_settled = ((int)$o['order_status'] === 5);
                $o_paid  = $o_is_settled ? $o_total : (float)$o['amount_paid'];
                $o_sisa  = $o_is_settled ? 0.0 : ($o_total - $o_paid);
                $o_pct   = $o_total > 0 ? round($o_paid/$o_total*100) : ($o_is_settled ? 100 : 0);
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
                        <?php if ($o['lens_name']): ?><div><span class="ch-od-label">Lens</span><span class="ch-od-val" style="font-size:12px"><?= htmlspecialchars($o['lens_name']) ?></span></div><?php endif; ?>
                        <?php if ($o['frame_ufc']): ?><div><span class="ch-od-label">Frame UFC</span><span class="ch-od-val" style="font-size:12px"><?= htmlspecialchars($o['frame_ufc']) ?></span></div><?php endif; ?>
                        <div><span class="ch-od-label">Total</span><span class="ch-od-val" style="color:var(--accent-solid)"><?= fmt_idr($o_total) ?></span></div>
                        <div><span class="ch-od-label">Paid</span><span class="ch-od-val" style="color:var(--success)"><?= fmt_idr($o_paid) ?></span></div>
                        <div><span class="ch-od-label">Balance</span><span class="ch-od-val" style="color:<?= $o_sisa>0?'var(--danger)':'var(--success)' ?>"><?= fmt_idr(max(0,$o_sisa)) ?></span></div>
                        <?php if ($o['due_date']): ?><div><span class="ch-od-label">Due Date</span><span class="ch-od-val" style="font-size:12px"><?= date('d M Y', strtotime($o['due_date'])) ?></span></div><?php endif; ?>
                        <?php if ($o['created_by']): ?><div><span class="ch-od-label">Created by</span><span class="ch-od-val" style="font-size:12px"><?= htmlspecialchars($o['created_by']) ?></span></div><?php endif; ?>
                        <?php if ($o['customer_address']): ?><div style="grid-column:1/-1"><span class="ch-od-label">Address</span><span class="ch-od-val" style="font-size:12px"><?= nl2br(htmlspecialchars($o['customer_address'])) ?></span></div><?php endif; ?>
                    </div>
                    <div class="ch-pay-wrap">
                        <div class="ch-pay-labels"><span>Payment</span><span><?= $o_pct ?>%</span></div>
                        <div class="ch-pay-track"><div class="ch-pay-fill" style="width:<?= $o_pct ?>%;background:<?= $fill_color ?>"></div></div>
                    </div>
                    <div style="font-size:11px;margin-top:6px;color:<?= $o_sisa>0?'var(--danger)':'var(--success)' ?>">
                        <?= $o_sisa>0 ? '⚠ Remaining Balance: '.fmt_idr($o_sisa) : '✓ Fully Paid' ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            </div><!-- /.ch-card-business -->

            <?php if (!empty($examinations)): ?>
            <div class="ch-group-header" id="custHeader" onclick="toggleGroup('custCard','custHeader')">
                <div class="ch-group-icon">🧑</div>
                <div class="ch-group-titles">
                    <div class="ch-group-title">Customer Record</div>
                    <div class="ch-group-sub">Riwayat pemeriksaan mata, resep, dan analisis AI</div>
                </div>
                <button type="button" class="ch-ai-btn" id="ch_pdf_btn" style="background:linear-gradient(135deg,#2c3e50,#4a5568)" onclick="event.stopPropagation(); downloadCustomerPdf()">
                    <span id="ch_pdf_btn_label">📄 Download PDF</span>
                </button>
                <span class="ch-group-arrow">▼</span>
            </div>
            <div class="ch-card-customer ch-collapsed" id="custCard">

            <?php if (count($rx_trend) >= 2): ?>
            <div class="ch-section">
                <span class="ch-section-title">Prescription Trend</span>
                <span class="ch-section-count">OD &amp; OS · New Rx</span>
            </div>
            <div class="ch-chart-grid">
                <div class="ch-chart-card">
                    <div class="ch-chart-title">SPH — Right (OD) &amp; Left (OS)</div>
                    <div class="ch-chart-wrap"><canvas id="chartSph"></canvas></div>
                </div>
                <div class="ch-chart-card">
                    <div class="ch-chart-title">CYL — Right (OD) &amp; Left (OS)</div>
                    <div class="ch-chart-wrap"><canvas id="chartCyl"></canvas></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="ch-section">
                <span class="ch-section-title">Examination History</span>
                <span class="ch-section-count"><?= $exam_count ?> records</span>
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
                // Lens Requirement card is only relevant when the customer needs BOTH
                // distance and near correction (i.e. has an ADD power on at least one eye).
                $has_add          = !empty(trim($e['new_r_add'] ?? '')) || !empty(trim($e['new_l_add'] ?? ''));
                $show_lens_req    = !empty($e['need_distance']) && !empty($e['need_near']) && $has_add;
            ?>
            <div class="ch-acc" id="ecard-<?= $idx ?>">
                <div class="ch-acc-header" onclick="toggle('ecard-<?= $idx ?>')">
                    <span class="ch-date-badge"><?= date('d M Y', strtotime($e['examination_date'])) ?></span>
                    <span class="ch-code"><?= htmlspecialchars($e['examination_code']) ?></span>
                    <?php if ($e['invoice_number'] && $e['invoice_number'] !== '00'): ?>
                        <span style="font-size:10px;color:#333;font-family:monospace">#<?= htmlspecialchars($e['invoice_number']) ?></span>
                    <?php endif; ?>
                    <div class="ch-need-wrap" style="margin-left:auto;margin-right:6px">
                        <?php if ($e['need_distance']): ?><span class="ch-need on">Distance</span><?php endif; ?>
                        <?php if ($e['need_intermediate']): ?><span class="ch-need on">Mid</span><?php endif; ?>
                        <?php if ($e['need_near']): ?><span class="ch-need on">Near</span><?php endif; ?>
                    </div>
                    <span class="ch-acc-arrow">▼</span>
                </div>
                <div class="ch-acc-body">
                    <table class="ch-rx-table">
                        <thead>
                            <tr>
                                <th>Eye</th>
                                <th>Old SPH</th><th>Old CYL</th><th>Old AX</th>
                                <th style="color:#333">→</th>
                                <th>New SPH</th><th>New CYL</th><th>New AX</th><th>ADD</th><th>VA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>OD (Right)</td>
                                <?= rx_td_ch($e['old_r_sph']) ?><?= rx_td_ch($e['old_r_cyl']) ?>
                                <td><?= htmlspecialchars($e['old_r_ax'] ?: '—') ?></td>
                                <td class="rx-arr">→</td>
                                <?= rx_td_ch($e['new_r_sph']) ?><?= rx_td_ch($e['new_r_cyl']) ?>
                                <td><?= htmlspecialchars($e['new_r_ax'] ?: '—') ?></td>
                                <?= rx_td_ch($e['new_r_add']) ?>
                                <td><?= htmlspecialchars($e['new_r_visus'] ?: '—') ?></td>
                            </tr>
                            <tr>
                                <td>OS (Left)</td>
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
                        <?php if ($e['age']): ?><div class="ch-meta-chip"><span class="ch-meta-label">Age at Exam</span><span class="ch-meta-val"><?= (int)$e['age'] ?> years old</span></div><?php endif; ?>
                        <div class="ch-meta-chip"><span class="ch-meta-label">Visual Habit</span><span class="ch-meta-val"><?= $e['visual_habit'] ? 'Near' : 'Distance' ?></span></div>
                        <?php if ($e['digital_usage']): ?><div class="ch-meta-chip"><span class="ch-meta-label">Digital</span><span class="ch-meta-val" style="color:var(--warning)">High</span></div><?php endif; ?>
                        <?php if ($e['lens_modification']): ?><div class="ch-meta-chip"><span class="ch-meta-label">Modification</span><span class="ch-meta-val" style="color:var(--warning)">Yes</span></div><?php endif; ?>
                    </div>

                    <?php if ($show_lens_req): ?>
                    <div style="margin-top:14px">
                        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:8px">Lens Requirements</div>
                        <div class="ch-need-wrap">
                            <span class="ch-need <?= $e['need_distance']?'on':'off' ?>">👁 Distance</span>
                            <span class="ch-need <?= $e['need_intermediate']?'on':'off' ?>">💻 Intermediate</span>
                            <span class="ch-need <?= $e['need_near']?'on':'off' ?>">📖 Near</span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($has_symptoms): ?>
                    <div class="ch-note-box"><span class="ch-note-label">Symptoms</span><?= nl2br(htmlspecialchars($e['symptoms'])) ?></div>
                    <?php endif; ?>
                    <?php if ($has_notes): ?>
                    <div class="ch-note-box"><span class="ch-note-label">Examiner Notes</span><?= nl2br(htmlspecialchars($e['exam_notes'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- ── AI Analysis (full history / trend-based) ─────────────────── -->
            <div class="ch-section">
                <span class="ch-section-title">AI Analysis</span>
                <span class="ch-section-count">Berdasarkan seluruh riwayat</span>
            </div>
            <div class="ch-ai-wrap">
                <?php if (count($examinations) < 1): ?>
                <div class="ch-ai-empty">Belum ada data pemeriksaan untuk dianalisis.</div>
                <?php else: ?>
                <div style="text-align:center">
                    <button type="button" class="ch-ai-btn" id="ch_ai_btn" onclick="requestHistoryAIAnalysis()">
                        <span id="ch_ai_btn_label">✦ Generate AI Analysis</span>
                    </button>
                </div>
                <div id="ch_ai_referral"></div>
                <div id="ch_ai_result" class="ch-ai-result"></div>
                <?php endif; ?>
            </div>

            </div><!-- /.ch-card-customer -->
            <?php endif; // end !empty($examinations) ?>

            <?php if ($customer_data && empty($examinations) && empty($orders)): ?>
            <div class="ch-state">
                <div class="ch-state-icon">📭</div>
                <p>Customer found but no examination or order records available.</p>
            </div>
            <?php endif; ?>

            <?php endif; // end customer found ?>

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

    <div class="footer-container">
        <span class="footer-text">© <?= date('Y') ?> <?= htmlspecialchars($STORE_NAME ?? 'LENZA OPTIC') ?>. All Rights Reserved.</span>
    </div>
</div>
<div class="logo-backdrop" id="logoBackdrop" ondblclick="zoomOutLogo(document.getElementById('storeLogo'))"></div>
        
<?php if ($customer_data && count($rx_trend) >= 2): ?>
<script>
(function(){
    const trend = <?= json_encode($rx_trend) ?>;
    const labels = trend.map(t => {
        const d = new Date(t.date);
        const base = d.toLocaleDateString('en-US', {day:'2-digit',month:'short',year:'2-digit'});
        return t.label === 'old' ? base + ' (Lama)' : base;
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

<?php if ($customer_data && count($examinations) >= 1): ?>
<script>
// ================================================================
// === TREND-BASED AI ANALYSIS (full examination history) ========
// ================================================================
(function(){
    const historyPayload = <?= json_encode($ai_history_payload) ?>;

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }

    function buildAiConditions(findings) {
        if (!Array.isArray(findings) || !findings.length) return '';
        return findings.map(f => {
            const sev = (f.severity || 'normal').toLowerCase();
            const validSev = ['normal','mild','moderate','high','severe'].includes(sev) ? sev : 'normal';
            const causes = Array.isArray(f.causes) ? f.causes : [];
            const management = Array.isArray(f.management) ? f.management : [];
            return `
                <div class="ch-ai-condition">
                    <span class="ch-ai-badge ${validSev}">${escHtml(f.name || 'TEMUAN')}</span>
                    <p class="ch-ai-text">${escHtml(f.explanation || '')}</p>
                    ${causes.length ? `<div class="ch-ai-label">Kemungkinan Penyebab</div><div class="ch-ai-text"><ul>${causes.map(c=>`<li>${escHtml(c)}</li>`).join('')}</ul></div>` : ''}
                    ${management.length ? `<div class="ch-ai-label">Saran Penanganan</div><div class="ch-ai-text"><ul>${management.map(m=>`<li>${escHtml(m)}</li>`).join('')}</ul></div>` : ''}
                </div>
            `;
        }).join('');
    }

    function renderHistoryAnalysis(analysis) {
        window.__chLastAiAnalysis = analysis;
        const referralBox = document.getElementById('ch_ai_referral');
        if (analysis.referral && analysis.referral.recommended) {
            referralBox.innerHTML = `
                <div class="ch-ai-referral">
                    <strong>⚠ DISARANKAN RUJUK KE SPESIALIS${analysis.referral.specialist ? ': ' + escHtml(analysis.referral.specialist) : ''}</strong>
                    <p style="margin:6px 0 0">${escHtml(analysis.referral.reason || '')}</p>
                </div>
            `;
        } else {
            referralBox.innerHTML = '';
        }

        let html = '';
        if (analysis.trend_summary) {
            html += `
                <div class="ch-ai-summary-box">
                    <div class="ch-ai-label" style="margin-top:0">Ringkasan Tren</div>
                    <p class="ch-ai-text">${escHtml(analysis.trend_summary)}</p>
                </div>
            `;
        }
        html += buildAiConditions(analysis.main_findings || []);
        if (Array.isArray(analysis.recommendations) && analysis.recommendations.length) {
            html += `
                <div class="ch-ai-summary-box">
                    <div class="ch-ai-label" style="margin-top:0">Rekomendasi</div>
                    <ul class="ch-ai-text" style="padding-left:18px;margin:0">
                        ${analysis.recommendations.map(r => `<li>${escHtml(r)}</li>`).join('')}
                    </ul>
                </div>
            `;
        }
        document.getElementById('ch_ai_result').innerHTML = html;
    }

    window.requestHistoryAIAnalysis = async function() {
        const btn = document.getElementById('ch_ai_btn');
        const label = document.getElementById('ch_ai_btn_label');
        const originalLabel = label.innerHTML;

        btn.disabled = true;
        label.innerHTML = '<span class="ch-ai-spinner"></span> MENGANALISIS...';
        document.getElementById('ch_ai_result').innerHTML = `
            <div style="text-align:center;padding:24px 10px;color:var(--accent-solid);font-size:12px">
                Menganalisis tren riwayat pemeriksaan pelanggan ini...
            </div>
        `;
        document.getElementById('ch_ai_referral').innerHTML = '';

        try {
            const res = await fetch('analyze_customer_history.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(historyPayload)
            });
            const data = await res.json().catch(() => ({ error: 'Respons server tidak valid.' }));

            if (!res.ok || !data.success) {
                throw new Error(data.error || `Server mengembalikan status ${res.status}`);
            }

            renderHistoryAnalysis(data.analysis);

            if (data.meta && (data.meta.input_tokens || data.meta.output_tokens)) {
                document.getElementById('ch_ai_result').innerHTML += `
                    <p class="ch-ai-meta">model: ${escHtml(data.meta.model)} · tokens in/out: ${data.meta.input_tokens || '?'}/${data.meta.output_tokens || '?'}</p>
                `;
            }
        } catch (err) {
            document.getElementById('ch_ai_result').innerHTML = `
                <div class="ch-ai-error">
                    <strong>⚠ ANALISIS AI GAGAL</strong><br>
                    <small>${escHtml(err.message)}</small>
                </div>
            `;
        } finally {
            btn.disabled = false;
            label.innerHTML = originalLabel;
        }
    };
})();
</script>
<?php endif; ?>

<script>
function toggle(id) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('open');
}

function toggleGroup(cardId, headerId) {
    const card = document.getElementById(cardId);
    const header = document.getElementById(headerId);
    if (card) card.classList.toggle('ch-collapsed');
    if (header) header.classList.toggle('open');
}

async function downloadCustomerPdf() {
    const btn = document.getElementById('ch_pdf_btn');
    const label = document.getElementById('ch_pdf_btn_label');
    const original = label.innerHTML;
    const phone = <?= json_encode($customer_data['phone'] ?? '') ?>;

    if (!phone || phone === '—') {
        alert('Nomor telepon customer tidak ditemukan, PDF tidak bisa dibuat.');
        return;
    }

    btn.disabled = true;
    label.innerHTML = '<span class="ch-ai-spinner"></span> MEMBUAT PDF...';

    try {
        const body = new URLSearchParams();
        body.set('phone', phone);
        if (window.__chLastAiAnalysis) {
            body.set('analysis_json', JSON.stringify(window.__chLastAiAnalysis));
        }

        const res = await fetch('generate_customer_pdf.php', { method: 'POST', body });
        const data = await res.json().catch(() => ({ success: false }));

        if (!res.ok || !data.success) {
            throw new Error(data.error || `Gagal membuat PDF (status ${res.status})`);
        }

        window.open(data.file_path, '_blank');
    } catch (err) {
        alert('Gagal membuat PDF: ' + err.message);
    } finally {
        btn.disabled = false;
        label.innerHTML = original;
    }
}

document.addEventListener('DOMContentLoaded', function(){
    // Auto-open most recent exam and order
    const e0 = document.getElementById('ecard-0');
    const o0 = document.getElementById('ocard-0');
    if (e0) e0.classList.add('open');
    if (o0) o0.classList.add('open');
});
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
                // back direction
                window.location.href = 'customer.php';
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