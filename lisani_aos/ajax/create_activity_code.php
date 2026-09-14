<?php
// lisani_aos/ajax/create_activity_code.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

// Wajib sudah re-verify password lewat verify_password.php, dan masih "fresh" (5 menit).
$reverifyAt = $_SESSION['aos_reverify_activity_code'] ?? 0;
if (time() - $reverifyAt > 300) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Verifikasi password kedaluwarsa, silakan ulangi.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn

// --- ADJUST INI kalau lokasi folder penyimpanan fisik berbeda ---
// Saat ini: lisani_aos/storage/input/{year}/{department}/{code}/
define('AOS_STORAGE_BASE', dirname(__DIR__) . '/storage');
// ------------------------------------------------------------------

$year         = trim($_POST['year'] ?? '');
$departement  = trim($_POST['departement'] ?? '');
$activityName = strtoupper(trim($_POST['activity_name'] ?? '')); // always stored uppercase
$cashflow     = trim($_POST['cashflow'] ?? '');

$validCashflow = ['inflow', 'outflow', 'in-out'];

// Validasi dasar
if (!preg_match('/^\d{4}$/', $year)) {
    echo json_encode(['ok' => false, 'message' => 'Year tidak valid.']);
    exit;
}
if ($activityName === '' || mb_strlen($activityName) > 150) {
    echo json_encode(['ok' => false, 'message' => 'Activity name tidak valid.']);
    exit;
}
if (!in_array($cashflow, $validCashflow, true)) {
    echo json_encode(['ok' => false, 'message' => 'Cashflow tidak valid.']);
    exit;
}

// Departemen harus salah satu key yang terdaftar di departments.json
// (mencegah path traversal / departemen liar masuk ke relative_path).
$departmentsFile = __DIR__ . '/../departments.json';
$departmentsData = json_decode(file_get_contents($departmentsFile), true) ?: ['departments' => []];
$validDeptKeys   = array_column($departmentsData['departments'], 'key');

if (!in_array($departement, $validDeptKeys, true)) {
    echo json_encode(['ok' => false, 'message' => 'Departement tidak dikenali.']);
    exit;
}

// --- Hitung nomor kode berikutnya, reset per departemen + tahun ---
$basePath = "input/{$year}/{$departement}/";

$stmt = $lisani_conn->prepare("SELECT relative_path FROM activities WHERE relative_path LIKE CONCAT(?, '%')");
$likeBase = $basePath;
$stmt->bind_param('s', $likeBase);
$stmt->execute();
$result = $stmt->get_result();

$maxNumber = 0;
while ($row = $result->fetch_assoc()) {
    // relative_path pola: input/{year}/{dept}/{code}/
    if (preg_match('#^' . preg_quote($basePath, '#') . '(\d+)/$#', $row['relative_path'], $m)) {
        $maxNumber = max($maxNumber, (int) $m[1]);
    }
}
$stmt->close();

$nextNumber = $maxNumber + 1;
$code       = str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT); // 001, 002, ... auto-lebar jika >999

$relativePath = $basePath . $code . '/';

// --- Buat folder fisik ---
$fullPath = rtrim(AOS_STORAGE_BASE, '/') . '/' . $relativePath;
if (!is_dir($fullPath)) {
    if (!mkdir($fullPath, 0755, true) && !is_dir($fullPath)) {
        echo json_encode(['ok' => false, 'message' => 'Gagal membuat folder di server.']);
        exit;
    }
}

// --- Simpan ke DB ---
$stmt = $lisani_conn->prepare(
    'INSERT INTO activities (activity_name, cashflow, relative_path, created_by) VALUES (?, ?, ?, ?)'
);
$stmt->bind_param('sssi', $activityName, $cashflow, $relativePath, $_SESSION['user_id']);

if (!$stmt->execute()) {
    // Kemungkinan besar race condition pada UNIQUE(relative_path) — folder sudah kebuat tapi baris gagal.
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan ke database. Coba lagi.']);
    exit;
}
$stmt->close();

// Re-verify hanya berlaku sekali pakai per create.
unset($_SESSION['aos_reverify_activity_code']);

echo json_encode([
    'ok' => true,
    'data' => [
        'year'          => $year,
        'departement'   => $departement,
        'activity_name' => $activityName,
        'activity_code' => $code,
        'cashflow'      => $cashflow,
        'relative_path' => $relativePath,
    ],
]);