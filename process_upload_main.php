<?php
session_start();
include 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_to_main'])) {
    
    // 1. Retrieve all data from staging
    $query = "SELECT * FROM frame_staging";
    $result = $conn->query($query);

    if ($result->num_rows > 0) {
        $conn->begin_transaction(); // Use transactions for data integrity

        try {
            // Create main_qrcodes folder if it doesn't exist
            if (!file_exists('main_qrcodes')) {
                mkdir('main_qrcodes', 0777, true);
            }

            // 2. Insert into frames_main
            // Query to update only informative columns and accumulate stock
            $sql = "INSERT INTO frames_main (
                ufc, brand, frame_code, frame_size, color_code, 
                buy_price, sell_price, price_secret_code, stock,
                material, lens_shape, structure, size_range, stock_age
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                buy_price = VALUES(buy_price),
                sell_price = VALUES(sell_price),
                price_secret_code = VALUES(price_secret_code),
                material = VALUES(material),
                lens_shape = VALUES(lens_shape),
                structure = VALUES(structure),
                size_range = VALUES(size_range),
                stock_age = VALUES(stock_age),
                stock = stock + VALUES(stock)"; // Old stock + incoming new stock

            $stmt = $conn->prepare($sql);
            
            while ($row = $result->fetch_assoc()) {

                // Bind params must remain complete according to the number of placeholders (?) in the VALUES section
                $stmt->bind_param("sssssddsisssss", 
                $row['ufc'], $row['brand'], $row['frame_code'], $row['frame_size'], $row['color_code'], 
                $row['buy_price'], $row['sell_price'], $row['price_secret_code'], $row['stock'],
                $row['material'], $row['lens_shape'], $row['structure'], $row['size_range'], $row['stock_age']
                );
                $stmt->execute();

                // 3. QR Code File Management
                $oldPath = "qrcodes/" . $row['ufc'] . ".png";
                $newPath = "main_qrcodes/" . $row['ufc'] . ".png";

                // Check if there are files in staging (8 new files)
                if (file_exists($oldPath)) {
                    // Move only if it doesn't exist in main (prevents unnecessary overwriting)
                    if (!file_exists($newPath)) {
                        rename($oldPath, $newPath);
                    } else {
                        // If a duplicate file suddenly appears in staging but already exists in main, delete the staging file
                        unlink($oldPath);
                    }
                }
            }

            // 4. Truncate/Empty the staging table
            $conn->query("DELETE FROM frame_staging");

            $conn->commit();
            $_SESSION['success_msg'] = "All data and QR Codes successfully moved to Main Database!";
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_msg'] = "System Error: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_msg'] = "Staging is already empty.";
    }

    header("Location: pending_records_frame.php");
    exit();
}