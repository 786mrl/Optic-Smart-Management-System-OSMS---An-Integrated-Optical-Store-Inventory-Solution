<?php
// lisani_aos/ajax/print_invoice.php
// Printable A4 invoice (Indonesian) for ONE invoice. Opens in a new tab from
// Sales Transaction > Customers > "Print" on an invoice; the browser's
// Print -> "Save as PDF" turns it into the PDF. No PDF library needed.
// Read-only.
//
// GET: invoice_id
// Bank accounts come from json_file/bank_accounts.json; the user ticks which
// ones to show on the page toolbar (several allowed, remembered per browser).
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
    return 'Rp ' . number_format($f, $dec, ',', '.');
}

function pi_qty($v): string
{
    $s = number_format((float) $v, 2, ',', '.');          // e.g. "1.000,00"
    return rtrim(rtrim($s, '0'), ',');                    // -> "1.000"
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
                i.period_month, i.period_year, c.customer_name, c.phone_number
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
        'SELECT m.id, m.movement_type, m.stock_source, m.movement_date, m.driver_name, m.police_number,
                m.qty_primary_package, m.price, m.total_price,
                sm.movement_date AS source_date,
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

    if ($type === 'out') {
        $outSum += $total;
        if ($m['stock_source'] === 'defective') {
            $tag = ['BARANG CACAT', 'tag-def'];
        }
        if (trim((string) $m['driver_name']) !== '') {
            $notes[] = 'Sopir: ' . $m['driver_name'];
        }
        if (trim((string) $m['police_number']) !== '') {
            $notes[] = 'No. Polisi: ' . $m['police_number'];
        }
    } elseif ($type === 'in') {
        $inSum += $total;
        $tag = ['RETUR', 'tag-ret'];
        if ($m['stock_source'] === 'defective') {
            $notes[] = 'Kondisi: cacat';
        } elseif ($m['stock_source'] === 'normal') {
            $notes[] = 'Kondisi: baik';
        }
        if (!empty($m['source_date'])) {
            $notes[] = 'dari pengambilan tgl ' . pi_date_id($m['source_date']);
        }
    } else { // price_adjustment
        $adjSum += $total;
        $tag = ['POTONGAN HARGA', 'tag-adj'];
        if (!empty($m['source_date'])) {
            $notes[] = 'dari pengambilan tgl ' . pi_date_id($m['source_date']);
        }
    }

    $rows[] = [
        'date'   => $m['movement_date'],
        'name'   => $m['product_name'],
        'tag'    => $tag,
        'notes'  => $notes,
        'qty'    => $m['qty_primary_package'],
        'unit'   => (string) $m['unit_label'],
        'price'  => $m['price'],
        'total'  => $total,
        'deduct' => $type !== 'out',
    ];
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
  .toolbar .banks { display: flex; flex-wrap: wrap; gap: 6px 14px; }
  .toolbar label { display: inline-flex; gap: 6px; align-items: center; font-size: 12px; cursor: pointer; }
  .toolbar .muted { color: var(--muted); font-size: 12px; }
  .toolbar .actions { display: flex; gap: 8px; }
  .btn { font: inherit; font-weight: 600; padding: 8px 14px; border-radius: 8px; border: 1px solid var(--navy); cursor: pointer; }
  .btn-primary { background: var(--navy); color: #fff; }
  .btn-secondary { background: #fff; color: var(--navy); }

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

  .bottom { display: grid; grid-template-columns: 1.3fr 1fr; gap: 18px; margin-top: 8px; align-items: end; break-inside: avoid; page-break-inside: avoid; }
  .bottom > div { min-width: 0; }
  .bank { border: 1px solid var(--line); border-left: 4px solid var(--accent); border-radius: 4px; padding: 6px 10px; margin-bottom: 6px; }
  .bank .bn { font-weight: 700; }
  .bank .no { font-size: 13px; font-weight: 700; letter-spacing: .04em; }
  .bank .sub { color: var(--muted); font-size: 10.5px; }
  .pay-note { font-size: 10.5px; color: var(--muted); margin-top: 4px; }
  .sign { text-align: center; }
  .sign img { display: block; margin: 4px auto; max-width: 62mm; max-height: 36mm; height: auto; mix-blend-mode: multiply; }
  .sign .name { font-weight: 700; text-decoration: underline; }
  .sign .role { color: var(--muted); }

  /* ---------- Small screens: let the paper breathe ---------- */
  @media screen and (max-width: 820px) {
    .paper { width: auto; min-height: 0; margin: 0; padding: 14px; box-shadow: none; }
    .info-grid, .lower, .bottom { grid-template-columns: 1fr; }
    table.items { min-width: 560px; }
  }

  /* ---------- Print ---------- */
  @page { size: A4; margin: 12mm 14mm; }
  @media print {
    body { background: #fff; font-size: 11.5px; }
    .toolbar { display: none !important; }
    .paper { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    .info-grid { grid-template-columns: 1fr 1fr; }
    .lower { grid-template-columns: 1.15fr 1fr; }
    .bottom { grid-template-columns: 1.3fr 1fr; }
    table.items { min-width: 0; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <div class="grow">
    <div class="tb-title">Bank accounts shown on this invoice</div>
    <?php if ($banks): ?>
      <div class="banks">
        <?php foreach ($banks as $b): ?>
          <label>
            <input type="checkbox" checked data-bank-toggle="<?= pi_h($b['id']) ?>">
            <span><?= pi_h($b['bank_name']) ?> &middot; <?= pi_h($b['account_number']) ?><?= $b['currency'] !== '' ? ' (' . pi_h($b['currency']) . ')' : '' ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="muted">No bank accounts saved yet (Settings &gt; Company Bank Accounts).</div>
    <?php endif; ?>
  </div>
  <div class="actions">
    <button type="button" class="btn btn-primary" id="btnPrint">Print / Save as PDF</button>
    <button type="button" class="btn btn-secondary" id="btnClose">Close</button>
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
          <th class="ctr" style="width:5%">No</th>
          <th style="width:14%">Tanggal</th>
          <th>Keterangan</th>
          <th class="num" style="width:13%">Jumlah</th>
          <th class="num" style="width:16%">Harga Satuan</th>
          <th class="num" style="width:17%">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="empty-row">Tidak ada rincian barang pada invoice ini.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $i => $r): ?>
          <tr>
            <td class="ctr"><?= $i + 1 ?></td>
            <td><?= pi_h(pi_date_id($r['date'])) ?></td>
            <td>
              <span class="item-name"><?= pi_h($r['name']) ?></span><?php if ($r['tag']): ?><span class="tag <?= pi_h($r['tag'][1]) ?>"><?= pi_h($r['tag'][0]) ?></span><?php endif; ?>
              <?php if ($r['notes']): ?>
                <div class="item-note"><?= pi_h(implode(' · ', $r['notes'])) ?></div>
              <?php endif; ?>
            </td>
            <td class="num"><?= pi_h(pi_qty($r['qty'])) ?> <?= pi_h($r['unit']) ?></td>
            <td class="num"><?= pi_h(pi_money($r['price'])) ?></td>
            <td class="num<?= $r['deduct'] ? ' deduct' : '' ?>"><?= $r['deduct'] ? '&minus; ' : '' ?><?= pi_h(pi_money($r['total'])) ?></td>
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
        <tr><td>Total Pengambilan Barang</td><td><?= pi_h(pi_money($outSum)) ?></td></tr>
        <?php if ($inSum > 0.004): ?>
          <tr><td>Retur Barang</td><td class="deduct">&minus; <?= pi_h(pi_money($inSum)) ?></td></tr>
        <?php endif; ?>
        <?php if ($adjSum > 0.004): ?>
          <tr><td>Potongan Harga</td><td class="deduct">&minus; <?= pi_h(pi_money($adjSum)) ?></td></tr>
        <?php endif; ?>
        <tr class="grand"><td>TOTAL TAGIHAN</td><td><?= $totalAmount < 0 ? '&minus; ' : '' ?><?= pi_h(pi_money($totalAmount)) ?></td></tr>
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
    <div id="bankSection">
      <?php if ($banks): ?>
        <h3 class="sec" style="margin-top:12px">Informasi Pembayaran</h3>
        <?php foreach ($banks as $b): ?>
          <div class="bank" data-bank-id="<?= pi_h($b['id']) ?>">
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
    <div class="sign">
      <div>Hormat kami,</div>
      <img src="../assets/img/invoice_signature.png" alt="Tanda tangan dan cap PT. Lisani Alaf Jaya">
      <div class="name"><?= pi_h(PI_SIGNER_NAME) ?></div>
      <div class="role"><?= pi_h(PI_SIGNER_ROLE) ?></div>
    </div>
  </div>
</div>

<script>
(function () {
  var KEY = 'aos_invoice_bank_selection';
  var toggles = [].slice.call(document.querySelectorAll('[data-bank-toggle]'));
  var blocks = [].slice.call(document.querySelectorAll('[data-bank-id]'));
  var section = document.getElementById('bankSection');

  function loadStored() {
    try {
      var raw = localStorage.getItem(KEY);
      if (raw === null) return null;
      var arr = JSON.parse(raw);
      return Array.isArray(arr) ? arr : null;
    } catch (e) { return null; }
  }
  function saveStored(ids) {
    try { localStorage.setItem(KEY, JSON.stringify(ids)); } catch (e) { /* storage unavailable */ }
  }
  function selectedIds() {
    return toggles.filter(function (c) { return c.checked; })
      .map(function (c) { return c.getAttribute('data-bank-toggle'); });
  }
  function apply() {
    var ids = selectedIds();
    var any = false;
    blocks.forEach(function (b) {
      var on = ids.indexOf(b.getAttribute('data-bank-id')) !== -1;
      b.hidden = !on;
      if (on) any = true;
    });
    // Hide the whole "Informasi Pembayaran" column content when nothing is
    // ticked (the signature stays in place).
    if (section) section.style.visibility = any ? 'visible' : 'hidden';
  }

  var stored = loadStored();
  if (stored !== null) {
    toggles.forEach(function (c) {
      c.checked = stored.indexOf(c.getAttribute('data-bank-toggle')) !== -1;
    });
  }
  toggles.forEach(function (c) {
    c.addEventListener('change', function () { apply(); saveStored(selectedIds()); });
  });
  apply();

  document.getElementById('btnPrint').addEventListener('click', function () { window.print(); });
  document.getElementById('btnClose').addEventListener('click', function () { window.close(); });
})();
</script>
</body>
</html>
