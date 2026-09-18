<?php
// lisani_aos/ajax/create_logistic.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn

$activityId   = (int) ($_POST['activity_id'] ?? 0);
$incomingDate = trim($_POST['incoming_date'] ?? '');

$primaryQty    = trim(str_replace(',', '', $_POST['primary_qty'] ?? ''));
$primaryUnit   = strtoupper(trim($_POST['primary_unit_label'] ?? ''));
$primaryUnitKg = trim(str_replace(',', '', $_POST['primary_unit_weight_kg'] ?? ''));

$secondaryUnit      = strtoupper(trim($_POST['secondary_unit_label'] ?? ''));
$secondaryUnitKg     = trim(str_replace(',', '', $_POST['secondary_unit_weight_kg'] ?? ''));
$secondaryRatio      = trim(str_replace(',', '', $_POST['secondary_ratio_per_primary'] ?? ''));

if ($activityId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Activity code belum dipilih.']);
    exit;
}
if ($incomingDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $incomingDate)) {
    echo json_encode(['ok' => false, 'message' => 'Tanggal masuk barang tidak valid.']);
    exit;
}

// Logistic is currently only supported for the "dates" department — server
// re-checks this from the activity's own relative_path.
$stmt = $lisani_conn->prepare('SELECT relative_path FROM activities WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $activityId);
$stmt->execute();
$stmt->bind_result($relativePath);
if (!$stmt->fetch()) {
    $stmt->close();
    echo json_encode(['ok' => false, 'message' => 'Activity code tidak ditemukan.']);
    exit;
}
$stmt->close();

if (!preg_match('#^input/\d{4}/dates/#', $relativePath)) {
    echo json_encode(['ok' => false, 'message' => 'Logistic saat ini hanya tersedia untuk departemen DATES.']);
    exit;
}

// One logistic record per activity code (also enforced by UNIQUE(activity_id)).
$dupStmt = $lisani_conn->prepare('SELECT id FROM logistics WHERE activity_id = ? LIMIT 1');
$dupStmt->bind_param('i', $activityId);
$dupStmt->execute();
$dupStmt->store_result();
if ($dupStmt->num_rows > 0) {
    $dupStmt->close();
    echo json_encode(['ok' => false, 'message' => 'Activity code ini sudah punya logistic.']);
    exit;
}
$dupStmt->close();

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

$incomingDateOrNull  = $incomingDate !== '' ? $incomingDate : null;
$primaryUnitOrNull   = $primaryUnit !== '' ? $primaryUnit : null;
$secondaryUnitOrNull = $secondaryUnit !== '' ? $secondaryUnit : null;

// remaining_primary_qty starts out equal to primary_qty. Future
// logistic_movements rows (in/out) will adjust this column — not built yet.
$remainingPrimaryQtyVal = $primaryQtyVal;

$stmt = $lisani_conn->prepare(
    'INSERT INTO logistics
        (activity_id, incoming_date,
         primary_qty, primary_unit_label, primary_unit_weight_kg, remaining_primary_qty,
         secondary_unit_label, secondary_unit_weight_kg, secondary_ratio_per_primary,
         created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param(
    'isdsddsddi',
    $activityId, $incomingDateOrNull,
    $primaryQtyVal, $primaryUnitOrNull, $primaryUnitKgVal, $remainingPrimaryQtyVal,
    $secondaryUnitOrNull, $secondaryUnitKgVal, $secondaryRatioVal,
    $_SESSION['user_id']
);

if (!$stmt->execute()) {
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan logistic ke database. Coba lagi.']);
    exit;
}

echo json_encode(['ok' => true, 'data' => ['id' => $stmt->insert_id]]);
$stmt->close();