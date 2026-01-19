<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }
    $role = $_SESSION['role'] ?? 'staff';

    // Action Process (Delete only for Admin)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && $role === 'admin') {
        $target_ufc = $_POST['ufc'];

        // 1. Get the QR Code filename based on UFC
        // Filename: ufc.png (e.g., LENZA-xxx.png)
        $qrCodePath = "qrcodes/" . $target_ufc . ".png";

        // 2. Delete the image file from the folder if it exists
        if (file_exists($qrCodePath)) {
            unlink($qrCodePath);
        }

        // 3. Delete record from the database
        $stmt = $conn->prepare("DELETE FROM frame_staging WHERE ufc = ?");
        $stmt->bind_param("s", $target_ufc);
        
        if ($stmt->execute()) {
            header("Location: pending_records_frame.php");
            exit();
        } else {
            die("Error deleting record: " . $conn->error);
        }
    }
?>

<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
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
            </div>

            <div class="btn-group">
                <button type="button" class="back-main" onclick="window.location.href='frame_management.php'">BACK TO PREVIOUS PAGE</button>
            </div>

            <footer class="footer-container">
                <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
            </footer>
        </div>

    </body>
</html>