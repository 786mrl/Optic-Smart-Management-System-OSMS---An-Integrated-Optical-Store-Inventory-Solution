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
// Returns a list of {source_bank, source_account_number, source_account_name,
// amount, origin_invoice_payment_id} rows that sum to $amount.
function trace_credit_to_real_payments(mysqli $conn, int $sourceInvoiceId, float $amount): array
{
    $out = [];
    if ($amount <= 0.0001 || $sourceInvoiceId <= 0) {
        return $out;
    }

    // invoice_payments has no source_account_number column (only
    // source_bank / source_account_name — the source account's NUMBER was
    // never captured there, unlike destination_account_number). That slot
    // always comes back null below; not a bug, the data just isn't there.
    $st = $conn->prepare(
        "SELECT id, amount, source_bank, source_account_name
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
            'source_bank'               => $p['source_bank'] !== '' ? $p['source_bank'] : null,
            'source_account_number'     => null, // not captured in invoice_payments — see query comment above
            'source_account_name'       => $p['source_account_name'] !== '' ? $p['source_account_name'] : null,
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