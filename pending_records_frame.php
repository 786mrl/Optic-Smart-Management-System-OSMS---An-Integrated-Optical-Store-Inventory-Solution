<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }
    $role = $_SESSION['role'] ?? 'staff';

    // Action Process (Delete only for Admin)
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && $role === 'admin') {
        $target_ufc = $_GET['ufc'];
        $stmt = $conn->prepare("DELETE FROM frame_staging WHERE ufc = ?");
        $stmt->bind_param("s", $target_ufc);
        $stmt->execute();
        header("Location: pending_record_frame.php");
        exit();
    }
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Pending Records</title>
    <link rel="stylesheet" href="style.css">    
    <script>let hasData;</script>
    <style>
        .container-pending { padding: 20px; background: #23272a; border-radius: 15px; margin: 20px; }
        .table-pending { width: 100%; border-collapse: collapse; margin-top: 15px; color: white; margin-bottom: 30px; }
        .table-pending th, .table-pending td { padding: 12px; border: 1px solid #3e4144; text-align: left; font-size: 13px; }
        .table-pending th { background: #2e3133; color: #00ff88; text-transform: uppercase; }
        .action-btn { padding: 6px 12px; border-radius: 5px; cursor: pointer; text-decoration: none; font-size: 11px; font-weight: bold; margin-right: 5px; display: inline-block; }
        .btn-edit { background: #f1c40f; color: black; }
        .btn-delete { background: #e74c3c; color: white; }
        .btn-approve { background: #00ff88; color: black; }
        .btn-disabled { background: #555; color: #888; cursor: not-allowed; pointer-events: none; }
        .section-title { color: #00ff88; margin-top: 20px; border-left: 4px solid #00ff88; padding-left: 10px; }
    </style>
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
                
                <div class="container-pending">
                    <!-- Determine empty or not -->
                    <?php if ($result->num_rows > 0): ?>
                        <script>
                            hasData = true;
                        </script>
                    <?php else: ?>
                        <script>
                            hasData = false;
                        </script>
                    <?php endif; ?>

                    <?php if ($role === 'admin'): ?>
                        <div id="admin-pending-display">
                            <h2 style="color: #00ff88; margin-top: 0;">PRICE ENTRY QUEUE</h2>
                            <p style="font-size: 13px; color: #ccc; margin-bottom: 20px;">
                                List of frames recently entered by staff that <strong>require price assignment</strong> by an Admin.
                            </p>
                            
                            <table class="table-pending">
                                <thead>
                                    <tr>
                                        <th>UFC / BRAND</th>
                                        <th>MATERIAL</th>
                                        <th>STOCK</th>
                                        <th>ACTIONS</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php
                                        // AND Filter: Only display if all three fields are not yet filled
                                        $query = "SELECT ufc, brand, material, stock 
                                                FROM frame_staging 
                                                WHERE buy_price = 0 
                                                AND sell_price = 0 
                                                AND (price_secret_code = '' OR price_secret_code IS NULL)";
                                        $result = $conn->query($query);

                                        if ($result->num_rows > 0):
                                            while($row = $result->fetch_assoc()):
                                    ?>
                                        <tr>
                                            <td>
                                                <strong style="color: #00ff88;"><?php echo $row['ufc']; ?></strong><br>
                                                <small><?php echo $row['brand']; ?></small>
                                            </td>
                                            <td><?php echo $row['material']; ?></td>
                                            <td><?php echo $row['stock']; ?></td>
                                            <td>
                                                <a href="edit_frame.php?ufc=<?php echo urlencode($row['ufc']); ?>" class="action-btn btn-edit">SET PRICE</a>
                                                
                                                <a href="?action=delete&ufc=<?php echo urlencode($row['ufc']); ?>" class="action-btn btn-delete" onclick="return confirm('Remove this from queue?')">DELETE</a>
                                            </td>
                                        </tr>
                                    <?php 
                                        endwhile; 
                                    else: 
                                    ?>
                                        <tr>
                                            <td colspan="4" class="empty-state">
                                                All frames have been priced. No pending records in queue.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    
                    <div class="empty-state" id="emptyMessage">
                        <div class="empty-icon">📂</div>
                        <p style="font-weight: 600;">No pending requests</p>
                        <p class="subtitle">All staff account entries have been successfully processed.</p>
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
        if(!hasData) {
            document.querySelector('.container-pending').style.display = 'none';
            document.getElementById('emptyMessage').style.display = 'block';
        }
    </script>

</body>
</html>