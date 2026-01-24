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

    // 3. Data Migration Process (Selected items only)
    $current_time = date('Y-m-d H:i:s');
    $selected_ufcs = $_POST['selected_ufc'] ?? [];

    if (!empty($selected_ufcs)) {
        $conn->begin_transaction();

        try {
            // Prepare Query to fetch data from staging based on selected UFCs
            $placeholders = implode(',', array_fill(0, count($selected_ufcs), '?'));
            $stmt_select = $conn->prepare("SELECT * FROM frame_staging WHERE ufc IN ($placeholders)");
            $stmt_select->bind_param(str_repeat('s', count($selected_ufcs)), ...$selected_ufcs);
            $stmt_select->execute();
            $result_staging = $stmt_select->get_result();

            // Prepare INSERT ... ON DUPLICATE KEY UPDATE Query
            $sql_insert = "INSERT INTO frames_main (
                        ufc, brand, frame_code, frame_size, color_code, 
                        material, lens_shape, structure, size_range, 
                        gender_category, buy_price, sell_price, price_secret_code, 
                        stock, stock_age, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    material = VALUES(material),
                    lens_shape = VALUES(lens_shape),
                    structure = VALUES(structure),
                    size_range = VALUES(size_range),
                    gender_category = VALUES(gender_category),
                    buy_price = VALUES(buy_price),
                    sell_price = VALUES(sell_price),
                    price_secret_code = VALUES(price_secret_code),
                    stock = stock + VALUES(stock),
                    updated_at = VALUES(updated_at)";

            $stmt_insert = $conn->prepare($sql_insert);

            while ($row = $result_staging->fetch_assoc()) {
                // Binding data to Main Database
                $stmt_insert->bind_param("ssssssssssddsisss", 
                    $row['ufc'], $row['brand'], $row['frame_code'], $row['frame_size'], 
                    $row['color_code'], $row['material'], $row['lens_shape'], $row['structure'], 
                    $row['size_range'], $row['gender_category'], $row['buy_price'], $row['sell_price'], $row['price_secret_code'], 
                    $row['stock'], $row['stock_age'], $current_time, $current_time
                );

                if (!$stmt_insert->execute()) {
                    throw new Exception("Migration Error: " . $stmt_insert->error);
                }

                // Manage QR Code Files (Move from staging to main_qrcodes)
                $oldPath = "qrcodes/" . $row['ufc'] . ".png";
                $newPath = "main_qrcodes/" . $row['ufc'] . ".png";

                if (file_exists($oldPath)) {
                    if (!file_exists("main_qrcodes")) mkdir("main_qrcodes", 0777, true);
                    rename($oldPath, $newPath);
                }

                // Delete this item from Staging since it has moved to Main
                $stmt_del = $conn->prepare("DELETE FROM frame_staging WHERE ufc = ?");
                $stmt_del->bind_param("s", $row['ufc']);
                $stmt_del->execute();
            }

            $conn->commit();
            $_SESSION['success_msg'] = count($selected_ufcs) . " items successfully migrated to Main Database.";
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_msg'] = "System Error: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_msg'] = "Please select at least one item to migrate.";
    }
    
    header("Location: pending_records_frame.php");
    exit();
}