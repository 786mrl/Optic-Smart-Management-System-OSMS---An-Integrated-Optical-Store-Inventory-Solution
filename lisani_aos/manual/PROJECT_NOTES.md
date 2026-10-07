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
    ├── settings_content.php       Company Documents & Company Bank Accounts
    ├── report_content.php         Report: Finance Report
    ├── investor_content.php       Investor: Investors, Investment, Report, Profit Payment & Rolled Capital (lihat Log terbaru)
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
- **Menu**: sidebar = Dashboard, Transactions, Logistic (teks saja, tanpa ikon), Report; menu lain pakai ikon Tabler.
  Settings & Exit di dropdown avatar. Ganti section lewat `[data-target]` (footer.php → `setActive()`), yang juga
  `dispatchEvent('aos:section-shown', {detail:{target}})`. Section yang fetch-nya cuma sekali bisa listen event ini
  untuk refresh live (pola generik, tidak perlu ubah footer.php).

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
- **invoice_refunds**: refund dari kredit; kolom source_bank, source_account_number, source_account_name (nullable, opsional).
- **defective_stock_events**: logistic_id, event_type (saat ini hanya `repaired_to_normal`), qty, created_by.
- Lain: `logistic_documents` (key activity_id, dibagi semua produk dalam activity code), `transactions` + tabel bantu
  (Disbursement), company documents & bank accounts (Settings).
- `logistics.defective_reference_price` sudah tidak dipakai (drop opsional, lihat §8).

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
Auto-isi rekening setelah scan nomor rekening: lihat Log terbaru (7 Okt 2026).

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
  destination diisi dari `ajax/list_bank_accounts.php`. Notes auto-fill via `recalcPayCapNotes()`, dibandingkan ke
  outstanding saat form dibuka (`total_amount − paid_amount`); berhenti begitu user mengetik manual di Notes.
  Overpayment ditolak (bukan di-clamp). Bukti di `selling/{year}/{customer}/payments/`. Setelah Save, kartu customer
  di-refresh (`refreshStCustomerCard`).
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

**Report**: `report_content.php` tab Finance Report: ringkasan saldo per rekening, Chart.js (inflow/outflow per bulan,
saldo kumulatif, saldo per rekening), ledger gabungan + Export CSV, snapshot piutang/credit. Data dari
`ajax/get_finance_report.php`: outflow (`transactions` category=disbursement), inflow (`invoice_payments`), outflow refund
(`invoice_refunds`), dicocokkan ke `bank_accounts.json` via bank_name+account_number (bukan FK). Saldo mulai 0 (keputusan
user). Refresh lewat event `aos:section-shown`. Investor Report = placeholder kosong. Lebar: Ledger `min-width:640px`,
tabel piutang 420px, `.table-wrapper` `overflow-x:auto` di semua lebar.

## 7. Peta endpoint (`ajax/`)
- **Auth/util**: `verify_password.php`, `_require_reverify.php`, `_order_patterns.php`, `_invoice_pdf.php`.
- **Activity/Customer/Settings**: `create|update|delete|list|preview_activity_code`, `manage_departments`,
  `create|update|delete|list_customers`, `*_customer_item_price(s)`, `list_priceable_products`,
  `upload|update|delete|download|share|list_documents`, `list|save_bank_account(s)`, `create_disbursement`.
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

### Log terbaru (belum dites di server; lebur ke §4–7 setelah dites, lalu hapus dari sini)
- **7 Okt 2026, Menu Investor baru** (`investor_content.php` + `ajax/investor_api.php`, satu endpoint untuk semua aksi):
  - DB baru: `investors`, `investor_deposits` (setoran, IDR/valas + kurs manual, bisa berkali-kali),
    `investor_support_expenses` (pengeluaran non-project: return_capital/aid/other, mengurangi ICU),
    `investor_activity_allocations` (investor→activity code, % dari ICU, total per investor ≤100%, dicek di PHP),
    `investor_activity_settings` (pdp **per activity code**), `investor_profit_payments` (bisa berkali-kali).
    Migrasi: `migration_investor.sql` lalu `migration_investor_v2.sql` (yang kedua DROP `transaction_activities` —
    sempat dibuat untuk menautkan manual pengeluaran project ke activity, ternyata berlebihan karena fitur
    Category Disbursement paralel sudah menambah `transaction_disbursements.activity_id`, 1 transaksi → 1 activity,
    `transaction_id` UNIQUE di situ).
  - Total cost per activity = `SUM(transactions.final_amount_idr) JOIN transaction_disbursements` (category=disbursement).
    Dana investor terpakai per project = Σ(%alokasi × ICU investor), dibatasi ≤ total cost (kalau total alokasi
    melebihi cost, proporsinya di-scale turun). Sisanya = company additional contribution.
  - Rumus laporan: gross = sales aktual (dari `logistic_movements`, out−in−price_adjustment) − cost; zakat 2.5% dari
    gross (0 bila ≤0); net = gross−zakat; distribusi investor = net × pdp (per activity); rasio tiap investor
    **per project** (bukan gabungan semua project); TP = Σ profit semua project; ICU = setoran − non-project;
    rolled capital = (TP−PP)+ICU. Rugi (net<0) ditandai badge "RUGI", tetap dihitung apa adanya — **belum ada
    kebijakan otomatis**, menunggu keputusan manajemen.
  - UI 4 tab: Investors (CRUD + list ringkas semua angka) / Investment (modal pilih investor dulu, card setoran+non-
    project, card alokasi ke activity + tabel pengeluaran project **read-only**, auto dari Category Disbursement) /
    Report (usage per investor, sales per project, profit distribution + share per investor) / Profit Payment &
    Rolled Capital. Hapus pakai password pola (a) langsung (keputusan user, bukan reverify 120 detik).
  - **8 Okt 2026, fix**: tombol Edit/Delete di baris tabel (semua tabel Investor) diseragamkan, class baru
    `.inv-action-btn` (`inline-flex` center, `width:72px` tetap, padding+font-size kecil, dibungkus
    `.inv-row-actions` kalau >1 tombol per baris) — sebelumnya ikut ukuran `.btn` default (besar, teks Delete tidak
    center). Label tombol rename investor di Investor List: "Rename" (sempat "Edit Investor Name", kepanjangan).
  - **8 Okt 2026, fitur**: semua `<th>` di modul Investor (64 kolom, 11 tabel) diberi `data-help="…"`; klik judul
    kolom mana pun → fly window `invColHelpOverlay` (judul = teks header, isi = penjelasan singkat artinya, mis.
    ICU, TP/PP, Rolled Capital, Fund Used vs Share %). Event delegation satu listener di `root`, bukan per-`<th>`.
    Pola ini generik, bisa dipakai modul lain kalau mau (`th[data-help]` + 1 modal + 1 listener).
  - **8 Okt 2026, fix**: tabel di `investor_content.php` sempat ikut berubah jadi kartu di <1024px (gotcha #7,
    `responsive.css` global memaksa semua `<table>`→card + `td{width:100%}`). Ditambah override khusus di dalam
    `.inv-scroll` (`display:revert` untuk table/thead/tbody/tr/th/td) supaya tabel Investor tetap tabel di semua
    lebar layar; scroll horizontal tetap lewat `.inv-scroll{overflow-x:auto}` seperti modul lain.
  - **8 Okt 2026, bugfix**: tab Investment → card Investor Fund Utilization → tabel "Project Expenses" selalu
    kosong walau alokasi investor↔activity berhasil. Penyebab: di `inv_compute()` (`investor_api.php`), query
    `$expensesByActivity` (dari `transaction_disbursements`) ditulis **setelah** loop yang membangun `$activityOut`
    tapi dipakai **di dalam** loop itu (`'expenses' => $expensesByActivity[$aid] ?? []`) — variabel belum ada saat
    dipakai, jadi selalu jatuh ke `[]`. PHP tidak error (undefined var + null coalescing diam-diam jadi array
    kosong), makanya lolos cek kurung/kurawal manual sebelumnya. Fix: pindahkan query `$expensesByActivity` ke atas
    loop `$activityOut`. Pelajaran: kalau sebuah array dipakai dengan `?? []` di tengah loop, **cek urutan
    deklarasinya**, bukan cuma isi nilainya — bug ini tidak akan ketahuan dari membaca query SQL-nya sendiri.
  - **8 Okt 2026, fitur**: tabel "Project Expenses" (tab Investment) dapat baris `<tfoot>` Total (jumlah kolom
    Amount dari baris yang tampil, dihitung di JS saat render, bukan dari server). Reset ke 0.00 saat belum ada
    investor dipilih.
  - **8 Okt 2026, ubah kolom**: tabel "Project Expenses" (tab Investment) kolom Notes diganti jadi **Category**
    (`disbursement_categories.category_name`, LEFT JOIN by `transaction_disbursements.category_id`, fallback "-"
    kalau NULL) dan **Description** (`transaction_disbursements.transaction_purpose`). `transactions.notes` sudah
    tidak dipakai di tabel ini.
  - Belum dites di server sama sekali (menunggu pengujian dari fix-fix di atas). Perlu dicek juga: tanda
    `total_price` pada `price_adjustment` (asumsi positif, dikurangkan); kelas
    `.input-uppercase`/`.input-number-comma` jalan tanpa `flexOverlays` untuk 2 modal baru (`invPickerOverlay`,
    `invPwOverlay`).
- **6 Okt 2026, Kategori Disbursement** (hanya label pengelompokan uang keluar; tidak memecah total/saldo/chart):
  - DB: tabel baru `disbursement_categories` (id, category_name UNIQUE, created_by, created_at), seed PURCHASE PAYMENT /
    CLEARANCE FEES / OPERATIONAL EXPENSES; kolom baru `transaction_disbursements.category_id` (NOT NULL, indeks, tanpa FK,
    validasi di PHP). Migrasi: `migration_disbursement_categories.sql` (jalankan sekali).
  - Wizard step 3 (Details): dropdown Category **wajib**, di antara Cashflow Type dan Transaction Purpose. Opsi
    "+ Add new category…" (uppercase; nama yang sudah ada dipakai ulang, bukan error). Tombol **Manage** = Rename/Delete
    **tanpa password** (keputusan user); Delete ditolak bila kategori sudah dipakai transaksi. Overlay
    `disbManageCategoryOverlay` terdaftar di `flexOverlays`. Berlaku untuk semua departemen.
  - Endpoint baru: `list|save|update|delete_disbursement_category.php`. `create_disbursement.php` kini wajib `category_id`.
  - Finance Report: `get_finance_report.php` menambah field `category` di ledger (disbursement = nama kategori,
    **invoice payment = tetap `SALES PAYMENT`**, refund = null). `report_content.php`: kolom Category di ledger + Export CSV,
    `min-width` ledger 640 → 760px. Nominal ledger: inflow hijau, outflow merah, negatif pakai kurung `(Rp …)` bukan minus
    (helper `fmtIDRParen`, hanya di ledger; kartu ringkasan tetap `fmtIDR`; CSV tetap angka mentah + kolom Type).
  - Belum ada: edit kategori transaksi yang sudah tersimpan (list/edit/delete transaksi tersimpan memang belum dibangun).
- **7 Okt 2026, Auto-isi rekening dari OCR** (tanpa migration; hanya `transaction_content.php` + `save_bank_account.php`):
  - Setelah OCR membaca nomor rekening, dicocokkan ke `bank_accounts.json` (via `list_bank_accounts.php`, diambil segar tiap
    pencarian). Disbursement = **source** account number → isi Source Bank, Source Account Name, Currency (+ exchange rate
    muncul bila non-IDR). Add Payment = **destination** account number → isi Destination Bank & Name (tidak ada currency:
    form & `invoice_payments` memang tak punya kolomnya). Jalan setelah scan OCR **dan** saat ketik manual (event `change` =
    blur/Enter, bukan tiap ketukan, supaya angka yang baru separuh diketik tidak dikira 4 digit terakhir).
  - Aturan cocok (`parseScannedAccount()`, `acctFindMatches()`): nomor penuh → sama persis (spasi/strip diabaikan); nomor
    tertutup → cocok 4 digit terakhir. OCR tidak pernah membaca `*` dengan benar (jadi huruf, mis. `SSSSSSSSS8393`), maka
    **karakter non-digit apa pun** (selain spasi/titik/strip) = tertutup; nomor rekening asli selalu digit. Juga tertutup bila ≤4
    karakter. Tanpa 4 digit di ujung → diabaikan.
  - Hasil: 1 cocok → langsung isi; >1 cocok (beda bank) → fly window `acctPickOverlay` pilih manual; 0 cocok → fly window
    `acctRegisterOverlay` (daftar rekening baru; bank/nama/currency diisi dari form bila ada; nomor penuh wajib diketik bila
    hanya 4 digit). Kedua overlay terdaftar di `flexOverlays`, z-index 2200 (di atas viewer & field picker).
  - Saat cocok/terdaftar, kolom nomor **diganti dengan nomor penuh milik JSON** (supaya Finance Report yang join lewat
    bank_name+account_number tetap cocok); pilihan ini bisa dibatalkan kalau user maunya nomor apa adanya dari slip.
  - Daftar rekening baru = **password pola (b)**: `verify_password.php` lalu `save_bank_account.php` dengan
    `require_reverify=1` (endpoint memanggil `aos_require_recent_reverify()` hanya bila `id` ada ATAU flag itu dikirim;
    Settings tetap membuat rekening baru tanpa password).
  - Datalist destination Add Payment (`loadDestBankAccounts`) dimuat ulang tiap form dibuka dan setelah rekening baru didaftarkan,
    jadi tidak perlu refresh halaman.
  - **Pencocokan hanya dari nama bank** (`lookupAccountByBank()`, `acctBankMatches()`): dipakai kalau nomor/nama akun tidak
    terbaca sama sekali di slip, hanya nama bank. Jalan saat field Bank (scan OCR atau selesai ketik) berisi nilai **dan**
    field Account Number untuk wizard itu masih kosong — begitu nomor terisi, pencocokan nomor di atas yang menang.
    Dicocokkan ke `bank_name` persis (tanpa spasi di pinggir, tanpa pandang besar/kecil huruf). 1 cocok → isi nomor, nama,
    currency. >1 cocok (bank sama, >1 rekening) → `acctPickOverlay`. 0 cocok → `acctRegisterOverlay` lewat
    `openAcctRegisterByBank()` (bank sudah terisi, nomor & nama kosong menunggu diisi manual; password tetap wajib).
    `openAcctPicker()`/`openAcctRegister()` dirombak jadi fungsi inti (`openAcctRegisterCore`) dipakai bersama kedua alur
    (nomor & bank-saja) supaya tidak dobel kode.

## 9. Cara lanjut di sesi baru
1. Upload PROJECT_NOTES.md ini + hanya file kode yang relevan (Sales: `transaction_content.php` + `ajax/` terkait;
   Logistic: `logistic_content.php` + `list_logistic_movements.php`/`list_logistics.php`).
2. Jangan upload `NOTES_ARCHIVE.md` kecuali perlu menelusuri alasan keputusan lama (sebut bagian yang dicari).
3. Setelah selesai: tambahkan ≤10 baris ke §8 (atau "Log terbaru"), lalu lebur ke §4–6 kalau sudah dites.