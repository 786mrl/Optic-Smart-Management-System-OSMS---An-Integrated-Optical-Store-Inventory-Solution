<?php
/**
 * frame_lookup.php
 * ─────────────────────────────────────────────────────────────────────────────
 * AJAX endpoint — Frame Barcode / QR Code lookup + Attribute Search.
 *
 * POST params (UFC mode):
 *   ufc    (string) — the UFC value decoded from the QR / typed manually.
 *
 * POST params (Attribute Search mode):
 *   action (string) = 'search_attr'
 *   brand  (string) — partial brand name
 *   code   (string) — partial frame code / ufc
 *   size   (string) — partial frame size
 *
 * Response UFC mode (JSON):
 *   { found: true,  source: 'main'|'staging', ufc, brand, frame_code,
 *                   frame_size, color_code, material, lens_shape, structure,
 *                   size_range, gender_category, sell_price, stock, stock_age }
 *   { found: false, message: '...' }
 *
 * Response Attribute Search mode (JSON):
 *   { rows: [ { source, ufc, brand, frame_code, frame_size, color_code,
 *               material, lens_shape, structure, size_range, gender_category,
 *               sell_price, stock, stock_age }, ... ] }
 * ─────────────────────────────────────────────────────────────────────────────
 */

session_start();
include 'db_config.php';

// ── Auth check ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['found' => false, 'message' => 'Unauthorized.']);
    exit();
}

// ── Only accept POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['found' => false, 'message' => 'Method not allowed.']);
    exit();
}

header('Content-Type: application/json');

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: search_attr
// Cari frame berdasarkan brand / frame_code / frame_size (partial match)
// ══════════════════════════════════════════════════════════════════════════════
$action = trim($_POST['action'] ?? '');

if ($action === 'search_attr') {

    $brand = trim($_POST['brand'] ?? '');
    $code  = trim($_POST['code']  ?? '');
    $size  = trim($_POST['size']  ?? '');

    if ($brand === '' && $code === '' && $size === '') {
        echo json_encode(['rows' => [], 'message' => 'No search parameters provided.']);
        exit();
    }

    $conditions = [];
    $bindParams = [];
    $bindTypes  = '';

    if ($brand !== '') {
        $conditions[] = 'brand LIKE ?';
        $bindParams[]  = '%' . $brand . '%';
        $bindTypes    .= 's';
    }
    if ($code !== '') {
        $conditions[] = '(frame_code LIKE ? OR ufc LIKE ?)';
        $bindParams[]  = '%' . $code . '%';
        $bindParams[]  = '%' . $code . '%';
        $bindTypes    .= 'ss';
    }
    if ($size !== '') {
        $conditions[] = 'frame_size LIKE ?';
        $bindParams[]  = '%' . $size . '%';
        $bindTypes    .= 's';
    }

    $whereClause = implode(' AND ', $conditions);
    $selectCols  = "ufc, brand, frame_code, frame_size, color_code, material,
                    lens_shape, structure, size_range, gender_category,
                    sell_price, stock, stock_age";

    $allRows = [];

    // ── Query frames_main ─────────────────────────────────────────────────────
    $sqlMain  = "SELECT $selectCols, 'main' AS source
                 FROM frames_main
                 WHERE $whereClause
                 ORDER BY brand ASC, frame_code ASC
                 LIMIT 60";
    $stmtMain = $conn->prepare($sqlMain);
    if ($stmtMain) {
        $stmtMain->bind_param($bindTypes, ...$bindParams);
        $stmtMain->execute();
        $resMain = $stmtMain->get_result();
        while ($row = $resMain->fetch_assoc()) {
            $allRows[] = $row;
        }
        $stmtMain->close();
    }

    // ── Query frame_staging ───────────────────────────────────────────────────
    $sqlStaging = "SELECT $selectCols, 'staging' AS source
                   FROM frame_staging
                   WHERE $whereClause
                   ORDER BY brand ASC, frame_code ASC
                   LIMIT 60";
    $stmtStg = $conn->prepare($sqlStaging);
    if ($stmtStg) {
        $stmtStg->bind_param($bindTypes, ...$bindParams);
        $stmtStg->execute();
        $resStg   = $stmtStg->get_result();
        $mainUfcs = array_column($allRows, 'ufc');
        while ($row = $resStg->fetch_assoc()) {
            if (!in_array($row['ufc'], $mainUfcs)) {
                $allRows[] = $row;
            }
        }
        $stmtStg->close();
    }

    // ── Sort: main dulu, stock > 0 dulu, lalu brand A-Z ─────────────────────
    usort($allRows, function ($a, $b) {
        $srcA = ($a['source'] === 'main') ? 0 : 1;
        $srcB = ($b['source'] === 'main') ? 0 : 1;
        if ($srcA !== $srcB) return $srcA - $srcB;
        $stA = ((int)$a['stock'] > 0) ? 0 : 1;
        $stB = ((int)$b['stock'] > 0) ? 0 : 1;
        if ($stA !== $stB) return $stA - $stB;
        return strcasecmp($a['brand'] ?? '', $b['brand'] ?? '');
    });

    $output = array_map(function ($r) {
        return [
            'source'          => $r['source'],
            'ufc'             => $r['ufc']             ?? '',
            'brand'           => $r['brand']           ?? '',
            'frame_code'      => $r['frame_code']      ?? '',
            'frame_size'      => $r['frame_size']      ?? '',
            'color_code'      => $r['color_code']      ?? '',
            'material'        => $r['material']        ?? '',
            'lens_shape'      => $r['lens_shape']      ?? '',
            'structure'       => $r['structure']       ?? '',
            'size_range'      => $r['size_range']      ?? '',
            'gender_category' => $r['gender_category'] ?? '',
            'sell_price'      => (float) ($r['sell_price'] ?? 0),
            'stock'           => (int)   ($r['stock']      ?? 0),
            'stock_age'       => $r['stock_age']       ?? '',
        ];
    }, $allRows);

    echo json_encode(['rows' => $output]);
    exit();
}

// ══════════════════════════════════════════════════════════════════════════════
// DEFAULT: UFC / QR Code lookup (logika asli)
// ══════════════════════════════════════════════════════════════════════════════

$rawUfc = trim($_POST['ufc'] ?? '');
if ($rawUfc === '') {
    echo json_encode(['found' => false, 'message' => 'No UFC provided.']);
    exit();
}
$ufc = mysqli_real_escape_string($conn, $rawUfc);

$mainQrPath    = __DIR__ . '/main_qrcodes/' . $rawUfc . '.png';
$stagingQrPath = __DIR__ . '/qrcodes/'      . $rawUfc . '.png';
$inMain        = file_exists($mainQrPath);
$inStaging     = file_exists($stagingQrPath);

function lookupFrame($conn, $table, $ufc) {
    $sql = "SELECT ufc, brand, frame_code, frame_size, color_code, material,
                   lens_shape, structure, size_range, gender_category,
                   sell_price, stock, stock_age
            FROM `$table`
            WHERE ufc = '$ufc'
            LIMIT 1";
    $res = mysqli_query($conn, $sql);
    if (!$res) return null;
    return mysqli_fetch_assoc($res);
}

$row    = null;
$source = null;

if ($inMain) {
    $row = lookupFrame($conn, 'frames_main', $ufc);
    if ($row) { $source = 'main'; }
    else {
        $row = lookupFrame($conn, 'frame_staging', $ufc);
        if ($row) $source = 'staging';
    }
} elseif ($inStaging) {
    $row = lookupFrame($conn, 'frame_staging', $ufc);
    if ($row) { $source = 'staging'; }
    else {
        $row = lookupFrame($conn, 'frames_main', $ufc);
        if ($row) $source = 'main';
    }
} else {
    $row = lookupFrame($conn, 'frames_main', $ufc);
    if ($row) { $source = 'main'; }
    else {
        $row = lookupFrame($conn, 'frame_staging', $ufc);
        if ($row) $source = 'staging';
    }
}

if ($row && $source) {
    echo json_encode([
        'found'           => true,
        'source'          => $source,
        'ufc'             => $row['ufc']             ?? '',
        'brand'           => $row['brand']           ?? '',
        'frame_code'      => $row['frame_code']      ?? '',
        'frame_size'      => $row['frame_size']      ?? '',
        'color_code'      => $row['color_code']      ?? '',
        'material'        => $row['material']        ?? '',
        'lens_shape'      => $row['lens_shape']      ?? '',
        'structure'       => $row['structure']       ?? '',
        'size_range'      => $row['size_range']      ?? '',
        'gender_category' => $row['gender_category'] ?? '',
        'sell_price'      => (float) ($row['sell_price'] ?? 0),
        'stock'           => (int)   ($row['stock']      ?? 0),
        'stock_age'       => $row['stock_age']       ?? '',
    ]);
} else {
    $hint = (!$inMain && !$inStaging)
        ? 'QR code image not found in main_qrcodes/ or qrcodes/.'
        : 'Frame record not found in the database.';
    echo json_encode(['found' => false, 'message' => $hint]);
}