<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

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

        // PD defaults to 62/60 if empty
        $pd_dist_val = cleanPres($conn, $_POST['pd_dist'], "62/60");

        // Get Visual Habits & Digital Usage
        $visual_habit = (int)($_POST['visual_habit'] ?? 1);
        $digital_usage = (int)($_POST['digital_usage'] ?? 1);
        $exam_notes = mysqli_real_escape_string($conn, $_POST['exam_notes']);

        // UCVA Data
        $ucva_r = cleanPres($conn, $_POST['ucva_r'], "20/20");
        $ucva_l = cleanPres($conn, $_POST['ucva_l'], "20/20");
        
        // 6. Insert Query using Prepared Statement
        $stmt = $conn->prepare("INSERT INTO customer_examinations (
            examination_date, examination_code, customer_name, gender, age, symptoms,
            old_r_sph, old_r_cyl, old_r_ax, old_r_add,
            old_l_sph, old_l_cyl, old_l_ax, old_l_add,
            new_r_sph, new_r_cyl, new_r_ax, new_r_add, new_r_visus,
            new_l_sph, new_l_cyl, new_l_ax, new_l_add, new_l_visus,
            pd_dist, invoice_number,
            visual_habit, digital_usage, ucva_r, ucva_l, exam_notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        // Define data types: 
        $types = "ssssi" . str_repeat("s", 21) . "iisss"; 

        $stmt->bind_param($types, 
            $exam_date, $exam_code, $customer_name, $gender, $age_to_save, $symptoms_to_save,
            $old_r_sph, $old_r_cyl, $old_r_ax, $old_r_add,
            $old_l_sph, $old_l_cyl, $old_l_ax, $old_l_add,
            $new_r_sph, $new_r_cyl, $new_r_ax, $new_r_add, $new_r_va,
            $new_l_sph, $new_l_cyl, $new_l_ax, $new_l_add, $new_l_va,
            $pd_dist_val, $invoice_val,
            $visual_habit, $digital_usage, $ucva_r, $ucva_l, $exam_notes
        );

        if ($stmt->execute()) {
            if ($invoice_val !== '00') {
                // Jika belanja, tetap ke invoice
                header("Location: invoice.php?code=" . $exam_code);
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
    $query_seq = "SELECT examination_code FROM customer_examinations ORDER BY id DESC LIMIT 1";
    $res_seq   = mysqli_query($conn, $query_seq);
    $sequence  = 1;

    if ($res_seq && mysqli_num_rows($res_seq) > 0) {
        $last_row = mysqli_fetch_assoc($res_seq);
        $parts = explode('/', $last_row['examination_code']);
        $sequence = (isset($parts[2])) ? (int)$parts[2] + 1 : 1;
    }
    $seq_padded = str_pad($sequence, 3, '0', STR_PAD_LEFT);
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
                grid-template-columns: repeat(3, 1fr);
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

            .swal2-popup {
                border: 1px solid #00ff88 !important;
                box-shadow: 0 0 20px rgba(0, 255, 136, 0.2) !important;
            }
            /* --- RESPONSIVE FIX --- */
            @media (max-width: 600px) {
                /* Change grid from 3 columns to 2 columns to prevent buttons from being squashed */
                .symptoms-grid {
                    grid-template-columns: repeat(2, 1fr); 
                    gap: 10px;
                }

                /* Buttons that were originally full-width remain full-width */
                .symptoms-grid > div[style*="grid-column: 1 / -1"] {
                    display: grid !important; /* Change flex to grid for mobile */
                    grid-template-columns: repeat(1, 1fr);
                    width: 100%;
                }
                
                /* Specifically for Diabetes & Hypertension rows, set to 1 column on mobile */
                .symptoms-grid > div[style*="gap: 15px"] {
                    display: grid !important;
                    grid-template-columns: 1fr !important;
                    gap: 10px !important;
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
                    overflow: hidden !important; /* Prevents the card from breaking the main layout */
                }

                .prescription-table {
                    display: block !important;
                    width: 100% !important;
                    overflow-x: auto !important; /* KEY: Enable horizontal scrolling */
                    -webkit-overflow-scrolling: touch;
                    padding-bottom: 15px;
                }

                .pres-grid {
                    display: grid !important;
                    /* FORCE minimum width to prevent stacking. 750px is usually safe for 7 columns */
                    min-width: 750px !important; 
                    gap: 5px !important;
                }

                /* Force inputs to have a fixed width so values like +2.00 stay on one line */
                .pres-grid input {
                    width: 100% !important;
                    min-width: 75px !important; 
                    font-size: 0.9em !important;
                    padding: 12px 2px !important;
                    white-space: nowrap !important; /* Prevent text from wrapping */
                }

                .eye-label {
                    min-width: 60px !important;
                    font-size: 0.7em !important;
                    white-space: nowrap !important;
                }

                .pres-grid.header {
                    font-size: 0.65em !important;
                }

                /* Ensure grid columns do not use flexible 'fr' units on mobile */
                .pres-grid.header, .pres-grid.row {
                    grid-template-columns: 80px repeat(6, 100px) !important; 
                }

                .swal2-html-container table {
                    display: block !important;
                    width: 100% !important;
                    overflow-x: auto !important;
                    white-space: nowrap !important;
                }

                .swal2-html-container th, .swal2-html-container td {
                    padding: 8px 12px !important;
                    min-width: 60px;
                }
            }
        </style>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    </head>

    <body>        
        <div class="main-wrapper">
            <div class="content-area" style="flex-direction: column">
                <div class="header-container" style="
                margin-left: auto; 
                margin-right: auto; 
                width: 100%;">
                    <button class="logout-btn" onclick="window.location.href='logout.php';">
                        <span>Logout</span>
                    </button>
            
                    <div class="brand-section">
                        <div class="logo-box">
                            <img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" style="height: 40px;">
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
                            <div class="input-group">
                                <label for="customer_name">NAME</label>
                                <input type="text" id="customer_name" name="customer_name" required placeholder="LENZA CUSTOMER" style="text-transform: uppercase;">
                            </div>

                            <!-- GENDER -->
                            <div class="input-group" style="flex: 0 0 100%; max-width: 100%; grid-column: 1 / -1; width: 100% !important;">
                                <label style="width: 100%; text-align: center; margin-bottom: 0;">GENDER</label>
                                <input type="hidden" name="gender" id="gender" value="FEMALE">
                                <div class="selection-wrapper">
                                    <button style="min-width: 100px;" value="FEMALE" type="button" class="neu-btn active"onclick="toggleNeu(this, 'gender')">
                                        <span>FEMALE</span>
                                        <div class="led"></div>
                                    </button>
                                    <button style="min-width: 100px;" value="MALE" type="button" class="neu-btn"onclick="toggleNeu(this, 'gender')">
                                        <span>MALE</span>
                                        <div class="led"></div>
                                    </button>
                                </div>
                            </div>
            
                            <!-- AGE -->
                            <div class="input-group">
                                <label for="age">AGE / BIRTH YEAR</label>
                                <input type="text" id="age" name="age" 
                                    inputmode="tel"
                                    placeholder="Example: 25 (Age) or .96 (Year)" 
                                    autocomplete="off">
                            </div>

                            <!-- VISUAL HABITS -->
                            <div class="input-group">
                                <label>VISUAL HABITS</label>
                                <select name="visual_habit" 
                                style="color: #00ff88; font-weight: bold; text-align: center;"
                                value="1">
                                    <option value="1">INDOOR</option>
                                    <option value="2">OUTDOOR</option>
                                    <option value="3">BOTH</option>
                                </select>
                            </div>
                            
                            <!-- DIGITAL DEVICE USAGE -->
                            <div class="input-group">
                                <label>DIGITAL DEVICE USAGE</label>
                                <select name="digital_usage" 
                                style="color: #00ff88; font-weight: bold; text-align: center;"
                                value="1">
                                    <option value="1">LOW (< 2h)</option>
                                    <option value="2">MODERATE (2h - 5h)</option>
                                    <option value="3">HIGH (> 5h)</option>
                                </select>
                            </div>
            
                            <!-- SYMPTOMS -->
                            <div class="input-group" style="grid-column: 1 / -1;">
                                <label>SYMPTOMS / COMPLAINTS</label>
                                <div id="btn_open_symptoms" style="background: #25282a; padding: 15px; border-radius: 12px; border: 1px solid #444; cursor: pointer; display: flex; justify-content: space-between; align-items: center; box-shadow: inset 2px 2px 5px #1a1c1d;">
                                    <span id="symptom_summary" style="color: #888; font-size: 0.9em;">NO SYMPTOMS SELECTED</span>
                                    <span id="arrow_icon" style="color: #00ff88;">▼</span>
                                </div>

                                <div id="symptoms_panel" style="display: none; background: #2b2e30; padding: 25px; border-radius: 15px; margin-top: 10px; border: 1px solid #00ff8844; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
                                    <div class="symptoms-grid">
                                        <button type="button" class="neu-btn symptom-btn" onclick="toggleSymptom(this, 'MYOPIA')"><span>MYOPIA</span><div class="led"></div></button>
                                        <button type="button" class="neu-btn symptom-btn" onclick="toggleSymptom(this, 'HYPEROPIA')"><span>HYPEROPIA</span><div class="led"></div></button>
                                        <button type="button" class="neu-btn symptom-btn" onclick="toggleSymptom(this, 'ASTIGMATISM')"><span>ASTIGMATISM</span><div class="led"></div></button>

                                        <button type="button" class="neu-btn symptom-btn" onclick="toggleSymptom(this, 'PRESBYOPIA')"><span>PRESBYOPIA</span><div class="led"></div></button>
                                        <button type="button" class="neu-btn symptom-btn" onclick="toggleSymptom(this, 'CATARACT')"><span>CATARACT</span><div class="led"></div></button>
                                        <button type="button" class="neu-btn symptom-btn" onclick="toggleSymptom(this, 'HEADACHE')"><span>HEADACHE</span><div class="led"></div></button>

                                        <div style="grid-column: 1 / -1; display: flex; justify-content: center;">
                                            <button type="button" class="neu-btn symptom-btn" onclick="toggleSymptom(this, 'GLAUCOMA')"><span>GLAUCOMA</span><div class="led"></div></button>
                                        </div>

                                        <div style="grid-column: 1 / -1; display: flex; justify-content: center; gap: 15px; width: 100%;">
                                            <button type="button" class="neu-btn symptom-btn" onclick="toggleSymptom(this, 'Diabetes', 'dm_detail')"><span>DIABETES</span><div class="led"></div></button>
                                            <button type="button" class="neu-btn symptom-btn" onclick="toggleSymptom(this, 'Hypertension', 'ht_detail')"><span>HYPERTENSION</span><div class="led"></div></button>
                                        </div>
                                    </div>

                                    <div id="dm_detail" class="hidden-detail">
                                        <label>DIABETES MILITUS DATA</label>
                                        <div style="display:flex; gap:10px;">
                                            <input type="text" name="dm_sugar" placeholder="Sugar Level (mg/dL)" style="flex:2">
                                            <select name="dm_status" style="flex:1"><option>CONTROLLED</option><option>UNCONTROLLED</option></select>
                                        </div>
                                    </div>

                                    <div id="ht_detail" class="hidden-detail">
                                        <label>HYPERTENSION DATA</label>
                                        <div style="display:flex; gap:10px;">
                                            <input type="text" name="ht_pressure" placeholder="Tension (e.g. 140/90)" style="flex:2">
                                            <select name="ht_status" style="flex:1"><option>CONTROLLED</option><option>UNCONTROLLED</option></select>
                                        </div>
                                    </div>

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
            
                            <!-- HAS OLD PRESCRIPTION? -->
                            <div class="input-group" style="flex: 0 0 100%; max-width: 100%; grid-column: 1 / -1; width: 100% !important;">
                                <label style="width: 100%; text-align: center; margin-bottom: 0;">HAS OLD PRESCRIPTION?</label>
                                <input type="hidden" name="has_old_prescription" id="has_old_prescription_input" value="no">
                                <div id="old_prescription_option" class="selection-wrapper">
                                    <button value="no" type="button" class="neu-btn active" onclick="toggleNeu(this, 'has_old_prescription_input', true)">
                                        <span>NO</span>
                                        <div class="led"></div>
                                    </button>
                                    <button value="yes" type="button" class="neu-btn" onclick="toggleNeu(this, 'has_old_prescription_input', true)">
                                        <span>YES</span>
                                        <div class="led"></div>
                                    </button>
                                </div>
                            </div>    
            
                            <!-- CUSTOMER OLD PRESCIPTION -->
                            <div id="old_prescript" style="display: none; grid-column: 1 / -1; width: 100%; margin-top: 20px;">
                                <div class="prescription-card">
                                    <h3 style="color: #00ff88; font-size: 0.9em; text-align: center; margin-bottom: 15px; letter-spacing: 1px;">OLD PRESCRIPTION DATA</h3>
                                    
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
                                    </div>
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
                        </div>
                        
                        <!-- SUBMIT -->
                        <div class="btn-group" style="grid-column: 1 / -1; margin-top: 30px; display: flex; justify-content: center;">
                            <button type="submit" name="submit_customer_prescription" class="submit-main" style="width: 100%; max-width: 400px;">SAVE DATA</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="btn-group">
                <button type="button" class="back-main" onclick="window.location.href='customer.php'">BACK TO PREVIOUS PAGE</button>
            </div>
        
            <footer class="footer-container">
                <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
            </footer>
        </div>

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
                    console.log("Input " + hiddenInputId + " updated to: " + val); // Cek di console (F12)
                }

                // Specific logic to display the old prescription form
                if (isOldPrescription) {
                    const oldBox = document.getElementById('old_prescript');
                    oldBox.style.display = (val === 'yes') ? 'block' : 'none'; // Use flex for aligned inputs
                    oldBox.style.flexWrap = 'wrap';
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
            };

            function closePanel() {
                panel.style.display = 'none';
                document.getElementById('arrow_icon').innerText = '▼';
            }

            function toggleSymptom(btn, value, detailId = null) {
                btn.classList.toggle('active');
                
                // Ensure the value is stored in uppercase
                const upperValue = value.toUpperCase();
                
                if (btn.classList.contains('active')) {
                    selectedSymptoms.push(upperValue);
                    if (detailId) document.getElementById(detailId).style.display = 'block';
                } else {
                    selectedSymptoms = selectedSymptoms.filter(item => item !== upperValue);
                    if (detailId) document.getElementById(detailId).style.display = 'none';
                }
                
                // Display in summary with comma separators, all automatically uppercase from the array
                summary.innerText = selectedSymptoms.length > 0 
                    ? selectedSymptoms.join(', ') 
                    : "NO SYMPTOMS SELECTED";
                
                // Still save to JSON to be sent to PHP
                document.getElementById('symptom_list_json').value = JSON.stringify(selectedSymptoms);
            }

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

                const pd = document.querySelector('input[name="pd_dist"]').value || "62/60";

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
        </script>
    </body>
</html>