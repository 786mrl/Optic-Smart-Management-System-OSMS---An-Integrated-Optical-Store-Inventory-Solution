<?php
// lisani_aos/ajax/get_finance_report.php
// Finance Report (Report menu > Finance Report tab).
// Aggregates three money-movement sources into one ledger + per-account balances:
//   - outflow: transactions (category='disbursement') -> source_bank/source_account_number
//   - inflow:  invoice_payments                        -> destination_bank/destination_account_number
//   - outflow: invoice_refunds (source account OPTIONAL, see migration_invoice_refunds_bank_fields.sql)
// "Rekening" here are company bank accounts (json_file/bank_accounts.json), matched
// by bank_name + account_number text (free-text columns, no FK) — case-insensitive,
// whitespace-insensitive, since the same account can be typed slightly differently.
// Balance starts from 0 (per user decision, 2 Okt 2026): purely from recorded movements.
//
// GET params (all optional): date_from, date_to (Y-m-d)
// Response: { ok, data: { accounts:[...], unassigned:{...}, ledger:[...], monthly:[...],
//             receivables:{...}, totals:{...} } }

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function aos_json(array $payload): void
{
    $noise = ob_get_clean();
    if ($noise !== false && trim($noise) !== '') {
        error_log('get_finance_report.php stray output: ' . $noise);
    }
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{"ok":false,"message":"Failed to encode the response."}';
    }
    echo $json;
    exit;
}

function aos_fail(string $message): void
{
    aos_json(['ok' => false, 'message' => $message]);
}

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    aos_fail('Session expired. Please log in again.');
}

require_once dirname(__DIR__) . '/db_config.php'; // $lisani_conn (mysqli)

// Make mysqli throw on failure (prepare() returning false on a missing
// column/table otherwise causes a silent fatal further down, since
// display_errors is off — this turns that into a clean JSON error instead).
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function get_str(string $key): string
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : '';
}

$dateFrom = get_str('date_from');
$dateTo   = get_str('date_to');
$hasFrom  = (bool)DateTime::createFromFormat('Y-m-d', $dateFrom);
$hasTo    = (bool)DateTime::createFromFormat('Y-m-d', $dateTo);

// ---------- Company bank accounts (source of truth for "our accounts") ----------
$jsonPath = dirname(__DIR__) . '/json_file/bank_accounts.json';
$bankAccounts = [];
if (is_file($jsonPath)) {
    $decoded = json_decode((string)file_get_contents($jsonPath), true);
    if (is_array($decoded)) {
        $bankAccounts = $decoded;
    }
}

// Normalise for matching: uppercase, strip whitespace, so "MANDIRI" / "mandiri "
// and "1110 0160 9789 7" / "11100160 97897" are treated as the same account.
function norm_key(string $bank, string $accNo): string
{
    $b = strtoupper(preg_replace('/\s+/', '', $bank));
    $n = strtoupper(preg_replace('/\s+/', '', $accNo));
    return $b . '|' . $n;
}

$accountsByKey = [];
$accountsOut = [];
foreach ($bankAccounts as $acc) {
    $key = norm_key((string)($acc['bank_name'] ?? ''), (string)($acc['account_number'] ?? ''));
    $accountsByKey[$key] = $acc['id'];
    $accountsOut[$acc['id']] = [
        'id'             => $acc['id'],
        'bank_name'      => $acc['bank_name'] ?? '',
        'account_number' => $acc['account_number'] ?? '',
        'account_name'   => $acc['account_name'] ?? '',
        'currency'       => $acc['currency'] ?? 'IDR',
        'inflow'         => 0.0,
        'outflow'        => 0.0,
        'balance'        => 0.0,
    ];
}
$unassigned = ['inflow' => 0.0, 'outflow' => 0.0, 'balance' => 0.0];

$ledger = [];   // flat list, newest first at the end (sorted later)
$monthly = []; // key Y-m -> {inflow, outflow}

function bump_monthly(array &$monthly, string $date, string $field, float $amount): void
{
    $ym = substr($date, 0, 7);
    if (!isset($monthly[$ym])) {
        $monthly[$ym] = ['month' => $ym, 'inflow' => 0.0, 'outflow' => 0.0];
    }
    $monthly[$ym][$field] += $amount;
}

function resolve_account_id(array $accountsByKey, string $bank, string $accNo): ?string
{
    $key = norm_key($bank, $accNo);
    return $accountsByKey[$key] ?? null;
}

try {

// ---------- Outflow: disbursements ----------
$whereDate = '1=1';
$params = [];
$types = '';
if ($hasFrom) { $whereDate .= ' AND transaction_date >= ?'; $params[] = $dateFrom; $types .= 's'; }
if ($hasTo)   { $whereDate .= ' AND transaction_date <= ?'; $params[] = $dateTo;   $types .= 's'; }

$sql = "SELECT t.id, t.transaction_date, t.source_bank, t.source_account_number,
               t.final_amount_idr, t.currency, t.amount, t.notes,
               d.transaction_purpose, d.cashflow_type
        FROM transactions t
        JOIN transaction_disbursements d ON d.transaction_id = t.id
        WHERE t.category = 'disbursement' AND $whereDate
        ORDER BY t.transaction_date ASC, t.id ASC";
$stmt = $lisani_conn->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $amt = (float)$row['final_amount_idr'];
    $accId = resolve_account_id($accountsByKey, (string)$row['source_bank'], (string)$row['source_account_number']);
    if ($accId !== null) {
        $accountsOut[$accId]['outflow'] += $amt;
    } else {
        $unassigned['outflow'] += $amt;
    }
    bump_monthly($monthly, $row['transaction_date'], 'outflow', $amt);
    $ledger[] = [
        'date'       => $row['transaction_date'],
        'type'       => 'outflow',
        'source'     => 'disbursement',
        'account_id' => $accId,
        'bank'       => $row['source_bank'],
        'account_no' => $row['source_account_number'],
        'amount'     => $amt,
        'original'   => $row['currency'] !== 'IDR' ? ($row['currency'] . ' ' . number_format((float)$row['amount'], 2)) : null,
        'label'      => $row['transaction_purpose'],
        'notes'      => $row['notes'],
        'ref_id'     => (int)$row['id'],
    ];
}
$stmt->close();

// ---------- Inflow: invoice payments ----------
$sql = "SELECT p.id, p.payment_date, p.destination_bank, p.destination_account_number,
               p.amount, p.notes, c.customer_name, i.invoice_number
        FROM invoice_payments p
        JOIN customers c ON c.id = p.customer_id
        JOIN invoices i ON i.id = p.invoice_id
        WHERE 1=1" . ($hasFrom ? ' AND p.payment_date >= ?' : '') . ($hasTo ? ' AND p.payment_date <= ?' : '') . "
        ORDER BY p.payment_date ASC, p.id ASC";
$stmt = $lisani_conn->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $amt = (float)$row['amount'];
    $accId = resolve_account_id($accountsByKey, (string)$row['destination_bank'], (string)$row['destination_account_number']);
    if ($accId !== null) {
        $accountsOut[$accId]['inflow'] += $amt;
    } else {
        $unassigned['inflow'] += $amt;
    }
    bump_monthly($monthly, $row['payment_date'], 'inflow', $amt);
    $ledger[] = [
        'date'       => $row['payment_date'],
        'type'       => 'inflow',
        'source'     => 'invoice_payment',
        'account_id' => $accId,
        'bank'       => $row['destination_bank'],
        'account_no' => $row['destination_account_number'],
        'amount'     => $amt,
        'original'   => null,
        'label'      => 'Payment — ' . $row['customer_name'] . ' (' . $row['invoice_number'] . ')',
        'notes'      => $row['notes'],
        'ref_id'     => (int)$row['id'],
    ];
}
$stmt->close();

// ---------- Outflow: refunds ----------
$sql = "SELECT r.id, r.refund_date, r.source_bank, r.source_account_number,
               r.amount, r.method, r.notes, c.customer_name
        FROM invoice_refunds r
        JOIN customers c ON c.id = r.customer_id
        WHERE 1=1" . ($hasFrom ? ' AND r.refund_date >= ?' : '') . ($hasTo ? ' AND r.refund_date <= ?' : '') . "
        ORDER BY r.refund_date ASC, r.id ASC";
$stmt = $lisani_conn->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $amt = (float)$row['amount'];
    $accId = $row['source_bank'] ? resolve_account_id($accountsByKey, (string)$row['source_bank'], (string)$row['source_account_number']) : null;
    if ($accId !== null) {
        $accountsOut[$accId]['outflow'] += $amt;
    } else {
        $unassigned['outflow'] += $amt;
    }
    bump_monthly($monthly, $row['refund_date'], 'outflow', $amt);
    $ledger[] = [
        'date'       => $row['refund_date'],
        'type'       => 'outflow',
        'source'     => 'refund',
        'account_id' => $accId,
        'bank'       => $row['source_bank'],
        'account_no' => $row['source_account_number'],
        'amount'     => $amt,
        'original'   => null,
        'label'      => 'Refund — ' . $row['customer_name'] . ($row['method'] ? ' (' . $row['method'] . ')' : ''),
        'notes'      => $row['notes'],
        'ref_id'     => (int)$row['id'],
    ];
}
$stmt->close();

// ---------- Finalize balances ----------
foreach ($accountsOut as &$acc) {
    $acc['balance'] = $acc['inflow'] - $acc['outflow'];
}
unset($acc);
$unassigned['balance'] = $unassigned['inflow'] - $unassigned['outflow'];

usort($ledger, function ($a, $b) {
    return $a['date'] <=> $b['date'] ?: $a['ref_id'] <=> $b['ref_id'];
});

ksort($monthly);
$monthlyOut = array_values($monthly);

$totalInflow = array_sum(array_column($monthlyOut, 'inflow'));
$totalOutflow = array_sum(array_column($monthlyOut, 'outflow'));

// ---------- Receivables / credit snapshot (not filtered by date — current state) ----------
$receivables = ['open_invoices_total' => 0.0, 'open_invoices_count' => 0, 'credit_balance_total' => 0.0, 'top_open' => []];
$r1 = $lisani_conn->query("SELECT COUNT(*) c, COALESCE(SUM(total_amount - paid_amount),0) s FROM invoices WHERE status='open'");
if ($row = $r1->fetch_assoc()) {
    $receivables['open_invoices_count'] = (int)$row['c'];
    $receivables['open_invoices_total'] = (float)$row['s'];
}
$r2 = $lisani_conn->query("SELECT COALESCE(SUM(credit_balance),0) s FROM customers WHERE credit_balance > 0");
if ($row = $r2->fetch_assoc()) {
    $receivables['credit_balance_total'] = (float)$row['s'];
}
$r3 = $lisani_conn->query(
    "SELECT c.customer_name, i.invoice_number, (i.total_amount - i.paid_amount) AS outstanding
     FROM invoices i JOIN customers c ON c.id = i.customer_id
     WHERE i.status='open' ORDER BY outstanding DESC LIMIT 10"
);
while ($row = $r3->fetch_assoc()) {
    $receivables['top_open'][] = [
        'customer_name'   => $row['customer_name'],
        'invoice_number'  => $row['invoice_number'],
        'outstanding'     => (float)$row['outstanding'],
    ];
}

aos_json([
    'ok'   => true,
    'data' => [
        'accounts'     => array_values($accountsOut),
        'unassigned'   => $unassigned,
        'ledger'       => $ledger,
        'monthly'      => $monthlyOut,
        'receivables'  => $receivables,
        'totals'       => [
            'inflow'  => $totalInflow,
            'outflow' => $totalOutflow,
            'net'     => $totalInflow - $totalOutflow,
        ],
    ],
]);

} catch (Throwable $e) {
    error_log('get_finance_report.php: ' . $e->getMessage());
    // Surfaced to the client on purpose (internal tool, not a public-facing
    // endpoint) so a schema mismatch (e.g. migration not yet run) is visible
    // immediately instead of a silent blank response.
    aos_fail('Report query failed: ' . $e->getMessage());
}