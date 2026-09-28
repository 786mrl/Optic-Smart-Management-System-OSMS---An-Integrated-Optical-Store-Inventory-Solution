# PROJECT_NOTES — lisani_aos

> Versi ringkas (28 Sep 2026): hanya **kondisi sekarang, aturan bisnis, peta file**. Riwayat lengkap
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
- **Menu**: sidebar = Dashboard, Transactions, Logistic, Report. Settings & Exit di dropdown avatar.
  Ganti section lewat `[data-target]` (footer.php). Report masih kosong.

## 4. Model data (kolom penting — cocokkan dengan `DESCRIBE` sebelum menulis query baru)
- **activities**: id, activity_name, cashflow(inflow/outflow/in-out), relative_path (UNIQUE,
  `input/{year}/{deptKey}/{code}/`), created_by, created_at. Nomor kode per departemen+tahun, **tidak
  di-generate ulang saat edit**.
- **customers**: id, year, customer_name (UNIQUE year+name), phone_number (`+628…` tanpa spasi),
  total_inflow (akumulator order), total_outflow (akumulator retur **+ diskon**), total_price_adjustments
  (akumulator diskon, khusus laporan), total_paid, profit.
- **customer_item_prices**: customer_id, logistic_id, price, price_date, unit — harga jual per produk per
  customer (riwayat; dipakai New Order: harga terbaru dengan `price_date ≤ tanggal order`).
- **logistics** (1 baris per activity code, UNIQUE activity_id): primary_qty, primary_unit_label,
  primary_unit_weight_kg, secondary_unit_label/_weight_kg/_ratio_per_primary, incoming_date,
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
  ketiga jenis. **Order/retur/diskon memakai invoice OPEN yang ada; hanya kalau tidak ada dibuat baru**
  (retur/diskon: total negatif).
- **defective_stock_events**: logistic_id, event_type (kini hanya `repaired_to_normal`), qty, created_by.
  Kolom lama `logistics.defective_reference_price` **tidak dipakai lagi** (drop opsional:
  `migration_remove_defective_reference_price.sql`).
- Lain: `logistic_documents` (key activity_id), `transactions`+tabel bantu (Disbursement), company
  documents/bank accounts (Settings).
- Migrasi yang dibutuhkan fitur berjalan (sudah terpakai di server user): `migration_batch_id.sql`,
  `migration_add_source_movement_id.sql`, `migration_defective_and_price_adjustment.sql`.

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
kategori Other, pencatatan pembayaran customer, print invoice, Report.
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
- *Create New Logistic*: pilih activity code (`<select>`, yang sudah punya logistic disabled), qty primary/secondary
  dua arah (yang diisi duluan jadi sumber), satuan dikelola via `manage_packaging_units.php`.

## 7. Peta endpoint (`ajax/`) — kontrak inti
- **Auth/util**: `verify_password.php`, `_require_reverify.php`, `_order_patterns.php`.
- **Activity/Customer/Settings**: `create|update|delete|list|preview_activity_code`, `manage_departments`,
  `create|update|delete|list_customers`, `*_customer_item_price(s)`, `list_priceable_products`,
  `upload|update|delete|download|share|list_documents`, `list|save_bank_account(s)`, `create_disbursement`.
- **Logistic**: `create|update|delete_logistic`, `list_logistics`, `list_logistic_activities`,
  `upload_logistic_document`, `manage_packaging_units?kind=primary|secondary`,
  `list_logistic_movements` (nested tahun/bulan/hari + `total_out/in/adjustment`, `*_normal_qty`,
  `total_taken_qty`, `by_customer`).
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

## 8. Log terbaru (28 Sep 2026)
- Fly window Return History punya 2 tab (Taken Out / Returned In), list scroll, Repair tersembunyi di 0 + qty default.
- Bug "Loading pickup history…": `list_return_price_options.php` di server salah isi → dipulihkan dari GitHub
  + `remaining_adjustable`. `list_logistic_movements.php` dicek benar & terbaru.
- Card Return + Discount digabung di tab Customers (display-only, ≤ 60 detik). Percobaan `join_batch_id` di
  `create_price_adjustment.php` dibatalkan (file itu kembali seperti asli).

## 9. Belum dites / terbuka
- **Belum dites di server user**: `list_return_price_options.php` versi baru (flow Returns end-to-end, termasuk
  Price Adjustment split), penggabungan card Return+Discount, tab Returned In, Repair default qty.
- Data lama order yang sudah terlanjur terpecah (sebelum `batch_id`) tetap terpisah; skrip migrasi belum dibuat.
- Ditunda: filter/pagination Movements, retention `storage/recycle/`, `customers.profit`, Report, pembayaran,
  print invoice, edit/hapus alias & order, aturan stok minus, uppercase server di `manage_packaging_units.php`.
- Opsi: `DROP COLUMN logistics.defective_reference_price` (ireversibel, belum dijalankan).

## 10. Cara lanjut di sesi baru
1. Upload **PROJECT_NOTES.md ini** + hanya file kode yang relevan (untuk Sales: `transaction_content.php` +
   `ajax/` terkait; untuk Logistic: `logistic_content.php` + `list_logistic_movements.php`/`list_logistics.php`).
2. Jangan upload `NOTES_ARCHIVE.md` kecuali perlu menelusuri alasan keputusan lama (sebut bagian yang dicari).
3. Setelah selesai: tambahkan ≤10 baris ke §8, lalu (kalau sudah dites) lebur ke §5/§6.
