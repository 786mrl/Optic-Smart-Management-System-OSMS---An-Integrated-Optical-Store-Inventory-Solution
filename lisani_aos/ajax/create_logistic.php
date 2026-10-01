<?php
// lisani_aos/ajax/create_logistic.php
// Creates one OR MORE logistics rows (one per product) for a single activity
// code, in one DB transaction: either every product is saved or none is.
//
// POST: activity_id, incoming_date (optional, shared by all products),
//       products = JSON array of
//         { product_name, primary_qty, primary_unit_label, primary_unit_weight_kg,
//           secondary_unit_label, secondary_unit_weight_kg, secondary_ratio_per_primary }
//
// Since 28 Sep 2026 an activity code may hold several products (each its own
// logistics row / logistic_id, with its own qty + packaging). Documents stay
// per activity code (logistic_documents.activity_id) and are shared.
// product_name is UNIQUE per activity code (uniq_activity_product).
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn

function cl_fail(string $message): void
{
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
}

$activityId   = (int) ($_POST['activity_id'] ?? 0);
$incomingDate = trim($_POST['incoming_date'] ?? '');
$productsRaw  = $_POST['products'] ?? '';

if ($activityId <= 0) {
    cl_fail('Activity code belum dipilih.');
}
if ($incomingDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $incomingDate)) {
    cl_fail('Tanggal masuk barang tidak valid.');
}

$products = json_decode((string) $productsRaw, true);
if (!is_array($products) || count($products) === 0) {
    cl_fail('Tambahkan minimal satu produk.');
}
if (count($products) > 50) {
    cl_fail('Terlalu banyak produk dalam satu kali simpan (maks. 50).');
}

// null = empty, false = not a valid non-negative number.
$toNullableDecimal = function ($v) {
    $v = trim(str_replace(',', '', (string) $v));
    if ($v === '') return null;
    if (!is_numeric($v) || (float) $v < 0) return false;
    return (float) $v;
};

// ---- Validate + normalise every product before touching the DB ----
$clean     = [];
$seenNames = [];
foreach ($products as $i => $p) {
    $n = $i + 1;
    if (!is_array($p)) {
        cl_fail('Data produk #' . $n . ' tidak valid.');
    }

    $name = strtoupper(trim((string) ($p['product_name'] ?? '')));
    if ($name === '') {
        cl_fail('Nama produk #' . $n . ' wajib diisi.');
    }
    if (mb_strlen($name) > 150) {
        cl_fail('Nama produk #' . $n . ' terlalu panjang (maks. 150 karakter).');
    }
    if (isset($seenNames[$name])) {
        cl_fail('Nama produk "' . $name . '" dipakai lebih dari sekali dalam form ini.');
    }
    $seenNames[$name] = true;

    $primaryQty  = $toNullableDecimal($p['primary_qty'] ?? '');
    $primaryKg   = $toNullableDecimal($p['primary_unit_weight_kg'] ?? '');
    $secondaryKg = $toNullableDecimal($p['secondary_unit_weight_kg'] ?? '');
    $ratio       = $toNullableDecimal($p['secondary_ratio_per_primary'] ?? '');
    foreach ([$primaryQty, $primaryKg, $secondaryKg, $ratio] as $v) {
        if ($v === false) {
            cl_fail('Ada angka yang tidak valid pada produk "' . $name . '".');
        }
    }

    $primaryUnit   = strtoupper(trim((string) ($p['primary_unit_label'] ?? '')));
    $secondaryUnit = strtoupper(trim((string) ($p['secondary_unit_label'] ?? '')));

    $clean[] = [
        'name'          => $name,
        'primary_qty'   => $primaryQty,
        'primary_unit'  => $primaryUnit !== '' ? $primaryUnit : null,
        'primary_kg'    => $primaryKg,
        'secondary_unit' => $secondaryUnit !== '' ? $secondaryUnit : null,
        'secondary_kg'  => $secondaryKg,
        'ratio'         => $ratio,
    ];
}

$incomingDateOrNull = $incomingDate !== '' ? $incomingDate : null;
$userId = (int) $_SESSION['user_id'];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $lisani_conn->begin_transaction();

    // Lock the activity row: serialises two people adding products to the
    // same activity code at the same moment, so the duplicate-name check
    // below cannot be raced. Also re-checks the department server-side.
    $stmt = $lisani_conn->prepare('SELECT relative_path FROM activities WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $activityId);
    $stmt->execute();
    $stmt->bind_result($relativePath);
    if (!$stmt->fetch()) {
        $stmt->close();
        $lisani_conn->rollback();
        cl_fail('Activity code tidak ditemukan.');
    }
    $stmt->close();

    // Logistic is currently only supported for the "dates" department.
    if (!preg_match('#^input/\d{4}/dates/#', $relativePath)) {
        $lisani_conn->rollback();
        cl_fail('Logistic saat ini hanya tersedia untuk departemen DATES.');
    }

    // Product names already used under this activity code.
    $existing = [];
    $stmt = $lisani_conn->prepare('SELECT product_name FROM logistics WHERE activity_id = ?');
    $stmt->bind_param('i', $activityId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $existing[strtoupper((string) $r['product_name'])] = true;
    }
    $stmt->close();

    foreach ($clean as $c) {
        if (isset($existing[$c['name']])) {
            $lisani_conn->rollback();
            cl_fail('Produk "' . $c['name'] . '" sudah ada di activity code ini.');
        }
    }

    // remaining_primary_qty starts out equal to primary_qty; movements
    // (order / return) adjust it afterwards.
    $stmt = $lisani_conn->prepare(
        'INSERT INTO logistics
            (activity_id, product_name, incoming_date,
             primary_qty, primary_unit_label, primary_unit_weight_kg, remaining_primary_qty,
             secondary_unit_label, secondary_unit_weight_kg, secondary_ratio_per_primary,
             created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $ids = [];
    foreach ($clean as $c) {
        $remaining = $c['primary_qty'];
        // i s s d s d d s d d i
        $stmt->bind_param(
            'issdsddsddi',
            $activityId, $c['name'], $incomingDateOrNull,
            $c['primary_qty'], $c['primary_unit'], $c['primary_kg'], $remaining,
            $c['secondary_unit'], $c['secondary_kg'], $c['ratio'],
            $userId
        );
        $stmt->execute();
        $ids[] = $stmt->insert_id;
    }
    $stmt->close();

    $lisani_conn->commit();
} catch (\Throwable $e) {
    @$lisani_conn->rollback();
    error_log('create_logistic.php: ' . $e->getMessage());
    // 1062 = duplicate key (uniq_activity_product) — a race the check above missed.
    if ((int) $e->getCode() === 1062) {
        cl_fail('Ada nama produk yang sudah terdaftar di activity code ini.');
    }
    cl_fail('Gagal menyimpan logistic ke database. Coba lagi.');
}

echo json_encode(['ok' => true, 'data' => ['ids' => $ids, 'count' => count($ids)]]);