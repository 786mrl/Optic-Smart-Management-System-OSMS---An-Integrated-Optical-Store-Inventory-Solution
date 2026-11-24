# PROJECT_NOTES — lisani_aos

> Versi ringkas (20 Nov 2026): hanya kondisi sekarang, aturan bisnis, peta file, dan pekerjaan terbuka.
> Riwayat per sesi ada di `NOTES_ARCHIVE.md` (upload hanya jika perlu tahu ALASAN keputusan lama).
> Pekerjaan baru: tambahkan ±10 baris di "Log terbaru" (§8). Setelah dites, lebur ke modul terkait dan hapus dari log.

## 1. Apa ini
Sub-project tersembunyi ("extended mode") di **LenZa Optic POS** (`optic_pos/`). Dibuka lewat triple-click
tombol login di `login.php`. DB & sesi terpisah dari optic_pos (`lisani_aos_db`, koneksi `$lisani_conn`, mysqli).
Stack: PHP + MariaDB (XAMPP / Termux), JS vanilla, tema dark neomorphism.

## 2. Struktur folder
```
optic_pos/
├── login.php, logout.php          (shared; cabang access_mode normal/extended)
├── db_config.php                  (milik optic_pos, TIDAK dipakai lisani_aos)
├── fpdf/fpdf.php                  (FPDF v1.9, dipakai cetak invoice PDF)
└── lisani_aos/
    ├── index.php, db_config.php, .htaccess
    ├── partials/                  header.php, sidebar.php, footer.php
    ├── assets/css/                theme.css (token + komponen), responsive.css (breakpoint)
    ├── transaction_content.php    Transactions: Activity Code, Customer List, Input Transaction, Sales
    ├── logistic_content.php       Logistic: List, Movements, Create New Logistic
    ├── document_tools_content.php Document Tools: Signature Library, Letterhead Library, Document Editor (lihat §6)
    ├── settings_content.php       Company Documents & Company Bank Accounts
    ├── dashboard_content.php      Report: Finance Report (RENAMED dari report_content.php, 16 Nov 2026 —
    │                              referensi "report_content.php" di dokumen lama/arsip masih pakai nama lama, itu historis)
    ├── investor_content.php       Investor: Investors, Investment, Report, Profit Payment & Rolled Capital
    ├── ajax/                      semua endpoint (§7); _invoice_pdf.php, _require_reverify.php, _order_patterns.php;
    │                              investor_api.php (endpoint tunggal modul Investor)
    ├── json_file/                departments.json, packaging units, bank_accounts.json, order_patterns/
    ├── sql/ & migration_*.sql
    └── storage/                   input/…, selling/…, recycle/… (AOS_STORAGE_BASE)
```

## 3. Konvensi & cara kerja (WAJIB)
- **Bahasa**: chat = Indonesia. Kode (identifier, komentar, kolom, nama file) = Inggris. **UI = Inggris.**
- **Cara kerja user**: user menguji sendiri lalu melapor. Ubah kode lama sesempit mungkin; serahkan **file lengkap
  siap copy-paste**. Struktur tabel dari user (`DESCRIBE` di phpMyAdmin), **jangan menebak nama kolom**. Kalau butuh
  file yang belum diupload, minta dulu, jangan mengarang isinya.
- Sandbox tidak punya PHP: cek JS dengan Node (`new Function()` per `<script>`), cek kurung/kurawal PHP manual,
  dan cek jumlah tipe `bind_param` vs variabel (pernah jadi bug, lihat §4 catatan `bind_param`).
- Halaman baru: `partials/header.php` → konten → `partials/footer.php` (+ sidebar). Guard wajib:
  `$_SESSION['app'] === 'lisani_aos'` dan `user_id`. Endpoint: 401 + `{ok:false}` kalau sesi habis.
- Tiap `*_content.php` adalah IIFE sendiri (tidak berbagi JS/CSS scope), jadi helper (`parseNumberInput`,
  collapsible, `fmtNum`) diduplikasi per file. `transaction_content.php` membungkus dirinya sendiri dengan
  `<div class="menu-section" data-section="transactions">`; modal ditaruh sebagai sibling di luar wrapper.
- **Uppercase**: semua input teks bebas di-uppercase (client: class `.input-uppercase`; server: `strtoupper()`),
  kecuali yang jadi nama folder/path (mis. `key` di `departments.json` tetap huruf kecil).
- **Angka**: input angka pakai koma ribuan (`.input-number-comma`); `parseNumberInput` strip koma; ke server kirim
  angka bersih. Hasil OCR memakai `parseOcrAmount()` (disambiguasi `.`/`,` dari teks OCR itu sendiri). Jangan
  disatukan dengan `parseNumberInput`, karena konvensinya beda.
- **Scan berulang pada field amount (Disbursement `capAmount`/`capBankFeeCustom`, Invoice Payment `payCapAmount`,
  Refund `refCapAmount`)**: kalau field sudah berisi angka valid dan discan ulang, modal `capScanAddOverlay`
  (shared) tanya **Replace** vs **Add** (dijumlahkan ke nilai lama) sebelum nilai final ditulis + recalc preview
  masing-masing dipanggil. Field kosong/tidak valid → scan langsung mengisi seperti biasa, tanpa modal.
  `capExchangeRate` dan `refCapBankFeeCustom` TIDAK kena mekanisme ini (selalu replace / tidak scannable). Modal
  ini otomatis menutup dulu `capViewerWrap` (viewer dokumen full-screen) sebelum tampil (supaya tidak ketutup
  z-index-nya), dan menunda `capFieldPickerOverlay` sampai modal dijawab.
- **Pola endpoint**: `ini_set('display_errors','0'); ob_start();` + `aos_json()`/`aos_fail()`. Simpan data = satu
  DB transaction + `FOR UPDATE`. Fitur yang menulis punya **`dry_run=1`** (preview, rollback) lalu `dry_run=0` (simpan).
- **Dua pola password** (pilihan sadar per fitur; untuk fitur destruktif baru yang user tidak sebut polanya, **tanya dulu**):
  (a) `password_verify()` langsung di endpoint aksi (`delete_customer`, `delete_activity_code`);
  (b) `verify_password.php` + `aos_require_recent_reverify()` (jendela 120 detik).
  Pola (b) membalas `{success:false,…}` saat expired → **client cek `res.success === false` DULU, baru `res.ok`.**
- **Komponen UI** (pakai yang sudah ada di `theme.css`, jangan bikin style baru per halaman):
  `.card`, `.table-wrapper`, `.form-group>.label+.input`, `.select`, `.btn` + `-primary/-secondary/-danger`,
  `.badge` + `-success/-danger/-warning`, `.tab-group>.tab`, `.modal-overlay>.modal`, `.accordion-*`, `.empty-state`.
  List banyak-field → Accordion, bukan tabel. Kalau perlu class baru, tambahkan ke `theme.css`.
- **Gotcha UI**:
  1. Ikon Tabler sering tofu (kotak kosong) → pakai SVG inline.
  2. Collapsible `max-height=scrollHeight`: kalau isi berubah saat terbuka, re-sync max-height.
  3. Klik card exclusive per parent (`closest('.accordion-list')`).
  4. Modal didaftarkan ke `flexOverlays` supaya `show()`/`hide()` dan MutationObserver bekerja — modal baru yang
     lupa didaftarkan akan tampil `display:block` (tidak center), bukan `flex`.
  5. **Layout/overflow**: `.app` HARUS `minmax(0,1fr)` (bukan `1fr`) dan `.main` `min-width:0`, kalau tidak konten
     lebar melebarkan seluruh halaman. Scroll horizontal hanya boleh di `.table-wrapper`. Grid
     `repeat(auto-fit, minmax(Npx,1fr))` pakai `minmax(min(Npx,100%),1fr)`.
  6. `.catch` di `rtReview`/`rtSaveReturn` menelan SEMUA error JS sebagai "Connection error". Kalau pesan itu muncul
     padahal jaringan normal, curigai TypeError di handler `.then`, bukan koneksi/endpoint.
  7. `responsive.css` mengubah SEMUA `table` jadi tampilan kartu di <1024px dan memaksa `td{width:100%}`. Tabel yang
     harus tetap tabel perlu override `display` eksplisit (dipakai mis. modul Investor via `.inv-scroll{display:revert}`).
  8. Chart.js dari cdnjs bisa diblokir jaringan user. Error di `renderCharts()` harus ditangkap (try/catch) agar
     bagian lain report tetap tampil.
  9. Sorting campuran `ref_id` int & string (`usort`/`strcmp`) tidak selalu urut benar secara numerik — proyek ini
     sengaja pakai trik suffix string (`"<id>_fee"`, `"<id>_credit"`, dst) supaya baris turunan tetap nempel tepat
     setelah baris induknya; baris dengan `ref_id` SAMA PERSIS (bukan suffix) akan tetap berurutan sesuai urutan
     push ke array karena `usort()` PHP 8+ stabil. Ini batasan yang diterima, bukan bug — lihat §6 Report.
- **Menu**: sidebar = Dashboard, Transactions, Logistic, Document Tools, Report, Investor — semua pakai ikon Tabler,
  berlaku di sidebar desktop & bottom-nav mobile. Settings & Exit di dropdown avatar. Ganti section lewat
  `[data-target]` (footer.php → `setActive()`), yang juga `dispatchEvent('aos:section-shown', {detail:{target}})`.
  Section yang fetch-nya cuma sekali bisa listen event ini untuk refresh live (pola generik, tidak perlu ubah footer.php).
- **Branding**: nama resmi **"LISANI AOS"** (huruf besar semua), AOS = *Lisani Administration Operating System*.
  Topbar (`index.php` → `.header-title`/`.header-sub`) pakai nama + subtitle lengkap + logo `assets/img/lisani_logo.png`
  (`.header-logo`). Sidebar (`partials/sidebar.php` → `.brand`) pakai logo yang sama + "LISANI AOS" saja, **tanpa**
  subtitle (disengaja, beda dari topbar). CSS untuk `.header-brand`/`.header-logo`/`.brand-logo` ditulis inline di
  `index.php`/`sidebar.php` sendiri (bukan di `theme.css`) — kalau nanti dirapikan, hapus duplikatnya dari kedua file itu.
- **Sidebar show/hide toggle**: tombol `#sidebarToggleBtn` di topbar (`.header-brand`, sebelah logo), **desktop-only
  (≥1024px)** — `.app` punya 3 mode lebar sidebar dari `responsive.css` (desktop 240px / tablet rail 64px / mobile
  `display:none` + `.bottom-nav`), toggle ini cuma override mode desktop lewat class `.app.sidebar-collapsed`
  (grid-template-columns 0 + `.sidebar{width:0}`, transisi `.18s`), disembunyikan total di <1024px lewat media query.
  Status diingat di `localStorage` (`aos_sidebar_collapsed`), ikon tombol ganti `ti-layout-sidebar-left-collapse` ⇄
  `-expand`. CSS & JS-nya di `index.php` (bukan `theme.css`/`footer.php`), sama alasannya dengan poin Branding di atas.

## 4. Model data (cocokkan dengan `DESCRIBE` sebelum menulis query baru)
- **activities**: id, activity_name, cashflow (inflow/outflow/in-out), relative_path (UNIQUE, `input/{year}/{deptKey}/{code}/`),
  created_by, created_at. Nomor kode per departemen+tahun, **tidak di-generate ulang saat edit**.
- **customers**: id, year, customer_name (UNIQUE year+name), phone_number (`+628…` tanpa spasi), total_inflow (akumulator
  order), total_outflow (akumulator retur **+ diskon**), total_price_adjustments (akumulator diskon, khusus laporan),
  total_paid, profit, credit_balance.
- **customer_item_prices**: customer_id, logistic_id, price, price_date, unit. Harga jual per produk per customer (riwayat).
  New Order memakai harga dengan `price_date ≤ tanggal order` (terbaru).
- **logistics**: satu baris = satu produk; UNIQUE(activity_id, product_name); activity_id TIDAK unique. Kolom: product_name,
  primary_qty, primary_unit_label, primary_unit_weight_kg, secondary_unit_label/_weight_kg/_ratio_per_primary, incoming_date,
  **remaining_primary_qty** (stok normal), **defective_qty** (stok defective di gudang), **total_taken_qty** (saldo yang sedang
  di tangan customer, normal+defective), **defective_taken_qty** (sub-saldo defective di customer). Berat total & qty secondary
  tidak disimpan, dihitung di `list_logistics.php`. Nama tampilan produk = `product_name` (bukan `activities.activity_name`).
  Kolom `defective_reference_price` sudah tidak dipakai (drop opsional, belum dijalankan — lihat §8).
- **logistic_movements**: logistic_id, customer_id, customer_name, movement_type (`out`=taken, `in`=returned,
  `price_adjustment`=diskon), stock_source, movement_date, driver_name, police_number, qty_primary_package, price, total_price,
  invoice_id, created_by, created_at, **source_movement_id** (di `in`/`price_adjustment` menunjuk pickup `out` asalnya),
  **batch_id** (identitas satu order/kartu = movement id terkecil saat disimpan).
- **invoices**: customer_id, invoice_number, sequence_number, period_month/year, status (**open/paid/cancelled**),
  total_amount, paid_amount. Nomor: `NNN/{inv|ret|adj}/laj-{INISIAL}-{n}/{ROMAWI}/{tahun}`, `[n]` unik lintas ketiga jenis.
  `NNN` = `sequence_number` yang lanjut per customer (001, 002, …) lintas bulan/tahun. Status `'cancelled'`
  (`migration_invoice_cancelled_status.sql`, sudah dijalankan) dipakai saat invoice ditutup tanpa benar-benar lunas
  (uangnya cuma pindah ke credit_balance, bukan dibayar beneran, jadi label `'paid'` menyesatkan) — efeknya sama seperti
  `'paid'` untuk query manapun yang cek `status = 'open'` (invoice otomatis tersingkir dari reuse-open-invoice), tapi
  `paid_at` tetap NULL karena memang tidak pernah benar-benar lunas. Lihat §5.11 untuk kapan status ini dipakai.
- **invoice_payments**: pembayaran cicilan per invoice. Kolom bank: source_bank, source_account_name (**TIDAK ADA**
  `source_account_number` — nomor rekening sumber memang tidak pernah dicatat di tabel ini), destination_bank,
  destination_account_number, destination_account_name. **TIDAK ADA** kolom `payment_method` (jangan diasumsikan ada).
  `proof_path`/`proof_original_name` nullable. Method "CREDIT BALANCE" = baris tanpa file (`source_bank = "CREDIT
  BALANCE"` sebagai penanda, field bank lain `''`, `notes = "AUTO-APPLIED FROM CREDIT BALANCE"` — dipakai baik oleh
  auto-apply di `create_order.php`/`create_booking.php` maupun tombol Apply Credit manual `apply_customer_credit.php`).
- **invoice_refunds**: refund dari kredit; kolom source_bank, source_account_number, source_account_name (nullable,
  opsional — rekening PERUSAHAAN pengirim refund, independen dari pelacakan sumber credit di `customer_credit_refunds`).
  **Tidak punya `invoice_id` maupun `activity_id`** — tidak bisa ditelusuri ke invoice/activity code tertentu, lihat
  §6 Report untuk konsekuensinya ke filter. **Tidak punya kolom `destination_*`** (rekening customer penerima) —
  rekening penerima cuma terekam di file bukti transfer (proof), tidak terstruktur.
- **refund_bank_fees**: id, invoice_refund_id, fee_amount, created_at. TANPA FK, index biasa di `invoice_refund_id`.
  Diisi kalau form Refund (Transfer) memilih Bank Fee > 0 (lihat §6 Sales Transaction).
- **disbursement_bank_fees**: id, transaction_id, fee_amount, created_at. Pola sama seperti `refund_bank_fees`, untuk
  form Disbursement.
- **customer_credit_sources**: customer_id, source_bank/account_number/account_name NULLABLE, amount, remaining_amount,
  origin_ref_invoice_id (nama kolom final setelah `migration_credit_sources_generic_origin.sql` — dulu
  `origin_return_invoice_id`), origin_invoice_payment_id, created_date. Ledger per rekening asal (FIFO) dari
  credit_balance — `customers.credit_balance` TETAP ada & tetap source of truth jumlah total (dipakai
  `create_refund.php` dkk); `SUM(remaining_amount)` per customer HARUS selalu sama dengan `customers.credit_balance`,
  diupdate bersamaan dalam transaction yang sama. Lihat §5.12 untuk pembentukan & §6 Report untuk tampilannya.
- **customer_credit_applications**: invoice_payment_id, credit_source_id, amount_used, remaining_after_amount
  (snapshot sisa SAAT potongan terjadi, bukan dihitung ulang dari state sekarang). Dicatat tiap kali credit dipakai
  (spend FIFO) via `ccs_spend_fifo()`.
- **customer_credit_refunds**: id, invoice_refund_id, credit_source_id, amount_refunded, remaining_after_amount,
  created_at — mirror pola `customer_credit_applications`, untuk pelacakan refund yang diambil dari credit_balance
  (FIFO, best-effort: credit lama dari sebelum migration ini tetap bisa direfund seperti biasa, cuma tanpa jejak sumber).
- **bookings**: customer_id, invoice_id (selalu invoice baru sendiri, lihat §5), booking_date, status
  (active/completed/cancelled), created_by. **booking_items**: booking_id, logistic_id, stock_source, qty_booked,
  qty_released (akumulator **gross**, tidak pernah turun), price. **booking_item_releases**: booking_item_id,
  movement_id (menunjuk `logistic_movements.id` hasil `release_booking.php`), qty — append-only, tidak pernah
  di-UPDATE, dipakai hitung "Taken net" (lihat §5). Lihat `migration_bookings.sql` dan
  `migration_booking_item_releases.sql`.
- **defective_stock_events**: logistic_id, event_type (saat ini hanya `repaired_to_normal`), qty, created_by.
- Lain: `logistic_documents` (key activity_id, dibagi semua produk dalam activity code), `transactions` + tabel bantu
  (Disbursement), company documents & bank accounts (Settings).
- **disbursement_categories**: id, category_name (UNIQUE), created_by, created_at. Seed: PURCHASE PAYMENT / CLEARANCE
  FEES / OPERATIONAL EXPENSES. **transaction_disbursements.category_id** (NOT NULL, indeks, tanpa FK, validasi di PHP)
  menunjuk ke sini — label pengelompokan uang keluar (tidak memecah total/saldo/chart, hanya tampilan & filter).
  **transaction_disbursements.activity_id** (NOT NULL, indeks, tanpa FK) juga ada — dipakai filter Activity Code di
  Report (lihat §6 Report).
- **investors**: id, name. **investor_deposits**: investor_id, date, currency, amount, rate (nullable, hanya valas),
  final (IDR), notes. **investor_support_expenses**: investor_id, date, category (`return_capital`/`aid`/`other`),
  amount (IDR), notes — mengurangi ICU. **investor_activity_allocations**: investor_id, activity_id, percent (dari
  ICU investor itu; total per investor ≤100% dicek di PHP lintas activity). **investor_activity_settings**: pdp
  (profit distribution percent) **per activity_id**. **investor_profit_payments**: investor_id, date, amount, notes
  (bisa berkali-kali). Tanpa tabel `transaction_activities` (sempat dibuat, di-drop).
- Modul yang sudah dihapus total (jangan direferensi lagi): `customer_refund_queue` (DROP, tidak pernah terisi data
  produksi), departemen `refund_settlement` & activity code "00-A" (dihapus dari `departments.json`/`activities`),
  `credit_action`/dialog `creditChoiceOverlay` (dihapus dari `cancel_booking.php`, `create_return.php`,
  `create_price_adjustment.php`, `transaction_content.php` — semua return/cancel SELALU masuk `customers.credit_balance`).
- **Catatan `bind_param`**: posisi tipe data wajib dicek manual (pernah ketukar, mis. string tipe `'isssssiiis'`
  seharusnya `'isssssiisi'` saat insert ke `customer_credit_sources` — posisi kolom tanggal `s` dan user_id `i`
  ketukar, MySQL tolak dengan "Incorrect date value").

## 5. Aturan bisnis (invarian, jangan dilanggar)
1. **Stok dua bucket independen**: `remaining_primary_qty` = normal, `defective_qty` = defective. Satu-satunya jalan
   defective → normal adalah aksi manual **Repair to Normal Stock**. Order dari defective memotong `defective_qty`
   (+ `defective_taken_qty`), bukan `remaining_primary_qty`.
2. **`stock_source`** satu kolom, dua makna: di baris `out` = bucket yang dipotong; di baris `in` = bucket TUJUAN restock.
   Retur bebas memilih tujuan (Good → normal / Defective → defective), independen dari asal pickup. Kalau pickup asal
   defective, `defective_taken_qty` tetap dikurangi.
3. **Price adjustment (diskon)** tidak menggerakkan stok (barang tetap di customer): tidak mengubah remaining/defective/
   total_taken/defective_taken. Qty-nya tidak dijumlah ke Taken/Returned; nilainya mengurangi Actual.
   `Total Actual = Ordered − Returned − Discounts` (per customer, per produk, dan di Movements).
4. **Retur per lot**: tiap retur/diskon dialokasikan ke pickup `out` tertentu (`source_movement_id`). Server memvalidasi
   `remaining = qty − Σin` dan `remaining_adjustable = qty − Σin − Σprice_adjustment` (dua batas terpisah). Harga default =
   harga pickup, bisa diedit. Server tidak percaya angka client.
5. **Order**: satu movement `out` per produk. Produk sama tidak boleh mixed `stock_source` dalam satu baris (pisah jadi dua).
   Qty > stok bucket ditolak, tanpa auto-split. Harga dari `customer_item_prices`; kalau tidak ada, overlay Set Price.
   Defective: harga diinput manual, dengan harga saran = harga terakhir untuk defective produk itu (dihitung dari
   `logistic_movements`). `customer_item_prices` sengaja tidak diisi dari defective.
6. **Update Existing Order**: baris produk yang sama di-UPDATE (qty diganti, bukan dijumlah), produk baru di-INSERT dengan
   `created_at`/`batch_id` order target. Stok & invoice/customer disesuaikan dengan selisih. Tanpa audit trail qty lama
   (disengaja). Peringatan modal bila driver/police berubah.
7. **Kartu riwayat (tab Customers)** dikelompokkan per `batch_id` (fallback data lama: menit created_at + driver + police),
   di dalam tiap invoice. Return + Discount yang disimpan berselisih ≤ 60 detik (tanggal, driver, police sama) digabung
   tampil jadi satu kartu (display-only, `MERGE_WINDOW_MS`).
8. `customers.total_outflow` = akumulator (tidak turun); `total_taken_qty` = saldo (turun saat retur). Jangan tertukar.
   Angka "pernah keluar" historis dihitung dari `SUM(movement out)`.
9. **Edit logistic**: Activity Code tidak bisa diubah. `remaining` dihitung ulang dengan offset:
   `remaining_baru = primary_qty_baru − (primary_qty_lama − remaining_lama)`; negatif ditolak.
10. **Hapus customer/dokumen**: isi folder yang tidak kosong **dipindah** ke `storage/recycle/…`, bukan dihapus.
11. **Order/retur/diskon memakai invoice OPEN yang ada**; invoice baru hanya dibuat saat tidak ada yang open.
    Pengecualian: New Order dengan `force_new_invoice` (checkbox "Open as a new invoice") selalu membuat invoice baru,
    sehingga boleh ada lebih dari satu invoice OPEN per customer. Retur/diskon di kasus itu wajib memilih invoice target
    (`invoice_id`). Kalau tidak dipilih, fallback ke invoice open terbaru. Invoice yang ditutup karena overpaid
    di-cap (lihat §5.16/§5.17, status `'cancelled'`) otomatis tersingkir dari reuse ini karena `status` bukan `'open'`.
12. **Kredit customer**: retur/diskon/cancel booking yang tidak punya invoice open untuk dikurangi (termasuk saat
    satu-satunya invoice sudah PAID) **selalu** masuk `customers.credit_balance`, `invoice_id = NULL` (grup
    "(No invoice)") — **tidak** ada lagi pilihan lain (fitur "Refund Queue"/`credit_action` sudah dihapus total, lihat §4).
    - **Precondition pembentukan dari Return**: credit_balance HANYA terbentuk dari barang yang invoice asalnya
      **sudah pernah dibayar** (barang yang belum pernah dibayar = sekadar pengurangan stok, bukan kredit). Return
      campuran (sebagian dari invoice sudah dibayar, sebagian belum) dalam satu request **ditolak total**
      (all-or-nothing, code `source_invoice_unpaid`) — user harus kirim 2 return terpisah.
    - **Pelacakan sumber (FIFO)**: tiap pembentukan credit ditelusuri ke `source_movement_id` → `invoice_id` asal →
      `invoice_payments` invoice itu (FIFO by id ASC, **skip baris `source_bank='CREDIT BALANCE'`** karena bukan
      rekening nyata) → nilai dipecah ke rekening-rekening itu sampai habis, via `trace_credit_to_real_payments()`
      (di `credit_sources_helper.php`). Sisa yang tidak tertelusuri (invoice asal dibayar pakai auto-apply credit
      juga, atau movement tanpa invoice_id — data lama pre-batch_id) dicatat sebagai baris `customer_credit_sources`
      dengan `origin_invoice_payment_id=NULL`: kalau asalnya SATU invoice yang payment-nya sendiri `CREDIT BALANCE`
      (order yang dibayar penuh pakai credit lalu sebagian di-return), nilai itu **di-fold kembali** sebagai entri
      "Returned (added back to this credit)" di baris credit ASAL (bukan baris Ledger baru — lihat §6 Report);
      kalau benar-benar tidak tertelusuri (data lama tanpa invoice_id sama sekali), tetap jadi baris Ledger mandiri
      "... (source account unknown)".
    - Kredit **tidak pernah dipakai otomatis saat dibentuk**: dipakai lewat Refund (`create_refund.php`, mengurangi
      credit_balance & total_paid, FIFO alokasi ke `customer_credit_refunds`), Apply Credit manual per invoice open
      (`apply_customer_credit.php`, mengurangi credit_balance saja; total_paid tidak berubah), atau **auto-apply**
      otomatis saat order baru dibuat/invoice open dipakai (`create_order.php`, juga `create_booking.php` — lihat
      §5.17): sebisa mungkin menyerap dari credit_balance ke invoice itu, dibatasi `min(outstanding invoice, credit
      tersedia)`. Pola pencatatan auto-apply SELALU disertai spend FIFO `customer_credit_sources` via
      `ccs_lock_sources()`/`ccs_spend_fifo()` + insert `customer_credit_applications` (bukan cuma kurangi
      `customers.credit_balance` — kalau cuma itu, pemakaiannya tidak akan muncul di Report Ledger).
13. **Investor — ICU & dana project**: ICU (Investor Capital Utilized) = Σdeposits − Σnon-project expenses; ini total
    dana investor yang tersedia (bukan akumulator, turun kalau ada return_capital/aid/other). Total cost per activity =
    `SUM(transactions.final_amount_idr) JOIN transaction_disbursements` (category=disbursement). Dana investor terpakai
    per project = Σ(%alokasi × ICU investor), **dibatasi ≤ total cost** project itu. Kalau total alokasi melebihi cost,
    pembagiannya **water-filling dua tahap** (`inv_compute()` di `investor_api.php`): investor yang sudah **capped**
    (alokasi = 100% dari ICU-nya sendiri untuk project ini) **selalu dapat porsi penuh**, tidak pernah ikut di-scale
    turun; sisa budget (`cost − total capped`) baru dibagi ke investor **partial** (masih ada ruang), di-scale
    proporsional kalau jumlah mereka melebihi sisa budget itu. Fallback (kasus ekstrem total capped sendiri > cost):
    semua investor (termasuk capped) di-scale proporsional, supaya company additional tidak negatif. Sisanya dari
    `cost` = company additional contribution. "Share of Investor Fund (%)" (fly window) auto-balance investor lain
    memakai pembagian **nilai rupiah sama rata** (bukan %) dari sisa cost yang belum dialokasikan investor yang sedang
    diedit; investor yang ICU-nya tidak cukup menampung bagian rupiah rata itu di-cap 100% ICU, kekurangannya
    dilempar & dibagi rata lagi ke investor tersisa (cascading, lihat `allocRecalc()` di `investor_content.php`).
    `allocation_percent` disimpan presisi 6 desimal (`DECIMAL(9,6)`) supaya pembulatan tidak bikin total alokasi
    overshoot cost secara material.
14. **Investor — rumus laporan**: gross = sales aktual (dari `logistic_movements`, out−in−price_adjustment) − cost;
    zakat 2.5% dari gross (0 bila ≤0); net = gross − zakat; distribusi investor = net × pdp (pdp per activity code);
    rasio tiap investor dihitung **per project** (bukan gabungan semua project). TP (Total Profit) = Σ profit semua
    project; PP (Paid Profit) = Σ `investor_profit_payments`; Rolled Capital = (TP − PP) + ICU. Rugi (net<0) ditandai
    badge "RUGI", tetap dihitung apa adanya — **belum ada kebijakan otomatis**, menunggu keputusan manajemen.
15. **Investor — hapus**: pola password (a) langsung (`password_verify()` di endpoint), bukan reverify 120 detik
    (keputusan user, beda dari pola delete customer/activity code di modul lain).
16. **Booking — reservasi, rilis, net**: booking memotong `remaining_primary_qty`/`defective_qty` **saat dibuat**,
    tapi belum bikin `logistic_movements` dan belum menambah `total_taken_qty`/`customers.total_inflow` — itu baru
    terjadi saat **Release** (`release_booking.php`), yang bikin movement `out` sungguhan, `booking_items.qty_released`
    naik, dan kalau semua item habis dirilis `bookings.status` jadi `completed`. Tiap booking **selalu invoice baru**
    sendiri (beda dari Order/Retur yang reuse invoice open, §5.11). `qty_released` tetap **gross** (akumulator, tidak
    pernah turun, jadi patokan kapan booking selesai & batas bawah Edit) — kalau barang yang sudah dirilis itu
    kemudian diretur lewat tab Logistic, retur itu tidak "membuka slot rilis baru"; "Taken net" dihitung on-the-fly
    dari `booking_item_releases` (append-only, 1 baris per release) dikurangi retur yang `source_movement_id`-nya
    menunjuk ke release itu — pola yang sama dengan retur/pickup biasa (§5.4). Edit (`edit_booking.php`) bisa ubah
    qty baris yang sudah ada (tidak boleh di bawah `qty_released`) **dan/atau** menambah produk baru (direservasi &
    diberi harga persis seperti booking baru, lihat §6 Sales Transaction); Cancel (`cancel_booking.php`) membatalkan
    SEMUA sisa yang belum dirilis sekaligus, mengembalikan stok & mengurangi invoice. `remaining_primary_qty`/
    `defective_qty` TIDAK disentuh lagi saat Release (sudah dipotong saat booking) atau saat Cancel di luar
    pengembalian itu. Cancel yang membuat invoice benar-benar kosong (`paid_amount`=0 dan total jadi 0 — belum
    pernah dibayar & belum pernah ada release sama sekali) **menghapus baris `invoices`-nya** (bukan sekadar ubah
    status), supaya nomor invoice-nya otomatis bebas dipakai booking/order berikutnya (lihat §7, `cancel_booking.php`).
    Cancel yang membuat invoice **overpaid** (paid_amount > total baru setelah dikurangi, tapi invoice itu tidak
    kosong total) di-cap: `paid_amount` dipotong persis sama dengan `total_amount` baru, invoice itu SEKALIGUS
    ditutup (`status='cancelled'`, lihat §4) supaya tidak numpuk order berikutnya; kelebihannya masuk
    `customers.credit_balance` (lihat §5.12) — ditelusuri ke source asal via `ccs_reverse_applications_for_invoice()`
    HANYA untuk bagian yang berasal dari Apply Credit yang sebelumnya pernah dipakai di invoice ini (lihat §5.17);
    sisanya (dari transfer bank asli) ditelusuri lewat `trace_credit_to_real_payments()` seperti biasa.
17. **Apply Credit saat Booking dibuat**: kalau customer punya `credit_balance` > 0, fly window di Create Booking
    menawarkan menerapkan sebagian/semua ke invoice booking ini — default & batas maksimum = `min(credit_balance,
    grand_total booking)`, tidak bisa diketik lebih (server re-clamp, tidak percaya client). Pola pencatatannya SAMA
    PERSIS dengan auto-apply-credit di `create_order.php` (bukan pola `apply_customer_credit.php` yang lebih
    sederhana): insert `invoice_payments` (`source_bank='CREDIT BALANCE'`) **ditambah** spend FIFO
    `customer_credit_sources` via `ccs_lock_sources()`/`ccs_spend_fifo()` + insert `customer_credit_applications`
    (`credit_sources_helper.php`) — kalau cuma kurangi `customers.credit_balance` tanpa bagian FIFO ini, pemakaiannya
    TIDAK muncul di Report Ledger → panel detail "Customer Credit Balance". Kalau booking yang sudah Apply Credit ini
    di-cancel dan jadi overpaid lagi, overpaid itu **dikembalikan ke source asal** via
    `ccs_reverse_applications_for_invoice()` (kebalikan `ccs_spend_fifo()`, newest-first) — BUKAN di-trace lewat
    `trace_credit_to_real_payments()` (fungsi itu sengaja skip row `source_bank='CREDIT BALANCE'`, jadi kalau dipaksa
    dipakai di sini hasilnya selalu "untraced" dan bikin `customer_credit_sources` baru yang salah/duplikat di
    Ledger). Trace lama tetap dipakai HANYA untuk sisa overpaid yang benar dari transfer bank asli (credit baru
    beneran, bukan reversal).

## 6. Kondisi tiap modul
**Transactions, Activity Code**: tab Preview (accordion, satu terbuka sekaligus; Edit/Delete pola a) + tab Create (nomor
dan path preview live dari `preview_activity_code.php`; duplikat nama dicek client & server). Departemen dikelola lewat
fly window `manage_departments.php`; hapus ditolak bila key sudah dipakai. Fly window pilih aksi & password muncul hanya
saat section Transactions dibuka.

**Customer List**: 2 tab (list accordion / form 3 field). Phone `+62 8` auto-format & normalisasi server. Folder
`selling/{year}/{nama}/` dibuat otomatis (best-effort). Edit = rename folder. Delete pola (a) + recycle.

**Itemized Pricing**: nested accordion per customer (lazy-load). Add Price bebas reverify. Edit/Delete pola (b).

**Input Transaction / Disbursement**: OCR Tesseract.js di browser (semi-otomatis, hasil selalu bisa diedit), viewer
layar penuh, wizard; simpan ke `transactions`. Belum ada: list/edit/delete transaksi tersimpan, kategori Other.
Viewer + OCR dipakai bersama dengan upload bukti pembayaran (satu instance, dipilah lewat `activeCapGroup`/`data-cap-group`).
- **Category** (wizard step 3, Details): dropdown **wajib**, di antara Cashflow Type dan Transaction Purpose. Opsi
  "+ Add new category…" (uppercase; nama yang sudah ada dipakai ulang, bukan error). Tombol **Manage** = Rename/Delete
  **tanpa password** (keputusan user); Delete ditolak bila kategori sudah dipakai transaksi. Overlay
  `disbManageCategoryOverlay` terdaftar di `flexOverlays`. Berlaku untuk semua departemen.
- **Auto-isi rekening dari OCR**: setelah OCR membaca nomor rekening, dicocokkan ke `bank_accounts.json` (via
  `list_bank_accounts.php`, diambil segar tiap pencarian). Disbursement = **source** account number → isi Source Bank,
  Source Account Name, Currency (+ exchange rate muncul bila non-IDR). Jalan setelah scan OCR **dan** saat ketik manual
  (event `change` = blur/Enter, bukan tiap ketukan). Aturan cocok (`parseScannedAccount()`, `acctFindMatches()`): nomor
  penuh → sama persis (spasi/strip diabaikan); nomor tertutup → cocok 4 digit terakhir (karakter non-digit apa pun
  selain spasi/titik/strip dianggap tertutup; juga tertutup bila ≤4 karakter; tanpa 4 digit di ujung → diabaikan). Hasil:
  1 cocok → langsung isi; >1 cocok (beda bank) → fly window `acctPickOverlay` pilih manual; 0 cocok → fly window
  `acctRegisterOverlay` (daftar rekening baru; bank/nama/currency diisi dari form bila ada; nomor penuh wajib diketik
  bila hanya 4 digit). Kedua overlay di `flexOverlays`, z-index 2200 (di atas viewer & field picker). Saat cocok/terdaftar,
  kolom nomor **diganti dengan nomor penuh milik JSON** (supaya Finance Report yang join lewat bank_name+account_number
  tetap akurat).
- **Bank Fee** (form-group di bawah Transaction Amount, 4 tombol: `None (Rp 0)` default, `Rp 2,500`, `Rp 6,500`,
  `Custom` + tombol Scan khusus Custom — slip hasil scan Amount kadang GROSS termasuk fee bank): preview read-only
  `#capAmountPreview` di bawah Amount, polos kalau fee=0, `GROSS (NET)` kalau fee>0. `amount`/`final_amount_idr`
  yang dikirim ke server SELALU NET (gross dikurangi fee); fee dikirim terpisah sebagai `bank_fee`, disimpan ke
  `disbursement_bank_fees` (lihat §4) kalau > 0. Validasi: fee tidak boleh ≥ gross. Bank Fee ikut Currency/Exchange
  Rate yang SAMA dengan Transaction Amount (bukan selector sendiri). Field Bank Fee juga muncul di Field Picker
  (tombol "Fields" di viewer) saat wizard Disbursement aktif.

**Settings**: Company Documents (upload/edit/delete→recycle/share WA/download) dan Company Bank Accounts (autocomplete
dari `bank_accounts.json`, tanpa password).

**Logistic** (hanya departemen `dates`): lihat §4/§5 untuk model data & invarian stok; UI List/Movements/Create New
Logistic standar (accordion per activity code, form tambah produk per activity code).

**Sales Transaction** (4 tab — Activity Code/Customer List/Itemized Pricing dibahas terpisah di atas; tab ini fokus
New Order, Customers, Returns, Booking):
- *New Order*: parse pesan WA → alias produk → pilih stok (normal/defective) → harga dari `customer_item_prices`
  atau Set Price/manual (defective) → `create_order.php`. Credit_balance auto-apply (lihat §5.12) terjadi di sini,
  ditampilkan di layar Confirm New Order (`showStConfirm`) kalau `credit_applied > 0` (field `paid_before`/
  `credit_applied`/`paid_after` dari response `order.invoice`, plus `order.credit.balance_before`/`balance_after`).
  `force_new_invoice` (checkbox "Open as a new invoice") memaksa invoice baru (lihat §5.11).
- *Booking*: Create Booking (reservasi stok, selalu invoice baru, opsional Apply Credit — lihat §5.17). **Active
  Bookings**: section terpisah di bawah Refund History, hanya booking status `active` — tiap booking tampil sebagai
  kartu, "Taken"/"Remaining" ditampilkan **net** (lihat §5.16) kalau beda dari gross. Tombol: **+ Add Payment**
  (reuse, lihat di bawah; notes penomoran First/Second/... pakai `invoice_payment_count` dari
  `list_customer_bookings.php`, bukan `.length` array payments yang tidak dikirim di kartu ini), **Release** (muncul
  kalau masih ada sisa reservasi — fly window qty per produk, default = sisa reservasi, bisa diedit untuk rilis
  sebagian, + tanggal + driver/police opsional → dry run → Confirm → `ajax/release_booking.php`), **Edit** dan
  **Cancel** (muncul selama masih ada sisa qty belum dirilis — lihat detail endpoint masing-masing di §7 dan
  invarian di §5.16; Edit tanpa layar confirm terpisah, tombol "Save Changes" langsung dry_run=1 lalu dry_run=0
  berturutan; Cancel otomatis ambil preview dry_run=1 begitu overlay dibuka). Endpoint list:
  `ajax/list_customer_bookings.php` (GET customer_id, dipanggil bareng `list_customer_orders.php` pakai
  Promise.all tiap buka kartu customer).
- *Add Payment*: upload bukti (viewer + OCR). Field: source bank, source account number, source account name,
  destination bank, destination account number, destination account name (semua uppercase, editable). Datalist
  destination diisi dari `ajax/list_bank_accounts.php`, dimuat ulang (`loadDestBankAccounts`) tiap form dibuka dan
  setelah rekening baru didaftarkan. Notes auto-fill via `recalcPayCapNotes()`, dibandingkan ke outstanding saat form
  dibuka (`total_amount − paid_amount`); berhenti begitu user mengetik manual di Notes. Overpayment ditolak (bukan
  di-clamp). Bukti di `selling/{year}/{customer}/payments/`. Setelah Save, kartu customer di-refresh
  (`refreshStCustomerCard`). **Auto-isi rekening dari OCR**: nomor rekening hasil scan dicocokkan ke
  `bank_accounts.json` sebagai **destination** → isi Destination Bank & Name (tidak ada currency: form &
  `invoice_payments` memang tak punya kolomnya); aturan cocok, fly window pilih/daftar, dan pencocokan-by-bank-saja
  sama seperti di Input Transaction / Disbursement (lihat bagian itu untuk detail). Baris "AUTO-APPLIED FROM CREDIT
  BALANCE" (tab Customers → expand invoice → Payments) menampilkan "Originally received via:" + list rekening &
  nominal masing-masing (bukan link "View proof of payment" — baris ini tidak pernah punya proof file), dari field
  `p.credit_sources` (`list_customer_orders.php`, join `customer_credit_applications`+`customer_credit_sources`).
- *Returns*: pesan WA retur → tiap produk dialokasikan FIFO otomatis ke pickup (bisa dikoreksi, `+ Split`). Tiap split
  punya disposition Good / Defective / Price Adjustment. Review (`dry_run`) → password (pola b) → simpan berurutan
  `create_return.php` lalu `create_price_adjustment.php` (dua transaksi, tidak atomik; kegagalan sebagian dilaporkan).
  Precondition "invoice asal sudah pernah dibayar" & pelacakan sumber credit — lihat §5.12.
- *Refund* (tombol "Refund" di baris Credit Balance tab Customers): buka fly window `refundMethodChoiceOverlay`
  (Cash/Transfer) via `openRefundMethodChoice()`. **Cash** → `refundOverlay` (form manual), default Method="CASH"
  dan Source Account otomatis terpilih akun "0000 0000 0000 · UNSUPPORTED TRANSACTION" (`selectRefundDefaultAccount()`,
  match by nomor rekening/nama ke opsi dari `bank_accounts.json`) — tetap bisa diganti manual. **Transfer** →
  halaman full-page `viewRefundCapture` (pola sama seperti Disbursement/Invoice Payment: upload slip, viewer + OCR
  per-field), field: Refund Date, Amount, Source Bank/Account Number/Account Name (rekening PERUSAHAAN pengirim,
  OCR-match ke `bank_accounts.json` via `ACCT_LOOKUP.refund`, arah sama seperti Disbursement), Notes, dan **Bank Fee**
  (pola sama seperti Bank Fee Disbursement di atas — 4 tombol, preview `#refCapAmountPreview` read-only GROSS(NET),
  `amount` yang dikirim SELALU NET, `bank_fee` terpisah, disimpan ke `refund_bank_fees` kalau > 0, validasi fee < amount).
  **Sengaja tidak ada field rekening tujuan** (lihat §4, `invoice_refunds`). Kedua jalur (Cash & Transfer) kirim ke
  `ajax/create_refund.php` yang sama; Transfer kirim `method=TRANSFER`, proof wajib diupload (Cash opsional). Tombol
  Back dari `viewRefundCapture` balik ke tab Customers.

**Print Invoice** (`ajax/print_invoice.php?invoice_id=N&banks[]=…`; read-only, tanpa migration; tombol Print di header
invoice tab Customers):
- Fly window "Bank Accounts on Invoice" (grup Rupiah / Foreign currency, Select all/Deselect all, pilihan diingat di
  localStorage `aos_invoice_bank_selection`). Hanya rekening di `banks[]` yang tampil.
- Format: HTML A4 bahasa Indonesia, atau PDF server-side via FPDF (`&format=pdf`, `_invoice_pdf.php`, `pi_pdf_render()`).
  Keduanya memakai satu sumber data. Font core Helvetica/cp1252, sehingga `→` ditulis `->`.
- Halaman 1: header gambar, customer, meta, tabel utama per produk + harga satuan. Pengambilan normal, low grade, dan
  Return dengan produk & harga sama digabung satu baris (harga beda = baris terpisah). Potongan harga dikumpulkan per
  produk di paling bawah; Harga Satuan baris potongan = selisih per unit dalam kurung; Keterangan "Harga turun Rp A → Rp B"
  (A = `price` pickup asal via `source_movement_id`; B = `price` baris `price_adjustment`; selisih = `total_price` ÷ qty,
  jadi `price_adjustment.price` BUKAN selisih). Label LOW GRADE / RETURN / POTONGAN HARGA hanya bila isinya satu jenis.
  Kolom: No · Tanggal · Produk · Jumlah (angka saja, tanpa unit) · Harga Satuan · Total · Keterangan.
- Ringkasan: Total Pengambilan Barang = Σout − Σin (sudah net; baris Return Barang dihapus), Potongan Harga, Total
  = `invoices.total_amount`. Lalu terbilang, riwayat pembayaran, ttd (kiri) + rekening (kanan), catatan "* Satuan Produk"
  paling bawah. Unit bermakna "tidak ada" (NO PRIMARY CARTON, NONE, N/A, `-`, diawali NO/TIDAK ADA/TANPA) tidak dicetak
  (`pi_unit_is_none()`).
- Nilai negatif ditulis dalam kurung `(Rp 1.000)`. Stok defective ditulis "LOW GRADE" (jangan pakai kata "cacat"). Istilah
  invoice: "Return", bukan "Retur".
- Halaman 2 = LAMPIRAN (hanya bila `attach=1`): semua movement per tanggal (sopir, no. polisi, subtotal, TOTAL). Toolbar
  layar memberi peringatan bila jumlah baris ≠ `total_amount`. Penanda tangan = `PI_SIGNER_NAME`/`PI_SIGNER_ROLE`.
- Send Invoice (WhatsApp): (1) fly window lampiran (default Without); (2) fly window sapaan Kak/Bang/Buk/Pak/None atau
  teks sendiri (maks 30 karakter), dengan checkbox "Remember" (localStorage `aos_invoice_honorific_{customer_id}`).
  Lalu tab WA dibuka synchronous (anti popup-blocker), PDF di-fetch dan diunduh sebagai `Invoice-<no>.pdf`, tab
  diarahkan ke `wa.me/<telp>?text=…` (telp digit saja, awalan 0 → 62). Teks: "Yth. {sapaan} {nama}, …".

**Report** (`dashboard_content.php`, lihat §2 — nama file berubah 16 Nov 2026, isinya tetap satu-satunya: Finance
Report): ringkasan saldo per rekening, Chart.js (inflow/outflow per bulan, saldo kumulatif, saldo per rekening),
ledger gabungan + Export CSV, snapshot piutang/credit. Data dari `ajax/get_finance_report.php`: outflow
(`transactions` category=disbursement), inflow (`invoice_payments`, exclude `source_bank='CREDIT BALANCE'` karena
itu bukan uang baru masuk — sudah dihitung inflow saat pertama kali diterima rekening aslinya), outflow refund
(`invoice_refunds`), dicocokkan ke `bank_accounts.json` via bank_name+account_number (bukan FK). Saldo mulai 0
(keputusan user). Refresh lewat event `aos:section-shown`. Lebar: Ledger `min-width:760px`, tabel piutang 420px,
`.table-wrapper` `overflow-x:auto` di semua lebar. Ledger punya kolom **Category** (disbursement = nama kategori dari
`disbursement_categories`, invoice payment tetap `SALES PAYMENT`, refund = null) + Export CSV. Nominal ledger: inflow
hijau, outflow merah, negatif pakai kurung `(Rp …)` bukan minus (helper `fmtIDRParen`, hanya di ledger; kartu ringkasan
tetap `fmtIDR`; CSV tetap angka mentah + kolom Type). Belum ada: edit kategori transaksi yang sudah tersimpan
(list/edit/delete transaksi tersimpan memang belum dibangun).
- **Urutan ledger**: tanggal terlama → terbaru (ASC), baik dari tiap query SQL maupun `usort` final di
  `get_finance_report.php` — running balance di JS tinggal akumulasi apa adanya tanpa re-sort. Tabel ledger punya
  `<tfoot>` baris **Total** (kolom Amount = net total bertanda, kolom Running Balance = saldo akhir; sama angkanya
  karena saldo selalu mulai dari 0).
- **Kolom Description**: dua baris — baris atas label (mis. "Payment — Budi (INV-001)"), baris bawah notes asli
  transaksi itu (`row.notes`, sudah dikirim backend sejak lama) kalau non-kosong setelah trim (`.rpt-desc-note`,
  font lebih kecil/pudar). Baris Credit Balance (notes selalu null) tetap satu baris. Suffix currency non-IDR
  "(CUR 1,234.56)" tetap nempel di baris pertama. Export CSV: `label + '\n' + notes` dalam satu cell (RFC4180 valid).
- **Kolom Notes** (kolom ke-8, isi `ledger_note`, BEDA dari `notes` bebas-teks di atas): dipakai untuk label jenis
  baris turunan yang perlu ditandai visual — "Bank Fee", "Credit Balance" (baris kredit terbentuk dari payment,
  lihat di bawah), dan Notes baris disbursement/Bank Fee-nya diisi `activity_name` saja (TANPA activity code —
  sebelumnya sempat pakai format "{kode} — {nama}", direvisi user jadi nama activity saja). Baris dengan
  `ledger_note` terisi menampilkan `display_amount` (bukan `amount` yang sengaja 0 untuk baris display-only) di
  kolom Amount — konvensi: `shownAmount = row.ledger_note ? (row.display_amount || 0) : row.amount`. Baris yang
  SECARA NYATA menambah/mengurangi kas (refund, disbursement, Bank Fee, split real-money payment) selalu isi
  `display_amount = amount` (nilainya identik, bukan baris display-only) justru supaya tidak kena tampil Rp 0 oleh
  konvensi ini.
- **Customer Credit Balance — pembentukan & penggunaan**: saat credit terbentuk dari suatu payment (`invoice_payment_credit`,
  dari `customer_credit_sources`), baris Ledger-nya TETAP satu baris seperti payment biasa (background gelap via
  `group_id`, TIDAK ada baris Ledger terpisah untuk momen "kredit terbentuk"). Baris ini **bisa diklik**
  (`.rpt-ledger-clickable`, `data-credit-source-id`) → modal `#rptCreditDetailOverlay` menampilkan: jumlah asli,
  daftar `usage` (gabungan `applied`/`refunded`/`returned_to_source`, di-sort by `created_at`) — "Applied to
  {customer} ({invoice})" dan "Refunded (METODE)" ditulis dalam kurung `(Rp …)` (mengurangi), "Returned (added back
  to this credit)" ditulis polos tanpa kurung (menambah) — serta baris "Remaining now" (bold) = `remaining_amount`
  live dari DB + akumulasi dari semua row return yang ter-fold ke situ (lihat §5.12). Kalau belum pernah dipakai:
  "Not used yet — full amount still available". **Tidak ada lagi baris Ledger terpisah "Remaining credit
  balance"/"Applied to ..."** di tabel utama (dipindah total ke modal ini, keputusan user — sebelumnya sempat ada
  tapi membingungkan karena terlihat seperti 3 transaksi padahal 1 sumber uang). Baris refund yang mengambil dari
  credit_balance (`customer_credit_refunds`) dapat chip "↳ Credit Balance — {tanggal}" di bawah Description-nya,
  diklik → buka modal credit source yang sama. **Limitasi**: refund dari SEBELUM `migration_customer_credit_refunds.sql`
  dijalankan tidak akan punya chip ini (tidak error, cuma tidak terhubung).
- **Bank Fee di Ledger** (refund & disbursement): kalau refund/disbursement punya Bank Fee > 0, persis di bawah
  baris induknya muncul baris "Bank Fee" terpisah — rekening SAMA dengan induknya, **betul-betul ikut menambah**
  `accountsOut[...]['outflow']`/`unassigned` dan `bump_monthly()` (bukan cuma tag informasi), supaya Total Outflow
  & balance per-rekening benar. Urutan baris terjamin lewat trik `ref_id` string `"<id>_fee"` (lihat §3 gotcha #9).
  - **Refund Bank Fee**: chip "↑ Refund — {tanggal}" di bawah Description, SELURUH `<tr>`-nya clickable
    (`data-link-refund-id`) → scroll+flash (`scrollToAndFlashRefundRow()`, highlight hijau 1.4s) ke baris refund
    terkait. Kalau fee-nya non-IDR, baris itu juga clickable untuk `openFxDetail()` (lihat di bawah) — prioritas
    klik: `data-fx-ref-id` dicek LEBIH DULU (lebih spesifik) sebelum `data-link-refund-id`.
  - **Disbursement Bank Fee**: field backend (`$bankFeesByDisbursement`, `disbursement_ref_id`) sudah lengkap dan
    sudah masuk Ledger (ikut Total Outflow & balance), TAPI **belum ada chip/klik scroll+flash** seperti refund
    (frontend belum pernah diminta eksplisit untuk ini — kalau mau, pola yang sama tinggal disalin:
    `ledgerRefundRows`→`ledgerDisbursementRows`, `data-link-refund-id`→`data-link-disbursement-id`, dst).
- **Amount (IDR) baris hasil konversi currency** (baris yang punya `row.original` + `row.exchange_rate`, saat ini
  hanya Disbursement & Bank Fee-nya yang non-IDR): bisa diklik → `openFxDetail()` buka panel detail (reuse overlay
  `rptCreditDetailOverlay`, judul dinamis via `#rptCreditDetailTitle`) isinya Original amount, Exchange Rate, Date,
  Final (IDR). Peta `ledgerFxRows` (keyed `ref_id`).
- **Klik Description buka bukti file**: kalau baris itu punya bukti (`row.has_proof`), label (bukan seluruh cell)
  dibungkus `.rpt-desc-link`, klik → tab baru ke `ajax/view_proof.php?source=...&ref_id=...` (stream file langsung,
  bukan JSON). Sumber bukti per jenis baris: `transactions.document_path` (disbursement), `invoice_refunds.proof_path`
  (refund), `invoice_payments.proof_path` (invoice_payment & invoice_payment_credit, pakai `ref_id` payment induknya).
  Baris Bank Fee tidak punya bukti sendiri → tidak clickable untuk ini. Semua `*_path` relatif ke `AOS_STORAGE_BASE`
  (bukan konstanta di `db_config.php` — tiap file define sendiri, guarded `defined()`).
- **Filter Activity Code**: dropdown `#rptActivityFilter`, diisi dari `ajax/list_activity_codes.php` (reuse endpoint
  tab Transactions > Activity Code > Preview), value = `id`, label `{activity_code} — {activity_name}`. Dikirim sebagai
  `activity_id` ke `get_finance_report.php`. Per sumber:
  - **Disbursement (outflow)**: difilter langsung via `transaction_disbursements.activity_id`.
  - **Invoice payment (inflow)**: difilter via `EXISTS` ke `logistic_movements` (`invoice_id`) JOIN `logistics`
    (`activity_id`). **Tidak dipecah proporsional** — kalau SALAH SATU produk invoice dari activity code yang
    difilter, payment itu ikut tampil **utuh**. Keputusan user.
  - **Refund**: **TIDAK difilter sama sekali, selalu tampil apa adanya**. `invoice_refunds` tidak punya kolom
    `invoice_id`/`activity_id` — refund mengurangi `customers.credit_balance` yang akumulasinya lintas
    invoice/activity code, secara skema memang tidak bisa ditelusuri ke satu activity code. Keputusan sadar user,
    **bukan bug** — jangan "perbaiki" tanpa nambah kolom baru + migration data lama kalau suatu saat mau diubah.
- **Card Filter & Ledger**: keduanya `<details class="card rpt-collapsible">` sendiri-sendiri, default collapsed,
  TIDAK pakai `data-rpt-accordion` (jadi bisa dibuka bersamaan, beda dari Totals/Bank Accounts yang exclusive).
  Urutan card: Filter → Ledger → Totals → Bank Accounts → Charts → Receivables. Card Filter membungkus toolbar
  From/To/Activity Code + tombol Apply/Clear/Export CSV.

**Investor** (`investor_content.php` + `ajax/investor_api.php`, satu endpoint untuk semua aksi; rumus & invarian di
§4–5 di atas; triple-click login untuk masuk extended mode, lihat §1):
- UI 4 tab: **Investors** (CRUD nama + tabel ringkas semua angka, kolom Deposits/Non-Project/ICU/Used in
  Projects/TP/PP/Rolled Capital; card ringkasan di atas tabel: Total ICU, Total Used in Projects, Total Remaining,
  dijumlahkan dari kolom yang sama) / **Investment** (pilih investor dulu lewat `fundChangeBtn`, lalu 3 card
  collapsible: Investment Input, Non-Project Expenses, Investor Fund Utilization — lihat gotcha collapsible di
  bawah) / **Report** (4 tabel: Investor Fund Usage Details, Sales Report per Project, Investment Profit
  Distribution, Per Investor Share — tiap tabel punya `<tfoot>` baris Total untuk tiap kolom angka, dijumlah di JS
  dalam `renderReport()`, pola sama seperti `txTotal` di tabel Project Expenses tab Investment; kolom non-angka
  seperti pdp %, Ratio %, Actions dibiarkan kosong karena jumlahnya tidak bermakna) / Profit Payment & Rolled
  Capital. Hapus pakai password pola (a) langsung.
- Card header "Selected investor" (tab Investment) menampilkan Available (ICU), Used in Projects, Remaining untuk
  investor yang sedang dipilih — field sama dengan tabel Investors (`findInv().icu`/`.related`), diisi di
  `renderFund()`.
- **Investment Input**: deposit (IDR/valas + kurs manual, bisa berkali-kali) + tabel Received Investments (Edit
  pola bebas password, Delete pola a).
- **Non-Project Expenses**: return_capital/aid/other, mengurangi ICU, Delete pola (a).
- **Investor Fund Utilization**: dropdown Activity Code (`#allocActivity`) → tabel "Investor Fund Utilization"
  (`allocBody`, `renderAllocTable()`) menampilkan SEMUA investor yang terkait ke activity itu, dengan tombol Unlink
  per baris (langsung `save_fund_shares` tanpa investor itu, tanpa buka fly window) → tombol "Share of Investor
  Fund (%)" buka fly window `invAllocOverlay` (`invAllocBody`, `allocRecalc()`/`allocRenderModal()`) berisi SEMUA
  investor ber-ICU>0, auto-balance cascading (lihat §5.13) saat satu baris diedit, tombol Unlink per baris (set %
  jadi 0, baru tersimpan saat Save fly window) → tabel "Project Expenses" **read-only**, auto-linked via Category
  (kolom Category + Description, bukan Notes; `<tfoot>` Total dihitung di JS). API: `save_fund_shares` (batch,
  `activity_id` + `rows` JSON `{investor_id,percent}`, satu transaksi hapus-lalu-insert ulang semua baris activity
  itu; investor tidak di `rows` atau percent≤0 = otomatis unlinked).
- **Tombol Edit/Delete** semua tabel Investor pakai class `.inv-action-btn` (`inline-flex` center, width:72px tetap),
  dibungkus `.inv-row-actions` kalau >1 tombol per baris.
- **Kolom bantuan**: semua `<th>` modul Investor punya `data-help="…"`; klik judul kolom → fly window
  `invColHelpOverlay` (event delegation satu listener di `root`, bukan per-`<th>`). Pola generik, bisa dipakai modul
  lain (`th[data-help]` + 1 modal + 1 listener). Khusus label non-`<th>` (mis. ringkasan card), pakai atribut
  `title` HTML biasa, BUKAN `data-help` (listener-nya scoped ke `th[data-help]` saja).
- **Gotcha khusus modul ini** (selain gotcha umum §3):
  1. Tabel modul Investor override `responsive.css` (gotcha umum #7) lewat `.inv-scroll{display:revert}` supaya
     tetap tabel di semua lebar layar.
  2. Input `.input-number-comma` di modul ini **tidak** otomatis diformat oleh mekanisme global proyek (beda dari
     modul lain) — grouping ribuan live via `formatComma()` + satu `input` listener lokal (delegasi di `root`),
     tidak memotong desimal (exchange rate 6 digit), cursor position disesuaikan.
  3. Collapsible 3 card tab Investment: default semua collapsed, exclusive-open 1 klik (klik header tertutup → buka
     & tutup yang lain; klik header yang sedang terbuka → tutup). Pakai pola `max-height:scrollHeight` proyek
     (bukan `display:none/block`, gotcha umum #2), di-re-sync lewat `window.invSyncCollapsibleHeights()` di akhir
     `render()` dan `renderAllocTable()` karena keduanya menulis ulang tbody di dalam card yang mungkin terbuka.

**Document Tools** (`document_tools_content.php`):

- *Signature Library*: satu-satunya cara tambah signature = lewat editor canvas (Upload Photo → edit → Save to
  Library, `ajax/upload_signature.php`); tidak ada form upload manual terpisah. Storage
  `storage/signature/{company_name_slug}/{origin_slug}/{file}`. Field **Company Name** + **Origin** (tidak bisa
  diedit lewat form Edit, hanya saat create; tidak terhubung ke `document_activities.json` — itu murni milik
  Document Editor). Kolom `is_main` (boleh lebih dari satu sekaligus, diatur manual user, tanpa constraint
  unik/auto-unset); `list_signatures.php` `ORDER BY is_main DESC, signature_date DESC, id DESC`, badge "MAIN" di
  list. List dikelompokkan per Company Name (header grup collapsible, grup pertama auto-terbuka). Date default ke
  hari ini (local time) saat form pertama dibuka & tiap reset form.
  - Signature Editor (canvas, Upload Photo saja): workspace putih + grid garis halus. **Crop**: drag kotak awal →
    8 handle resize + drag-dari-tengah untuk geser, tombol **Undo Crop** (1 langkah terakhir). **Erase Part**:
    brush radius diatur slider "Eraser Size", hasil tersimpan di `erasedMask` terpisah dari threshold; **Undo
    Erase** adalah history stack maks 30 entri, snapshot diambil tiap awal stroke. Remove White Background
    (`sigBgThreshold`) punya tombol -5/+5 di samping slider. **Ink Contrast** (slider 0-100, default 0): satu
    kontrol gelapkan warna + tebalkan opacity piksel tinta (`inkFactor`), selalu dihitung ulang dari
    `originalPhotoImageData`, tidak direset saat upload foto baru/Clear. **Zoom** (`sigZoomSlider` 50-300% +
    tombol −/Reset/+): murni ukuran tampilan (CSS), tidak mengubah resolusi pixel asli canvas. Toolbar terbagi
    jadi kartu grup: Photo / Zoom / Crop / Erase Part / Remove White Background / Ink Contrast. Konstanta Zoom
    (`ZOOM_MIN/MAX/STEP`) khusus Signature Editor — Document Editor pakai prefix `DOC_ZOOM_*` sendiri.
  - Delete Signature: pindah ke `recycle/` + membersihkan folder asli `storage/signature/.../` yang jadi kosong
    (jalan ke atas berhenti di folder yang masih ada isinya, tidak pernah menghapus `storage/signature` sendiri).

- *Letterhead Library* (tabel `letterhead_library`, 1 entri = header wajib + footer opsional, storage
  `storage/letterhead/{company_slug}/{origin_slug}/`, pola CRUD sama seperti Signature Library): 3 input
  (`lhNameInput`/`lhCompanyInput`/`lhOriginInput`) pakai `.input-uppercase` + `strtoupper()` server. Delete
  membersihkan folder asli sama seperti Signature, dan tombol Delete punya teks di sebelah ikon.

- *Document Editor*: upload dokumen gambar atau **PDF multi-halaman** (PDF→gambar per halaman via PDF.js, murni
  browser), kanvas **Fabric.js 5.3.0** (cdnjs, dibungkus try/catch — gotcha §3 #8). Tiap halaman punya state
  tersendiri. Ribbon toolbar (satu baris, scroll horizontal): Photo Correction (Deskew + Remove White
  Background/Ink Contrast), Guide Lines (garis merah solid + tombol Remove Guide Lines), tombol -/+ ukuran tidak
  menutup ribbon, panel dropdown `position:fixed` + clamp viewport. Clear All tanpa password.

## 7. Peta endpoint (`ajax/`)
- **Auth/util**: `verify_password.php`, `_require_reverify.php`, `_order_patterns.php`, `_invoice_pdf.php`.
- **Activity/Customer/Settings**: `create|update|delete|list|preview_activity_code`, `manage_departments`,
  `create|update|delete|list_customers`, `*_customer_item_price(s)`, `list_priceable_products`,
  `upload|update|delete|download|share|list_documents`, `list|save_bank_account(s)` (`save_bank_account.php` butuh
  `require_reverify=1` saat dipanggil dari auto-isi rekening OCR, Settings tetap tanpa password),
  `create_disbursement` (kini wajib `category_id`, opsional `bank_fee` → `disbursement_bank_fees`),
  `list|save|update|delete_disbursement_category`.
- **Logistic**: `list_logistics`, `list_logistic_activities` (balikin `has_logistic` + `existing_products[]`),
  `upload_logistic_document`, `manage_packaging_units?kind=primary|secondary`, `list_logistic_movements` (nested
  tahun/bulan/hari + `total_out/in/adjustment`, `*_normal_qty`, `total_taken_qty`, `by_customer`), `manage_defective_stock`.
  - `create_logistic.php` POST `activity_id, incoming_date, products` (JSON array, maks 50) → satu transaction →
    `{ok, data:{ids, count}}`. Tolak `product_name` dobel dalam satu activity code.
  - `update_logistic.php` validasi `product_name` (unik per activity code, kecuali baris itu sendiri).
  - `delete_logistic.php`: dokumen & folder `import_documents/` hanya dihapus kalau produk yang dihapus adalah produk
    terakhir di activity code itu.
- **Report**: `get_finance_report` (lihat §6 Report) + `view_proof.php` — GET `source`
  (`disbursement|refund|invoice_payment|invoice_payment_credit`) + `ref_id`, stream file bukti transaksi ke tab baru;
  beda dari `view_invoice_payment_proof` (Sales, khusus satu invoice payment) — ini generik untuk semua jenis baris
  Ledger di Finance Report.
- **Sales**: `parse_order_message`, `save_order_alias`, `check_existing_orders`, `create_order`, `update_order`,
  `list_customer_orders` (per invoice movements + `batch_id`, `source_movement_id`, `discount_value`, payments[] dengan
  fallback kolom lama bila migrasi bank belum jalan, plus `credit_sources` untuk baris AUTO-APPLIED — lihat §6),
  `create_invoice_payment`, `view_invoice_payment_proof`, `apply_customer_credit`, `create_refund` (terima opsional
  `bank_fee` → `refund_bank_fees`), `list_bank_accounts`, `get_finance_report`.
  - `list_return_price_options.php` GET `customer_id, logistic_id` → `{ok,data:{available,total_taken,total_returned,
    movements:[{movement_id,movement_date,qty,price,remaining,remaining_adjustable,disabled,label}]}}`.
    ⚠ Pernah tertimpa isi `list_logistic_movements.php` (gejala: "Loading pickup history…" tak selesai). Kalau terulang,
    cek file ini dulu.
  - `create_return.php` POST `customer_id, return_date, driver_name, police_number, dry_run, invoice_id?,
    items:[{logistic_id, allocations:[{source_movement_id, qty, price, restock_bucket}]}]` → `{ok, ret:{invoice, items,
    grand_total, movement_ids}}`. Code `not_returnable` bila melebihi remaining; code `source_invoice_unpaid` bila
    invoice asalnya belum pernah dibayar (lihat §5.12) — SELALU masuk `customers.credit_balance` kalau `invoice:
    null`, tidak ada pilihan lain lagi.
  - `create_price_adjustment.php` POST `customer_id, adjustment_date, driver_name, police_number, dry_run, invoice_id?,
    items:[{logistic_id, allocations:[{source_movement_id, qty, old_price, new_price}]}]` → `{ok, adj:{…, grand_discount,
    movement_ids}}`. Code `not_adjustable`.
  - `manage_defective_stock.php`: GET `action=history` (Taken Out) & `action=return_history` (Returned In) + `logistic_id`
    → `{data:{product_name, unit_label, defective_qty, defective_taken_qty, history:[…]}}`. POST `action=repair`
    `logistic_id, qty` (≤ defective_qty).
  - **Booking** (§5.16-17 & §6 Sales Transaction untuk detail UI): `create_booking.php` (dry_run, selalu invoice baru;
    `apply_credit_amount` optional — re-clamp server-side ke `min(credit_balance, grand_total)`, lihat §5.17),
    `list_customer_bookings.php` (GET customer_id → booking `active` + `qty_released_net`/`qty_returned`,
    `invoice_payment_count`), `release_booking.php` (dry_run, POST qty per booking_item → movement `out` + naikkan
    `qty_released` & `total_taken_qty`/`customers.total_inflow`, tulis `booking_item_releases`), `edit_booking.php`
    (dry_run, POST `items` campur `{booking_item_id,qty}` existing dan `{logistic_id,qty,stock_source}` baru +
    `new_prices` optional — lihat header comment file untuk kontrak lengkap), `cancel_booking.php` (dry_run, batalkan
    semua sisa belum dirilis sekaligus; invoice yang jadi benar-benar kosong di-DELETE, invoice yang overpaid
    di-cap & ditutup `status='cancelled'`, lihat §5.16; overpaid dari credit yang pernah di-apply di-reverse ke
    source asal via `ccs_reverse_applications_for_invoice()`, lihat §5.17).
    `credit_sources_helper.php` (shared, bukan endpoint sendiri): `ccs_lock_sources()`/`ccs_spend_fifo()` (spend FIFO),
    `ccs_reverse_applications_for_invoice()` (kebalikannya, per invoice), `trace_credit_to_real_payments()` (hanya
    untuk credit yang BENAR baru terbentuk dari transfer bank asli, bukan untuk reversal — skip baris
    `source_bank='CREDIT BALANCE'`).
- **Investor**: `investor_api.php`, satu endpoint untuk semua aksi (lihat §6 Investor untuk detail UI tiap action).
  Action relevan: `save_deposit`/`update_deposit`/`delete_deposit`, `save_support_expense`/`delete_support_expense`,
  `save_fund_shares` (batch alloc per activity, lihat §5.13 & §6), `save_pdp`, `save_profit_payment`,
  `save_investor`/`rename_investor`/`delete_investor`. `inv_compute()` membangun `$activityOut` (dipakai tabel
  Project Expenses & allocation) — query `$expensesByActivity` HARUS dideklarasikan sebelum loop yang memakainya
  (pernah jadi bug diam-diam).

## 8. Pekerjaan terbuka

Belum dibangun (disengaja, bukan bug):
- Fitur pembayaran bersama (disebut user saat desain Create New Logistic). Baru placeholder di form.
- Filter/pagination Movements; retention `storage/recycle/`; `customers.profit`; edit/hapus alias & order; aturan stok minus;
  uppercase server di `manage_packaging_units.php`.
- Data lama order yang sudah terpecah sebelum `batch_id` tetap terpisah. Skrip migrasi belum dibuat.
- Opsi: `DROP COLUMN logistics.defective_reference_price` (ireversibel, belum dijalankan).
- Opsi: host Chart.js lokal di server, kalau jaringan user terus memblokir cdnjs.
- Finance Report belum menampilkan breakdown refund per credit_source_id yang direfund (butuh kolom FK baru
  `credit_source_id` nullable di `invoice_refunds` kalau mau dikerjakan — `invoice_refunds` hanya terikat ke
  `customer_id`, tidak ke sumber credit spesifik).
- Chip "↑ Disbursement — {tanggal}" + klik scroll+flash untuk baris Disbursement Bank Fee di Ledger (pola refund
  Bank Fee sudah ada, tinggal disalin — lihat §6 Report).
- **Document Archive** (24 Nov 2026, user request, SUDAH DITES load/list; upload/delete masih BELUM DITES): card
  ke-4 baru di Document Tools, sejajar Signature/Letterhead Library/Document Editor — "Document Editor minus
  editing": upload **file jenis APAPUN** (direvisi 24 Nov 2026 dari JPG/PNG/PDF saja → user minta "support semuanya,
  seperti docx dan lain-lain") langsung disimpan apa adanya (TANPA kanvas Fabric.js, blocks, deskew, atau recombine
  FPDF), tanpa whitelist ekstensi, maks 50 MB. Field sama seperti Document Editor (Document Name, Date, Activity
  Title — pakai datalist `sigActivityOptions` yang sama). Tabel baru `archived_documents`
  (`migration_archived_documents.sql`, **jalankan dulu**), storage
  `storage/company_activity/{y}/{m}/{slug}/archived_documents/{file}`. List+Delete saja (pola sama Letterhead v1) +
  Download (stream file asli, Content-Type fallback `application/octet-stream` utk ekstensi yang tidak dikenal).
  File baru: `ajax/upload_archived_document.php`, `ajax/list_archived_documents.php`,
  `ajax/delete_archived_document.php`, `ajax/download_archived_document.php`. Delete pakai `_require_reverify.php`
  (`aos_require_recent_reverify()`, dikonfirmasi cocok persis). Delete pindahkan file ke
  `storage/recycle/archived_documents/` lalu bersihkan folder asli yang jadi kosong (pola sama Signature/Letterhead).

- **Disbursement — Additional Documents** (24 Nov 2026, user request, BELUM DITES): form Disbursement sekarang bisa
  attach dokumen tambahan (invoice, PO, dll) selain slip transfer — 0 atau lebih file, beda dari slip (yang tetap
  cuma 1 file, tanpa OCR engine). Tabel baru `disbursement_additional_documents` (id, transaction_id, document_path,
  original_name, created_at) — **jalankan `migration_disbursement_additional_documents.sql` dulu**. Tanpa FK ke
  `transactions`, pola sama seperti `refund_bank_fees`/`disbursement_bank_fees`. Disimpan di folder BARU yang
  di-generate di dalam `.../disbursement/`, namanya = nama file slip transfer yang sudah ada TANPA ekstensi (variabel
  `$baseName` yang sudah ada di `create_disbursement.php`, mis. slip `pembelian_kardus_20260920.pdf` →
  folder `.../disbursement/pembelian_kardus_20260920/`), file di dalamnya dinamai `<slug>_<n>.<ext>` berurutan.
  Validasi file sama persis dengan slip (PDF/JPG/PNG/WEBP, 1 byte–20 MB per file), tidak ada batas jumlah file
  (keputusan user). POST field `additional_documents[]` (multipart array, optional). Insert ke DB dalam satu
  transaction yang sama dengan insert `transactions`/`transaction_disbursements`; rollback juga hapus semua file
  tambahan yang sempat ter-upload (bukan cuma slip). Frontend: input `<input type="file" multiple>` baru
  (`capAdditionalDocsInput`) di bawah input slip, append-only (tiap pilih file nambah ke array `capAdditionalFiles`,
  tidak mengganti), preview nama file di `capAdditionalDocsList`, direset di `resetCaptureForm()`.
  **Melihatnya di Report/Ledger** (24 Nov 2026, BELUM DITES): klik Description pada baris Disbursement tetap lewat
  `ajax/view_proof.php?source=disbursement&ref_id=...` (frontend `dashboard_content.php` TIDAK berubah). Endpoint ini
  sekarang cek dulu apakah `disbursement_additional_documents` punya baris untuk `ref_id` itu — kalau ADA, balas
  halaman HTML kecil berisi link (slip + tiap dokumen tambahan, masing-masing `target="_blank"`) alih-alih langsung
  stream file; tiap link menunjuk endpoint yang sama dengan param baru `doc` (`doc=slip` utk transfer slip,
  `doc=<id>` utk satu baris `disbursement_additional_documents`). Kalau TIDAK ada dokumen tambahan, perilaku lama
  (langsung stream slip) tidak berubah sama sekali — sumber lain (`refund`, `invoice_payment`,
  `invoice_payment_credit`) juga tidak tersentuh oleh perubahan ini.

Perlu konfirmasi keputusan:
- Invoice numbering `sequence_number` lanjut per customer lintas bulan (bukan reset bulanan). Kalau ternyata tidak,
  kembalikan filter `period_month/period_year` di query MAX di `create_order.php`.
- Logistic List & Movements dengan >1 produk per activity code masih tampil per baris produk, belum dikelompokkan di
  bawah satu header activity code. Pertimbangkan pengelompokan kalau terasa berantakan.
- Audit scroll horizontal menu lain setelah perbaikan `.app`/`.main` (global): Transactions, Logistic, Settings, Dashboard
  dicek di 3 ukuran (HP ≤767px, tablet 768–1023px, jendela setengah layar ~900–1000px).
- Modul Investor: tanda `total_price` pada `price_adjustment` (dipakai rumus gross di §5.14; asumsi positif,
  dikurangkan) — ini sebenarnya milik modul Sales, bukan Investor, tapi memengaruhi rumus laporan Investor; kelas
  `.input-uppercase` jalan tanpa `flexOverlays` untuk 2 modal `invPickerOverlay`/`invPwOverlay` (kalau masih dipakai —
  cek apakah sudah terpakai di kode saat ini). Fitur lain di §6 Investor (Investment Input, Non-Project Expenses,
  Profit Payment & Rolled Capital, delete investor) belum dikonfirmasi ditest terpisah.
- Document Editor → Export as DOCX (OCR): belum pernah dites di LibreOffice/Google Docs (namespace `wps` murni
  ekstensi Microsoft 2010); belum ada pengujian batas performa untuk dokumen sangat panjang/padat teks per halaman.

## 9. Cara lanjut di sesi baru
1. Upload PROJECT_NOTES.md ini + hanya file kode yang relevan (Sales: `transaction_content.php` + `ajax/` terkait;
   Logistic: `logistic_content.php` + `list_logistic_movements.php`/`list_logistics.php`; Investor:
   `investor_content.php` + `ajax/investor_api.php`; Document Tools: `document_tools_content.php`).
2. Jangan upload `NOTES_ARCHIVE.md` kecuali perlu menelusuri alasan keputusan lama (sebut bagian yang dicari).
3. Setelah selesai: tambahkan ≤10 baris ke §8 (atau "Log terbaru"), lalu lebur ke §4–6 kalau sudah dites.