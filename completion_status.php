<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    // ── Load lens lead times (in days) from settings table ─────────────
    // Same pattern as invoice.php: used to classify a lens as "Stock" (ready
    // fast) or "Lab" (custom-made, longer turnaround) based on how many days
    // the order was given between order_date and due_date.
    $lensStockLeadTimeDays = 2;
    $lensLabLeadTimeDays   = 10;
    $resLead = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('lens_stock_lead_time_days', 'lens_lab_lead_time_days')");
    if ($resLead) {
        while ($rowLead = mysqli_fetch_assoc($resLead)) {
            if ($rowLead['setting_key'] === 'lens_stock_lead_time_days') $lensStockLeadTimeDays = (int)$rowLead['setting_value'];
            if ($rowLead['setting_key'] === 'lens_lab_lead_time_days')   $lensLabLeadTimeDays   = (int)$rowLead['setting_value'];
        }
    }

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
            ce.examination_code,
            ce.lens_modification,
            ce.pd_dist,
            ce.new_r_sph, ce.new_r_cyl, ce.new_r_ax, ce.new_r_add,
            ce.new_l_sph, ce.new_l_cyl, ce.new_l_ax, ce.new_l_add,
            pm.od_sph AS mod_r_sph, pm.od_cyl AS mod_r_cyl, pm.od_axis AS mod_r_ax, pm.od_add AS mod_r_add,
            pm.os_sph AS mod_l_sph, pm.os_cyl AS mod_l_cyl, pm.os_axis AS mod_l_ax, pm.os_add AS mod_l_add,
            cf.brand_key AS custom_frame_brand_key
        FROM customer_orders co
        LEFT JOIN customer_examinations ce
            ON co.invoice_number = ce.invoice_number
            AND co.invoice_number != '00'
        LEFT JOIN prescription_modifications pm
            ON co.invoice_number = pm.invoice_number
        LEFT JOIN custom_frames cf
            ON co.invoice_number = cf.invoice_number COLLATE utf8mb4_general_ci
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

    // ── Lens sizes for orders currently "Order Received" (status 1) ────
    // Mirrors the original-vs-modified prescription logic used in invoice.php:
    // if lens_modification == 1 and a prescription_modifications row exists,
    // use the modified (mod_*) values; otherwise use the original (new_*) values.
    $lensSizeOrders = [];
    foreach ($orders as $o) {
        if ((int)$o['order_status'] !== 1) continue;
        $hasMod = ((int)($o['lens_modification'] ?? 0) === 1) && isset($o['mod_r_sph']) && $o['mod_r_sph'] !== null && $o['mod_r_sph'] !== '';

        // ── Determine prescription source status ───────────────────────
        // examination_code pattern e.g. "LZ/EC/001/VII/2026" (normal exam)
        // vs "LZ/EC/000-001/VII/2026" (the "000-" prefix on the sequence
        // segment marks the prescription as customer-provided, not measured
        // in-house).
        $examCode  = $o['examination_code'] ?? '';
        $codeParts = explode('/', $examCode);
        $seqPart   = $codeParts[2] ?? '';
        $isCustomerRx = (strpos($seqPart, '000-') === 0);

        if ($isCustomerRx) {
            $rxStatus = 'Customer-Provided Prescription';
        } elseif ($hasMod) {
            $rxStatus = 'Modified by Customer';
        } else {
            $rxStatus = 'Original Prescription';
        }

        // ── Determine lens type (Stock vs Lab) from order_date → due_date gap ──
        // Compared against the configurable lead times from the settings table:
        // closer to lens_stock_lead_time_days (default 2) => "Stock",
        // closer to lens_lab_lead_time_days   (default 10) => "Lab".
        $lensType = null;
        if (!empty($o['order_date']) && !empty($o['due_date']) && strpos($o['due_date'], '0000-00-00') !== 0) {
            $odTs = strtotime($o['order_date']);
            $ddTs = strtotime($o['due_date']);
            if ($odTs !== false && $ddTs !== false) {
                $leadDays = round(($ddTs - $odTs) / 86400);
                $distToStock = abs($leadDays - $lensStockLeadTimeDays);
                $distToLab   = abs($leadDays - $lensLabLeadTimeDays);
                $lensType = ($distToStock <= $distToLab) ? 'Stock' : 'Lab';
            }
        }

        // ── Determine frame name ────────────────────────────────────────
        // Case 1: frame_ufc is set, pattern "BRAND-MODEL-..." → take the
        //         segment before the first "-" (e.g. "RAYBAN-TB6283-52-14-156-C9" → "RAYBAN").
        // Case 2: frame_ufc is NULL → look up custom_frames.brand_key for the
        //         same invoice_number, pattern "...+...+BRAND" → take the
        //         segment after the last "+" (e.g. "52-18-140+08/07+BRENDEN" → "BRENDEN").
        $frameName = '';
        if (!empty($o['frame_ufc'])) {
            $ufcParts  = explode('-', $o['frame_ufc']);
            $frameName = trim($ufcParts[0]);
        } elseif (!empty($o['custom_frame_brand_key'])) {
            $bkParts   = explode('+', $o['custom_frame_brand_key']);
            $frameName = trim(end($bkParts));
        }

        // Frame name defaults to "S" (no frame name resolvable at all)
        if ($frameName === '') {
            $frameName = 'S';
        }

        $rSph = $hasMod ? $o['mod_r_sph'] : $o['new_r_sph'];
        $rCyl = $hasMod ? $o['mod_r_cyl'] : $o['new_r_cyl'];
        $rAx  = $hasMod ? $o['mod_r_ax']  : $o['new_r_ax'];
        $rAdd = $hasMod ? $o['mod_r_add'] : $o['new_r_add'];
        $lSph = $hasMod ? $o['mod_l_sph'] : $o['new_l_sph'];
        $lCyl = $hasMod ? $o['mod_l_cyl'] : $o['new_l_cyl'];
        $lAx  = $hasMod ? $o['mod_l_ax']  : $o['new_l_ax'];
        $lAdd = $hasMod ? $o['mod_l_add'] : $o['new_l_add'];

        // ── Skip frame-only purchases ────────────────────────────────────
        // If every prescription value is empty OR numerically zero, the
        // customer only bought a frame (no real lens prescription) — a
        // default PD value alone must not count as "has a prescription".
        $allEmpty = true;
        foreach ([$rSph, $rCyl, $rAx, $rAdd, $lSph, $lCyl, $lAx, $lAdd] as $v) {
            $vStr = trim((string)$v);
            if ($vStr !== '' && (float)$vStr != 0) { $allEmpty = false; break; }
        }
        if ($allEmpty) continue;

        $lensSizeOrders[] = [
            'invoice_number' => $o['invoice_number'],
            'patient_name'   => $o['patient_name'],
            'frame_ufc'      => $o['frame_ufc'],
            'frame_name'     => $frameName,
            'lens_name'      => $o['lens_name'],
            'lens_type'      => $lensType,
            'is_modified'    => $hasMod,
            'rx_status'      => $rxStatus,
            'order_date'     => $o['order_date'],
            'pd'             => $o['pd_dist'],
            'r_sph'          => $rSph,
            'r_cyl'          => $rCyl,
            'r_ax'           => $rAx,
            'r_add'          => $rAdd,
            'l_sph'          => $lSph,
            'l_cyl'          => $lCyl,
            'l_ax'           => $lAx,
            'l_add'          => $lAdd,
        ];
    }

    // ── Group into Stock / Lab (and Unspecified, if lead-time couldn't be determined) ──
    $lensGroupStock = [];
    $lensGroupLab   = [];
    $lensGroupOther = [];
    foreach ($lensSizeOrders as $lo) {
        if ($lo['lens_type'] === 'Stock') {
            $lensGroupStock[] = $lo;
        } elseif ($lo['lens_type'] === 'Lab') {
            $lensGroupLab[] = $lo;
        } else {
            $lensGroupOther[] = $lo;
        }
    }

    // ── Sort each group by order_date ascending (oldest first) ─────────
    $lensDateSort = function ($a, $b) {
        return strtotime($a['order_date'] ?? '') <=> strtotime($b['order_date'] ?? '');
    };
    usort($lensGroupStock, $lensDateSort);
    usort($lensGroupLab,   $lensDateSort);
    usort($lensGroupOther, $lensDateSort);

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

        .cs-header-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            user-select: none;
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

        /* ── Lens Sizes section (below Order Tracking) ───────────────── */
        .cs-lens-section {
            margin-top: -16px;
            background: var(--bg-color);
            border-radius: 18px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.04);
            padding: 18px;
        }
        .cs-lens-section-title {
            font-size: 1rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }

        /* ── Lens Sizes card (Order Received) — collapsible ─────────── */
        .cs-lens-card {
            background: var(--bg-color);
            border-radius: 16px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            border: 1px solid rgba(255,255,255,0.04);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .cs-lens-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            cursor: pointer;
            user-select: none;
        }
        .cs-lens-card-title {
            font-size: 0.85rem;
            font-weight: 800;
            color: var(--text-color);
        }
        .cs-lens-card-chevron {
            font-size: 0.9rem;
            color: var(--text-muted);
            transition: transform 0.2s ease;
        }
        .cs-lens-card-chevron.open {
            transform: rotate(90deg);
        }
        .cs-lens-card-body {
            padding: 0 18px 16px 18px;
        }
        .cs-lens-card-body .cs-lens-item {
            margin-top: 12px;
        }
        .cs-lens-empty {
            font-size: 0.75rem;
            color: var(--text-muted);
            padding: 6px 0 4px 0;
        }
        .cs-lens-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .cs-lens-group + .cs-lens-group {
            padding-top: 14px;
            border-top: 1px solid rgba(255,255,255,0.06);
        }
        .cs-lens-group-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.3px;
        }
        .cs-lens-group-title--stock { color: #00ff88; }
        .cs-lens-group-title--lab   { color: #aa88ff; }
        .cs-lens-group-title--other { color: var(--text-muted); }
        .cs-lens-group-count {
            font-size: 0.62rem;
            font-weight: 800;
            color: var(--text-muted);
            background: rgba(255,255,255,0.06);
            border-radius: 10px;
            padding: 1px 8px;
        }
        .cs-lens-group-items {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .cs-lens-item {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 10px 12px;
        }
        .cs-lens-item-head {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            cursor: pointer;
            user-select: none;
        }
        .cs-lens-item-chevron {
            margin-left: auto;
            font-size: 0.8rem;
            color: var(--text-muted);
            transition: transform 0.2s ease;
        }
        .cs-lens-item-chevron.open {
            transform: rotate(90deg);
        }
        .cs-lens-item-body {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(255,255,255,0.06);
        }
        .cs-lens-item-line {
            font-size: 0.74rem;
            color: var(--text-color);
            margin-bottom: 6px;
        }
        .cs-lens-item-icon {
            display: inline-block;
            width: 18px;
            text-align: center;
        }
        .cs-lens-item-inv {
            font-size: 0.78rem;
            font-weight: 800;
            color: var(--text-color);
        }
        .cs-lens-item-name {
            font-size: 0.78rem;
            color: var(--text-muted);
        }
        .cs-lens-rx-badge {
            font-size: 0.58rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            padding: 2px 7px;
            border-radius: 10px;
            border: 1px solid transparent;
        }
        .cs-lens-rx-badge.original {
            background: rgba(0,255,136,0.12);
            color: #00ff88;
            border-color: rgba(0,255,136,0.3);
        }
        .cs-lens-rx-badge.modified {
            background: rgba(0,207,255,0.15);
            color: #00cfff;
            border-color: rgba(0,207,255,0.3);
        }
        .cs-lens-rx-badge.customer-rx {
            background: rgba(255,170,0,0.15);
            color: #ffaa00;
            border-color: rgba(255,170,0,0.3);
        }
        .cs-lens-type-badge {
            display: inline-block;
            font-size: 0.56rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            padding: 1px 6px;
            border-radius: 8px;
            margin-left: 6px;
            border: 1px solid transparent;
            vertical-align: middle;
        }
        .cs-lens-type-badge.stock {
            background: rgba(0,255,136,0.12);
            color: #00ff88;
            border-color: rgba(0,255,136,0.3);
        }
        .cs-lens-type-badge.lab {
            background: rgba(170,136,255,0.15);
            color: #aa88ff;
            border-color: rgba(170,136,255,0.3);
        }
        .cs-lens-item-frame {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 8px;
        }
        .cs-lens-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.72rem;
            font-variant-numeric: tabular-nums;
        }
        .cs-lens-table th, .cs-lens-table td {
            text-align: center;
            padding: 5px 6px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .cs-lens-table thead th {
            border-bottom: 1px solid rgba(255,255,255,0.12);
        }
        .cs-lens-table tbody tr:last-child td {
            border-bottom: none;
        }
        .cs-lens-table th {
            color: var(--text-muted);
            font-weight: 700;
        }
        .cs-lens-table td:first-child, .cs-lens-table th:first-child {
            text-align: left;
            color: var(--text-muted);
            font-weight: 700;
        }
        .cs-lens-table td {
            color: var(--text-color);
        }
        .cs-lens-item-pd {
            font-size: 0.68rem;
            color: var(--text-muted);
            margin-top: 6px;
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
    <!-- button logout, back animation for logo -->
    <style>
        .neu-button.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
            filter: grayscale(1);
        }

        /* ===== New neumorphic style for Back & Logout buttons ===== */
        .neu-pill-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #1c1e22;
            border: none;
            border-radius: 32px;
            padding: 6px 16px 6px 6px;
            cursor: pointer;
            box-shadow:
                6px 6px 14px rgba(0, 0, 0, 0.55),
                -6px -6px 14px rgba(255, 255, 255, 0.03);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            font-family: inherit;
        }

        .neu-pill-btn:hover {
            box-shadow:
                6px 6px 16px rgba(0, 0, 0, 0.6),
                -6px -6px 16px rgba(255, 255, 255, 0.04);
        }

        .neu-pill-btn:active {
            transform: scale(0.96);
        }

        /* Overflow hidden so the icon can slide across without spilling out */
        .neu-pill-btn {
            overflow: hidden;
        }

        .neu-pill-icon {
            width: 32px;
            height: 32px;
            min-width: 32px;
            border-radius: 50%;
            background: #17181b;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 10px rgba(103, 232, 249, 0.35);
            transition: box-shadow 0.15s ease, transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Pressed state: icon slides to the right, text fades and slides out */
        .neu-pill-btn.pressed {
            box-shadow:
                inset 4px 4px 10px rgba(0, 0, 0, 0.6),
                inset -4px -4px 10px rgba(255, 255, 255, 0.03);
        }

        .neu-pill-btn.pressed .neu-pill-icon {
            transform: translateX(calc(100% + 24px));
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 18px rgba(103, 232, 249, 0.7);
        }

        .neu-pill-btn.pressed .neu-pill-text {
            opacity: 0;
            transform: translateX(15px);
        }

        .neu-pill-btn.pressed .neu-pill-icon,
        .neu-pill-btn:active .neu-pill-icon {
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 18px rgba(103, 232, 249, 0.7);
        }

        .neu-pill-icon svg {
            width: 15px;
            height: 15px;
            stroke: #7fe3f0;
            filter: drop-shadow(0 0 4px rgba(103, 232, 249, 0.8));
        }

        .neu-pill-text {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
            text-align: left;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }

        .neu-pill-text .line1 {
            font-weight: 700;
            font-size: 10px;
            letter-spacing: 0.4px;
            color: #f2f2f2;
        }

        .neu-pill-text .line2 {
            font-weight: 400;
            font-size: 9px;
            letter-spacing: 0.4px;
            color: #9a9da1;
        }

        /* Logout variant: warm amber/orange tone instead of cyan */
        .neu-pill-btn.logout-variant .neu-pill-icon {
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 10px rgba(255, 138, 101, 0.4);
        }

        .neu-pill-btn.logout-variant.pressed .neu-pill-icon {
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 18px rgba(255, 138, 101, 0.75);
        }

        .neu-pill-btn.logout-variant .neu-pill-icon svg {
            stroke: #ff8a65;
            filter: drop-shadow(0 0 4px rgba(255, 138, 101, 0.8));
        }

        /* ===== Logo zoom (fly window) effect ===== */
        .logo-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0);
            backdrop-filter: blur(0px);
            -webkit-backdrop-filter: blur(0px);
            z-index: 999;
            opacity: 0;
            pointer-events: none;
            transition: background 0.3s ease, opacity 0.3s ease, backdrop-filter 0.3s ease;
        }

        .logo-backdrop.active {
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            opacity: 1;
            pointer-events: auto;
        }

        .logo-box img {
            cursor: pointer;
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        top 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        left 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .logo-box img.zoomed {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(2.8);
            z-index: 1000;
        }

        /* Center the header block (logout button + logo/name/address group)
           on PC to match how it already appears centered on mobile. Only
           the container's own horizontal position is changed here — the
           internal layout is left exactly as in the original code. */
        .header-container {
            margin-left: auto !important;
            margin-right: auto !important;
            width: fit-content !important;
        }
    </style>
</head>
<body>
    <div class="main-wrapper">
        <div class="content-area" style="flex-direction: column">
            <div class="header-container">
                <button type="button" class="logout-btn neu-pill-btn logout-variant" id="logoutBtn" onclick="handleLogoutClick(this)">
                    <span class="neu-pill-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                            <polyline points="16 17 21 12 16 7"></polyline>
                            <line x1="21" y1="12" x2="9" y2="12"></line>
                        </svg>
                    </span>
                    <span class="neu-pill-text">
                        <span class="line1">LOGOUT</span>
                    </span>
                </button>
            
                <div class="brand-section">
                    <div class="logo-box">
                        <img id="storeLogo" src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" style="height: 40px;" onclick="zoomInLogo(this)" ondblclick="zoomOutLogo(this)">
                    </div>
                    <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                    <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
                </div>
            </div>

<div class="main-card" style="margin-left: auto; margin-right: auto; width: 100%;">
    <div class="cs-body">

        <!-- ── Page Header ─────────────────────────────────────── -->
        <div class="cs-header">
            <div class="cs-header-toggle" onclick="csToggleOrderTracking()">
                <div>
                    <div class="cs-title">📦 Order Tracking</div>
                    <div class="cs-subtitle">ACTIVE ORDERS — STATUS 1 TO 4</div>
                </div>
                <span class="cs-lens-card-chevron" id="cs-ordertracking-chevron">▸</span>
            </div>
            <div class="cs-search-wrap">
                <span class="cs-search-icon">🔍</span>
                <input type="text" class="cs-search" id="cs-search-input"
                       placeholder="Find by name, invoice, phone number…"
                       oninput="csFilterCards()">
            </div>
        </div>

        <div id="cs-ordertracking-body" style="display:none;">

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
                // Skip invalid/zero MySQL dates (e.g. "0000-00-00") — these are not
                // real due dates and must not be counted as overdue.
                if (strpos($o['due_date'], '0000-00-00') === 0) continue;
                if ((int)$o['order_status'] === 4) continue; // status 4 dikecualikan
                $dueTs = strtotime($o['due_date']);
                if ($dueTs === false) continue; // guard against any other unparseable date
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

        </div><!-- /cs-ordertracking-body -->

    </div><!-- /cs-body -->

            </div><!-- /main-card -->

            <!-- ── Lens Sizes (Order Received) — own card, below Order Tracking ── -->
            <div class="cs-lens-section">
                <div class="cs-lens-section-title">👓 Lens Sizes — Order Received (<?php echo count($lensSizeOrders); ?>)</div>

                <?php if (empty($lensSizeOrders)): ?>
                <div class="cs-lens-empty">No lens prescriptions to show for "Order Received" orders.</div>
                <?php else: ?>

                <?php
                    $lensGroups = [
                        ['key' => 'stock', 'label' => '📦 Stock',       'items' => $lensGroupStock],
                        ['key' => 'lab',   'label' => '🔬 Lab',         'items' => $lensGroupLab],
                        ['key' => 'other', 'label' => '❔ Unspecified', 'items' => $lensGroupOther],
                    ];
                    $lensItemSeq = 0;
                ?>
                <?php foreach ($lensGroups as $group): if (empty($group['items'])) continue; ?>
                <div class="cs-lens-card">
                    <div class="cs-lens-card-header" onclick="csToggleSection('cs-lens-group-body-<?php echo $group['key']; ?>', 'cs-lens-group-chevron-<?php echo $group['key']; ?>')">
                        <div class="cs-lens-card-title cs-lens-group-title--<?php echo $group['key']; ?>">
                            <?php echo $group['label']; ?>
                            <span class="cs-lens-group-count"><?php echo count($group['items']); ?></span>
                        </div>
                        <div class="cs-lens-card-chevron" id="cs-lens-group-chevron-<?php echo $group['key']; ?>">▸</div>
                    </div>
                    <div class="cs-lens-card-body" id="cs-lens-group-body-<?php echo $group['key']; ?>" style="display:none;">
                        <?php foreach ($group['items'] as $lo): $lensItemSeq++; $itemBodyId = 'cs-lens-item-body-' . $lensItemSeq; $itemChevronId = 'cs-lens-item-chevron-' . $lensItemSeq; ?>
                        <div class="cs-lens-item">
                            <div class="cs-lens-item-head" onclick="csToggleSection('<?php echo $itemBodyId; ?>', '<?php echo $itemChevronId; ?>')">
                                <span class="cs-lens-item-inv">#<?php echo htmlspecialchars($lo['invoice_number']); ?></span>
                                <span class="cs-lens-item-name"><?php echo htmlspecialchars($lo['patient_name'] ?: '-'); ?></span>
                                <?php
                                    $rxBadgeClass = 'original';
                                    if ($lo['rx_status'] === 'Customer-Provided Prescription') {
                                        $rxBadgeClass = 'customer-rx';
                                    } elseif ($lo['rx_status'] === 'Modified by Customer') {
                                        $rxBadgeClass = 'modified';
                                    }
                                ?>
                                <span class="cs-lens-rx-badge <?php echo $rxBadgeClass; ?>"><?php echo htmlspecialchars($lo['rx_status']); ?></span>
                                <span class="cs-lens-item-chevron" id="<?php echo $itemChevronId; ?>">▸</span>
                            </div>
                            <div class="cs-lens-item-body" id="<?php echo $itemBodyId; ?>" style="display:none;">
                                <div class="cs-lens-item-line"><span class="cs-lens-item-icon">🖼️</span> Frame: <strong><?php echo htmlspecialchars($lo['frame_name']); ?></strong></div>
                                <div class="cs-lens-item-line"><span class="cs-lens-item-icon">🔎</span> Lens: <strong><?php echo htmlspecialchars($lo['lens_name'] ?: '-'); ?></strong></div>
                                <table class="cs-lens-table">
                                    <thead>
                                        <tr><th></th><th>SPH</th><th>CYL</th><th>AXIS</th><th>ADD</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>OD (R)</td>
                                            <td><?php echo htmlspecialchars($lo['r_sph'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($lo['r_cyl'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($lo['r_ax'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($lo['r_add'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <td>OS (L)</td>
                                            <td><?php echo htmlspecialchars($lo['l_sph'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($lo['l_cyl'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($lo['l_ax'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($lo['l_add'] ?: '-'); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div class="cs-lens-item-pd">PD: <?php echo htmlspecialchars($lo['pd'] ?: '-'); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php endif; ?>
            </div>


            
        </div><!-- /content-area -->
        
        <div class="btn-group">
            <button type="button" class="neu-pill-btn" id="backBtn" onclick="handleBackClick(this)">
                <span class="neu-pill-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                </span>
                <span class="neu-pill-text">
                    <span class="line1">RETURN TO</span>
                    <span class="line2">PREVIOUS PAGE</span>
                </span>
            </button>
        </div>
    
    
        <footer class="footer-container">
            <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
        </footer>
    </div><!-- /main-wrapper -->
    <div class="logo-backdrop" id="logoBackdrop" ondblclick="zoomOutLogo(document.getElementById('storeLogo'))"></div>
        
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

        function csToggleOrderTracking() {
            var body = document.getElementById('cs-ordertracking-body');
            var chevron = document.getElementById('cs-ordertracking-chevron');
            if (!body || !chevron) return;
            var isOpen = body.style.display !== 'none';
            body.style.display = isOpen ? 'none' : 'block';
            chevron.classList.toggle('open', !isOpen);
        }

        // Generic collapsible section toggle — used by the Lens Sizes group
        // cards (Stock/Lab/Unspecified) and by each individual lens item.
        function csToggleSection(bodyId, chevronId) {
            var body = document.getElementById(bodyId);
            var chevron = document.getElementById(chevronId);
            if (!body || !chevron) return;
            var isOpen = body.style.display !== 'none';
            body.style.display = isOpen ? 'none' : 'block';
            chevron.classList.toggle('open', !isOpen);
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
                            // Parse the date-only part manually as LOCAL time (Year, Month, Day).
                            // Using `new Date(dueDateRaw)` directly is unreliable: a plain
                            // "YYYY-MM-DD" string is parsed as UTC by the browser, while
                            // todayTs/in2days below are computed in local time — causing
                            // mismatches near midnight/timezone boundaries.
                            var dueDateOnly = dueDateRaw.split(' ')[0].split('T')[0]; // "YYYY-MM-DD"
                            var dp = dueDateOnly.split('-');
                            if (dp.length !== 3 || dp[0] === '0000') {
                                matchFilter = false; // invalid / zero MySQL date
                            } else {
                                var dueLocal = new Date(parseInt(dp[0], 10), parseInt(dp[1], 10) - 1, parseInt(dp[2], 10));
                                dueLocal.setHours(0, 0, 0, 0);
                                var dueTs = dueLocal.getTime();
                                matchFilter = isNaN(dueTs) ? false : (dueTs < todayTs || dueTs <= in2days);
                            }
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
    <!-- button logout, back animation for logo -->
    <script>
        // Single tap/click on the logo zooms it in (only if not already zoomed).
        function zoomInLogo(imgEl) {
            if (imgEl.classList.contains('zoomed')) return;
            imgEl.classList.add('zoomed');
            document.getElementById('logoBackdrop').classList.add('active');
        }

        // Double tap/click zooms it back out.
        function zoomOutLogo(imgEl) {
            imgEl.classList.remove('zoomed');
            document.getElementById('logoBackdrop').classList.remove('active');
        }

        // Animate the new pill-style Back button before navigating
        function handleBackClick(element) {
            const icon = element.querySelector('.neu-pill-icon');
            const text = element.querySelector('.neu-pill-text');

            // Make sure nothing else fights with our manual animation.
            element.style.transition = 'none';
            text.style.transition = 'none';

            const startWidth = element.offsetWidth;
            // Target: just the round icon left, with the button's own
            // left/right padding preserved (6px left, 6px right when collapsed).
            const targetWidth = icon.offsetWidth + 12;

            // Hide the text immediately so only the shrinking pill is visible.
            text.style.opacity = '0';

            const duration = 400; // ms
            const startTime = performance.now();

            function step(now) {
                const elapsed = now - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);

                const currentWidth = startWidth - (startWidth - targetWidth) * eased;
                element.style.width = currentWidth + 'px';

                if (progress < 1) {
                    requestAnimationFrame(step);
                } else {
                    // back direction
                    window.location.href = 'customer.php';
                }
            }
            requestAnimationFrame(step);
        }

        // Animate the new pill-style Logout button before logging out
        function handleLogoutClick(element) {
            element.classList.add('pressed');
            setTimeout(() => {
                window.location.href = 'logout.php';
            }, 220);
        }

        // Function executed when a button is clicked
        function handleButtonClick(element) {
            // 1. Get the URL from the data-url attribute
            const targetUrl = element.getAttribute('data-url');
            
            // 2. Save this URL to localStorage as the active button identity
            localStorage.setItem('activeMenuUrl', targetUrl);
            
            // 3. Add the active class immediately (for an instant visual effect)
            document.querySelectorAll('.neu-button').forEach(btn => btn.classList.remove('active'));
            element.classList.add('active');

            // 4. Navigate to the page
            window.location.href = targetUrl;
        }

        // Function that runs automatically when the page is refreshed or returned to (Back)
        window.addEventListener('DOMContentLoaded', () => {
            const activeUrl = localStorage.getItem('activeMenuUrl');
            
            if (activeUrl) {
                document.querySelectorAll('.neu-button').forEach(btn => {
                    // If the button's data-url matches the one in memory, activate it!
                    if (btn.getAttribute('data-url') === activeUrl) {
                        btn.classList.add('active');
                    }
                });
            }
        });
    </script>
</body>
</html>