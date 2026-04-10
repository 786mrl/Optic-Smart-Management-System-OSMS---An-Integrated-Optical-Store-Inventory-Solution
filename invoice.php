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
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

            /* Container for video and guide */
            .video-wrapper {
                position: relative;
                width: 320px;
                height: 240px;
                margin: 0 auto;
            }

            /* Oval face guide (Face Guide) */
            .face-guide {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 160px; /* Oval width */
                height: 200px; /* Oval height */
                border: 2px dashed rgba(0, 255, 136, 0.5);
                border-radius: 50%;
                z-index: 5;
                pointer-events: none; /* Prevents blocking clicks */
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
                                
                                <div class="video-wrapper">
                                    <div class="face-guide" id="guide-line"></div>
                                    <div id="video-container" style="position: relative; border-radius: 20px; overflow: hidden; box-shadow: 10px 10px 20px var(--shadow-dark);">
                                        <div class="scan-line" id="scanner"></div>
                                        <video id="video" width="320" height="240" autoplay muted style="transform: scaleX(-1); display: block;"></video>
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
                try {
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
                        resultBox.innerText = "CAMERA READY (" + (currentFacingMode === 'user' ? 'FRONT' : 'BACK') + ")";
                        
                        // Set mirroring: Only mirror if using the front camera
                        video.style.transform = currentFacingMode === 'user' ? 'scaleX(-1)' : 'scaleX(1)';
                        
                        scanBtn.innerHTML = '<div class="led"></div> CAPTURE PHOTO';
                        scanBtn.onclick = captureAndAnalyze;
                        
                        // Show the switch button
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
                
                // 1. Setup Canvas
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const context = canvas.getContext('2d');
                
                if (currentFacingMode === 'user') {
                    // Apply mirroring only for the front camera
                    context.translate(canvas.width, 0);
                    context.scale(-1, 1);
                }

                context.drawImage(video, 0, 0, canvas.width, canvas.height);

                // Hide the switch button during analysis
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

                    // DETECTION: Ensure withFaceLandmarks() is used
                    const detections = await faceapi.detectSingleFace(
                        canvas, 
                        new faceapi.SsdMobilenetv1Options({ minConfidence: 0.5 }) // Menggunakan SSD
                    ).withFaceLandmarks();

                    scanner.style.display = 'none';

                    if (detections) {
                        // ... (after the detections variable is obtained)
                        const points = detections.landmarks.positions;

                        // Key Landmarks
                        const jawLeft = points[0];
                        const jawRight = points[16];
                        const chin = points[8];
                        const eyebrowLeft = points[17];
                        const eyebrowRight = points[26];
                        const leftCheek = points[2];
                        const rightCheek = points[14];

                        // 1. Dimension Measurement
                        const faceWidth = Math.sqrt(Math.pow(jawRight.x - jawLeft.x, 2) + Math.pow(jawRight.y - jawLeft.y, 2));
                        const foreheadWidth = Math.sqrt(Math.pow(eyebrowRight.x - eyebrowLeft.x, 2) + Math.pow(eyebrowRight.y - eyebrowLeft.y, 2));
                        const cheekWidth = Math.sqrt(Math.pow(rightCheek.x - leftCheek.x, 2) + Math.pow(rightCheek.y - leftCheek.y, 2));

                        // Estimated Face Height (Eyebrows to Chin with forehead factor)
                        const eyeBrowY = (eyebrowLeft.y + eyebrowRight.y) / 2;
                        const faceHeight = Math.abs(chin.y - eyeBrowY) * 1.5; 

                        const ratio = faceHeight / faceWidth;

                        // 2. Multi-Factor Classification Logic
                        let shape = "";

                        if (ratio > 1.6) {
                            shape = "OVAL / LONG"; // Long face; rectangular or Wayfarer-style glasses are a good fit
                        } else if (ratio <= 1.35) {
                            // Distinguishing between Square and Round shapes
                            // Compare jaw width with cheekbone width
                            if (faceWidth > cheekWidth * 0.95) {
                                shape = "SQUARE"; // Broad and defined jawline
                            } else {
                                shape = "ROUND"; // Jawline narrower than cheeks and curved
                            }
                        } else if (foreheadWidth > faceWidth * 0.85) {
                            shape = "HEART"; // Wide forehead, pointed chin
                        } else {
                            shape = "DIAMOND"; // Cheekbones are the most dominant feature
                        }

                        // ENSURE VIDEO IS HIDDEN AND CANVAS IS VISIBLE
                        video.style.display = 'none';
                        canvas.style.display = 'block';
                        canvas.style.margin = '0 auto';

                        // FORCE RESULT BOX TO APPEAR AND STAY IN FRONT
                        resultBox.style.display = 'block'; 
                        resultBox.style.zIndex = '100'; 
                        resultBox.style.position = 'relative';
                        
                        // Update result text
                        resultBox.innerHTML = `
                            <div style="color: var(--text-muted); font-size: 0.7rem; margin-bottom: 5px;">ANALYSIS RESULT:</div>
                            <b style="color: #00ff88; font-size: 1.5rem; text-shadow: 0 0 10px rgba(0,255,136,0.5);">${shape}</b>
                            <div style="font-size: 10px; color: #888; margin-top: 5px;">
                                Ratio: ${ratio.toFixed(2)} | Width: ${Math.round(faceWidth)}px
                            </div>
                        `;

                        scanBtn.innerHTML = '<div class="led"></div> RETAKE PHOTO';
                        scanBtn.onclick = () => {
                            video.style.display = 'block';
                            canvas.style.display = 'none';
                            startFaceAnalysis();
                        };
                    } else {
                        throw new Error("FACE NOT DETECTED");
                    }
                } catch (err) {
                    scanner.style.display = 'none';
                    console.error(err); // Log original error for debugging
                    resultBox.innerHTML = `<b style='color:#ff4d4d;'>${err.message}. TRY AGAIN.</b>`;
                    scanBtn.innerHTML = '<div class="led"></div> RETAKE';
                    scanBtn.onclick = () => {
                        video.style.display = 'block';
                        canvas.style.display = 'none';
                        startFaceAnalysis();
                    };
                }
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