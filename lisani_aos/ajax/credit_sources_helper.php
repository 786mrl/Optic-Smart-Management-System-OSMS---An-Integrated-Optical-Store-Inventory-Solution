<?php
// credit_sources_helper.php
//
// Shared FIFO logic for spending a customer's credit_balance down to the
// underlying per-account rows in customer_credit_sources. Every path that
// draws on a customer's credit (create_order.php auto-apply, create_refund.php,
// create_disbursement.php) calls the same two functions here so they all lock
// and spend the exact same rows — no double-spend across the three paths.
//
// Caller contract (see "Revised 1 Nov 2026" note in create_order.php):
//   $lockedSources = ccs_lock_sources($conn, $customerId);
//   $slices        = ccs_spend_fifo($conn, $lockedSources, $amountToSpend);
//
// The caller is expected to already be inside its own transaction
// (mysqli->begin_transaction()) before calling ccs_lock_sources() — this file
// never starts or commits a transaction itself, it only issues the
// SELECT ... FOR UPDATE and the UPDATE statements inside whatever transaction
// the caller already opened.

// ---- ccs_lock_sources ----
// Locks (FOR UPDATE) every row of customer_credit_sources for this customer
// that still has balance left (remaining_amount > 0), oldest id first (FIFO).
// Returns an array of ['id' => int, 'remaining_amount' => float], in the
// order they'll be spent.
function ccs_lock_sources(mysqli $conn, int $customerId): array
{
    $st = $conn->prepare(
        'SELECT id, remaining_amount
           FROM customer_credit_sources
          WHERE customer_id = ? AND remaining_amount > 0
          ORDER BY id ASC
          FOR UPDATE'
    );
    $st->bind_param('i', $customerId);
    $st->execute();
    $res = $st->get_result();

    $sources = [];
    while ($row = $res->fetch_assoc()) {
        $sources[] = [
            'id'               => (int) $row['id'],
            'remaining_amount' => (float) $row['remaining_amount'],
        ];
    }
    $st->close();

    return $sources;
}

// ---- trace_credit_to_real_payments ----
// Moved here from create_return.php (1 Nov 2026) so every path that can
// create a customer_credit_sources row — create_return.php, cancel_booking.php,
// create_price_adjustment.php — traces back to the REAL bank account(s) the
// same way. Given one invoice and a credit amount to trace, walks that
// invoice's own invoice_payments (oldest first, skipping rows with
// source_bank='CREDIT BALANCE' — those aren't a real transfer, just a prior
// auto-apply) and returns the slice(s) of real account(s) that cover it.
// If the invoice's real payments don't fully cover $amount (e.g. it was
// itself partly settled via auto-applied credit), the untraced remainder
// comes back as one more slice with source_bank/account = NULL — asal tidak
// diketahui, never silently dropped.
//
// Fixed 6 Nov 2026 (user-reported: account number missing on Credit Balance
// ledger rows): this used to read invoice_payments.source_bank /
// source_account_name — but "source" on invoice_payments means which bank
// the CUSTOMER paid FROM (no account number is even captured for it, by
// design). What this function actually needs is which of OUR OWN company
// accounts the money landed in — that's invoice_payments.destination_bank /
// destination_account_number / destination_account_name, the same fields
// get_finance_report.php itself matches against bank_accounts.json for
// every other inflow row. customer_credit_sources.source_bank/source_account_number
// name the column from the CREDIT's point of view ("which of our accounts is
// this credit sitting in"), which is the invoice's destination, not its source.
//
// Returns a list of {source_bank, source_account_number, source_account_name,
// amount, origin_invoice_payment_id} rows that sum to $amount.
function trace_credit_to_real_payments(mysqli $conn, int $sourceInvoiceId, float $amount): array
{
    $out = [];
    if ($amount <= 0.0001 || $sourceInvoiceId <= 0) {
        return $out;
    }

    $st = $conn->prepare(
        "SELECT id, amount, destination_bank, destination_account_number, destination_account_name
         FROM invoice_payments
         WHERE invoice_id = ? AND source_bank <> 'CREDIT BALANCE'
         ORDER BY id ASC"
    );
    $st->bind_param('i', $sourceInvoiceId);
    $st->execute();
    $res = $st->get_result();
    $payments = [];
    while ($row = $res->fetch_assoc()) {
        $payments[] = $row;
    }
    $st->close();

    $remaining = round($amount, 2);
    foreach ($payments as $p) {
        if ($remaining <= 0.0001) {
            break;
        }
        $take = min($remaining, (float) $p['amount']);
        if ($take <= 0.0001) {
            continue;
        }
        $out[] = [
            'source_bank'               => $p['destination_bank'] !== '' ? $p['destination_bank'] : null,
            'source_account_number'     => $p['destination_account_number'] !== '' ? $p['destination_account_number'] : null,
            'source_account_name'       => $p['destination_account_name'] !== '' ? $p['destination_account_name'] : null,
            'amount'                    => round($take, 2),
            'origin_invoice_payment_id' => (int) $p['id'],
        ];
        $remaining = round($remaining - $take, 2);
    }

    if ($remaining > 0.0001) {
        $out[] = [
            'source_bank'               => null,
            'source_account_number'     => null,
            'source_account_name'       => null,
            'amount'                    => $remaining,
            'origin_invoice_payment_id' => null,
        ];
    }

    return $out;
}

// ---- ccs_reverse_applications_for_invoice ----
// Reverses credit_balance applications that were made FOR this invoice via
// the "CREDIT BALANCE" auto-apply/apply-on-booking marker — i.e. undoes
// exactly the slices ccs_spend_fifo() created against customer_credit_sources,
// instead of treating the money as a fresh, untraceable inflow (which is what
// trace_credit_to_real_payments() does, and is the wrong tool here — see
// cancel_booking.php, "Revised 9 Nov 2026 (credit reversal, not re-trace)").
//
// Added 9 Nov 2026 (user-reported: cancelling a booking that had credit
// applied to it created a brand-new customer_credit_sources row instead of
// giving the money back to the source(s) it actually came from — showed up
// as a spurious extra "Customer Credit Balance" row on the Report Ledger).
//
// Walks this invoice's own invoice_payments rows with source_bank =
// 'CREDIT BALANCE' (newest payment first — if more than one credit-apply
// payment exists on the same invoice, the most recently applied one is the
// most natural to unwind first), and for each one's customer_credit_applications
// rows (also newest first), adds amount_used back onto that same
// customer_credit_sources.remaining_amount row — capped at $amount total
// reversed across all of them. A partial reversal (amount smaller than one
// application) splits that one application's amount_used itself: the
// portion being reversed is subtracted from amount_used (zeroed rows are
// left as 0.00, not deleted, so the audit trail of "this invoice_payment
// drew from this source" still exists) and the matching remaining_amount is
// increased by the same portion.
//
// Returns the slices actually reversed: [{credit_source_id, amount_reversed}],
// summing to at most $amount (less if this invoice's applications don't
// cover that much — same "display-only gap" caveat as ccs_spend_fifo()).
function ccs_reverse_applications_for_invoice(mysqli $conn, int $invoiceId, float $amount): array
{
    $reversed = [];
    $left = round($amount, 2);
    if ($left <= 0.0001 || $invoiceId <= 0) {
        return $reversed;
    }

    $st = $conn->prepare(
        "SELECT a.id, a.credit_source_id, a.amount_used
           FROM customer_credit_applications a
           JOIN invoice_payments p ON p.id = a.invoice_payment_id
          WHERE p.invoice_id = ? AND p.source_bank = 'CREDIT BALANCE'
          ORDER BY p.id DESC, a.id DESC
          FOR UPDATE"
    );
    $st->bind_param('i', $invoiceId);
    $st->execute();
    $res = $st->get_result();
    $apps = [];
    while ($row = $res->fetch_assoc()) {
        $apps[] = $row;
    }
    $st->close();

    if (!$apps) {
        return $reversed;
    }

    $srcIds = array_unique(array_map(function ($a) { return (int) $a['credit_source_id']; }, $apps));
    $marks = implode(',', array_fill(0, count($srcIds), '?'));
    $st = $conn->prepare(
        "SELECT id, remaining_amount FROM customer_credit_sources WHERE id IN ($marks) FOR UPDATE"
    );
    $st->bind_param(str_repeat('i', count($srcIds)), ...$srcIds);
    $st->execute();
    $res = $st->get_result();
    $sourceRemaining = [];
    while ($row = $res->fetch_assoc()) {
        $sourceRemaining[(int) $row['id']] = (float) $row['remaining_amount'];
    }
    $st->close();

    $updApp = $conn->prepare('UPDATE customer_credit_applications SET amount_used = ? WHERE id = ?');
    $updSrc = $conn->prepare('UPDATE customer_credit_sources SET remaining_amount = ? WHERE id = ?');
    $takenBySource = [];

    foreach ($apps as $app) {
        if ($left <= 0.0001) {
            break;
        }
        $appId   = (int) $app['id'];
        $srcId   = (int) $app['credit_source_id'];
        $usedNow = (float) $app['amount_used'];
        if ($usedNow <= 0.0001) {
            continue;
        }
        $take = min($usedNow, $left);

        $newUsed = round($usedNow - $take, 2);
        $newUsedStr = number_format($newUsed, 2, '.', '');
        $updApp->bind_param('si', $newUsedStr, $appId);
        $updApp->execute();

        $newRemaining = round(($sourceRemaining[$srcId] ?? 0.0) + $take, 2);
        $newRemainingStr = number_format($newRemaining, 2, '.', '');
        $updSrc->bind_param('si', $newRemainingStr, $srcId);
        $updSrc->execute();
        $sourceRemaining[$srcId] = $newRemaining;

        $takenBySource[$srcId] = round(($takenBySource[$srcId] ?? 0.0) + $take, 2);
        $left = round($left - $take, 2);
    }

    $updApp->close();
    $updSrc->close();

    foreach ($takenBySource as $srcId => $amt) {
        $reversed[] = ['credit_source_id' => $srcId, 'amount_reversed' => $amt];
    }

    return $reversed;
}

// ---- ccs_spend_fifo ----
// Spends $amountToSpend across $lockedSources (as returned by
// ccs_lock_sources(), already FIFO-ordered), oldest source first. Writes the
// new remaining_amount for every source it touches. Returns the slices
// actually taken, one entry per source touched:
//   ['credit_source_id' => int, 'amount_used' => string, 'remaining_after_amount' => string]
// (amount_used / remaining_after_amount are money-formatted strings, "0.00"
// style, matching the bind_param('iiss', ...) the caller uses for
// customer_credit_applications.)
//
// If $amountToSpend exceeds the sum of all sources' remaining_amount, only
// what's actually available is spent — the caller (create_order.php) already
// notes this as a known display-only gap, not fixed silently here.
function ccs_spend_fifo(mysqli $conn, array $lockedSources, float $amountToSpend): array
{
    $slices = [];
    $left   = round($amountToSpend, 2);

    if ($left <= 0.0001) {
        return $slices;
    }

    $upd = $conn->prepare(
        'UPDATE customer_credit_sources SET remaining_amount = ? WHERE id = ?'
    );

    foreach ($lockedSources as $source) {
        if ($left <= 0.0001) {
            break;
        }

        $available = $source['remaining_amount'];
        if ($available <= 0.0001) {
            continue;
        }

        $take           = min($available, $left);
        $remainingAfter = round($available - $take, 2);

        $remainingStr = number_format($remainingAfter, 2, '.', '');
        $upd->bind_param('si', $remainingStr, $source['id']);
        $upd->execute();

        $slices[] = [
            'credit_source_id'       => $source['id'],
            'amount_used'            => number_format($take, 2, '.', ''),
            'remaining_after_amount' => $remainingStr,
        ];

        $left = round($left - $take, 2);
    }

    $upd->close();

    return $slices;
}