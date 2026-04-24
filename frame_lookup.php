<?php
/**
 * frame_lookup.php
 * ─────────────────────────────────────────────────────────────────────────────
 * AJAX endpoint — Frame Barcode / QR Code lookup.
 *
 * POST params:
 *   ufc  (string) — the UFC value decoded from the QR / typed manually.
 *
 * Logic:
 *   1. Validate the UFC against the QR image file in ./main_qrcodes/{ufc}.png
 *      If found → query frames_main table.
 *   2. If not in main → check ./qrcodes/{ufc}.png
 *      If found → query frame_staging table.
 *   3. If neither file exists → still try both tables as a fallback
 *      (handles edge cases where the image file was deleted/moved).
 *
 * Response (JSON):
 *   { found: true,  source: 'main'|'staging', ufc, brand, sell_price, stock }
 *   { found: false, message: '...' }
 * ─────────────────────────────────────────────────────────────────────────────
 */

session_start();
include 'db_config.php';

// ── Auth check ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['found' => false, 'message' => 'Unauthorized.']);
    exit();
}

// ── Only accept POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['found' => false, 'message' => 'Method not allowed.']);
    exit();
}

// ── Sanitize input ─────────────────────────────────────────────────────────────
$rawUfc = trim($_POST['ufc'] ?? '');
if ($rawUfc === '') {
    echo json_encode(['found' => false, 'message' => 'No UFC provided.']);
    exit();
}
$ufc = mysqli_real_escape_string($conn, $rawUfc);

// ── Path constants ─────────────────────────────────────────────────────────────
$mainQrPath    = __DIR__ . '/main_qrcodes/' . $rawUfc . '.png';
$stagingQrPath = __DIR__ . '/qrcodes/'      . $rawUfc . '.png';

// ── Determine search order based on QR file location ──────────────────────────
$inMain    = file_exists($mainQrPath);
$inStaging = file_exists($stagingQrPath);

// Helper: query a table and return the row or null
function lookupFrame($conn, $table, $ufc) {
    $sql = "SELECT ufc, brand, sell_price, stock FROM `$table` WHERE ufc = '$ufc' LIMIT 1";
    $res = mysqli_query($conn, $sql);
    if (!$res) return null;
    return mysqli_fetch_assoc($res);
}

$row    = null;
$source = null;

if ($inMain) {
    // QR image is in main_qrcodes → look in frames_main first
    $row = lookupFrame($conn, 'frames_main', $ufc);
    if ($row) {
        $source = 'main';
    } else {
        // File exists but not in DB yet (edge case) — try staging
        $row = lookupFrame($conn, 'frame_staging', $ufc);
        if ($row) $source = 'staging';
    }
} elseif ($inStaging) {
    // QR image is in staging qrcodes → look in frame_staging first
    $row = lookupFrame($conn, 'frame_staging', $ufc);
    if ($row) {
        $source = 'staging';
    } else {
        // Try main as fallback
        $row = lookupFrame($conn, 'frames_main', $ufc);
        if ($row) $source = 'main';
    }
} else {
    // No QR file found in either folder — try both tables directly
    $row = lookupFrame($conn, 'frames_main', $ufc);
    if ($row) {
        $source = 'main';
    } else {
        $row = lookupFrame($conn, 'frame_staging', $ufc);
        if ($row) $source = 'staging';
    }
}

// ── Build response ─────────────────────────────────────────────────────────────
header('Content-Type: application/json');

if ($row && $source) {
    echo json_encode([
        'found'      => true,
        'source'     => $source,
        'ufc'        => $row['ufc'],
        'brand'      => $row['brand'],
        'sell_price' => (float) $row['sell_price'],
        'stock'      => (int)   $row['stock'],
    ]);
} else {
    $hint = (!$inMain && !$inStaging)
        ? 'QR code image not found in main_qrcodes/ or qrcodes/.'
        : 'Frame record not found in the database.';
    echo json_encode([
        'found'   => false,
        'message' => $hint,
    ]);
}