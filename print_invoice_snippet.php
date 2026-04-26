<?php
/*
 * ============================================================
 *  PRINT INVOICE SNIPPET
 *  Tambahkan kode CSS ini ke dalam <style> di invoice.php,
 *  dan tambahkan HTML di bawah ke dalam <body> sebelum </body>.
 * ============================================================
 *
 *  CARA INTEGRASI:
 *  1. Copy blok <style @media print> ke dalam tag <style> yang sudah ada
 *     di invoice.php (di bagian <head>).
 *  2. Copy blok <div id="print-invoice"> beserta script-nya,
 *     letakkan tepat sebelum tag </body> di invoice.php.
 *  3. Ganti onclick pada tombol PRINT INVOICE menjadi:
 *        onclick="openPrintInvoice()"
 * ============================================================
 */
?>

<!-- ============================================================
     BAGIAN 1 — CSS PRINT
     Tambahkan ke dalam blok <style> yang sudah ada di invoice.php
     ============================================================ -->
<style>
/* ── @media print: sembunyikan semua UI biasa, tampilkan hanya print-invoice ── */
@media print {
    /* Sembunyikan semua elemen halaman utama */
    body > *,
    .main-wrapper,
    .header-container,
    .main-card,
    .btn-group,
    .footer-container,
    #mfs-overlay {
        display: none !important;
    }

    /* Tampilkan HANYA print sheet */
    #print-invoice {
        display: block !important;
        position: fixed;
        inset: 0;
        z-index: 99999;
        background: #fff !important;
    }

    @page {
        size: A5 portrait;
        margin: 0;
    }
}
</style>

<!-- ============================================================
     BAGIAN 2 — HTML PRINT INVOICE
     Letakkan tepat sebelum </body> di invoice.php
     ============================================================ -->

<div id="print-invoice" style="display:none;">
    <style>
        /* ── Reset & base ── */
        #print-invoice * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Georgia', 'Times New Roman', serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ── Sheet wrapper ── */
        #print-invoice .inv-sheet {
            width: 148mm;
            min-height: 210mm;
            margin: 0 auto;
            background: #fff;
            color: #111;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* ── Header strip ── */
        #print-invoice .inv-header {
            background: #0d0d0d;
            color: #fff;
            padding: 18px 20px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        #print-invoice .inv-header-logo img {
            height: 36px;
            width: auto;
            object-fit: contain;
            filter: brightness(0) invert(1);
        }
        #print-invoice .inv-header-info {
            text-align: right;
        }
        #print-invoice .inv-store-name {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            color: #fff;
        }
        #print-invoice .inv-store-addr {
            font-size: 7.5px;
            color: #aaa;
            letter-spacing: 0.5px;
            margin-top: 2px;
            line-height: 1.4;
        }

        /* ── Gold accent bar ── */
        #print-invoice .inv-accent-bar {
            height: 3px;
            background: linear-gradient(90deg, #c9a84c 0%, #f5d98b 50%, #c9a84c 100%);
        }

        /* ── Invoice title row ── */
        #print-invoice .inv-title-row {
            padding: 12px 20px 10px;
            border-bottom: 1px solid #e8e8e8;
            display: flex;
            align-items: baseline;
            justify-content: space-between;
        }
        #print-invoice .inv-title-text {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: #0d0d0d;
        }
        #print-invoice .inv-meta {
            text-align: right;
        }
        #print-invoice .inv-meta-line {
            font-size: 7.5px;
            color: #555;
            letter-spacing: 0.8px;
            font-family: 'Courier New', monospace;
            line-height: 1.6;
        }
        #print-invoice .inv-meta-line strong {
            color: #0d0d0d;
            font-family: 'Courier New', monospace;
        }

        /* ── Body content ── */
        #print-invoice .inv-body {
            padding: 14px 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        /* ── Section label ── */
        #print-invoice .inv-section-label {
            font-size: 6.5px;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            color: #c9a84c;
            font-family: 'Arial', sans-serif;
            font-weight: 700;
            margin-bottom: 6px;
            padding-bottom: 3px;
            border-bottom: 1px solid #f0e8d5;
        }

        /* ── Customer info grid ── */
        #print-invoice .inv-cust-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 14px;
        }
        #print-invoice .inv-cust-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        #print-invoice .inv-cust-item.full {
            grid-column: 1 / -1;
        }
        #print-invoice .inv-field-label {
            font-size: 6px;
            letter-spacing: 1.5px;
            color: #888;
            text-transform: uppercase;
            font-family: 'Arial', sans-serif;
            font-weight: 700;
        }
        #print-invoice .inv-field-value {
            font-size: 9px;
            color: #111;
            font-family: 'Courier New', monospace;
            font-weight: 600;
            letter-spacing: 0.5px;
            word-break: break-word;
        }

        /* ── Code badge ── */
        #print-invoice .inv-code-badge {
            display: inline-block;
            background: #0d0d0d;
            color: #c9a84c;
            font-family: 'Courier New', monospace;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;
            padding: 3px 8px;
            border-radius: 3px;
        }

        /* ── Divider ── */
        #print-invoice .inv-divider {
            border: none;
            border-top: 1px dashed #ddd;
            margin: 2px 0;
        }

        /* ── Prescription table ── */
        #print-invoice .inv-rx-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-family: 'Courier New', monospace;
            font-size: 8.5px;
            border: 1px solid #e0d8c8;
            border-radius: 4px;
            overflow: hidden;
        }
        #print-invoice .inv-rx-table thead th {
            background: #0d0d0d;
            color: #c9a84c;
            font-size: 7px;
            letter-spacing: 1.5px;
            text-align: center;
            padding: 5px 4px;
            font-weight: 700;
            font-family: 'Arial', sans-serif;
        }
        #print-invoice .inv-rx-table tbody td {
            text-align: center;
            padding: 6px 4px;
            border-top: 1px solid #f0ebe0;
            color: #111;
            font-weight: 700;
        }
        #print-invoice .inv-rx-table tbody td.eye-cell {
            background: #f9f6ef;
            color: #c9a84c;
            font-size: 9px;
            font-weight: 900;
            letter-spacing: 1px;
            font-family: 'Arial', sans-serif;
            width: 28px;
        }
        #print-invoice .inv-rx-table tbody tr:nth-child(even) td:not(.eye-cell) {
            background: #fafaf8;
        }

        /* ── Order items list ── */
        #print-invoice .inv-items {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        #print-invoice .inv-item-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 6px 10px;
            background: #fafaf8;
            border: 1px solid #e8e3d8;
            border-radius: 4px;
        }
        #print-invoice .inv-item-name {
            font-size: 8px;
            font-weight: 700;
            color: #111;
            font-family: 'Courier New', monospace;
            letter-spacing: 0.5px;
            flex: 1;
        }
        #print-invoice .inv-item-type {
            font-size: 7px;
            color: #888;
            letter-spacing: 0.5px;
            font-family: 'Arial', sans-serif;
        }
        #print-invoice .inv-item-price {
            font-size: 9px;
            font-weight: 700;
            color: #0d0d0d;
            font-family: 'Courier New', monospace;
            white-space: nowrap;
        }
        #print-invoice .inv-item-placeholder {
            font-size: 8px;
            color: #bbb;
            font-style: italic;
            font-family: 'Arial', sans-serif;
        }

        /* ── Payment summary ── */
        #print-invoice .inv-payment-box {
            background: #0d0d0d;
            color: #fff;
            border-radius: 5px;
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        #print-invoice .inv-payment-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: 'Courier New', monospace;
        }
        #print-invoice .inv-payment-row .lbl {
            font-size: 7px;
            letter-spacing: 1px;
            color: #888;
        }
        #print-invoice .inv-payment-row .val {
            font-size: 9px;
            color: #fff;
            font-weight: 700;
        }
        #print-invoice .inv-payment-row.total-row {
            border-top: 1px solid rgba(201,168,76,0.4);
            padding-top: 6px;
            margin-top: 2px;
        }
        #print-invoice .inv-payment-row.total-row .lbl {
            color: #c9a84c;
            font-size: 7.5px;
        }
        #print-invoice .inv-payment-row.total-row .val {
            color: #c9a84c;
            font-size: 11px;
        }
        #print-invoice .inv-payment-row.balance-row .val {
            color: #ff8a65;
        }
        #print-invoice .inv-payment-row.balance-row.zero .val {
            color: #69f0ae;
        }

        /* ── Dates row ── */
        #print-invoice .inv-dates-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        /* ── Notes section ── */
        #print-invoice .inv-notes-box {
            background: #fafaf8;
            border: 1px dashed #d8d0c0;
            border-radius: 4px;
            padding: 8px 10px;
            font-size: 7.5px;
            color: #444;
            line-height: 1.6;
            font-family: 'Georgia', serif;
            min-height: 32px;
            word-break: break-word;
        }

        /* ── Symptoms section ── */
        #print-invoice .inv-symptoms-box {
            font-size: 7.5px;
            color: #444;
            font-family: 'Courier New', monospace;
            line-height: 1.6;
        }

        /* ── Customer contact info ── */
        #print-invoice .inv-contact-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        #print-invoice .inv-contact-chip {
            display: flex;
            align-items: center;
            gap: 4px;
            background: #f4f1eb;
            border: 1px solid #e0d8c8;
            border-radius: 20px;
            padding: 3px 8px;
        }
        #print-invoice .inv-contact-chip .chip-label {
            font-size: 6px;
            color: #888;
            letter-spacing: 1px;
            font-family: 'Arial', sans-serif;
            font-weight: 700;
        }
        #print-invoice .inv-contact-chip .chip-value {
            font-size: 7.5px;
            color: #111;
            font-family: 'Courier New', monospace;
            font-weight: 700;
        }

        /* ── Footer ── */
        #print-invoice .inv-footer {
            padding: 10px 20px;
            border-top: 1px solid #e8e8e8;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        #print-invoice .inv-footer-msg {
            font-size: 7px;
            color: #999;
            letter-spacing: 0.5px;
            font-style: italic;
            font-family: 'Georgia', serif;
        }
        #print-invoice .inv-footer-sig {
            font-size: 6.5px;
            color: #bbb;
            letter-spacing: 1.5px;
            font-family: 'Arial', sans-serif;
            font-weight: 700;
            text-transform: uppercase;
        }

        /* ── Stamp / watermark ── */
        #print-invoice .inv-stamp {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            border: 2.5px solid #0d0d0d;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 2px;
            padding: 6px;
            opacity: 0.12;
            position: absolute;
            right: 22px;
            bottom: 48px;
        }

        /* ── Mod badge ── */
        #print-invoice .inv-mod-badge {
            display: inline-block;
            font-size: 6.5px;
            background: #fff3e0;
            border: 1px solid #ffb74d;
            color: #e65100;
            border-radius: 20px;
            padding: 2px 7px;
            letter-spacing: 0.5px;
            font-family: 'Arial', sans-serif;
            font-weight: 700;
            vertical-align: middle;
            margin-left: 6px;
        }

        /* ── Habits chips ── */
        #print-invoice .inv-habit-row {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            margin-top: 4px;
        }
        #print-invoice .inv-habit-chip {
            font-size: 6.5px;
            border-radius: 20px;
            padding: 2px 7px;
            font-family: 'Arial', sans-serif;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        #print-invoice .inv-habit-chip.habit {
            background: #e8f5e9;
            border: 1px solid #81c784;
            color: #2e7d32;
        }
        #print-invoice .inv-habit-chip.digital {
            background: #e3f2fd;
            border: 1px solid #64b5f6;
            color: #0d47a1;
        }

        /* ── Signature line ── */
        #print-invoice .inv-sign-area {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
        }
        #print-invoice .inv-sign-line {
            width: 90px;
            border-top: 1px solid #0d0d0d;
            margin-top: 24px;
        }
        #print-invoice .inv-sign-label {
            font-size: 6.5px;
            color: #666;
            letter-spacing: 1px;
            font-family: 'Arial', sans-serif;
            text-align: center;
            width: 90px;
        }

        /* ── Decorative corner ── */
        #print-invoice .inv-corner-deco {
            position: absolute;
            top: 0;
            left: 0;
            width: 40px;
            height: 40px;
            overflow: hidden;
        }
        #print-invoice .inv-corner-deco::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 0;
            height: 0;
            border-style: solid;
            border-width: 40px 40px 0 0;
            border-color: #c9a84c transparent transparent transparent;
        }
    </style>

    <!-- ── Actual invoice sheet ── -->
    <div class="inv-sheet" style="position:relative;">

        <!-- HEADER -->
        <div class="inv-header">
            <div class="inv-header-logo">
                <img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Logo">
            </div>
            <div class="inv-header-info">
                <div class="inv-store-name"><?php echo htmlspecialchars($STORE_NAME); ?></div>
                <div class="inv-store-addr"><?php echo nl2br(htmlspecialchars($STORE_ADDRESS)); ?></div>
            </div>
        </div>

        <!-- GOLD ACCENT BAR -->
        <div class="inv-accent-bar"></div>

        <!-- INVOICE TITLE ROW -->
        <div class="inv-title-row">
            <div class="inv-title-text">Invoice</div>
            <div class="inv-meta">
                <div class="inv-meta-line">NO. <strong><?php echo htmlspecialchars($data['invoice_number']); ?></strong></div>
                <div class="inv-meta-line">DATE <strong><?php echo date('d / m / Y', strtotime($data['examination_date'])); ?></strong></div>
            </div>
        </div>

        <!-- BODY -->
        <div class="inv-body">

            <!-- SECTION: CUSTOMER INFORMATION -->
            <div>
                <div class="inv-section-label">◈ Customer Information</div>
                <div class="inv-cust-grid">

                    <!-- Examination Code -->
                    <div class="inv-cust-item">
                        <div class="inv-field-label">Examination Code</div>
                        <div class="inv-field-value">
                            <span class="inv-code-badge"><?php echo htmlspecialchars($data['examination_code']); ?></span>
                        </div>
                    </div>

                    <!-- Date -->
                    <div class="inv-cust-item">
                        <div class="inv-field-label">Examination Date</div>
                        <div class="inv-field-value"><?php echo date('d M Y', strtotime($data['examination_date'])); ?></div>
                    </div>

                    <!-- Customer Name -->
                    <div class="inv-cust-item full">
                        <div class="inv-field-label">Customer Name</div>
                        <div class="inv-field-value" style="font-size:11px; letter-spacing:1px;"><?php echo strtoupper(htmlspecialchars($data['customer_name'])); ?></div>
                    </div>

                    <!-- Age -->
                    <div class="inv-cust-item">
                        <div class="inv-field-label">Age</div>
                        <div class="inv-field-value"><?php echo (int)$data['age']; ?> Years</div>
                    </div>

                    <!-- Gender -->
                    <div class="inv-cust-item">
                        <div class="inv-field-label">Gender</div>
                        <div class="inv-field-value"><?php echo ucfirst(htmlspecialchars($data['gender'])); ?></div>
                    </div>

                </div>

                <!-- Habits row -->
                <div class="inv-habit-row" style="margin-top:8px;">
                    <?php
                        $habitLabels = [1 => 'Indoor', 2 => 'Outdoor', 3 => 'Indoor & Outdoor'];
                        $digitalLabels = [1 => 'Low Screen', 2 => 'Moderate Screen', 3 => 'High Screen'];
                        $habitVal = (int)($data['visual_habit'] ?? 1);
                        $digitalVal = (int)($data['digital_usage'] ?? 1);
                    ?>
                    <span class="inv-habit-chip habit">👁 <?php echo $habitLabels[$habitVal] ?? 'N/A'; ?></span>
                    <span class="inv-habit-chip digital">💻 <?php echo $digitalLabels[$digitalVal] ?? 'N/A'; ?></span>
                </div>

                <!-- Phone & Address chips (filled by JS from inputs) -->
                <div class="inv-contact-row" style="margin-top:8px;" id="inv-contact-display">
                    <!-- JS will populate this -->
                </div>

            </div>

            <hr class="inv-divider">

            <!-- SECTION: SYMPTOMS & NOTES -->
            <?php if (!empty($data['symptoms']) || !empty($data['exam_notes'])): ?>
            <div>
                <div class="inv-section-label">◈ Clinical Notes</div>
                <div class="inv-cust-grid">
                    <?php if (!empty($data['symptoms'])): ?>
                    <div class="inv-cust-item full">
                        <div class="inv-field-label">Symptoms / Complaints</div>
                        <div class="inv-symptoms-box"><?php echo nl2br(htmlspecialchars($data['symptoms'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($data['exam_notes'])): ?>
                    <div class="inv-cust-item full">
                        <div class="inv-field-label">Exam Notes</div>
                        <div class="inv-notes-box"><?php echo nl2br(htmlspecialchars($data['exam_notes'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <hr class="inv-divider">
            <?php endif; ?>

            <!-- SECTION: PRESCRIPTION -->
            <div>
                <div class="inv-section-label">
                    ◈ Prescription
                    <?php if ($data['lens_modification'] == 1): ?>
                        <span class="inv-mod-badge">✎ MODIFIED</span>
                    <?php endif; ?>
                </div>
                <table class="inv-rx-table">
                    <thead>
                        <tr>
                            <th style="width:28px;">EYE</th>
                            <th>SPH</th>
                            <th>CYL</th>
                            <th>AXIS</th>
                            <th>ADD</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="eye-cell">R</td>
                            <td><?php
                                $r_sph = ($data['lens_modification'] == 1 && isset($data['mod_r_sph']) && $data['mod_r_sph'] !== '') 
                                    ? $data['mod_r_sph'] : $data['new_r_sph'];
                                echo $r_sph ?: '—';
                            ?></td>
                            <td><?php
                                $r_cyl = ($data['lens_modification'] == 1 && isset($data['mod_r_cyl']) && $data['mod_r_cyl'] !== '') 
                                    ? $data['mod_r_cyl'] : $data['new_r_cyl'];
                                echo $r_cyl ?: '—';
                            ?></td>
                            <td><?php
                                $r_ax = ($data['lens_modification'] == 1 && isset($data['mod_r_ax']) && $data['mod_r_ax'] !== '') 
                                    ? $data['mod_r_ax'] : $data['new_r_ax'];
                                echo $r_ax ?: '—';
                            ?></td>
                            <td><?php
                                $r_add = ($data['lens_modification'] == 1 && isset($data['mod_r_add']) && $data['mod_r_add'] !== '') 
                                    ? $data['mod_r_add'] : $data['new_r_add'];
                                echo $r_add ?: '—';
                            ?></td>
                        </tr>
                        <tr>
                            <td class="eye-cell">L</td>
                            <td><?php
                                $l_sph = ($data['lens_modification'] == 1 && isset($data['mod_l_sph']) && $data['mod_l_sph'] !== '') 
                                    ? $data['mod_l_sph'] : $data['new_l_sph'];
                                echo $l_sph ?: '—';
                            ?></td>
                            <td><?php
                                $l_cyl = ($data['lens_modification'] == 1 && isset($data['mod_l_cyl']) && $data['mod_l_cyl'] !== '') 
                                    ? $data['mod_l_cyl'] : $data['new_l_cyl'];
                                echo $l_cyl ?: '—';
                            ?></td>
                            <td><?php
                                $l_ax = ($data['lens_modification'] == 1 && isset($data['mod_l_ax']) && $data['mod_l_ax'] !== '') 
                                    ? $data['mod_l_ax'] : $data['new_l_ax'];
                                echo $l_ax ?: '—';
                            ?></td>
                            <td><?php
                                $l_add = ($data['lens_modification'] == 1 && isset($data['mod_l_add']) && $data['mod_l_add'] !== '') 
                                    ? $data['mod_l_add'] : $data['new_l_add'];
                                echo $l_add ?: '—';
                            ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <hr class="inv-divider">

            <!-- SECTION: ORDER ITEMS (Frame + Lens) -->
            <div>
                <div class="inv-section-label">◈ Items Ordered</div>
                <div class="inv-items" id="inv-items-list">
                    <!-- Filled by JS from customer selection state -->
                    <div class="inv-item-placeholder">— No item selected —</div>
                </div>
            </div>

            <hr class="inv-divider">

            <!-- SECTION: PAYMENT -->
            <div>
                <div class="inv-section-label">◈ Payment Summary</div>
                <div class="inv-payment-box">
                    <div class="inv-payment-row">
                        <span class="lbl">TOTAL AMOUNT</span>
                        <span class="val" id="inv-pay-total">IDR —</span>
                    </div>
                    <div class="inv-payment-row">
                        <span class="lbl">AMOUNT PAID</span>
                        <span class="val" id="inv-pay-paid">IDR —</span>
                    </div>
                    <div class="inv-payment-row total-row balance-row" id="inv-pay-balance-row">
                        <span class="lbl">BALANCE DUE</span>
                        <span class="val" id="inv-pay-balance">IDR —</span>
                    </div>
                </div>

                <!-- Dates -->
                <div class="inv-dates-row" style="margin-top:8px;">
                    <div class="inv-cust-item">
                        <div class="inv-field-label">Order Date</div>
                        <div class="inv-field-value"><?php echo date('d M Y', strtotime($data['examination_date'])); ?></div>
                    </div>
                    <div class="inv-cust-item">
                        <div class="inv-field-label">Est. Ready Date</div>
                        <div class="inv-field-value" id="inv-due-date">—</div>
                    </div>
                </div>
            </div>

        </div><!-- /inv-body -->

        <!-- FOOTER -->
        <div class="inv-footer" style="position:relative;">
            <div>
                <div class="inv-footer-msg">Thank you for your trust. Please keep this invoice for your records.</div>
                <div style="margin-top:6px;">
                    <div class="inv-sign-area" style="align-items:flex-start;">
                        <div style="font-size:6px;color:#aaa;letter-spacing:1px;font-family:Arial,sans-serif;font-weight:700;">RECEIVED BY</div>
                        <div class="inv-sign-line"></div>
                        <div class="inv-sign-label">Customer Signature</div>
                    </div>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:6px;color:#aaa;letter-spacing:1px;font-family:Arial,sans-serif;font-weight:700;margin-bottom:2px;">AUTHORIZED BY</div>
                <div class="inv-sign-line"></div>
                <div class="inv-sign-label"><?php echo htmlspecialchars($STORE_NAME); ?></div>
                <div style="margin-top:10px;">
                    <div class="inv-footer-sig"><?php echo htmlspecialchars($STORE_NAME); ?></div>
                </div>
            </div>
        </div>

        <!-- Decorative corner -->
        <div class="inv-corner-deco"></div>

    </div><!-- /inv-sheet -->
</div><!-- /print-invoice -->


<!-- ============================================================
     BAGIAN 3 — JAVASCRIPT
     Tambahkan ke dalam blok <script> di bagian bawah invoice.php,
     atau letakkan sebelum </body>.
     ============================================================ -->
<script>
/**
 * openPrintInvoice()
 * Kumpulkan data dari UI, isi print-invoice, lalu panggil window.print()
 * Ganti onclick tombol PRINT INVOICE menjadi: onclick="openPrintInvoice()"
 */
function openPrintInvoice() {

    /* ── 1. Phone & Address dari input ── */
    var phone   = (document.getElementById('lr-customer-phone')   || {}).value || '';
    var address = (document.getElementById('lr-customer-address') || {}).value || '';
    var contactDiv = document.getElementById('inv-contact-display');
    if (contactDiv) {
        var chips = '';
        if (phone) {
            chips += '<div class="inv-contact-chip">'
                   + '<span class="chip-label">PHONE</span>'
                   + '<span class="chip-value">' + escH(phone) + '</span>'
                   + '</div>';
        }
        if (address) {
            chips += '<div class="inv-contact-chip">'
                   + '<span class="chip-label">ADDRESS</span>'
                   + '<span class="chip-value">' + escH(address) + '</span>'
                   + '</div>';
        }
        contactDiv.innerHTML = chips;
    }

    /* ── 2. Order items ── */
    var itemsList = document.getElementById('inv-items-list');
    if (itemsList) {
        var rows = '';

        // Frame (baca dari elemen yang diisi JS bestSelectionBar)
        var frameNameEl  = document.getElementById('lr-sel-frame-name');
        var framePriceEl = document.getElementById('lr-sel-frame-price');
        if (frameNameEl && frameNameEl.textContent.trim()) {
            rows += buildItemRow('FRAME', frameNameEl.textContent.trim(), framePriceEl ? framePriceEl.textContent.trim() : '');
        }

        // Lens (baca dari elemen yang diisi JS selection bar)
        var lensNameEl  = document.getElementById('lr-sel-lens-name');
        var lensPriceEl = document.getElementById('lr-sel-lens-price');
        if (lensNameEl && lensNameEl.textContent.trim()) {
            rows += buildItemRow('LENS', lensNameEl.textContent.trim(), lensPriceEl ? lensPriceEl.textContent.trim() : '');
        }

        // Fallback: baca dari teks di #lr-selection-bar-inner
        if (!rows) {
            var innerEl = document.getElementById('lr-selection-bar-inner');
            if (innerEl && innerEl.textContent.trim()) {
                // Buat satu baris generik dari teks yang ada
                var innerText = innerEl.innerText.replace(/\n+/g, ' · ').trim();
                if (innerText) {
                    rows += '<div class="inv-item-row">'
                          + '<span class="inv-item-name">' + escH(innerText.substring(0, 120)) + '</span>'
                          + '</div>';
                }
            }
        }

        itemsList.innerHTML = rows || '<div class="inv-item-placeholder">— Belum ada item dipilih —</div>';
    }

    /* ── 3. Payment fields ── */
    var totalEl   = document.getElementById('lr-total-amount');
    var paidEl    = document.getElementById('lr-amount-paid');
    var balanceEl = document.getElementById('lr-balance-display');
    var dueEl     = document.getElementById('lr-due-date-box');

    var setVal = function(id, text) {
        var el = document.getElementById(id);
        if (el) el.textContent = text || '—';
    };

    setVal('inv-pay-total',   totalEl   ? (totalEl.value || 'IDR —')   : 'IDR —');
    setVal('inv-pay-paid',    paidEl    ? (paidEl.value  || 'IDR —')   : 'IDR —');
    setVal('inv-pay-balance', balanceEl ? balanceEl.textContent         : 'IDR —');
    setVal('inv-due-date',    dueEl     ? dueEl.textContent             : '—');

    /* ── 4. Balance styling: hijau jika 0, orange jika ada sisa ── */
    var balRow = document.getElementById('inv-pay-balance-row');
    if (balRow && balanceEl) {
        var balText = balanceEl.textContent.replace(/[^0-9]/g, '');
        if (parseInt(balText || '0', 10) === 0) {
            balRow.classList.add('zero');
        } else {
            balRow.classList.remove('zero');
        }
    }

    /* ── 5. Tampilkan print sheet dan print ── */
    document.getElementById('print-invoice').style.display = 'block';
    window.print();
    document.getElementById('print-invoice').style.display = 'none';
}

/* Helper: build one item row HTML */
function buildItemRow(type, name, price) {
    return '<div class="inv-item-row">'
         + '<div><div class="inv-item-type">' + escH(type) + '</div>'
         + '<div class="inv-item-name">' + escH(name) + '</div></div>'
         + (price ? '<div class="inv-item-price">' + escH(price) + '</div>' : '')
         + '</div>';
}

/* Helper: escape HTML */
function escH(str) {
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}
</script>
