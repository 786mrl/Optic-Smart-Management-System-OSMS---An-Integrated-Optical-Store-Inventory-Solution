<?php
// lisani_aos/ajax/list_logistics.php
// Returns existing logistics rows for the "Logistic List" tab. Only the
// rate columns are stored (primary_qty, unit weights, secondary ratio) —
// totals (primary_total_weight_kg, secondary_qty, secondary_total_weight_kg)
// are calculated here, never stored in the DB.
ini_set('display_errors', '0'); // any stray PHP warning/notice would print
ob_start();                     // before the JSON below and break res.json()
                                 // on the frontend — same guard as
                                 // list_customer_orders.php.
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function logListOut(array $payload): void
{
    $noise = ob_get_clean();
    if ($noise !== false && trim($noise) !== '') {
        error_log('list_logistics.php stray output: ' . $noise);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    logListOut(['ok' => false, 'message' => 'Session tidak valid.']);
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn

// Defective-stock columns are from a migration that may not have run yet on
// this DB (migration_defective_and_price_adjustment.sql) — checked ONCE
// here via SHOW COLUMNS, rather than just try/catching the query that uses
// them. A failed query still gets sent to MySQL and can leave the mysqli
// connection object in a state that trips up the NEXT query on the same
// connection under PHP 8.1+'s default exception-throwing mode — so the
// only fully safe fix is to never issue the query at all when the column
// isn't there yet.
function aosColumnExists(mysqli $conn, string $table, string $column): bool
{
    $t = str_replace('`', '', $table);
    $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '" . $conn->real_escape_string($column) . "'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}
$hasDefectiveQty   = aosColumnExists($lisani_conn, 'logistics', 'defective_qty');
// NOTE: there is no separate `restock_bucket` column — create_return.php
// writes the destination bucket for 'in' rows into the SAME `stock_source`
// column used by 'out' rows for the source bucket (see create_return.php,
// "The stock_source column on the inserted 'in' row records restock_bucket").
$hasStockSource    = aosColumnExists($lisani_conn, 'logistic_movements', 'stock_source');

$departmentsFile = __DIR__ . '/../departments.json';
$departmentsData = json_decode(file_get_contents($departmentsFile), true) ?: ['departments' => []];
$deptLabelByKey  = [];
foreach ($departmentsData['departments'] as $d) {
    $deptLabelByKey[$d['key']] = $d['label'];
}

$result = $lisani_conn->query(
    "SELECT l.*, a.activity_name, a.relative_path
     FROM logistics l
     JOIN activities a ON a.id = l.activity_id
     ORDER BY l.id DESC"
);

if ($result === false) {
    logListOut(['ok' => false, 'message' => 'Query error: ' . $lisani_conn->error]);
}

// Document counts per activity_id + type, fetched once and grouped in PHP
// rather than N+1 queries per logistic row.
$docCounts = [];
$docResult = $lisani_conn->query(
    "SELECT activity_id, document_type, COUNT(*) AS total FROM logistic_documents GROUP BY activity_id, document_type"
);
if ($docResult !== false) {
    while ($d = $docResult->fetch_assoc()) {
        $docCounts[(int) $d['activity_id']][$d['document_type']] = (int) $d['total'];
    }
}

// Normal-bucket movements (both 'out' — taken from normal stock — and
// 'in' — returned to normal stock), chronological per logistic_id, used to
// build the "Remaining Primary Qty" ledger below. NULL stock_source is
// treated as normal too: those are legacy rows written before the
// defective-stock column existed, back when everything WAS normal stock.
$normalMovementsByLogistic = [];
$normSql = "SELECT m.logistic_id, m.movement_type, m.movement_date, m.created_at, m.qty_primary_package, c.customer_name
            FROM logistic_movements m
            LEFT JOIN customers c ON c.id = m.customer_id
            WHERE m.movement_type IN ('out','in')"
            . ($hasStockSource ? " AND (m.stock_source = 'normal' OR m.stock_source IS NULL)" : '') . "
            ORDER BY m.logistic_id ASC, m.movement_date ASC, m.created_at ASC, m.id ASC";
$normResult = $lisani_conn->query($normSql);
if ($normResult instanceof mysqli_result) {
    while ($n = $normResult->fetch_assoc()) {
        $normalMovementsByLogistic[(int) $n['logistic_id']][] = $n;
    }
}

// Defective stock return history, per logistic_id: every 'in' movement that
// landed in the defective bucket (stock_source='defective' on the 'in' row
// itself — see note above), newest first, so the "push to expand" row in
// the Logistic List can show who returned the goods and when without a
// second round-trip per row. Only queried at all when the column exists
// (see $hasStockSource above).
$defectiveHistory = [];
if ($hasStockSource) {
    $histResult = $lisani_conn->query(
        "SELECT m.logistic_id, m.movement_date, m.created_at, m.qty_primary_package, c.customer_name
         FROM logistic_movements m
         LEFT JOIN customers c ON c.id = m.customer_id
         WHERE m.movement_type = 'in' AND m.stock_source = 'defective'
         ORDER BY m.movement_date DESC, m.created_at DESC, m.id DESC"
    );
    if ($histResult instanceof mysqli_result) {
        while ($h = $histResult->fetch_assoc()) {
            $defectiveHistory[(int) $h['logistic_id']][] = [
                'customer_name' => $h['customer_name'], // null if the return had no customer attached (legacy rows)
                'movement_date' => $h['movement_date'],
                'created_at'    => $h['created_at'],
                'qty'           => $h['qty_primary_package'],
            ];
        }
    }
}

$rows = [];
while ($r = $result->fetch_assoc()) {
    $deptKey = null;
    $code    = null;
    if (preg_match('#^input/\d{4}/([^/]+)/(\d+)/$#', $r['relative_path'], $m)) {
        $deptKey = $m[1];
        $code    = $m[2];
    }
    $aid = (int) $r['activity_id'];

    // --- Calculated on the fly, never stored ---
    $primaryQty    = $r['primary_qty']              !== null ? (float) $r['primary_qty']              : null;
    $primaryUnitKg = $r['primary_unit_weight_kg']    !== null ? (float) $r['primary_unit_weight_kg']    : null;
    $secondaryKg   = $r['secondary_unit_weight_kg']  !== null ? (float) $r['secondary_unit_weight_kg']  : null;
    $secondaryRatio= $r['secondary_ratio_per_primary'] !== null ? (float) $r['secondary_ratio_per_primary'] : null;

    $primaryTotalWeightKg = ($primaryQty !== null && $primaryUnitKg !== null) ? $primaryQty * $primaryUnitKg : null;
    $secondaryQty         = ($primaryQty !== null && $secondaryRatio !== null) ? $primaryQty * $secondaryRatio : null;
    $secondaryTotalWeightKg = ($secondaryQty !== null && $secondaryKg !== null) ? $secondaryQty * $secondaryKg : null;

    // Defective stock is an independent bucket from remaining_primary_qty
    // (see PROJECT_NOTES.md, "Kolom stok terpisah untuk barang defective")
    // — sums with it here only for display, "how much is sitting in the
    // warehouse right now regardless of bucket". Gated on $hasDefectiveQty
    // (checked once via SHOW COLUMNS above) rather than touching
    // $r['defective_qty'] directly, so a DB that hasn't run the migration
    // yet never even tries to read a column that isn't there.
    $defectiveQtyRaw = $hasDefectiveQty ? $r['defective_qty'] : null;
    $defectiveQty     = $defectiveQtyRaw !== null ? (float) $defectiveQtyRaw : 0.0;
    $remainingQty     = $r['remaining_primary_qty'] !== null ? (float) $r['remaining_primary_qty'] : null;
    $totalAllStock    = $remainingQty !== null ? $remainingQty + $defectiveQty : null;

    // ---- Normal-stock ledger for the "Remaining Primary Qty" fly window ----
    // Steps, in order:
    //   1. 'initial'      — primary_qty as received, dated incoming_date.
    //   2/3. repeating pair, one per normal return, oldest first:
    //        'taken_so_far' — how much is still out with customers right
    //                         before this return comes back (net of any
    //                         earlier returns already subtracted), dated at
    //                         the most recent take before this return.
    //        'return'       — the return itself: qty, who, date + time.
    //   4. 'final'         — remaining_primary_qty (authoritative DB value),
    //                        dated at whichever normal movement (take or
    //                        return) happened last. If there were never any
    //                        normal movements at all, this repeats the
    //                        initial date with no time.
    $normalLedger = [
        ['type' => 'initial', 'qty' => $r['primary_qty'], 'date' => $r['incoming_date'], 'time' => null],
    ];
    $movs = $normalMovementsByLogistic[(int) $r['id']] ?? [];
    $runningOut  = 0.0;
    $lastOutDate = null;
    $lastOutTime = null;
    $lastAnyDate = $r['incoming_date'];
    $lastAnyTime = null;
    foreach ($movs as $mv) {
        $mvQty = (float) $mv['qty_primary_package'];
        if ($mv['movement_type'] === 'out') {
            $runningOut += $mvQty;
            $lastOutDate = $mv['movement_date'];
            $lastOutTime = $mv['created_at'];
            $lastAnyDate = $mv['movement_date'];
            $lastAnyTime = $mv['created_at'];
        } else { // 'in' = normal return
            $normalLedger[] = [
                'type' => 'taken_so_far',
                'qty'  => $runningOut,
                'date' => $lastOutDate,
                'time' => $lastOutTime,
            ];
            $normalLedger[] = [
                'type'          => 'return',
                'qty'           => $mvQty,
                'customer_name' => $mv['customer_name'],
                'date'          => $mv['movement_date'],
                'time'          => $mv['created_at'],
            ];
            $runningOut -= $mvQty;
            $lastAnyDate = $mv['movement_date'];
            $lastAnyTime = $mv['created_at'];
        }
    }
    $normalLedger[] = [
        'type' => 'final',
        'qty'  => $r['remaining_primary_qty'],
        'date' => $lastAnyDate,
        'time' => $lastAnyTime,
    ];

    $rows[] = [
        'id'                         => (int) $r['id'],
        'activity_id'                => $aid,
        'activity_code'              => $code ?? '-',
        'activity_name'              => $r['activity_name'],
        'department'                 => $deptLabelByKey[$deptKey] ?? ($deptKey ?? '-'),
        'incoming_date'              => $r['incoming_date'],
        'primary_qty'                => $r['primary_qty'],
        'primary_unit_label'         => $r['primary_unit_label'],
        'primary_total_weight_kg'    => $primaryTotalWeightKg,
        'remaining_primary_qty'      => $r['remaining_primary_qty'],
        'normal_stock_ledger'        => $normalLedger,
        'defective_qty'              => $defectiveQtyRaw,
        'total_all_stock'            => $totalAllStock,
        'defective_history'          => $defectiveHistory[(int) $r['id']] ?? [],
        'secondary_qty'              => $secondaryQty,
        'secondary_unit_label'       => $r['secondary_unit_label'],
        'secondary_total_weight_kg'  => $secondaryTotalWeightKg,
        'documents' => [
            'shipper'   => $docCounts[$aid]['shipper']   ?? 0,
            'custom'    => $docCounts[$aid]['custom']    ?? 0,
            'consignee' => $docCounts[$aid]['consignee'] ?? 0,
        ],
    ];
}

logListOut(['ok' => true, 'data' => $rows]);