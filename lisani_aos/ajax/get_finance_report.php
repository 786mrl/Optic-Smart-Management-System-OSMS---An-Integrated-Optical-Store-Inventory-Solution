<?php
// lisani_aos/ajax/get_finance_report.php
// Finance Report (Report menu — no tabs; this is the whole Report section).
// Aggregates three money-movement sources into one ledger + per-account balances:
//   - outflow: transactions (category='disbursement') -> source_bank/source_account_number
//             (+ ledger 'category' = disbursement_categories.category_name; invoice payments = fixed 'SALES PAYMENT'; refunds = null; display-only grouping label)
//   - inflow:  invoice_payments, EXCLUDING source_bank='CREDIT BALANCE' rows -> destination_bank/destination_account_number
//             (those rows are create_order.php's auto-apply of an existing credit_balance against
//              a new invoice — not new cash; the real inflow was already counted when the credit
//              was formed, see customer_credit_sources / migration_customer_credit_sources.sql.
//              Fix 1 Nov 2026 — user-reported: Ledger total kept growing past what any account
//              actually received, because this money was being counted twice.)
//   - outflow: invoice_refunds (source account OPTIONAL, see migration_invoice_refunds_bank_fields.sql)
// "Rekening" here are company bank accounts (json_file/bank_accounts.json), matched
// by bank_name + account_number text (free-text columns, no FK) — case-insensitive,
// whitespace-insensitive, since the same account can be typed slightly differently.
// Balance starts from 0 (per user decision, 2 Okt 2026): purely from recorded movements.
//
// GET params (all optional): date_from, date_to (Y-m-d), activity_id (int)
//
// activity_id filter (added 12 Okt 2026), per source:
//   - disbursement : transaction_disbursements.activity_id (direct column)
//   - inflow       : invoice_payments -> EXISTS against logistic_movements/logistics
//                     for that invoice (an invoice can mix products from several
//                     activity codes; a payment is NOT split proportionally — it is
//                     included whole if ANY product on the invoice belongs to the
//                     filtered activity code; user decision, 12 Okt 2026)
//   - refund       : invoice_refunds has NO invoice_id / activity_id column at all
//                     (confirmed via DESCRIBE, 12 Okt 2026) — a refund reduces
//                     customers.credit_balance, which can accumulate from returns
//                     across many invoices/activity codes, so it cannot be traced to
//                     one activity code. Refunds therefore ALWAYS show, regardless of
//                     the activity_id filter (user decision, 12 Okt 2026). Deliberate,
//                     not a bug — do not "fix" this without a schema change.
//
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

$activityIdRaw = get_str('activity_id');
$hasActivity   = ($activityIdRaw !== '' && ctype_digit($activityIdRaw));
$activityId    = $hasActivity ? (int)$activityIdRaw : 0;

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
               d.transaction_purpose, d.cashflow_type, dc.category_name
        FROM transactions t
        JOIN transaction_disbursements d ON d.transaction_id = t.id
        LEFT JOIN disbursement_categories dc ON dc.id = d.category_id
        WHERE t.category = 'disbursement' AND $whereDate" .
        ($hasActivity ? " AND d.activity_id = ?" : "") . "
        ORDER BY t.transaction_date ASC, t.id ASC";
$outflowParams = $params;
$outflowTypes  = $types;
if ($hasActivity) { $outflowParams[] = $activityId; $outflowTypes .= 'i'; }
$stmt = $lisani_conn->prepare($sql);
if ($outflowParams) { $stmt->bind_param($outflowTypes, ...$outflowParams); }
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
        'category'   => $row['category_name'], // disbursement category (null for inflow/refund rows)
        'notes'      => $row['notes'],
        'ledger_note' => null,
        'group_id'   => null,
        'ref_id'     => (int)$row['id'],
    ];
}
$stmt->close();

// ---------- Credit formed directly from an invoice_payment ----------
// Built up front so the inflow loop below can split a payment the moment
// any of its money became a customer_credit_sources row (user request,
// 6 Nov 2026: "dari inflow row 1 itu... dipecah jadi 2, X customer credit
// balance dan Y yang masuk betul ke perusahaan"). Unlike the credit_remaining
// / credit_used display pair further below, BOTH halves here are real money
// — the original payment genuinely arrived in full — so both keep their own
// real 'amount' counted toward accounts/monthly/totals; this only relabels
// how one real inflow is split across adjacent rows, it never changes what
// a bank account's total says it received (the Rp 60 TRANSFER-refund
// question from the same conversation is separate and unaffected — see
// PROJECT_NOTES.md).
$creditByPayment = [];
$ccsRes = $lisani_conn->query(
    "SELECT id, origin_invoice_payment_id, amount, remaining_amount, source_bank, source_account_number, origin_type
     FROM customer_credit_sources
     WHERE origin_invoice_payment_id IS NOT NULL
     ORDER BY origin_invoice_payment_id ASC, id ASC"
);
if ($ccsRes) {
    while ($ccsRow = $ccsRes->fetch_assoc()) {
        $pid = (int) $ccsRow['origin_invoice_payment_id'];
        if (!isset($creditByPayment[$pid])) {
            $creditByPayment[$pid] = ['total' => 0.0, 'rows' => []];
        }
        $creditByPayment[$pid]['total'] += (float) $ccsRow['amount'];
        $creditByPayment[$pid]['rows'][] = $ccsRow;
    }
}

// ---------- Credit-balance applications, by credit source ----------
// customer_credit_applications (migration_customer_credit_sources.sql +
// migration_customer_credit_applications_snapshot.sql, 1 Nov 2026 — guarded,
// may not exist yet). Used to be rendered as two extra visible ledger rows
// per application ("remaining" + "used"), which made a single credit
// balance's later use look like 2-3 separate transactions once it got
// partly applied and partly refunded (user-reported confusion, 8 Nov 2026:
// "baris 1 ... masih ada, ini membuat kondisi jadi bingung"). Folded into a
// lookup by credit_source_id instead — the row below ("Credit formed
// directly from an invoice_payment") attaches each source's applications to
// its own ledger row as 'applications', for the frontend to show in a
// detail panel on click rather than as separate rows. Not pushed to $ledger
// and not counted toward any total — this money was already counted once,
// as a real inflow, when the account first received it.
$appsBySource = [];
$tableCheck = $lisani_conn->query(
    "SELECT COUNT(*) c FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'customer_credit_applications'"
);
$hasCreditApplications = $tableCheck && (int)($tableCheck->fetch_assoc()['c'] ?? 0) > 0;
if ($hasCreditApplications) {
    $sql = "SELECT a.id AS app_id, a.credit_source_id, a.amount_used, a.remaining_after_amount, a.created_at,
                   p.payment_date, c.customer_name, i.invoice_number
            FROM customer_credit_applications a
            JOIN invoice_payments p ON p.id = a.invoice_payment_id
            JOIN invoices i ON i.id = p.invoice_id
            JOIN customers c ON c.id = p.customer_id
            WHERE 1=1" . ($hasFrom ? ' AND p.payment_date >= ?' : '') . ($hasTo ? ' AND p.payment_date <= ?' : '') . "
            ORDER BY p.payment_date ASC, a.id ASC";
    $stmt = $lisani_conn->prepare($sql);
    if ($params) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $srcId = (int) $row['credit_source_id'];
        if (!isset($appsBySource[$srcId])) {
            $appsBySource[$srcId] = [];
        }
        $appsBySource[$srcId][] = [
            'kind'                   => 'applied',
            'date'                   => $row['payment_date'],
            'created_at'             => $row['created_at'], // merge-sort key with refunds below — same credit source, so both share one real timeline
            'amount'                 => (float) $row['amount_used'],
            'remaining_after_amount' => (float) $row['remaining_after_amount'],
            'customer_name'          => $row['customer_name'],
            'invoice_number'         => $row['invoice_number'],
        ];
    }
    $stmt->close();
}

// ---------- Credit-balance refunds, by credit source ----------
// customer_credit_refunds (migration_customer_credit_refunds.sql, 8 Nov
// 2026 — guarded, may not exist yet if the migration hasn't been run).
// Same idea as $appsBySource above but for cash refunded instead of credit
// applied to a new invoice — create_refund.php records which source(s) a
// refund was drawn from, FIFO. Lets the Ledger's refund rows (built further
// below) carry a reference back to the "Customer Credit Balance" row they
// belong to, since a refund's date won't generally sit next to that row
// (user request, 8 Nov 2026: refund harus tampil di Ledger betul, tapi
// perlu penanda jelas keterkaitannya karena urutan tanggal bisa jauh).
// Also grouped the OTHER way, by invoice_refund_id ($sourcesByRefund), for
// the refund ledger rows themselves to attach as 'credit_source_refs'.
$refundsBySource  = [];
$sourcesByRefund  = [];
$tableCheck = $lisani_conn->query(
    "SELECT COUNT(*) c FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'customer_credit_refunds'"
);
$hasCreditRefunds = $tableCheck && (int)($tableCheck->fetch_assoc()['c'] ?? 0) > 0;
if ($hasCreditRefunds) {
    $sql = "SELECT cr.id, cr.invoice_refund_id, cr.credit_source_id, cr.amount_refunded,
                   cr.remaining_after_amount, cr.created_at,
                   r.refund_date, r.method
            FROM customer_credit_refunds cr
            JOIN invoice_refunds r ON r.id = cr.invoice_refund_id
            WHERE 1=1" . ($hasFrom ? ' AND r.refund_date >= ?' : '') . ($hasTo ? ' AND r.refund_date <= ?' : '') . "
            ORDER BY r.refund_date ASC, cr.id ASC";
    $stmt = $lisani_conn->prepare($sql);
    if ($params) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $srcId    = (int) $row['credit_source_id'];
        $refundId = (int) $row['invoice_refund_id'];
        if (!isset($refundsBySource[$srcId])) {
            $refundsBySource[$srcId] = [];
        }
        $refundsBySource[$srcId][] = [
            'kind'                   => 'refunded',
            'date'                   => $row['refund_date'],
            'created_at'             => $row['created_at'],
            'amount'                 => (float) $row['amount_refunded'],
            'remaining_after_amount' => (float) $row['remaining_after_amount'],
            'method'                 => $row['method'],
        ];
        if (!isset($sourcesByRefund[$refundId])) {
            $sourcesByRefund[$refundId] = [];
        }
        $sourcesByRefund[$refundId][] = [
            'credit_source_id' => $srcId,
            'amount'           => (float) $row['amount_refunded'],
        ];
    }
    $stmt->close();
}

// ---------- Inflow: invoice payments ----------
// Excludes source_bank='CREDIT BALANCE' rows (create_order.php auto-apply,
// see "AUTO-APPLIED FROM CREDIT BALANCE"): that money isn't new cash in —
// it was already counted as an inflow the first time it reached a real
// account (the payment(s) traced in customer_credit_sources when the
// credit_balance was formed). Counting it again here double-counted every
// auto-applied invoice, inflating the ledger total and running balance past
// what any account actually received (user-reported bug, 1 Nov 2026).
$sql = "SELECT p.id, p.payment_date, p.destination_bank, p.destination_account_number,
               p.amount, p.notes, c.customer_name, i.invoice_number
        FROM invoice_payments p
        JOIN customers c ON c.id = p.customer_id
        JOIN invoices i ON i.id = p.invoice_id
        WHERE p.source_bank <> 'CREDIT BALANCE'" . ($hasFrom ? ' AND p.payment_date >= ?' : '') . ($hasTo ? ' AND p.payment_date <= ?' : '') .
        ($hasActivity ? ' AND EXISTS (
            SELECT 1 FROM logistic_movements lm
            JOIN logistics lg ON lg.id = lm.logistic_id
            WHERE lm.invoice_id = i.id AND lg.activity_id = ?
        )' : '') . "
        ORDER BY p.payment_date ASC, p.id ASC";
$paymentParams = $params;
$paymentTypes  = $types;
if ($hasActivity) { $paymentParams[] = $activityId; $paymentTypes .= 'i'; }
$stmt = $lisani_conn->prepare($sql);
if ($paymentParams) { $stmt->bind_param($paymentTypes, ...$paymentParams); }
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

    $paymentLabel = 'Payment — ' . $row['customer_name'] . ' (' . $row['invoice_number'] . ')';
    $credit = $creditByPayment[(int) $row['id']] ?? null;

    if ($credit === null) {
        // No credit was ever traced back to this payment — single row, same
        // as always.
        $ledger[] = [
            'date'       => $row['payment_date'],
            'type'       => 'inflow',
            'source'     => 'invoice_payment',
            'account_id' => $accId,
            'bank'       => $row['destination_bank'],
            'account_no' => $row['destination_account_number'],
            'amount'     => $amt,
            'original'   => null,
            'label'      => $paymentLabel,
            'category'   => 'SALES PAYMENT', // fixed label: every invoice payment is a sales payment
            'notes'      => $row['notes'],
            'ledger_note' => null, // semantic tag shown in the dedicated "Notes" column (distinct from the free-text 'notes' above); see "Credit Balance" rows below
            'group_id'   => null,  // visually clusters related rows; null = stands alone
            'ref_id'     => (int)$row['id'],
        ];
    } else {
        // Part (or all) of this payment became customer_credit_sources —
        // split into adjacent rows sharing one group_id. Sum of all rows'
        // 'amount' below still equals $amt exactly — nothing added, nothing
        // removed from what the account actually received.
        $groupId  = 'invoice_payment_' . (int) $row['id'];
        $remainder = round($amt - $credit['total'], 2);
        if ($remainder > 0.0001) {
            $ledger[] = [
                'date'       => $row['payment_date'],
                'type'       => 'inflow',
                'source'     => 'invoice_payment',
                'account_id' => $accId,
                'bank'       => $row['destination_bank'],
                'account_no' => $row['destination_account_number'],
                'amount'     => $remainder,
                'original'   => null,
                'label'      => $paymentLabel,
                'category'   => 'SALES PAYMENT',
                'notes'      => $row['notes'],
                'ledger_note' => null,
                'group_id'   => $groupId,
                // Plain int, same value this row's ref_id always had before
                // any split existed — keeps its sort position exactly where
                // an unsplit payment row would land (fixes ordering bug
                // found by user, 6 Nov 2026: a string ref_id here sorted
                // after other same-day rows with plain numeric ref_ids,
                // since strcmp('1_...', '12') ranks '_' above '2').
                'ref_id'     => (int) $row['id'],
            ];
        }
        foreach ($credit['rows'] as $ccsRow) {
            $ccsAmt = (float) $ccsRow['amount'];
            if ($ccsAmt <= 0.0001) {
                continue;
            }
            // Same account the payment itself landed in, per customer_credit_sources'
            // own fields — not assumed from the payment row, though for a
            // single payment they'll always match (that's where it was traced from).
            $ccsAccId = $ccsRow['source_bank']
                ? resolve_account_id($accountsByKey, (string) $ccsRow['source_bank'], (string) $ccsRow['source_account_number'])
                : null;
            // This credit source's own usage — applications (used toward a
            // later invoice) AND refunds (paid back as cash) — merged into
            // one chronological list by credit_source_id. Both tables
            // decrement the very same customer_credit_sources.remaining_amount
            // counter, so sorting by created_at gives one real timeline
            // regardless of which table each entry came from. The frontend
            // shows this in a click-through detail panel instead of
            // separate rows per application/refund (user, 8 Nov 2026).
            $srcId = (int) $ccsRow['id'];
            $usage = array_merge($appsBySource[$srcId] ?? [], $refundsBySource[$srcId] ?? []);
            usort($usage, function ($a, $b) { return strcmp($a['created_at'], $b['created_at']); });
            // Authoritative, live figure straight from customer_credit_sources
            // — not inferred from the last usage row's snapshot, so it's
            // correct even if applications/refunds ever land out of order.
            $remainingAmt = (float) $ccsRow['remaining_amount'];
            $ledger[] = [
                'date'       => $row['payment_date'],
                'type'       => 'inflow',
                'source'     => 'invoice_payment_credit',
                'account_id' => $ccsAccId,
                'bank'       => $ccsRow['source_bank'],
                'account_no' => $ccsRow['source_account_number'],
                'amount'     => round($ccsAmt, 2),
                // Same reason as the refund fix above: ledger_note set ->
                // frontend reads display_amount, not amount. This row's
                // money is real, so mirror it.
                'display_amount' => round($ccsAmt, 2),
                'original'   => null,
                'label'      => 'Customer Credit Balance — ' . $paymentLabel,
                'category'   => null,
                'notes'      => null,
                'ledger_note' => 'Credit Balance',
                'credit_source_id' => $srcId,
                'usage'             => $usage,
                'remaining_amount'  => round($remainingAmt, 2),
                'group_id'   => $groupId,
                // Same plain int as the remainder row above (and as this
                // payment's own ref_id pre-split) rather than a distinguishing
                // compound string — usort() is stable (PHP 8+), so ties on
                // (date, ref_id) keep array push order: remainder first (if
                // any), then each credit slice in order. That keeps this
                // payment's split rows adjacent to each other AND sorted
                // relative to other same-day rows exactly where the single,
                // unsplit row would have sorted (fixes ordering bug found by
                // user, 6 Nov 2026 — a distinguishing string suffix here sorted
                // after other same-day plain-int ref_ids via strcmp).
                'ref_id'     => (int) $row['id'],
            ];
        }
    }
}
$stmt->close();

// ---------- Outflow: refunds ----------
// Intentionally NOT filtered by activity_id — see header comment: invoice_refunds
// has no invoice_id/activity_id, so it cannot be traced to one activity code.
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
        // The frontend's Amount column reads display_amount instead of
        // amount whenever ledger_note is set (a convention from the
        // Credit Balance rows, where amount is deliberately 0) — this row's
        // amount is real, so display_amount must mirror it or the Amount
        // column renders Rp 0 (bug found by user, 6 Nov 2026).
        'display_amount' => $amt,
        'original'   => null,
        'label'      => 'Refund — ' . $row['customer_name'] . ($row['method'] ? ' (' . $row['method'] . ')' : ''),
        'category'   => null,
        'notes'      => $row['notes'],
        // Shown in the dedicated Notes column (same slot the Credit Balance
        // rows use) — a refund outflow always gets this tag regardless of
        // whether the user also typed a free-text note (user request, 6 Nov 2026).
        'ledger_note' => 'Refund' . ($row['method'] ? ' (' . $row['method'] . ')' : ''),
        // Which credit_source(s) this refund was drawn from (FIFO, see
        // customer_credit_refunds / create_refund.php) — empty when this
        // refund predates that tracking, or wasn't from credit at all. The
        // frontend uses this to show a small reference chip linking back to
        // the originating "Customer Credit Balance" row, since the two
        // won't generally sit next to each other by date (user, 8 Nov 2026).
        'credit_source_refs' => $sourcesByRefund[(int) $row['id']] ?? [],
        'group_id'   => null,
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

// Sort key is cast to string throughout: $ref_id is an int for every
// pre-existing row but a string ('credit_app_remaining_5' etc.) for the new
// credit-usage rows above, and PHP 8's <=> compares int<=>non-numeric-string
// unreliably. String comparison keeps a stable, predictable order and still
// sorts plain ints correctly (numeric strings compare the same as the ints
// they came from). The "remaining" row's ref_id has a literal '_0_' and
// "used" a literal '_1_' (not relying on the words' own spelling), so within
// one group_id (same date) "remaining" always sorts directly before "used".
usort($ledger, function ($a, $b) {
    return $a['date'] <=> $b['date'] ?: strcmp((string) $a['ref_id'], (string) $b['ref_id']);
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