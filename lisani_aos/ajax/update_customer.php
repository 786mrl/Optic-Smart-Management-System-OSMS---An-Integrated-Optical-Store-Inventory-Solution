<?php
// lisani_aos/ajax/update_customer.php
// Updates an existing customer row (year, customer_name, phone_number).
// total_inflow / total_outflow / profit are NOT editable here — they're
// populated by a separate program later, same rule as create_customer.php.

session_start();

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session expired. Please log in again.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // provides $lisani_conn (mysqli)

header('Content-Type: application/json');

// Must match the base used in create_customer.php / create_activity_code.php
// so renames land in the same place the original folder was created.
if (!defined('AOS_STORAGE_BASE')) {
    define('AOS_STORAGE_BASE', dirname(__DIR__) . '/storage');
}

/**
 * Turn a customer name into a filesystem-safe, lowercase folder name.
 * Strips path separators, ".." traversal, and anything outside
 * letters/digits/space/dash/underscore, then collapses whitespace.
 */
function sanitize_folder_name(string $name): string
{
    $clean = str_replace(['/', '\\'], ' ', $name);
    $clean = preg_replace('/\.\.+/', '', $clean);
    $clean = preg_replace('/[^A-Za-z0-9 _-]/', '', $clean);
    $clean = trim(preg_replace('/\s+/', ' ', $clean));
    return strtolower($clean);
}

$id           = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$year         = isset($_POST['year']) ? trim($_POST['year']) : '';
$customerName = isset($_POST['customer_name']) ? strtoupper(trim($_POST['customer_name'])) : '';
$phoneNumberRaw = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : '';

if ($id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid customer id.']);
    exit;
}
if (!preg_match('/^\d{4}$/', $year)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid year.']);
    exit;
}
if ($customerName === '') {
    echo json_encode(['ok' => false, 'message' => 'Customer name is required.']);
    exit;
}
if ($phoneNumberRaw === '') {
    echo json_encode(['ok' => false, 'message' => 'Phone number is required.']);
    exit;
}

// Same normalization as create_customer.php.
$digitsOnly = preg_replace('/\D/', '', $phoneNumberRaw);
if (strpos($digitsOnly, '628') !== 0) {
    if (strpos($digitsOnly, '0') === 0) {
        $digitsOnly = '62' . substr($digitsOnly, 1);
    } elseif (strpos($digitsOnly, '62') !== 0) {
        $digitsOnly = '62' . $digitsOnly;
    }
}
$phoneNumber = '+' . $digitsOnly;

if (!preg_match('/^\+628\d{7,11}$/', $phoneNumber)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid phone number format.']);
    exit;
}

// Fetch the existing row first — needed to know the OLD year/customer_name
// so the physical folder can be renamed/moved if either changed.
$stmt = $lisani_conn->prepare('SELECT year, customer_name FROM customers WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing) {
    echo json_encode(['ok' => false, 'message' => 'Customer not found.']);
    exit;
}

// Duplicate check (year + customer_name), excluding this row's own id —
// same rule as create_customer.php.
$stmt = $lisani_conn->prepare('SELECT id FROM customers WHERE year = ? AND customer_name = ? AND id != ? LIMIT 1');
$stmt->bind_param('isi', $year, $customerName, $id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $stmt->close();
    echo json_encode(['ok' => false, 'message' => 'This customer already exists for the selected year.']);
    exit;
}
$stmt->close();

$stmt = $lisani_conn->prepare('UPDATE customers SET year = ?, customer_name = ?, phone_number = ? WHERE id = ?');
$stmt->bind_param('issi', $year, $customerName, $phoneNumber, $id);

if ($stmt->execute()) {
    // Best-effort folder rename/move if year or customer_name changed.
    // Never fails the request — reported via 'folder_synced'.
    $folderSynced = true;
    $oldDir = rtrim(AOS_STORAGE_BASE, '/') . '/selling/' . $existing['year'] . '/' . sanitize_folder_name($existing['customer_name']);
    $newDir = rtrim(AOS_STORAGE_BASE, '/') . '/selling/' . $year . '/' . sanitize_folder_name($customerName);

    if ($oldDir !== $newDir) {
        if (is_dir($oldDir) && !is_dir($newDir)) {
            $newYearDir = dirname($newDir);
            if (!is_dir($newYearDir)) {
                @mkdir($newYearDir, 0775, true);
            }
            $folderSynced = @rename($oldDir, $newDir);
        } elseif (!is_dir($oldDir)) {
            // Old folder never existed (e.g. created before this feature) —
            // just create the new one so it exists going forward.
            $folderSynced = is_dir($newDir) || @mkdir($newDir, 0775, true);
        }
        // If both old and new already exist, leave both alone rather than
        // silently deleting/merging — flagged as unsynced for manual check.
        elseif (is_dir($oldDir) && is_dir($newDir)) {
            $folderSynced = false;
        }
    }

    echo json_encode([
        'ok'   => true,
        'data' => [
            'id'             => $id,
            'year'           => $year,
            'customer_name'  => $customerName,
            'phone_number'   => $phoneNumber,
            'folder_synced'  => $folderSynced
        ]
    ]);
} else {
    echo json_encode(['ok' => false, 'message' => 'Failed to update customer.']);
}

$stmt->close();