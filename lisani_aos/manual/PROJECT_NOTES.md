# PROJECT_NOTES — lisani_aos

> Versi ringkas (5 Okt 2026): hanya kondisi sekarang, aturan bisnis, peta file, dan pekerjaan terbuka.
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
    ├── document_tools_content.php Document Tools: Signature Library (baru; lihat §8)
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
- **Menu**: sidebar = Dashboard, Transactions, Logistic, Document Tools, Report, Investor — semua pakai ikon Tabler
  (Logistic sempat tanpa ikon, diperbaiki 16 Okt 2026 jadi `ti-truck-delivery`; Document Tools baru, `ti-signature`,
  lihat §8), berlaku di sidebar desktop & bottom-nav mobile.
  Settings & Exit di dropdown avatar. Ganti section lewat `[data-target]` (footer.php → `setActive()`), yang juga
  `dispatchEvent('aos:section-shown', {detail:{target}})`. Section yang fetch-nya cuma sekali bisa listen event ini
  untuk refresh live (pola generik, tidak perlu ubah footer.php).
- **Branding** (16 Okt 2026): nama resmi **"LISANI AOS"** (huruf besar semua), AOS = *Lisani Administration Operating
  System*. Topbar (`index.php` → `.header-title`/`.header-sub`) pakai nama + subtitle lengkap + logo
  `assets/img/lisani_logo.png` (`.header-logo`). Sidebar (`partials/sidebar.php` → `.brand`) pakai logo yang sama
  (ganti ikon kacamata lama di `.brand-mark`) + "LISANI AOS" saja, **tanpa** subtitle (disengaja, beda dari topbar).
  CSS untuk `.header-brand`/`.header-logo`/`.brand-logo` ditulis inline di `index.php`/`sidebar.php` sendiri (bukan di
  `theme.css`) karena saat ditulis belum sempat ditambahkan ke situ — kalau nanti dirapikan ke `theme.css`, hapus
  duplikatnya dari kedua file itu.
- **Sidebar show/hide toggle** (16 Okt 2026): tombol `#sidebarToggleBtn` di topbar (`.header-brand`, sebelah logo),
  **desktop-only (≥1024px)** — `.app` punya 3 mode lebar sidebar dari `responsive.css` (desktop 240px / tablet rail
  64px / mobile `display:none` + `.bottom-nav`), jadi toggle ini cuma override mode desktop lewat class
  `.app.sidebar-collapsed` (grid-template-columns 0 + `.sidebar{width:0}`, transisi `.18s`), disembunyikan total di
  <1024px lewat media query supaya tidak tabrakan sama mode tablet/mobile. Status diingat di `localStorage`
  (`aos_sidebar_collapsed`), ikon tombol ganti `ti-layout-sidebar-left-collapse` ⇄ `-expand`. CSS & JS-nya di
  `index.php` (bukan `theme.css`/`footer.php`), sama alasannya dengan poin Branding di atas.

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
  opsional). **Tidak punya `invoice_id` maupun `activity_id`** (dikonfirmasi `DESCRIBE`, 12 Okt 2026) — tidak bisa
  ditelusuri ke invoice/activity code tertentu, lihat §6 Report untuk konsekuensinya ke filter.
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
  (bisa berkali-kali). Tanpa tabel `transaction_activities` (sempat dibuat, di-drop — lihat §8 kalau perlu alasan).

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
  penuh → sama persis (spasi/strip diabaikan); nomor tertutup → cocok 4 digit terakhir (OCR tidak pernah membaca `*`
  dengan benar, jadi **karakter non-digit apa pun** selain spasi/titik/strip dianggap tertutup; juga tertutup bila ≤4
  karakter; tanpa 4 digit di ujung → diabaikan). Hasil: 1 cocok → langsung isi; >1 cocok (beda bank) → fly window
  `acctPickOverlay` pilih manual; 0 cocok → fly window `acctRegisterOverlay` (daftar rekening baru; bank/nama/currency
  diisi dari form bila ada; nomor penuh wajib diketik bila hanya 4 digit). Kedua overlay di `flexOverlays`, z-index
  2200 (di atas viewer & field picker). Saat cocok/terdaftar, kolom nomor **diganti dengan nomor penuh milik JSON**
  (supaya Finance Report yang join lewat bank_name+account_number tetap cocok; bisa dibatalkan kalau user maunya nomor
  apa adanya dari slip). Daftar rekening baru = **password pola (b)** (`verify_password.php` lalu
  `save_bank_account.php` dengan `require_reverify=1`; Settings tetap bisa tanpa password). **Pencocokan dari nama bank
  saja** (`lookupAccountByBank()`, `acctBankMatches()`) dipakai kalau nomor/nama akun tidak terbaca di slip, hanya nama
  bank — jalan saat field Bank terisi **dan** Account Number masih kosong (begitu nomor terisi, pencocokan nomor di
  atas yang menang); 0 cocok → `acctRegisterOverlay` lewat `openAcctRegisterByBank()` (bank sudah terisi). Fungsi inti
  `openAcctRegisterCore()` dipakai bersama alur nomor & bank-saja.

**Settings**: Company Documents (upload/edit/delete→recycle/share WA/download) dan Company Bank Accounts (autocomplete
bank/nama, duplikat per bank, share WA). Edit/Delete/Share pola (b).

**Logistic** (hanya departemen `dates`):
- *Logistic List*: accordion per activity code; Edit/Delete (pola b); Add Document; baris Remaining (fly window ledger
  normal); **Defective Stock** → fly window "Return History" dengan tab Taken Out (`out`+defective) dan Returned In
  (`in`+defective), list scroll max-height 260px. Form Repair to Normal Stock hanya tampil bila `defective_qty>0`, qty
  default seluruh sisa. Import Document (Shipper/Custom/Consignee) collapsible.
- *Movements*: per activity code → tahun → bulan → hari; total Taken/Returned/Discount/Actual di tiap level. Card
  Validation per activity code (3 badge): (1) Actual Taken gabungan vs `total_taken_qty`; (2) Actual Taken bucket normal
  vs `primary_qty − remaining_primary_qty`; (3) rekonsiliasi per customer vs tab Customers. Detail disembunyikan bila Valid.
- *Create New Logistic*: pilih activity code (`<select>`, selalu aktif; opsi menampilkan produk yang sudah ada). Isi satu
  atau lebih blok produk (tombol + Add Product), tiap blok punya qty primary/secondary dua arah. Incoming Date & dokumen
  dipakai bersama semua produk dalam satu kali simpan. Satu Save → `create_logistic.php` menyimpan semua baris dalam satu
  transaction (all-or-nothing). Nama produk wajib unik per activity code.

**Sales Transaction** (3 tab):
- *New Order*: tempel pesan WA → `parse_order_message.php` (alias produk via `save_order_alias.php`, pola di
  `json_file/order_patterns/`) → review → overlay stok normal/defective bila ada produk dengan `defective_qty>0` →
  Confirm → `create_order.php`. Order lain di tanggal sama → pilihan New Order / Update Existing. Checkbox
  "Open as a new invoice" (lihat §5.11).
- *Customers*: kartu customer (Total Ordered/Returned/Discounts/Actual, Total Paid, Total Balance = Total Actual − Total
  Paid; bisa negatif kalau overpaid, kelebihannya di Credit Balance), riwayat per invoice → kartu per batch. Badge RETURN
  (+NORMAL/DEFECTIVE) dan DISCOUNT (nilai bertanda minus), "from pickup on …". Tombol + Add Payment hanya untuk invoice
  `open`. Apply Credit hanya untuk invoice open. Refund ada di tab ini.
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

**Report**: `report_content.php` — **tidak ada tab lagi** (dihapus 12 Okt 2026; Investor Report yang dulu cuma
placeholder kosong ikut dihapus total, bukan disembunyikan). Sekarang isinya langsung satu-satunya: Finance Report —
ringkasan saldo per rekening, Chart.js (inflow/outflow per bulan, saldo kumulatif, saldo per rekening), ledger
gabungan + Export CSV, snapshot piutang/credit. Data dari `ajax/get_finance_report.php`: outflow (`transactions`
category=disbursement), inflow (`invoice_payments`), outflow refund (`invoice_refunds`), dicocokkan ke
`bank_accounts.json` via bank_name+account_number (bukan FK). Saldo mulai 0 (keputusan user). Refresh lewat event
`aos:section-shown` (karena tidak ada lagi tab-click untuk trigger load pertama). Lebar: Ledger `min-width:640px` →
760px (setelah kolom Category ditambahkan), tabel piutang 420px, `.table-wrapper` `overflow-x:auto` di semua lebar.
Ledger punya kolom **Category** (disbursement = nama kategori dari `disbursement_categories`, **invoice payment
tetap `SALES PAYMENT`**, refund = null) + Export CSV. Nominal ledger: inflow hijau, outflow merah, negatif pakai kurung
`(Rp …)` bukan minus (helper `fmtIDRParen`, hanya di ledger; kartu ringkasan tetap `fmtIDR`; CSV tetap angka mentah +
kolom Type). Belum ada: edit kategori transaksi yang sudah tersimpan (list/edit/delete transaksi tersimpan memang
belum dibangun).
- **Urutan ledger** (12 Okt 2026): tanggal terlama → terbaru (ASC), baik dari tiap query SQL (`ORDER BY ... ASC`) maupun
  `usort` final di `get_finance_report.php` — jadi running balance di JS tinggal akumulasi apa adanya tanpa re-sort.
  Tabel ledger punya `<tfoot>` baris **Total** (kolom Amount = net total bertanda, kolom Running Balance = saldo akhir;
  dua-duanya sama angkanya karena saldo selalu mulai dari 0).
- **Filter Activity Code** (12 Okt 2026): dropdown `#rptActivityFilter`, diisi dari `ajax/list_activity_codes.php`
  (endpoint yang sama dipakai tab Transactions > Activity Code > Preview — reuse, bukan endpoint baru), value = `id`,
  label `{activity_code} — {activity_name}`. Dikirim sebagai `activity_id` ke `get_finance_report.php`. Per sumber:
  - **Disbursement (outflow)**: difilter langsung via `transaction_disbursements.activity_id` (kolom ini memang ada,
    NOT NULL, dikonfirmas lewat `DESCRIBE`).
  - **Invoice payment (inflow)**: difilter via `EXISTS` ke `logistic_movements` (`invoice_id`) JOIN `logistics`
    (`activity_id`). **Tidak dipecah proporsional** — satu invoice bisa berisi produk dari beberapa activity code
    sekaligus; kalau SALAH SATU produknya dari activity code yang difilter, payment itu ikut tampil **utuh** (bukan
    nilai yang dipecah). Keputusan user, 12 Okt 2026.
  - **Refund**: **TIDAK difilter sama sekali, selalu tampil apa adanya**, berapa pun activity code yang dipilih.
    `invoice_refunds` tidak punya kolom `invoice_id` maupun `activity_id` (dikonfirmasi `DESCRIBE`) — refund mengurangi
    `customers.credit_balance` yang akumulasinya lintas invoice/activity code, jadi secara skema memang tidak bisa
    ditelusuri ke satu activity code. Ini keputusan sadar user (12 Okt 2026), **bukan bug** — jangan "perbaiki" tanpa
    nambah kolom baru + migration data lama kalau suatu saat mau diubah.

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
- **Investor**: `investor_api.php`, satu endpoint untuk semua aksi (lihat §6 Investor untuk detail UI tiap action).
  Action relevan: `save_deposit`/`update_deposit`/`delete_deposit`, `save_support_expense`/`delete_support_expense`,
  `save_fund_shares` (batch alloc per activity, lihat §5.13 & §6), `save_pdp`, `save_profit_payment`,
  `save_investor`/`rename_investor`/`delete_investor`. `inv_compute()` membangun `$activityOut` (dipakai tabel
  Project Expenses & allocation) — query `$expensesByActivity` HARUS dideklarasikan sebelum loop yang memakainya
  (pernah jadi bug diam-diam, lihat §8 kalau perlu detail).

## 8. Pekerjaan terbuka

### Log terbaru (belum dites, jangan lebur ke §6 sebelum dikonfirmasi)
- **21 Okt 2026, BUG FATAL & FIX: Zoom slider Signature Editor mengecil terus, tidak bisa
  dibesarkan lagi** (laporan user via 2 screenshot - gambar 1 sebelum zoom diapa-apakan, gambar
  2 setelah digeser langsung mentok sangat kecil & tidak bisa dibesarkan). Akar masalah: seluruh
  script `document_tools_content.php` ada di SATU IIFE yang sama, dan Document Editor (bagian
  Zoom Fabric.js, ditambahkan 18 Okt 2026) mendeklarasikan `var ZOOM_MIN = 0.1; var ZOOM_MAX = 3;
  var ZOOM_STEP = 0.15;` - NAMA YANG SAMA PERSIS dengan konstanta Signature Editor
  (`var ZOOM_MIN = 50, ZOOM_MAX = 300, ZOOM_STEP = 25;`, ditambahkan 21 Okt 2026 revisi slider).
  Karena keduanya `var` di scope fungsi yang sama (bukan per-blok), deklarasi Document Editor
  (lebih bawah di file, jalan belakangan) MENIMPA nilai milik Signature Editor saat script
  dieksekusi - jadi slider Signature Editor (`sigZoomSlider`, range 50-300) ikut ke-clamp oleh
  `Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, pct))` versi Document Editor (0.1-3), persis gejala di
  screenshot. **Fix**: konstanta Document Editor di-rename pakai prefix unik
  (`DOC_ZOOM_MIN`/`DOC_ZOOM_MAX`/`DOC_ZOOM_STEP`, 3 titik pakai: deklarasi + `applyZoom()` +
  tombol Zoom In/Out Document Editor), konstanta Signature Editor (`ZOOM_MIN`/`ZOOM_MAX`/
  `ZOOM_STEP`) TIDAK diubah. Dicek ulang: tidak ada pasangan nama variabel lain yang collide di
  file ini (sudah di-grep khusus `ZOOM_MIN/MAX/STEP`; belum di-audit variabel lain secara
  menyeluruh - kalau bug serupa muncul lagi di konstanta lain, curigai pola yang sama: dua bagian
  beda fitur di file yang sama, satu IIFE, nama variabel sama). **Gotcha baru untuk §3**: file
  `*_content.php` di proyek ini memang satu IIFE per file (bukan per kartu/fitur di dalamnya) -
  kalau menambah kartu/fitur baru dengan konstanta/variabel top-level (`var` di luar function),
  JANGAN pakai nama generik (`ZOOM_MIN`, dsb) yang kemungkinan dipakai bagian lain di file yang
  sama; kasih prefix spesifik fitur (seperti `DOC_`/`sig` yang sudah dipakai untuk id elemen).
  **Belum dites ulang oleh user** - perlu dicoba: slider Signature Editor sekarang bisa digeser
  bebas 50%-300% seperti semula, dan Document Editor's Zoom In/Out/Fit to Page (konstanta yang
  baru di-rename) masih bekerja normal seperti sebelumnya (tidak ada regresi di sana).

- **21 Okt 2026, Zoom slider ditambahkan (revisi kecil atas poin Zoom di log sebelumnya)**: user
  bilang Zoom harus bisa diatur bebas, bukan cuma tombol −/+/Reset step 25%. Ditambah
  `<input type="range" id="sigZoomSlider" min="50" max="300" step="5">` di antara tombol
  −/+ (grup toolbar "Zoom"). `setZoomPercent(pct, syncSlider)` sekarang terima parameter ke-2
  opsional: dipanggil `true`/default (tombol -/+/Reset, `clearEditorCanvas()`) → ikut
  memindahkan slider; dipanggil `false` (dari slider's own `input` listener) → tidak muter
  balik set value slider yang sedang digeser user. Tombol −/+/Reset TETAP ada (sengaja, buat
  nudge halus tanpa drag). Belum dites - perlu dicoba slider digeser langsung ke berbagai
  persentase (bukan cuma lewat tombol), dan posisi klik Crop/Erase tetap akurat di level zoom
  manapun (sama seperti item belum-dites Zoom sebelumnya).

- **21 Okt 2026, 3 revisi tambahan Signature Editor canvas (lanjutan revisi 6-poin di bawah, sama
  file `document_tools_content.php`, belum dites user)**:
  1. **Undo Erase jadi HISTORY STACK** (sebelumnya cuma 1 langkah) - `eraseUndoSnapshot` tunggal
     diganti `eraseUndoStack` (array). Tiap mulai 1 stroke brush (`pointerdown` saat `eraseMode`),
     snapshot `erasedMask` SEBELUM stroke itu di-`push()`; tombol **Undo Erase** sekarang `pop()`
     1 snapshot per klik dan bisa diklik berkali-kali berturut-turut sampai stack kosong (baru
     tombolnya hilang lagi). Dibatasi max 30 langkah (`shift()` kalau kepenuhan, cukup buat
     koreksi normal, hindari numpuk memori tanpa batas). Undo Crop TETAP 1 langkah saja (sengaja,
     beda dari Erase - Crop jarang dipakai berturut-turut seperti brush erase).
  2. **Zoom In/Out untuk canvas editor** (grup toolbar baru "Zoom": tombol -/Reset/+ + label
     persen). Murni ukuran TAMPILAN (`#sigEditorCanvasOuter` jadi viewport `overflow:auto`,
     `.sig-canvas-zoom-wrap` di dalamnya di-resize via CSS `width%` 50%-300%, step 25%) - TIDAK
     mengubah resolusi pixel asli canvas (`editorCanvas.width/height` tetap), jadi tidak
     memengaruhi hasil Crop/Erase/Remove Background maupun data yang disimpan. Koordinat
     pointer (`canvasPointFromEvent()`) otomatis tetap akurat karena masih pakai rasio
     `canvas.width/rect.width` yang sudah ada sebelumnya - TIDAK ada perubahan logic crop/erase.
  3. **Toolbar dirapikan jadi kartu-kartu grup terpisah** (`.sig-editor-toolbar` > beberapa
     `.sig-editor-group`, masing-masing punya label kecil di atas: Photo / Zoom / Crop / Erase
     Part / Remove White Background) - sebelumnya semua tombol (Crop, Erase, Clear, threshold,
     dll) nyampur jadi 1-2 baris panjang tanpa pemisah visual. Class wrapper canvas JUGA diganti
     dari reuse `.sig-editor-canvas-wrap` (dipakai bareng Document Editor's
     `docEditorCanvasWrap`) jadi dedicated `.sig-canvas-outer`/`.sig-canvas-zoom-wrap`, supaya
     background putih + Zoom BARU di sini tidak bocor ke canvas Document Editor (ikut gotcha
     soal reuse nama class di §3 - `.sig-editor-canvas-wrap` lama TETAP ada & tidak diubah,
     hanya dipakai `docEditorCanvasWrap` sekarang).
  - **Belum dites** - perlu dicoba: Undo Erase berkali-kali mengembalikan tiap stroke satu per
    satu dengan urutan benar; Zoom In/Out/Reset mengubah ukuran canvas TAPI posisi klik
    Crop/Erase tetap akurat di level zoom berapa pun (termasuk saat discroll di dalam viewport
    zoom >100%); Document Editor's canvas (kartu terpisah, bukan Signature Editor) TIDAK ikut
    berubah tampilannya (pastikan CSS rename tidak ada efek samping).

- **21 Okt 2026, 6 revisi UI Signature Library (hasil `document_tools_content.php` ini, belum dites user)**:
  1. **Workspace canvas putih + grid garis halus** (ganti checkerboard gelap) - kebanyakan ttd
     warnanya hitam, susah dibedakan dari latar checkerboard semi-transparan sebelumnya. CSS
     `.sig-editor-canvas-wrap` jadi `background-color:#fff` + 2 `linear-gradient` garis 1px
     (`rgba(0,0,0,0.06)`) tiap 20px, bukan indikator transparansi lagi, murni garis bantu visual.
  2. **Crop sekarang editable sebelum Apply**: setelah drag kotak pertama kali, muncul 8 resize
     handle (4 sudut + 4 tengah-tepi, `hitTestCropHandle()`) yang bisa di-drag untuk resize, atau
     drag dari TENGAH kotak untuk geser posisi (`cropHandleHit==='move'`, pakai `cropDragOffset`).
     Cursor berubah sesuai handle yang di-hover (`cursorForHandle()`). Tombol **Undo Crop**
     (`sigCropUndoBtn`, baru muncul setelah 1x Apply Crop) mengembalikan
     `originalPhotoImageData`+`erasedMask`+ukuran canvas ke snapshot SEBELUM crop terakhir
     (`cropUndoSnapshot`, cuma nyimpan 1 langkah terakhir, bukan history berlapis).
  3. **Erase Part sekarang punya tombol Undo Erase** (`sigEraseUndoBtn`): snapshot `erasedMask`
     diambil SEKALI di `pointerdown` (awal 1 stroke brush), bukan tiap dab, supaya 1 klik Undo
     mengembalikan seluruh stroke terakhir (`eraseUndoSnapshot`, juga cuma 1 langkah terakhir).
     Kedua tombol Undo (Crop & Erase) dan snapshot-nya direset ke null oleh `clearEditorCanvas()`
     (dipanggil saat upload foto baru / Clear All / reset form / buka form Edit).
  4. **Date default ke tanggal hari ini** (local time via `Date()`, bukan UTC) - diisi sekali saat
     kartu pertama dimuat (IIFE `setInitialDateDefault()`) dan tiap `resetSignatureForm()` jalan
     (setelah Save sukses / Clear All / Cancel Edit). `openEditSignatureForm()` tetap override
     dengan `sig.signature_date` asli saat Edit, tidak kena default ini.
  5. **Placeholder Origin diperjelas** jadi "e.g. Jakarta, Indonesia" (kota/negara asal perusahaan,
     bukan nama departemen/kantor) - cuma ganti placeholder teks, field & validasi tidak berubah.
  6. **List signature dikelompokkan per Company Name** (header grup collapsible,
     `renderSignatureList()` dirombak: fungsi item lama dipisah jadi `buildSigAccordionItem(sig)`
     dipakai ulang di dalam tiap grup `.doc-group`). Urutan grup & urutan dalam grup MENGIKUTI
     urutan `signaturesCache` apa adanya (hasil `ORDER BY is_main DESC, signature_date DESC, id
     DESC` dari `list_signatures.php`, TIDAK diubah) - grouping di sini murni kosmetik tampilan.
     Grup pertama otomatis terbuka saat render, sisanya tertutup. Grup & accordion item di
     dalamnya independen (buka 1 item TIDAK menutup grup lain), tapi `openSigItem()`/
     `closeSigItem()` sekarang panggil `resyncParentGroupHeight()` supaya `max-height` grup induk
     ikut di-resync saat isi di dalamnya berubah tinggi (sama pola dengan gotcha #2 di §3).
  - **Backend TIDAK diubah** (`list_signatures.php`/`update_signature.php`/`upload_signature.php`
    semuanya identik dengan sebelumnya) - keenam revisi ini murni HTML/CSS/JS di
    `document_tools_content.php`.
  - **Belum dites sama sekali** - perlu dicoba: workspace putih kelihatan jelas buat ttd hitam;
    crop handle resize di 8 titik + geser dari tengah kotak jalan benar (termasuk di layar
    sentuh/HP, bukan cuma mouse); Undo Crop & Undo Erase masing-masing balik 1 langkah dengan
    benar dan tombolnya hilang lagi setelah dipakai; Date terisi otomatis tanggal hari ini saat
    kartu dibuka pertama kali DAN setelah Save/Cancel Edit; grup per Company Name kebuka/ketutup
    independen dan tinggi collapsible tidak kepotong saat item di dalam grup dibuka.

- **21 Okt 2026, Signature Library + Signature Editor digabung jadi satu kartu** (belum dites):
  - Kartu "Signature Editor" terpisah DIHAPUS. Form upload manual lama di kartu "Signature Library"
    JUGA dihapus - sekarang satu-satunya cara menambah signature adalah lewat editor canvas
    (Upload Photo → edit → Save to Library), yang tetap pakai `ajax/upload_signature.php` yang sama.
    List signature (accordion, select-all, share WA) sekarang nempel langsung di bawah form editor
    di kartu yang sama (`#cardSignatures`), bukan di kartu terpisah lagi. Tombol Edit tiap item
    mengisi ulang form editor (bukan canvas) untuk ganti signature_name/signature_date/is_main.
  - **Tab "Draw" dihapus total** (sesuai permintaan user) - editor sekarang cuma Upload Photo.
  - **Fitur baru di canvas**: tombol **Crop** (drag kotak seleksi lalu Apply Crop - benar-benar
    memotong `originalPhotoImageData`+`erasedMask` dan resize `<canvas>`, bukan cuma CSS crop) dan
    **Erase Part** (brush, radius diatur slider "Eraser Size", menandai piksel transparan permanen
    lewat `erasedMask` terpisah dari threshold - supaya geser slider Remove White Background tidak
    menghapus hasil erase). Remove White Background sekarang punya tombol **-5/+5** di samping
    slider-nya (`sigThresholdMinusBtn`/`sigThresholdPlusBtn`), bukan cuma drag.
  - **Storage pindah dari `company_activity/{year}/{month}/{slug}` ke `storage/signature/{company_name_slug}/{origin_slug}/{file}`.**
    Judul Kegiatan/`activity_title` DIHAPUS TOTAL dari Signature (kolom DB-nya didrop), diganti
    2 field manual baru: **Company Name** + **Origin**, keduanya menentukan folder (sama seperti
    activity_title dulu: TIDAK BISA diedit lewat form Edit, hanya diisi saat create). Field ini
    TIDAK terhubung ke `json_file/document_activities.json`/`ajax/list_activities.php` sama sekali
    (itu murni milik Document Editor sekarang, activity_title Document Editor sendiri TIDAK berubah).
  - **Kolom baru `is_main`** (tinyint, default 0) - checkbox "Set as Main signature" di form editor.
    **Boleh lebih dari satu is_main=1 sekaligus** (user atur manual, tidak ada constraint unik/auto-unset).
    `list_signatures.php` sekarang `ORDER BY is_main DESC, signature_date DESC, id DESC` supaya Main
    selalu di atas; list menampilkan badge "MAIN" di nama signature yang is_main=1.
  - Migrasi baru: `sql/migration_signature_library_company_origin.sql` (tambah `company_name`,
    `origin`, `is_main`; DROP `activity_title`) - jalankan SETELAH
    `migration_signature_library_activity.sql`. File lama di `storage/company_activity/.../` TIDAK
    dipindah otomatis oleh migrasi ini (manual kalau mau konsisten).
  - `ajax/delete_signature.php` & `ajax/download_signature.php` TIDAK diubah (keduanya cuma pakai
    `file_path`/`id`/`signature_name`/`file_ext`, generik terhadap struktur folder).
  - **Belum dites user sama sekali** - perlu dicoba: upload baru (folder
    `storage/signature/...` ke-create?), Crop (hasil benar-benar terpotong, bukan cuma visual?),
    Erase Part + geser threshold setelahnya (erase tidak hilang?), Edit (company_name/origin
    read-only tampil benar, is_main toggle jalan?), urutan list (Main di atas), SQL migration
    jalan tanpa error di phpMyAdmin.

- **17 Okt 2026, Pola storage "company_activity" + Signature Editor** (lanjutan Document Tools, belum dites):
  - **Pola storage baru (§13), berlaku ke SELURUH Document Tools ke depan**, bukan cuma Signature Library:
    `storage/company_activity/{year}/{month}/{judul_kegiatan_slug}/{file}` - year/month dari tanggal file
    (bukan tanggal upload), judul kegiatan diinput manual lalu disimpan ke `json_file/document_activities.json`
    supaya bisa dipilih ulang (datalist HTML, bukan dropdown custom). Folder lama
    `storage/document_tools/signatures/` SUDAH TIDAK DIPAKAI untuk upload baru (file lama di situ, kalau
    ada, perlu dipindah manual kalau mau konsisten - belum ada script migrasi otomatis).
  - DB: tabel `signature_library` tambah kolom `activity_title` (migrasi baru:
    `sql/migration_signature_library_activity.sql`, jalankan SETELAH migration_signature_library.sql).
    Judul kegiatan TIDAK BISA diedit lewat form Edit (karena akan butuh pindah file antar folder - belum
    dibangun), hanya bisa diisi saat upload/save baru.
  - Endpoint baru: `ajax/list_activities.php` (baca `json_file/document_activities.json`); ditulis oleh
    `upload_signature.php` (fungsi `remember_activity_title()`, dedup case-insensitive).
  - `ajax/delete_signature.php`: recycle dir sekarang dibangun dinamis dari `dirname(file_path)` (sebelumnya
    hardcode ke path lama) supaya tetap benar untuk struktur folder nested yang baru.
  - **Signature Editor** (kartu baru di `document_tools_content.php`, Opsi 1 dari rencana rangkuman): canvas
    HTML5 dengan 2 mode - Draw (gambar langsung di canvas transparan, tidak perlu hapus background) dan
    Upload Photo (tempel foto ke canvas, lalu slider threshold mengubah piksel nyaris-putih jadi transparan,
    re-derive dari `originalPhotoImageData` tiap slider digeser biar tidak degradasi berulang). "Save to
    Library" export canvas jadi PNG Blob dan POST ke `ajax/upload_signature.php` yang SAMA dengan form
    upload manual (tidak ada endpoint terpisah).
  - Belum ada: threshold algoritma masih sederhana (near-white flat threshold, bukan edge-aware/anti-alias),
    belum ada undo/redo di canvas, belum ada rotate/crop sebelum simpan.

- **7 Okt 2026, Document Tools > Signature Library** (langkah awal rencana "tool ttd" dari
  `rangkuman_tool_ttd_dokumen.md`; baru pustaka ttd, BELUM ada editor canvas/OCR/kop/template):
  - Menu baru di sidebar `document_tools` (ikon `ti-signature`), file `document_tools_content.php`, kloning
    persis pola Settings > Company Documents (satu kartu collapsible, accordion list, modal konfirmasi
    password sendiri `docToolsConfirm*` supaya id tidak bentrok dengan `settingsConfirm*`).
  - DB: tabel baru `signature_library` (kolom sama seperti `company_documents`, hanya beda nama
    `signature_name`/`signature_date`). Migrasi: `sql/migration_signature_library.sql`.
  - Storage: `storage/document_tools/signatures/`; delete pakai pola sama (pindah ke
    `storage/recycle/document_tools/signatures/`, bukan hapus permanen).
  - Endpoint baru (pola b, `_require_reverify.php`): `upload|list|update|delete|download|share_signature(s).php`.
  - Belum ada: aturan retensi/akses khusus untuk gambar ttd (data sensitif) - saat ini sama persis dengan
    Company Documents, PERLU DIKONFIRMASI apakah cukup.
  - Belum dibangun: editor ttd canvas, tempel ttd & kop ke dokumen, edit teks OCR, template form.
  - **16 Okt 2026**: `index.php`/`sidebar.php` sempat diupdate paralel (logo, toggle sidebar, branding LISANI
    AOS, ikon Logistic) tanpa menyertakan perubahan Document Tools di atas — sudah digabung ulang di versi
    terbaru kedua file itu.
  - **Bug ditemukan+fix (16 Okt 2026)**: kartu "Signature Library" tidak bisa dibuka (klik tidak ada efek).
    Sebab: `settings_content.php` (Company Documents) query `document.querySelectorAll('.settings-collapsible')`
    TANPA scope, jadi ikut menangkap card saya (reuse nama class yang sama) → card kena 2 listener yang
    saling tabrakan (punya saya membuka, punya Settings langsung menutup lagi di frame yang sama). Fix:
    rename seluruh class wrapper di `document_tools_content.php` dari `settings-collapsible*` jadi
    `doctools-collapsible*` (HTML+CSS+JS). **Gotcha baru untuk §3**: kalau meniru pola file `*_content.php`
    lain dengan copy class CSS-nya, JANGAN reuse nama class yang dipakai `document.querySelectorAll()`
    TANPA scope di file asal — cek dulu apakah file sumber query class itu secara global atau sudah di-scope
    ke id/parent-nya sendiri. Belum dites ulang oleh user pasca-fix.

- **Adjustable OCR crop box** (`transaction_content.php`, Input Transaction/Disbursement & Invoice Payment, shared
  viewer): setelah drag awal (`endDrag`), box TIDAK langsung di-OCR — masuk `enterAdjustMode()`: box jadi persisten
  dengan 8 handle resize (sudut+tengah sisi, `ADJUST_HANDLE_DIRS`), bisa digeser mouse & touch
  (`resizeAdjustBox`/`renderAdjustUI`, posisi dihitung ulang dari `adjustBox` yang disimpan dalam **canvas pixel
  space**, bukan client space, supaya tahan zoom/scroll — resync di `applyCapZoom()` & scroll `capStage`). Dua tombol
  baru muncul di hint bar: **Read** (`btnCapReadSelection`, lanjut `runOcrOnRegion` seperti biasa) dan **Redraw**
  (`btnCapRedrawSelection`, buang box & kembali ke mode drag). Mousedown/touchstart di canvas sekarang juga dicek
  `|| adjustBox` supaya tidak mulai drag baru selagi masih adjust. `closeCapViewer()` dan tombol Prev/Next Page
  panggil `exitAdjustMode()` untuk bersih-bersih handle yang menggantung.
  - **Shortcut tap**: selain tombol, double-tap di mana saja dalam `capStage` = Read, triple-tap = Redraw
    (`adjustTapCount`/`adjustTapTimer`, pola sama dengan tap-counter arming field, listener di `capStage` bukan
    `document` supaya tombol viewer bar lain tidak ikut kehitung; tap di handle sendiri diabaikan via
    `e.target.dataset.dir`).
  - **Bug yang sudah diperbaiki**: `renderAdjustUI()` sempat dipanggil SEBELUM `capAdjustControls` (tombol
    Read/Redraw) ditampilkan, padahal tombol itu menambah tinggi hint bar dan mendorong `capStage`/canvas turun —
    akibatnya box tertinggal di posisi lama. Fix: tampilkan `capAdjustControls` dulu, baru `renderAdjustUI()`
    (baca `getBoundingClientRect()` setelah reflow).
  - Belum dites ulang oleh user pasca-fix. Setelah dites: kalau OK, lebur poin ini ke §6 (Input Transaction /
    Disbursement) & hapus dari log ini.

- **18 Okt 2026, Document Tools > Document Editor (Tahap 8 rencana, dari `rangkuman_tool_ttd_dokumen.md`)**:
  mulai rencana kartu baru "Document Editor" (tempel ttd ke dokumen + blok/pindah teks), lanjutan dari Signature
  Editor yang sudah ada. Scope awal: upload dokumen (gambar dulu; PDF menyusul via PDF.js), seleksi blok persegi
  di atas canvas, pindah blok jadi layer terpisah (isi bekas area dengan warna latar), ambil ttd dari Signature
  Library (reuse endpoint list yang sudah ada), tempel sebagai layer PNG di atas dokumen, ekspor hasil (PNG
  dulu). Guide box, kop, multi-halaman, dan OCR ditunda ke iterasi berikutnya (Tahap 9-10 di rangkuman).
  **Keputusan (18 Okt 2026)**: (1) riwayat disimpan ke DB, tabel baru (nama diusulkan: `edited_documents`,
  struktur diusulkan saat coding karena tabel baru, bukan nebak tabel lama); (2) library layer pakai **Fabric.js**
  via CDN (stabil, dokumentasi lengkap, built-in drag/resize/select object, tanpa build step - cocok untuk pola
  vanilla IIFE proyek ini); risiko sama seperti Chart.js (§3 gotcha #8, cdnjs bisa diblokir jaringan user) -
  siapkan try/catch + pesan error jelas, dan simpan opsi host lokal kalau sering bermasalah; (3) MVP Tahap 8
  SEKALIAN dengan guide box (kotak bantuan jarak ttd), tidak dipotong minimal.
  **Implementasi awal selesai ditulis (18 Okt 2026), BELUM DITES oleh user:**
  - DB: tabel baru `edited_documents` (`sql/migration_edited_documents.sql`) - kolom: document_name,
    document_date, activity_title, source_filename, signature_id (FK nullable ke `signature_library.id`,
    `ON DELETE SET NULL`), stored_filename, file_path, file_ext, file_size, uploaded_by, created_at.
  - Storage: `storage/company_activity/{year}/{month}/{activity_slug}/edited_documents/{file}.png` (year/month
    dari `document_date`, sama persis pola `upload_signature.php`).
  - Endpoint baru: `ajax/save_edited_document.php` (pola sama dgn `upload_signature.php`: sanitize nama,
    slugify activity, simpan file + insert record, update `document_activities.json` yang SAMA dgn Signature
    Library). Tidak pakai password reverify (bukan aksi destruktif, hanya create).
  - UI: kartu baru "Document Editor" di `document_tools_content.php`, di bawah Signature Editor, pakai
    **Fabric.js 5.3.0** dari cdnjs (dimuat via `document.write` guard, sama gotcha cdnjs-bisa-diblokir seperti
    Chart.js - §3 gotcha #8 - dibungkus try/catch + pesan error eksplisit kalau `fabric` undefined).
  - Alur: upload gambar dokumen (di-downscale ke working-canvas offscreen max lebar 1400px, PDF BELUM
    didukung) → tombol "Block: Teks Atas"/"Block: Nama" (gambar rect, mouse-up langsung cut jadi fabric.Image
    draggable + hole dipatch pakai 1 rect warna sampling piksel tetangga, background image sendiri TIDAK
    diedit) → "Add Guide Box" (rect oranye resizable, event `moving`/`scaling` menggeser blok "Nama" sebesar
    delta posisi guide box kalau checkbox "Auto-shift Nama" aktif) → "Insert Signature" (modal baru
    `#docSigPickerOverlay`, reuse `ajax/list_signatures.php` utk listing + `ajax/download_signature.php`
    sebagai `<img>`/fabric source) → Save (flatten canvas jadi PNG via `toDataURL`, guide box dilepas dulu
    sebelum export lalu dipasang lagi, POST ke `save_edited_document.php`).
  - Belum ada/ditunda sesuai scope: **DOCX** (ditunda - user belum yakin Composer bisa dipakai di server;
    rencana kalau lanjut: PhpWord + salah satu dari dompdf/mpdf/tcpdf buat DOCX→PDF, baru lewat jalur PDF yang
    sama), kop/header-footer (Tahap 9), edit teks OCR (Tahap 10), undo/redo, rotate/straighten foto miring
    (Tahap 3).
  - **Perlu dites user**: (1) apakah `ajax/download_signature.php` (yang mengirim `Content-Disposition:
    attachment`) tetap bisa dipakai sebagai `<img src>`/fabric image source di semua browser target; (2)
    apakah `require dirname(__DIR__, 2) . '/fpdf/fpdf.php'` di `save_edited_document.php` menunjuk ke path
    yang benar (asumsi dari peta folder §2: `optic_pos/fpdf/fpdf.php`, satu level di atas `lisani_aos/`) -
    BELUM diverifikasi langsung, cuma dari peta folder; (3) kualitas hasil render PDF.js & akurasi warna
    sampling utk patch lubang di foto (bukan scan putih bersih); (4) ukuran halaman PDF akhir diasumsikan
    150 DPI tetap (`$ASSUMED_DPI` di `save_edited_document.php`) - PDF.js sendiri render halaman dgn scale
    dibatasi `MAX_DOC_WIDTH` 1400px, jadi DPI efektif bisa beda dari asumsi kalau ukuran kertas sumber aneh;
    cek hasil cetak/ukuran fisiknya masuk akal atau tidak.

  **REVISI 18 Okt 2026 (multi-halaman + multi-ttd, atas permintaan user)**:
  - Scope ditambah: dokumen boleh **PDF multi-halaman** (bukan cuma 1 gambar), dengan **pilih halaman** via
    strip tombol "Hal 1/2/3.." di atas canvas, dan **lebih dari satu ttd** bisa ditempel di halaman manapun.
    DOCX sengaja DITUNDA (user belum yakin Composer tersedia di server; lihat baris di atas).
  - PDF→gambar per halaman pakai **PDF.js** (cdnjs, murni di browser, TIDAK butuh Imagick/Ghostscript/
    pdftoppm di server - aman untuk Termux/XAMPP yang belum tentu ada binary itu).
  - Tiap halaman punya state Fabric sendiri (blok, guide box, ttd) disimpan via `canvas.toJSON()`/
    `loadFromJSON()` saat pindah halaman (`switchToPage()`); blok "Nama"/guide box per halaman dicari ulang
    lewat custom prop (`blockType`, `isGuideBox`) setelah di-load karena referensi objek lama hilang.
  - ID ttd yang dipakai (di halaman manapun, berkali-kali) dikumpulkan di `insertedSignatureIds` (map id→true,
    TIDAK berkurang kalau ttd dihapus lagi dari canvas - limitasi kecil, cukup akurat untuk keperluan
    riwayat/jejak).
  - Saat Save: SEMUA halaman di-flatten jadi PNG (guide box dibuang dari hasil export), dikirim sekaligus
    (`pages[]`) + `signature_ids[]` ke `ajax/save_edited_document.php`, yang menggabungkannya jadi **1 file
    PDF** pakai **FPDF** (reuse `optic_pos/fpdf/fpdf.php`, sudah dipakai utk invoice) - ukuran tiap halaman PDF
    mengikuti rasio gambar aslinya (asumsi 150 DPI), bukan dipaksa A4.
  - DB: `sql/migration_edited_documents.sql` DIREVISI - `signature_id` (FK tunggal) → `signature_ids` (VARCHAR
    CSV, tanpa FK, karena bisa banyak nilai), tambah `page_count`, `file_ext` default jadi `'pdf'` (bukan
    `'png'`). Kalau migrasi versi lama SUDAH sempat dijalankan, ada `ALTER TABLE` di komentar file SQL-nya.

  **REVISI 18 Okt 2026 (toolbar + zoom, atas permintaan user), sudah ditulis, BELUM DITES oleh user:**
  - Toolbar `document_tools_content.php` dikelompokkan jadi 3 grup (border tipis): Blocks (Block: Top Text /
    Block: Name / Add Guide Box / checkbox Auto-shift), Actions (Insert Signature / Delete Selected / Clear
    All), Zoom (di kanan via `margin-left:auto`). Label "Block: Teks Atas"/"Block: Nama"/"Auto-shift Nama"
    diinggriskan jadi "Block: Top Text"/"Block: Name"/"Auto-shift Name" (tombol lain sudah Inggris). Label tab
    halaman "Hal 1/2/3" & field "Judul Kegiatan" SENGAJA belum diinggriskan - di luar scope yang diminta.
  - Auto-fit + Zoom In/Out/Fit to Page (3 tombol baru): ganti pendekatan CSS `max-width:100%;height:auto` di
    `#docEditorCanvas` (yang cuma squish tampilan, bukan zoom beneran) dengan **Fabric native**
    `canvas.setZoom()` + `canvas.setDimensions()`, supaya `getPointer()` saat gambar blok/drag tetap akurat di
    level zoom berapa pun. Fungsi baru `applyZoom()`/`fitZoomToContainer()` (`document_tools_content.php`);
    `fitZoomToContainer()` dipanggil tiap `switchToPage()` (tiap buka/ganti halaman auto-fit ke lebar wrap,
    dibatasi tinggi 65% viewport). `.doc-editor-canvas-wrap` jadi `overflow:auto` (scrollable pas zoom-in)
    dengan `max-height:75vh`. Export/save PDF TIDAK terpengaruh - tetap render dari `fabric.StaticCanvas`
    terpisah di resolusi asli (`renderPageToDataURL()`), bukan dari canvas layar yang sedang di-zoom.
  - **Perlu dites user**: (1) akurasi klik/drag gambar blok & guide box di berbagai level zoom, terutama di
    HP/touch; (2) tampilan saat dokumen sangat lebar/sempit (rasio ekstrem) - cek `fitZoomToContainer()` tidak
    menghasilkan ukuran terlalu kecil; (3) scroll di `.doc-editor-canvas-wrap` saat zoom-in masih nyaman dipakai
    di mobile.

  **REVISI 18 Okt 2026 (fullscreen + zoom SVG + draw-mode sticky + guide box preset), atas permintaan user,
  sudah ditulis, BELUM DITES oleh user:**
  - **Fullscreen** (`document_tools_content.php`): seluruh workspace editor (upload, page tabs, toolbar, canvas,
    DAN form Save/Document Name/Date/Activity) dibungkus satu div `#docEditorWorkspace`. Tombol baru
    "Fullscreen"/"Exit Fullscreen" (`setFullscreen()`) membuatnya `position:fixed;inset:0` menutupi viewport
    (`z-index:500` - **BELUM dicek** apakah masih kalah/menang lawan topbar/sidebar app, cek kalau salah satu
    masih nongol di atasnya). Auto-aktif begitu upload selesai (`startEditingPages()`); Escape atau tombol yang
    sama untuk keluar. Body scroll di-`hidden`-kan selama fullscreen, dikembalikan saat keluar.
  - Ikon tombol Zoom In/Out, Fit to Page, dan Fullscreen diganti dari `<i class="ti ...">` (Tabler) ke **SVG
    inline**, sesuai gotcha §3 #1 (ikon Tabler sering tofu/kotak kosong) - supaya tombol baru ini kelihatan
    jelas sebagai tombol, bukan kotak kosong. Ikon tombol lama (crop/trash/eraser/dsb, sudah ada sebelum sesi
    ini) SENGAJA belum diubah - di luar scope yang diminta.
  - `fitZoomToContainer()` sekarang baca `clientHeight` wrap yang sebenarnya (bukan cuma persentase
    `window.innerHeight`), supaya auto-fit tetap akurat baik di mode normal (dibatasi CSS `max-height:75vh`)
    maupun fullscreen (wrap jadi flex item yang stretch ke sisa tinggi viewport).
  - **Block: Top Text / Block: Name tidak lagi "perlu diklik dua kali"**: akar masalahnya di handler
    `mouse:up` - drag yang terlalu kecil (<4px, mis. klik tunggal tanpa seret) sebelumnya SELALU mematikan
    `docDrawMode` walau tidak ada blok yang terbentuk, jadi tombol harus ditekan ulang. Sekarang `docDrawMode`
    cuma dimatikan kalau blok BERHASIL dibuat; drag gagal membiarkan tool tetap aktif. Ditambah cursor
    `crosshair` di canvas selama armed, sebagai indikator visual "siap gambar".
  - **Guide Box: dropdown preset ukuran** (`docGuideBoxPresetSelect`, disimpan di INCI, bukan lagi persentase
    halaman 45%/12%). Preset baru: `json_file/guide_box_presets.json` (dibuat/self-seed via endpoint baru
    `ajax/list_guide_box_presets.php`, pola sama persis dgn `document_activities.json`), default 2 baris sesuai
    permintaan user: **1.46" x 2.18"** dan **1.46" x 3.05"**. Admin boleh edit/tambah baris json ini langsung di
    server tanpa ubah kode. Konversi inci→px pakai konstanta `DOC_DPI = 150` di JS, **HARUS tetap sinkron**
    dengan `$ASSUMED_DPI = 150` di `save_edited_document.php` (dua konstanta terpisah, kalau salah satu diubah
    yang satu lagi juga harus). Guide box tetap bisa di-resize/drag manual seperti sebelumnya setelah dibuat.
  - **Perlu dites user**: (1) z-index fullscreen (500) vs elemen app lain (topbar/sidebar/modal) - lihat poin di
    atas; (2) apakah 1.46"/2.18"/3.05" di layar memang pas dicetak di PDF hasil Save (validasi asumsi
    `DOC_DPI`/`$ASSUMED_DPI` 150 sama-sama benar); (3) folder `json_file/` di server harus writable supaya
    self-seed `guide_box_presets.json` jalan, sama seperti `document_activities.json`; (4) perilaku Escape-key
    keluar fullscreen tidak bentrok dgn shortcut lain di app.

  **BUG FATAL & PERBAIKAN (18 Okt 2026, sama hari, ditemukan user langsung setelah revisi di atas)**:
  Semua tombol di menu Document Tools (bukan cuma Document Editor - Signature Library & Signature Editor ikut
  mati) tidak bisa diklik sama sekali. Sebab: `loadGuideBoxPresets()` dipanggil langsung (top-level) di dekat
  `loadActivities()`, DEKAT AWAL `<script>`, padahal `var docGuideBoxPresetSelect = document.getElementById(...)`
  baru di-assign jauh di bawah (bagian Document Editor). Karena dipanggil sebelum variabelnya ter-assign,
  `docGuideBoxPresetSelect` masih `undefined` saat itu (var di-hoist, assignment-nya belum jalan) →
  `docGuideBoxPresetSelect.innerHTML = ''` lempar TypeError tak tertangkap → sisa `<script>` (semua
  `addEventListener` tombol lain, collapsible, dsb, yang letaknya di bawah pemanggilan itu) tidak pernah
  dijalankan sama sekali. **Perbaikan**: pindahkan definisi+pemanggilan `loadGuideBoxPresets()`/
  `renderGuideBoxPresetOptions()` ke bagian Document Editor, PERSIS setelah `docGuideBoxPresetSelect` (dan
  `inchesToPx`/`docGuideBoxPresets`) sudah ter-assign. File `ajax/list_guide_box_presets.php` dan
  `json_file/guide_box_presets.json` TIDAK berubah, tidak perlu diupload ulang - cukup
  `document_tools_content.php` yang baru.
  **Pelajaran untuk sesi berikutnya**: kalau nambah kode baru yang langsung dipanggil di top-level script (bukan
  di dalam event handler), WAJIB taruh setelah semua `var ... = document.getElementById(...)` yang dipakainya
  sudah ter-assign secara tekstual (bukan cuma dideklarasikan) - jangan taruh dekat kode lama yang kebetulan
  posisinya di awal file (`loadActivities()` dkk) hanya karena polanya mirip.

  **BUG #2 & PERBAIKAN (18 Okt 2026, laporan user: "setelah upload, fullscreen tidak keluar sama sekali")**:
  `#docEditorWorkspace` normalnya ada di dalam `.doctools-collapsible-body` (`overflow:hidden`, tinggi diatur
  JS lewat `max-height` inline). Secara spek CSS, `position:fixed` semestinya "lolos" dari ancestor
  `overflow:hidden` biasa (tidak kena clip), TAPI beberapa WebView Android/browser lama dikenal salah
  implementasi soal ini dan tetap memotong/mengecilkannya - persis kelihatan seperti "tombol Fullscreen
  dipencet, tidak terjadi apa-apa". **Perbaikan**: `setFullscreen()` sekarang betulan **memindahkan**
  `#docEditorWorkspace` jadi anak langsung `<body>` saat masuk fullscreen (`document.body.appendChild(...)`),
  dan mengembalikannya persis ke posisi asal (parent + next-sibling yang disimpan) saat keluar - bukan cuma
  toggle class di tempat. Ini keluar total dari ancestor manapun yang bisa jadi containing block/clip, jadi
  kerja di browser mana pun selama kuncinya cuma soal containing-block itu.
  **Perlu dites user lagi**: apakah fullscreen SEKARANG benar muncul setelah upload; kalau masih belum
  kelihatan sama sekali (bukan cuma kepotong), kemungkinan sebabnya lain - z-index 500 kalah vs elemen app lain
  (lihat poin z-index di atas) atau CSS global (theme.css) override yang tidak kelihatan dari file ini saja.

  **BUG #3 & PERBAIKAN (18 Okt 2026, laporan user: "saat fullscreen preview dokumennya tidak keluar, baru
  muncul pas exit fullscreen")**: elemen fullscreen-nya sendiri sudah benar muncul (bug #2 sudah kebetulan
  fix), tapi ISI dokumennya (canvas) blank selama masih fullscreen. Akar masalah: urutan lama di
  `startEditingPages()` adalah `setFullscreen(true)` **SEBELUM** `switchToPage(0)` - artinya workspace
  dipindah (reparent) ke fullscreen SAAT canvas-nya belum dibuat/belum ada gambar dokumen sama sekali (Fabric
  baru bikin `new fabric.Canvas(...)` dan load gambar di dalam `switchToPage`, yang jalan SETELAH reparent).
  **Perbaikan**: urutannya dibalik pakai flag `docAutoFullscreenPending` - `startEditingPages()` cuma pasang
  flag, lalu `switchToPage(0)` jalan seperti biasa (di layout NORMAL yang sudah terbukti kerja), dan baru
  setelah `afterLoad()` (gambar sudah ke-load + di-render) selesai, BARU `setFullscreen(true)` dipanggil -
  jadi reparent ke `<body>` terjadi saat canvas SUDAH punya isi, bukan saat masih kosong. Ditambah juga
  pengaman kecil di `fitZoomToContainer()`/`applyZoom()`: diam-diam `return` kalau `page.width`/`page.height`
  belum valid atau hasil `zoom` bukan angka wajar (NaN/≤0), supaya tidak pernah set `canvas.setDimensions()`
  dengan nilai rusak walau ada race lain di kemudian hari.

  **BUG #4 & PERBAIKAN (18 Okt 2026, laporan user: "posisi Insert Signature tidak ikut Guide Box seperti yang
  saya mau")**: `insertSignatureOntoCanvas()` SEBENARNYA sudah mencoba mengikuti posisi guide box
  (`guideBox ? ... : ...`), tapi pakai `guideBox.width`/`guideBox.height` mentah. Masalahnya: kalau user
  RESIZE guide box dengan drag handle di pojoknya (perilaku default Fabric.js), Fabric menyimpan perubahan
  ukuran itu di `scaleX`/`scaleY`, BUKAN mengubah `.width`/`.height` object-nya langsung - jadi begitu guide
  box di-resize, `insertSignatureOntoCanvas()` masih mengira ukurannya seperti waktu pertama dibuat, dan
  tanda tangan jadi salah posisi/ukuran. **Perbaikan**: ganti ke `guideBox.getScaledWidth()` /
  `guideBox.getScaledHeight()` (method Fabric yang sudah memperhitungkan scaleX/scaleY) untuk hitung `targetW`
  dan posisi tengah. `.left`/`.top` tidak diubah karena itu tidak kena pengaruh scale (originX/originY
  default `left`/`top`).
  **Alur yang dikonfirmasi user ("ini dulu, baru selanjutnya" - jadi BARU bagian ini yang dikerjakan)**: Add
  Guide Box → user posisikan/resize guide box → Insert Signature → pilih dari pustaka → otomatis ditempatkan
  mengikuti posisi+ukuran guide box saat itu. User bilang ada langkah/alur TAMBAHAN yang belum disampaikan -
  **TUNGGU instruksi lanjutan dari user sebelum menambah apa pun lagi di alur ini**, jangan menebak.

  **STATUS 18 Okt 2026 (setelah 3x revisi fullscreen)**: bug preview blank (#3) dan posisi ttd vs guide box
  (#4) di atas **DIKONFIRMASI FIX** oleh user (dites via screenshot, lihat gambar yang dikirim). z-index
  diperkuat ke `2147483647 !important` (bukan 500 lagi) + `position/top/left/right/bottom/width/height` pakai
  `!important` juga, karena fullscreen sempat tidak muncul SAMA SEKALI (ternyata overridden sesuatu yang tidak
  kelihatan dari file ini - root cause PASTINYA belum ketemu, cuma dilawan pakai `!important` + z-index
  maksimum sampai user konfirmasi jalan).

  **BUG #5 & REDESIGN (18 Okt 2026, laporan user: "pas layar dilebarkan fullscreen muncul, pas dikecilkan
  hilang lagi" + "fullscreen maunya bukan begini - dokumennya harus full 1 halaman, tombol-tombol di paling
  atas biar gampang nge-posisiin")**:
  - **Akar bug resize**: `.doc-editor-toolbar` (3 grup: Blocks/Actions/Zoom) pakai `flex-wrap:wrap` - di
    window sempit, grup-grup itu pecah jadi 2-3 baris, makan tinggi vertikal, dan `.doc-editor-canvas-wrap`
    (flex child dengan `flex:1 1 auto`) jadi KEPEPET sampai nyaris 0 tinggi (atau malah dapat computed height
    negatif/0 karena flex item default `min-height:auto` menolak mengecil di bawah ukuran kontennya - root
    cause ganda). **Perbaikan**: khusus saat fullscreen, `.doc-editor-toolbar` dikunci `flex-wrap:nowrap` +
    `overflow-x:auto` (geser horizontal kalau kepanjangan, TIDAK PERNAH pecah baris lagi), dan
    `.doc-editor-canvas-wrap` ditambah `min-height:0` biar bener-bener taat sama `flex:1`.
  - **Redesign tampilan fullscreen** (supaya dokumen dominan + toolbar di atas, sesuai permintaan): field
    "Upload Document" (`#docUploadFormGroup`, id baru) dan teks hint (`#docEditorHint`) di-`display:none` KHUSUS
    saat fullscreen (toolbar+tab halaman otomatis jadi elemen paling atas begitu dua ini hilang - TIDAK perlu
    reorder DOM/flex `order`, urutannya sudah pas begitu dua elemen itu disembunyikan). Form Save (Document
    Name/Date/Judul Kegiatan/tombol Save) dirapatkan jadi strip tipis di bawah canvas (grid kolom lebih sempit,
    margin dikecilkan) - TETAP ada/terlihat (tidak disembunyikan), supaya user tidak perlu exit fullscreen
    cuma buat isi nama dokumen & simpan.
  - **Perlu dites user**: (1) apakah toolbar yang sekarang 1-baris-scroll-horizontal di layar sempit/HP masih
    nyaman dipakai (geser ke kanan buat lihat tombol Zoom/Fullscreen kalau muat banyak tombol); (2) apakah
    canvas sekarang benar-benar dominan (hampir 1 halaman penuh) seperti yang diminta, atau strip form Save di
    bawah masih kerasa makan tempat - kalau masih kurang lega, opsi lanjutannya adalah sembunyikan form Save
    juga di balik tombol terpisah (baru muncul kalau diklik), TAPI BELUM dikerjakan, nunggu konfirmasi user
    dulu apakah versi sekarang sudah cukup.

  **REVISI 21 Okt 2026 (4 request user, hasil dites via screenshot `localhost/optic_pos/lisani_aos/index.php`
  - jadi dikonfirmasi `document_tools_content.php` ini memang yang di-`include` di sana, bukan file lain)**:
  1. **Form Save disembunyikan total saat fullscreen** (bukan dirapatin strip tipis lagi seperti BUG #5 di
     atas - user balik keputusan). `#docEditorSaveForm { display:none !important; }` saat
     `.doc-editor-fullscreen`. Alur sekarang: isi nama dokumen/save = exit fullscreen dulu.
  2. **FIX: "Insert Signature" di fullscreen tidak muncul fly window-nya.** Akar masalah: `#docSigPickerOverlay`
     posisinya di DOM SEBELUM `#docEditorWorkspace`; begitu masuk fullscreen, workspace di-reparent jadi anak
     TERAKHIR `<body>` (z-index `2147483647`) sedangkan overlay tetap di posisi lama dengan z-index
     `.modal-overlay` biasa (lebih rendah) → ketimpa. Perbaikan: `setFullscreen(true)` sekarang reparent
     `docSigPickerOverlay` juga ke `<body>`, PERSIS setelah workspace (supaya urutan DOM menang stacking), +
     CSS `#docSigPickerOverlay.modal-overlay { z-index: 2147483647 !important; }` biar z-index-nya juga pasti
     menang lawan theme.css. Dikembalikan ke posisi asal saat exit fullscreen.
  3. **Fullscreen sekarang fit-to-WIDTH, bukan fit-to-page lagi.** `fitZoomToContainer()` dicabang: di luar
     fullscreen tetap `min(fit-width, fit-height)` seperti sebelumnya; KHUSUS saat fullscreen jadi
     `fit = available-width / page.width` saja (tinggi tidak dibatasi) - kalau halaman jadi lebih tinggi dari
     layar, tinggal scroll vertikal (canvas-wrap sudah `overflow:auto`).
  4. **BUG BELUM KETEMU & DIABAIKAN SEMENTARA (atas permintaan user)**: tombol-tombol toolbar (termasuk grup
     Zoom) terlihat MENGECIL saat persentase zoom dokumen dinaikkan (cth. dari 20% ke 65%), dikonfirmasi lewat
     screenshot. BUKAN zoom browser (user sudah cek: Chrome zoom tetap 100% baik sebelum maupun sesudah klik
     tombol zoom dokumen). Sudah ditelusuri habis di SEMUA file terkait (`document_tools_content.php`,
     `theme.css`, `responsive.css`, `header.php`, `footer.php`) - tidak ada `zoom:`, `transform: scale()`,
     container-query unit (`cqw`/`cqi`), atau `--space-*`/`--text-*` berbasis `vw`/`clamp()` di manapun;
     `.doc-zoom-btn` fixed `34x34px`; `.doc-editor-toolbar-group` sudah `flex:0 0 auto` (tidak boleh shrink);
     JS `applyZoom()` cuma nyentuh elemen `<canvas>`, tidak ada `setProperty`/`style.fontSize`/`style.transform`
     ke toolbar. **Root cause TIDAK DITEMUKAN** - user bilang abaikan dulu, lanjut ke hal lain. Kalau mau
     dilanjutkan nanti: minta user screenshot panel **Computed** (bukan Styles) DevTools dari salah satu tombol
     toolbar pas zoom 20% vs 65% (font-size/padding), biar ketauan browser override dari CSS mana.

Belum dibangun (disengaja, bukan bug):
- Fitur pembayaran bersama (disebut user saat desain Create New Logistic). Baru placeholder di form.
- Filter/pagination Movements; retention `storage/recycle/`; `customers.profit`; edit/hapus alias & order; aturan stok minus;
  uppercase server di `manage_packaging_units.php`.
- Data lama order yang sudah terpecah sebelum `batch_id` tetap terpisah. Skrip migrasi belum dibuat.
- Opsi: `DROP COLUMN logistics.defective_reference_price` (ireversibel, belum dijalankan).
- Opsi: host Chart.js lokal di server, kalau jaringan user terus memblokir cdnjs.

Perlu konfirmasi keputusan:
- Invoice numbering `sequence_number` lanjut per customer lintas bulan (bukan reset bulanan). Kalau ternyata tidak,
  kembalikan filter `period_month/period_year` di query MAX di `create_order.php`.
- Logistic List & Movements dengan >1 produk per activity code masih tampil per baris produk, belum dikelompokkan di
  bawah satu header activity code. Pertimbangkan pengelompokan kalau terasa berantakan.
- Audit scroll horizontal menu lain setelah perbaikan `.app`/`.main` (global): Transactions, Logistic, Settings, Dashboard
  dicek di 3 ukuran (HP ≤767px, tablet 768–1023px, jendela setengah layar ~900–1000px).
- Modul Investor: alokasi dana (Fund Used/Share) & total tfoot tab Report sudah dites & jalan benar di server (12 Okt
  2026). Masih perlu dicek: tanda `total_price` pada `price_adjustment` (dipakai rumus gross di §5.14; asumsi positif,
  dikurangkan) — ini sebenarnya milik modul Sales, bukan Investor, tapi memengaruhi rumus laporan Investor; kelas
  `.input-uppercase` jalan tanpa `flexOverlays` untuk 2 modal `invPickerOverlay`/`invPwOverlay` (kalau masih dipakai —
  cek apakah sudah terpakai di kode saat ini). Fitur lain di §6 Investor (Investment Input, Non-Project Expenses,
  Profit Payment & Rolled Capital, delete investor) belum dikonfirmasi ditest terpisah.

## 9. Cara lanjut di sesi baru
1. Upload PROJECT_NOTES.md ini + hanya file kode yang relevan (Sales: `transaction_content.php` + `ajax/` terkait;
   Logistic: `logistic_content.php` + `list_logistic_movements.php`/`list_logistics.php`; Investor:
   `investor_content.php` + `ajax/investor_api.php`).
2. Jangan upload `NOTES_ARCHIVE.md` kecuali perlu menelusuri alasan keputusan lama (sebut bagian yang dicari).
3. Setelah selesai: tambahkan ≤10 baris ke §8 (atau "Log terbaru"), lalu lebur ke §4–6 kalau sudah dites.