<?php
// lisani_aos/ajax/update_activity_code.php
// Updates an existing activity code row (year, departement, activity_name,
// cashflow). Unlike create_activity_code.php, this does NOT re-generate a
// new sequence number — the existing numeric code is kept as-is and simply
// reused inside the new relative_path, so editing a row never collides with
// numbers already handed out to OTHER rows. Only the physical folder
// (input/[year]/[department]/[code]/) is renamed/moved if year and/or
// department changed, same best-effort pattern as update_customer.php.

session_start();

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session expired. Please log in again.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // provides $lisani_conn (mysqli)

header('Content-Type: application/json');

// Same root as create_activity_code.php / create_customer.php.
if (!defined('AOS_STORAGE_BASE')) {
    define('AOS_STORAGE_BASE', dirname(__DIR__) . '/storage');
}

$id           = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$year         = isset($_POST['year']) ? trim($_POST['year']) : '';
$departement  = isset($_POST['departement']) ? trim($_POST['departement']) : '';
$activityName = isset($_POST['activity_name']) ? strtoupper(trim($_POST['activity_name'])) : '';
$cashflow     = isset($_POST['cashflow']) ? trim($_POST['cashflow']) : '';

$validCashflow = ['inflow', 'outflow', 'in-out'];

if ($id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid activity code id.']);
    exit;
}
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

// Fetch the existing row first — needed for the OLD relative_path (to
// extract the existing code number and locate the physical folder) and to
// know whether anything actually changed.
$stmt = $lisani_conn->prepare('SELECT relative_path FROM activities WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing) {
    echo json_encode(['ok' => false, 'message' => 'Activity code not found.']);
    exit;
}

if (!preg_match('#^input/\d{4}/[^/]+/(\d+)/$#', $existing['relative_path'], $m)) {
    echo json_encode(['ok' => false, 'message' => 'Existing relative path is in an unexpected format.']);
    exit;
}
$code = $m[1]; // keep the SAME code number — never re-generated on edit

$newRelativePath = "input/{$year}/{$departement}/{$code}/";

// --- Activity name harus unik, kecuali baris yang sedang diedit sendiri ---
$dupStmt = $lisani_conn->prepare('SELECT id FROM activities WHERE activity_name = ? AND id != ? LIMIT 1');
$dupStmt->bind_param('si', $activityName, $id);
$dupStmt->execute();
$dupStmt->store_result();
if ($dupStmt->num_rows > 0) {
    $dupStmt->close();
    echo json_encode(['ok' => false, 'message' => 'Activity name sudah dipakai, gunakan nama lain.']);
    exit;
}
$dupStmt->close();

// If year/department changed, the new relative_path must not already
// collide with another row's path (e.g. that department+year+code already
// taken by something else — shouldn't normally happen since code numbers
// are per department+year, but guard anyway since this write is manual).
if ($newRelativePath !== $existing['relative_path']) {
    $pathStmt = $lisani_conn->prepare('SELECT id FROM activities WHERE relative_path = ? AND id != ? LIMIT 1');
    $pathStmt->bind_param('si', $newRelativePath, $id);
    $pathStmt->execute();
    $pathStmt->store_result();
    if ($pathStmt->num_rows > 0) {
        $pathStmt->close();
        echo json_encode(['ok' => false, 'message' => 'Target activity code path is already taken.']);
        exit;
    }
    $pathStmt->close();
}

$stmt = $lisani_conn->prepare(
    'UPDATE activities SET activity_name = ?, cashflow = ?, relative_path = ? WHERE id = ?'
);
$stmt->bind_param('sssi', $activityName, $cashflow, $newRelativePath, $id);

if ($stmt->execute()) {
    // Best-effort folder rename/move if the relative_path changed.
    // Never fails the request — reported via 'folder_synced'.
    $folderSynced = true;
    $oldDir = rtrim(AOS_STORAGE_BASE, '/') . '/' . $existing['relative_path'];
    $newDir = rtrim(AOS_STORAGE_BASE, '/') . '/' . $newRelativePath;

    if ($oldDir !== $newDir) {
        if (is_dir($oldDir) && !is_dir($newDir)) {
            $newParentDir = dirname($newDir);
            if (!is_dir($newParentDir)) {
                @mkdir($newParentDir, 0755, true);
            }
            $folderSynced = @rename($oldDir, $newDir);
        } elseif (!is_dir($oldDir)) {
            // Old folder never existed (unusual, but don't block the edit) —
            // just create the new one so it exists going forward.
            $folderSynced = is_dir($newDir) || @mkdir($newDir, 0755, true);
        } elseif (is_dir($oldDir) && is_dir($newDir)) {
            // Both already exist — leave both alone rather than silently
            // deleting/merging, flagged as unsynced for manual check.
            $folderSynced = false;
        }
    }

    echo json_encode([
        'ok'   => true,
        'data' => [
            'id'             => $id,
            'year'           => $year,
            'departement'    => $departement,
            'activity_name'  => $activityName,
            'activity_code'  => $code,
            'cashflow'       => $cashflow,
            'relative_path'  => $newRelativePath,
            'folder_synced'  => $folderSynced,
        ],
    ]);
} else {
    echo json_encode(['ok' => false, 'message' => 'Failed to update activity code.']);
}

$stmt->close();