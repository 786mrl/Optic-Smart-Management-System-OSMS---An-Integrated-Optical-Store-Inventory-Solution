<?php
// lisani_aos/ajax/create_customer.php
// Inserts a new customer row. Only year, customer_name, phone_number are
// accepted here — total_inflow / total_outflow / profit are populated by a
// separate program later and default to 0 in the DB.

session_start();

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session expired. Please log in again.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // provides $lisani_conn (mysqli)

header('Content-Type: application/json');

$year         = isset($_POST['year']) ? trim($_POST['year']) : '';
$customerName = isset($_POST['customer_name']) ? strtoupper(trim($_POST['customer_name'])) : '';
$phoneNumberRaw = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : '';

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

// Client always sends the number pre-formatted as "+62 8 xxxx xxxx xxx"
// (see clPhoneNumber logic in transaction_content.php). Normalize here by
// stripping everything but digits and re-adding the leading "+", so what's
// stored in the DB is always a clean "+628xxxxxxxxxx" — independent of
// whatever spacing/grouping the client happened to send.
$digitsOnly = preg_replace('/\D/', '', $phoneNumberRaw);
if (strpos($digitsOnly, '628') !== 0) {
    // Safety net in case this endpoint is ever hit with a raw/legacy
    // format (e.g. "081234567890" or "81234567890") instead of the
    // client's "+62 8..." format.
    if (strpos($digitsOnly, '0') === 0) {
        $digitsOnly = '62' . substr($digitsOnly, 1);
    } elseif (strpos($digitsOnly, '62') !== 0) {
        $digitsOnly = '62' . $digitsOnly;
    }
}
$phoneNumber = '+' . $digitsOnly;

// Indonesian mobile numbers: "+628" followed by 7-11 more digits (total
// 10-14 digits after the "+"). Reject anything outside that shape.
if (!preg_match('/^\+628\d{7,11}$/', $phoneNumber)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid phone number format.']);
    exit;
}

// Server-side safety net for the same client-side check in
// transaction_content.php: same customer name + same year is not allowed.
$stmt = $lisani_conn->prepare('SELECT id FROM customers WHERE year = ? AND customer_name = ? LIMIT 1');
$stmt->bind_param('is', $year, $customerName);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $stmt->close();
    echo json_encode(['ok' => false, 'message' => 'This customer already exists for the selected year.']);
    exit;
}
$stmt->close();

$stmt = $lisani_conn->prepare('INSERT INTO customers (year, customer_name, phone_number, total_inflow, total_outflow, profit) VALUES (?, ?, ?, 0, 0, 0)');
$stmt->bind_param('iss', $year, $customerName, $phoneNumber);

if ($stmt->execute()) {
    echo json_encode([
        'ok'   => true,
        'data' => [
            'id'            => $stmt->insert_id,
            'year'          => $year,
            'customer_name' => $customerName,
            'phone_number'  => $phoneNumber
        ]
    ]);
} else {
    echo json_encode(['ok' => false, 'message' => 'Failed to save customer.']);
}

$stmt->close();