<?php
// lisani_aos/ajax/list_activity_codes.php
// Returns existing activity codes for the "Preview" tab in
// transaction_content.php. Department label + code number are derived
// from relative_path (the table itself doesn't store department/year/code
// as separate columns).
//
// ASSUMPTION: `activities` table has an auto-increment `id` and a
// `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP column, in addition to
// the columns already used by create_activity_code.php (activity_name,
// cashflow, relative_path, created_by). Adjust the SELECT below if your
// actual schema differs.
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
    'SELECT id, activity_name, cashflow, relative_path, created_at FROM activities ORDER BY id DESC'
);

if ($result === false) {
    // Surface the real DB error instead of silently returning an empty
    // list (which used to look identical to "no data yet" in the UI).
    echo json_encode(['ok' => false, 'message' => 'Query error: ' . $lisani_conn->error]);
    exit;
}

$rows = [];
while ($r = $result->fetch_assoc()) {
    $deptKey = null;
    $code    = null;
    if (preg_match('#^input/\d{4}/([^/]+)/(\d+)/$#', $r['relative_path'], $m)) {
        $deptKey = $m[1];
        $code    = $m[2];
    }

    $year = null;
    if (preg_match('#^input/(\d{4})/#', $r['relative_path'], $ym)) {
        $year = $ym[1];
    }

    $rows[] = [
        'id'              => (int) $r['id'],
        'activity_code'   => $code ?? '-',
        'activity_name'   => $r['activity_name'],
        'department'      => $deptLabelByKey[$deptKey] ?? ($deptKey ?? '-'),
        'department_key'  => $deptKey ?? '',
        'cashflow'        => $r['cashflow'],
        'relative_path'   => $r['relative_path'],
        'year'            => $year ?? '',
    ];
}

echo json_encode(['ok' => true, 'data' => $rows]);