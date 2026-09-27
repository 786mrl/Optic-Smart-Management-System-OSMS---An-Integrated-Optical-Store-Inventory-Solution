<?php
// lisani_aos/ajax/list_logistic_movements.php
// Returns `logistic_movements` history for the Logistic menu's "Movements"
// tab, grouped: one entry per logistic (= per activity code) -> Year ->
// Month -> Day, each level carrying its own total taken (movement_type
// 'out') / total returned (movement_type 'in') qty + IDR value, so the UI
// can render nested collapsible cards without re-summing on the client.
//
// Mirrors the query/response conventions of list_logistics.php (same
// activity_code parsing from `activities.relative_path`, same department
// label lookup from departments.json).
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn

$departmentsFile = __DIR__ . '/../departments.json';
$departmentsData = json_decode(file_get_contents($departmentsFile), true) ?: ['departments' => []];
$deptLabelByKey  = [];
foreach ($departmentsData['departments'] as $d) {
    $deptLabelByKey[$d['key']] = $d['label'];
}

// One flat query, grouped in PHP below — easier to keep correct than
// nested SQL GROUP BY across 3 levels, and the row count per logistic is
// small (movement history, not raw transactional volume).
$result = $lisani_conn->query(
    "SELECT m.id, m.logistic_id, m.movement_type, m.movement_date,
            m.customer_id, m.customer_name, m.driver_name, m.police_number,
            m.qty_primary_package, m.price, m.total_price, m.created_at,
            m.stock_source,
            l.activity_id, l.primary_unit_label, l.primary_qty, l.remaining_primary_qty,
            l.total_taken_qty,
            a.activity_name, a.relative_path
     FROM logistic_movements m
     JOIN logistics l ON l.id = m.logistic_id
     JOIN activities a ON a.id = l.activity_id
     ORDER BY m.logistic_id, m.movement_date, m.created_at, m.id"
);

if ($result === false) {
    echo json_encode(['ok' => false, 'message' => 'Query error: ' . $lisani_conn->error]);
    exit;
}

// logistics[logistic_id] = ['meta'=>..., 'years'=>[year=>['months'=>[month=>['days'=>[day=>['movements'=>[...]]]]]]]]
$logistics = [];

while ($r = $result->fetch_assoc()) {
    $lid = (int) $r['logistic_id'];

    if (!isset($logistics[$lid])) {
        $deptKey = null;
        $code    = null;
        if (preg_match('#^input/\d{4}/([^/]+)/(\d+)/$#', $r['relative_path'], $m)) {
            $deptKey = $m[1];
            $code    = $m[2];
        }
        $logistics[$lid] = [
            'logistic_id'            => $lid,
            'activity_code'          => $code ?? '-',
            'activity_name'          => $r['activity_name'],
            'department'             => $deptLabelByKey[$deptKey] ?? ($deptKey ?? '-'),
            'primary_unit_label'     => $r['primary_unit_label'],
            'primary_qty'            => $r['primary_qty'] !== null ? (float) $r['primary_qty'] : null,
            'remaining_primary_qty'  => $r['remaining_primary_qty'] !== null ? (float) $r['remaining_primary_qty'] : null,
            // Source-agnostic "currently with customers" balance — kept in
            // sync by create_order.php (+) / create_return.php (-) for BOTH
            // stock_source buckets (normal + defective), and left untouched
            // by create_price_adjustment.php. Unlike remaining_primary_qty
            // (which only ever reflects the 'normal' bucket), this is the
            // correct thing to validate Actual Taken against — see
            // PROJECT_NOTES.md, "Bugfix: logMovValidTag pakai field salah
            // (defective stock)".
            'total_taken_qty'        => $r['total_taken_qty'] !== null ? (float) $r['total_taken_qty'] : null,
            'years'                  => [], // year(string) => [...]
        ];
    }

    $dt    = strtotime($r['movement_date']);
    $year  = date('Y', $dt);
    $month = date('m', $dt);
    $day   = date('d', $dt);

    if (!isset($logistics[$lid]['years'][$year])) {
        $logistics[$lid]['years'][$year] = ['months' => []];
    }
    $yRef = &$logistics[$lid]['years'][$year];

    if (!isset($yRef['months'][$month])) {
        $yRef['months'][$month] = ['months_label' => date('F', $dt), 'days' => []];
    }
    $mRef = &$yRef['months'][$month];

    if (!isset($mRef['days'][$day])) {
        $mRef['days'][$day] = ['date_label' => date('D, j M Y', $dt), 'movements' => []];
    }
    $dRef = &$mRef['days'][$day];

    $dRef['movements'][] = [
        'id'            => (int) $r['id'],
        'movement_type' => $r['movement_type'], // 'out' = taken, 'in' = returned, 'price_adjustment' = kept by customer, price reduced (no stock movement)
        'qty'           => (float) $r['qty_primary_package'],
        'price'         => $r['price'] !== null ? (float) $r['price'] : null,
        'total_price'   => $r['total_price'] !== null ? (float) $r['total_price'] : null,
        // For 'out' rows this is the pick source ('normal'/'defective'); for
        // 'in' rows create_return.php stores the chosen restock_bucket in
        // this same column (see PROJECT_NOTES.md, "Bugfix: logMovValidTag
        // pakai field salah (defective stock)", line ~3036). Used to derive
        // total_out_normal/total_in_normal below, which is the only figure
        // remaining_primary_qty can validly be checked against — that
        // column only ever reflects the 'normal' bucket.
        'stock_source'  => $r['stock_source'],
        'customer_id'   => $r['customer_id'] !== null ? (int) $r['customer_id'] : null,
        'customer_name' => $r['customer_name'],
        'driver_name'   => $r['driver_name'],
        'police_number' => $r['police_number'],
        'created_at'    => $r['created_at'],
    ];

    unset($dRef, $mRef, $yRef);
}

// ---- Roll totals up from movements -> day -> month -> year -> logistic,
// then re-sort each level newest-first (query above was ASC so summing
// naturally follows chronological order first, sort happens after).
//
// Only 'out' (Taken) and 'in' (Returned) move stock, so only those two feed
// total_out/total_in's QTY. 'price_adjustment' (added 22 Sep 2026 — see
// "Kendala #1 & #2" in PROJECT_NOTES.md) moves NO stock at all: the goods
// stay with the customer, only the price changes. It must be explicitly
// excluded from total_out/total_in — treating "not 'in'" as 'out' (the
// previous bucketing) silently added a price adjustment's qty AND its
// discount value into Taken, inflating both Taken and Actual Taken
// (= Taken - Returned) for no real pickup. A price_adjustment row is still
// returned in each day's `movements` list further down either way.
//
// Its VALUE, however, DOES belong in Actual Taken's financial figure: a
// price adjustment lowers what the customer owes for goods already taken,
// same as transaction_content.php's Sales Transaction > Customers card does
// with "Total Actual = Total Ordered - Total Returned - Total Discounts".
// So price_adjustment value is summed separately here (total_adjustment)
// and subtracted from Actual Taken's value on the frontend — see
// logMovActual() in logistic_content.php — while its qty is never summed
// anywhere (kept 0), since goods never left the customer to begin with.
function rt_sum_movements(array $movements): array {
    $out = ['qty' => 0.0, 'value' => 0.0];
    $in  = ['qty' => 0.0, 'value' => 0.0];
    $adj = ['qty' => 0.0, 'value' => 0.0]; // qty always 0 — informational value-only bucket
    // Qty-only, 'normal' bucket alone — the only thing remaining_primary_qty
    // can be validated against (see comment on 'stock_source' above and
    // logMovNormalValidTag() in logistic_content.php).
    $outNormal = 0.0;
    $inNormal  = 0.0;
    foreach ($movements as $mv) {
        if ($mv['movement_type'] === 'out') {
            $out['qty']   += $mv['qty'];
            $out['value'] += (float) ($mv['total_price'] ?? 0);
            if (($mv['stock_source'] ?? 'normal') === 'normal') $outNormal += $mv['qty'];
        } elseif ($mv['movement_type'] === 'in') {
            $in['qty']   += $mv['qty'];
            $in['value'] += (float) ($mv['total_price'] ?? 0);
            if (($mv['stock_source'] ?? 'normal') === 'normal') $inNormal += $mv['qty'];
        } elseif ($mv['movement_type'] === 'price_adjustment') {
            $adj['value'] += (float) ($mv['total_price'] ?? 0);
        }
    }
    return [
        'total_out' => $out, 'total_in' => $in, 'total_adjustment' => $adj,
        'total_out_normal_qty' => $outNormal, 'total_in_normal_qty' => $inNormal,
    ];
}

// Per (logistic_id, customer_id) breakdown — lets the UI show, inside each
// activity code's Validation card, a Taken/Returned/Discount/Actual line
// per customer alongside a Valid/Invalid badge for that pair. Built from
// the same $logistics structure above (no second query), so it can never
// disagree with the day/month/year totals already computed from the same
// rows. Kept in insertion order (first-seen = earliest movement date, per
// the ASC query), re-sorted below by name for display.
function rt_sum_by_customer(array $logisticEntry): array {
    $byCustomer = []; // customer_id => ['customer_name'=>, 'total_out'=>, 'total_in'=>, 'total_adjustment'=>]
    foreach ($logisticEntry['years'] as $yData) {
        foreach ($yData['months'] as $mData) {
            foreach ($mData['days'] as $dData) {
                foreach ($dData['movements'] as $mv) {
                    $cid = $mv['customer_id'] !== null ? $mv['customer_id'] : 0; // 0 = no customer_id (legacy/edge rows, if any)
                    if (!isset($byCustomer[$cid])) {
                        $byCustomer[$cid] = [
                            'customer_id'   => $mv['customer_id'],
                            'customer_name' => $mv['customer_name'],
                            'total_out'        => ['qty' => 0.0, 'value' => 0.0],
                            'total_in'         => ['qty' => 0.0, 'value' => 0.0],
                            'total_adjustment' => ['qty' => 0.0, 'value' => 0.0],
                        ];
                    }
                    if ($mv['movement_type'] === 'out') {
                        $byCustomer[$cid]['total_out']['qty']   += $mv['qty'];
                        $byCustomer[$cid]['total_out']['value'] += (float) ($mv['total_price'] ?? 0);
                    } elseif ($mv['movement_type'] === 'in') {
                        $byCustomer[$cid]['total_in']['qty']   += $mv['qty'];
                        $byCustomer[$cid]['total_in']['value'] += (float) ($mv['total_price'] ?? 0);
                    } elseif ($mv['movement_type'] === 'price_adjustment') {
                        $byCustomer[$cid]['total_adjustment']['value'] += (float) ($mv['total_price'] ?? 0);
                    }
                }
            }
        }
    }
    $list = array_values($byCustomer);
    usort($list, function ($a, $b) { return strcasecmp($a['customer_name'] ?? '', $b['customer_name'] ?? ''); });
    return $list;
}

$data = [];
foreach ($logistics as $lid => $lg) {
    $logOut = ['qty' => 0.0, 'value' => 0.0];
    $logIn  = ['qty' => 0.0, 'value' => 0.0];
    $logAdj = ['qty' => 0.0, 'value' => 0.0];
    $logOutNormal = 0.0;
    $logInNormal  = 0.0;

    $years = [];
    krsort($lg['years']); // newest year first
    foreach ($lg['years'] as $year => $yData) {
        $yearOut = ['qty' => 0.0, 'value' => 0.0];
        $yearIn  = ['qty' => 0.0, 'value' => 0.0];
        $yearAdj = ['qty' => 0.0, 'value' => 0.0];

        $yearOutNormal = 0.0;
        $yearInNormal  = 0.0;

        $months = [];
        krsort($yData['months']); // newest month first
        foreach ($yData['months'] as $month => $mData) {
            $monthOut = ['qty' => 0.0, 'value' => 0.0];
            $monthIn  = ['qty' => 0.0, 'value' => 0.0];
            $monthAdj = ['qty' => 0.0, 'value' => 0.0];
            $monthOutNormal = 0.0;
            $monthInNormal  = 0.0;

            $days = [];
            krsort($mData['days']); // newest day first
            foreach ($mData['days'] as $day => $dData) {
                $sums = rt_sum_movements($dData['movements']);
                $monthOut['qty']   += $sums['total_out']['qty'];
                $monthOut['value'] += $sums['total_out']['value'];
                $monthIn['qty']    += $sums['total_in']['qty'];
                $monthIn['value']  += $sums['total_in']['value'];
                $monthAdj['value'] += $sums['total_adjustment']['value'];
                $monthOutNormal    += $sums['total_out_normal_qty'];
                $monthInNormal     += $sums['total_in_normal_qty'];

                // newest movement first within the day
                $movs = $dData['movements'];
                usort($movs, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });

                $days[] = [
                    'day'                  => $day,
                    'date_label'           => $dData['date_label'],
                    'total_out'            => $sums['total_out'],
                    'total_in'             => $sums['total_in'],
                    'total_adjustment'     => $sums['total_adjustment'],
                    'total_out_normal_qty' => $sums['total_out_normal_qty'],
                    'total_in_normal_qty'  => $sums['total_in_normal_qty'],
                    'movements'            => $movs,
                ];
            }

            $yearOut['qty']   += $monthOut['qty'];
            $yearOut['value'] += $monthOut['value'];
            $yearIn['qty']    += $monthIn['qty'];
            $yearIn['value']  += $monthIn['value'];
            $yearAdj['value'] += $monthAdj['value'];
            $yearOutNormal    += $monthOutNormal;
            $yearInNormal     += $monthInNormal;

            $months[] = [
                'month'                => $month,
                'month_label'          => $mData['months_label'],
                'total_out'            => $monthOut,
                'total_in'             => $monthIn,
                'total_adjustment'     => $monthAdj,
                'total_out_normal_qty' => $monthOutNormal,
                'total_in_normal_qty'  => $monthInNormal,
                'days'                 => $days,
            ];
        }

        $logOut['qty']   += $yearOut['qty'];
        $logOut['value'] += $yearOut['value'];
        $logIn['qty']    += $yearIn['qty'];
        $logIn['value']  += $yearIn['value'];
        $logAdj['value'] += $yearAdj['value'];
        $logOutNormal    += $yearOutNormal;
        $logInNormal     += $yearInNormal;

        $years[] = [
            'year'                 => $year,
            'total_out'            => $yearOut,
            'total_in'             => $yearIn,
            'total_adjustment'     => $yearAdj,
            'total_out_normal_qty' => $yearOutNormal,
            'total_in_normal_qty'  => $yearInNormal,
            'months'               => $months,
        ];
    }

    $data[] = [
        'logistic_id'           => $lg['logistic_id'],
        'activity_code'         => $lg['activity_code'],
        'activity_name'         => $lg['activity_name'],
        'department'            => $lg['department'],
        'primary_unit_label'    => $lg['primary_unit_label'],
        'primary_qty'           => $lg['primary_qty'],
        'remaining_primary_qty' => $lg['remaining_primary_qty'],
        'total_taken_qty'       => $lg['total_taken_qty'],
        'total_out'             => $logOut,
        'total_in'              => $logIn,
        'total_adjustment'      => $logAdj,
        // Qty moved through the 'normal' bucket only — the figure
        // remaining_primary_qty can actually be checked against (see
        // logMovNormalValidTag() in logistic_content.php).
        'total_out_normal_qty'  => $logOutNormal,
        'total_in_normal_qty'   => $logInNormal,
        // Per-customer breakdown for the activity code's Validation card —
        // see rt_sum_by_customer() above.
        'by_customer'           => rt_sum_by_customer($lg),
        'years'                 => $years,
    ];
}

// Newest activity first (highest logistic_id = most recently created).
usort($data, function ($a, $b) { return $b['logistic_id'] <=> $a['logistic_id']; });

echo json_encode(['ok' => true, 'data' => $data]);