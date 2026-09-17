<?php
// lisani_aos/ajax/list_logistics.php
// Returns existing logistics rows for the "Logistic List" tab. Only the
// rate columns are stored (primary_qty, unit weights, secondary ratio) —
// totals (primary_total_weight_kg, secondary_qty, secondary_total_weight_kg)
// are calculated here, never stored in the DB.
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

$result = $lisani_conn->query(
    "SELECT l.*, a.activity_name, a.relative_path
     FROM logistics l
     JOIN activities a ON a.id = l.activity_id
     ORDER BY l.id DESC"
);

if ($result === false) {
    echo json_encode(['ok' => false, 'message' => 'Query error: ' . $lisani_conn->error]);
    exit;
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

echo json_encode(['ok' => true, 'data' => $rows]);
