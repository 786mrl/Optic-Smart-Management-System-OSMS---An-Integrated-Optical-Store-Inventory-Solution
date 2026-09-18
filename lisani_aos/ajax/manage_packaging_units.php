<?php
// lisani_aos/ajax/manage_packaging_units.php
// Add/edit/delete/list Primary or Secondary packaging units for Logistic.
// Storage is still the JSON files under json_file/, but from now on they
// are ONLY ever written by this endpoint — never edited by hand — same
// spirit as departments.json being managed through manage_departments.php.
//
// Params: kind = 'primary' | 'secondary' (required for every action)
//         action = 'list' (default) | 'add' | 'edit' | 'delete'
//         label, weight_kg                      (primary)
//         label, ratio_per_primary, weight_kg    (secondary)
//         id (required for edit/delete)
session_start();
header('Content-Type: application/json');

// Any stray output — a PHP notice/warning/deprecation printed by the
// runtime, a stray newline outside the PHP tags of an included file — gets
// prepended to the response and makes it invalid JSON, which the client
// then can't parse (the unit really does get saved, but the UI sees a
// broken response). So: warnings never go to the browser, everything is
// buffered, and the buffer is discarded right before the JSON is written.
ini_set('display_errors', '0');
ob_start();

function aos_units_json($payload)
{
    if (ob_get_level() > 0) {
        $stray = ob_get_clean();
        if ($stray !== '' && $stray !== false) {
            // Not shown to the user, but visible in the PHP error log if
            // something upstream is polluting the output.
            error_log('manage_packaging_units.php stray output: ' . $stray);
        }
    }
    $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        // json_encode itself failed (e.g. a non-UTF8 byte in a unit label
        // read back from the JSON file) — still answer with valid JSON.
        error_log('manage_packaging_units.php json_encode failed: ' . json_last_error_msg());
        $json = json_encode(['ok' => false, 'message' => 'Data satuan tidak bisa dibaca (encoding).']);
    }
    echo $json;
    exit;
}

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    aos_units_json(['ok' => false, 'message' => 'Session tidak valid.']);
}

$kind = $_POST['kind'] ?? $_GET['kind'] ?? '';
if (!in_array($kind, ['primary', 'secondary'], true)) {
    aos_units_json(['ok' => false, 'message' => 'Kind tidak valid.']);
}

$file = __DIR__ . '/../json_file/' . ($kind === 'primary' ? 'primary_packaging_units.json' : 'secondary_packaging_units.json');

$data = json_decode((string) @file_get_contents($file), true);
if (!is_array($data) || !isset($data['units']) || !is_array($data['units'])) {
    $data = ['units' => []];
}

function log_units_save($file, $data)
{
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

function log_units_next_id($units)
{
    $max = 0;
    foreach ($units as $u) $max = max($max, (int) ($u['id'] ?? 0));
    return $max + 1;
}

// Duplicate check: a unit is only considered a duplicate of another when
// EVERY relevant field matches — label (case-insensitive) AND weight_kg
// for primary, plus ratio_per_primary for secondary. Same label with a
// different weight (or, for secondary, a different ratio) is a distinct
// unit and is allowed — e.g. "Master Carton @ 12 kg" and "Master Carton
// @ 10 kg" can coexist.
function log_units_taken($units, $label, $weightKg, $ratioPerPrimary, $kind, $excludeId = null)
{
    foreach ($units as $u) {
        if ($excludeId !== null && (int) $u['id'] === (int) $excludeId) continue;
        if (mb_strtolower($u['label']) !== mb_strtolower($label)) continue;
        if ((float) $u['weight_kg'] !== (float) $weightKg) continue;
        if ($kind === 'secondary' && (float) ($u['ratio_per_primary'] ?? 0) !== (float) $ratioPerPrimary) continue;
        return true;
    }
    return false;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($action === 'list') {
    aos_units_json(['ok' => true, 'units' => $data['units']]);
}

if ($action === 'add') {
    $label = trim($_POST['label'] ?? '');
    $weight = $_POST['weight_kg'] ?? '';

    if ($label === '' || mb_strlen($label) > 100) {
        aos_units_json(['ok' => false, 'message' => 'Nama satuan tidak valid.']);
    }
    if (!is_numeric($weight) || (float) $weight <= 0) {
        aos_units_json(['ok' => false, 'message' => 'Berat (kg) tidak valid.']);
    }

    $ratio = null;
    if ($kind === 'secondary') {
        $ratio = $_POST['ratio_per_primary'] ?? '';
        if (!is_numeric($ratio) || (float) $ratio <= 0) {
            aos_units_json(['ok' => false, 'message' => 'Rasio ke primary tidak valid.']);
        }
    }

    if (log_units_taken($data['units'], $label, $weight, $ratio, $kind)) {
        aos_units_json(['ok' => false, 'message' => 'Satuan dengan nama, berat' . ($kind === 'secondary' ? ', dan rasio' : '') . ' yang sama sudah ada.']);
    }

    $newUnit = ['id' => log_units_next_id($data['units']), 'label' => $label];
    if ($kind === 'secondary') $newUnit['ratio_per_primary'] = (float) $ratio;
    $newUnit['weight_kg'] = (float) $weight;

    $data['units'][] = $newUnit;
    if (!log_units_save($file, $data)) {
        aos_units_json(['ok' => false, 'message' => 'Gagal menyimpan file.']);
    }
    aos_units_json(['ok' => true, 'units' => $data['units']]);
}

if ($action === 'edit') {
    $id     = (int) ($_POST['id'] ?? 0);
    $label  = trim($_POST['label'] ?? '');
    $weight = $_POST['weight_kg'] ?? '';

    if ($label === '' || mb_strlen($label) > 100) {
        aos_units_json(['ok' => false, 'message' => 'Nama satuan tidak valid.']);
    }
    if (!is_numeric($weight) || (float) $weight <= 0) {
        aos_units_json(['ok' => false, 'message' => 'Berat (kg) tidak valid.']);
    }
    $ratio = null;
    if ($kind === 'secondary') {
        $ratio = $_POST['ratio_per_primary'] ?? '';
        if (!is_numeric($ratio) || (float) $ratio <= 0) {
            aos_units_json(['ok' => false, 'message' => 'Rasio ke primary tidak valid.']);
        }
    }
    if (log_units_taken($data['units'], $label, $weight, $ratio, $kind, $id)) {
        aos_units_json(['ok' => false, 'message' => 'Satuan dengan nama, berat' . ($kind === 'secondary' ? ', dan rasio' : '') . ' yang sama sudah ada.']);
    }

    $found = false;
    foreach ($data['units'] as $idx => $u) {
        if ((int) $u['id'] === $id) {
            $data['units'][$idx]['label']     = $label;
            $data['units'][$idx]['weight_kg'] = (float) $weight;
            if ($kind === 'secondary') $data['units'][$idx]['ratio_per_primary'] = (float) $ratio;
            $found = true;
            break;
        }
    }
    if (!$found) {
        aos_units_json(['ok' => false, 'message' => 'Satuan tidak ditemukan.']);
    }
    if (!log_units_save($file, $data)) {
        aos_units_json(['ok' => false, 'message' => 'Gagal menyimpan file.']);
    }
    aos_units_json(['ok' => true, 'units' => $data['units']]);
}

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    $before = count($data['units']);
    $data['units'] = array_values(array_filter($data['units'], function ($u) use ($id) {
        return (int) $u['id'] !== $id;
    }));
    if (count($data['units']) === $before) {
        aos_units_json(['ok' => false, 'message' => 'Satuan tidak ditemukan.']);
    }
    if (!log_units_save($file, $data)) {
        aos_units_json(['ok' => false, 'message' => 'Gagal menyimpan file.']);
    }
    aos_units_json(['ok' => true, 'units' => $data['units']]);
}

aos_units_json(['ok' => false, 'message' => 'Action tidak dikenali.']);