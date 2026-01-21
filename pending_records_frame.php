<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }
    $role = $_SESSION['role'] ?? 'staff';

    // Action Process (Delete only for Admin)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && $role === 'admin') {
        $target_ufc = basename($_POST['ufc']);

        // 1. Get the QR Code filename based on UFC
        // Filename: ufc.png (e.g., LENZA-xxx.png)
        $qrCodePath = "qrcodes/" . $target_ufc . ".png";

        // 2. Delete the image file from the folder if it exists
        if (!empty($target_ufc) && file_exists($qrCodePath)) {
            unlink($qrCodePath);
        }

        // 3. Delete record from the database
        $stmt = $conn->prepare("DELETE FROM frame_staging WHERE ufc = ?");
        $stmt->bind_param("s", $target_ufc);
        
        if ($stmt->execute()) {
            $_SESSION['success_msg'] = "Record $target_ufc has been deleted.";
            header("Location: pending_records_frame.php");
            exit();
        }
    }
?>

<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Pending Records</title>
        <link rel="stylesheet" href="style.css">    
        <style>
            /* Main container must have the same color as buttons for the neumorphic effect to appear */
            :root {
                --bg-dark: #23272a;
                --shadow-light: #2d3236; /* Slightly lighter than BG */
                --shadow-dark: #191c1e;  /* Slightly darker than BG */
                --accent-gold: #f1c40f;
                --accent-red: #e74c3c;
                --accent-green: #81C784;
            }

            .action-btn-container { 
                display: flex; 
                gap: 15px; 
                justify-content: center;
            }

            /* Neumorphism Base Button Style */
            .btn-table { 
                padding: 10px 20px; 
                border: none; 
                border-radius: 12px; /* Large radius characteristic of neumorphism */
                cursor: pointer; 
                font-weight: bold; 
                font-size: 11px;
                background: var(--bg-dark);
                color: #fff;
                transition: all 0.2s ease;
                
                /* Embossed Effect (Extruded) */
                box-shadow: 5px 5px 10px var(--shadow-dark), 
                        -5px -5px 10px var(--shadow-light);
                outline: none;
            }

            /* Pressed effect (Appears sunken/inset) */
            .btn-table:active {
                box-shadow: inset 5px 5px 10px var(--shadow-dark), 
                            inset -5px -5px 10px var(--shadow-light);
                transform: scale(0.98);
            }

            /* Color variations and thin borders for accent */
            .btn-set-price { 
                color: var(--accent-gold);
                border: 1px solid rgba(241, 196, 15, 0.1);
            }

            .btn-set-price:hover {
                text-shadow: 0 0 8px rgba(241, 196, 15, 0.5);
            }

            .btn-delete-row { 
                color: var(--accent-red);
                border: 1px solid rgba(231, 76, 60, 0.1);
            }

            .btn-delete-row:hover {
                text-shadow: 0 0 8px rgba(231, 76, 60, 0.5);
            }

            /* Table row styles for consistency */
            .table-wrapper table tr {
                background: transparent;
            }
            
            .table-wrapper td {
                border-bottom: 1px solid #2d3236;
                padding: 15px 10px;
                font-size: 12px;
                text-align: center;
            }

            th {
                text-align: center;
            }
            #emptyMessage { display: none; text-align: center; padding: 40px; }
            input[type="checkbox"] {
                cursor: pointer;
                width: 18px;
                height: 18px;
                accent-color: var(--accent-green);
            }
            
            .neumorph-alert {
                border-radius: 20px !important;
                box-shadow: 10px 10px 20px #1a1d20, -10px -10px 20px #2c3134 !important;
            }
            /* Responsif Mobile Fix */
            @media (max-width: 600px) {
                .table-wrapper {
                    overflow-x: auto;
                }
                table {
                    table-layout: auto !important;
                    min-width: 600px;
                }
                .table-wrapper td[data-label="SELECT"], 
                .table-wrapper td[data-label="NO"] {
                    text-align: right !important;
                }
            }
        </style>
    </head>

    <body>

        <div class="main-wrapper">
            <div class="content-area" style="flex-direction: column">
                <div class="header-container" style="margin-left: auto; margin-right: auto; width: 100%;">
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
                
                <!-- PRICE ENTRY QUEUE -->
                <div class="main-card" style="margin-left: auto; margin-right: auto;">                    
                    <div class="glass-window">
                        <?php
                            $query = "SELECT ufc, brand, stock 
                                    FROM frame_staging 
                                    WHERE buy_price = 0 
                                    AND sell_price = 0 
                                    AND (price_secret_code = '' OR price_secret_code IS NULL)";
                            $result = $conn->query($query);
                            $hasData = ($result && $result->num_rows > 0);
                        ?>

                        <?php if ($role === 'admin'): ?>
                            <div id="admin-display-section" class="table-responsive_approve_user" style="<?php echo !$hasData ? 'display:none;' : ''; ?>">
                                <h2 style="margin-bottom: 25px; font-size: 18px;">PRICE ENTRY QUEUE</h2>
                                <p style="font-size: 13px; color: #ccc; margin-bottom: 20px;">
                                    List of frames recently entered by staff that <strong>require price assignment</strong> by an Admin.
                                </p>

                                <div class="table-wrapper">
                                    <table style="table-layout: fixed; width: 100%;">
                                        <colgroup>
                                            <col style="width: 200px;">
                                            <col style="width: 150px;">
                                            <col style="width: 80px;">
                                            <col style="width: 250px;">
                                        </colgroup>
                                        <thead>
                                            <tr>
                                                <th>UFC</th>
                                                <th>BRAND</th>
                                                <th>STOCK</th>
                                                <th>ACTIONS</th>
                                            </tr>
                                        </thead>
        
                                        <tbody>
                                            <?php if ($hasData): 
                                                while($row = $result->fetch_assoc()): ?>
                                                    <tr>
                                                        <td><strong style="color: var(--accent);"><?php echo $row['ufc']; ?></strong></td>
                                                        <td><?php echo $row['brand']; ?></td>
                                                        <td><?php echo $row['stock']; ?></td>
                                                        <td>
                                                            <div class="action-btn-container">
                                                                <button type="button" class="btn-table btn-set-price" 
                                                                        onclick="window.location.href='edit_frame.php?ufc=<?php echo urlencode($row['ufc']); ?>'">
                                                                    SET PRICE
                                                                </button>
                                                                
                                                                <form method="POST" action="pending_records_frame.php">
                                                                    <input type="hidden" name="action" value="delete">
                                                                    <input type="hidden" name="ufc" value="<?php echo htmlspecialchars($row['ufc']); ?>">
                                                                    <button type="submit" class="btn-table btn-delete-row" 
                                                                            onclick="return confirm('Remove this from queue?')">
                                                                        DELETE
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>                    
                            </div>
                        <?php endif; ?>
                        
                        <div class="empty-state" id="emptyMessage" style="<?php echo !$hasData ? 'display:block;' : 'display:none;'; ?>">
                            <div class="empty-icon">📂</div>
                            <p style="font-weight: 600;">No pending requests</p>
                            <p class="subtitle">NO RECENT INPUT DATA FROM STAFF</p>
                        </div>
                    </div>                
                </div>

                <!-- CORRUPTED DATA -->
                <div class="main-card" style="margin-left: auto; margin-right: auto;">                    
                    <div class="glass-window">
                        <?php
                            $queryCorrupt = "SELECT ufc, brand, stock 
                                            FROM frame_staging 
                                            WHERE 
                                            (
                                            (ufc = '' OR ufc IS NULL) OR
                                            (brand = '' OR brand IS NULL) OR
                                            (frame_code = '' OR frame_code IS NULL) OR
                                            (frame_size = '' OR frame_size IS NULL) OR
                                            (color_code = '' OR color_code IS NULL) OR
                                            (stock < 0)
                                            )
                                            OR 
                                            (
                                            NOT (buy_price = 0 AND sell_price = 0 AND (price_secret_code = '' OR price_secret_code IS NULL))
                                            AND 
                                            (
                                                (buy_price > 0 AND sell_price <= 0 AND TRIM(price_secret_code) = 'LZ00') 
                                                OR 
                                                (sell_price > 0 AND TRIM(price_secret_code) = 'LZ00') 
                                            )
                                            )";
                            $resultCorrupt = $conn->query($queryCorrupt);
                            
                            // DEFINE THIS VARIABLE FOR USE BELOW
                            $hasCorruptedData = ($resultCorrupt && $resultCorrupt->num_rows > 0);
                        ?>

                        <?php if ($role === 'admin'): ?>
                            <div id="corrupt-display-section" class="table-responsive_approve_user" style="<?php echo !$hasCorruptedData ? 'display:none;' : ''; ?>">
                                <h2 style="margin-bottom: 25px; font-size: 18px; color: var(--accent-red);">CORRUPTED DATA</h2>
                                <p style="font-size: 13px; color: #ccc; margin-bottom: 20px;">
                                    List of records with <strong>missing identity</strong>, <strong>negative stock</strong>, or <strong>price encryption errors</strong>.
                                </p>

                                <div class="table-wrapper">
                                    <table style="table-layout: fixed; width: 100%;">
                                        <colgroup>
                                            <col style="width: 200px;">
                                            <col style="width: 150px;">
                                            <col style="width: 80px;">
                                            <col style="width: 250px;">
                                        </colgroup>
                                        <thead>
                                            <tr>
                                                <th>UFC</th>
                                                <th>BRAND</th>
                                                <th>STOCK</th>
                                                <th>ACTIONS</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($hasCorruptedData): 
                                                while($rowCorrupt = $resultCorrupt->fetch_assoc()): ?>
                                                    <tr>
                                                        <td><strong style="color: var(--accent-red);"><?php echo $rowCorrupt['ufc'] ?: 'MISSING UFC'; ?></strong></td>
                                                        <td><?php echo $rowCorrupt['brand']; ?></td>
                                                        <td><?php echo $rowCorrupt['stock']; ?></td>
                                                        <td>
                                                            <div class="action-btn-container">
                                                                <button type="button" class="btn-table btn-set-price" 
                                                                        onclick="window.location.href='edit_frame.php?ufc=<?php echo urlencode($rowCorrupt['ufc']); ?>'">
                                                                    FIX DATA
                                                                </button>
                                                                
                                                                <form method="POST" action="pending_records_frame.php">
                                                                    <input type="hidden" name="action" value="delete">
                                                                    <input type="hidden" name="ufc" value="<?php echo htmlspecialchars($rowCorrupt['ufc']); ?>">
                                                                    <button type="submit" class="btn-table btn-delete-row" 
                                                                            onclick="return confirm('Permanently delete this corrupted record?')">
                                                                        DELETE
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>                    
                            </div>
                        <?php endif; ?>
                        
                        <div class="empty-state" id="corruptedDataMessage" style="<?php echo !$hasCorruptedData ? 'display:block;' : 'display:none;'; ?>">
                            <div class="empty-icon">🛡️</div> 
                            <p style="font-weight: 600;">System Integrity Clear</p>
                            <p class="subtitle">NO CORRUPTED DATA DETECTED IN THE SYSTEM</p>
                        </div>
                    </div>                
                </div>

                <!-- STAGING TABLE -->
                <div class="main-card" style="margin-left: auto; margin-right: auto;">                    
                    <div class="glass-window">
                        <?php
                            $queryStaging = "SELECT ufc, brand, stock, price_secret_code FROM frame_staging";
                            $resultStaging = $conn->query($queryStaging);
                            $hasStagingData = ($resultStaging && $resultStaging->num_rows > 0);
                        ?>

                        <?php if ($role === 'admin'): ?>
                            <div id="staging-display-section" class="table-responsive_approve_user" style="<?php echo !$hasStagingData ? 'display:none;' : ''; ?>">
                                <h2 style="margin-bottom: 25px; font-size: 18px; color: var(--accent-green);">STAGING TABLE</h2>

                                <form id="printForm" action="print_qrcodes.php" method="POST">
                                    <div class="form-grid">
                                        <div class="table-wrapper">
                                            <table style="table-layout: fixed; width: 100%;">
                                                <colgroup>
                                                    <col style="width: 50px;"> <col style="width: 50px;"> <col style="width: 180px;"> <col style="width: 120px;"> <col style="width: 80px;">  <col style="width: 150px;"> <col style="width: 200px;"> </colgroup>
                                                <thead>
                                                    <tr>
                                                        <th><input type="checkbox" id="selectAllStaging" onclick="toggleSelectAll(this)" checked></th>
                                                        <th>NO</th>
                                                        <th>UFC</th>
                                                        <th>BRAND</th>
                                                        <th>STOCK</th>
                                                        <th>SECRET CODE</th>
                                                        <th>ACTIONS</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if ($hasStagingData): 
                                                        $no = 1;
                                                        while($rowStaging = $resultStaging->fetch_assoc()): ?>
                                                            <tr>
                                                                <td data-label="SELECT">
                                                                    <input type="checkbox" class="staging-checkbox" name="selected_ufc[]" 
                                                                    value="<?php echo htmlspecialchars($rowStaging['ufc']); ?>" checked>
                                                                </td>
                                                                <td data-label="NO"><?php echo $no++; ?></td>
                                                                <td data-label="UFC"><strong style="color: var(--accent-green);"><?php echo $rowStaging['ufc'] ?: 'MISSING UFC'; ?></strong></td>
                                                                <td data-label="BRAND"><?php echo $rowStaging['brand']; ?></td>
                                                                <td data-label="STOCK"><?php echo $rowStaging['stock']; ?></td>
                                                                <td data-label="SECRET CODE"><?php echo $rowStaging['price_secret_code']; ?></td>
                                                                <td data-label="ACTIONS">
                                                                    <div class="action-btn-container">
                                                                        <button type="button" class="btn-table btn-set-price" 
                                                                                onclick="window.location.href='edit_frame.php?ufc=<?php echo urlencode($rowStaging['ufc']); ?>'">
                                                                            EDIT
                                                                        </button>
                                                                        
                                                                        <form method="POST" action="" style="display:inline;">
                                                                            <input type="hidden" name="action" value="delete">
                                                                            <input type="hidden" name="ufc" value="<?php echo htmlspecialchars($rowStaging['ufc']); ?>">
                                                                            <button type="submit" class="btn-table btn-delete-row" 
                                                                                    onclick="return confirm('Permanently delete this record?')">
                                                                                DELETE
                                                                            </button>
                                                                        </form>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        <?php endwhile; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        
                                        <div class="btn-group">
                                            <button type="submit" class="submit-main">
                                                🖨️ PRINT SELECTED QR
                                            </button>
                                            <button type="submit" 
                                                    name="submit_to_main" 
                                                    formaction="process_upload_main.php" 
                                                    class="submit-main" 
                                                    style="background: var(--accent-green); color: #191c1e;"
                                                    onclick="return confirm('Move all staging data to the Main Database?')">
                                                SAVE DATA TO MAIN DATABASE
                                            </button>                                            
                                        </div>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                        
                        <div class="empty-state" id="stagingEmptyMessage" style="<?php echo !$hasStagingData ? 'display:block;' : 'display:none;'; ?>">
                            <div class="empty-icon">📥</div> 
                            <p style="font-weight: 600;">Staging Area Empty</p>
                            <p class="subtitle">NO PENDING DATA READY TO BE UPLOADED</p>
                        </div>
                    </div>                
                </div>
            </div>

            <div class="btn-group">
                <button type="button" class="back-main" onclick="window.location.href='frame_management.php'">BACK TO PREVIOUS PAGE</button>
            </div>

            <footer class="footer-container">
                <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
            </footer>
        </div>        

        <script>
            function toggleSelectAll(source) {
                const checkboxes = document.querySelectorAll('.staging-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = source.checked;
                });
            }
            document.addEventListener('DOMContentLoaded', function() {
                const masterCheckbox = document.getElementById('selectAllStaging');
                const itemCheckboxes = document.querySelectorAll('.staging-checkbox');

                itemCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        const totalChecked = document.querySelectorAll('.staging-checkbox:checked').length;
                        masterCheckbox.checked = (totalChecked === itemCheckboxes.length);
                    });
                });
            });
        </script>

        <!-- ALERT IF SUCCESS TO UPLOAD TO MAIN DATABASE -->
        <?php if(isset($_SESSION['success_msg'])): ?>
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
            <script>
                Swal.fire({
                    title: 'SUCCESS',
                    text: '<?php echo $_SESSION['success_msg']; ?>',
                    icon: 'success',
                    iconColor: '#00ff88',
                    background: '#23272a', /* Matches your neumorphic theme */
                    color: '#ffffff',
                    confirmButtonText: 'GREAT',
                    customClass: {
                        popup: 'neumorph-alert',
                        confirmButton: 'btn-table' 
                    }
                });
            </script>
            <?php unset($_SESSION['success_msg']); ?>
        <?php endif; ?>

        <!-- ALERT FOR ERROR -->
        <?php if(isset($_SESSION['error_msg'])): ?>
            <script>
                Swal.fire({
                    title: 'FAILED!',
                    text: '<?php echo $_SESSION['error_msg']; ?>',
                    icon: 'error',
                    background: '#23272a',
                    color: '#ffffff',
                    confirmButtonText: 'OK'
                });
            </script>
            <?php unset($_SESSION['error_msg']); ?>
        <?php endif; ?>

    </body>
</html>