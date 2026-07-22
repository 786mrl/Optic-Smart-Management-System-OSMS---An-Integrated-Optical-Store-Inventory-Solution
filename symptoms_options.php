<?php
    // ============================================================
    // === SYMPTOMS OPTIONS MANAGER (JSON-backed) ================
    // ============================================================
    // Simple AJAX endpoint used by the "Symptom Options" settings
    // fly-window on customer_prescription.php.
    // Stores the list of manual symptom buttons in:
    //   data_json/symptoms.json
    //
    // Each symptom option record:
    //   {
    //     "id"        : unique string id (auto-generated),
    //     "label"     : button text shown to the user (UPPERCASE),
    //     "value"     : value stored in the symptoms list on save,
    //     "full_width": bool, whether the button spans the full grid row,
    //     "detail_id" : optional id of an extra detail box (e.g. "dm_detail"),
    //                   only used for the built-in DIABETES / HYPERTENSION
    //                   special cases; leave null for normal options.
    //   }
    // ============================================================

    session_start();
    include 'db_config.php';
    include 'auth_check.php';

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }

    header('Content-Type: application/json');

    $dataDir  = __DIR__ . '/data_json';
    $dataFile = $dataDir . '/symptoms.json';

    // Make sure the folder and file exist
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    if (!file_exists($dataFile)) {
        file_put_contents($dataFile, json_encode([], JSON_PRETTY_PRINT));
    }

    function loadSymptoms($dataFile) {
        $raw = file_get_contents($dataFile);
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    function saveSymptoms($dataFile, $arr) {
        return file_put_contents($dataFile, json_encode(array_values($arr), JSON_PRETTY_PRINT));
    }

    function genId() {
        return 'sym_' . bin2hex(random_bytes(4));
    }

    $action = $_REQUEST['action'] ?? 'list';

    switch ($action) {

        // ---------------------------------------------------
        // LIST all symptom options
        // ---------------------------------------------------
        case 'list':
            echo json_encode(['success' => true, 'data' => loadSymptoms($dataFile)]);
            break;

        // ---------------------------------------------------
        // ADD a new symptom option
        // ---------------------------------------------------
        case 'add':
            $input = json_decode(file_get_contents('php://input'), true);
            $label = strtoupper(trim($input['label'] ?? ''));

            if ($label === '') {
                echo json_encode(['success' => false, 'message' => 'Label cannot be empty']);
                break;
            }

            $symptoms = loadSymptoms($dataFile);
            $newItem = [
                'id'         => genId(),
                'label'      => $label,
                'value'      => $label,
                'full_width' => !empty($input['full_width']),
                'detail_id'  => null // Custom options never carry the special DM/HT detail box
            ];

            // Insert the new option right before the first entry that has a
            // detail_id (DIABETES / HYPERTENSION), so those always stay last.
            $insertAt = count($symptoms);
            foreach ($symptoms as $idx => $s) {
                if (!empty($s['detail_id'])) {
                    $insertAt = $idx;
                    break;
                }
            }
            array_splice($symptoms, $insertAt, 0, [$newItem]);

            saveSymptoms($dataFile, $symptoms);

            echo json_encode(['success' => true, 'data' => $newItem]);
            break;

        // ---------------------------------------------------
        // EDIT an existing symptom option
        // ---------------------------------------------------
        case 'edit':
            $input = json_decode(file_get_contents('php://input'), true);
            $id    = $input['id'] ?? '';
            $label = strtoupper(trim($input['label'] ?? ''));

            if ($id === '' || $label === '') {
                echo json_encode(['success' => false, 'message' => 'Missing id or label']);
                break;
            }

            $symptoms = loadSymptoms($dataFile);
            $found = false;
            foreach ($symptoms as &$s) {
                if ($s['id'] === $id) {
                    $s['label'] = $label;
                    // Keep original value in sync unless it's a protected special value (Diabetes/Hypertension)
                    if (empty($s['detail_id'])) {
                        $s['value'] = $label;
                    }
                    if (isset($input['full_width'])) {
                        $s['full_width'] = !empty($input['full_width']);
                    }
                    $found = true;
                    break;
                }
            }
            unset($s);

            if (!$found) {
                echo json_encode(['success' => false, 'message' => 'Symptom not found']);
                break;
            }

            saveSymptoms($dataFile, $symptoms);
            echo json_encode(['success' => true]);
            break;

        // ---------------------------------------------------
        // DELETE a symptom option
        // ---------------------------------------------------
        case 'delete':
            $input = json_decode(file_get_contents('php://input'), true);
            $id    = $input['id'] ?? '';

            if ($id === '') {
                echo json_encode(['success' => false, 'message' => 'Missing id']);
                break;
            }

            $symptoms = loadSymptoms($dataFile);
            $filtered = array_filter($symptoms, function ($s) use ($id) {
                return $s['id'] !== $id;
            });

            saveSymptoms($dataFile, $filtered);
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }