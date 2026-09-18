<?php
// lisani_aos/ajax/list_logistic_activities.php
// Returns activity codes for one department (usually "dates", the only
// department Logistic currently supports), each flagged has_logistic so the
// Create New Logistic form can grey out / disable codes that already have a
// logistic record (UNIQUE(activity_id) in `logistics`).
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn

$department = trim($_GET['department'] ?? '');

$departmentsFile = __DIR__ . '/../departments.json';
$departmentsData = json_decode(file_get_contents($departmentsFile), true) ?: ['departments' => []];
$validDeptKeys   = array_column($departmentsData['departments'], 'key');

if (!in_array($department, $validDeptKeys, true)) {
    echo json_encode(['ok' => false, 'message' => 'Departement tidak dikenali.']);
    exit;
}

$stmt = $lisani_conn->prepare(
    "SELECT a.id, a.activity_name, a.relative_path,
            (l.id IS NOT NULL) AS has_logistic
     FROM activities a
     LEFT JOIN logistics l ON l.activity_id = a.id
     WHERE a.relative_path LIKE CONCAT('input/%/', ?, '/%')
     ORDER BY a.id DESC"
);
$stmt->bind_param('s', $department);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($r = $result->fetch_assoc()) {
    $code = null;
    $year = null;
    if (preg_match('#^input/(\d{4})/' . preg_quote($department, '#') . '/(\d+)/$#', $r['relative_path'], $m)) {
        $year = $m[1];
        $code = $m[2];
    }
    $rows[] = [
        'id'            => (int) $r['id'],
        'activity_code' => $code ?? '-',
        'activity_name' => $r['activity_name'],
        'relative_path' => $r['relative_path'],
        'year'          => $year ?? '-',
        'has_logistic'  => (bool) $r['has_logistic'],
    ];
}
$stmt->close();

echo json_encode(['ok' => true, 'data' => $rows]);