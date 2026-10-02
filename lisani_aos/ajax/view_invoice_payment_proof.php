<?php
// lisani_aos/ajax/view_invoice_payment_proof.php
// Streams one invoice payment's proof file (set via create_invoice_payment.php)
// so it can be opened in a new tab from the "View proof of payment" link in
// transaction_content.php (Sales Transaction > Customers > invoice).
//
// GET: payment_id
// On success: the raw file bytes with the correct Content-Type.
// On failure: plain-text error + matching HTTP status (this is opened
// directly by the browser, not fetched as JSON, so no aos_json() here).

ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('AOS_STORAGE_BASE')) {
    define('AOS_STORAGE_BASE', dirname(__DIR__) . '/storage');
}

function deny(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    deny(401, 'Session expired. Please log in again.');
}

require_once dirname(__DIR__) . '/db_config.php'; // provides $lisani_conn (mysqli)

$paymentId = (int) ($_GET['payment_id'] ?? 0);
if ($paymentId <= 0) {
    deny(400, 'Payment is missing.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $stmt = $lisani_conn->prepare(
        'SELECT proof_path, proof_original_name FROM invoice_payments WHERE id = ?'
    );
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $e) {
    error_log('view_invoice_payment_proof.php: ' . $e->getMessage());
    deny(500, 'Could not look up this payment.');
}

if (!$row) {
    deny(404, 'Payment was not found.');
}

// Resolve + confirm the real path stays inside storage (same precaution as
// create_invoice_payment.php refusing ".." in any path it builds).
$fullPath = AOS_STORAGE_BASE . '/' . ltrim(str_replace('\\', '/', (string) $row['proof_path']), '/');
$realFull = realpath($fullPath);
$realBase = realpath(AOS_STORAGE_BASE);
if ($realFull === false || $realBase === false || strpos($realFull, $realBase) !== 0) {
    deny(404, 'The proof file could not be found.');
}

$mimeByExt = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
];
$ext = strtolower(pathinfo($realFull, PATHINFO_EXTENSION));
$mime = $mimeByExt[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realFull));
header('Content-Disposition: inline; filename="' . basename((string) $row['proof_original_name']) . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($realFull);
exit;
