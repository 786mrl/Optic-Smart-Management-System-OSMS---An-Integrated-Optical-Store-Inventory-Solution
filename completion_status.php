<?php
    session_start();
    include 'db_config.php';
include 'activity_helper.php';
    include 'config_helper.php';

    // ── Build daftar nama lensa STOCK dari lense_prices.json ─────────────
    // Format nama di DB: "CATEGORY / TYPE" (contoh: "SINGLE VISION / BLUERAY")
    // Semua nama di bawah key "stock" dianggap lensa stock
    $stockLensNames = [];
    $lensJsonPath   = __DIR__ . '/lense_prices.json';
    if (file_exists($lensJsonPath)) {
        $lensData = json_decode(file_get_contents($lensJsonPath), true);
        if (!empty($lensData['stock']) && is_array($lensData['stock'])) {
            foreach ($lensData['stock'] as $category => $types) {
                if (is_array($types)) {
                    foreach (array_keys($types) as $type) {
                        // Simpan dalam uppercase untuk pencocokan case-insensitive
                        $stockLensNames[] = strtoupper(trim($category) . ' / ' . trim($type));
                    }
                }
            }
        }
    }

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

    // ── Handle order_status update via AJAX ───────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        header('Content-Type: application/json');
        $id         = (int)$_POST['order_id'];
        $new_status = (int)$_POST['new_status'];
        if ($id > 0 && $new_status >= 1 && $new_status <= 5) {
            $sql = "UPDATE customer_orders SET order_status = $new_status WHERE id = $id";
            if ($conn->query($sql)) {
                log_activity($conn, 'customer_orders', (string)$id, 'UPDATE', $_SESSION['username'] ?? 'system');
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        }
        exit();
    }

    // ── Fetch all active orders (status 1-4) ─────────────────────────
    // JOIN dengan customer_examinations jika invoice_number valid (bukan '00')
    // Kolom nama pasien ada di customer_examinations.customer_name
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
            ce.customer_name  AS patient_name,
            ce.age,
            ce.gender,
            NULL              AS date_of_birth,
            ce.examination_code
        FROM customer_orders co
        LEFT JOIN customer_examinations ce
            ON co.invoice_number = ce.invoice_number
            AND co.invoice_number != '00'
        WHERE co.order_status BETWEEN 1 AND 4
        ORDER BY co.order_date DESC, co.id DESC
    ";
    $result = $conn->query($sql);

    $orders = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
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
            // Remaja — formal
            if ($gender === 'male' || $gender === 'laki-laki' || $gender === 'm') {
                $sapaan = 'Saudara ' . explode(' ', $name)[0];
            } else {
                $sapaan = 'Saudari ' . explode(' ', $name)[0];
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

        // ── Salam pembuka (selalu di awal) ───────────────────────────
        $salam = "السَّلَامُ عَلَيْكُمْ وَرَحْمَةُ اللهِ وَبَرَكَاتُهُ\n\n";

        // ── Build message per status ─────────────────────────────────
        switch ($status) {
            case 1: // Pesanan Diterima / Sedang Diproses
                if ($gaya === 'formal_ortu') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami dari LenZa Optic ingin menginformasikan bahwa pesanan kacamata untuk putra/putri Anda dengan nomor order *$custNum* telah kami terima dan sedang dalam proses pengerjaan.\n\nNomor Invoice: *$invNum*\nEstimasi selesai: *$dueDate*\n\nTerima kasih telah mempercayakan kebutuhan penglihatan buah hati Anda kepada kami. Kami akan terus memberikan informasi perkembangannya. 🙏";
                } elseif ($gaya === 'remaja') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami dari LenZa Optic ingin menginformasikan bahwa pesanan kacamata dengan nomor order *$custNum* telah kami terima dan sedang dalam proses pengerjaan.\n\nNomor Invoice: *$invNum*\nEstimasi selesai: *$dueDate*\n\nTerima kasih atas kepercayaan Anda. Kami akan segera menginformasikan perkembangan selanjutnya. 🙏";
                } else {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami dari LenZa Optic ingin menginformasikan bahwa pesanan kacamata Anda dengan nomor order *$custNum* telah kami terima dan sedang dalam proses pengerjaan.\n\nNomor Invoice: *$invNum*\nEstimasi selesai: *$dueDate*\n\nTerima kasih atas kepercayaan Anda. Kami akan segera menginformasikan perkembangan selanjutnya. 🙏";
                }
                break;

            case 2: // Sedang Proses Produksi Lensa
                if ($gaya === 'formal_ortu') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami ingin menginformasikan bahwa kacamata putra/putri Anda (No. Order: *$custNum*) saat ini sedang dalam proses pembuatan lensa.\n\nSetiap detail dikerjakan dengan teliti dan penuh perhatian. Estimasi selesai: *$dueDate*\n\nTerima kasih atas kesabaran Anda. 🙏";
                } elseif ($gaya === 'remaja') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami ingin menginformasikan bahwa kacamata Anda (No. Order: *$custNum*) saat ini sedang dalam proses pembuatan lensa.\n\nSetiap detail dikerjakan dengan penuh ketelitian. Estimasi selesai: *$dueDate*\n\nTerima kasih atas kesabaran Anda. 🙏";
                } else {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami ingin menginformasikan bahwa kacamata Anda (No. Order: *$custNum*) saat ini sedang dalam proses pembuatan lensa.\n\nSetiap detail dikerjakan dengan penuh ketelitian. Estimasi selesai: *$dueDate*\n\nTerima kasih atas kesabaran Anda. 🙏";
                }
                break;

            case 3: // Dalam Pengiriman ke Toko
                if ($gaya === 'formal_ortu') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami ingin menyampaikan kabar baik bahwa kacamata putra/putri Anda (No. Order: *$custNum*) telah selesai dibuat dan saat ini sedang dalam perjalanan menuju toko kami.\n\nKami akan menghubungi kembali begitu kacamata tiba dan siap untuk diambil. 🚚";
                } elseif ($gaya === 'remaja') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami ingin menyampaikan kabar baik bahwa kacamata Anda (No. Order: *$custNum*) telah selesai dibuat dan saat ini sedang dalam perjalanan menuju toko kami.\n\nKami akan menghubungi Anda kembali begitu kacamata tiba dan siap untuk diambil. 🚚";
                } else {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nKami ingin menyampaikan kabar baik bahwa kacamata Anda (No. Order: *$custNum*) telah selesai dibuat dan saat ini sedang dalam perjalanan menuju toko kami.\n\nKami akan menghubungi Anda kembali begitu kacamata tiba dan siap untuk diambil. 🚚";
                }
                break;

            case 4: // Siap Diambil
                if ($gaya === 'formal_ortu') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nAlhamdulillah, kami dengan senang hati menginformasikan bahwa kacamata putra/putri Anda (No. Order: *$custNum*) telah selesai dan siap untuk diambil di toko kami.\n\nMohon membawa nomor invoice *$invNum* saat pengambilan.\n\nKami tunggu kedatangan Anda. Terima kasih 😊🙏";
                } elseif ($gaya === 'remaja') {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nAlhamdulillah, kami dengan senang hati menginformasikan bahwa kacamata Anda (No. Order: *$custNum*) telah selesai dan siap untuk diambil di toko kami.\n\nMohon membawa nomor invoice *$invNum* saat pengambilan.\n\nKami tunggu kedatangan Anda. Terima kasih 😊🙏";
                } else {
                    $msg = $salam . "Kepada $sapaan 🙏\n\nAlhamdulillah, kami dengan senang hati menginformasikan bahwa kacamata Anda (No. Order: *$custNum*) telah selesai dan siap untuk diambil di toko kami.\n\nMohon membawa nomor invoice *$invNum* saat pengambilan.\n\nKami tunggu kedatangan Anda. Terima kasih 😊🙏";
                }
                break;

            default:
                $msg = $salam . "Kepada pelanggan,\n\nBerikut informasi mengenai pesanan Anda dengan no. order *$custNum*. Silakan hubungi kami untuk informasi lebih lanjut.";
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

        .cs-step.step-disabled {
            cursor: not-allowed;
            opacity: 0.25;
            border-style: dashed;
            color: #555;
            font-size: 0.75rem;
        }

        .cs-step.step-disabled:hover {
            border-color: rgba(255,255,255,0.08);
            color: #555;
        }

        .cs-step-line.step-line-disabled {
            opacity: 0.2;
            border-top: 2px dashed rgba(255,255,255,0.2);
            background: transparent;
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
            height: 260px;
            overflow-y: auto;
            font-family: inherit;
            resize: none;
            width: 100%;
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.2s;
        }

        .cs-msg-preview:focus {
            border-color: rgba(0,255,136,0.3);
        }

        /* ── Muslim/Non-Muslim toggle ────────────────────────── */
        .cs-religion-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
        }

        .cs-religion-toggle-label {
            font-size: 0.68rem;
            color: var(--text-muted);
            letter-spacing: 0.5px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .cs-toggle-group {
            display: flex;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 20px;
            overflow: hidden;
            padding: 3px;
            gap: 3px;
        }

        .cs-toggle-btn {
            padding: 5px 14px;
            border-radius: 16px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            cursor: pointer;
            border: none;
            background: transparent;
            color: var(--text-muted);
            font-family: inherit;
            transition: all 0.2s;
        }

        .cs-toggle-btn.active {
            background: rgba(0,255,136,0.15);
            color: #00ff88;
            box-shadow: 0 0 8px rgba(0,255,136,0.15);
        }

        .cs-toggle-btn:hover:not(.active) {
            color: var(--text-main);
            background: rgba(255,255,255,0.05);
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

        /* ── Collapsible card body ───────────────────────────── */
        .cs-card-header {
            cursor: pointer;
            user-select: none;
        }

        .cs-card-header:hover .cs-patient-name {
            color: #00ff88;
            transition: color 0.2s;
        }

        .cs-card-body {
            overflow: hidden;
            max-height: 0;
            transition: max-height 0.35s ease, opacity 0.3s ease;
            opacity: 0;
        }

        .cs-card.expanded .cs-card-body {
            max-height: 900px;
            opacity: 1;
        }

        .cs-chevron {
            font-size: 0.7rem;
            color: var(--text-muted);
            transition: transform 0.3s;
            flex-shrink: 0;
        }

        .cs-card.expanded .cs-chevron {
            transform: rotate(180deg);
        }


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
            gap: 12px;
            flex-wrap: wrap;
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

        /* ── Due Soon / Overdue filter card ─────────────────── */
        .cs-stat-card.due-alert {
            border-color: rgba(255,107,107,0.2);
        }
        .cs-stat-card.due-alert:hover {
            border-color: rgba(255,107,107,0.45);
            box-shadow: 10px 10px 24px var(--shadow-dark), -10px -10px 24px var(--shadow-light);
        }
        .cs-stat-card.due-alert.active {
            border-color: rgba(255,107,107,0.55);
            box-shadow: 0 0 14px rgba(255,107,107,0.18), 8px 8px 20px var(--shadow-dark), -8px -8px 20px var(--shadow-light);
        }
        .cs-due-badge {
            display: inline-block;
            font-size: 0.58rem;
            font-weight: 800;
            letter-spacing: 0.6px;
            padding: 2px 7px;
            border-radius: 10px;
            margin-left: 5px;
            vertical-align: middle;
        }
        .cs-due-badge.overdue {
            background: rgba(255,107,107,0.15);
            color: #ff6b6b;
            border: 1px solid rgba(255,107,107,0.3);
        }
        .cs-due-badge.soon {
            background: rgba(255,170,0,0.15);
            color: #ffaa00;
            border: 1px solid rgba(255,170,0,0.3);
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
            /* ── Layout ── */
            .cs-body { padding: 10px; }

            /* ── Header: stack title + search ── */
            .cs-header {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                margin-bottom: 16px;
            }
            .cs-title { font-size: 1.1rem; }
            .cs-search-wrap { max-width: 100%; }

            /* ── Stat cards: 2×2 grid on mobile ── */
            .cs-stats-top {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }
            .cs-stat-card { min-width: 0; padding: 10px 12px; }
            .cs-stat-num { font-size: 1.3rem; }
            .cs-stat-label { font-size: 0.58rem; }
            .cs-stats-bottom .cs-stat-card { min-width: 0; max-width: 100%; width: 100%; }

            /* ── Order card ── */
            .cs-card { padding: 14px 14px; border-radius: 16px; }

            /* ── Top row (header): name kiri, badge+chevron kanan — tetap row di mobile ── */
            .cs-card-header.cs-card-top {
                flex-direction: row;
                align-items: center;
                gap: 10px;
            }
            .cs-card-header .cs-patient-info { flex: 1; min-width: 0; }
            .cs-card-header .cs-patient-name { font-size: 0.9rem; }
            .cs-status-badge { align-self: center; font-size: 0.6rem; padding: 4px 9px; white-space: nowrap; }

            /* ── Chips: wrap & potong teks panjang ── */
            .cs-chip { font-size: 0.6rem; padding: 2px 8px; max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

            /* ── Card body: beri jarak dari header ── */
            .cs-card.expanded .cs-card-body { padding-top: 12px; }

            /* ── Details grid: 2 columns ── */
            .cs-details-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
                margin-top: 10px;
                padding-top: 10px;
            }
            .cs-detail-label { font-size: 0.58rem; }
            .cs-detail-value { font-size: 0.76rem; }

            /* ── Action row: stepper full-width, WA button full-width ── */
            .cs-card-actions {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                margin-top: 12px;
            }
            .cs-stepper { justify-content: space-between; }
            .cs-step { width: 32px; height: 32px; font-size: 0.65rem; }
            .cs-step-line { flex: 1; }

            /* ── WA button: full width ── */
            .cs-wa-btn {
                width: 100%;
                justify-content: center;
                padding: 10px 16px;
                font-size: 0.75rem;
            }

            /* ── Modal: bottom sheet style ── */
            .cs-modal-overlay {
                align-items: flex-end;
                padding: 0;
            }
            .cs-modal {
                border-radius: 24px 24px 0 0;
                padding: 20px 18px 30px;
                max-width: 100%;
                max-height: 92vh;
                overflow-y: auto;
            }
            .cs-msg-preview { height: 200px; font-size: 0.75rem; }

            /* ── Religion toggle wraps nicely ── */
            .cs-religion-toggle { flex-wrap: wrap; }

            /* ── Modal buttons: stack ── */
            .cs-modal-actions { flex-direction: column; }
            .cs-btn { flex: none; width: 100%; text-align: center; padding: 12px; }

            /* ── Toast: full width bottom ── */
            #cs-toast {
                left: 12px;
                right: 12px;
                bottom: 16px;
                text-align: center;
            }

            /* ── Back button: full width ── */
            .btn-group { padding: 0 10px; }
            .btn-group .back-main {
                width: 100%;
                box-sizing: border-box;
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
                       placeholder="Find by name, invoice, phone number…"
                       oninput="csFilterCards()">
            </div>
        </div>

        <!-- ── Summary Stats ───────────────────────────────────── -->
        <?php
            $counts = [1=>0, 2=>0, 3=>0, 4=>0];
            foreach ($orders as $o) { $counts[(int)$o['order_status']]++; }

            // Hitung due soon (≤ 2 hari ke depan) dan overdue (sudah lewat)
            $countOverdue  = 0;
            $countDueSoon  = 0;
            $now = time();
            foreach ($orders as $o) {
                if (empty($o['due_date'])) continue;
                if ((int)$o['order_status'] === 4) continue; // status 4 dikecualikan
                $dueTs = strtotime($o['due_date']);
                $diff  = $dueTs - strtotime(date('Y-m-d')); // selisih hari (dalam detik)
                if ($diff < 0) {
                    $countOverdue++;
                } elseif ($diff <= 2 * 86400) {
                    $countDueSoon++;
                }
            }
            $countDueTotal = $countOverdue + $countDueSoon;
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
            <!-- Bottom row: Total Active + Due Alert -->
            <div class="cs-stats-bottom">
                <div class="cs-stat-card" data-filter="all" onclick="csSetFilter('all', this)" title="Show all orders">
                    <div class="cs-stat-num" style="color:#fff;"><?php echo count($orders); ?></div>
                    <div class="cs-stat-label">Total Active</div>
                </div>
                <div class="cs-stat-card due-alert" data-filter="due" onclick="csSetFilter('due', this)" title="Filter: Due Soon & Overdue">
                    <div class="cs-stat-num" style="color:#ff6b6b;"><?php echo $countDueTotal; ?></div>
                    <div class="cs-stat-label">
                        ⏰ Due Alert
                        <?php if ($countOverdue > 0): ?>
                            <span class="cs-due-badge overdue"><?php echo $countOverdue; ?> overdue</span>
                        <?php endif; ?>
                        <?php if ($countDueSoon > 0): ?>
                            <span class="cs-due-badge soon"><?php echo $countDueSoon; ?> soon</span>
                        <?php endif; ?>
                    </div>
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

            // Deteksi lensa stock vs lab: cocokkan lens_name dengan daftar dari lense_prices.json
            // Format: "CATEGORY / TYPE" — contoh "SINGLE VISION / BLUERAY"
            $isStock = in_array(strtoupper(trim($lensName)), $stockLensNames);

            // Deteksi short order: due_date - order_date == 3 hari → step 4 & 5 disabled
            $isShortOrder = false;
            if (!empty($o['order_date']) && !empty($o['due_date'])) {
                $orderTs = strtotime(date('Y-m-d', strtotime($o['order_date'])));
                $dueTs   = strtotime(date('Y-m-d', strtotime($o['due_date'])));
                $diffDays = ($dueTs - $orderTs) / 86400;
                $isShortOrder = ($diffDays <= 3);
            }

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
             data-custnum="<?php echo htmlspecialchars(strtolower($o['customer_number'] ?? '')); ?>"
             data-fullname="<?php echo htmlspecialchars($name); ?>"
             data-age="<?php echo $age; ?>"
             data-gender="<?php echo htmlspecialchars(strtolower($gender)); ?>"
             data-custnum-orig="<?php echo htmlspecialchars($o['customer_number'] ?? ''); ?>"
             data-invnum="<?php echo htmlspecialchars($o['invoice_number'] ?? ''); ?>"
             data-duedate="<?php echo $dueDate; ?>"
             data-waphone="<?php echo htmlspecialchars(preg_replace('/[^0-9]/', '', $phone) ? (($waPhone)) : ''); ?>"
             data-isstock="<?php echo $isStock ? '1' : '0'; ?>"
             data-shortorder="<?php echo $isShortOrder ? '1' : '0'; ?>"
             data-orderdate="<?php echo htmlspecialchars($o['order_date'] ?? ''); ?>"
             data-duedate-raw="<?php echo htmlspecialchars($o['due_date'] ?? ''); ?>">

            <!-- Top row: patient info + status badge (clickable header) -->
            <div class="cs-card-header cs-card-top" onclick="csToggleCard(this)">
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
                <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
                    <div class="cs-status-badge"
                         style="color:<?php echo $sm['color']; ?>;border-color:<?php echo $sm['color']; ?>33;background:<?php echo $sm['bg']; ?>">
                        <?php echo $sm['icon']; ?>&nbsp;<?php echo strtoupper($sm['label']); ?>
                    </div>
                    <span class="cs-chevron">▼</span>
                </div>
            </div>

            <!-- Collapsible body: details + actions -->
            <div class="cs-card-body">

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
                <!-- Stock lens: hanya step 1, 4, 5 yang aktif -->
                <div class="cs-stepper" title="Click a number to change status">
                    <?php foreach ([1,2,3,4,5] as $step):
                        $isDone    = ($step < $st);
                        $isCurrent = ($step === $st);
                        $cls = $isDone ? 'done' : ($isCurrent ? 'current' : '');
                        $stepSm = $statusMap[$step] ?? [];
                        $tt = htmlspecialchars($stepSm['label'] ?? 'Step ' . $step);
                        // Stock lens: step 2 & 3 tidak tersedia
                        $isDisabled = $isStock && in_array($step, [2, 3]);
                        // Short order (due_date - order_date <= 3 hari): step 2 & 3 tidak tersedia
                        if (!$isDisabled && $isShortOrder && in_array($step, [2, 3])) {
                            $isDisabled = true;
                        }
                    ?>
                    <?php if ($step > 1): ?>
                    <div class="cs-step-line <?php echo ($step <= $st ? 'done-line' : ''); ?><?php echo $isDisabled ? ' step-line-disabled' : ''; ?>"></div>
                    <?php endif; ?>
                    <div class="cs-step <?php echo $cls; ?><?php echo $isDisabled ? ' step-disabled' : ''; ?>"
                         title="<?php
                             if ($isDisabled) {
                                 if ($isStock && in_array($step, [2, 3])) {
                                     echo 'Tidak tersedia untuk lensa stock';
                                 } else {
                                     echo 'Tidak tersedia untuk order 3 hari';
                                 }
                             } else {
                                 echo 'Set: ' . $tt;
                             }
                         ?>"
                         <?php if (!$isDisabled): ?>
                         onclick="csChangeStatus(<?php echo $o['id']; ?>, <?php echo $step; ?>, this)"
                         <?php endif; ?>>
                        <?php echo $isDisabled ? '—' : $step; ?>
                    </div>
                    <?php endforeach; ?>
                    <span style="font-size:0.65rem;color:var(--text-muted);margin-left:8px;letter-spacing:0.5px;">STATUS</span>
                </div>

                <!-- WA Button -->
                <?php if (!empty($phone)): ?>
                <button class="cs-wa-btn"
                        onclick="csOpenWAModal(<?php echo $st; ?>, <?php echo htmlspecialchars(json_encode($name)); ?>, <?php echo $age; ?>, <?php echo htmlspecialchars(json_encode(strtolower($gender))); ?>, <?php echo htmlspecialchars(json_encode($o['customer_number'] ?? '')); ?>, <?php echo htmlspecialchars(json_encode($o['invoice_number'] ?? '')); ?>, <?php echo htmlspecialchars(json_encode($dueDate)); ?>, <?php echo htmlspecialchars(json_encode($waPhone)); ?>, <?php echo htmlspecialchars(json_encode($name)); ?>)">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                    SEND WA
                </button>
                <?php else: ?>
                <span style="font-size:0.68rem;color:#555;font-style:italic;">Phone number unavailable</span>
                <?php endif; ?>

            </div><!-- /cs-card-actions -->
            </div><!-- /cs-card-body -->
        </div><!-- /cs-card -->
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
            <!-- Muslim / Non-Muslim toggle -->
            <div class="cs-religion-toggle">
                <span class="cs-religion-toggle-label">Customer:</span>
                <div class="cs-toggle-group">
                    <button class="cs-toggle-btn active" id="cs-toggle-muslim" onclick="csSetReligion('muslim')">&#9775;&#65039; Muslim</button>
                    <button class="cs-toggle-btn" id="cs-toggle-nonmuslim" onclick="csSetReligion('nonmuslim')">Non-Muslim</button>
                </div>
            </div>
            <textarea class="cs-msg-preview" id="cs-modal-msg" spellcheck="false"></textarea>
            <div class="cs-modal-actions">
                <button class="cs-btn cancel" onclick="csCloseModal()">Cancel</button>
                <a class="cs-btn send" id="cs-modal-wa-link" href="#" target="_blank" onclick="csUpdateWALinkAndClose()">
                    Send via WhatsApp &#128242;
                </a>
            </div>
        </div>
    </div>

    <!-- ── Toast ─────────────────────────────────────────────────── -->
    <div id="cs-toast"></div>

    <script>
    // ── Filter state ──────────────────────────────────────────────
    var _csActiveFilter = 'none'; // default: tidak ada yang dipilih, semua card tersembunyi

    // ── Toggle card expand/collapse ───────────────────────────────
    function csToggleCard(headerEl) {
        var card = headerEl.closest('.cs-card');
        card.classList.toggle('expanded');
    }

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

        // Pre-compute today's date string (YYYY-MM-DD) for due filter
        var today    = new Date();
        today.setHours(0,0,0,0);
        var todayTs  = today.getTime();
        var in2days  = todayTs + (2 * 86400 * 1000);

        var container = document.getElementById('cs-cards-container');
        var cards     = Array.prototype.slice.call(container.querySelectorAll('.cs-card'));

        cards.forEach(function(card) {
            var status  = card.getAttribute('data-status');
            var name    = card.getAttribute('data-name') || '';
            var inv     = card.getAttribute('data-inv') || '';
            var phone   = card.getAttribute('data-phone') || '';
            var custnum = card.getAttribute('data-custnum') || '';

            var matchFilter;
            if (filter === 'none') {
                matchFilter = false;
            } else if (filter === 'all') {
                matchFilter = true;
            } else if (filter === 'due') {
                // Status 4 (Awaiting Collection) dikecualikan dari Due Alert
                if (status === '4') {
                    matchFilter = false;
                } else {
                    var dueDateRaw = card.getAttribute('data-duedate-raw') || '';
                    if (!dueDateRaw) {
                        matchFilter = false;
                    } else {
                        var dueTs = new Date(dueDateRaw).getTime();
                        matchFilter = (dueTs < todayTs || dueTs <= in2days);
                    }
                }
            } else {
                matchFilter = (status === filter);
            }

            var matchSearch = !q ||
                name.indexOf(q) !== -1 ||
                inv.indexOf(q) !== -1 ||
                phone.indexOf(q) !== -1 ||
                custnum.indexOf(q) !== -1;

            card.style.display = (matchFilter && matchSearch) ? '' : 'none';
        });

        // filter 'due': overdue/due soon ascending (paling lama lewat paling atas)
        // filter '4' (Awaiting Collection): ascending (paling duluan siap paling atas)
        if (filter === 'due' || filter === '4') {
            var visible = cards.filter(function(c) { return c.style.display !== 'none'; });
            visible.sort(function(a, b) {
                var da = new Date(a.getAttribute('data-duedate-raw') || '9999-12-31').getTime();
                var db = new Date(b.getAttribute('data-duedate-raw') || '9999-12-31').getTime();
                return da - db;
            });
            visible.forEach(function(card) { container.appendChild(card); });
        }
    }

    // ── WA Message Builder (JavaScript, sinkron dengan PHP) ──────
    // isMuslim: true = sertakan salam & alhamdulillah; false = hilangkan keduanya
    function buildWAMessage(status, name, age, gender, custNum, invNum, dueDate, isMuslim) {
        age      = parseInt(age) || 0;
        gender   = (gender || '').toLowerCase();
        status   = parseInt(status);
        if (isMuslim === undefined) isMuslim = true;

        var salam;
        if (isMuslim) {
            salam = 'السَّلَامُ عَلَيْكُمْ وَرَحْمَةُ اللهِ وَبَرَكَاتُهُ\n\n';
        } else {
            var hour = new Date().getHours();
            var greeting = hour >= 3 && hour < 11  ? 'Selamat Pagi'
                         : hour >= 11 && hour < 15 ? 'Selamat Siang'
                         : hour >= 15 && hour < 19 ? 'Selamat Sore'
                         :                           'Selamat Malam';
            salam = greeting + '\n\n';
        }
        var sapaan, gaya;
        var firstName = name.split(' ')[0];

        if (age > 0 && age < 13) {
            sapaan = 'Bapak/Ibu';
            gaya   = 'formal_ortu';
        } else if (age >= 13 && age <= 17) {
            sapaan = (gender === 'male') ? 'Saudara ' + firstName : 'Saudari ' + firstName;
            gaya   = 'remaja';
        } else {
            sapaan = (gender === 'male') ? 'Bapak ' + firstName : 'Ibu ' + firstName;
            gaya   = 'dewasa';
        }

        // Prefix status 4: "Alhamdulillah, " hanya untuk Muslim
        var alhamdulillah = isMuslim ? 'Alhamdulillah, kami' : 'Kami';

        var msg = '';
        switch (status) {
            case 1:
                if (gaya === 'formal_ortu') {
                    msg = salam + 'Kepada ' + sapaan + ' 🙏\n\nKami dari LenZa Optic ingin menginformasikan bahwa pesanan kacamata untuk putra/putri Anda dengan nomor order *' + custNum + '* telah kami terima dan sedang dalam proses pengerjaan.\n\nNomor Invoice: *' + invNum + '*\nEstimasi selesai: *' + dueDate + '*\n\nTerima kasih telah mempercayakan kebutuhan penglihatan buah hati Anda kepada kami. Kami akan terus memberikan informasi perkembangannya. 🙏';
                } else {
                    msg = salam + 'Kepada ' + sapaan + ' 🙏\n\nKami dari LenZa Optic ingin menginformasikan bahwa pesanan kacamata Anda dengan nomor order *' + custNum + '* telah kami terima dan sedang dalam proses pengerjaan.\n\nNomor Invoice: *' + invNum + '*\nEstimasi selesai: *' + dueDate + '*\n\nTerima kasih atas kepercayaan Anda. Kami akan segera menginformasikan perkembangan selanjutnya. 🙏';
                }
                break;
            case 2:
                if (gaya === 'formal_ortu') {
                    msg = salam + 'Kepada ' + sapaan + ' 🙏\n\nKami ingin menginformasikan bahwa kacamata putra/putri Anda (No. Order: *' + custNum + '*) saat ini sedang dalam proses pembuatan lensa.\n\nSetiap detail dikerjakan dengan teliti dan penuh perhatian. Estimasi selesai: *' + dueDate + '*\n\nTerima kasih atas kesabaran Anda. 🙏';
                } else {
                    msg = salam + 'Kepada ' + sapaan + ' 🙏\n\nKami ingin menginformasikan bahwa kacamata Anda (No. Order: *' + custNum + '*) saat ini sedang dalam proses pembuatan lensa.\n\nSetiap detail dikerjakan dengan penuh ketelitian. Estimasi selesai: *' + dueDate + '*\n\nTerima kasih atas kesabaran Anda. 🙏';
                }
                break;
            case 3:
                if (gaya === 'formal_ortu') {
                    msg = salam + 'Kepada ' + sapaan + ' 🙏\n\nKami ingin menyampaikan kabar baik bahwa kacamata putra/putri Anda (No. Order: *' + custNum + '*) telah selesai dibuat dan saat ini sedang dalam perjalanan menuju toko kami.\n\nKami akan menghubungi kembali begitu kacamata tiba dan siap untuk diambil. 🚚';
                } else {
                    msg = salam + 'Kepada ' + sapaan + ' 🙏\n\nKami ingin menyampaikan kabar baik bahwa kacamata Anda (No. Order: *' + custNum + '*) telah selesai dibuat dan saat ini sedang dalam perjalanan menuju toko kami.\n\nKami akan menghubungi Anda kembali begitu kacamata tiba dan siap untuk diambil. 🚚';
                }
                break;
            case 4:
                if (gaya === 'formal_ortu') {
                    msg = salam + 'Kepada ' + sapaan + ' 🙏\n\n' + alhamdulillah + ' dengan senang hati menginformasikan bahwa kacamata putra/putri Anda (No. Order: *' + custNum + '*) telah selesai dan siap untuk diambil di toko kami.\n\nMohon membawa nomor invoice *' + invNum + '* saat pengambilan.\n\nKami tunggu kedatangan Anda. Terima kasih 😊🙏';
                } else {
                    msg = salam + 'Kepada ' + sapaan + ' 🙏\n\n' + alhamdulillah + ' dengan senang hati menginformasikan bahwa kacamata Anda (No. Order: *' + custNum + '*) telah selesai dan siap untuk diambil di toko kami.\n\nMohon membawa nomor invoice *' + invNum + '* saat pengambilan.\n\nKami tunggu kedatangan Anda. Terima kasih 😊🙏';
                }
                break;
            default:
                msg = salam + 'Kepada pelanggan,\n\nBerikut informasi mengenai pesanan Anda dengan no. order *' + custNum + '*. Silakan hubungi kami untuk informasi lebih lanjut.';
        }
        return msg;
    }

    // ── Run initial filter on page load ──────────────────────────
    document.addEventListener('DOMContentLoaded', function() { csFilterCards(); });

    // ── Change order status via AJAX ─────────────────────────────
    function csChangeStatus(orderId, newStatus, stepEl) {
        // Proteksi: abaikan jika step ini disabled (lensa stock)
        if (stepEl.classList.contains('step-disabled')) return;

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
                    // Pertahankan class step-disabled untuk lensa stock dan short order
                    card.querySelectorAll('.cs-step').forEach(function(s, idx) {
                        var stepNum    = idx + 1;
                        var wasDisabled = s.classList.contains('step-disabled');
                        s.className = 'cs-step';
                        if (wasDisabled) {
                            s.classList.add('step-disabled');
                        } else if (stepNum < newStatus) {
                            s.classList.add('done');
                        } else if (stepNum === newStatus) {
                            s.classList.add('current');
                        }
                    });

                    // Update step lines — pertahankan step-line-disabled
                    card.querySelectorAll('.cs-step-line').forEach(function(ln, idx) {
                        var wasDisabled = ln.classList.contains('step-line-disabled');
                        ln.className = 'cs-step-line';
                        if (wasDisabled) {
                            ln.classList.add('step-line-disabled');
                        } else if ((idx + 2) <= newStatus) {
                            ln.classList.add('done-line');
                        }
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

                    // Update WA button dengan pesan sesuai status baru
                    var waBtn = card.querySelector('.cs-wa-btn');
                    if (waBtn) {
                        var fullName = card.getAttribute('data-fullname') || '';
                        var age      = card.getAttribute('data-age') || '0';
                        var gender   = card.getAttribute('data-gender') || '';
                        var custNum  = card.getAttribute('data-custnum-orig') || '';
                        var invNum   = card.getAttribute('data-invnum') || '';
                        var dueDate  = card.getAttribute('data-duedate') || '-';
                        var waPhone  = card.getAttribute('data-waphone') || '';

                        var newMsg = buildWAMessage(newStatus, fullName, age, gender, custNum, invNum, dueDate, true);
                        var newUrl = 'https://wa.me/' + waPhone + '?text=' + encodeURIComponent(newMsg);

                        waBtn.onclick = (function(m, u, n, wp, st, fn, a, g, cn, inv, dd) {
                            return function() { csOpenWAModal(st, fn, a, g, cn, inv, dd, wp, n); };
                        })(newMsg, newUrl, fullName, waPhone, newStatus, fullName, age, gender, custNum, invNum, dueDate);
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

    // ── WA Modal state ────────────────────────────────────────────
    var _csModalIsMuslim = true;
    var _csModalData     = {}; // { status, name, age, gender, custNum, invNum, dueDate, waPhone }

    function csSetReligion(religion) {
        _csModalIsMuslim = (religion === 'muslim');
        document.getElementById('cs-toggle-muslim').classList.toggle('active',    _csModalIsMuslim);
        document.getElementById('cs-toggle-nonmuslim').classList.toggle('active', !_csModalIsMuslim);

        // Re-build message preview & update WA link
        var d   = _csModalData;
        var msg = buildWAMessage(d.status, d.name, d.age, d.gender, d.custNum, d.invNum, d.dueDate, _csModalIsMuslim);
        var url = 'https://wa.me/' + d.waPhone + '?text=' + encodeURIComponent(msg);
        document.getElementById('cs-modal-msg').value         = msg;
        document.getElementById('cs-modal-wa-link').href      = url;
    }

    // ── WA Modal ──────────────────────────────────────────────────
    // status, name, age, gender, custNum, invNum, dueDate, waPhone, displayName
    function csOpenWAModal(status, name, age, gender, custNum, invNum, dueDate, waPhone, displayName) {
        // Store state for re-build on toggle
        _csModalIsMuslim = true; // reset to Muslim (default) each time modal opens
        _csModalData = { status: status, name: name, age: age, gender: gender,
                         custNum: custNum, invNum: invNum, dueDate: dueDate, waPhone: waPhone };

        // Reset toggle UI
        document.getElementById('cs-toggle-muslim').classList.add('active');
        document.getElementById('cs-toggle-nonmuslim').classList.remove('active');

        var msg = buildWAMessage(status, name, age, gender, custNum, invNum, dueDate, true);
        var url = 'https://wa.me/' + waPhone + '?text=' + encodeURIComponent(msg);

        document.getElementById('cs-modal-msg').value       = msg;
        document.getElementById('cs-modal-sub').textContent = 'To: ' + (displayName || name);
        document.getElementById('cs-modal-wa-link').href    = url;
        document.getElementById('cs-modal-overlay').classList.add('open');
    }

    function csCloseModal() {
        document.getElementById('cs-modal-overlay').classList.remove('open');
    }

    // Update WA link with current (possibly edited) textarea content before opening WA
    function csUpdateWALinkAndClose() {
        var editedMsg = document.getElementById('cs-modal-msg').value;
        var d = _csModalData;
        var waPhone = d.waPhone || '';
        var url = 'https://wa.me/' + waPhone + '?text=' + encodeURIComponent(editedMsg);
        document.getElementById('cs-modal-wa-link').href = url;
        csCloseModal();
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
