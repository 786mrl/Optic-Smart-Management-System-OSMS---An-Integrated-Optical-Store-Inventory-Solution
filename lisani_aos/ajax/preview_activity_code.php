<?php
// lisani_aos/ajax/preview_activity_code.php
// Read-only preview of the NEXT activity code + relative path for a given
// year + department. Numbering logic mirrors create_activity_code.php
// exactly (same query, same padding) so the preview always matches what
// will actually be saved. Does NOT create a folder or write to the DB.
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn

$year        = trim($_GET['year'] ?? $_POST['year'] ?? '');
$departement = trim($_GET['departement'] ?? $_POST['departement'] ?? '');

if (!preg_match('/^\d{4}$/', $year)) {
    echo json_encode(['ok' => false, 'message' => 'Year tidak valid.']);
    exit;
}

$departmentsFile = __DIR__ . '/../departments.json';
$departmentsData = json_decode(file_get_contents($departmentsFile), true) ?: ['departments' => []];
$validDeptKeys   = array_column($departmentsData['departments'], 'key');

if (!in_array($departement, $validDeptKeys, true)) {
    echo json_encode(['ok' => false, 'message' => 'Departement tidak dikenali.']);
    exit;
}

// --- Sama persis dengan logika di create_activity_code.php ---
$basePath = "input/{$year}/{$departement}/";

$stmt = $lisani_conn->prepare("SELECT relative_path FROM activities WHERE relative_path LIKE CONCAT(?, '%')");
$likeBase = $basePath;
$stmt->bind_param('s', $likeBase);
$stmt->execute();
$result = $stmt->get_result();

$maxNumber = 0;
while ($row = $result->fetch_assoc()) {
    if (preg_match('#^' . preg_quote($basePath, '#') . '(\d+)/$#', $row['relative_path'], $m)) {
        $maxNumber = max($maxNumber, (int) $m[1]);
    }
}
$stmt->close();

$nextNumber = $maxNumber + 1;
$code       = str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);

$relativePath = $basePath . $code . '/';

echo json_encode([
    'ok' => true,
    'data' => [
        'activity_code' => $code,
        'relative_path' => $relativePath,
    ],
]);