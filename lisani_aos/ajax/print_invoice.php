<?php
// lisani_aos/ajax/print_invoice.php
// Printable A4 invoice (Indonesian) for ONE invoice. Opens in a new tab from
// Sales Transaction > Customers > "Print" on an invoice; the browser's
// Print -> "Save as PDF" turns it into the PDF. No PDF library needed.
// Read-only.
//
// GET: invoice_id, banks[] (ids from json_file/bank_accounts.json to show on the
// invoice; picked in the fly window in transaction_content.php before this
// page opens. Missing/empty = no bank section).
// The toolbar is UI (English); the paper itself is Indonesian.

ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function pi_h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function pi_page_error(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Invoice</title>'
        . '<body style="font-family:Arial,sans-serif;padding:24px">' . pi_h($message) . '</body>';
    exit;
}

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    pi_page_error(401, 'Session expired. Please log in again.');
}

require_once dirname(__DIR__) . '/db_config.php'; // provides $lisani_conn (mysqli)

$invoiceId = (int) ($_GET['invoice_id'] ?? 0);
if ($invoiceId <= 0) {
    pi_page_error(400, 'Invoice is missing.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ---------- Signer (change here if the signatory changes) ----------
const PI_SIGNER_NAME = 'SYIS BIN SAMSUL BAHRI';
const PI_SIGNER_ROLE = 'Director';

// ---------- Helpers ----------
function pi_months(): array
{
    return [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
            'Agustus', 'September', 'Oktober', 'November', 'Desember'];
}

function pi_date_id($s): string
{
    $s = substr((string) $s, 0, 10);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return '-';
    }
    $mo = (int) $m[2];
    $months = pi_months();
    if ($mo < 1 || $mo > 12) {
        return '-';
    }
    return (int) $m[3] . ' ' . $months[$mo] . ' ' . $m[1];
}

function pi_money($v): string
{
    $f = abs((float) $v);
    $dec = abs($f - round($f)) < 0.005 ? 0 : 2;
    return "Rp\u{00A0}" . number_format($f, $dec, ',', '.');   // non-breaking space: "Rp 1.000" never wraps
}

// Negative amounts are written in parentheses: (Rp 1.000)
function pi_money_signed($v, ?bool $negative = null): string
{
    $neg = $negative ?? ((float) $v < 0);
    return $neg ? '(' . pi_money($v) . ')' : pi_money($v);
}

// Price adjustment movement: `price` = the NEW unit price, `total_price` = the
// discount value = qty x (old price - new price). Old price = price of the pickup
// it points to (source_movement_id). The per-unit DIFFERENCE is derived from
// total_price / qty, so the invoice never shows the new price as if it were the discount.
function pi_price_change_note($oldPrice, $newPrice, $diff): string
{
    if ($oldPrice === null || $oldPrice === '' || $newPrice === null || $newPrice === '') {
        return 'Selisih harga ' . pi_money($diff);
    }
    $word = (float) $newPrice < (float) $oldPrice ? 'Harga turun ' : 'Harga berubah ';
    return $word . pi_money($oldPrice) . ' → ' . pi_money($newPrice);
}

function pi_qty($v): string
{
    $s = number_format((float) $v, 2, ',', '.');          // e.g. "1.000,00"
    return rtrim(rtrim($s, '0'), ',');                    // -> "1.000"
}

// A unit label that really means "there is no such unit" (e.g. "NO PRIMARY CARTON",
// "NONE", "-", "N/A", "TIDAK ADA") must never be printed.
function pi_unit_is_none(string $label): bool
{
    $l = trim($label);
    if ($l === '') {
        return true;
    }
    return (bool) preg_match('/^(no|none|nil|n\/a|na|tidak ada|tanpa|-+)(\s|$)/i', $l);
}

function pi_terbilang(int $n): string
{
    $s = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($n < 12) {
        return $s[$n];
    }
    if ($n < 20) {
        return pi_terbilang($n - 10) . ' belas';
    }
    if ($n < 100) {
        return pi_terbilang(intdiv($n, 10)) . ' puluh' . ($n % 10 ? ' ' . pi_terbilang($n % 10) : '');
    }
    if ($n < 200) {
        return 'seratus' . ($n > 100 ? ' ' . pi_terbilang($n - 100) : '');
    }
    if ($n < 1000) {
        return pi_terbilang(intdiv($n, 100)) . ' ratus' . ($n % 100 ? ' ' . pi_terbilang($n % 100) : '');
    }
    if ($n < 2000) {
        return 'seribu' . ($n > 1000 ? ' ' . pi_terbilang($n - 1000) : '');
    }
    if ($n < 1000000) {
        return pi_terbilang(intdiv($n, 1000)) . ' ribu' . ($n % 1000 ? ' ' . pi_terbilang($n % 1000) : '');
    }
    if ($n < 1000000000) {
        return pi_terbilang(intdiv($n, 1000000)) . ' juta' . ($n % 1000000 ? ' ' . pi_terbilang($n % 1000000) : '');
    }
    if ($n < 1000000000000) {
        return pi_terbilang(intdiv($n, 1000000000)) . ' miliar' . ($n % 1000000000 ? ' ' . pi_terbilang($n % 1000000000) : '');
    }
    return pi_terbilang(intdiv($n, 1000000000000)) . ' triliun' . ($n % 1000000000000 ? ' ' . pi_terbilang($n % 1000000000000) : '');
}

function pi_column_exists(mysqli $conn, string $table, string $column): bool
{
    $t = str_replace('`', '', $table);
    $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '" . $conn->real_escape_string($column) . "'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function pi_table_exists(mysqli $conn, string $table): bool
{
    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

// ---------- Load data ----------
try {
    $st = $lisani_conn->prepare(
        'SELECT i.id, i.invoice_number, i.status, i.total_amount, i.paid_amount, i.created_at,
                i.period_month, i.period_year, i.customer_id, c.customer_name, c.phone_number
         FROM invoices i
         JOIN customers c ON c.id = i.customer_id
         WHERE i.id = ?'
    );
    $st->bind_param('i', $invoiceId);
    $st->execute();
    $inv = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$inv) {
        pi_page_error(404, 'Invoice was not found.');
    }

    $st = $lisani_conn->prepare(
        'SELECT m.id, m.logistic_id, m.movement_type, m.stock_source, m.movement_date, m.driver_name, m.police_number,
                m.qty_primary_package, m.price, m.total_price,
                sm.movement_date AS source_date, sm.price AS source_price,
                l.product_name, l.primary_unit_label AS unit_label
         FROM logistic_movements m
         JOIN logistics l ON l.id = m.logistic_id
         LEFT JOIN logistic_movements sm ON sm.id = m.source_movement_id
         WHERE m.invoice_id = ?
         ORDER BY m.movement_date ASC, m.id ASC'
    );
    $st->bind_param('i', $invoiceId);
    $st->execute();
    $movements = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $movements[] = $row;
    }
    $st->close();

    // Payments received on this invoice. Same guard pattern as
    // list_customer_orders.php: works before and after the bank-fields migration.
    $payments = [];
    if (pi_table_exists($lisani_conn, 'invoice_payments')) {
        $hasBank = pi_column_exists($lisani_conn, 'invoice_payments', 'destination_bank');
        $st = $lisani_conn->prepare(
            $hasBank
            ? 'SELECT payment_date, amount, destination_bank AS bank, destination_account_name AS holder
               FROM invoice_payments WHERE invoice_id = ? ORDER BY payment_date ASC, id ASC'
            : "SELECT payment_date, amount, payment_method AS bank, '' AS holder
               FROM invoice_payments WHERE invoice_id = ? ORDER BY payment_date ASC, id ASC"
        );
        $st->bind_param('i', $invoiceId);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $payments[] = $row;
        }
        $st->close();
    }
} catch (Throwable $e) {
    error_log('print_invoice.php: ' . $e->getMessage());
    pi_page_error(500, 'Failed to load the invoice.');
}

// ---------- Product units (primary / secondary) for the "Satuan Produk" table ----------
// Separate try/catch + column checks: if anything differs in the logistics table
// the invoice still prints, just without this table.
$units = [];
try {
    $unitIds = [];
    foreach ($movements as $m) {
        $unitIds[(int) $m['logistic_id']] = true;
    }
    if ($unitIds) {
        $want = ['primary_unit_label', 'primary_unit_weight_kg', 'secondary_unit_label',
                 'secondary_unit_weight_kg', 'secondary_ratio_per_primary'];
        $cols = ['id', 'product_name'];
        foreach ($want as $c) {
            if (pi_column_exists($lisani_conn, 'logistics', $c)) {
                $cols[] = $c;
            }
        }
        $sqlCols = implode(', ', array_map(function ($c) {
            return '`' . $c . '`';
        }, $cols));
        $res = $lisani_conn->query(
            'SELECT ' . $sqlCols . ' FROM logistics WHERE id IN (' . implode(',', array_keys($unitIds)) . ') ORDER BY product_name ASC'
        );
        while ($row = $res->fetch_assoc()) {
            $units[] = $row;
        }
    }
} catch (Throwable $e) {
    error_log('print_invoice.php units: ' . $e->getMessage());
    $units = [];
}

// ---------- Bank accounts (json_file/bank_accounts.json) ----------
$banks = [];
$bankPath = dirname(__DIR__) . '/json_file/bank_accounts.json';
if (is_file($bankPath)) {
    $raw = @file_get_contents($bankPath);
    $arr = json_decode($raw === false ? '' : $raw, true);
    if (is_array($arr)) {
        foreach ($arr as $a) {
            if (!is_array($a)) {
                continue;
            }
            $bn = trim((string) ($a['bank_name'] ?? ''));
            $no = trim((string) ($a['account_number'] ?? ''));
            if ($bn === '' || $no === '') {
                continue;
            }
            $banks[] = [
                'id'             => (string) ($a['id'] ?? ($bn . '|' . $no)),
                'bank_name'      => $bn,
                'account_number' => $no,
                'account_name'   => trim((string) ($a['account_name'] ?? '')),
                'currency'       => trim((string) ($a['currency'] ?? '')),
                'swift_code'     => trim((string) ($a['swift_code'] ?? '')),
                'address'        => trim((string) ($a['address'] ?? '')),
            ];
        }
    }
}

// Keep only the accounts picked in the fly window (JSON order is preserved).
$pickedIds = [];
if (isset($_GET['banks']) && is_array($_GET['banks'])) {
    foreach ($_GET['banks'] as $bid) {
        if (is_string($bid) && $bid !== '') {
            $pickedIds[$bid] = true;
        }
    }
}
$banks = array_values(array_filter($banks, function ($b) use ($pickedIds) {
    return isset($pickedIds[$b['id']]);
}));

// ---------- Prepare display rows ----------
$rows = [];
$outSum = 0.0;
$inSum = 0.0;
$adjSum = 0.0;
foreach ($movements as $m) {
    $type = $m['movement_type'];
    $total = (float) $m['total_price'];
    $tag = null;       // [label, css class]
    $notes = [];
    $unitPrice = $m['price'];   // shown in "Harga Satuan"
    $newPrice = null;           // adjustments only: the new unit price

    if ($type === 'out') {
        $outSum += $total;
        if ($m['stock_source'] === 'defective') {
            $tag = ['LOW GRADE', 'tag-def'];
        }
        if (trim((string) $m['driver_name']) !== '') {
            $notes[] = 'Sopir: ' . $m['driver_name'];
        }
        if (trim((string) $m['police_number']) !== '') {
            $notes[] = 'No. Polisi: ' . $m['police_number'];
        }
    } elseif ($type === 'in') {
        $inSum += $total;
        $tag = ['RETURN', 'tag-ret'];
        if ($m['stock_source'] === 'defective') {
            $notes[] = 'Kondisi: low grade';
        } elseif ($m['stock_source'] === 'normal') {
            $notes[] = 'Kondisi: baik';
        }
        if (!empty($m['source_date'])) {
            $notes[] = 'dari pengambilan ' . pi_date_id($m['source_date']);
        }
    } else { // price_adjustment
        $adjSum += $total;
        $tag = ['POTONGAN HARGA', 'tag-adj'];
        $adjQty = (float) $m['qty_primary_package'];
        $newPrice = $m['price'];
        if ($adjQty > 0.0) {
            $unitPrice = round($total / $adjQty, 2);                 // difference per unit
        } elseif ($m['source_price'] !== null) {
            $unitPrice = (float) $m['source_price'] - (float) $m['price'];
        }
        $notes[] = pi_price_change_note($m['source_price'], $newPrice, $unitPrice);
        if (!empty($m['source_date'])) {
            $notes[] = 'dari pengambilan ' . pi_date_id($m['source_date']);
        }
    }

    $rows[] = [
        'type'         => $type,
        'logistic_id'  => (int) $m['logistic_id'],
        'stock_source' => (string) $m['stock_source'],
        'date'   => $m['movement_date'],
        'name'   => $m['product_name'],
        'tag'    => $tag,
        'notes'  => $notes,
        'qty'    => $m['qty_primary_package'],
        'unit'   => (string) $m['unit_label'],
        'price'  => $unitPrice,
        'old_price' => $type === 'price_adjustment' ? $m['source_price'] : null,
        'new_price' => $newPrice,
        'total'  => $total,
        'deduct' => $type !== 'out',
    ];
}

// Main table: ONE row per product + unit price. Normal pickups, low grade
// pickups and returns that share the same product and price are collected into
// the same row (returns subtract); a different price = a separate row. Price
// adjustments (their "price" is the discount per unit) are collected per
// product + discount value in their own rows. Date = first..last movement.
$groups = [];
foreach ($rows as $r) {
    $priceKey = $r['price'] === null ? 'null' : number_format((float) $r['price'], 2, '.', '');
    $isAdj = $r['type'] === 'price_adjustment';
    $oldKey = $r['old_price'] === null ? 'null' : number_format((float) $r['old_price'], 2, '.', '');
    $key = ($isAdj ? 'adj' : 'item') . '|' . $r['logistic_id'] . '|' . $priceKey . ($isAdj ? '|' . $oldKey : '');
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'adj' => $isAdj, 'name' => $r['name'], 'unit' => $r['unit'], 'price' => $r['price'], 'old_price' => $r['old_price'], 'new_price' => $r['new_price'],
            'qty' => 0.0, 'total' => 0.0, 'first' => $r['date'], 'last' => $r['date'],
            'out_n' => 0, 'out_qty' => 0.0, 'low_qty' => 0.0,
            'in_n' => 0, 'in_qty' => 0.0, 'in_low_qty' => 0.0, 'adj_n' => 0,
        ];
    }
    $g = &$groups[$key];
    $q = (float) $r['qty'];
    if ($r['type'] === 'out') {
        $g['qty'] += $q;
        $g['total'] += $r['total'];
        $g['out_n']++;
        $g['out_qty'] += $q;
        if ($r['stock_source'] === 'defective') {
            $g['low_qty'] += $q;
        }
    } elseif ($r['type'] === 'in') {
        $g['qty'] -= $q;
        $g['total'] -= $r['total'];
        $g['in_n']++;
        $g['in_qty'] += $q;
        if ($r['stock_source'] === 'defective') {
            $g['in_low_qty'] += $q;
        }
    } else {
        $g['qty'] += $q;
        $g['total'] -= $r['total'];
        $g['adj_n']++;
    }
    if ($r['date'] < $g['first']) {
        $g['first'] = $r['date'];
    }
    if ($r['date'] > $g['last']) {
        $g['last'] = $r['date'];
    }
    unset($g);
}
$groups = array_values($groups);
foreach ($groups as &$g) {
    $g['tag'] = null;
    $g['notes'] = [];
    $g['deduct'] = $g['total'] < 0;
    $g['show_qty'] = abs($g['qty']);
    $g['show_total'] = abs($g['total']);
    if ($g['adj']) {
        $g['tag'] = ['POTONGAN HARGA', 'tag-adj'];
        $g['notes'][] = pi_price_change_note($g['old_price'], $g['new_price'], $g['price']);
        if ($g['adj_n'] > 1) {
            $g['notes'][] = $g['adj_n'] . 'x';
        }
        continue;
    }
    if ($g['out_n'] === 0) {                      // returns only
        $g['tag'] = ['RETURN', 'tag-ret'];
        if (abs($g['in_low_qty'] - $g['in_qty']) < 0.005) {
            $g['notes'][] = 'Low grade';
        } elseif ($g['in_low_qty'] < 0.005) {
            $g['notes'][] = 'Kondisi baik';
        }
        if ($g['in_n'] > 1) {
            $g['notes'][] = $g['in_n'] . 'x';
        }
        continue;
    }
    if ($g['in_n'] === 0 && $g['low_qty'] > 0.004 && abs($g['low_qty'] - $g['out_qty']) < 0.005) {
        $g['tag'] = ['LOW GRADE', 'tag-def'];     // every pickup in this row is low grade
    }
    if ($g['out_n'] > 1) {
        $g['notes'][] = 'Diambil ' . $g['out_n'] . 'x';
    }
    if ($g['in_n'] > 0) {
        $g['notes'][] = 'Return ' . pi_qty($g['in_qty']);
    }
    if ($g['low_qty'] > 0.004 && $g['low_qty'] < $g['out_qty'] - 0.004) {
        $g['notes'][] = 'Low grade ' . pi_qty($g['low_qty']);
    }
}
unset($g);
usort($groups, function ($a, $b) {
    $c = ((int) $a['adj']) <=> ((int) $b['adj']);   // product rows first, then price adjustments
    if ($c !== 0) {
        return $c;
    }
    $c = strcmp((string) $a['name'], (string) $b['name']);
    if ($c !== 0) {
        return $c;
    }
    $c = strcmp((string) $a['first'], (string) $b['first']);
    if ($c !== 0) {
        return $c;
    }
    return ((float) $a['price']) <=> ((float) $b['price']);
});

// Attachment (Lampiran): every movement, grouped by date (rows are already date-sorted).
$byDate = [];
$netSum = 0.0;
foreach ($rows as $r) {
    $byDate[substr((string) $r['date'], 0, 10)][] = $r;
    $netSum += $r['deduct'] ? -$r['total'] : $r['total'];
}

$totalAmount = (float) $inv['total_amount'];
$paidAmount  = (float) $inv['paid_amount'];
$remaining   = round($totalAmount - $paidAmount, 2);
$isPaid      = $inv['status'] === 'paid';
$months      = pi_months();
$periodLabel = '-';
$pm = (int) $inv['period_month'];
if ($pm >= 1 && $pm <= 12) {
    $periodLabel = $months[$pm] . ' ' . (int) $inv['period_year'];
}
$terbilangText = '';
if ($totalAmount > 0.004) {
    $terbilangText = ucfirst(trim(pi_terbilang((int) round($totalAmount)))) . ' rupiah';
}

  // Drop unit labels that mean "none" (e.g. NO PRIMARY CARTON) and hide the
  // secondary column entirely when no product has a real secondary unit.
  $unitRows = [];
  $anySecondary = false;
  foreach ($units as $u) {
      $pLabel = trim((string) ($u['primary_unit_label'] ?? ''));
      $sLabel = trim((string) ($u['secondary_unit_label'] ?? ''));
      if (pi_unit_is_none($pLabel)) {
          $pLabel = '';
      }
      if (pi_unit_is_none($sLabel)) {
          $sLabel = '';
      }
      $pW = (float) ($u['primary_unit_weight_kg'] ?? 0);
      $sW = (float) ($u['secondary_unit_weight_kg'] ?? 0);
      $ratio = (float) ($u['secondary_ratio_per_primary'] ?? 0);
      $sSub = [];
      if ($sLabel !== '' && $pLabel !== '' && $ratio > 0) {
          $sSub[] = '1 ' . $pLabel . ' = ' . pi_qty($ratio) . ' ' . $sLabel;
      }
      if ($sLabel !== '' && $sW > 0) {
          $sSub[] = '@ ' . pi_qty($sW) . ' kg';
      }
      if ($sLabel !== '') {
          $anySecondary = true;
      }
      $unitRows[] = ['name' => $u['product_name'], 'p' => $pLabel, 'pw' => ($pLabel !== '' ? $pW : 0), 's' => $sLabel, 'ssub' => $sSub];
  }

// ---------- PDF download (FPDF) ----------
// ?format=pdf -> same data as the HTML page, rendered by _invoice_pdf.php.
if (($_GET['format'] ?? '') === 'pdf') {
    $pdfBase = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', 'Invoice ' . $inv['invoice_number']), '-');
    try {
        require_once __DIR__ . '/_invoice_pdf.php';
        $pdfBytes = pi_pdf_render([
            'inv' => $inv, 'groups' => $groups, 'rows' => $rows, 'withAttachment' => (($_GET['attach'] ?? '') === '1'), 'byDate' => $byDate, 'netSum' => $netSum,
            'outSum' => $outSum, 'inSum' => $inSum, 'adjSum' => $adjSum, 'payments' => $payments, 'banks' => $banks,
            'unitRows' => $unitRows, 'anySecondary' => $anySecondary, 'totalAmount' => $totalAmount,
            'paidAmount' => $paidAmount, 'remaining' => $remaining, 'isPaid' => $isPaid,
            'periodLabel' => $periodLabel, 'terbilangText' => $terbilangText,
            'assets' => dirname(__DIR__) . '/assets/img',
        ]);
    } catch (Throwable $e) {
        error_log('print_invoice.php pdf: ' . $e->getMessage());
        pi_page_error(500, 'Failed to generate the PDF.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdfBase . '.pdf"');
    header('Content-Length: ' . strlen($pdfBytes));
    header('Cache-Control: private, no-store');
    echo $pdfBytes;
    exit;
}

// ---------- WhatsApp hand-off ----------
// Customer phone is stored as "+628..." -> wa.me wants digits only (country code first).
$waDigits = preg_replace('/\D+/', '', (string) $inv['phone_number']);
if ($waDigits !== '' && $waDigits[0] === '0') {
    $waDigits = '62' . substr($waDigits, 1);
}
$waRemain = $remaining > 0.004 ? pi_money($remaining) : '';
$pdfName = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', 'Invoice ' . $inv['invoice_number']), '-');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invoice <?= pi_h($inv['invoice_number']) ?></title>
<style>
  :root { --ink:#1b2430; --muted:#5b6672; --line:#d5dbe1; --accent:#1d6b3b; --navy:#17324d; --soft:#f3f6f8; }
  * { box-sizing: border-box; }
  [hidden] { display: none !important; }
  html, body { margin: 0; padding: 0; }
  body { font-family: "Segoe UI", Arial, Helvetica, sans-serif; color: var(--ink); background: #e7eaee; font-size: 12px; line-height: 1.45; }

  /* ---------- Toolbar (screen only, English UI) ---------- */
  .toolbar { position: sticky; top: 0; z-index: 10; background: #fff; border-bottom: 1px solid var(--line);
             padding: 10px 16px; display: flex; flex-wrap: wrap; gap: 10px 18px; align-items: center; }
  .toolbar .grow { flex: 1 1 320px; min-width: 0; }
  .toolbar .tb-title { font-weight: 700; font-size: 13px; margin-bottom: 4px; }
  .toolbar .muted { color: var(--muted); font-size: 12px; }
  .toolbar .actions { display: flex; gap: 8px; }
  .btn { font: inherit; font-weight: 600; padding: 8px 14px; border-radius: 8px; border: 1px solid var(--navy); cursor: pointer; }
  .btn-primary { background: var(--navy); color: #fff; }
  .btn-secondary { background: #fff; color: var(--navy); }
  .modal-ov { position: fixed; inset: 0; z-index: 50; background: rgba(15,23,32,.55); display: flex; align-items: center; justify-content: center; padding: 16px; }
  .modal-box { background: #fff; border-radius: 12px; width: 100%; max-width: 380px; padding: 18px; box-shadow: 0 10px 40px rgba(0,0,0,.3); }
  .modal-box h3 { margin: 0 0 4px; font-size: 15px; }
  .modal-box .cname { color: var(--muted); margin-bottom: 12px; }
  .hon-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }
  .hon-grid label { display: block; text-align: center; padding: 10px 6px; border: 1px solid var(--line); border-radius: 8px; cursor: pointer; font-weight: 600; }
  .hon-grid label.wide { grid-column: 1 / -1; }
  .hon-grid input { position: absolute; opacity: 0; pointer-events: none; }
  .hon-grid label:has(input:checked) { background: var(--navy); border-color: var(--navy); color: #fff; }
  .hon-grid label:has(input:focus-visible) { outline: 2px solid var(--accent); outline-offset: 2px; }
  .hon-custom { width: 100%; font: inherit; padding: 9px 10px; border: 1px solid var(--line); border-radius: 8px; margin-bottom: 12px; }
  .hon-custom:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
  .remember { display: flex; gap: 8px; align-items: center; margin-bottom: 14px; color: var(--muted); }
  .modal-actions { display: flex; justify-content: flex-end; gap: 8px; }
  .btn-wa { background: #1f9d55; border-color: #1f9d55; color: #fff; }

  /* ---------- Paper ---------- */
  .paper { width: 210mm; min-height: 297mm; margin: 16px auto; background: #fff; padding: 10mm 14mm 12mm;
           box-shadow: 0 2px 14px rgba(0,0,0,.18); }
  .letterhead { display: block; width: 100%; height: auto; mix-blend-mode: multiply; }

  .doc-title { text-align: center; margin: 14px 0 12px; }
  .doc-title h1 { margin: 0; font-size: 22px; letter-spacing: 6px; color: var(--navy); }
  .doc-title .doc-no { margin-top: 2px; font-size: 12px; color: var(--muted); }

  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
  .info-box { border: 1px solid var(--line); border-radius: 6px; padding: 9px 12px; }
  .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); font-weight: 700; margin-bottom: 3px; }
  .cust-name { font-size: 14px; font-weight: 700; }
  table.meta { width: 100%; border-collapse: collapse; }
  table.meta td { padding: 1px 0; vertical-align: top; }
  table.meta td:first-child { width: 38%; color: var(--muted); }
  .status { display: inline-block; font-weight: 700; font-size: 10.5px; padding: 0 8px; border-radius: 10px; border: 1px solid; }
  .status-paid { color: var(--accent); border-color: var(--accent); }
  .status-open { color: #a15c00; border-color: #a15c00; }

  .tbl-wrap { overflow-x: auto; }
  table.items { width: 100%; border-collapse: collapse; }
  table.items thead { display: table-header-group; }
  table.items th { background: var(--navy); color: #fff; font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em;
                   padding: 6px 7px; text-align: left; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  table.items td { padding: 6px 7px; border-bottom: 1px solid var(--line); vertical-align: top; }
  table.items tr { break-inside: avoid; page-break-inside: avoid; }
  table.items .num { text-align: right; white-space: nowrap; }
  table.items .ctr { text-align: center; }
  .item-name { font-weight: 600; }
  .item-note { color: var(--muted); font-size: 10.5px; }
  .tag { display: inline-block; border: 1px solid; border-radius: 3px; padding: 0 5px; margin-left: 6px;
         font-size: 9px; font-weight: 700; letter-spacing: .03em; vertical-align: 1px; }
  .tag-ret { color: #a15c00; }
  .tag-adj { color: #1d4f91; }
  .tag-def { color: #b3261e; }
  .deduct { color: #b3261e; }
  .keter { color: var(--muted); font-size: 10.5px; }
  .keter .tag { margin-left: 0; }
  .empty-row { text-align: center; color: var(--muted); padding: 14px 0; }

  .lower { display: grid; grid-template-columns: 1.15fr 1fr; gap: 16px; margin-top: 14px; align-items: start; }
  .lower > div { min-width: 0; }
  .terbilang { border: 1px dashed var(--line); border-radius: 6px; padding: 8px 10px; background: var(--soft); font-style: italic;
               -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  table.summary { width: 100%; border-collapse: collapse; }
  table.summary td { padding: 3px 0; }
  table.summary td:last-child { text-align: right; white-space: nowrap; }
  table.summary tr.grand td { border-top: 2px solid var(--navy); border-bottom: 2px solid var(--navy); font-weight: 700; font-size: 13px; padding: 6px 0; }
  table.summary tr.remain td { font-weight: 700; font-size: 13px; padding-top: 6px; }
  .stamp { display: inline-block; margin-top: 8px; border: 3px solid var(--accent); color: var(--accent); font-weight: 800; letter-spacing: 3px;
           padding: 1px 14px; transform: rotate(-7deg); opacity: .8; font-size: 18px; border-radius: 6px; }

  h3.sec { margin: 16px 0 6px; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--navy); }
  table.pays { width: 100%; border-collapse: collapse; }
  table.pays th { text-align: left; font-size: 10px; color: var(--muted); text-transform: uppercase; border-bottom: 1px solid var(--line); padding: 3px 4px; }
  table.pays td { padding: 4px; border-bottom: 1px solid var(--line); }
  table.pays .num { text-align: right; white-space: nowrap; }
  table.pays tr { break-inside: avoid; page-break-inside: avoid; }

  .bottom { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-top: 8px; align-items: start; break-inside: avoid; page-break-inside: avoid; }
  .bottom > div { min-width: 0; }
  .bottom h3.sec { margin: 12px 0 6px; }
  .bank { border: 1px solid var(--line); border-left: 4px solid var(--accent); border-radius: 4px; padding: 6px 10px; margin-bottom: 6px; }
  .bank .bn { font-weight: 700; }
  .bank .no { font-size: 13px; font-weight: 700; letter-spacing: .04em; }
  .bank .sub { color: var(--muted); font-size: 10.5px; }
  .pay-note { font-size: 10.5px; color: var(--muted); margin-top: 4px; }
  .unit-note { margin-top: 28px; padding-top: 8px; border-top: 1px dashed var(--line); break-inside: avoid; page-break-inside: avoid; }
  .unit-note h3.sec { margin-top: 4px; }
  .sign { text-align: left; }
  .sign img { display: block; margin: 4px 0; max-width: 62mm; max-height: 36mm; height: auto; mix-blend-mode: multiply; }
  .sign .name { font-weight: 700; text-decoration: underline; }
  .sign .role { color: var(--muted); }

  /* ---------- Attachment (Lampiran) ---------- */
  .att-title { text-align: center; margin: 14px 0 10px; }
  .att-title h2 { margin: 0; font-size: 18px; letter-spacing: 4px; color: var(--navy); }
  .att-title .sub { color: var(--muted); font-size: 12px; margin-top: 2px; }
  table.items tr.date-head td { background: var(--soft); font-weight: 700; color: var(--navy); border-bottom: 1px solid var(--line);
                                -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  table.items tr.date-sub td { font-weight: 600; border-bottom: 2px solid var(--line); }
  table.items tr.att-total td { font-weight: 700; font-size: 12.5px; border-top: 2px solid var(--navy); border-bottom: 2px solid var(--navy); }
  .warn { color: #b3261e; font-size: 12px; margin-top: 4px; }

  /* ---------- Small screens: let the paper breathe ---------- */
  @media screen and (max-width: 820px) {
    .paper { width: auto; min-height: 0; margin: 0; padding: 14px; box-shadow: none; }
    .info-grid, .lower, .bottom { grid-template-columns: 1fr; }
    table.items { min-width: 560px; }
  }

  /* ---------- Print ---------- */
  @page { size: A4; margin: 12mm 14mm; }
  @media print {
    .modal-ov { display: none !important; }
    body { background: #fff; font-size: 11.5px; }
    .toolbar { display: none !important; }
    .paper { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    .info-grid { grid-template-columns: 1fr 1fr; }
    .lower { grid-template-columns: 1.15fr 1fr; }
    .bottom { grid-template-columns: 1fr 1fr; }
    .paper + .paper { break-before: page; page-break-before: always; }
    table.items { min-width: 0; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <div class="grow">
    <div class="tb-title">Invoice preview</div>
    <div class="muted"><?= count($banks) ?> bank account(s) shown. To change them, close this tab and press Print again.</div>
    <?php if ($waDigits === ''): ?>
      <div class="warn">This customer has no phone number. WhatsApp will open without a recipient.</div>
    <?php endif; ?>
    <div class="muted" id="waHint" hidden>The PDF was downloaded. If WhatsApp did not open: <a id="waLink" href="#" target="_blank" rel="noopener">open WhatsApp</a></div>
    <?php if ($rows && abs($netSum - $totalAmount) > 0.5): ?>
      <div class="warn">Note: the item lines add up to <?= pi_h(pi_money_signed($netSum)) ?> but the invoice total is <?= pi_h(pi_money_signed($totalAmount)) ?>. Please check the invoice data.</div>
    <?php endif; ?>
  </div>
  <div class="actions">
    <button type="button" class="btn btn-primary" id="btnPrint">Print / Save as PDF</button>
    <button type="button" class="btn btn-wa" id="btnWa">Send Invoice (WhatsApp)</button>
    <button type="button" class="btn btn-secondary" id="btnClose">Close</button>
  </div>
</div>

<div class="modal-ov" id="attOverlay" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="attTitle">
    <h3 id="attTitle">Include the Lampiran (attachment)?</h3>
    <div class="cname">The detailed per-date page after the invoice.</div>
    <div class="hon-grid">
      <label><input type="radio" name="att" value="0" checked> Without</label>
      <label><input type="radio" name="att" value="1"> With Lampiran</label>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-secondary" id="attCancel">Cancel</button>
      <button type="button" class="btn btn-wa" id="attNext">Next</button>
    </div>
  </div>
</div>

<div class="modal-ov" id="honOverlay" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="honTitle">
    <h3 id="honTitle">How do you address this customer?</h3>
    <div class="cname"><?= pi_h($inv['customer_name']) ?></div>
    <div class="hon-grid">
      <label><input type="radio" name="hon" value="Kak"> Kak</label>
      <label><input type="radio" name="hon" value="Bang"> Bang</label>
      <label><input type="radio" name="hon" value="Buk"> Buk</label>
      <label><input type="radio" name="hon" value="Pak"> Pak</label>
      <label class="wide"><input type="radio" name="hon" value="none"> None (no title)</label>
    </div>
    <input type="text" class="hon-custom" id="honCustom" maxlength="30" placeholder="Or type your own (e.g. Mas, Pak Haji)" autocomplete="off">
    <label class="remember"><input type="checkbox" id="honRemember" checked> Remember for this customer</label>
    <div class="modal-actions">
      <button type="button" class="btn btn-secondary" id="honCancel">Cancel</button>
      <button type="button" class="btn btn-wa" id="honOk">Continue</button>
    </div>
  </div>
</div>

<div class="paper">
  <img class="letterhead" src="../assets/img/invoice_header.png" alt="PT. LISANI ALAF JAYA">

  <div class="doc-title">
    <h1>INVOICE</h1>
    <div class="doc-no">No. <?= pi_h($inv['invoice_number']) ?></div>
  </div>

  <div class="info-grid">
    <div class="info-box">
      <div class="lbl">Kepada Yth.</div>
      <div class="cust-name"><?= pi_h($inv['customer_name']) ?></div>
      <?php if (trim((string) $inv['phone_number']) !== ''): ?>
        <div>Telp. <?= pi_h($inv['phone_number']) ?></div>
      <?php endif; ?>
    </div>
    <div class="info-box">
      <table class="meta">
        <tr><td>No. Invoice</td><td><?= pi_h($inv['invoice_number']) ?></td></tr>
        <tr><td>Tanggal Invoice</td><td><?= pi_h(pi_date_id($inv['created_at'])) ?></td></tr>
        <tr><td>Periode</td><td><?= pi_h($periodLabel) ?></td></tr>
        <tr><td>Status</td><td>
          <?php if ($isPaid): ?>
            <span class="status status-paid">LUNAS</span>
          <?php else: ?>
            <span class="status status-open">BELUM LUNAS</span>
          <?php endif; ?>
        </td></tr>
      </table>
    </div>
  </div>

  <div class="tbl-wrap">
    <table class="items">
      <thead>
        <tr>
          <th class="ctr" style="width:4%">No</th>
          <th style="width:15%">Tanggal</th>
          <th>Produk</th>
          <th class="num" style="width:9%;white-space:nowrap">Jumlah *</th>
          <th class="num" style="width:12%">Harga Satuan</th>
          <th class="num" style="width:14%">Total</th>
          <th style="width:18%">Keterangan</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$groups): ?>
          <tr><td colspan="7" class="empty-row">Tidak ada rincian barang pada invoice ini.</td></tr>
        <?php endif; ?>
        <?php foreach ($groups as $i => $g): ?>
          <tr>
            <td class="ctr"><?= $i + 1 ?></td>
            <td>
              <?= pi_h(pi_date_id($g['first'])) ?>
              <?php if (substr((string) $g['first'], 0, 10) !== substr((string) $g['last'], 0, 10)): ?>
                <div class="item-note">s.d. <?= pi_h(pi_date_id($g['last'])) ?></div>
              <?php endif; ?>
            </td>
            <td><span class="item-name"><?= pi_h($g['name']) ?></span></td>
            <td class="num"><?= pi_h(pi_qty($g['show_qty'])) ?></td>
            <td class="num<?= $g['adj'] ? ' deduct' : '' ?>"><?= pi_h(pi_money_signed($g['price'], (bool) $g['adj'])) ?></td>
            <td class="num<?= $g['deduct'] ? ' deduct' : '' ?>"><?= pi_h(pi_money_signed($g['show_total'], $g['deduct'])) ?></td>
            <td class="keter">
              <?php if ($g['tag']): ?><span class="tag <?= pi_h($g['tag'][1]) ?>"><?= pi_h($g['tag'][0]) ?></span><?php endif; ?>
              <?= pi_h(implode(' · ', $g['notes'])) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="lower">
    <div>
      <?php if ($terbilangText !== ''): ?>
        <div class="lbl">Terbilang</div>
        <div class="terbilang"><?= pi_h($terbilangText) ?></div>
      <?php endif; ?>
    </div>
    <div>
      <table class="summary">
        <tr><td>Total Pengambilan Barang</td><td><?= pi_h(pi_money_signed($outSum - $inSum)) ?></td></tr>
        <?php if ($adjSum > 0.004): ?>
          <tr><td>Potongan Harga</td><td class="deduct"><?= pi_h(pi_money_signed($adjSum, true)) ?></td></tr>
        <?php endif; ?>
        <tr class="grand"><td>TOTAL TAGIHAN</td><td><?= pi_h(pi_money_signed($totalAmount)) ?></td></tr>
        <tr><td>Sudah Dibayar</td><td><?= pi_h(pi_money($paidAmount)) ?></td></tr>
        <?php if ($remaining < -0.004): ?>
          <tr class="remain"><td>Kelebihan Pembayaran</td><td><?= pi_h(pi_money($remaining)) ?></td></tr>
        <?php else: ?>
          <tr class="remain"><td>Sisa Tagihan</td><td><?= pi_h(pi_money(max(0, $remaining))) ?></td></tr>
        <?php endif; ?>
      </table>
      <?php if ($isPaid): ?>
        <div style="text-align:right"><span class="stamp">LUNAS</span></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($payments): ?>
    <h3 class="sec">Pembayaran Diterima</h3>
    <table class="pays">
      <thead><tr><th style="width:22%">Tanggal</th><th>Rekening Penerima</th><th class="num" style="width:22%">Jumlah</th></tr></thead>
      <tbody>
        <?php foreach ($payments as $p): ?>
          <?php
            $bankLabel = trim((string) $p['bank']);
            if (strtoupper($bankLabel) === 'CREDIT BALANCE') {
                $bankLabel = 'Saldo Kredit';
            }
            $label = implode(' · ', array_filter([$bankLabel, trim((string) $p['holder'])], 'strlen'));
          ?>
          <tr>
            <td><?= pi_h(pi_date_id($p['payment_date'])) ?></td>
            <td><?= pi_h($label !== '' ? $label : '-') ?></td>
            <td class="num"><?= pi_h(pi_money($p['amount'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="bottom">
    <div class="sign">
      <h3 class="sec">Hormat kami,</h3>
      <img src="../assets/img/invoice_signature.png" alt="Tanda tangan dan cap PT. Lisani Alaf Jaya">
      <div class="name"><?= pi_h(PI_SIGNER_NAME) ?></div>
      <div class="role"><?= pi_h(PI_SIGNER_ROLE) ?></div>
    </div>
    <div>
      <?php if ($banks): ?>
        <h3 class="sec">Informasi Pembayaran</h3>
        <?php foreach ($banks as $b): ?>
          <div class="bank">
            <div class="bn"><?= pi_h($b['bank_name']) ?></div>
            <div class="no"><?= pi_h($b['account_number']) ?></div>
            <?php if ($b['account_name'] !== ''): ?><div class="sub">a.n. <?= pi_h($b['account_name']) ?></div><?php endif; ?>
            <?php if ($b['currency'] !== '' && $b['currency'] !== 'IDR'): ?>
              <div class="sub">Mata uang: <?= pi_h($b['currency']) ?><?= $b['swift_code'] !== '' ? ' &middot; SWIFT: ' . pi_h($b['swift_code']) : '' ?></div>
              <?php if ($b['address'] !== ''): ?><div class="sub"><?= pi_h($b['address']) ?></div><?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div class="pay-note">Mohon cantumkan nomor invoice pada berita transfer.</div>
      <?php endif; ?>
    </div>
  </div>

    <?php if ($unitRows): ?>
    <div class="unit-note">
      <h3 class="sec">* Satuan Produk</h3>
      <div class="pay-note" style="margin:0 0 6px">* Satuan untuk kolom Jumlah pada tabel di atas.</div>
      <table class="pays">
        <thead><tr>
          <th>Produk</th>
          <th style="width:<?= $anySecondary ? '30%' : '40%' ?>">Satuan Primary</th>
          <?php if ($anySecondary): ?><th style="width:38%">Satuan Secondary</th><?php endif; ?>
        </tr></thead>
        <tbody>
          <?php foreach ($unitRows as $u): ?>
            <tr>
              <td><span class="item-name"><?= pi_h($u['name']) ?></span></td>
              <td>
                <?= pi_h($u['p'] !== '' ? $u['p'] : '-') ?>
                <?php if ($u['p'] !== '' && $u['pw'] > 0): ?><div class="item-note">@ <?= pi_h(pi_qty($u['pw'])) ?> kg</div><?php endif; ?>
              </td>
              <?php if ($anySecondary): ?>
                <td>
                  <?php if ($u['s'] !== ''): ?>
                    <?= pi_h($u['s']) ?>
                    <?php if ($u['ssub']): ?><div class="item-note"><?= pi_h(implode(' · ', $u['ssub'])) ?></div><?php endif; ?>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($rows): ?>
<div class="paper">
  <img class="letterhead" src="../assets/img/invoice_header.png" alt="PT. LISANI ALAF JAYA">

  <div class="att-title">
    <h2>LAMPIRAN</h2>
    <div class="sub">Rincian Pengambilan Barang per Tanggal</div>
  </div>

  <div class="info-grid">
    <div class="info-box">
      <div class="lbl">Kepada Yth.</div>
      <div class="cust-name"><?= pi_h($inv['customer_name']) ?></div>
    </div>
    <div class="info-box">
      <table class="meta">
        <tr><td>No. Invoice</td><td><?= pi_h($inv['invoice_number']) ?></td></tr>
        <tr><td>Periode</td><td><?= pi_h($periodLabel) ?></td></tr>
      </table>
    </div>
  </div>

  <div class="tbl-wrap">
    <table class="items">
      <thead>
        <tr>
          <th>Produk</th>
          <th class="num" style="width:9%">Jumlah</th>
          <th class="num" style="width:15%">Harga Satuan</th>
          <th class="num" style="width:16%">Total</th>
          <th style="width:25%">Keterangan</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($byDate as $date => $list): ?>
          <?php $sub = 0.0; ?>
          <tr class="date-head"><td colspan="5"><?= pi_h(pi_date_id($date)) ?></td></tr>
          <?php foreach ($list as $r): ?>
            <?php $sub += $r['deduct'] ? -$r['total'] : $r['total']; ?>
            <tr>
              <td><span class="item-name"><?= pi_h($r['name']) ?></span></td>
              <td class="num"><?= pi_h(pi_qty($r['qty'])) ?></td>
              <td class="num<?= $r['type'] === 'price_adjustment' ? ' deduct' : '' ?>"><?= pi_h(pi_money_signed($r['price'], $r['type'] === 'price_adjustment')) ?></td>
              <td class="num<?= $r['deduct'] ? ' deduct' : '' ?>"><?= pi_h(pi_money_signed($r['total'], $r['deduct'])) ?></td>
              <td class="keter"><?php if ($r['tag']): ?><span class="tag <?= pi_h($r['tag'][1]) ?>"><?= pi_h($r['tag'][0]) ?></span> <?php endif; ?><?= pi_h(implode(' · ', $r['notes'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <tr class="date-sub">
            <td colspan="3" class="num">Subtotal <?= pi_h(pi_date_id($date)) ?></td>
            <td class="num<?= $sub < 0 ? ' deduct' : '' ?>"><?= pi_h(pi_money_signed($sub)) ?></td>
            <td></td>
          </tr>
        <?php endforeach; ?>
        <tr class="att-total">
          <td colspan="3" class="num">TOTAL</td>
          <td class="num<?= $netSum < 0 ? ' deduct' : '' ?>"><?= pi_h(pi_money_signed($netSum)) ?></td>
          <td></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
document.getElementById('btnPrint').addEventListener('click', function () { window.print(); });
(function () {
  var waBase = <?= json_encode('https://wa.me/' . $waDigits) ?>;
  var custName = <?= json_encode((string) $inv['customer_name']) ?>;
  var invNo = <?= json_encode((string) $inv['invoice_number']) ?>;
  var remain = <?= json_encode($waRemain) ?>;
  var pdfName = <?= json_encode($pdfName) ?>;
  var storeKey = 'aos_invoice_honorific_' + <?= json_encode((int) $inv['customer_id']) ?>;
  var waUrl = '';
  var overlay = document.getElementById('honOverlay');
  var attOverlay = document.getElementById('attOverlay');
  var radios = document.querySelectorAll('input[name="hon"]');
  var attRadios = document.querySelectorAll('input[name="att"]');
  var withAttachment = false;
  var legacy = { Kakak: 'Kak', Abang: 'Bang', Bapak: 'Pak', Ibu: 'Buk' };   // values saved by the first version

  function saved() {
    var v = '';
    try { v = localStorage.getItem(storeKey) || ''; } catch (e) {}
    return legacy[v] || v;
  }
  var customInput = document.getElementById('honCustom');
  function selected() {
    var t = customInput.value.replace(/\s+/g, ' ').trim();
    if (t !== '') { return t; }                       // typed text wins over the buttons
    for (var i = 0; i < radios.length; i++) { if (radios[i].checked) { return radios[i].value; } }
    return '';
  }
  for (var ri = 0; ri < radios.length; ri++) {
    radios[ri].addEventListener('change', function () { customInput.value = ''; });
  }
  customInput.addEventListener('input', function () {
    if (customInput.value.trim() !== '') { for (var i = 0; i < radios.length; i++) { radios[i].checked = false; } }
  });
  function closeModal() { overlay.hidden = true; attOverlay.hidden = true; }

  function openHonorific() {
    var s = saved();
    var matched = false;
    for (var i = 0; i < radios.length; i++) {
      radios[i].checked = (radios[i].value === s);
      if (radios[i].checked) { matched = true; }
    }
    customInput.value = (s !== '' && !matched) ? s : '';     // a previously typed title comes back in the text box
    document.getElementById('honRemember').checked = true;
    attOverlay.hidden = true;
    overlay.hidden = false;
    var f = customInput.value !== '' ? customInput : (document.querySelector('input[name="hon"]:checked') || radios[0]);
    if (f) { f.focus(); }
  }

  // Step 1: attachment? (default: without)
  document.getElementById('btnWa').addEventListener('click', function () {
    attRadios[0].checked = true;
    overlay.hidden = true;
    attOverlay.hidden = false;
    attRadios[0].focus();
  });
  document.getElementById('attCancel').addEventListener('click', closeModal);
  document.getElementById('attNext').addEventListener('click', function () {
    withAttachment = attRadios[1].checked;
    openHonorific();                                      // Step 2: how to address the customer
  });
  attOverlay.addEventListener('click', function (e) { if (e.target === attOverlay) { closeModal(); } });
  document.getElementById('honCancel').addEventListener('click', closeModal);
  overlay.addEventListener('click', function (e) { if (e.target === overlay) { closeModal(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && (!overlay.hidden || !attOverlay.hidden)) { closeModal(); } });

  document.getElementById('honOk').addEventListener('click', function () {
    var hon = selected();
    if (hon === '') { alert('Please choose an option, type a title, or pick "None".'); return; }
    if (document.getElementById('honRemember').checked) {
      try { localStorage.setItem(storeKey, hon); } catch (e) {}
    }
    var text = 'Yth. ' + (hon === 'none' ? '' : hon + ' ') + custName + ',\n\n'
      + 'Berikut kami lampirkan invoice No. ' + invNo + '.\n'
      + (remain ? 'Sisa tagihan: ' + remain + '\n' : '')
      + '\nTerima kasih.\nPT. Lisani Alaf Jaya';
    waUrl = waBase + '?text=' + encodeURIComponent(text);
    closeModal();

    // Open the WhatsApp tab NOW (inside the click, so it is not popup-blocked);
    // it is pointed at the chat once the PDF has been downloaded.
    var w = window.open('', '_blank');
    if (w) { try { w.document.title = 'WhatsApp'; w.document.body.style.fontFamily = 'Arial, sans-serif'; w.document.body.textContent = 'Preparing the invoice PDF...'; } catch (e) {} }
    var btn = document.getElementById('btnWa');
    btn.disabled = true;
    var pdfUrl = location.pathname + location.search + (location.search ? '&' : '?') + 'format=pdf' + (withAttachment ? '&attach=1' : '');

    fetch(pdfUrl, { credentials: 'same-origin' })
      .then(function (r) {
        var ct = r.headers.get('Content-Type') || '';
        if (!r.ok || ct.indexOf('application/pdf') === -1) { throw new Error('bad response'); }
        return r.blob();
      })
      .then(function (blob) {
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = pdfName + '.pdf';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 15000);
        setTimeout(function () {                       // give the browser a moment to start the download
          if (w && !w.closed) {
            w.location.href = waUrl;
          } else {
            var l = document.getElementById('waLink');
            l.href = waUrl;
            document.getElementById('waHint').hidden = false;
          }
          btn.disabled = false;
        }, 900);
      })
      .catch(function () {
        if (w && !w.closed) { w.close(); }
        btn.disabled = false;
        alert('Failed to create the PDF. Please try again, or use Print / Save as PDF.');
      });
  });
})();
document.getElementById('btnClose').addEventListener('click', function () { window.close(); });
</script>
</body>
</html>