<?php
include 'db_config.php';
// Calculate how many customers have made a purchase (not '00')
$query = "SELECT COUNT(*) as total FROM customer_examinations WHERE invoice_number != '00'";
$result = mysqli_query($conn, $query);
$row = mysqli_fetch_assoc($result);
$next_num = (int)$row['total'] + 1;
echo str_pad($next_num, 3, '0', STR_PAD_LEFT);
?>