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
            m.customer_name, m.driver_name, m.police_number,
            m.qty_primary_package, m.price, m.total_price, m.created_at,
            l.activity_id, l.primary_unit_label, l.primary_qty, l.remaining_primary_qty,
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
        'movement_type' => $r['movement_type'], // 'out' = taken, 'in' = returned
        'qty'           => (float) $r['qty_primary_package'],
        'price'         => $r['price'] !== null ? (float) $r['price'] : null,
        'total_price'   => $r['total_price'] !== null ? (float) $r['total_price'] : null,
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
function rt_sum_movements(array $movements): array {
    $out = ['qty' => 0.0, 'value' => 0.0];
    $in  = ['qty' => 0.0, 'value' => 0.0];
    foreach ($movements as $mv) {
        $bucket = $mv['movement_type'] === 'in' ? $in : $out;
        $bucket['qty']   += $mv['qty'];
        $bucket['value'] += (float) ($mv['total_price'] ?? 0);
        if ($mv['movement_type'] === 'in') { $in = $bucket; } else { $out = $bucket; }
    }
    return ['total_out' => $out, 'total_in' => $in];
}

$data = [];
foreach ($logistics as $lid => $lg) {
    $logOut = ['qty' => 0.0, 'value' => 0.0];
    $logIn  = ['qty' => 0.0, 'value' => 0.0];

    $years = [];
    krsort($lg['years']); // newest year first
    foreach ($lg['years'] as $year => $yData) {
        $yearOut = ['qty' => 0.0, 'value' => 0.0];
        $yearIn  = ['qty' => 0.0, 'value' => 0.0];

        $months = [];
        krsort($yData['months']); // newest month first
        foreach ($yData['months'] as $month => $mData) {
            $monthOut = ['qty' => 0.0, 'value' => 0.0];
            $monthIn  = ['qty' => 0.0, 'value' => 0.0];

            $days = [];
            krsort($mData['days']); // newest day first
            foreach ($mData['days'] as $day => $dData) {
                $sums = rt_sum_movements($dData['movements']);
                $monthOut['qty']   += $sums['total_out']['qty'];
                $monthOut['value'] += $sums['total_out']['value'];
                $monthIn['qty']    += $sums['total_in']['qty'];
                $monthIn['value']  += $sums['total_in']['value'];

                // newest movement first within the day
                $movs = $dData['movements'];
                usort($movs, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });

                $days[] = [
                    'day'        => $day,
                    'date_label' => $dData['date_label'],
                    'total_out'  => $sums['total_out'],
                    'total_in'   => $sums['total_in'],
                    'movements'  => $movs,
                ];
            }

            $yearOut['qty']   += $monthOut['qty'];
            $yearOut['value'] += $monthOut['value'];
            $yearIn['qty']    += $monthIn['qty'];
            $yearIn['value']  += $monthIn['value'];

            $months[] = [
                'month'       => $month,
                'month_label' => $mData['months_label'],
                'total_out'   => $monthOut,
                'total_in'    => $monthIn,
                'days'        => $days,
            ];
        }

        $logOut['qty']   += $yearOut['qty'];
        $logOut['value'] += $yearOut['value'];
        $logIn['qty']    += $yearIn['qty'];
        $logIn['value']  += $yearIn['value'];

        $years[] = [
            'year'      => $year,
            'total_out' => $yearOut,
            'total_in'  => $yearIn,
            'months'    => $months,
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
        'total_out'             => $logOut,
        'total_in'              => $logIn,
        'years'                 => $years,
    ];
}

// Newest activity first (highest logistic_id = most recently created).
usort($data, function ($a, $b) { return $b['logistic_id'] <=> $a['logistic_id']; });

echo json_encode(['ok' => true, 'data' => $data]);