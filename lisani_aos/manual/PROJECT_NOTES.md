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
- **Menu**: sidebar = Dashboard, Transactions, Logistic, Document Tools, Report, Investor — semua pakai ikon Tabler
  (Logistic sempat tanpa ikon, diperbaiki 16 Okt 2026 jadi `ti-truck-delivery`; Document Tools baru, `ti-signature`,
  lihat §6), berlaku di sidebar desktop & bottom-nav mobile.
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

**Document Tools** (`document_tools_content.php`; semua sudah dites user & OK per 26 Okt 2026):

- *Signature Library*: satu-satunya cara tambah signature = lewat editor canvas (Upload Photo → edit → Save to
  Library, `ajax/upload_signature.php`); tidak ada form upload manual terpisah lagi, tab "Draw" sudah dihapus.
  Storage `storage/signature/{company_name_slug}/{origin_slug}/{file}`. Field **Company Name** + **Origin**
  (tidak bisa diedit lewat form Edit, hanya saat create; tidak terhubung ke `document_activities.json` — itu
  murni milik Document Editor). Kolom `is_main` (boleh lebih dari satu sekaligus, diatur manual user, tanpa
  constraint unik/auto-unset); `list_signatures.php` `ORDER BY is_main DESC, signature_date DESC, id DESC`,
  badge "MAIN" di list. List dikelompokkan per Company Name (header grup collapsible, grup pertama auto-terbuka).
  Date default ke hari ini (local time) saat form pertama dibuka & tiap reset form.
  - Signature Editor (canvas, Upload Photo saja): workspace putih + grid garis halus (bukan checkerboard).
    **Crop**: drag kotak awal → 8 handle resize + drag-dari-tengah untuk geser, tombol **Undo Crop** (1 langkah
    terakhir). **Erase Part**: brush radius diatur slider "Eraser Size", hasil tersimpan di `erasedMask` terpisah
    dari threshold (geser slider Remove White Background tidak menghapus hasil erase); **Undo Erase** adalah
    history stack (bukan 1 langkah) maks 30 entri, snapshot diambil tiap awal stroke (`pointerdown`). Remove
    White Background (`sigBgThreshold`) punya tombol -5/+5 di samping slider. **Ink Contrast** (slider 0-100,
    default 0): satu kontrol untuk gelapkan warna + tebalkan opacity piksel tinta sekaligus (`inkFactor`), selalu
    dihitung ulang dari `originalPhotoImageData`, tidak direset saat upload foto baru/Clear (konsisten dengan
    `sigBgThreshold`). **Zoom** (grup toolbar terpisah, `sigZoomSlider` 50-300% + tombol −/Reset/+): murni ukuran
    tampilan (CSS `width%` pada `.sig-canvas-zoom-wrap`), tidak mengubah resolusi pixel asli canvas, koordinat
    pointer tetap akurat di level zoom berapa pun. Toolbar terbagi jadi kartu grup: Photo / Zoom / Crop / Erase
    Part / Remove White Background / Ink Contrast. Konstanta Zoom proyek ini (`ZOOM_MIN/MAX/STEP`) khusus
    Signature Editor — Document Editor pakai prefix `DOC_ZOOM_*` sendiri (lihat gotcha §3 soal nama variabel
    top-level yang collide dalam satu IIFE file).
  - Delete Signature: pindah ke `recycle/` + membersihkan folder asli `storage/signature/.../` yang jadi kosong
    (jalan ke atas berhenti di folder yang masih ada isinya, tidak pernah menghapus `storage/signature` sendiri).

- *Letterhead Library* (tabel `letterhead_library`, 1 entri = header wajib + footer opsional, storage
  `storage/letterhead/{company_slug}/{origin_slug}/`, pola CRUD sama seperti Signature Library): 3 input
  (`lhNameInput`/`lhCompanyInput`/`lhOriginInput`) pakai `.input-uppercase` + `strtoupper()` server. Delete
  membersihkan folder asli sama seperti Signature, dan tombol Delete punya teks di sebelah ikon.

- *Document Editor*: upload dokumen gambar atau **PDF multi-halaman** (PDF→gambar per halaman via PDF.js, murni
  browser, tidak butuh Imagick/Ghostscript/pdftoppm di server), strip tombol pilih halaman, kanvas **Fabric.js
  5.3.0** (cdnjs, dibungkus try/catch — gotcha §3 #8). Tiap halaman punya state Fabric sendiri
  (`toJSON()`/`loadFromJSON()` saat `switchToPage()`).
  - **Block**: "Block: Top Text"/"Block: Name" — drag kotak di canvas langsung jadi `fabric.Image` draggable +
    lubang bekas area dipatch warna sampling piksel tetangga. Drag gagal (<4px) tidak mematikan draw-mode lagi
    (sebelumnya harus klik ulang tombol); cursor `crosshair` selama armed.
  - **Guide Box**: rect oranye resizable, dropdown preset ukuran dalam **inci** (`json_file/guide_box_presets.json`,
    self-seed via `ajax/list_guide_box_presets.php`, default 1.46"×2.18" dan 1.46"×3.05", admin bisa
    edit/tambah baris JSON langsung di server). Checkbox "Auto-shift Name" menggeser blok Name sebesar delta
    posisi guide box saat guide box digeser/di-resize. Konversi inci→px pakai `DOC_DPI = 150` (JS), **harus tetap
    sinkron** dengan `$ASSUMED_DPI = 150` di `save_edited_document.php`.
  - **Insert Signature**: modal `#docSigPickerOverlay` (reuse `ajax/list_signatures.php` + `download_signature.php`
    sebagai image source), posisi mengikuti guide box pakai `getScaledWidth()`/`getScaledHeight()` (bukan
    `.width`/`.height` mentah, supaya ikut resize guide box yang sudah di-scale via drag handle Fabric).
  - **Insert Letterhead**: ditempel full-width pinned ke atas/bawah halaman (`centeredScaling:true` khusus layer
    kop, beda dari signature yang biasanya di-resize dari sudut). Checkbox "Apply to all pages" (default checked):
    halaman yang tidak sedang dibuka ditempel lewat `fabric.StaticCanvas` offscreen
    (`addLetterheadToPageData()`), rumus placement satu sumber via `letterheadPlacementProps()`. Edit
    (geser/resize) kop ikut sync ke semua halaman via tag custom Fabric `letterheadSyncId` + `letterheadPart`
    (ditambahkan ke semua pemanggilan `toJSON([...])` termasuk undo snapshot) dan listener `object:modified` →
    `syncLetterheadTransform(obj)`. Undo letterhead: snapshot diambil di `mouse:down` begitu user mulai pegang
    layer letterhead (bukan hanya sebelum aksi eksplisit seperti layer lain) — jadi Undo pertama = balik posisi
    sebelum geser terakhir (letterhead tetap ada, tersync ke semua halaman), Undo kedua baru menghapus total.
    Arrow-key nudge ikut sync tapi tidak push undo snapshot sendiri.
  - **Zoom**: `applyZoom()`/`fitZoomToContainer()` pakai Fabric native `setZoom()`+`setDimensions()` (bukan CSS
    squish) supaya `getPointer()` akurat di level zoom berapa pun; `fitZoomToContainer()` auto-fit tiap
    `switchToPage()` dan tiap `window.resize`. Export/save PDF selalu render dari `fabric.StaticCanvas` terpisah
    di resolusi asli (`renderPageToDataURL()`), tidak terpengaruh zoom layar.
    **Default tampilan = Fit to Width** (26 Okt 2026, ganti dari fit-to-page/`min(width,height)`): `fit =
    containerWidth / page.width`, tinggi tinggal discroll di `.doc-editor-canvas-wrap` (`overflow:auto`,
    `max-height:80vh`, `align-items:flex-start` bukan `center` supaya halaman tinggi scroll dari atas). Tombol
    toolbar "Fit to Width" (id tetap `docZoomFitBtn`, cuma teksnya diganti dari "Fit to Page").
    **Fitur Fullscreen DIHAPUS** (26 Okt 2026, atas permintaan user — dianggap tidak perlu): tombol
    `#docFullscreenBtn`, fungsi `setFullscreen()`, auto-fullscreen setelah upload, reparenting workspace +
    signature/letterhead picker ke `<body>`, CSS `.doc-editor-fullscreen`, semuanya sudah dibuang total.
    Workspace sekarang permanen di tempat asalnya di dalam `.doctools-collapsible-body`.
  - **Toolbar** (dirombak total 26 Okt 2026 jadi ribbon ala Microsoft Word, sudah dites & OK): 4 grup lama
    (Blocks, Guide Lines, Actions, Zoom & View) masing-masing sekarang SATU `.doc-ribbon-trigger` terlihat
    (ikon + nama grup + chevron) yang membuka `.doc-ribbon-panel` dropdown berisi tombol-tombol aslinya — id
    tombol tidak berubah sama sekali dari sebelum redesign, jadi semua handler JS lama utuh. Show/hide +
    outside-click + Escape + auto-tutup saat tombol di dalam panel diklik: `wireDocRibbonMenus()` (query semua
    `.doc-ribbon-group`, toggle class `.open`, cuma 1 panel boleh terbuka sekaligus), dipanggil sekali saat load.
    **Grup kontekstual** (`#docBlockAdjustGroup` - Apply/Cancel Block, muncul-hilang lewat
    `docBlockAdjustGroup.style.display` di `enterPendingAdjustMode()`/`applyPendingBlock()`/`cancelPendingBlock()`,
    TIDAK diubah titik togglenya) bentuknya SAMA PERSIS seperti 4 grup lain (trigger + dropdown, bukan
    dibentangkan terbuka — revisi dari percobaan pertama yang salah dibentangkan) dan otomatis ikut ter-wire
    karena juga punya class `.doc-ribbon-group`; pembedanya cuma warna trigger (kuning/amber via class tambahan
    `.doc-ribbon-group-contextual`, baik saat tertutup maupun saat `.open`). Pola ini (grup sama bentuk, beda
    warna trigger lewat 1 class tambahan) jadi referensi kalau nanti ada tombol/grup kontekstual lain yang perlu
    ditambahkan ke toolbar ini.
  - **Ribbon interaction fixes** (27 Okt 2026, sudah dites): `docGuideBoxPresetSelect` sempat tidak bisa diklik
    sama sekali — listener global `document.addEventListener('click', closeAll)` di `wireDocRibbonMenus()` ikut
    menutup panel sebelum dropdown native sempat terbuka; fix dengan `panel.addEventListener('click', …)` ber-
    `e.stopPropagation()`. Grup `data-ribbon-group="zoom"` dikecualikan dari auto-close-on-button-click, supaya
    Zoom In/Out/Fit bisa diklik berkali-kali tanpa panel tertutup (tetap tertutup lewat trigger sendiri atau klik
    luar). Setelah Save Edited Document sukses, dokumen upload otomatis dibersihkan (canvas dispose, file input &
    toolbar reset) — pola sama seperti `docClearAllBtn` tapi tanpa modal password. Date default ke tanggal akses
    hari ini (reuse `todayDateInputValue()` dari Signature Editor), berlaku saat card pertama dibuka maupun
    setelah reset pasca-Save. Sisa teks UI Document Editor yang masih Indonesia diterjemahkan ke Inggris (hint
    DOCX belum didukung, "Judul Kegiatan"→"Activity Title" + placeholder, "Hal N"→"Page N").
  - **Save**: semua halaman di-flatten jadi PNG (guide box dibuang dari export), dikirim sekaligus (`pages[]`)
    + `signature_ids[]` + `letterhead_ids[]` ke `ajax/save_edited_document.php`, digabung jadi **1 file PDF**
    via **FPDF** (reuse `optic_pos/fpdf/fpdf.php`). Tabel `edited_documents`: `signature_ids`/`letterhead_ids`
    (VARCHAR CSV, tanpa FK), `page_count`, `file_ext` default `'pdf'`. DOCX ditunda (belum yakin Composer
    tersedia di server).
  - **Bug diketahui, belum ditemukan akar masalahnya, diabaikan sementara atas permintaan user**: tombol-tombol
    toolbar (termasuk grup Zoom) terlihat mengecil saat persentase zoom dokumen dinaikkan (bukan zoom browser,
    sudah ditelusuri habis di semua file terkait tanpa hasil). Kalau mau dilanjutkan: minta user screenshot
    panel Computed DevTools dari tombol toolbar di dua level zoom berbeda.

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

**Document Editor → OCR to DOCX, Tahap 1 selesai dikoding (27 Okt 2026, SUDAH ditulis, BELUM semua ditest user)**
- Rencana besar (disepakati user): OCR pakai Tesseract.js (reuse pola Input Transaction/Disbursement), ambil data
  word-level + bounding box (bukan teks polos) supaya urutan baca & layout lebih akurat saat direkonstruksi jadi
  paragraf/baris — user minta "paling akurat". Generate `.docx` murni di browser (OOXML sederhana, tanpa
  PHPWord/Composer di server — DOCX sebelumnya ditunda krn Composer belum pasti ada, pendekatan client-side ini
  menghindari masalah itu). Letterhead header/footer **otomatis** masuk exclude (lewat checkbox Apply to all
  pages di Exclude Zone, bukan logika terpisah). Output: 1 file `.docx` gabungan semua halaman. Tombol OCR
  nanti masuk grup ribbon baru sendiri.
- **Tahap 1 (exclude zone + UX fix, sudah dikoding, test checklist di bawah):**
  - Grup ribbon baru **"Exclude Zone"**: tombol **Add Exclude Zone** (bisa diklik berkali-kali, tiap klik = rect
    baru, beda dari Guide Box yang cuma 1 instance) + checkbox **Apply to all pages** (default checked, pola sync
    sama seperti Letterhead — `excludeSyncId`, `syncExcludeZoneTransform()`, `addExcludeZoneToPageData()`). Tahap
    ini masih alat bantu visual murni (dikecualikan dari flatten/Save PDF, sama seperti Guide Box/Line) — belum
    disambung ke OCR.
  - Custom Fabric props baru: `isExcludeZone`, `excludeSyncId` — ditambahkan ke SEMUA 3 tempat daftar
    `toJSON([...])` (pushDocUndoSnapshot, syncCurrentPageState, addLetterheadToPageData) — kalau nanti nambah
    prop custom baru lagi di area ini, cari ketiga tempat itu sekaligus, gampang kelewatan salah satu.
  - **Bug dilaporkan user**: setelah Add Exclude Zone / Insert Letterhead, klik di area kosong membuat hasil kerja
    hilang ("pindah ke belakang halaman") — akar masalah belum ditelusuri/ditemukan, diatasi dengan workaround
    atas permintaan user (bukan fix akar masalah): tombol **"Done"** (id `docZoneDoneGroup`, hijau `#1fb15a` —
    beda warna dari `docBlockAdjustGroup` yang amber, style inline di HTML karena belum pegang `theme.css` untuk
    nambah class resmi ke sana). **Revisi (27 Okt, atas permintaan user)**: awalnya dibuat sebagai grup ribbon
    dropdown (trigger+panel) kayak grup lain, tapi user minta 1 klik langsung — sekarang `docZoneDoneGroup`
    ADALAH tombolnya sendiri, bukan wrapper lagi, TIDAK pakai class `.doc-ribbon-group` (supaya tidak diproses
    `wireDocRibbonMenus()`). Muncul setelah Add Exclude Zone / Insert Letterhead (`showZoneDoneGroup()`), klik
    langsung `discardActiveObject()` + sembunyikan tombol. Disembunyikan juga saat pindah halaman/reset state.
    **Kalau nanti bug akar masalahnya ditemukan**, tombol "Done" ini mungkin jadi tidak perlu lagi — jangan
    dihapus dulu tanpa tanya user.
  - **Sudah ditest user & OK**: Save (flatten PDF) dgn Exclude Zone ada di halaman — tidak ikut tercetak.
  - Belum ditest ulang user setelah revisi tombol Done jadi 1-klik (versi dropdown sebelumnya belum sempat
    dites user sampai selesai).
- **Tahap 2 — OCR to DOCX, SUDAH DIKODING (27 Okt 2026), BELUM DITEST USER SAMA SEKALI:**
  - Ribbon "Exclude Zone" digabung+diganti nama jadi **"Convert to Text"** (atas permintaan user, supaya jelas
    Exclude Zone ini fungsinya untuk apa) — isinya: Add Exclude Zone + checkbox Apply to all pages (Tahap 1,
    tidak berubah) + tombol baru **Export as DOCX (OCR)** (`docOcrBtn`) dalam satu panel yang sama.
  - **Tesseract.js**: lazy-load `tesseract.js@5.0.4` dari cdnjs — **URL/versi disamakan persis** dengan yang
    sudah dipakai `transaction_content.php` (Input Transaction/Disbursement OCR, `ensureCaptureLibs()`) supaya
    tidak ada 2 versi beda yang bentrok kalau kebetulan sama-sama ke-load di 1 halaman. Bahasa dipakai
    **`'eng+ind'`** (bukan cuma `'eng'` seperti di transaction_content.php) — ini keputusan sepihak saya karena
    dokumennya Indonesia & user minta "paling akurat", **belum dikonfirmasi user**, gampang diubah balik ke
    `'eng'` saja di `convertDocumentToDocx()` kalau hasilnya malah lebih jelek.
  - **JSZip** (`jszip@3.10.1`, cdnjs) dipakai bikin kontainer `.docx` (zip minimal: `[Content_Types].xml`,
    `_rels/.rels`, `word/document.xml`) — library baru di proyek ini, belum dipakai modul lain.
  - `renderPageForOcr(page)`: sama seperti `renderPageToDataURL()` tapi area tiap Exclude Zone **diputihkan**
    (bukan dihapus) di canvas offscreen sebelum di-OCR — dokumen asli (`page.json`) tidak berubah. Urutan WAJIB
    render dulu baru gambar putih (kalau dibalik, `renderAll()` menimpa ulang) — pakai
    `tmp.lowerCanvasEl.toDataURL()` langsung (bukan `tmp.toDataURL()` Fabric) supaya tidak ada render ulang
    tersembunyi yang menghapus kotak putih manual itu.
  - **Rekonstruksi paragraf**: PAKAI LANGSUNG hasil `data.blocks → paragraphs` dari Tesseract (layout analysis
    bawaan Tesseract sendiri), BUKAN dihitung manual dari bounding box tiap word — keputusan ambil pendekatan
    ini (bukan rencana awal word-level+bbox manual di log sebelumnya) karena lebih sederhana & percobaan
    pertama sebaiknya reuse layout analysis Tesseract dulu. Fallback ke `data.text` dipecah baris kosong kalau
    `data.blocks` kosong. **Ini yang paling perlu divalidasi user** — kalau hasil paragraf/urutan bacanya kacau
    terutama di dokumen bertabel/multi-kolom, perlu direvisi ke pendekatan bbox manual yang lebih rumit.
  - Semua halaman diproses berurutan (bukan paralel, supaya tidak membanjiri browser) → 1 file `.docx` gabungan,
    page break antar halaman, langsung didownload dari browser (tidak lewat server/ajax sama sekali).
  - **Checklist test user**: coba di dokumen 1 halaman dulu, cek urutan baca & pemisahan paragraf hasil DOCX;
    cek Exclude Zone benar-benar tidak ikut terbaca; cek dokumen multi-halaman (page break benar); cek file
    `.docx` hasil bisa dibuka normal di Word/LibreOffice/Google Docs (belum pernah dites — kemungkinan ada
    bagian OOXML minimal ini yang perlu ditambah kalau ada software yang rewel).

**Document Editor → Exclude Zone hilang total di DOCX export, DIPERBAIKI (27 Okt 2026, SUDAH ditulis, BELUM ditest user)**
- **Bukan regresi** — klarifikasi ke user: fitur "area Exclude Zone muncul sebagai GAMBAR di DOCX" memang belum
  pernah dibangun di Tahap 2 awal. `renderPageForOcr()` sudah benar memutihkan zone sebelum OCR (teks di
  dalamnya tidak ikut terbaca), tapi `buildDocxBlob()` sebelumnya cuma menulis `<w:p>` teks — tidak ada logika
  crop/embed gambar sama sekali, jadi area itu hilang total di hasil akhir, bukan tetap sebagai gambar.
- **Perbaikan**: `renderPageForOcr()` sekarang JUGA meng-crop tiap Exclude Zone (pakai pola `cropCanvas` yang
  sama seperti `cutBlockToLayer()`) dari `page.workingCanvas` (bitmap asli, SEBELUM diputihkan) — resolve value
  berubah dari `dataUrl` string jadi `{ dataUrl, zones: [{x,y,w,h,imageDataUrl}, ...] }`. `convertDocumentToDocx()`
  dan `buildDocxBlob()` diupdate menyesuaikan (param baru `allPageZones`).
- `buildDocxBlob()`: OOXML tidak lagi minimal 3 bagian, sekarang juga tulis `word/_rels/document.xml.rels`
  (relationship rIdN → `word/media/imageN.png`) + `word/media/*.png` (base64 dari crop) + `Default Extension="png"`
  di `[Content_Types].xml`. Tiap zone jadi floating image (`wp:anchor`, `relativeFrom="page"`, posisi dari
  `pxToEmu()` konversi koordinat pixel zone → EMU, 96 DPI) disisipkan di paragraf PERTAMA halaman terkait, supaya
  posisinya kira-kira sama seperti di halaman asli (bukan ikut alur teks berurutan).
- **Bug ditemukan user (percobaan pertama, 27 Okt 2026)**: Word gagal buka file (`try_6 (2).docx`) — "Word
  experienced an error trying to open the file". **Root cause ditemukan & diperbaiki**: XML `word/document.xml`
  punya *unbound prefix* — prefix `wp:` (dipakai di `<wp:anchor>` dll) tidak pernah dideklarasikan sama sekali
  (lupa), dan `xmlns:a`/`xmlns:pic`/`xmlns:r` dideklarasikan lokal di elemen anak alih-alih di root
  `<w:document>`. Dikonfirmasi lewat `xml.dom.minidom` (Python) — bukan dugaan, divalidasi aktual dengan
  menjalankan ulang fungsi `buildDocxBlob()` di Node (eval fungsi asli + `jszip`) lalu parse tiap XML part di
  dalam zip. **Fix**: semua 4 namespace (`wp`, `a`, `pic`, `r`) sekarang dideklarasikan sekali di root
  `<w:document>`, dihapus dari deklarasi lokal per elemen.
- **Sudah divalidasi (otomatis, bukan di Word asli)**: 3 skenario digenerate & dicek — (1) 2 halaman, 2 exclude
  zone di halaman 1 → semua 4 XML part valid, `image1.png`+`image2.png` ter-embed, dibuka `python-docx` tanpa
  error, teks & page break terbaca benar; (2) dokumen TANPA exclude zone sama sekali → tetap valid, no-op aman;
  (3) `inline_shapes` python-docx = 0 itu NORMAL (gambar kita floating/anchor, bukan inline — python-docx cuma
  hitung inline, bukan indikasi gambar hilang).
- **Sudah ditest user di Word asli (percobaan ke-2, 27 Okt 2026)**: file berhasil dibuka (bug unbound prefix di
  atas terkonfirmasi fix), TAPI ukuran & posisi gambar exclude zone (logo letterhead) salah — jauh lebih besar
  & bergeser dari posisi aslinya di editor (dikonfirmasi user via screenshot perbandingan editor vs Word).
- **Root cause ke-2 ditemukan & diperbaiki**: `pxToEmu()` salah asumsi 96 DPI (CSS px standar), padahal SELURUH
  proyek ini (`DOC_DPI`, lihat dekat `inchesToPx()`/guide box presets, juga dipakai `save_edited_document.php`
  sbg `$ASSUMED_DPI`) menetapkan working canvas = **150 DPI**, bukan 96. Rasio salahnya ~150/96 ≈ 1.56x, cocok
  dengan gambar yang tampak membesar di screenshot. Fix: `pxToEmu()` sekarang pakai `DOC_DPI` (150) alih-alih
  angka 96 hardcoded. Divalidasi: `pxToEmu(150)` sekarang tepat 914400 EMU (= 1 inch persis), sebelumnya salah.
- **Sudah ditest user, posisi gambar Exclude Zone OK** ("sudah ok", 27 Okt 2026) — bug px→EMU 96 vs 150 DPI di
  atas dikonfirmasi FIX, tidak perlu lanjut ke dugaan margin halaman.

**Document Editor → OCR to DOCX, layout teks dirombak jadi floating per-paragraf (27 Okt 2026, SUDAH ditulis,
BELUM ditest user)**
- **Keluhan user setelah Exclude Zone gambar OK**: "layout tulisan yang di export itu sama persis dengan yang
  di asli, sekarang ini kacau" — diklarifikasi by ask_user_input_v0: user minta (1) presisi tinggi, tiap
  paragraf floating di koordinat x/y sama seperti halaman asli (bukan cuma urutan baca benar) + (2) font size,
  bold, alignment ikut style asli juga (bukan cuma posisi). Dokumen masalahnya kolom tunggal (surat/kontrak),
  BUKAN tabel/multi-kolom — jadi bukan soal urutan baca Tesseract, soal presisi render.
- **Rombak total `extractParagraphs()` → `extractLayoutBlocks(data, pageWidthPx)`**: sebelumnya cuma ambil teks
  tiap `paragraph.text` (rata kiri semua, satu ukuran font). Sekarang untuk tiap paragraf juga ambil
  `paragraph.bbox` (x0/y0/x1/y1, field dikonfirmasi dari tesseract.js index.d.ts resmi via web search, BUKAN
  tebakan), tinggi baris pertama (buat estimasi font size, bukan tinggi seluruh paragraf yg bisa multi-baris),
  `is_bold` mayoritas word di paragraf (>50%), dan heuristik alignment (center kalau margin kiri≈kanan &
  margin kiri > 8% lebar halaman, selain itu left — SENGAJA tidak coba deteksi "right align" krn jarang dipakai
  di surat Indonesia & riskan salah). Fallback tanpa bbox (`data.text` dipecah baris kosong) tetap ada,
  ditandai `x:null` → dirender sbg alur teks biasa, bukan floating.
- **Rombak `buildDocxBlob()`**: tiap paragraf berposisi sekarang jadi **floating textbox** OOXML
  (`wp:anchor > a:graphic > a:graphicData(uri=wordprocessingShape) > wps:wsp`), BUKAN `<w:p>` alur teks biasa
  lagi. Struktur `wps:wsp` (cNvSpPr, spPr dgn xfrm+prstGeom+noFill+ln, txbx>txbxContent, bodyPr) **dikutip
  persis dari test fixture resmi parser DOCX `dxpdf`** (docs.rs/crate/dxpdf/tests/shape_text_anchor.rs) supaya
  tidak menebak struktur lagi seperti kegagalan unbound-prefix sebelumnya. **PENTING**: namespace `wps` di sini
  pakai URI `http://schemas.microsoft.com/office/word/2010/wordprocessingShape` (BEDA dari openxmlformats.org
  yang dipakai pic/a/wp/r — jangan disamakan kalau ubah2 lagi). Posisi textbox: `relativeFrom="page"` + offset
  dari `pxToEmu(block.x/y)` (sama seperti Exclude Zone). Tinggi textbox dilonggarkan `*1.3` dari bbox asli
  (reflow font Word vs gambar bisa beda lebar dikit) TAPI lebar TIDAK dilonggarkan (user minta presisi x
  tinggi). Background/border textbox transparan (`a:noFill`) biar tidak menimpa teks lain.
- **Font size**: fungsi baru `pxHeightToHalfPoint(px)` — px (150 DOC_DPI) → pt → half-point (satuan `w:sz`),
  di-clamp 12–144 half-pt (6–72pt) biar noise OCR (bbox 1px dianggap "paragraf") tidak menghasilkan font
  rusak. **Bold**: `<w:b/><w:bCs/>` kalau `block.bold`. **Alignment**: `<w:jc w:val="center">` — WAJIB dibungkus
  `<w:pPr>` (bukan child langsung `<w:p>`), sempat salah taruh lepas di percobaan pertama rombakan ini,
  dikonfirmasi & diperbaiki via referensi skema OOXML resmi (ECMA-376 §17.3.1.13) sebelum ditulis ke file utama.
- **Divalidasi otomatis** (sebelum diserahkan ke user, belajar dari 2 kegagalan sebelumnya): generate ulang
  `buildDocxBlob()` yang sama persis di Node+jszip dengan data bbox dummy (judul center-bold, paragraf kiri
  biasa, 1 fallback tanpa posisi) → semua 4 XML part valid (`xml.dom.minidom`), `python-docx` bisa buka file
  tanpa error, urutan elemen `wps:spPr` (xfrm→prstGeom→noFill→ln) dicocokkan ke skema resmi, posisi `w:jc` di
  dalam `w:pPr` dicocokkan ke skema resmi, estimasi font size hasil `pxHeightToHalfPoint()` masuk akal (10.5pt
  - 13.5pt utk input 22-28px, bukan ukuran ekstrem).
- **Belum ditest user di Word asli** — perlu dicek (percobaan ke-4): posisi tiap paragraf vs posisi asli di
  editor; ukuran font kelihatan wajar (bukan kekecilan/kebesaran); bold & center-alignment judul/heading
  kebaca benar; textbox transparan tidak menimpa/menutupi teks lain yg overlap; performa/waktu export dgn
  banyak paragraf per halaman (tiap paragraf = 1 floating shape, dokumen padat teks bisa jadi ratusan shape -
  belum ada pengujian batas jumlah); device/versi Word lain (LibreOffice, Google Docs) belum dicoba sama
  sekali untuk namespace `wps` khusus Microsoft ini - ada risiko LibreOffice/Google Docs render beda atau
  kurang sempurna karena wps bukan namespace ECMA-376 standar, murni ekstensi Microsoft 2010.

## 9. Cara lanjut di sesi baru
1. Upload PROJECT_NOTES.md ini + hanya file kode yang relevan (Sales: `transaction_content.php` + `ajax/` terkait;
   Logistic: `logistic_content.php` + `list_logistic_movements.php`/`list_logistics.php`; Investor:
   `investor_content.php` + `ajax/investor_api.php`).
2. Jangan upload `NOTES_ARCHIVE.md` kecuali perlu menelusuri alasan keputusan lama (sebut bagian yang dicari).
3. Setelah selesai: tambahkan ≤10 baris ke §8 (atau "Log terbaru"), lalu lebur ke §4–6 kalau sudah dites.