<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

    // ── Handle order_status update via AJAX ───────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        header('Content-Type: application/json');
        $id         = (int)$_POST['order_id'];
        $new_status = (int)$_POST['new_status'];
        if ($id > 0 && $new_status >= 1 && $new_status <= 5) {
            $sql = "UPDATE customer_orders SET order_status = $new_status WHERE id = $id";
            if (mysqli_query($conn, $sql)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        }
        exit();
    }

    // ── Fetch all active orders (status 1-4) ─────────────────────────
    // Try JOIN with customer_examinations first
    $sql = "
        SELECT 
            co.id,
            co.customer_number,
            co.invoice_number,
            co.frame_ufc,
            co.lens_name,
            co.customer_phone,
            co.customer_address,
            co.total_amount,
            co.amount_paid,
            co.order_date,
            co.due_date,
            co.order_status,
            ce.patient_name,
            ce.age,
            ce.gender,
            ce.date_of_birth,
            ce.examination_code
        FROM customer_orders co
        LEFT JOIN customer_examinations ce ON co.invoice_number = ce.invoice_number
        WHERE co.order_status BETWEEN 1 AND 4
        ORDER BY co.order_date DESC, co.id DESC
    ";
    $result = mysqli_query($conn, $sql);

    $orders = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $orders[] = $row;
        }
    }

    // Fallback: jika JOIN gagal (tabel tidak ada / error) ATAU hasil 0 baris,
    // coba query orders saja menggunakan customer_name dari customer_orders
    if (!$result || count($orders) === 0) {
        $sql_fallback = "
            SELECT 
                co.id,
                co.customer_number,
                co.invoice_number,
                co.frame_ufc,
                co.lens_name,
                co.customer_phone,
                co.customer_address,
                co.total_amount,
                co.amount_paid,
                co.order_date,
                co.due_date,
                co.order_status,
                co.customer_name  AS patient_name,
                NULL              AS age,
                NULL              AS gender,
                NULL              AS date_of_birth,
                NULL              AS examination_code
            FROM customer_orders co
            WHERE co.order_status BETWEEN 1 AND 4
            ORDER BY co.order_date DESC, co.id DESC
        ";
        $result_fallback = mysqli_query($conn, $sql_fallback);
        $orders = [];
        if ($result_fallback) {
            while ($row = mysqli_fetch_assoc($result_fallback)) {
                $orders[] = $row;
            }
        }
    }

    // ── Status label & color map ──────────────────────────────────────
    $statusMap = [
        1 => ['label' => 'Order Received',      'color' => '#ffaa00', 'icon' => '📋', 'bg' => 'rgba(255,170,0,0.12)'],
        2 => ['label' => 'Manufacturing',        'color' => '#00cfff', 'icon' => '⚙️',  'bg' => 'rgba(0,207,255,0.12)'],
        3 => ['label' => 'Out for Delivery',     'color' => '#aa88ff', 'icon' => '🚚', 'bg' => 'rgba(170,136,255,0.12)'],
        4 => ['label' => 'Awaiting Collection',  'color' => '#00ff88', 'icon' => '✅', 'bg' => 'rgba(0,255,136,0.12)'],
        5 => ['label' => 'Finished',             'color' => '#555',    'icon' => '🏁', 'bg' => 'rgba(85,85,85,0.12)'],
    ];

    // ── WA message generator ──────────────────────────────────────────
    // Generates a contextual WA message based on order_status, patient name, age, gender
    function buildWAMessage($order, $statusMap) {
        $name    = trim($order['patient_name'] ?? 'Customer');
        $age     = (int)($order['age'] ?? 0);
        $gender  = strtolower(trim($order['gender'] ?? ''));
        $status  = (int)$order['order_status'];
        $invNum  = $order['invoice_number'] ?? '';
        $custNum = $order['customer_number'] ?? '';
        $dueDate = $order['due_date'] ? date('d/m/Y', strtotime($order['due_date'])) : '-';

        // ── Greeting based on gender & age ──────────────────────────
        // Children: < 13 | Teens: 13-17 | Adults: 18+ 
        if ($age > 0 && $age < 13) {
            // Children — address the parents
            $sapaan    = 'Sir/Ma\'am';
            $gaya      = 'formal_ortu'; // use formal language, parent context
        } elseif ($age >= 13 && $age <= 17) {
            // Teens — casual but polite
            if ($gender === 'male' || $gender === 'laki-laki' || $gender === 'm') {
                $sapaan = 'Kak ' . explode(' ', $name)[0];
            } else {
                $sapaan = 'Kak ' . explode(' ', $name)[0];
            }
            $gaya = 'remaja';
        } else {
            // Adults / elderly — formal
            if ($gender === 'male' || $gender === 'laki-laki' || $gender === 'm') {
                $sapaan = 'Bapak ' . explode(' ', $name)[0];
            } else {
                $sapaan = 'Ibu ' . explode(' ', $name)[0];
            }
            $gaya = 'dewasa';
        }

        // ── Build message per status ─────────────────────────────────
        switch ($status) {
            case 1: // Order Received / Processing
                if ($gaya === 'formal_ortu') {
                    $msg = "Hello $sapaan 🙏\n\nWe from *Optik LZ* would like to inform you that the eyeglass order for your child with order number *$custNum* has been received and is currently being processed.\n\nInvoice Number: *$invNum*\nEstimated completion: *$dueDate*\n\nThank you for entrusting your child's vision needs to us. We will keep you updated on the progress. 🙏";
                } elseif ($gaya === 'remaja') {
                    $msg = "Hey $sapaan! 👋\n\nYour eyeglass order number *$custNum* has been received and is being processed!\n\nInvoice No: *$invNum*\nEstimated completion: *$dueDate*\n\nWe'll keep you posted on the progress. Stay tuned! 😊";
                } else {
                    $msg = "Hello $sapaan 🙏\n\nWe from *Optik LZ* would like to inform you that your eyeglass order number *$custNum* has been received and is currently being processed.\n\nInvoice Number: *$invNum*\nEstimated completion: *$dueDate*\n\nThank you for your trust. We will notify you of further progress shortly. 🙏";
                }
                break;

            case 2: // Manufacturing in Progress
                if ($gaya === 'formal_ortu') {
                    $msg = "Hello $sapaan 🙏\n\nWe would like to inform you that your child's eyeglasses (Order No: *$custNum*) are currently in the lens manufacturing process.\n\nWe ensure every detail is crafted with care. Estimated completion: *$dueDate*\n\nThank you for your patience 🙏";
                } elseif ($gaya === 'remaja') {
                    $msg = "Hey $sapaan! ⚙️\n\nOrder update — your eyeglasses (Order No: *$custNum*) are in the lens manufacturing process!\n\nEstimated completion: *$dueDate*\nHang tight, almost done! 😄";
                } else {
                    $msg = "Hello $sapaan 🙏\n\nWe would like to inform you that your eyeglasses (Order No: *$custNum*) are currently in the lens manufacturing process.\n\nEvery detail is being crafted with great care. Estimated completion: *$dueDate*\n\nThank you for your patience 🙏";
                }
                break;

            case 3: // Out for Delivery / Shipping
                if ($gaya === 'formal_ortu') {
                    $msg = "Hello $sapaan 🙏\n\nGreat news! Your child's eyeglasses (Order No: *$custNum*) have been completed and are on their way to our store.\n\nWe will contact you again once they arrive and are ready for pickup. 🚚";
                } elseif ($gaya === 'remaja') {
                    $msg = "Hey $sapaan! 🚚\n\nYay, your eyeglasses (Order No: *$custNum*) are done and on their way to our store!\n\nWe'll let you know once they're ready for pickup 😊";
                } else {
                    $msg = "Hello $sapaan 🙏\n\nGreat news! Your eyeglasses (Order No: *$custNum*) have been completed and are currently in transit to our store.\n\nWe will contact you once the eyeglasses arrive and are ready for pickup. 🚚";
                }
                break;

            case 4: // Completed / Awaiting Collection
                if ($gaya === 'formal_ortu') {
                    $msg = "Hello $sapaan 🙏\n\nAlhamdulillah, your child's eyeglasses (Order No: *$custNum*) are ready and available for pickup at our store!\n\nPlease bring invoice number *$invNum* when collecting.\n\nWe look forward to seeing you. Thank you 😊🙏";
                } elseif ($gaya === 'remaja') {
                    $msg = "Hey $sapaan! ✅\n\nYour eyeglasses are done and ready for pickup at our store!\n\nOrder No: *$custNum*\nDon't forget to bring invoice number *$invNum* when you come.\n\nSee you soon! 😄";
                } else {
                    $msg = "Hello $sapaan 🙏\n\nWe are pleased to inform you that your eyeglasses (Order No: *$custNum*) are ready and available for pickup at our store.\n\nPlease bring invoice number *$invNum* when collecting.\n\nWe look forward to your visit. Thank you 😊🙏";
                }
                break;

            default:
                $msg = "Hello, here is information regarding your order no. *$custNum*. Please contact us for further details.";
        }

        return $msg;
    }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
    <title>Completion Status — Active Orders</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* ── Page Layout ─────────────────────────────────────── */
        .cs-body {
            padding: 20px;
            max-width: 1100px;
            margin: auto;
        }

        .cs-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .cs-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: 1px;
        }

        .cs-subtitle {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 3px;
            letter-spacing: 0.5px;
        }

        /* ── Stat cards as filter buttons ───────────────────── */
        .cs-stat-card {
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }

        .cs-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 10px 10px 24px var(--shadow-dark), -10px -10px 24px var(--shadow-light);
            border-color: rgba(0,255,136,0.25);
        }

        .cs-stat-card.active {
            border-color: rgba(0,255,136,0.45);
            box-shadow: 0 0 14px rgba(0,255,136,0.12), 8px 8px 20px var(--shadow-dark), -8px -8px 20px var(--shadow-light);
        }

        /* ── Search bar ──────────────────────────────────────── */
        .cs-search-wrap {
            position: relative;
            max-width: 320px;
        }

        .cs-search {
            width: 100%;
            background: var(--bg-color);
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 20px;
            color: var(--text-main);
            font-size: 0.8rem;
            padding: 9px 16px 9px 38px;
            font-family: inherit;
            box-shadow: inset 4px 4px 8px var(--shadow-dark), inset -4px -4px 8px var(--shadow-light);
            outline: none;
            transition: border-color 0.2s;
        }

        .cs-search:focus {
            border-color: rgba(0,255,136,0.3);
        }

        .cs-search-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.9rem;
            pointer-events: none;
        }

        /* ── Order card ──────────────────────────────────────── */
        .cs-card {
            background: var(--bg-color);
            border-radius: 20px;
            padding: 20px 22px;
            margin-bottom: 14px;
            box-shadow: 8px 8px 20px var(--shadow-dark), -8px -8px 20px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.04);
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .cs-card:hover {
            transform: translateY(-1px);
            box-shadow: 10px 10px 24px var(--shadow-dark), -10px -10px 24px var(--shadow-light);
        }

        .cs-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .cs-patient-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .cs-patient-name {
            font-size: 1rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: 0.5px;
        }

        .cs-meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 4px;
        }

        .cs-chip {
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.6px;
            padding: 3px 10px;
            border-radius: 20px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            color: var(--text-muted);
        }

        .cs-chip.inv { color: #00cfff; border-color: rgba(0,207,255,0.25); background: rgba(0,207,255,0.07); }
        .cs-chip.cust { color: #aa88ff; border-color: rgba(170,136,255,0.25); background: rgba(170,136,255,0.07); }
        .cs-chip.age { color: #ffaa00; border-color: rgba(255,170,0,0.25); background: rgba(255,170,0,0.07); }

        /* ── Status badge ────────────────────────────────────── */
        .cs-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.8px;
            border: 1px solid;
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* ── Order details grid ──────────────────────────────── */
        .cs-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid rgba(255,255,255,0.05);
        }

        .cs-detail-item {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .cs-detail-label {
            font-size: 0.62rem;
            color: var(--text-muted);
            letter-spacing: 0.7px;
            text-transform: uppercase;
        }

        .cs-detail-value {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .cs-detail-value.price {
            color: #ffaa00;
            font-family: monospace;
        }

        .cs-detail-value.due { color: #ff6b6b; }
        .cs-detail-value.due.ok { color: #00ff88; }

        /* ── Bottom action row ───────────────────────────────── */
        .cs-card-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 16px;
            flex-wrap: wrap;
            gap: 10px;
        }

        /* ── Status stepper ──────────────────────────────────── */
        .cs-stepper {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .cs-step {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            font-size: 0.6rem;
            font-weight: 800;
            cursor: pointer;
            border: 2px solid rgba(255,255,255,0.08);
            background: var(--bg-color);
            color: var(--text-muted);
            box-shadow: 3px 3px 6px var(--shadow-dark), -3px -3px 6px var(--shadow-light);
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .cs-step.done {
            background: rgba(0,255,136,0.1);
            border-color: rgba(0,255,136,0.4);
            color: #00ff88;
        }

        .cs-step.current {
            background: rgba(0,255,136,0.18);
            border-color: #00ff88;
            color: #00ff88;
            box-shadow: 0 0 10px rgba(0,255,136,0.3), 3px 3px 6px var(--shadow-dark), -3px -3px 6px var(--shadow-light);
        }

        .cs-step:hover:not(.current) {
            border-color: rgba(0,255,136,0.3);
            color: #aaa;
        }

        .cs-step-line {
            width: 12px;
            height: 2px;
            background: rgba(255,255,255,0.07);
            border-radius: 2px;
        }

        .cs-step-line.done-line {
            background: rgba(0,255,136,0.3);
        }

        /* ── WA Send button ──────────────────────────────────── */
        .cs-wa-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: rgba(37,211,102,0.12);
            border: 1px solid rgba(37,211,102,0.35);
            border-radius: 20px;
            color: #25d366;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.7px;
            padding: 7px 16px;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
            text-decoration: none;
        }

        .cs-wa-btn:hover {
            background: rgba(37,211,102,0.2);
            box-shadow: 0 0 12px rgba(37,211,102,0.2);
        }

        .cs-wa-btn svg {
            width: 15px; height: 15px; fill: #25d366;
        }

        /* ── Preview message modal ───────────────────────────── */
        .cs-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            z-index: 9000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .cs-modal-overlay.open {
            display: flex;
        }

        .cs-modal {
            background: var(--bg-color);
            border-radius: 24px;
            padding: 28px;
            max-width: 480px;
            width: 100%;
            box-shadow: 20px 20px 60px var(--shadow-dark), -20px -20px 60px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.07);
        }

        .cs-modal-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .cs-modal-sub {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-bottom: 16px;
        }

        .cs-msg-preview {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            padding: 14px 16px;
            font-size: 0.78rem;
            color: var(--text-main);
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 280px;
            overflow-y: auto;
            font-family: inherit;
        }

        .cs-modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .cs-btn {
            flex: 1;
            padding: 10px 16px;
            border-radius: 14px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.6px;
            cursor: pointer;
            font-family: inherit;
            border: 1px solid;
            transition: all 0.2s;
        }

        .cs-btn.cancel {
            background: var(--bg-color);
            border-color: rgba(255,255,255,0.1);
            color: var(--text-muted);
            box-shadow: 4px 4px 8px var(--shadow-dark), -4px -4px 8px var(--shadow-light);
        }

        .cs-btn.cancel:hover { color: var(--text-main); }

        .cs-btn.send {
            background: rgba(37,211,102,0.12);
            border-color: rgba(37,211,102,0.4);
            color: #25d366;
        }

        .cs-btn.send:hover {
            background: rgba(37,211,102,0.22);
            box-shadow: 0 0 14px rgba(37,211,102,0.25);
        }

        /* ── Empty state ─────────────────────────────────────── */
        .cs-empty {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .cs-empty-icon { font-size: 2.5rem; margin-bottom: 12px; }
        .cs-empty-title { font-size: 1rem; font-weight: 700; color: var(--text-main); }
        .cs-empty-sub { font-size: 0.75rem; margin-top: 5px; }

        /* ── Toast notification ──────────────────────────────── */
        #cs-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: var(--bg-color);
            border: 1px solid rgba(0,255,136,0.35);
            border-radius: 14px;
            color: #00ff88;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 12px 20px;
            box-shadow: 0 0 20px rgba(0,255,136,0.15);
            z-index: 9999;
            opacity: 0;
            transform: translateY(12px);
            transition: all 0.3s;
            pointer-events: none;
        }

        #cs-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        /* ── Summary stats ───────────────────────────────────── */
        .cs-stats-row {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 20px;
        }

        .cs-stats-top {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .cs-stats-bottom {
            display: flex;
            justify-content: center;
        }

        .cs-stat-card {
            flex: 1;
            min-width: 110px;
            background: var(--bg-color);
            border-radius: 16px;
            padding: 14px 16px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.04);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .cs-stats-bottom .cs-stat-card {
            flex: 0 0 auto;
            min-width: 160px;
            max-width: 220px;
            align-items: center;
            text-align: center;
        }

        .cs-stat-num {
            font-size: 1.6rem;
            font-weight: 900;
            line-height: 1;
        }

        .cs-stat-label {
            font-size: 0.62rem;
            color: var(--text-muted);
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }

        @media (max-width: 600px) {
            .cs-body { padding: 14px; }
            .cs-details-grid { grid-template-columns: repeat(2, 1fr); }
            .cs-card-actions { flex-direction: column; align-items: flex-start; }
            .cs-stepper { flex-wrap: wrap; }
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

            <div class="main-card" style="margin-left: auto; margin-right: auto; width: 100%;">
    <div class="cs-body">

        <!-- ── Page Header ─────────────────────────────────────── -->
        <div class="cs-header">
            <div>
                <div class="cs-title">📦 Order Tracking</div>
                <div class="cs-subtitle">ACTIVE ORDERS — STATUS 1 TO 4</div>
            </div>
            <div class="cs-search-wrap">
                <span class="cs-search-icon">🔍</span>
                <input type="text" class="cs-search" id="cs-search-input"
                       placeholder="Cari nama, invoice, no. HP…"
                       oninput="csFilterCards()">
            </div>
        </div>

        <!-- ── Summary Stats ───────────────────────────────────── -->
        <?php
            $counts = [1=>0, 2=>0, 3=>0, 4=>0];
            foreach ($orders as $o) { $counts[(int)$o['order_status']]++; }
        ?>
        <div class="cs-stats-row">
            <!-- Top row: Order Received → Awaiting Collection -->
            <div class="cs-stats-top">
                <?php foreach ($statusMap as $s => $sm): if ($s === 5) continue; ?>
                <div class="cs-stat-card" data-filter="<?php echo $s; ?>" onclick="csSetFilter('<?php echo $s; ?>', this)" title="Filter: <?php echo $sm['label']; ?>">
                    <div class="cs-stat-num" style="color:<?php echo $sm['color']; ?>"><?php echo $counts[$s]; ?></div>
                    <div class="cs-stat-label"><?php echo $sm['icon'] . ' ' . $sm['label']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <!-- Bottom row: Total Active centered -->
            <div class="cs-stats-bottom">
                <div class="cs-stat-card active" data-filter="all" onclick="csSetFilter('all', this)" title="Show all orders">
                    <div class="cs-stat-num" style="color:#fff;"><?php echo count($orders); ?></div>
                    <div class="cs-stat-label">Total Active</div>
                </div>
            </div>
        </div>

        <!-- ── Order Cards ─────────────────────────────────────── -->
        <?php if (empty($orders)): ?>
        <div class="cs-empty">
            <div class="cs-empty-icon">🎉</div>
            <div class="cs-empty-title">No active orders</div>
            <div class="cs-empty-sub">All orders have been completed or no orders have been placed yet.</div>
        </div>
        <?php else: ?>

        <div id="cs-cards-container">
        <?php foreach ($orders as $o):
            $st       = (int)$o['order_status'];
            $sm       = $statusMap[$st] ?? $statusMap[1];
            $name     = trim($o['patient_name'] ?? '—');
            $age      = (int)($o['age'] ?? 0);
            $gender   = strtolower(trim($o['gender'] ?? ''));
            $genderIcon = ($gender === 'male' || $gender === 'laki-laki' || $gender === 'm') ? '👨' : '👩';
            $phone    = $o['customer_phone'] ?? '';
            $lensName = $o['lens_name'] ?? '—';
            $frameUfc = $o['frame_ufc'] ?? '—';
            $totalAmt = (int)$o['total_amount'];
            $paidAmt  = (int)$o['amount_paid'];
            $remaining = $totalAmt - $paidAmt;
            $orderDate = $o['order_date'] ? date('d/m/Y', strtotime($o['order_date'])) : '—';
            $dueDate   = $o['due_date']   ? date('d/m/Y', strtotime($o['due_date']))   : '—';
            $isDuePast = $o['due_date'] && strtotime($o['due_date']) < time();

            // Build WA message
            $waMsg     = buildWAMessage($o, $statusMap);
            $waMsgEnc  = urlencode($waMsg);
            $waPhone   = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($waPhone) > 0 && $waPhone[0] === '0') {
                $waPhone = '62' . substr($waPhone, 1);
            }
            $waUrl     = 'https://wa.me/' . $waPhone . '?text=' . $waMsgEnc;
        ?>
        <div class="cs-card"
             data-status="<?php echo $st; ?>"
             data-name="<?php echo htmlspecialchars(strtolower($name)); ?>"
             data-inv="<?php echo htmlspecialchars(strtolower($o['invoice_number'] ?? '')); ?>"
             data-phone="<?php echo htmlspecialchars($phone); ?>"
             data-custnum="<?php echo htmlspecialchars(strtolower($o['customer_number'] ?? '')); ?>">

            <!-- Top row: patient info + status badge -->
            <div class="cs-card-top">
                <div class="cs-patient-info">
                    <div class="cs-patient-name"><?php echo htmlspecialchars($name); ?> <?php echo $genderIcon; ?></div>
                    <div class="cs-meta-row">
                        <span class="cs-chip inv">INV: <?php echo htmlspecialchars($o['invoice_number'] ?? '—'); ?></span>
                        <span class="cs-chip cust"><?php echo htmlspecialchars($o['customer_number'] ?? '—'); ?></span>
                        <?php if ($age > 0): ?>
                        <span class="cs-chip age"><?php echo $age; ?> yrs</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="cs-status-badge"
                     style="color:<?php echo $sm['color']; ?>;border-color:<?php echo $sm['color']; ?>33;background:<?php echo $sm['bg']; ?>">
                    <?php echo $sm['icon']; ?>&nbsp;<?php echo strtoupper($sm['label']); ?>
                </div>
            </div>

            <!-- Details grid -->
            <div class="cs-details-grid">
                <div class="cs-detail-item">
                    <span class="cs-detail-label">Lens</span>
                    <span class="cs-detail-value"><?php echo htmlspecialchars($lensName); ?></span>
                </div>
                <div class="cs-detail-item">
                    <span class="cs-detail-label">Frame (UFC)</span>
                    <span class="cs-detail-value"><?php echo htmlspecialchars($frameUfc); ?></span>
                </div>
                <div class="cs-detail-item">
                    <span class="cs-detail-label">Order Date</span>
                    <span class="cs-detail-value"><?php echo $orderDate; ?></span>
                </div>
                <div class="cs-detail-item">
                    <span class="cs-detail-label">Est. Completion</span>
                    <span class="cs-detail-value due <?php echo (!$isDuePast ? 'ok' : ''); ?>">
                        <?php echo $dueDate; ?><?php echo ($isDuePast && $dueDate !== '—') ? ' ⚠' : ''; ?>
                    </span>
                </div>
                <div class="cs-detail-item">
                    <span class="cs-detail-label">Total</span>
                    <span class="cs-detail-value price">Rp <?php echo number_format($totalAmt, 0, ',', '.'); ?></span>
                </div>
                <div class="cs-detail-item">
                    <span class="cs-detail-label">Remaining Balance</span>
                    <span class="cs-detail-value price" style="<?php echo ($remaining > 0 ? 'color:#ff6b6b' : 'color:#00ff88'); ?>">
                        <?php echo $remaining > 0 ? 'Rp ' . number_format($remaining, 0, ',', '.') : 'PAID ✓'; ?>
                    </span>
                </div>
                <div class="cs-detail-item">
                    <span class="cs-detail-label">Phone No.</span>
                    <span class="cs-detail-value"><?php echo htmlspecialchars($phone ?: '—'); ?></span>
                </div>
                <div class="cs-detail-item">
                    <span class="cs-detail-label">Address</span>
                    <span class="cs-detail-value" style="font-size:0.75rem;"><?php echo htmlspecialchars($o['customer_address'] ?? '—'); ?></span>
                </div>
            </div>

            <!-- Action row: stepper + WA button -->
            <div class="cs-card-actions">

                <!-- Status stepper: click to advance/set status -->
                <div class="cs-stepper" title="Click a number to change status">
                    <?php foreach ([1,2,3,4,5] as $step):
                        $isDone    = ($step < $st);
                        $isCurrent = ($step === $st);
                        $cls = $isDone ? 'done' : ($isCurrent ? 'current' : '');
                        $stepSm = $statusMap[$step] ?? [];
                        $tt = htmlspecialchars($stepSm['label'] ?? 'Step ' . $step);
                    ?>
                    <?php if ($step > 1): ?>
                    <div class="cs-step-line <?php echo ($step <= $st ? 'done-line' : ''); ?>"></div>
                    <?php endif; ?>
                    <div class="cs-step <?php echo $cls; ?>"
                         title="Set: <?php echo $tt; ?>"
                         onclick="csChangeStatus(<?php echo $o['id']; ?>, <?php echo $step; ?>, this)">
                        <?php echo $step; ?>
                    </div>
                    <?php endforeach; ?>
                    <span style="font-size:0.65rem;color:var(--text-muted);margin-left:8px;letter-spacing:0.5px;">STATUS</span>
                </div>

                <!-- WA Button -->
                <?php if (!empty($phone)): ?>
                <button class="cs-wa-btn"
                        onclick="csOpenWAModal(<?php echo htmlspecialchars(json_encode($waMsg)); ?>, <?php echo htmlspecialchars(json_encode($waUrl)); ?>, <?php echo htmlspecialchars(json_encode($name)); ?>)">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                    SEND WA
                </button>
                <?php else: ?>
                <span style="font-size:0.68rem;color:#555;font-style:italic;">Phone number unavailable</span>
                <?php endif; ?>

            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <?php endif; ?>

    </div><!-- /cs-body -->

            </div><!-- /main-card -->

            <div class="btn-group">
                <button type="button" class="back-main" onclick="window.history.back()">BACK TO PREVIOUS PAGE</button>
            </div>

            <footer class="footer-container">
                <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
            </footer>

        </div><!-- /content-area -->
    </div><!-- /main-wrapper -->

    <!-- ── WA Preview Modal ─────────────────────────────────────── -->
    <div class="cs-modal-overlay" id="cs-modal-overlay">
        <div class="cs-modal">
            <div class="cs-modal-title">📱 WhatsApp Message Preview</div>
            <div class="cs-modal-sub" id="cs-modal-sub">To: —</div>
            <div class="cs-msg-preview" id="cs-modal-msg"></div>
            <div class="cs-modal-actions">
                <button class="cs-btn cancel" onclick="csCloseModal()">Cancel</button>
                <a class="cs-btn send" id="cs-modal-wa-link" href="#" target="_blank" onclick="csCloseModal()">
                    Send via WhatsApp 📲
                </a>
            </div>
        </div>
    </div>

    <!-- ── Toast ─────────────────────────────────────────────────── -->
    <div id="cs-toast"></div>

    <script>
    // ── Filter state ──────────────────────────────────────────────
    var _csActiveFilter = 'all';

    function csSetFilter(val, btn) {
        _csActiveFilter = val;
        document.querySelectorAll('.cs-stat-card').forEach(function(b) {
            b.classList.remove('active');
        });
        btn.classList.add('active');
        csFilterCards();
    }

    function csFilterCards() {
        var q      = (document.getElementById('cs-search-input').value || '').toLowerCase().trim();
        var filter = _csActiveFilter;

        document.querySelectorAll('#cs-cards-container .cs-card').forEach(function(card) {
            var status  = card.getAttribute('data-status');
            var name    = card.getAttribute('data-name') || '';
            var inv     = card.getAttribute('data-inv') || '';
            var phone   = card.getAttribute('data-phone') || '';
            var custnum = card.getAttribute('data-custnum') || '';

            var matchFilter = (filter === 'all' || status === filter);
            var matchSearch = !q ||
                name.indexOf(q) !== -1 ||
                inv.indexOf(q) !== -1 ||
                phone.indexOf(q) !== -1 ||
                custnum.indexOf(q) !== -1;

            card.style.display = (matchFilter && matchSearch) ? '' : 'none';
        });
    }

    // ── Change order status via AJAX ─────────────────────────────
    function csChangeStatus(orderId, newStatus, stepEl) {
        var fd = new FormData();
        fd.append('action',     'update_status');
        fd.append('order_id',   orderId);
        fd.append('new_status', newStatus);

        fetch('completion_status.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    // Update the card's stepper visually
                    var card = stepEl.closest('.cs-card');
                    card.setAttribute('data-status', newStatus);

                    // Update all steps in this stepper
                    card.querySelectorAll('.cs-step').forEach(function(s, idx) {
                        var stepNum = idx + 1;
                        s.className = 'cs-step';
                        if (stepNum < newStatus)       s.classList.add('done');
                        else if (stepNum === newStatus) s.classList.add('current');
                    });

                    // Update step lines
                    card.querySelectorAll('.cs-step-line').forEach(function(ln, idx) {
                        ln.className = 'cs-step-line';
                        if ((idx + 2) <= newStatus) ln.classList.add('done-line');
                    });

                    // Update status badge
                    var statusMap = {
                        1: { label: 'ORDER RECEIVED',     color: '#ffaa00', icon: '📋', bg: 'rgba(255,170,0,0.12)' },
                        2: { label: 'MANUFACTURING',       color: '#00cfff', icon: '⚙️',  bg: 'rgba(0,207,255,0.12)' },
                        3: { label: 'OUT FOR DELIVERY',    color: '#aa88ff', icon: '🚚', bg: 'rgba(170,136,255,0.12)' },
                        4: { label: 'AWAITING COLLECTION', color: '#00ff88', icon: '✅', bg: 'rgba(0,255,136,0.12)' },
                        5: { label: 'FINISHED',            color: '#555',    icon: '🏁', bg: 'rgba(85,85,85,0.12)' },
                    };
                    var sm = statusMap[newStatus] || statusMap[1];
                    var badge = card.querySelector('.cs-status-badge');
                    if (badge) {
                        badge.style.color       = sm.color;
                        badge.style.borderColor = sm.color + '33';
                        badge.style.background  = sm.bg;
                        badge.innerHTML         = sm.icon + '&nbsp;' + sm.label;
                    }

                    // If status is now 5 (Finish), hide card after short delay
                    if (newStatus === 5) {
                        setTimeout(function() {
                            card.style.transition = 'opacity 0.5s, transform 0.5s';
                            card.style.opacity    = '0';
                            card.style.transform  = 'scale(0.95)';
                            setTimeout(function() { card.remove(); }, 500);
                        }, 800);
                    }

                    csShowToast('✅ Status updated');
                } else {
                    csShowToast('❌ Failed: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(function() {
                csShowToast('❌ Connection error');
            });
    }

    // ── WA Modal ──────────────────────────────────────────────────
    function csOpenWAModal(msg, waUrl, name) {
        document.getElementById('cs-modal-msg').textContent = msg;
        document.getElementById('cs-modal-sub').textContent = 'To: ' + name;
        document.getElementById('cs-modal-wa-link').href    = waUrl;
        document.getElementById('cs-modal-overlay').classList.add('open');
    }

    function csCloseModal() {
        document.getElementById('cs-modal-overlay').classList.remove('open');
    }

    // Close modal on overlay click
    document.getElementById('cs-modal-overlay').addEventListener('click', function(e) {
        if (e.target === this) csCloseModal();
    });

    // ── Toast ─────────────────────────────────────────────────────
    var _toastTimer = null;
    function csShowToast(msg) {
        var el = document.getElementById('cs-toast');
        el.textContent = msg;
        el.classList.add('show');
        clearTimeout(_toastTimer);
        _toastTimer = setTimeout(function() { el.classList.remove('show'); }, 2800);
    }
    </script>
</body>
</html>