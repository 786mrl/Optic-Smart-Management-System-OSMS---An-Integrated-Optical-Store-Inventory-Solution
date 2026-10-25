<?php
// lisani_aos/ajax/update_letterhead.php
// Same as update_signature.php: only letterhead_name/is_main can be
// changed. company_name/origin (which determine the on-disk folder) CANNOT
// be changed here. Header/footer files on disk are left untouched
// (replacing a file means delete + re-upload).
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

function sanitize_letterhead_name_for_update(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[\/\\\\]+/', '-', $name);
    $name = preg_replace('/\.\.+/', '.', $name);
    $name = preg_replace('/[^A-Za-z0-9 _\-]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$letterheadName = sanitize_letterhead_name_for_update($_POST['letterhead_name'] ?? '');
$isMain = (isset($_POST['is_main']) && $_POST['is_main'] === '1') ? 1 : 0;

if ($id <= 0 || $letterheadName === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

$stmt = $lisani_conn->prepare('UPDATE letterhead_library SET letterhead_name = ?, is_main = ? WHERE id = ?');
$stmt->bind_param('sii', $letterheadName, $isMain, $id);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to update letterhead record.']);
    exit;
}

if ($stmt->affected_rows === 0) {
    $check = $lisani_conn->prepare('SELECT id FROM letterhead_library WHERE id = ?');
    $check->bind_param('i', $id);
    $check->execute();
    if (!$check->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'message' => 'Letterhead not found.']);
        exit;
    }
}

echo json_encode(['success' => true]);