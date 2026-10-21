<?php
// lisani_aos/ajax/update_signature.php
// Edit pola (b): client sudah panggil ajax/verify_password.php lebih dulu
// (set $_SESSION['aos_reverify_at']), endpoint ini tinggal cek via
// _require_reverify.php. Hanya mengubah signature_name/signature_date/
// is_main; company_name/origin (menentukan folder di disk) TIDAK bisa
// diubah di sini (sama seperti activity_title sebelumnya) - lihat
// PROJECT_NOTES.md §8. File di disk tidak disentuh.
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/_require_reverify.php';
aos_require_recent_reverify();

function sanitize_signature_name_for_update(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[\/\\\\]+/', '-', $name);
    $name = preg_replace('/\.\.+/', '.', $name);
    $name = preg_replace('/[^A-Za-z0-9 _\-]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$signatureName = sanitize_signature_name_for_update($_POST['signature_name'] ?? '');
$signatureDate = $_POST['signature_date'] ?? '';
$isMain = (isset($_POST['is_main']) && $_POST['is_main'] === '1') ? 1 : 0;

if ($id <= 0 || $signatureName === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

$dateObj = DateTime::createFromFormat('Y-m-d', $signatureDate);
if (!$dateObj) {
    echo json_encode(['success' => false, 'message' => 'Invalid signature date.']);
    exit;
}

$stmt = $lisani_conn->prepare(
    'UPDATE signature_library SET signature_name = ?, signature_date = ?, is_main = ? WHERE id = ?'
);
$stmt->bind_param('ssii', $signatureName, $signatureDate, $isMain, $id);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to update signature record.']);
    exit;
}

if ($stmt->affected_rows === 0) {
    // Could mean "not found" OR "no actual change" - check existence to
    // distinguish, same spirit as other update_* endpoints in this project.
    $check = $lisani_conn->prepare('SELECT id FROM signature_library WHERE id = ?');
    $check->bind_param('i', $id);
    $check->execute();
    if (!$check->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'message' => 'Signature not found.']);
        exit;
    }
}

echo json_encode(['success' => true]);