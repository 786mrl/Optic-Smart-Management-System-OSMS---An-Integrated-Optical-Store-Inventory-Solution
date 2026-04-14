<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    if (isset($_POST['save_modification'])) {
        $inv = mysqli_real_escape_string($conn, $_POST['invoice_number']);
        
        // Fetch input data
        $od_sph = mysqli_real_escape_string($conn, $_POST['od_sph']);
        $od_cyl = mysqli_real_escape_string($conn, $_POST['od_cyl']);
        $od_axis = mysqli_real_escape_string($conn, $_POST['od_axis']);
        $od_add = mysqli_real_escape_string($conn, $_POST['od_add']);
        $os_sph = mysqli_real_escape_string($conn, $_POST['os_sph']);
        $os_cyl = mysqli_real_escape_string($conn, $_POST['os_cyl']);
        $os_axis = mysqli_real_escape_string($conn, $_POST['os_axis']);
        $os_add = mysqli_real_escape_string($conn, $_POST['os_add']);
    
        // Save to modification table
        $sql_mod = "INSERT INTO prescription_modifications (invoice_number, od_sph, od_cyl, od_axis, od_add, os_sph, os_cyl, os_axis, os_add) 
                    VALUES ('$inv', '$od_sph', '$od_cyl', '$od_axis', '$od_add', '$os_sph', '$os_cyl', '$os_axis', '$os_add')";
        
        // Update status in customer_examinations table
        $sql_update = "UPDATE customer_examinations SET lens_modification = 1 WHERE invoice_number = '$inv'";
    
        if (mysqli_query($conn, $sql_mod) && mysqli_query($conn, $sql_update)) {
            header("Location: invoice.php?inv=$inv&status=success");
            exit();
        }
    }

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

    // Check 'inv' parameter (manual) or 'code' (from customer_prescription.php)
    $invoice_num = $_GET['inv'] ?? $_GET['code'] ?? '';
    $invoice_num = mysqli_real_escape_string($conn, $invoice_num);

    if (empty($invoice_num) || $invoice_num === '00' || $invoice_num === '000') {
        die("
            <div style='background:#1a1c1d; color:#888; height:100vh; display:flex; align-items:center; justify-content:center; font-family:sans-serif; flex-direction:column;'>
                <h2 style='color:#00ff88;'>NO PURCHASE FOUND</h2>
                <p>Invoice '$invoice_num' is invalid or represents an examination only.</p>
                <a href='customer.php' style='color:#00ff88; text-decoration:none; border:1px solid #00ff88; padding:10px 20px; border-radius:10px;'>Back to List</a>
            </div>
        ");
    }

    // Query customer_examinations using 'invoice_number' column
    $query = "SELECT ce.*, 
            pm.od_sph AS mod_r_sph, pm.od_cyl AS mod_r_cyl, pm.od_axis AS mod_r_ax, pm.od_add AS mod_r_add,
            pm.os_sph AS mod_l_sph, pm.os_cyl AS mod_l_cyl, pm.os_axis AS mod_l_ax, pm.os_add AS mod_l_add
            FROM customer_examinations ce
            LEFT JOIN prescription_modifications pm ON ce.invoice_number = pm.invoice_number
            WHERE ce.invoice_number = '$invoice_num' 
            LIMIT 1";

    $result = mysqli_query($conn, $query);
    $data = mysqli_fetch_assoc($result);

    if (!$data) {
        die("
            <div style='background:#1a1c1d; color:#ff4d4d; height:100vh; display:flex; align-items:center; justify-content:center; font-family:sans-serif;'>
                Invoice data for <b>$invoice_num</b> was not found in the database.
            </div>
        ");
    }
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
        <title>Invoice - <?php echo $data['examination_code']; ?></title>
        <link rel="stylesheet" href="style.css">
        <style  id="mediapipe-styles">
            .mp-wrapper {
                position: relative;
                width: 300px;
                height: 400px;
                margin: 0 auto;
                border-radius: 20px;
                overflow: hidden;
                background: #000;
                -webkit-mask-image: -webkit-radial-gradient(white, black);
            }

            #mp-video {
                width: 100%;
                height: 100%;
                object-fit: cover;
                transform: scaleX(-1);
            }

            #mp-canvas {
                position: absolute;
                top: 0; left: 0;
                width: 100%;
                height: 100%;
                transform: scaleX(-1);
                pointer-events: none;
            }

            .mp-guide {
                position: absolute;
                top: 0; left: 0;
                width: 100%;
                height: 100%;
                z-index: 20;
                pointer-events: none;
                background: rgba(0,0,0,0.35);
                backdrop-filter: blur(6px);
                -webkit-backdrop-filter: blur(6px);
                -webkit-mask-image: radial-gradient(ellipse 30% 45% at 50% 50%, transparent 95%, black 100%);
                mask-image: radial-gradient(ellipse 30% 45% at 50% 50%, transparent 95%, black 100%);
                transition: opacity 0.4s;
            }

            .mp-guide::after {
                content: "";
                position: absolute;
                top: 50%; left: 50%;
                transform: translate(-50%, -50%);
                width: 60%;
                height: 90%;
                border: 2px solid #00ff88;
                border-radius: 50% 50% 50% 50% / 45% 45% 55% 55%;
                box-shadow: 0 0 15px rgba(0,255,136,0.5), inset 0 0 10px rgba(0,255,136,0.2);
                transition: border-color 0.3s, box-shadow 0.3s;
            }

            .mp-guide.locked::after {
                border-color: #00cfff;
                box-shadow: 0 0 20px rgba(0,207,255,0.6), inset 0 0 12px rgba(0,207,255,0.2);
            }

            /* Confidence bar */
            .conf-bar-wrap {
                width: 100%;
                height: 6px;
                background: rgba(255,255,255,0.1);
                border-radius: 3px;
                margin-top: 8px;
                overflow: hidden;
            }
            .conf-bar-fill {
                height: 100%;
                border-radius: 3px;
                background: linear-gradient(90deg, #00ff88, #00cfff);
                transition: width 0.4s ease;
            }

            #mp-result {
                min-height: 90px;
                transition: all 0.3s ease;
                border: 1px solid rgba(0,255,136,0.2);
                margin-top: 15px !important;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 15px;
            }

            .shape-badge {
                font-size: 1.4rem;
                font-weight: 800;
                letter-spacing: 2px;
                color: #00ff88;
                text-shadow: 0 0 12px rgba(0,255,136,0.5);
            }

            .metrics-row {
                display: flex;
                gap: 10px;
                margin-top: 8px;
                flex-wrap: wrap;
                justify-content: center;
            }
            .metric-chip {
                font-size: 10px;
                color: #888;
                background: rgba(255,255,255,0.05);
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 20px;
                padding: 3px 8px;
            }

            /* Loading state */
            .mp-loading {
                display: flex;
                align-items: center;
                gap: 8px;
                color: var(--text-muted);
                font-size: 0.75rem;
            }
            .spinner {
                width: 14px; height: 14px;
                border: 2px solid rgba(0,255,136,0.2);
                border-top-color: #00ff88;
                border-radius: 50%;
                animation: spin 0.8s linear infinite;
            }
            @keyframes spin { to { transform: rotate(360deg); } }

            @media (max-width: 600px) {
                .mp-wrapper { width: 100%; height: 350px; }
            }
            .invoice-body { padding: 20px; max-width: 800px; margin: auto; }
            .neumorph-card {
                background: var(--bg-color);
                padding: 30px;
                border-radius: 25px;
                box-shadow: 20px 20px 60px var(--shadow-dark), -20px -20px 60px var(--shadow-light);
            }
            .info-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
                margin-top: 20px;
            }
            .read-only-box {
                background: var(--bg-color);
                padding: 12px 15px;
                border-radius: 12px;
                color: var(--accent-color);
                box-shadow: inset 4px 4px 8px var(--shadow-dark), inset -4px -4px 8px var(--shadow-light);
                min-height: 45px;
                display: flex;
                align-items: center;
                font-weight: 600;
            }
            label { color: var(--text-muted); font-size: 0.8rem; margin-left: 5px; margin-bottom: 5px; display: block; }
            .full { grid-column: span 2; }

            /* Table Neumorphic Style */
            .prescription-container {
                background: var(--bg-color);
                padding: 30px;
                border-radius: 25px;
                box-shadow: 8px 8px 16px var(--shadow-dark), -8px -8px 16px var(--shadow-light);
                margin-top: 20px;
                border: 1px solid rgba(255,255,255,0.05);
            }

            .selection-wrapper {
                display: flex;
                gap: 15px;
                justify-content: center; /* Tombol berada di tengah container */
            }

            .prescription-table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 15px 10px; /* Provides spacing between cells */
            }

            .prescription-table th {
                color: var(--text-muted);
                font-size: 0.7rem;
                letter-spacing: 1px;
                text-transform: uppercase;
                padding-bottom: 10px;
            }

            .prescription-table td {
                padding: 0;
            }

            .input-table-neu {
                width: 100%;
                background: var(--bg-color);
                border: none;
                padding: 15px 5px;
                border-radius: 15px;
                color: var(--text-main);
                text-align: center;
                font-weight: 700;
                font-size: 1rem;
                /* Characteristic Neumorphic inset (concave) effect */
                box-shadow: inset 6px 6px 12px var(--shadow-dark), 
                            inset -6px -6px 12px var(--shadow-light);
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .input-table-neu:not([readonly]) {
                color: #00ff88; /* Neon green for contrast during editing */
                text-shadow: 0 0 8px rgba(0, 255, 136, 0.3);
            }

            .input-table-neu:focus {
                outline: none;
                color: var(--accent-color);
            }

            .eye-indicator {
                width: 45px;
                height: 45px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 50%;
                background: var(--bg-color);
                font-weight: 800;
                color: var(--accent-color);
                box-shadow: 4px 4px 8px var(--shadow-dark), -4px -4px 8px var(--shadow-light);
                border: 1px solid rgba(255,255,255,0.05);
            }

            .eye-label {
                vertical-align: middle;
            }

            /* Scan Line Animation */
            .scan-line {
                position: absolute;
                width: 100%;
                height: 4px;
                background: rgba(0, 255, 136, 0.5);
                box-shadow: 0 0 15px #00ff88;
                top: 0;
                left: 0;
                z-index: 10;
                display: none;
                animation: scanMove 2s linear infinite;
            }

            @keyframes scanMove {
                0% { top: 0; }
                100% { top: 100%; }
            }

            .video-wrapper {
                position: relative;
                width: 300px; /* Standard mobile size to prevent overload */
                height: 400px; /* Portrait ratio is better for face tracking on mobile */
                margin: 0 auto;
                overflow: hidden;
                border-radius: 20px;
                background: #000;
                -webkit-mask-image: -webkit-radial-gradient(white, black); /* Fix for rounded corner bugs in Safari/iOS */
            }

            /* Video container must fill the wrapper */
            #video-container {
                width: 100%;
                height: 100%;
                position: absolute;
                top: 0;
                left: 0;
            }

            /* Face Guide mobile fix: Centered and smaller oval adjustment */
            .face-guide {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 20;
                pointer-events: none;
                background: rgba(0, 0, 0, 0.4);
                backdrop-filter: blur(8px);
                -webkit-backdrop-filter: blur(8px);
                
                /* Center cutout reduced to 30% width and 45% height */
                -webkit-mask-image: radial-gradient(ellipse 30% 45% at 50% 50%, transparent 95%, black 100%);
                mask-image: radial-gradient(ellipse 30% 45% at 50% 50%, transparent 95%, black 100%);
            }

            /* Green Outline: Matching the mask size above */
            .face-guide::after {
                content: "";
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                /* Size matched to mask dimensions (50% x 65%) */
                width: 60%; 
                height: 90%;
                border: 2px solid #00ff88;
                border-radius: 50% 50% 50% 50% / 45% 45% 55% 55%;
                box-shadow: 0 0 15px rgba(0, 255, 136, 0.5), inset 0 0 10px rgba(0, 255, 136, 0.2);
            }
            
            /* Small instruction message above the video */
            .scan-instruction {
                font-size: 11px;
                color: var(--text-muted);
                margin-bottom: 8px;
                text-transform: uppercase;
            }

            #face-result {
                min-height: 80px;
                transition: all 0.3s ease;
                border: 1px solid rgba(0, 255, 136, 0.2);
                margin-top: 20px !important;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
            }

            #overlay {
                max-width: 100%;
                height: auto;
                border-radius: 20px;
                box-shadow: 10px 10px 20px var(--shadow-dark);
            }

            #video {
                width: 100%;
                height: 100%;
                object-fit: cover; /* Ensures video fills the container without distortion */
                transform: scaleX(-1); /* Mirroring */
            }

            /* --- Global Adjustments for Mobile --- */
            @media (max-width: 600px) {
                .invoice-body {
                    padding: 10px;
                }

                /* Change grid to single column on mobile */
                .info-grid {
                    grid-template-columns: 1fr; 
                    gap: 15px;
                }

                .info-grid div.full {
                    grid-column: span 1;
                }

                /* Reduce card padding for more screen space */
                .neumorph-card, .main-card, .prescription-container {
                    padding: 15px;
                    border-radius: 15px;
                }

                /* Prescription Table Adjustments (Critical) */
                .prescription-table {
                    border-spacing: 5px 8px;
                }

                .prescription-table th {
                    font-size: 0.6rem;
                }

                .input-table-neu {
                    padding: 10px 2px;
                    font-size: 0.85rem;
                    border-radius: 10px;
                }

                .eye-indicator {
                    width: 35px;
                    height: 35px;
                    font-size: 0.9rem;
                }

                /* Video Scanner Adjustments */
                .video-wrapper {
                    width: 100%;      /* Follows the mobile screen width */
                    height: 380px;    /* Sufficient height for face positioning */
                    position: relative;
                }

                #video, #overlay {
                    width: 100% !important;
                    height: auto !important;
                }

                .face-guide {
                    -webkit-mask-image: radial-gradient(ellipse 30% 45% at 50% 50%, transparent 95%, black 100%);
                    mask-image: radial-gradient(ellipse 30% 45% at 50% 50%, transparent 95%, black 100%);
                }

                .face-guide::after {
                    width: 60%;
                    height: 90%;
                    border: 2px solid #00ff88;
                    border-radius: 50%;
                }

                /* Optimize buttons for touch targets */
                .neu-btn {
                    padding: 12px 10px;
                    font-size: 0.8rem;
                    flex: 1; /* Buttons share space equally */
                }

                .selection-wrapper {
                    flex-wrap: nowrap; /* Keep aligned horizontally */
                }

                .btn-action {
                    width: 100%;
                    padding: 15px;
                }
            }

            /* Enable horizontal scroll for very small screens (e.g., iPhone SE) */
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin-top: 10px;
            }

            @keyframes pulse {
                0% { opacity: 0.6; }
                50% { opacity: 1; }
                100% { opacity: 0.6; }
            }
        </style>
    </head>

    <body style="background: var(--bg-color);">
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
                    <h2>INVOICE</h2>
            
                    <div class="info-grid">
                        <div>
                            <label>EXAMINATION CODE</label>
                            <div class="read-only-box"><?php echo $data['examination_code']; ?></div>
                        </div>

                        <div>
                            <label>DATE</label>
                            <div class="read-only-box"><?php echo date('d/m/Y', strtotime($data['examination_date'])); ?></div>
                        </div>

                        <div class="full">
                            <label>CUSTOMER NAME</label>
                            <div class="read-only-box"><?php echo strtoupper($data['customer_name']); ?></div>

                        </div>

                        <div>
                            <label>AGE</label>
                            <div class="read-only-box"><?php echo $data['age']; ?> YEARS</div>

                        </div>

                        <div>
                            <label>GENDER</label>
                            <div class="read-only-box"><?php echo $data['gender']; ?></div>

                        </div>

                        <div class="full">
                            <label>SYMPTOMS</label>
                            <div class="read-only-box" style="height: auto;"><?php echo $data['symptoms']; ?></div>

                        </div>

                        <div class="full">
                            <label>EXAM NOTES</label>
                            <div class="read-only-box" style="height: auto; min-height: 80px;"><?php echo $data['exam_notes'] ?: '-'; ?></div>
                        </div>

                        <div class="full">
                            <div class="prescription-container">
                                <label>PRESCRIPTION MODIFICATION</label>

                                <div class="selection-wrapper">
                                    <button type="button" class="neu-btn active" id="mod-no">
                                        <div class="led"></div> NO
                                    </button>
                                    <button type="button" class="neu-btn" id="mod-yes">
                                        <div class="led"></div> YES (MODIFY)
                                    </button>
                                </div>

                                <form method="POST" class="full">
                                    <input type="hidden" name="invoice_number" value="<?php echo $data['invoice_number']; ?>">
                                    
                                    <div class="prescription-container">
                                        <h3 style="color: var(--accent-color); font-size: 0.85rem; margin-bottom: 20px; text-align: center; opacity: 0.8;">
                                            — MEASUREMENT —
                                        </h3>
                                        <div class="table-responsive">
                                            <table class="prescription-table">
                                                <thead>
                                                    <tr>
                                                        <th>SIDE</th>
                                                        <th>SPH</th>
                                                        <th>CYL</th>
                                                        <th>AXIS</th>
                                                        <th>ADD</th>
                                                    </tr>
                                                </thead>
            
                                                <tbody>
                                                    <tr>
                                                        <td class="eye-label"><div class="eye-indicator">R</div></td>
                                                        <td><input type="text" name="od_sph" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_r_sph']; ?>" 
                                                            data-modified="<?php echo $data['mod_r_sph'] ?? $data['new_r_sph']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_r_sph'] : $data['new_r_sph']; ?>" readonly></td>
                                                        
                                                        <td><input type="text" name="od_cyl" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_r_cyl']; ?>" 
                                                            data-modified="<?php echo $data['mod_r_cyl'] ?? $data['new_r_cyl']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_r_cyl'] : $data['new_r_cyl']; ?>" readonly></td>
                                                        
                                                        <td><input type="text" name="od_axis" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_r_ax']; ?>" 
                                                            data-modified="<?php echo $data['mod_r_ax'] ?? $data['new_r_ax']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_r_ax'] : $data['new_r_ax']; ?>" readonly></td>
                                                        
                                                        <td><input type="text" name="od_add" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_r_add']; ?>" 
                                                            data-modified="<?php echo $data['mod_r_add'] ?? $data['new_r_add']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_r_add'] : $data['new_r_add']; ?>" readonly></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="eye-label"><div class="eye-indicator">L</div></td>
                                                        <td><input type="text" name="os_sph" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_l_sph']; ?>" 
                                                            data-modified="<?php echo $data['mod_l_sph'] ?? $data['new_l_sph']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_l_sph'] : $data['new_l_sph']; ?>" readonly></td>
                                                        <td><input type="text" name="os_cyl" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_l_cyl']; ?>" 
                                                            data-modified="<?php echo $data['mod_l_cyl'] ?? $data['new_l_cyl']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_l_cyl'] : $data['new_l_cyl']; ?>" readonly></td>
                                                        <td><input type="text" name="os_axis" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_l_ax']; ?>" 
                                                            data-modified="<?php echo $data['mod_l_ax'] ?? $data['new_l_ax']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_l_ax'] : $data['new_l_ax']; ?>" readonly></td>
                                                        <td><input type="text" name="os_add" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_l_add']; ?>" 
                                                            data-modified="<?php echo $data['mod_l_add'] ?? $data['new_l_add']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_l_add'] : $data['new_l_add']; ?>" readonly></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
        
                                    <div id="save-btn-container" style="display: none; margin-top: 30px; text-align: center;">
                                        <button type="submit" name="save_modification" class="btn-action" style="width: 100%; max-width: 400px; border-radius: 50px;">
                                            CONFIRM & SAVE MODIFICATION
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="full">
                            <div class="prescription-container" style="text-align: center;">
                                <label>FACE SHAPE ANALYSIS</label>
                                <p class="scan-instruction">Posisikan wajah di dalam garis hijau — deteksi otomatis</p>

                                <div class="mp-wrapper">
                                    <video id="mp-video" autoplay muted playsinline></video>
                                    <canvas id="mp-canvas"></canvas>
                                    <div class="mp-guide" id="mp-guide"></div>
                                </div>

                                <div id="mp-result" class="read-only-box" style="color: #00ff88;">
                                    READY TO SCAN...
                                </div>

                                <div class="selection-wrapper" style="margin-top: 15px;">
                                    <button type="button" class="neu-btn" id="mp-start-btn">
                                        <div class="led"></div> START CAMERA
                                    </button>
                                    <button type="button" class="neu-btn" id="mp-switch-btn" style="display:none;">
                                        <div class="led"></div> SWITCH CAMERA
                                    </button>
                                    <button type="button" class="neu-btn" id="mp-freeze-btn" style="display:none;">
                                        <div class="led"></div> FREEZE &amp; LOCK
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 40px; text-align: center;">
                        <button onclick="window.print()" class="btn-action">PRINT INVOICE</button>
                    </div>
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
            const modYes = document.getElementById('mod-yes');
            const modNo = document.getElementById('mod-no');
            const fields = document.querySelectorAll('.mod-field');
            const saveContainer = document.getElementById('save-btn-container');
            // faceapi removed — using MediaPipe now

            const formatZeroValue = (e) => {
                let val = e.target.value.trim();
                if (val === "0" || val === "00") {
                    e.target.value = "0.00";
                }
            };

            modYes.onclick = () => {
                modYes.classList.add('active');
                modNo.classList.remove('active');
                saveContainer.style.display = 'block';
                
                fields.forEach(f => {
                    // Retrieve value from data-modified attribute
                    f.value = f.getAttribute('data-modified');
                    f.readOnly = false;
                    f.style.color = "#00ff88"; // Neon Green
                    f.style.boxShadow = "inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light)";
                    f.addEventListener('focus', () => f.select());
                    f.addEventListener('blur', formatZeroValue);
                });
            };

            modNo.onclick = () => {
                modNo.classList.add('active');
                modYes.classList.remove('active');
                saveContainer.style.display = 'none';
                
                fields.forEach(f => {
                    // Revert to original value from customer_examinations database
                    f.value = f.getAttribute('data-original');
                    f.readOnly = true;
                    f.style.color = "var(--text-main)";
                    f.style.boxShadow = "inset 5px 5px 10px var(--shadow-dark), inset -5px -5px 10px var(--shadow-light)";
                    f.removeEventListener('blur', formatZeroValue);
                });
            };

            window.onload = () => {
                const isModified = <?php echo $data['lens_modification'] == 1 ? 'true' : 'false'; ?>;
                if (isModified) {
                    // Trigger UI as if 'Yes' was clicked
                    modYes.classList.add('active');
                    modNo.classList.remove('active');
                    
                    fields.forEach(f => {
                        f.style.color = "#00ff88"; // Highlights modified data
                        if(f.value === "0") f.value = "0.00";
                        f.readOnly = false; 
                        f.addEventListener('focus', () => f.select());
                    });
                }
            };

            
            (function() {

                // === Indeks landmark penting di MediaPipe Face Mesh ===
                // Referensi: https://github.com/google/mediapipe/blob/master/mediapipe/modules/face_geometry/data/canonical_face_model_uv_visualization.png
                const LM = {
                    // Kontur rahang (oval luar wajah)
                    JAW_LEFT:      234,   // ujung kiri rahang
                    JAW_RIGHT:     454,   // ujung kanan rahang
                    JAW_L1:        172,   // rahang kiri dalam
                    JAW_R1:        397,   // rahang kanan dalam
                    JAW_L2:        136,   // sudut rahang kiri
                    JAW_R2:        365,   // sudut rahang kanan
                    CHIN:          152,   // ujung dagu terbawah
                    CHIN_L:        176,   // dagu kiri
                    CHIN_R:        400,   // dagu kanan

                    // Dahi & pelipis
                    TEMPLE_L:      162,   // pelipis kiri
                    TEMPLE_R:      389,   // pelipis kanan
                    FOREHEAD_TOP:  10,    // ubun-ubun (estimasi dahi atas)

                    // Tulang pipi
                    CHEEK_L:       123,   // tulang pipi kiri
                    CHEEK_R:       352,   // tulang pipi kanan
                    CHEEK_L2:      116,
                    CHEEK_R2:      345,

                    // Alis (untuk estimasi tinggi dahi)
                    BROW_L:        70,
                    BROW_R:        300,
                    BROW_L_INNER:  55,
                    BROW_R_INNER:  285,

                    // Mata
                    EYE_L_OUTER:   33,
                    EYE_R_OUTER:   263,

                    // Hidung
                    NOSE_TIP:      4,

                    // Mulut (untuk proporsi bawah wajah)
                    MOUTH_L:       61,
                    MOUTH_R:       291,

                    // Titik tengah wajah
                    FACE_CENTER:   168,
                };

                const video     = document.getElementById('mp-video');
                const canvas    = document.getElementById('mp-canvas');
                const guide     = document.getElementById('mp-guide');
                const resultBox = document.getElementById('mp-result');
                const startBtn  = document.getElementById('mp-start-btn');
                const switchBtn = document.getElementById('mp-switch-btn');
                const freezeBtn = document.getElementById('mp-freeze-btn');
                const ctx       = canvas.getContext('2d');

                let faceMesh     = null;
                let camera       = null;
                let facingMode   = 'user';
                let isRunning    = false;
                let isFrozen     = false;
                let lastShape    = null;
                let stableCount  = 0;          // jumlah frame stabil berturut-turut
                let frameBuffer  = [];         // buffer beberapa frame untuk smoothing
                const STABLE_THRESHOLD = 8;    // frame stabil sebelum auto-lock
                const BUFFER_SIZE = 5;

                // ----------------------------------------------------------------
                // LOAD MediaPipe — Sequential (wajib berurutan untuk mobile)
                // ----------------------------------------------------------------
                function loadMediaPipe() {
                    resultBox.innerHTML = `<div class="mp-loading"><div class="spinner"></div> MEMUAT MEDIAPIPE...</div>`;

                    const scripts = [
                        'https://cdn.jsdelivr.net/npm/@mediapipe/camera_utils/camera_utils.js',
                        'https://cdn.jsdelivr.net/npm/@mediapipe/drawing_utils/drawing_utils.js',
                        'https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/face_mesh.js'
                    ];

                    function loadNext(index) {
                        if (index >= scripts.length) { initFaceMesh(); return; }
                        const s = document.createElement('script');
                        s.src = scripts[index];
                        s.onload = () => loadNext(index + 1);
                        s.onerror = () => {
                            resultBox.innerHTML = `<b style="color:#ff4d4d">GAGAL MEMUAT LIBRARY (${index+1}/3). Periksa koneksi.</b>`;
                            startBtn.disabled  = false;
                            startBtn.innerHTML = '<div class="led"></div> COBA LAGI';
                        };
                        document.head.appendChild(s);
                    }
                    loadNext(0);
                }

                // ----------------------------------------------------------------
                // INIT FACE MESH
                // ----------------------------------------------------------------
                function initFaceMesh() {
                    resultBox.innerHTML = `<div class="mp-loading"><div class="spinner"></div> INISIALISASI MODEL 3D...</div>`;

                    faceMesh = new FaceMesh({
                        locateFile: (file) => `https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/${file}`
                    });

                    faceMesh.setOptions({
                        maxNumFaces: 1,
                        refineLandmarks: true,       // aktifkan iris + mesh detail
                        minDetectionConfidence: 0.6,
                        minTrackingConfidence: 0.6
                    });

                    faceMesh.onResults(onResults);

                    startCamera();
                }

                // ----------------------------------------------------------------
                // START KAMERA — Pakai RAF manual, bukan new Camera() (iOS fix)
                // ----------------------------------------------------------------
                let rafId = null;

                function startCamera() {
                    // Stop RAF loop lama jika ada
                    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }

                    // Stop stream lama
                    if (video.srcObject) {
                        video.srcObject.getTracks().forEach(t => t.stop());
                        video.srcObject = null;
                    }

                    // Constraints ramah mobile
                    const constraints = {
                        video: {
                            facingMode: { ideal: facingMode },
                            width:  { ideal: 640, max: 1280 },
                            height: { ideal: 480, max: 720 }
                        },
                        audio: false
                    };

                    navigator.mediaDevices.getUserMedia(constraints)
                    .then(stream => {
                        video.srcObject = stream;

                        // Untuk iOS Safari: harus ada interaksi user sebelum play
                        video.setAttribute('playsinline', true);
                        video.setAttribute('muted', true);
                        video.muted = true;

                        const playPromise = video.play();
                        if (playPromise !== undefined) {
                            playPromise.catch(() => { video.play(); });
                        }

                        video.onloadedmetadata = () => {
                            canvas.width  = video.videoWidth  || 640;
                            canvas.height = video.videoHeight || 480;

                            isRunning = true;
                            startBtn.innerHTML      = '<div class="led"></div> CAMERA AKTIF';
                            switchBtn.style.display = 'inline-block';
                            freezeBtn.style.display = 'inline-block';
                            resultBox.innerHTML     = `<span style="color:var(--text-muted);font-size:0.75rem">Posisikan wajah Anda...</span>`;

                            // Mulai loop RAF — lebih kompatibel dengan iOS vs new Camera()
                            let processing = false;
                            async function rafLoop() {
                                if (!isFrozen && !processing && video.readyState >= 2) {
                                    processing = true;
                                    try {
                                        await faceMesh.send({ image: video });
                                    } catch(e) { /* abaikan error frame individual */ }
                                    processing = false;
                                }
                                rafId = requestAnimationFrame(rafLoop);
                            }
                            rafLoop();
                        };

                    }).catch(err => {
                        let msg = err.message;
                        if (err.name === 'NotAllowedError')  msg = 'Izin kamera ditolak. Buka Pengaturan → izinkan kamera untuk browser ini.';
                        if (err.name === 'NotFoundError')    msg = 'Kamera tidak ditemukan di perangkat ini.';
                        if (err.name === 'NotReadableError') msg = 'Kamera sedang digunakan aplikasi lain.';
                        resultBox.innerHTML = `<b style="color:#ff4d4d">ERROR: ${msg}</b>`;
                        startBtn.disabled  = false;
                        startBtn.innerHTML = '<div class="led"></div> COBA LAGI';
                    });
                }

                // ----------------------------------------------------------------
                // CALLBACK HASIL DETEKSI
                // ----------------------------------------------------------------
                function onResults(results) {
                    if (canvas.width !== video.videoWidth) {
                        canvas.width  = video.videoWidth;
                        canvas.height = video.videoHeight;
                    }

                    ctx.clearRect(0, 0, canvas.width, canvas.height);

                    if (!results.multiFaceLandmarks || results.multiFaceLandmarks.length === 0) {
                        stableCount = 0;
                        lastShape   = null;
                        guide.classList.remove('locked');
                        resultBox.innerHTML = `<span style="color:var(--text-muted);font-size:0.75rem">Wajah tidak terdeteksi...</span>`;
                        return;
                    }

                    const landmarks = results.multiFaceLandmarks[0];

                    // Gambar mesh tipis sebagai panduan
                    drawMesh(landmarks);

                    // Analisis bentuk wajah
                    const analysis = analyzeFaceShape(landmarks);

                    // Smoothing: masukkan ke buffer
                    frameBuffer.push(analysis.shape);
                    if (frameBuffer.length > BUFFER_SIZE) frameBuffer.shift();

                    // Ambil bentuk yang paling sering muncul di buffer
                    const smoothedShape = mostFrequent(frameBuffer);

                    // Hitung stabilitas
                    if (smoothedShape === lastShape) {
                        stableCount++;
                    } else {
                        stableCount = 0;
                        lastShape   = smoothedShape;
                    }

                    // Tampilkan hasil
                    displayResult(smoothedShape, analysis, stableCount);

                    // Auto-lock setelah cukup stabil
                    if (stableCount >= STABLE_THRESHOLD && !isFrozen) {
                        freezeResult(smoothedShape, analysis);
                    }
                }

                // ----------------------------------------------------------------
                // GAMBAR MESH WAJAH
                // ----------------------------------------------------------------
                function drawMesh(lm) {
                    const W = canvas.width;
                    const H = canvas.height;

                    ctx.save();
                    // Mirror canvas
                    ctx.translate(W, 0);
                    ctx.scale(-1, 1);

                    // Gambar titik-titik penting saja (tidak semua 468 — terlalu ramai)
                    const keyPoints = [
                        LM.JAW_LEFT, LM.JAW_RIGHT, LM.JAW_L1, LM.JAW_R1,
                        LM.JAW_L2, LM.JAW_R2, LM.CHIN,
                        LM.TEMPLE_L, LM.TEMPLE_R,
                        LM.CHEEK_L, LM.CHEEK_R,
                        LM.BROW_L, LM.BROW_R,
                        LM.CHIN_L, LM.CHIN_R
                    ];

                    keyPoints.forEach(idx => {
                        const p = lm[idx];
                        ctx.beginPath();
                        ctx.arc(p.x * W, p.y * H, 3, 0, 2 * Math.PI);
                        ctx.fillStyle = 'rgba(0, 255, 136, 0.7)';
                        ctx.fill();
                    });

                    // Gambar garis kontur rahang
                    const jawContour = [162, 21, 54, 103, 67, 109, 10, 338, 297, 332, 284,
                                        251, 389, 454, 323, 361, 288, 397, 365, 379, 378,
                                        400, 377, 152, 148, 176, 149, 150, 136, 172, 58,
                                        132, 93, 234, 127, 162];

                    ctx.beginPath();
                    jawContour.forEach((idx, i) => {
                        const p = lm[idx];
                        if (i === 0) ctx.moveTo(p.x * W, p.y * H);
                        else         ctx.lineTo(p.x * W, p.y * H);
                    });
                    ctx.strokeStyle = 'rgba(0, 255, 136, 0.35)';
                    ctx.lineWidth   = 1.5;
                    ctx.stroke();

                    ctx.restore();
                }

                // ----------------------------------------------------------------
                // ANALISIS BENTUK WAJAH (Inti algoritma)
                // ----------------------------------------------------------------
                function analyzeFaceShape(lm) {
                    const W = canvas.width;
                    const H = canvas.height;

                    // Helper: jarak 3D (memanfaatkan koordinat Z dari MediaPipe)
                    function dist3D(a, b) {
                        const dx = (a.x - b.x) * W;
                        const dy = (a.y - b.y) * H;
                        const dz = (a.z - b.z) * W;   // Z dinormalisasi relatif terhadap lebar wajah
                        return Math.sqrt(dx*dx + dy*dy + dz*dz);
                    }

                    function dist2D(a, b) {
                        return Math.hypot((a.x - b.x) * W, (a.y - b.y) * H);
                    }

                    const p = lm;

                    // === PENGUKURAN UTAMA ===

                    // Lebar wajah maksimum (cheekbone area — titik terlebar di wajah)
                    const faceWidth = dist3D(p[LM.JAW_LEFT], p[LM.JAW_RIGHT]);

                    // Tinggi wajah: dari puncak dahi (estimasi) ke dagu
                    // Titik 10 = puncak kepala di mesh, tapi kita estimasi dari alis atas
                    const browTop    = Math.min(p[LM.BROW_L].y, p[LM.BROW_R].y);
                    const foreheadEst = browTop - (p[LM.CHIN].y - browTop) * 0.15;
                    const faceHeight = Math.abs(p[LM.CHIN].y - foreheadEst) * H;

                    // Lebar dahi: jarak antara kedua pelipis
                    const foreheadWidth = dist3D(p[LM.TEMPLE_L], p[LM.TEMPLE_R]);

                    // Lebar tulang pipi: titik terlebar di area pipi
                    const cheekWidth = dist3D(p[LM.CHEEK_L], p[LM.CHEEK_R]);

                    // Lebar rahang: titik sudut rahang
                    const jawWidth = dist3D(p[LM.JAW_L2], p[LM.JAW_R2]);

                    // Lebar dagu: titik bawah rahang kiri-kanan
                    const chinWidth = dist3D(p[LM.CHIN_L], p[LM.CHIN_R]);

                    // Ketajaman dagu (sudut 3D)
                    function angle3D(center, a, b) {
                        const v1 = { x: (a.x-center.x)*W, y: (a.y-center.y)*H, z: (a.z-center.z)*W };
                        const v2 = { x: (b.x-center.x)*W, y: (b.y-center.y)*H, z: (b.z-center.z)*W };
                        const dot = v1.x*v2.x + v1.y*v2.y + v1.z*v2.z;
                        const mag1 = Math.sqrt(v1.x**2 + v1.y**2 + v1.z**2);
                        const mag2 = Math.sqrt(v2.x**2 + v2.y**2 + v2.z**2);
                        if (mag1 === 0 || mag2 === 0) return 90;
                        return Math.acos(Math.max(-1, Math.min(1, dot/(mag1*mag2)))) * 180/Math.PI;
                    }

                    const chinAngle = angle3D(p[LM.CHIN], p[LM.CHIN_L], p[LM.CHIN_R]);

                    // === RASIO ===
                    const faceRatio     = faceHeight / faceWidth;           // tinggi/lebar keseluruhan
                    const foreheadRatio = foreheadWidth / faceWidth;        // dahi vs wajah
                    const cheekRatio    = cheekWidth / faceWidth;           // pipi vs wajah
                    const jawRatio      = jawWidth / faceWidth;             // rahang vs wajah
                    const chinRatio     = chinWidth / jawWidth;             // dagu vs rahang (lancip/kotak)
                    const jawForeRatio  = jawWidth / foreheadWidth;         // rahang vs dahi

                    // === SCORING SISTEM ===
                    // Setiap fungsi mengembalikan nilai 0-10
                    const scores = {
                        "OVAL":     calcOval(faceRatio, foreheadRatio, cheekRatio, jawRatio, chinAngle),
                        "ROUND":    calcRound(faceRatio, cheekRatio, jawRatio, chinAngle),
                        "SQUARE":   calcSquare(faceRatio, jawRatio, chinAngle),
                        "OBLONG":   calcOblong(faceRatio, foreheadRatio, jawRatio, chinRatio),
                        "HEART":    calcHeart(foreheadRatio, jawRatio, chinAngle, jawForeRatio),
                        "DIAMOND":  calcDiamond(foreheadRatio, cheekRatio, jawRatio, chinAngle),
                        "TRIANGLE": calcTriangle(foreheadRatio, jawRatio, jawForeRatio)
                    };

                    // Normalisasi scores menjadi persentase
                    const totalScore = Object.values(scores).reduce((a, b) => a + b, 0);
                    const percentages = {};
                    for (const [k, v] of Object.entries(scores)) {
                        percentages[k] = totalScore > 0 ? Math.round((v / totalScore) * 100) : 0;
                    }

                    // Pilih yang tertinggi
                    const shape = Object.entries(scores).reduce((a, b) => b[1] > a[1] ? b : a)[0];
                    const confidence = percentages[shape];

                    return {
                        shape,
                        confidence,
                        scores,
                        percentages,
                        metrics: {
                            faceRatio:     +faceRatio.toFixed(3),
                            foreheadRatio: +foreheadRatio.toFixed(3),
                            cheekRatio:    +cheekRatio.toFixed(3),
                            jawRatio:      +jawRatio.toFixed(3),
                            chinRatio:     +chinRatio.toFixed(3),
                            chinAngle:     +chinAngle.toFixed(1)
                        }
                    };
                }

                // ----------------------------------------------------------------
                // FUNGSI SCORING TIAP BENTUK
                // Bobot didesain berdasarkan referensi ciri khas tiap bentuk wajah
                // ----------------------------------------------------------------

                function calcOval(fr, fore, cheek, jaw, chinA) {
                    let s = 0;
                    // Oval: wajah lebih panjang dari lebar, pipi sedikit lebih lebar dari dahi & rahang
                    if (fr >= 1.3 && fr <= 1.7)         s += 3.0;
                    if (cheek > jaw)                     s += 2.0;
                    if (cheek > fore)                    s += 1.5;
                    if (fore > jaw)                      s += 1.0;    // dahi sedikit lebih lebar dari rahang
                    if (jaw >= 0.60 && jaw <= 0.80)      s += 1.5;
                    if (chinA >= 70 && chinA <= 120)     s += 1.0;
                    return s;
                }

                function calcRound(fr, cheek, jaw, chinA) {
                    let s = 0;
                    // Round: wajah hampir sama lebar & tinggi, semua bagian membulat
                    if (fr < 1.25)                       s += 4.0;
                    if (cheek >= 0.85)                   s += 2.0;
                    if (jaw >= 0.70)                     s += 1.5;
                    if (chinA >= 120)                    s += 2.5;    // dagu sangat tumpul = bulat
                    return s;
                }

                function calcSquare(fr, jaw, chinA) {
                    let s = 0;
                    // Square: wajah proporsional, rahang tegas & lebar, dagu kotak
                    if (fr >= 0.95 && fr <= 1.3)         s += 2.5;
                    if (jaw >= 0.82)                     s += 4.5;   // rahang lebar = ciri utama
                    if (chinA >= 105)                    s += 3.0;   // dagu kotak
                    return s;
                }

                function calcOblong(fr, fore, jaw, chinR) {
                    let s = 0;
                    // Oblong/Long: wajah sangat panjang, proporsional tapi memanjang ke bawah
                    if (fr >= 1.65)                      s += 5.0;
                    if (fore < 0.82)                     s += 1.5;
                    if (jaw < 0.72)                      s += 2.0;
                    if (chinR < 0.55)                    s += 1.5;   // dagu relatif kecil vs rahang
                    return s;
                }

                function calcHeart(fore, jaw, chinA, jawFore) {
                    let s = 0;
                    // Heart: dahi lebar, rahang sempit, dagu lancip
                    if (fore >= 0.95)                    s += 3.0;
                    if (jaw < 0.65)                      s += 3.0;
                    if (chinA < 80)                      s += 3.0;   // dagu sangat lancip = ciri utama
                    if (jawFore < 0.75)                  s += 1.0;   // rahang jauh lebih sempit dari dahi
                    return s;
                }

                function calcDiamond(fore, cheek, jaw, chinA) {
                    let s = 0;
                    // Diamond: pipi paling lebar, dahi & rahang sempit, dagu agak lancip
                    if (cheek > fore && cheek > jaw)     s += 5.0;   // pipi terlebar = ciri UTAMA
                    if (fore < 0.82)                     s += 1.5;
                    if (jaw < 0.68)                      s += 1.5;
                    if (chinA < 95)                      s += 2.0;
                    return s;
                }

                function calcTriangle(fore, jaw, jawFore) {
                    let s = 0;
                    // Triangle/Pear: rahang jauh lebih lebar dari dahi
                    if (jawFore >= 1.15)                 s += 5.0;   // rahang dominan = ciri UTAMA
                    if (jaw >= 0.85)                     s += 3.0;
                    if (fore < 0.78)                     s += 2.0;
                    return s;
                }

                // ----------------------------------------------------------------
                // TAMPILKAN HASIL
                // ----------------------------------------------------------------
                function displayResult(shape, analysis, stable) {
                    const conf    = analysis.confidence;
                    const m       = analysis.metrics;
                    const progress = Math.min(100, Math.round((stable / STABLE_THRESHOLD) * 100));
                    const isStable = stable >= STABLE_THRESHOLD;

                    const shapeEmoji = {
                        OVAL: '◉', ROUND: '●', SQUARE: '■',
                        OBLONG: '▬', HEART: '♥', DIAMOND: '◆', TRIANGLE: '▼'
                    };

                    resultBox.innerHTML = `
                        <div style="font-size: 0.65rem; color: var(--text-muted); margin-bottom: 4px; letter-spacing: 1px;">
                            LIVE DETECTION ${isStable ? '— <span style="color:#00ff88">LOCKED ✓</span>' : ''}
                        </div>
                        <div class="shape-badge">${shapeEmoji[shape] || ''} ${shape}</div>
                        <div style="font-size: 11px; color: #00cfff; margin-top: 4px;">Confidence: ${conf}%</div>
                        <div class="conf-bar-wrap" style="width: 80%;">
                            <div class="conf-bar-fill" style="width: ${conf}%"></div>
                        </div>
                        <div class="metrics-row">
                            <span class="metric-chip">H/W ${m.faceRatio}</span>
                            <span class="metric-chip">Dahi ${m.foreheadRatio}</span>
                            <span class="metric-chip">Pipi ${m.cheekRatio}</span>
                            <span class="metric-chip">Rahang ${m.jawRatio}</span>
                            <span class="metric-chip">Dagu ${m.chinAngle}°</span>
                        </div>
                        ${!isStable ? `
                        <div style="font-size: 10px; color: #555; margin-top: 8px;">
                            Stabilizing... ${stable}/${STABLE_THRESHOLD}
                        </div>` : ''}
                    `;

                    guide.classList.toggle('locked', isStable);
                }

                // ----------------------------------------------------------------
                // FREEZE & LOCK HASIL
                // ----------------------------------------------------------------
                function freezeResult(shape, analysis) {
                    isFrozen = true;
                    if (camera) camera.stop();

                    const conf = analysis.confidence;
                    const m    = analysis.metrics;

                    // Tampilkan top 3 bentuk
                    const top3 = Object.entries(analysis.percentages)
                        .sort((a, b) => b[1] - a[1])
                        .slice(0, 3);

                    const shapeDescriptions = {
                        OVAL:     'Wajah simetris, lebih panjang dari lebar, garis halus.',
                        ROUND:    'Wajah membulat, lebar & tinggi hampir sama, dagu tumpul.',
                        SQUARE:   'Rahang tegas & lebar, dagu kotak, proporsi seimbang.',
                        OBLONG:   'Wajah panjang & sempit, dahi & rahang sejajar.',
                        HEART:    'Dahi lebar menyempit ke dagu yang lancip.',
                        DIAMOND:  'Tulang pipi menonjol, dahi & rahang lebih sempit.',
                        TRIANGLE: 'Rahang lebih lebar dari dahi, wajah membesar ke bawah.'
                    };

                    resultBox.innerHTML = `
                        <div style="font-size: 0.65rem; color: var(--text-muted); letter-spacing: 1px; margin-bottom: 6px;">
                            HASIL ANALISIS TERKUNCI
                        </div>
                        <div class="shape-badge" style="font-size: 1.6rem;">${shape}</div>
                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px; max-width: 260px; text-align: center;">
                            ${shapeDescriptions[shape] || ''}
                        </div>
                        <div class="conf-bar-wrap" style="width: 80%; margin-bottom: 8px;">
                            <div class="conf-bar-fill" style="width: ${conf}%"></div>
                        </div>
                        <div style="font-size: 10px; color: #666; margin-bottom: 8px;">
                            ${top3.map(([s, p]) => `${s} ${p}%`).join(' · ')}
                        </div>
                        <div class="metrics-row">
                            <span class="metric-chip">H/W ${m.faceRatio}</span>
                            <span class="metric-chip">Dahi ${m.foreheadRatio}</span>
                            <span class="metric-chip">Pipi ${m.cheekRatio}</span>
                            <span class="metric-chip">Rahang ${m.jawRatio}</span>
                            <span class="metric-chip">Dagu ${m.chinAngle}°</span>
                        </div>
                    `;

                    freezeBtn.innerHTML = '<div class="led"></div> ULANGI SCAN';
                    freezeBtn.onclick = resetScan;
                    guide.classList.add('locked');
                }

                // ----------------------------------------------------------------
                // RESET
                // ----------------------------------------------------------------
                function resetScan() {
                    isFrozen    = false;
                    stableCount = 0;
                    lastShape   = null;
                    frameBuffer = [];
                    lastAnalysis = null;
                    guide.classList.remove('locked');
                    freezeBtn.innerHTML = '<div class="led"></div> FREEZE &amp; LOCK';
                    freezeBtn.onclick   = () => { if (lastShape && lastAnalysis) freezeResult(lastShape, lastAnalysis); };
                    resultBox.innerHTML = `<span style="color:var(--text-muted);font-size:0.75rem">Posisikan wajah Anda...</span>`;
                    // Selalu restart kamera (RAF loop) — tidak pakai new Camera() lagi
                    startCamera();
                }

                // ----------------------------------------------------------------
                // UTILITY
                // ----------------------------------------------------------------
                function mostFrequent(arr) {
                    const freq = {};
                    arr.forEach(v => freq[v] = (freq[v] || 0) + 1);
                    return Object.entries(freq).reduce((a, b) => b[1] > a[1] ? b : a)[0];
                }

                // Simpan analisis terakhir untuk keperluan freeze manual
                let lastAnalysis = null;
                const origOnResults = onResults;

                // Override onResults untuk simpan lastAnalysis
                function onResultsWrapper(results) {
                    if (results.multiFaceLandmarks && results.multiFaceLandmarks.length > 0) {
                        lastAnalysis = analyzeFaceShape(results.multiFaceLandmarks[0]);
                    }
                    origOnResults(results);
                }

                // ----------------------------------------------------------------
                // EVENT HANDLERS
                // ----------------------------------------------------------------
                startBtn.onclick = () => {
                    if (!isRunning) {
                        loadMediaPipe();
                        startBtn.innerHTML = '<div class="led"></div> MEMUAT...';
                        startBtn.disabled  = true;
                    }
                };

                switchBtn.onclick = () => {
                    facingMode = (facingMode === 'user') ? 'environment' : 'user';
                    // RAF dibersihkan di dalam startCamera()
                    startCamera();
                };

                freezeBtn.onclick = () => {
                    if (lastAnalysis && lastShape) {
                        freezeResult(lastShape, lastAnalysis);
                    }
                };

                // Override faceMesh.onResults setelah inisialisasi untuk simpan lastAnalysis
                const origInit = initFaceMesh;
                window._mpInitDone = false;

            })(); // end IIFE
        </script>
    </body>
</html>