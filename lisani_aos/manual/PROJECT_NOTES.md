# PROJECT_NOTES — lisani_aos

> Versi ringkas (1 Okt 2026): hanya **kondisi sekarang, aturan bisnis, peta file**. Riwayat lengkap
> per sesi ada di `NOTES_ARCHIVE.md` (jangan diupload kecuali perlu tahu ALASAN sebuah keputusan lama).
> **Aturan merawat file ini:** tiap pekerjaan baru cukup ±10 baris di bagian "Log terbaru". Kalau sudah
> selesai & dites, lebur ringkasannya ke bagian modul terkait lalu hapus dari log. Jaga total < 600 baris.

## 1. Apa ini
Sub-project tersembunyi ("extended mode") di project **LenZa Optic POS** (`optic_pos/`). Dibuka lewat
**triple-click** tombol login di `login.php`. DB & sesi terpisah total dari optic_pos
(`lisani_aos_db`, koneksi `$lisani_conn`, mysqli). Stack: PHP + MariaDB (XAMPP / Termux), JS vanilla,
tema dark neomorphism.

## 2. Struktur folder
```
optic_pos/
├── login.php, logout.php      (shared; cabang access_mode normal/extended)
├── db_config.php               (milik optic_pos, TIDAK dipakai lisani_aos)
└── lisani_aos/
    ├── index.php, db_config.php, .htaccess
    ├── partials/ header.php, sidebar.php, footer.php
    ├── assets/css/ theme.css (token + komponen), responsive.css (breakpoint)
    ├── transaction_content.php   menu Transactions (Activity Code, Customer List, Input Transaction, Sales)
    ├── logistic_content.php      menu Logistic (Logistic List, Movements, Create New Logistic)
    ├── settings_content.php      Company Documents & Company Bank Accounts
    ├── ajax/                     semua endpoint (lihat §7)
    ├── json_file/                departments.json, packaging units, order_patterns/
    ├── sql/ & migration_*.sql
    └── storage/                  input/…, selling/…, recycle/… (AOS_STORAGE_BASE)
```

## 3. Konvensi & cara kerja (WAJIB)
- **Bahasa**: chat = Indonesia. Kode (identifier, komentar, kolom, nama file) = Inggris. **UI = Inggris.**
- **Cara kerja user**: user menguji sendiri lalu melapor. Ubah kode lama **sesempit mungkin**; serahkan
  **file lengkap siap copy-paste**. Struktur tabel dari user (`DESCRIBE` di phpMyAdmin) — **jangan menebak
  nama kolom**. Kalau butuh file yang belum diupload, **minta dulu**, jangan mengarang isinya.
- Sandbox tidak punya PHP: cek JS dengan Node (`new Function()` per `<script>`), cek kurung/kurawal PHP
  manual. Cek juga jumlah tipe `bind_param` vs variabel (pernah jadi bug).
- Halaman baru: `partials/header.php` → konten → `partials/footer.php` (+ sidebar). Guard wajib:
  `$_SESSION['app'] === 'lisani_aos'` dan `user_id`. Endpoint: 401 + `{ok:false}` kalau sesi habis.
- Tiap `*_content.php` adalah **IIFE sendiri** (tidak berbagi JS/CSS scope) → helper seperti
  `parseNumberInput`, collapsible, `fmtNum` diduplikasi per file. `transaction_content.php` membungkus
  dirinya sendiri dengan `<div class="menu-section" data-section="transactions">` (jangan dibungkus lagi
  di index.php); modal ditaruh sebagai sibling di luar wrapper.
- **Uppercase**: semua input teks bebas di-uppercase (client via class `.input-uppercase` + server
  `strtoupper()`), **kecuali** yang jadi nama folder/path (mis. `key` di `departments.json` tetap huruf kecil).
- **Angka**: input angka pakai koma ribuan (`.input-number-comma`); `parseNumberInput` strip koma. Ke server
  kirim angka bersih.
- **Endpoint pola**: `ini_set('display_errors','0'); ob_start();` + `aos_json()`/`aos_fail()` (buang output
  liar, JSON bersih). Simpan data = satu DB transaction + `FOR UPDATE`. Fitur yang menulis punya
  **`dry_run=1`** (preview, rollback) lalu `dry_run=0` (simpan).
- **Dua pola password** (pilihan sadar per-fitur; kalau fitur destruktif baru dan user tidak menyebut pola,
  **tanya dulu**): (a) `password_verify()` langsung di endpoint aksi (`delete_customer`, `delete_activity_code`);
  (b) `verify_password.php` + `aos_require_recent_reverify()` (`_require_reverify.php`, jendela 120 detik).
  Pola (b) membalas `{success:false,…}` kalau expired → **client cek `res.success === false` DULU, baru `res.ok`.**
- **UI komponen** (pakai yang sudah ada di `theme.css`, jangan bikin style baru per halaman):
  `.card`, `.table-wrapper`, `.form-group>.label+.input`, `.select`, `.btn .btn-primary/-secondary/-danger`,
  `.badge .badge-success/-danger/-warning`, `.tab-group>.tab`, `.modal-overlay>.modal`, `.accordion-*`,
  `.empty-state`. List banyak-field → **Accordion** (bukan tabel). Kalau perlu class baru → tambah ke `theme.css`.
- **Gotcha UI**: (1) ikon Tabler sering tofu (kotak kosong) → langsung pakai **SVG inline**. (2) Collapsible
  `max-height=scrollHeight`: kalau isinya berubah saat terbuka, **re-sync max-height** (hanya jika sedang
  terbuka). (3) Klik card exclusive per parent (`closest('.accordion-list')`). (4) Modal didaftarkan ke
  `flexOverlays` supaya `show()`/`hide()` dan MutationObserver bekerja.
  (5) **Layout/overflow**: `.app` HARUS `minmax(0,1fr)` (bukan `1fr`) dan `.main` `min-width:0` — kalau tidak, konten
  lebar melebarkan SELURUH halaman (scroll horizontal di layar kecil/jendela setengah). Scroll horizontal hanya
  boleh di `.table-wrapper`. Grid `repeat(auto-fit, minmax(Npx,1fr))` pakai `minmax(min(Npx,100%),1fr)`. Lihat §8 (3 Okt).
- **Menu**: sidebar = Dashboard, Transactions, Logistic, Report (Logistic = teks saja, tanpa ikon; menu
  lain pakai ikon Tabler). Settings & Exit di dropdown avatar. Ganti section lewat `[data-target]`
  (footer.php) → `setActive()` juga `dispatchEvent('aos:section-shown', {detail:{target}})` tiap ganti
  section (lihat §8) supaya section yang IIFE-nya cuma fetch sekali bisa listen & refresh live. Report
  sudah berisi Finance Report (lihat §8).

## 4. Model data (kolom penting — cocokkan dengan `DESCRIBE` sebelum menulis query baru)
- **activities**: id, activity_name, cashflow(inflow/outflow/in-out), relative_path (UNIQUE,
  `input/{year}/{deptKey}/{code}/`), created_by, created_at. Nomor kode per departemen+tahun, **tidak
  di-generate ulang saat edit**.
- **customers**: id, year, customer_name (UNIQUE year+name), phone_number (`+628…` tanpa spasi),
  total_inflow (akumulator order), total_outflow (akumulator retur **+ diskon**), total_price_adjustments
  (akumulator diskon, khusus laporan), total_paid, profit.
- **customer_item_prices**: customer_id, logistic_id, price, price_date, unit — harga jual per produk per
  customer (riwayat; dipakai New Order: harga terbaru dengan `price_date ≤ tanggal order`).
- **logistics** (sejak 1 Okt 2026: **banyak baris per activity code**, satu baris = satu produk;
  UNIQUE(activity_id, product_name), activity_id sendiri TIDAK unique lagi): **product_name** (nama
  tampilan produk — `activity_name` di `activities` TIDAK lagi dipakai sebagai nama produk di mana pun),
  primary_qty, primary_unit_label, primary_unit_weight_kg,
  secondary_unit_label/_weight_kg/_ratio_per_primary, incoming_date,
  **remaining_primary_qty** (stok NORMAL saja), **defective_qty** (stok defective di gudang),
  **total_taken_qty** (saldo yang SEDANG di tangan customer, normal+defective; naik saat order, turun saat
  retur, tidak berubah oleh diskon), **defective_taken_qty** (sub-saldo defective di tangan customer).
  Berat total & qty secondary tidak disimpan — dihitung saat `list_logistics.php`.
- **logistic_movements**: logistic_id, customer_id, customer_name, movement_type
  (`out`=taken, `in`=returned, `price_adjustment`=diskon), **stock_source**, movement_date, driver_name,
  police_number, qty_primary_package, price, total_price, invoice_id, created_by, created_at,
  **source_movement_id** (di `in`/`price_adjustment` menunjuk pickup `out` asalnya), **batch_id**
  (identitas satu "order/kartu" = movement id terkecil saat disimpan).
- **invoices**: customer_id, invoice_number, sequence_number, period_month/year, status(open/paid),
  total_amount, paid_amount. Nomor: `NNN/{inv|ret|adj}/laj-{INISIAL}-{n}/{ROMAWI}/{tahun}`; `[n]` unik lintas
  ketiga jenis. **`NNN` = `sequence_number` lanjut per customer (001, 002, …) lintas bulan/tahun, TIDAK reset
  tiap bulan** (sejak 2 Okt 2026, belum dites; invoice lama tidak diubah). **Order/retur/diskon memakai invoice OPEN yang ada; hanya kalau tidak ada dibuat baru**
  (retur/diskon: total negatif). **Kecuali** (1 Okt 2026, belum dites): New Order bisa **manual** minta invoice
  baru walau masih ada yang open (`force_new_invoice`, checkbox "Open as a new invoice") → sekarang **boleh ada
  lebih dari satu invoice OPEN per customer sekaligus**; Returns/Price Adjustment di kasus itu wajib user pilih
  invoice target (`rtInvoiceSelect` → `invoice_id`), kalau tidak dipilih fallback ke perilaku lama (invoice open
  terbaru / buat baru kalau tidak ada).
- **defective_stock_events**: logistic_id, event_type (kini hanya `repaired_to_normal`), qty, created_by.
  Kolom lama `logistics.defective_reference_price` **tidak dipakai lagi** (drop opsional:
  `migration_remove_defective_reference_price.sql`).
- Lain: `logistic_documents` (key activity_id — **tetap milik activity code, dibagi oleh semua produk**
  di dalamnya, bukan per-produk), `transactions`+tabel bantu (Disbursement), company documents/bank
  accounts (Settings).
- Migrasi yang dibutuhkan fitur berjalan (sudah terpakai di server user): `migration_batch_id.sql`,
  `migration_add_source_movement_id.sql`, `migration_defective_and_price_adjustment.sql`,
  `migration_multi_product_logistics.sql` (tambah `logistics.product_name`, isi dari `activity_name`
  lama, ganti UNIQUE(activity_id) → UNIQUE(activity_id, product_name)).
- Alias order WA pindah kunci dari `activity_id` ke **`logistic_id`**: file di `json_file/order_patterns/`
  sekarang bernama `{logistic_id}.json` (bukan `{activity_id}.json`); isi tiap file juga simpan
  `logistic_id` + `activity_id`.

## 5. Aturan bisnis (invarian — jangan dilanggar)
1. **Stok dua bucket independen**: `remaining_primary_qty` = normal, `defective_qty` = defective. Satu-satunya
   jalan defective→normal = aksi manual **Repair to Normal Stock**. Order dari defective memotong
   `defective_qty` (+`defective_taken_qty`), **bukan** `remaining_primary_qty`.
2. **`stock_source`** = satu kolom, dua makna: di baris `out` = bucket yang dipotong; di baris `in` = bucket
   TUJUAN restock (`restock_bucket`). Retur bebas memilih tujuan (Good→normal / Defective→defective)
   **independen** dari asal pickup; kalau pickup asal defective, `defective_taken_qty` tetap dikurangi.
3. **Price adjustment (DISCOUNT)** tidak menggerakkan stok sama sekali (barang tetap di customer): tidak
   mengubah `remaining/defective/total_taken/defective_taken`. Qty-nya **tidak pernah** dijumlah ke
   Taken/Returned; **nilainya** mengurangi Actual. `Total Actual = Ordered − Returned − Discounts`
   (berlaku di tab Customers per customer & per produk, dan di Movements).
4. **Retur per-lot**: tiap retur/diskon dialokasikan ke pickup `out` tertentu (`source_movement_id`),
   divalidasi server: `remaining = qty − Σin`, `remaining_adjustable = qty − Σin − Σprice_adjustment`
   (dua batas terpisah). Harga tiap alokasi default = harga pickup, bisa diedit. Server tidak percaya angka client.
5. **Order**: 1 movement `out` per produk; produk sama tidak boleh mixed `stock_source` dalam satu baris
   (pisah jadi 2 baris); qty > stok bucket → ditolak, **tanpa auto-split**. Harga: `customer_item_prices`
   (price_date ≤ tanggal order) → kalau tak ada, overlay Set Price. Untuk defective: harga **diinput manual**,
   dengan **harga saran** = harga terakhir yang pernah diberikan untuk defective produk itu (dihitung dari
   `logistic_movements`, bukan kolom statis); `customer_item_prices` sengaja **tidak** diisi dari defective.
6. **Update Existing Order**: baris produk yang sama di-UPDATE (qty diganti, bukan dijumlah), produk baru
   di-INSERT dengan `created_at`/`batch_id` order target; stok & invoice/customer disesuaikan pakai
   **selisih**. Tanpa audit trail qty lama (disengaja). Peringatan modal bila driver/police berubah.
7. **Kartu riwayat (tab Customers)** dikelompokkan per `batch_id` (fallback data lama: menit created_at +
   driver + police), di dalam tiap invoice. Return + Discount yang disimpan berselisih ≤ 60 detik
   (tanggal, driver, police sama) **digabung tampil jadi satu kartu** (display-only, `MERGE_WINDOW_MS`).
8. `customers.total_outflow` = akumulator (tidak turun); `total_taken_qty` = saldo (turun saat retur) —
   jangan tertukar. Angka historis "pernah keluar" harus dihitung dari `SUM(movement out)`.
9. Edit logistic: Activity Code tidak bisa diubah; `remaining` dihitung ulang dengan **offset** (bukan rasio):
   `remaining_baru = primary_qty_baru − (primary_qty_lama − remaining_lama)`; negatif → ditolak.
10. Hapus customer/dokumen: isi folder yang tidak kosong **dipindah** ke `storage/recycle/…` (bukan dihapus);
    retention recycle belum dirancang.

## 6. Kondisi tiap modul (sekarang)
**Transactions — Activity Code**: tab Preview (accordion, satu terbuka sekaligus; Edit/Delete; delete pola a)
+ tab Create (nomor & path preview live dari `preview_activity_code.php`; duplikat nama dicek client+server).
Departemen dikelola lewat fly window (`manage_departments.php`); hapus ditolak bila key sudah dipakai.
Fly window pemilihan aksi & password muncul hanya saat section Transactions dibuka.
**Customer List**: 2 tab (list accordion / form 3 field). Phone `+62 8` auto-format & normalisasi server.
Folder `selling/{year}/{nama}/` auto (best-effort). Edit rename folder; Delete pola (a) + recycle.
**Itemized Pricing**: nested accordion di tiap customer (lazy-load), Add Price bebas reverify, Edit/Delete pola (b).
**Input Transaction / Disbursement**: OCR Tesseract.js di browser (semi-otomatis, hasil selalu bisa diedit),
viewer layar penuh, wizard; simpan ke `transactions`. **Belum ada**: list/edit/delete transaksi tersimpan,
kategori Other, pencatatan pembayaran customer, Report.
**Settings**: Company Documents (upload/edit/delete→recycle/share WA/download) & Company Bank Accounts
(autocomplete bank/nama, duplikat per bank, share WA). Edit/Delete/Share pola (b).
**Sales Transaction** (3 tab): 
- *New Order*: tempel pesan WA → `parse_order_message.php` (+alias produk `save_order_alias.php`, pola di
  `json_file/order_patterns/`) → review → (overlay stok normal/defective bila ada produk dengan
  `defective_qty>0`) → Confirm → `create_order.php`. Order lain di tanggal sama → pilihan New Order / Update Existing.
- *Customers*: kartu customer (Total Ordered/Returned/Discounts/Actual, per produk), riwayat per invoice →
  kartu per batch, badge RETURN (+NORMAL/DEFECTIVE) dan DISCOUNT (nilai bertanda minus), "from pickup on …".
- *Returns*: pesan WA retur → tiap produk: qty dialokasikan **FIFO otomatis** ke pickup (bisa dikoreksi, `+ Split`),
  tiap split punya disposition **Good / Defective / Price Adjustment** (adjust: old→new price). Review
  (`dry_run`) → password (pola b) → simpan **berurutan**: `create_return.php` lalu `create_price_adjustment.php`
  (dua transaksi, **tidak atomik**; kegagalan sebagian dilaporkan ke user).
**Logistic** (hanya departemen `dates`): 
- *Logistic List*: accordion per activity code, Edit/Delete (pola b), Add Document, baris Remaining (fly window
  ledger normal) dan **Defective Stock** → fly window "Defective Stock — Return History": tombol/tab **Taken Out**
  (`out`+defective) dan **Returned In** (`in`+defective), list scroll (max-height 260px); form **Repair to Normal
  Stock** hanya tampil bila `defective_qty>0`, qty default = seluruh sisa (bisa diedit). Import Document
  (Shipper/Custom/Consignee) collapsible.
- *Movements*: per activity code → tahun → bulan → hari; total Taken/Returned/Discount/Actual di tiap level.
  **Card Validation** per activity code (3 badge Valid/Invalid): (1) Actual Taken gabungan vs `total_taken_qty`;
  (2) Actual Taken bucket normal vs `primary_qty − remaining_primary_qty`; (3) rekonsiliasi per customer vs tab
  Customers. Detail disembunyikan bila Valid. Baris defective punya badge DEFECTIVE.
- *Create New Logistic*: pilih activity code (`<select>` — **sejak 1 Okt 2026 TIDAK disabled lagi** walau
  sudah punya produk; opsi menampilkan daftar produk yang sudah ada), lalu **isi satu atau lebih blok
  produk** (tombol **+ Add Product**): tiap blok = nama produk + qty primary/secondary dua arah sendiri
  (yang diisi duluan jadi sumber), satuan dikelola via `manage_packaging_units.php`. Incoming Date & Import
  Document dipakai bersama oleh semua produk dalam satu kali simpan (satu activity code, satu `incoming_date`,
  satu set dokumen). Satu kali Save → `create_logistic.php` menyimpan semua baris `logistics` sekaligus
  dalam satu transaction (all-or-nothing). Nama produk wajib unik dalam satu activity code.

## 7. Peta endpoint (`ajax/`) — kontrak inti
- **Auth/util**: `verify_password.php`, `_require_reverify.php`, `_order_patterns.php`.
- **Activity/Customer/Settings**: `create|update|delete|list|preview_activity_code`, `manage_departments`,
  `create|update|delete|list_customers`, `*_customer_item_price(s)`, `list_priceable_products`,
  `upload|update|delete|download|share|list_documents`, `list|save_bank_account(s)`, `create_disbursement`.
- **Logistic**: `list_logistics`, `list_logistic_activities` (sekarang balikin `has_logistic` +
  `existing_products[]` per activity code, tidak lagi dipakai untuk disable opsi), `upload_logistic_document`,
  `manage_packaging_units?kind=primary|secondary`, `list_logistic_movements` (nested tahun/bulan/hari +
  `total_out/in/adjustment`, `*_normal_qty`, `total_taken_qty`, `by_customer`).
  - **`create_logistic.php`** (diubah 1 Okt 2026) POST `activity_id, incoming_date, products` (products =
    JSON array `[{product_name, primary_qty, primary_unit_label, primary_unit_weight_kg,
    secondary_unit_label, secondary_unit_weight_kg, secondary_ratio_per_primary}]`, maks 50 produk per
    panggilan) → satu transaction, insert semua baris sekaligus → `{ok, data:{ids, count}}`. Tolak kalau
    `product_name` dobel dalam satu activity code (client + `uniq_activity_product` di DB).
  - **`update_logistic.php`** sekarang juga terima & validasi `product_name` (unik per activity code,
    kecuali baris itu sendiri).
  - **`delete_logistic.php`**: dokumen (`logistic_documents`) & folder `import_documents/` hanya ikut
    dihapus/dipindah kalau produk yang dihapus adalah **produk terakhir** di activity code itu — produk
    lain yang berbagi activity code tidak kehilangan dokumennya.
- **Sales**: `parse_order_message`, `save_order_alias`, `check_existing_orders`, `create_order`, `update_order`,
  `list_customer_orders` (per invoice movements + `batch_id`, `source_movement_id`, `discount_value` per produk).
- **`list_return_price_options.php`** GET `customer_id, logistic_id` → `{ok,data:{available,total_taken,
  total_returned,movements:[{movement_id,movement_date,qty,price,remaining,remaining_adjustable,disabled,label}]}}`
  (terbaru dulu; `available = total_taken − total_returned`). ⚠ Pernah tertimpa isi `list_logistic_movements.php`
  (gejala: "Loading pickup history…" tak selesai) — kalau terulang, cek file ini dulu.
- **`create_return.php`** POST `customer_id, return_date, driver_name, police_number, dry_run,
  items:[{logistic_id, allocations:[{source_movement_id, qty, price, restock_bucket}]}]` →
  `{ok, ret:{invoice, items, grand_total, movement_ids}}`; code `not_returnable` bila melebihi remaining.
- **`create_price_adjustment.php`** POST `customer_id, adjustment_date, driver_name, police_number, dry_run,
  items:[{logistic_id, allocations:[{source_movement_id, qty, old_price, new_price}]}]` → `{ok, adj:{…,
  grand_discount, movement_ids}}`; code `not_adjustable`.
- **`manage_defective_stock.php`**: GET `action=history` (Taken Out) & `action=return_history` (Returned In),
  `logistic_id` → `{data:{product_name, unit_label, defective_qty, defective_taken_qty, history:[{customer_name,
  movement_date, qty, price, total_price}]}}`; POST `action=repair` `logistic_id, qty` (≤ defective_qty).

## 8. Log terbaru (1–4 Okt 2026)
- **Print Invoice / PDF (4 Okt 2026, belum dites user)**: tombol **Print** di header tiap invoice (tab Customers,
  `buildStInvoiceItem`) → tab baru `ajax/print_invoice.php?invoice_id=N` (halaman HTML A4 bahasa Indonesia;
  PDF = Print → Save as PDF dari browser, TANPA library PHP). Isi: header gambar, customer, meta invoice, tabel
  item per movement (order / RETUR / POTONGAN HARGA, urut tanggal), ringkasan (Total = `invoices.total_amount`,
  Sudah Dibayar, Sisa), terbilang, riwayat pembayaran, rekening, ttd. **Rekening**: toolbar halaman (UI Inggris)
  punya checkbox per rekening dari `json_file/bank_accounts.json` (field: id, bank_name, account_number,
  account_name, currency, swift_code, address); boleh >1, pilihan diingat di localStorage
  `aos_invoice_bank_selection` (default semua tercentang). Aset baru: `assets/img/invoice_header.png`,
  `invoice_signature.png`. Penanda tangan = konstanta `PI_SIGNER_NAME`/`PI_SIGNER_ROLE` di atas file. Read-only,
  tanpa migration. Layout sudah diuji render (Chromium) dengan data contoh; PHP-nya belum dijalankan (sandbox tanpa PHP).
- **Fix "Connection error" saat Return/Discount kena kredit (2 Okt 2026, belum dites ulang)**: gejala — semua
  invoice customer sudah PAID, lalu ada barang di-return → Review/Save di tab Returns muncul "Connection error".
  Akar masalah (disimpulkan dari kode `transaction_content.php`): server sudah membalas `ret.invoice`/`adj.invoice`
  = `null` + `ret.credit`/`adj.credit` (`balance_after`) untuk kasus kredit (§8 "Kredit customer"), tapi client
  masih mengasumsikan invoice selalu ada → TypeError di dalam `.then`, yang ketangkap `.catch` dan ditampilkan
  sebagai "Connection error". Perbaikan hanya di client: `showRtConfirm()` (baris Invoice → "None — goes to
  customer credit", total akhir → "Credit Balance After") dan ringkasan sukses di `rtSaveReturn()` (label
  "Customer credit" kalau invoice null). **Gotcha**: `.catch` di `rtReview`/`rtSaveReturn` menelan SEMUA error JS
  sebagai "Connection error" — kalau pesan itu muncul padahal jaringan normal, curigai TypeError di handler
  `.then`, bukan koneksi/endpoint.
- **Add Payment & penomoran invoice (2 Okt 2026, belum dites)**: (1) `buildStInvoiceItem`
  (`transaction_content.php`): tombol "+ Add Payment" kini hanya dibuat kalau `inv.status === 'open'`;
  invoice PAID tidak punya tombol itu (Apply Credit sudah dari dulu hanya untuk open). (2) `create_order.php`:
  `sequence_number` invoice baru = `MAX(sequence_number)` **per customer** + 1 (dulu per customer+bulan+tahun,
  jadi order bulan baru kembali ke 001). Hanya `create_order.php` yang membuat invoice baru — return/diskon
  tanpa invoice open kini masuk `credit_balance`, jadi `create_return.php`/`create_price_adjustment.php`
  tidak perlu diubah. **Asumsi**: user menginginkan urutan lanjut terus per customer (bukan reset bulanan);
  kalau ternyata tidak, kembalikan filter `period_month/period_year` di query MAX.
- **Live refresh antar section** (belum dites user): dulu tiap `*_content.php` cuma fetch sekali saat load,
  sementara ganti menu lewat `footer.php` cuma show/hide div (bukan reload) → pindah ke Logistic setelah
  input order di Transactions tetap nampilin data lama sampai refresh manual. Perbaikan: `footer.php`
  (`setActive()`) sekarang `document.dispatchEvent(new CustomEvent('aos:section-shown', {detail:{target}}))`
  tiap kali section diganti. `logistic_content.php` listen event ini, kalau `target==='logistic'` re-fetch
  tab yang sedang aktif (`list`→`loadLogisticList`, `movements`→`loadLogisticMovements`,
  `create`→`loadActivityCodes`). Pola event ini generik — kalau menu lain nanti butuh live refresh juga,
  tinggal tambah listener serupa di file masing-masing, tidak perlu ubah `footer.php` lagi.
- **Hapus ikon SVG "Logistic" di sidebar**: tombol Logistic (`sidebar.php` desktop + `bottom-nav` mobile)
  sekarang teks saja ("Logistic"), tanpa `<svg>`/`<i>` di depannya — konsisten dipermintaan user, item sidebar
  lain (Dashboard/Transactions/Report) tetap pakai ikon Tabler seperti biasa.
- **Upload bukti pembayaran invoice** (belum dites): tombol "+ Add Payment" di tiap invoice (Sales Transaction >
  Customers), sistemnya dicontoh dari Disbursement — viewer+OCR Tesseract.js dipakai BERSAMA (satu instance,
  dipilah lewat `activeCapGroup`/`data-cap-group`, lihat `transaction_content.php`), bukan duplikat. Satu
  invoice bisa dicicil berkali-kali → tabel baru **`invoice_payments`** (`migration_invoice_payments.sql`,
  **belum dijalankan user**), endpoint baru `create_invoice_payment.php` (update `invoices.paid_amount`/
  `status`/`paid_at` + `customers.total_paid` dalam 1 transaction + `FOR UPDATE`) dan
  `view_invoice_payment_proof.php` (stream file). `list_customer_orders.php` ikut invoice.payments[].
  **Asumsi perlu dikonfirmasi**: overpayment DITOLAK (bukan di-clamp). Folder bukti ikut persis konvensi
  `create_customer.php` (`selling/{customers.year}/{customer_name, sanitized+lowercase}/`), subfolder
  `payments/` di dalamnya.
- **Buka invoice baru walau masih ada yang open** (belum dites): diminta user karena kadang customer sengaja
  mau invoice terpisah untuk pengambilan barang tertentu. Diputuskan **manual** (bukan ditebak otomatis dari
  produk/driver/tanggal — itu soal maksud bisnis customer, bukan sesuatu yang bisa disimpulkan dari data).
  `create_order.php`: param baru `force_new_invoice` (checkbox "Open as a new invoice" di New Order), `=1`
  skip pencarian invoice open sepenuhnya → selalu buat baru. `create_return.php` & `create_price_adjustment.php`:
  param baru `invoice_id` (dropdown `rtInvoiceSelect` di tab Returns, diisi dari invoice `status==='open'` milik
  customer terpilih via `list_customer_orders.php`); kalau diisi, divalidasi `WHERE id=? AND customer_id=? AND
  status='open'` lalu dipakai; kalau kosong, fallback ke perilaku lama (auto pick invoice open terbaru).
- **Kredit customer (sudah lunas, lalu return/turun harga)** (belum dites): kalau retur/diskon tidak ada
  invoice open untuk dikurangi (termasuk kasus satu-satunya invoice customer itu sudah PAID), sistem **tidak
  lagi bikin invoice minus baru** — nilainya masuk ke `customers.credit_balance` (kolom baru), pergerakan
  barang tetap tercatat tapi `invoice_id = NULL` (masuk grup "(No invoice)" yang sudah ada di
  `list_customer_orders.php`). Kredit **tidak pernah dipakai otomatis** — dua tombol manual di tab Customers:
  **Refund** (`create_refund.php`, tabel baru `invoice_refunds`, bukti opsional, kurangi `credit_balance` &
  `total_paid`) dan **Apply Credit** per invoice open (`apply_customer_credit.php`, tercatat di
  `invoice_payments` method "CREDIT BALANCE" tanpa file, kurangi `credit_balance` saja — `total_paid` TIDAK
  ikut berubah karena bukan uang baru masuk). `invoice_payments.proof_path`/`proof_original_name` jadi
  nullable (migration) untuk menampung baris tanpa file ini.

- **Perbaikan Record Payment invoice (dikerjakan, BELUM DITES user)**: (1) **Bug OCR amount** diperbaiki —
  fungsi baru `parseOcrAmount()` di `transaction_content.php` (disambiguasi `.`/`,` dari teks OCR itu
  sendiri: dua simbol dipakai → yang terakhir = desimal; satu simbol diikuti 3 digit atau dipakai berkali
  → ribuan). Dipakai di 3 tempat yang baca hasil OCR angka: `capAmount`, `capExchangeRate` (Disbursement),
  `payCapAmount` (payment) — `parseNumberInput` lama (koma=ribuan, titik=desimal) TETAP dipakai apa adanya
  untuk ketikan manual (`input-number-comma`), jangan disatukan, beda konvensi sumbernya. (2) Field
  "Payment Method / Bank" di form Add Payment diganti 6 field (pola persis disamakan dengan Disbursement):
  **source bank, source account number, source account name, destination bank, destination account
  number, destination account name** — semua text uppercase hasil OCR, editable; destination bank/account
  number dikasih `<datalist>` kosong (id `payCapDestBankList`/`payCapDestAccountNumberList`) tapi **belum
  diisi** (butuh kontrak endpoint list bank accounts, lihat §9). (3) **Notes auto-fill** jalan via
  `recalcPayCapNotes()`: dibandingkan ke **outstanding saat form dibuka** (`total_amount − paid_amount`,
  bukan total invoice) — kalau `inv.payments` kosong DAN amount ≥ outstanding → `Payment for invoice
  [no]`; selain itu (cicilan) → `First/Second/… payment for invoice [no]` berdasar
  `inv.payments.length + 1`. Auto-fill berhenti begitu user ngetik manual di Notes (flag
  `payCapNotesEdited`). (4) **DB**: kolom `payment_method` di `invoice_payments` **diganti** (bukan
  ditambah) jadi 6 kolom di atas — lihat `migration_invoice_payments_bank_fields.sql` (ALTER, destruktif,
  **belum dijalankan user**); `create_invoice_payment.php` sudah disesuaikan (validasi panjang per kolom +
  INSERT 14 kolom). Baris riwayat Payments di tab Customers sekarang nampilin "destination bank ·
  destination account name" (ganti `payment_method` yang sudah tidak ada).

- **Record Payment — revisi lanjutan (dikerjakan, belum dites)**: (1) **Source Account Number dihapus**
  total dari fitur payment (form, JS, `create_invoice_payment.php`, migration, `list_customer_orders.php`)
  — kalau sempat sudah menjalankan versi migration SEBELUMNYA yang masih punya kolom ini, ada instruksi
  `DROP COLUMN` tambahan di awal `migration_invoice_payments_bank_fields.sql`, jalankan itu dulu. (2)
  **Bugfix: Total Paid tidak update setelah Save Payment** — akar masalahnya `btnPayCapSave` sukses cuma
  manggil `backFromPayCapture()` (balik ke tab Customers) tanpa refetch; `refreshStCustomerCard(customerId)`
  yang sudah dipakai Refund/Apply Credit TIDAK pernah dipanggil di jalur payment. Fix: `openPayCaptureView`
  sekarang terima param `customer` (dikirim dari `buildStInvoiceItem`) → disimpan ke `payCapCustomerId` →
  dipanggil `refreshStCustomerCard(payCapCustomerId)` setelah save sukses. (3) **Baris baru "Total
  Balance"** di kartu Customer (`renderStCustomerDetail`) = Total Actual − Total Paid (sisa belum dibayar
  level customer, bisa negatif kalau overpaid — kelebihannya ada di Credit Balance, bukan di sini).

- **Report (2 Okt 2026, belum dites)**: menu Report diisi — `report_content.php` (baru, di-include dari
  `index.php` menggantikan placeholder lama) berisi tab **Finance Report** (ringkasan saldo per rekening,
  Chart.js: inflow/outflow per bulan, saldo kumulatif, saldo per rekening; ledger gabungan + Export CSV;
  snapshot piutang/credit) dan **Investor Report** (placeholder kosong). Endpoint baru `ajax/get_finance_report.php`
  menggabungkan outflow (`transactions` category=disbursement), inflow (`invoice_payments`), outflow refund
  (`invoice_refunds`), dicocokkan ke rekening di `bank_accounts.json` via bank_name+account_number (bukan FK).
  Saldo mulai dari 0 (keputusan user, bukan saldo awal manual). **Migration baru belum dijalankan**:
  `migration_invoice_refunds_bank_fields.sql` (3 kolom nullable di `invoice_refunds`: source_bank,
  source_account_number, source_account_name) — `create_refund.php` dan modal Refund (`transaction_content.php`)
  sudah diupdate untuk field ini (opsional, ada pilihan "No account / no transfer proof" karena refund kadang
  cuma validasi manajemen tanpa transfer). **Pola tab/badge** (`.tab-group`, `.tab`, `.badge-success/danger`)
  dikonfirmasi dari `transaction_content.php`, bukan tebakan. Event `aos:section-shown` (dipakai buat refresh
  data tiap pindah ke tab Report) sudah dicek ke `footer_php.txt`/`sidebar_php.txt` — benar ada
  (`dispatchEvent` di `setActive()`, `data-target="report"` match `data-section="report"`), jadi load data
  finance report via event asli ini, bukan `MutationObserver` lagi. Sudah dites sebagian: migration sudah
  dijalankan user, query PHP sukses (200 di Network tab). Tapi **`chart.umd.min.js` dari cdnjs.cloudflare.com
  gagal dimuat** (diblokir jaringan user) — tadinya bikin SELURUH report gagal tampil ("Connection error")
  karena error di `renderCharts()` tidak tertangkap. Sudah diperbaiki: dibungkus try/catch supaya
  ringkasan/ledger/piutang tetap tampil walau grafik gagal. **Belum dites ulang** setelah fix ini — kalau
  jaringan user terus memblokir cdnjs, pertimbangkan host Chart.js lokal di server.

- **Fix scroll horizontal halaman Report (3 Okt 2026, belum dites user)**: gejala — di layar kecil (HP, tablet,
  jendela dibagi dua) SELURUH halaman Report bisa scroll ke samping; seharusnya hanya tabel (Ledger, AP/Receivable).
  **Akar masalah global**, bukan di Report: `.app { grid-template-columns: … 1fr }` (`theme.css` + 2 aturan di
  `responsive.css`) = `minmax(auto,1fr)` → kolom konten melebar mengikuti isi terlebar. Fix: `minmax(0,1fr)` di
  ketiga tempat + `.main{min-width:0}`. Di `report_content.php` (hanya blok `<style>`): containment section/card
  (`min-width:0`, `overflow-x:clip` di card terluar), grid `min(Npx,100%)`, kartu grafik `position:relative;
  min-width:0`, `.table-wrapper` `overflow-x:auto` di SEMUA lebar (bukan cuma ≤768px), Ledger `min-width:640px`,
  tabel piutang `420px`, `th/td{width:auto}`. Catatan: `responsive.css` mengubah SEMUA `table` jadi tampilan kartu
  di <1024px (bukan 768px) dan memaksa `td{width:100%}` — tabel yang harus tetap tabel perlu override `display`
  eksplisit (sudah dilakukan untuk tabel Report).

## 9. Belum dites / terbuka
- **PENGINGAT — audit scroll horizontal menu lain**: perbaikan `.app`/`.main` (3 Okt) berlaku GLOBAL, jadi
  Transactions, Logistic, Settings, Dashboard sekarang ikut "tertahan" di lebar layar. Tes tiap menu di 3 ukuran
  (HP ≤767px, tablet 768–1023px, jendela setengah layar ~900–1000px). Cek: (1) halaman tidak scroll ke samping;
  (2) tabel/konten lebar yang dulu "lega" sekarang terpotong → bungkus `.table-wrapper` + `overflow-x:auto`
  (atau `min-width:0` di parent flex/grid-nya); (3) tabel yang seharusnya tetap kolom malah jadi kartu di
  <1024px (aturan `responsive.css`). Tiap temuan: catat menu + ukuran layar, lalu tulis hasilnya di sini.
  Daftar sudah dicek: Report ☐ dites user · Transactions ☐ · Logistic ☐ · Settings ☐ · Dashboard ☐.
- **Record Payment (lihat §8), lanjutan**: `list_customer_orders.php` sudah diupdate — `invoice.payments[]`
  sekarang pakai `aosColumnExists(..., 'source_bank')` (pola sama dengan `$hasStockSource`) buat tahu
  migrasi bank fields sudah jalan atau belum: kalau sudah, SELECT 6 kolom bank baru; kalau belum, fallback
  SELECT `payment_method` lama lalu dipetakan ke `destination_bank` saja (field lain `''`) supaya tampilan
  tidak `undefined` di kedua kondisi. **Datalist destination bank/account** (`payCapDestBankList`/
  `payCapDestAccountNumberList`) sekarang diisi dari `ajax/list_bank_accounts.php` (→
  `json_file/bank_accounts.json`), tapi **nama field JSON-nya ditebak** (dicoba beberapa kemungkinan:
  `bank_name`/`bank`, `account_number`/`number`, `account_name`/`holder_name`/`name`) karena isi
  `bank_accounts.json` yang sebenarnya belum dilihat — **tolong tes**: kalau datalist destination bank
  kosong atau salah nampilin teks, laporkan isi `bank_accounts.json` (atau `settings_content.php` bagian
  Company Bank Accounts) biar nama field di `pickField()` (`transaction_content.php`) dikoreksi.
- **Belum dites**: Logistic List & Movements dengan >1 produk dalam satu activity code (tampilan masih per
  baris produk, BELUM dikelompokkan di bawah satu header activity code — kalau user merasa berantakan,
  pertimbangkan pengelompokan). Edit/Delete logistic untuk kasus multi-produk (delete bukan produk
  terakhir → dokumen activity code harus tetap ada, lihat §7).
- Fitur pembayaran bersama (disebut user saat desain form Create New Logistic) — **belum dibangun**,
  placeholder saja di form (Incoming Date & dokumen sudah dibagi, payment belum ada kolom/fiturnya sama sekali).
- `list_return_price_options.php` versi baru (flow Returns end-to-end, termasuk Price Adjustment split),
  penggabungan card Return+Discount, tab Returned In, Repair default qty — carry-over dari sesi 28 Sep,
  belum dikonfirmasi user.
- Data lama order yang sudah terlanjur terpecah (sebelum `batch_id`) tetap terpisah; skrip migrasi belum dibuat.
- Ditunda: filter/pagination Movements, retention `storage/recycle/`, `customers.profit`, pembayaran,
  edit/hapus alias & order, aturan stok minus, uppercase server di `manage_packaging_units.php`.
- Opsi: `DROP COLUMN logistics.defective_reference_price` (ireversibel, belum dijalankan).

## 10. Cara lanjut di sesi baru
1. Upload **PROJECT_NOTES.md ini** + hanya file kode yang relevan (untuk Sales: `transaction_content.php` +
   `ajax/` terkait; untuk Logistic: `logistic_content.php` + `list_logistic_movements.php`/`list_logistics.php`).
2. Jangan upload `NOTES_ARCHIVE.md` kecuali perlu menelusuri alasan keputusan lama (sebut bagian yang dicari).
3. Setelah selesai: tambahkan ≤10 baris ke §8, lalu (kalau sudah dites) lebur ke §5/§6.