<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';
    include 'auth_check.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

    $role = $_SESSION['role'] ?? 'staff';

    // 1. Function to Convert Month to Roman Numerals
    function getRomawi($month) {
        $romawi = [1=>'I', 2=>'II', 3=>'III', 4=>'IV', 5=>'V', 6=>'VI', 
                7=>'VII', 8=>'VIII', 9=>'IX', 10=>'X', 11=>'XI', 12=>'XII'];
        return $romawi[(int)$month];
    }

    // Simple helper function to provide a default value (e.g., 0.00) if empty
    // New Version: Adding $forcePlus parameter
    function cleanPres($conn, $val, $default = "0.00", $forcePlus = false) {
        $cleaned = mysqli_real_escape_string($conn, trim($val));
        if ($cleaned === "") return $default;

        // Logic for adding the + sign only runs if $forcePlus is set to TRUE
        if ($forcePlus && is_numeric($cleaned) && $cleaned > 0) {
            if (strpos($val, '+') === false) {
                $cleaned = "+" . $cleaned;
            }
        }
        
        return $cleaned;
    }

    // DATA SAVE PROCESS
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['customer_name'])) {
        // 1. Get Basic Data
        $exam_date = (isset($_POST['examination_date']) && !empty($_POST['examination_date'])) 
                 ? $_POST['examination_date'] 
                 : date('Y-m-d');
        $customer_name = mysqli_real_escape_string($conn, strtoupper(trim($_POST['customer_name'])));
        $symptoms      = mysqli_real_escape_string($conn, $_POST['symptoms']);
        $invoice_val = $_POST['invoice_number'] ?? '00';

        $gender_raw = $_POST['gender'] ?? 'FEMALE'; 
        $gender = mysqli_real_escape_string($conn, strtoupper(trim($gender_raw)));
        if (empty($gender)) {
            $gender = 'FEMALE';
        }

        // 2. Generate Automatic Examination Code (Format: LZ/EC/[DATA_SEQUANCE]/[MONTH]/[YEAR])
        $exam_code = mysqli_real_escape_string($conn, $_POST['examination_code']);

        // 3. Age input
        // Get raw input from the form
        $raw_age = trim($_POST['age'] ?? '');
        $calculated_age = 0;
        $exam_year = (int)date('Y', strtotime($exam_date));
        if (!empty($raw_age)) {
            // Check if there is a single quote (.) in the input
            if (strpos($raw_age, ".") !== false) {
                // YEAR CASE: Remove the quote and extract the numbers
                $year_input = str_replace(".", "", $raw_age);
                $year_val = (int)$year_input;

                // If input is 1-2 digits (e.g., .96 or .05)
                if (strlen($year_input) <= 2) {
                    // If number > current year (e.g., 96), assume 1900s. 
                    // If number <= current year (e.g., 05), assume 2000s.
                    $full_year = ($year_val > (int)date('y')) ? 1900 + $year_val : 2000 + $year_val;
                } else {
                    // If input is 4 digits (e.g., 1996)
                    $full_year = $year_val;
                }
                $calculated_age = $exam_year - $full_year;
            } else {
                // DIRECT AGE CASE: No single quote present
                $calculated_age = (int)$raw_age;
            }
        }
        $age_to_save = (int)$calculated_age;

        // 4 Process Symptoms from JSON
        $symptoms_json = json_decode($_POST['symptom_list_json'], true) ?? [];
        $final_symptoms_arr = [];

        foreach ($symptoms_json as $s) {
            if ($s == 'DIABETES') {
                // Convert status (CONTROLLED/UNCONTROLLED) and blood sugar input to uppercase
                $dm_val = strtoupper(trim($_POST['dm_sugar']));
                $dm_stat = strtoupper($_POST['dm_status']);
                $final_symptoms_arr[] = "DIABETES ($dm_val MG/DL, $dm_stat)";
            } elseif ($s == 'HYPERTENSION') {
                // Convert blood pressure and status to uppercase
                $ht_val = strtoupper(trim($_POST['ht_pressure']));
                $ht_stat = strtoupper($_POST['ht_status']);
                $final_symptoms_arr[] = "HYPERTENSION ($ht_val, $ht_stat)";
            } else {
                // Since $s originates from pre-capitalized buttons, change is not strictly necessary,
                // however, strtoupper() ensures extra security and consistency.
                $final_symptoms_arr[] = strtoupper($s);
            }
        }

        // MAIN FIX: Ensure manual "OTHERS" input is always in uppercase
        if (!empty($_POST['other_symptoms'])) {
            $other_val = strtoupper(trim($_POST['other_symptoms']));
            $final_symptoms_arr[] = "OTHERS: " . $other_val;
        }

        $symptoms_to_save = mysqli_real_escape_string($conn, implode(", ", $final_symptoms_arr));
        
        // 5. Get Old Prescription Data
        $has_old = $_POST['has_old_prescription'];

        if ($has_old == 'yes') {
            $old_r_sph = cleanPres($conn, $_POST['old_prescript_R_sph'], "0.00", true);
            $old_r_cyl = cleanPres($conn, $_POST['old_prescript_R_cyl'], "0.00", true);
            $old_r_ax  = cleanPres($conn, $_POST['old_prescript_R_ax'], "0");
            $old_r_add = cleanPres($conn, $_POST['old_prescript_R_add'], "0.00", true);

            $old_l_sph = cleanPres($conn, $_POST['old_prescript_L_sph'], "0.00", true);
            $old_l_cyl = cleanPres($conn, $_POST['old_prescript_L_cyl'], "0.00", true);
            $old_l_ax  = cleanPres($conn, $_POST['old_prescript_L_ax'], "0");
            $old_l_add = cleanPres($conn, $_POST['old_prescript_L_add'], "0.00", true);
        } else {
            // If No, set all values to 0.00 / 0
            $old_r_sph = $old_r_cyl = $old_r_add = "0.00";
            $old_l_sph = $old_l_cyl = $old_l_add = "0.00";
            $old_r_ax = $old_l_ax = "0";
        }

        // 6. New Prescription Data
        $new_r_sph = cleanPres($conn, $_POST['new_r_sph'], "0.00", true);
        $new_r_cyl = cleanPres($conn, $_POST['new_r_cyl'], "0.00", true);
        $new_r_ax  = cleanPres($conn, $_POST['new_r_ax'], "0");
        $new_r_add = cleanPres($conn, $_POST['new_r_add'], "0.00", true);
        $new_r_va  = cleanPres($conn, $_POST['new_r_va'], "20/20");

        $new_l_sph = cleanPres($conn, $_POST['new_l_sph'], "0.00", true);
        $new_l_cyl = cleanPres($conn, $_POST['new_l_cyl'], "0.00", true);
        $new_l_ax  = cleanPres($conn, $_POST['new_l_ax'], "0");
        $new_l_add = cleanPres($conn, $_POST['new_l_add'], "0.00", true);
        $new_l_va  = cleanPres($conn, $_POST['new_l_va'], "20/20");

        // PD: auto-detect default — 62/60 jika ADD bukan 0.00, 62 jika ADD kosong/0.00
        $r_add_raw = trim($_POST['new_r_add'] ?? '');
        $l_add_raw = trim($_POST['new_l_add'] ?? '');
        $has_add = ($r_add_raw !== '' && $r_add_raw !== '0.00')
                || ($l_add_raw !== '' && $l_add_raw !== '0.00');
        $pd_default = $has_add ? "62/60" : "62";
        $pd_dist_val = cleanPres($conn, $_POST['pd_dist'], $pd_default);

        // Get Visual Habits & Digital Usage
        $visual_habit = (int)($_POST['visual_habit'] ?? 1);
        $digital_usage = (int)($_POST['digital_usage'] ?? 1);
        $exam_notes = mysqli_real_escape_string($conn, $_POST['exam_notes']);

        // UCVA Data
        $ucva_r = cleanPres($conn, $_POST['ucva_r'], "20/20");
        $ucva_l = cleanPres($conn, $_POST['ucva_l'], "20/20");

        // 7. Vision Need (Distance / Intermediate / Near) — auto-shown if age >= 39
        // Each field: 1 = YES, 0 = NO
        if ($age_to_save >= 39) {
            $need_distance     = isset($_POST['need_distance'])     ? 1 : 0;
            $need_intermediate = isset($_POST['need_intermediate']) ? 1 : 0;
            $need_near         = isset($_POST['need_near'])         ? 1 : 0;
        } else {
            // Under 39: always 0
            $need_distance     = 0;
            $need_intermediate = 0;
            $need_near         = 0;
        }
        
        // created_by: ambil username yang sedang login
        $created_by = mysqli_real_escape_string($conn, $_SESSION['username'] ?? 'staff');

        // 6. Insert Query using Prepared Statement
        $stmt = $conn->prepare("INSERT INTO customer_examinations (
            examination_date, examination_code, customer_name, gender, age, symptoms,
            old_r_sph, old_r_cyl, old_r_ax, old_r_add,
            old_l_sph, old_l_cyl, old_l_ax, old_l_add,
            new_r_sph, new_r_cyl, new_r_ax, new_r_add, new_r_visus,
            new_l_sph, new_l_cyl, new_l_ax, new_l_add, new_l_visus,
            pd_dist, invoice_number,
            visual_habit, digital_usage, ucva_r, ucva_l, exam_notes,
            need_distance, need_intermediate, need_near,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        // Define data types: 
        $types = "ssssi" . str_repeat("s", 21) . "iisss" . "iii" . "s"; 

        $stmt->bind_param($types, 
            $exam_date, $exam_code, $customer_name, $gender, $age_to_save, $symptoms_to_save,
            $old_r_sph, $old_r_cyl, $old_r_ax, $old_r_add,
            $old_l_sph, $old_l_cyl, $old_l_ax, $old_l_add,
            $new_r_sph, $new_r_cyl, $new_r_ax, $new_r_add, $new_r_va,
            $new_l_sph, $new_l_cyl, $new_l_ax, $new_l_add, $new_l_va,
            $pd_dist_val, $invoice_val,
            $visual_habit, $digital_usage, $ucva_r, $ucva_l, $exam_notes,
            $need_distance, $need_intermediate, $need_near,
            $created_by
        );

        if ($stmt->execute()) {
            $exam_inserted_id = (string)$conn->insert_id;
            if ($invoice_val !== '00') {
                // Jika belanja, tetap ke invoice
                header("Location: invoice.php?inv=" . $invoice_val);
            } else {
                // CEK INSTRUKSI REDIRECT DARI JAVASCRIPT
                $redirect_to = $_POST['after_save_redirect'] ?? 'self';
                
                if ($redirect_to === 'customer_list') {
                    // Jika pilih "NO, JUST EXAM", kembali ke daftar customer
                    header("Location: customer.php");
                } else {
                    // Jika default, tetap di halaman ini dengan pesan sukses
                    $_SESSION['success_msg'] = "DATA SAVED SUCCESSFULLY!";
                    header("Location: " . $_SERVER['PHP_SELF']);
                }
            }
            exit();
        } else {
            die("DATABASE ERROR: " . $stmt->error);
        }
    }
    // Skip direct-sale codes (LZ/EC/000-xxx/...) to avoid resetting the sequence.
    $query_seq = "SELECT examination_code FROM customer_examinations
                  WHERE examination_code NOT LIKE 'LZ/EC/000-%'
                  ORDER BY id DESC LIMIT 1";
    $res_seq   = mysqli_query($conn, $query_seq);
    $sequence  = 1;

    if ($res_seq && mysqli_num_rows($res_seq) > 0) {
        $last_row = mysqli_fetch_assoc($res_seq);
        $parts = explode('/', $last_row['examination_code']);
        $sequence = (isset($parts[2])) ? (int)$parts[2] + 1 : 1;
    }
    $seq_padded = str_pad($sequence, 3, '0', STR_PAD_LEFT);
    $exam_code = 'LZ/EC/' . $seq_padded . '/' . getRomawi((int)date('n')) . '/' . date('Y');
?>

<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Frame Entry - <?php echo htmlspecialchars($STORE_NAME); ?></title>
        <link rel="stylesheet" href="style.css">
        <style>
            h2 {
                text-align: center;
                margin-bottom: 35px;
                font-weight: 700;
                letter-spacing: -0.5px;
            }
            .hidden { display: none !important; }
            .hidden-detail {
                display: none;
                background: #1a1c1d;
                padding: 15px;
                border-radius: 10px;
                border-left: 4px solid #00ff88;
                margin-top: 10px;
                text-align: left;
            }
            .detail-box label { font-size: 0.8em; color: #00ff88; margin-bottom: 5px; display: block; }
            .symptom-btn.active { 
                color: #00ff88 !important; 
                box-shadow: inset 2px 2px 5px #000 !important;
            }
            .symptom-btn.active .led { background: #00ff88; box-shadow: 0 0 10px #00ff88; }
            .symptoms-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
                justify-items: center; 
                align-items: center;
                margin-bottom: 20px;
                width: 100%;
            }
            .symptom-btn {
                width: 100% !important;
                min-height: 50px;
            }

            /* Spin animation for the 🏷️ symptom settings button when pressed */
            @keyframes symptomSettingsSpin {
                from { transform: rotate(0deg); }
                to   { transform: rotate(360deg); }
            }
            #btn_open_symptom_settings.spinning {
                animation: symptomSettingsSpin 0.5s ease-in-out;
            }
            #btn_open_symptom_settings:active {
                transform: scale(0.92);
            }

            /* Rows inside the Symptom Options fly window */
            .symptom-option-row {
                display: flex;
                align-items: center;
                gap: 8px;
                background: #25282a;
                border: 1px solid #333;
                border-radius: 10px;
                padding: 8px 10px;
            }
            .symptom-option-row input[type="text"] {
                flex: 1;
                min-width: 0;
                background: #0d0f12;
                border: 1px solid #252830;
                border-radius: 6px;
                color: #e5e7eb;
                font-size: 0.82em;
                padding: 8px;
                box-sizing: border-box;
            }
            .symptom-option-row .opt-btn {
                border-radius: 6px;
                border: 1px solid #444;
                background: #1f2224;
                color: #ccc;
                cursor: pointer;
                font-size: 0.78em;
                padding: 7px 10px;
                white-space: nowrap;
            }
            .symptom-option-row .opt-btn.save {
                border-color: #00ff88;
                color: #00ff88;
            }
            .symptom-option-row .opt-btn.delete {
                border-color: #ff5566;
                color: #ff5566;
            }

            .prescription-card {
                background: #25282a;
                padding: 20px;
                border-radius: 15px;
                border: 1px solid #444;
                box-shadow: inset 5px 5px 10px #1a1c1d;
                
                /* --- Center the content inside --- */
                display: flex;
                flex-direction: column; /* Title on top, table below */
                align-items: center;    /* Horizontal centering */
                justify-content: center; /* Vertical centering if there is remaining space */
            }

            .prescription-table {
                width: 100%;       /* Let the table expand to the card padding limits */
                max-width: 600px;  /* Limit maximum width to prevent over-stretching on wide screens */
                margin: 0 auto;    /* Ensure margin-based centering as a fallback */
            }

            .pres-grid {
                display: grid;
                /* Adjust number of columns. Using 5 columns for Old Prescription */
                grid-template-columns: 1.2fr repeat(4, 1fr); 
                gap: 10px;
                align-items: center;
                width: 100%;
            }

            .pres-grid.header {
                text-align: center;
                font-size: 0.7em;
                color: #888;
                font-weight: bold;
                text-transform: uppercase;
            }

            .pres-grid input {
                width: 100%;
                background: #1a1c1d !important;
                border: 1px solid #333 !important;
                border-radius: 8px !important;
                padding: 12px 5px !important;
                color: #00ff88 !important;
                text-align: center;
                font-family: monospace;
                font-size: 0.9em;
            }

            .eye-label {
                font-size: 0.8em;
                font-weight: bold;
                color: #eee;
            }

            #new_prescript_section {
                margin-bottom: 20px;
            }

            /* --- PRESCRIPTION ANALYSIS PANEL --- */
            #prescription_analysis {
                display: none;
                grid-column: 1 / -1;
                width: 100%;
                margin-top: 20px;
                background: #25282a;
                padding: 20px;
                border-radius: 15px;
                border: 1px solid #00ccff66;
                box-shadow: 0 0 15px rgba(0, 204, 255, 0.1);
            }
            #prescription_analysis h3.analysis-title {
                color: #00ccff;
                font-size: 1em;
                text-align: center;
                margin-top: 0;
                margin-bottom: 20px;
                letter-spacing: 2px;
            }
            .analysis-eye-block {
                background: #1a1c1d;
                border-left: 4px solid #00ccff;
                border-radius: 10px;
                padding: 15px;
                margin-bottom: 15px;
            }
            .analysis-eye-block h4 {
                color: #00ccff;
                margin: 0 0 12px 0;
                font-size: 0.9em;
                letter-spacing: 1px;
            }
            .analysis-condition {
                margin-bottom: 14px;
                padding-bottom: 12px;
                border-bottom: 1px dashed #333;
            }
            .analysis-condition:last-child {
                border-bottom: none;
                padding-bottom: 0;
                margin-bottom: 0;
            }
            .analysis-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 6px;
                font-size: 0.72em;
                font-weight: bold;
                margin-bottom: 8px;
                letter-spacing: 1px;
                font-family: monospace;
            }
            .badge-mild     { background: #2d4a2d; color: #66ff66; border: 1px solid #66ff66; }
            .badge-moderate { background: #4a432d; color: #ffcc00; border: 1px solid #ffcc00; }
            .badge-high     { background: #4a2d2d; color: #ff8866; border: 1px solid #ff8866; }
            .badge-severe   { background: #4a2d4a; color: #ff4466; border: 1px solid #ff4466; }
            .badge-normal   { background: #2d3a4a; color: #66ccff; border: 1px solid #66ccff; }
            .analysis-label {
                font-size: 0.72em;
                color: #888;
                text-transform: uppercase;
                letter-spacing: 1px;
                margin-top: 8px;
                margin-bottom: 4px;
                font-weight: bold;
            }
            .analysis-text {
                color: #ddd;
                font-size: 0.85em;
                line-height: 1.5;
                margin: 0;
            }
            .analysis-text ul {
                margin: 4px 0 0 0;
                padding-left: 20px;
            }
            .analysis-text ul li {
                margin-bottom: 3px;
            }
            .analysis-recommendation {
                background: #1a2c2a;
                border: 1px solid #00ff8844;
                border-radius: 10px;
                padding: 15px;
                margin-top: 15px;
            }
            .analysis-recommendation h4 {
                color: #00ff88;
                margin: 0 0 10px 0;
                font-size: 0.85em;
                letter-spacing: 1px;
            }
            .analysis-insights {
                background: #1a1e2c;
                border: 1px solid #6699ff44;
                border-left: 4px solid #6699ff;
                border-radius: 10px;
                padding: 15px;
                margin-top: 15px;
            }
            .analysis-insights h4 {
                color: #6699ff;
                margin: 0 0 10px 0;
                font-size: 0.85em;
                letter-spacing: 1px;
            }
            .analysis-disclaimer {
                margin-top: 14px;
                font-size: 0.7em;
                color: #777;
                text-align: center;
                font-style: italic;
                line-height: 1.4;
            }

            /* --- COLLAPSIBLE SECTIONS (click to expand/collapse) --- */
            .collapsible .collapsible-header {
                cursor: pointer;
                user-select: none;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                transition: opacity 0.2s ease;
            }
            .collapsible .collapsible-header:hover {
                opacity: 0.75;
            }
            .collapsible .collapsible-header .chevron {
                font-size: 0.65em;
                opacity: 0.6;
                transition: transform 0.25s ease;
                display: inline-block;
                flex-shrink: 0;
            }
            .collapsible.collapsed .collapsible-header .chevron {
                transform: rotate(-90deg);
            }
            .collapsible.collapsed .collapsible-content {
                display: none;
            }
            .collapsible.collapsed {
                padding-bottom: 15px;
            }
            /* When the main outer panel is collapsed, hide the disclaimer too */
            #prescription_analysis.collapsed .analysis-disclaimer {
                display: none;
            }

            /* --- AI ANALYZE BUTTON --- */
            .ai-analyze-btn {
                background: linear-gradient(135deg, #00ccff 0%, #6699ff 50%, #9966ff 100%);
                background-size: 200% 200%;
                color: #0a0a0a;
                border: none;
                border-radius: 12px;
                padding: 14px 30px;
                font-weight: 700;
                font-size: 0.9em;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 0 20px rgba(0, 204, 255, 0.3);
                animation: ai-btn-shimmer 4s ease infinite;
                font-family: inherit;
            }
            .ai-analyze-btn:hover:not(:disabled) {
                transform: translateY(-2px);
                box-shadow: 0 0 30px rgba(0, 204, 255, 0.6);
            }
            .ai-analyze-btn:active:not(:disabled) {
                transform: translateY(0);
            }
            .ai-analyze-btn:disabled {
                cursor: not-allowed;
                opacity: 0.7;
                background: #444;
                color: #888;
                animation: none;
                box-shadow: none;
            }
            @keyframes ai-btn-shimmer {
                0%   { background-position: 0% 50%; }
                50%  { background-position: 100% 50%; }
                100% { background-position: 0% 50%; }
            }

            /* --- LOADING SPINNERS --- */
            .spinner {
                display: inline-block;
                width: 14px;
                height: 14px;
                border: 2px solid rgba(10, 10, 10, 0.3);
                border-top-color: #0a0a0a;
                border-radius: 50%;
                animation: spin 0.8s linear infinite;
                vertical-align: middle;
                margin-right: 6px;
            }
            .spinner-lg {
                display: inline-block;
                width: 40px;
                height: 40px;
                border: 4px solid rgba(0, 204, 255, 0.2);
                border-top-color: #00ccff;
                border-radius: 50%;
                animation: spin 0.9s linear infinite;
            }
            @keyframes spin {
                to { transform: rotate(360deg); }
            }

            .swal2-popup {
                border: 1px solid #00ff88 !important;
                box-shadow: 0 0 20px rgba(0, 255, 136, 0.2) !important;
            }

            /* ============================================================ */
            /* === INPUT REVIEW CARD ======================================= */
            /* ============================================================ */
            #input_review_card {
                display: none;
                margin-top: 28px;
                background: linear-gradient(145deg, #1e2225, #252930);
                border: 1px solid #00ff8833;
                border-radius: 18px;
                overflow: hidden;
                box-shadow: 0 4px 30px rgba(0, 255, 136, 0.08), inset 0 1px 0 rgba(255,255,255,0.04);
                transition: all 0.3s ease;
            }
            #input_review_card.has-data {
                border-color: #00ff8855;
                box-shadow: 0 4px 30px rgba(0, 255, 136, 0.12), inset 0 1px 0 rgba(255,255,255,0.04);
            }
            .review-card-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 14px 20px;
                cursor: pointer;
                background: rgba(0, 255, 136, 0.04);
                border-bottom: 1px solid transparent;
                transition: all 0.25s ease;
                user-select: none;
            }
            .review-card-header:hover {
                background: rgba(0, 255, 136, 0.07);
            }
            #input_review_card.expanded .review-card-header {
                border-bottom-color: #00ff8822;
            }
            .review-header-left {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .review-header-icon {
                width: 28px;
                height: 28px;
                background: linear-gradient(135deg, #00ff88, #00ccaa);
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 0.9em;
                flex-shrink: 0;
                box-shadow: 0 0 10px rgba(0, 255, 136, 0.3);
            }
            .review-header-title {
                font-size: 0.82em;
                font-weight: 700;
                color: #00ff88;
                letter-spacing: 2px;
                text-transform: uppercase;
            }
            .review-header-subtitle {
                font-size: 0.68em;
                color: #666;
                letter-spacing: 0.5px;
                margin-top: 1px;
            }
            .review-header-right {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .review-badge-count {
                background: rgba(0, 255, 136, 0.15);
                border: 1px solid #00ff8844;
                color: #00ff88;
                font-size: 0.65em;
                font-weight: bold;
                padding: 2px 9px;
                border-radius: 20px;
                letter-spacing: 1px;
            }
            .review-chevron {
                color: #00ff88;
                font-size: 0.7em;
                opacity: 0.7;
                transition: transform 0.25s ease;
                flex-shrink: 0;
            }
            #input_review_card.expanded .review-chevron {
                transform: rotate(180deg);
            }
            .review-card-body {
                display: none;
                padding: 18px 20px 20px;
            }
            #input_review_card.expanded .review-card-body {
                display: block;
            }
            .review-section {
                margin-bottom: 16px;
            }
            .review-section:last-child {
                margin-bottom: 0;
            }
            .review-section-title {
                font-size: 0.62em;
                color: #555;
                letter-spacing: 2px;
                text-transform: uppercase;
                font-weight: bold;
                margin-bottom: 8px;
                padding-bottom: 5px;
                border-bottom: 1px solid #2a2d30;
            }
            .review-row {
                display: flex;
                align-items: flex-start;
                gap: 10px;
                margin-bottom: 6px;
                font-size: 0.8em;
                line-height: 1.4;
            }
            .review-row:last-child {
                margin-bottom: 0;
            }
            .review-label {
                color: #888;
                min-width: 90px;
                flex-shrink: 0;
                font-size: 0.88em;
                letter-spacing: 0.5px;
            }
            .review-value {
                color: #ddd;
                font-weight: 500;
                flex: 1;
            }
            .review-value.highlight {
                color: #00ff88;
                font-family: 'Courier New', monospace;
            }
            .review-value.yellow {
                color: #ffcc00;
            }
            .review-value.muted {
                color: #555;
                font-style: italic;
            }
            /* Prescription mini table inside review */
            .review-pres-table {
                width: 100%;
                border-collapse: collapse;
                font-family: 'Courier New', monospace;
                font-size: 0.78em;
                margin-top: 4px;
            }
            .review-pres-table th {
                color: #555;
                font-size: 0.85em;
                letter-spacing: 1px;
                text-align: center;
                padding: 4px 6px;
                border-bottom: 1px solid #2a2d30;
                font-weight: bold;
            }
            .review-pres-table th:first-child { text-align: left; }
            .review-pres-table td {
                text-align: center;
                padding: 6px 6px;
                color: #00ff88;
                border-bottom: 1px solid #1e2225;
            }
            .review-pres-table td:first-child {
                text-align: left;
                color: #aaa;
                font-weight: bold;
            }
            .review-pres-table tr:last-child td { border-bottom: none; }
            .review-pres-table td.val-zero { color: #444; }
            .review-divider {
                height: 1px;
                background: linear-gradient(to right, transparent, #2a2d30, transparent);
                margin: 14px 0;
            }
            .review-symptoms-wrap {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                margin-top: 4px;
            }
            .review-symptom-tag {
                background: rgba(255, 204, 0, 0.1);
                border: 1px solid #ffcc0033;
                color: #ffcc00;
                font-size: 0.72em;
                padding: 3px 9px;
                border-radius: 20px;
                letter-spacing: 0.5px;
                font-weight: bold;
            }
            .review-notes-text {
                background: #1a1c1e;
                border: 1px solid #2a2d30;
                border-radius: 8px;
                padding: 10px 12px;
                color: #aaa;
                font-size: 0.78em;
                line-height: 1.6;
                margin-top: 4px;
                white-space: pre-wrap;
                word-break: break-word;
            }

            /* --- TOGGLE PANEL (Gender, Visual Habits, Digital Usage) --- */
            .toggle-panel-header {
                background: #25282a;
                width: 100%;
                padding: 13px 18px;
                border-radius: 12px;
                border: 1px solid #444;
                cursor: pointer;
                display: flex;
                justify-content: flex-start;
                align-items: center;
                gap: 10px;
                box-shadow: inset 2px 2px 5px #1a1c1d;
                user-select: none;
                transition: opacity 0.2s ease;
                box-sizing: border-box;
            }
            .toggle-panel-header:hover { opacity: 0.8; }
            .toggle-panel-header .tph-label {
                font-size: 0.82em;
                color: #888;
                letter-spacing: 1px;
                text-transform: uppercase;
            }
            .toggle-panel-header .tph-value {
                font-size: 0.85em;
                color: #00ff88;
                font-weight: bold;
                letter-spacing: 0.5px;
                margin-left: auto;
            }
            .toggle-panel-header .tph-arrow {
                color: #00ff88;
                font-size: 0.75em;
                transition: transform 0.25s ease;
                flex-shrink: 0;
                margin-left: 8px;
            }
            .toggle-panel-header.open .tph-arrow {
                transform: rotate(180deg);
            }
            .toggle-panel-body {
                display: none;
                margin-top: 8px;
            }
            .toggle-panel-body.open {
                display: block;
            }

            /* --- VISION NEED SECTION --- */
            #vision_need_section {
                display: none;
                flex: 0 0 100%;
                max-width: 100%;
                grid-column: 1 / -1;
                width: 100% !important;
            }
            .vision-need-label {
                width: 100%;
                text-align: center;
                margin-bottom: 8px;
                font-size: 0.75em;
                color: #888;
                text-transform: uppercase;
                letter-spacing: 1px;
                font-weight: bold;
            }
            .vision-need-wrapper {
                display: flex;
                gap: 12px;
                justify-content: center;
                flex-wrap: wrap;
            }
            .vision-need-btn {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 6px;
                background: #25282a;
                border: 1px solid #444;
                border-radius: 14px;
                padding: 14px 20px;
                cursor: pointer;
                transition: all 0.2s ease;
                min-width: 110px;
                color: #aaa;
                font-size: 0.8em;
                font-weight: bold;
                letter-spacing: 1px;
                box-shadow: inset 2px 2px 5px #1a1c1d;
                position: relative;
            }
            .vision-need-btn .vn-icon {
                font-size: 1.6em;
                line-height: 1;
            }
            .vision-need-btn .vn-led {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                background: #333;
                transition: all 0.2s ease;
            }
            .vision-need-btn.active {
                border-color: #00ff88;
                color: #00ff88;
                box-shadow: inset 2px 2px 5px #000, 0 0 12px rgba(0,255,136,0.2);
            }
            .vision-need-btn.active .vn-led {
                background: #00ff88;
                box-shadow: 0 0 8px #00ff88;
            }
            .vision-need-header-row {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                margin-bottom: 12px;
            }
            .vision-need-title {
                color: #ffcc00;
                font-size: 0.78em;
                font-weight: bold;
                letter-spacing: 2px;
                text-align: center;
            }
            .vision-need-badge {
                background: #3a3200;
                border: 1px solid #ffcc0066;
                color: #ffcc00;
                font-size: 0.65em;
                padding: 2px 8px;
                border-radius: 20px;
                letter-spacing: 1px;
            }

            .prescription-table::-webkit-scrollbar {
                height: 6px;
            }
            .prescription-table::-webkit-scrollbar-thumb {
                background: #00ff88;
                border-radius: 10px;
            }
            .prescription-table::-webkit-scrollbar-track {
                background: #1a1c1d;
            }
            /* --- RESPONSIVE FIX --- */
            @media (max-width: 600px) {
                /* Grid tetap 2 kolom di mobile */
                .symptoms-grid {
                    grid-template-columns: repeat(2, 1fr) !important; 
                    gap: 10px;
                }

                /* GLAUCOMA full satu baris */
                .symptoms-grid > button.symptom-btn[style*="grid-column"] {
                    grid-column: 1 / -1 !important;
                }

                /* DIABETES & HYPERTENSION tetap setengah-setengah */
                .symptoms-grid > button.symptom-btn:not([style*="grid-column"]) {
                    grid-column: auto !important;
                }

                .symptom-btn {
                    max-width: 100%; /* Buttons fill the column width */
                    font-size: 0.75em; /* Slightly reduce font size to prevent overflow */
                    padding: 12px 5px;
                }

                .main-card {
                    padding: 15px; /* Reduce card padding for more screen space */
                }

                #symptoms_panel {
                    padding: 15px;
                }

                .prescription-card {
                    padding: 15px 5px !important;
                    width: 100% !important;
                    overflow: hidden !important;
                }

                /* KEY: Table wrapper must be block and overflow-x auto */
                .prescription-table {
                    display: block !important;
                    width: 100% !important;
                    overflow-x: auto !important; 
                    -webkit-overflow-scrolling: touch;
                    padding-bottom: 20px; /* Extra space for the scrollbar */
                    cursor: grab;
                }

                /* KEY: Force minimum table width to prevent shrinking */
                .pres-grid {
                    display: grid !important;
                    min-width: 750px !important; /* Force 750px width so values like +2.00 stay on one line */
                    gap: 8px !important;
                }

                /* Prevent numbers from wrapping (line-break) */
                .pres-grid input {
                    width: 100% !important;
                    min-width: 75px !important; 
                    font-size: 0.9em !important;
                    padding: 12px 2px !important;
                    white-space: nowrap !important;
                }

                .eye-label {
                    font-size: 0.7em !important;
                    min-width: 60px;
                    white-space: nowrap;
                }

                /* Fixed column settings (no '1fr' to prevent flexible shrinking) */
                .pres-grid.header, .pres-grid.row {
                    grid-template-columns: 80px repeat(6, 100px) !important; 
                }

                /* Additional styling for fly window (SweetAlert) to prevent stacking */
                .swal2-html-container table {
                    display: block !important;
                    width: 100% !important;
                    overflow-x: auto !important;
                    white-space: nowrap !important;
                }

                /* --- TOGGLE PANEL HEADER (Gender, Visual Habits, Digital Usage, Has Old Prescription) --- */
                .toggle-panel-header {
                    flex-wrap: wrap;
                    padding: 12px 14px;
                    gap: 6px;
                }
                .toggle-panel-header .tph-label {
                    font-size: 0.75em;
                    white-space: nowrap;
                }
                .toggle-panel-header .tph-value {
                    font-size: 0.75em;
                    text-align: right;
                    white-space: normal;
                    word-break: break-word;
                }
                .toggle-panel-header .tph-arrow {
                    margin-left: 8px;
                }

                /* --- SYMPTOMS SUMMARY BAR --- */
                #btn_open_symptoms {
                    padding: 12px;
                }
                #symptom_summary {
                    font-size: 0.8em;
                    word-break: break-word;
                }
            }
            .field-done-btn {
                position: absolute !important;
                right: 8px !important;
                top: 50% !important;
                left: auto !important;
                bottom: auto !important;
                margin: 0 !important;
                transform: translateY(-50%) !important;
                z-index: 5;
                background: #00ff88;
                color: #0d0f10;
                font-weight: bold;
                font-size: 0.72em;
                letter-spacing: 0.5px;
                border: none;
                border-radius: 8px;
                padding: 10px 12px;
                box-shadow: 0 2px 10px rgba(0, 255, 136, 0.5);
                cursor: pointer;
                animation: fieldDonePulse 1.4s ease-in-out infinite;
            }
            .field-done-btn:active {
                transform: translateY(-50%) scale(0.95) !important;
            }

            .section-done-btn {
                background: #00ff88;
                color: #0d0f10;
                font-weight: bold;
                font-size: 0.78em;
                letter-spacing: 0.5px;
                border: none;
                border-radius: 8px;
                padding: 10px 20px;
                box-shadow: 0 2px 10px rgba(0, 255, 136, 0.5);
                cursor: pointer;
                animation: fieldDonePulse 1.4s ease-in-out infinite;
            }
            .section-done-btn:active {
                transform: scale(0.95);
            }
            @keyframes fieldDonePulse {
                0%, 100% { box-shadow: 0 2px 10px rgba(0, 255, 136, 0.5); }
                50% { box-shadow: 0 2px 16px rgba(0, 255, 136, 0.9); }
            }
        </style>
        <!-- button logout, back animation for logo -->
        <style>
            .neu-button.disabled {
                opacity: 0.4;
                cursor: not-allowed;
                pointer-events: none;
                filter: grayscale(1);
            }

            /* ===== New neumorphic style for Back & Logout buttons ===== */
            .neu-pill-btn {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                background: #1c1e22;
                border: none;
                border-radius: 32px;
                padding: 6px 16px 6px 6px;
                cursor: pointer;
                box-shadow:
                    6px 6px 14px rgba(0, 0, 0, 0.55),
                    -6px -6px 14px rgba(255, 255, 255, 0.03);
                transition: transform 0.15s ease, box-shadow 0.15s ease;
                font-family: inherit;
            }

            .neu-pill-btn:hover {
                box-shadow:
                    6px 6px 16px rgba(0, 0, 0, 0.6),
                    -6px -6px 16px rgba(255, 255, 255, 0.04);
            }

            .neu-pill-btn:active {
                transform: scale(0.96);
            }

            /* Overflow hidden so the icon can slide across without spilling out */
            .neu-pill-btn {
                overflow: hidden;
            }

            .neu-pill-icon {
                width: 32px;
                height: 32px;
                min-width: 32px;
                border-radius: 50%;
                background: #17181b;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow:
                    inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                    inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                    0 0 10px rgba(103, 232, 249, 0.35);
                transition: box-shadow 0.15s ease, transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            }

            /* Pressed state: icon slides to the right, text fades and slides out */
            .neu-pill-btn.pressed {
                box-shadow:
                    inset 4px 4px 10px rgba(0, 0, 0, 0.6),
                    inset -4px -4px 10px rgba(255, 255, 255, 0.03);
            }

            .neu-pill-btn.pressed .neu-pill-icon {
                transform: translateX(calc(100% + 24px));
                box-shadow:
                    inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                    inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                    0 0 18px rgba(103, 232, 249, 0.7);
            }

            .neu-pill-btn.pressed .neu-pill-text {
                opacity: 0;
                transform: translateX(15px);
            }

            .neu-pill-btn.pressed .neu-pill-icon,
            .neu-pill-btn:active .neu-pill-icon {
                box-shadow:
                    inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                    inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                    0 0 18px rgba(103, 232, 249, 0.7);
            }

            .neu-pill-icon svg {
                width: 15px;
                height: 15px;
                stroke: #7fe3f0;
                filter: drop-shadow(0 0 4px rgba(103, 232, 249, 0.8));
            }

            .neu-pill-text {
                display: flex;
                flex-direction: column;
                line-height: 1.15;
                text-align: left;
                transition: opacity 0.25s ease, transform 0.25s ease;
            }

            .neu-pill-text .line1 {
                font-weight: 700;
                font-size: 10px;
                letter-spacing: 0.4px;
                color: #f2f2f2;
            }

            .neu-pill-text .line2 {
                font-weight: 400;
                font-size: 9px;
                letter-spacing: 0.4px;
                color: #9a9da1;
            }

            /* Logout variant: warm amber/orange tone instead of cyan */
            .neu-pill-btn.logout-variant .neu-pill-icon {
                box-shadow:
                    inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                    inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                    0 0 10px rgba(255, 138, 101, 0.4);
            }

            .neu-pill-btn.logout-variant.pressed .neu-pill-icon {
                box-shadow:
                    inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                    inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                    0 0 18px rgba(255, 138, 101, 0.75);
            }

            .neu-pill-btn.logout-variant .neu-pill-icon svg {
                stroke: #ff8a65;
                filter: drop-shadow(0 0 4px rgba(255, 138, 101, 0.8));
            }

            /* ===== Logo zoom (fly window) effect ===== */
            .logo-backdrop {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0);
                backdrop-filter: blur(0px);
                -webkit-backdrop-filter: blur(0px);
                z-index: 999;
                opacity: 0;
                pointer-events: none;
                transition: background 0.3s ease, opacity 0.3s ease, backdrop-filter 0.3s ease;
            }

            .logo-backdrop.active {
                background: rgba(0, 0, 0, 0.6);
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                opacity: 1;
                pointer-events: auto;
            }

            .logo-box img {
                cursor: pointer;
                transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                            top 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                            left 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .logo-box img.zoomed {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) scale(2.8);
                z-index: 1000;
            }

            /* Center the header block (logout button + logo/name/address group)
               on PC to match how it already appears centered on mobile. Only
               the container's own horizontal position is changed here — the
               internal layout is left exactly as in the original code. */
            .header-container {
                margin-left: auto !important;
                margin-right: auto !important;
                width: fit-content !important;
            }

            /* Referral-to-specialist alert (shown at the very top when recommended) */
            .referral-alert {
                background: #4a1f1f;
                border: 2px solid #ff3355;
                border-radius: 10px;
                padding: 16px 18px;
                margin: 15px 0;
                color: #ffb3b3;
                animation: referralPulse 1.6s ease-in-out infinite;
            }
            .referral-alert strong {
                color: #ff6677;
                font-size: 1.05em;
                letter-spacing: 1px;
            }
            .referral-alert p {
                margin: 8px 0 0 0;
                color: #ffcccc;
                font-size: 0.9em;
            }
            @keyframes referralPulse {
                0%, 100% { box-shadow: 0 0 6px 0 rgba(255,51,85,0.5); border-color: #ff3355; }
                50%      { box-shadow: 0 0 22px 4px rgba(255,51,85,0.9); border-color: #ff8899; }
            }

            /* Applied to #prescription_analysis when a specialist referral is recommended */
            #prescription_analysis.analysis-warning-glow {
                animation: analysisGlow 1.6s ease-in-out infinite;
                border: 2px solid #ff3355;
                border-radius: 10px;
            }
            @keyframes analysisGlow {
                0%, 100% { box-shadow: 0 0 6px 0 rgba(255,51,85,0.4); }
                50%      { box-shadow: 0 0 20px 4px rgba(255,51,85,0.85); }
            }
        </style>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    </head>

    <body>        
        <div class="main-wrapper">
            <div class="content-area" style="flex-direction: column">
                <div class="header-container">
                    <button type="button" class="logout-btn neu-pill-btn logout-variant" id="logoutBtn" onclick="handleLogoutClick(this)">
                        <span class="neu-pill-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                <polyline points="16 17 21 12 16 7"></polyline>
                                <line x1="21" y1="12" x2="9" y2="12"></line>
                            </svg>
                        </span>
                        <span class="neu-pill-text">
                            <span class="line1">LOGOUT</span>
                        </span>
                    </button>
                
                    <div class="brand-section">
                        <div class="logo-box">
                            <img id="storeLogo" src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" style="height: 40px;" onclick="zoomInLogo(this)" ondblclick="zoomOutLogo(this)">
                        </div>
                        <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                        <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
                    </div>
                </div>
                
                <div class="main-card" style="
                margin-left: auto; 
                margin-right: auto; 
                width: 100%;">
                    <h2>CUSTOMER PRESCRIPTION</h2>
            
                    <form id="examForm" action="" method="POST" onsubmit="return showSummary(event)">
                        <div class="form-grid">

                            <!-- EXAMINATION CODE AND SEQUANCE-->
                            <input type="hidden" name="after_save_redirect" id="after_save_redirect" value="self">
                            <input type="hidden" id="base_sequence" value="<?php echo $seq_padded; ?>">
                            <input type="hidden" id="hidden_exam_code" name="examination_code" value="<?php echo $exam_code; ?>">
                            <input type="hidden" name="invoice_number" id="invoice_decision" value="00">

                            <!-- DATE -->
                            <div class="input-group">
                                <label for="examination_date_display">EXAMINATION DATE</label>
                                <input type="text" inputmode="tel" id="examination_date_display" 
                                    value="<?php echo date('d/m/Y'); ?>" 
                                    placeholder="Example: 2/21 or 21/2/25, also can use . (dot)" 
                                    style="color: #00ff88; font-weight: bold; text-align: center;"
                                    autocomplete="off">
                                
                                <input type="hidden" id="examination_date" name="examination_date" value="<?php echo date('Y-m-d'); ?>">
                            </div>

                            <!-- SEQUENCE NO. -->
                            <div class="input-group">
                                <label>SEQUENCE NO.</label>
                                <input type="text" value="<?php echo $seq_padded; ?>" readonly 
                                    style="background: #1a1c1d; color: #00ff88; font-weight: bold; text-align: center;" 
                                    tabindex="-1">
                            </div>

                            <!-- NAME -->
                            <div class="input-group" style="flex: 0 0 100%; max-width: 100%; grid-column: 1 / -1; width: 100% !important;">
                                <label for="customer_name">NAME</label>
                                <div style="position: relative;">
                                    <input type="text" 
                                    id="customer_name" 
                                    name="customer_name" required 
                                    placeholder="LENZA CUSTOMER" 
                                    style="text-transform: uppercase; padding: 20px; padding-right: 70px;">
                                    <button type="button" id="customer_name_done_btn" class="field-done-btn" style="display: none;">DONE ▼</button>
                                </div>
                            </div>

                            <!-- GENDER -->
                            <div class="input-group" style="flex: 0 0 100%; max-width: 100%; grid-column: 1 / -1; width: 100% !important;">
                                <input type="hidden" name="gender" id="gender" value="FEMALE">
                                <div class="toggle-panel-header" id="gender_header" onclick="togglePanel('gender_panel', this, 'gender_display')">
                                    <span class="tph-label">GENDER</span>
                                    <span class="tph-value" id="gender_display">FEMALE</span>
                                    <span class="tph-arrow">▼</span>
                                </div>
                                <div class="toggle-panel-body" id="gender_panel">
                                    <div class="selection-wrapper">
                                        <button style="min-width: 100px;" value="FEMALE" type="button" class="neu-btn active" onclick="toggleNeuPanel(this, 'gender', 'gender_display', 'gender_panel', 'gender_header')">
                                            <span>FEMALE</span>
                                            <div class="led"></div>
                                        </button>
                                        <button style="min-width: 100px;" value="MALE" type="button" class="neu-btn" onclick="toggleNeuPanel(this, 'gender', 'gender_display', 'gender_panel', 'gender_header')">
                                            <span>MALE</span>
                                            <div class="led"></div>
                                        </button>
                                    </div>
                                </div>
                            </div>
            
                            <!-- AGE -->
                            <div class="input-group" style="flex: 0 0 100%; max-width: 100%; grid-column: 1 / -1; width: 100% !important;">
                                <label for="age">AGE / BIRTH YEAR</label>
                                <div style="position: relative;">
                                    <input type="text" id="age" name="age" 
                                        inputmode="tel"
                                        placeholder="Example: 25 (Age) or .96 (Year)" 
                                        autocomplete="off"
                                        style="padding-right: 70px;">
                                    <button type="button" id="age_done_btn" class="field-done-btn" style="display: none;">DONE ▼</button>
                                </div>
                            </div>

                            <!-- VISUAL HABITS -->
                            <div class="input-group" style="flex: 0 0 100%; max-width: 100%; grid-column: 1 / -1; width: 100% !important;">
                                <input type="hidden" name="visual_habit" id="visual_habit" value="1">
                                <div class="toggle-panel-header" id="visual_habit_header" onclick="togglePanel('visual_habit_panel', this, 'visual_habit_display')">
                                    <span class="tph-label">VISUAL HABITS</span>
                                    <span class="tph-value" id="visual_habit_display">INDOOR</span>
                                    <span class="tph-arrow">▼</span>
                                </div>
                                <div class="toggle-panel-body" id="visual_habit_panel">
                                    <div class="selection-wrapper">
                                        <button style="min-width: 100px;" value="1" type="button" class="neu-btn active" onclick="toggleNeuPanel(this, 'visual_habit', 'visual_habit_display', 'visual_habit_panel', 'visual_habit_header')">
                                            <span>INDOOR</span>
                                            <div class="led"></div>
                                        </button>
                                        <button style="min-width: 100px;" value="2" type="button" class="neu-btn" onclick="toggleNeuPanel(this, 'visual_habit', 'visual_habit_display', 'visual_habit_panel', 'visual_habit_header')">
                                            <span>OUTDOOR</span>
                                            <div class="led"></div>
                                        </button>
                                        <button style="min-width: 100px;" value="3" type="button" class="neu-btn" onclick="toggleNeuPanel(this, 'visual_habit', 'visual_habit_display', 'visual_habit_panel', 'visual_habit_header')">
                                            <span>BOTH</span>
                                            <div class="led"></div>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- DIGITAL DEVICE USAGE -->
                            <div class="input-group" style="flex: 0 0 100%; max-width: 100%; grid-column: 1 / -1; width: 100% !important;">
                                <input type="hidden" name="digital_usage" id="digital_usage" value="1">
                                <div class="toggle-panel-header" id="digital_usage_header" onclick="togglePanel('digital_usage_panel', this, 'digital_usage_display')">
                                    <span class="tph-label">DIGITAL DEVICE USAGE</span>
                                    <span class="tph-value" id="digital_usage_display">LOW (&lt; 2H)</span>
                                    <span class="tph-arrow">▼</span>
                                </div>
                                <div class="toggle-panel-body" id="digital_usage_panel">
                                    <div class="selection-wrapper">
                                        <button style="min-width: 100px;" value="1" type="button" class="neu-btn active" onclick="toggleNeuPanel(this, 'digital_usage', 'digital_usage_display', 'digital_usage_panel', 'digital_usage_header')">
                                            <span>LOW<br>(&lt; 2H)</span>
                                            <div class="led"></div>
                                        </button>
                                        <button style="min-width: 100px;" value="2" type="button" class="neu-btn" onclick="toggleNeuPanel(this, 'digital_usage', 'digital_usage_display', 'digital_usage_panel', 'digital_usage_header')">
                                            <span>MODERATE<br>(2H - 5H)</span>
                                            <div class="led"></div>
                                        </button>
                                        <button style="min-width: 100px;" value="3" type="button" class="neu-btn" onclick="toggleNeuPanel(this, 'digital_usage', 'digital_usage_display', 'digital_usage_panel', 'digital_usage_header')">
                                            <span>HIGH<br>(&gt; 5H)</span>
                                            <div class="led"></div>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- VISION NEED (muncul otomatis jika umur >= 39) -->
                            <div id="vision_need_section" class="input-group">
                                <div style="background: #25282a; padding: 18px; border-radius: 14px; border: 1px solid #ffcc0033; box-shadow: inset 2px 2px 5px #1a1c1d; width: 100%;">
                                    <div class="vision-need-header-row">
                                        <div class="vision-need-title">⚑ VISION NEED</div>
                                        <span class="vision-need-badge">AGE ≥ 39</span>
                                    </div>
                                    <p style="text-align:center; font-size:0.72em; color:#888; margin: 0 0 14px 0; letter-spacing:0.5px;">Select required vision needs (multiple selection allowed)</p>
                                    
                                    <div class="vision-need-wrapper">
                                        <button type="button" 
                                            id="btn_need_distance"
                                            class="vision-need-btn" 
                                            onclick="toggleVisionNeed(this, 'need_distance')">
                                            <div class="vn-icon">🔭</div>
                                            <span>DISTANCE</span>
                                            <small style="font-size:0.75em; color: inherit; opacity:0.7;">Far Vision</small>
                                            <div class="vn-led"></div>
                                        </button>

                                        <button type="button" 
                                            id="btn_need_intermediate"
                                            class="vision-need-btn" 
                                            onclick="toggleVisionNeed(this, 'need_intermediate')">
                                            <div class="vn-icon">🖥️</div>
                                            <span>INTERMEDIATE</span>
                                            <small style="font-size:0.75em; color: inherit; opacity:0.7;">Mid Range</small>
                                            <div class="vn-led"></div>
                                        </button>

                                        <button type="button" 
                                            id="btn_need_near"
                                            class="vision-need-btn" 
                                            onclick="toggleVisionNeed(this, 'need_near')">
                                            <div class="vn-icon">📖</div>
                                            <span>NEAR</span>
                                            <small style="font-size:0.75em; color: inherit; opacity:0.7;">Reading/Close-up</small>
                                            <div class="vn-led"></div>
                                        </button>
                                    </div>
                                </div>

                                <input type="checkbox" name="need_distance"     id="chk_need_distance"     style="display:none;" value="1">
                                <input type="checkbox" name="need_intermediate" id="chk_need_intermediate" style="display:none;" value="1">
                                <input type="checkbox" name="need_near"         id="chk_need_near"         style="display:none;" value="1">

                                <!-- DONE -> scroll to Symptoms -->
                                <div style="margin-top: 14px; display: flex; justify-content: center; width: 100%;">
                                    <button type="button" id="vision_need_done_btn" class="section-done-btn">DONE ▼</button>
                                </div>
                            </div>
            
                            <!-- SYMPTOMS -->
                            <div class="input-group" style="flex: 0 0 100%; max-width: 100%; grid-column: 1 / -1; width: 100% !important;">
                                <div style="display:flex; align-items:center; justify-content:space-between;">
                                    <label style="margin-bottom:0;">SYMPTOMS / COMPLAINTS</label>
                                    <button type="button" id="btn_open_symptom_settings" title="Manage symptom options"
                                        onclick="openSymptomSettings()"
                                        style="background:#25282a; border:1px solid #444; color:#00ff88; border-radius:8px; width:34px; height:34px; cursor:pointer; font-size:1em; display:flex; align-items:center; justify-content:center; box-shadow: inset 2px 2px 5px #1a1c1d;">
                                        ⚙️
                                    </button>
                                </div>
                                <div id="btn_open_symptoms" 
                                style="background: #25282a;
                                width: 100%; 
                                text-align: center;
                                padding: 15px; 
                                border-radius: 12px;
                                border: 1px solid #444;
                                cursor: pointer;
                                display: flex;
                                justify-content: space-between;
                                align-items: center;
                                box-shadow: inset 2px 2px 5px #1a1c1d;">
                                    <span id="symptom_summary" style="color: #888; font-size: 0.9em;">NO SYMPTOMS SELECTED</span>
                                    <span id="arrow_icon" style="color: #00ff88;">▼</span>
                                </div>

                                <div id="symptoms_panel" style="display: none; background: #2b2e30; padding: 25px; border-radius: 15px; margin-top: 10px; border: 1px solid #00ff8844; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
                                    <!-- AUTO-DETECTED symptoms (MYOPIA, HYPEROPIA, ASTIGMATISM, PRESBYOPIA) are no longer manual buttons -->
                                    <!-- They are automatically detected from the New Prescription values -->
                                    <div class="symptoms-grid" id="symptoms_grid">
                                        <?php
                                            // Symptom options are now loaded from data_json/symptoms.json
                                            // so they can be managed via the 🏷️ settings fly window.
                                            $symptomsDataFile = __DIR__ . '/data_json/symptoms.json';
                                            $symptomOptions = [];
                                            if (file_exists($symptomsDataFile)) {
                                                $decoded = json_decode(file_get_contents($symptomsDataFile), true);
                                                if (is_array($decoded)) {
                                                    $symptomOptions = $decoded;
                                                }
                                            }

                                            foreach ($symptomOptions as $opt) {
                                                $label = htmlspecialchars($opt['label'] ?? '');
                                                $value = htmlspecialchars($opt['value'] ?? $label, ENT_QUOTES);
                                                $detailId = $opt['detail_id'] ?? null;
                                                $fullWidth = !empty($opt['full_width']);

                                                $styleAttr = $fullWidth ? ' style="grid-column: 1 / -1;"' : '';
                                                $onclickAttr = $detailId
                                                    ? "toggleSymptom(this, '{$value}', '" . htmlspecialchars($detailId, ENT_QUOTES) . "')"
                                                    : "toggleSymptom(this, '{$value}')";

                                                echo '<button type="button" class="neu-btn symptom-btn"' . $styleAttr . ' onclick="' . $onclickAttr . '"><span>' . $label . '</span><div class="led"></div></button>';
                                            }
                                        ?>
                                    </div>

                                    <!-- DIABETES MILITUS DATA & HYPERTENSION DATA moved to a fly window
                                         (see #condition_details_overlay below) instead of showing inline here. -->

                                    <textarea name="other_symptoms" id="other_symptoms" 
                                    placeholder="OTHER COMPLAINTS..." 
                                    oninput="this.value = this.value.toUpperCase()"
                                    style="width: 100%; margin-top: 15px; background:#1a1c1d; color:white; border:1px solid #444; padding:10px; border-radius:10px;"></textarea>
                                    
                                    <div style="text-align: center;">
                                        <button type="button" class="submit-main" style="margin-top: 20px; font-size: 0.8em; padding: 10px; width: 150px;" onclick="closePanel()">DONE</button>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="symptom_list_json" id="symptom_list_json" value="[]">

                            <!-- ========================================================= -->
                            <!-- === SYMPTOM OPTIONS SETTINGS FLY WINDOW ================== -->
                            <!-- ========================================================= -->
                            <div id="symptom_settings_overlay" style="
                                display:none; position:fixed; inset:0; z-index:9999;
                                background:rgba(0,0,0,0.82); backdrop-filter:blur(4px);
                                overflow-y:auto; padding:20px; box-sizing:border-box;">

                                <div style="
                                    background:#1a1d22; border:1px solid #00ff8844; border-radius:16px;
                                    max-width:480px; width:100%; margin:0 auto; padding:24px;
                                    box-sizing:border-box; position:relative;">

                                    <!-- Fly window header -->
                                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:18px;">
                                        <div>
                                            <div style="color:#00ff88; font-size:0.9em; font-weight:800; letter-spacing:2px;">🏷️ SYMPTOM OPTIONS</div>
                                            <div style="color:#555; font-size:0.68em; margin-top:3px;">Add, edit, or remove symptom buttons</div>
                                        </div>
                                        <button type="button" onclick="closeSymptomSettings()"
                                            style="background:#2a2d30; border:1px solid #444; color:#aaa; border-radius:8px; padding:6px 12px; cursor:pointer; font-size:0.8em;">
                                            ✕ Close
                                        </button>
                                    </div>

                                    <!-- Add new option -->
                                    <div style="display:flex; gap:8px; margin-bottom:16px;">
                                        <input type="text" id="new_symptom_label" placeholder="NEW SYMPTOM NAME..."
                                            oninput="this.value = this.value.toUpperCase()"
                                            style="flex:1; min-width:0; background:#0d0f12; border:1px solid #252830; border-radius:8px; color:#e5e7eb; font-size:0.85em; padding:10px; box-sizing:border-box;">
                                        <button type="button" onclick="addSymptomOption()"
                                            style="background:#1a2c1a; border:1px solid #00ff88; color:#00ff88; border-radius:8px; padding:0 16px; cursor:pointer; font-weight:700; font-size:0.85em;">
                                            + ADD
                                        </button>
                                    </div>

                                    <!-- List of current options -->
                                    <div id="symptom_settings_list" style="display:flex; flex-direction:column; gap:8px; max-height:50vh; overflow-y:auto;">
                                        <!-- Populated dynamically by JS -->
                                    </div>

                                    <div id="symptom_settings_empty" style="display:none; text-align:center; color:#555; font-size:0.8em; padding:20px 0;">
                                        No symptom options yet. Add one above.
                                    </div>
                                </div>
                            </div>

                            <!-- ========================================================= -->
                            <!-- === CONDITION DETAILS FLY WINDOW (DIABETES / HYPERTENSION) === -->
                            <!-- ========================================================= -->
                            <!-- Opens automatically when DIABETES and/or HYPERTENSION is selected
                                 in the Symptoms card, instead of showing the extra fields inline. -->
                            <div id="condition_details_overlay" style="
                                display:none; position:fixed; inset:0; z-index:9999;
                                background:rgba(0,0,0,0.82); backdrop-filter:blur(4px);
                                overflow-y:auto; padding:20px; box-sizing:border-box;
                                align-items:flex-start; justify-content:center;">

                                <div style="
                                    background:#1a1d22; border:1px solid #00ff8844; border-radius:16px;
                                    max-width:480px; width:100%; margin:40px auto; padding:24px;
                                    box-sizing:border-box; position:relative;">

                                    <!-- Fly window header -->
                                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:18px;">
                                        <div>
                                            <div style="color:#00ff88; font-size:0.9em; font-weight:800; letter-spacing:2px;">🩺 CONDITION DETAILS</div>
                                            <div style="color:#555; font-size:0.68em; margin-top:3px;">Fill in the extra data for the selected condition(s)</div>
                                        </div>
                                        <button type="button" onclick="closeConditionDetails()"
                                            style="background:#2a2d30; border:1px solid #444; color:#aaa; border-radius:8px; padding:6px 12px; cursor:pointer; font-size:0.8em;">
                                            ✕ Close
                                        </button>
                                    </div>

                                    <div id="dm_detail" class="hidden-detail" style="margin-top:0;">
                                        <label>DIABETES MILITUS DATA</label>
                                        <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                            <input type="text" name="dm_sugar" placeholder="Sugar Level (mg/dL)" style="flex:1 1 120px; min-width:0; font-size:0.85em;">
                                            <select name="dm_status" style="flex:1 1 110px; min-width:0; font-size:0.82em; padding:8px 4px;"><option>CONTROLLED</option><option>UNCONTROLLED</option></select>
                                        </div>
                                    </div>

                                    <div id="ht_detail" class="hidden-detail">
                                        <label>HYPERTENSION DATA</label>
                                        <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                            <input type="text" name="ht_pressure" placeholder="Tension (e.g. 140/90)" style="flex:1 1 120px; min-width:0; font-size:0.85em;">
                                            <select name="ht_status" style="flex:1 1 110px; min-width:0; font-size:0.82em; padding:8px 4px;"><option>CONTROLLED</option><option>UNCONTROLLED</option></select>
                                        </div>
                                    </div>

                                    <div style="text-align:center;">
                                        <button type="button" class="submit-main" style="margin-top: 20px; font-size: 0.8em; padding: 10px; width: 150px;" onclick="closeConditionDetails()">DONE</button>
                                    </div>
                                </div>
                            </div>

                            <!-- HAS OLD PRESCRIPTION? — Collapsible Toggle -->
                            <div style="flex: 0 0 100%; max-width: 100%; grid-column: 1 / -1; width: 100% !important;">
                                <input type="hidden" name="has_old_prescription" id="has_old_prescription_input" value="no">

                                <!-- Toggle Header -->
                                <div id="old_pres_header" class="toggle-panel-header" onclick="toggleOldPresSection()">
                                    <span class="tph-label">HAS OLD PRESCRIPTION?</span>
                                    <span id="old_pres_status_label" class="tph-value">NO</span>
                                    <span id="old_pres_arrow" class="tph-arrow">▼</span>
                                </div>

                                <!-- Collapsible Body: button opsi + tabel -->
                                <div id="old_pres_body" style="display: none; margin-top: 10px;">

                                    <!-- YES / NO Button Group -->
                                    <div id="old_prescription_option" class="selection-wrapper" style="margin-bottom: 14px;">
                                        <button value="no" type="button" class="neu-btn active" onclick="toggleNeu(this, 'has_old_prescription_input', true)">
                                            <span>NO</span>
                                            <div class="led"></div>
                                        </button>
                                        <button value="yes" type="button" class="neu-btn" onclick="toggleNeu(this, 'has_old_prescription_input', true)">
                                            <span>YES</span>
                                            <div class="led"></div>
                                        </button>
                                    </div>

                                    <!-- CUSTOMER OLD PRESCRIPTION TABLE (muncul hanya jika YES) -->
                                    <div id="old_prescript" style="display: none; width: 100%;">
                                        <div class="prescription-card">
                                            <div style="display:flex; align-items:center; justify-content:center; gap:12px; margin-bottom:15px; flex-wrap:wrap;">
                                                <h3 style="color: #00ff88; font-size: 0.9em; text-align: center; margin:0; letter-spacing: 1px;">OLD PRESCRIPTION DATA</h3>
                                                <button type="button" id="btn_open_lensmeter"
                                                    onclick="openLensmeterModal()"
                                                    style="background:#1a2c2a; border:1px solid #00ff8866; color:#00ff88; border-radius:8px; padding:6px 14px; font-size:0.75em; font-weight:700; letter-spacing:1px; cursor:pointer; transition:all 0.2s;">
                                                    🔬 USE LENSOMETER
                                                </button>
                                            </div>
                                            
                                            <div class="prescription-table">
                                                <div class="pres-grid header">
                                                    <div>EYE</div>
                                                    <div>SPH</div>
                                                    <div>CYL</div>
                                                    <div>AXIS</div>
                                                    <div>ADD</div>
                                                </div>

                                                <div class="pres-grid row">
                                                    <div class="eye-label">RIGHT</div>
                                                    <input type="text" inputmode="tel" name="old_prescript_R_sph" placeholder="0.00">
                                                    <input type="text" inputmode="tel" name="old_prescript_R_cyl" placeholder="0.00">
                                                    <input type="text" inputmode="tel" name="old_prescript_R_ax" placeholder="0">
                                                    <input type="text" inputmode="tel" name="old_prescript_R_add" placeholder="0.00">
                                                </div>

                                                <div class="pres-grid row">
                                                    <div class="eye-label">LEFT</div>
                                                    <input type="text" inputmode="tel" name="old_prescript_L_sph" placeholder="0.00">
                                                    <input type="text" inputmode="tel" name="old_prescript_L_cyl" placeholder="0.00">
                                                    <input type="text" inputmode="tel" name="old_prescript_L_ax" placeholder="0">
                                                    <input type="text" inputmode="tel" name="old_prescript_L_add" placeholder="0.00">
                                                </div>
                                            </div>

                                            <div style="text-align: center;">
                                                <button type="button" class="submit-main" style="margin-top: 20px; font-size: 0.8em; padding: 10px; width: 150px;" onclick="closeOldPresAndOpenReview()">DONE</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ========================================================= -->
                                    <!-- === LENSOMETER MODAL ===================================== -->
                                    <!-- ========================================================= -->
                                    <div id="lensmeter_modal_overlay" style="
                                        display:none; position:fixed; inset:0; z-index:9999;
                                        background:rgba(0,0,0,0.82); backdrop-filter:blur(4px);
                                        overflow-y:auto; padding:20px; box-sizing:border-box;">

                                        <div style="
                                            background:#1a1d22; border:1px solid #00ff8844; border-radius:16px;
                                            max-width:540px; width:100%; margin:0 auto; padding:24px;
                                            box-sizing:border-box; position:relative;">

                                            <!-- Modal header -->
                                            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
                                                <div>
                                                    <div style="color:#00ff88; font-size:0.9em; font-weight:800; letter-spacing:2px;">🔬 LENSOMETER</div>
                                                    <div style="color:#555; font-size:0.68em; margin-top:3px;">Enter readings → select which eye → Apply</div>
                                                </div>
                                                <button type="button" onclick="closeLensmeterModal()"
                                                    style="background:#2a2d30; border:1px solid #444; color:#aaa; border-radius:8px; padding:6px 12px; cursor:pointer; font-size:0.8em;">
                                                    ✕ Close
                                                </button>
                                            </div>

                                            <!-- Eye tabs -->
                                            <div style="display:flex; gap:10px; margin-bottom:18px;">
                                                <button type="button" id="lm_tab_od"
                                                    onclick="lmSwitchEye('od')"
                                                    style="flex:1; padding:10px; border-radius:8px; border:1px solid #00ff88; background:#1a2c1a; color:#00ff88; font-weight:700; font-size:0.82em; cursor:pointer; letter-spacing:1px;">
                                                    OD · RIGHT
                                                </button>
                                                <button type="button" id="lm_tab_os"
                                                    onclick="lmSwitchEye('os')"
                                                    style="flex:1; padding:10px; border-radius:8px; border:1px solid #333; background:#1a1d22; color:#555; font-weight:700; font-size:0.82em; cursor:pointer; letter-spacing:1px;">
                                                    OS · LEFT
                                                </button>
                                            </div>

                                            <!-- OD panel -->
                                            <div id="lm_panel_od" style="display:block;">
                                                <div style="background:#13151a; border:1px solid #252830; border-left:3px solid #34d399; border-radius:10px; padding:14px; margin-bottom:10px;">
                                                    <div style="color:#34d399; font-size:0.7em; font-weight:700; letter-spacing:1px; margin-bottom:12px;">── 2-LINE FOCUS</div>
                                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                                                        <div>
                                                            <label style="display:block; font-size:0.68em; color:#555; font-weight:700; letter-spacing:0.6px; margin-bottom:4px;">POWER</label>
                                                            <input type="tel" inputmode="numeric" id="lm_od_dua_power" placeholder="0.00"
                                                                onfocus="this.select()" oninput="lmLiveCalc()"
                                                                style="width:100%; background:#0d0f12; border:1px solid #252830; border-radius:6px; color:#e5e7eb; font-size:16px; font-weight:700; padding:10px 8px; text-align:center; outline:none; box-sizing:border-box;">
                                                        </div>
                                                        <div>
                                                            <label style="display:block; font-size:0.68em; color:#555; font-weight:700; letter-spacing:0.6px; margin-bottom:4px;">AXIS (°)</label>
                                                            <input type="number" id="lm_od_dua_axis" placeholder="0" min="0" max="180"
                                                                onfocus="this.select()" oninput="lmAutoFillTigaAxis('od')"
                                                                style="width:100%; background:#0d0f12; border:1px solid #252830; border-radius:6px; color:#e5e7eb; font-size:16px; font-weight:700; padding:10px 8px; text-align:center; outline:none; box-sizing:border-box;">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div style="background:#13151a; border:1px solid #252830; border-left:3px solid #fb923c; border-radius:10px; padding:14px;">
                                                    <div style="color:#fb923c; font-size:0.7em; font-weight:700; letter-spacing:1px; margin-bottom:12px;">│││ 3-LINE FOCUS</div>
                                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                                                        <div>
                                                            <label style="display:block; font-size:0.68em; color:#555; font-weight:700; letter-spacing:0.6px; margin-bottom:4px;">POWER</label>
                                                            <input type="tel" inputmode="numeric" id="lm_od_tiga_power" placeholder="0.00"
                                                                onfocus="this.select()" oninput="lmLiveCalc()"
                                                                style="width:100%; background:#0d0f12; border:1px solid #252830; border-radius:6px; color:#e5e7eb; font-size:16px; font-weight:700; padding:10px 8px; text-align:center; outline:none; box-sizing:border-box;">
                                                        </div>
                                                        <div>
                                                            <label style="display:block; font-size:0.68em; color:#555; font-weight:700; letter-spacing:0.6px; margin-bottom:4px;">AXIS (°)</label>
                                                            <input type="number" id="lm_od_tiga_axis" placeholder="0" min="0" max="180"
                                                                onfocus="this.select()" oninput="lmAutoFillDuaAxis('od')"
                                                                style="width:100%; background:#0d0f12; border:1px solid #252830; border-radius:6px; color:#e5e7eb; font-size:16px; font-weight:700; padding:10px 8px; text-align:center; outline:none; box-sizing:border-box;">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- OS panel -->
                                            <div id="lm_panel_os" style="display:none;">
                                                <div style="background:#13151a; border:1px solid #252830; border-left:3px solid #34d399; border-radius:10px; padding:14px; margin-bottom:10px;">
                                                    <div style="color:#34d399; font-size:0.7em; font-weight:700; letter-spacing:1px; margin-bottom:12px;">── 2-LINE FOCUS</div>
                                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                                                        <div>
                                                            <label style="display:block; font-size:0.68em; color:#555; font-weight:700; letter-spacing:0.6px; margin-bottom:4px;">POWER</label>
                                                            <input type="tel" inputmode="numeric" id="lm_os_dua_power" placeholder="0.00"
                                                                onfocus="this.select()" oninput="lmLiveCalc()"
                                                                style="width:100%; background:#0d0f12; border:1px solid #252830; border-radius:6px; color:#e5e7eb; font-size:16px; font-weight:700; padding:10px 8px; text-align:center; outline:none; box-sizing:border-box;">
                                                        </div>
                                                        <div>
                                                            <label style="display:block; font-size:0.68em; color:#555; font-weight:700; letter-spacing:0.6px; margin-bottom:4px;">AXIS (°)</label>
                                                            <input type="number" id="lm_os_dua_axis" placeholder="0" min="0" max="180"
                                                                onfocus="this.select()" oninput="lmAutoFillTigaAxis('os')"
                                                                style="width:100%; background:#0d0f12; border:1px solid #252830; border-radius:6px; color:#e5e7eb; font-size:16px; font-weight:700; padding:10px 8px; text-align:center; outline:none; box-sizing:border-box;">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div style="background:#13151a; border:1px solid #252830; border-left:3px solid #fb923c; border-radius:10px; padding:14px;">
                                                    <div style="color:#fb923c; font-size:0.7em; font-weight:700; letter-spacing:1px; margin-bottom:12px;">│││ 3-LINE FOCUS</div>
                                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                                                        <div>
                                                            <label style="display:block; font-size:0.68em; color:#555; font-weight:700; letter-spacing:0.6px; margin-bottom:4px;">POWER</label>
                                                            <input type="tel" inputmode="numeric" id="lm_os_tiga_power" placeholder="0.00"
                                                                onfocus="this.select()" oninput="lmLiveCalc()"
                                                                style="width:100%; background:#0d0f12; border:1px solid #252830; border-radius:6px; color:#e5e7eb; font-size:16px; font-weight:700; padding:10px 8px; text-align:center; outline:none; box-sizing:border-box;">
                                                        </div>
                                                        <div>
                                                            <label style="display:block; font-size:0.68em; color:#555; font-weight:700; letter-spacing:0.6px; margin-bottom:4px;">AXIS (°)</label>
                                                            <input type="number" id="lm_os_tiga_axis" placeholder="0" min="0" max="180"
                                                                onfocus="this.select()" oninput="lmAutoFillDuaAxis('os')"
                                                                style="width:100%; background:#0d0f12; border:1px solid #252830; border-radius:6px; color:#e5e7eb; font-size:16px; font-weight:700; padding:10px 8px; text-align:center; outline:none; box-sizing:border-box;">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Result preview -->
                                            <div id="lm_result_preview" style="display:none; margin-top:16px; background:#13151a; border:1px solid #252830; border-radius:10px; padding:14px;">
                                                <div style="font-size:0.68em; font-weight:700; color:#555; letter-spacing:1px; text-transform:uppercase; margin-bottom:10px;">Calculation Result</div>
                                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                                                    <!-- Original -->
                                                    <div>
                                                        <div style="font-size:0.65em; color:#888; letter-spacing:1px; text-transform:uppercase; margin-bottom:6px;">Original</div>
                                                        <div id="lm_preview_orig" style="font-family:monospace; font-size:0.85em; line-height:2; color:#e5e7eb;"></div>
                                                    </div>
                                                    <!-- Transposition -->
                                                    <div>
                                                        <div style="font-size:0.65em; color:#f59e0b; letter-spacing:1px; text-transform:uppercase; margin-bottom:6px;">⇄ Transposition</div>
                                                        <div id="lm_preview_trans" style="font-family:monospace; font-size:0.85em; line-height:2; color:#e5e7eb;"></div>
                                                    </div>
                                                </div>
                                                <div id="lm_which_used" style="margin-top:10px; font-size:0.72em; color:#00ff88; text-align:center; font-weight:700; letter-spacing:0.5px;"></div>
                                            </div>

                                            <!-- Action buttons -->
                                            <div style="display:flex; gap:10px; margin-top:18px; flex-wrap:wrap;">
                                                <button type="button" id="lm_btn_apply_od"
                                                    onclick="lmApply('od')"
                                                    style="flex:1; padding:13px; background:#1a2c1a; border:1px solid #00ff8866; color:#00ff88; border-radius:8px; font-weight:700; font-size:0.8em; letter-spacing:1px; cursor:pointer;">
                                                    APPLY → RIGHT (OD)
                                                </button>
                                                <button type="button" id="lm_btn_apply_os"
                                                    onclick="lmApply('os')"
                                                    style="flex:1; padding:13px; background:#1a2c1a; border:1px solid #00ff8866; color:#00ff88; border-radius:8px; font-weight:700; font-size:0.8em; letter-spacing:1px; cursor:pointer;">
                                                    APPLY → LEFT (OS)
                                                </button>
                                            </div>
                                            <div style="margin-top:10px; text-align:center;">
                                                <button type="button" onclick="lmApplyBoth()"
                                                    style="width:100%; padding:13px; background:#0a2020; border:1px solid #00ccff55; color:#00ccff; border-radius:8px; font-weight:700; font-size:0.8em; letter-spacing:1px; cursor:pointer;">
                                                    ✦ APPLY BOTH EYES AT ONCE
                                                </button>
                                            </div>
                                            <div style="margin-top:8px; text-align:center;">
                                                <button type="button" onclick="lmReset()"
                                                    style="background:transparent; border:none; color:#555; font-size:0.72em; cursor:pointer; text-decoration:underline; letter-spacing:0.5px;">
                                                    ↺ Reset Lensometer
                                                </button>
                                            </div>

                                        </div><!-- end modal inner -->
                                    </div>
                                    <!-- ========================================================= -->
                                    <!-- === END LENSOMETER MODAL ================================ -->
                                    <!-- ========================================================= -->

                                </div>
                            </div>
                            
                            <!-- CUSTOMER NEW PRESCIPTION -->
                            <div id="new_prescript_section" style="grid-column: 1 / -1; width: 100%; margin-top: 30px;">
                                <div class="prescription-card" style="border: 1px solid #00ff8866; box-shadow: 0 0 15px rgba(0, 255, 136, 0.1);">
                                    <h3 style="color: #00ff88; font-size: 1em; text-align: center; margin-bottom: 15px; letter-spacing: 2px;">NEW PRESCRIPTION</h3>
                                    
                                    <div class="prescription-table">
                                        <div class="pres-grid header" style="grid-template-columns: 1fr repeat(6, 1fr);">
                                            <div>EYE</div>
                                            <div style="color: #ffcc00;">UCVA</div>
                                            <div>SPH</div>
                                            <div>CYL</div>
                                            <div>AXIS</div>
                                            <div>ADD</div>
                                            <div>VA</div>
                                        </div>

                                        <div class="pres-grid row" style="grid-template-columns: 1fr repeat(6, 1fr); gap: 8px;">
                                            <div class="eye-label">RIGHT</div>
                                            <input type="text" inputmode="tel" name="ucva_r" class="va-input" placeholder="20/20" style="border-color: #ffcc0044 !important;">
                                            <input type="text" inputmode="tel" name="new_r_sph" placeholder="0.00">
                                            <input type="text" inputmode="tel" name="new_r_cyl" placeholder="0.00">
                                            <input type="text" inputmode="tel" name="new_r_ax" placeholder="0">
                                            <input type="text" inputmode="tel" name="new_r_add" placeholder="0.00">
                                            <input type="text" inputmode="tel" name="new_r_va" class="va-input" placeholder="20/20" onclick="this.select()">
                                        </div>

                                        <div class="pres-grid row" style="grid-template-columns: 1fr repeat(6, 1fr); gap: 8px;">
                                            <div class="eye-label">LEFT</div>
                                            <input type="text" inputmode="tel" name="ucva_l" class="va-input" placeholder="20/20" style="border-color: #ffcc0044 !important;">
                                            <input type="text" inputmode="tel" name="new_l_sph" placeholder="0.00">
                                            <input type="text" inputmode="tel" name="new_l_cyl" placeholder="0.00">
                                            <input type="text" inputmode="tel" name="new_l_ax" placeholder="0">
                                            <input type="text" inputmode="tel" name="new_l_add" placeholder="0.00">
                                            <input type="text" inputmode="tel" name="new_l_va" class="va-input" placeholder="20/20" onclick="this.select()">
                                        </div>

                                        <div style="margin-top: 20px; display: flex; justify-content: center;">
                                            <div class="input-group" style="width: 200px;">
                                                <label style="font-size: 0.75em; color: #888; text-align: center; display: block;">PD (PUPILLARY DISTANCE)</label>
                                                <input type="text" inputmode="tel" name="pd_dist" placeholder="62/60" style="background: #1a1c1d; border: 1px solid #333; color: #00ff88; border-radius: 8px; padding: 12px; width: 100%; text-align: center; font-family: monospace;">
                                            </div>
                                        </div>

                                        <!-- AI ANALYSIS TRIGGER -->
                                        <div style="margin-top: 25px; display: flex; justify-content: center;">
                                            <button type="button" id="btn_ai_analyze" class="ai-analyze-btn" onclick="requestAIAnalysis()">
                                                <span style="letter-spacing: 2px;">✦ GENERATE AI ANALYSIS ✦</span>
                                            </button>
                                        </div>

                                        <!-- DONE -> scroll to Additional Notes -->
                                        <div style="margin-top: 15px; display: flex; justify-content: center;">
                                            <button type="button" id="new_prescription_done_btn" class="section-done-btn">DONE ▼</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- PRESCRIPTION ANALYSIS PANEL (AUTO-GENERATED) -->
                            <div id="analysis_referral_alert"></div>
                            <div id="prescription_analysis" class="collapsible">
                                <h3 class="analysis-title collapsible-header" onclick="toggleCollapsible(this)">
                                    <span>◆ CLINICAL ANALYSIS &amp; INTERPRETATION ◆</span>
                                    <span class="chevron">▼</span>
                                </h3>
                                <div class="collapsible-content">
                                    <div id="analysis_main_findings"></div>
                                    <div id="analysis_right" class="analysis-eye-block collapsible"></div>
                                    <div id="analysis_left" class="analysis-eye-block collapsible"></div>
                                    <div id="analysis_summary"></div>
                                    <p class="analysis-disclaimer">
                                        ✦ Analysis generated by Google Gemini AI based on the entered refractive values and patient context.<br>
                                        It is intended to assist the optometrist during consultation and does NOT replace a direct clinical diagnosis by a licensed ophthalmologist.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- NOTES -->
                        <div class="input-group" style="grid-column: 1 / -1; width: 100% !important; display: flex; flex-direction: column; margin-top: 20px;">
                            <label for="exam_notes" style="align-self: flex-start; margin-bottom: 8px;">ADDITIONAL NOTES</label>
                            <textarea name="exam_notes" id="exam_notes" 
                                placeholder="Write additional notes here..." 
                                style="
                                    width: 100% !important; 
                                    min-width: 100% !important; 
                                    max-width: 100% !important; 
                                    background: #1a1c1d; 
                                    color: #00ff88; 
                                    border: 1px solid #444; 
                                    padding: 15px; 
                                    border-radius: 12px; 
                                    min-height: 120px; 
                                    box-sizing: border-box; 
                                    display: block;
                                    font-family: monospace;
                                    resize: vertical;
                                "></textarea>

                            <!-- DONE -> scroll to Input Review -->
                            <div style="margin-top: 12px; display: flex; justify-content: center; width: 100%;">
                                <button type="button" id="notes_done_btn" class="section-done-btn">DONE ▼</button>
                            </div>
                        </div>
                        
                        <!-- ====================================================== -->
                        <!-- === INPUT REVIEW CARD (live preview before save) ====== -->
                        <!-- ====================================================== -->
                        <div id="input_review_card">
                            <div class="review-card-header" onclick="toggleReviewCard()">
                                <div class="review-header-left">
                                    <div class="review-header-icon">📋</div>
                                    <div>
                                        <div class="review-header-title">INPUT REVIEW</div>
                                        <div class="review-header-subtitle">Tap to expand / collapse</div>
                                    </div>
                                </div>
                                <div class="review-header-right">
                                    <span class="review-badge-count" id="review_field_count">0 fields</span>
                                    <span class="review-chevron">▼</span>
                                </div>
                            </div>

                            <div class="review-card-body">
                                <!-- PATIENT INFO -->
                                <div class="review-section" id="review_sec_patient">
                                    <div class="review-section-title">Patient Info</div>
                                    <div class="review-row">
                                        <span class="review-label">Name</span>
                                        <span class="review-value highlight" id="rv_name">—</span>
                                    </div>
                                    <div class="review-row">
                                        <span class="review-label">Gender</span>
                                        <span class="review-value" id="rv_gender">—</span>
                                    </div>
                                    <div class="review-row">
                                        <span class="review-label">Age</span>
                                        <span class="review-value" id="rv_age">—</span>
                                    </div>
                                    <div class="review-row">
                                        <span class="review-label">Exam Date</span>
                                        <span class="review-value" id="rv_date">—</span>
                                    </div>
                                    <div class="review-row">
                                        <span class="review-label">Exam Code</span>
                                        <span class="review-value highlight" id="rv_code">—</span>
                                    </div>
                                </div>

                                <div class="review-divider"></div>

                                <!-- SYMPTOMS -->
                                <div class="review-section" id="review_sec_symptoms">
                                    <div class="review-section-title">Symptoms / Complaints</div>
                                    <div id="rv_symptoms_wrap" class="review-symptoms-wrap"></div>
                                </div>

                                <div class="review-divider"></div>

                                <!-- HABITS -->
                                <div class="review-section" id="review_sec_habits">
                                    <div class="review-section-title">Habits</div>
                                    <div class="review-row">
                                        <span class="review-label">Visual Habit</span>
                                        <span class="review-value" id="rv_visual_habit">—</span>
                                    </div>
                                    <div class="review-row">
                                        <span class="review-label">Digital Usage</span>
                                        <span class="review-value" id="rv_digital_usage">—</span>
                                    </div>
                                </div>

                                <div class="review-divider"></div>

                                <!-- NEW PRESCRIPTION -->
                                <div class="review-section">
                                    <div class="review-section-title">New Prescription</div>
                                    <table class="review-pres-table">
                                        <thead>
                                            <tr>
                                                <th>EYE</th>
                                                <th>UCVA</th>
                                                <th>SPH</th>
                                                <th>CYL</th>
                                                <th>AX</th>
                                                <th>ADD</th>
                                                <th>VA</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>OD (R)</td>
                                                <td id="rv_ucva_r">—</td>
                                                <td id="rv_r_sph">—</td>
                                                <td id="rv_r_cyl">—</td>
                                                <td id="rv_r_ax">—</td>
                                                <td id="rv_r_add">—</td>
                                                <td id="rv_r_va">—</td>
                                            </tr>
                                            <tr>
                                                <td>OS (L)</td>
                                                <td id="rv_ucva_l">—</td>
                                                <td id="rv_l_sph">—</td>
                                                <td id="rv_l_cyl">—</td>
                                                <td id="rv_l_ax">—</td>
                                                <td id="rv_l_add">—</td>
                                                <td id="rv_l_va">—</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <div class="review-row" style="margin-top: 10px;">
                                        <span class="review-label">PD</span>
                                        <span class="review-value highlight" id="rv_pd">—</span>
                                    </div>
                                </div>

                                <!-- OLD PRESCRIPTION (hidden by default, shown if yes) -->
                                <div id="review_sec_old_pres" style="display:none;">
                                    <div class="review-divider"></div>
                                    <div class="review-section">
                                        <div class="review-section-title">Old Prescription</div>
                                        <table class="review-pres-table">
                                            <thead>
                                                <tr>
                                                    <th>EYE</th>
                                                    <th>SPH</th>
                                                    <th>CYL</th>
                                                    <th>AXIS</th>
                                                    <th>ADD</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>OD (R)</td>
                                                    <td id="rv_old_r_sph">—</td>
                                                    <td id="rv_old_r_cyl">—</td>
                                                    <td id="rv_old_r_ax">—</td>
                                                    <td id="rv_old_r_add">—</td>
                                                </tr>
                                                <tr>
                                                    <td>OS (L)</td>
                                                    <td id="rv_old_l_sph">—</td>
                                                    <td id="rv_old_l_cyl">—</td>
                                                    <td id="rv_old_l_ax">—</td>
                                                    <td id="rv_old_l_add">—</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- NOTES -->
                                <div id="review_sec_notes" style="display:none;">
                                    <div class="review-divider"></div>
                                    <div class="review-section">
                                        <div class="review-section-title">Additional Notes</div>
                                        <div class="review-notes-text" id="rv_notes"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- ====================================================== -->
                        <!-- === END INPUT REVIEW CARD ============================ -->
                        <!-- ====================================================== -->

                        <!-- SUBMIT -->
                        <div class="btn-group" style="grid-column: 1 / -1; margin-top: 30px; display: flex; justify-content: center;">
                            <button type="submit" name="submit_customer_prescription" class="submit-main" style="width: 100%; max-width: 400px;">SAVE DATA</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="btn-group">
                <button type="button" class="neu-pill-btn" id="backBtn" onclick="handleBackClick(this)">
                    <span class="neu-pill-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="19" y1="12" x2="5" y2="12"></line>
                            <polyline points="12 19 5 12 12 5"></polyline>
                        </svg>
                    </span>
                    <span class="neu-pill-text">
                        <span class="line1">RETURN TO</span>
                        <span class="line2">PREVIOUS PAGE</span>
                    </span>
                </button>
            </div>
        
            <footer class="footer-container">
                <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
            </footer>
        </div>
        <div class="logo-backdrop" id="logoBackdrop" ondblclick="zoomOutLogo(document.getElementById('storeLogo'))"></div>
        
        <script>
            function generateExamCode() {
                const dateInput = document.getElementById('examination_date').value;
                const dateObj = new Date(dateInput);
                
                // 1. Extract Month & Year
                const month = dateObj.getMonth() + 1;
                const year = dateObj.getFullYear();
                
                // 2. Roman Numerals Function (JS Version)
                const romawiArr = ["", "I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX", "X", "XI", "XII"];
                const monthRom = romawiArr[month];

                // 3. Get Sequence from PHP (Initial Value)
                // Retrieve the base sequence from a hidden input field prepared by PHP
                const baseSequence = document.getElementById('base_sequence').value;
                
                return `LZ/EC/${baseSequence}/${monthRom}/${year}`;
            }

            // Function to Toggle Neumorphism Buttons (Gender & Has Old Prescription)
            function toggleNeu(btn, hiddenInputId, isOldPrescription = false) {
                // Get the button container
                const wrapper = btn.closest('.selection-wrapper');
                // Remove active class from all buttons within that wrapper
                wrapper.querySelectorAll('.neu-btn').forEach(b => b.classList.remove('active'));
                
                // Add active class to the clicked button
                btn.classList.add('active');
                
                // Update value to the hidden input
                const val = btn.getAttribute('value');

                const hiddenInput = document.getElementById(hiddenInputId);
                if (hiddenInput) {
                    hiddenInput.value = val;
                    console.log("Input " + hiddenInputId + " updated to: " + val);
                }

                // Specific logic to display the old prescription form
                if (isOldPrescription) {
                    const oldBox = document.getElementById('old_prescript');
                    oldBox.style.display = (val === 'yes') ? 'block' : 'none';
                    oldBox.style.flexWrap = 'wrap';

                    // Update status label di header toggle
                    const statusLabel = document.getElementById('old_pres_status_label');
                    if (statusLabel) statusLabel.textContent = val.toUpperCase();

                    // If "NO" is chosen, close this section and open the Input Review card
                    if (val === 'no') {
                        closeOldPresAndOpenReview();
                    }
                }
            }

            // Toggle show/hide the old prescription collapsible section
            function toggleOldPresSection() {
                const body   = document.getElementById('old_pres_body');
                const arrow  = document.getElementById('old_pres_arrow');
                const header = document.getElementById('old_pres_header');
                const isOpen = body.style.display === 'block';

                body.style.display  = isOpen ? 'none' : 'block';
                arrow.textContent   = isOpen ? '\u25bc' : '\u25b2';
                header.classList.toggle('open', !isOpen);
            }

            // DONE button inside the Old Prescription table (when "YES" is selected):
            // closes the "Has Old Prescription" section and opens the Input Review card
            function closeOldPresAndOpenReview() {
                const body   = document.getElementById('old_pres_body');
                const arrow  = document.getElementById('old_pres_arrow');
                const header = document.getElementById('old_pres_header');

                // Collapse the Has Old Prescription section
                body.style.display = 'none';
                arrow.textContent  = '\u25bc';
                header.classList.remove('open');

                // Expand the Input Review card
                const reviewCard = document.getElementById('input_review_card');
                if (reviewCard) reviewCard.classList.add('expanded');
            }

            // ================================================================
            // === TOGGLE PANEL (Gender, Visual Habits, Digital Usage) ========
            // ================================================================
            // Map of display labels for each section
            const _panelLabels = {
                'visual_habit': { '1': 'INDOOR', '2': 'OUTDOOR', '3': 'BOTH' },
                'digital_usage': { '1': 'LOW (< 2H)', '2': 'MODERATE (2H - 5H)', '3': 'HIGH (> 5H)' },
                'gender': { 'FEMALE': 'FEMALE', 'MALE': 'MALE' }
            };

            function togglePanel(panelId, headerEl, displayId) {
                const panel  = document.getElementById(panelId);
                const isOpen = panel.classList.contains('open');
                panel.classList.toggle('open', !isOpen);
                headerEl.classList.toggle('open', !isOpen);
            }

            function toggleNeuPanel(btn, hiddenInputId, displayId, panelId, headerId) {
                // 1. Toggle active button
                const wrapper = btn.closest('.selection-wrapper');
                wrapper.querySelectorAll('.neu-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                // 2. Update hidden input
                const val = btn.getAttribute('value');
                const hiddenInput = document.getElementById(hiddenInputId);
                if (hiddenInput) hiddenInput.value = val;

                // 3. Update display label in header
                const displayEl = document.getElementById(displayId);
                if (displayEl) {
                    const labels = _panelLabels[hiddenInputId] || {};
                    displayEl.textContent = labels[val] || btn.querySelector('span').innerText.replace(/\n/g, ' ') || val;
                }

                // 4. Close the panel
                const panel  = document.getElementById(panelId);
                const header = document.getElementById(headerId);
                if (panel)  panel.classList.remove('open');
                if (header) header.classList.remove('open');

                // 5. Auto-chain: open the next section in the sequence
                openNextAutoSection(panelId);

                // 6. Auto-scroll: focus attention on the next field in the sequence
                scrollAfterChain(panelId);
            }

            // ================================================================
            // === AUTO SCROLL ON SEQUENTIAL INPUT COMPLETION ==================
            // ================================================================
            // Smoothly scrolls the next field/section into view so the user's
            // attention follows the same order as the auto-chain above.
            function scrollToField(elId, focusElId) {
                const el = document.getElementById(elId);
                if (!el) return;

                // Close the mobile keyboard first (blur whatever is focused).
                // On mobile, scrollIntoView 'center' is calculated against the
                // viewport BEFORE the keyboard closes, which pushes the target
                // toward the bottom instead of the true center. Waiting for the
                // keyboard to collapse first fixes this.
                if (document.activeElement && document.activeElement.blur) {
                    document.activeElement.blur();
                }

                setTimeout(() => {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    if (focusElId) {
                        const target = document.getElementById(focusElId);
                        if (target && target.focus) {
                            setTimeout(() => { target.focus(); }, 300);
                        }
                    }
                }, 300);
            }

            // Decide which field/section to scroll to after a toggle-panel selection.
            // Gender is a special case: after choosing gender, the Visual Habits panel
            // opens automatically in the background, but the user should be scrolled
            // to the AGE input first (since it comes before Visual Habits on screen).
            function scrollAfterChain(currentPanelId) {
                if (currentPanelId === 'gender_panel') {
                    scrollToField('age', 'age');
                    return;
                }

                // Digital Device Usage is a special case: if the Vision Need card
                // is visible (shown automatically for age >= 39), scroll there first
                // instead of jumping straight to Symptoms.
                if (currentPanelId === 'digital_usage_panel') {
                    const visionSection = document.getElementById('vision_need_section');
                    if (visionSection && visionSection.style.display !== 'none') {
                        scrollToField('vision_need_section');
                        return;
                    }
                }

                const idx = _autoChainOrder.indexOf(currentPanelId);
                if (idx === -1 || idx === _autoChainOrder.length - 1) return;

                const nextId = _autoChainOrder[idx + 1];

                if (nextId === 'symptoms_panel') {
                    scrollToField('symptoms_panel');
                } else if (nextId === 'old_pres_body') {
                    scrollToField('old_pres_header');
                } else {
                    scrollToField(nextId.replace('_panel', '_header'));
                }
            }

            // NAME -> a "DONE" button appears next to the field as soon as it has
            // text (mobile keyboards don't reliably fire a usable "Enter" event,
            // so a visible button is used instead). Tapping it opens the Gender
            // dropdown automatically and scrolls it into view.
            (function() {
                const nameInput = document.getElementById('customer_name');
                const doneBtn   = document.getElementById('customer_name_done_btn');
                if (!nameInput || !doneBtn) return;

                nameInput.addEventListener('input', function() {
                    doneBtn.style.display = nameInput.value.trim() !== '' ? 'block' : 'none';
                });

                doneBtn.addEventListener('click', function() {
                    const panel  = document.getElementById('gender_panel');
                    const header = document.getElementById('gender_header');
                    if (panel)  panel.classList.add('open');
                    if (header) header.classList.add('open');
                    scrollToField('gender_header');
                });
            })();

            // AGE -> a "DONE" button appears next to the field as soon as it has
            // text. Tapping it scrolls down to the Visual Habits dropdown (already
            // auto-opened after gender selection).
            (function() {
                const ageInput = document.getElementById('age');
                const doneBtn  = document.getElementById('age_done_btn');
                if (!ageInput || !doneBtn) return;

                ageInput.addEventListener('input', function() {
                    doneBtn.style.display = ageInput.value.trim() !== '' ? 'block' : 'none';
                });

                doneBtn.addEventListener('click', function() {
                    scrollToField('visual_habit_header');
                });
            })();

            // NEW PRESCRIPTION section -> DONE button below "Generate AI Analysis"
            // scrolls down to the Additional Notes field.
            (function() {
                const doneBtn = document.getElementById('new_prescription_done_btn');
                if (!doneBtn) return;
                doneBtn.addEventListener('click', function() {
                    scrollToField('exam_notes', 'exam_notes');
                });
            })();

            // ADDITIONAL NOTES -> DONE button scrolls down to the Input Review card.
            (function() {
                const doneBtn = document.getElementById('notes_done_btn');
                if (!doneBtn) return;
                doneBtn.addEventListener('click', function() {
                    scrollToField('input_review_card');
                });
            })();

            // VISION NEED -> DONE button scrolls down to the Symptoms section
            // (Symptoms is already auto-opened when Digital Device Usage was selected).
            (function() {
                const doneBtn = document.getElementById('vision_need_done_btn');
                if (!doneBtn) return;
                doneBtn.addEventListener('click', function() {
                    scrollToField('symptoms_panel');
                });
            })();

            // ================================================================
            // === AUTO-CHAIN SEQUENTIAL SECTION OPENING =======================
            // ================================================================
            // Order: Gender -> Visual Habits -> Digital Device Usage -> Symptoms -> Has Old Prescription
            const _autoChainOrder = ['gender_panel', 'visual_habit_panel', 'digital_usage_panel', 'symptoms_panel', 'old_pres_body'];

            function openNextAutoSection(currentPanelId) {
                const idx = _autoChainOrder.indexOf(currentPanelId);
                if (idx === -1 || idx === _autoChainOrder.length - 1) return;

                const nextId = _autoChainOrder[idx + 1];

                if (nextId === 'symptoms_panel') {
                    const panel = document.getElementById('symptoms_panel');
                    const arrowIcon = document.getElementById('arrow_icon');
                    if (panel) panel.style.display = 'block';
                    if (arrowIcon) arrowIcon.innerText = '▲';
                } else if (nextId === 'old_pres_body') {
                    const body   = document.getElementById('old_pres_body');
                    const arrow  = document.getElementById('old_pres_arrow');
                    const header = document.getElementById('old_pres_header');
                    if (body && body.style.display !== 'block') {
                        body.style.display = 'block';
                        if (arrow)  arrow.textContent = '\u25b2';
                        if (header) header.classList.add('open');
                    }
                } else {
                    // Toggle panels (visual_habit_panel, digital_usage_panel)
                    const nextPanel  = document.getElementById(nextId);
                    const nextHeader = document.getElementById(nextId.replace('_panel', '_header'));
                    if (nextPanel)  nextPanel.classList.add('open');
                    if (nextHeader) nextHeader.classList.add('open');
                }
            }

            // ================================================================
            // === VISION NEED TOGGLE (multi-select, 1=yes 0=no) =============
            // ================================================================
            function toggleVisionNeed(btn, checkboxId) {
                btn.classList.toggle('active');
                const chk = document.getElementById('chk_' + checkboxId);
                if (chk) {
                    chk.checked = btn.classList.contains('active');
                }
            }

            // Show / hide vision need section based on calculated age
            function updateVisionNeedVisibility(calculatedAge) {
                const section = document.getElementById('vision_need_section');
                if (calculatedAge >= 39) {
                    section.style.display = 'block';
                    // Auto-select Distance and Near as default for age >= 39
                    const distBtn = document.getElementById('btn_need_distance');
                    const nearBtn = document.getElementById('btn_need_near');
                    const distChk = document.getElementById('chk_need_distance');
                    const nearChk = document.getElementById('chk_need_near');
                    if (distBtn && !distBtn.classList.contains('active')) {
                        distBtn.classList.add('active');
                        if (distChk) distChk.checked = true;
                    }
                    if (nearBtn && !nearBtn.classList.contains('active')) {
                        nearBtn.classList.add('active');
                        if (nearChk) nearChk.checked = true;
                    }
                } else {
                    section.style.display = 'none';
                    document.querySelectorAll('.vision-need-btn').forEach(b => b.classList.remove('active'));
                    ['chk_need_distance','chk_need_intermediate','chk_need_near'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.checked = false;
                    });
                }
            }

            // 1. Logic to Sync Right ADD to Left ADD For New Prescription
            const rAddInput = document.querySelector('input[name="new_r_add"]');
            const lAddInput = document.querySelector('input[name="new_l_add"]');

            // 2. Logic to Sync Right ADD to Left ADD For Old Prescription
            const oldRAddInput = document.querySelector('input[name="old_prescript_R_add"]');
            const oldLAddInput = document.querySelector('input[name="old_prescript_L_add"]');

            // 1. Helper function to apply the "+" sign
            function applyPlus(value) {
                let val = value.trim();
                if (val !== "" && !val.startsWith('+') && !val.startsWith('-') && !isNaN(val)) {
                    return "+" + val;
                }
                return val;
            }

            // Standard function for ADD estimation based on age
            function calculateAddByAge(age) {
                if (age < 40) return "0.00"; // ADD not yet required
                if (age >= 40 && age <= 42) return "+1.00";
                if (age >= 43 && age <= 44) return "+1.25";
                if (age >= 45 && age <= 47) return "+1.50";
                if (age >= 48 && age <= 49) return "+1.75";
                if (age >= 50 && age <= 52) return "+2.00";
                if (age >= 53 && age <= 54) return "+2.25";
                if (age >= 55 && age <= 57) return "+2.50";
                if (age >= 58 && age <= 59) return "+2.75";
                if (age > 60) return "+3.00";
                return "0.00";
            }

            // ================================================================
            // === AI-POWERED PRESCRIPTION ANALYSIS LOGIC =====================
            // ================================================================
            // Calls analyze_prescription.php which forwards data to Claude API
            // and returns a contextual clinical analysis as JSON.

            // HTML-escape helper to safely inject AI response into the DOM
            function escHtml(str) {
                if (str == null) return '';
                const d = document.createElement('div');
                d.textContent = String(str);
                return d.innerHTML;
            }

            // Generic collapsible toggle — called from any .collapsible-header click
            function toggleCollapsible(headerEl) {
                const parent = headerEl.closest('.collapsible');
                if (parent) parent.classList.toggle('collapsed');
            }

            // Build HTML for one eye from AI response (collapsible)
            function buildEyeFromAI(eyeLabel, eyeData) {
                let inner = '';
                if (!eyeData || !Array.isArray(eyeData.conditions)) {
                    inner = `<p class="analysis-text">No data available for this eye.</p>`;
                } else {
                    if (eyeData.summary) {
                        inner += `<p class="analysis-text" style="font-style: italic; color: #aaa; margin-bottom: 12px;">${escHtml(eyeData.summary)}</p>`;
                    }
                    eyeData.conditions.forEach(c => {
                        const sev = (c.severity || 'normal').toLowerCase();
                        const validSev = ['normal','mild','moderate','high','severe'].includes(sev) ? sev : 'normal';
                        const badgeClass = 'badge-' + validSev;
                        const valuePart = c.value ? ` (${escHtml(c.value)})` : '';
                        const symptoms = Array.isArray(c.symptoms) ? c.symptoms : [];
                        const causes   = Array.isArray(c.causes)   ? c.causes   : [];

                        inner += `
                            <div class="analysis-condition">
                                <span class="analysis-badge ${badgeClass}">${escHtml(c.name || 'UNKNOWN')}${valuePart}</span>
                                <p class="analysis-text">${escHtml(c.description || '')}</p>
                                ${symptoms.length ? `
                                    <div class="analysis-label">▸ Patient may experience:</div>
                                    <div class="analysis-text"><ul>${symptoms.map(s => `<li>${escHtml(s)}</li>`).join('')}</ul></div>
                                ` : ''}
                                ${causes.length ? `
                                    <div class="analysis-label">▸ Possible causes:</div>
                                    <div class="analysis-text"><ul>${causes.map(s => `<li>${escHtml(s)}</li>`).join('')}</ul></div>
                                ` : ''}
                            </div>
                        `;
                    });
                }

                return `
                    <h4 class="collapsible-header" onclick="toggleCollapsible(this)">
                        <span>◉ ${eyeLabel}</span>
                        <span class="chevron">▼</span>
                    </h4>
                    <div class="collapsible-content">
                        ${inner}
                    </div>
                `;
            }

            // Build HTML for the top-level "possible conditions" card (open by default)
            function buildMainFindings(findings) {
                if (!Array.isArray(findings) || !findings.length) return '';

                let inner = '';
                findings.forEach(f => {
                    const sev = (f.severity || 'normal').toLowerCase();
                    const validSev = ['normal','mild','moderate','high','severe'].includes(sev) ? sev : 'normal';
                    const badgeClass = 'badge-' + validSev;
                    const causes     = Array.isArray(f.causes)     ? f.causes     : [];
                    const management = Array.isArray(f.management) ? f.management : [];

                    inner += `
                        <div class="analysis-condition">
                            <span class="analysis-badge ${badgeClass}">${escHtml(f.name || 'UNKNOWN')}</span>
                            <p class="analysis-text">${escHtml(f.explanation || '')}</p>
                            ${causes.length ? `
                                <div class="analysis-label">▸ Penyebab:</div>
                                <div class="analysis-text"><ul>${causes.map(s => `<li>${escHtml(s)}</li>`).join('')}</ul></div>
                            ` : ''}
                            ${management.length ? `
                                <div class="analysis-label">▸ Penanggulangan:</div>
                                <div class="analysis-text"><ul>${management.map(s => `<li>${escHtml(s)}</li>`).join('')}</ul></div>
                            ` : ''}
                        </div>
                    `;
                });

                // Not marked "collapsed" -> opens by default, unlike the other sections
                return `
                    <div class="analysis-main-findings collapsible">
                        <h4 class="collapsible-header" onclick="toggleCollapsible(this)">
                            <span>★ POSSIBLE CONDITIONS, CAUSES &amp; MANAGEMENT</span>
                            <span class="chevron">▼</span>
                        </h4>
                        <div class="collapsible-content">
                            ${inner}
                        </div>
                    </div>
                `;
            }

            // Adds the AI-identified condition name(s) into the symptoms list, tagged
            // "(AI ANALYSIS)", in English/uppercase. Re-running analysis replaces
            // any previously AI-added tags rather than duplicating them.
            function applyAIConditionsToSymptoms(findings) {
                // Remove any AI tags from a previous run
                selectedSymptoms = selectedSymptoms.filter(s => !s.endsWith('(AI ANALYSIS)'));

                if (Array.isArray(findings)) {
                    findings.forEach(f => {
                        const name = (f.name || '').toUpperCase().trim();
                        if (!name || name.includes('NORMAL') || name.includes('EMMETROPIA')) return;
                        const tag = `${name} (AI ANALYSIS)`;
                        if (!selectedSymptoms.includes(tag)) selectedSymptoms.push(tag);
                    });
                }

                updateSymptomListJson();
            }

            // Render complete AI analysis into the panel
            function renderAIAnalysis(analysis) {
                // All sections start COLLAPSED — user clicks the header(s) they want to expand
                document.getElementById('prescription_analysis').classList.add('collapsed');
                document.getElementById('analysis_right').classList.add('collapsed');
                document.getElementById('analysis_left').classList.add('collapsed');

                // New card: possible conditions / causes / management — open by default
                document.getElementById('analysis_main_findings').innerHTML =
                    buildMainFindings(analysis.main_findings || []);

                // Tag the AI-identified condition(s) into the symptoms list (English, uppercase)
                applyAIConditionsToSymptoms(analysis.main_findings || []);

                document.getElementById('analysis_right').innerHTML =
                    buildEyeFromAI('RIGHT EYE (OD)', analysis.right_eye || {});
                document.getElementById('analysis_left').innerHTML =
                    buildEyeFromAI('LEFT EYE (OS)',  analysis.left_eye  || {});

                let summaryHtml = '';
                if (analysis.contextual_insights) {
                    summaryHtml += `
                        <div class="analysis-insights collapsible collapsed">
                            <h4 class="collapsible-header" onclick="toggleCollapsible(this)">
                                <span>✦ CONTEXTUAL INSIGHTS (PERSONALIZED)</span>
                                <span class="chevron">▼</span>
                            </h4>
                            <div class="collapsible-content">
                                <p class="analysis-text">${escHtml(analysis.contextual_insights)}</p>
                            </div>
                        </div>
                    `;
                }
                if (Array.isArray(analysis.recommendations) && analysis.recommendations.length) {
                    summaryHtml += `
                        <div class="analysis-recommendation collapsible collapsed">
                            <h4 class="collapsible-header" onclick="toggleCollapsible(this)">
                                <span>► LENS &amp; CARE RECOMMENDATIONS</span>
                                <span class="chevron">▼</span>
                            </h4>
                            <div class="collapsible-content">
                                <ul class="analysis-text" style="padding-left: 20px; margin: 0;">
                                    ${analysis.recommendations.map(r => `<li>${escHtml(r)}</li>`).join('')}
                                </ul>
                            </div>
                        </div>
                    `;
                }
                document.getElementById('analysis_summary').innerHTML = summaryHtml;
            }

            // Collect all form data and build the payload sent to the backend
            function collectAnalysisPayload() {
                const getVal = (name) => {
                    const el = document.querySelector(`input[name="${name}"], textarea[name="${name}"]`);
                    return el ? el.value.trim() : '';
                };

                // Age parsing (supports "25" or ".96")
                const ageVal = document.getElementById('age').value.trim();
                let age = 0;
                if (ageVal.includes('.')) {
                    const yearInput = ageVal.replace('.', '');
                    const yearVal = parseInt(yearInput);
                    const fullYear = yearInput.length <= 2
                        ? (yearVal > 26 ? 1900 + yearVal : 2000 + yearVal)
                        : yearVal;
                    age = 2026 - fullYear;
                } else {
                    age = parseInt(ageVal) || 0;
                }

                // Symptoms (enrich DIABETES / HYPERTENSION with their details)
                const symptoms = [...selectedSymptoms];
                if (symptoms.includes('DIABETES')) {
                    const dmVal = getVal('dm_sugar');
                    const dmStat = document.querySelector('select[name="dm_status"]')?.value || '';
                    if (dmVal) {
                        const i = symptoms.indexOf('DIABETES');
                        symptoms[i] = `DIABETES (${dmVal} mg/dL, ${dmStat})`;
                    }
                }
                if (symptoms.includes('HYPERTENSION')) {
                    const htVal = getVal('ht_pressure');
                    const htStat = document.querySelector('select[name="ht_status"]')?.value || '';
                    if (htVal) {
                        const i = symptoms.indexOf('HYPERTENSION');
                        symptoms[i] = `HYPERTENSION (${htVal}, ${htStat})`;
                    }
                }
                const others = getVal('other_symptoms');
                if (others) symptoms.push('OTHERS: ' + others.toUpperCase());

                // Old prescription (only if toggle = yes)
                let old_prescription = null;
                const oldToggle = document.getElementById('has_old_prescription_input');
                if (oldToggle && oldToggle.value === 'yes') {
                    old_prescription = {
                        r_sph: getVal('old_prescript_R_sph') || '0.00',
                        r_cyl: getVal('old_prescript_R_cyl') || '0.00',
                        r_ax:  getVal('old_prescript_R_ax')  || '0',
                        r_add: getVal('old_prescript_R_add') || '0.00',
                        l_sph: getVal('old_prescript_L_sph') || '0.00',
                        l_cyl: getVal('old_prescript_L_cyl') || '0.00',
                        l_ax:  getVal('old_prescript_L_ax')  || '0',
                        l_add: getVal('old_prescript_L_add') || '0.00',
                    };
                }

                return {
                    age,
                    gender: document.getElementById('gender').value,
                    visual_habit:  parseInt(document.getElementById('visual_habit').value)  || null,
                    digital_usage: parseInt(document.getElementById('digital_usage').value) || null,
                    symptoms,
                    new_prescription: {
                        r_sph: getVal('new_r_sph') || '0.00',
                        r_cyl: getVal('new_r_cyl') || '0.00',
                        r_ax:  getVal('new_r_ax')  || '0',
                        r_add: getVal('new_r_add') || '0.00',
                        l_sph: getVal('new_l_sph') || '0.00',
                        l_cyl: getVal('new_l_cyl') || '0.00',
                        l_ax:  getVal('new_l_ax')  || '0',
                        l_add: getVal('new_l_add') || '0.00',
                    },
                    old_prescription,
                    ucva: {
                        r: getVal('ucva_r'),
                        l: getVal('ucva_l')
                    }
                };
            }

            // Shows the top-of-page referral alert and toggles the glow animation
            // on the Clinical Analysis card when a specialist referral is recommended.
            function applyReferralAlert(referral) {
                const alertBox = document.getElementById('analysis_referral_alert');
                const panel = document.getElementById('prescription_analysis');

                if (referral && referral.recommended) {
                    alertBox.innerHTML = `
                        <div class="referral-alert">
                            <strong>⚠ REFERRAL TO SPECIALIST RECOMMENDED${referral.specialist ? ': ' + escHtml(referral.specialist) : ''}</strong>
                            <p>${escHtml(referral.reason || '')}</p>
                        </div>
                    `;
                    panel.classList.add('analysis-warning-glow');
                } else {
                    alertBox.innerHTML = '';
                    panel.classList.remove('analysis-warning-glow');
                }
            }

            // Sends the exam + analysis data to generate_pdf.php, which saves the
            // PDF automatically into /pdf_file/{exam_code}.pdf on the server.
            async function savePrescriptionPDF(payload, analysis) {
                const examCode = document.getElementById('hidden_exam_code')?.value || '';
                if (!examCode) return;

                try {
                    const res = await fetch('generate_pdf.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            exam_code: examCode,
                            patient: {
                                name:   document.getElementById('customer_name')?.value || '',
                                gender: payload.gender,
                                age:    payload.age
                            },
                            new_prescription: payload.new_prescription,
                            old_prescription: payload.old_prescription,
                            analysis
                        })
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || !data.success) {
                        console.warn('PDF auto-save failed:', data.error || res.status);
                    }
                } catch (e) {
                    console.warn('PDF auto-save request failed:', e);
                }
            }

            // Main trigger function — called when user clicks "Generate AI Analysis"
            async function requestAIAnalysis() {
                const payload = collectAnalysisPayload();

                // Validate: at least ONE prescription value must be non-zero
                const rx = payload.new_prescription;
                const hasValue = Object.values(rx).some(v => {
                    const n = parseFloat(v);
                    return !isNaN(n) && n !== 0;
                });
                if (!hasValue) {
                    Swal.fire({
                        title: 'NO PRESCRIPTION DATA',
                        text: 'Please enter at least one prescription value before generating AI analysis.',
                        icon: 'warning',
                        background: '#25282a',
                        color: '#fff',
                        confirmButtonColor: '#00ccff'
                    });
                    return;
                }

                const btn = document.getElementById('btn_ai_analyze');
                const panel = document.getElementById('prescription_analysis');
                const originalBtnHTML = btn.innerHTML;

                // Enter loading state
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner"></span> ANALYZING WITH GEMINI AI...';
                panel.style.display = 'block';
                document.getElementById('analysis_right').innerHTML = `
                    <div style="text-align: center; padding: 40px 20px; color: #00ccff;">
                        <div class="spinner-lg"></div>
                        <p style="margin-top: 15px; font-size: 0.9em;">Gemini is analyzing the prescription in context with the patient's profile...</p>
                        <p style="margin-top: 5px; font-size: 0.75em; color: #888;">This usually takes 3-8 seconds.</p>
                    </div>
                `;
                document.getElementById('analysis_left').innerHTML = '';
                document.getElementById('analysis_summary').innerHTML = '';

                try {
                    const res = await fetch('analyze_prescription.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json().catch(() => ({ error: 'Invalid server response.' }));

                    if (!res.ok || !data.success) {
                        throw new Error(data.error || `Server returned ${res.status}`);
                    }

                    renderAIAnalysis(data.analysis);
                    applyReferralAlert(data.analysis.referral || null);

                    // Auto-scroll so the result is immediately visible/focused
                    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

                    // Show token usage + remaining token budget for this request
                    if (data.meta && (data.meta.input_tokens || data.meta.output_tokens)) {
                        const meta = data.meta;
                        const remainingTxt = (meta.remaining_tokens !== null && meta.remaining_tokens !== undefined)
                            ? ` · sisa token (batas request ini): ${meta.remaining_tokens}/${meta.max_output_tokens}`
                            : '';
                        document.getElementById('analysis_summary').innerHTML += `
                            <p style="text-align: center; font-size: 0.7em; color: #555; margin-top: 10px;">
                                model: ${escHtml(meta.model)} · tokens in/out: ${meta.input_tokens || '?'}/${meta.output_tokens || '?'}${remainingTxt}
                            </p>
                        `;
                    }

                    // Auto-save the PDF summary (fire-and-forget, does not block the UI)
                    savePrescriptionPDF(payload, data.analysis);

                } catch (err) {
                    document.getElementById('analysis_right').innerHTML = `
                        <div style="padding: 20px; background: #4a2d2d; border: 1px solid #ff4466; border-radius: 10px; color: #ff8866;">
                            <strong>⚠ AI ANALYSIS FAILED</strong><br>
                            <small style="color: #ffaa99;">${escHtml(err.message)}</small>
                            <br><br>
                            <small style="color: #aaa;">Please check: (1) your internet connection, (2) that GEMINI_API_KEY is defined in config_helper.php, (3) that the API key is valid and you have not exceeded the free daily quota (500 requests/day).</small>
                        </div>
                    `;
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = originalBtnHTML;
                }
            }

            // ================================================================
            // === END AI-POWERED PRESCRIPTION ANALYSIS LOGIC =================
            // ================================================================

            // 2. When Right eye is input, Left eye follows immediately (Raw value)
            rAddInput.addEventListener('input', function() {
                lAddInput.value = this.value;
            });

            // 3. When Right eye input loses focus (blur), apply "+" to BOTH Right and Left
            rAddInput.addEventListener('blur', function() {
                this.value = applyPlus(this.value);
                // Format the left input as well to maintain consistency
                lAddInput.value = applyPlus(this.value);
            });

            // 4. Still allow the Left eye to be modified manually and formatted independently
            lAddInput.addEventListener('blur', function() {
                this.value = applyPlus(this.value);
            });

            document.getElementById('examination_date').addEventListener('change', function() {
                const newCode = generateExamCode();
                document.getElementById('hidden_exam_code').value = newCode;
                console.log("Code updated to: " + newCode);
            });

            // --- OLD PRESCRIPTION ADD SYNC LOGIC ---
            // 1. When Right eye is input, Left eye follows (Raw value)
            oldRAddInput.addEventListener('input', function() {
                oldLAddInput.value = this.value;
            });

            // 2. When Right eye loses focus (blur), apply the "+" sign
            oldRAddInput.addEventListener('blur', function() {
                this.value = applyPlus(this.value);
                oldLAddInput.value = applyPlus(this.value);
            });

            // 3. Still allow the Left eye to be manually changed if different
            oldLAddInput.addEventListener('blur', function() {
                this.value = applyPlus(this.value);
            });

            // --- SYMPTOMS PANEL LOGIC ---
            const btnOpen = document.getElementById('btn_open_symptoms');
            const panel = document.getElementById('symptoms_panel');
            const summary = document.getElementById('symptom_summary');
            let selectedSymptoms = [];

            btnOpen.onclick = function() {
                const isHidden = (panel.style.display === 'none' || panel.style.display === '');
                panel.style.display = isHidden ? 'block' : 'none';
                document.getElementById('arrow_icon').innerText = isHidden ? '▲' : '▼';

                // Auto-chain: if the Symptoms card is being collapsed (closed), open "Has Old Prescription" next
                if (!isHidden) {
                    openNextAutoSection('symptoms_panel');
                }
            };

            function closePanel() {
                panel.style.display = 'none';
                document.getElementById('arrow_icon').innerText = '▼';
                // Auto-chain: after closing Symptoms, open "Has Old Prescription" section
                openNextAutoSection('symptoms_panel');
            }

            function toggleSymptom(btn, value, detailId = null) {
                btn.classList.toggle('active');
                
                // Ensure the value is stored in uppercase
                const upperValue = value.toUpperCase();
                
                if (btn.classList.contains('active')) {
                    selectedSymptoms.push(upperValue);
                    if (detailId) showConditionDetail(detailId);
                } else {
                    selectedSymptoms = selectedSymptoms.filter(item => item !== upperValue);
                    if (detailId) hideConditionDetail(detailId);
                }
                
                // Rebuild the full list (auto-detected + manual) and update hidden field + summary
                updateSymptomListJson();
            }

            // ================================================================
            // === CONDITION DETAILS FLY WINDOW (DIABETES / HYPERTENSION) ===
            // ================================================================
            // Shows the DIABETES MILITUS DATA / HYPERTENSION DATA fields inside
            // a floating window (#condition_details_overlay) instead of inline
            // in the Symptoms card.
            function showConditionDetail(detailId) {
                const detailEl = document.getElementById(detailId);
                if (detailEl) detailEl.style.display = 'block';
                const overlay = document.getElementById('condition_details_overlay');
                if (overlay) overlay.style.display = 'flex';
            }

            function hideConditionDetail(detailId) {
                const detailEl = document.getElementById(detailId);
                if (detailEl) detailEl.style.display = 'none';

                // Close the fly window automatically once no condition detail is left visible
                const dmVisible = document.getElementById('dm_detail')?.style.display === 'block';
                const htVisible = document.getElementById('ht_detail')?.style.display === 'block';
                if (!dmVisible && !htVisible) {
                    closeConditionDetails();
                }
            }

            function closeConditionDetails() {
                const overlay = document.getElementById('condition_details_overlay');
                if (overlay) overlay.style.display = 'none';
            }

            // ================================================================
            // === AUTO-DETECT REFRACTION SYMPTOMS FROM NEW PRESCRIPTION ===
            // ================================================================
            // Detects MYOPIA, HYPEROPIA, ASTIGMATISM, PRESBYOPIA automatically
            // based on the entered SPH, CYL, and ADD values.
            // Rules:
            //   MYOPIA      : either eye SPH < 0
            //   HYPEROPIA   : either eye SPH > 0 (and no myopia in that eye)
            //   ASTIGMATISM : either eye CYL != 0
            //   PRESBYOPIA  : ADD value > 0 in either eye

            let autoDetectedSymptoms = []; // Holds the 4 auto conditions (separate from manual)

            function detectRefractionSymptoms() {
                const rSph = parseFloat(document.querySelector('input[name="new_r_sph"]').value) || 0;
                const lSph = parseFloat(document.querySelector('input[name="new_l_sph"]').value) || 0;
                const rCyl = parseFloat(document.querySelector('input[name="new_r_cyl"]').value) || 0;
                const lCyl = parseFloat(document.querySelector('input[name="new_l_cyl"]').value) || 0;
                const rAdd = parseFloat(document.querySelector('input[name="new_r_add"]').value) || 0;
                const lAdd = parseFloat(document.querySelector('input[name="new_l_add"]').value) || 0;

                const detected = [];

                // MYOPIA: at least one eye SPH is negative
                if (rSph < 0 || lSph < 0) detected.push('MYOPIA');

                // HYPEROPIA: at least one eye SPH is positive (only if not already labelled myopia for that eye)
                // Simplified: if any SPH > 0 exists among the eyes
                if (rSph > 0 || lSph > 0) detected.push('HYPEROPIA');

                // ASTIGMATISM: at least one eye CYL is non-zero
                if (rCyl !== 0 || lCyl !== 0) detected.push('ASTIGMATISM');

                // PRESBYOPIA: ADD value is positive in either eye
                if (rAdd > 0 || lAdd > 0) detected.push('PRESBYOPIA');

                autoDetectedSymptoms = detected;

                // Rebuild the full symptom list and update the hidden JSON input
                updateSymptomListJson();
            }

            // Merges auto-detected + manually selected symptoms into the hidden JSON field
            function updateSymptomListJson() {
                // Combine: auto first, then manual (avoid duplicates)
                const manualOnly = selectedSymptoms.filter(s => 
                    !['MYOPIA','HYPEROPIA','ASTIGMATISM','PRESBYOPIA'].includes(s)
                );
                const combined = [...autoDetectedSymptoms, ...manualOnly];

                // Update summary display
                const allForDisplay = combined.length > 0 ? combined : [];
                if (allForDisplay.length > 0) {
                    summary.innerText = allForDisplay.join(', ');
                    summary.style.color = '#00ff88';
                } else {
                    summary.innerText = 'NO SYMPTOMS SELECTED';
                    summary.style.color = '#888';
                }

                document.getElementById('symptom_list_json').value = JSON.stringify(combined);
            }

            // Attach input listeners to all 6 new prescription fields that affect detection
            ['new_r_sph','new_l_sph','new_r_cyl','new_l_cyl','new_r_add','new_l_add'].forEach(name => {
                const el = document.querySelector(`input[name="${name}"]`);
                if (el) {
                    el.addEventListener('input', detectRefractionSymptoms);
                    el.addEventListener('blur',  detectRefractionSymptoms);
                }
            });

            // ================================================================
            // === END AUTO-DETECT REFRACTION SYMPTOMS ========================
            // ================================================================

            // ================================================================
            // === SYMPTOM OPTIONS SETTINGS FLY WINDOW (JSON-backed) ==========
            // ================================================================
            // Lets the user add / edit / delete the manual symptom buttons.
            // Options are persisted server-side in data_json/symptoms.json
            // via symptoms_options.php, then the symptoms grid is re-rendered
            // live without a full page reload.

            function openSymptomSettings() {
                const btn = document.getElementById('btn_open_symptom_settings');
                btn.classList.remove('spinning');
                // Force reflow so the animation can retrigger on repeated clicks
                void btn.offsetWidth;
                btn.classList.add('spinning');

                document.getElementById('symptom_settings_overlay').style.display = 'block';
                loadSymptomOptions();
            }

            function closeSymptomSettings() {
                document.getElementById('symptom_settings_overlay').style.display = 'none';
            }

            // Close the fly window when clicking outside its card
            document.getElementById('symptom_settings_overlay').addEventListener('click', function(e) {
                if (e.target === this) closeSymptomSettings();
            });

            async function loadSymptomOptions() {
                const listEl  = document.getElementById('symptom_settings_list');
                const emptyEl = document.getElementById('symptom_settings_empty');

                try {
                    const res = await fetch('symptoms_options.php?action=list');
                    const json = await res.json();

                    if (!json.success) {
                        listEl.innerHTML = '';
                        emptyEl.style.display = 'block';
                        emptyEl.textContent = 'Failed to load symptom options.';
                        return;
                    }

                    renderSymptomOptionsList(json.data);
                } catch (err) {
                    listEl.innerHTML = '';
                    emptyEl.style.display = 'block';
                    emptyEl.textContent = 'Error loading symptom options.';
                }
            }

            function renderSymptomOptionsList(options) {
                const listEl  = document.getElementById('symptom_settings_list');
                const emptyEl = document.getElementById('symptom_settings_empty');

                listEl.innerHTML = '';

                if (!options || options.length === 0) {
                    emptyEl.style.display = 'block';
                    emptyEl.textContent = 'No symptom options yet. Add one above.';
                    return;
                }
                emptyEl.style.display = 'none';

                options.forEach(opt => {
                    const row = document.createElement('div');
                    row.className = 'symptom-option-row';
                    row.dataset.id = opt.id;

                    const input = document.createElement('input');
                    input.type = 'text';
                    input.value = opt.label;
                    input.oninput = function() { this.value = this.value.toUpperCase(); };

                    const saveBtn = document.createElement('button');
                    saveBtn.type = 'button';
                    saveBtn.className = 'opt-btn save';
                    saveBtn.textContent = 'SAVE';
                    saveBtn.onclick = function() { saveSymptomOption(opt.id, input.value); };

                    const delBtn = document.createElement('button');
                    delBtn.type = 'button';
                    delBtn.className = 'opt-btn delete';
                    delBtn.textContent = 'DELETE';
                    delBtn.onclick = function() { deleteSymptomOption(opt.id, opt.label); };

                    row.appendChild(input);
                    row.appendChild(saveBtn);
                    row.appendChild(delBtn);
                    listEl.appendChild(row);
                });
            }

            async function addSymptomOption() {
                const input = document.getElementById('new_symptom_label');
                const label = input.value.trim();
                if (label === '') {
                    input.focus();
                    return;
                }

                try {
                    const res = await fetch('symptoms_options.php?action=add', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ label: label })
                    });
                    const json = await res.json();

                    if (json.success) {
                        input.value = '';
                        await loadSymptomOptions();
                        refreshSymptomsGrid();
                    } else {
                        alert(json.message || 'Failed to add symptom option.');
                    }
                } catch (err) {
                    alert('Error adding symptom option.');
                }
            }

            async function saveSymptomOption(id, label) {
                label = label.trim();
                if (label === '') {
                    alert('Label cannot be empty.');
                    return;
                }

                try {
                    const res = await fetch('symptoms_options.php?action=edit', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: id, label: label })
                    });
                    const json = await res.json();

                    if (json.success) {
                        refreshSymptomsGrid();
                    } else {
                        alert(json.message || 'Failed to save symptom option.');
                    }
                } catch (err) {
                    alert('Error saving symptom option.');
                }
            }

            async function deleteSymptomOption(id, label) {
                if (!confirm('Delete symptom option "' + label + '"?')) return;

                try {
                    const res = await fetch('symptoms_options.php?action=delete', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: id })
                    });
                    const json = await res.json();

                    if (json.success) {
                        await loadSymptomOptions();
                        refreshSymptomsGrid();
                    } else {
                        alert(json.message || 'Failed to delete symptom option.');
                    }
                } catch (err) {
                    alert('Error deleting symptom option.');
                }
            }

            // Re-fetch the current symptom option list and rebuild the visible
            // symptoms-grid buttons in the main form, without a page reload.
            // Any symptom that was selected but no longer exists is dropped
            // from the current selection.
            async function refreshSymptomsGrid() {
                try {
                    const res = await fetch('symptoms_options.php?action=list');
                    const json = await res.json();
                    if (!json.success) return;

                    const grid = document.getElementById('symptoms_grid');
                    grid.innerHTML = '';

                    const validValues = json.data.map(o => (o.value || o.label).toUpperCase());

                    json.data.forEach(opt => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'neu-btn symptom-btn';
                        if (opt.full_width) btn.style.gridColumn = '1 / -1';

                        const span = document.createElement('span');
                        span.textContent = opt.label;
                        const led = document.createElement('div');
                        led.className = 'led';

                        btn.appendChild(span);
                        btn.appendChild(led);

                        btn.onclick = function() {
                            toggleSymptom(btn, opt.value || opt.label, opt.detail_id || null);
                        };

                        grid.appendChild(btn);
                    });

                    // Drop any previously selected symptoms that no longer exist
                    selectedSymptoms = selectedSymptoms.filter(s => validValues.includes(s));
                    updateSymptomListJson();
                } catch (err) {
                    // Silently ignore — the settings list will still show the latest data
                }
            }

            // ================================================================
            // === END SYMPTOM OPTIONS SETTINGS FLY WINDOW ====================
            // ================================================================

            async function showSummary(event) {
                event.preventDefault(); // Prevents the form from being submitted immediately

                // 1. Get Basic Data & Examination Code
                const dynamicExamCode = generateExamCode();
                const name = document.getElementById('customer_name').value.toUpperCase() || "UNKNOWN";
                document.getElementById('hidden_exam_code').value = dynamicExamCode;

                // --- GET SYMPTOMS (Same as previous logic) ---
                let symptomsArray = [];
                const mainSymptoms = document.getElementById('symptom_summary').innerText;
                if (mainSymptoms !== "NO SYMPTOMS SELECTED") {
                    symptomsArray.push(mainSymptoms);
                }

                const dmSugar = document.querySelector('input[name="dm_sugar"]').value;
                if (dmSugar) {
                    const dmStatus = document.querySelector('select[name="dm_status"]').value;
                    symptomsArray.push(`DIABETES (${dmSugar} MG/DL, ${dmStatus.toUpperCase()})`);
                }

                const others = document.querySelector('textarea[name="other_symptoms"]').value.trim().toUpperCase();
                if (others) {
                    symptomsArray.push(`OTHERS: ${others}`);
                }

                const fullSymptomsSummary = symptomsArray.length > 0 ? symptomsArray.join(' | ') : "NONE";

                // --- GET PRESCRIPTION DATA & FORMAT ADD ---
                const r_sph = document.querySelector('input[name="new_r_sph"]').value || "0.00";
                const r_cyl = document.querySelector('input[name="new_r_cyl"]').value || "0.00";
                const r_ax  = document.querySelector('input[name="new_r_ax"]').value || "0";
                const r_va  = document.querySelector('input[name="new_r_va"]').value || "20/20";
                
                // Ensure ADD uses the applyPlus function to display with the + sign
                let r_add = applyPlus(document.querySelector('input[name="new_r_add"]').value || "0.00");
                let l_add = applyPlus(document.querySelector('input[name="new_l_add"]').value || "0.00");

                const l_sph = document.querySelector('input[name="new_l_sph"]').value || "0.00";
                const l_cyl = document.querySelector('input[name="new_l_cyl"]').value || "0.00";
                const l_ax  = document.querySelector('input[name="new_l_ax"]').value || "0";
                const l_va  = document.querySelector('input[name="new_l_va"]').value || "20/20";

                const _pdRawAdd_r = document.querySelector('input[name="new_r_add"]').value.trim();
                const _pdRawAdd_l = document.querySelector('input[name="new_l_add"]').value.trim();
                const _pdHasAdd = (_pdRawAdd_r !== '' && _pdRawAdd_r !== '0.00')
                               || (_pdRawAdd_l !== '' && _pdRawAdd_l !== '0.00');
                const _pdSmartDefault = _pdHasAdd ? '62/60' : '62';
                const pd = document.querySelector('input[name="pd_dist"]').value.trim() || _pdSmartDefault;

                // Create HTML template for the first popup
                const summaryHtml = `
                    <div style="text-align: left; font-family: 'Courier New', monospace; font-size: 0.85em; background: #1a1c1d; padding: 15px; border-radius: 10px; border: 1px solid #444; color: #eee;">
                            <div style="text-align: center; border-bottom: 2px solid #00ff88; margin-bottom: 12px;">
                                <h3 style="margin: 0; color: #00ff88;">EXAMINATION RECEIPT</h3>
                                <small style="color: #888;">ID: ${dynamicExamCode}</small>
                            </div>

                        <div style="margin-bottom: 15px;">
                            <p style="margin: 5px 0;"><strong style="color: #00ff88;">NAME    :</strong> ${name}</p>
                            <p style="margin: 5px 0;"><strong style="color: #00ff88;">SYMPTOMS:</strong> <span style="color: #ffcc00;">${fullSymptomsSummary}</span></p>
                        </div>

                        <table style="width: 100%; border-collapse: collapse; color: #fff; text-align: center;">
                            <thead>
                                <tr style="color: #00ff88; font-size: 0.8em; border-bottom: 1px solid #444;">
                                    <th style="padding: 5px; text-align: left;">EYE</th>
                                    <th>SPH</th>
                                    <th>CYL</th>
                                    <th>AXS</th>
                                    <th>ADD</th>
                                    <th>VA</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr style="border-bottom: 1px solid #333;">
                                    <td style="padding: 10px 0; text-align: left; font-weight: bold; color: #00ff88;">OD (R)</td>
                                    <td>${r_sph}</td>
                                    <td>${r_cyl}</td>
                                    <td>${r_ax}</td>
                                    <td>${r_add}</td>
                                    <td>${r_va}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 0; text-align: left; font-weight: bold; color: #00ff88;">OS (L)</td>
                                    <td>${l_sph}</td>
                                    <td>${l_cyl}</td>
                                    <td>${l_ax}</td>
                                    <td>${l_add}</td>
                                    <td>${l_va}</td>
                                </tr>
                            </tbody>
                        </table>

                        <div style="margin-top: 15px; padding-top: 10px; border-top: 1px dashed #555; text-align: center;">
                            <strong style="color: #00ff88;">PD:</strong> ${pd}
                        </div>
                    </div>
                `;

                // --- 2. First Popup: Data Verification ---
                const result = await Swal.fire({
                    title: 'VERIFY DATA',
                    html: summaryHtml,
                    icon: 'info',
                    showCancelButton: true,
                    confirmButtonColor: '#00ff88',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'CONFIRM & SAVE',
                    cancelButtonText: 'RE-CHECK',
                    background: '#25282a',
                    color: '#fff',
                    width: '550px'
                });

                if (result.isConfirmed) {
                    // --- 3. Second Popup: Purchase Inquiry ---
                    const shoppingResult = await Swal.fire({
                        title: 'CUSTOMER PURCHASE?',
                        text: "Does the customer want to buy glasses/frames now?",
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#00ff88',
                        cancelButtonColor: '#888',
                        confirmButtonText: 'YES, SHOPPING',
                        cancelButtonText: 'NO, JUST EXAM',
                        background: '#25282a',
                        color: '#fff'
                    });

                    if (shoppingResult.isConfirmed) {
                        // CASE: YES, SHOPPING
                        try {
                            const response = await fetch('get_next_invoice.php');
                            const nextInvoice = await response.text();
                            document.getElementById('invoice_decision').value = nextInvoice.trim();
                        } catch (err) {
                            document.getElementById('invoice_decision').value = '001';
                        }
                    } else {
                        // CASE: NO, JUST EXAM
                        document.getElementById('invoice_decision').value = '00';
                        // TELL PHP TO REDIRECT TO CUSTOMER.PHP
                        document.getElementById('after_save_redirect').value = 'customer_list'; 
                    }

                    // --- 4. Final Submit ---
                    // Update the final examination code one last time before sending
                    document.getElementById('hidden_exam_code').value = generateExamCode();
                    // Ensure PD has the correct default value before submit (not left empty)
                    const _pdInput = document.querySelector('input[name="pd_dist"]');
                    if (_pdInput.value.trim() === '') {
                        const _rAdd = document.querySelector('input[name="new_r_add"]').value.trim();
                        const _lAdd = document.querySelector('input[name="new_l_add"]').value.trim();
                        const _hasAdd = (_rAdd !== '' && _rAdd !== '0.00')
                                     || (_lAdd !== '' && _lAdd !== '0.00');
                        _pdInput.value = _hasAdd ? '62/60' : '62';
                    }
                    document.getElementById('examForm').submit();
                }
            }

            // Logic for smart date
            // Logic for smart date (Supports: 20.2, 2.20, 20/2/25, 2.20.2025, etc.)
            document.getElementById('examination_date_display').addEventListener('blur', function() {
                let val = this.value.trim();
                if (val === "") return;

                let day, month, year;
                const currentYear = 2026; 

                // Split based on "/" OR "." using Regular Expression
                let parts = val.split(/[/.]/);

                if (parts.length >= 2) {
                    let p1 = parseInt(parts[0]);
                    let p2 = parseInt(parts[1]);
                    let p3 = parts[2] ? parts[2].trim() : null;

                    // 1. DATE & MONTH DETERMINATION
                    if (p1 > 12) { 
                        // If the first number > 12, it must be the Day (Format: 21.2)
                        day = p1;
                        month = p2;
                    } else if (p2 > 12) {
                        // If the second number > 12, it must be the Day (Format: 2.21)
                        day = p2;
                        month = p1;
                    } else {
                        // If both are <= 12, assume format: Month.Day (Matches your initial code)
                        month = p2;
                        day = p1;
                    }

                    // 2. YEAR DETERMINATION (SMART YEAR)
                    if (p3) {
                        let yearNum = parseInt(p3);
                        if (p3.length <= 2) {
                            // If input is 2 digits (e.g., .25), assume 2000s
                            year = 2000 + yearNum;
                        } else {
                            // If input is 4 digits (e.g., .2025)
                            year = yearNum;
                        }
                    } else {
                        // If no year is provided, use the current year (2026)
                        year = currentYear;
                    }

                    // 3. SIMPLE VALIDATION
                    if (month > 12) month = 12;
                    if (day > 31) day = 31;

                    // 4. REFORMATTING
                    let formattedMonth = String(month).padStart(2, '0');
                    let formattedDay = String(day).padStart(2, '0');
                    
                    // Update Hidden Input for Database (YYYY-MM-DD)
                    document.getElementById('examination_date').value = `${year}-${formattedMonth}-${formattedDay}`;

                    // Update Display (DD/MM/YYYY) for consistency
                    this.value = `${formattedDay}/${formattedMonth}/${year}`;

                    // 5. AUTO-UPDATE EXAMINATION CODE (IMPORTANT!)
                    // Call the generate function so the LZ/EC/... code updates its month/year
                    const newCode = generateExamCode();
                    document.getElementById('hidden_exam_code').value = newCode;
                }
            });
            // Add feature: Click to highlight all text for easy editing without manual deletion
            document.getElementById('examination_date_display').addEventListener('click', function() {
                this.select();
            });

            // --- AUTO PD DEFAULT: 62/60 if ADD exists, else 62 ---
            function updatePdDefault() {
                const rAdd = document.querySelector('input[name="new_r_add"]').value.trim();
                const lAdd = document.querySelector('input[name="new_l_add"]').value.trim();
                const hasAdd = (rAdd !== '' && rAdd !== '0.00')
                            || (lAdd !== '' && lAdd !== '0.00');
                const pdInput = document.querySelector('input[name="pd_dist"]');
                const pdDefault = hasAdd ? '62/60' : '62';
                pdInput.placeholder = pdDefault;
                // Only update value if user hasn't typed anything in PD field
                if (pdInput.value === '' || pdInput.value === '62' || pdInput.value === '62/60') {
                    pdInput.value = '';
                    pdInput.placeholder = pdDefault;
                }
            }
            document.querySelector('input[name="new_r_add"]').addEventListener('input', updatePdDefault);
            document.querySelector('input[name="new_l_add"]').addEventListener('input', updatePdDefault);
            document.querySelector('input[name="new_r_add"]').addEventListener('blur', updatePdDefault);
            document.querySelector('input[name="new_l_add"]').addEventListener('blur', updatePdDefault);

            // --- SMART VISUAL ACUITY (VA) LOGIC ---
            // If the user types "40", it automatically becomes "20/40"
            document.querySelectorAll('.va-input').forEach(input => {
                input.addEventListener('blur', function() {
                    let val = this.value.trim();
                    // If it's a number and doesn't contain "/", prepend "20/"
                    if (val !== "" && !val.includes('/') && !isNaN(val)) {
                        this.value = "20/" + val;
                    }
                });
            });

            // --- SMART ARROW KEYS NAVIGATION ---
            document.addEventListener('keydown', function(e) {
                const keys = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'];
                if (!keys.includes(e.key)) return;

                const active = document.activeElement;
                
                // Ensure it only operates on prescription inputs
                if (active.tagName === 'INPUT' && 
                (active.name.includes('old_prescript') || 
                active.name.includes('new_') || 
                active.name.includes('ucva')
                )) {
                    
                    const card = active.closest('.prescription-card');
                    const inputs = Array.from(card.querySelectorAll('input[type="text"]'));
                    const index = inputs.indexOf(active);
                    
                    // Calculate the number of columns in the active row
                    const currentRow = active.closest('.pres-grid.row');
                    const columnsCount = Array.from(currentRow.querySelectorAll('input[type="text"]')).length;

                    // Check cursor position within the input
                    const cursorPosition = active.selectionStart;
                    const textLength = active.value.length;

                    let nextIndex = -1;

                    if (e.key === 'ArrowLeft') {
                        // Move input only if the cursor is at the very beginning (position 0)
                        if (cursorPosition === 0) nextIndex = index - 1;
                    } 
                    else if (e.key === 'ArrowRight') {
                        // Move input only if the cursor is at the very end
                        if (cursorPosition === textLength) nextIndex = index + 1;
                    } 
                    else if (e.key === 'ArrowUp') {
                        // Up/Down arrows usually move across rows immediately
                        nextIndex = index - columnsCount;
                    } 
                    else if (e.key === 'ArrowDown') {
                        // Up/Down arrows usually move across rows immediately
                        nextIndex = index + columnsCount;
                    }

                    // Execute focus transition
                    if (nextIndex >= 0 && nextIndex < inputs.length) {
                        e.preventDefault(); 
                        inputs[nextIndex].focus();
                        
                        // Set cursor position when entering the new input
                        if (e.key === 'ArrowLeft') {
                            // If coming from the right (pressed left), place cursor at the end of the new input text
                            const len = inputs[nextIndex].value.length;
                            inputs[nextIndex].setSelectionRange(len, len);
                        } else if (e.key === 'ArrowRight') {
                            // If coming from the left (pressed right), place cursor at the start of the new input text
                            inputs[nextIndex].setSelectionRange(0, 0);
                        } else {
                            // For Up/Down, select all text (optional, can be replaced with setSelectionRange)
                            inputs[nextIndex].select();
                        }
                    }
                }
            });

            document.getElementById('age').addEventListener('blur', function() {
                let ageVal = this.value.trim();
                let calculatedAge = 0;

                // Age detection logic (mimics your PHP logic)
                if (ageVal.includes(".")) {
                    let yearInput = ageVal.replace(".", "");
                    let yearVal = parseInt(yearInput);
                    let currentYear = 2026; // Based on your system year
                    
                    let fullYear;
                    if (yearInput.length <= 2) {
                        fullYear = (yearVal > 26) ? 1900 + yearVal : 2000 + yearVal;
                    } else {
                        fullYear = yearVal;
                    }
                    calculatedAge = currentYear - fullYear;
                } else {
                    calculatedAge = parseInt(ageVal);
                }

                // Replace input value with calculated age (e.g., .96 → 30)
                if (!isNaN(calculatedAge) && calculatedAge > 0) {
                    this.value = calculatedAge;
                }

                // Auto-fill ADD if age is valid
                if (!isNaN(calculatedAge) && calculatedAge > 0) {
                    let suggestedAdd = calculateAddByAge(calculatedAge);
                    
                    // Only fill if ADD field is empty or contains 0.00
                    // To avoid overwriting data if the user is revising the entry
                    const rAdd = document.querySelector('input[name="new_r_add"]');
                    const lAdd = document.querySelector('input[name="new_l_add"]');
                    
                    if (suggestedAdd !== "") {
                        rAdd.value = suggestedAdd;
                        lAdd.value = suggestedAdd;
                        updatePdDefault(); // update PD default setelah ADD diisi otomatis
                    }
                }

                // Show / hide Vision Need section
                updateVisionNeedVisibility(calculatedAge);
            });

            // Panggil saat page load agar PD default langsung sesuai kondisi awal ADD
            updatePdDefault();

            // ================================================================
            // === INPUT REVIEW CARD LOGIC =====================================
            // ================================================================

            const _rvHabitLabels  = { '1': 'INDOOR', '2': 'OUTDOOR', '3': 'BOTH' };
            const _rvDigitalLabels = { '1': 'LOW (< 2H)', '2': 'MODERATE (2–5H)', '3': 'HIGH (> 5H)' };

            function _rvVal(id) {
                const el = document.getElementById(id);
                return el ? el.value.trim() : '';
            }
            function _rvInput(name) {
                const el = document.querySelector(`input[name="${name}"]`);
                return el ? el.value.trim() : '';
            }
            function _rvSet(id, text, cls) {
                const el = document.getElementById(id);
                if (!el) return;
                el.textContent = text || '—';
                el.className = 'review-value' + (cls ? ' ' + cls : '');
            }
            function _rvTd(id, val) {
                const el = document.getElementById(id);
                if (!el) return;
                const empty = (!val || val === '0.00' || val === '0');
                el.textContent = val || '—';
                el.className = empty ? 'val-zero' : '';
            }

            function updateReviewCard() {
                let filledCount = 0;

                // Name
                const nameVal = _rvVal('customer_name');
                if (nameVal) { _rvSet('rv_name', nameVal.toUpperCase(), 'highlight'); filledCount++; }
                else _rvSet('rv_name', '—', 'muted');

                // Gender
                const genderVal = _rvVal('gender');
                _rvSet('rv_gender', genderVal || '—');
                if (genderVal) filledCount++;

                // Age
                const ageVal = _rvVal('age');
                if (ageVal && parseInt(ageVal) > 0) {
                    _rvSet('rv_age', ageVal + ' years old');
                    filledCount++;
                } else _rvSet('rv_age', '—', 'muted');

                // Date
                const dateDisp = document.getElementById('examination_date_display');
                const dateVal  = dateDisp ? dateDisp.value.trim() : '';
                if (dateVal) { _rvSet('rv_date', dateVal); filledCount++; }
                else _rvSet('rv_date', '—', 'muted');

                // Exam Code
                const codeVal = _rvVal('hidden_exam_code') || generateExamCode();
                _rvSet('rv_code', codeVal, 'highlight');

                // Symptoms
                const sympEl = document.getElementById('symptom_summary');
                const sympText = sympEl ? sympEl.innerText.trim() : '';
                const sympWrap = document.getElementById('rv_symptoms_wrap');
                sympWrap.innerHTML = '';
                if (sympText && sympText !== 'NO SYMPTOMS SELECTED') {
                    sympText.split(',').forEach(s => {
                        s = s.trim();
                        if (s) {
                            const tag = document.createElement('span');
                            tag.className = 'review-symptom-tag';
                            tag.textContent = s;
                            sympWrap.appendChild(tag);
                        }
                    });
                    filledCount++;
                } else {
                    const none = document.createElement('span');
                    none.className = 'review-value muted';
                    none.textContent = 'None selected';
                    sympWrap.appendChild(none);
                }

                // Visual Habit & Digital Usage
                const habitVal   = _rvVal('visual_habit');
                const digitalVal = _rvVal('digital_usage');
                _rvSet('rv_visual_habit', _rvHabitLabels[habitVal] || habitVal || '—');
                _rvSet('rv_digital_usage', _rvDigitalLabels[digitalVal] || digitalVal || '—');

                // New Prescription
                const fields = [
                    ['rv_ucva_r','ucva_r'],['rv_r_sph','new_r_sph'],['rv_r_cyl','new_r_cyl'],
                    ['rv_r_ax','new_r_ax'],['rv_r_add','new_r_add'],['rv_r_va','new_r_va'],
                    ['rv_ucva_l','ucva_l'],['rv_l_sph','new_l_sph'],['rv_l_cyl','new_l_cyl'],
                    ['rv_l_ax','new_l_ax'],['rv_l_add','new_l_add'],['rv_l_va','new_l_va'],
                ];
                let presHasVal = false;
                fields.forEach(([tdId, fieldName]) => {
                    const v = _rvInput(fieldName);
                    _rvTd(tdId, v || '—');
                    if (v && v !== '0.00' && v !== '0') presHasVal = true;
                });
                if (presHasVal) filledCount++;

                // PD
                const pdEl = document.querySelector('input[name="pd_dist"]');
                const pdVal = pdEl ? (pdEl.value.trim() || pdEl.placeholder || '62') : '62';
                _rvSet('rv_pd', pdVal, 'highlight');

                // Old Prescription
                const hasOld = _rvVal('has_old_prescription_input');
                const oldSec = document.getElementById('review_sec_old_pres');
                if (hasOld === 'yes') {
                    oldSec.style.display = 'block';
                    _rvTd('rv_old_r_sph', _rvInput('old_prescript_R_sph'));
                    _rvTd('rv_old_r_cyl', _rvInput('old_prescript_R_cyl'));
                    _rvTd('rv_old_r_ax',  _rvInput('old_prescript_R_ax'));
                    _rvTd('rv_old_r_add', _rvInput('old_prescript_R_add'));
                    _rvTd('rv_old_l_sph', _rvInput('old_prescript_L_sph'));
                    _rvTd('rv_old_l_cyl', _rvInput('old_prescript_L_cyl'));
                    _rvTd('rv_old_l_ax',  _rvInput('old_prescript_L_ax'));
                    _rvTd('rv_old_l_add', _rvInput('old_prescript_L_add'));
                    filledCount++;
                } else {
                    oldSec.style.display = 'none';
                }

                // Notes
                const notesEl = document.getElementById('exam_notes');
                const notesVal = notesEl ? notesEl.value.trim() : '';
                const notesSec = document.getElementById('review_sec_notes');
                if (notesVal) {
                    notesSec.style.display = 'block';
                    document.getElementById('rv_notes').textContent = notesVal;
                    filledCount++;
                } else {
                    notesSec.style.display = 'none';
                }

                // Show / hide card and update badge
                const card = document.getElementById('input_review_card');
                const badge = document.getElementById('review_field_count');
                if (filledCount > 0) {
                    card.style.display = 'block';
                    card.classList.add('has-data');
                    badge.textContent = filledCount + (filledCount === 1 ? ' field' : ' fields');
                } else {
                    card.style.display = 'none';
                    card.classList.remove('has-data');
                }
            }

            function toggleReviewCard() {
                const card = document.getElementById('input_review_card');
                card.classList.toggle('expanded');
            }

            // Watch all relevant inputs & trigger updateReviewCard
            (function _attachReviewListeners() {
                const watchIds = ['customer_name','gender','age','visual_habit','digital_usage',
                    'examination_date_display','hidden_exam_code','exam_notes'];
                watchIds.forEach(id => {
                    const el = document.getElementById(id);
                    if (el) { el.addEventListener('input', updateReviewCard); el.addEventListener('change', updateReviewCard); }
                });

                const watchNames = [
                    'ucva_r','new_r_sph','new_r_cyl','new_r_ax','new_r_add','new_r_va',
                    'ucva_l','new_l_sph','new_l_cyl','new_l_ax','new_l_add','new_l_va',
                    'pd_dist',
                    'old_prescript_R_sph','old_prescript_R_cyl','old_prescript_R_ax','old_prescript_R_add',
                    'old_prescript_L_sph','old_prescript_L_cyl','old_prescript_L_ax','old_prescript_L_add',
                ];
                watchNames.forEach(name => {
                    const el = document.querySelector(`input[name="${name}"], textarea[name="${name}"]`);
                    if (el) { el.addEventListener('input', updateReviewCard); el.addEventListener('blur', updateReviewCard); }
                });

                // Also observe hidden inputs that are set programmatically (gender, habit, etc.)
                const hiddenObs = ['gender','visual_habit','digital_usage','has_old_prescription_input'];
                hiddenObs.forEach(id => {
                    const el = document.getElementById(id);
                    if (!el) return;
                    const observer = new MutationObserver(updateReviewCard);
                    observer.observe(el, { attributes: true, attributeFilter: ['value'] });
                    el.addEventListener('change', updateReviewCard);
                });

                // Symptom summary is updated by JS — observe its text
                const sympEl = document.getElementById('symptom_summary');
                if (sympEl) {
                    new MutationObserver(updateReviewCard).observe(sympEl, { childList: true, characterData: true, subtree: true });
                }

                // Textarea for other_symptoms
                const otherEl = document.getElementById('other_symptoms');
                if (otherEl) otherEl.addEventListener('input', updateReviewCard);

                // Patch toggleNeuPanel & toggleNeu to also fire updateReviewCard after
                const _origToggleNeu = window.toggleNeu;
                window.toggleNeu = function() { _origToggleNeu.apply(this, arguments); updateReviewCard(); };
                const _origToggleNeuPanel = window.toggleNeuPanel;
                window.toggleNeuPanel = function() { _origToggleNeuPanel.apply(this, arguments); updateReviewCard(); };
            })();

            // Initial call to set state on page load
            setTimeout(updateReviewCard, 50);

            // ================================================================
            // === LENSOMETER MODAL LOGIC ======================================
            // ================================================================

            // Stored calculation results (both eyes)
            var _lmData = { od: null, os: null };

            function openLensmeterModal() {
                document.getElementById('lensmeter_modal_overlay').style.display = 'block';
                document.body.style.overflow = 'hidden';
            }

            function closeLensmeterModal() {
                document.getElementById('lensmeter_modal_overlay').style.display = 'none';
                document.body.style.overflow = '';
            }

            // Close modal when clicking the backdrop
            document.getElementById('lensmeter_modal_overlay').addEventListener('click', function(e) {
                if (e.target === this) closeLensmeterModal();
            });

            function lmSwitchEye(eye) {
                var odTab = document.getElementById('lm_tab_od');
                var osTab = document.getElementById('lm_tab_os');
                var odPanel = document.getElementById('lm_panel_od');
                var osPanel = document.getElementById('lm_panel_os');
                var isOd = (eye === 'od');

                odPanel.style.display = isOd ? 'block' : 'none';
                osPanel.style.display = isOd ? 'none' : 'block';

                odTab.style.borderColor = isOd ? '#00ff88' : '#333';
                odTab.style.background  = isOd ? '#1a2c1a' : '#1a1d22';
                odTab.style.color       = isOd ? '#00ff88' : '#555';

                osTab.style.borderColor = isOd ? '#333' : '#00ff88';
                osTab.style.background  = isOd ? '#1a1d22' : '#1a2c1a';
                osTab.style.color       = isOd ? '#555' : '#00ff88';

                lmUpdatePreview();
            }

            // Auto-fill axis (90 deg apart) - same logic as lensmeter.php
            function lmPairAxis(val) {
                return val > 90 ? val - 90 : val + 90;
            }
            function lmAutoFillDuaAxis(eye) {
                var tigaEl = document.getElementById('lm_' + eye + '_tiga_axis');
                var duaEl  = document.getElementById('lm_' + eye + '_dua_axis');
                var val    = parseInt(tigaEl.value);
                duaEl.value = (isNaN(val) || tigaEl.value.trim() === '') ? '' : lmPairAxis(val);
                lmLiveCalc();
            }
            function lmAutoFillTigaAxis(eye) {
                var duaEl  = document.getElementById('lm_' + eye + '_dua_axis');
                var tigaEl = document.getElementById('lm_' + eye + '_tiga_axis');
                var val    = parseInt(duaEl.value);
                tigaEl.value = (isNaN(val) || duaEl.value.trim() === '') ? '' : lmPairAxis(val);
                lmLiveCalc();
            }

            function lmParseVal(s) {
                s = String(s).trim();
                if (!s || s === '-') return null;
                var n = parseFloat(s);
                if (isNaN(n)) return null;
                if (s.indexOf('.') === -1 && Math.abs(n) >= 25 && Math.round(n) % 25 === 0) {
                    n = n / 100;
                }
                return n;
            }
            function lmRoundQ(n) { return Math.round(n * 4) / 4; }
            function lmFmt(n) {
                if (n === 0) return '0.00';
                return (n > 0 ? '+' : '') + n.toFixed(2);
            }

            function lmCalcEye(tigaPower, tigaAxis, duaPower, duaAxis) {
                var tp = lmParseVal(tigaPower);
                var dp = lmParseVal(duaPower);
                var ta = parseInt(tigaAxis) || 0;
                if (tp === null && dp === null) return null;
                if (tp === null) { tp = dp; ta = 0; }
                if (dp === null) { dp = tp; }
                tp = lmRoundQ(tp);
                dp = lmRoundQ(dp);
                var sph, cyl, axis;
                if (Math.abs(tp - dp) < 0.01) {
                    sph = dp; cyl = 0; axis = 0;
                } else {
                    sph = dp;
                    cyl = lmRoundQ(tp - dp);
                    axis = ta;
                }
                return { sph: sph, cyl: cyl, axis: axis };
            }

            function lmTranspose(e) {
                if (!e || e.cyl === 0) return e ? { sph: e.sph, cyl: 0, axis: 0 } : null;
                return {
                    sph:  lmRoundQ(e.sph + e.cyl),
                    cyl:  lmRoundQ(-e.cyl),
                    axis: e.axis > 90 ? e.axis - 90 : e.axis + 90
                };
            }

            // Choose which version (original or transposed) has CYL negative (minus)
            // Rule: pick the one where CYL < 0.
            // If both CYL == 0 (spherical) use original.
            // If orig CYL > 0 use transposition (which will have cyl < 0).
            function lmPickNegCyl(orig, trans) {
                if (!orig) return { data: null, label: 'original' };
                if (orig.cyl === 0) return { data: orig, label: 'original (spherical)' };
                if (orig.cyl < 0)   return { data: orig, label: 'original' };
                return { data: trans, label: 'transposition' };
            }

            function lmUpdatePreview() {
                var activeEye = document.getElementById('lm_panel_od').style.display !== 'none' ? 'od' : 'os';

                var orig = lmCalcEye(
                    document.getElementById('lm_' + activeEye + '_tiga_power').value,
                    document.getElementById('lm_' + activeEye + '_tiga_axis').value,
                    document.getElementById('lm_' + activeEye + '_dua_power').value,
                    document.getElementById('lm_' + activeEye + '_dua_axis').value
                );
                var trans = orig ? lmTranspose(orig) : null;
                var picked = lmPickNegCyl(orig, trans);

                _lmData[activeEye] = { orig: orig, trans: trans, picked: picked };

                var preview = document.getElementById('lm_result_preview');
                if (!orig) { preview.style.display = 'none'; return; }

                preview.style.display = 'block';

                var fmtRow = function(e) {
                    if (!e) return '---';
                    var s = lmFmt(e.sph);
                    if (e.cyl !== 0) {
                        s += ' / ' + lmFmt(e.cyl) + ' x ' + e.axis + 'deg';
                    } else {
                        s += ' sph';
                    }
                    return s;
                };

                document.getElementById('lm_preview_orig').innerHTML  = fmtRow(orig);
                document.getElementById('lm_preview_trans').innerHTML = fmtRow(trans);

                var whichEl = document.getElementById('lm_which_used');
                var cylNote = picked.data && picked.data.cyl < 0
                    ? 'CYL negative (-)  will be used'
                    : (picked.data && picked.data.cyl === 0 ? 'Spherical only' : '');
                whichEl.textContent = 'Will apply: ' + picked.label.toUpperCase() + ' — ' + cylNote;
            }

            function lmLiveCalc() {
                lmUpdatePreview();
            }

            // Apply picked result for one eye to the OLD PRESCRIPTION inputs
            function lmApply(eye) {
                var orig = lmCalcEye(
                    document.getElementById('lm_' + eye + '_tiga_power').value,
                    document.getElementById('lm_' + eye + '_tiga_axis').value,
                    document.getElementById('lm_' + eye + '_dua_power').value,
                    document.getElementById('lm_' + eye + '_dua_axis').value
                );
                if (!orig) {
                    alert('Please enter lensometer readings for ' + (eye === 'od' ? 'RIGHT (OD)' : 'LEFT (OS)') + ' first.');
                    return;
                }
                var trans  = lmTranspose(orig);
                var picked = lmPickNegCyl(orig, trans);
                var d      = picked.data;
                if (!d) return;

                var prefix = eye === 'od' ? 'old_prescript_R' : 'old_prescript_L';
                var sphEl  = document.querySelector('input[name="' + prefix + '_sph"]');
                var cylEl  = document.querySelector('input[name="' + prefix + '_cyl"]');
                var axEl   = document.querySelector('input[name="' + prefix + '_ax"]');

                if (sphEl) sphEl.value = lmFmt(d.sph);
                if (cylEl) cylEl.value = d.cyl !== 0 ? lmFmt(d.cyl) : '0.00';
                if (axEl)  axEl.value  = d.cyl !== 0 ? String(d.axis) : '0';

                // Fire events so review card and other listeners update
                [sphEl, cylEl, axEl].forEach(function(el) {
                    if (el) {
                        el.dispatchEvent(new Event('input'));
                        el.dispatchEvent(new Event('blur'));
                    }
                });

                // Flash feedback on the button
                var btn = document.getElementById('lm_btn_apply_' + eye);
                var origText = btn.textContent;
                btn.textContent = 'APPLIED!';
                btn.style.background = '#0a3a1a';
                setTimeout(function() {
                    btn.textContent = origText;
                    btn.style.background = '#1a2c1a';
                }, 1200);
            }

            function lmApplyBoth() {
                lmApply('od');
                lmApply('os');
                setTimeout(closeLensmeterModal, 900);
            }

            function lmReset() {
                ['od','os'].forEach(function(eye) {
                    ['dua_power','dua_axis','tiga_power','tiga_axis'].forEach(function(field) {
                        var el = document.getElementById('lm_' + eye + '_' + field);
                        if (el) el.value = '';
                    });
                });
                document.getElementById('lm_result_preview').style.display = 'none';
                _lmData = { od: null, os: null };
            }

            // ================================================================
            // === END LENSOMETER MODAL LOGIC ==================================
            // ================================================================

            // ================================================================
            // === END INPUT REVIEW CARD LOGIC =================================
            // ================================================================
        </script>
        <!-- button logout, back animation for logo -->
        <script>
            // Single tap/click on the logo zooms it in (only if not already zoomed).
            function zoomInLogo(imgEl) {
                if (imgEl.classList.contains('zoomed')) return;
                imgEl.classList.add('zoomed');
                document.getElementById('logoBackdrop').classList.add('active');
            }

            // Double tap/click zooms it back out.
            function zoomOutLogo(imgEl) {
                imgEl.classList.remove('zoomed');
                document.getElementById('logoBackdrop').classList.remove('active');
            }

            // Animate the new pill-style Back button before navigating
            function handleBackClick(element) {
                const icon = element.querySelector('.neu-pill-icon');
                const text = element.querySelector('.neu-pill-text');

                // Make sure nothing else fights with our manual animation.
                element.style.transition = 'none';
                text.style.transition = 'none';

                const startWidth = element.offsetWidth;
                // Target: just the round icon left, with the button's own
                // left/right padding preserved (6px left, 6px right when collapsed).
                const targetWidth = icon.offsetWidth + 12;

                // Hide the text immediately so only the shrinking pill is visible.
                text.style.opacity = '0';

                const duration = 400; // ms
                const startTime = performance.now();

                function step(now) {
                    const elapsed = now - startTime;
                    const progress = Math.min(elapsed / duration, 1);
                    const eased = 1 - Math.pow(1 - progress, 3);

                    const currentWidth = startWidth - (startWidth - targetWidth) * eased;
                    element.style.width = currentWidth + 'px';

                    if (progress < 1) {
                        requestAnimationFrame(step);
                    } else {
                        // back direction
                        window.location.href = 'customer.php';
                    }
                }
                requestAnimationFrame(step);
            }

            // Animate the new pill-style Logout button before logging out
            function handleLogoutClick(element) {
                element.classList.add('pressed');
                setTimeout(() => {
                    window.location.href = 'logout.php';
                }, 220);
            }

            // Function executed when a button is clicked
            function handleButtonClick(element) {
                // 1. Get the URL from the data-url attribute
                const targetUrl = element.getAttribute('data-url');
                
                // 2. Save this URL to localStorage as the active button identity
                localStorage.setItem('activeMenuUrl', targetUrl);
                
                // 3. Add the active class immediately (for an instant visual effect)
                document.querySelectorAll('.neu-button').forEach(btn => btn.classList.remove('active'));
                element.classList.add('active');

                // 4. Navigate to the page
                window.location.href = targetUrl;
            }

            // Function that runs automatically when the page is refreshed or returned to (Back)
            window.addEventListener('DOMContentLoaded', () => {
                const activeUrl = localStorage.getItem('activeMenuUrl');
                
                if (activeUrl) {
                    document.querySelectorAll('.neu-button').forEach(btn => {
                        // If the button's data-url matches the one in memory, activate it!
                        if (btn.getAttribute('data-url') === activeUrl) {
                            btn.classList.add('active');
                        }
                    });
                }
            });
        </script>
    </body>
</html>