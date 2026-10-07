<?php
// lisani_aos/ajax/investor_api.php
// Endpoint tunggal modul Investor.
//   GET  ?action=list                 -> semua data + hasil perhitungan laporan
//   POST action=<nama> + field        -> simpan / hapus
// Aksi hapus memakai pola (a): password_verify() langsung (lihat delete_customer.php).

ini_set('display_errors', '0');
ob_start();
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function inv_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode($payload);
    exit;
}

function inv_fail(string $message): void
{
    global $db;
    if ($db instanceof mysqli) {
        $db->rollback();
    }
    inv_out(['ok' => false, 'message' => $message]);
}

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    inv_out(['ok' => false, 'message' => 'Session expired. Please log in again.'], 401);
}

require_once __DIR__ . '/../db_config.php'; // -> $lisani_conn (mysqli)
$db  = $lisani_conn;
$uid = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

/** Angka dari input (koma ribuan dibuang). */
function inv_num($v): float
{
    $s = str_replace(',', '', trim((string) $v));
    return is_numeric($s) ? (float) $s : 0.0;
}

/** Tanggal format YYYY-MM-DD yang valid, atau null. */
function inv_date($v): ?string
{
    $v = trim((string) $v);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
        return null;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
}

/** Teks dipaksa uppercase dan dipotong sesuai panjang kolom. */
function inv_text($v, int $max): string
{
    return mb_substr(strtoupper(trim((string) $v)), 0, $max);
}

/** Label activity code: nama + nomor kode dari relative_path (input/{year}/{dept}/{code}/). */
function inv_activity_label(string $name, string $relPath): string
{
    $code = '';
    if (preg_match('#^input/\d{4}/[^/]+/(\d+)/$#', $relPath, $m)) {
        $code = $m[1];
    }
    return $code !== '' ? $name . ' (CODE ' . $code . ')' : $name;
}

/** Ambil satu baris dengan satu parameter integer. Null bila tidak ada. */
function inv_fetch_one(string $sql, int $param): ?array
{
    global $db;
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $param);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function inv_exists(string $sql, int $id): bool
{
    return inv_fetch_one($sql, $id) !== null;
}

/** ICU = total setoran - pengeluaran non-project. */
function inv_icu(int $investorId): float
{
    global $db;
    $stmt = $db->prepare(
        'SELECT (SELECT COALESCE(SUM(final_amount_idr), 0) FROM investor_deposits WHERE investor_id = ?)
              - (SELECT COALESCE(SUM(amount_idr), 0) FROM investor_support_expenses WHERE investor_id = ?) AS icu'
    );
    $stmt->bind_param('ii', $investorId, $investorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return round((float) ($row['icu'] ?? 0), 2);
}

/** Verifikasi password user yang sedang login (pola a). */
function inv_verify_password(): void
{
    global $db, $uid;
    $pw = (string) ($_POST['password'] ?? '');
    if ($pw === '') {
        inv_fail('Password is required.');
    }
    $stmt = $db->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || !password_verify($pw, $user['password_hash'])) {
        inv_fail('Incorrect password.');
    }
}

// ---------------------------------------------------------------------
// Perhitungan laporan
// ---------------------------------------------------------------------

function inv_compute(): array
{
    global $db;

    // ---- Activity code: nama, pdp, biaya project, penjualan aktual ----
    $activities = [];
    $res = $db->query('SELECT id, activity_name, relative_path FROM activities ORDER BY id DESC');
    while ($r = $res->fetch_assoc()) {
        $aid = (int) $r['id'];
        $activities[$aid] = [
            'id'    => $aid,
            'label' => inv_activity_label($r['activity_name'], $r['relative_path']),
            'pdp'   => 0.0,
            'cost'  => 0.0,
            'sales' => 0.0,
        ];
    }
    $res->free();

    $res = $db->query('SELECT activity_id, profit_distribution_percent AS v FROM investor_activity_settings');
    while ($r = $res->fetch_assoc()) {
        $aid = (int) $r['activity_id'];
        if (isset($activities[$aid])) {
            $activities[$aid]['pdp'] = (float) $r['v'];
        }
    }
    $res->free();

    // Total cost = pengeluaran project, dikaitkan otomatis lewat transaction_disbursements.activity_id
    // (fitur Category Disbursement). Satu transaksi -> satu activity code (transaction_id UNIQUE di sana).
    $res = $db->query(
        "SELECT td.activity_id, SUM(t.final_amount_idr) AS v
         FROM transactions t
         JOIN transaction_disbursements td ON td.transaction_id = t.id
         WHERE t.category = 'disbursement'
         GROUP BY td.activity_id"
    );
    while ($r = $res->fetch_assoc()) {
        $aid = (int) $r['activity_id'];
        if (isset($activities[$aid])) {
            $activities[$aid]['cost'] = (float) $r['v'];
        }
    }
    $res->free();

    // Penjualan aktual = out - in - price_adjustment (sesuai aturan §5.3 catatan proyek).
    // ASUMSI: total_price pada 'in' dan 'price_adjustment' bernilai positif.
    $res = $db->query(
        "SELECT l.activity_id, SUM(CASE m.movement_type
                WHEN 'out' THEN COALESCE(m.total_price, 0)
                WHEN 'in' THEN -COALESCE(m.total_price, 0)
                WHEN 'price_adjustment' THEN -COALESCE(m.total_price, 0)
                ELSE 0 END) AS v
         FROM logistic_movements m
         JOIN logistics l ON l.id = m.logistic_id
         GROUP BY l.activity_id"
    );
    while ($r = $res->fetch_assoc()) {
        $aid = (int) $r['activity_id'];
        if (isset($activities[$aid])) {
            $activities[$aid]['sales'] = (float) $r['v'];
        }
    }
    $res->free();

    // ---- Investor: setoran, pengeluaran non-project, pembayaran profit ----
    $investors = [];
    $res = $db->query('SELECT id, investor_name FROM investors ORDER BY investor_name');
    while ($r = $res->fetch_assoc()) {
        $iid = (int) $r['id'];
        $investors[$iid] = [
            'id'       => $iid,
            'name'     => $r['investor_name'],
            'deposits' => 0.0,
            'support'  => 0.0,
            'payments' => 0.0,
            'icu'      => 0.0,
            'related'  => 0.0,
            'tp'       => 0.0,
        ];
    }
    $res->free();

    $sums = [
        'deposits' => 'SELECT investor_id, SUM(final_amount_idr) AS v FROM investor_deposits GROUP BY investor_id',
        'support'  => 'SELECT investor_id, SUM(amount_idr) AS v FROM investor_support_expenses GROUP BY investor_id',
        'payments' => 'SELECT investor_id, SUM(amount_idr) AS v FROM investor_profit_payments GROUP BY investor_id',
    ];
    foreach ($sums as $key => $sql) {
        $res = $db->query($sql);
        while ($r = $res->fetch_assoc()) {
            $iid = (int) $r['investor_id'];
            if (isset($investors[$iid])) {
                $investors[$iid][$key] = (float) $r['v'];
            }
        }
        $res->free();
    }
    foreach ($investors as $iid => $inv) {
        $investors[$iid]['icu'] = round($inv['deposits'] - $inv['support'], 2);
    }

    // ---- Alokasi: kontribusi = pct% x ICU investor, per activity ----
    $allocRows = [];
    $res = $db->query('SELECT id, investor_id, activity_id, allocation_percent FROM investor_activity_allocations ORDER BY id');
    while ($r = $res->fetch_assoc()) {
        $allocRows[] = $r;
    }
    $res->free();

    $contrib = []; // [activity_id][investor_id] => float
    $allocOut = [];
    foreach ($allocRows as $r) {
        $iid = (int) $r['investor_id'];
        $aid = (int) $r['activity_id'];
        $pct = (float) $r['allocation_percent'];
        $allocOut[] = [
            'id'             => (int) $r['id'],
            'investor_id'    => $iid,
            'activity_id'    => $aid,
            'activity_label' => $activities[$aid]['label'] ?? '-',
            'percent'        => round($pct, 2),
        ];
        if (!isset($investors[$iid]) || !isset($activities[$aid])) {
            continue;
        }
        $icu = max(0.0, $investors[$iid]['icu']);
        $contrib[$aid][$iid] = ($contrib[$aid][$iid] ?? 0.0) + $icu * $pct / 100;
    }

    // ---- Per activity: dana terpakai, perusahaan, gross, zakat, net, distribusi ----
    $activityOut = [];
    foreach ($activities as $aid => $act) {
        $parts = $contrib[$aid] ?? [];
        $raw   = array_sum($parts);
        $cost  = round($act['cost'], 2);
        $used  = min($raw, $cost);
        $scale = $raw > 0 ? $used / $raw : 0.0;
        $company = max(0.0, $cost - $used);

        $gross = round($act['sales'] - $cost, 2);
        $zakat = $gross > 0 ? round($gross * 0.025, 2) : 0.0;
        $net   = round($gross - $zakat, 2);
        $distribution = round($net * $act['pdp'] / 100, 2);

        $investorRows = [];
        foreach ($parts as $iid => $c) {
            $usedI  = round($c * $scale, 2);
            $ratio  = $used > 0 ? $usedI / $used : 0.0;
            $profit = round($distribution * $ratio, 2);

            $investorRows[] = [
                'investor_id'   => $iid,
                'investor_name' => $investors[$iid]['name'],
                'used'          => $usedI,
                'ratio'         => round($ratio * 100, 2),
                'profit'        => $profit,
            ];
            $investors[$iid]['related'] += $usedI;
            $investors[$iid]['tp']      += $profit;
        }

        $activityOut[] = [
            'id'            => $aid,
            'label'         => $act['label'],
            'pdp'           => round($act['pdp'], 2),
            'cost'          => $cost,
            'investor_used' => round($used, 2),
            'company'       => round($company, 2),
            'sales'         => round($act['sales'], 2),
            'gross'         => $gross,
            'zakat'         => $zakat,
            'net'           => $net,
            'distribution'  => $distribution,
            'investors'     => $investorRows,
            'expenses'      => $expensesByActivity[$aid] ?? [],
        ];
    }

    // ---- Per investor: TP, PP, ICU, rolled capital ----
    $investorOut = [];
    foreach ($investors as $inv) {
        $tp = round($inv['tp'], 2);
        $pp = round($inv['payments'], 2);
        $investorOut[] = [
            'id'       => $inv['id'],
            'name'     => $inv['name'],
            'deposits' => round($inv['deposits'], 2),
            'support'  => round($inv['support'], 2),
            'icu'      => $inv['icu'],
            'related'  => round($inv['related'], 2),
            'tp'       => $tp,
            'pp'       => $pp,
            'rolled'   => round(($tp - $pp) + $inv['icu'], 2),
        ];
    }

    // ---- Daftar mentah untuk tampilan ----
    $deposits = [];
    $res = $db->query('SELECT id, investor_id, deposit_date, currency, amount, exchange_rate, final_amount_idr, notes
                       FROM investor_deposits ORDER BY deposit_date, id');
    while ($r = $res->fetch_assoc()) {
        $deposits[] = [
            'id'          => (int) $r['id'],
            'investor_id' => (int) $r['investor_id'],
            'date'        => $r['deposit_date'],
            'currency'    => $r['currency'],
            'amount'      => (float) $r['amount'],
            'rate'        => $r['exchange_rate'] === null ? null : (float) $r['exchange_rate'],
            'final'       => (float) $r['final_amount_idr'],
            'notes'       => $r['notes'],
        ];
    }
    $res->free();

    $support = [];
    $res = $db->query('SELECT id, investor_id, expense_date, category, amount_idr, notes
                       FROM investor_support_expenses ORDER BY expense_date, id');
    while ($r = $res->fetch_assoc()) {
        $support[] = [
            'id'          => (int) $r['id'],
            'investor_id' => (int) $r['investor_id'],
            'date'        => $r['expense_date'],
            'category'    => $r['category'],
            'amount'      => (float) $r['amount_idr'],
            'notes'       => $r['notes'],
        ];
    }
    $res->free();

    $payments = [];
    $res = $db->query('SELECT p.id, p.investor_id, p.payment_date, p.amount_idr, p.notes
                       FROM investor_profit_payments p ORDER BY p.payment_date, p.id');
    while ($r = $res->fetch_assoc()) {
        $payments[] = [
            'id'          => (int) $r['id'],
            'investor_id' => (int) $r['investor_id'],
            'date'        => $r['payment_date'],
            'amount'      => (float) $r['amount_idr'],
            'notes'       => $r['notes'],
        ];
    }
    $res->free();

    // Pengeluaran project per activity (read-only), dikaitkan otomatis lewat
    // transaction_disbursements.activity_id (fitur Category Disbursement).
    $expensesByActivity = [];
    $res = $db->query(
        "SELECT t.id, t.transaction_date, t.notes, t.final_amount_idr, td.activity_id
         FROM transactions t
         JOIN transaction_disbursements td ON td.transaction_id = t.id
         WHERE t.category = 'disbursement'
         ORDER BY t.transaction_date DESC, t.id DESC"
    );
    while ($r = $res->fetch_assoc()) {
        $aid = (int) $r['activity_id'];
        $expensesByActivity[$aid][] = [
            'id'    => (int) $r['id'],
            'date'  => $r['transaction_date'],
            'notes' => $r['notes'],
            'final' => round((float) $r['final_amount_idr'], 2),
        ];
    }
    $res->free();

    $activityOptions = [];
    foreach ($activities as $act) {
        $activityOptions[] = ['id' => $act['id'], 'label' => $act['label'], 'pdp' => round($act['pdp'], 2), 'cost' => round($act['cost'], 2)];
    }

    return [
        'investors'         => $investorOut,
        'deposits'          => $deposits,
        'support'           => $support,
        'payments'          => $payments,
        'allocations'       => $allocOut,
        'activities'        => array_values($activityOut),
        'activity_options'  => $activityOptions,
    ];
}

// ---------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------

$action = (string) ($_SERVER['REQUEST_METHOD'] === 'GET' ? ($_GET['action'] ?? '') : ($_POST['action'] ?? ''));

try {
    switch ($action) {

        case 'list':
            inv_out(['ok' => true, 'data' => inv_compute()]);

        // ----- Investor -----
        case 'save_investor':
            $id   = (int) ($_POST['id'] ?? 0);
            $name = inv_text($_POST['investor_name'] ?? '', 150);
            if ($name === '') {
                inv_fail('Investor name is required.');
            }
            $stmt = $db->prepare('SELECT id FROM investors WHERE investor_name = ? AND id <> ? LIMIT 1');
            $stmt->bind_param('si', $name, $id);
            $stmt->execute();
            $dup = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($dup) {
                inv_fail('Investor name already exists.');
            }
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE investors SET investor_name = ? WHERE id = ?');
                $stmt->bind_param('si', $name, $id);
            } else {
                $stmt = $db->prepare('INSERT INTO investors (investor_name, created_by) VALUES (?, ?)');
                $stmt->bind_param('si', $name, $uid);
            }
            $stmt->execute();
            $stmt->close();
            inv_out(['ok' => true]);

        case 'delete_investor':
            inv_verify_password();
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $db->prepare(
                'SELECT (SELECT COUNT(*) FROM investor_deposits WHERE investor_id = ?)
                      + (SELECT COUNT(*) FROM investor_support_expenses WHERE investor_id = ?)
                      + (SELECT COUNT(*) FROM investor_activity_allocations WHERE investor_id = ?)
                      + (SELECT COUNT(*) FROM investor_profit_payments WHERE investor_id = ?) AS n'
            );
            $stmt->bind_param('iiii', $id, $id, $id, $id);
            $stmt->execute();
            $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
            $stmt->close();
            if ($n > 0) {
                inv_fail('Investor still has deposits, expenses, allocations, or payments.');
            }
            $stmt = $db->prepare('DELETE FROM investors WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            inv_out(['ok' => true]);

        // ----- Setoran -----
        case 'save_deposit':
            $id         = (int) ($_POST['id'] ?? 0);
            $investorId = (int) ($_POST['investor_id'] ?? 0);
            $date       = inv_date($_POST['deposit_date'] ?? '');
            $currency   = inv_text($_POST['currency'] ?? 'IDR', 10) ?: 'IDR';
            $amount     = round(inv_num($_POST['amount'] ?? 0), 2);
            $notes      = inv_text($_POST['notes'] ?? '', 500);
            if ($investorId <= 0 || $date === null) {
                inv_fail('Investor and a valid date are required.');
            }
            if (!inv_exists('SELECT id FROM investors WHERE id = ?', $investorId)) {
                inv_fail('Investor not found.');
            }
            if ($amount <= 0) {
                inv_fail('Amount must be greater than 0.');
            }
            if ($currency === 'IDR') {
                $rate  = null;
                $final = $amount;
            } else {
                $rate = round(inv_num($_POST['exchange_rate'] ?? 0), 6);
                if ($rate <= 0) {
                    inv_fail('Exchange rate is required for foreign currency.');
                }
                $final = round($amount * $rate, 2);
            }
            $db->begin_transaction();
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE investor_deposits SET investor_id = ?, deposit_date = ?, currency = ?, amount = ?, exchange_rate = ?, final_amount_idr = ?, notes = ? WHERE id = ?');
                $stmt->bind_param('issdddsi', $investorId, $date, $currency, $amount, $rate, $final, $notes, $id);
            } else {
                $stmt = $db->prepare('INSERT INTO investor_deposits (investor_id, deposit_date, currency, amount, exchange_rate, final_amount_idr, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('issdddsi', $investorId, $date, $currency, $amount, $rate, $final, $notes, $uid);
            }
            $stmt->execute();
            $stmt->close();
            if (inv_icu($investorId) < 0) {
                inv_fail('Non-project expenses would exceed deposits for this investor.');
            }
            $db->commit();
            inv_out(['ok' => true]);

        case 'delete_deposit':
            inv_verify_password();
            $id  = (int) ($_POST['id'] ?? 0);
            $row = inv_fetch_one('SELECT investor_id FROM investor_deposits WHERE id = ?', $id);
            if (!$row) {
                inv_fail('Deposit not found.');
            }
            $investorId = (int) $row['investor_id'];
            $db->begin_transaction();
            $stmt = $db->prepare('DELETE FROM investor_deposits WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            if (inv_icu($investorId) < 0) {
                inv_fail('Cannot delete: non-project expenses would exceed the remaining deposits.');
            }
            $db->commit();
            inv_out(['ok' => true]);

        // ----- Pengeluaran non-project -----
        case 'save_support':
            $id         = (int) ($_POST['id'] ?? 0);
            $investorId = (int) ($_POST['investor_id'] ?? 0);
            $date       = inv_date($_POST['expense_date'] ?? '');
            $category   = (string) ($_POST['category'] ?? '');
            $amount     = round(inv_num($_POST['amount'] ?? 0), 2);
            $notes      = inv_text($_POST['notes'] ?? '', 500);
            if ($investorId <= 0 || $date === null) {
                inv_fail('Investor and a valid date are required.');
            }
            if (!in_array($category, ['return_capital', 'aid', 'other'], true)) {
                inv_fail('Invalid category.');
            }
            if (!inv_exists('SELECT id FROM investors WHERE id = ?', $investorId)) {
                inv_fail('Investor not found.');
            }
            if ($amount <= 0) {
                inv_fail('Amount must be greater than 0.');
            }
            $db->begin_transaction();
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE investor_support_expenses SET investor_id = ?, expense_date = ?, category = ?, amount_idr = ?, notes = ? WHERE id = ?');
                $stmt->bind_param('issdsi', $investorId, $date, $category, $amount, $notes, $id);
            } else {
                $stmt = $db->prepare('INSERT INTO investor_support_expenses (investor_id, expense_date, category, amount_idr, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('issdsi', $investorId, $date, $category, $amount, $notes, $uid);
            }
            $stmt->execute();
            $stmt->close();
            if (inv_icu($investorId) < 0) {
                inv_fail('Non-project expenses would exceed deposits for this investor.');
            }
            $db->commit();
            inv_out(['ok' => true]);

        case 'delete_support':
            inv_verify_password();
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM investor_support_expenses WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            inv_out(['ok' => true]);

        // ----- Alokasi investor ke activity -----
        case 'save_allocation':
            $investorId = (int) ($_POST['investor_id'] ?? 0);
            $activityId = (int) ($_POST['activity_id'] ?? 0);
            $pct        = round(inv_num($_POST['allocation_percent'] ?? 0), 2);
            if (!inv_exists('SELECT id FROM investors WHERE id = ?', $investorId)) {
                inv_fail('Investor not found.');
            }
            if (!inv_exists('SELECT id FROM activities WHERE id = ?', $activityId)) {
                inv_fail('Activity code not found.');
            }
            if ($pct <= 0 || $pct > 100) {
                inv_fail('Allocation must be between 0.01 and 100.');
            }
            $stmt = $db->prepare('SELECT COALESCE(SUM(allocation_percent), 0) AS s FROM investor_activity_allocations WHERE investor_id = ? AND activity_id <> ?');
            $stmt->bind_param('ii', $investorId, $activityId);
            $stmt->execute();
            $others = (float) $stmt->get_result()->fetch_assoc()['s'];
            $stmt->close();
            if ($others + $pct > 100.001) {
                inv_fail('Total allocation for this investor would exceed 100% (already ' . number_format($others, 2) . '%).');
            }
            $stmt = $db->prepare('INSERT INTO investor_activity_allocations (investor_id, activity_id, allocation_percent, created_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE allocation_percent = VALUES(allocation_percent)');
            $stmt->bind_param('iidi', $investorId, $activityId, $pct, $uid);
            $stmt->execute();
            $stmt->close();
            inv_out(['ok' => true]);

        case 'delete_allocation':
            inv_verify_password();
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM investor_activity_allocations WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            inv_out(['ok' => true]);

        // ----- Pengaturan pdp per activity -----
        case 'save_pdp':
            $activityId = (int) ($_POST['activity_id'] ?? 0);
            $pdp        = round(inv_num($_POST['profit_distribution_percent'] ?? 0), 2);
            if (!inv_exists('SELECT id FROM activities WHERE id = ?', $activityId)) {
                inv_fail('Activity code not found.');
            }
            if ($pdp < 0 || $pdp > 100) {
                inv_fail('Profit distribution must be between 0 and 100.');
            }
            $stmt = $db->prepare('INSERT INTO investor_activity_settings (activity_id, profit_distribution_percent, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE profit_distribution_percent = VALUES(profit_distribution_percent), updated_by = VALUES(updated_by)');
            $stmt->bind_param('idi', $activityId, $pdp, $uid);
            $stmt->execute();
            $stmt->close();
            inv_out(['ok' => true]);

        // ----- Pembayaran profit -----
        case 'save_profit_payment':
            $investorId = (int) ($_POST['investor_id'] ?? 0);
            $date       = inv_date($_POST['payment_date'] ?? '');
            $amount     = round(inv_num($_POST['amount'] ?? 0), 2);
            $notes      = inv_text($_POST['notes'] ?? '', 500);
            if ($investorId <= 0 || $date === null) {
                inv_fail('Investor and a valid date are required.');
            }
            if (!inv_exists('SELECT id FROM investors WHERE id = ?', $investorId)) {
                inv_fail('Investor not found.');
            }
            if ($amount <= 0) {
                inv_fail('Amount must be greater than 0.');
            }
            $stmt = $db->prepare('INSERT INTO investor_profit_payments (investor_id, payment_date, amount_idr, notes, created_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('isdsi', $investorId, $date, $amount, $notes, $uid);
            $stmt->execute();
            $stmt->close();
            inv_out(['ok' => true]);

        case 'delete_profit_payment':
            inv_verify_password();
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM investor_profit_payments WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            inv_out(['ok' => true]);

        default:
            inv_fail('Unknown action.');
    }
} catch (Throwable $e) {
    error_log('investor_api [' . $action . ']: ' . $e->getMessage());
    if ($db instanceof mysqli) {
        $db->rollback();
    }
    inv_out(['ok' => false, 'message' => 'Database error. Please try again.'], 500);
}