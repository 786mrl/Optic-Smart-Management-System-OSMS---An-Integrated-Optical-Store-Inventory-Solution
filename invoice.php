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
                padding: 25px;
                border-radius: 20px;
                box-shadow: 8px 8px 16px var(--shadow-dark), -8px -8px 16px var(--shadow-light);
                margin-top: 15px;
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
                            <label>PRESCRIPTION MODIFICATION</label>
                            <div class="selection-wrapper">
                                <button type="button" class="neu-btn active" id="mod-no">
                                    <div class="led"></div> NO
                                </button>
                                <button type="button" class="neu-btn" id="mod-yes">
                                    <div class="led"></div> YES (MODIFY)
                                </button>
                            </div>
                        </div>

                        <form method="POST" class="full">
                            <input type="hidden" name="invoice_number" value="<?php echo $data['invoice_number']; ?>">
                            
                            <div class="prescription-container">
                                <h3 style="color: var(--accent-color); font-size: 0.85rem; margin-bottom: 20px; text-align: center; opacity: 0.8;">
                                    — ADJUSTED MEASUREMENT —
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
                });
            };

            window.onload = () => {
                const isModified = <?php echo $data['lens_modification'] == 1 ? 'true' : 'false'; ?>;
                if (isModified) {
                    // Memicu tampilan seolah-olah tombol 'Yes' diklik
                    modYes.classList.add('active');
                    modNo.classList.remove('active');
                    // Namun tetap biarkan readonly kecuali user ingin mengedit lagi
                    fields.forEach(f => {
                        f.style.color = "#00ff88"; // Warna hijau menandakan data modifikasi
                    });
                }
            };
        </script>
    </body>
</html>