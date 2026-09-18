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

if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

$kind = $_POST['kind'] ?? $_GET['kind'] ?? '';
if (!in_array($kind, ['primary', 'secondary'], true)) {
    echo json_encode(['ok' => false, 'message' => 'Kind tidak valid.']);
    exit;
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
    echo json_encode(['ok' => true, 'units' => $data['units']]);
    exit;
}

if ($action === 'add') {
    $label = trim($_POST['label'] ?? '');
    $weight = $_POST['weight_kg'] ?? '';

    if ($label === '' || mb_strlen($label) > 100) {
        echo json_encode(['ok' => false, 'message' => 'Nama satuan tidak valid.']);
        exit;
    }
    if (!is_numeric($weight) || (float) $weight <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Berat (kg) tidak valid.']);
        exit;
    }

    $ratio = null;
    if ($kind === 'secondary') {
        $ratio = $_POST['ratio_per_primary'] ?? '';
        if (!is_numeric($ratio) || (float) $ratio <= 0) {
            echo json_encode(['ok' => false, 'message' => 'Rasio ke primary tidak valid.']);
            exit;
        }
    }

    if (log_units_taken($data['units'], $label, $weight, $ratio, $kind)) {
        echo json_encode(['ok' => false, 'message' => 'Satuan dengan nama, berat' . ($kind === 'secondary' ? ', dan rasio' : '') . ' yang sama sudah ada.']);
        exit;
    }

    $newUnit = ['id' => log_units_next_id($data['units']), 'label' => $label];
    if ($kind === 'secondary') $newUnit['ratio_per_primary'] = (float) $ratio;
    $newUnit['weight_kg'] = (float) $weight;

    $data['units'][] = $newUnit;
    if (!log_units_save($file, $data)) {
        echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan file.']);
        exit;
    }
    echo json_encode(['ok' => true, 'units' => $data['units']]);
    exit;
}

if ($action === 'edit') {
    $id     = (int) ($_POST['id'] ?? 0);
    $label  = trim($_POST['label'] ?? '');
    $weight = $_POST['weight_kg'] ?? '';

    if ($label === '' || mb_strlen($label) > 100) {
        echo json_encode(['ok' => false, 'message' => 'Nama satuan tidak valid.']);
        exit;
    }
    if (!is_numeric($weight) || (float) $weight <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Berat (kg) tidak valid.']);
        exit;
    }
    $ratio = null;
    if ($kind === 'secondary') {
        $ratio = $_POST['ratio_per_primary'] ?? '';
        if (!is_numeric($ratio) || (float) $ratio <= 0) {
            echo json_encode(['ok' => false, 'message' => 'Rasio ke primary tidak valid.']);
            exit;
        }
    }
    if (log_units_taken($data['units'], $label, $weight, $ratio, $kind, $id)) {
        echo json_encode(['ok' => false, 'message' => 'Satuan dengan nama, berat' . ($kind === 'secondary' ? ', dan rasio' : '') . ' yang sama sudah ada.']);
        exit;
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
        echo json_encode(['ok' => false, 'message' => 'Satuan tidak ditemukan.']);
        exit;
    }
    if (!log_units_save($file, $data)) {
        echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan file.']);
        exit;
    }
    echo json_encode(['ok' => true, 'units' => $data['units']]);
    exit;
}

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    $before = count($data['units']);
    $data['units'] = array_values(array_filter($data['units'], function ($u) use ($id) {
        return (int) $u['id'] !== $id;
    }));
    if (count($data['units']) === $before) {
        echo json_encode(['ok' => false, 'message' => 'Satuan tidak ditemukan.']);
        exit;
    }
    if (!log_units_save($file, $data)) {
        echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan file.']);
        exit;
    }
    echo json_encode(['ok' => true, 'units' => $data['units']]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Action tidak dikenali.']);