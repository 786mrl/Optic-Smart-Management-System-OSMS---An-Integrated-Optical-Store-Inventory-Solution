<?php
// lisani_aos/ajax/list_return_price_options.php
// Sales Transaction > Returns tab: for one (customer, product) pair, returns
// every pickup (movement_type='out') that still has stock left to return,
// so the UI can let the user allocate a return across one or more specific
// pickups instead of a single aggregate number.
//
// Revised 22 Sep 2026 (lot-based returns — see PROJECT_NOTES.md, "Redesign
// Returns: alokasi per-lot"). Each pickup's remaining = its own qty minus
// whatever has already been returned AGAINST THAT SPECIFIC PICKUP
// (logistic_movements.source_movement_id), not a customer/product-wide
// aggregate like before.
//
// GET: customer_id, logistic_id
// Response contract: { ok, message?, data: { available, total_taken,
//   total_returned, movements[]: { movement_id, movement_date, qty, price,
//   remaining, disabled, label } } }
// movements[] is sorted NEWEST first (the fly window default), each row
// also carries `label` ("Pickup #2 of the day") when more than one pickup
// shares the same movement_date, so the UI can disambiguate.

ini_set('display_errors', '0');
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function aos_json(array $payload): void
{
    $noise = ob_get_clean();
    if ($noise !== false && trim($noise) !== '') {
        error_log('list_return_price_options.php stray output: ' . $noise);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
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

require_once dirname(__DIR__) . '/db_config.php'; // provides $lisani_conn (mysqli)

$customerId = (int) ($_GET['customer_id'] ?? 0);
$logisticId = (int) ($_GET['logistic_id'] ?? 0);

if ($customerId <= 0 || $logisticId <= 0) {
    aos_fail('Customer and product are required.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // ---- Totals, kept for the top-line "Available to return: X" note and
    //      the "never taken at all" message (unchanged behaviour). ----
    $st = $lisani_conn->prepare(
        "SELECT COALESCE(SUM(CASE WHEN movement_type = 'out' THEN qty_primary_package ELSE 0 END), 0) AS total_taken,
                COALESCE(SUM(CASE WHEN movement_type = 'in'  THEN qty_primary_package ELSE 0 END), 0) AS total_returned
         FROM logistic_movements
         WHERE customer_id = ? AND logistic_id = ?"
    );
    $st->bind_param('ii', $customerId, $logisticId);
    $st->execute();
    $totals = $st->get_result()->fetch_assoc();
    $st->close();

    $totalTaken    = (float) $totals['total_taken'];
    $totalReturned = (float) $totals['total_returned'];

    // ---- Every pickup (movement_type='out') for this (customer, product),
    //      each with its OWN remaining (qty minus returns allocated to it
    //      specifically via source_movement_id). Fetched oldest-first here
    //      so "pickup #N of the day" numbering is stable and cheap to
    //      compute in PHP; re-sorted newest-first before it's sent out. ----
    $st = $lisani_conn->prepare(
        "SELECT o.id AS movement_id, o.movement_date, o.created_at,
                o.qty_primary_package AS qty, o.price,
                o.qty_primary_package - COALESCE(SUM(
                    CASE WHEN i.movement_type = 'in' THEN i.qty_primary_package ELSE 0 END
                ), 0) AS remaining
         FROM logistic_movements o
         LEFT JOIN logistic_movements i ON i.source_movement_id = o.id AND i.movement_type = 'in'
         WHERE o.customer_id = ? AND o.logistic_id = ? AND o.movement_type = 'out'
         GROUP BY o.id, o.movement_date, o.created_at, o.qty_primary_package, o.price
         ORDER BY o.movement_date ASC, o.created_at ASC, o.id ASC"
    );
    $st->bind_param('ii', $customerId, $logisticId);
    $st->execute();

    $rows = [];
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $st->close();

    // "Pickup #N of the day" — only assign/show a label when a date has
    // more than one pickup, so the common case (one pickup per day) stays
    // unlabeled.
    $countPerDate = [];
    foreach ($rows as $row) {
        $d = $row['movement_date'];
        $countPerDate[$d] = ($countPerDate[$d] ?? 0) + 1;
    }
    $seenPerDate = [];
    $movements = [];
    foreach ($rows as $row) {
        $d = $row['movement_date'];
        $seenPerDate[$d] = ($seenPerDate[$d] ?? 0) + 1;
        $remaining = round((float) $row['remaining'], 2);
        $movements[] = [
            'movement_id'   => (int) $row['movement_id'],
            'movement_date' => $row['movement_date'],
            'qty'           => (float) $row['qty'],
            'price'         => (float) $row['price'],
            'remaining'     => $remaining,
            'disabled'      => $remaining <= 0.0001,
            'label'         => $countPerDate[$d] > 1 ? ('Pickup #' . $seenPerDate[$d] . ' of the day') : null,
        ];
    }

    // Newest first for display (user decision, 22 Sep 2026).
    $movements = array_reverse($movements);

    $available = round($totalTaken - $totalReturned, 2);

    aos_json([
        'ok'   => true,
        'data' => [
            'available'      => $available,
            'total_taken'    => $totalTaken,
            'total_returned' => $totalReturned,
            'movements'      => $movements,
        ],
    ]);
} catch (Throwable $e) {
    error_log('list_return_price_options.php: ' . $e->getMessage());
    aos_fail('Failed to load pickup history.');
}