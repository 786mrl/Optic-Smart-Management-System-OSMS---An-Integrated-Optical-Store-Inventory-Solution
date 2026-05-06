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

/* ── Hitung discount (selisih harga item vs total yang dibayar) ── */
$gItemsTotal = $gFramePrice + $gLensPrice;
$gDiscount   = ($gItemsTotal > $gTotal && $gTotal > 0) ? ($gItemsTotal - $gTotal) : 0;

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

/* ── Sapaan WA berdasarkan umur ── */
$customerAge  = (int)($data['age'] ?? $data['customer_age'] ?? 0);
$customerName = trim($data['customer_name'] ?? '');

// Hitung umur dari tanggal lahir jika kolom dob tersedia
if ($customerAge === 0 && !empty($data['date_of_birth'])) {
    $dob = new DateTime($data['date_of_birth']);
    $now = new DateTime();
    $customerAge = (int)$dob->diff($now)->y;
}

// Salam & penutup Arab
$wasSalam  = "اَلسَّلَامُ عَلَيْكُمْ وَرَحْمَةُ اللهِ وَبَرَكَاتُهُ";
$wassSalam = "وَالسَّلَامُ عَلَيْكُمْ وَرَحْمَةُ اللهِ وَبَرَكَاتُهُ";

// Deteksi gender dari data (jika tersedia), default netral
$gender = strtolower(trim($data['gender'] ?? $data['sex'] ?? ''));
if ($gender === 'l' || $gender === 'male' || $gender === 'laki' || $gender === 'laki-laki') {
    $sapaPanggil = 'Bapak';
} elseif ($gender === 'p' || $gender === 'female' || $gender === 'perempuan' || $gender === 'wanita') {
    $sapaPanggil = 'Ibu';
} else {
    $sapaPanggil = 'Bapak/Ibu';
}

if ($customerAge > 0 && $customerAge <= 12) {
    // Anak-anak: pesan ke orang tua, sebut "Adik" untuk si anak
    $anakPanggil = ($gender === 'l' || $gender === 'male' || $gender === 'laki' || $gender === 'laki-laki') ? 'Adik' : (($gender === 'p' || $gender === 'female' || $gender === 'perempuan' || $gender === 'wanita') ? 'Adik' : 'Adik');
    $waSapaan  = "Yang terhormat Bapak/Ibu orang tua dari *Adik {$customerName}* 🙏";
    $waKalimat = "Perkenankan kami menyampaikan bahwa pesanan kacamata untuk Adik {$customerName} telah kami terima dengan baik dan sedang dalam proses pengerjaan. Insyaallah kami akan mengerjakannya dengan penuh ketelitian dan kehati-hatian.";
    $waPenutup = "Semoga kacamata yang kami siapkan dapat memberikan kenyamanan serta mendukung tumbuh kembang dan aktivitas belajar Adik {$customerName}. Terima kasih atas kepercayaan yang telah Bapak/Ibu berikan kepada kami 🙏";
} elseif ($customerAge > 12 && $customerAge <= 17) {
    // Remaja (SMP/SMA) — Adik
    $waSapaan  = "Yang terhormat *Adik {$customerName}* 🙏";
    $waKalimat = "Perkenankan kami menyampaikan bahwa pesanan kacamata Adik telah kami terima dengan baik dan saat ini sedang dalam proses pengerjaan. Insyaallah kami akan menyelesaikannya dengan sebaik-baiknya.";
    $waPenutup = "Apabila Adik memiliki pertanyaan atau ingin mengetahui perkembangan pesanan, kami dengan senang hati siap membantu kapan saja. Semoga kacamatanya nyaman dan cocok digunakan 🙏";
} elseif ($customerAge > 17 && $customerAge <= 25) {
    // Dewasa muda — Saudara/Saudari
    $sdra = ($sapaPanggil === 'Bapak') ? 'Saudara' : (($sapaPanggil === 'Ibu') ? 'Saudari' : 'Saudara/i');
    $waSapaan  = "Yang terhormat *{$sdra} {$customerName}* 🙏";
    $waKalimat = "Perkenankan kami menyampaikan bahwa pesanan kacamata {$sdra} telah kami terima dengan baik dan saat ini sedang dalam proses pengerjaan. Insyaallah kami akan menyelesaikannya dengan sebaik-baiknya.";
    $waPenutup = "Apabila {$sdra} memiliki pertanyaan atau ingin mengetahui perkembangan pesanan, kami dengan senang hati siap membantu kapan saja. Semoga kacamatanya nyaman dan cocok digunakan 🙏";
} elseif ($customerAge > 25 && $customerAge <= 55) {
    // Dewasa
    $waSapaan  = "Yang terhormat *{$sapaPanggil} {$customerName}* 🙏";
    $waKalimat = "Perkenankan kami menyampaikan bahwa pesanan kacamata {$sapaPanggil} telah kami terima dengan baik dan saat ini sedang dalam proses pengerjaan. Insyaallah kami akan menyelesaikannya dengan penuh ketelitian.";
    $waPenutup = "Apabila {$sapaPanggil} memiliki pertanyaan atau memerlukan informasi lebih lanjut, jangan ragu untuk menghubungi kami. Kami senantiasa siap melayani dengan sepenuh hati 🙏";
} elseif ($customerAge > 55) {
    // Lansia
    $waSapaan  = "Dengan penuh hormat, kami menyapa *{$sapaPanggil} {$customerName}* 🙏";
    $waKalimat = "Perkenankan kami menyampaikan rasa terima kasih yang sebesar-besarnya atas kepercayaan {$sapaPanggil} kepada kami. Pesanan kacamata {$sapaPanggil} telah kami terima dengan baik dan sedang kami kerjakan dengan penuh ketelitian dan rasa tanggung jawab.";
    $waPenutup = "Apabila {$sapaPanggil} berkenan menanyakan sesuatu atau memerlukan bantuan apa pun, kami dengan hormat dan sepenuh hati siap melayani kapan saja. Semoga Allah senantiasa memberikan kesehatan dan kemudahan bagi {$sapaPanggil} 🙏";
} else {
    // Umur tidak diketahui
    $waSapaan  = "Yang terhormat *{$sapaPanggil} {$customerName}* 🙏";
    $waKalimat = "Perkenankan kami menyampaikan bahwa pesanan kacamata {$sapaPanggil} telah kami terima dengan baik dan saat ini sedang dalam proses pengerjaan. Insyaallah kami akan menyelesaikannya dengan sebaik-baiknya.";
    $waPenutup = "Apabila {$sapaPanggil} memiliki pertanyaan atau memerlukan informasi lebih lanjut, jangan ragu untuk menghubungi kami. Kami senantiasa siap melayani dengan sepenuh hati 🙏";
}

/* ── Bangun pesan WA — tanpa detail invoice (sudah ada di gambar) ── */
$waMessage = "{$wasSalam}

"
           . "{$waSapaan}

"
           . "{$waKalimat}

"
           . "Terlampir bersama pesan ini adalah gambar invoice pesanan sebagai bukti transaksi yang sah dari *{$storeName}*.

"
           . "{$waPenutup}

"
           . "{$wassSalam} 🙏

"
           . "— *{$storeName}*";

// Encode untuk URL WA
$waMessageEncoded = rawurlencode($waMessage);

// Nomor WA: bersihkan dan format internasional
$waPhone = preg_replace('/[^0-9]/', '', $gPhone);
if (substr($waPhone, 0, 1) === '0') {
    $waPhone = '62' . substr($waPhone, 1);
}
$waUrl = "https://wa.me/{$waPhone}?text={$waMessageEncoded}";
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

/* ── Sheet lebar A5, tinggi mengikuti konten ── */
.inv-sheet{
    width:148mm;
    background:#F5F4F0;
    display:flex;flex-direction:column;
    overflow:visible;
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

/* ── Scaling untuk layar HP ── */
@media screen and (max-width:600px){
    html,body{
        overflow-x:hidden;
        padding:60px 0 30px;
        justify-content:flex-start;
        align-items:center;
    }
    .inv-sheet{
        transform-origin:top center;
        transform:scale(var(--sheet-scale,1));
        margin-left:auto; margin-right:auto;
    }
}

@media print{
    html,body{background:#fff!important;padding:0!important;}
    .no-print{display:none!important;}
    .inv-sheet{box-shadow:none;width:148mm;}
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
        <?php if (!empty($waPhone)): ?>
        <button id="btnKirimWA" onclick="kirimInvoiceWA()"
            style="background:#25D366;color:#fff;border:none;border-radius:6px;padding:8px 18px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
            </svg>
            <span id="btnKirimWALabel">📸 Kirim Invoice WA</span>
        </button>
        <?php else: ?>
        <button disabled title="Nomor HP tidak tersedia"
            style="background:#555;color:#999;border:none;border-radius:6px;padding:8px 18px;font-size:12px;font-weight:600;cursor:not-allowed;font-family:inherit;display:flex;align-items:center;gap:6px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
            </svg>
            Kirim WA
        </button>
        <?php endif; ?>
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
                <?php if ($gDiscount > 0): ?>
                <div class="tot-row"><span>Harga Item</span><span><?php echo piIDR($gItemsTotal); ?></span></div>
                <div class="tot-row div"><span>Diskon</span><span class="v-green">- <?php echo piIDR($gDiscount); ?></span></div>
                <?php else: ?>
                <div class="tot-row div"><span>Subtotal</span><span><?php echo piIDR($gTotal); ?></span></div>
                <?php endif; ?>
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
    </div>

</div><!-- /sheet -->

<!-- html2canvas untuk screenshot invoice -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
// ── Scale sheet agar tidak terpotong di HP ──
(function(){
    function applyScale(){
        var sheet = document.querySelector('.inv-sheet');
        if(!sheet) return;
        if(window.innerWidth < 600){
            sheet.style.transform = '';
            sheet.style.marginLeft = '';
            sheet.style.marginBottom = '';
            var sheetW = sheet.getBoundingClientRect().width;
            var vw = window.innerWidth;
            var scale = Math.min(1, (vw - 16) / sheetW);
            document.documentElement.style.setProperty('--sheet-scale', scale.toFixed(4));
            sheet.style.transform = 'scale(' + scale.toFixed(4) + ')';
            sheet.style.transformOrigin = 'top center';
            sheet.style.marginLeft = 'auto';
            sheet.style.marginRight = 'auto';
            // Kompensasi ruang yang hilang akibat scale (gunakan tinggi aktual)
            var sheetH = sheet.getBoundingClientRect().height;
            sheet.style.marginBottom = (sheetH * (scale - 1)) + 'px';
        } else {
            document.documentElement.style.setProperty('--sheet-scale', '1');
            sheet.style.transform = '';
            sheet.style.transformOrigin = '';
            sheet.style.marginLeft = '';
            sheet.style.marginBottom = '';
        }
    }
    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', applyScale);
    } else {
        applyScale();
    }
    window.addEventListener('resize', applyScale);
})();
var waPhone   = "<?php echo addslashes($waPhone); ?>";
var waMessage = <?php echo json_encode($waMessage, JSON_UNESCAPED_UNICODE); ?>;

function kirimInvoiceWA() {
    var btn   = document.getElementById('btnKirimWA');
    var label = document.getElementById('btnKirimWALabel');
    if (!btn) return;

    btn.disabled = true;
    label.textContent = '⏳ Membuat gambar...';

    var sheet    = document.querySelector('.inv-sheet');
    var invNum   = sheet.querySelector('.inv-num-val')
                   ? sheet.querySelector('.inv-num-val').innerText.trim().replace(/\s+/g, '_')
                   : 'invoice';
    var fileName = 'Invoice_' + invNum + '.png';
    var waUrl    = "https://wa.me/" + waPhone + "?text=" + encodeURIComponent(waMessage);

    html2canvas(sheet, {
        scale: 2,
        useCORS: true,
        backgroundColor: '#F5F4F0',
        logging: false
    }).then(function(canvas) {

        // Download gambar invoice
        var link      = document.createElement('a');
        link.download = fileName;
        link.href     = canvas.toDataURL('image/png');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        // Langsung buka WA ke nomor yang bersangkutan
        label.textContent = '⏳ Membuka WhatsApp...';
        setTimeout(function() {
            window.open(waUrl, '_blank');
            btn.disabled      = false;
            label.textContent = '📸 Kirim Invoice WA';
        }, 600);

    }).catch(function(err) {
        console.error('html2canvas error:', err);
        window.open(waUrl, '_blank');
        btn.disabled      = false;
        label.textContent = '📸 Kirim Invoice WA';
    });
}
</script>
</body>
</html>