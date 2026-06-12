<?php
/**
 * update_exam_invoice.php
 * 
 * Updates the invoice_number of an existing customer_examinations record.
 * Called via POST from rx_only_customer.php when converting an RX-only
 * customer to a paid invoice.
 * 
 * POST params:
 *   exam_id        (int)    - ID of the record in customer_examinations
 *   invoice_number (string) - New invoice number (e.g. "042")
 * 
 * Returns JSON: { "success": true } or { "success": false, "error": "..." }
 */

session_start();
include 'db_config.php';
include 'activity_helper.php';
include 'auth_check.php';

header('Content-Type: application/json');

// Auth guard
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Validate input
$exam_id       = (int)($_POST['exam_id'] ?? 0);
$invoice_number = trim($_POST['invoice_number'] ?? '');

if ($exam_id <= 0 || $invoice_number === '' || $invoice_number === '00') {
    echo json_encode(['success' => false, 'error' => 'Invalid exam_id or invoice_number.']);
    exit();
}

// Safety: make sure the record currently has invoice_number = '00'
$check = $conn->prepare("SELECT id FROM customer_examinations WHERE id = ? AND invoice_number = '00'");
$check->bind_param('i', $exam_id);
$check->execute();
$check->store_result();
if ($check->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Record not found or already has an invoice.']);
    exit();
}
$check->close();

// Update
$stmt = $conn->prepare("UPDATE customer_examinations SET invoice_number = ? WHERE id = ?");
$stmt->bind_param('si', $invoice_number, $exam_id);

if ($stmt->execute()) {
    log_activity($conn, 'customer_examinations', (string)$exam_id, 'UPDATE', $_SESSION['username'] ?? 'staff');
    echo json_encode(['success' => true, 'invoice_number' => $invoice_number]);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}

$stmt->close();
$conn->close();
