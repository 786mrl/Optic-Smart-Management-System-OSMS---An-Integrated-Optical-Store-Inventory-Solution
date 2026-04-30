<?php
/*
 * print_invoice_snippet.php — LENZA OPTIC
 * Halaman cetak mandiri. Dipanggil dari tombol PRINT INVOICE
 * melalui openPrintPage() di invoice.php.
 *
 * GET params: inv, frame, frame_price, lens, lens_price,
 *             rx_mode, total, paid, balance, phone, due_date, auto
 */

session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

include 'db_config.php';
include 'config_helper.php';

$invoice_num = mysqli_real_escape_string($conn, trim($_GET['inv'] ?? ''));
if (empty($invoice_num)) die("<p style='padding:2rem;color:red;font-family:sans-serif;'>Invoice tidak valid.</p>");

$query = "SELECT ce.*,
          pm.od_sph AS mod_r_sph, pm.od_cyl AS mod_r_cyl,
          pm.od_axis AS mod_r_ax,  pm.od_add AS mod_r_add,
          pm.os_sph AS mod_l_sph, pm.os_cyl AS mod_l_cyl,
          pm.os_axis AS mod_l_ax,  pm.os_add AS mod_l_add
          FROM customer_examinations ce
          LEFT JOIN prescription_modifications pm
            ON ce.invoice_number = pm.invoice_number
          WHERE ce.invoice_number = '$invoice_num' LIMIT 1";
$data = mysqli_fetch_assoc(mysqli_query($conn, $query));
if (!$data) die("<p style='padding:2rem;color:red;font-family:sans-serif;'>Data tidak ditemukan.</p>");

/* ── Ambil settings langsung dari DB ── */
$settingsRes = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");
$cfg = [];
while ($r = mysqli_fetch_assoc($settingsRes)) $cfg[$r['setting_key']] = $r['setting_value'];

$storeName   = $STORE_NAME         ?? ($cfg['store_name']                    ?? 'LENZA OPTIC');
$storePhone  = $STORE_PHONE        ?? ($cfg['store_phone']                   ?? '');
$brandPath   = $BRAND_IMAGE_PATH   ?? ($cfg['brand_image_location']          ?? '');
$barcodePath = $cfg['barcode_guide_image_location'] ?? ''; // selalu dari DB

/* ── Helper: raw DB → dioptri float ── */
function piValRaw($raw) {
    $v = floatval(str_replace('+', '', $raw ?? '0'));
    if (abs($v) >= 10) $v /= 100.0;
    return round($v, 2);
}
function piValFmt($raw) {
    $v = piValRaw($raw);
    if (abs($v) < 0.01) return null;
    return ($v > 0 ? '+' : '') . number_format($v, 2, '.', '');
}

/* ── Set resep ── */
$rxMode     = $_GET['rx_mode'] ?? 'original';
$isModified = ($rxMode === 'modified' && $data['lens_modification'] == 1
               && isset($data['mod_r_sph']) && $data['mod_r_sph'] !== '');
function piRx($mod, $mv, $ov) { return ($mod && $mv !== '') ? $mv : $ov; }

$rx = [
    'r_sph' => piValFmt(piRx($isModified, $data['mod_r_sph']??'', $data['new_r_sph']??'')),
    'r_cyl' => piValFmt(piRx($isModified, $data['mod_r_cyl']??'', $data['new_r_cyl']??'')),
    'r_add' => piValFmt(piRx($isModified, $data['mod_r_add']??'', $data['new_r_add']??'')),
    'l_sph' => piValFmt(piRx($isModified, $data['mod_l_sph']??'', $data['new_l_sph']??'')),
    'l_cyl' => piValFmt(piRx($isModified, $data['mod_l_cyl']??'', $data['new_l_cyl']??'')),
    'l_add' => piValFmt(piRx($isModified, $data['mod_l_add']??'', $data['new_l_add']??'')),
];

/* ── GET data ── */
$gFrame      = trim($_GET['frame']        ?? '');
$gFramePrice = (int)($_GET['frame_price'] ?? 0);
$gLens       = trim($_GET['lens']         ?? '');
$gLensPrice  = (int)($_GET['lens_price']  ?? 0);
$gTotal      = (int)($_GET['total']       ?? 0);
$gPaid       = (int)($_GET['paid']        ?? 0);
$gBalance    = (int)($_GET['balance']     ?? ($gTotal - $gPaid));
$gPhone      = trim($_GET['phone']        ?? '');
$gDueDate    = trim($_GET['due_date']     ?? '');

/* ── Data dari DB ── */
$symptoms  = trim($data['symptoms']   ?? '');
$examNotes = trim($data['exam_notes'] ?? '');

function piIDR($n) {
    if ($n <= 0) return '—';
    return 'Rp&nbsp;' . number_format((int)$n, 0, ',', '.');
}
function rxCell($v) {
    return $v !== null ? htmlspecialchars($v) : '<span class="rx-dash">—</span>';
}

$isLunas      = ($gBalance <= 0 && $gTotal > 0);
$statusLabel  = $isLunas ? 'Lunas' : 'DP / Belum Lunas';
$statusClass  = $isLunas ? 'lunas' : 'dp';
$orderDateFmt = date('d M Y', strtotime($data['examination_date']));
$orderDateNum = date('d/m/Y', strtotime($data['examination_date']));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars($data['invoice_number']); ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
html,body{background:#E8E8E5;display:flex;justify-content:center;padding:50px 0 40px;font-family:'Segoe UI','Helvetica Neue',Arial,sans-serif;}

/* ── Sheet tepat A5, tidak boleh overflow ── */
.inv-sheet{
    width:148mm; height:210mm;          /* TEPAT A5 */
    background:#F5F4F0;
    display:flex;flex-direction:column;
    overflow:hidden;                    /* potong jika melebihi */
    box-shadow:0 4px 32px rgba(0,0,0,.18);
}

/* HEADER — kompak */
.inv-header{background:#2C2C2A;padding:12px 18px 11px;flex-shrink:0;}
.inv-dots{display:flex;gap:5px;margin-bottom:7px;}
.inv-dot{width:7px;height:7px;border-radius:50%;}
.dot-a{background:#EF9F27;}.dot-b{background:rgba(255,255,255,.3);}.dot-c{background:rgba(255,255,255,.12);}
.inv-header-top{display:flex;justify-content:space-between;align-items:flex-start;}
.inv-brand{display:flex;align-items:center;gap:9px;}
.inv-brand-logo{height:24px;width:auto;object-fit:contain;}
.inv-brand-name{font-size:15px;font-weight:500;color:#fff;letter-spacing:.4px;}
.inv-brand-sub{font-size:9px;color:rgba(255,255,255,.45);margin-top:2px;}
.inv-num-block{text-align:right;}
.inv-num-lbl{display:inline-block;border:1px solid rgba(239,159,39,.5);border-radius:3px;padding:1px 8px;margin-bottom:4px;}
.inv-num-lbl span{font-size:8px;color:#FAC775;letter-spacing:.12em;font-weight:600;}
.inv-num-val{font-size:18px;font-weight:500;color:#EF9F27;line-height:1;}
.inv-num-date{font-size:9px;color:rgba(255,255,255,.38);margin-top:3px;}
.inv-meta-row{display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem;margin-top:.8rem;padding-top:.8rem;border-top:.5px solid rgba(255,255,255,.1);}
.mk{font-size:8px;color:rgba(255,255,255,.38);margin-bottom:2px;letter-spacing:.08em;font-weight:600;text-transform:uppercase;}
.mv{font-size:10.5px;color:#fff;font-weight:500;}
.ms{font-size:9px;color:rgba(255,255,255,.45);margin-top:1px;}
.s-badge{display:inline-block;font-size:9px;padding:2px 8px;border-radius:3px;font-weight:500;}
.s-badge.lunas{background:rgba(0,200,100,.15);color:#4CD98A;border:.5px solid rgba(0,200,100,.3);}
.s-badge.dp{background:rgba(239,159,39,.18);color:#FAC775;border:.5px solid rgba(239,159,39,.35);}

/* ACCENT */
.inv-bar{height:3px;background:#EF9F27;flex-shrink:0;}

/* BODY — isi sisa tinggi secara proporsional */
.inv-body{
    padding:.9rem 1.25rem;
    flex:1;
    display:flex;flex-direction:column;gap:.7rem;
    background:#F5F4F0;
    overflow:hidden;
    min-height:0;
}
.sec-lbl{font-size:8px;color:#BA7517;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:6px;}

/* RESEP */
.rx-wrap{background:#EDECEA;border-radius:6px;padding:9px 11px;}
.rx-table{width:100%;border-collapse:collapse;}
.rx-table thead tr{border-bottom:1px solid #EF9F27;}
.rx-table thead th{padding:4px 6px 4px 0;font-weight:600;font-size:8px;color:#BA7517;letter-spacing:.07em;text-align:center;}
.rx-table thead th:first-child{text-align:left;width:28px;}
.rx-table tbody td{text-align:center;padding:6px 6px;font-size:12px;font-weight:500;color:#1a1a1a;}
.rx-table tbody td:first-child{text-align:left;font-size:8.5px;color:#777;font-weight:600;letter-spacing:.06em;}
.rx-table tbody tr:first-child td{border-bottom:.5px solid #D8D5CF;}
.rx-dash{color:#C0BDB8;}
.mod-badge{display:inline-block;font-size:7.5px;background:#fff3e0;border:1px solid #ffb74d;color:#e65100;border-radius:20px;padding:1px 6px;font-weight:700;vertical-align:middle;margin-left:4px;}

/* SYMPTOMS — compact */
.sym-wrap{background:#EDECEA;border-radius:6px;padding:8px 11px;}
.sym-key{font-size:8px;color:#BA7517;font-weight:600;letter-spacing:.06em;text-transform:uppercase;margin-bottom:4px;}
.sym-key.note{color:#888;margin-top:7px;}
.sym-text{font-size:10px;color:#444;line-height:1.5;white-space:pre-wrap;word-break:break-word;}

/* ITEMS */
.items-wrap{border-radius:6px;overflow:hidden;border:.5px solid #D8D5CF;}
.items-head{display:grid;grid-template-columns:1fr 50px 90px;background:#EDECEA;padding:6px 10px;font-size:8px;font-weight:600;color:#888;letter-spacing:.09em;text-transform:uppercase;}
.items-head span:nth-child(2){text-align:center;}.items-head span:nth-child(3){text-align:right;}
.item-row{display:grid;grid-template-columns:1fr 50px 90px;padding:8px 10px;border-top:.5px solid #D8D5CF;align-items:center;background:#fff;}
.iname{font-size:11px;font-weight:500;color:#1a1a1a;}
.isub{font-size:9px;color:#888;margin-top:1px;}
.iqty{text-align:center;font-size:11px;color:#333;}
.iprice{text-align:right;font-size:11px;color:#333;}
.iprice.free{color:#BA7517;font-weight:600;}

/* TOTALS */
.tot-wrap{display:flex;justify-content:flex-end;}
.tot-inner{min-width:200px;}
.tot-row{display:flex;justify-content:space-between;font-size:10px;color:#777;padding:3px 0;}
.tot-row.div{border-bottom:.5px solid #D8D5CF;}
.v-orange{color:#ff8a4d;font-weight:600;}.v-green{color:#4CD98A;font-weight:600;}
.tot-box{display:flex;justify-content:space-between;align-items:center;padding:7px 11px;background:#2C2C2A;border-radius:6px;margin-top:7px;}
.tot-box .lbl{color:#fff;font-size:11px;font-weight:500;}.tot-box .val{color:#EF9F27;font-size:13px;font-weight:600;}

/* FOOTER — kompak */
.inv-footer{
    border-top:.5px solid #D8D5CF;
    padding:.75rem 1.25rem .85rem;
    display:grid;grid-template-columns:auto 1fr;
    gap:.9rem;align-items:start;
    background:#F5F4F0;
    flex-shrink:0;
}
/* Barcode diperbesar jadi 90x90 */
.bc-wrap{display:flex;flex-direction:column;align-items:center;gap:4px;}
.bc-box{background:#EDECEA;border-radius:6px;padding:6px;}
.bc-box img{width:90px;height:90px;object-fit:contain;display:block;}
.bc-cap{font-size:8px;color:#999;text-align:center;line-height:1.4;}
.foot-notes{display:flex;flex-direction:column;gap:6px;}
.wa-box{background:#FAEEDA;border-radius:6px;padding:8px 10px;}
.wa-inner{display:flex;align-items:flex-start;gap:6px;}
.wa-title{font-size:8px;color:#633806;font-weight:600;letter-spacing:.05em;text-transform:uppercase;margin-bottom:2px;}
.wa-body{font-size:9px;color:#854F0B;line-height:1.5;}
.wa-body strong{color:#633806;}
.info-box{background:#EDECEA;border-radius:6px;padding:8px 10px;font-size:9px;color:#666;line-height:1.55;}
.info-box strong{color:#333;}
.dates-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:2px;}
.dkey{font-size:7.5px;color:#999;letter-spacing:.08em;font-weight:600;margin-bottom:2px;text-transform:uppercase;}
.dval{font-size:10px;color:#333;font-family:monospace;}
.dval.amber{color:#BA7517;}

/* STORE BAR */
.store-bar{background:#2C2C2A;padding:6px 1.25rem;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;}
.sbar-name{font-size:8.5px;color:rgba(255,255,255,.5);letter-spacing:1px;text-transform:uppercase;font-weight:600;}
.sbar-phone{font-size:8.5px;color:rgba(255,255,255,.35);font-family:monospace;}

/* NO-PRINT */
.no-print{display:flex;}
@media print{
    html,body{background:#fff!important;padding:0!important;}
    .no-print{display:none!important;}
    .inv-sheet{box-shadow:none;height:210mm;width:148mm;}
    @page{
        size:A5 portrait;
        margin:0;
        /* Sembunyikan header/footer browser saat print */
        margin-top:0;margin-bottom:0;margin-left:0;margin-right:0;
    }
    /* Chrome/Edge: paksa hilangkan header footer */
    html{
        -webkit-print-color-adjust:exact;
    }
}
</style>
</head>
<body>

<div class="no-print" style="position:fixed;top:10px;left:50%;transform:translateX(-50%);flex-direction:column;align-items:center;gap:8px;z-index:999;">
    <div style="display:flex;gap:10px;">
        <button onclick="window.close()"
            style="background:#2C2C2A;color:#EF9F27;border:1px solid rgba(239,159,39,.4);border-radius:6px;padding:8px 18px;font-size:12px;cursor:pointer;font-family:inherit;">
            ✕ Tutup
        </button>
        <button onclick="window.print()"
            style="background:#EF9F27;color:#fff;border:none;border-radius:6px;padding:8px 18px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;">
            🖨 Cetak
        </button>
    </div>
    <div style="background:rgba(44,44,42,.9);color:rgba(255,255,255,.6);font-size:10px;padding:5px 12px;border-radius:5px;text-align:center;line-height:1.5;">
        Saat dialog print muncul → <strong style="color:#FAC775;">More settings</strong> → matikan <strong style="color:#FAC775;">Headers and footers</strong>
    </div>
</div>

<div class="inv-sheet">

    <!-- HEADER -->
    <div class="inv-header">
        <div class="inv-header-top">
            <div>
                <div class="inv-dots">
                    <div class="inv-dot dot-a"></div>
                    <div class="inv-dot dot-b"></div>
                    <div class="inv-dot dot-c"></div>
                </div>
                <div class="inv-brand">
                    <?php if (!empty($brandPath)): ?>
                    <img class="inv-brand-logo" src="<?php echo htmlspecialchars($brandPath); ?>" alt="Logo"
                         onerror="this.style.display='none'">
                    <?php else: ?>
                    <svg width="40" height="26" viewBox="0 0 60 36" fill="none">
                        <circle cx="15" cy="18" r="12" stroke="#EF9F27" stroke-width="2.5"/>
                        <circle cx="45" cy="18" r="12" stroke="#EF9F27" stroke-width="2.5"/>
                        <line x1="27" y1="18" x2="33" y2="18" stroke="#EF9F27" stroke-width="2.5"/>
                        <line x1="3" y1="11" x2="0" y2="7" stroke="#EF9F27" stroke-width="2" stroke-linecap="round"/>
                        <line x1="57" y1="11" x2="60" y2="7" stroke="#EF9F27" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <?php endif; ?>
                    <div>
                        <div class="inv-brand-name"><?php echo htmlspecialchars($storeName); ?></div>
                        <div class="inv-brand-sub">Premium Eyewear &amp; Optometry</div>
                    </div>
                </div>
            </div>
            <div class="inv-num-block">
                <div class="inv-num-lbl"><span>INVOICE</span></div>
                <div class="inv-num-val"><?php echo htmlspecialchars($data['invoice_number']); ?></div>
                <div class="inv-num-date"><?php echo $orderDateFmt; ?></div>
            </div>
        </div>
        <div class="inv-meta-row">
            <div>
                <div class="mk">Tanggal</div>
                <div class="mv"><?php echo $orderDateFmt; ?></div>
            </div>
            <div>
                <div class="mk">Pelanggan</div>
                <div class="mv"><?php echo htmlspecialchars($data['customer_name']); ?></div>
                <?php if ($gPhone): ?><div class="ms"><?php echo htmlspecialchars($gPhone); ?></div><?php endif; ?>
            </div>
            <div>
                <div class="mk">Status</div>
                <span class="s-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
            </div>
        </div>
    </div>

    <div class="inv-bar"></div>

    <!-- BODY -->
    <div class="inv-body">

        <!-- RESEP -->
        <div>
            <div class="sec-lbl">
                Resep Kacamata
                <?php if ($isModified): ?><span class="mod-badge">✎ Dimodifikasi</span><?php endif; ?>
            </div>
            <div class="rx-wrap">
                <table class="rx-table">
                    <thead><tr><th></th><th>SPH</th><th>CYL</th><th>ADD</th></tr></thead>
                    <tbody>
                        <tr>
                            <td>OD</td>
                            <td><?php echo rxCell($rx['r_sph']); ?></td>
                            <td><?php echo rxCell($rx['r_cyl']); ?></td>
                            <td><?php echo rxCell($rx['r_add']); ?></td>
                        </tr>
                        <tr>
                            <td>OS</td>
                            <td><?php echo rxCell($rx['l_sph']); ?></td>
                            <td><?php echo rxCell($rx['l_cyl']); ?></td>
                            <td><?php echo rxCell($rx['l_add']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SYMPTOMS -->
        <?php if (!empty($symptoms) || !empty($examNotes)): ?>
        <div>
            <div class="sec-lbl">Keluhan &amp; Catatan Klinis</div>
            <div class="sym-wrap">
                <?php if (!empty($symptoms)): ?>
                <div class="sym-key">Keluhan / Gejala</div>
                <div class="sym-text"><?php echo htmlspecialchars($symptoms); ?></div>
                <?php endif; ?>
                <?php if (!empty($examNotes)): ?>
                <div class="sym-key note">Catatan Pemeriksaan</div>
                <div class="sym-text"><?php echo htmlspecialchars($examNotes); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- PRODUK -->
        <div>
            <div class="sec-lbl">Produk / Layanan</div>
            <div class="items-wrap">
                <div class="items-head">
                    <span>Produk / Layanan</span><span>Qty</span><span>Harga</span>
                </div>

                <?php if (!empty($gFrame)): ?>
                <div class="item-row">
                    <div>
                        <div class="iname"><?php echo htmlspecialchars($gFrame); ?></div>
                        <div class="isub">Frame kacamata</div>
                    </div>
                    <div class="iqty">1 pcs</div>
                    <div class="iprice"><?php echo $gFramePrice > 0 ? piIDR($gFramePrice) : '—'; ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($gLens)): ?>
                <div class="item-row">
                    <div>
                        <div class="iname"><?php echo htmlspecialchars($gLens); ?></div>
                        <div class="isub">Rx: <?php echo $isModified ? 'Modifikasi' : 'Original'; ?></div>
                    </div>
                    <div class="iqty">1 pasang</div>
                    <div class="iprice"><?php echo $gLensPrice > 0 ? piIDR($gLensPrice) : '—'; ?></div>
                </div>
                <?php endif; ?>

                <?php if (empty($gFrame) && empty($gLens)): ?>
                <div class="item-row" style="grid-template-columns:1fr;">
                    <div style="font-size:10px;color:#aaa;font-style:italic;text-align:center;">Belum ada item dipilih</div>
                </div>
                <?php endif; ?>

                <div class="item-row">
                    <div><div class="iname">Cek mata &amp; konsultasi</div></div>
                    <div class="iqty">1</div>
                    <div class="iprice free">Gratis</div>
                </div>
            </div>
        </div>

        <!-- PAYMENT -->
        <div class="tot-wrap">
            <div class="tot-inner">
                <div class="tot-row div"><span>Subtotal</span><span><?php echo piIDR($gTotal); ?></span></div>
                <div class="tot-row"><span>Bayar</span><span><?php echo piIDR($gPaid); ?></span></div>
                <div class="tot-row div">
                    <span>Sisa</span>
                    <span class="<?php echo $gBalance <= 0 ? 'v-green' : 'v-orange'; ?>">
                        <?php echo $gBalance <= 0 ? 'Lunas' : piIDR($gBalance); ?>
                    </span>
                </div>
                <div class="tot-box">
                    <span class="lbl">Total</span>
                    <span class="val"><?php echo piIDR($gTotal); ?></span>
                </div>
            </div>
        </div>

    </div><!-- /body -->

    <!-- FOOTER -->
    <div class="inv-footer">
        <div class="bc-wrap">
            <div class="bc-box">
                <?php if (!empty($barcodePath)): ?>
                <img src="<?php echo htmlspecialchars($barcodePath); ?>" alt="Barcode panduan"
                     onerror="this.style.display='none'">
                <?php else: ?>
                <svg width="72" height="72" viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg">
                    <rect x="2"  y="2"  width="22" height="22" rx="2" fill="#2C2C2A"/>
                    <rect x="5"  y="5"  width="16" height="16" rx="1" fill="#F5F4F0"/>
                    <rect x="8"  y="8"  width="10" height="10" rx=".5" fill="#2C2C2A"/>
                    <rect x="56" y="2"  width="22" height="22" rx="2" fill="#2C2C2A"/>
                    <rect x="59" y="5"  width="16" height="16" rx="1" fill="#F5F4F0"/>
                    <rect x="62" y="8"  width="10" height="10" rx=".5" fill="#2C2C2A"/>
                    <rect x="2"  y="56" width="22" height="22" rx="2" fill="#2C2C2A"/>
                    <rect x="5"  y="59" width="16" height="16" rx="1" fill="#F5F4F0"/>
                    <rect x="8"  y="62" width="10" height="10" rx=".5" fill="#2C2C2A"/>
                    <rect x="28" y="28" width="5"  height="5"  fill="#EF9F27"/>
                    <rect x="35" y="28" width="7"  height="3"  fill="#2C2C2A"/>
                    <rect x="44" y="28" width="3"  height="8"  fill="#2C2C2A"/>
                    <rect x="49" y="28" width="7"  height="3"  fill="#2C2C2A"/>
                    <rect x="28" y="35" width="4"  height="7"  fill="#2C2C2A"/>
                    <rect x="35" y="33" width="6"  height="6"  fill="#2C2C2A"/>
                    <rect x="43" y="34" width="5"  height="4"  fill="#2C2C2A"/>
                    <rect x="50" y="33" width="4"  height="7"  fill="#2C2C2A"/>
                </svg>
                <?php endif; ?>
            </div>
            <div class="bc-cap">Scan untuk info<br>panduan &amp; garansi</div>
        </div>
        <div class="foot-notes">
            <div class="wa-box">
                <div class="wa-inner">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" style="flex-shrink:0;margin-top:1px;">
                        <rect x="3" y="3" width="18" height="18" rx="5" fill="#EF9F27"/>
                        <path d="M8 12.5l2.5 2.5 5.5-5.5" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <div>
                        <div class="wa-title">Update Status Kacamata via WhatsApp</div>
                        <div class="wa-body">Informasi kesiapan kacamata Anda akan disampaikan melalui <strong>WhatsApp</strong> ke nomor yang terdaftar. Pastikan nomor WA Anda aktif dan dapat dihubungi.</div>
                    </div>
                </div>
            </div>
            <div class="info-box">
                Barcode di samping memuat <strong>petunjuk penggunaan</strong>, <strong>aturan garansi</strong>, dan informasi layanan <?php echo htmlspecialchars($storeName); ?>. Scan menggunakan kamera HP Anda.
            </div>
            <div class="dates-grid">
                <div>
                    <div class="dkey">Tgl Pesan</div>
                    <div class="dval"><?php echo $orderDateNum; ?></div>
                </div>
                <div>
                    <div class="dkey">Est. Siap</div>
                    <div class="dval amber"><?php echo htmlspecialchars($gDueDate) ?: '—'; ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- STORE BAR -->
    <div class="store-bar">
        <div class="sbar-name"><?php echo htmlspecialchars($storeName); ?></div>
        <div class="sbar-phone"><?php echo htmlspecialchars($storePhone); ?></div>
    </div>

</div><!-- /sheet -->

<script>
/* Print hanya ketika user klik tombol Cetak */
</script>
</body>
</html>