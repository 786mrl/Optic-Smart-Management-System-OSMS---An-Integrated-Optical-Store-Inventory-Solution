<?php
/**
 * custom_frame_save.php
 * Endpoint: POST
 * Menyimpan frame yang tidak ada di frames_main / frame_staging
 * ke tabel custom_frames.
 */
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

include 'db_config.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

if ($action === 'save_custom_frame') {

    $invoice_number = mysqli_real_escape_string($conn, $_POST['invoice_number'] ?? '');
    $brand_key      = mysqli_real_escape_string($conn, $_POST['brand_key']      ?? '');
    $sell_price     = (float)($_POST['sell_price'] ?? 0);
    $frame_size     = mysqli_real_escape_string($conn, $_POST['frame_size']     ?? '');
    $is_purchased   = (int)($_POST['is_purchased']  ?? 1);

    // Basic validation
    if (empty($invoice_number) || empty($brand_key) || $sell_price <= 0) {
        echo json_encode(['success' => false, 'error' => 'invoice_number, brand_key, dan sell_price wajib diisi.']);
        exit();
    }

    // Clamp is_purchased to 0 or 1
    $is_purchased = ($is_purchased >= 1) ? 1 : 0;

    $frame_size_val = !empty($frame_size) ? "'" . $frame_size . "'" : 'NULL';

    $sql = "INSERT INTO custom_frames
                (invoice_number, brand_key, sell_price, frame_size, is_purchased)
            VALUES
                ('$invoice_number', '$brand_key', $sell_price, $frame_size_val, $is_purchased)";

    if (mysqli_query($conn, $sql)) {
        $inserted_id = mysqli_insert_id($conn);
        echo json_encode([
            'success'    => true,
            'id'         => $inserted_id,
            'brand_key'  => $brand_key,
            'sell_price' => $sell_price,
            'frame_size' => $frame_size ?: null,
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    exit();
}

if ($action === 'set_purchased') {

    $invoice_number = mysqli_real_escape_string($conn, $_POST['invoice_number'] ?? '');
    $brand_key      = mysqli_real_escape_string($conn, $_POST['brand_key']      ?? '');
    $is_purchased   = (int)($_POST['is_purchased'] ?? 0);

    if (empty($invoice_number) || empty($brand_key)) {
        echo json_encode(['success' => false, 'error' => 'invoice_number and brand_key are required.']);
        exit();
    }

    $is_purchased = ($is_purchased >= 1) ? 1 : 0;

    $sql = "UPDATE custom_frames
            SET is_purchased = $is_purchased
            WHERE invoice_number = '$invoice_number' AND brand_key = '$brand_key'";

    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'is_purchased' => $is_purchased]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    exit();
}

// Unknown action
echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
exit();