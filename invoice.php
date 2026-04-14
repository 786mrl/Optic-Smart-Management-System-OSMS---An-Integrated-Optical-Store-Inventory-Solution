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
        <script src="js/face-api.min.js"></script>
        <style>
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
                                <p class="scan-instruction">Position your face directly inside the green line</p>
                                
                                <div class="video-wrapper" style="height: 320px;"> <div class="face-guide" id="guide-line"></div>
                                    <div id="video-container" style="position: relative; border-radius: 20px; overflow: hidden; background: #000;">
                                        <div class="scan-line" id="scanner"></div>
                                        <video id="video" autoplay muted playsinline style="width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1);"></video>
                                        <canvas id="overlay" style="display: none;"></canvas> 
                                    </div>
                                </div>

                                <div id="face-result" class="read-only-box" style="margin-top: 15px; flex-direction: column; height: auto; padding: 15px; color: #00ff88;">
                                    READY TO SCAN...
                                </div>

                                <div class="selection-wrapper" style="margin-top: 15px;">
                                    <button type="button" class="neu-btn" id="start-scan">
                                        <div class="led"></div> START CAMERA
                                    </button>
                                    <button type="button" class="neu-btn" id="switch-camera" style="display: none;">
                                        <div class="led"></div> SWITCH CAMERA
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
            const api = window.faceapi || faceapi;

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

            const video = document.getElementById('video');
            const scanBtn = document.getElementById('start-scan');
            const resultBox = document.getElementById('face-result');
            const canvas = document.getElementById('overlay');

            // Helper function to ensure faceapi is ready
            function checkFaceApiReady() {
                return new Promise((resolve) => {
                    const check = setInterval(() => {
                        if (typeof faceapi !== 'undefined' && faceapi.nets) {
                            clearInterval(check);
                            resolve();
                        }
                    }, 100); // Check every 100ms
                });
            }

            // Global variable to store the snapped image
            let snappedImage = null;

            let currentFacingMode = "user"; // 'user' for front, 'environment' for back
            const switchBtn = document.getElementById('switch-camera');
            const guide = document.getElementById('guide-line');

            async function startFaceAnalysis() {
                const guide = document.getElementById('guide-line');
                guide.style.display = 'block'; // Ensure it is visible
                video.style.display = 'block'; // Ensure video is not hidden
                canvas.style.display = 'none'; // Hide previous photo results
                guide.style.animation = "pulse 1.5s infinite";
                try {
                    guide.style.display = 'block';
                    resultBox.innerText = "INITIALIZING...";
                    const MODEL_URL = './models/model'; 
                    
                    // Load models only if they haven't been loaded yet
                    if (!faceapi.nets.ssdMobilenetv1.params) {
                        resultBox.innerText = "LOADING ACCURATE MODELS...";
                        await faceapi.nets.ssdMobilenetv1.loadFromUri(MODEL_URL);
                        await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
                    }
                    
                    // Stop any currently running stream
                    if (video.srcObject) {
                        video.srcObject.getTracks().forEach(track => track.stop());
                    }

                    const stream = await navigator.mediaDevices.getUserMedia({ 
                        video: { facingMode: currentFacingMode } 
                    });
                    
                    video.srcObject = stream;
                    
                    video.onloadedmetadata = () => {
                        video.play();
                        resultBox.innerText = "CAMERA READY";
                        
                        // Ensure canvas size matches the cropped video display
                        video.style.width = "100%";
                        video.style.height = "100%";
                        video.style.objectFit = "cover";
                        
                        scanBtn.innerHTML = '<div class="led"></div> CAPTURE PHOTO';
                        scanBtn.onclick = captureAndAnalyze;
                        switchBtn.style.display = 'inline-block';
                    };

                } catch (err) {
                    resultBox.style.color = "#ff4d4d";
                    resultBox.innerText = "ERROR: " + err.message;
                }
            }

            // Logic to switch camera
            switchBtn.onclick = () => {
                currentFacingMode = (currentFacingMode === "user") ? "environment" : "user";
                startFaceAnalysis();
            };

            async function captureAndAnalyze() {
                const scanner = document.getElementById('scanner');
                resultBox.innerText = "CAPTURING...";

                // === SETUP CANVAS (SAMA SEPERTI SEBELUMNYA) ===
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const context = canvas.getContext('2d');

                if (currentFacingMode === 'user') {
                    context.translate(canvas.width, 0);
                    context.scale(-1, 1);
                }

                const centerX = canvas.width / 2;
                const centerY = canvas.height / 2.62;
                const radiusX = (canvas.width * 0.62) / 2;
                const radiusY = (canvas.height * 0.672) / 2;

                context.filter = 'blur(20px) brightness(0.5)';
                context.drawImage(video, 0, 0, canvas.width, canvas.height);
                context.filter = 'none';

                context.save();
                context.beginPath();
                context.ellipse(centerX, centerY, radiusX, radiusY, 0, 0, 2 * Math.PI);
                context.clip();
                context.drawImage(video, 0, 0, canvas.width, canvas.height);
                context.restore();

                context.beginPath();
                context.ellipse(centerX, centerY, radiusX, radiusY, 0, 0, 2 * Math.PI);
                context.strokeStyle = '#00ff88';
                context.lineWidth = Math.max(4, canvas.width * 0.005);
                context.stroke();

                switchBtn.style.display = 'none';
                guide.style.display = 'none';
                video.style.display = 'none';
                canvas.style.display = 'block';
                scanner.style.display = 'block';

                const stream = video.srcObject;
                if (stream) stream.getTracks().forEach(track => track.stop());

                resultBox.innerHTML = "ANALYZING SHAPE <span class='loading-dots'>...</span>";

                try {
                    await new Promise(r => setTimeout(r, 2000));

                    const detections = await faceapi.detectSingleFace(
                        canvas,
                        new faceapi.SsdMobilenetv1Options({ minConfidence: 0.5 })
                    ).withFaceLandmarks();

                    scanner.style.display = 'none';

                    if (!detections) throw new Error("FACE NOT DETECTED");

                    const pts = detections.landmarks.positions;

                    // === 6 PENGUKURAN AKURAT ===

                    // 1. Lebar wajah (jaw-to-jaw): titik 0 & 16
                    const faceWidth = Math.hypot(pts[16].x - pts[0].x, pts[16].y - pts[0].y);

                    // 2. Tinggi wajah: dari dagu (8) ke tengah alis
                    const browMidY = (pts[19].y + pts[24].y) / 2;
                    const faceHeight = Math.abs(pts[8].y - browMidY);

                    // 3. Lebar dahi: titik alis paling luar (17 & 26), diestimasi naik sedikit
                    const foreheadWidth = Math.hypot(pts[26].x - pts[17].x, pts[26].y - pts[17].y) * 1.1;

                    // 4. Lebar tulang pipi: titik 1 & 15 (area pipi)
                    const cheekWidth = Math.hypot(pts[15].x - pts[1].x, pts[15].y - pts[1].y);

                    // 5. Lebar rahang: titik 4 & 12
                    const jawWidth = Math.hypot(pts[12].x - pts[4].x, pts[12].y - pts[4].y);

                    // 6. Ketajaman dagu: sudut antara titik 6 → 8 → 10
                    const v1 = { x: pts[6].x - pts[8].x, y: pts[6].y - pts[8].y };
                    const v2 = { x: pts[10].x - pts[8].x, y: pts[10].y - pts[8].y };
                    const cosAngle = (v1.x * v2.x + v1.y * v2.y) / (Math.hypot(v1.x, v1.y) * Math.hypot(v2.x, v2.y));
                    const chinAngle = Math.acos(Math.max(-1, Math.min(1, cosAngle))) * (180 / Math.PI);

                    // === RASIO ===
                    const faceRatio     = faceHeight / faceWidth;
                    const foreheadRatio = foreheadWidth / faceWidth;
                    const cheekRatio    = cheekWidth / faceWidth;
                    const jawRatio      = jawWidth / faceWidth;
                    const chinSharp     = chinAngle; // makin kecil = makin lancip

                    // === SCORING SETIAP BENTUK ===
                    // Menggunakan sistem skor tertimbang — bentuk dengan skor tertinggi menang
                    const scores = {
                        "OVAL":     scoreOval(faceRatio, foreheadRatio, cheekRatio, jawRatio, chinSharp),
                        "ROUND":    scoreRound(faceRatio, cheekRatio, jawRatio, chinSharp),
                        "SQUARE":   scoreSquare(faceRatio, jawRatio, chinSharp),
                        "OBLONG":   scoreOblong(faceRatio, foreheadRatio, jawRatio),
                        "HEART":    scoreHeart(foreheadRatio, jawRatio, chinSharp),
                        "DIAMOND":  scoreDiamond(foreheadRatio, cheekRatio, jawRatio, chinSharp),
                        "TRIANGLE": scoreTriangle(foreheadRatio, jawRatio)
                    };

                    // Ambil bentuk dengan skor tertinggi
                    const shape = Object.entries(scores).reduce((a, b) => b[1] > a[1] ? b : a)[0];
                    const topScore = scores[shape];
                    const confidence = Math.min(100, Math.round((topScore / 10) * 100));

                    resultBox.innerHTML = `
                        <div style="color: var(--text-muted); font-size: 0.7rem; margin-bottom: 5px;">ANALYSIS RESULT:</div>
                        <b style="color: #00ff88; font-size: 1.5rem; text-shadow: 0 0 10px rgba(0,255,136,0.5);">${shape}</b>
                        <div style="font-size: 11px; color: #888; margin-top: 8px; line-height: 1.6;">
                            Confidence: ${confidence}% &nbsp;|&nbsp; 
                            Ratio: ${faceRatio.toFixed(2)} &nbsp;|&nbsp; 
                            Jaw: ${jawRatio.toFixed(2)} &nbsp;|&nbsp; 
                            Chin: ${chinSharp.toFixed(0)}°
                        </div>
                    `;

                    scanBtn.innerHTML = '<div class="led"></div> RETAKE PHOTO';
                    scanBtn.onclick = () => {
                        video.style.display = 'block';
                        canvas.style.display = 'none';
                        guide.style.display = 'block';
                        startFaceAnalysis();
                    };

                } catch (err) {
                    scanner.style.display = 'none';
                    resultBox.innerHTML = `<b style='color:#ff4d4d;'>${err.message}. TRY AGAIN.</b>`;
                    scanBtn.innerHTML = '<div class="led"></div> RETAKE';
                    scanBtn.onclick = () => {
                        video.style.display = 'block';
                        canvas.style.display = 'none';
                        guide.style.display = 'block';
                        startFaceAnalysis();
                    };
                }
            }

            // =============================================
            // FUNGSI SCORING UNTUK SETIAP BENTUK WAJAH
            // Nilai 0-10, makin tinggi makin cocok
            // =============================================

            function scoreOval(fr, fore, cheek, jaw, chin) {
                let s = 0;
                if (fr > 1.3 && fr < 1.7)    s += 3;   // tinggi sedang
                if (cheek > jaw)              s += 2;   // pipi lebih lebar dari rahang
                if (cheek > fore)             s += 2;   // pipi lebih lebar dari dahi
                if (jaw < 0.75)               s += 2;   // rahang tidak terlalu lebar
                if (chin > 80 && chin < 130)  s += 1;   // dagu tidak terlalu lancip
                return s;
            }

            function scoreRound(fr, cheek, jaw, chin) {
                let s = 0;
                if (fr < 1.3)        s += 3;   // wajah pendek / bulat
                if (cheek > 0.85)    s += 2;   // pipi sangat lebar
                if (jaw > 0.70)      s += 2;   // rahang cukup lebar
                if (chin > 120)      s += 3;   // dagu tumpul / bulat
                return s;
            }

            function scoreSquare(fr, jaw, chin) {
                let s = 0;
                if (fr > 0.9 && fr < 1.3)  s += 3;   // proporsi hampir sama
                if (jaw > 0.80)             s += 4;   // rahang sangat lebar & tegas
                if (chin > 110)             s += 3;   // dagu kotak / tumpul
                return s;
            }

            function scoreOblong(fr, fore, jaw) {
                let s = 0;
                if (fr > 1.65)      s += 5;   // wajah sangat panjang
                if (fore < 0.85)    s += 2;   // dahi tidak terlalu lebar
                if (jaw < 0.72)     s += 3;   // rahang sempit
                return s;
            }

            function scoreHeart(fore, jaw, chin) {
                let s = 0;
                if (fore > 0.95)    s += 4;   // dahi lebar
                if (jaw < 0.65)     s += 3;   // rahang sempit
                if (chin < 80)      s += 3;   // dagu lancip
                return s;
            }

            function scoreDiamond(fore, cheek, jaw, chin) {
                let s = 0;
                if (cheek > fore && cheek > jaw)  s += 5;  // pipi paling lebar
                if (fore < 0.85)                  s += 2;  // dahi tidak terlalu lebar
                if (jaw < 0.70)                   s += 2;  // rahang sempit
                if (chin < 90)                    s += 1;  // dagu agak lancip
                return s;
            }

            function scoreTriangle(fore, jaw) {
                let s = 0;
                if (jaw > fore * 1.15)  s += 6;   // rahang jauh lebih lebar dari dahi
                if (fore < 0.80)        s += 4;   // dahi sempit
                return s;
            }

            scanBtn.onclick = startFaceAnalysis;

            window.onload = () => {
                console.log("Check FaceAPI:", window.faceapi);
                if (typeof faceapi === 'undefined') {
                    alert("FaceAPI Library failed to load! Please check the file path or connection.");
                }
            };
        </script>
    </body>
</html>