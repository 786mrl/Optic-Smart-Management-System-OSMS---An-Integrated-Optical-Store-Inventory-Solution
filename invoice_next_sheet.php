<?php
// invoice_next_sheet.php
// Returns the next suggested invoice_sheet value based on the
// last saved order in customer_orders.
// Response: JSON { next_sheet: "16.32" }

session_start();
include 'db_config.php';
include 'config_helper.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

// Get the latest invoice_sheet from customer_orders
$result = mysqli_query($conn,
    "SELECT invoice_sheet FROM customer_orders ORDER BY id DESC LIMIT 1");

if (!$result || mysqli_num_rows($result) === 0) {
    // No orders yet — use starting_invoice_number from settings
    $start = getConfig($conn, 'starting_invoice_number', '1.01');
    echo json_encode(['next_sheet' => $start]);
    exit();
}

$row   = mysqli_fetch_assoc($result);
$sheet = $row['invoice_sheet']; // e.g. "16.31"

// Parse: left of dot = invoice number, right = sheet number
$dotPos     = strpos($sheet, '.');
$invoiceNo  = (int)substr($sheet, 0, $dotPos);
$sheetNo    = (int)substr($sheet, $dotPos + 1);

// Increment sheet; reset to 01 and bump invoice number at 50
$sheetNo++;
if ($sheetNo > 50) {
    $invoiceNo++;
    $sheetNo = 1;
}

$nextSheet = $invoiceNo . '.' . str_pad($sheetNo, 2, '0', STR_PAD_LEFT);

echo json_encode(['next_sheet' => $nextSheet]);
