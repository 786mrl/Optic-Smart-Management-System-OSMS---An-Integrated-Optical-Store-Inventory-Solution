<?php
session_start();
include 'db_config.php';
date_default_timezone_set('Asia/Jakarta');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_to_main'])) {
    
    // 1. Get Input & Session Validation
    $input_password = trim($_POST['admin_password_verify'] ?? '');
    $user_id = $_SESSION['user_id'] ?? null;

    if (!$user_id) {
        $_SESSION['error_msg'] = "Session expired. Please log in again.";
        header("Location: login.php");
        exit();
    }

    // 2. Securely Verify Admin Password
    $stmt_check = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
    $stmt_check->bind_param("i", $user_id);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    $user_data = $res_check->fetch_assoc();

    if (!$user_data || !password_verify($input_password, $user_data['password_hash'])) {
        $_SESSION['error_msg'] = "Authorization Failed: Incorrect Password!";
        header("Location: pending_records_frame.php");
        exit();
    }

    // 3. Data Migration Process
    $current_time = date('Y-m-d H:i:s');
    $query_staging = "SELECT * FROM frame_staging";
    $result_staging = $conn->query($query_staging);

    if ($result_staging && $result_staging->num_rows > 0) {
        $conn->begin_transaction();

        try {
            // Prepare INSERT ... ON DUPLICATE KEY UPDATE Query
            $sql = "INSERT INTO frames_main (
                        ufc, brand, frame_code, frame_size, color_code, 
                        material, lens_shape, structure, size_range, 
                        buy_price, sell_price, price_secret_code, 
                        stock, stock_age, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        buy_price = VALUES(buy_price),
                        sell_price = VALUES(sell_price),
                        price_secret_code = VALUES(price_secret_code),
                        material = VALUES(material),
                        lens_shape = VALUES(lens_shape),
                        structure = VALUES(structure),
                        size_range = VALUES(size_range),
                        stock_age = VALUES(stock_age),
                        stock = stock + VALUES(stock),
                        updated_at = VALUES(updated_at)";

            $stmt = $conn->prepare($sql);

            while ($row = $result_staging->fetch_assoc()) {
                // Binding 16 Parameters: sssssssssddsisss
                $stmt->bind_param("sssssssssddsisss", 
                    $row['ufc'], $row['brand'], $row['frame_code'], $row['frame_size'], 
                    $row['color_code'], $row['material'], $row['lens_shape'], $row['structure'], 
                    $row['size_range'], $row['buy_price'], $row['sell_price'], $row['price_secret_code'], 
                    $row['stock'], $row['stock_age'], $current_time, $current_time
                );

                if (!$stmt->execute()) {
                    throw new Exception("Error during data migration: " . $stmt->error);
                }

                // QR Code File Management
                $oldPath = "qrcodes/" . $row['ufc'] . ".png";
                $newPath = "main_qrcodes/" . $row['ufc'] . ".png";

                if (file_exists($oldPath)) {
                    if (!file_exists("main_qrcodes")) mkdir("main_qrcodes", 0777, true);
                    rename($oldPath, $newPath);
                }
            }

            // Clear Staging Table after success
            $conn->query("DELETE FROM frame_staging");

            $conn->commit();
            $_SESSION['success_msg'] = "Success! Data has been migrated to Main Database.";
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_msg'] = "System Error: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_msg'] = "No data found in staging to migrate.";
    }
    
    header("Location: pending_records_frame.php");
    exit();
}