<?php
// lisani_aos/ajax/update_logistic.php
// Edits an existing logistics row (rate columns only — totals are always
// calculated on read, see list_logistics.php). Gated by the shared
// re-verify guard (ajax/verify_password.php sets $_SESSION['aos_reverify_at'],
// this endpoint just checks it's recent) rather than accepting a password
// field directly, per the Logistic List Edit/Delete pattern.
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn
require_once __DIR__ . '/_require_reverify.php';
aos_require_recent_reverify(); // exits with ['success' => false, ...] on failure — NOT ['ok' => false]

$logisticId   = (int) ($_POST['id'] ?? 0);
$incomingDate = trim($_POST['incoming_date'] ?? '');

$primaryQty    = trim(str_replace(',', '', $_POST['primary_qty'] ?? ''));
$primaryUnit   = strtoupper(trim($_POST['primary_unit_label'] ?? ''));
$primaryUnitKg = trim(str_replace(',', '', $_POST['primary_unit_weight_kg'] ?? ''));

$secondaryUnit      = strtoupper(trim($_POST['secondary_unit_label'] ?? ''));
$secondaryUnitKg    = trim(str_replace(',', '', $_POST['secondary_unit_weight_kg'] ?? ''));
$secondaryRatio     = trim(str_replace(',', '', $_POST['secondary_ratio_per_primary'] ?? ''));

if ($logisticId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Logistic tidak valid.']);
    exit;
}
if ($incomingDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $incomingDate)) {
    echo json_encode(['ok' => false, 'message' => 'Tanggal masuk barang tidak valid.']);
    exit;
}

$toNullableDecimal = function ($v) {
    if ($v === '' || $v === null) return null;
    if (!is_numeric($v)) return false;
    return (float) $v;
};

$primaryQtyVal      = $toNullableDecimal($primaryQty);
$primaryUnitKgVal   = $toNullableDecimal($primaryUnitKg);
$secondaryUnitKgVal = $toNullableDecimal($secondaryUnitKg);
$secondaryRatioVal  = $toNullableDecimal($secondaryRatio);

foreach ([$primaryQtyVal, $primaryUnitKgVal, $secondaryUnitKgVal, $secondaryRatioVal] as $v) {
    if ($v === false) {
        echo json_encode(['ok' => false, 'message' => 'Ada angka yang tidak valid pada input packaging.']);
        exit;
    }
}

// Fetch the current row first — needed both to confirm it exists and to
// carry the "used" amount (primary_qty - remaining_primary_qty) forward
// proportionally as a fixed offset, not a ratio, when primary_qty changes.
// Example: qty 1000, remaining 900 (100 already moved out). Edit qty to
// 1100 -> used stays 100 -> new remaining = 1100 - 100 = 1000.
$stmt = $lisani_conn->prepare('SELECT primary_qty, remaining_primary_qty FROM logistics WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $logisticId);
$stmt->execute();
$stmt->bind_result($oldPrimaryQty, $oldRemainingQty);
if (!$stmt->fetch()) {
    $stmt->close();
    echo json_encode(['ok' => false, 'message' => 'Logistic tidak ditemukan.']);
    exit;
}
$stmt->close();

$oldPrimaryQty   = $oldPrimaryQty   !== null ? (float) $oldPrimaryQty   : null;
$oldRemainingQty = $oldRemainingQty !== null ? (float) $oldRemainingQty : null;
$used = ($oldPrimaryQty !== null && $oldRemainingQty !== null) ? ($oldPrimaryQty - $oldRemainingQty) : 0.0;

if ($primaryQtyVal === null) {
    // Primary qty cleared entirely — remaining follows it to null, nothing
    // to prorate against.
    $remainingPrimaryQtyVal = null;
} else {
    $remainingPrimaryQtyVal = $primaryQtyVal - $used;
    if ($remainingPrimaryQtyVal < 0) {
        echo json_encode([
            'ok' => false,
            'message' => 'Primary Qty baru (' . $primaryQtyVal . ') lebih kecil dari yang sudah keluar (' . $used . '). Tidak bisa disimpan.',
        ]);
        exit;
    }
}

$incomingDateOrNull  = $incomingDate !== '' ? $incomingDate : null;
$primaryUnitOrNull   = $primaryUnit !== '' ? $primaryUnit : null;
$secondaryUnitOrNull = $secondaryUnit !== '' ? $secondaryUnit : null;

$stmt = $lisani_conn->prepare(
    'UPDATE logistics
     SET incoming_date = ?,
         primary_qty = ?, primary_unit_label = ?, primary_unit_weight_kg = ?, remaining_primary_qty = ?,
         secondary_unit_label = ?, secondary_unit_weight_kg = ?, secondary_ratio_per_primary = ?
     WHERE id = ?'
);
$stmt->bind_param(
    'sdsddsddi',
    $incomingDateOrNull,
    $primaryQtyVal, $primaryUnitOrNull, $primaryUnitKgVal, $remainingPrimaryQtyVal,
    $secondaryUnitOrNull, $secondaryUnitKgVal, $secondaryRatioVal,
    $logisticId
);

if (!$stmt->execute()) {
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan perubahan. Coba lagi.']);
    exit;
}

echo json_encode(['ok' => true]);
$stmt->close();
