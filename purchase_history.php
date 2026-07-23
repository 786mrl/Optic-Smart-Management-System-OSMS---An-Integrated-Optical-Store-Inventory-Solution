<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }


    // ── Handle AJAX: update custom_frames buy_price ─────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'update_frame_cost') {
        header('Content-Type: application/json');
        $invoice  = $conn->real_escape_string($_POST['invoice'] ?? '');
        $buy_price = (int)$_POST['buy_price'];
        if (empty($invoice)) {
            echo json_encode(['success' => false, 'error' => 'Invalid invoice']);
            exit();
        }
        $stmt = $conn->prepare("UPDATE custom_frames SET buy_price = ? WHERE invoice_number = ?");
        $stmt->bind_param("is", $buy_price, $invoice);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'buy_price' => $buy_price]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        $stmt->close();
        exit();
    }

    // ── Handle AJAX: update packaging cost ──────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'update_packaging') {
        header('Content-Type: application/json');
        $order_id = (int)$_POST['order_id'];
        $box      = (int)$_POST['box'];
        $flanel   = (int)$_POST['flanel'];
        $faset    = (int)$_POST['faset'];
        $wrapping = (int)$_POST['wrapping'];
        $cleaner  = (int)$_POST['cleaner'];

        if ($order_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid order id']);
            exit();
        }

        $packaging_cost = $box + $flanel + $faset + $wrapping + $cleaner;

        $stmt = $conn->prepare("UPDATE customer_orders SET packaging_cost = ? WHERE id = ?");
        $stmt->bind_param("ii", $packaging_cost, $order_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'packaging_cost' => $packaging_cost]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        $stmt->close();
        exit();
    }

    // ── Handle AJAX: update total_amount with password verification ──────
    if (isset($_POST['action']) && $_POST['action'] === 'update_total') {
        header('Content-Type: application/json');
        $order_id  = (int)$_POST['order_id'];
        $new_total = (int)$_POST['new_total'];
        $password  = $_POST['password'] ?? '';
        $user_id   = (int)$_SESSION['user_id'];

        if ($order_id <= 0 || $new_total < 0 || empty($password)) {
            echo json_encode(['success' => false, 'error' => 'Invalid input']);
            exit();
        }

        // Verify password against current logged-in user
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            echo json_encode(['success' => false, 'error' => 'Incorrect password']);
            exit();
        }

        // Update total_amount
        $stmt2 = $conn->prepare("UPDATE customer_orders SET total_amount = ? WHERE id = ? AND order_status = 5");
        $stmt2->bind_param("ii", $new_total, $order_id);
        if ($stmt2->execute()) {
            echo json_encode(['success' => true, 'new_total' => $new_total]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        $stmt2->close();
        exit();
    }

// ══════════════════════════════════════════════════════════════════════
// EDIT ORDER FEATURE — full multi-group editor for a finished order
// Touches: customer_examinations, customer_orders, custom_frames,
//          frames_main, frame_staging, prescription_modifications
// Access:  requires role = 'admin' AND a verified password (unlocked
//          for a short window, stored in session) before any group save.
// ══════════════════════════════════════════════════════════════════════

// ── Make sure the audit-trail column exists on customer_orders ────────
$conn->query("ALTER TABLE customer_orders ADD COLUMN IF NOT EXISTS edit_log TEXT NULL DEFAULT NULL");

// ── Small helpers ───────────────────────────────────────────────────

// Is the current session unlocked for editing? (role checked at unlock time)
function phEditIsUnlocked() {
    return isset($_SESSION['ph_edit_unlocked_until'])
        && $_SESSION['ph_edit_unlocked_until'] > time()
        && isset($_SESSION['ph_edit_admin_user_id'])
        && $_SESSION['ph_edit_admin_user_id'] === ($_SESSION['user_id'] ?? null);
}

// Append a structured entry to customer_orders.edit_log (JSON array, human-readable summary each)
function phAppendEditLog($conn, $order_id, $group, $summary) {
    $order_id = (int)$order_id;
    $res  = $conn->query("SELECT edit_log FROM customer_orders WHERE id = $order_id LIMIT 1");
    $row  = $res ? $res->fetch_assoc() : null;
    $log  = [];
    if ($row && !empty($row['edit_log'])) {
        $decoded = json_decode($row['edit_log'], true);
        if (is_array($decoded)) $log = $decoded;
    }
    $log[] = [
        'ts'      => date('Y-m-d H:i:s'),
        'user'    => $_SESSION['username'] ?? ('user#' . ($_SESSION['user_id'] ?? '?')),
        'group'   => $group,
        'summary' => $summary,
    ];
    $encoded = $conn->real_escape_string(json_encode($log, JSON_UNESCAPED_UNICODE));
    $conn->query("UPDATE customer_orders SET edit_log = '$encoded' WHERE id = $order_id");
}

// Build a custom-frame brand_key exactly like the JS side does in invoice.php:
// [size+]dd/mm+brand(lowercase)
function phBuildCustomFrameKey($brand, $size) {
    $parts = [];
    $size  = trim($size);
    if ($size !== '') $parts[] = $size;
    $parts[] = date('d/m');
    $parts[] = strtolower(trim($brand));
    return implode('+', $parts);
}

// Look up lens cost from the same JSON price list used elsewhere on this page
function phLensCostLookup($lensName) {
    $lensName = trim($lensName);
    if ($lensName === '') return 0;
    $jsonPath = __DIR__ . '/data_json/lense_prices.json';
    if (!file_exists($jsonPath)) return 0;
    $data = json_decode(file_get_contents($jsonPath), true);
    foreach (['stock', 'lab'] as $lt) {
        if (empty($data[$lt])) continue;
        foreach ($data[$lt] as $cat => $types) {
            foreach ($types as $type => $info) {
                $k = strtoupper(trim($cat) . ' / ' . trim($type));
                if ($k === strtoupper($lensName)) return (int)($info['cost'] ?? 0);
            }
        }
    }
    return 0;
}

// Is a given frame_ufc value actually a custom_frames brand_key (not a catalog UFC)?
// Mirrors the detection already used lower in this file: brand_key starts with a digit (size prefix).
function phIsCustomFrameUfc($ufc) {
    $ufc = trim($ufc);
    return $ufc !== '' && is_numeric($ufc[0]);
}

// Try to restore +1 stock to whichever catalog table (frames_main / frame_staging) owns this ufc.
// Returns the table name it restored to, or null if not found in either.
function phRestoreCatalogStock($conn, $ufc) {
    $ufc = $conn->real_escape_string($ufc);
    foreach (['frames_main', 'frame_staging'] as $tbl) {
        $chk = $conn->query("SELECT ufc FROM `$tbl` WHERE ufc = '$ufc' LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            $conn->query("UPDATE `$tbl` SET stock = stock + 1 WHERE ufc = '$ufc'");
            return $tbl;
        }
    }
    return null;
}

// Try to deduct 1 stock from whichever catalog table owns this ufc, only if stock > 0.
// Returns the table name on success, or null on failure (not found / out of stock).
function phDeductCatalogStock($conn, $ufc) {
    $ufc = $conn->real_escape_string($ufc);
    foreach (['frames_main', 'frame_staging'] as $tbl) {
        $chk = $conn->query("SELECT stock FROM `$tbl` WHERE ufc = '$ufc' AND stock > 0 LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            $conn->query("UPDATE `$tbl` SET stock = stock - 1 WHERE ufc = '$ufc'");
            return $tbl;
        }
    }
    return null;
}

// Delete a custom_frames row and, only if it held the current highest id,
// roll AUTO_INCREMENT back so the next insert reuses that id (safe reuse only).
function phDeleteCustomFrameAndReclaimId($conn, $rowId) {
    $rowId = (int)$rowId;
    $maxRes = $conn->query("SELECT MAX(id) AS max_id FROM custom_frames");
    $maxRow = $maxRes ? $maxRes->fetch_assoc() : null;
    $wasHighest = $maxRow && (int)$maxRow['max_id'] === $rowId;

    $conn->query("DELETE FROM custom_frames WHERE id = $rowId LIMIT 1");

    if ($wasHighest) {
        // Next auto_increment value becomes the id we just freed up.
        $conn->query("ALTER TABLE custom_frames AUTO_INCREMENT = $rowId");
    }
}

// Verify the currently logged-in user is an admin, returns [ok(bool), error(string)]
function phVerifyAdminPassword($conn, $password) {
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    if ($user_id <= 0) return [false, 'Not logged in.'];

    $stmt = $conn->prepare("SELECT role, password_hash FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return [false, 'User not found.'];
    if (strtolower($row['role']) !== 'admin') return [false, 'Only admin users are allowed to edit orders.'];
    if (empty($password) || !password_verify($password, $row['password_hash'])) return [false, 'Incorrect password.'];

    return [true, ''];
}

// ── AJAX: verify admin + password, unlock editing for this session ────
if (isset($_POST['action']) && $_POST['action'] === 'edit_verify_access') {
    header('Content-Type: application/json');
    [$ok, $err] = phVerifyAdminPassword($conn, $_POST['password'] ?? '');
    if (!$ok) { echo json_encode(['success' => false, 'error' => $err]); exit(); }

    $_SESSION['ph_edit_unlocked_until'] = time() + 900; // 15 minutes
    $_SESSION['ph_edit_admin_user_id']  = $_SESSION['user_id'];
    echo json_encode(['success' => true, 'unlocked_for_seconds' => 900]);
    exit();
}

// ── AJAX: fetch full editable detail for one order (all 6 tables) ─────
if (isset($_POST['action']) && $_POST['action'] === 'edit_get_details') {
    header('Content-Type: application/json');
    if (!phEditIsUnlocked()) { echo json_encode(['success' => false, 'error' => 'Session locked. Please verify admin access again.']); exit(); }

    $order_id = (int)($_POST['order_id'] ?? 0);
    if ($order_id <= 0) { echo json_encode(['success' => false, 'error' => 'Invalid order id']); exit(); }

    $stmt = $conn->prepare("SELECT * FROM customer_orders WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) { echo json_encode(['success' => false, 'error' => 'Order not found']); exit(); }

    $inv = $order['invoice_number'];

    $stmt = $conn->prepare("SELECT * FROM customer_examinations WHERE invoice_number = ? LIMIT 1");
    $stmt->bind_param("s", $inv);
    $stmt->execute();
    $exam = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM prescription_modifications WHERE invoice_number = ? ORDER BY modified_at DESC LIMIT 1");
    $stmt->bind_param("s", $inv);
    $stmt->execute();
    $lastMod = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // All custom_frames saved against this invoice (so staff can switch back to a previously entered one)
    $customFrames = [];
    $stmt = $conn->prepare("SELECT id, brand_key, sell_price, buy_price, is_purchased FROM custom_frames WHERE invoice_number = ? ORDER BY id ASC");
    $stmt->bind_param("s", $inv);
    $stmt->execute();
    $cfRes = $stmt->get_result();
    while ($cfRow = $cfRes->fetch_assoc()) { $customFrames[] = $cfRow; }
    $stmt->close();

    // Current frame — is it catalog or custom?
    $curUfc      = trim($order['frame_ufc'] ?? '');
    $frameIsCustom = phIsCustomFrameUfc($curUfc);
    $catalogFrame  = null;
    if ($curUfc !== '' && !$frameIsCustom) {
        foreach (['frames_main', 'frame_staging'] as $tbl) {
            $safeUfc = $conn->real_escape_string($curUfc);
            $r = $conn->query("SELECT ufc, brand, frame_code, frame_size, sell_price, stock FROM `$tbl` WHERE ufc = '$safeUfc' LIMIT 1");
            if ($r && $r->num_rows > 0) { $catalogFrame = $r->fetch_assoc(); $catalogFrame['source_table'] = $tbl; break; }
        }
    }

    echo json_encode([
        'success'       => true,
        'order'         => $order,
        'exam'          => $exam ?: null,
        'last_mod'      => $lastMod ?: null,
        'custom_frames' => $customFrames,
        'frame_is_custom' => $frameIsCustom,
        'catalog_frame' => $catalogFrame,
    ]);
    exit();
}

// ── AJAX: Group "Customer Data" — name/age/gender/symptoms/notes/date ─
if (isset($_POST['action']) && $_POST['action'] === 'edit_group_customer') {
    header('Content-Type: application/json');
    if (!phEditIsUnlocked()) { echo json_encode(['success' => false, 'error' => 'Session locked. Please verify admin access again.']); exit(); }

    $order_id = (int)($_POST['order_id'] ?? 0);
    $inv      = $conn->real_escape_string($_POST['invoice_number'] ?? '');
    if ($order_id <= 0 || $inv === '') { echo json_encode(['success' => false, 'error' => 'Invalid input']); exit(); }

    $curRes = $conn->query("SELECT examination_date, customer_name, age, gender, symptoms, exam_notes FROM customer_examinations WHERE invoice_number = '$inv' LIMIT 1");
    $cur    = $curRes ? $curRes->fetch_assoc() : null;
    if (!$cur) { echo json_encode(['success' => false, 'error' => 'Examination record not found']); exit(); }

    $new = [
        'examination_date' => $_POST['examination_date'] ?? $cur['examination_date'],
        'customer_name'    => strtoupper(trim($_POST['customer_name'] ?? $cur['customer_name'])),
        'age'              => (string)(int)($_POST['age'] ?? $cur['age']),
        'gender'           => in_array($_POST['gender'] ?? '', ['MALE', 'FEMALE']) ? $_POST['gender'] : $cur['gender'],
        'symptoms'         => $_POST['symptoms']   ?? $cur['symptoms'],
        'exam_notes'       => $_POST['exam_notes'] ?? $cur['exam_notes'],
    ];

    $setParts = [];
    $changes  = [];
    foreach ($new as $field => $val) {
        $curVal = ($field === 'examination_date') ? date('Y-m-d', strtotime($cur[$field])) : (string)$cur[$field];
        if ((string)$val !== $curVal) {
            $setParts[] = "`$field` = '" . $conn->real_escape_string($val) . "'";
            $changes[]  = "$field: \"$curVal\" -> \"$val\"";
        }
    }

    if (empty($setParts)) { echo json_encode(['success' => true, 'changed' => false]); exit(); }

    $ok = $conn->query("UPDATE customer_examinations SET " . implode(', ', $setParts) . " WHERE invoice_number = '$inv'");
    if (!$ok) { echo json_encode(['success' => false, 'error' => $conn->error]); exit(); }

    phAppendEditLog($conn, $order_id, 'customer_data', implode('; ', $changes));
    echo json_encode(['success' => true, 'changed' => true, 'name' => $new['customer_name'], 'age' => $new['age'], 'gender' => $new['gender']]);
    exit();
}

// ── AJAX: Group "Exam Results" — Rx measurements, ucva, pd, habits ────
if (isset($_POST['action']) && $_POST['action'] === 'edit_group_exam') {
    header('Content-Type: application/json');
    if (!phEditIsUnlocked()) { echo json_encode(['success' => false, 'error' => 'Session locked. Please verify admin access again.']); exit(); }

    $order_id = (int)($_POST['order_id'] ?? 0);
    $inv      = $conn->real_escape_string($_POST['invoice_number'] ?? '');
    if ($order_id <= 0 || $inv === '') { echo json_encode(['success' => false, 'error' => 'Invalid input']); exit(); }

    $fields = [
        'old_r_sph','old_r_cyl','old_r_ax','old_r_add',
        'old_l_sph','old_l_cyl','old_l_ax','old_l_add',
        'new_r_sph','new_r_cyl','new_r_ax','new_r_add','new_r_visus',
        'new_l_sph','new_l_cyl','new_l_ax','new_l_add','new_l_visus',
        'pd_dist','ucva_r','ucva_l',
    ];
    $toggleFields = ['visual_habit','digital_usage','need_distance','need_intermediate','need_near'];

    $curRes = $conn->query("SELECT " . implode(',', array_merge($fields, $toggleFields)) . " FROM customer_examinations WHERE invoice_number = '$inv' LIMIT 1");
    $cur    = $curRes ? $curRes->fetch_assoc() : null;
    if (!$cur) { echo json_encode(['success' => false, 'error' => 'Examination record not found']); exit(); }

    $setParts = [];
    $changes  = [];
    foreach ($fields as $f) {
        if (!array_key_exists($f, $_POST)) continue;
        $val = trim($_POST[$f]);
        if ($val !== (string)$cur[$f]) {
            $setParts[] = "`$f` = " . ($val === '' ? 'NULL' : "'" . $conn->real_escape_string($val) . "'");
            $changes[]  = "$f: \"{$cur[$f]}\" -> \"$val\"";
        }
    }
    foreach ($toggleFields as $f) {
        if (!array_key_exists($f, $_POST)) continue;
        $val = (int)$_POST[$f];
        if ($val !== (int)$cur[$f]) {
            $setParts[] = "`$f` = $val";
            $changes[]  = "$f: {$cur[$f]} -> $val";
        }
    }

    if (empty($setParts)) { echo json_encode(['success' => true, 'changed' => false]); exit(); }

    $ok = $conn->query("UPDATE customer_examinations SET " . implode(', ', $setParts) . " WHERE invoice_number = '$inv'");
    if (!$ok) { echo json_encode(['success' => false, 'error' => $conn->error]); exit(); }

    phAppendEditLog($conn, $order_id, 'exam_results', implode('; ', $changes));
    echo json_encode(['success' => true, 'changed' => true]);
    exit();
}

// ── AJAX: Group "Prescription" — revert / re-apply / new modification ─
if (isset($_POST['action']) && $_POST['action'] === 'edit_group_prescription') {
    header('Content-Type: application/json');
    if (!phEditIsUnlocked()) { echo json_encode(['success' => false, 'error' => 'Session locked. Please verify admin access again.']); exit(); }

    $order_id = (int)($_POST['order_id'] ?? 0);
    $inv      = $conn->real_escape_string($_POST['invoice_number'] ?? '');
    $mode     = $_POST['mode'] ?? '';
    if ($order_id <= 0 || $inv === '' || !in_array($mode, ['revert', 'reapply', 'new_modification'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']); exit();
    }

    if ($mode === 'revert') {
        // Customer decided to keep the original (unmodified) Rx.
        // The old prescription_modifications row is intentionally KEPT for history.
        $ok = $conn->query("UPDATE customer_examinations SET lens_modification = 0 WHERE invoice_number = '$inv'");
        if (!$ok) { echo json_encode(['success' => false, 'error' => $conn->error]); exit(); }
        phAppendEditLog($conn, $order_id, 'prescription', 'Reverted to original prescription (previous modification kept in history, not deleted).');
        echo json_encode(['success' => true, 'lens_modification' => 0]);
        exit();
    }

    if ($mode === 'reapply') {
        // Customer changed their mind again and wants the last recorded modification back.
        $chk = $conn->query("SELECT modification_id FROM prescription_modifications WHERE invoice_number = '$inv' ORDER BY modified_at DESC LIMIT 1");
        if (!$chk || $chk->num_rows === 0) { echo json_encode(['success' => false, 'error' => 'No previous modification found to re-apply.']); exit(); }
        $ok = $conn->query("UPDATE customer_examinations SET lens_modification = 1 WHERE invoice_number = '$inv'");
        if (!$ok) { echo json_encode(['success' => false, 'error' => $conn->error]); exit(); }
        phAppendEditLog($conn, $order_id, 'prescription', 'Re-applied the last recorded prescription modification.');
        echo json_encode(['success' => true, 'lens_modification' => 1]);
        exit();
    }

    if ($mode === 'new_modification') {
        $od_sph  = $conn->real_escape_string($_POST['od_sph']  ?? '');
        $od_cyl  = $conn->real_escape_string($_POST['od_cyl']  ?? '');
        $od_axis = $conn->real_escape_string($_POST['od_axis'] ?? '');
        $od_add  = $conn->real_escape_string($_POST['od_add']  ?? '');
        $os_sph  = $conn->real_escape_string($_POST['os_sph']  ?? '');
        $os_cyl  = $conn->real_escape_string($_POST['os_cyl']  ?? '');
        $os_axis = $conn->real_escape_string($_POST['os_axis'] ?? '');
        $os_add  = $conn->real_escape_string($_POST['os_add']  ?? '');

        $conn->begin_transaction();
        try {
            $ins = $conn->query("INSERT INTO prescription_modifications
                (invoice_number, od_sph, od_cyl, od_axis, od_add, os_sph, os_cyl, os_axis, os_add)
                VALUES ('$inv', '$od_sph', '$od_cyl', '$od_axis', '$od_add', '$os_sph', '$os_cyl', '$os_axis', '$os_add')");
            if (!$ins) throw new Exception($conn->error);

            $upd = $conn->query("UPDATE customer_examinations SET lens_modification = 1 WHERE invoice_number = '$inv'");
            if (!$upd) throw new Exception($conn->error);

            $conn->commit();
            phAppendEditLog($conn, $order_id, 'prescription', "New modification recorded (OD $od_sph/$od_cyl/$od_axis/$od_add, OS $os_sph/$os_cyl/$os_axis/$os_add).");
            echo json_encode(['success' => true, 'lens_modification' => 1]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
}

// ── AJAX: Group "Lens" — swap lens_name on the order ───────────────────
if (isset($_POST['action']) && $_POST['action'] === 'edit_group_lens') {
    header('Content-Type: application/json');
    if (!phEditIsUnlocked()) { echo json_encode(['success' => false, 'error' => 'Session locked. Please verify admin access again.']); exit(); }

    $order_id    = (int)($_POST['order_id'] ?? 0);
    $newLensName = trim($_POST['lens_name'] ?? '');
    if ($order_id <= 0 || $newLensName === '') { echo json_encode(['success' => false, 'error' => 'Invalid input']); exit(); }

    $stmt = $conn->prepare("SELECT lens_name FROM customer_orders WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $cur = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$cur) { echo json_encode(['success' => false, 'error' => 'Order not found']); exit(); }

    $oldLensName = $cur['lens_name'];
    if ($newLensName === $oldLensName) { echo json_encode(['success' => true, 'changed' => false]); exit(); }

    $stmt = $conn->prepare("UPDATE customer_orders SET lens_name = ? WHERE id = ?");
    $stmt->bind_param("si", $newLensName, $order_id);
    if (!$stmt->execute()) { echo json_encode(['success' => false, 'error' => $conn->error]); exit(); }
    $stmt->close();

    phAppendEditLog($conn, $order_id, 'lens', "lens_name: \"$oldLensName\" -> \"$newLensName\"");

    echo json_encode([
        'success'   => true,
        'changed'   => true,
        'lens_name' => $newLensName,
        'lens_cost' => phLensCostLookup($newLensName),
    ]);
    exit();
}

// ── AJAX: Group "Frame" — the complex one (stock + custom_frames) ─────
if (isset($_POST['action']) && $_POST['action'] === 'edit_group_frame') {
    header('Content-Type: application/json');
    if (!phEditIsUnlocked()) { echo json_encode(['success' => false, 'error' => 'Session locked. Please verify admin access again.']); exit(); }

    $order_id = (int)($_POST['order_id'] ?? 0);
    $inv      = $conn->real_escape_string($_POST['invoice_number'] ?? '');
    $mode     = $_POST['mode'] ?? ''; // catalog | custom_new | custom_select | remove
    if ($order_id <= 0 || $inv === '' || !in_array($mode, ['catalog', 'custom_new', 'custom_select', 'remove'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']); exit();
    }

    $stmt = $conn->prepare("SELECT frame_ufc FROM customer_orders WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $curOrder = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$curOrder) { echo json_encode(['success' => false, 'error' => 'Order not found']); exit(); }

    $oldUfc      = trim($curOrder['frame_ufc'] ?? '');
    $oldIsCustom = phIsCustomFrameUfc($oldUfc);

    $conn->begin_transaction();
    try {
        $logParts = [];

        // ── Step 1: release the OLD frame ──────────────────────────
        if ($oldUfc !== '') {
            if ($oldIsCustom) {
                $safeOld = $conn->real_escape_string($oldUfc);
                $oldRow  = $conn->query("SELECT id FROM custom_frames WHERE invoice_number = '$inv' AND brand_key = '$safeOld' LIMIT 1")->fetch_assoc();
                if ($oldRow) {
                    phDeleteCustomFrameAndReclaimId($conn, $oldRow['id']);
                    $logParts[] = "removed custom frame \"$oldUfc\" (customer no longer taking it)";
                }
            } else {
                $restoredTo = phRestoreCatalogStock($conn, $oldUfc);
                if ($restoredTo) {
                    $logParts[] = "restored +1 stock to $restoredTo for old frame \"$oldUfc\"";
                }
            }
        }

        $newUfc = null;

        // ── Step 2: apply the NEW frame ─────────────────────────────
        if ($mode === 'catalog') {
            $newUfc = trim($_POST['new_ufc'] ?? '');
            if ($newUfc === '') throw new Exception('New frame UFC is required.');
            $deductedFrom = phDeductCatalogStock($conn, $newUfc);
            if (!$deductedFrom) throw new Exception("Frame \"$newUfc\" not found or out of stock.");
            $logParts[] = "deducted -1 stock from $deductedFrom for new frame \"$newUfc\"";

        } elseif ($mode === 'custom_select') {
            $brandKey = trim($_POST['brand_key'] ?? '');
            if ($brandKey === '') throw new Exception('brand_key is required.');
            $safeKey = $conn->real_escape_string($brandKey);
            $exists  = $conn->query("SELECT id FROM custom_frames WHERE invoice_number = '$inv' AND brand_key = '$safeKey' LIMIT 1");
            if (!$exists || $exists->num_rows === 0) throw new Exception('Saved custom frame not found for this invoice.');
            // Only one custom frame should be flagged purchased per invoice at a time.
            $conn->query("UPDATE custom_frames SET is_purchased = 0 WHERE invoice_number = '$inv'");
            $conn->query("UPDATE custom_frames SET is_purchased = 1 WHERE invoice_number = '$inv' AND brand_key = '$safeKey'");
            $newUfc = $brandKey;
            $logParts[] = "selected previously-saved custom frame \"$brandKey\"";

        } elseif ($mode === 'custom_new') {
            $brand = trim($_POST['brand'] ?? '');
            $size  = trim($_POST['size']  ?? '');
            $sellPrice = (int)($_POST['sell_price'] ?? 0);
            if ($brand === '' || $sellPrice <= 0) throw new Exception('Brand and sell price are required for a new custom frame.');

            $brandKey = phBuildCustomFrameKey($brand, $size);
            $buyPrice = getCustomFrameBuyPrice($sellPrice);
            $createdBy = $conn->real_escape_string($_SESSION['username'] ?? 'system');
            $safeKey  = $conn->real_escape_string($brandKey);

            // Make sure no other row for this invoice is flagged purchased.
            $conn->query("UPDATE custom_frames SET is_purchased = 0 WHERE invoice_number = '$inv'");

            $ins = $conn->query("INSERT INTO custom_frames
                (invoice_number, brand_key, sell_price, buy_price, is_purchased, created_by)
                VALUES ('$inv', '$safeKey', $sellPrice, $buyPrice, 1, '$createdBy')");
            if (!$ins) throw new Exception($conn->error);

            $newUfc = $brandKey;
            $logParts[] = "added new custom frame \"$brandKey\" (sell Rp$sellPrice)";

        } elseif ($mode === 'remove') {
            // Frame removed entirely, no replacement (frame_ufc becomes NULL).
            $newUfc = null;
            $logParts[] = 'frame removed, no replacement selected';
        }

        // ── Step 3: persist frame_ufc on the order ──────────────────
        if ($newUfc === null) {
            $conn->query("UPDATE customer_orders SET frame_ufc = NULL WHERE id = $order_id");
        } else {
            $safeNew = $conn->real_escape_string($newUfc);
            $conn->query("UPDATE customer_orders SET frame_ufc = '$safeNew' WHERE id = $order_id");
        }

        $conn->commit();

        $summary = ($oldUfc !== '' ? "old frame \"$oldUfc\"" : 'no previous frame') . ' -> '
                 . ($newUfc !== null ? "new frame \"$newUfc\"" : 'removed') . ' | ' . implode('; ', $logParts);
        phAppendEditLog($conn, $order_id, 'frame', $summary);

        // Compute fresh cost/source for the front-end to redraw the card.
        $frameCost   = 0;
        $frameSource = '—';
        if ($newUfc !== null) {
            if (phIsCustomFrameUfc($newUfc)) {
                $safeKey = $conn->real_escape_string($newUfc);
                $cfRow = $conn->query("SELECT buy_price FROM custom_frames WHERE invoice_number = '$inv' AND brand_key = '$safeKey' LIMIT 1")->fetch_assoc();
                $frameCost   = $cfRow ? (int)$cfRow['buy_price'] : 0;
                $frameSource = 'custom';
            } else {
                $safeUfc = $conn->real_escape_string($newUfc);
                foreach (['frames_main', 'frame_staging'] as $tbl) {
                    $r = $conn->query("SELECT buy_price FROM `$tbl` WHERE ufc = '$safeUfc' LIMIT 1");
                    if ($r && $r->num_rows > 0) { $frameCost = (int)$r->fetch_assoc()['buy_price']; $frameSource = 'catalog'; break; }
                }
            }
        }

        echo json_encode([
            'success'      => true,
            'frame_ufc'    => $newUfc,
            'frame_cost'   => $frameCost,
            'frame_source' => $frameSource,
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── AJAX: Group "Order Info" — phone / address / due date ─────────────
if (isset($_POST['action']) && $_POST['action'] === 'edit_group_order_info') {
    header('Content-Type: application/json');
    if (!phEditIsUnlocked()) { echo json_encode(['success' => false, 'error' => 'Session locked. Please verify admin access again.']); exit(); }

    $order_id = (int)($_POST['order_id'] ?? 0);
    if ($order_id <= 0) { echo json_encode(['success' => false, 'error' => 'Invalid input']); exit(); }

    $stmt = $conn->prepare("SELECT customer_phone, customer_address, due_date FROM customer_orders WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $cur = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$cur) { echo json_encode(['success' => false, 'error' => 'Order not found']); exit(); }

    $newPhone   = trim($_POST['customer_phone']   ?? $cur['customer_phone']);
    $newAddress = trim($_POST['customer_address'] ?? $cur['customer_address']);
    $newDue     = trim($_POST['due_date']          ?? $cur['due_date']);

    $changes = [];
    if ($newPhone !== (string)$cur['customer_phone'])     $changes[] = "phone: \"{$cur['customer_phone']}\" -> \"$newPhone\"";
    if ($newAddress !== (string)$cur['customer_address']) $changes[] = "address: \"{$cur['customer_address']}\" -> \"$newAddress\"";
    if ($newDue !== (string)$cur['due_date'])              $changes[] = "due_date: \"{$cur['due_date']}\" -> \"$newDue\"";

    if (empty($changes)) { echo json_encode(['success' => true, 'changed' => false]); exit(); }

    $stmt = $conn->prepare("UPDATE customer_orders SET customer_phone = ?, customer_address = ?, due_date = ? WHERE id = ?");
    $stmt->bind_param("sssi", $newPhone, $newAddress, $newDue, $order_id);
    if (!$stmt->execute()) { echo json_encode(['success' => false, 'error' => $conn->error]); exit(); }
    $stmt->close();

    phAppendEditLog($conn, $order_id, 'order_info', implode('; ', $changes));
    echo json_encode(['success' => true, 'changed' => true, 'customer_phone' => $newPhone, 'customer_address' => $newAddress, 'due_date' => $newDue]);
    exit();
}

    // ── Auto-update packaging_cost based on total_amount ─────────────
    // Rules:
    //   total  50,000 –  89,999 → packaging 14,500
    //   total  90,000 – 499,999 → packaging 19,500
    //   total 500,000+          → packaging 26,500
    // Only update if packaging_cost == 19500 (old default) OR within ±1000 of any tier
    // Tier values — packaging that matches exactly one of these = auto-managed
    $tierValues = [14500, 19500, 26500];

    $autoResult = $conn->query("SELECT id, total_amount, packaging_cost FROM customer_orders WHERE order_status = 5");
    if ($autoResult) {
        while ($autoRow = $autoResult->fetch_assoc()) {
            $curPkg   = (int)$autoRow['packaging_cost'];
            $curTotal = (int)$autoRow['total_amount'];

            // Only auto-manage if packaging_cost is exactly one of the tier values
            // (meaning it hasn't been manually customised to something else)
            if (!in_array($curPkg, $tierValues)) continue;

            // Determine correct tier based on total_amount
            if ($curTotal >= 500000) {
                $newPkg = 26500;
            } elseif ($curTotal >= 90000) {
                $newPkg = 19500;
            } elseif ($curTotal >= 50000) {
                $newPkg = 14500;
            } else {
                continue; // below range — skip
            }

            if ($newPkg !== $curPkg) {
                $updStmt = $conn->prepare("UPDATE customer_orders SET packaging_cost = ? WHERE id = ?");
                $updStmt->bind_param("ii", $newPkg, $autoRow['id']);
                $updStmt->execute();
                $updStmt->close();
            }
        }
        $autoResult->free();
    }

    // ── Auto-set custom_frames buy_price if column missing/zero ────────
    // Add buy_price column if not exists (safe to run every time)
    $conn->query("ALTER TABLE custom_frames ADD COLUMN IF NOT EXISTS buy_price DECIMAL(12,2) NOT NULL DEFAULT 0");

    // Function to get default buy_price from sell_price tier
    function getCustomFrameBuyPrice($sellPrice) {
        $sp = (int)$sellPrice;
        if ($sp <= 90000)          return 20000;
        elseif ($sp <= 150000)     return 30000;
        elseif ($sp <= 180000)     return 36000;
        elseif ($sp <= 200000)     return 42000;
        elseif ($sp <= 250000)     return 54000;
        elseif ($sp <= 300000)     return 62000;
        elseif ($sp <= 350000)     return 70000;
        elseif ($sp <= 400000)     return 92000;
        else                       return 100000;
    }

    // Update custom_frames rows where buy_price = 0 (not yet set)
    $cfResult = $conn->query("SELECT id, sell_price, buy_price FROM custom_frames WHERE buy_price = 0 OR buy_price IS NULL");
    if ($cfResult) {
        while ($cfRow = $cfResult->fetch_assoc()) {
            $defaultBuy = getCustomFrameBuyPrice($cfRow['sell_price']);
            $cfId = (int)$cfRow['id'];
            $conn->query("UPDATE custom_frames SET buy_price = $defaultBuy WHERE id = $cfId");
        }
        $cfResult->free();
    }

    // ── Fetch all finished orders (status 5) ─────────────────────────
    $sql = "
        SELECT
            co.id,
            co.customer_number,
            co.invoice_number,
            co.frame_ufc,
            co.lens_name,
            co.customer_phone,
            co.customer_address,
            co.total_amount,
            co.order_date,
            co.due_date,
            co.order_status,
            COALESCE(co.packaging_cost, 19500) AS packaging_cost,
            ce.customer_name  AS patient_name,
            ce.age,
            ce.gender,
            ce.examination_code
        FROM customer_orders co
        LEFT JOIN customer_examinations ce
            ON co.invoice_number = ce.invoice_number
            AND co.invoice_number != '00'
        WHERE co.order_status = 5
        ORDER BY co.order_date DESC, co.id DESC
    ";
    $result = $conn->query($sql);

    $orders = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
    }

    // ── Build month list from orders ──────────────────────────────────
    $monthList = [];
    foreach ($orders as $o) {
        if (!empty($o['order_date'])) {
            $mk = date('Y-m', strtotime($o['order_date']));
            $ml = date('F Y', strtotime($o['order_date']));
            if (!isset($monthList[$mk])) $monthList[$mk] = $ml;
        }
    }
    krsort($monthList); // latest first

    // ── Build faktur (invoice book) list from customer_number ─────────
    // Format: 1/LZ-C/16.31/001/V/26  → segment[2] = "16.31" → faktur = 16
    $fakturList = [];
    foreach ($orders as $o) {
        $cn = $o['customer_number'] ?? '';
        if (!empty($cn)) {
            $parts = explode('/', $cn);
            if (isset($parts[2])) {
                $sub = explode('.', $parts[2]);
                $fNum = (int)$sub[0];
                if ($fNum > 0 && !in_array($fNum, $fakturList)) {
                    $fakturList[] = $fNum;
                }
            }
        }
    }
    sort($fakturList);

    // ── Summary stats ─────────────────────────────────────────────────
    $totalOrders   = count($orders);
    $totalRevenue  = array_sum(array_column($orders, 'total_amount'));

    // Count this month
    $thisMonth     = date('Y-m');
    $thisMonthCount = 0;
    foreach ($orders as $o) {
        if (!empty($o['order_date']) && date('Y-m', strtotime($o['order_date'])) === $thisMonth) {
            $thisMonthCount++;
        }
    }

    // ── Total net profit (all orders) ────────────────────────────────
    $lensJsonPathEarly = __DIR__ . '/data_json/lense_prices.json';
    $lensCostMapEarly  = [];
    if (file_exists($lensJsonPathEarly)) {
        $ljEarly = json_decode(file_get_contents($lensJsonPathEarly), true);
        foreach (['stock', 'lab'] as $lt) {
            if (!empty($ljEarly[$lt])) {
                foreach ($ljEarly[$lt] as $cat => $types) {
                    foreach ($types as $type => $info) {
                        $k = strtoupper(trim($cat) . ' / ' . trim($type));
                        $lensCostMapEarly[$k] = (int)($info['cost'] ?? 0);
                    }
                }
            }
        }
    }
    $frameCostMapEarly = [];
    $r1 = $conn->query("SELECT ufc, buy_price FROM frames_main WHERE buy_price IS NOT NULL");
    if ($r1) { while ($r = $r1->fetch_assoc()) { $frameCostMapEarly[strtoupper(trim($r['ufc']))] = (int)$r['buy_price']; } $r1->free(); }
    $r2 = $conn->query("SELECT ufc, buy_price FROM frame_staging WHERE buy_price IS NOT NULL");
    if ($r2) { while ($r = $r2->fetch_assoc()) { $k2 = strtoupper(trim($r['ufc'])); if (!isset($frameCostMapEarly[$k2])) $frameCostMapEarly[$k2] = (int)$r['buy_price']; } $r2->free(); }
    $customMapEarly = [];
    $r3 = $conn->query("SELECT invoice_number, buy_price FROM custom_frames");
    if ($r3) { while ($r = $r3->fetch_assoc()) { $customMapEarly[$r['invoice_number']] = (int)$r['buy_price']; } $r3->free(); }

    $totalNetProfit = 0;
    $totalCost      = 0;
    foreach ($orders as $o) {
        $oAmt = (int)$o['total_amount'];
        $oPkg = (int)($o['packaging_cost'] ?? 19500);
        $oLnNorm = preg_replace('/\s*[\x{2014}\x{2013}\/]\s*/u', ' / ', trim($o['lens_name'] ?? ''));
        $oLc  = $lensCostMapEarly[strtoupper($oLnNorm)] ?? 0;
        $oUfc = strtoupper(trim($o['frame_ufc'] ?? ''));
        $oFc  = (strlen($oUfc) > 0 && is_numeric($oUfc[0]))
            ? ($customMapEarly[$o['invoice_number'] ?? ''] ?? 0)
            : ($frameCostMapEarly[$oUfc] ?? 0);
        $totalNetProfit += ($oAmt - $oLc - $oFc - $oPkg);
        $totalCost      += ($oLc + $oFc + $oPkg);
    }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
    <title>Purchase History — Finished Orders</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* ── Page Layout ─────────────────────────────────────── */
        .cs-body {
            padding: 20px;
            max-width: 1100px;
            margin: auto;
        }

        .cs-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .cs-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: 1px;
        }

        .cs-subtitle {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 3px;
            letter-spacing: 0.5px;
        }

        /* ── Smart Filter Bar ────────────────────────────────── */
        .ph-filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            align-items: center;
        }

        .ph-filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .ph-filter-label {
            font-size: 0.58rem;
            color: var(--text-muted);
            letter-spacing: 0.8px;
            text-transform: uppercase;
            padding-left: 4px;
        }

        .ph-filter-icon {
            font-size: 1.05rem;
            vertical-align: middle;
            display: inline-block;
            margin-right: 1px;
        }

        .ph-select {
            background: var(--bg-color);
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 14px;
            color: var(--text-main);
            font-size: 0.78rem;
            font-weight: 600;
            padding: 8px 14px;
            font-family: inherit;
            box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 6px var(--shadow-light);
            outline: none;
            cursor: pointer;
            transition: border-color 0.2s;
            min-width: 140px;
        }

        .ph-select:focus {
            border-color: rgba(0,255,136,0.3);
        }

        .ph-select option {
            background: var(--bg-color);
        }

        /* ── Search bar ──────────────────────────────────────── */
        .cs-search-wrap {
            position: relative;
            flex: 1;
            min-width: 200px;
            max-width: 320px;
        }

        .cs-search {
            width: 100%;
            background: var(--bg-color);
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 20px;
            color: var(--text-main);
            font-size: 0.8rem;
            padding: 9px 16px 9px 38px;
            font-family: inherit;
            box-shadow: inset 4px 4px 8px var(--shadow-dark), inset -4px -4px 8px var(--shadow-light);
            outline: none;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }

        .cs-search:focus { border-color: rgba(0,255,136,0.3); }

        .cs-search-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.25rem;
            pointer-events: none;
        }

        .ph-filter-reset {
            background: var(--bg-color);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            color: var(--text-muted);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            padding: 8px 16px;
            cursor: pointer;
            font-family: inherit;
            box-shadow: 4px 4px 8px var(--shadow-dark), -4px -4px 8px var(--shadow-light);
            transition: all 0.2s;
            align-self: flex-end;
        }

        .ph-filter-reset:hover {
            color: var(--text-main);
            border-color: rgba(255,255,255,0.18);
        }

        /* ── Active filter chips ─────────────────────────────── */
        .ph-active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 16px;
            min-height: 0;
        }

        .ph-active-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(0,255,136,0.08);
            border: 1px solid rgba(0,255,136,0.25);
            border-radius: 20px;
            color: #00ff88;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            padding: 3px 10px 3px 12px;
        }

        .ph-active-chip button {
            background: none;
            border: none;
            color: #00ff88;
            cursor: pointer;
            font-size: 0.75rem;
            padding: 0;
            line-height: 1;
            opacity: 0.7;
        }

        .ph-active-chip button:hover { opacity: 1; }

        /* ── Summary stat cards ──────────────────────────────── */
        .cs-stats-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .cs-stat-card {
            flex: 1;
            min-width: 120px;
            background: var(--bg-color);
            border-radius: 16px;
            padding: 14px 16px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.04);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .cs-stat-num {
            font-size: 1.6rem;
            font-weight: 900;
            line-height: 1;
        }

        .cs-stat-label {
            font-size: 0.62rem;
            color: var(--text-muted);
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }

        .cs-stat-card.full-width {
            flex-basis: 100%;
            width: 100%;
        }

        /* ── Result count ────────────────────────────────────── */
        .ph-result-count {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-bottom: 14px;
            letter-spacing: 0.5px;
        }

        .ph-result-count span {
            color: #00ff88;
            font-weight: 700;
        }

        /* ── Order card ──────────────────────────────────────── */
        .cs-card {
            position: relative;
            background: var(--bg-color);
            border-radius: 20px;
            padding: 20px 22px;
            margin-bottom: 14px;
            box-shadow: 8px 8px 20px var(--shadow-dark), -8px -8px 20px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.04);
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .cs-card:hover {
            transform: translateY(-1px);
            box-shadow: 10px 10px 24px var(--shadow-dark), -10px -10px 24px var(--shadow-light);
        }

        .cs-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .cs-patient-info { display: flex; flex-direction: column; gap: 3px; }

        .cs-patient-name {
            font-size: 1rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: 0.5px;
        }

        .cs-meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 4px;
        }

        .cs-chip {
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.6px;
            padding: 3px 10px;
            border-radius: 20px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            color: var(--text-muted);
        }

        .cs-chip.inv   { color: #00cfff; border-color: rgba(0,207,255,0.25);    background: rgba(0,207,255,0.07); }
        .cs-chip.cust  { color: #aa88ff; border-color: rgba(170,136,255,0.25);  background: rgba(170,136,255,0.07); }
        .cs-chip.age   { color: #ffaa00; border-color: rgba(255,170,0,0.25);    background: rgba(255,170,0,0.07); }
        .cs-chip.done  { color: #00ff88; border-color: rgba(0,255,136,0.25);    background: rgba(0,255,136,0.07); }

        /* ── Symptom analysis PDF button ─────────────────────── */
        .cs-pdf-btn {
            position: absolute;
            top: 16px;
            right: 20px;
            font-size: 1.05rem;
            line-height: 1;
            padding: 0;
            background: none;
            border: none;
            color: var(--text-main);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-family: inherit;
            transition: transform 0.2s ease, filter 0.2s ease;
        }
        .cs-pdf-btn b { font-weight: 900; }
        .cs-pdf-btn:hover {
            transform: scale(1.15) translateY(-1px);
            filter: drop-shadow(0 2px 6px rgba(255,59,59,0.5));
        }
        .cs-pdf-btn:active { transform: scale(1.02); }

        /* ── Edit-order button (opens the full multi-group editor) ── */
        .ph-edit-order-btn {
            position: absolute;
            top: 16px;
            right: 62px; /* sits left of the PDF button so they never overlap */
            font-size: 0.95rem;
            line-height: 1;
            padding: 0;
            background: none;
            border: none;
            color: #ffaa00;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-family: inherit;
            transition: transform 0.2s ease, filter 0.2s ease;
        }
        .ph-edit-order-btn b { font-weight: 900; font-size: 0.65rem; letter-spacing: 0.5px; }
        .ph-edit-order-btn:hover { transform: scale(1.1) translateY(-1px); filter: drop-shadow(0 2px 6px rgba(255,170,0,0.5)); }
        .ph-edit-order-btn:active { transform: scale(1.02); }

        /* ── Finished badge ──────────────────────────────────── */
        .cs-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.8px;
            border: 1px solid;
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* ── Details grid ────────────────────────────────────── */
        .cs-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid rgba(255,255,255,0.05);
        }

        .cs-detail-item { display: flex; flex-direction: column; gap: 3px; }

        .cs-detail-label {
            font-size: 0.62rem;
            color: var(--text-muted);
            letter-spacing: 0.7px;
            text-transform: uppercase;
        }

        .cs-detail-value {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .cs-detail-value.price { color: #ffaa00; font-family: monospace; }
        .cs-detail-value.paid  { color: #00ff88; }

        /* ── Collapsible card body ───────────────────────────── */
        .cs-card-header {
            cursor: pointer;
            user-select: none;
        }

        .cs-card-header:hover .cs-patient-name {
            color: #00ff88;
            transition: color 0.2s;
        }

        .cs-card-body {
            overflow: hidden;
            max-height: 0;
            transition: max-height 0.35s ease, opacity 0.3s ease;
            opacity: 0;
        }

        .cs-card.expanded .cs-card-body {
            max-height: 600px;
            opacity: 1;
        }

        .cs-chevron {
            font-size: 0.7rem;
            color: var(--text-muted);
            transition: transform 0.3s;
            flex-shrink: 0;
        }

        .cs-card.expanded .cs-chevron { transform: rotate(180deg); }

        /* ── Empty state ─────────────────────────────────────── */
        .cs-empty {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .cs-empty-icon  { font-size: 2.5rem; margin-bottom: 12px; }
        .cs-empty-title { font-size: 1rem; font-weight: 700; color: var(--text-main); }
        .cs-empty-sub   { font-size: 0.75rem; margin-top: 5px; }

        /* ── Toast ───────────────────────────────────────────── */
        #ph-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: var(--bg-color);
            border: 1px solid rgba(0,255,136,0.35);
            border-radius: 14px;
            color: #00ff88;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 12px 20px;
            box-shadow: 0 0 20px rgba(0,255,136,0.15);
            z-index: 9999;
            opacity: 0;
            transform: translateY(12px);
            transition: all 0.3s;
            pointer-events: none;
        }

        #ph-toast.show { opacity: 1; transform: translateY(0); }

        /* ── Month divider ───────────────────────────────────── */
        .ph-month-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0 12px;
        }

        .ph-month-divider-label {
            font-size: 0.68rem;
            font-weight: 800;
            color: var(--text-muted);
            letter-spacing: 1px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .ph-month-divider-line {
            flex: 1;
            height: 1px;
            background: rgba(255,255,255,0.06);
        }

        /* ── Year / Month accordion groups ───────────────────── */
        .ph-year-group {
            border-radius: 16px;
            background: var(--bg-color);
            box-shadow: 6px 6px 14px rgba(0,0,0,0.35), -6px -6px 14px rgba(255,255,255,0.03);
            margin-bottom: 16px;
            overflow: hidden;
        }

        .ph-year-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 16px 20px;
            cursor: pointer;
            user-select: none;
        }

        .ph-year-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: #00cfff;
            letter-spacing: 0.3px;
        }

        .ph-year-count {
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--text-muted);
            margin-left: auto;
            margin-right: 8px;
        }

        .ph-year-arrow,
        .ph-month-arrow {
            display: inline-block;
            font-size: 0.75rem;
            color: var(--text-muted);
            transition: transform 0.25s ease;
        }

        .ph-year-group.expanded > .ph-year-header .ph-year-arrow { transform: rotate(180deg); }

        .ph-year-body {
            display: none;
            padding: 0 14px 14px;
        }

        .ph-year-group.expanded > .ph-year-body { display: block; }

        .ph-month-group {
            border-radius: 12px;
            background: rgba(255,255,255,0.02);
            margin-bottom: 10px;
            overflow: hidden;
        }

        .ph-month-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 16px;
            cursor: pointer;
            user-select: none;
        }

        .ph-month-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--text-color, #eaeaea);
        }

        .ph-month-count {
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--text-muted);
            margin-left: auto;
            margin-right: 8px;
        }

        .ph-month-group.expanded > .ph-month-header .ph-month-arrow { transform: rotate(180deg); }

        .ph-month-body {
            display: none;
            padding: 4px 10px 10px;
        }

        .ph-month-group.expanded > .ph-month-body { display: block; }

        /* ── Responsive ──────────────────────────────────────── */
        @media (max-width: 600px) {
            .cs-body { padding: 10px; max-width: 100%; box-sizing: border-box; overflow-x: hidden; }

            .cs-header {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                margin-bottom: 16px;
            }
            .cs-title { font-size: 1.1rem; }
            .cs-subtitle { font-size: 0.68rem; }

            .ph-filter-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .ph-filter-group { width: 100%; }

            .cs-search-wrap { max-width: 100%; width: 100%; }

            .ph-select { width: 100%; min-width: 0; box-sizing: border-box; }

            .ph-filter-reset { align-self: stretch; width: 100%; box-sizing: border-box; }

            /* Stat cards: 2-column grid instead of squished flex row */
            .cs-stats-row {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }
            .cs-stat-card { min-width: 0; padding: 10px 12px; }
            .cs-stat-card.full-width { grid-column: 1 / -1; }
            .cs-stat-num  { font-size: 1.1rem; word-break: break-word; }
            .cs-stat-label { font-size: 0.56rem; }

            .cs-card { padding: 14px; border-radius: 16px; }

            .cs-card-header.cs-card-top {
                flex-direction: row;
                align-items: flex-start;
                gap: 8px;
                flex-wrap: nowrap;
            }

            .cs-card-header .cs-patient-info { flex: 1 1 auto; min-width: 0; }
            .cs-card-header .cs-patient-name {
                font-size: 0.9rem;
                white-space: normal;
                word-break: break-word;
            }
            .cs-status-badge { font-size: 0.6rem; padding: 4px 9px; }
            .cs-chip {
                font-size: 0.58rem;
                padding: 2px 7px;
                max-width: 100%;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .cs-meta-row { gap: 5px; }

            .cs-card-header .ph-header-total { font-size: 0.66rem; }

            .cs-details-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px 8px;
            }

            .cs-detail-value {
                font-size: 0.78rem;
                word-break: break-word;
            }
            .cs-detail-value.price { word-break: break-all; }

            /* Address gets its own full-width row */
            .cs-details-grid > .cs-detail-item:last-child {
                grid-column: 1 / -1;
            }

            #ph-toast { left: 12px; right: 12px; bottom: 16px; text-align: center; }

            .btn-group { padding: 0 10px; }
            .btn-group .back-main { width: 100%; box-sizing: border-box; }
        }

        @media (max-width: 380px) {
            .cs-details-grid { grid-template-columns: 1fr; }
            .cs-patient-name { font-size: 0.85rem; }
        }




        /* ── Net profit highlight ────────────────────────────── */
        .ph-net-profit {
            display: inline-flex;
            align-items: baseline;
            gap: 4px;
        }

        /* ── Edit total button ───────────────────────────────── */
        .ph-edit-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.75rem;
            padding: 0 0 0 5px;
            vertical-align: middle;
            opacity: 0.5;
            transition: opacity 0.2s;
            line-height: 1;
        }
        .ph-edit-btn:hover { opacity: 1; }

        /* ── Edit modal ──────────────────────────────────────── */
        .ph-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.75);
            z-index: 9000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .ph-modal-overlay.open { display: flex; }

        .ph-modal {
            background: var(--bg-color);
            border-radius: 24px;
            padding: 28px;
            max-width: 420px;
            width: 100%;
            box-shadow: 20px 20px 60px var(--shadow-dark), -20px -20px 60px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.07);
        }

        .ph-modal-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .ph-modal-sub {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-bottom: 20px;
        }

        .ph-modal-field {
            margin-bottom: 14px;
        }

        .ph-modal-field label {
            display: block;
            font-size: 0.62rem;
            color: var(--text-muted);
            letter-spacing: 0.7px;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .ph-modal-input {
            width: 100%;
            background: var(--bg-color);
            border: 1px solid rgba(255,255,255,0.09);
            border-radius: 14px;
            color: var(--text-main);
            font-size: 0.85rem;
            font-weight: 600;
            padding: 10px 14px;
            font-family: inherit;
            box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 6px var(--shadow-light);
            outline: none;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }

        .ph-modal-input:focus { border-color: rgba(0,255,136,0.35); }

        .ph-modal-input.password-input { letter-spacing: 2px; }

        .ph-modal-preview {
            font-size: 0.72rem;
            color: #ffaa00;
            font-weight: 700;
            margin-top: 6px;
            min-height: 18px;
            font-family: monospace;
        }

        .ph-modal-preview.error { color: #ff6b6b; }

        .ph-modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .ph-modal-btn {
            flex: 1;
            padding: 10px 16px;
            border-radius: 14px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.6px;
            cursor: pointer;
            font-family: inherit;
            border: 1px solid;
            transition: all 0.2s;
        }

        .ph-modal-btn.cancel {
            background: var(--bg-color);
            border-color: rgba(255,255,255,0.1);
            color: var(--text-muted);
            box-shadow: 4px 4px 8px var(--shadow-dark), -4px -4px 8px var(--shadow-light);
        }

        .ph-modal-btn.cancel:hover { color: var(--text-main); }

        .ph-modal-btn.confirm {
            background: rgba(0,255,136,0.1);
            border-color: rgba(0,255,136,0.35);
            color: #00ff88;
        }

        .ph-modal-btn.confirm:hover {
            background: rgba(0,255,136,0.2);
            box-shadow: 0 0 12px rgba(0,255,136,0.2);
        }

        .ph-modal-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        @media (max-width: 600px) {
            .ph-modal { padding: 20px 18px 28px; border-radius: 24px 24px 0 0; }
            .ph-modal-overlay { align-items: flex-end; padding: 0; }
            .ph-modal-actions { flex-direction: column; }
        }

        /* ── Edit Order modal (wide, tabbed) ─────────────────────── */
        .ph-modal.ph-modal-wide { max-width: 640px; max-height: 88vh; overflow-y: auto; }

        .ph-eo-tabs {
            display: flex; flex-wrap: wrap; gap: 6px;
            margin: 4px 0 18px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding-bottom: 12px;
        }
        .ph-eo-tab {
            font-family: inherit; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.4px;
            padding: 7px 12px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.09);
            background: var(--bg-color); color: var(--text-muted); cursor: pointer;
            box-shadow: 3px 3px 6px var(--shadow-dark), -3px -3px 6px var(--shadow-light);
            transition: all 0.2s;
        }
        .ph-eo-tab.active { color: #00ff88; border-color: rgba(0,255,136,0.4); background: rgba(0,255,136,0.08); }

        .ph-eo-group { display: none; }
        .ph-eo-group.active { display: block; }

        .ph-eo-rx-grid {
            display: grid; grid-template-columns: 55px repeat(4, 1fr);
            gap: 6px; align-items: center;
        }
        .ph-eo-rx-head { font-size: 0.58rem; color: var(--text-muted); text-align: center; letter-spacing: 0.5px; }
        .ph-eo-rx-label { font-size: 0.62rem; font-weight: 800; color: #aa88ff; }
        .ph-eo-rx-grid input.ph-modal-input { padding: 8px 6px; text-align: center; font-size: 0.75rem; }

        .ph-eo-check {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 0.65rem; color: var(--text-muted); cursor: pointer;
            background: var(--bg-color); border: 1px solid rgba(255,255,255,0.08);
            border-radius: 10px; padding: 6px 10px;
        }
        .ph-eo-check input { accent-color: #00ff88; }

        .ph-eo-note {
            font-size: 0.68rem; color: var(--text-muted); line-height: 1.5;
            background: rgba(255,170,0,0.06); border: 1px solid rgba(255,170,0,0.2);
            border-radius: 12px; padding: 10px 12px; margin: 10px 0;
        }

        .ph-eo-subtabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
        .ph-eo-subtab {
            font-family: inherit; font-size: 0.62rem; font-weight: 700;
            padding: 6px 10px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);
            background: var(--bg-color); color: var(--text-muted); cursor: pointer;
        }
        .ph-eo-subtab.active { color: #ffaa00; border-color: rgba(255,170,0,0.4); background: rgba(255,170,0,0.08); }

        .ph-eo-fpanel { display: none; }
        .ph-eo-fpanel.active { display: block; }

        .ph-eo-custom-item {
            display: flex; justify-content: space-between; align-items: center; gap: 8px;
            padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.08);
            background: var(--bg-color); cursor: pointer; font-size: 0.72rem;
        }
        .ph-eo-custom-item.selected { border-color: rgba(0,255,136,0.4); background: rgba(0,255,136,0.06); }
    </style>
    <!-- button logout, back animation for logo -->
    <style>
        .neu-button.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
            filter: grayscale(1);
        }

        /* ===== New neumorphic style for Back & Logout buttons ===== */
        .neu-pill-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #1c1e22;
            border: none;
            border-radius: 32px;
            padding: 6px 16px 6px 6px;
            cursor: pointer;
            box-shadow:
                6px 6px 14px rgba(0, 0, 0, 0.55),
                -6px -6px 14px rgba(255, 255, 255, 0.03);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            font-family: inherit;
        }

        .neu-pill-btn:hover {
            box-shadow:
                6px 6px 16px rgba(0, 0, 0, 0.6),
                -6px -6px 16px rgba(255, 255, 255, 0.04);
        }

        .neu-pill-btn:active {
            transform: scale(0.96);
        }

        /* Overflow hidden so the icon can slide across without spilling out */
        .neu-pill-btn {
            overflow: hidden;
        }

        .neu-pill-icon {
            width: 32px;
            height: 32px;
            min-width: 32px;
            border-radius: 50%;
            background: #17181b;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 10px rgba(103, 232, 249, 0.35);
            transition: box-shadow 0.15s ease, transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Pressed state: icon slides to the right, text fades and slides out */
        .neu-pill-btn.pressed {
            box-shadow:
                inset 4px 4px 10px rgba(0, 0, 0, 0.6),
                inset -4px -4px 10px rgba(255, 255, 255, 0.03);
        }

        .neu-pill-btn.pressed .neu-pill-icon {
            transform: translateX(calc(100% + 24px));
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 18px rgba(103, 232, 249, 0.7);
        }

        .neu-pill-btn.pressed .neu-pill-text {
            opacity: 0;
            transform: translateX(15px);
        }

        .neu-pill-btn.pressed .neu-pill-icon,
        .neu-pill-btn:active .neu-pill-icon {
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 18px rgba(103, 232, 249, 0.7);
        }

        .neu-pill-icon svg {
            width: 15px;
            height: 15px;
            stroke: #7fe3f0;
            filter: drop-shadow(0 0 4px rgba(103, 232, 249, 0.8));
        }

        .neu-pill-text {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
            text-align: left;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }

        .neu-pill-text .line1 {
            font-weight: 700;
            font-size: 10px;
            letter-spacing: 0.4px;
            color: #f2f2f2;
        }

        .neu-pill-text .line2 {
            font-weight: 400;
            font-size: 9px;
            letter-spacing: 0.4px;
            color: #9a9da1;
        }

        /* Logout variant: warm amber/orange tone instead of cyan */
        .neu-pill-btn.logout-variant .neu-pill-icon {
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 10px rgba(255, 138, 101, 0.4);
        }

        .neu-pill-btn.logout-variant.pressed .neu-pill-icon {
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 18px rgba(255, 138, 101, 0.75);
        }

        .neu-pill-btn.logout-variant .neu-pill-icon svg {
            stroke: #ff8a65;
            filter: drop-shadow(0 0 4px rgba(255, 138, 101, 0.8));
        }

        /* ===== Logo zoom (fly window) effect ===== */
        .logo-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0);
            backdrop-filter: blur(0px);
            -webkit-backdrop-filter: blur(0px);
            z-index: 999;
            opacity: 0;
            pointer-events: none;
            transition: background 0.3s ease, opacity 0.3s ease, backdrop-filter 0.3s ease;
        }

        .logo-backdrop.active {
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            opacity: 1;
            pointer-events: auto;
        }

        .logo-box img {
            cursor: pointer;
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        top 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        left 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .logo-box img.zoomed {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(2.8);
            z-index: 1000;
        }

        /* Center the header block (logout button + logo/name/address group)
           on PC to match how it already appears centered on mobile. Only
           the container's own horizontal position is changed here — the
           internal layout is left exactly as in the original code. */
        .header-container {
            margin-left: auto !important;
            margin-right: auto !important;
            width: fit-content !important;
        }
    </style>
</head>
<body>
    <div class="main-wrapper">
        <div class="content-area" style="flex-direction: column">
            <div class="header-container">
                <button type="button" class="logout-btn neu-pill-btn logout-variant" id="logoutBtn" onclick="handleLogoutClick(this)">
                    <span class="neu-pill-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                            <polyline points="16 17 21 12 16 7"></polyline>
                            <line x1="21" y1="12" x2="9" y2="12"></line>
                        </svg>
                    </span>
                    <span class="neu-pill-text">
                        <span class="line1">LOGOUT</span>
                    </span>
                </button>
            
                <div class="brand-section">
                    <div class="logo-box">
                        <img id="storeLogo" src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" style="height: 40px;" onclick="zoomInLogo(this)" ondblclick="zoomOutLogo(this)">
                    </div>
                    <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                    <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
                </div>
            </div>

        <div class="main-card" style="margin-left: auto; margin-right: auto; width: 100%;">
    <div class="cs-body">

        <!-- ── Page Header ─────────────────────────────────────── -->
        <div class="cs-header">
            <div>
                <div class="cs-title">🏁 Purchase History</div>
                <div class="cs-subtitle">COMPLETED ORDERS — STATUS 5 (FINISHED)</div>
            </div>
        </div>

        <!-- ── Summary Stats ───────────────────────────────────── -->
        <div class="cs-stats-row">
            <div class="cs-stat-card">
                <div class="cs-stat-num" id="ph-finished-display" style="color:#00ff88;"><?php echo $totalOrders; ?></div>
                <div class="cs-stat-label">🏁 Total Finished</div>
            </div>
            <div class="cs-stat-card">
                <div class="cs-stat-num" id="ph-revenue-display" style="color:#ffaa00;font-size:1.1rem;">Rp <?php echo number_format($totalRevenue, 0, ',', '.'); ?></div>
                <div class="cs-stat-label">💰 Total Revenue</div>
            </div>
            <div class="cs-stat-card">
                <div class="cs-stat-num" id="ph-month-display" style="color:#00cfff;"><?php echo $thisMonthCount; ?></div>
                <div class="cs-stat-label">📅 This Month</div>
            </div>
            <div class="cs-stat-card">
                <div class="cs-stat-num" id="ph-profit-display" style="color:<?php echo $totalNetProfit >= 0 ? '#00ff88' : '#ff6b6b'; ?>;font-size:1.1rem;">
                    <?php echo ($totalNetProfit >= 0 ? '' : '-') . 'Rp ' . number_format(abs($totalNetProfit), 0, ',', '.'); ?>
                </div>
                <div class="cs-stat-label">💹 Total Net Profit</div>
            </div>
            <div class="cs-stat-card full-width">
                <div class="cs-stat-num" id="ph-cost-display" style="color:#ff6b6b;font-size:1.1rem;">
                    Rp <?php echo number_format($totalCost, 0, ',', '.'); ?>
                </div>
                <div class="cs-stat-label">💸 Total Cost</div>
            </div>

        </div>

        <!-- ── Smart Filter Bar ────────────────────────────────── -->
        <div class="ph-filter-bar">

            <!-- Search -->
            <div class="ph-filter-group" style="flex:1;min-width:200px;">
                <div class="ph-filter-label"><span class="ph-filter-icon">🔍</span> Search</div>
                <div class="cs-search-wrap" style="max-width:100%;">
                    <span class="cs-search-icon">🔍</span>
                    <input type="text" class="cs-search" id="ph-search"
                           placeholder="Name, invoice, phone, frame…"
                           oninput="phApplyFilters()">
                </div>
            </div>

            <!-- Month filter -->
            <div class="ph-filter-group">
                <div class="ph-filter-label"><span class="ph-filter-icon">📅</span> Month</div>
                <select class="ph-select" id="ph-filter-month" onchange="phApplyFilters()">
                    <option value="">All Months</option>
                    <?php foreach ($monthList as $mk => $ml): ?>
                    <option value="<?php echo $mk; ?>"><?php echo $ml; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Gender filter -->
            <div class="ph-filter-group">
                <div class="ph-filter-label"><span class="ph-filter-icon">👤</span> Gender</div>
                <select class="ph-select" id="ph-filter-gender" onchange="phApplyFilters()">
                    <option value="">All</option>
                    <option value="male">Male 👨</option>
                    <option value="female">Female 👩</option>
                </select>
            </div>

            <!-- Faktur filter -->
            <div class="ph-filter-group">
                <div class="ph-filter-label"><span class="ph-filter-icon">📒</span> Invoice Book</div>
                <select class="ph-select" id="ph-filter-faktur" onchange="phApplyFilters()">
                    <option value="">All Books</option>
                    <?php foreach ($fakturList as $fNum): ?>
                    <option value="<?php echo $fNum; ?>">Book #<?php echo $fNum; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

<!-- Sort -->
            <div class="ph-filter-group">
                <div class="ph-filter-label"><span class="ph-filter-icon">↕</span> Sort</div>
                <select class="ph-select" id="ph-sort" onchange="phApplyFilters()">
                    <option value="date_desc">Newest First</option>
                    <option value="date_asc">Oldest First</option>
                    <option value="name_asc">Name A–Z</option>
                    <option value="name_desc">Name Z–A</option>
                    <option value="total_desc">Highest Total</option>
                    <option value="total_asc">Lowest Total</option>
                </select>
            </div>

            <button class="ph-filter-reset" onclick="phResetFilters()" title="Reset all filters">✕ Reset</button>
        </div>

        <!-- ── Active filter chips ──────────────────────────────── -->
        <div class="ph-active-filters" id="ph-active-filters"></div>

        <!-- ── Result count ─────────────────────────────────────── -->
        <div class="ph-result-count" id="ph-result-count"></div>

        <!-- ── Order Cards ─────────────────────────────────────── -->
        <?php if (empty($orders)): ?>
        <div class="cs-empty">
            <div class="cs-empty-icon">📭</div>
            <div class="cs-empty-title">No finished orders yet</div>
            <div class="cs-empty-sub">Completed orders will appear here once their status is set to Finished.</div>
        </div>
        <?php else: ?>

        <div id="ph-cards-container">
        <?php
    // ── Build lens cost lookup from lense_prices.json ──────────────────
    $lensJsonPath = __DIR__ . '/data_json/lense_prices.json';
    $lensCostMap  = []; // key: "CATEGORY / TYPE" (uppercase) => cost
    if (file_exists($lensJsonPath)) {
        $lensJson = json_decode(file_get_contents($lensJsonPath), true);
        foreach (['stock', 'lab'] as $lensType) {
            if (!empty($lensJson[$lensType])) {
                foreach ($lensJson[$lensType] as $cat => $types) {
                    foreach ($types as $type => $info) {
                        $key = strtoupper(trim($cat) . ' / ' . trim($type));
                        $lensCostMap[$key] = ['cost' => (int)($info['cost'] ?? 0), 'type' => $lensType];
                    }
                }
            }
        }
    }

    // ── Build frame cost lookup ───────────────────────────────────────
    $frameCostMap = [];
    $fmRes = $conn->query("SELECT ufc, buy_price FROM frames_main WHERE buy_price IS NOT NULL");
    if ($fmRes) { while ($r = $fmRes->fetch_assoc()) { $frameCostMap[strtoupper(trim($r['ufc']))] = (int)$r['buy_price']; } $fmRes->free(); }
    $fsRes = $conn->query("SELECT ufc, buy_price FROM frame_staging WHERE buy_price IS NOT NULL");
    if ($fsRes) { while ($r = $fsRes->fetch_assoc()) { $key = strtoupper(trim($r['ufc'])); if (!isset($frameCostMap[$key])) $frameCostMap[$key] = (int)$r['buy_price']; } $fsRes->free(); }

    // ── Pre-fetch custom_frames keyed by invoice_number ───────────────
    $customFrameMap = [];
    $cfRes = $conn->query("SELECT id, invoice_number, sell_price, buy_price FROM custom_frames");
    if ($cfRes) { while ($r = $cfRes->fetch_assoc()) { $customFrameMap[$r['invoice_number']] = $r; } $cfRes->free(); }
        ?>

        <?php foreach ($orders as $o):
            $name      = trim($o['patient_name'] ?? '—');
            $age       = (int)($o['age'] ?? 0);
            $gender    = strtolower(trim($o['gender'] ?? ''));
            $genderIcon = ($gender === 'male' || $gender === 'laki-laki' || $gender === 'm') ? '👨' : '👩';
            $phone     = $o['customer_phone'] ?? '';
            $lensName  = $o['lens_name'] ?? '—';
            $frameUfc  = $o['frame_ufc'] ?? '—';
            $totalAmt  = (int)$o['total_amount'];
            $orderDate = $o['order_date'] ? date('d/m/Y', strtotime($o['order_date'])) : '—';
            $orderMonth = $o['order_date'] ? date('Y-m', strtotime($o['order_date'])) : '';
            $dueDate   = $o['due_date']   ? date('d/m/Y', strtotime($o['due_date']))   : '—';
            $genderNorm = ($gender === 'male' || $gender === 'laki-laki' || $gender === 'm') ? 'male' : 'female';
            $pkgTotal   = (int)($o['packaging_cost'] ?? 19500);
            // Default breakdown for modal
            $pkgBox      = 3000;
            $pkgFlanel   = 500;
            $pkgFaset    = 10000;
            $pkgWrapping = 3000;

            // ── Extract faktur number from customer_number ────────────
            // Format: 1/LZ-C/16.31/001/V/26 → parts[2] = "16.31" → faktur = 16
            $fakturNum = 0;
            $_cnParts = explode('/', $o['customer_number'] ?? '');
            if (isset($_cnParts[2])) {
                $_cnSub = explode('.', $_cnParts[2]);
                $fakturNum = (int)$_cnSub[0];
            }

            // ── Lens cost ─────────────────────────────────────────────
            // Detect stock vs lab: diff <= 3 days = stock, >= 10 days = lab
            // Normalize lens_name: DB uses em dash (SINGLE VISION — ONE-DRIVE)
            // JSON keys use slash (SINGLE VISION / ONE-DRIVE)
            $lensNameNorm = preg_replace('/\s*[\x{2014}\x{2013}\/]\s*/u', ' / ', trim($lensName));
            $lensKey      = strtoupper($lensNameNorm);
            $diffDays    = 0;
            if (!empty($o['order_date']) && !empty($o['due_date'])) {
                $diffDays = (int)round((strtotime(date('Y-m-d', strtotime($o['due_date']))) - strtotime(date('Y-m-d', strtotime($o['order_date'])))) / 86400);
            }
            $lensType    = ($diffDays >= 10) ? 'lab' : 'stock';
            $lensCost    = 0;
            $lensSource  = '—';
            // Try exact key match first
            if (isset($lensCostMap[$lensKey]) && $lensCostMap[$lensKey]['type'] === $lensType) {
                $lensCost   = $lensCostMap[$lensKey]['cost'];
                $lensSource = $lensKey;
            } else {
                // Try any type (fallback)
                if (isset($lensCostMap[$lensKey])) {
                    $lensCost   = $lensCostMap[$lensKey]['cost'];
                    $lensSource = $lensKey;
                }
            }

            // ── Frame cost ────────────────────────────────────────────
            $ufcUpper    = strtoupper(trim($frameUfc));
            $frameCost   = 0;
            $frameSource = '—';
            $isCustom    = false;
            $customFrameData = null;

            // Detect custom frame: UFC starts with digit
            if (strlen($ufcUpper) > 0 && is_numeric($ufcUpper[0])) {
                $isCustom = true;
                $inv      = $o['invoice_number'] ?? '';
                if (isset($customFrameMap[$inv])) {
                    $customFrameData = $customFrameMap[$inv];
                    $frameCost   = (int)$customFrameData['buy_price'];
                    $frameSource = 'custom';
                }
            } else {
                // Look up in frames_main then frame_staging
                if (isset($frameCostMap[$ufcUpper])) {
                    $frameCost   = $frameCostMap[$ufcUpper];
                    $frameSource = 'catalog';
                }
            }

            // ── Net profit ────────────────────────────────────────────
            $netProfit   = $totalAmt - $lensCost - $frameCost - $pkgTotal;

            // ── Symptom analysis PDF (matched by examination_code) ─────
            // DB stores as "LZ/EC/028/IX/2025", file is saved as "LZ-EC-028-IX-2025.pdf"
            $examCode    = trim($o['examination_code'] ?? '');
            $examCodeFile= str_replace('/', '-', $examCode);
            $pdfFileName = $examCodeFile . '.pdf';
            $hasPdfFile  = ($examCode !== '') && file_exists(__DIR__ . '/pdf_file/' . $pdfFileName);
        ?>
        <div class="cs-card"
             data-name="<?php echo htmlspecialchars(strtolower($name)); ?>"
             data-inv="<?php echo htmlspecialchars(strtolower($o['invoice_number'] ?? '')); ?>"
             data-phone="<?php echo htmlspecialchars(strtolower($phone)); ?>"
             data-custnum="<?php echo htmlspecialchars(strtolower($o['customer_number'] ?? '')); ?>"
             data-frame="<?php echo htmlspecialchars(strtolower($frameUfc)); ?>"
             data-lens="<?php echo htmlspecialchars(strtolower($lensName)); ?>"
             data-month="<?php echo $orderMonth; ?>"
             data-gender="<?php echo $genderNorm; ?>"
             data-id="<?php echo $o['id']; ?>"
             data-total="<?php echo $totalAmt; ?>"
             data-fullname="<?php echo htmlspecialchars($name); ?>"
             data-orderdate-raw="<?php echo htmlspecialchars($o['order_date'] ?? ''); ?>"
             data-pkg-total="<?php echo $pkgTotal; ?>"
             data-total-amount="<?php echo $totalAmt; ?>"
             data-net-profit="<?php echo $netProfit; ?>"
             data-cost="<?php echo $lensCost + $frameCost + $pkgTotal; ?>"
             data-lens-cost="<?php echo $lensCost; ?>"
             data-frame-cost="<?php echo $frameCost; ?>"
             data-is-custom="<?php echo $isCustom ? '1' : '0'; ?>"
             data-invoice="<?php echo htmlspecialchars($o['invoice_number'] ?? ''); ?>"
             data-faktur="<?php echo $fakturNum; ?>">

            <!-- Header (clickable) -->
            <div class="cs-card-header cs-card-top" onclick="csToggleCard(this)">
                <div class="cs-patient-info">
                    <div class="cs-patient-name">
                        <?php echo htmlspecialchars($name); ?> <?php echo $genderIcon; ?>
                    </div>
                    <div class="cs-meta-row">
                        <span class="cs-chip inv">INV: <?php echo htmlspecialchars($o['invoice_number'] ?? '—'); ?></span>
                        <span class="cs-chip cust"><?php echo htmlspecialchars($o['customer_number'] ?? '—'); ?></span>
                        <?php if ($age > 0): ?>
                        <span class="cs-chip age"><?php echo $age; ?> yrs</span>
                        <?php endif; ?>
                        <span class="cs-chip done">🏁 FINISHED</span>
                    </div>
                </div>
                <?php if ($hasPdfFile): ?>
                <button type="button" class="cs-pdf-btn" title="View symptom analysis PDF"
                        onclick="event.stopPropagation(); window.open('pdf_file/<?php echo rawurlencode($pdfFileName); ?>', '_blank');">📕<b>PDF</b></button>
                <?php endif; ?>
                <button type="button" class="ph-edit-order-btn" title="Edit this order (customer, exam, prescription, lens, frame)"
                        onclick="event.stopPropagation(); phOpenEditOrderModal(<?php echo (int)$o['id']; ?>, '<?php echo htmlspecialchars($o['invoice_number'] ?? '', ENT_QUOTES); ?>');">
                        ✏️<b>EDIT</b>
                </button>
                <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
                    <div style="text-align:right;">
                        <div class="ph-header-total" style="font-size:0.72rem;font-weight:800;color:#ffaa00;font-family:monospace;">
                            Rp <?php echo number_format($totalAmt, 0, ',', '.'); ?>
                        </div>
                        <div style="font-size:0.62rem;color:#00ff88;font-weight:700;margin-top:2px;">PAID ✓</div>
                    </div>
                    <span class="cs-chevron">▼</span>
                </div>
            </div>

            <!-- Collapsible body -->
            <div class="cs-card-body">
                <div class="cs-details-grid">
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Lens</span>
                        <span class="cs-detail-value"><?php echo htmlspecialchars($lensName); ?></span>
                    </div>
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Frame (UFC)</span>
                        <span class="cs-detail-value"><?php echo htmlspecialchars($frameUfc); ?></span>
                    </div>
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Order Date</span>
                        <span class="cs-detail-value"><?php echo $orderDate; ?></span>
                    </div>
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Due Date</span>
                        <span class="cs-detail-value"><?php echo $dueDate; ?></span>
                    </div>
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Total
                            <button class="ph-edit-btn" onclick="phOpenEditModal(this)" title="Edit total amount">✏️</button>
                        </span>
                        <span class="ph-total-display cs-detail-value price" data-raw="<?php echo $totalAmt; ?>">Rp <?php echo number_format($totalAmt, 0, ',', '.'); ?></span>
                    </div>

                    <!-- Packaging Cost -->
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">
                            Packaging
                            <button class="ph-edit-btn" onclick="phOpenPkgModal(this)" title="Edit packaging cost">✏️</button>
                        </span>
                        <span class="ph-pkg-total cs-detail-value price">Rp <?php echo number_format($pkgTotal, 0, ',', '.'); ?></span>
                    </div>

                    <!-- Frame Cost (custom = editable) -->
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">
                            Frame Cost
                            <?php if ($isCustom): ?>
                            <button class="ph-edit-btn" onclick="phOpenFrameCostModal(this)" title="Edit frame cost">✏️</button>
                            <?php endif; ?>
                        </span>
                        <span class="ph-frame-cost-display cs-detail-value price" style="color:#aa88ff;">
                            <?php echo $frameCost > 0 ? 'Rp ' . number_format($frameCost, 0, ',', '.') : '<span style="color:#555;font-size:0.72rem;">Not found</span>'; ?>
                        </span>
                    </div>

                    <!-- Lens Cost -->
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Lens Cost <span style="font-size:0.58rem;color:#555;">(<?php echo $lensType; ?>)</span></span>
                        <span class="cs-detail-value price" style="color:#aa88ff;">
                            <?php echo $lensCost > 0 ? 'Rp ' . number_format($lensCost, 0, ',', '.') : '<span style="color:#555;font-size:0.72rem;">Not found</span>'; ?>
                        </span>
                    </div>

                    <!-- Net Profit -->
                    <div class="cs-detail-item" style="grid-column: 1 / -1;">
                        <span class="cs-detail-label">Net Profit</span>
                        <span class="ph-net-profit cs-detail-value" style="font-size:1rem;font-weight:900;color:<?php echo $netProfit >= 0 ? '#00ff88' : '#ff6b6b'; ?>;">
                            <?php echo ($netProfit >= 0 ? '' : '−') . 'Rp ' . number_format(abs($netProfit), 0, ',', '.'); ?>
                            <span style="font-size:0.65rem;font-weight:600;color:var(--text-muted);margin-left:6px;">
                                (<?php echo $totalAmt > 0 ? round($netProfit / $totalAmt * 100) : 0; ?>%)
                            </span>
                        </span>
                    </div>

                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Phone No.</span>
                        <span class="cs-detail-value"><?php echo htmlspecialchars($phone ?: '—'); ?></span>
                    </div>
                    <div class="cs-detail-item">
                        <span class="cs-detail-label">Address</span>
                        <span class="cs-detail-value" style="font-size:0.75rem;"><?php echo htmlspecialchars($o['customer_address'] ?? '—'); ?></span>
                    </div>
                </div>
            </div>

        </div>
        <?php endforeach; ?>
        </div>

        <?php endif; ?>

    </div><!-- .cs-body -->
            </div><!-- .main-card -->

            
        </div><!-- .content-area -->

        <div class="btn-group">
            <button type="button" class="neu-pill-btn" id="backBtn" onclick="handleBackClick(this)">
                <span class="neu-pill-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                </span>
                <span class="neu-pill-text">
                    <span class="line1">RETURN TO</span>
                    <span class="line2">PREVIOUS PAGE</span>
                </span>
            </button>
        </div>

        <footer class="footer-container">
            <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
        </footer>
    </div><!-- .main-wrapper -->
    <div class="logo-backdrop" id="logoBackdrop" ondblclick="zoomOutLogo(document.getElementById('storeLogo'))"></div>
        

    <!-- ── Packaging Cost Modal ─────────────────────────────────────── -->
    <div class="ph-modal-overlay" id="ph-pkg-modal-overlay">
        <div class="ph-modal">
            <div class="ph-modal-title">📦 Edit Packaging Cost</div>
            <div class="ph-modal-sub" id="ph-pkg-modal-sub">Order —</div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:8px;">
                <div class="ph-modal-field">
                    <label>📦 Box</label>
                    <input type="text" class="ph-modal-input" id="ph-pkg-box" oninput="phPkgFormat(this);phPkgUpdateTotal()" onfocus="this.select()" inputmode="numeric">
                </div>
                <div class="ph-modal-field">
                    <label>🧣 Flanel</label>
                    <input type="text" class="ph-modal-input" id="ph-pkg-flanel" oninput="phPkgFormat(this);phPkgUpdateTotal()" onfocus="this.select()" inputmode="numeric">
                </div>
                <div class="ph-modal-field">
                    <label>💎 Faset</label>
                    <input type="text" class="ph-modal-input" id="ph-pkg-faset" oninput="phPkgFormat(this);phPkgUpdateTotal()" onfocus="this.select()" inputmode="numeric">
                </div>
                <div class="ph-modal-field">
                    <label>🎁 Wrapping</label>
                    <input type="text" class="ph-modal-input" id="ph-pkg-wrapping" oninput="phPkgFormat(this);phPkgUpdateTotal()" onfocus="this.select()" inputmode="numeric">
                </div>
                <div class="ph-modal-field">
                    <label>🧴 Lens Cleaner</label>
                    <input type="text" class="ph-modal-input" id="ph-pkg-cleaner" oninput="phPkgFormat(this);phPkgUpdateTotal()" onfocus="this.select()" inputmode="numeric">
                </div>
            </div>

            <div style="background:rgba(255,170,0,0.07);border:1px solid rgba(255,170,0,0.2);border-radius:12px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <span style="font-size:0.72rem;color:var(--text-muted);font-weight:700;letter-spacing:0.5px;">TOTAL PACKAGING</span>
                <span id="ph-pkg-total-preview" style="font-size:0.88rem;font-weight:900;color:#ffaa00;font-family:monospace;">Rp 0</span>
            </div>

            <div class="ph-modal-actions">
                <button class="ph-modal-btn cancel" onclick="phClosePkgModal()">Cancel</button>
                <button class="ph-modal-btn confirm" id="ph-pkg-confirm" onclick="phSubmitPkg()">Save</button>
            </div>
        </div>
    </div>

    <!-- ── Edit Frame Cost Modal (custom frames only) ──────────────────── -->
    <div class="ph-modal-overlay" id="ph-frame-modal-overlay">
        <div class="ph-modal">
            <div class="ph-modal-title">🖼 Edit Frame Cost</div>
            <div class="ph-modal-sub" id="ph-frame-modal-sub">Custom Frame —</div>

            <div class="ph-modal-field">
                <label>Buy Price (Modal Frame)</label>
                <input type="text" class="ph-modal-input" id="ph-frame-cost-input"
                       onfocus="this.select()"
                       oninput="phFrameCostFormat(this)"
                       inputmode="numeric" autocomplete="off">
                <div class="ph-modal-preview" id="ph-frame-cost-preview"></div>
            </div>

            <div class="ph-modal-actions">
                <button class="ph-modal-btn cancel" onclick="phCloseFrameModal()">Cancel</button>
                <button class="ph-modal-btn confirm" id="ph-frame-confirm" onclick="phSubmitFrameCost()">Save</button>
            </div>
        </div>
    </div>

    <!-- ── Edit Total Modal ───────────────────────────────────────── -->
    <div class="ph-modal-overlay" id="ph-modal-overlay">
        <div class="ph-modal">
            <div class="ph-modal-title">✏️ Edit Total Amount</div>
            <div class="ph-modal-sub" id="ph-modal-sub">Order — —</div>

            <div class="ph-modal-field">
                <label>New Amount (or expression e.g. 600000-50000)</label>
                <input type="text" class="ph-modal-input" id="ph-modal-amount"
                       placeholder="e.g. 550000 or 600000-50000"
                       autocomplete="off">
                <div class="ph-modal-preview" id="ph-modal-preview"></div>
            </div>

            <div class="ph-modal-field">
                <label>Your Password (verification)</label>
                <input type="password" class="ph-modal-input password-input" id="ph-modal-password"
                       placeholder="Enter your password" autocomplete="current-password">
            </div>

            <div class="ph-modal-actions">
                <button class="ph-modal-btn cancel" onclick="phCloseModal()">Cancel</button>
                <button class="ph-modal-btn confirm" id="ph-modal-confirm" onclick="phSubmitEdit()">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         EDIT ORDER MODAL — password gate + tabbed group editor
         ══════════════════════════════════════════════════════════════ -->
    <div class="ph-modal-overlay" id="ph-eo-overlay">
        <div class="ph-modal ph-modal-wide">

            <!-- Step 1: admin password gate (shown until session unlocked) -->
            <div id="ph-eo-gate">
                <div class="ph-modal-title">🔒 Admin Verification Required</div>
                <div class="ph-modal-sub">Editing an order touches stock &amp; financial records. Admin role + password required.</div>
                <div class="ph-modal-field">
                    <label>Your Password</label>
                    <input type="password" class="ph-modal-input password-input" id="ph-eo-gate-password"
                           placeholder="Enter your admin password" autocomplete="current-password">
                </div>
                <div class="ph-modal-preview error" id="ph-eo-gate-error"></div>
                <div class="ph-modal-actions">
                    <button class="ph-modal-btn cancel" onclick="phCloseEditOrderModal()">Cancel</button>
                    <button class="ph-modal-btn confirm" id="ph-eo-gate-btn" onclick="phEoVerifyAccess()">Unlock Editing</button>
                </div>
            </div>

            <!-- Step 2: the actual multi-group editor (shown after unlock) -->
            <div id="ph-eo-editor" style="display:none;">
                <div class="ph-modal-title">✏️ Edit Order — <span id="ph-eo-title-name">—</span></div>
                <div class="ph-modal-sub" id="ph-eo-title-sub">Invoice — —</div>

                <div class="ph-eo-tabs" id="ph-eo-tabs">
                    <button type="button" class="ph-eo-tab active" data-group="customer">Customer</button>
                    <button type="button" class="ph-eo-tab" data-group="exam">Exam Results</button>
                    <button type="button" class="ph-eo-tab" data-group="prescription">Prescription</button>
                    <button type="button" class="ph-eo-tab" data-group="lens">Lens</button>
                    <button type="button" class="ph-eo-tab" data-group="frame">Frame</button>
                    <button type="button" class="ph-eo-tab" data-group="order_info">Order Info</button>
                </div>

                <div id="ph-eo-loading" style="text-align:center;padding:24px;color:var(--text-muted);font-size:0.75rem;">Loading order details…</div>

                <div id="ph-eo-body" style="display:none;">

                    <!-- ── Group: Customer Data ─────────────────────── -->
                    <div class="ph-eo-group active" data-group="customer">
                        <div class="ph-modal-field">
                            <label>Examination Date</label>
                            <input type="date" class="ph-modal-input" id="eo-c-date">
                        </div>
                        <div class="ph-modal-field">
                            <label>Customer Name</label>
                            <input type="text" class="ph-modal-input" id="eo-c-name">
                        </div>
                        <div class="ph-modal-field" style="display:flex;gap:10px;">
                            <div style="flex:1;">
                                <label>Age</label>
                                <input type="number" class="ph-modal-input" id="eo-c-age" min="0">
                            </div>
                            <div style="flex:1;">
                                <label>Gender</label>
                                <select class="ph-modal-input" id="eo-c-gender">
                                    <option value="MALE">MALE</option>
                                    <option value="FEMALE">FEMALE</option>
                                </select>
                            </div>
                        </div>
                        <div class="ph-modal-field">
                            <label>Symptoms</label>
                            <textarea class="ph-modal-input" id="eo-c-symptoms" rows="2"></textarea>
                        </div>
                        <div class="ph-modal-field">
                            <label>Exam Notes</label>
                            <textarea class="ph-modal-input" id="eo-c-notes" rows="2"></textarea>
                        </div>
                        <div class="ph-modal-actions">
                            <button class="ph-modal-btn confirm" onclick="phEoSaveCustomer()">Save Customer Data</button>
                        </div>
                    </div>

                    <!-- ── Group: Exam Results ──────────────────────── -->
                    <div class="ph-eo-group" data-group="exam">
                        <div class="ph-eo-rx-grid">
                            <div></div><div class="ph-eo-rx-head">SPH</div><div class="ph-eo-rx-head">CYL</div><div class="ph-eo-rx-head">AX</div><div class="ph-eo-rx-head">ADD</div>

                            <div class="ph-eo-rx-label">OLD R</div>
                            <input class="ph-modal-input" id="eo-e-old_r_sph"><input class="ph-modal-input" id="eo-e-old_r_cyl"><input class="ph-modal-input" id="eo-e-old_r_ax"><input class="ph-modal-input" id="eo-e-old_r_add">
                            <div class="ph-eo-rx-label">OLD L</div>
                            <input class="ph-modal-input" id="eo-e-old_l_sph"><input class="ph-modal-input" id="eo-e-old_l_cyl"><input class="ph-modal-input" id="eo-e-old_l_ax"><input class="ph-modal-input" id="eo-e-old_l_add">

                            <div class="ph-eo-rx-label">NEW R</div>
                            <input class="ph-modal-input" id="eo-e-new_r_sph"><input class="ph-modal-input" id="eo-e-new_r_cyl"><input class="ph-modal-input" id="eo-e-new_r_ax"><input class="ph-modal-input" id="eo-e-new_r_add">
                            <div class="ph-eo-rx-label">NEW L</div>
                            <input class="ph-modal-input" id="eo-e-new_l_sph"><input class="ph-modal-input" id="eo-e-new_l_cyl"><input class="ph-modal-input" id="eo-e-new_l_ax"><input class="ph-modal-input" id="eo-e-new_l_add">
                        </div>
                        <div class="ph-modal-field" style="display:flex;gap:10px;margin-top:12px;">
                            <div style="flex:1;"><label>Visus R (new)</label><input class="ph-modal-input" id="eo-e-new_r_visus"></div>
                            <div style="flex:1;"><label>Visus L (new)</label><input class="ph-modal-input" id="eo-e-new_l_visus"></div>
                        </div>
                        <div class="ph-modal-field" style="display:flex;gap:10px;">
                            <div style="flex:1;"><label>UCVA R</label><input class="ph-modal-input" id="eo-e-ucva_r"></div>
                            <div style="flex:1;"><label>UCVA L</label><input class="ph-modal-input" id="eo-e-ucva_l"></div>
                            <div style="flex:1;"><label>PD Distance</label><input class="ph-modal-input" id="eo-e-pd_dist"></div>
                        </div>
                        <div class="ph-modal-field">
                            <label>Habits &amp; Needs</label>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <label class="ph-eo-check"><input type="checkbox" id="eo-e-visual_habit"> Visual Habit</label>
                                <label class="ph-eo-check"><input type="checkbox" id="eo-e-digital_usage"> Digital Usage</label>
                                <label class="ph-eo-check"><input type="checkbox" id="eo-e-need_distance"> Need Distance</label>
                                <label class="ph-eo-check"><input type="checkbox" id="eo-e-need_intermediate"> Need Intermediate</label>
                                <label class="ph-eo-check"><input type="checkbox" id="eo-e-need_near"> Need Near</label>
                            </div>
                        </div>
                        <div class="ph-modal-actions">
                            <button class="ph-modal-btn confirm" onclick="phEoSaveExam()">Save Exam Results</button>
                        </div>
                    </div>

                    <!-- ── Group: Prescription (modification handling) ─ -->
                    <div class="ph-eo-group" data-group="prescription">
                        <div class="ph-modal-sub" id="eo-p-status">Current status: —</div>
                        <div id="eo-p-lastmod" class="ph-eo-note" style="display:none;"></div>

                        <div class="ph-modal-actions" style="margin-top:6px;">
                            <button class="ph-modal-btn cancel" onclick="phEoPrescriptionSimple('revert')">↩ Revert to Original Rx</button>
                            <button class="ph-modal-btn confirm" onclick="phEoPrescriptionSimple('reapply')">↪ Re-apply Last Modification</button>
                        </div>

                        <div style="margin:18px 0 8px;font-size:0.65rem;color:var(--text-muted);letter-spacing:0.6px;text-transform:uppercase;">Or record a brand-new modification</div>
                        <div class="ph-eo-rx-grid" style="grid-template-columns:60px repeat(4,1fr);">
                            <div></div><div class="ph-eo-rx-head">SPH</div><div class="ph-eo-rx-head">CYL</div><div class="ph-eo-rx-head">AXIS</div><div class="ph-eo-rx-head">ADD</div>
                            <div class="ph-eo-rx-label">OD</div>
                            <input class="ph-modal-input" id="eo-p-od_sph"><input class="ph-modal-input" id="eo-p-od_cyl"><input class="ph-modal-input" id="eo-p-od_axis"><input class="ph-modal-input" id="eo-p-od_add">
                            <div class="ph-eo-rx-label">OS</div>
                            <input class="ph-modal-input" id="eo-p-os_sph"><input class="ph-modal-input" id="eo-p-os_cyl"><input class="ph-modal-input" id="eo-p-os_axis"><input class="ph-modal-input" id="eo-p-os_add">
                        </div>
                        <div class="ph-modal-actions">
                            <button class="ph-modal-btn confirm" onclick="phEoPrescriptionNew()">Save New Modification</button>
                        </div>
                    </div>

                    <!-- ── Group: Lens ──────────────────────────────── -->
                    <div class="ph-eo-group" data-group="lens">
                        <div class="ph-modal-field">
                            <label>Lens Name (format: CATEGORY / TYPE)</label>
                            <input type="text" class="ph-modal-input" id="eo-l-name" placeholder="e.g. STOCK / SINGLE VISION">
                        </div>
                        <div class="ph-modal-actions">
                            <button class="ph-modal-btn confirm" onclick="phEoSaveLens()">Save Lens</button>
                        </div>
                    </div>

                    <!-- ── Group: Frame ─────────────────────────────── -->
                    <div class="ph-eo-group" data-group="frame">
                        <div class="ph-modal-sub" id="eo-f-current">Current frame: —</div>

                        <div class="ph-eo-subtabs" id="ph-eo-frame-subtabs">
                            <button type="button" class="ph-eo-subtab active" data-fmode="catalog">Scan / Catalog</button>
                            <button type="button" class="ph-eo-subtab" data-fmode="custom_select">Saved Custom</button>
                            <button type="button" class="ph-eo-subtab" data-fmode="custom_new">New Custom</button>
                            <button type="button" class="ph-eo-subtab" data-fmode="remove">Remove Frame</button>
                        </div>

                        <!-- Catalog / scan -->
                        <div class="ph-eo-fpanel active" data-fmode="catalog">
                            <div class="ph-modal-field">
                                <label>Frame UFC (scan barcode or type manually)</label>
                                <input type="text" class="ph-modal-input" id="eo-f-ufc" placeholder="Scan or type UFC code" autocomplete="off">
                                <div class="ph-modal-preview" id="eo-f-ufc-preview"></div>
                            </div>
                        </div>

                        <!-- Existing saved custom frames for this invoice -->
                        <div class="ph-eo-fpanel" data-fmode="custom_select">
                            <div id="eo-f-custom-list" style="display:flex;flex-direction:column;gap:8px;"></div>
                        </div>

                        <!-- New custom frame -->
                        <div class="ph-eo-fpanel" data-fmode="custom_new">
                            <div class="ph-modal-field"><label>Brand</label><input type="text" class="ph-modal-input" id="eo-f-brand"></div>
                            <div class="ph-modal-field"><label>Size (optional)</label><input type="text" class="ph-modal-input" id="eo-f-size"></div>
                            <div class="ph-modal-field"><label>Sell Price</label><input type="text" class="ph-modal-input" id="eo-f-price" inputmode="numeric" placeholder="e.g. 250000"></div>
                        </div>

                        <!-- Remove -->
                        <div class="ph-eo-fpanel" data-fmode="remove">
                            <div class="ph-eo-note">This will remove the frame from the order. Any custom frame will be deleted; catalog stock will be restored. No replacement frame will be assigned.</div>
                        </div>

                        <div class="ph-modal-actions">
                            <button class="ph-modal-btn confirm" onclick="phEoSaveFrame()">Save Frame Changes</button>
                        </div>
                    </div>

                    <!-- ── Group: Order Info ────────────────────────── -->
                    <div class="ph-eo-group" data-group="order_info">
                        <div class="ph-modal-field"><label>Phone</label><input type="text" class="ph-modal-input" id="eo-o-phone"></div>
                        <div class="ph-modal-field"><label>Address</label><textarea class="ph-modal-input" id="eo-o-address" rows="2"></textarea></div>
                        <div class="ph-modal-field"><label>Due Date</label><input type="date" class="ph-modal-input" id="eo-o-due"></div>
                        <div class="ph-modal-actions">
                            <button class="ph-modal-btn confirm" onclick="phEoSaveOrderInfo()">Save Order Info</button>
                        </div>
                    </div>

                </div>

                <div class="ph-modal-preview" id="ph-eo-msg"></div>
                <div class="ph-modal-actions" style="margin-top:6px;">
                    <button class="ph-modal-btn cancel" onclick="phCloseEditOrderModal()">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div id="ph-toast"></div>

    <script>
        // ── Filter state ──────────────────────────────────────────────────
        var _phFilters = {
            search:  '',
            month:   '',
            gender:  '',
            faktur:  '',
            sort:    'date_desc'
        };

        // ── Toggle card expand/collapse ───────────────────────────────────
        function csToggleCard(headerEl) {
            headerEl.closest('.cs-card').classList.toggle('expanded');
        }

        // ── Apply all filters + sort ──────────────────────────────────────
        function phApplyFilters() {
            _phFilters.search  = (document.getElementById('ph-search').value || '').toLowerCase().trim();
            _phFilters.month   = document.getElementById('ph-filter-month').value;
            _phFilters.gender  = document.getElementById('ph-filter-gender').value;
            _phFilters.faktur  = document.getElementById('ph-filter-faktur').value;
            _phFilters.sort    = document.getElementById('ph-sort').value;

            var container = document.getElementById('ph-cards-container');
            if (!container) return;
            var cards = Array.prototype.slice.call(container.querySelectorAll('.cs-card'));

            // ── Filter ────────────────────────────────────────────────────
            var visible = [];
            cards.forEach(function(card) {
                var show = true;

                // Search: name, invoice, phone, custnum, frame, lens
                if (_phFilters.search) {
                    var q = _phFilters.search;
                    var haystack = [
                        card.getAttribute('data-name')    || '',
                        card.getAttribute('data-inv')      || '',
                        card.getAttribute('data-phone')    || '',
                        card.getAttribute('data-custnum')  || '',
                        card.getAttribute('data-frame')    || '',
                        card.getAttribute('data-lens')     || ''
                    ].join(' ');
                    if (haystack.indexOf(q) === -1) show = false;
                }

                // Month
                if (show && _phFilters.month) {
                    if (card.getAttribute('data-month') !== _phFilters.month) show = false;
                }

                // Gender
                if (show && _phFilters.gender) {
                    if (card.getAttribute('data-gender') !== _phFilters.gender) show = false;
                }

                // Faktur
                if (show && _phFilters.faktur) {
                    if (card.getAttribute('data-faktur') !== _phFilters.faktur) show = false;
                }

                card.style.display = show ? '' : 'none';
                if (show) visible.push(card);
            });

            // ── Sort ──────────────────────────────────────────────────────
            visible.sort(function(a, b) {
                switch (_phFilters.sort) {
                    case 'date_asc':
                        return new Date(a.getAttribute('data-orderdate-raw')||'').getTime()
                            - new Date(b.getAttribute('data-orderdate-raw')||'').getTime();
                    case 'date_desc':
                        return new Date(b.getAttribute('data-orderdate-raw')||'').getTime()
                            - new Date(a.getAttribute('data-orderdate-raw')||'').getTime();
                    case 'name_asc':
                        return (a.getAttribute('data-name')||'').localeCompare(b.getAttribute('data-name')||'');
                    case 'name_desc':
                        return (b.getAttribute('data-name')||'').localeCompare(a.getAttribute('data-name')||'');
                    case 'total_desc':
                        return parseInt(b.getAttribute('data-total')||0) - parseInt(a.getAttribute('data-total')||0);
                    case 'total_asc':
                        return parseInt(a.getAttribute('data-total')||0) - parseInt(b.getAttribute('data-total')||0);
                    default:
                        return 0;
                }
            });

            // Re-append in sorted order
            visible.forEach(function(card) { container.appendChild(card); });

            // ── Year/Month accordion groups (only when sorted by date and no month filter) ──
            phRenderGroups(visible);

            // ── Update result count ───────────────────────────────────────
            var countEl = document.getElementById('ph-result-count');
            if (countEl) {
                countEl.innerHTML = 'Showing <span>' + visible.length + '</span> of <span>' + cards.length + '</span> orders';
            }

            // ── Active filter chips ───────────────────────────────────────
            phRenderChips();

            // ── Recalculate stat cards based on visible cards ─────────────
            var isFiltered = (_phFilters.search || _phFilters.month || _phFilters.gender || _phFilters.faktur);
            var thisMonthKey = (function() {
                var now = new Date();
                return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
            })();

            if (!isFiltered) {
                // No filter: restore original totals from PHP-rendered values
                var finishedEl = document.getElementById('ph-finished-display');
                var revenueEl  = document.getElementById('ph-revenue-display');
                var monthEl    = document.getElementById('ph-month-display');
                var profitEl   = document.getElementById('ph-profit-display');
                var costEl     = document.getElementById('ph-cost-display');
                if (finishedEl) finishedEl.textContent = _phOrigFinished;
                if (revenueEl)  { revenueEl.style.color = '#ffaa00'; revenueEl.textContent = 'Rp ' + _phOrigRevenue.toLocaleString('id-ID'); }
                if (monthEl)    monthEl.textContent = _phOrigMonth;
                if (profitEl)   { profitEl.style.color = _phOrigNetProfit >= 0 ? '#00ff88' : '#ff6b6b'; profitEl.textContent = (_phOrigNetProfit >= 0 ? '' : '-') + 'Rp ' + Math.abs(_phOrigNetProfit).toLocaleString('id-ID'); }
                if (costEl)     costEl.textContent = 'Rp ' + _phOrigCost.toLocaleString('id-ID');
            } else {
                // Filtered: sum only visible cards
                var filtTotal    = 0;
                var filtRevenue  = 0;
                var filtMonth    = 0;
                var filtProfit   = 0;
                var filtCost     = 0;
                visible.forEach(function(card) {
                    filtTotal++;
                    filtRevenue += parseInt(card.getAttribute('data-total') || 0);
                    var cardMonth = card.getAttribute('data-month') || '';
                    if (cardMonth === thisMonthKey) filtMonth++;
                    filtProfit  += parseInt(card.getAttribute('data-net-profit') || 0);
                    filtCost    += parseInt(card.getAttribute('data-cost') || 0);
                });
                var finishedEl = document.getElementById('ph-finished-display');
                var revenueEl  = document.getElementById('ph-revenue-display');
                var monthEl    = document.getElementById('ph-month-display');
                var profitEl   = document.getElementById('ph-profit-display');
                var costEl     = document.getElementById('ph-cost-display');
                if (finishedEl) finishedEl.textContent = filtTotal;
                if (revenueEl)  { revenueEl.style.color = '#ffaa00'; revenueEl.textContent = 'Rp ' + filtRevenue.toLocaleString('id-ID'); }
                if (monthEl)    monthEl.textContent = filtMonth;
                if (profitEl)   { profitEl.style.color = filtProfit >= 0 ? '#00ff88' : '#ff6b6b'; profitEl.textContent = (filtProfit >= 0 ? '' : '-') + 'Rp ' + Math.abs(filtProfit).toLocaleString('id-ID'); }
                if (costEl)     costEl.textContent = 'Rp ' + filtCost.toLocaleString('id-ID');
            }
        }

        // ── Year / Month accordion groups ───────────────────────────────────
        // Only one year is expanded at a time, and within that year only one
        // month is expanded at a time. Everything starts collapsed by default.
        var _phExpandedYear  = null;
        var _phExpandedMonth = null;

        function phRenderGroups(visible) {
            var container = document.getElementById('ph-cards-container');
            if (!container) return;

            // Clean up any leftover legacy dividers.
            document.querySelectorAll('.ph-month-divider').forEach(function(d) { d.remove(); });

            // Flatten: pull every card back to be a direct child of the container
            // so old group wrappers can be discarded without losing any card nodes.
            var allCards = Array.prototype.slice.call(container.querySelectorAll('.cs-card'));
            allCards.forEach(function(c) { container.appendChild(c); });
            container.querySelectorAll('.ph-year-group').forEach(function(g) { g.remove(); });

            var useGrouping = (_phFilters.sort === 'date_desc' || _phFilters.sort === 'date_asc') && !_phFilters.month;
            if (!useGrouping || visible.length === 0) return;

            var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

            // Group visible cards (already in sorted order) by year, then by month.
            var years       = {};
            var yearOrder   = [];

            visible.forEach(function(card) {
                var raw  = card.getAttribute('data-orderdate-raw') || '';
                var d    = raw ? new Date(raw) : null;
                var year = d ? String(d.getFullYear()) : 'Unknown';
                var mk   = d ? (d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')) : 'unknown';
                var ml   = d ? monthNames[d.getMonth()] : 'Unknown';

                if (!years[year]) { years[year] = { monthOrder: [], months: {} }; yearOrder.push(year); }
                if (!years[year].months[mk]) { years[year].months[mk] = { label: ml, cards: [] }; years[year].monthOrder.push(mk); }
                years[year].months[mk].cards.push(card);
            });

            yearOrder.forEach(function(year) {
                var yearData = years[year];
                var totalCount = 0;
                yearData.monthOrder.forEach(function(mk) { totalCount += yearData.months[mk].cards.length; });

                var yearWrap = document.createElement('div');
                yearWrap.className = 'ph-year-group' + (_phExpandedYear === year ? ' expanded' : '');
                yearWrap.setAttribute('data-year', year);

                var yearHeader = document.createElement('div');
                yearHeader.className = 'ph-year-header';
                yearHeader.innerHTML = '<span class="ph-year-title">📁 ' + year + '</span>'
                                      + '<span class="ph-year-count">' + totalCount + ' orders</span>'
                                      + '<span class="ph-year-arrow">▾</span>';
                yearHeader.addEventListener('click', (function(y) { return function() { phToggleYear(y); }; })(year));
                yearWrap.appendChild(yearHeader);

                var yearBody = document.createElement('div');
                yearBody.className = 'ph-year-body';

                yearData.monthOrder.forEach(function(mk) {
                    var monthData = yearData.months[mk];

                    var monthWrap = document.createElement('div');
                    monthWrap.className = 'ph-month-group' + ((_phExpandedYear === year && _phExpandedMonth === mk) ? ' expanded' : '');
                    monthWrap.setAttribute('data-monthkey', mk);

                    var monthHeader = document.createElement('div');
                    monthHeader.className = 'ph-month-header';
                    monthHeader.innerHTML = '<span class="ph-month-title">🗓️ ' + monthData.label + '</span>'
                                           + '<span class="ph-month-count">' + monthData.cards.length + '</span>'
                                           + '<span class="ph-month-arrow">▾</span>';
                    monthHeader.addEventListener('click', (function(y, m) { return function(e) { e.stopPropagation(); phToggleMonth(y, m); }; })(year, mk));
                    monthWrap.appendChild(monthHeader);

                    var monthBody = document.createElement('div');
                    monthBody.className = 'ph-month-body';
                    monthData.cards.forEach(function(c) { monthBody.appendChild(c); });
                    monthWrap.appendChild(monthBody);

                    yearBody.appendChild(monthWrap);
                });

                yearWrap.appendChild(yearBody);
                container.appendChild(yearWrap);
            });
        }

        // Expanding a year collapses every other year, and resets which month is open.
        function phToggleYear(year) {
            year = String(year);
            if (_phExpandedYear === year) {
                _phExpandedYear  = null;
                _phExpandedMonth = null;
            } else {
                _phExpandedYear  = year;
                _phExpandedMonth = null;
            }
            phApplyFilters();
        }

        // Expanding a month collapses every other month (including in other years).
        function phToggleMonth(year, monthKey) {
            year = String(year);
            if (_phExpandedYear === year && _phExpandedMonth === monthKey) {
                _phExpandedMonth = null;
            } else {
                _phExpandedYear  = year;
                _phExpandedMonth = monthKey;
            }
            phApplyFilters();
        }

        // ── Active filter chips ───────────────────────────────────────────
        function phRenderChips() {
            var container = document.getElementById('ph-active-filters');
            if (!container) return;
            container.innerHTML = '';

            var labels = {
                month:   { label: '📅 ' + (document.getElementById('ph-filter-month').options[document.getElementById('ph-filter-month').selectedIndex] || {}).text, clear: function() { document.getElementById('ph-filter-month').value = ''; phApplyFilters(); } },
                gender:  { label: '👤 ' + (document.getElementById('ph-filter-gender').options[document.getElementById('ph-filter-gender').selectedIndex] || {}).text, clear: function() { document.getElementById('ph-filter-gender').value = ''; phApplyFilters(); } },
                faktur:  { label: '📒 ' + (document.getElementById('ph-filter-faktur').options[document.getElementById('ph-filter-faktur').selectedIndex] || {}).text, clear: function() { document.getElementById('ph-filter-faktur').value = ''; phApplyFilters(); } },
            };

            if (_phFilters.search) {
                var chip = document.createElement('div');
                chip.className = 'ph-active-chip';
                chip.innerHTML = '🔍 "' + _phFilters.search + '" <button onclick="document.getElementById(\'ph-search\').value=\'\';phApplyFilters()">✕</button>';
                container.appendChild(chip);
            }

            ['month','gender','faktur'].forEach(function(key) {
                if (_phFilters[key]) {
                    var chip = document.createElement('div');
                    chip.className = 'ph-active-chip';
                    var lbl = labels[key];
                    chip.innerHTML = lbl.label + ' <button>✕</button>';
                    chip.querySelector('button').addEventListener('click', lbl.clear);
                    container.appendChild(chip);
                }
            });
        }

        // ── Reset all filters ─────────────────────────────────────────────
        function phResetFilters() {
            document.getElementById('ph-search').value          = '';
            document.getElementById('ph-filter-month').value   = '';
            document.getElementById('ph-filter-gender').value  = '';
            document.getElementById('ph-filter-faktur').value  = '';
            document.getElementById('ph-sort').value           = 'date_desc';
            phApplyFilters();
        }


        var _phTotalRevenue   = <?php echo (int)$totalRevenue; ?>;
        var _phTotalNetProfit = <?php echo (int)$totalNetProfit; ?>;
        var _phTotalCost      = <?php echo (int)$totalCost; ?>;

        // Original (unfiltered) values for stat card restoration
        var _phOrigFinished   = <?php echo (int)$totalOrders; ?>;
        var _phOrigRevenue    = <?php echo (int)$totalRevenue; ?>;
        var _phOrigMonth      = <?php echo (int)$thisMonthCount; ?>;
        var _phOrigNetProfit  = <?php echo (int)$totalNetProfit; ?>;
        var _phOrigCost       = <?php echo (int)$totalCost; ?>;

        // ── Edit Total Modal ──────────────────────────────────────────────
        var _phEditState = { orderId: null, currentTotal: 0, displayEl: null, headerEl: null };

        function phOpenEditModal(btnEl) {
            var card     = btnEl.closest('.cs-card');
            var totalEl  = card.querySelector('.ph-total-display');
            var headerAmt = card.querySelector('.ph-header-total');
            var orderId  = card.getAttribute('data-id');
            var current  = parseInt(card.getAttribute('data-total')) || 0;
            var name     = card.getAttribute('data-fullname') || '—';
            var inv      = card.getAttribute('data-inv') || '—';

            _phEditState = { orderId: orderId, currentTotal: current, displayEl: totalEl, headerEl: headerAmt, card: card };

            document.getElementById('ph-modal-sub').textContent  = name + '  |  INV: ' + inv.toUpperCase() + '  |  Current: Rp ' + current.toLocaleString('id-ID');
            document.getElementById('ph-modal-amount').value     = phFormatNumber(String(current));
            phUpdatePreview(phFormatNumber(String(current)));
            document.getElementById('ph-modal-password').value   = '';
            document.getElementById('ph-modal-preview').className   = 'ph-modal-preview';
            document.getElementById('ph-modal-overlay').classList.add('open');
            setTimeout(function() { var inp = document.getElementById('ph-modal-amount'); inp.focus(); inp.select(); }, 100);
        }

        function phCloseModal() {
            document.getElementById('ph-modal-overlay').classList.remove('open');
        }

        // Close on overlay click
        document.getElementById('ph-modal-overlay').addEventListener('click', function(e) {
            if (e.target === this) phCloseModal();
        });

        // ── Parse raw digits from a value (strip thousand separators) ──────
        function phStripFormat(val) {
            return val.replace(/\./g, '').replace(/[^0-9+\-*\/\s().]/g, '');
        }

        // ── Format plain number with dot thousand separators ─────────────
        function phFormatNumber(digits) {
            if (!digits) return '';
            return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        // ── Real-time input handler: format digits as typed ──────────────
        function phHandleAmountInput(e) {
            var inp  = e.target;
            var raw  = inp.value;

            // If expression (has operator other than leading minus) — skip formatting
            var isExpr = /[+*\/]/.test(raw) || /\d[\-]/.test(raw);
            if (isExpr) {
                phUpdatePreview(raw);
                return;
            }

            // Plain number mode: strip non-digits, reformat, restore cursor
            var cursorPos   = inp.selectionStart;
            var beforeCursor = raw.slice(0, cursorPos);
            var digitsBeforeCursor = beforeCursor.replace(/\./g, '').replace(/[^0-9]/g, '');
            var allDigits   = raw.replace(/\./g, '').replace(/[^0-9]/g, '');

            var formatted   = phFormatNumber(allDigits);
            inp.value       = formatted;

            // Restore cursor: count how many digits are before cursor, find new position
            var newPos = 0, digitCount = 0;
            for (var i = 0; i < formatted.length; i++) {
                if (digitCount >= digitsBeforeCursor.length) break;
                if (formatted[i] !== '.') digitCount++;
                newPos = i + 1;
            }
            inp.setSelectionRange(newPos, newPos);

            phUpdatePreview(formatted);
        }

        // ── Update preview line below input ──────────────────────────────
        function phUpdatePreview(raw) {
            var preview = document.getElementById('ph-modal-preview');
            if (!raw || !raw.trim()) { preview.textContent = ''; return; }

            var sanitized = phStripFormat(raw);
            if (!sanitized.trim()) { preview.textContent = ''; return; }

            var result;
            try {
                result = Function('"use strict"; return (' + sanitized + ')')();
            } catch(e) {
                preview.textContent = 'Invalid expression';
                preview.className   = 'ph-modal-preview error';
                return;
            }

            result = Math.round(result);
            if (isNaN(result) || result < 0) {
                preview.textContent = 'Invalid value';
                preview.className   = 'ph-modal-preview error';
            } else {
                var diff    = result - (_phEditState.currentTotal || 0);
                var diffStr = diff === 0 ? '' : (diff > 0
                    ? '  (+Rp ' + diff.toLocaleString('id-ID') + ')'
                    : '  (−Rp ' + Math.abs(diff).toLocaleString('id-ID') + ')');
                preview.textContent = '→ Rp ' + result.toLocaleString('id-ID') + diffStr;
                preview.className   = 'ph-modal-preview';
            }
        }

        // Bind input handler after DOM ready (see DOMContentLoaded below)
        function phBindAmountInput() {
            var inp = document.getElementById('ph-modal-amount');
            inp.addEventListener('input', phHandleAmountInput);
        }

        // ── Submit edit ───────────────────────────────────────────────────
        function phSubmitEdit() {
            var raw      = document.getElementById('ph-modal-amount').value.trim();
            var password = document.getElementById('ph-modal-password').value;
            var confirm  = document.getElementById('ph-modal-confirm');

            if (!raw || !password) {
                phShowToast('⚠ Fill in amount and password');
                return;
            }

            var sanitized = phStripFormat(raw);
            var newTotal;
            try {
                newTotal = Math.round(Function('"use strict"; return (' + sanitized + ')')());
            } catch(e) {
                phShowToast('⚠ Invalid amount expression');
                return;
            }

            if (isNaN(newTotal) || newTotal < 0) {
                phShowToast('⚠ Invalid amount');
                return;
            }

            confirm.disabled    = true;
            confirm.textContent = 'Saving…';

            var fd = new FormData();
            fd.append('action',    'update_total');
            fd.append('order_id',  _phEditState.orderId);
            fd.append('new_total', newTotal);
            fd.append('password',  password);

            fetch('purchase_history.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    confirm.disabled    = false;
                    confirm.textContent = 'Save Changes';

                    if (data.success) {
                        var oldTotal  = _phEditState.currentTotal;
                        var formatted = 'Rp ' + newTotal.toLocaleString('id-ID');
                        _phEditState.currentTotal = newTotal; // update state for next edit
                        if (_phEditState.displayEl) {
                            _phEditState.displayEl.textContent = formatted;
                            _phEditState.displayEl.setAttribute('data-raw', newTotal);
                        }
                        if (_phEditState.headerEl) {
                            _phEditState.headerEl.textContent = formatted;
                        }
                        _phEditState.card.setAttribute('data-total', newTotal);

                        // Update card header total
                        var headerTotal = _phEditState.card.querySelector('.ph-header-total');
                        if (headerTotal) headerTotal.textContent = formatted;

                        // Update Total Revenue stat card live
                        _phTotalRevenue = _phTotalRevenue - oldTotal + newTotal;
                        var revEl = document.getElementById('ph-revenue-display');
                        if (revEl) revEl.textContent = 'Rp ' + _phTotalRevenue.toLocaleString('id-ID');

                        phCloseModal();
                        phShowToast('✅ Total updated');
                    } else {
                        phShowToast('❌ ' + (data.error || 'Failed'));
                    }
                })
                .catch(function() {
                    confirm.disabled    = false;
                    confirm.textContent = 'Save Changes';
                    phShowToast('❌ Connection error');
                });
        }

        // Enter key submits
        document.getElementById('ph-modal-password').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') phSubmitEdit();
        });

        // ── Packaging Modal ──────────────────────────────────────────────
        var _phPkgCard = null;

        // Return packaging breakdown defaults based on total_amount tier
        function phPkgTierDefaults(totalAmount) {
            if (totalAmount >= 500000) {
                return { box: 10000, flanel: 500, faset: 10000, wrapping: 3000, cleaner: 3000 }; // 26,500
            } else if (totalAmount >= 90000) {
                return { box: 3000,  flanel: 500, faset: 10000, wrapping: 3000, cleaner: 3000 }; // 19,500
            } else {
                return { box: 3000,  flanel: 500, faset: 10000, wrapping: 1000, cleaner: 0    }; // 14,500
            }
        }

        function phOpenPkgModal(btnEl) {
            _phPkgCard = btnEl.closest('.cs-card');
            var name     = _phPkgCard.getAttribute('data-fullname') || '—';
            var inv      = _phPkgCard.getAttribute('data-inv') || '—';
            var pkgTotal = parseInt(_phPkgCard.getAttribute('data-pkg-total')) || 16500;

            document.getElementById('ph-pkg-modal-sub').textContent = name + '  |  INV: ' + inv.toUpperCase() + '  |  Current: Rp ' + pkgTotal.toLocaleString('id-ID');

            // Pre-fill with tier defaults based on total_amount
            var totalAmt  = parseInt(_phPkgCard.getAttribute('data-total-amount')) || 0;
            var tierDefs  = phPkgTierDefaults(totalAmt);
            ['box','flanel','faset','wrapping','cleaner'].forEach(function(key) {
                document.getElementById('ph-pkg-' + key).value = phFormatNumber(String(tierDefs[key]));
            });

            phPkgUpdateTotal();
            document.getElementById('ph-pkg-modal-overlay').classList.add('open');
            setTimeout(function() { document.getElementById('ph-pkg-box').focus(); document.getElementById('ph-pkg-box').select(); }, 100);
        }

        function phClosePkgModal() {
            document.getElementById('ph-pkg-modal-overlay').classList.remove('open');
        }

        document.getElementById('ph-pkg-modal-overlay').addEventListener('click', function(e) {
            if (e.target === this) phClosePkgModal();
        });

        // Format a packaging input (digits only, dot thousand sep)
        function phPkgFormat(inp) {
            var pos    = inp.selectionStart;
            var before = inp.value.length;
            var beforeCursorDigits = inp.value.slice(0, pos).replace(/\./g, '').replace(/[^0-9]/g, '');
            var allDigits = inp.value.replace(/\./g, '').replace(/[^0-9]/g, '');
            var formatted = phFormatNumber(allDigits);
            inp.value = formatted;
            // Restore cursor
            var newPos = 0, digitCount = 0;
            for (var i = 0; i < formatted.length; i++) {
                if (digitCount >= beforeCursorDigits.length) break;
                if (formatted[i] !== '.') digitCount++;
                newPos = i + 1;
            }
            inp.setSelectionRange(newPos, newPos);
        }

        function phPkgRawVal(id) {
            return parseInt(document.getElementById(id).value.replace(/\./g, '')) || 0;
        }

        function phPkgUpdateTotal() {
            var total = phPkgRawVal('ph-pkg-box') + phPkgRawVal('ph-pkg-flanel')
                    + phPkgRawVal('ph-pkg-faset') + phPkgRawVal('ph-pkg-wrapping')
                    + phPkgRawVal('ph-pkg-cleaner');
            document.getElementById('ph-pkg-total-preview').textContent = 'Rp ' + total.toLocaleString('id-ID');
        }

        function phSubmitPkg() {
            if (!_phPkgCard) return;
            var btn     = document.getElementById('ph-pkg-confirm');
            var orderId = _phPkgCard.getAttribute('data-id');
            var box     = phPkgRawVal('ph-pkg-box');
            var flanel  = phPkgRawVal('ph-pkg-flanel');
            var faset   = phPkgRawVal('ph-pkg-faset');
            var wrapping= phPkgRawVal('ph-pkg-wrapping');
            var cleaner = phPkgRawVal('ph-pkg-cleaner');
            var total   = box + flanel + faset + wrapping + cleaner;

            btn.disabled    = true;
            btn.textContent = 'Saving…';

            var fd = new FormData();
            fd.append('action',   'update_packaging');
            fd.append('order_id', orderId);
            fd.append('box',      box);
            fd.append('flanel',   flanel);
            fd.append('faset',    faset);
            fd.append('wrapping', wrapping);
            fd.append('cleaner', cleaner);

            fetch('purchase_history.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.disabled    = false;
                    btn.textContent = 'Save';
                    if (data.success) {
                        // Update card data attribute + displayed total
                        _phPkgCard.setAttribute('data-pkg-total', data.packaging_cost);
                        var totEl = _phPkgCard.querySelector('.ph-pkg-total');
                        if (totEl) totEl.textContent = 'Rp ' + data.packaging_cost.toLocaleString('id-ID');

                        // Recalculate net profit
                        phUpdateNetProfit(_phPkgCard);

                        phClosePkgModal();
                        phShowToast('✅ Packaging updated');
                    } else {
                        phShowToast('❌ ' + (data.error || 'Failed'));
                    }
                })
                .catch(function() {
                    btn.disabled    = false;
                    btn.textContent = 'Save';
                    phShowToast('❌ Connection error');
                });
        }

        // ── Frame Cost Modal ─────────────────────────────────────────────
        var _phFrameCard = null;

        function phOpenFrameCostModal(btnEl) {
            _phFrameCard = btnEl.closest('.cs-card');
            var name     = _phFrameCard.getAttribute('data-fullname') || '—';
            var inv      = _phFrameCard.getAttribute('data-invoice') || '—';
            var current  = parseInt(_phFrameCard.getAttribute('data-frame-cost')) || 0;

            document.getElementById('ph-frame-modal-sub').textContent = name + '  |  INV: ' + inv.toUpperCase() + '  |  Current: Rp ' + current.toLocaleString('id-ID');
            document.getElementById('ph-frame-cost-input').value      = phFormatNumber(String(current));
            document.getElementById('ph-frame-cost-preview').textContent = '';
            document.getElementById('ph-frame-modal-overlay').classList.add('open');
            setTimeout(function() { var inp = document.getElementById('ph-frame-cost-input'); inp.focus(); inp.select(); }, 100);
        }

        function phCloseFrameModal() {
            document.getElementById('ph-frame-modal-overlay').classList.remove('open');
        }

        document.getElementById('ph-frame-modal-overlay').addEventListener('click', function(e) {
            if (e.target === this) phCloseFrameModal();
        });

        function phFrameCostFormat(inp) {
            var pos    = inp.selectionStart;
            var beforeCursorDigits = inp.value.slice(0, pos).replace(/\./g, '').replace(/[^0-9]/g, '');
            var allDigits = inp.value.replace(/\./g, '').replace(/[^0-9]/g, '');
            var formatted = phFormatNumber(allDigits);
            inp.value = formatted;
            var newPos = 0, digitCount = 0;
            for (var i = 0; i < formatted.length; i++) {
                if (digitCount >= beforeCursorDigits.length) break;
                if (formatted[i] !== '.') digitCount++;
                newPos = i + 1;
            }
            inp.setSelectionRange(newPos, newPos);
            // Update preview
            var val = parseInt(allDigits) || 0;
            document.getElementById('ph-frame-cost-preview').textContent = val > 0 ? '→ Rp ' + val.toLocaleString('id-ID') : '';
        }

        function phSubmitFrameCost() {
            if (!_phFrameCard) return;
            var btn      = document.getElementById('ph-frame-confirm');
            var invoice  = _phFrameCard.getAttribute('data-invoice') || '';
            var rawVal   = document.getElementById('ph-frame-cost-input').value.replace(/\./g, '');
            var buyPrice = parseInt(rawVal) || 0;

            btn.disabled    = true;
            btn.textContent = 'Saving…';

            var fd = new FormData();
            fd.append('action',    'update_frame_cost');
            fd.append('invoice',   invoice);
            fd.append('buy_price', buyPrice);

            fetch('purchase_history.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.disabled    = false;
                    btn.textContent = 'Save';
                    if (data.success) {
                        // Update card display + data attr
                        _phFrameCard.setAttribute('data-frame-cost', buyPrice);
                        var dispEl = _phFrameCard.querySelector('.ph-frame-cost-display');
                        if (dispEl) dispEl.innerHTML = 'Rp ' + buyPrice.toLocaleString('id-ID');

                        // Recalculate net profit display
                        phUpdateNetProfit(_phFrameCard);

                        phCloseFrameModal();
                        phShowToast('✅ Frame cost updated');
                    } else {
                        phShowToast('❌ ' + (data.error || 'Failed'));
                    }
                })
                .catch(function() {
                    btn.disabled    = false;
                    btn.textContent = 'Save';
                    phShowToast('❌ Connection error');
                });
        }

        // ── Recalculate net profit on card after any cost edit ────────────
        function phUpdateNetProfit(card) {
            var total    = parseInt(card.getAttribute('data-total'))      || 0;
            var lensCost = parseInt(card.getAttribute('data-lens-cost'))   || 0;
            var frameCost= parseInt(card.getAttribute('data-frame-cost'))  || 0;
            var pkgCost  = parseInt(card.getAttribute('data-pkg-total'))   || 0;
            var profit   = total - lensCost - frameCost - pkgCost;
            var pct      = total > 0 ? Math.round(profit / total * 100) : 0;
            var el       = card.querySelector('.ph-net-profit');
            if (!el) return;
            el.style.color = profit >= 0 ? '#00ff88' : '#ff6b6b';
            var sign = profit >= 0 ? '' : '−';
            // Update total net profit stat card
            var oldProfit = parseInt(card.getAttribute('data-net-profit') || '0');
            var oldCost   = parseInt(card.getAttribute('data-cost') || '0');
            var newCost   = lensCost + frameCost + pkgCost;
            card.setAttribute('data-net-profit', profit);
            card.setAttribute('data-cost', newCost);
            _phTotalNetProfit += (profit - oldProfit);
            _phTotalCost      += (newCost - oldCost);
            var profEl = document.getElementById('ph-profit-display');
            if (profEl) {
                profEl.style.color = _phTotalNetProfit >= 0 ? '#00ff88' : '#ff6b6b';
                profEl.textContent = (_phTotalNetProfit >= 0 ? '' : '-') + 'Rp ' + Math.abs(_phTotalNetProfit).toLocaleString('id-ID');
            }
            var costEl = document.getElementById('ph-cost-display');
            if (costEl) costEl.textContent = 'Rp ' + _phTotalCost.toLocaleString('id-ID');

            el.innerHTML = sign + 'Rp ' + Math.abs(profit).toLocaleString('id-ID')
                + ' <span style="font-size:0.65rem;font-weight:600;color:var(--text-muted);margin-left:6px;">(' + pct + '%)</span>';
        }

        // ── Toast ─────────────────────────────────────────────────────────
        var _phToastTimer = null;
        function phShowToast(msg) {
            var el = document.getElementById('ph-toast');
            el.textContent = msg;
            el.classList.add('show');
            clearTimeout(_phToastTimer);
            _phToastTimer = setTimeout(function() { el.classList.remove('show'); }, 2800);
        }

        // ── Init on load ──────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() { phApplyFilters(); phBindAmountInput(); });
    </script>
    <!-- ══════════════════════════════════════════════════════════════
         EDIT ORDER MODAL — client logic
         ══════════════════════════════════════════════════════════════ -->
    <script>
        // ── State ─────────────────────────────────────────────────────────
        var _phEo = {
            orderId: null,
            invoice: null,
            card: null,        // reference to the .cs-card DOM node being edited
            details: null,     // last fetched edit_get_details payload
            frameMode: 'catalog'
        };

        function phEoShowMsg(text, isError) {
            var el = document.getElementById('ph-eo-msg');
            el.textContent = text || '';
            el.classList.toggle('error', !!isError);
        }

        // ── Open / close ─────────────────────────────────────────────────
        function phOpenEditOrderModal(orderId, invoiceNumber) {
            _phEo.orderId = orderId;
            _phEo.invoice = invoiceNumber;
            _phEo.card    = document.querySelector('.cs-card[data-id="' + orderId + '"]');

            document.getElementById('ph-eo-overlay').classList.add('open');
            document.getElementById('ph-eo-gate-error').textContent = '';
            document.getElementById('ph-eo-gate-password').value = '';
            phEoShowMsg('');

            // Try loading details straight away — if the session is still
            // unlocked from a previous edit, this skips the password gate.
            document.getElementById('ph-eo-gate').style.display = 'none';
            document.getElementById('ph-eo-editor').style.display = 'block';
            document.getElementById('ph-eo-loading').style.display = 'block';
            document.getElementById('ph-eo-body').style.display = 'none';
            phEoLoadDetails(true);
        }

        function phCloseEditOrderModal() {
            document.getElementById('ph-eo-overlay').classList.remove('open');
        }

        // ── Step 1: password gate ───────────────────────────────────────
        function phEoVerifyAccess() {
            var pw  = document.getElementById('ph-eo-gate-password').value;
            var btn = document.getElementById('ph-eo-gate-btn');
            var err = document.getElementById('ph-eo-gate-error');
            err.textContent = '';

            if (!pw) { err.textContent = 'Please enter your password.'; return; }

            btn.disabled = true;
            btn.textContent = 'Verifying…';

            var fd = new FormData();
            fd.append('action', 'edit_verify_access');
            fd.append('password', pw);

            fetch('purchase_history.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.disabled = false;
                    btn.textContent = 'Unlock Editing';
                    if (data.success) {
                        document.getElementById('ph-eo-gate').style.display = 'none';
                        document.getElementById('ph-eo-editor').style.display = 'block';
                        phEoLoadDetails(false);
                    } else {
                        err.textContent = data.error || 'Verification failed.';
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.textContent = 'Unlock Editing';
                    err.textContent = 'Connection error. Please try again.';
                });
        }

        // ── Load full order detail (all 6 tables) into the editor ─────────
        function phEoLoadDetails(silentFallbackToGate) {
            document.getElementById('ph-eo-loading').style.display = 'block';
            document.getElementById('ph-eo-body').style.display = 'none';

            var fd = new FormData();
            fd.append('action', 'edit_get_details');
            fd.append('order_id', _phEo.orderId);

            fetch('purchase_history.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) {
                        if (silentFallbackToGate) {
                            // Session not unlocked yet — show the password gate instead.
                            document.getElementById('ph-eo-gate').style.display = 'block';
                            document.getElementById('ph-eo-editor').style.display = 'none';
                            return;
                        }
                        phEoShowMsg(data.error || 'Failed to load order details.', true);
                        return;
                    }
                    _phEo.details = data;
                    phEoPopulate(data);
                    document.getElementById('ph-eo-loading').style.display = 'none';
                    document.getElementById('ph-eo-body').style.display = 'block';
                })
                .catch(function() {
                    if (silentFallbackToGate) {
                        document.getElementById('ph-eo-gate').style.display = 'block';
                        document.getElementById('ph-eo-editor').style.display = 'none';
                        return;
                    }
                    phEoShowMsg('Connection error while loading order.', true);
                });
        }

        // ── Populate every group's fields from the fetched data ───────────
        function phEoPopulate(data) {
            var order = data.order || {};
            var exam  = data.exam  || {};

            document.getElementById('ph-eo-title-name').textContent = exam.customer_name || '—';
            document.getElementById('ph-eo-title-sub').textContent  = 'Invoice ' + (order.invoice_number || '—');

            // Customer group
            document.getElementById('eo-c-date').value     = exam.examination_date ? exam.examination_date.substring(0, 10) : '';
            document.getElementById('eo-c-name').value      = exam.customer_name || '';
            document.getElementById('eo-c-age').value        = exam.age || '';
            document.getElementById('eo-c-gender').value      = exam.gender || 'MALE';
            document.getElementById('eo-c-symptoms').value     = exam.symptoms || '';
            document.getElementById('eo-c-notes').value          = exam.exam_notes || '';

            // Exam results group
            ['old_r_sph','old_r_cyl','old_r_ax','old_r_add','old_l_sph','old_l_cyl','old_l_ax','old_l_add',
             'new_r_sph','new_r_cyl','new_r_ax','new_r_add','new_r_visus',
             'new_l_sph','new_l_cyl','new_l_ax','new_l_add','new_l_visus',
             'pd_dist','ucva_r','ucva_l'].forEach(function(f) {
                var el = document.getElementById('eo-e-' + f);
                if (el) el.value = exam[f] || '';
            });
            ['visual_habit','digital_usage','need_distance','need_intermediate','need_near'].forEach(function(f) {
                var el = document.getElementById('eo-e-' + f);
                if (el) el.checked = String(exam[f]) === '1';
            });

            // Prescription group
            var statusEl = document.getElementById('eo-p-status');
            statusEl.textContent = 'Current status: ' + (String(exam.lens_modification) === '1' ? 'MODIFIED prescription is active' : 'ORIGINAL prescription is active');
            var lastModEl = document.getElementById('eo-p-lastmod');
            if (data.last_mod) {
                var m = data.last_mod;
                lastModEl.style.display = 'block';
                lastModEl.textContent = 'Last recorded modification (' + (m.modified_at || '') + '): OD ' +
                    (m.od_sph || '-') + '/' + (m.od_cyl || '-') + '/' + (m.od_axis || '-') + '/' + (m.od_add || '-') +
                    ' — OS ' + (m.os_sph || '-') + '/' + (m.os_cyl || '-') + '/' + (m.os_axis || '-') + '/' + (m.os_add || '-');
            } else {
                lastModEl.style.display = 'none';
            }
            ['od_sph','od_cyl','od_axis','od_add','os_sph','os_cyl','os_axis','os_add'].forEach(function(f) {
                var el = document.getElementById('eo-p-' + f);
                if (el) el.value = '';
            });

            // Lens group
            document.getElementById('eo-l-name').value = order.lens_name || '';

            // Frame group
            var curUfc = order.frame_ufc || '';
            var curLabel = '—';
            if (curUfc) {
                if (data.frame_is_custom) {
                    curLabel = curUfc + ' (custom frame)';
                } else if (data.catalog_frame) {
                    curLabel = curUfc + ' — ' + (data.catalog_frame.brand || '') + ' (stock: ' + data.catalog_frame.stock + ', in ' + data.catalog_frame.source_table + ')';
                } else {
                    curLabel = curUfc + ' (not found in catalog)';
                }
            }
            document.getElementById('eo-f-current').textContent = 'Current frame: ' + curLabel;
            document.getElementById('eo-f-ufc').value = '';
            document.getElementById('eo-f-brand').value = '';
            document.getElementById('eo-f-size').value = '';
            document.getElementById('eo-f-price').value = '';

            var listEl = document.getElementById('eo-f-custom-list');
            listEl.innerHTML = '';
            if (data.custom_frames && data.custom_frames.length > 0) {
                data.custom_frames.forEach(function(cf) {
                    var div = document.createElement('div');
                    div.className = 'ph-eo-custom-item' + (String(cf.is_purchased) === '1' ? ' selected' : '');
                    div.innerHTML = '<span>' + cf.brand_key + ' — Rp ' + Number(cf.sell_price).toLocaleString('id-ID') + '</span>' +
                        (String(cf.is_purchased) === '1' ? '<b style="color:#00ff88;">ACTIVE</b>' : '');
                    div.addEventListener('click', function() {
                        listEl.querySelectorAll('.ph-eo-custom-item').forEach(function(x) { x.classList.remove('selected'); });
                        div.classList.add('selected');
                        div.setAttribute('data-picked', '1');
                    });
                    div.setAttribute('data-brand-key', cf.brand_key);
                    listEl.appendChild(div);
                });
            } else {
                listEl.innerHTML = '<div class="ph-eo-note">No custom frames saved for this invoice yet.</div>';
            }

            // Order info group
            document.getElementById('eo-o-phone').value   = order.customer_phone   || '';
            document.getElementById('eo-o-address').value = order.customer_address || '';
            document.getElementById('eo-o-due').value     = order.due_date ? String(order.due_date).substring(0, 10) : '';

            phEoShowMsg('');
        }

        // ── Tab switching ────────────────────────────────────────────────
        document.getElementById('ph-eo-tabs').addEventListener('click', function(e) {
            var btn = e.target.closest('.ph-eo-tab');
            if (!btn) return;
            document.querySelectorAll('#ph-eo-tabs .ph-eo-tab').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var group = btn.getAttribute('data-group');
            document.querySelectorAll('.ph-eo-group').forEach(function(g) { g.classList.toggle('active', g.getAttribute('data-group') === group); });
            phEoShowMsg('');
        });

        // ── Frame sub-tab switching ─────────────────────────────────────
        document.getElementById('ph-eo-frame-subtabs').addEventListener('click', function(e) {
            var btn = e.target.closest('.ph-eo-subtab');
            if (!btn) return;
            document.querySelectorAll('#ph-eo-frame-subtabs .ph-eo-subtab').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var mode = btn.getAttribute('data-fmode');
            _phEo.frameMode = mode;
            document.querySelectorAll('.ph-eo-fpanel').forEach(function(p) { p.classList.toggle('active', p.getAttribute('data-fmode') === mode); });
        });

        // Pressing Enter in the UFC field behaves like a barcode-scanner "scan"
        // (most USB/Bluetooth barcode scanners type the code then send Enter).
        document.getElementById('eo-f-ufc').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); phEoSaveFrame(); }
        });

        // ── Generic small helper to POST a group action ────────────────────
        function phEoPost(action, extraFields, onSuccess) {
            phEoShowMsg('');
            var fd = new FormData();
            fd.append('action', action);
            fd.append('order_id', _phEo.orderId);
            fd.append('invoice_number', _phEo.invoice);
            Object.keys(extraFields).forEach(function(k) { fd.append(k, extraFields[k]); });

            fetch('purchase_history.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        phEoShowMsg('✅ Saved.');
                        phShowToast('✅ Order updated');
                        if (onSuccess) onSuccess(data);
                    } else {
                        phEoShowMsg('❌ ' + (data.error || 'Failed to save.'), true);
                    }
                })
                .catch(function() {
                    phEoShowMsg('❌ Connection error. Please try again.', true);
                });
        }

        // ── Group: Customer ─────────────────────────────────────────────
        function phEoSaveCustomer() {
            phEoPost('edit_group_customer', {
                examination_date: document.getElementById('eo-c-date').value,
                customer_name:    document.getElementById('eo-c-name').value,
                age:               document.getElementById('eo-c-age').value,
                gender:             document.getElementById('eo-c-gender').value,
                symptoms:            document.getElementById('eo-c-symptoms').value,
                exam_notes:            document.getElementById('eo-c-notes').value
            }, function(data) {
                if (!_phEo.card || !data.changed) return;
                _phEo.card.setAttribute('data-name', (data.name || '').toLowerCase());
                _phEo.card.setAttribute('data-fullname', data.name || '');
                // Name/age/gender chip is a small mixed-content header; rather than
                // risk mangling it with partial DOM patching, ask for a quick refresh.
                phEoShowMsg('✅ Saved. Refresh the page to see the updated name in the card header.');
            });
        }

        // ── Group: Exam Results ─────────────────────────────────────────
        function phEoSaveExam() {
            var fields = {};
            ['old_r_sph','old_r_cyl','old_r_ax','old_r_add','old_l_sph','old_l_cyl','old_l_ax','old_l_add',
             'new_r_sph','new_r_cyl','new_r_ax','new_r_add','new_r_visus',
             'new_l_sph','new_l_cyl','new_l_ax','new_l_add','new_l_visus',
             'pd_dist','ucva_r','ucva_l'].forEach(function(f) {
                var el = document.getElementById('eo-e-' + f);
                if (el) fields[f] = el.value;
            });
            ['visual_habit','digital_usage','need_distance','need_intermediate','need_near'].forEach(function(f) {
                var el = document.getElementById('eo-e-' + f);
                if (el) fields[f] = el.checked ? '1' : '0';
            });
            phEoPost('edit_group_exam', fields);
        }

        // ── Group: Prescription (revert / reapply / new) ───────────────
        function phEoPrescriptionSimple(mode) {
            phEoPost('edit_group_prescription', { mode: mode }, function(data) {
                var statusEl = document.getElementById('eo-p-status');
                statusEl.textContent = 'Current status: ' + (String(data.lens_modification) === '1' ? 'MODIFIED prescription is active' : 'ORIGINAL prescription is active');
            });
        }

        function phEoPrescriptionNew() {
            var fields = { mode: 'new_modification' };
            ['od_sph','od_cyl','od_axis','od_add','os_sph','os_cyl','os_axis','os_add'].forEach(function(f) {
                var el = document.getElementById('eo-p-' + f);
                if (el) fields[f] = el.value;
            });
            phEoPost('edit_group_prescription', fields, function(data) {
                var statusEl = document.getElementById('eo-p-status');
                statusEl.textContent = 'Current status: ' + (String(data.lens_modification) === '1' ? 'MODIFIED prescription is active' : 'ORIGINAL prescription is active');
            });
        }

        // ── Group: Lens ──────────────────────────────────────────────────
        function phEoSaveLens() {
            var newName = document.getElementById('eo-l-name').value;
            phEoPost('edit_group_lens', { lens_name: newName }, function(data) {
                if (!_phEo.card || !data.changed) return;
                _phEo.card.setAttribute('data-lens', (data.lens_name || '').toLowerCase());
                _phEo.card.setAttribute('data-lens-cost', data.lens_cost || 0);
                var lensDisplay = _phEo.card.querySelector('.cs-detail-value'); // fallback, refined below
                var items = _phEo.card.querySelectorAll('.cs-detail-item');
                items.forEach(function(item) {
                    var label = item.querySelector('.cs-detail-label');
                    if (label && label.textContent.indexOf('Lens') === 0) {
                        var val = item.querySelector('.cs-detail-value');
                        if (val) val.textContent = data.lens_name;
                    }
                });
                if (typeof phUpdateNetProfit === 'function') phUpdateNetProfit(_phEo.card);
            });
        }

        // ── Group: Frame ─────────────────────────────────────────────────
        function phEoSaveFrame() {
            var mode = _phEo.frameMode;
            var fields = { mode: mode };

            if (mode === 'catalog') {
                fields.new_ufc = document.getElementById('eo-f-ufc').value.trim();
                if (!fields.new_ufc) { phEoShowMsg('Please scan or type a frame UFC.', true); return; }
            } else if (mode === 'custom_select') {
                var picked = document.querySelector('#eo-f-custom-list .ph-eo-custom-item.selected');
                if (!picked) { phEoShowMsg('Please pick a saved custom frame first.', true); return; }
                fields.brand_key = picked.getAttribute('data-brand-key');
            } else if (mode === 'custom_new') {
                fields.brand = document.getElementById('eo-f-brand').value.trim();
                fields.size  = document.getElementById('eo-f-size').value.trim();
                fields.sell_price = (document.getElementById('eo-f-price').value || '').replace(/\D/g, '');
                if (!fields.brand || !fields.sell_price) { phEoShowMsg('Brand and sell price are required.', true); return; }
            } else if (mode === 'remove') {
                if (!confirm('Remove the frame from this order with no replacement?')) return;
            }

            phEoPost('edit_group_frame', fields, function(data) {
                if (!_phEo.card) return;
                _phEo.card.setAttribute('data-frame', (data.frame_ufc || '').toLowerCase());
                _phEo.card.setAttribute('data-frame-cost', data.frame_cost || 0);
                _phEo.card.setAttribute('data-is-custom', data.frame_source === 'custom' ? '1' : '0');

                var items = _phEo.card.querySelectorAll('.cs-detail-item');
                items.forEach(function(item) {
                    var label = item.querySelector('.cs-detail-label');
                    if (label && label.textContent.indexOf('Frame (UFC)') === 0) {
                        var val = item.querySelector('.cs-detail-value');
                        if (val) val.textContent = data.frame_ufc || '—';
                    }
                });

                var frameCostDisplay = _phEo.card.querySelector('.ph-frame-cost-display');
                if (frameCostDisplay) {
                    frameCostDisplay.innerHTML = data.frame_cost > 0
                        ? 'Rp ' + Number(data.frame_cost).toLocaleString('id-ID')
                        : '<span style="color:#555;font-size:0.72rem;">Not found</span>';
                }

                if (typeof phUpdateNetProfit === 'function') phUpdateNetProfit(_phEo.card);

                // Refresh the "current frame" line + custom-frame list for further edits in this session.
                phEoLoadDetails(false);
            });
        }

        // ── Group: Order Info ───────────────────────────────────────────
        function phEoSaveOrderInfo() {
            phEoPost('edit_group_order_info', {
                customer_phone:   document.getElementById('eo-o-phone').value,
                customer_address: document.getElementById('eo-o-address').value,
                due_date:         document.getElementById('eo-o-due').value
            }, function(data) {
                if (!_phEo.card || !data.changed) return;
                _phEo.card.setAttribute('data-phone', (data.customer_phone || '').toLowerCase());
                var items = _phEo.card.querySelectorAll('.cs-detail-item');
                items.forEach(function(item) {
                    var label = item.querySelector('.cs-detail-label');
                    if (!label) return;
                    if (label.textContent.indexOf('Phone') === 0) {
                        var val = item.querySelector('.cs-detail-value');
                        if (val) val.textContent = data.customer_phone || '—';
                    }
                    if (label.textContent.indexOf('Address') === 0) {
                        var val2 = item.querySelector('.cs-detail-value');
                        if (val2) val2.textContent = data.customer_address || '—';
                    }
                });
            });
        }
    </script>
    <!-- button logout, back animation for logo -->
    <script>
        // Single tap/click on the logo zooms it in (only if not already zoomed).
        function zoomInLogo(imgEl) {
            if (imgEl.classList.contains('zoomed')) return;
            imgEl.classList.add('zoomed');
            document.getElementById('logoBackdrop').classList.add('active');
        }

        // Double tap/click zooms it back out.
        function zoomOutLogo(imgEl) {
            imgEl.classList.remove('zoomed');
            document.getElementById('logoBackdrop').classList.remove('active');
        }

        // Animate the new pill-style Back button before navigating
        function handleBackClick(element) {
            const icon = element.querySelector('.neu-pill-icon');
            const text = element.querySelector('.neu-pill-text');

            // Make sure nothing else fights with our manual animation.
            element.style.transition = 'none';
            text.style.transition = 'none';

            const startWidth = element.offsetWidth;
            // Target: just the round icon left, with the button's own
            // left/right padding preserved (6px left, 6px right when collapsed).
            const targetWidth = icon.offsetWidth + 12;

            // Hide the text immediately so only the shrinking pill is visible.
            text.style.opacity = '0';

            const duration = 400; // ms
            const startTime = performance.now();

            function step(now) {
                const elapsed = now - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);

                const currentWidth = startWidth - (startWidth - targetWidth) * eased;
                element.style.width = currentWidth + 'px';

                if (progress < 1) {
                    requestAnimationFrame(step);
                } else {
                    // back direction
                    window.location.href = 'customer.php';
                }
            }
            requestAnimationFrame(step);
        }

        // Animate the new pill-style Logout button before logging out
        function handleLogoutClick(element) {
            element.classList.add('pressed');
            setTimeout(() => {
                window.location.href = 'logout.php';
            }, 220);
        }

        // Function executed when a button is clicked
        function handleButtonClick(element) {
            // 1. Get the URL from the data-url attribute
            const targetUrl = element.getAttribute('data-url');
            
            // 2. Save this URL to localStorage as the active button identity
            localStorage.setItem('activeMenuUrl', targetUrl);
            
            // 3. Add the active class immediately (for an instant visual effect)
            document.querySelectorAll('.neu-button').forEach(btn => btn.classList.remove('active'));
            element.classList.add('active');

            // 4. Navigate to the page
            window.location.href = targetUrl;
        }

        // Function that runs automatically when the page is refreshed or returned to (Back)
        window.addEventListener('DOMContentLoaded', () => {
            const activeUrl = localStorage.getItem('activeMenuUrl');
            
            if (activeUrl) {
                document.querySelectorAll('.neu-button').forEach(btn => {
                    // If the button's data-url matches the one in memory, activate it!
                    if (btn.getAttribute('data-url') === activeUrl) {
                        btn.classList.add('active');
                    }
                });
            }
        });
    </script>
</body>
</html>