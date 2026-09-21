<?php
// lisani_aos/ajax/_order_patterns.php
//
// Shared helpers for Sales Transaction orders pasted from WhatsApp:
//   - aos_norm_product_text()   : canonical form of a product wording
//   - aos_parse_order_message() : pure parser (no DB, no session) -> driver,
//                                 police number, product lines + quantities
//   - aos_load_products()       : logistics JOIN activities (valid products)
//   - aos_load_alias_map()      : reads json_file/order_patterns/{activity_id}.json
//   - aos_save_alias()          : appends one alias to a product's JSON file
//
// Pattern file format (one file per activity_id, because activity code numbers
// reset per department + year):
//   { "activity_id": 1, "aliases": ["SMALL DEGLET", "DEGLET KECIL"] }
//
// Aliases are the product wording WITHOUT the quantity, stored in the
// normalized uppercase form produced by aos_norm_product_text().
//
// Usage: require_once __DIR__ . '/_order_patterns.php';

if (!defined('AOS_ORDER_PATTERNS_DIR')) {
    define('AOS_ORDER_PATTERNS_DIR', dirname(__DIR__) . '/json_file/order_patterns');
}

// ---------------------------------------------------------------------------
// Normalization
// ---------------------------------------------------------------------------

// "Tunis tangkai 500 gram" -> "TUNIS TANGKAI 500GR"
// Uppercase, punctuation removed, weights glued to their number, spaces collapsed.
function aos_norm_product_text(string $raw): string
{
    $s = mb_strtoupper($raw, 'UTF-8');
    // decimal comma -> dot ("1,5 kg" -> "1.5 kg")
    $s = preg_replace('/(\d)[.,](?=\d)/u', '$1.', $s);
    // anything that is not a letter, digit, space or dot -> space
    $s = preg_replace('/[^\p{L}\p{N}\s.]/u', ' ', $s);
    // dots that are not a decimal point -> space
    $s = preg_replace('/(?<!\d)\.|\.(?!\d)/u', ' ', $s);
    // weight units glued to the number: 500 GRAM / 500 G -> 500GR, 2 KILO -> 2KG
    $s = preg_replace('/(\d)\s*(?:GRAM|GR|G)(?![\p{L}\p{N}])/u', '$1GR', $s);
    $s = preg_replace('/(\d)\s*(?:KILOGRAM|KILO|KG)(?![\p{L}\p{N}])/u', '$1KG', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

// ---------------------------------------------------------------------------
// Line-level extractors (all expect an UPPERCASE line)
// ---------------------------------------------------------------------------

// Finds the carton quantity in a line.
// Returns [?int $qty, string $restOfLine].
//   "TUNIS TANGKAI 500GR 30DUS" -> [30, "TUNIS TANGKAI 500GR  "]
//   "20 AJWA"                   -> [20, " AJWA"]
// Weights ("500GR", "2 KG") are never taken as quantity.
function aos_extract_qty(string $line): array
{
    // 1) A number next to a carton word: "30DUS", "30 dus", "30 karton".
    if (preg_match(
        '/(?<![\d.,])(\d{1,5})\s*(?:DUS|KARDUS|KARTON|CTN|CARTON|BOX|BX)(?![\p{L}\p{N}])/u',
        $line, $m, PREG_OFFSET_CAPTURE
    )) {
        $qty  = (int) $m[1][0];
        $rest = substr($line, 0, $m[0][1]) . ' ' . substr($line, $m[0][1] + strlen($m[0][0]));
        return [$qty >= 1 ? $qty : null, $rest];
    }

    // 2) Otherwise a bare number that is not a weight/volume: leading one wins,
    //    else the last one ("20 AJWA", "SMALL DEGLET 30").
    preg_match_all(
        '/(?<![\d.,])(\d{1,5})(?![\d.,]*\d)(?!\s*(?:GRAM|GR|G|KILOGRAM|KILO|KG|ML|LTR|L)(?![\p{L}\p{N}]))/u',
        $line, $mm, PREG_OFFSET_CAPTURE
    );
    if (empty($mm[1])) {
        return [null, $line];
    }

    $pick  = end($mm[1]);
    $first = $mm[1][0];
    if ($first[1] === strlen($line) - strlen(ltrim($line))) {
        $pick = $first; // number sits at the very start of the line
    }

    $qty  = (int) $pick[0];
    $rest = substr($line, 0, $pick[1]) . ' ' . substr($line, $pick[1] + strlen($pick[0]));
    return [$qty >= 1 ? $qty : null, $rest];
}

// Indonesian style plate: 1-2 letters, 1-4 digits, 1-3 letters ("BL 8392 N").
// Returns [?string $plate, string $restOfLine].
function aos_extract_plate(string $line): array
{
    if (!preg_match(
        '/(?<![\p{L}\p{N}])([A-Z]{1,2})\s?(\d{1,4})\s?([A-Z]{1,3})(?![\p{L}\p{N}])/u',
        $line, $m, PREG_OFFSET_CAPTURE
    )) {
        return [null, $line];
    }

    // "20 KG"-style text is a weight, not a plate suffix.
    if (in_array($m[3][0], ['KG', 'GR', 'G', 'ML', 'L', 'DUS', 'CTN', 'BOX', 'BX'], true)) {
        return [null, $line];
    }

    $plate = $m[1][0] . ' ' . $m[2][0] . ' ' . $m[3][0];
    $rest  = substr($line, 0, $m[0][1]) . ' ' . substr($line, $m[0][1] + strlen($m[0][0]));
    return [$plate, $rest];
}

// Removes labels/punctuation left around a plate ("NOPOL :", "PLAT NO.").
function aos_strip_plate_labels(string $text): string
{
    $text = preg_replace('/\b(?:NOMOR\s+POLISI|NO\.?\s*POLISI|NO\.?\s*POL|NOPOL|PLAT\s+NOMOR|PLAT\s+NO\.?|PLAT)\b/u', ' ', $text);
    $text = preg_replace('/[\s:\-,\/.]+/u', ' ', $text);
    return trim($text);
}

// Driver line with a title or label: "PAK FADLUN", "BP. FADLUN", "DRIVER: FADLUN",
// "NAMA: RENDI". Never contains digits.
// The title is kept as written ("PAK FADLUN") because that is how the customer knows him.
function aos_extract_driver(string $text): ?string
{
    $text = trim($text);
    if ($text === '' || preg_match('/\d/u', $text)) {
        return null;
    }

    if (preg_match('/^(?:DRIVER|SOPIR|SUPIR|NAMA)\s*[:\-]?\s*(\p{L}[\p{L}\s.\']{0,40})$/u', $text, $m)) {
        return trim(preg_replace('/\s+/u', ' ', $m[1]));
    }

    if (preg_match('/^((?:PAK|BAPAK|BPK|BP|PK|MAS|BANG|ABANG|OM|KAK|KOH|HAJI|BU|IBU)\.?\s+\p{L}[\p{L}\s.\']{0,40})$/u', $text, $m)) {
        return trim(preg_replace('/\s+/u', ' ', str_replace('.', ' ', $m[1])));
    }

    return null;
}

// Words that mean a text-only line is chatter, not a driver name.
function aos_chatter_words(): array
{
    return [
        'ASSALAMUALAIKUM', 'ASSALAMUALAIKUMWARAHMATULLAHI', 'WAALAIKUMSALAM', 'SALAM', 'HALO',
        'HALLO', 'HAI', 'HI', 'PAGI', 'SIANG', 'SORE', 'MALAM', 'SELAMAT', 'TERIMA', 'KASIH',
        'MAKASIH', 'TERIMAKASIH', 'THANKS', 'THANK', 'THX', 'TOLONG', 'MOHON', 'MINTA', 'OK',
        'OKE', 'OKEY', 'SIAP', 'PLAT', 'NOPOL', 'NOMOR', 'POLISI', 'PESANAN', 'PESAN', 'ORDER',
        'ORDERAN', 'BERIKUT', 'INFO', 'AMBIL', 'KIRIM', 'BARANG', 'LAGI', 'YANG', 'MAU', 'UNTUK',
        'DAN', 'DARI', 'KE', 'SOPIR', 'SUPIR', 'DRIVER', 'TOTAL', 'JUMLAH', 'MOBIL', 'TRUK',
    ];
}

// A text-only UPPERCASE line that could be a person's name: no digits, 1-3 words,
// letters only, and none of the chatter words above. Used to find a driver written
// WITHOUT a title ("RENDI") — see the second pass in aos_parse_order_message().
function aos_looks_like_name(string $text): bool
{
    $text = trim($text);
    if ($text === '' || preg_match('/\d/u', $text)) {
        return false;
    }
    if (!preg_match('/^\p{L}[\p{L}\s.\']{0,39}$/u', $text)) {
        return false;
    }
    $words = preg_split('/[\s.\']+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) < 1 || count($words) > 3) {
        return false;
    }
    if (mb_strlen(implode('', $words), 'UTF-8') < 2) {
        return false;
    }
    $chatter = aos_chatter_words();
    foreach ($words as $w) {
        if (in_array($w, $chatter, true)) {
            return false;
        }
    }
    return true;
}

// ---------------------------------------------------------------------------
// Pure parser
// ---------------------------------------------------------------------------

// $aliasMap: [ normalized alias => activity_id ]
//
// LINE ORDER DOES NOT MATTER. Driver, police number and product lines may appear in
// any order, with or without blank lines between them. Two passes:
//   1) every line is classified on its own: police number, titled driver, product
//      line (has a quantity or a known alias), or a text-only line kept as a
//      driver-name CANDIDATE;
//   2) if no driver was found yet, the driver is the candidate written directly
//      before (preferred) or after the police-number line, or - when there is no
//      such neighbour - the only candidate in the whole message. Anything else is
//      never guessed; it is reported in ignored[] and the field stays editable.
//
// Returns:
//   driver_name   ?string
//   police_number ?string
//   items         [ { line, qty, product_text, status, activity_id } ]
//       status: matched | unknown_product | missing_qty | missing_product
//   ignored       [ { line, reason } ]   (chatter, duplicates, lines with no quantity)
//
// Lines are split on newline and ";". Product matching is EXACT on the normalized
// wording - a wrong guess would put a wrong price on an invoice, so anything not
// seen before is reported as unknown_product and asked about.
function aos_parse_order_message(string $message, array $aliasMap): array
{
    $driverName   = null;
    $policeNumber = null;
    $items        = [];
    $ignored      = [];

    // Non-empty lines only; adjacency below is measured between these.
    $lines = [];
    foreach (preg_split('/\r\n|\r|\n|;/u', $message) as $rawLine) {
        $original = trim($rawLine);
        if ($original !== '') {
            $lines[] = ['original' => $original, 'upper' => mb_strtoupper($original, 'UTF-8')];
        }
    }

    $plateIndex = null; // index in $lines of the line the police number came from
    $candidates = [];   // index in $lines => ['original' => ..., 'upper' => ...]

    // ---------- Pass 1: classify every line ----------
    foreach ($lines as $idx => $ln) {
        $original = $ln['original'];
        $line     = $ln['upper'];

        // --- police number (alone, or together with the driver) ---
        [$plate, $afterPlate] = aos_extract_plate($line);
        if ($plate !== null) {
            $leftover = aos_strip_plate_labels($afterPlate);
            if ($leftover === '') {
                if ($policeNumber === null) {
                    $policeNumber = $plate;
                    $plateIndex   = $idx;
                } else {
                    $ignored[] = ['line' => $original, 'reason' => 'duplicate_police_number'];
                }
                continue;
            }
            // Something else on the plate's own line: a titled name, or a plain name.
            $drv = aos_extract_driver($leftover);
            if ($drv === null && aos_looks_like_name($leftover)) {
                $drv = $leftover;
            }
            if ($drv !== null) {
                if ($policeNumber === null) {
                    $policeNumber = $plate;
                    $plateIndex   = $idx;
                }
                if ($driverName === null) {
                    $driverName = $drv;
                }
                continue;
            }
            // Not a plate line after all: fall through and treat it as a product line.
        }

        // --- driver written with a title or label ---
        $drv = aos_extract_driver($line);
        if ($drv !== null) {
            if ($driverName === null) {
                $driverName = $drv;
            } else {
                $ignored[] = ['line' => $original, 'reason' => 'duplicate_driver'];
            }
            continue;
        }

        // --- product + quantity ---
        [$qty, $rest] = aos_extract_qty($line);
        $key = aos_norm_product_text($rest);

        if ($key === '' && $qty === null) {
            $ignored[] = ['line' => $original, 'reason' => 'empty'];
            continue;
        }

        if ($key === '') {
            $items[] = [
                'line' => $original, 'qty' => $qty, 'product_text' => '',
                'status' => 'missing_product', 'activity_id' => null,
            ];
            continue;
        }

        if (isset($aliasMap[$key])) {
            $items[] = [
                'line' => $original, 'qty' => $qty, 'product_text' => $key,
                'status' => $qty === null ? 'missing_qty' : 'matched',
                'activity_id' => (int) $aliasMap[$key],
            ];
            continue;
        }

        if ($qty !== null) {
            $items[] = [
                'line' => $original, 'qty' => $qty, 'product_text' => $key,
                'status' => 'unknown_product', 'activity_id' => null,
            ];
            continue;
        }

        // No quantity and not a known alias.
        if (aos_looks_like_name($line)) {
            $candidates[$idx] = $ln; // maybe the driver; decided in pass 2
            continue;
        }
        $ignored[] = [
            'line'   => $original,
            'reason' => preg_match('/\d/u', $line) ? 'no_quantity' : 'unrecognized_text',
        ];
    }

    // ---------- Pass 2: driver without a title ----------
    if ($driverName === null && $candidates) {
        $pick = null;
        if ($plateIndex !== null) {
            if (isset($candidates[$plateIndex - 1])) {
                $pick = $plateIndex - 1;
            } elseif (isset($candidates[$plateIndex + 1])) {
                $pick = $plateIndex + 1;
            }
        }
        if ($pick === null && count($candidates) === 1) {
            $keys = array_keys($candidates);
            $pick = $keys[0];
        }
        if ($pick !== null) {
            $driverName = $candidates[$pick]['upper'];
            unset($candidates[$pick]);
        }
    }
    foreach ($candidates as $ln) {
        $ignored[] = ['line' => $ln['original'], 'reason' => 'unrecognized_text'];
    }

    return [
        'driver_name'   => $driverName,
        'police_number' => $policeNumber,
        'items'         => $items,
        'ignored'       => $ignored,
    ];
}

// ---------------------------------------------------------------------------
// DB + JSON storage
// ---------------------------------------------------------------------------

// Every product that can be ordered: one row per `logistics` record.
// Returns [ activity_id => [logistic_id, activity_id, activity_name, unit_label, remaining_qty] ].
function aos_load_products(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT l.id AS logistic_id, l.activity_id, a.activity_name, l.primary_unit_label,
                l.remaining_primary_qty
         FROM logistics l
         JOIN activities a ON a.id = l.activity_id'
    );

    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[(int) $row['activity_id']] = [
            'logistic_id'   => (int) $row['logistic_id'],
            'activity_id'   => (int) $row['activity_id'],
            'activity_name' => $row['activity_name'],
            'unit_label'    => $row['primary_unit_label'],
            'remaining_qty' => $row['remaining_primary_qty'] === null ? null : (float) $row['remaining_primary_qty'],
        ];
    }
    return $products;
}

function aos_pattern_file(int $activityId): string
{
    return AOS_ORDER_PATTERNS_DIR . '/' . $activityId . '.json';
}

// Reads the aliases of one product. Missing/broken file -> empty list.
function aos_read_aliases(int $activityId): array
{
    $file = aos_pattern_file($activityId);
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || !isset($data['aliases']) || !is_array($data['aliases'])) {
        return [];
    }
    $out = [];
    foreach ($data['aliases'] as $alias) {
        $key = aos_norm_product_text((string) $alias);
        if ($key !== '') {
            $out[] = $key;
        }
    }
    return array_values(array_unique($out));
}

// [ normalized alias => activity_id ] for the given activity ids only, so pattern
// files of deleted products are ignored.
function aos_load_alias_map(array $validActivityIds): array
{
    $map = [];
    foreach ($validActivityIds as $activityId) {
        $activityId = (int) $activityId;
        foreach (aos_read_aliases($activityId) as $key) {
            if (!isset($map[$key])) {
                $map[$key] = $activityId;
            } elseif ($map[$key] !== $activityId) {
                error_log('order_patterns: alias "' . $key . '" is used by activity ' . $map[$key] . ' and ' . $activityId);
            }
        }
    }
    return $map;
}

// Adds one alias to a product's file.
// Returns ['ok' => bool, 'message' => string, 'alias' => string].
function aos_save_alias(int $activityId, string $aliasText, array $products): array
{
    $key = aos_norm_product_text($aliasText);
    if ($key === '') {
        return ['ok' => false, 'message' => 'Product wording is empty.', 'alias' => ''];
    }
    if (mb_strlen($key) > 100) {
        return ['ok' => false, 'message' => 'Product wording is too long (max 100 characters).', 'alias' => $key];
    }
    if (!isset($products[$activityId])) {
        return ['ok' => false, 'message' => 'Product was not found.', 'alias' => $key];
    }

    // The same wording must never point to two different products.
    $map = aos_load_alias_map(array_keys($products));
    if (isset($map[$key]) && $map[$key] !== $activityId) {
        $other = $products[$map[$key]]['activity_name'] ?? 'another product';
        return ['ok' => false, 'message' => 'This wording is already used by ' . $other . '.', 'alias' => $key];
    }
    if (isset($map[$key])) {
        return ['ok' => true, 'message' => 'Wording was already saved.', 'alias' => $key];
    }

    if (!is_dir(AOS_ORDER_PATTERNS_DIR) && !mkdir(AOS_ORDER_PATTERNS_DIR, 0775, true) && !is_dir(AOS_ORDER_PATTERNS_DIR)) {
        return ['ok' => false, 'message' => 'Could not create the patterns folder.', 'alias' => $key];
    }

    // Lock so two saves at the same moment cannot overwrite each other.
    $lock = fopen(AOS_ORDER_PATTERNS_DIR . '/.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        return ['ok' => false, 'message' => 'Could not lock the patterns folder.', 'alias' => $key];
    }

    $aliases   = aos_read_aliases($activityId); // re-read inside the lock
    $aliases[] = $key;

    $json = json_encode(
        ['activity_id' => $activityId, 'aliases' => array_values(array_unique($aliases))],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $file = aos_pattern_file($activityId);
    $tmp  = $file . '.tmp';
    $ok   = $json !== false
        && file_put_contents($tmp, $json) !== false
        && rename($tmp, $file);

    flock($lock, LOCK_UN);
    fclose($lock);

    if (!$ok) {
        @unlink($tmp);
        return ['ok' => false, 'message' => 'Could not save the wording.', 'alias' => $key];
    }
    return ['ok' => true, 'message' => 'Wording saved.', 'alias' => $key];
}