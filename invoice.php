<?php
session_start();
include 'db_config.php';
include 'config_helper.php';

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
$query = "SELECT * FROM customer_examinations WHERE invoice_number = '$invoice_num' LIMIT 1";
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
    </style>
</head>
<body style="background: var(--bg-color);">
    <div class="invoice-body">
        <div class="neumorph-card">
            <h2 style="color: var(--text-main); text-align: center; margin-bottom: 30px;">EXAMINATION DETAILS</h2>
            
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
            </div>

            <div style="margin-top: 40px; text-align: center;">
                <button onclick="window.print()" class="btn-action">PRINT INVOICE</button>
            </div>
        </div>
    </div>
</body>
</html>
