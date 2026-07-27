<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    // ── Load lens lead times (in days) from settings table ─────────────
    // Same pattern as invoice.php: used to classify a lens as "Stock" (ready
    // fast) or "Lab" (custom-made, longer turnaround) based on how many days
    // the order was given between order_date and due_date.
    $lensStockLeadTimeDays = 2;
    $lensLabLeadTimeDays   = 10;
    $resLead = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('lens_stock_lead_time_days', 'lens_lab_lead_time_days')");
    if ($resLead) {
        while ($rowLead = mysqli_fetch_assoc($resLead)) {
            if ($rowLead['setting_key'] === 'lens_stock_lead_time_days') $lensStockLeadTimeDays = (int)$rowLead['setting_value'];
            if ($rowLead['setting_key'] === 'lens_lab_lead_time_days')   $lensLabLeadTimeDays   = (int)$rowLead['setting_value'];
        }
    }

    // ── Build daftar nama lensa STOCK dari lense_prices.json ─────────────
    // Format nama di DB: "CATEGORY / TYPE" (contoh: "SINGLE VISION / BLUERAY")
    // Semua nama di bawah key "stock" dianggap lensa stock
    $stockLensNames = [];
    $lensJsonPath   = __DIR__ . '/lense_prices.json';
    if (file_exists($lensJsonPath)) {
        $lensData = json_decode(file_get_contents($lensJsonPath), true);
        if (!empty($lensData['stock']) && is_array($lensData['stock'])) {
            foreach ($lensData['stock'] as $category => $types) {
                if (is_array($types)) {
                    foreach (array_keys($types) as $type) {
                        // Simpan dalam uppercase untuk pencocokan case-insensitive
                        $stockLensNames[] = strtoupper(trim($category) . ' / ' . trim($type));
                    }
                }
            }
        }
    }

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

    // ── Handle order_status update via AJAX ───────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        header('Content-Type: application/json');
        $id         = (int)$_POST['order_id'];
        $new_status = (int)$_POST['new_status'];
        if ($id > 0 && $new_status >= 1 && $new_status <= 5) {
            $sql = "UPDATE customer_orders SET order_status = $new_status WHERE id = $id";
            if ($conn->query($sql)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        }
        exit();
    }


// ══════════════════════════════════════════════════════════════════════
// EDIT ORDER FEATURE — full multi-group editor for a finished order
// Touches: customer_examinations, customer_orders, custom_frames,
//          frames_main, frame_staging, prescription_modifications
// Access:  requires role = 'admin' AND a verified password (unlocked
//          for a short window, stored in session) before any group save.
//
// NOTE: this file no longer runs any ALTER TABLE / migration SQL on its
// own. Run the following once in phpMyAdmin before using this feature:
//   ALTER TABLE customer_orders ADD COLUMN edit_log TEXT NULL DEFAULT NULL;
// ══════════════════════════════════════════════════════════════════════

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

// Build a single plain "field: old -> new" diff string. Every edit_log entry
// on this page is made of one or more of these, joined with "; " — nothing
// else (no extra narration) is ever written to edit_log.
function phDiff($field, $old, $new) {
    $old = ($old === null || $old === '') ? '(empty)' : $old;
    $new = ($new === null || $new === '') ? '(empty)' : $new;
    return "$field: $old -> $new";
}

// Same Roman-numeral month converter as customer_prescription.php's getRomawi().
function phGetRomawi($month) {
    $romawi = [1=>'I', 2=>'II', 3=>'III', 4=>'IV', 5=>'V', 6=>'VI',
            7=>'VII', 8=>'VIII', 9=>'IX', 10=>'X', 11=>'XI', 12=>'XII'];
    return $romawi[(int)$month] ?? 'I';
}

// If examination_date's month/year changes, examination_code's month/roman +
// year suffix is regenerated to match — the sequence number (parts[2]) is
// NEVER touched, since customer_prescription.php only assigns it once, at
// creation time, from a running counter (not tied to any particular date).
// Direct-sale codes ("LZ/EC/000-xxx/...") are left untouched, same as
// customer_prescription.php's own sequence query explicitly skips them.
// Returns the new code string, or null if no regeneration was needed/possible.
function phRegenerateExamCode($oldCode, $newDate) {
    $oldCode = trim($oldCode);
    if ($oldCode === '' || strpos($oldCode, 'LZ/EC/000-') === 0) return null; // direct-sale code, never touched

    $parts = explode('/', $oldCode);
    if (count($parts) < 5) return null; // not the expected LZ/EC/seq/month/year shape

    $newMonth = (int)date('n', strtotime($newDate));
    $newYear  = date('Y', strtotime($newDate));
    $newRoman = phGetRomawi($newMonth);

    if ($parts[3] === $newRoman && $parts[4] === $newYear) return null; // month/year unchanged, nothing to do

    $parts[3] = $newRoman;
    $parts[4] = $newYear;
    return implode('/', $parts);
}

// If order_date's month/year changes, customer_number's month/roman + 2-digit
// year suffix is regenerated to match — everything else (sequence number,
// "LZ-C", invoice sheet, and the bbb sequence borrowed from examination_code)
// is left untouched, since none of those are tied to order_date.
// Format: {seqNum}/LZ-C/{invoiceSheet}/{bbb}/{romanMonth}/{yy}
// Returns the new customer_number string, or null if no regeneration was needed/possible.
function phRegenerateCustomerNumber($oldCustomerNumber, $newOrderDate) {
    $oldCustomerNumber = trim($oldCustomerNumber);
    if ($oldCustomerNumber === '') return null;

    $parts = explode('/', $oldCustomerNumber);
    if (count($parts) < 6) return null; // not the expected seq/LZ-C/sheet/bbb/month/yy shape

    $newMonth = (int)date('n', strtotime($newOrderDate));
    $newYy    = date('y', strtotime($newOrderDate));
    $newRoman = phGetRomawi($newMonth);

    if ($parts[4] === $newRoman && $parts[5] === $newYy) return null; // month/year unchanged, nothing to do

    $parts[4] = $newRoman;
    $parts[5] = $newYy;
    return implode('/', $parts);
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

// Full lens lookup (cost, selling price, stock/lab source) by matching a
// "Category — Type" (or "Category / Type") label against lense_prices.json.
// Matching is separator/case tolerant; the returned 'label' is always the
// canonical "Category — Type" format (identical to what invoice.php writes
// into customer_orders.lens_name), so saves stay consistent with new orders.
//
// $preferredSource, when given ('stock'|'lab'), restricts the search to that
// source only — this is how the Edit Order Lens tab disambiguates a label
// that exists in BOTH stock and lab with different prices (the dropdown
// option carries which source was actually picked). When omitted (e.g.
// resolving an existing/legacy lens_name that has no recorded source, or the
// card's cost display), the whole 'stock' source is tried first — if it has
// ANY match, that's used; only if 'stock' has no match at all does 'lab' get
// tried. Within whichever single source ends up being used, if more than one
// entry normalizes to the same label (matching type name catalogued more than
// once), the cheapest (lowest selling price) one is picked.
function phLensLookupFull($label, $preferredSource = null) {
    $label = trim($label);
    if ($label === '') return null;
    $jsonPath = __DIR__ . '/lense_prices.json';
    if (!file_exists($jsonPath)) return null;
    $data = json_decode(file_get_contents($jsonPath), true);
    if (!$data) return null;

    $normalize = function ($s) {
        // Same normalization already used elsewhere on this page: any of
        // em-dash / en-dash / slash (with surrounding spaces) → " / ", then uppercase.
        $s = preg_replace('/\s*[\x{2014}\x{2013}\/]\s*/u', ' / ', trim($s));
        return strtoupper($s);
    };
    $target = $normalize($label);

    $sources = ($preferredSource === 'stock' || $preferredSource === 'lab') ? [$preferredSource] : ['stock', 'lab'];

    foreach ($sources as $lt) {
        if (empty($data[$lt])) continue;

        $matches = [];
        foreach ($data[$lt] as $cat => $types) {
            foreach ($types as $type => $info) {
                if ($normalize(trim($cat) . ' / ' . trim($type)) === $target) {
                    $matches[] = [
                        'cost'    => (int)($info['cost']    ?? 0),
                        'selling' => (int)($info['selling'] ?? 0),
                        'source'  => $lt, // 'stock' | 'lab'
                        'label'   => trim($cat) . ' — ' . trim($type), // canonical stored format
                    ];
                }
            }
        }
        if (empty($matches)) continue; // nothing in this source — try the next one

        // Pick the cheapest match within this source (usually there's only one).
        usort($matches, function ($a, $b) { return $a['selling'] <=> $b['selling']; });
        return $matches[0];
    }
    return null;
}

// Read lens lead times (in days) from the settings table, same keys/defaults as invoice.php.
function phLensLeadTimeDays($conn) {
    $days = ['stock' => 2, 'lab' => 10];
    $res = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('lens_stock_lead_time_days', 'lens_lab_lead_time_days')");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if ($row['setting_key'] === 'lens_stock_lead_time_days') $days['stock'] = (int)$row['setting_value'];
            if ($row['setting_key'] === 'lens_lab_lead_time_days')   $days['lab']   = (int)$row['setting_value'];
        }
    }
    return $days;
}

// Is a given frame_ufc value actually a custom_frames brand_key (not a catalog UFC)?
// Mirrors the detection already used lower in this file: brand_key starts with a digit (size prefix).
function phIsCustomFrameUfc($ufc) {
    $ufc = trim($ufc);
    return $ufc !== '' && is_numeric($ufc[0]);
}

// Fetch a full row from a frame table (frames_main / frame_staging / frame_unrecord).
function phGetFrameRow($conn, $table, $ufc) {
    $ufc = $conn->real_escape_string($ufc);
    $r = $conn->query("SELECT * FROM `$table` WHERE ufc = '$ufc' LIMIT 1");
    return $r ? $r->fetch_assoc() : null;
}

// The descriptive columns shared by frames_main / frame_staging / frame_unrecord
// (everything except ufc, stock, and the bookkeeping timestamps).
const PH_FRAME_DESC_COLS = ['brand', 'frame_code', 'frame_size', 'color_code', 'material',
    'lens_shape', 'structure', 'size_range', 'gender_category', 'buy_price', 'sell_price',
    'price_secret_code', 'stock_age'];

// Move 1 unit from frame_staging → frame_unrecord (buying_status = 1).
// frame_staging holds NOT-YET-CATALOGUED stock, and a separate bulk process
// (outside this file) periodically migrates all of it into frames_main. If a
// unit had already been committed to a customer order but was left sitting in
// frame_staging, that migration would silently absorb it and lose track of the
// commitment. Pulling the committed unit into frame_unrecord as soon as it's
// chosen prevents that.
function phMoveStagingUnitToUnrecord($conn, $ufc) {
    $row = phGetFrameRow($conn, 'frame_staging', $ufc);
    if (!$row) return false;
    $safeUfc = $conn->real_escape_string($ufc);

    $exists = $conn->query("SELECT stock FROM frame_unrecord WHERE ufc = '$safeUfc' LIMIT 1");
    if ($exists && $exists->num_rows > 0) {
        $conn->query("UPDATE frame_unrecord SET stock = stock + 1, buying_status = 1, updated_at = NOW() WHERE ufc = '$safeUfc'");
    } else {
        $cols = "ufc, " . implode(', ', PH_FRAME_DESC_COLS) . ", stock, buying_status, created_at, updated_at";
        $vals = "'$safeUfc'";
        foreach (PH_FRAME_DESC_COLS as $c) {
            $v = $row[$c];
            $vals .= ", " . ($v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'");
        }
        $vals .= ", 1, 1, NOW(), NOW()";
        $conn->query("INSERT INTO frame_unrecord ($cols) VALUES ($vals)");
    }

    if ((int)$row['stock'] <= 1) {
        $conn->query("DELETE FROM frame_staging WHERE ufc = '$safeUfc' LIMIT 1");
        phDeleteFrameQrCodeIfExists($ufc); // stock is now fully depleted from staging — clean up its QR code, if any
    } else {
        $conn->query("UPDATE frame_staging SET stock = stock - 1, updated_at = NOW() WHERE ufc = '$safeUfc'");
    }
    return true;
}

// QR code cleanup for a frame UFC whose frame_staging stock just hit 0.
// Confirmed pattern: /qrcodes/{UFC}.png. Deletes only if the file actually
// exists; if it doesn't, this is a safe no-op (per instruction).
function phDeleteFrameQrCodeIfExists($ufc) {
    $ufc = trim($ufc);
    if ($ufc === '') return;
    $path = __DIR__ . '/qrcodes/' . $ufc . '.png';
    if (file_exists($path)) { @unlink($path); }
}

// Release 1 unit that was previously taken from frame_staging into
// frame_unrecord (see phMoveStagingUnitToUnrecord). Per explicit instruction,
// this does NOT move anything back to frame_staging and does NOT delete the
// frame_unrecord row even if its stock reaches 0 — it's just decremented
// (floored at 0) and left as-is for manual handling later.
function phReleaseUnrecordUnit($conn, $ufc) {
    $safeUfc = $conn->real_escape_string($ufc);
    $conn->query("UPDATE frame_unrecord SET stock = GREATEST(stock - 1, 0), updated_at = NOW() WHERE ufc = '$safeUfc'");
}

// Try to restore +1 stock for a frame that's being released (order edited away from it).
// Checks frame_unrecord FIRST: if the frame currently lives there, it was originally
// taken from frame_staging — releasing it only decrements frame_unrecord's own stock
// (see phReleaseUnrecordUnit; nothing is moved back to frame_staging). Otherwise falls
// back to plain frames_main / frame_staging.
// Returns ['table' => ..., 'sell_price' => ..., 'stock_before' => ...] on success, or null if not found anywhere.
// frames_main.updated_at has no automatic ON UPDATE clause, so it's set explicitly here.
function phRestoreCatalogStock($conn, $ufc) {
    $safeUfc = $conn->real_escape_string($ufc);

    $unrec = $conn->query("SELECT sell_price, stock FROM frame_unrecord WHERE ufc = '$safeUfc' LIMIT 1");
    if ($unrec && $unrec->num_rows > 0) {
        $row = $unrec->fetch_assoc();
        phReleaseUnrecordUnit($conn, $ufc);
        return ['table' => 'frame_unrecord', 'sell_price' => (float)$row['sell_price'], 'stock_before' => (int)$row['stock']];
    }

    foreach (['frames_main', 'frame_staging'] as $tbl) {
        $chk = $conn->query("SELECT sell_price, stock FROM `$tbl` WHERE ufc = '$safeUfc' LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            $row = $chk->fetch_assoc();
            $conn->query("UPDATE `$tbl` SET stock = stock + 1, updated_at = NOW() WHERE ufc = '$safeUfc'");
            return ['table' => $tbl, 'sell_price' => (float)$row['sell_price'], 'stock_before' => (int)$row['stock']];
        }
    }
    return null;
}

// Try to deduct 1 stock from whichever catalog table owns this ufc, only if stock > 0.
// frames_main is already-catalogued stock: plain deduction. frame_staging is NOT
// YET catalogued: taking a unit from there relocates it into frame_unrecord (see
// phMoveStagingUnitToUnrecord) instead of a plain deduction.
// Returns ['table' => ..., 'sell_price' => ..., 'stock_before' => ...] on success, or null on failure (not found / out of stock).
function phDeductCatalogStock($conn, $ufc) {
    $safeUfc = $conn->real_escape_string($ufc);

    $main = $conn->query("SELECT sell_price, stock FROM frames_main WHERE ufc = '$safeUfc' AND stock > 0 LIMIT 1");
    if ($main && $main->num_rows > 0) {
        $row = $main->fetch_assoc();
        $conn->query("UPDATE frames_main SET stock = stock - 1, updated_at = NOW() WHERE ufc = '$safeUfc'");
        return ['table' => 'frames_main', 'sell_price' => (float)$row['sell_price'], 'stock_before' => (int)$row['stock']];
    }

    $staging = $conn->query("SELECT sell_price, stock FROM frame_staging WHERE ufc = '$safeUfc' AND stock > 0 LIMIT 1");
    if ($staging && $staging->num_rows > 0) {
        $row = $staging->fetch_assoc();
        phMoveStagingUnitToUnrecord($conn, $ufc);
        return ['table' => 'frame_staging -> frame_unrecord', 'sell_price' => (float)$row['sell_price'], 'stock_before' => (int)$row['stock']];
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

// Update customer_examinations.lens_modification AND customer_orders.is_modified
// together — the two columns must always carry the same value. Only touches a
// column if its current value actually differs. Returns the phDiff() strings
// for whatever it changed (empty array if both were already correct).
function phSetLensModification($conn, $inv, $order_id, $newVal) {
    $newVal = (int)$newVal;
    $changes = [];

    $examRes = $conn->query("SELECT lens_modification FROM customer_examinations WHERE invoice_number = '" . $conn->real_escape_string($inv) . "' LIMIT 1");
    $examRow = $examRes ? $examRes->fetch_assoc() : null;
    if ($examRow && (int)$examRow['lens_modification'] !== $newVal) {
        $conn->query("UPDATE customer_examinations SET lens_modification = $newVal WHERE invoice_number = '" . $conn->real_escape_string($inv) . "'");
        $changes[] = phDiff('lens_modification', $examRow['lens_modification'], $newVal);
    }

    $ordRes = $conn->query("SELECT is_modified FROM customer_orders WHERE id = " . (int)$order_id . " LIMIT 1");
    $ordRow = $ordRes ? $ordRes->fetch_assoc() : null;
    if ($ordRow && (int)$ordRow['is_modified'] !== $newVal) {
        $conn->query("UPDATE customer_orders SET is_modified = $newVal WHERE id = " . (int)$order_id);
        $changes[] = phDiff('is_modified', $ordRow['is_modified'], $newVal);
    }

    return $changes;
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

    // Unlock only for the duration of this editing session (the front-end
    // explicitly invalidates this again as soon as the editor is closed,
    // so opening the editor always asks for the password again).
    $_SESSION['ph_edit_unlocked_until'] = time() + 900; // safety ceiling, 15 minutes
    $_SESSION['ph_edit_admin_user_id']  = $_SESSION['user_id'];
    echo json_encode(['success' => true, 'unlocked_for_seconds' => 900]);
    exit();
}

// ── AJAX: invalidate the edit unlock (called when the editor is closed) ─
if (isset($_POST['action']) && $_POST['action'] === 'edit_lock_access') {
    header('Content-Type: application/json');
    unset($_SESSION['ph_edit_unlocked_until']);
    unset($_SESSION['ph_edit_admin_user_id']);
    echo json_encode(['success' => true]);
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

    // The prescription currently "in effect" for this order — used to pre-fill
    // the new-modification form. If a modification is active, that's the latest
    // prescription_modifications row. Otherwise it's the freshly-measured Rx
    // from this exam (new_r_*/new_l_* → OD/OS convention: R = OD, L = OS).
    $activeRx = null;
    if ($exam && (string)($exam['lens_modification'] ?? '0') === '1' && $lastMod) {
        $activeRx = [
            'od_sph' => $lastMod['od_sph'], 'od_cyl' => $lastMod['od_cyl'], 'od_axis' => $lastMod['od_axis'], 'od_add' => $lastMod['od_add'],
            'os_sph' => $lastMod['os_sph'], 'os_cyl' => $lastMod['os_cyl'], 'os_axis' => $lastMod['os_axis'], 'os_add' => $lastMod['os_add'],
        ];
    } elseif ($exam) {
        $activeRx = [
            'od_sph' => $exam['new_r_sph'], 'od_cyl' => $exam['new_r_cyl'], 'od_axis' => $exam['new_r_ax'], 'od_add' => $exam['new_r_add'],
            'os_sph' => $exam['new_l_sph'], 'os_cyl' => $exam['new_l_cyl'], 'os_axis' => $exam['new_l_ax'], 'os_add' => $exam['new_l_add'],
        ];
    }

    echo json_encode([
        'success'       => true,
        'order'         => $order,
        'exam'          => $exam ?: null,
        'last_mod'      => $lastMod ?: null,
        'active_rx'     => $activeRx,
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

    $curRes = $conn->query("SELECT examination_date, examination_code, customer_name, age, gender, symptoms, exam_notes FROM customer_examinations WHERE invoice_number = '$inv' LIMIT 1");
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
            $changes[]  = phDiff($field, $curVal, $val);
        }
    }

    // examination_date's month/year moved → examination_code's month/roman +
    // year suffix must follow (its sequence number never changes — see
    // phRegenerateExamCode). customer_number is a separate code tied to
    // order_date, not examination_date — it's regenerated by the Order Info
    // group instead (see edit_group_order_info / phRegenerateCustomerNumber).
    $newExamCode = null;
    if (isset($new['examination_date']) && (string)$new['examination_date'] !== date('Y-m-d', strtotime($cur['examination_date']))) {
        $newExamCode = phRegenerateExamCode($cur['examination_code'], $new['examination_date']);
        if ($newExamCode !== null) {
            $setParts[] = "examination_code = '" . $conn->real_escape_string($newExamCode) . "'";
            $changes[]  = phDiff('examination_code', $cur['examination_code'], $newExamCode);

            // The symptom-analysis PDF is filed under the OLD code's filename
            // (slashes replaced with dashes) — rename it so it isn't orphaned.
            $oldPdfName = str_replace('/', '-', trim($cur['examination_code'])) . '.pdf';
            $newPdfName = str_replace('/', '-', $newExamCode) . '.pdf';
            $oldPdfPath = __DIR__ . '/pdf_file/' . $oldPdfName;
            $newPdfPath = __DIR__ . '/pdf_file/' . $newPdfName;
            if (file_exists($oldPdfPath) && !file_exists($newPdfPath)) {
                @rename($oldPdfPath, $newPdfPath);
            }
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
            $changes[]  = phDiff($f, $cur[$f], $val);
        }
    }
    foreach ($toggleFields as $f) {
        if (!array_key_exists($f, $_POST)) continue;
        $val = (int)$_POST[$f];
        if ($val !== (int)$cur[$f]) {
            $setParts[] = "`$f` = $val";
            $changes[]  = phDiff($f, $cur[$f], $val);
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
        $curRes = $conn->query("SELECT lens_modification FROM customer_examinations WHERE invoice_number = '$inv' LIMIT 1");
        $curRow = $curRes ? $curRes->fetch_assoc() : null;
        if (!$curRow) { echo json_encode(['success' => false, 'error' => 'Examination record not found']); exit(); }
        if ((string)$curRow['lens_modification'] === '0') { echo json_encode(['success' => true, 'changed' => false, 'lens_modification' => 0]); exit(); }

        $changes = phSetLensModification($conn, $inv, $order_id, 0);
        if (!empty($changes)) phAppendEditLog($conn, $order_id, 'prescription', implode('; ', $changes));
        echo json_encode(['success' => true, 'changed' => true, 'lens_modification' => 0]);
        exit();
    }

    if ($mode === 'reapply') {
        // Customer changed their mind again and wants the last recorded modification back.
        $curRes = $conn->query("SELECT lens_modification FROM customer_examinations WHERE invoice_number = '$inv' LIMIT 1");
        $curRow = $curRes ? $curRes->fetch_assoc() : null;
        if (!$curRow) { echo json_encode(['success' => false, 'error' => 'Examination record not found']); exit(); }
        if ((string)$curRow['lens_modification'] === '1') { echo json_encode(['success' => true, 'changed' => false, 'lens_modification' => 1]); exit(); }

        $chk = $conn->query("SELECT modification_id FROM prescription_modifications WHERE invoice_number = '$inv' ORDER BY modified_at DESC LIMIT 1");
        if (!$chk || $chk->num_rows === 0) { echo json_encode(['success' => false, 'error' => 'No previous modification found to re-apply.']); exit(); }
        $changes = phSetLensModification($conn, $inv, $order_id, 1);
        if (!empty($changes)) phAppendEditLog($conn, $order_id, 'prescription', implode('; ', $changes));
        echo json_encode(['success' => true, 'changed' => true, 'lens_modification' => 1]);
        exit();
    }

    if ($mode === 'new_modification') {
        $new = [
            'od_sph'  => trim($_POST['od_sph']  ?? ''), 'od_cyl' => trim($_POST['od_cyl'] ?? ''),
            'od_axis' => trim($_POST['od_axis'] ?? ''), 'od_add' => trim($_POST['od_add'] ?? ''),
            'os_sph'  => trim($_POST['os_sph']  ?? ''), 'os_cyl' => trim($_POST['os_cyl'] ?? ''),
            'os_axis' => trim($_POST['os_axis'] ?? ''), 'os_add' => trim($_POST['os_add'] ?? ''),
        ];

        // Whether to INSERT a new history row or UPDATE the latest one
        // in-place depends on the flag *at the moment of saving*:
        //   - currently ORIGINAL (0) → this is a brand-new modification → INSERT.
        //   - currently MODIFIED (1) → the customer is adjusting the modification
        //     that's already active → UPDATE that same row, so history doesn't
        //     pile up with near-duplicate rows.
        // This is why pressing "Revert to Original" first (flag → 0) makes the
        // next save an INSERT, while pressing "Re-apply Last Modification"
        // first (flag → 1) makes the next save an UPDATE of that reapplied row.
        $curFlagRes = $conn->query("SELECT lens_modification FROM customer_examinations WHERE invoice_number = '$inv' LIMIT 1");
        $curFlagRow = $curFlagRes ? $curFlagRes->fetch_assoc() : null;
        $isCurrentlyModified = $curFlagRow && (string)$curFlagRow['lens_modification'] === '1';

        $conn->begin_transaction();
        try {
            $changes = [];

            if ($isCurrentlyModified) {
                $latestRes = $conn->query("SELECT * FROM prescription_modifications WHERE invoice_number = '$inv' ORDER BY modified_at DESC LIMIT 1");
                $latestRow = $latestRes ? $latestRes->fetch_assoc() : null;
                if (!$latestRow) throw new Exception('No existing modification row found to update.');

                $setParts = [];
                foreach ($new as $field => $val) {
                    if ($val !== (string)$latestRow[$field]) {
                        $setParts[] = "`$field` = '" . $conn->real_escape_string($val) . "'";
                        $changes[]  = phDiff($field, $latestRow[$field], $val);
                    }
                }
                if (!empty($setParts)) {
                    $modId = (int)$latestRow['modification_id'];
                    // prescription_modifications has no auto-update column, so modified_at is bumped explicitly.
                    $upd1 = $conn->query("UPDATE prescription_modifications SET " . implode(', ', $setParts) . ", modified_at = NOW() WHERE modification_id = $modId");
                    if (!$upd1) throw new Exception($conn->error);
                }
            } else {
                $ins = $conn->prepare("INSERT INTO prescription_modifications
                    (invoice_number, od_sph, od_cyl, od_axis, od_add, os_sph, os_cyl, os_axis, os_add)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->bind_param("sssssssss", $inv, $new['od_sph'], $new['od_cyl'], $new['od_axis'], $new['od_add'], $new['os_sph'], $new['os_cyl'], $new['os_axis'], $new['os_add']);
                if (!$ins->execute()) throw new Exception($conn->error);
                $ins->close();
                foreach ($new as $field => $val) { $changes[] = phDiff("new_modification.$field", '', $val); }
            }

            if (!$isCurrentlyModified) {
                $changes = array_merge($changes, phSetLensModification($conn, $inv, $order_id, 1));
            }

            $conn->commit();
            if (!empty($changes)) phAppendEditLog($conn, $order_id, 'prescription', implode('; ', $changes));
            echo json_encode(['success' => true, 'changed' => !empty($changes), 'lens_modification' => 1]);
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

    $order_id      = (int)($_POST['order_id'] ?? 0);
    $newLensLabel  = trim($_POST['lens_name'] ?? '');
    $newLensSource = $_POST['lens_source'] ?? null; // 'stock' | 'lab', sent by the dropdown option that was actually picked
    if ($order_id <= 0 || $newLensLabel === '') { echo json_encode(['success' => false, 'error' => 'Invalid input']); exit(); }

    $newLens = phLensLookupFull($newLensLabel, $newLensSource);
    if (!$newLens) { echo json_encode(['success' => false, 'error' => 'Selected lens was not found in the current price list.']); exit(); }

    $stmt = $conn->prepare("SELECT lens_name, total_amount, due_date, order_date FROM customer_orders WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $cur = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$cur) { echo json_encode(['success' => false, 'error' => 'Order not found']); exit(); }

    $oldLensLabel = $cur['lens_name'];
    if ($newLens['label'] === $oldLensLabel) { echo json_encode(['success' => true, 'changed' => false]); exit(); }

    // Adjust total_amount by the *difference* in selling price, rather than
    // recomputing it from scratch, so any manual discount baked into the
    // original total is preserved.
    $oldLens    = phLensLookupFull($oldLensLabel); // may be null if old lens_name isn't in the JSON (renamed/removed)
    $oldSelling = $oldLens ? $oldLens['selling'] : 0;
    $newSelling = $newLens['selling'];
    $oldTotal   = (float)$cur['total_amount'];
    $newTotal   = max(0, $oldTotal + ($newSelling - $oldSelling));

    // Recompute due_date from order_date using the same lead-time settings invoice.php uses.
    $leadDays   = phLensLeadTimeDays($conn);
    $days       = ($newLens['source'] === 'stock') ? $leadDays['stock'] : $leadDays['lab'];
    $newDueDate = !empty($cur['order_date']) ? date('Y-m-d', strtotime($cur['order_date'] . " +{$days} days")) : null;

    // Only touch the columns that actually change.
    $setParts = ["lens_name = '" . $conn->real_escape_string($newLens['label']) . "'"];
    $changes  = [phDiff('lens_name', $oldLensLabel, $newLens['label'])];

    if ((float)$newTotal !== $oldTotal) {
        $setParts[] = "total_amount = " . (float)$newTotal;
        $changes[]  = phDiff('total_amount', $oldTotal, $newTotal);
    }
    $oldDueDateNorm = !empty($cur['due_date']) ? date('Y-m-d', strtotime($cur['due_date'])) : null;
    if ($newDueDate !== $oldDueDateNorm) {
        $setParts[] = "due_date = " . ($newDueDate ? "'" . $conn->real_escape_string($newDueDate) . "'" : 'NULL');
        $changes[]  = phDiff('due_date', $oldDueDateNorm, $newDueDate);
    }

    $ok = $conn->query("UPDATE customer_orders SET " . implode(', ', $setParts) . " WHERE id = $order_id");
    if (!$ok) { echo json_encode(['success' => false, 'error' => $conn->error]); exit(); }

    phAppendEditLog($conn, $order_id, 'lens', implode('; ', $changes));

    echo json_encode([
        'success'      => true,
        'changed'      => true,
        'lens_name'    => $newLens['label'],
        'lens_cost'    => $newLens['cost'],
        'total_amount' => $newTotal,
        'due_date'     => $newDueDate,
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

    $stmt = $conn->prepare("SELECT frame_ufc, total_amount FROM customer_orders WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $curOrder = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$curOrder) { echo json_encode(['success' => false, 'error' => 'Order not found']); exit(); }

    $oldUfc      = trim($curOrder['frame_ufc'] ?? '');
    $oldIsCustom = phIsCustomFrameUfc($oldUfc);
    $oldTotal    = (float)$curOrder['total_amount'];

    // ── No-op guard: skip entirely (no transaction, nothing touched) if the
    // requested change wouldn't actually change anything. ──────────────────
    if ($mode === 'catalog' && trim($_POST['new_ufc'] ?? '') === $oldUfc) {
        echo json_encode(['success' => true, 'changed' => false]); exit();
    }
    if ($mode === 'custom_select' && trim($_POST['brand_key'] ?? '') === $oldUfc) {
        echo json_encode(['success' => true, 'changed' => false]); exit();
    }
    if ($mode === 'remove' && $oldUfc === '') {
        echo json_encode(['success' => true, 'changed' => false]); exit();
    }

    $oldSellPrice = 0; // the customer-facing price of whatever frame is being replaced/removed
    $newSellPrice = 0; // the customer-facing price of the new frame (0 if removed)

    $conn->begin_transaction();
    try {
        $changes = [];

        // ── Step 1: release the OLD frame ──────────────────────────
        if ($oldUfc !== '') {
            if ($oldIsCustom) {
                $safeOld = $conn->real_escape_string($oldUfc);
                $oldRow  = $conn->query("SELECT id, sell_price FROM custom_frames WHERE invoice_number = '$inv' AND brand_key = '$safeOld' LIMIT 1")->fetch_assoc();
                if ($oldRow) {
                    $oldSellPrice = (float)$oldRow['sell_price'];
                    // Customer is no longer taking this custom frame at all — delete it outright.
                    phDeleteCustomFrameAndReclaimId($conn, $oldRow['id']);
                    $changes[] = phDiff('custom_frames row', "id={$oldRow['id']} brand_key=$oldUfc", 'deleted');
                }
            } else {
                $restored = phRestoreCatalogStock($conn, $oldUfc);
                if ($restored) {
                    $oldSellPrice = $restored['sell_price'];
                    $changes[] = phDiff("{$restored['table']}.stock (ufc=$oldUfc)", $restored['stock_before'], $restored['stock_before'] + 1);
                }
            }
        }

        $newUfc = null;

        // ── Step 2: apply the NEW frame ─────────────────────────────
        if ($mode === 'catalog') {
            $newUfc = trim($_POST['new_ufc'] ?? '');
            if ($newUfc === '') throw new Exception('New frame UFC is required.');
            $deducted = phDeductCatalogStock($conn, $newUfc);
            if (!$deducted) throw new Exception("Frame \"$newUfc\" not found or out of stock.");
            $newSellPrice = $deducted['sell_price'];
            $changes[] = phDiff("{$deducted['table']}.stock (ufc=$newUfc)", $deducted['stock_before'], $deducted['stock_before'] - 1);

        } elseif ($mode === 'custom_select') {
            $brandKey = trim($_POST['brand_key'] ?? '');
            if ($brandKey === '') throw new Exception('brand_key is required.');
            $safeKey = $conn->real_escape_string($brandKey);
            $selRes  = $conn->query("SELECT sell_price FROM custom_frames WHERE invoice_number = '$inv' AND brand_key = '$safeKey' LIMIT 1");
            if (!$selRes || $selRes->num_rows === 0) throw new Exception('Saved custom frame not found for this invoice.');
            $newSellPrice = (float)$selRes->fetch_assoc()['sell_price'];
            // Only one custom frame should be flagged purchased per invoice at a time.
            $conn->query("UPDATE custom_frames SET is_purchased = 0 WHERE invoice_number = '$inv'");
            $conn->query("UPDATE custom_frames SET is_purchased = 1 WHERE invoice_number = '$inv' AND brand_key = '$safeKey'");
            $newUfc = $brandKey;
            $changes[] = phDiff('custom_frames.is_purchased (brand_key=' . $brandKey . ')', 0, 1);

        } elseif ($mode === 'custom_new') {
            $brand = trim($_POST['brand'] ?? '');
            $size  = trim($_POST['size']  ?? '');
            $sellPrice = (int)($_POST['sell_price'] ?? 0);
            if ($brand === '' || $sellPrice <= 0) throw new Exception('Brand and sell price are required for a new custom frame.');

            $brandKey  = phBuildCustomFrameKey($brand, $size);
            $buyPrice  = getCustomFrameBuyPrice($sellPrice);
            $createdBy = $conn->real_escape_string($_SESSION['username'] ?? 'system');
            $safeKey   = $conn->real_escape_string($brandKey);

            // Make sure no other row for this invoice is flagged purchased.
            $conn->query("UPDATE custom_frames SET is_purchased = 0 WHERE invoice_number = '$inv'");

            $ins = $conn->query("INSERT INTO custom_frames
                (invoice_number, brand_key, sell_price, buy_price, is_purchased, created_by)
                VALUES ('$inv', '$safeKey', $sellPrice, $buyPrice, 1, '$createdBy')");
            if (!$ins) throw new Exception($conn->error);

            $newUfc = $brandKey;
            $newSellPrice = (float)$sellPrice;
            $changes[] = phDiff('custom_frames row', '(none)', "created: brand_key=$brandKey, sell_price=$sellPrice, buy_price=$buyPrice");

        } elseif ($mode === 'remove') {
            // Frame removed entirely, no replacement (frame_ufc becomes NULL, price contribution becomes 0).
            $newUfc = null;
            $newSellPrice = 0;
        }

        // ── Step 3: persist frame_ufc + adjusted total_amount ────────
        // total_amount is adjusted by the *difference* in frame selling
        // price (old → new), not recomputed from scratch, so any manual
        // discount baked into the original total is preserved.
        $newTotal = max(0, $oldTotal + ($newSellPrice - $oldSellPrice));

        $setParts = [];
        if ($newUfc !== $oldUfc) {
            $setParts[] = $newUfc === null ? "frame_ufc = NULL" : "frame_ufc = '" . $conn->real_escape_string($newUfc) . "'";
            $changes[]  = phDiff('frame_ufc', $oldUfc, $newUfc);
        }
        if ((float)$newTotal !== $oldTotal) {
            $setParts[] = "total_amount = " . (float)$newTotal;
            $changes[]  = phDiff('total_amount', $oldTotal, $newTotal);
        }
        if (!empty($setParts)) {
            $conn->query("UPDATE customer_orders SET " . implode(', ', $setParts) . " WHERE id = $order_id");
        }

        $conn->commit();

        if (!empty($changes)) phAppendEditLog($conn, $order_id, 'frame', implode('; ', $changes));

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
            'total_amount' => $newTotal,
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

    $stmt = $conn->prepare("SELECT customer_phone, customer_address, order_date, due_date, customer_number, lens_name FROM customer_orders WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $cur = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$cur) { echo json_encode(['success' => false, 'error' => 'Order not found']); exit(); }

    $newPhone     = trim($_POST['customer_phone']   ?? $cur['customer_phone']);
    $newAddress   = trim($_POST['customer_address'] ?? $cur['customer_address']);
    $newOrderDate = trim($_POST['order_date']       ?? $cur['order_date']);

    $setParts = [];
    $changes  = [];
    if ($newPhone !== (string)$cur['customer_phone']) {
        $setParts[] = "customer_phone = '" . $conn->real_escape_string($newPhone) . "'";
        $changes[]  = phDiff('customer_phone', $cur['customer_phone'], $newPhone);
    }
    if ($newAddress !== (string)$cur['customer_address']) {
        $setParts[] = "customer_address = '" . $conn->real_escape_string($newAddress) . "'";
        $changes[]  = phDiff('customer_address', $cur['customer_address'], $newAddress);
    }

    $newDueDate     = null;
    $newCustNumber  = null;
    $oldOrderDateNorm = !empty($cur['order_date']) ? date('Y-m-d', strtotime($cur['order_date'])) : null;
    if ($newOrderDate !== '' && $newOrderDate !== $oldOrderDateNorm) {
        $setParts[] = "order_date = '" . $conn->real_escape_string($newOrderDate) . "'";
        $changes[]  = phDiff('order_date', $oldOrderDateNorm, $newOrderDate);

        // customer_number's month/roman + yy suffix follows order_date.
        $regenNumber = phRegenerateCustomerNumber($cur['customer_number'], $newOrderDate);
        if ($regenNumber !== null) {
            $setParts[] = "customer_number = '" . $conn->real_escape_string($regenNumber) . "'";
            $changes[]  = phDiff('customer_number', $cur['customer_number'], $regenNumber);
            $newCustNumber = $regenNumber;
        }

        // due_date is derived, not directly editable: order_date + lead time
        // of whatever lens is currently on this order (stock/lab resolved the
        // same way the Lens tab does — stock preferred, cheapest tie-break).
        $lensInfo  = phLensLookupFull($cur['lens_name'] ?? '');
        $leadDays  = phLensLeadTimeDays($conn);
        $days      = ($lensInfo && $lensInfo['source'] === 'lab') ? $leadDays['lab'] : $leadDays['stock'];
        $computedDueDate = date('Y-m-d', strtotime($newOrderDate . " +{$days} days"));
        $oldDueDateNorm  = !empty($cur['due_date']) ? date('Y-m-d', strtotime($cur['due_date'])) : null;
        if ($computedDueDate !== $oldDueDateNorm) {
            $setParts[] = "due_date = '" . $conn->real_escape_string($computedDueDate) . "'";
            $changes[]  = phDiff('due_date', $oldDueDateNorm, $computedDueDate);
            $newDueDate = $computedDueDate;
        }
    }

    if (empty($setParts)) { echo json_encode(['success' => true, 'changed' => false]); exit(); }

    $ok = $conn->query("UPDATE customer_orders SET " . implode(', ', $setParts) . " WHERE id = $order_id");
    if (!$ok) { echo json_encode(['success' => false, 'error' => $conn->error]); exit(); }

    phAppendEditLog($conn, $order_id, 'order_info', implode('; ', $changes));
    echo json_encode([
        'success'          => true,
        'changed'          => true,
        'customer_phone'   => $newPhone,
        'customer_address' => $newAddress,
        'order_date'       => $newOrderDate,
        'due_date'         => $newDueDate,
        'customer_number'  => $newCustNumber,
    ]);
    exit();
}

    // ── Fetch all active orders (status 1-4) ─────────────────────────
    // JOIN dengan customer_examinations jika invoice_number valid (bukan '00')
    // Kolom nama pasien ada di customer_examinations.customer_name
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
            co.amount_paid,
            co.order_date,
            co.due_date,
            co.order_status,
            ce.customer_name  AS patient_name,
            ce.age,
            ce.gender,
            NULL              AS date_of_birth,
            ce.examination_code,
            ce.lens_modification,
            ce.pd_dist,
            ce.new_r_sph, ce.new_r_cyl, ce.new_r_ax, ce.new_r_add,
            ce.new_l_sph, ce.new_l_cyl, ce.new_l_ax, ce.new_l_add,
            pm.od_sph AS mod_r_sph, pm.od_cyl AS mod_r_cyl, pm.od_axis AS mod_r_ax, pm.od_add AS mod_r_add,
            pm.os_sph AS mod_l_sph, pm.os_cyl AS mod_l_cyl, pm.os_axis AS mod_l_ax, pm.os_add AS mod_l_add,
            cf.brand_key AS custom_frame_brand_key
        FROM customer_orders co
        LEFT JOIN customer_examinations ce
            ON co.invoice_number = ce.invoice_number
            AND co.invoice_number != '00'
        LEFT JOIN prescription_modifications pm
            ON co.invoice_number = pm.invoice_number
        LEFT JOIN custom_frames cf
            ON co.invoice_number = cf.invoice_number COLLATE utf8mb4_general_ci
        WHERE co.order_status BETWEEN 1 AND 4
        ORDER BY co.order_date DESC, co.id DESC
    ";
    $result = $conn->query($sql);

    $orders = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
    }

    // ── Lens sizes for orders currently "Order Received" (status 1) ────
    // Mirrors the original-vs-modified prescription logic used in invoice.php:
    // if lens_modification == 1 and a prescription_modifications row exists,
    // use the modified (mod_*) values; otherwise use the original (new_*) values.
    $lensSizeOrders = [];
    foreach ($orders as $o) {
        if ((int)$o['order_status'] !== 1) continue;
        $hasMod = ((int)($o['lens_modification'] ?? 0) === 1) && isset($o['mod_r_sph']) && $o['mod_r_sph'] !== null && $o['mod_r_sph'] !== '';

        // ── Determine prescription source status ───────────────────────
        // examination_code pattern e.g. "LZ/EC/001/VII/2026" (normal exam)
        // vs "LZ/EC/000-001/VII/2026" (the "000-" prefix on the sequence
        // segment marks the prescription as customer-provided, not measured
        // in-house).
        $examCode  = $o['examination_code'] ?? '';
        $codeParts = explode('/', $examCode);
        $seqPart   = $codeParts[2] ?? '';
        $isCustomerRx = (strpos($seqPart, '000-') === 0);

        if ($isCustomerRx) {
            $rxStatus = 'Customer-Provided Prescription';
        } elseif ($hasMod) {
            $rxStatus = 'Modified by Customer';
        } else {
            $rxStatus = 'Original Prescription';
        }

        // ── Determine lens type (Stock vs Lab) from order_date → due_date gap ──
        // Compared against the configurable lead times from the settings table:
        // closer to lens_stock_lead_time_days (default 2) => "Stock",
        // closer to lens_lab_lead_time_days   (default 10) => "Lab".
        $lensType = null;
        if (!empty($o['order_date']) && !empty($o['due_date']) && strpos($o['due_date'], '0000-00-00') !== 0) {
            $odTs = strtotime($o['order_date']);
            $ddTs = strtotime($o['due_date']);
            if ($odTs !== false && $ddTs !== false) {
                $leadDays = round(($ddTs - $odTs) / 86400);
                $distToStock = abs($leadDays - $lensStockLeadTimeDays);
                $distToLab   = abs($leadDays - $lensLabLeadTimeDays);
                $lensType = ($distToStock <= $distToLab) ? 'Stock' : 'Lab';
            }
        }

        // ── Determine frame name ────────────────────────────────────────
        // Case 1: frame_ufc is set, pattern "BRAND-MODEL-..." → take the
        //         segment before the first "-" (e.g. "RAYBAN-TB6283-52-14-156-C9" → "RAYBAN").
        // Case 2: frame_ufc is NULL → look up custom_frames.brand_key for the
        //         same invoice_number, pattern "...+...+BRAND" → take the
        //         segment after the last "+" (e.g. "52-18-140+08/07+BRENDEN" → "BRENDEN").
        $frameName = '';
        if (!empty($o['frame_ufc'])) {
            $ufcParts  = explode('-', $o['frame_ufc']);
            $frameName = trim($ufcParts[0]);
        } elseif (!empty($o['custom_frame_brand_key'])) {
            $bkParts   = explode('+', $o['custom_frame_brand_key']);
            $frameName = trim(end($bkParts));
        }

        // Frame name defaults to "S" (no frame name resolvable at all)
        if ($frameName === '') {
            $frameName = 'S';
        }

        $rSph = $hasMod ? $o['mod_r_sph'] : $o['new_r_sph'];
        $rCyl = $hasMod ? $o['mod_r_cyl'] : $o['new_r_cyl'];
        $rAx  = $hasMod ? $o['mod_r_ax']  : $o['new_r_ax'];
        $rAdd = $hasMod ? $o['mod_r_add'] : $o['new_r_add'];
        $lSph = $hasMod ? $o['mod_l_sph'] : $o['new_l_sph'];
        $lCyl = $hasMod ? $o['mod_l_cyl'] : $o['new_l_cyl'];
        $lAx  = $hasMod ? $o['mod_l_ax']  : $o['new_l_ax'];
        $lAdd = $hasMod ? $o['mod_l_add'] : $o['new_l_add'];

        // ── Skip frame-only purchases ────────────────────────────────────
        // If every prescription value is empty OR numerically zero, the
        // customer only bought a frame (no real lens prescription) — a
        // default PD value alone must not count as "has a prescription".
        $allEmpty = true;
        foreach ([$rSph, $rCyl, $rAx, $rAdd, $lSph, $lCyl, $lAx, $lAdd] as $v) {
            $vStr = trim((string)$v);
            if ($vStr !== '' && (float)$vStr != 0) { $allEmpty = false; break; }
        }
        if ($allEmpty) continue;

        $lensSizeOrders[] = [
            'invoice_number' => $o['invoice_number'],
            'patient_name'   => $o['patient_name'],
            'frame_ufc'      => $o['frame_ufc'],
            'frame_name'     => $frameName,
            'lens_name'      => $o['lens_name'],
            'lens_type'      => $lensType,
            'is_modified'    => $hasMod,
            'rx_status'      => $rxStatus,
            'order_date'     => $o['order_date'],
            'pd'             => $o['pd_dist'],
            'r_sph'          => $rSph,
            'r_cyl'          => $rCyl,
            'r_ax'           => $rAx,
            'r_add'          => $rAdd,
            'l_sph'          => $lSph,
            'l_cyl'          => $lCyl,
            'l_ax'           => $lAx,
            'l_add'          => $lAdd,
        ];
    }

    // ── Group into Stock / Lab (and Unspecified, if lead-time couldn't be determined) ──
    $lensGroupStock = [];
    $lensGroupLab   = [];
    $lensGroupOther = [];
    foreach ($lensSizeOrders as $lo) {
        if ($lo['lens_type'] === 'Stock') {
            $lensGroupStock[] = $lo;
        } elseif ($lo['lens_type'] === 'Lab') {
            $lensGroupLab[] = $lo;
        } else {
            $lensGroupOther[] = $lo;
        }
    }

    // ── Sort each group by order_date ascending (oldest first) ─────────
    $lensDateSort = function ($a, $b) {
        return strtotime($a['order_date'] ?? '') <=> strtotime($b['order_date'] ?? '');
    };
    usort($lensGroupStock, $lensDateSort);
    usort($lensGroupLab,   $lensDateSort);
    usort($lensGroupOther, $lensDateSort);

    // ── Status label & color map ──────────────────────────────────────
    $statusMap = [
        1 => ['label' => 'Order Received',      'color' => '#ffaa00', 'icon' => '📋', 'bg' => 'rgba(255,170,0,0.12)'],
        2 => ['label' => 'Manufacturing',        'color' => '#00cfff', 'icon' => '⚙️',  'bg' => 'rgba(0,207,255,0.12)'],
        3 => ['label' => 'Out for Delivery',     'color' => '#aa88ff', 'icon' => '🚚', 'bg' => 'rgba(170,136,255,0.12)'],
        4 => ['label' => 'Awaiting Collection',  'color' => '#00ff88', 'icon' => '✅', 'bg' => 'rgba(0,255,136,0.12)'],
        5 => ['label' => 'Finished',             'color' => '#555',    'icon' => '🏁', 'bg' => 'rgba(85,85,85,0.12)'],
    ];

    // ── WA message generator ──────────────────────────────────────────
    // Generates a contextual WA message based on order_status, patient name, age, gender
    function buildWAMessage($order, $statusMap) {
        $name    = trim($order['patient_name'] ?? 'Customer');
        $age     = (int)($order['age'] ?? 0);
        $gender  = strtolower(trim($order['gender'] ?? ''));
        $status  = (int)$order['order_status'];
        $invNum  = $order['invoice_number'] ?? '';
        $custNum = $order['customer_number'] ?? '';
        $dueDate = $order['due_date'] ? date('d/m/Y', strtotime($order['due_date'])) : '-';

        // ── Greeting based on gender & age ──────────────────────────
        // Children: < 13 | Teens: 13-17 | Adults: 18+ 
        if ($age > 0 && $age < 13) {
            // Children — address the parents
            $sapaan    = 'Sir/Ma\'am';
            $gaya      = 'formal_ortu'; // use formal language, parent context
        } elseif ($age >= 13 && $age <= 17) {
            // Remaja — formal
            if ($gender === 'male' || $gender === 'laki-laki' || $gender === 'm') {
                $sapaan = 'Saudara ' . explode(' ', $name)[0];
            } else {
                $sapaan = 'Saudari ' . explode(' ', $name)[0];
            }
            $gaya = 'remaja';
        } else {
            // Adults / elderly — formal
            if ($gender === 'male' || $gender === 'laki-laki' || $gender === 'm') {
                $sapaan = 'Bapak ' . explode(' ', $name)[0];
            } else {
                $sapaan = 'Ibu ' . explode(' ', $name)[0];
            }
            $gaya = 'dewasa';
        }

        // ── Salam pembuka (selalu di awal) ───────────────────────────
        $salam = "السَّلَامُ عَلَيْكُمْ وَرَحْمَةُ اللهِ وَبَرَكَاتُهُ\n\n";

        // ── Build message per status ─────────────────────────────────
        switch ($status) {
            case 1: // Pesanan Diterima / Sedang Diproses
                if ($gaya === 'formal_ortu') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami dari LenZa Optic ingin menginformasikan bahwa pesanan kacamata untuk putra/putri Anda dengan nomor order *$custNum* telah kami terima dan sedang dalam proses pengerjaan.\n\nNomor Invoice: *$invNum*\nEstimasi selesai: *$dueDate*\n\nTerima kasih telah mempercayakan kebutuhan penglihatan buah hati Anda kepada kami. Kami akan terus memberikan informasi perkembangannya. 🙏";
                } elseif ($gaya === 'remaja') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami dari LenZa Optic ingin menginformasikan bahwa pesanan kacamata dengan nomor order *$custNum* telah kami terima dan sedang dalam proses pengerjaan.\n\nNomor Invoice: *$invNum*\nEstimasi selesai: *$dueDate*\n\nTerima kasih atas kepercayaan Anda. Kami akan segera menginformasikan perkembangan selanjutnya. 🙏";
                } else {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami dari LenZa Optic ingin menginformasikan bahwa pesanan kacamata Anda dengan nomor order *$custNum* telah kami terima dan sedang dalam proses pengerjaan.\n\nNomor Invoice: *$invNum*\nEstimasi selesai: *$dueDate*\n\nTerima kasih atas kepercayaan Anda. Kami akan segera menginformasikan perkembangan selanjutnya. 🙏";
                }
                break;

            case 2: // Sedang Proses Produksi Lensa
                if ($gaya === 'formal_ortu') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami ingin menginformasikan bahwa kacamata putra/putri Anda (No. Order: *$custNum*) saat ini sedang dalam proses pembuatan lensa.\n\nSetiap detail dikerjakan dengan teliti dan penuh perhatian. Estimasi selesai: *$dueDate*\n\nTerima kasih atas kesabaran Anda. 🙏";
                } elseif ($gaya === 'remaja') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami ingin menginformasikan bahwa kacamata Anda (No. Order: *$custNum*) saat ini sedang dalam proses pembuatan lensa.\n\nSetiap detail dikerjakan dengan penuh ketelitian. Estimasi selesai: *$dueDate*\n\nTerima kasih atas kesabaran Anda. 🙏";
                } else {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami ingin menginformasikan bahwa kacamata Anda (No. Order: *$custNum*) saat ini sedang dalam proses pembuatan lensa.\n\nSetiap detail dikerjakan dengan penuh ketelitian. Estimasi selesai: *$dueDate*\n\nTerima kasih atas kesabaran Anda. 🙏";
                }
                break;

            case 3: // Dalam Pengiriman ke Toko
                if ($gaya === 'formal_ortu') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami ingin menyampaikan kabar baik bahwa kacamata putra/putri Anda (No. Order: *$custNum*) telah selesai dibuat dan saat ini sedang dalam perjalanan menuju toko kami.\n\nKami akan menghubungi kembali begitu kacamata tiba dan siap untuk diambil. 🚚";
                } elseif ($gaya === 'remaja') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami ingin menyampaikan kabar baik bahwa kacamata Anda (No. Order: *$custNum*) telah selesai dibuat dan saat ini sedang dalam perjalanan menuju toko kami.\n\nKami akan menghubungi Anda kembali begitu kacamata tiba dan siap untuk diambil. 🚚";
                } else {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami ingin menyampaikan kabar baik bahwa kacamata Anda (No. Order: *$custNum*) telah selesai dibuat dan saat ini sedang dalam perjalanan menuju toko kami.\n\nKami akan menghubungi Anda kembali begitu kacamata tiba dan siap untuk diambil. 🚚";
                }
                break;

            case 4: // Siap Diambil
                if ($gaya === 'formal_ortu') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nAlhamdulillah, kami dengan senang hati menginformasikan bahwa kacamata putra/putri Anda (No. Order: *$custNum*) telah selesai dan siap untuk diambil di toko kami.\n\nMohon membawa nomor invoice *$invNum* saat pengambilan.\n\nKami tunggu kedatangan Anda. Terima kasih 😊🙏";
                } elseif ($gaya === 'remaja') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nAlhamdulillah, kami dengan senang hati menginformasikan bahwa kacamata Anda (No. Order: *$custNum*) telah selesai dan siap untuk diambil di toko kami.\n\nMohon membawa nomor invoice *$invNum* saat pengambilan.\n\nKami tunggu kedatangan Anda. Terima kasih 😊🙏";
                } else {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nAlhamdulillah, kami dengan senang hati menginformasikan bahwa kacamata Anda (No. Order: *$custNum*) telah selesai dan siap untuk diambil di toko kami.\n\nMohon membawa nomor invoice *$invNum* saat pengambilan.\n\nKami tunggu kedatangan Anda. Terima kasih 😊🙏";
                }
                break;

            default:
                $msg = $salam . "Kepada pelanggan,\n\nBerikut informasi mengenai pesanan Anda dengan no. order *$custNum*. Silakan hubungi kami untuk informasi lebih lanjut.";
        }

        return $msg;
    }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
    <title>Completion Status — Active Orders</title>
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

        .cs-header-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            user-select: none;
        }

        /* ── Stat cards as filter buttons ───────────────────── */
        .cs-stat-card {
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }

        .cs-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 10px 10px 24px var(--shadow-dark), -10px -10px 24px var(--shadow-light);
            border-color: rgba(0,255,136,0.25);
        }

        .cs-stat-card.active {
            border-color: rgba(0,255,136,0.45);
            box-shadow: 0 0 14px rgba(0,255,136,0.12), 8px 8px 20px var(--shadow-dark), -8px -8px 20px var(--shadow-light);
        }

        /* ── Search bar ──────────────────────────────────────── */
        .cs-search-wrap {
            position: relative;
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
        }

        .cs-search:focus {
            border-color: rgba(0,255,136,0.3);
        }

        .cs-search-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.9rem;
            pointer-events: none;
        }

        /* ── Order card ──────────────────────────────────────── */
        .cs-card {
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

        .cs-patient-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

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

        .cs-chip.inv { color: #00cfff; border-color: rgba(0,207,255,0.25); background: rgba(0,207,255,0.07); }
        .cs-chip.cust { color: #aa88ff; border-color: rgba(170,136,255,0.25); background: rgba(170,136,255,0.07); }
        .cs-chip.age { color: #ffaa00; border-color: rgba(255,170,0,0.25); background: rgba(255,170,0,0.07); }

        /* ── Status badge ────────────────────────────────────── */
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

        /* ── Order details grid ──────────────────────────────── */
        .cs-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid rgba(255,255,255,0.05);
        }

        .cs-detail-item {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

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

        .cs-detail-value.price {
            color: #ffaa00;
            font-family: monospace;
        }

        .cs-detail-value.due { color: #ff6b6b; }
        .cs-detail-value.due.ok { color: #00ff88; }

        /* ── Bottom action row ───────────────────────────────── */
        .cs-card-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 16px;
            flex-wrap: wrap;
            gap: 10px;
        }

        /* ── Status stepper ──────────────────────────────────── */
        .cs-stepper {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .cs-step {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            font-size: 0.6rem;
            font-weight: 800;
            cursor: pointer;
            border: 2px solid rgba(255,255,255,0.08);
            background: var(--bg-color);
            color: var(--text-muted);
            box-shadow: 3px 3px 6px var(--shadow-dark), -3px -3px 6px var(--shadow-light);
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .cs-step.done {
            background: rgba(0,255,136,0.1);
            border-color: rgba(0,255,136,0.4);
            color: #00ff88;
        }

        .cs-step.current {
            background: rgba(0,255,136,0.18);
            border-color: #00ff88;
            color: #00ff88;
            box-shadow: 0 0 10px rgba(0,255,136,0.3), 3px 3px 6px var(--shadow-dark), -3px -3px 6px var(--shadow-light);
        }

        .cs-step:hover:not(.current) {
            border-color: rgba(0,255,136,0.3);
            color: #aaa;
        }

        .cs-step.step-disabled {
            cursor: not-allowed;
            opacity: 0.25;
            border-style: dashed;
            color: #555;
            font-size: 0.75rem;
        }

        .cs-step.step-disabled:hover {
            border-color: rgba(255,255,255,0.08);
            color: #555;
        }

        .cs-step-line.step-line-disabled {
            opacity: 0.2;
            border-top: 2px dashed rgba(255,255,255,0.2);
            background: transparent;
        }

        .cs-step-line {
            width: 12px;
            height: 2px;
            background: rgba(255,255,255,0.07);
            border-radius: 2px;
        }

        .cs-step-line.done-line {
            background: rgba(0,255,136,0.3);
        }

        /* ── WA Send button ──────────────────────────────────── */
        .cs-wa-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: rgba(37,211,102,0.12);
            border: 1px solid rgba(37,211,102,0.35);
            border-radius: 20px;
            color: #25d366;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.7px;
            padding: 7px 16px;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
            text-decoration: none;
        }

        .cs-wa-btn:hover {
            background: rgba(37,211,102,0.2);
            box-shadow: 0 0 12px rgba(37,211,102,0.2);
        }

        .cs-wa-btn svg {
            width: 15px; height: 15px; fill: #25d366;
        }

        /* ── Preview message modal ───────────────────────────── */
        .cs-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            z-index: 9000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .cs-modal-overlay.open {
            display: flex;
        }

        .cs-modal {
            background: var(--bg-color);
            border-radius: 24px;
            padding: 28px;
            max-width: 480px;
            width: 100%;
            box-shadow: 20px 20px 60px var(--shadow-dark), -20px -20px 60px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.07);
        }

        .cs-modal-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .cs-modal-sub {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-bottom: 16px;
        }

        .cs-msg-preview {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            padding: 14px 16px;
            font-size: 0.78rem;
            color: var(--text-main);
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
            height: 260px;
            overflow-y: auto;
            font-family: inherit;
            resize: none;
            width: 100%;
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.2s;
        }

        .cs-msg-preview:focus {
            border-color: rgba(0,255,136,0.3);
        }

        /* ── Muslim/Non-Muslim toggle ────────────────────────── */
        .cs-religion-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
        }

        .cs-religion-toggle-label {
            font-size: 0.68rem;
            color: var(--text-muted);
            letter-spacing: 0.5px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .cs-toggle-group {
            display: flex;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 20px;
            overflow: hidden;
            padding: 3px;
            gap: 3px;
        }

        .cs-toggle-btn {
            padding: 5px 14px;
            border-radius: 16px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            cursor: pointer;
            border: none;
            background: transparent;
            color: var(--text-muted);
            font-family: inherit;
            transition: all 0.2s;
        }

        .cs-toggle-btn.active {
            background: rgba(0,255,136,0.15);
            color: #00ff88;
            box-shadow: 0 0 8px rgba(0,255,136,0.15);
        }

        .cs-toggle-btn:hover:not(.active) {
            color: var(--text-main);
            background: rgba(255,255,255,0.05);
        }

        .cs-modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .cs-btn {
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

        .cs-btn.cancel {
            background: var(--bg-color);
            border-color: rgba(255,255,255,0.1);
            color: var(--text-muted);
            box-shadow: 4px 4px 8px var(--shadow-dark), -4px -4px 8px var(--shadow-light);
        }

        .cs-btn.cancel:hover { color: var(--text-main); }

        .cs-btn.send {
            background: rgba(37,211,102,0.12);
            border-color: rgba(37,211,102,0.4);
            color: #25d366;
        }

        .cs-btn.send:hover {
            background: rgba(37,211,102,0.22);
            box-shadow: 0 0 14px rgba(37,211,102,0.25);
        }

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
            max-height: 900px;
            opacity: 1;
        }

        .cs-chevron {
            font-size: 0.7rem;
            color: var(--text-muted);
            transition: transform 0.3s;
            flex-shrink: 0;
        }

        .cs-card.expanded .cs-chevron {
            transform: rotate(180deg);
        }


        .cs-empty {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .cs-empty-icon { font-size: 2.5rem; margin-bottom: 12px; }
        .cs-empty-title { font-size: 1rem; font-weight: 700; color: var(--text-main); }
        .cs-empty-sub { font-size: 0.75rem; margin-top: 5px; }

        /* ── Toast notification ──────────────────────────────── */
        #cs-toast {
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

        #cs-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        /* ── Summary stats ───────────────────────────────────── */
        .cs-stats-row {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 20px;
        }

        .cs-stats-top {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .cs-stats-bottom {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .cs-stat-card {
            flex: 1;
            min-width: 110px;
            background: var(--bg-color);
            border-radius: 16px;
            padding: 14px 16px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.04);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .cs-stats-bottom .cs-stat-card {
            flex: 0 0 auto;
            min-width: 160px;
            max-width: 220px;
            align-items: center;
            text-align: center;
        }

        /* ── Due Soon / Overdue filter card ─────────────────── */
        .cs-stat-card.due-alert {
            border-color: rgba(255,107,107,0.2);
        }
        .cs-stat-card.due-alert:hover {
            border-color: rgba(255,107,107,0.45);
            box-shadow: 10px 10px 24px var(--shadow-dark), -10px -10px 24px var(--shadow-light);
        }
        .cs-stat-card.due-alert.active {
            border-color: rgba(255,107,107,0.55);
            box-shadow: 0 0 14px rgba(255,107,107,0.18), 8px 8px 20px var(--shadow-dark), -8px -8px 20px var(--shadow-light);
        }
        .cs-due-badge {
            display: inline-block;
            font-size: 0.58rem;
            font-weight: 800;
            letter-spacing: 0.6px;
            padding: 2px 7px;
            border-radius: 10px;
            margin-left: 5px;
            vertical-align: middle;
        }
        .cs-due-badge.overdue {
            background: rgba(255,107,107,0.15);
            color: #ff6b6b;
            border: 1px solid rgba(255,107,107,0.3);
        }
        .cs-due-badge.soon {
            background: rgba(255,170,0,0.15);
            color: #ffaa00;
            border: 1px solid rgba(255,170,0,0.3);
        }

        /* ── Lens Sizes section (below Order Tracking) ───────────────── */
        .cs-lens-section {
            margin-top: -16px;
            background: var(--bg-color);
            border-radius: 18px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.04);
            padding: 18px;
        }
        .cs-lens-section-title {
            font-size: 1rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }

        /* ── Lens Sizes card (Order Received) — collapsible ─────────── */
        .cs-lens-card {
            background: var(--bg-color);
            border-radius: 16px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.04);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .cs-lens-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            cursor: pointer;
            user-select: none;
        }
        .cs-lens-card-title {
            font-size: 0.85rem;
            font-weight: 800;
            color: var(--text-color);
        }
        .cs-lens-card-chevron {
            font-size: 0.9rem;
            color: var(--text-muted);
            transition: transform 0.2s ease;
        }
        .cs-lens-card-chevron.open {
            transform: rotate(90deg);
        }
        .cs-lens-card-body {
            padding: 0 18px 16px 18px;
        }
        .cs-lens-card-body .cs-lens-item {
            margin-top: 12px;
        }
        .cs-lens-empty {
            font-size: 0.75rem;
            color: var(--text-muted);
            padding: 6px 0 4px 0;
        }
        .cs-lens-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .cs-lens-group + .cs-lens-group {
            padding-top: 14px;
            border-top: 1px solid rgba(255,255,255,0.06);
        }
        .cs-lens-group-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.3px;
        }
        .cs-lens-group-title--stock { color: #00ff88; }
        .cs-lens-group-title--lab   { color: #aa88ff; }
        .cs-lens-group-title--other { color: var(--text-muted); }
        .cs-lens-group-count {
            font-size: 0.62rem;
            font-weight: 800;
            color: var(--text-muted);
            background: rgba(255,255,255,0.06);
            border-radius: 10px;
            padding: 1px 8px;
        }
        .cs-lens-group-items {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .cs-lens-item {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 10px 12px;
        }
        .cs-lens-item-head {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            cursor: pointer;
            user-select: none;
        }
        .cs-lens-item-chevron {
            margin-left: auto;
            font-size: 0.8rem;
            color: var(--text-muted);
            transition: transform 0.2s ease;
        }
        .cs-lens-item-chevron.open {
            transform: rotate(90deg);
        }
        .cs-lens-item-body {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(255,255,255,0.06);
        }
        .cs-lens-item-line {
            font-size: 0.74rem;
            color: var(--text-color);
            margin-bottom: 6px;
        }
        .cs-lens-item-icon {
            display: inline-block;
            width: 18px;
            text-align: center;
        }
        .cs-lens-item-inv {
            font-size: 0.78rem;
            font-weight: 800;
            color: var(--text-color);
        }
        .cs-lens-item-name {
            font-size: 0.78rem;
            color: var(--text-muted);
        }
        .cs-lens-rx-badge {
            font-size: 0.58rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            padding: 2px 7px;
            border-radius: 10px;
            border: 1px solid transparent;
        }
        .cs-lens-rx-badge.original {
            background: rgba(0,255,136,0.12);
            color: #00ff88;
            border-color: rgba(0,255,136,0.3);
        }
        .cs-lens-rx-badge.modified {
            background: rgba(0,207,255,0.15);
            color: #00cfff;
            border-color: rgba(0,207,255,0.3);
        }
        .cs-lens-rx-badge.customer-rx {
            background: rgba(255,170,0,0.15);
            color: #ffaa00;
            border-color: rgba(255,170,0,0.3);
        }
        .cs-lens-type-badge {
            display: inline-block;
            font-size: 0.56rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            padding: 1px 6px;
            border-radius: 8px;
            margin-left: 6px;
            border: 1px solid transparent;
            vertical-align: middle;
        }
        .cs-lens-type-badge.stock {
            background: rgba(0,255,136,0.12);
            color: #00ff88;
            border-color: rgba(0,255,136,0.3);
        }
        .cs-lens-type-badge.lab {
            background: rgba(170,136,255,0.15);
            color: #aa88ff;
            border-color: rgba(170,136,255,0.3);
        }
        .cs-lens-item-frame {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 8px;
        }
        .cs-lens-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.72rem;
            font-variant-numeric: tabular-nums;
        }
        .cs-lens-table th, .cs-lens-table td {
            text-align: center;
            padding: 5px 6px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .cs-lens-table thead th {
            border-bottom: 1px solid rgba(255,255,255,0.12);
        }
        .cs-lens-table tbody tr:last-child td {
            border-bottom: none;
        }
        .cs-lens-table th {
            color: var(--text-muted);
            font-weight: 700;
        }
        .cs-lens-table td:first-child, .cs-lens-table th:first-child {
            text-align: left;
            color: var(--text-muted);
            font-weight: 700;
        }
        .cs-lens-table td {
            color: var(--text-color);
        }
        .cs-lens-item-pd {
            font-size: 0.68rem;
            color: var(--text-muted);
            margin-top: 6px;
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

        @media (max-width: 600px) {
            /* ── Layout ── */
            .cs-body { padding: 10px; }

            /* ── Header: stack title + search ── */
            .cs-header {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                margin-bottom: 16px;
            }
            .cs-title { font-size: 1.1rem; }
            .cs-search-wrap { max-width: 100%; }

            /* ── Stat cards: 2×2 grid on mobile ── */
            .cs-stats-top {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }
            .cs-stat-card { min-width: 0; padding: 10px 12px; }
            .cs-stat-num { font-size: 1.3rem; }
            .cs-stat-label { font-size: 0.58rem; }
            .cs-stats-bottom .cs-stat-card { min-width: 0; max-width: 100%; width: 100%; }

            /* ── Order card ── */
            .cs-card { padding: 14px 14px; border-radius: 16px; }

            /* ── Top row (header): name kiri, badge+chevron kanan — tetap row di mobile ── */
            .cs-card-header.cs-card-top {
                flex-direction: row;
                align-items: center;
                gap: 10px;
            }
            .cs-card-header .cs-patient-info { flex: 1; min-width: 0; }
            .cs-card-header .cs-patient-name { font-size: 0.9rem; }
            .cs-status-badge { align-self: center; font-size: 0.6rem; padding: 4px 9px; white-space: nowrap; }

            /* ── Chips: wrap & potong teks panjang ── */
            .cs-chip { font-size: 0.6rem; padding: 2px 8px; max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

            /* ── Card body: beri jarak dari header ── */
            .cs-card.expanded .cs-card-body { padding-top: 12px; }

            /* ── Details grid: 2 columns ── */
            .cs-details-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
                margin-top: 10px;
                padding-top: 10px;
            }
            .cs-detail-label { font-size: 0.58rem; }
            .cs-detail-value { font-size: 0.76rem; }

            /* ── Action row: stepper full-width, WA button full-width ── */
            .cs-card-actions {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                margin-top: 12px;
            }
            .cs-stepper { justify-content: space-between; }
            .cs-step { width: 32px; height: 32px; font-size: 0.65rem; }
            .cs-step-line { flex: 1; }

            /* ── WA button: full width ── */
            .cs-wa-btn {
                width: 100%;
                justify-content: center;
                padding: 10px 16px;
                font-size: 0.75rem;
            }

            /* ── Modal: bottom sheet style ── */
            .cs-modal-overlay {
                align-items: flex-end;
                padding: 0;
            }
            .cs-modal {
                border-radius: 24px 24px 0 0;
                padding: 20px 18px 30px;
                max-width: 100%;
                max-height: 92vh;
                overflow-y: auto;
            }
            .cs-msg-preview { height: 200px; font-size: 0.75rem; }

            /* ── Religion toggle wraps nicely ── */
            .cs-religion-toggle { flex-wrap: wrap; }

            /* ── Modal buttons: stack ── */
            .cs-modal-actions { flex-direction: column; }
            .cs-btn { flex: none; width: 100%; text-align: center; padding: 12px; }

            /* ── Toast: full width bottom ── */
            #cs-toast {
                left: 12px;
                right: 12px;
                bottom: 16px;
                text-align: center;
            }

            /* ── Back button: full width ── */
            .btn-group { padding: 0 10px; }
            .btn-group .back-main {
                width: 100%;
                box-sizing: border-box;
            }
        }
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

        /* ── Edit-order button (opens the full multi-group editor) ── */
        .ph-edit-order-btn {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            margin-left: 8px;
            vertical-align: middle;
            font-size: 0.72rem;
            line-height: 1;
            padding: 3px 8px;
            background: rgba(255,170,0,0.08);
            border: 1px solid rgba(255,170,0,0.3);
            border-radius: 8px;
            color: #ffaa00;
            cursor: pointer;
            font-family: inherit;
            transition: transform 0.2s ease, filter 0.2s ease, background 0.2s ease;
        }
        .ph-edit-order-btn b { font-weight: 900; font-size: 0.62rem; letter-spacing: 0.5px; }
        .ph-edit-order-btn:hover { transform: scale(1.05); background: rgba(255,170,0,0.16); filter: drop-shadow(0 2px 6px rgba(255,170,0,0.35)); }
        .ph-edit-order-btn:active { transform: scale(0.98); }

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
        .ph-eo-rx-grid.ph-eo-rx-grid-4 { grid-template-columns: 50px repeat(4, 1fr); }
        .ph-eo-rx-grid.ph-eo-rx-grid-6 { grid-template-columns: 40px repeat(6, 1fr); }
        .ph-eo-rx-head { font-size: 0.58rem; color: var(--text-muted); text-align: center; letter-spacing: 0.5px; }
        .ph-eo-rx-label { font-size: 0.62rem; font-weight: 800; color: #aa88ff; }
        .ph-eo-rx-grid input.ph-modal-input { padding: 8px 4px; text-align: center; font-size: 0.72rem; }

        /* Old vs New prescription shown as two clearly separate cards */
        .ph-eo-rx-card {
            border-radius: 16px; padding: 14px; margin-bottom: 16px;
            border: 1px solid rgba(255,255,255,0.08);
            background: var(--bg-color);
            box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
        }
        .ph-eo-rx-card.old { border-color: rgba(255,255,255,0.1); }
        .ph-eo-rx-card.new { border-color: rgba(0,255,136,0.28); background: rgba(0,255,136,0.03); }
        .ph-eo-rx-card-title {
            font-size: 0.66rem; font-weight: 800; letter-spacing: 0.6px; color: var(--text-main);
            margin-bottom: 10px;
        }
        .ph-eo-rx-card.new .ph-eo-rx-card-title { color: #00ff88; }
        .ph-eo-rx-card-title span { font-weight: 500; color: var(--text-muted); text-transform: none; letter-spacing: 0; margin-left: 4px; }

        .ph-eo-check {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 0.65rem; color: var(--text-muted); cursor: pointer;
            background: var(--bg-color); border: 1px solid rgba(255,255,255,0.08);
            border-radius: 10px; padding: 6px 10px;
        }
        .ph-eo-check input { accent-color: #00ff88; }

        /* Visual habit / digital usage — single-select toggle group */
        .ph-eo-toggle-group { display: flex; gap: 6px; flex-wrap: wrap; }
        .ph-eo-toggle-btn {
            flex: 1; min-width: 80px; font-family: inherit;
            padding: 9px 8px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.09);
            background: var(--bg-color); color: var(--text-muted);
            font-size: 0.62rem; font-weight: 700; letter-spacing: 0.4px; cursor: pointer;
            box-shadow: 3px 3px 6px var(--shadow-dark), -3px -3px 6px var(--shadow-light);
            transition: all 0.2s;
        }
        .ph-eo-toggle-btn.active { color: #00ff88; border-color: rgba(0,255,136,0.4); background: rgba(0,255,136,0.1); }

        /* Vision need — multi-select icon buttons */
        .ph-eo-vision-wrapper { display: flex; gap: 8px; flex-wrap: wrap; }
        .ph-eo-vision-btn {
            flex: 1; min-width: 88px; font-family: inherit;
            display: flex; flex-direction: column; align-items: center; gap: 3px;
            padding: 12px 8px; border-radius: 14px; border: 1px solid rgba(255,255,255,0.09);
            background: var(--bg-color); color: var(--text-muted); cursor: pointer;
            box-shadow: 3px 3px 6px var(--shadow-dark), -3px -3px 6px var(--shadow-light);
            transition: all 0.2s; position: relative;
        }
        .ph-eo-vision-btn .vn-icon { font-size: 1.2rem; }
        .ph-eo-vision-btn span { font-size: 0.6rem; font-weight: 800; letter-spacing: 0.5px; }
        .ph-eo-vision-btn small { font-size: 0.52rem; color: var(--text-muted); font-weight: 500; }
        .ph-eo-vision-btn .vn-led {
            width: 6px; height: 6px; border-radius: 50%; background: rgba(255,255,255,0.15);
            position: absolute; top: 8px; right: 8px;
        }
        .ph-eo-vision-btn.active { border-color: rgba(0,255,136,0.4); background: rgba(0,255,136,0.08); color: #00ff88; }
        .ph-eo-vision-btn.active small { color: #00ff88; opacity: 0.8; }
        .ph-eo-vision-btn.active .vn-led { background: #00ff88; box-shadow: 0 0 6px #00ff88; }

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

        /* Info tooltip icon — hover on desktop, tap/long-press on mobile (toggled via JS) */
        .ph-eo-info-wrap { display: inline-flex; align-items: center; gap: 4px; position: relative; }
        .ph-eo-info {
            display: inline-flex; align-items: center; justify-content: center;
            width: 16px; height: 16px; border-radius: 50%;
            font-size: 0.65rem; color: var(--text-muted);
            border: 1px solid rgba(255,255,255,0.15); cursor: help; flex-shrink: 0;
            position: relative;
        }
        .ph-eo-info::after {
            content: attr(data-tooltip);
            position: absolute; bottom: 130%; left: 50%; transform: translateX(-50%);
            width: 220px; max-width: 60vw; padding: 8px 10px; border-radius: 10px;
            background: #14161a; border: 1px solid rgba(255,255,255,0.12);
            color: var(--text-main); font-size: 0.62rem; line-height: 1.5; font-weight: 400;
            text-align: left; letter-spacing: 0; text-transform: none;
            box-shadow: 0 6px 18px rgba(0,0,0,0.4);
            opacity: 0; pointer-events: none; transition: opacity 0.15s ease;
            z-index: 20;
        }
        .ph-eo-info:hover::after,
        .ph-eo-info.show::after { opacity: 1; }

        /* Frame camera scanner (mirrors invoice.php's scan viewfinder) */
        @keyframes eo-fbs-slide { 0% { top: 10%; } 100% { top: 90%; } }

        @media (max-width: 600px) {
            #ph-toast { left: 12px; right: 12px; bottom: 16px; text-align: center; }
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
            <div class="cs-header-toggle" onclick="csToggleOrderTracking()">
                <div>
                    <div class="cs-title">📦 Order Tracking</div>
                    <div class="cs-subtitle">ACTIVE ORDERS — STATUS 1 TO 4</div>
                </div>
                <span class="cs-lens-card-chevron" id="cs-ordertracking-chevron">▸</span>
            </div>
            <div class="cs-search-wrap">
                <span class="cs-search-icon">🔍</span>
                <input type="text" class="cs-search" id="cs-search-input"
                       placeholder="Find by name, invoice, phone number…"
                       oninput="csFilterCards()">
            </div>
        </div>

        <div id="cs-ordertracking-body" style="display:none;">

        <!-- ── Summary Stats ───────────────────────────────────── -->
        <?php
            $counts = [1=>0, 2=>0, 3=>0, 4=>0];
            foreach ($orders as $o) { $counts[(int)$o['order_status']]++; }

            // Hitung due soon (≤ 2 hari ke depan) dan overdue (sudah lewat)
            $countOverdue  = 0;
            $countDueSoon  = 0;
            $now = time();
            foreach ($orders as $o) {
                if (empty($o['due_date'])) continue;
                // Skip invalid/zero MySQL dates (e.g. "0000-00-00") — these are not
                // real due dates and must not be counted as overdue.
                if (strpos($o['due_date'], '0000-00-00') === 0) continue;
                if ((int)$o['order_status'] === 4) continue; // status 4 dikecualikan
                $dueTs = strtotime($o['due_date']);
                if ($dueTs === false) continue; // guard against any other unparseable date
                $diff  = $dueTs - strtotime(date('Y-m-d')); // selisih hari (dalam detik)
                if ($diff < 0) {
                    $countOverdue++;
                } elseif ($diff <= 2 * 86400) {
                    $countDueSoon++;
                }
            }
            $countDueTotal = $countOverdue + $countDueSoon;
        ?>
        <div class="cs-stats-row">
            <!-- Top row: Order Received → Awaiting Collection -->
            <div class="cs-stats-top">
                <?php foreach ($statusMap as $s => $sm): if ($s === 5) continue; ?>
                <div class="cs-stat-card" data-filter="<?php echo $s; ?>" onclick="csSetFilter('<?php echo $s; ?>', this)" title="Filter: <?php echo $sm['label']; ?>">
                    <div class="cs-stat-num" style="color:<?php echo $sm['color']; ?>"><?php echo $counts[$s]; ?></div>
                    <div class="cs-stat-label"><?php echo $sm['icon'] . ' ' . $sm['label']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <!-- Bottom row: Total Active + Due Alert -->
            <div class="cs-stats-bottom">
                <div class="cs-stat-card" data-filter="all" onclick="csSetFilter('all', this)" title="Show all orders">
                    <div class="cs-stat-num" style="color:#fff;"><?php echo count($orders); ?></div>
                    <div class="cs-stat-label">Total Active</div>
                </div>
                <div class="cs-stat-card due-alert" data-filter="due" onclick="csSetFilter('due', this)" title="Filter: Due Soon & Overdue">
                    <div class="cs-stat-num" style="color:#ff6b6b;"><?php echo $countDueTotal; ?></div>
                    <div class="cs-stat-label">
                        ⏰ Due Alert
                        <?php if ($countOverdue > 0): ?>
                            <span class="cs-due-badge overdue"><?php echo $countOverdue; ?> overdue</span>
                        <?php endif; ?>
                        <?php if ($countDueSoon > 0): ?>
                            <span class="cs-due-badge soon"><?php echo $countDueSoon; ?> soon</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Order Cards ─────────────────────────────────────── -->
        <?php if (empty($orders)): ?>
            <div class="cs-empty">
                <div class="cs-empty-icon">🎉</div>
                <div class="cs-empty-title">No active orders</div>
                <div class="cs-empty-sub">All orders have been completed or no orders have been placed yet.</div>
            </div>
        <?php else: ?>

        <div id="cs-cards-container">
        <?php foreach ($orders as $o):
            $st       = (int)$o['order_status'];
            $sm       = $statusMap[$st] ?? $statusMap[1];
            $name     = trim($o['patient_name'] ?? '—');
            $age      = (int)($o['age'] ?? 0);
            $gender   = strtolower(trim($o['gender'] ?? ''));
            $genderIcon = ($gender === 'male' || $gender === 'laki-laki' || $gender === 'm') ? '👨' : '👩';
            $phone    = $o['customer_phone'] ?? '';
            $lensName = $o['lens_name'] ?? '—';
            $frameUfc = $o['frame_ufc'] ?? '—';
            $totalAmt = (int)$o['total_amount'];
            $paidAmt  = (int)$o['amount_paid'];
            $remaining = $totalAmt - $paidAmt;
            $orderDate = $o['order_date'] ? date('d/m/Y', strtotime($o['order_date'])) : '—';
            $dueDate   = $o['due_date']   ? date('d/m/Y', strtotime($o['due_date']))   : '—';
            $isDuePast = $o['due_date'] && strtotime($o['due_date']) < time();

            // Deteksi lensa stock vs lab: cocokkan lens_name dengan daftar dari lense_prices.json
            // Format: "CATEGORY / TYPE" — contoh "SINGLE VISION / BLUERAY"
            $isStock = in_array(strtoupper(trim($lensName)), $stockLensNames);

            // Deteksi short order: due_date - order_date == 3 hari → step 4 & 5 disabled
            $isShortOrder = false;
            if (!empty($o['order_date']) && !empty($o['due_date'])) {
                $orderTs = strtotime(date('Y-m-d', strtotime($o['order_date'])));
                $dueTs   = strtotime(date('Y-m-d', strtotime($o['due_date'])));
                $diffDays = ($dueTs - $orderTs) / 86400;
                $isShortOrder = ($diffDays <= 3);
            }

            // Build WA message
            $waMsg     = buildWAMessage($o, $statusMap);
            $waMsgEnc  = urlencode($waMsg);
            $waPhone   = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($waPhone) > 0 && $waPhone[0] === '0') {
                $waPhone = '62' . substr($waPhone, 1);
            }
            $waUrl     = 'https://wa.me/' . $waPhone . '?text=' . $waMsgEnc;
        ?>
        <div class="cs-card"
             data-id="<?php echo (int)$o['id']; ?>"
             data-status="<?php echo $st; ?>"
             data-name="<?php echo htmlspecialchars(strtolower($name)); ?>"
             data-inv="<?php echo htmlspecialchars(strtolower($o['invoice_number'] ?? '')); ?>"
             data-phone="<?php echo htmlspecialchars($phone); ?>"
             data-custnum="<?php echo htmlspecialchars(strtolower($o['customer_number'] ?? '')); ?>"
             data-fullname="<?php echo htmlspecialchars($name); ?>"
             data-age="<?php echo $age; ?>"
             data-gender="<?php echo htmlspecialchars(strtolower($gender)); ?>"
             data-custnum-orig="<?php echo htmlspecialchars($o['customer_number'] ?? ''); ?>"
             data-invnum="<?php echo htmlspecialchars($o['invoice_number'] ?? ''); ?>"
             data-duedate="<?php echo $dueDate; ?>"
             data-waphone="<?php echo htmlspecialchars(preg_replace('/[^0-9]/', '', $phone) ? (($waPhone)) : ''); ?>"
             data-isstock="<?php echo $isStock ? '1' : '0'; ?>"
             data-shortorder="<?php echo $isShortOrder ? '1' : '0'; ?>"
             data-orderdate="<?php echo htmlspecialchars($o['order_date'] ?? ''); ?>"
             data-duedate-raw="<?php echo htmlspecialchars($o['due_date'] ?? ''); ?>">

            <!-- Top row: patient info + status badge (clickable header) -->
            <div class="cs-card-header cs-card-top" onclick="csToggleCard(this)">
                <div class="cs-patient-info">
                    <div class="cs-patient-name">
                        <?php echo htmlspecialchars($name); ?> <?php echo $genderIcon; ?>
                        <button type="button" class="ph-edit-order-btn" title="Edit this order (customer, exam, prescription, lens, frame)"
                                onclick="event.stopPropagation(); phOpenEditOrderModal(<?php echo (int)$o['id']; ?>, '<?php echo htmlspecialchars($o['invoice_number'] ?? '', ENT_QUOTES); ?>');">
                                ✏️<b>EDIT</b>
                        </button>
                    </div>
                    <div class="cs-meta-row">
                        <span class="cs-chip inv">INV: <?php echo htmlspecialchars($o['invoice_number'] ?? '—'); ?></span>
                        <span class="cs-chip cust"><?php echo htmlspecialchars($o['customer_number'] ?? '—'); ?></span>
                        <?php if ($age > 0): ?>
                        <span class="cs-chip age"><?php echo $age; ?> yrs</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
                    <div class="cs-status-badge"
                         style="color:<?php echo $sm['color']; ?>;border-color:<?php echo $sm['color']; ?>33;background:<?php echo $sm['bg']; ?>">
                        <?php echo $sm['icon']; ?>&nbsp;<?php echo strtoupper($sm['label']); ?>
                    </div>
                    <span class="cs-chevron">▼</span>
                </div>
            </div>

            <!-- Collapsible body: details + actions -->
            <div class="cs-card-body">

            <!-- Details grid -->
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
                    <span class="cs-detail-label">Est. Completion</span>
                    <span class="cs-detail-value due <?php echo (!$isDuePast ? 'ok' : ''); ?>">
                        <?php echo $dueDate; ?><?php echo ($isDuePast && $dueDate !== '—') ? ' ⚠' : ''; ?>
                    </span>
                </div>
                <div class="cs-detail-item">
                    <span class="cs-detail-label">Total</span>
                    <span class="cs-detail-value price">Rp <?php echo number_format($totalAmt, 0, ',', '.'); ?></span>
                </div>
                <div class="cs-detail-item">
                    <span class="cs-detail-label">Remaining Balance</span>
                    <span class="cs-detail-value price" style="<?php echo ($remaining > 0 ? 'color:#ff6b6b' : 'color:#00ff88'); ?>">
                        <?php echo $remaining > 0 ? 'Rp ' . number_format($remaining, 0, ',', '.') : 'PAID ✓'; ?>
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

            <!-- Action row: stepper + WA button -->
            <div class="cs-card-actions">

                <!-- Status stepper: click to advance/set status -->
                <!-- Stock lens: hanya step 1, 4, 5 yang aktif -->
                <div class="cs-stepper" title="Click a number to change status">
                    <?php foreach ([1,2,3,4,5] as $step):
                        $isDone    = ($step < $st);
                        $isCurrent = ($step === $st);
                        $cls = $isDone ? 'done' : ($isCurrent ? 'current' : '');
                        $stepSm = $statusMap[$step] ?? [];
                        $tt = htmlspecialchars($stepSm['label'] ?? 'Step ' . $step);
                        // Stock lens: step 2 & 3 tidak tersedia
                        $isDisabled = $isStock && in_array($step, [2, 3]);
                        // Short order (due_date - order_date <= 3 hari): step 2 & 3 tidak tersedia
                        if (!$isDisabled && $isShortOrder && in_array($step, [2, 3])) {
                            $isDisabled = true;
                        }
                    ?>
                    <?php if ($step > 1): ?>
                    <div class="cs-step-line <?php echo ($step <= $st ? 'done-line' : ''); ?><?php echo $isDisabled ? ' step-line-disabled' : ''; ?>"></div>
                    <?php endif; ?>
                    <div class="cs-step <?php echo $cls; ?><?php echo $isDisabled ? ' step-disabled' : ''; ?>"
                         title="<?php
                             if ($isDisabled) {
                                 if ($isStock && in_array($step, [2, 3])) {
                                     echo 'Tidak tersedia untuk lensa stock';
                                 } else {
                                     echo 'Tidak tersedia untuk order 3 hari';
                                 }
                             } else {
                                 echo 'Set: ' . $tt;
                             }
                         ?>"
                         <?php if (!$isDisabled): ?>
                         onclick="csChangeStatus(<?php echo $o['id']; ?>, <?php echo $step; ?>, this)"
                         <?php endif; ?>>
                        <?php echo $isDisabled ? '—' : $step; ?>
                    </div>
                    <?php endforeach; ?>
                    <span style="font-size:0.65rem;color:var(--text-muted);margin-left:8px;letter-spacing:0.5px;">STATUS</span>
                </div>

                <!-- WA Button -->
                <?php if (!empty($phone)): ?>
                <button class="cs-wa-btn"
                        onclick="csOpenWAModal(<?php echo $st; ?>, <?php echo htmlspecialchars(json_encode($name)); ?>, <?php echo $age; ?>, <?php echo htmlspecialchars(json_encode(strtolower($gender))); ?>, <?php echo htmlspecialchars(json_encode($o['customer_number'] ?? '')); ?>, <?php echo htmlspecialchars(json_encode($o['invoice_number'] ?? '')); ?>, <?php echo htmlspecialchars(json_encode($dueDate)); ?>, <?php echo htmlspecialchars(json_encode($waPhone)); ?>, <?php echo htmlspecialchars(json_encode($name)); ?>)">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                    SEND WA
                </button>
                <?php else: ?>
                <span style="font-size:0.68rem;color:#555;font-style:italic;">Phone number unavailable</span>
                <?php endif; ?>

            </div><!-- /cs-card-actions -->
            </div><!-- /cs-card-body -->
        </div><!-- /cs-card -->
        <?php endforeach; ?>
        </div>

        <?php endif; ?>

        </div><!-- /cs-ordertracking-body -->

    </div><!-- /cs-body -->

            </div><!-- /main-card -->

            <!-- ── Lens Sizes (Order Received) — own card, below Order Tracking ── -->
            <div class="cs-lens-section">
                <div class="cs-lens-section-title">👓 Lens Sizes — Order Received (<?php echo count($lensSizeOrders); ?>)</div>

                <?php if (empty($lensSizeOrders)): ?>
                <div class="cs-lens-empty">No lens prescriptions to show for "Order Received" orders.</div>
                <?php else: ?>

                <?php
                    $lensGroups = [
                        ['key' => 'stock', 'label' => '📦 Stock',       'items' => $lensGroupStock],
                        ['key' => 'lab',   'label' => '🔬 Lab',         'items' => $lensGroupLab],
                        ['key' => 'other', 'label' => '❔ Unspecified', 'items' => $lensGroupOther],
                    ];
                    $lensItemSeq = 0;
                ?>
                <?php foreach ($lensGroups as $group): if (empty($group['items'])) continue; ?>
                <div class="cs-lens-card">
                    <div class="cs-lens-card-header" onclick="csToggleSection('cs-lens-group-body-<?php echo $group['key']; ?>', 'cs-lens-group-chevron-<?php echo $group['key']; ?>')">
                        <div class="cs-lens-card-title cs-lens-group-title--<?php echo $group['key']; ?>">
                            <?php echo $group['label']; ?>
                            <span class="cs-lens-group-count"><?php echo count($group['items']); ?></span>
                        </div>
                        <div class="cs-lens-card-chevron" id="cs-lens-group-chevron-<?php echo $group['key']; ?>">▸</div>
                    </div>
                    <div class="cs-lens-card-body" id="cs-lens-group-body-<?php echo $group['key']; ?>" style="display:none;">
                        <?php foreach ($group['items'] as $lo): $lensItemSeq++; $itemBodyId = 'cs-lens-item-body-' . $lensItemSeq; $itemChevronId = 'cs-lens-item-chevron-' . $lensItemSeq; ?>
                        <div class="cs-lens-item">
                            <div class="cs-lens-item-head" onclick="csToggleSection('<?php echo $itemBodyId; ?>', '<?php echo $itemChevronId; ?>')">
                                <span class="cs-lens-item-inv">#<?php echo htmlspecialchars($lo['invoice_number']); ?></span>
                                <span class="cs-lens-item-name"><?php echo htmlspecialchars($lo['patient_name'] ?: '-'); ?></span>
                                <?php
                                    $rxBadgeClass = 'original';
                                    if ($lo['rx_status'] === 'Customer-Provided Prescription') {
                                        $rxBadgeClass = 'customer-rx';
                                    } elseif ($lo['rx_status'] === 'Modified by Customer') {
                                        $rxBadgeClass = 'modified';
                                    }
                                ?>
                                <span class="cs-lens-rx-badge <?php echo $rxBadgeClass; ?>"><?php echo htmlspecialchars($lo['rx_status']); ?></span>
                                <span class="cs-lens-item-chevron" id="<?php echo $itemChevronId; ?>">▸</span>
                            </div>
                            <div class="cs-lens-item-body" id="<?php echo $itemBodyId; ?>" style="display:none;">
                                <div class="cs-lens-item-line"><span class="cs-lens-item-icon">🖼️</span> Frame: <strong><?php echo htmlspecialchars($lo['frame_name']); ?></strong></div>
                                <div class="cs-lens-item-line"><span class="cs-lens-item-icon">🔎</span> Lens: <strong><?php echo htmlspecialchars($lo['lens_name'] ?: '-'); ?></strong></div>
                                <table class="cs-lens-table">
                                    <thead>
                                        <tr><th></th><th>SPH</th><th>CYL</th><th>AXIS</th><th>ADD</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>OD (R)</td>
                                            <td><?php echo htmlspecialchars($lo['r_sph'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($lo['r_cyl'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($lo['r_ax'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($lo['r_add'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <td>OS (L)</td>
                                            <td><?php echo htmlspecialchars($lo['l_sph'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($lo['l_cyl'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($lo['l_ax'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($lo['l_add'] ?: '-'); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div class="cs-lens-item-pd">PD: <?php echo htmlspecialchars($lo['pd'] ?: '-'); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php endif; ?>
            </div>


            
        </div><!-- /content-area -->
        
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
    </div><!-- /main-wrapper -->
    <div class="logo-backdrop" id="logoBackdrop" ondblclick="zoomOutLogo(document.getElementById('storeLogo'))"></div>
        
    <!-- ── WA Preview Modal ─────────────────────────────────────── -->
    <div class="cs-modal-overlay" id="cs-modal-overlay">
        <div class="cs-modal">
            <div class="cs-modal-title">📱 WhatsApp Message Preview</div>
            <div class="cs-modal-sub" id="cs-modal-sub">To: —</div>
            <!-- Muslim / Non-Muslim toggle -->
            <div class="cs-religion-toggle">
                <span class="cs-religion-toggle-label">Customer:</span>
                <div class="cs-toggle-group">
                    <button class="cs-toggle-btn active" id="cs-toggle-muslim" onclick="csSetReligion('muslim')">&#9775;&#65039; Muslim</button>
                    <button class="cs-toggle-btn" id="cs-toggle-nonmuslim" onclick="csSetReligion('nonmuslim')">Non-Muslim</button>
                </div>
            </div>
            <textarea class="cs-msg-preview" id="cs-modal-msg" spellcheck="false"></textarea>
            <div class="cs-modal-actions">
                <button class="cs-btn cancel" onclick="csCloseModal()">Cancel</button>
                <a class="cs-btn send" id="cs-modal-wa-link" href="#" target="_blank" onclick="csUpdateWALinkAndClose()">
                    Send via WhatsApp &#128242;
                </a>
            </div>
        </div>
    </div>

    <!-- ── Toast ─────────────────────────────────────────────────── -->
    <div id="cs-toast"></div>

    <script>
        // ── Filter state ──────────────────────────────────────────────
        var _csActiveFilter = 'none'; // default: tidak ada yang dipilih, semua card tersembunyi

        // ── Toggle card expand/collapse ───────────────────────────────
        function csToggleCard(headerEl) {
            var card = headerEl.closest('.cs-card');
            card.classList.toggle('expanded');
        }

        function csToggleOrderTracking() {
            var body = document.getElementById('cs-ordertracking-body');
            var chevron = document.getElementById('cs-ordertracking-chevron');
            if (!body || !chevron) return;
            var isOpen = body.style.display !== 'none';
            body.style.display = isOpen ? 'none' : 'block';
            chevron.classList.toggle('open', !isOpen);
        }

        // Generic collapsible section toggle — used by the Lens Sizes group
        // cards (Stock/Lab/Unspecified) and by each individual lens item.
        function csToggleSection(bodyId, chevronId) {
            var body = document.getElementById(bodyId);
            var chevron = document.getElementById(chevronId);
            if (!body || !chevron) return;
            var isOpen = body.style.display !== 'none';
            body.style.display = isOpen ? 'none' : 'block';
            chevron.classList.toggle('open', !isOpen);
        }

        function csSetFilter(val, btn) {
            _csActiveFilter = val;
            document.querySelectorAll('.cs-stat-card').forEach(function(b) {
                b.classList.remove('active');
            });
            btn.classList.add('active');
            csFilterCards();
        }

        function csFilterCards() {
            var q      = (document.getElementById('cs-search-input').value || '').toLowerCase().trim();
            var filter = _csActiveFilter;

            // Pre-compute today's date string (YYYY-MM-DD) for due filter
            var today    = new Date();
            today.setHours(0,0,0,0);
            var todayTs  = today.getTime();
            var in2days  = todayTs + (2 * 86400 * 1000);

            var container = document.getElementById('cs-cards-container');
            var cards     = Array.prototype.slice.call(container.querySelectorAll('.cs-card'));

            cards.forEach(function(card) {
                var status  = card.getAttribute('data-status');
                var name    = card.getAttribute('data-name') || '';
                var inv     = card.getAttribute('data-inv') || '';
                var phone   = card.getAttribute('data-phone') || '';
                var custnum = card.getAttribute('data-custnum') || '';

                var matchFilter;
                if (filter === 'none') {
                    matchFilter = false;
                } else if (filter === 'all') {
                    matchFilter = true;
                } else if (filter === 'due') {
                    // Status 4 (Awaiting Collection) dikecualikan dari Due Alert
                    if (status === '4') {
                        matchFilter = false;
                    } else {
                        var dueDateRaw = card.getAttribute('data-duedate-raw') || '';
                        if (!dueDateRaw) {
                            matchFilter = false;
                        } else {
                            // Parse the date-only part manually as LOCAL time (Year, Month, Day).
                            // Using `new Date(dueDateRaw)` directly is unreliable: a plain
                            // "YYYY-MM-DD" string is parsed as UTC by the browser, while
                            // todayTs/in2days below are computed in local time — causing
                            // mismatches near midnight/timezone boundaries.
                            var dueDateOnly = dueDateRaw.split(' ')[0].split('T')[0]; // "YYYY-MM-DD"
                            var dp = dueDateOnly.split('-');
                            if (dp.length !== 3 || dp[0] === '0000') {
                                matchFilter = false; // invalid / zero MySQL date
                            } else {
                                var dueLocal = new Date(parseInt(dp[0], 10), parseInt(dp[1], 10) - 1, parseInt(dp[2], 10));
                                dueLocal.setHours(0, 0, 0, 0);
                                var dueTs = dueLocal.getTime();
                                matchFilter = isNaN(dueTs) ? false : (dueTs < todayTs || dueTs <= in2days);
                            }
                        }
                    }
                } else {
                    matchFilter = (status === filter);
                }

                var matchSearch = !q ||
                    name.indexOf(q) !== -1 ||
                    inv.indexOf(q) !== -1 ||
                    phone.indexOf(q) !== -1 ||
                    custnum.indexOf(q) !== -1;

                card.style.display = (matchFilter && matchSearch) ? '' : 'none';
            });

            // filter 'due': overdue/due soon ascending (paling lama lewat paling atas)
            // filter '4' (Awaiting Collection): ascending (paling duluan siap paling atas)
            if (filter === 'due' || filter === '4') {
                var visible = cards.filter(function(c) { return c.style.display !== 'none'; });
                visible.sort(function(a, b) {
                    var da = new Date(a.getAttribute('data-duedate-raw') || '9999-12-31').getTime();
                    var db = new Date(b.getAttribute('data-duedate-raw') || '9999-12-31').getTime();
                    return da - db;
                });
                visible.forEach(function(card) { container.appendChild(card); });
            }
        }

        // ── WA Message Builder (JavaScript, sinkron dengan PHP) ──────
        // isMuslim: true = sertakan salam & alhamdulillah; false = hilangkan keduanya
        function buildWAMessage(status, name, age, gender, custNum, invNum, dueDate, isMuslim) {
            age      = parseInt(age) || 0;
            gender   = (gender || '').toLowerCase();
            status   = parseInt(status);
            if (isMuslim === undefined) isMuslim = true;

            var salam;
            if (isMuslim) {
                salam = 'السَّلَامُ عَلَيْكُمْ وَرَحْمَةُ اللهِ وَبَرَكَاتُهُ\n\n';
            } else {
                var hour = new Date().getHours();
                var greeting = hour >= 3 && hour < 11  ? 'Selamat Pagi'
                            : hour >= 11 && hour < 15 ? 'Selamat Siang'
                            : hour >= 15 && hour < 19 ? 'Selamat Sore'
                            :                           'Selamat Malam';
                salam = greeting + '\n\n';
            }
            var sapaan, gaya;
            var firstName = name.split(' ')[0];

            if (age > 0 && age < 13) {
                sapaan = 'Bapak/Ibu';
                gaya   = 'formal_ortu';
            } else if (age >= 13 && age <= 17) {
                sapaan = (gender === 'male') ? 'Saudara ' + firstName : 'Saudari ' + firstName;
                gaya   = 'remaja';
            } else {
                sapaan = (gender === 'male') ? 'Bapak ' + firstName : 'Ibu ' + firstName;
                gaya   = 'dewasa';
            }

            // Prefix status 4: "Alhamdulillah, " hanya untuk Muslim
            var alhamdulillah = isMuslim ? 'Alhamdulillah, kami' : 'Kami';

            var msg = '';
            switch (status) {
                case 1:
                    if (gaya === 'formal_ortu') {
                        msg = salam + 'Kepada ' + sapaan + ' 🙏\n\nKami dari LenZa Optic ingin menginformasikan bahwa pesanan kacamata untuk putra/putri Anda dengan nomor order *' + custNum + '* telah kami terima dan sedang dalam proses pengerjaan.\n\nNomor Invoice: *' + invNum + '*\nEstimasi selesai: *' + dueDate + '*\n\nTerima kasih telah mempercayakan kebutuhan penglihatan buah hati Anda kepada kami. Kami akan terus memberikan informasi perkembangannya. 🙏';
                    } else {
                        msg = salam + 'Kepada ' + sapaan + ' 🙏\n\nKami dari LenZa Optic ingin menginformasikan bahwa pesanan kacamata Anda dengan nomor order *' + custNum + '* telah kami terima dan sedang dalam proses pengerjaan.\n\nNomor Invoice: *' + invNum + '*\nEstimasi selesai: *' + dueDate + '*\n\nTerima kasih atas kepercayaan Anda. Kami akan segera menginformasikan perkembangan selanjutnya. 🙏';
                    }
                    break;
                case 2:
                    if (gaya === 'formal_ortu') {
                        msg = salam + 'Kepada ' + sapaan + ' 🙏\n\nKami ingin menginformasikan bahwa kacamata putra/putri Anda (No. Order: *' + custNum + '*) saat ini sedang dalam proses pembuatan lensa.\n\nSetiap detail dikerjakan dengan teliti dan penuh perhatian. Estimasi selesai: *' + dueDate + '*\n\nTerima kasih atas kesabaran Anda. 🙏';
                    } else {
                        msg = salam + 'Kepada ' + sapaan + ' 🙏\n\nKami ingin menginformasikan bahwa kacamata Anda (No. Order: *' + custNum + '*) saat ini sedang dalam proses pembuatan lensa.\n\nSetiap detail dikerjakan dengan penuh ketelitian. Estimasi selesai: *' + dueDate + '*\n\nTerima kasih atas kesabaran Anda. 🙏';
                    }
                    break;
                case 3:
                    if (gaya === 'formal_ortu') {
                        msg = salam + 'Kepada ' + sapaan + ' 🙏\n\nKami ingin menyampaikan kabar baik bahwa kacamata putra/putri Anda (No. Order: *' + custNum + '*) telah selesai dibuat dan saat ini sedang dalam perjalanan menuju toko kami.\n\nKami akan menghubungi kembali begitu kacamata tiba dan siap untuk diambil. 🚚';
                    } else {
                        msg = salam + 'Kepada ' + sapaan + ' 🙏\n\nKami ingin menyampaikan kabar baik bahwa kacamata Anda (No. Order: *' + custNum + '*) telah selesai dibuat dan saat ini sedang dalam perjalanan menuju toko kami.\n\nKami akan menghubungi Anda kembali begitu kacamata tiba dan siap untuk diambil. 🚚';
                    }
                    break;
                case 4:
                    if (gaya === 'formal_ortu') {
                        msg = salam + 'Kepada ' + sapaan + ' 🙏\n\n' + alhamdulillah + ' dengan senang hati menginformasikan bahwa kacamata putra/putri Anda (No. Order: *' + custNum + '*) telah selesai dan siap untuk diambil di toko kami.\n\nMohon membawa nomor invoice *' + invNum + '* saat pengambilan.\n\nKami tunggu kedatangan Anda. Terima kasih 😊🙏';
                    } else {
                        msg = salam + 'Kepada ' + sapaan + ' 🙏\n\n' + alhamdulillah + ' dengan senang hati menginformasikan bahwa kacamata Anda (No. Order: *' + custNum + '*) telah selesai dan siap untuk diambil di toko kami.\n\nMohon membawa nomor invoice *' + invNum + '* saat pengambilan.\n\nKami tunggu kedatangan Anda. Terima kasih 😊🙏';
                    }
                    break;
                default:
                    msg = salam + 'Kepada pelanggan,\n\nBerikut informasi mengenai pesanan Anda dengan no. order *' + custNum + '*. Silakan hubungi kami untuk informasi lebih lanjut.';
            }
            return msg;
        }

        // ── Run initial filter on page load ──────────────────────────
        document.addEventListener('DOMContentLoaded', function() { csFilterCards(); });

        // ── Change order status via AJAX ─────────────────────────────
        function csChangeStatus(orderId, newStatus, stepEl) {
            // Proteksi: abaikan jika step ini disabled (lensa stock)
            if (stepEl.classList.contains('step-disabled')) return;

            var fd = new FormData();
            fd.append('action',     'update_status');
            fd.append('order_id',   orderId);
            fd.append('new_status', newStatus);

            fetch('completion_status.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        // Update the card's stepper visually
                        var card = stepEl.closest('.cs-card');
                        card.setAttribute('data-status', newStatus);

                        // Update all steps in this stepper
                        // Pertahankan class step-disabled untuk lensa stock dan short order
                        card.querySelectorAll('.cs-step').forEach(function(s, idx) {
                            var stepNum    = idx + 1;
                            var wasDisabled = s.classList.contains('step-disabled');
                            s.className = 'cs-step';
                            if (wasDisabled) {
                                s.classList.add('step-disabled');
                            } else if (stepNum < newStatus) {
                                s.classList.add('done');
                            } else if (stepNum === newStatus) {
                                s.classList.add('current');
                            }
                        });

                        // Update step lines — pertahankan step-line-disabled
                        card.querySelectorAll('.cs-step-line').forEach(function(ln, idx) {
                            var wasDisabled = ln.classList.contains('step-line-disabled');
                            ln.className = 'cs-step-line';
                            if (wasDisabled) {
                                ln.classList.add('step-line-disabled');
                            } else if ((idx + 2) <= newStatus) {
                                ln.classList.add('done-line');
                            }
                        });

                        // Update status badge
                        var statusMap = {
                            1: { label: 'ORDER RECEIVED',     color: '#ffaa00', icon: '📋', bg: 'rgba(255,170,0,0.12)' },
                            2: { label: 'MANUFACTURING',       color: '#00cfff', icon: '⚙️',  bg: 'rgba(0,207,255,0.12)' },
                            3: { label: 'OUT FOR DELIVERY',    color: '#aa88ff', icon: '🚚', bg: 'rgba(170,136,255,0.12)' },
                            4: { label: 'AWAITING COLLECTION', color: '#00ff88', icon: '✅', bg: 'rgba(0,255,136,0.12)' },
                            5: { label: 'FINISHED',            color: '#555',    icon: '🏁', bg: 'rgba(85,85,85,0.12)' },
                        };
                        var sm = statusMap[newStatus] || statusMap[1];
                        var badge = card.querySelector('.cs-status-badge');
                        if (badge) {
                            badge.style.color       = sm.color;
                            badge.style.borderColor = sm.color + '33';
                            badge.style.background  = sm.bg;
                            badge.innerHTML         = sm.icon + '&nbsp;' + sm.label;
                        }

                        // If status is now 5 (Finish), hide card after short delay
                        if (newStatus === 5) {
                            setTimeout(function() {
                                card.style.transition = 'opacity 0.5s, transform 0.5s';
                                card.style.opacity    = '0';
                                card.style.transform  = 'scale(0.95)';
                                setTimeout(function() { card.remove(); }, 500);
                            }, 800);
                        }

                        // Update WA button dengan pesan sesuai status baru
                        var waBtn = card.querySelector('.cs-wa-btn');
                        if (waBtn) {
                            var fullName = card.getAttribute('data-fullname') || '';
                            var age      = card.getAttribute('data-age') || '0';
                            var gender   = card.getAttribute('data-gender') || '';
                            var custNum  = card.getAttribute('data-custnum-orig') || '';
                            var invNum   = card.getAttribute('data-invnum') || '';
                            var dueDate  = card.getAttribute('data-duedate') || '-';
                            var waPhone  = card.getAttribute('data-waphone') || '';

                            var newMsg = buildWAMessage(newStatus, fullName, age, gender, custNum, invNum, dueDate, true);
                            var newUrl = 'https://wa.me/' + waPhone + '?text=' + encodeURIComponent(newMsg);

                            waBtn.onclick = (function(m, u, n, wp, st, fn, a, g, cn, inv, dd) {
                                return function() { csOpenWAModal(st, fn, a, g, cn, inv, dd, wp, n); };
                            })(newMsg, newUrl, fullName, waPhone, newStatus, fullName, age, gender, custNum, invNum, dueDate);
                        }

                        csShowToast('✅ Status updated');
                    } else {
                        csShowToast('❌ Failed: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(function() {
                    csShowToast('❌ Connection error');
                });
        }

        // ── WA Modal state ────────────────────────────────────────────
        var _csModalIsMuslim = true;
        var _csModalData     = {}; // { status, name, age, gender, custNum, invNum, dueDate, waPhone }

        function csSetReligion(religion) {
            _csModalIsMuslim = (religion === 'muslim');
            document.getElementById('cs-toggle-muslim').classList.toggle('active',    _csModalIsMuslim);
            document.getElementById('cs-toggle-nonmuslim').classList.toggle('active', !_csModalIsMuslim);

            // Re-build message preview & update WA link
            var d   = _csModalData;
            var msg = buildWAMessage(d.status, d.name, d.age, d.gender, d.custNum, d.invNum, d.dueDate, _csModalIsMuslim);
            var url = 'https://wa.me/' + d.waPhone + '?text=' + encodeURIComponent(msg);
            document.getElementById('cs-modal-msg').value         = msg;
            document.getElementById('cs-modal-wa-link').href      = url;
        }

        // ── WA Modal ──────────────────────────────────────────────────
        // status, name, age, gender, custNum, invNum, dueDate, waPhone, displayName
        function csOpenWAModal(status, name, age, gender, custNum, invNum, dueDate, waPhone, displayName) {
            // Store state for re-build on toggle
            _csModalIsMuslim = true; // reset to Muslim (default) each time modal opens
            _csModalData = { status: status, name: name, age: age, gender: gender,
                            custNum: custNum, invNum: invNum, dueDate: dueDate, waPhone: waPhone };

            // Reset toggle UI
            document.getElementById('cs-toggle-muslim').classList.add('active');
            document.getElementById('cs-toggle-nonmuslim').classList.remove('active');

            var msg = buildWAMessage(status, name, age, gender, custNum, invNum, dueDate, true);
            var url = 'https://wa.me/' + waPhone + '?text=' + encodeURIComponent(msg);

            document.getElementById('cs-modal-msg').value       = msg;
            document.getElementById('cs-modal-sub').textContent = 'To: ' + (displayName || name);
            document.getElementById('cs-modal-wa-link').href    = url;
            document.getElementById('cs-modal-overlay').classList.add('open');
        }

        function csCloseModal() {
            document.getElementById('cs-modal-overlay').classList.remove('open');
        }

        // Update WA link with current (possibly edited) textarea content before opening WA
        function csUpdateWALinkAndClose() {
            var editedMsg = document.getElementById('cs-modal-msg').value;
            var d = _csModalData;
            var waPhone = d.waPhone || '';
            var url = 'https://wa.me/' + waPhone + '?text=' + encodeURIComponent(editedMsg);
            document.getElementById('cs-modal-wa-link').href = url;
            csCloseModal();
        }

        // Close modal on overlay click
        document.getElementById('cs-modal-overlay').addEventListener('click', function(e) {
            if (e.target === this) csCloseModal();
        });

        // ── Toast ─────────────────────────────────────────────────────
        var _toastTimer = null;
        function csShowToast(msg) {
            var el = document.getElementById('cs-toast');
            el.textContent = msg;
            el.classList.add('show');
            clearTimeout(_toastTimer);
            _toastTimer = setTimeout(function() { el.classList.remove('show'); }, 2800);
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
<?php
    // ── Lens options for the Edit Order modal's lens dropdown ──────────
    // Parsed independently here (rather than reusing $lensData above) so this
    // block stays self-contained regardless of what the rest of the page does
    // with the JSON. Label format ("Category — Type", em dash, original
    // casing) matches exactly what invoice.php writes into lens_name.
    $phEoLensOptions = ['stock' => [], 'lab' => []];
    $phEoLensJsonPath = __DIR__ . '/lense_prices.json';
    if (file_exists($phEoLensJsonPath)) {
        $phEoLj = json_decode(file_get_contents($phEoLensJsonPath), true);
        if (!empty($phEoLj)) {
            foreach (['stock', 'lab'] as $lt) {
                if (!empty($phEoLj[$lt])) {
                    foreach ($phEoLj[$lt] as $cat => $types) {
                        foreach ($types as $type => $info) {
                            $lim = $info['limits'] ?? [];
                            $phEoLensOptions[$lt][] = [
                                'label'    => trim($cat) . ' — ' . trim($type),
                                'category' => strtoupper(trim($cat)),
                                'source'   => $lt,
                                'cost'     => (int)($info['cost']    ?? 0),
                                'selling'  => (int)($info['selling'] ?? 0),
                                'sph_from' => (int)($lim['sph_from'] ?? 0),
                                'sph_to'   => (int)($lim['sph_to']   ?? 0),
                                'cyl_from' => (int)($lim['cyl_from'] ?? 0),
                                'cyl_to'   => (int)($lim['cyl_to']   ?? 0),
                                'comb_max' => (int)($lim['comb_max'] ?? 0),
                                'add_from' => (int)($lim['add_from'] ?? 0),
                                'add_to'   => (int)($lim['add_to']   ?? 0),
                            ];
                        }
                    }
                }
            }
        }
    }
?>
            </div>
        </div>
    </div>

    <!-- Lens options for the Edit Order modal's Lens tab dropdown, built server-side from lense_prices.json -->
    <script>var PH_EO_LENS_OPTIONS = <?php echo json_encode($phEoLensOptions, JSON_UNESCAPED_UNICODE); ?>;</script>

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

                        <!-- ── OLD PRESCRIPTION (previous glasses) ─────── -->
                        <div class="ph-eo-rx-card old">
                            <div class="ph-eo-rx-card-title">🕶 OLD PRESCRIPTION <span>(previous glasses)</span></div>
                            <div class="ph-eo-rx-grid ph-eo-rx-grid-4">
                                <div></div><div class="ph-eo-rx-head">SPH</div><div class="ph-eo-rx-head">CYL</div><div class="ph-eo-rx-head">AXIS</div><div class="ph-eo-rx-head">ADD</div>
                                <div class="ph-eo-rx-label">R</div>
                                <input class="ph-modal-input" id="eo-e-old_r_sph"><input class="ph-modal-input" id="eo-e-old_r_cyl"><input class="ph-modal-input" id="eo-e-old_r_ax"><input class="ph-modal-input" id="eo-e-old_r_add">
                                <div class="ph-eo-rx-label">L</div>
                                <input class="ph-modal-input" id="eo-e-old_l_sph"><input class="ph-modal-input" id="eo-e-old_l_cyl"><input class="ph-modal-input" id="eo-e-old_l_ax"><input class="ph-modal-input" id="eo-e-old_l_add">
                            </div>
                        </div>

                        <!-- ── NEW PRESCRIPTION (current exam result) ──── -->
                        <!-- VISUS and UCVA are merged into this table (per request) instead of standing alone. -->
                        <div class="ph-eo-rx-card new">
                            <div class="ph-eo-rx-card-title">✨ NEW PRESCRIPTION <span>(current exam result)</span></div>
                            <div class="ph-eo-rx-grid ph-eo-rx-grid-6">
                                <div></div><div class="ph-eo-rx-head">SPH</div><div class="ph-eo-rx-head">CYL</div><div class="ph-eo-rx-head">AXIS</div><div class="ph-eo-rx-head">ADD</div><div class="ph-eo-rx-head">VISUS</div><div class="ph-eo-rx-head">UCVA</div>
                                <div class="ph-eo-rx-label">R</div>
                                <input class="ph-modal-input" id="eo-e-new_r_sph"><input class="ph-modal-input" id="eo-e-new_r_cyl"><input class="ph-modal-input" id="eo-e-new_r_ax"><input class="ph-modal-input" id="eo-e-new_r_add"><input class="ph-modal-input" id="eo-e-new_r_visus"><input class="ph-modal-input" id="eo-e-ucva_r">
                                <div class="ph-eo-rx-label">L</div>
                                <input class="ph-modal-input" id="eo-e-new_l_sph"><input class="ph-modal-input" id="eo-e-new_l_cyl"><input class="ph-modal-input" id="eo-e-new_l_ax"><input class="ph-modal-input" id="eo-e-new_l_add"><input class="ph-modal-input" id="eo-e-new_l_visus"><input class="ph-modal-input" id="eo-e-ucva_l">
                            </div>
                            <div class="ph-modal-field" style="max-width:180px;margin:12px auto 0;">
                                <label style="text-align:center;">PD Distance</label>
                                <input class="ph-modal-input" id="eo-e-pd_dist" style="text-align:center;">
                            </div>
                        </div>

                        <!-- ── Visual Habit ─────────────────────────────── -->
                        <div class="ph-modal-field">
                            <label>Visual Habit</label>
                            <input type="hidden" id="eo-e-visual_habit" value="1">
                            <div class="ph-eo-toggle-group" data-target="eo-e-visual_habit">
                                <button type="button" class="ph-eo-toggle-btn active" data-value="1">INDOOR</button>
                                <button type="button" class="ph-eo-toggle-btn" data-value="2">OUTDOOR</button>
                                <button type="button" class="ph-eo-toggle-btn" data-value="3">BOTH</button>
                            </div>
                        </div>

                        <!-- ── Digital Usage ─────────────────────────────── -->
                        <div class="ph-modal-field">
                            <label>Digital Device Usage</label>
                            <input type="hidden" id="eo-e-digital_usage" value="1">
                            <div class="ph-eo-toggle-group" data-target="eo-e-digital_usage">
                                <button type="button" class="ph-eo-toggle-btn active" data-value="1">LOW (&lt; 2H)</button>
                                <button type="button" class="ph-eo-toggle-btn" data-value="2">MODERATE (2-5H)</button>
                                <button type="button" class="ph-eo-toggle-btn" data-value="3">HIGH (&gt; 5H)</button>
                            </div>
                        </div>

                        <!-- ── Vision Need (multi-select) ───────────────── -->
                        <div class="ph-modal-field">
                            <label>⚑ Vision Need <span style="text-transform:none;font-weight:400;">(multiple selection allowed)</span></label>
                            <input type="checkbox" id="eo-e-need_distance"     style="display:none;">
                            <input type="checkbox" id="eo-e-need_intermediate" style="display:none;">
                            <input type="checkbox" id="eo-e-need_near"         style="display:none;">
                            <div class="ph-eo-vision-wrapper">
                                <button type="button" class="ph-eo-vision-btn" data-target="eo-e-need_distance">
                                    <div class="vn-icon">🔭</div><span>DISTANCE</span><small>Far Vision</small><div class="vn-led"></div>
                                </button>
                                <button type="button" class="ph-eo-vision-btn" data-target="eo-e-need_intermediate">
                                    <div class="vn-icon">🖥️</div><span>INTERMEDIATE</span><small>Mid Range</small><div class="vn-led"></div>
                                </button>
                                <button type="button" class="ph-eo-vision-btn" data-target="eo-e-need_near">
                                    <div class="vn-icon">📖</div><span>NEAR</span><small>Reading/Close-up</small><div class="vn-led"></div>
                                </button>
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
                            <span class="ph-eo-info-wrap">
                                <button class="ph-modal-btn cancel" id="eo-p-revert-btn" onclick="phEoPrescriptionSimple('revert')">↩ Revert to Original Rx</button>
                                <span class="ph-eo-info" data-tooltip="Switches the customer back to the ORIGINAL (unmodified) prescription. The modification already on record is kept as history, not deleted.">ⓘ</span>
                            </span>
                            <span class="ph-eo-info-wrap">
                                <button class="ph-modal-btn confirm" id="eo-p-reapply-btn" onclick="phEoPrescriptionSimple('reapply')">↪ Re-apply Last Modification</button>
                                <span class="ph-eo-info" data-tooltip="Turns the last recorded modification back ON without retyping it. Only shown while the original Rx is currently active and a previous modification exists.">ⓘ</span>
                            </span>
                        </div>
                        <div class="ph-eo-note" style="margin-top:10px;">
                            <b>Revert</b> switches the customer back to their original Rx — the modification already on record is kept as history, not deleted.
                            <b>Re-apply</b> only appears after a revert, to quickly restore that same last-recorded modification without retyping it.
                        </div>

                        <div style="margin:18px 0 8px;font-size:0.65rem;color:var(--text-muted);letter-spacing:0.6px;text-transform:uppercase;">Or record a brand-new modification</div>
                        <div class="ph-eo-note" id="eo-p-prefill-note">Fields below are pre-filled with the prescription the customer is currently using — edit whichever values changed.</div>
                        <div class="ph-eo-rx-grid ph-eo-rx-grid-4">
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
                            <label>Lens</label>
                            <select class="ph-modal-input" id="eo-l-name"></select>
                            <div class="ph-modal-preview" id="eo-l-preview"></div>
                            <div class="ph-eo-note" id="eo-l-filter-note" style="margin-top:8px;"></div>
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

                        <!-- Catalog / camera + manual scan (same jsQR approach as invoice.php) -->
                        <div class="ph-eo-fpanel active" data-fmode="catalog">

                            <div id="eo-fbs-viewfinder" style="display:none;margin-top:6px;">
                                <div style="position:relative;width:100%;max-width:280px;height:200px;margin:0 auto;border-radius:16px;overflow:hidden;background:#000;box-sizing:border-box;">
                                    <video id="eo-fbs-video" autoplay muted playsinline style="width:100%;height:100%;object-fit:cover;"></video>
                                    <div id="eo-fbs-scanline" style="position:absolute;left:10%;width:80%;height:2px;background:rgba(0,255,136,0.7);box-shadow:0 0 8px rgba(0,255,136,0.6);top:50%;animation:eo-fbs-slide 2s linear infinite;pointer-events:none;"></div>
                                    <div id="eo-fbs-cam-status" style="position:absolute;bottom:10px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.65);color:#00ff88;font-size:9px;padding:3px 10px;border-radius:20px;letter-spacing:1px;white-space:nowrap;">&#9679; SCANNING…</div>
                                    <canvas id="eo-fbs-canvas" style="display:none;"></canvas>
                                </div>
                            </div>

                            <div class="ph-modal-field" style="margin-top:10px;">
                                <label>Frame UFC (scan with camera or type manually)</label>
                                <input type="text" class="ph-modal-input" id="eo-f-ufc" placeholder="Scan or type UFC code" autocomplete="off" oninput="this.value=this.value.toUpperCase()">
                            </div>

                            <div class="ph-modal-actions" style="margin-top:0;">
                                <button type="button" class="ph-modal-btn cancel" id="eo-fbs-start-btn" onclick="eoFbsStartCamera()">📷 Start Scanner</button>
                                <button type="button" class="ph-modal-btn cancel" id="eo-fbs-stop-btn" style="display:none;" onclick="eoFbsStopCamera()">⏹ Stop Scanner</button>
                                <button type="button" class="ph-modal-btn confirm" onclick="eoFbsLookupManual()">🔍 Look Up</button>
                            </div>

                            <div class="ph-modal-preview" id="eo-f-ufc-preview"></div>
                        </div>

                        <!-- Existing saved custom frames for this invoice -->
                        <div class="ph-eo-fpanel" data-fmode="custom_select">
                            <div class="ph-eo-note">
                                These are custom frames already saved against this invoice (e.g. from earlier options the customer tried).
                                Picking one just marks it as the active choice — it does not create a duplicate row.
                            </div>
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
                            <div class="ph-eo-note">This will remove the frame from the order. Any custom frame will be deleted (and its ID reclaimed if it was the highest ID); catalog stock will be restored. No replacement frame will be assigned.</div>
                        </div>

                        <div class="ph-modal-actions">
                            <button class="ph-modal-btn confirm" onclick="phEoSaveFrame()">Save Frame Changes</button>
                        </div>
                    </div>

                    <!-- ── Group: Order Info ────────────────────────── -->
                    <div class="ph-eo-group" data-group="order_info">
                        <div class="ph-modal-field"><label>Phone</label><input type="text" class="ph-modal-input" id="eo-o-phone"></div>
                        <div class="ph-modal-field"><label>Address</label><textarea class="ph-modal-input" id="eo-o-address" rows="2"></textarea></div>
                        <div class="ph-modal-field"><label>Order Date</label><input type="date" class="ph-modal-input" id="eo-o-order-date"></div>
                        <div class="ph-eo-note">Changing Order Date automatically regenerates <code>customer_number</code>'s month/year (its sequence number stays the same) and recalculates Due Date from the new Order Date + the current lens's lead time. Due Date itself isn't directly editable here.</div>
                        <div class="ph-modal-actions">
                            <button class="ph-modal-btn confirm" onclick="phEoSaveOrderInfo()">Save Order Info</button>
                        </div>
                    </div>

                </div>

                <div class="ph-modal-preview" id="ph-eo-msg"></div>
                <div class="ph-modal-actions" style="margin-top:6px;">
                    <button class="ph-modal-btn cancel" onclick="phCloseEditOrderModal()">Close</button>
                </div>
    <div id="ph-toast"></div>
    <script>
        // ── Toast (shared by the Edit Order modal) ──────────────────────────
        var _phToastTimer = null;
        function phShowToast(msg) {
            var el = document.getElementById('ph-toast');
            if (!el) return;
            el.textContent = msg;
            el.classList.add('show');
            clearTimeout(_phToastTimer);
            _phToastTimer = setTimeout(function() { el.classList.remove('show'); }, 2800);
        }
    </script>
    <!-- ══════════════════════════════════════════════════════════════
         EDIT ORDER MODAL — client logic
         Frame tab camera scan reuses the same jsQR library + frame_lookup.php
         endpoint that invoice.php's frame scanner already uses.
         ══════════════════════════════════════════════════════════════ -->
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
    <script>
        // ── State ─────────────────────────────────────────────────────────
        var _phEo = {
            orderId: null,
            invoice: null,
            card: null,        // reference to the .cs-card DOM node being edited
            details: null,     // last fetched edit_get_details payload
            frameMode: 'catalog',
            hasChanges: false  // true once any group has been saved successfully
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
            _phEo.hasChanges = false;

            document.getElementById('ph-eo-overlay').classList.add('open');
            document.getElementById('ph-eo-gate-error').textContent = '';
            document.getElementById('ph-eo-gate-password').value = '';
            phEoShowMsg('');

            // Every single open of the editor requires the admin password
            // again — there is no silent bypass, even right after a previous
            // successful edit in the same browser session.
            document.getElementById('ph-eo-gate').style.display = 'block';
            document.getElementById('ph-eo-editor').style.display = 'none';
        }

        function phCloseEditOrderModal() {
            document.getElementById('ph-eo-overlay').classList.remove('open');
            if (_phEo.frameStreamStop) _phEo.frameStreamStop(); // stop the camera if it was left running

            // Invalidate the server-side unlock immediately, so the next time
            // the editor is opened (even for the same order) it must verify
            // the admin password again.
            var fd = new FormData();
            fd.append('action', 'edit_lock_access');
            fetch('purchase_history.php', { method: 'POST', body: fd })
                .catch(function() {})
                .then(function() {
                    // If anything was actually saved, refresh the page so every
                    // card reflects the new data instead of relying on partial
                    // DOM patching.
                    if (_phEo.hasChanges) location.reload();
                });
        }

        document.getElementById('ph-eo-overlay').addEventListener('click', function(e) {
            if (e.target === this) phCloseEditOrderModal();
        });

        // Every text/number/textarea field in the editor: select all existing
        // text on focus, so typing immediately replaces the old value instead
        // of the user having to manually clear it first.
        document.getElementById('ph-eo-editor').addEventListener('focusin', function(e) {
            var t = e.target;
            if (!t) return;
            var tag = t.tagName;
            var type = (t.getAttribute('type') || '').toLowerCase();
            if (tag === 'TEXTAREA' || (tag === 'INPUT' && ['text', 'number', 'password'].indexOf(type) !== -1)) {
                t.select();
            }
        });

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

            // Visual habit / digital usage — single-select toggle groups (values 1/2/3)
            ['eo-e-visual_habit', 'eo-e-digital_usage'].forEach(function(hiddenId) {
                var field = hiddenId.replace('eo-e-', '');
                var val = String(exam[field] || '1');
                var hidden = document.getElementById(hiddenId);
                if (hidden) hidden.value = val;
                var group = document.querySelector('.ph-eo-toggle-group[data-target="' + hiddenId + '"]');
                if (group) {
                    group.querySelectorAll('.ph-eo-toggle-btn').forEach(function(btn) {
                        btn.classList.toggle('active', btn.getAttribute('data-value') === val);
                    });
                }
            });

            // Vision need — multi-select icon buttons (0/1 each)
            ['eo-e-need_distance', 'eo-e-need_intermediate', 'eo-e-need_near'].forEach(function(chkId) {
                var field = chkId.replace('eo-e-', '');
                var isOn = String(exam[field]) === '1';
                var chk = document.getElementById(chkId);
                if (chk) chk.checked = isOn;
                var btn = document.querySelector('.ph-eo-vision-btn[data-target="' + chkId + '"]');
                if (btn) btn.classList.toggle('active', isOn);
            });

            // Prescription group
            var statusEl = document.getElementById('eo-p-status');
            var isModified = String(exam.lens_modification) === '1';
            statusEl.textContent = 'Current status: ' + (isModified ? 'MODIFIED prescription is active' : 'ORIGINAL prescription is active');
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
            // Revert only makes sense if a modification is currently active;
            // Re-apply only makes sense if it's currently reverted but a
            // previous modification exists to bring back.
            var revertBtn  = document.getElementById('eo-p-revert-btn');
            var reapplyBtn = document.getElementById('eo-p-reapply-btn');
            if (revertBtn)  revertBtn.style.display  = isModified ? 'inline-flex' : 'none';
            if (reapplyBtn) reapplyBtn.style.display  = (!isModified && data.last_mod) ? 'inline-flex' : 'none';

            // Pre-fill the "new modification" mini-form with whichever Rx the
            // customer is currently actually using (see active_rx from the server).
            var activeRx = data.active_rx || {};
            ['od_sph','od_cyl','od_axis','od_add','os_sph','os_cyl','os_axis','os_add'].forEach(function(f) {
                var el = document.getElementById('eo-p-' + f);
                if (el) el.value = activeRx[f] || '';
            });

            // Lens group
            phEoFilterLensOptions(); // builds the dropdown and greys out lenses that don't fit the current Rx
            var lensSelect = document.getElementById('eo-l-name');
            lensSelect.value = order.lens_name || '';
            // If the saved lens_name isn't one of the current JSON options
            // (e.g. it was renamed/removed in lense_prices.json), add it as
            // a one-off option so the actual saved value is still visible.
            if (order.lens_name && lensSelect.value !== order.lens_name) {
                var opt = document.createElement('option');
                opt.value = order.lens_name;
                opt.textContent = order.lens_name + ' (not in current price list)';
                lensSelect.appendChild(opt);
                lensSelect.value = order.lens_name;
            }
            phEoUpdateLensPreview();

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
            document.getElementById('eo-o-phone').value      = order.customer_phone   || '';
            document.getElementById('eo-o-address').value    = order.customer_address || '';
            document.getElementById('eo-o-order-date').value = order.order_date ? String(order.order_date).substring(0, 10) : '';

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
            // Re-filter lens options against whatever Rx is currently in the Exam
            // tab's fields (even if not saved yet), so a prescription change is
            // immediately reflected in which lenses are selectable.
            if (group === 'lens') phEoFilterLensOptions();
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
            if (mode !== 'catalog' && _phEo.frameStreamStop) _phEo.frameStreamStop(); // leaving the scan panel stops the camera
        });

        // ── Info tooltip icons — tap to toggle (mobile), long-press also works ─
        // Hover is handled purely by CSS; this covers touch devices where
        // there's no hover state.
        (function () {
            var pressTimer = null;
            document.getElementById('ph-eo-editor').addEventListener('click', function (e) {
                var icon = e.target.closest('.ph-eo-info');
                document.querySelectorAll('.ph-eo-info.show').forEach(function (el) {
                    if (el !== icon) el.classList.remove('show');
                });
                if (icon) icon.classList.toggle('show');
            });
            document.getElementById('ph-eo-editor').addEventListener('touchstart', function (e) {
                var icon = e.target.closest('.ph-eo-info');
                if (!icon) return;
                pressTimer = setTimeout(function () { icon.classList.add('show'); }, 450);
            }, { passive: true });
            document.getElementById('ph-eo-editor').addEventListener('touchend', function () {
                clearTimeout(pressTimer);
            });
        }());

        // ── Visual habit / digital usage — single-select toggle group ─────
        document.querySelectorAll('.ph-eo-toggle-group').forEach(function(group) {
            group.addEventListener('click', function(e) {
                var btn = e.target.closest('.ph-eo-toggle-btn');
                if (!btn) return;
                group.querySelectorAll('.ph-eo-toggle-btn').forEach(function(b) { b.classList.remove('active'); });
                btn.classList.add('active');
                var hidden = document.getElementById(group.getAttribute('data-target'));
                if (hidden) hidden.value = btn.getAttribute('data-value');
            });
        });

        // ── Vision need — multi-select icon buttons ────────────────────────
        document.querySelectorAll('.ph-eo-vision-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var chk = document.getElementById(btn.getAttribute('data-target'));
                if (!chk) return;
                chk.checked = !chk.checked;
                btn.classList.toggle('active', chk.checked);
            });
        });

        // Pressing Enter in the UFC field looks the frame up (same as the
        // "Look Up" button) rather than saving immediately, so the details
        // can be reviewed first.
        document.getElementById('eo-f-ufc').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); eoFbsLookupManual(); }
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
                        if (data.changed !== false) _phEo.hasChanges = true; // some endpoints don't send `changed`; treat as changed
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
            // visual_habit / digital_usage are hidden <input> fields driven by the
            // toggle-group buttons (values 1/2/3) — read .value, not .checked.
            ['visual_habit', 'digital_usage'].forEach(function(f) {
                var el = document.getElementById('eo-e-' + f);
                if (el) fields[f] = el.value;
            });
            // need_distance / need_intermediate / need_near are real checkboxes
            // driven by the vision-need icon buttons — read .checked.
            ['need_distance', 'need_intermediate', 'need_near'].forEach(function(f) {
                var el = document.getElementById('eo-e-' + f);
                if (el) fields[f] = el.checked ? '1' : '0';
            });
            phEoPost('edit_group_exam', fields);
        }

        // ── Group: Prescription (revert / reapply / new) ───────────────
        function phEoPrescriptionSimple(mode) {
            phEoPost('edit_group_prescription', { mode: mode }, function(data) {
                phEoLoadDetails(false); // refresh status text, revert/reapply buttons, and the prefill form
            });
        }

        function phEoPrescriptionNew() {
            var fields = { mode: 'new_modification' };
            ['od_sph','od_cyl','od_axis','od_add','os_sph','os_cyl','os_axis','os_add'].forEach(function(f) {
                var el = document.getElementById('eo-p-' + f);
                if (el) fields[f] = el.value;
            });
            phEoPost('edit_group_prescription', fields, function(data) {
                phEoLoadDetails(false); // refresh status text, revert/reapply buttons, and the prefill form
            });
        }

        // ── Group: Lens ──────────────────────────────────────────────────

        // Build the <optgroup>-grouped lens dropdown once from PH_EO_LENS_OPTIONS
        // (parsed server-side from lense_prices.json).
        var _phEoLensBuilt = false;
        function phEoBuildLensOptions() {
            if (_phEoLensBuilt) return;
            var select = document.getElementById('eo-l-name');
            select.innerHTML = '<option value="">— Select a lens —</option>';

            var groupLabels = { stock: 'STOCK LENS', lab: 'LAB / ORDER LENS' };
            var limitFields = ['sph_from', 'sph_to', 'cyl_from', 'cyl_to', 'comb_max', 'add_from', 'add_to'];
            ['stock', 'lab'].forEach(function(lt) {
                var items = (PH_EO_LENS_OPTIONS && PH_EO_LENS_OPTIONS[lt]) || [];
                if (!items.length) return;
                var optgroup = document.createElement('optgroup');
                optgroup.label = groupLabels[lt];
                items.forEach(function(item) {
                    var opt = document.createElement('option');
                    opt.value = item.label;
                    opt.textContent = item.label;
                    opt.setAttribute('data-cost', item.cost);
                    opt.setAttribute('data-selling', item.selling);
                    opt.setAttribute('data-category', item.category);
                    opt.setAttribute('data-source', item.source);
                    limitFields.forEach(function(f) { opt.setAttribute('data-' + f, item[f] || 0); });
                    optgroup.appendChild(opt);
                });
                select.appendChild(optgroup);
            });
            _phEoLensBuilt = true;
        }

        // Same Rx-fit gate as invoice.php's lens recommendation card (lr_rxFits),
        // reimplemented client-side so it can react instantly to unsaved Exam-tab edits.
        function phEoRxFits(lim, rSph, rCyl, lSph, lCyl, rAdd, lAdd) {
            var maxC   = Math.max(Math.abs(rCyl), Math.abs(lCyl));
            var maxAdd = Math.max(Math.abs(rAdd), Math.abs(lAdd));

            // Sphere range (0/0 = no restriction)
            if (lim.sph_from !== 0 || lim.sph_to !== 0) {
                var sphMin = Math.min(lim.sph_from, lim.sph_to) / 100;
                var sphMax = Math.max(lim.sph_from, lim.sph_to) / 100;
                if (rSph < sphMin || rSph > sphMax) return false;
                if (lSph < sphMin || lSph > sphMax) return false;
            }

            // Cylinder (0/0 = plano-only)
            if (lim.cyl_from === 0 && lim.cyl_to === 0) {
                if (maxC > 0) return false;
            } else if (lim.cyl_to !== 0) {
                if (maxC > Math.abs(lim.cyl_to) / 100) return false;
            }

            // Combined SPH+CYL
            if (lim.comb_max !== 0) {
                var maxS = Math.max(Math.abs(rSph), Math.abs(lSph));
                if ((maxS + maxC) > Math.abs(lim.comb_max) / 100) return false;
            }

            // ADD range (progressive/kryptok/flattop)
            if (lim.add_to !== 0) {
                var addMin = Math.abs(lim.add_from) / 100;
                var addMax = Math.abs(lim.add_to)   / 100;
                if (maxAdd < addMin || maxAdd > addMax) return false;
            }
            return true;
        }

        // Grey out (and prevent selecting) any lens option whose Rx-fit range
        // doesn't cover the prescription currently sitting in the Exam tab's
        // "new" fields — matches invoice.php's lens recommendation gate.
        // Category gate: mirrors invoice.php's lr_isPresbyopia / lr_catAllowed.
        // A patient only needs a Progressive-family lens (not plain Single Vision)
        // once they're presbyopic AND actually need more than distance vision.
        function phEoCatAllowed(category, isPresbyopia, farOnlySV) {
            var isSV   = category === 'SINGLE VISION';
            var isProg = ['PROGRESSIVE', 'KRYPTOK', 'FLATTOP'].indexOf(category) !== -1;
            if (farOnlySV) return isSV;
            if (isPresbyopia) return isProg;
            return isSV;
        }

        function phEoFilterLensOptions() {
            phEoBuildLensOptions();
            var num = function(id) { var el = document.getElementById(id); return el ? (parseFloat(el.value) || 0) : 0; };
            var rSph = num('eo-e-new_r_sph'), rCyl = num('eo-e-new_r_cyl'), rAdd = num('eo-e-new_r_add');
            var lSph = num('eo-e-new_l_sph'), lCyl = num('eo-e-new_l_cyl'), lAdd = num('eo-e-new_l_add');

            var age = num('eo-c-age');
            var maxAdd = Math.max(Math.abs(rAdd), Math.abs(lAdd));
            var isPresbyopia = (maxAdd >= 0.75 && age >= 39);
            var needDist  = document.getElementById('eo-e-need_distance')     ? document.getElementById('eo-e-need_distance').checked     : false;
            var needInter = document.getElementById('eo-e-need_intermediate') ? document.getElementById('eo-e-need_intermediate').checked : false;
            var needNear  = document.getElementById('eo-e-need_near')         ? document.getElementById('eo-e-need_near').checked         : false;
            // "far_only" (Single Vision still OK despite presbyopia) only applies
            // when the customer explicitly wants distance ONLY — same rule as
            // invoice.php's lr_presbyDesign() "anySet" branch.
            var farOnlySV = isPresbyopia && needDist && !needInter && !needNear;

            var select = document.getElementById('eo-l-name');
            var hiddenCount = 0;
            Array.prototype.forEach.call(select.options, function(opt) {
                if (!opt.value) return; // keep the placeholder option always visible
                var lim = {
                    sph_from: parseInt(opt.getAttribute('data-sph_from')) || 0,
                    sph_to:   parseInt(opt.getAttribute('data-sph_to'))   || 0,
                    cyl_from: parseInt(opt.getAttribute('data-cyl_from')) || 0,
                    cyl_to:   parseInt(opt.getAttribute('data-cyl_to'))   || 0,
                    comb_max: parseInt(opt.getAttribute('data-comb_max')) || 0,
                    add_from: parseInt(opt.getAttribute('data-add_from')) || 0,
                    add_to:   parseInt(opt.getAttribute('data-add_to'))   || 0
                };
                var rxOk  = phEoRxFits(lim, rSph, rCyl, lSph, lCyl, rAdd, lAdd);
                var catOk = phEoCatAllowed(opt.getAttribute('data-category'), isPresbyopia, farOnlySV);
                var fits  = rxOk && catOk;
                opt.disabled = !fits;
                opt.textContent = fits ? opt.value : (opt.value + (!rxOk ? ' (Rx out of range)' : ' (wrong design for this Rx)'));
                if (!fits) hiddenCount++;
            });

            var note = document.getElementById('eo-l-filter-note');
            if (note) {
                note.textContent = 'Filtered by the prescription and vision-need in the Exam Results tab (age ' + age + ', ' +
                    (isPresbyopia ? (farOnlySV ? 'presbyopic, distance only' : 'presbyopic, needs progressive design') : 'not presbyopic') + ')' +
                    (hiddenCount > 0 ? ' — ' + hiddenCount + ' lens type(s) hidden.' : '.');
            }
        }

        function phEoUpdateLensPreview() {
            var select  = document.getElementById('eo-l-name');
            var preview = document.getElementById('eo-l-preview');
            var opt = select.options[select.selectedIndex];
            if (!opt || !opt.value) { preview.textContent = ''; return; }
            var selling = opt.getAttribute('data-selling');
            preview.textContent = (selling !== null && selling !== '')
                ? 'Selling price: Rp ' + Number(selling).toLocaleString('id-ID') + ' (this will adjust the order total by the price difference)'
                : '';
        }
        document.getElementById('eo-l-name').addEventListener('change', phEoUpdateLensPreview);

        function phEoSaveLens() {
            var select  = document.getElementById('eo-l-name');
            var newName = select.value;
            if (!newName) { phEoShowMsg('Please select a lens.', true); return; }
            var chosenOpt = select.options[select.selectedIndex];
            if (chosenOpt && chosenOpt.disabled) {
                phEoShowMsg('That lens does not cover the current prescription range. Pick another, or update the Exam Results first.', true);
                return;
            }
            phEoPost('edit_group_lens', { lens_name: newName, lens_source: chosenOpt ? (chosenOpt.getAttribute('data-source') || '') : '' }, function(data) {
                if (!_phEo.card || !data.changed) return;
                _phEo.card.setAttribute('data-lens', (data.lens_name || '').toLowerCase());
                _phEo.card.setAttribute('data-lens-cost', data.lens_cost || 0);
                var items = _phEo.card.querySelectorAll('.cs-detail-item');
                items.forEach(function(item) {
                    var label = item.querySelector('.cs-detail-label');
                    if (!label) return;
                    if (label.textContent.indexOf('Lens') === 0) {
                        var val = item.querySelector('.cs-detail-value');
                        if (val) val.textContent = data.lens_name;
                    }
                    if (label.textContent.indexOf('Due') === 0) {
                        var val2 = item.querySelector('.cs-detail-value');
                        if (val2) val2.textContent = data.due_date || '—';
                    }
                });
                if (data.total_amount !== undefined) {
                    _phEo.card.setAttribute('data-total', data.total_amount);
                    var headerTotal = _phEo.card.querySelector('.ph-header-total');
                    if (headerTotal) headerTotal.textContent = 'Rp ' + Number(data.total_amount).toLocaleString('id-ID');
                }
                if (typeof phUpdateNetProfit === 'function') phUpdateNetProfit(_phEo.card);
            });
        }

        // ── Group: Frame — camera scanner ─────────────────────────────────
        // Same approach as invoice.php's frame scanner: jsQR decodes the
        // camera feed, frame_lookup.php resolves the UFC against
        // frames_main / frame_staging.
        (function () {
            var eoFbsStream   = null;
            var eoFbsRafId    = null;
            var eoFbsLocked   = false;
            var eoFbsCtx      = null;
            var eoFbsLastScan = 0;
            var EO_FBS_THROTTLE = 300;

            var video    = document.getElementById('eo-fbs-video');
            var canvas   = document.getElementById('eo-fbs-canvas');
            var viewfinder = document.getElementById('eo-fbs-viewfinder');
            var camStatus  = document.getElementById('eo-fbs-cam-status');
            var startBtn   = document.getElementById('eo-fbs-start-btn');
            var stopBtn    = document.getElementById('eo-fbs-stop-btn');
            var preview    = document.getElementById('eo-f-ufc-preview');
            var ufcInput   = document.getElementById('eo-f-ufc');

            window.eoFbsStartCamera = function () {
                if (eoFbsStream) return;
                viewfinder.style.display = 'block';
                eoFbsLocked = false;

                navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } } })
                    .then(function (stream) {
                        eoFbsStream = stream;
                        video.srcObject = stream;
                        video.play();
                        video.onloadedmetadata = function () {
                            canvas.width  = video.videoWidth  || 640;
                            canvas.height = video.videoHeight || 480;
                            eoFbsCtx = canvas.getContext('2d');
                            eoFbsLastScan = 0;
                            startBtn.style.display = 'none';
                            stopBtn.style.display  = 'inline-flex';
                            camStatus.textContent = '● SCANNING…';
                            eoFbsRafId = requestAnimationFrame(eoFbsScanLoop);
                        };
                    })
                    .catch(function (err) {
                        viewfinder.style.display = 'none';
                        preview.classList.add('error');
                        preview.textContent = 'Camera access denied. Use the text field to type the UFC manually.';
                        console.error('Edit-order frame scanner camera error:', err);
                    });
            };

            window.eoFbsStopCamera = function () {
                if (eoFbsRafId)  { cancelAnimationFrame(eoFbsRafId); eoFbsRafId = null; }
                if (eoFbsStream) { eoFbsStream.getTracks().forEach(function (t) { t.stop(); }); eoFbsStream = null; }
                video.srcObject = null;
                viewfinder.style.display = 'none';
                startBtn.style.display = 'inline-flex';
                stopBtn.style.display  = 'none';
            };
            _phEo.frameStreamStop = window.eoFbsStopCamera; // so closing the modal / switching tabs can stop the camera

            function eoFbsScanLoop(timestamp) {
                if (!eoFbsStream || eoFbsLocked) return;
                if (timestamp - eoFbsLastScan >= EO_FBS_THROTTLE) {
                    eoFbsLastScan = timestamp;
                    if (video.readyState >= video.HAVE_ENOUGH_DATA) {
                        var W = canvas.width, H = canvas.height;
                        eoFbsCtx.drawImage(video, 0, 0, W, H);
                        var img = eoFbsCtx.getImageData(0, 0, W, H);
                        var decoded = (typeof jsQR === 'function') ? jsQR(img.data, W, H, { inversionAttempts: 'attemptBoth' }) : null;
                        if (decoded && decoded.data && decoded.data.trim() !== '') {
                            eoFbsLocked = true;
                            camStatus.textContent = '✓ CODE DETECTED';
                            window.eoFbsStopCamera();
                            ufcInput.value = decoded.data.trim().toUpperCase();
                            eoFbsLookup(ufcInput.value);
                            return;
                        }
                    }
                }
                eoFbsRafId = requestAnimationFrame(eoFbsScanLoop);
            }

            window.eoFbsLookupManual = function () {
                var val = (ufcInput.value || '').trim();
                if (!val) { preview.classList.add('error'); preview.textContent = 'Please enter or scan a UFC code.'; return; }
                eoFbsLookup(val);
            };

            function eoFbsLookup(ufc) {
                preview.classList.remove('error');
                preview.textContent = 'Looking up ' + ufc + '…';
                var fd = new FormData();
                fd.append('ufc', ufc);
                fetch('frame_lookup.php', { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.found) {
                            var stock = parseInt(data.stock) || 0;
                            var price = parseFloat(data.sell_price) > 0 ? 'Rp ' + parseInt(data.sell_price).toLocaleString('id-ID') : 'contact staff';
                            preview.textContent = '✓ ' + (data.brand || ufc) + ' — stock: ' + stock + ' pcs — ' + price + ' (' + (data.source === 'main' ? 'main DB' : 'staging') + ')';
                            ufcInput.value = data.ufc || ufc;
                        } else {
                            preview.classList.add('error');
                            preview.textContent = '✗ ' + (data.message || 'Frame not found in any database.');
                        }
                    })
                    .catch(function () {
                        preview.classList.add('error');
                        preview.textContent = 'Connection error while looking up the frame.';
                    });
            }
        }());

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

                if (data.total_amount !== undefined) {
                    _phEo.card.setAttribute('data-total', data.total_amount);
                    var headerTotal = _phEo.card.querySelector('.ph-header-total');
                    if (headerTotal) headerTotal.textContent = 'Rp ' + Number(data.total_amount).toLocaleString('id-ID');
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
                order_date:       document.getElementById('eo-o-order-date').value
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
                    if (label.textContent.indexOf('Due') === 0 && data.due_date) {
                        var val3 = item.querySelector('.cs-detail-value');
                        if (val3) val3.textContent = data.due_date;
                    }
                });
                if (data.customer_number) {
                    var custNumChip = _phEo.card.querySelector('.cs-chip.cust');
                    if (custNumChip) custNumChip.textContent = data.customer_number;
                    _phEo.card.setAttribute('data-custnum', data.customer_number.toLowerCase());
                }
            });
        }
    </script>
</body>
</html>