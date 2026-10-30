# PROJECT_NOTES — lisani_aos

> Versi ringkas (27 Okt 2026): hanya kondisi sekarang, aturan bisnis, peta file, dan pekerjaan terbuka.
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
    ├── report_content.php         Report: Finance Report
    ├── investor_content.php       Investor: Investors, Investment, Report, Profit Payment & Rolled Capital
    ├── ajax/                      semua endpoint (§7); _invoice_pdf.php, _require_reverify.php, _order_patterns.php;
    │                              investor_api.php (endpoint tunggal modul Investor)
    ├── json_file/                 departments.json, packaging units, bank_accounts.json, order_patterns/
    ├── sql/ & migration_*.sql
    └── storage/                   input/…, selling/…, recycle/… (AOS_STORAGE_BASE)
```

## 3. Konvensi & cara kerja (WAJIB)
- **Bahasa**: chat = Indonesia. Kode (identifier, komentar, kolom, nama file) = Inggris. **UI = Inggris.**
- **Cara kerja user**: user menguji sendiri lalu melapor. Ubah kode lama sesempit mungkin; serahkan **file lengkap
  siap copy-paste**. Struktur tabel dari user (`DESCRIBE` di phpMyAdmin), **jangan menebak nama kolom**. Kalau butuh
  file yang belum diupload, minta dulu, jangan mengarang isinya.
- Sandbox tidak punya PHP: cek JS dengan Node (`new Function()` per `<script>`), cek kurung/kurawal PHP manual,
  dan cek jumlah tipe `bind_param` vs variabel (pernah jadi bug).
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
  4. Modal didaftarkan ke `flexOverlays` supaya `show()`/`hide()` dan MutationObserver bekerja.
  5. **Layout/overflow**: `.app` HARUS `minmax(0,1fr)` (bukan `1fr`) dan `.main` `min-width:0`, kalau tidak konten
     lebar melebarkan seluruh halaman. Scroll horizontal hanya boleh di `.table-wrapper`. Grid
     `repeat(auto-fit, minmax(Npx,1fr))` pakai `minmax(min(Npx,100%),1fr)`.
  6. `.catch` di `rtReview`/`rtSaveReturn` menelan SEMUA error JS sebagai "Connection error". Kalau pesan itu muncul
     padahal jaringan normal, curigai TypeError di handler `.then`, bukan koneksi/endpoint.
  7. `responsive.css` mengubah SEMUA `table` jadi tampilan kartu di <1024px dan memaksa `td{width:100%}`. Tabel yang
     harus tetap tabel perlu override `display` eksplisit.
  8. Chart.js dari cdnjs bisa diblokir jaringan user. Error di `renderCharts()` harus ditangkap (try/catch) agar
     bagian lain report tetap tampil.
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
- **logistic_movements**: logistic_id, customer_id, customer_name, movement_type (`out`=taken, `in`=returned,
  `price_adjustment`=diskon), stock_source, movement_date, driver_name, police_number, qty_primary_package, price, total_price,
  invoice_id, created_by, created_at, **source_movement_id** (di `in`/`price_adjustment` menunjuk pickup `out` asalnya),
  **batch_id** (identitas satu order/kartu = movement id terkecil saat disimpan).
- **invoices**: customer_id, invoice_number, sequence_number, period_month/year, status (open/paid), total_amount, paid_amount.
  Nomor: `NNN/{inv|ret|adj}/laj-{INISIAL}-{n}/{ROMAWI}/{tahun}`, `[n]` unik lintas ketiga jenis. `NNN` = `sequence_number`
  yang lanjut per customer (001, 002, …) lintas bulan/tahun.
- **invoice_payments**: pembayaran cicilan per invoice. Kolom bank: source_bank, source_account_number, source_account_name,
  destination_bank, destination_account_number, destination_account_name. `proof_path`/`proof_original_name` nullable.
  Method "CREDIT BALANCE" = baris tanpa file (Apply Credit).
- **invoice_refunds**: refund dari kredit; kolom source_bank, source_account_number, source_account_name (nullable,
  opsional). **Tidak punya `invoice_id` maupun `activity_id`** — tidak bisa ditelusuri ke invoice/activity code
  tertentu, lihat §6 Report untuk konsekuensinya ke filter.
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
- `logistics.defective_reference_price` sudah tidak dipakai (drop opsional, lihat §8).
- **investors**: id, name. **investor_deposits**: investor_id, date, currency, amount, rate (nullable, hanya valas),
  final (IDR), notes. **investor_support_expenses**: investor_id, date, category (`return_capital`/`aid`/`other`),
  amount (IDR), notes — mengurangi ICU. **investor_activity_allocations**: investor_id, activity_id, percent (dari
  ICU investor itu; total per investor ≤100% dicek di PHP lintas activity). **investor_activity_settings**: pdp
  (profit distribution percent) **per activity_id**. **investor_profit_payments**: investor_id, date, amount, notes
  (bisa berkali-kali). Tanpa tabel `transaction_activities` (sempat dibuat, di-drop).

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
    (`invoice_id`). Kalau tidak dipilih, fallback ke invoice open terbaru.
12. **Kredit customer**: retur/diskon yang tidak punya invoice open untuk dikurangi (termasuk saat satu-satunya invoice
    sudah PAID) **tidak** membuat invoice minus. Nilainya masuk `customers.credit_balance`, `invoice_id = NULL` (grup
    "(No invoice)"). Kredit **tidak pernah dipakai otomatis**: lewat Refund (`create_refund.php`, mengurangi credit_balance
    & total_paid) atau Apply Credit per invoice open (`apply_customer_credit.php`, mengurangi credit_balance saja;
    total_paid tidak berubah).
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
    pengembalian itu.

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
  tetap cocok; bisa dibatalkan kalau user maunya nomor apa adanya dari slip). Daftar rekening baru = **password pola (b)**
  (`verify_password.php` lalu `save_bank_account.php` dengan `require_reverify=1`; Settings tetap bisa tanpa password).
  **Pencocokan dari nama bank saja** (`lookupAccountByBank()`, `acctBankMatches()`) dipakai kalau nomor/nama akun tidak
  terbaca di slip, hanya nama bank — jalan saat field Bank terisi **dan** Account Number masih kosong (begitu nomor
  terisi, pencocokan nomor di atas yang menang); 0 cocok → `acctRegisterOverlay` lewat `openAcctRegisterByBank()` (bank
  sudah terisi). Fungsi inti `openAcctRegisterCore()` dipakai bersama alur nomor & bank-saja.
- **Adjustable OCR crop box** (viewer dipakai bersama Invoice Payment): setelah drag awal (`endDrag`), box TIDAK
  langsung di-OCR — masuk `enterAdjustMode()`: box jadi persisten dengan 8 handle resize (sudut+tengah sisi,
  `ADJUST_HANDLE_DIRS`), bisa digeser mouse & touch (`resizeAdjustBox`/`renderAdjustUI`, posisi dihitung ulang dari
  `adjustBox` dalam **canvas pixel space**, bukan client space, supaya tahan zoom/scroll — resync di `applyCapZoom()`
  & scroll `capStage`). Dua tombol di hint bar: **Read** (`btnCapReadSelection`, lanjut `runOcrOnRegion`) dan
  **Redraw** (`btnCapRedrawSelection`, buang box & kembali ke mode drag). Mousedown/touchstart di canvas dicek
  `|| adjustBox` supaya tidak mulai drag baru selagi masih adjust. `closeCapViewer()` dan tombol Prev/Next Page
  panggil `exitAdjustMode()` untuk bersih-bersih handle yang menggantung. Shortcut: double-tap di mana saja dalam
  `capStage` = Read, triple-tap = Redraw (`adjustTapCount`/`adjustTapTimer`, listener di `capStage` bukan `document`;
  tap di handle sendiri diabaikan via `e.target.dataset.dir`).

**Settings**: Company Documents (upload/edit/delete→recycle/share WA/download) dan Company Bank Accounts (autocomplete
bank/nama, duplikat per bank, share WA). Edit/Delete/Share pola (b).

**Logistic** (hanya departemen `dates`):
- *Logistic List*: accordion per activity code; Edit/Delete (pola b); Add Document; baris Remaining (fly window ledger
  normal); **Defective Stock** → fly window "Return History" dengan tab Taken Out (`out`+defective) dan Returned In
  (`in`+defective), list scroll max-height 260px. Form Repair to Normal Stock hanya tampil bila `defective_qty>0`, qty
  default seluruh sisa. Import Document (Shipper/Custom/Consignee) collapsible.
  - Baris **"Actual Remaining Qty on Storage"** (menggantikan label "Total All Stock" yang lama, selalu muncul):
    jawab "barang fisik yang masih ada di gudang sekarang, apapun statusnya" = `total_all_stock` (RPQ+defective_qty)
    + Σ(qty_booked − qty_released) booking **aktif** produk itu (gross, booking yang belum dirilis) — karena
    `total_all_stock` sendiri cuma stok yang belum direservasi booking apapun. Field lama `total_all_stock` tidak
    diubah/dihapus, masih dipakai tempat lain dengan makna "stok bebas dari reservasi". Invariant:
    `actual_remaining_qty_on_storage + taken_net (booking) = primary_qty`, kapan pun di siklus booking→release→
    retur→edit.
  - Baris **"Booking"** (setelah "Actual Remaining Qty on Storage", cuma muncul kalau produk itu memang sedang ada
    booking aktif): Total/Taken/Remaining dari qty yang masih berstatus booking `active` untuk produk itu (query
    agregat `booking_items` JOIN `bookings` WHERE `status='active'` GROUP BY `logistic_id` di `list_logistics.php`,
    dengan guard `SHOW TABLES` biar DB yang belum jalanin `migration_bookings.sql` tidak error; "Taken" di sini
    net, bukan gross, lihat §5.16). Booking `completed`/`cancelled` tidak ikut kehitung.
- *Movements*: per activity code → tahun → bulan → hari; total Taken/Returned/Discount/Actual di tiap level. Card
  Validation per activity code (3 badge): (1) Actual Taken gabungan vs `total_taken_qty`; (2) Actual Taken bucket normal
  vs `primary_qty − remaining_primary_qty`; (3) rekonsiliasi per customer vs tab Customers. Detail disembunyikan bila Valid.
- *Create New Logistic*: pilih activity code (`<select>`, selalu aktif; opsi menampilkan produk yang sudah ada). Isi satu
  atau lebih blok produk (tombol + Add Product), tiap blok punya qty primary/secondary dua arah. Incoming Date & dokumen
  dipakai bersama semua produk dalam satu kali simpan. Satu Save → `create_logistic.php` menyimpan semua baris dalam satu
  transaction (all-or-nothing). Nama produk wajib unik per activity code.

**Sales Transaction** (4 tab):
- *New Order*: tempel pesan WA → `parse_order_message.php` (alias produk via `save_order_alias.php`, pola di
  `json_file/order_patterns/`) → review → overlay stok normal/defective bila ada produk dengan `defective_qty>0` →
  Confirm → `create_order.php`. Order lain di tanggal sama → pilihan New Order / Update Existing. Checkbox
  "Open as a new invoice" (lihat §5.11).
- *Booking*: DP untuk qty produk tertentu, stok direservasi langsung tapi belum resmi "keluar" (lihat §5.16). UI
  manual — pilih produk dari dropdown + qty (bukan paste pesan WA seperti New Order), reuse `loadPriceableProducts()`
  dari Itemized Pricing. Harga dari `customer_item_prices` (fallback overlay Set Price, sama seperti New Order).
  Field `stock_source` backend sudah mendukung defective, tapi UI Booking saat ini cuma expose normal stock (belum
  ada toggle normal/defective). Selalu bikin invoice baru sendiri (§5.16), **tidak** reuse invoice open customer.
  DP/pembayaran booking sengaja tidak ditangani endpoint khusus — invoice hasil booking adalah row `invoices` biasa,
  jadi otomatis bisa dibayar lewat tombol + Add Payment yang sudah ada di tab Customers (murni reuse, bisa dicicil).
  `ajax/create_booking.php`, pola dry_run sama seperti `create_order.php`.
- *Customers*: kartu customer (Total Ordered/Returned/Discounts/Actual, Total Paid, Total Balance = Total Actual − Total
  Paid; bisa negatif kalau overpaid, kelebihannya di Credit Balance), riwayat per invoice → kartu per batch. Badge RETURN
  (+NORMAL/DEFECTIVE) dan DISCOUNT (nilai bertanda minus), "from pickup on …". Tombol + Add Payment hanya untuk invoice
  `open`. Apply Credit hanya untuk invoice open. Refund ada di tab ini.
  - **Active Bookings**: section terpisah di bawah Refund History, hanya booking status `active` — tiap booking
    tampil sebagai kartu, "Taken"/"Remaining" ditampilkan **net** (lihat §5.16) kalau beda dari gross. Tombol:
    **+ Add Payment** (reuse, lihat atas; notes penomoran First/Second/... pakai `invoice_payment_count` dari
    `list_customer_bookings.php`, bukan `.length` array payments yang tidak dikirim di kartu ini), **Release**
    (muncul kalau masih ada sisa reservasi — fly window qty per produk, default = sisa reservasi, bisa diedit untuk
    rilis sebagian, + tanggal + driver/police opsional → dry run → Confirm → `ajax/release_booking.php`), **Edit**
    dan **Cancel** (muncul selama masih ada sisa qty belum dirilis — lihat detail endpoint masing-masing di §7 dan
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
  sama seperti di Input Transaction / Disbursement (lihat bagian itu untuk detail).
- *Returns*: pesan WA retur → tiap produk dialokasikan FIFO otomatis ke pickup (bisa dikoreksi, `+ Split`). Tiap split
  punya disposition Good / Defective / Price Adjustment. Review (`dry_run`) → password (pola b) → simpan berurutan
  `create_return.php` lalu `create_price_adjustment.php` (dua transaksi, tidak atomik; kegagalan sebagian dilaporkan).

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

**Report**: `report_content.php` — hanya satu-satunya: Finance Report — ringkasan saldo per rekening, Chart.js
(inflow/outflow per bulan, saldo kumulatif, saldo per rekening), ledger gabungan + Export CSV, snapshot piutang/credit.
Data dari `ajax/get_finance_report.php`: outflow (`transactions` category=disbursement), inflow (`invoice_payments`),
outflow refund (`invoice_refunds`), dicocokkan ke `bank_accounts.json` via bank_name+account_number (bukan FK). Saldo
mulai 0 (keputusan user). Refresh lewat event `aos:section-shown`. Lebar: Ledger `min-width:760px`, tabel piutang 420px,
`.table-wrapper` `overflow-x:auto` di semua lebar. Ledger punya kolom **Category** (disbursement = nama kategori dari
`disbursement_categories`, invoice payment tetap `SALES PAYMENT`, refund = null) + Export CSV. Nominal ledger: inflow
hijau, outflow merah, negatif pakai kurung `(Rp …)` bukan minus (helper `fmtIDRParen`, hanya di ledger; kartu ringkasan
tetap `fmtIDR`; CSV tetap angka mentah + kolom Type). Belum ada: edit kategori transaksi yang sudah tersimpan
(list/edit/delete transaksi tersimpan memang belum dibangun).
- **Urutan ledger**: tanggal terlama → terbaru (ASC), baik dari tiap query SQL maupun `usort` final di
  `get_finance_report.php` — running balance di JS tinggal akumulasi apa adanya tanpa re-sort. Tabel ledger punya
  `<tfoot>` baris **Total** (kolom Amount = net total bertanda, kolom Running Balance = saldo akhir; sama angkanya
  karena saldo selalu mulai dari 0).
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
  Fabric sendiri (`toJSON()`/`loadFromJSON()` saat `switchToPage()`).
  - **Block**: "Block: Top Text"/"Block: Name" — drag kotak di canvas langsung jadi `fabric.Image` draggable +
    lubang bekas area dipatch warna sampling piksel tetangga. Drag gagal (<4px) tidak mematikan draw-mode;
    cursor `crosshair` selama armed.
  - **Guide Box**: rect oranye resizable, dropdown preset ukuran dalam **inci** (`json_file/guide_box_presets.json`,
    self-seed via `ajax/list_guide_box_presets.php`, default 1.46"×2.18" dan 1.46"×3.05"). Checkbox "Auto-shift
    Name" menggeser blok Name sebesar delta posisi guide box saat guide box digeser/di-resize. Konversi inci→px
    pakai `DOC_DPI = 150` (JS), **harus tetap sinkron** dengan `$ASSUMED_DPI = 150` di `save_edited_document.php`.
  - **Insert Signature**: modal `#docSigPickerOverlay` (reuse `ajax/list_signatures.php` + `download_signature.php`),
    posisi mengikuti guide box pakai `getScaledWidth()`/`getScaledHeight()`.
  - **Insert Letterhead**: ditempel full-width pinned ke atas/bawah halaman (`centeredScaling:true`). Checkbox
    "Apply to all pages" (default checked): halaman lain ditempel lewat `fabric.StaticCanvas` offscreen
    (`addLetterheadToPageData()`), rumus placement satu sumber via `letterheadPlacementProps()`. Edit kop ikut
    sync ke semua halaman via tag custom Fabric `letterheadSyncId` + `letterheadPart` dan listener
    `object:modified` → `syncLetterheadTransform(obj)`. Undo letterhead: snapshot diambil di `mouse:down` begitu
    user mulai pegang layer letterhead.
  - **Zoom**: `applyZoom()`/`fitZoomToContainer()` pakai Fabric native `setZoom()`+`setDimensions()`. Export/save
    PDF selalu render dari `fabric.StaticCanvas` terpisah di resolusi asli (`renderPageToDataURL()`), tidak
    terpengaruh zoom layar. **Default tampilan = Fit to Width** (`fit = containerWidth / page.width`, scroll di
    `.doc-editor-canvas-wrap`). Tidak ada fitur Fullscreen (dihapus atas permintaan user).
  - **Photo Correction** (grup ribbon paling kiri, sejajar Blocks/Guide Lines/Convert to Text/Actions/Zoom & View):
    2 kontrol — **Deskew** (`startDeskew()`/`applyDeskew()`/`cancelDeskew()`: 4 handle `fabric.Circle` + `fabric.Polygon`
    penghubung diseret user ke tiap pojok kertas, grup kontekstual `#docDeskewAdjustGroup` Apply/Cancel; homography DLT
    buatan sendiri `computeHomography()`/`solveLinearSystem()`/`applyHomographyPoint()` (solve 8x8 Gaussian elimination
    + partial pivot) lalu inverse-warp nearest-neighbor per piksel `warpQuadToRect()` ke `page.workingCanvas`; ukuran
    halaman tidak berubah jadi object lain di halaman — block/ttd/kop/dst — tidak perlu digeser, cuma background-nya
    diganti via `refreshPageBackgroundImage()`) dan **Remove White Background + Ink Contrast** (2 slider
    `#docBgThreshold`/`#docInkContrast` + tombol -/+ per slider, algoritma PERSIS SAMA seperti Signature Library
    tapi background jadi **putih solid**, bukan transparan, karena `page.workingCanvas` = seluruh halaman, tidak ada
    apa pun di belakangnya; non-destructive — `page.bgSourceCanvas` menyimpan bitmap SEBELUM koreksi disentuh,
    tiap slider digeser dihitung ULANG dari situ; `page.bgThreshold`/`page.bgInkContrast` per halaman untuk
    sinkronisasi tampilan slider saat `switchToPage()`; default 0/0 = no-op). Fungsi ink contrast dinamai
    `stepDocInkContrast()` (beda dari `stepInkContrast()` milik Signature Library — keduanya hidup di satu IIFE yang
    sama, lihat §3 WAJIB soal IIFE per file). `docCropPageBtn`/"Crop Page" dan `docCleanBgToggle` (checkbox Clean
    Background lama) sudah **dihapus** — redundan dengan Deskew (warp penuh ke `page.width x page.height` sudah
    menghasilkan efek crop yang sama). Saling eksklusif dengan Block/Exclude Zone/pending block lain (tiap `start*()`
    saling cancel); Undo dan pindah halaman ikut membatalkan Deskew/pending block yang masih menggantung. Scope per
    halaman saja, tidak ada "Apply to all pages" (beda dari Letterhead/Exclude Zone, posisi kertas tiap foto beda-beda).
    Deteksi tepi otomatis (OpenCV.js) belum ada — murni 4 titik manual untuk saat ini.
  - **Guide Lines** (grup ribbon terpisah dari Guide Box oranye di atas): `docAddHGuideBtn`/`docAddVGuideBtn`
    menggambar garis (`addGuideLine()`) merah solid `#ff0000` strokeWidth 2 (beda elemen dari Guide Box/preview
    Block Text/Exclude Zone, warnanya sendiri-sendiri). Tombol **Remove Guide Lines** (`docRemoveGuideLinesBtn`,
    btn-danger) menghapus SEMUA object `isGuideLine` di halaman yang sedang dibuka sekaligus (beda dari Delete
    Selected yang cuma 1 object, tidak menyentuh Guide Box/Exclude Zone/block/ttd/kop lain), ikut
    `pushDocUndoSnapshot()` jadi bisa di-Undo.
  - **Toolbar**: ribbon ala Microsoft Word — tiap grup (Photo Correction, Blocks, Guide Lines, Actions, Zoom & View,
    **Convert to Text**) satu `.doc-ribbon-trigger` (ikon + nama + chevron) membuka `.doc-ribbon-panel` dropdown berisi
    tombol-tombol aslinya. `wireDocRibbonMenus()` handle show/hide + outside-click + Escape, 1 panel terbuka
    sekaligus; grup `data-ribbon-group="zoom"` **dan** tombol manapun berclass `.doc-ribbon-keepopen` (4 tombol
    -/+ Remove White Background/Ink Contrast) dikecualikan dari auto-close-on-button-click — Deskew di grup yang
    sama TIDAK dikasih class ini, tetap menutup ribbon sekali klik. Panel `.doc-ribbon-panel` `position:fixed`,
    koordinat `top`/`left` dihitung JS (`positionRibbonPanel()`) dari `getBoundingClientRect()` trigger SETIAP kali
    dibuka, diklem ke `window.innerWidth`/`innerHeight` (ketabrak kanan → nempel kanan; tidak muat di bawah → buka
    ke atas trigger). `.doc-editor-toolbar` satu baris + scroll horizontal (`flex-wrap:nowrap`+`overflow-x:auto`,
    `.doc-ribbon-group` `flex-shrink:0`); listener `scroll` pada toolbar langsung `closeAll()` panel (panel fixed
    tidak ikut bergeser saat toolbar di-scroll). Grup kontekstual (`#docBlockAdjustGroup` - Apply/Cancel Block, warna
    amber via class `.doc-ribbon-group-contextual`) bentuknya sama seperti grup biasa.
  - **Exclude Zone** (grup ribbon **"Convert to Text"**): tombol **Add Exclude Zone** — klik lalu drag di canvas
    (pola sama seperti Block Text, `docDrawMode='excludeZone'`, preview merah dashed) untuk menggambar area
    sendiri dari awal (bukan kotak default), lepas klik langsung jadi object final (selectable/resizable) + sync
    ke semua halaman kalau "Apply to all pages" (checkbox, default checked, pola sama Letterhead —
    `excludeSyncId`, `syncExcludeZoneTransform()`). Area exclude zone diputihkan sebelum di-print/OCR, dikecualikan
    dari flatten/Save PDF biasa (gambar tidak ikut tercetak). Tombol **"Done"** (`docZoneDoneGroup`, hijau, 1 klik
    langsung — bukan dropdown) muncul setelah Add Exclude Zone/Insert Letterhead untuk `discardActiveObject()`
    (workaround bug "klik kosong = hasil kerja pindah ke belakang" yang akar masalahnya belum ditemukan — jangan
    hapus tombol Done tanpa tanya user dulu).
  - **Export as DOCX (OCR)** (`docOcrBtn`, satu panel dengan Exclude Zone): OCR Tesseract.js (`'eng+ind'`, lazy-load
    sama seperti Input Transaction/Disbursement) tiap halaman → generate `.docx` murni di browser (OOXML manual +
    JSZip, tanpa PHPWord/Composer di server) → didownload langsung, tidak lewat server. Area Exclude Zone
    diputihkan sebelum OCR (teks di dalamnya tidak ikut terbaca) TAPI tetap di-crop & di-embed sebagai **floating
    image** di posisi aslinya (`pxToEmu()` pakai `DOC_DPI=150`, bukan 96 — sempat jadi bug ukuran gambar
    membesar, sudah fix & ditest OK). Teks hasil OCR direkonstruksi **per baris** (`Paragraph.lines[]`, bukan
    per-paragraf — paragraf Tesseract sering menggabung banyak baris jadi satu bbox raksasa di dokumen rapat) jadi
    **paragraf alur teks biasa** (`<w:p>`, BUKAN floating textbox — sempat dicoba floating per-baris supaya presisi
    x/y, tapi user minta mudah diedit langsung klik-ketik, jadi dibalik ke alur biasa), dengan `w:jc` (alignment,
    heuristik dua-syarat: menjorok jauh dari margin umum halaman DAN margin kiri≈kanan baris itu sendiri),
    `w:b`/`w:bCs` (bold, mayoritas word di baris), `w:sz`/`w:szCs` (font size dari tinggi baris, clamp 6–72pt).
    Fallback tanpa bbox (`data.text` dipecah baris kosong) tetap ada. Progress ditampilkan di modal
    `docConvertProgressOverlay` (progress bar + teks status per tahap, auto-tertutup 700ms setelah selesai).
    Setelah download sukses, halaman di-reset bersih (pola sama seperti setelah Save PDF) — siap upload dokumen
    baru; `docEditorMessage` (teks sukses) tetap kebaca.
    - **Belum pernah dites di LibreOffice/Google Docs** — namespace `wps` (dipakai internal OOXML, walau textbox
      sudah dibalik ke paragraf biasa, exclude-zone image masih pakai anchor `wp`) murni ekstensi Microsoft 2010,
      ada risiko render beda di software lain.
    - Semua halaman diproses berurutan (bukan paralel). Dokumen padat teks bisa menghasilkan banyak paragraf;
      belum ada pengujian batas performa untuk dokumen sangat panjang.
  - **Clear All** (grup ribbon Actions) jalan langsung tanpa password/modal konfirmasi apa pun (teardown
    `docFabricCanvas.dispose()` + reset state tidak berubah, cuma pintu masuknya) — **satu-satunya** aksi destruktif
    di Document Editor tanpa konfirmasi (Delete Selected & aksi lain tetap pakai pola password biasa); keputusan
    sadar atas permintaan eksplisit user, dicatat di sini kalau nanti dipertanyakan lagi.
  - **Save** (PDF, bukan DOCX): semua halaman di-flatten jadi PNG (guide box & exclude zone dibuang dari export),
    dikirim sekaligus (`pages[]`) + `signature_ids[]` + `letterhead_ids[]` ke `ajax/save_edited_document.php`,
    digabung jadi **1 file PDF** via **FPDF** (reuse `optic_pos/fpdf/fpdf.php`). Tabel `edited_documents`:
    `signature_ids`/`letterhead_ids` (VARCHAR CSV, tanpa FK), `page_count`, `file_ext` default `'pdf'`. DOCX
    scan-to-editable sudah ada lewat alur OCR di atas; export langsung dari editor tetap PDF only.
  - **Bug diketahui, diabaikan sementara atas permintaan user**: tombol-tombol toolbar (termasuk grup Zoom)
    terlihat mengecil saat persentase zoom dokumen dinaikkan (bukan zoom browser). Akar masalah belum ditemukan.
    Kalau mau dilanjutkan: minta user screenshot panel Computed DevTools dari tombol toolbar di dua level zoom
    berbeda.

## 7. Peta endpoint (`ajax/`)
- **Auth/util**: `verify_password.php`, `_require_reverify.php`, `_order_patterns.php`, `_invoice_pdf.php`.
- **Activity/Customer/Settings**: `create|update|delete|list|preview_activity_code`, `manage_departments`,
  `create|update|delete|list_customers`, `*_customer_item_price(s)`, `list_priceable_products`,
  `upload|update|delete|download|share|list_documents`, `list|save_bank_account(s)` (`save_bank_account.php` butuh
  `require_reverify=1` saat dipanggil dari auto-isi rekening OCR, Settings tetap tanpa password),
  `create_disbursement` (kini wajib `category_id`), `list|save|update|delete_disbursement_category`.
- **Logistic**: `list_logistics`, `list_logistic_activities` (balikin `has_logistic` + `existing_products[]`),
  `upload_logistic_document`, `manage_packaging_units?kind=primary|secondary`, `list_logistic_movements` (nested
  tahun/bulan/hari + `total_out/in/adjustment`, `*_normal_qty`, `total_taken_qty`, `by_customer`), `manage_defective_stock`.
  - `create_logistic.php` POST `activity_id, incoming_date, products` (JSON array, maks 50) → satu transaction →
    `{ok, data:{ids, count}}`. Tolak `product_name` dobel dalam satu activity code.
  - `update_logistic.php` validasi `product_name` (unik per activity code, kecuali baris itu sendiri).
  - `delete_logistic.php`: dokumen & folder `import_documents/` hanya dihapus kalau produk yang dihapus adalah produk
    terakhir di activity code itu.
- **Sales**: `parse_order_message`, `save_order_alias`, `check_existing_orders`, `create_order`, `update_order`,
  `list_customer_orders` (per invoice movements + `batch_id`, `source_movement_id`, `discount_value`, payments[] dengan
  fallback kolom lama bila migrasi bank belum jalan), `create_invoice_payment`, `view_invoice_payment_proof`,
  `apply_customer_credit`, `create_refund`, `list_bank_accounts`, `get_finance_report`.
  - `list_return_price_options.php` GET `customer_id, logistic_id` → `{ok,data:{available,total_taken,total_returned,
    movements:[{movement_id,movement_date,qty,price,remaining,remaining_adjustable,disabled,label}]}}`.
    ⚠ Pernah tertimpa isi `list_logistic_movements.php` (gejala: "Loading pickup history…" tak selesai). Kalau terulang,
    cek file ini dulu.
  - `create_return.php` POST `customer_id, return_date, driver_name, police_number, dry_run, invoice_id?,
    items:[{logistic_id, allocations:[{source_movement_id, qty, price, restock_bucket}]}]` → `{ok, ret:{invoice, items,
    grand_total, movement_ids}}`. Code `not_returnable` bila melebihi remaining. `invoice: null` = kredit.
  - `create_price_adjustment.php` POST `customer_id, adjustment_date, driver_name, police_number, dry_run, invoice_id?,
    items:[{logistic_id, allocations:[{source_movement_id, qty, old_price, new_price}]}]` → `{ok, adj:{…, grand_discount,
    movement_ids}}`. Code `not_adjustable`.
  - `manage_defective_stock.php`: GET `action=history` (Taken Out) & `action=return_history` (Returned In) + `logistic_id`
    → `{data:{product_name, unit_label, defective_qty, defective_taken_qty, history:[…]}}`. POST `action=repair`
    `logistic_id, qty` (≤ defective_qty).
  - **Booking** (§5.16 & §6 Sales Transaction untuk detail UI): `create_booking.php` (dry_run, selalu invoice baru),
    `list_customer_bookings.php` (GET customer_id → booking `active` + `qty_released_net`/`qty_returned`,
    `invoice_payment_count`), `release_booking.php` (dry_run, POST qty per booking_item → movement `out` + naikkan
    `qty_released` & `total_taken_qty`/`customers.total_inflow`, tulis `booking_item_releases`), `edit_booking.php`
    (dry_run, POST `items` campur `{booking_item_id,qty}` existing dan `{logistic_id,qty,stock_source}` baru +
    `new_prices` optional — lihat header comment file untuk kontrak lengkap), `cancel_booking.php` (dry_run, batalkan
    semua sisa belum dirilis sekaligus).
- **Investor**: `investor_api.php`, satu endpoint untuk semua aksi (lihat §6 Investor untuk detail UI tiap action).
  Action relevan: `save_deposit`/`update_deposit`/`delete_deposit`, `save_support_expense`/`delete_support_expense`,
  `save_fund_shares` (batch alloc per activity, lihat §5.13 & §6), `save_pdp`, `save_profit_payment`,
  `save_investor`/`rename_investor`/`delete_investor`. `inv_compute()` membangun `$activityOut` (dipakai tabel
  Project Expenses & allocation) — query `$expensesByActivity` HARUS dideklarasikan sebelum loop yang memakainya
  (pernah jadi bug diam-diam).

## 8. Pekerjaan terbuka

Selesai & sudah dites (30 Okt 2026) — Fitur Booking/DP lengkap: tab Booking (create), Active Bookings di tab
Customers (Add Payment reuse, Release, Edit termasuk tambah produk baru, Cancel), kolom "Booking" & "Actual
Remaining Qty on Storage" di Logistic List, booking selalu invoice baru, "Taken" net vs gross (`booking_item_releases`).
Detail lengkap sudah dilebur ke §4 Model data, §5.16, §6 Sales Transaction & Logistic, §7 endpoint map.

Belum dibangun (disengaja, bukan bug):
- Fitur pembayaran bersama (disebut user saat desain Create New Logistic). Baru placeholder di form.
- Filter/pagination Movements; retention `storage/recycle/`; `customers.profit`; edit/hapus alias & order; aturan stok minus;
  uppercase server di `manage_packaging_units.php`.
- Data lama order yang sudah terpecah sebelum `batch_id` tetap terpisah. Skrip migrasi belum dibuat.
- Opsi: `DROP COLUMN logistics.defective_reference_price` (ireversibel, belum dijalankan).
- Opsi: host Chart.js lokal di server, kalau jaringan user terus memblokir cdnjs.

Selesai & sudah dites (27 Okt 2026) — Document Editor, Tahap 14: Crop Page/Clean Background dihapus & diganti
grup ribbon Photo Correction (Deskew + Remove White Background/Ink Contrast), fix 3 UX ribbon (tombol -/+ tidak
menutup ribbon, panel dropdown position:fixed + clamp viewport, toolbar satu baris scroll horizontal), Guide
Lines jadi merah solid + tombol Remove Guide Lines, Clear All tanpa password. Detail lengkap sudah dilebur ke
§6 Document Tools → Document Editor (bullet Photo Correction, Guide Lines, Toolbar, Clear All).

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