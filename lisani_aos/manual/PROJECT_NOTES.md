# PROJECT_NOTES — lisani_aos

## Apa ini
Sub-project tersembunyi ("extended mode") yang nempel di project utama **LenZa Optic POS**
(folder `optic_pos/`). Diakses lewat **triple-click** tombol login di `login.php` (bukan
single-click biasa). Database dan sesi-nya terpisah total dari optic_pos.

## Struktur folder
```
optic_pos/
├── login.php          <-- shared, punya cabang access_mode=normal / extended
├── logout.php         <-- shared, punya cabang berdasarkan $_SESSION['app']
├── db_config.php       (punya optic_pos, TIDAK dipakai lisani_aos)
└── lisani_aos/
    ├── index.php
    ├── db_config.php   (koneksi ke lisani_aos_db, pakai $lisani_conn)
    ├── .htaccess       (Options -Indexes + blok akses langsung ke db_config.php)
    ├── partials/
    │   ├── header.php  (<head>, link CSS)
    │   ├── sidebar.php (menu sidebar + bottom-nav mobile)
    │   └── footer.php  (JS switch antar section, </body></html>)
    └── assets/css/
        ├── theme.css       (tokens warna/shadow + semua komponen)
        └── responsive.css  (breakpoint: desktop / tablet rail / mobile bottom-nav)
```

## Konvensi penting
- **Bahasa saat kerja**: komunikasi dengan user (chat) pakai **Bahasa Indonesia**. Semua
  **kode** (identifier, komentar, nama kolom/field, nama file) tetap **Bahasa Inggris** —
  sama seperti aturan [[lenza-optic-pos]], berlaku juga untuk lisani_aos.
- **Tema**: dark neomorphism (soft shadow ganda terang+gelap), sesuai spec MD yang sudah
  diberikan user sebelumnya. `theme.css` = tokens & komponen, `responsive.css` = breakpoint.
  Jangan digabung jadi satu file — ikuti aturan section 8.1 di spec asli.
- **Halaman baru**: `include 'partials/header.php'` → konten → `include 'partials/footer.php'`,
  sidebar di-include di antara keduanya. Supaya menu konsisten di semua halaman.
- **Session vars** (di-set oleh `login.php` mode extended):
  `$_SESSION['app'] = 'lisani_aos'`, `user_id`, `username`, `role`, `session_token`.
- **Guard wajib** di tiap halaman baru:
  ```php
  if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
      header('Location: ../login.php');
      exit;
  }
  ```
- **DB**: `require_once 'db_config.php'` di dalam `lisani_aos/` → dapat `$lisani_conn`
  (mysqli). Tabel utama: `users` (user_id, username, password_hash, role, is_approved,
  session_token, session_expires, last_login). Tabel lain untuk Transactions/Report/dst
  belum dibuat — rancang saat ngisi menu terkait.
- **Menu sidebar**: Dashboard, Transactions, Report saja (menu utama). Settings dan Exit
  **tidak lagi** di sidebar/bottom-nav — dipindah ke dropdown avatar user (pojok kanan
  atas header, sebelah search box, `.user-menu` / `.user-menu-dropdown`). Section switching
  tetap satu mekanisme untuk semua trigger (sidebar item, bottom-nav item, avatar dropdown
  item) lewat listener generik `[data-target]` di footer.php — jadi nambah trigger baru
  (mis. taruh menu di tempat lain lagi nanti) tinggal kasih atribut `data-target` yang sama
  dengan `data-section` target-nya, tidak perlu ubah JS.
  Saat ini semua section masih empty-state placeholder.
- **Header**: avatar = tombol trigger dropdown (`#userMenuTrigger` di dalam `#userMenu`),
  bukan lagi elemen statis. Tidak ada lagi tombol notifikasi/lonceng terpisah — dihapus
  karena belum ada fitur notifikasi di baliknya.
- **Bahasa UI**: semua teks yang tampil ke user (label, placeholder, empty-state) pakai
  **Bahasa Inggris**, beda dari [[lenza-optic-pos]] yang UI-nya Bahasa Indonesia. Lihat
  update di `theme-dark-neomorphism.md` section 8.3.

## Cara jaga konsistensi tampilan (PENTING)
Aturan tema lengkap ada di `theme-dark-neomorphism.md` (disertakan di paket ini) — itu
sumber aslinya. Tapi untuk kerja sehari-hari, **cukup pakai class yang sudah jadi di
`assets/css/theme.css`**, jangan bikin style baru dari nol tiap halaman:

| Kebutuhan              | Class                                   |
|-------------------------|------------------------------------------|
| Kartu pembungkus        | `.card`                                  |
| Kartu statistik         | `.card` + `.card-stat-value`             |
| Tabel data              | `.table-wrapper` > `<table>`             |
| Input teks              | `.form-group` > `.label` + `.input`      |
| Dropdown                | `.select`                                |
| Tombol utama            | `.btn .btn-primary`                      |
| Tombol sekunder         | `.btn .btn-secondary`                    |
| Tombol bahaya/hapus     | `.btn .btn-danger`                       |
| Status kecil            | `.badge .badge-success/-danger/-warning` |
| Tab dalam halaman       | `.tab-group` > `.tab`                    |
| Modal/dialog            | `.modal-overlay` > `.modal`              |
| Toggle on/off           | `.toggle-track` + `.toggle-thumb`        |
| Placeholder kosong      | `.empty-state`                           |

Kalau butuh komponen yang BELUM ada di tabel ini (misal date-picker, chart, dsb), baru
buka `theme-dark-neomorphism.md` untuk acuan warna/shadow biar tetap nyambung secara
visual, lalu tambahkan class barunya ke `theme.css` (bukan style inline per halaman) supaya
bisa dipakai ulang di menu lain juga.

## Status terakhir
- Shell `index.php` + menu + tema sudah jadi, konten semua menu masih kosong.
- Integrasi login/logout dengan project lama (optic_pos) sudah beres dan sudah dicek
  supaya tidak mengganggu alur login normal.
- Header sudah dirapikan: teks UI full Inggris, Settings/Exit dipindah dari sidebar ke
  dropdown avatar, tombol notifikasi kosong (tidak ada fiturnya) dihapus.
- **Penting:** `transaction_content.php` membungkus isinya sendiri dengan
  `<div class="menu-section" data-section="transactions" style="display:none;">` —
  di `index.php`, include-nya **menggantikan** div section itu sepenuhnya (bukan
  diletakkan di dalam div section seperti section lain). Jangan bungkus lagi dari
  `index.php`, dan jangan taruh modal (`txnEntryOverlay`/`txnPasswordOverlay`) di
  dalam wrapper itu — modal ditaruh sebagai sibling di luar supaya tetap bisa
  dirender walau section-nya sedang `display:none`.
- **Transactions — Create Activity Code sudah jalan** (`transaction_content.php`,
  `ajax/verify_password.php`, `ajax/create_activity_code.php`, `departments.json`,
  tabel `activities` — lihat `lisani_aos_activities.sql`):
  - Fly window (modal) **hanya** dipakai untuk 2 hal: pemilihan aksi (Create Activity
    Code / Input Transaction) dan verifikasi password. Keduanya muncul **hanya saat
    section Transactions benar-benar dibuka** (dideteksi lewat `MutationObserver` pada
    `style` attribute section, bukan pada saat load awal dengan Dashboard aktif).
  - Form **Create Activity Code** dan hasilnya (activity code + relative path) **bukan
    modal** — keduanya adalah view `.card` yang di-swap langsung di dalam konten section
    Transactions (`#viewCreateActivityCode`, `#viewActivityCodeResult`), pakai class baru
    `.panel-header` / `.panel-title` (lihat update di `theme-dark-neomorphism.md` §5.11).
  - Penomoran activity code reset per departemen + tahun; folder fisik di-`mkdir` otomatis
    saat create (lokasi diatur lewat konstanta `AOS_STORAGE_BASE` di
    `ajax/create_activity_code.php`).
  - Perbaikan: `.modal-overlay` di tema aslinya belum punya `position: fixed` + centering,
    jadi modal ikut alur dokumen biasa alih-alih mengambang di tengah layar — sudah
    ditambahkan ke `theme.css` (lihat update di `theme-dark-neomorphism.md` §5.9).
  - Fly window pemilihan aksi punya tombol **X** (pojok kanan atas) untuk kembali ke
    Dashboard — implementasinya memicu `.click()` pada elemen `[data-target="dashboard"]`
    yang sudah ada, bukan menduplikasi logic `setActive()` dari `footer.php`.
  - **Konvensi huruf besar**: `Activity Name` selalu huruf besar — dipaksa di input
    (`text-transform:uppercase` + JS force value saat mengetik) **dan** di server
    (`strtoupper()` sebelum `INSERT`, jadi tidak bisa dilewati lewat request manual).
    Label departemen di `departments.json` juga huruf besar (tampilan). `key` departemen
    (`date`/`tax`/`adm`) **sengaja tetap huruf kecil** karena dipakai langsung sebagai nama
    folder fisik — diubah besar berisiko mismatch dengan folder yang sudah ada di server.
  - Field **Department** punya tombol edit (ikon pensil) di sebelah select-nya, membuka
    fly window **Manage Departments** (`ajax/manage_departments.php`) untuk add/edit/hapus
    opsi departemen langsung dari `departments.json`. Aturan pentingnya: **`key` tidak bisa
    diubah lewat edit** (cuma label) — key dipakai sebagai nama folder fisik. **Hapus
    departemen ditolak server** kalau `key`-nya sudah dipakai activity code manapun (dicek
    lewat `relative_path LIKE 'input/%/{key}/%'` di tabel `activities`). Menambah
    departemen baru men-generate `key` otomatis dari label (slug huruf kecil), unik.
- **Transactions — Activity Code sekarang punya 2 tab** di dalam card
  `#viewCreateActivityCode` (`transaction_content.php`), diatur lewat `.tab-group`
  `#acTabGroup` + `[data-ac-tab]`:
  - **Tab 1 "Preview"** (default saat form dibuka) — tabel list activity code yang
    sudah ada (`#acTabPanelPreview`, tabel `#acPreviewTableBody`), datanya dari AJAX
    baru `ajax/list_activity_codes.php` (kolom: Activity Code, Activity Name,
    Department, Cashflow, Relative Path, Created — department & code number di-parse
    dari `relative_path` karena tidak ada kolom terpisah di tabel `activities`).
    **Asumsi skema**: tabel `activities` punya kolom `id` (PK auto-increment) dan
    `created_at` (TIMESTAMP DEFAULT CURRENT_TIMESTAMP) selain kolom yang sudah dipakai
    `create_activity_code.php` — cek `lisani_aos_activities.sql` asli, sesuaikan query
    di `list_activity_codes.php` kalau beda.
  - **Tab 2 "Create Activity Code"** — form yang sudah ada sebelumnya (Year,
    Department, Activity Name, Cashflow, Save), sekarang dengan 2 tambahan:
    - Field **Activity Code** tidak lagi teks statis "Auto-generated" — sekarang
      `#acCodePreview` menampilkan nomor **asli** yang akan dibuat, dan field
      **Relative Path (preview)** menampilkan path asli (bukan `???` lagi). Keduanya
      di-fetch live dari AJAX baru `ajax/preview_activity_code.php` (debounce 250ms)
      setiap Year/Department berubah — logika penomorannya sengaja **disalin persis**
      dari `create_activity_code.php` (query + padding yang sama) supaya preview selalu
      cocok dengan hasil final. Endpoint ini read-only, tidak `mkdir` dan tidak
      INSERT ke DB.
    - Validasi **Activity Name tidak boleh duplikat**: dicek real-time di browser
      (`checkDuplicateName()`) terhadap data yang sudah dimuat dari
      `list_activity_codes.php` (tanpa request tambahan tiap ketik) — kalau duplikat,
      pesan error muncul dan tombol Save disable. Sebagai jaring pengaman, pengecekan
      yang sama juga ditambahkan di server `create_activity_code.php` (query
      `SELECT id FROM activities WHERE activity_name = ?` sebelum INSERT).
  - Saat form dibuka (baik dari alur password-confirm maupun "Create Another"), tab
    otomatis kembali ke **Preview** dan list di-refresh (`resetCreateCodeForm()`),
    supaya code yang baru dibuat langsung kelihatan di list.

- **Fix (sudah lewat, digantikan) — Tab Preview activity code hilang di layar <1024px**:
  sempat diperbaiki dengan pendekatan tabel yang collapse jadi kartu di mobile/tablet
  (`data-label` per `<td>` + CSS `::before` di `responsive.css`). Pendekatan tabel ini
  **sudah tidak dipakai lagi** — lihat poin di bawah, tab Preview sekarang accordion,
  bukan tabel. Catatan historisnya ditinggal di git history kalau perlu ditelusuri lagi.
  Sempat juga ada bug lanjutan: rule `table, thead { display:none }` di `responsive.css`
  ternyata mematikan `<table>`-nya sendiri (bukan cuma `thead`), jadi `tbody`/`tr`/`td`
  ikut hilang total walau di-set `display:block` — ini juga sudah tidak relevan lagi
  setelah tabelnya diganti accordion, tapi rule generic `table, thead` di
  `responsive.css` **tetap dibiarkan ada** (sudah diperbaiki: `table { display:block }`
  + `thead { display:none }` terpisah) untuk tabel lain yang mungkin ditambahkan ke
  aplikasi ini nanti.
- **Transactions — Tab Preview sekarang Accordion, bukan tabel** (`transaction_content.php`,
  `theme.css` §Accordion, `theme-dark-neomorphism.md` §5.12):
  - `#acPreviewList` (`.accordion-list`) menggantikan `.table-wrapper` > `<table>` yang
    lama. Satu komponen yang sama dipakai di **semua lebar layar** — tidak ada lagi
    perbedaan tampilan mobile vs desktop untuk list ini, jadi tidak butuh media query
    khusus seperti pendekatan tabel-ke-kartu sebelumnya.
  - **Semua item collapsed saat pertama dirender / list di-refresh.** Header tiap item
    (`.accordion-header`) hanya menampilkan **Activity Name**. Field lain (Activity Code,
    Department, Cashflow, Relative Path, Created) baru muncul di `.accordion-body` saat
    item-nya dibuka.
  - **Hanya satu item yang boleh terbuka dalam satu waktu** — `toggleAccordionItem()`
    di `transaction_content.php` selalu menutup item lain yang sedang terbuka sebelum
    membuka item yang baru diklik.
  - `.accordion-body` di-expand pakai `max-height = scrollHeight` elemen dalamnya (dihitung
    di JS saat toggle), bukan nilai tetap, supaya animasinya pas untuk jumlah field apa pun.
  - `renderActivityList()` di-refactor total: tidak lagi bikin `<tr>`/`<td>` dengan
    `data-label`, sekarang bikin `.accordion-item` per activity code lewat DOM API biasa
    (bukan `innerHTML`, supaya `activity_name` dari data tidak perlu di-escape manual).
  - **Konvensi baru untuk list serupa ke depan** (bukan tabel lebar dengan banyak kolom):
    pertimbangkan Accordion (`theme-dark-neomorphism.md` §5.12) sejak awal, bukan bikin
    tabel dulu lalu di-collapse-in-CSS belakangan seperti kasus Activity Code ini.
  - Tambahan kecil yang masih relevan dari perbaikan sebelumnya: tab Preview tetap
    refresh datanya setiap kali diklik (bukan cuma sekali saat form pertama dibuka), dan
    `loadActivityList()` tetap log ke `console.warn`/`console.error` kalau request gagal.

- **Activity Code — kolom Created dihapus dari Preview, ditambah Edit & Delete**
  (`transaction_content.php`, `ajax/list_activity_codes.php`,
  `ajax/update_activity_code.php`, `ajax/delete_activity_code.php`):
  - **`created_at` tidak lagi ditampilkan** di `.accordion-body` tab Preview — field
    `created_at` juga dihapus dari response `list_activity_codes.php` sekalian (tidak
    dikirim ke client sama sekali, bukan cuma disembunyikan di UI). Field yang tersisa
    di body: Activity Code, Department, Cashflow, Relative Path.
  - **Skema tabel `activities` dikonfirmasi via `DESCRIBE`** (lihat `id` int unsigned PK
    auto_increment, `activity_name` varchar(150), `cashflow`
    enum('inflow','outflow','in-out'), `relative_path` varchar(255) UNIQUE, `created_by`
    int unsigned, `created_at` datetime default current_timestamp()) — cocok dengan
    asumsi yang sudah dipakai `create_activity_code.php`/`list_activity_codes.php`
    sebelumnya, jadi tidak ada penyesuaian query yang diperlukan. `list_activity_codes.php`
    sekarang juga ikut mengirim `id`, `department_key` (key mentah dari
    `departments.json`, bukan cuma label), dan `year` (di-parse dari `relative_path`)
    — dipakai untuk mengisi ulang form Edit dan sebagai payload Delete.
  - **Pola Edit/Delete disamakan persis dengan Customer List** (lihat poin di bawah
    untuk detail pola aslinya) — tiap item `#acPreviewList` (accordion body) sekarang
    punya tombol **Edit** (`.btn-secondary`) dan **Delete** (`.btn-danger`) di baris
    terakhir, sejajar kanan, keduanya `e.stopPropagation()`.
  - **Edit** (`openEditActivityForm()`): mengisi ulang form di tab "Create Activity
    Code" (Year, Department — dari `department_key`, Activity Name, Cashflow) lalu
    pindah ke tab itu. Field Activity Code & Relative Path (preview) diisi langsung dari
    data row yang diklik (bukan lewat `updatePathPreview()`/`preview_activity_code.php`,
    yang menghitung nomor kode *berikutnya* — saat edit, nomor kode yang sudah ada
    sengaja dipertahankan, lihat poin `update_activity_code.php` di bawah). Variabel JS
    `editingActivityId` menandai mode edit; selama variabel ini terisi:
    - Label kecil `#acFormModeLabel` ("Editing <NAMA>") muncul di atas form,
      tombol Save berubah jadi **"Update"**.
    - `checkDuplicateName()` mengecualikan row yang sedang diedit dari pengecekan
      duplikat.
    - Klik Save mengirim ke **`ajax/update_activity_code.php`** (bukan
      `create_activity_code.php`), menyertakan `id`. Sukses **tidak** menampilkan
      `#viewActivityCodeResult` (itu cuma untuk create baru) — langsung kembali ke
      Preview yang sudah di-refresh, sama seperti alur Customer.
    - Klik tab "Preview" secara manual **atau** hasil Save yang sukses keduanya
      memanggil `resetCreateCodeForm()`, yang juga mereset `editingActivityId` ke
      `null`. Klik tab "Create Activity Code" **secara manual** (bukan lewat tombol
      Edit) saat `editingActivityId` masih `null` akan reset label/tombol ke mode
      create biasa — pola identik `clTabs` di Customer List.
  - **`ajax/update_activity_code.php`**: update `activity_name`, `cashflow`, `year`,
    `departement`. **Nomor kode (`001`, `002`, dst di dalam `relative_path`) TIDAK
    pernah di-generate ulang saat edit** — diambil dari `relative_path` lama lewat
    regex lalu dipakai apa adanya di `relative_path` baru. Ini sengaja beda dari
    `create_activity_code.php` (yang menghitung nomor berikutnya per departemen+tahun)
    supaya edit tidak pernah berebut/tabrakan nomor dengan activity code lain. Validasi
    duplikat nama (`activity_name`) dicek ulang, mengecualikan id row sendiri — sama
    seperti `update_customer.php`. Karena `relative_path` UNIQUE di skema DB, ditambah
    juga pengecekan manual sebelum UPDATE kalau `year`/`departement` baru menghasilkan
    path yang sudah dipakai row lain (harusnya jarang terjadi karena numbering per
    departemen+tahun, tapi tetap dijaga karena ini jalur tulis manual). Kalau
    `year`/`departement` berubah (yang berarti `relative_path` berubah), folder fisik
    lama di-`rename()` ke lokasi baru (best-effort, status balik lewat
    `folder_synced`); kalau folder tujuan sudah ada duluan, keduanya **dibiarkan apa
    adanya** dan `folder_synced` di-set `false` — pola sama persis dengan
    `update_customer.php`.
  - **`ajax/delete_activity_code.php`**: **wajib verifikasi password** user yang
    sedang login (`password_verify()` ke `password_hash` tabel `users`), sama seperti
    `delete_customer.php` — tidak lewat `verify_password.php` yang lama. Setelah row
    `activities` terhapus, folder fisik (`AOS_STORAGE_BASE/input/[year]/[dept]/[code]/`)
    ditangani best-effort:
    - **Kosong (atau tidak ada)** → langsung `rmdir()`.
    - **Ada isinya** → **dipindah** (bukan dihapus) ke
      `AOS_STORAGE_BASE/recycle/input/[year]/[dept]/[code]/` — segmen `input`
      dipertahankan (sama alasannya dengan segmen `selling` di recycle Customer:
      supaya struktur recycle-nya kelihatan asalnya dari section mana). Tabrakan nama
      folder tujuan ditambah suffix timestamp (`-YmdHis`).
    - Status aksi folder (`deleted` / `moved_to_recycle` / `none` / `failed`) dikirim
      balik lewat field `folder_action` — sama seperti Customer, belum ditampilkan ke
      UI, baru dikirim ke response saja.
  - **Delete** (`openDeleteActivityConfirm()`): membuka fly window baru
    **`#txnDeleteActivityCodeOverlay`** — terpisah dari `#txnDeleteCustomerOverlay`
    (satu overlay per jenis aksi destruktif, pola sama). Isinya: teks warning data
    loss, nama activity yang akan dihapus, field password, tombol Cancel/Delete. Klik
    **Delete** langsung POST `id` + `password` ke `ajax/delete_activity_code.php`.
    Kalau salah password, error tampil di dalam modal, modal tetap terbuka. Kalau row
    yang dihapus kebetulan sedang dalam mode edit, form ikut direset balik ke mode
    create (`resetCreateCodeForm()`).
  - Modal baru ini didaftarkan ke helper `show()`/`hide()` yang sudah ada dan ikut
    ditutup otomatis oleh `MutationObserver` saat section Transactions ditinggalkan —
    sama seperti `#txnDeleteCustomerOverlay`.

- **Transactions — Customer List sudah jalan** (`transaction_content.php`,
  `ajax/create_customer.php`, `ajax/list_customers.php`, tabel `customers` —
  lihat `lisani_aos_customers.sql`):
  - Tombol **Customer List** ditambahkan di fly window pemilihan aksi
    (`#txnEntryOverlay`), sejajar dengan Create Activity Code / Input Transaction.
  - Sama seperti Create Activity Code, membuka Customer List **wajib verifikasi
    password dulu** — fly window password (`#txnPasswordOverlay`) dan endpoint
    `ajax/verify_password.php` dipakai bersama untuk kedua flow, dibedakan lewat
    variabel JS `pendingPasswordTarget` (`'activity_code'` / `'customer_list'`)
    yang di-set saat tombol pembuka masing-masing diklik.
  - View `#viewCustomerList` punya 2 tab (`.tab-group` `#clTabGroup`,
    `[data-cl-tab]`), pola identik dengan Activity Code:
    - **Tab 1 "Customer List"** (default) — accordion (`#clPreviewList`,
      collapsed by default, header = Customer Name saja, satu item terbuka
      dalam satu waktu) dari `ajax/list_customers.php`. Body accordion
      menampilkan Year, Phone Number, Total Inflow, Total Outflow, Profit,
      Created. Fungsi toggle/open/close accordion **dipakai bersama** dengan
      Activity Code Preview (didefinisikan sekali, dipanggil dari kedua list).
    - **Tab 2 "New Customer"** — form input **hanya 3 field**: Year (default
      current year, bisa diubah), Customer Name (dipaksa uppercase, sama
      seperti Activity Name), Phone Number (WA, bebas format). Total Inflow/
      Outflow/Profit **tidak diinput di sini** — kolomnya di DB default 0,
      diisi program lain nanti.
    - **Validasi duplikat**: customer name + year yang sama tidak boleh
      diinput dua kali. Dicek real-time di browser (`checkDuplicateCustomer()`)
      terhadap data dari `list_customers.php`, dan sebagai jaring pengaman
      juga dicek di server `create_customer.php` (`SELECT id FROM customers
      WHERE year = ? AND customer_name = ?` sebelum INSERT) + `UNIQUE KEY
      (year, customer_name)` di skema tabel.
    - Setelah Save sukses, **tidak ada view hasil terpisah** (beda dari
      Activity Code yang generate code/path) — form langsung direset dan tab
      kembali ke Customer List yang sudah di-refresh, supaya customer baru
      langsung kelihatan.
  - Tombol **Back** di `#viewCustomerList` kembali ke fly window pemilihan aksi
    (`#txnEntryOverlay`), sama seperti pola `btnBackToEntryFromForm` di
    Activity Code.

- **Customer List — perbaikan tampilan & format phone number** (`transaction_content.php`,
  `ajax/create_customer.php`):
  - **Tab styling disamakan dengan Activity Code**: `#clTabGroup` sebelumnya tidak
    punya `<style>` scoped (beda dari `#acTabGroup`), jadi tampilan tab-nya polos/tidak
    konsisten. Sudah ditambahkan style block yang identik dengan `#acTabGroup` (inset
    shadow saat idle, elevated shadow + warna accent saat `.active`).
  - **Tab Customer List (preview) tetap Accordion**, konsisten di semua ukuran layar —
    sama seperti Activity Code sekarang. Pendekatan table (desktop) / card (mobile)
    **sengaja tidak dipakai** untuk Customer List (dikonfirmasi user, lihat catatan di
    poin Activity Code Accordion di atas soal kenapa pendekatan tabel-collapse sudah
    ditinggalkan).
  - **Field Phone Number (WA) sekarang auto-format**: prefix `+62 8` selalu tampil
    dan tidak bisa dihapus/diedit user (klik/backspace ke area prefix otomatis
    melempar caret ke akhir). Sisa digit yang diketik user di-*group* 4-4 secara
    realtime, mis. `+62 8 1234 5678 901`. Dibatasi maks 11 digit setelah `8` (total
    12 digit setelah `+62`, panjang wajar nomor HP Indonesia). Logic ada di
    `transaction_content.php` (`PHONE_PREFIX`, `phoneDigits`, `renderPhoneValue()`,
    `resetPhoneField()` — dipanggil dari `resetCreateCustomerForm()`).
  - **Normalisasi di server** (`create_customer.php`): nilai `phone_number` yang
    dikirim client (`+62 8 xxxx xxxx xxx`, dengan spasi) di-strip jadi digit saja lalu
    disimpan ke DB dalam bentuk bersih `+628xxxxxxxxxx` (tanpa spasi) — supaya format
    di database konsisten terlepas dari spacing/grouping yang dikirim client. Ada
    juga jaring pengaman untuk format lama/mentah (`081234567890` atau
    `81234567890`) kalau endpoint ini suatu saat diakses tanpa lewat form yang
    sekarang. Validasi format: harus match `+628` diikuti 7–11 digit (total 10–14
    digit setelah `+`), kalau tidak endpoint menolak dengan `'Invalid phone number
    format.'`.

- **Customer List — folder fisik otomatis per customer** (`ajax/create_customer.php`,
  `ajax/update_customer.php`):
  - Setelah INSERT customer baru berhasil, folder fisik dibuat otomatis dengan pola:
    ```
    [AOS_STORAGE_BASE]/selling/[year]/[customer_name, huruf kecil]/
    ```
    **Satu root yang sama dengan folder activity code** — `AOS_STORAGE_BASE`
    (`dirname(__DIR__) . '/storage'`, konstanta di-redefine dengan guard
    `!defined()` di `create_customer.php`/`update_customer.php` karena masing-
    masing endpoint berdiri sendiri, bukan saling include). Activity code
    pakai subfolder `input/...`, Customer List pakai subfolder `selling/...`
    di bawah root yang sama.
  - `customer_name` (sudah uppercase di DB) di-lowercase-kan + disanitasi
    (`sanitize_folder_name()`) sebelum dipakai jadi nama folder — strip `/`, `\`,
    `..`, dan karakter di luar huruf/angka/spasi/dash/underscore, supaya aman dari
    path traversal dan konsisten sebagai nama folder OS.
  - Pembuatan folder **best-effort**: kalau `mkdir` gagal, insert customer tetap
    dianggap sukses (tidak di-rollback) — statusnya dikirim balik lewat field
    `folder_created` di response JSON.
  - `ajax/update_customer.php` (lihat poin Edit/Delete di bawah) melakukan hal yang
    sama tapi berupa **rename/move folder** kalau `year` dan/atau `customer_name`
    berubah saat edit, pakai konstanta `AOS_STORAGE_BASE` yang sama.

- **Customer List — Edit & Delete** (`ajax/update_customer.php`, `ajax/delete_customer.php`):
  - **Edit** (`update_customer.php`): update `year`, `customer_name` (dipaksa
    uppercase), `phone_number` (normalisasi sama seperti create). `total_inflow` /
    `total_outflow` / `profit` **tetap tidak bisa diedit di sini** — konsisten
    dengan aturan create. Validasi duplikat (`year` + `customer_name`) dicek ulang,
    kali ini **mengecualikan id row yang sedang diedit sendiri**. Kalau `year`
    dan/atau `customer_name` berubah, folder fisik lama di-`rename()` ke lokasi
    baru (best-effort, status balik lewat `folder_synced`); kalau folder tujuan
    sudah ada duluan (tabrakan), keduanya **dibiarkan apa adanya** (tidak
    dihapus/digabung otomatis) dan `folder_synced` di-set `false` supaya bisa
    dicek manual.
  - **Delete** (`delete_customer.php`): **wajib verifikasi password** user yang
    sedang login (`password_verify()` terhadap `password_hash` di tabel `users`,
    dicocokkan ke `$_SESSION['user_id']`) sebelum baris `customers` dihapus —
    tanpa password yang benar, DELETE ditolak. Setelah row terhapus, folder
    fisik customer (`AOS_STORAGE_BASE/selling/[year]/[nama]/`) ditangani
    best-effort:
    - **Kosong (atau tidak ada)** → langsung `rmdir()`.
    - **Ada isinya** → **dipindah** (bukan dihapus) ke
      `AOS_STORAGE_BASE/recycle/selling/[year]/[nama]/` — segmen `selling`
      sengaja dipertahankan supaya dari struktur recycle-nya kelihatan folder
      itu asalnya dari section mana (bukan cuma tumpukan `[year]/[nama]` yang
      ambigu kalau nanti ada section lain yang juga masuk recycle). Kalau
      folder tujuan sudah ada duluan (nama+tahun sama pernah dihapus
      sebelumnya), ditambah suffix timestamp (`-YmdHis`) supaya tidak
      tertimpa.
    - Status aksi folder (`deleted` / `moved_to_recycle` / `none` / `failed`)
      dikirim balik lewat field `folder_action` di response JSON — belum
      ditampilkan ke UI, baru dikirim ke response saja.
    - **Struktur/retention/cleanup untuk `storage/recycle/` belum dirancang**
      — sengaja ditunda, akan diatur terpisah nanti.
  - **Sudah diintegrasikan ke UI** (`transaction_content.php`):
    - Tiap item `#clPreviewList` (accordion body) sekarang punya tombol
      **Edit** (`.btn-secondary`) dan **Delete** (`.btn-danger`) di baris
      terakhir, sejajar kanan. Keduanya `e.stopPropagation()` supaya klik
      tombol tidak ikut toggle buka/tutup accordion item.
    - **Edit** (`openEditCustomerForm()`): mengisi ulang form di tab
      "New Customer" (Year, Customer Name, Phone Number — termasuk parse
      ulang `phoneDigits` dari `+628xxxxxxxxxx` tersimpan lewat
      `digitsFromStoredPhone()`) lalu pindah ke tab itu. Variabel JS
      `editingCustomerId` menandai mode edit; selama variabel ini terisi:
      - Label kecil `#clFormModeLabel` ("Editing <NAMA>") muncul di atas form,
        tombol Save berubah jadi **"Update"**.
      - `checkDuplicateCustomer()` mengecualikan row yang sedang diedit dari
        pengecekan duplikat.
      - Klik Save mengirim ke **`ajax/update_customer.php`** (bukan
        `create_customer.php`), menyertakan `id`.
      - Klik tab "New Customer" **secara manual** (bukan lewat tombol Edit)
        saat `editingCustomerId` masih `null` akan reset label/tombol ke mode
        create biasa. Tombol **Back** (`btnBackToEntryFromCustomer`) dan
        `resetCreateCustomerForm()` juga mereset `editingCustomerId` ke
        `null`, supaya state edit tidak "nyangkut" ke sesi berikutnya.
    - **Delete** (`openDeleteCustomerConfirm()`): membuka fly window baru
      **`#txnDeleteCustomerOverlay`** — terpisah dari `#txnPasswordOverlay`
      yang sudah ada (itu untuk gate MASUK ke suatu alur; ini untuk satu aksi
      destruktif saja). Isinya: teks **warning data loss** eksplisit ("cannot
      be undone and all associated data will be lost"), nama customer yang
      akan dihapus, field password, tombol Cancel/Delete. Klik **Delete**
      langsung POST `id` + `password` ke **`ajax/delete_customer.php`**
      (verifikasi password terjadi di endpoint itu sendiri via
      `password_verify()` — **tidak** lewat `verify_password.php` yang lama,
      supaya cuma perlu 1x input password, bukan 2x). Kalau salah password,
      error tampil di dalam modal, modal tetap terbuka. Kalau row yang
      dihapus kebetulan sedang dalam mode edit, form ikut direset balik ke
      mode create.
    - Modal baru ini didaftarkan ke helper `show()`/`hide()` yang sudah ada
      (supaya pakai `display:flex` seperti modal lain) dan ikut ditutup
      otomatis oleh `MutationObserver` saat section Transactions ditinggalkan.

  - **Bugfix: card Customer List tidak bisa ditutup / tidak menutup card lain**:
    `toggleAccordionItem()` sebelumnya hardcode menutup item lain di
    `acPreviewList` (list Activity Code) untuk SEMUA pemanggil, padahal fungsi
    ini dipakai bersama oleh dua accordion (`acPreviewList` & `clPreviewList`).
    Akibatnya item di `clPreviewList` tidak pernah ikut ditutup lewat baris itu
    — sekali dibuka jadi tidak bisa ditutup lagi, dan membuka item lain tidak
    menutup item yang sudah terbuka. Sudah diperbaiki: fungsi sekarang cari
    `.accordion-list` terdekat dari item yang diklik (`item.closest(...)`)
    sebelum menutup item `.open` lainnya, jadi otomatis scoped ke list
    masing-masing (Activity Code & Customer List sama-sama benar, dan
    accordion-list lain di masa depan otomatis ikut benar juga).

- Belum: isi konten menu Report, Input Transaction (baru placeholder alert),
  rancang tabel DB untuk Report, program terpisah untuk mengisi Total
  Inflow/Outflow/Profit di tabel `customers`, dan pengaturan
  `storage/recycle/` (retention/cleanup/siapa yang boleh lihat isinya) —
  sengaja ditunda sesuai keputusan user, folder-nya sudah dibuat otomatis
  oleh `delete_customer.php` (dan sekarang juga oleh
  `ajax/delete_document.php` di fitur Settings, subfolder
  `recycle/company/legal_document/`) tapi belum ada pengelolaan lanjutannya.
  Settings sudah ada isi (Company Documents & Company Bank Accounts, lihat
  poin di atas) — bagian Settings lain (kalau ada) masih bisa ditambah nanti.

- **Settings — Company Documents & Company Bank Accounts sudah jalan**
  (`settings_content.php`, `ajax/upload_document.php`, `ajax/update_document.php`,
  `ajax/delete_document.php`, `ajax/download_document.php`, `ajax/share_documents.php`,
  `ajax/list_documents.php`, `ajax/list_bank_accounts.php`, `ajax/save_bank_account.php`,
  `ajax/delete_bank_account.php`, `ajax/share_bank_accounts.php`,
  `ajax/manage_currencies.php`, tabel `company_documents` — lihat
  `sql/lisani_aos_company_documents.sql`):
  - **Sama seperti `transaction_content.php`**: `settings_content.php` membungkus
    isinya sendiri dengan `<div class="menu-section" data-section="settings"
    style="display:none;">` — di `index.php`, include-nya **menggantikan** div
    section Settings sepenuhnya (bukan diletakkan di dalamnya). Modal-modalnya
    (`#settingsConfirmOverlay`, `#settingsAddCurrencyOverlay`) ditaruh sebagai
    sibling di luar wrapper itu, sama alasannya seperti modal Transactions.
  - **Dua card collapsible baru** (`.settings-collapsible`, class ditambahkan
    lewat `<style>` scoped di dalam `settings_content.php` sendiri, belum
    dipindah ke `theme.css` — kalau ada card collapsible serupa di menu lain
    nanti, baru layak diangkat jadi komponen umum): default collapsed, buka
    salah satu otomatis menutup yang lain (`openCollapsible()` /
    `closeCollapsible()`, animasi lewat `max-height`). Data list masing-masing
    card (`loadDocuments()` / `loadBankAccounts()`) baru di-fetch pertama kali
    card itu dibuka, bukan saat halaman load.
  - **Company Documents**: form upload di atas (Document Name, Document Date,
    File). Field **Final File Name** auto-generate pola `[nama]_[tahun]` dari
    Document Name + tahun Document Date (`updateFinalNamePreview()`), tapi bisa
    diedit manual sebelum submit — begitu user ngetik di field itu langsung,
    auto-generate berhenti (`finalNameManuallyEdited`). Nama itu juga dipakai
    sebagai nama file fisik (disanitasi di `upload_document.php`). File
    disimpan di `[AOS_STORAGE_BASE]/company/legal_document/` (root sama dengan
    folder Activity Code & Customer List, subfolder baru `company/...`),
    metadata di tabel `company_documents`. List tampil sebagai table biasa
    (bukan accordion) dengan checkbox per baris + tombol Download/Edit/Delete.
    Edit hanya untuk `document_name`/`document_date`, tidak bisa ganti file
    (kalau perlu ganti file, hapus lalu upload ulang).
  - **Company Bank Accounts**: form di atas (Account Number, Account Name,
    Currency, SWIFT Code + Address — dua field terakhir cuma muncul &
    `required` kalau currency-nya bukan `IDR`, lihat `toggleBankIntlFields()`).
    Disimpan di **JSON**, bukan DB — `json_file/bank_accounts.json` (array of
    object, tiap object punya `id` unik dari `uniqid()`). List ditampilkan
    **dikelompokkan per currency** (`renderBankGroups()`, satu `<table>` per
    grup). Kolom SWIFT/Address ikut disembunyikan di tabel kalau grupnya
    `IDR`. Currency dropdown-nya sendiri juga JSON (`json_file/currencies.json`,
    default `["IDR", "USD"]`), bisa ditambah lewat tombol `+` di sebelah
    dropdown (`ajax/manage_currencies.php?action=add`, tanpa password — sama
    seperti `departments.json` di fitur Activity Code) tanpa perlu deploy ulang
    kode.
  - **Multi-select + Share via WhatsApp** (dokumen & rekening, masing-masing
    checkbox sendiri + tombol "Select All" per tabel/grup): karena `wa.me`
    cuma bisa kirim teks/link (tidak bisa attach file dari browser), alurnya
    untuk **dokumen** adalah: setelah password diverifikasi, semua file
    terpilih **otomatis ke-download** ke device user (loop bikin `<a
    download>` lalu `.click()`), lalu tab baru `wa.me/?text=...` kebuka berisi
    daftar nama dokumen — file-nya di-attach manual oleh user sendiri di
    WhatsApp (keputusan user, bukan kirim link publik). Untuk **rekening**,
    tidak ada file — teksnya langsung berisi **detail lengkap** tiap rekening
    terpilih (account number, name, currency, dan SWIFT/address kalau bukan
    IDR).
  - **Verifikasi password untuk Edit/Delete/Share — PENTING, beda dari pola
    `delete_customer.php`**: alih-alih tiap endpoint terima field `password`
    dan `password_verify()` sendiri-sendiri, sekarang **dipusatkan** lewat
    `ajax/verify_password.php` yang sudah ada (dipakai lebih dulu oleh
    Create Activity Code). Alurnya:
    1. Klik Edit/Delete/Share (dokumen atau rekening) → selalu buka satu modal
       generik yang sama, `#settingsConfirmOverlay` (title + pesan warning +
       field password, di-set dinamis lewat `openConfirmModal(title, message,
       onConfirm)`).
    2. Klik Confirm di modal → JS POST password ke `ajax/verify_password.php`
       (bukan ke endpoint aksinya). Kalau `ok:false`, error tampil di dalam
       modal, modal tetap terbuka (`confirmModalError()`).
    3. Kalau `ok:true`, `verify_password.php` set
       `$_SESSION['aos_reverify_at'] = time()` (flag baru, generik — beda dari
       `$_SESSION['aos_reverify_activity_code']` yang lama, keduanya sekarang
       di-set bareng, yang lama **tidak dihapus** supaya
       `create_activity_code.php` tidak perlu diubah). JS baru lanjut manggil
       `pendingConfirmAction()` **tanpa** kirim password lagi.
    4. Endpoint aksinya sendiri (`update_document.php`, `delete_document.php`,
       `share_documents.php`, `save_bank_account.php` mode update,
       `delete_bank_account.php`, `share_bank_accounts.php`) tidak lagi terima
       `password`/`password_verify()` — cukup
       `require_once 'ajax/_require_reverify.php'; aos_require_recent_reverify();`
       yang cek flag itu masih ada dan belum lewat **120 detik**
       (`AOS_REVERIFY_WINDOW_SECONDS`, di `ajax/_require_reverify.php`). Kalau
       basi/tidak ada, endpoint balikin `success:false` dengan pesan expired,
       modal-nya sudah kebuka duluan jadi user tinggal isi password lagi.
    - **Kenapa beda dari `delete_customer.php`** (yang verifikasi
      `password_verify()` langsung di endpoint aksi, bukan lewat
      `verify_password.php`, biar cuma 1x input password): user secara
      eksplisit minta pakai `verify_password.php` yang sudah ada untuk fitur
      Settings ini. Jadi sekarang ada **2 pola berbeda** yang hidup
      berdampingan di project ini — kalau bikin fitur destructive baru,
      **tanya dulu ke user** mau pola yang mana sebelum nulis endpoint-nya,
      jangan asumsi salah satu.
    - Create baru (dokumen: upload; rekening: tambah akun baru) **tidak**
      perlu verifikasi password sama sekali — hanya Edit/Delete/Share yang
      digate.
  - Currency create (`manage_currencies.php?action=add`) juga tidak digate
    password, sama seperti create department di fitur Activity Code.
  - **List dokumen di-refactor dari table jadi accordion card**
    (`renderDocumentList()`, ganti dari `renderDocumentTable()` yang lama —
    `docTableBody`/`<table>` untuk list dokumen **sudah dihapus total**, jangan
    dicari lagi kalau baca history sebelumnya):
    - Tiap dokumen = 1 card (`.doc-accordion-item`), default **collapsed**,
      cuma satu yang boleh terbuka dalam satu waktu (`openDocItem()` otomatis
      nutup item lain lewat `closeDocItem()` — pola sama seperti dua card
      besar Settings di level atas, animasi juga sama-sama `max-height`).
    - **Header** (`.doc-accordion-header`) = checkbox select + nama dokumen +
      chevron, dan header ini **selalu tampil** baik saat collapsed maupun
      expanded (bukan cuma judul yang hilang-muncul) — jadi checkbox selalu
      di kiri nama dokumen di kedua state, sesuai yang diminta user. Klik di
      checkbox sendiri (`e.stopPropagation()`) tidak ikut toggle buka/tutup
      card.
    - **Body** (`.doc-accordion-body`, cuma kebuka kalau card expanded) berisi
      Date + Original File (`.doc-meta-row`) lalu baris tombol Download/
      Edit/Delete (`.doc-accordion-actions`) — ketiganya sekarang icon **+
      teks label** (sebelumnya cuma icon, `title` doang buat tooltip), dan
      **sejajar horizontal** (`display:flex; flex-direction:row`, bukan
      block/stack) — soalnya sebelumnya 3 tombol itu numpuk ke bawah gara-gara
      dulu ditaruh langsung di dalam `<td>` yang di-collapse jadi block oleh
      `responsive.css` (lihat catatan di bawah).
    - Card ini **tidak lagi pakai `.table-wrapper`/`<table>`** sama sekali,
      jadi tidak lagi kena aturan collapse mobile (`td[data-label]`) dari
      `responsive.css` untuk list dokumen — tapi table Bank Accounts (yang
      masih dikelompokkan per currency) **tetap** pakai `<table>` seperti
      semula, jadi tetap kena aturan itu.
  - **theme.css / responsive.css — TIDAK perlu diubah untuk fitur Settings
    ini, dan sengaja dihindari**: semua styling baru (dua card collapsible,
    accordion dokumen, override label vertikal utk mobile, dsb) ditaruh di
    `<style>` scoped di dalam `settings_content.php` sendiri, class-nya semua
    prefix `.settings-*`/`.doc-accordion-*` biar tidak tabrakan/tidak
    mempengaruhi menu lain. Alasannya:
    - `theme.css`/`responsive.css` itu **global**, dipakai semua menu
      (Transactions, Report, dst) — komponen accordion dokumen ini masih
      spesifik punya Settings, belum tentu bakal dipakai ulang di tempat lain.
    - Kalaupun ternyata pola accordion-card serupa dibutuhkan lagi di menu
      lain nanti, **baru saat itu** layak diangkat jadi class umum di
      `theme.css` (sama seperti keputusan yang sama untuk komponen lain di
      project ini — baca kalimat "baru buka `theme-dark-neomorphism.md`...
      kalau butuh komponen yang belum ada" di bagian atas notes ini).
    - Satu-satunya override yang **sengaja** menyasar `td[data-label]` bawaan
      `responsive.css` (supaya label di atas, value di bawah, bukan
      kiri-kanan) juga ditulis **scoped** lewat selector
      `.menu-section[data-section="settings"] td[data-label]` di dalam
      `settings_content.php` — bukan edit langsung ke `responsive.css` —
      justru supaya perubahan itu **tidak** ikut mempengaruhi tabel di
      Transactions/Report yang masih mengandalkan perilaku default
      (horizontal) dari `responsive.css`. File `responsive.css` sendiri
      sampai sekarang **belum disentuh sama sekali** oleh fitur Settings.
  - **Tombol Share via WhatsApp (dokumen & rekening) diubah jadi icon-only,
    dan cuma muncul kalau ada item yang diselect**:
    - Sebelumnya tombolnya selalu ada tapi `disabled` + ada teks "Share via
      WhatsApp". Sekarang class `.btn-wa-icon` (bulat, cuma ikon
      `ti-brand-whatsapp`, background hijau WA `#25d366`) dan **disembunyikan
      total** (`style.display = 'none'`) selama belum ada checkbox yang
      dicentang — bukan cuma di-`disabled`. Logic toggle-nya ada di
      `updateDocSelectionUI()` / `updateBankSelectionUI()`
      (`docShareBtn.style.display` / `bankShareBtn.style.display`, jadi
      `'inline-flex'` kalau `selected.length > 0`, `'none'` kalau tidak).
    - **Khusus dokumen**: toolbar "Select All" + tombol share digabung jadi
      satu baris (`.doc-list-toolbar`, `display:flex; justify-content:
      space-between`) — label "Select All" di kiri, "N selected" + tombol WA
      icon di kanan (`.doc-list-toolbar-right`). Div terpisah
      `.doc-accordion-toolbar-select` yang lama **sudah dihapus**, jangan
      dicari lagi.
    - **Bank Accounts**: karena tidak ada satu checkbox "Select All" tunggal
      (tiap grup currency punya select-all sendiri di header tabelnya
      masing-masing), toolbar-nya tetap terpisah di atas daftar grup
      (`.settings-list-toolbar`, cuma isinya sekarang "N selected" + tombol
      ikon WA yang sama, bukan lagi tombol teks).
  - **Company Bank Accounts — 3 penyesuaian form**:
    1. **Semua input teks di form ini dipaksa UPPERCASE** (Bank Name, Account
       Number, Account Name, SWIFT Code, Address) — bukan cuma visual lewat
       CSS (`.input-uppercase { text-transform: uppercase; }`), tapi
       benar-benar diubah value-nya lewat listener `input` (`el.value =
       el.value.toUpperCase()`, jaga posisi caret pakai `setSelectionRange`).
       Saat submit, payload juga di-`.toUpperCase()` lagi sebelum dikirim
       (jaga-jaga), dan **server-side** (`save_bank_account.php`) juga
       `mb_strtoupper()` semua field itu sebelum disimpan ke JSON — jadi
       uppercase-nya digaransi di 3 lapis (input, submit, server), bukan
       cuma titip ke JS di browser.
    2. **Account Number di-grouping tiap 4 karakter** pakai spasi
       (`1234 5678 9012 3456`) via `formatAccountNumberGroups()` — dipanggil
       baik saat user ngetik (listener `input` di `bankAccountNumber`,
       reformat ulang tiap keystroke + jaga posisi caret di akhir) maupun
       saat buka form Edit (`openEditBankForm()` format ulang value lama).
       Karakter non-alfanumerik dibuang dulu sebelum di-grouping ulang, jadi
       aman walau user paste nomor dengan format lain. Nilai yang **disimpan
       ke JSON juga sudah dalam bentuk grouped ini** (bukan di-strip lagi di
       backend) — jadi tampil konsisten grouped di form maupun di list/teks
       WA share, sesuai tujuan "mudah dicek & dibaca".
    3. **Field baru: Bank Name** (`bankName`, wajib diisi, disimpan sebagai
       `bank_name` di `json_file/bank_accounts.json`). Muncul di: form input
       (field pertama, sebelum Account Number), kolom baru paling kiri di
       tiap tabel grup currency (`renderBankGroups()`), dan baris baru di
       teks share WhatsApp rekening (`share_bank_accounts.php` otomatis ikut
       balikin field ini karena cuma nge-filter object JSON apa adanya, tidak
       perlu diubah). Data lama di `bank_accounts.json` yang belum punya
       `bank_name` (dibuat sebelum perubahan ini) akan tampil `-` di kolom
       itu sampai di-edit ulang — tidak ada migrasi otomatis.
  - **Bank Name & Account Name punya "memori" (autocomplete)**: pakai
    `<datalist>` HTML native (`#bankNameSuggestions`,
    `#bankAccountNameSuggestions`), diisi dari nilai unik yang sudah pernah
    tersimpan (`refreshBankSuggestions()`, dipanggil tiap habis
    `loadBankAccounts()` — jadi otomatis update begitu ada akun baru
    disimpan). Ini murni bawaan browser (muncul dropdown saran setelah user
    ngetik beberapa huruf), tidak butuh library tambahan atau endpoint baru.
  - **Account Number tidak boleh duplikat UNTUK BANK YANG SAMA** — dicek di
    server (`save_bank_account.php`), kombinasi **Bank Name + Account
    Number** (bukan Account Number sendirian — awalnya sempat cuma cek
    Account Number saja, itu salah dan sudah diperbaiki, karena dua bank
    berbeda bisa saja punya nomor rekening yang sama persis, itu normal).
    Account Number dibandingkan **setelah dinormalisasi** (buang semua
    karakter selain A-Z0-9, jadi `"1234 5678"` dan `"12345678"` dianggap
    sama), Bank Name dibandingkan uppercase. Saat mode edit, record yang
    sedang diedit sendiri di-skip dari pengecekan (supaya save ulang tanpa
    ubah nomor tidak dianggap duplikat). Kalau ketahuan sama persis (bank +
    nomor), balikin `success:false` dengan pesan "This account number
    already exists for this bank." — muncul di `bankFormMessage` (create)
    atau di modal password (`confirmModalError`, kalau ketahuan saat proses
    edit setelah password diverifikasi).
  - **Tombol Edit & Delete rekening**: sudah ada sejak awal fitur ini dibuat
    (lihat `renderBankGroups()`), bukan penambahan baru — sempat ditanyakan
    ulang oleh user dan dikonfirmasi sudah ada.
  - **Company Documents — hapus sekarang pindah ke recycle, bukan hapus
    permanen** (`ajax/delete_document.php`): mengikuti pola yang sama
    seperti `delete_customer.php` (lihat bagian atas notes ini — folder
    `storage/recycle/` sudah ada duluan untuk fitur Customer). Saat dokumen
    dihapus, record di tabel `company_documents` tetap **dihapus dari DB**
    (sama seperti sebelumnya), tapi file fisiknya **tidak lagi di-`unlink()`**
    — sekarang di-`rename()` (pindah) ke
    `storage/recycle/company/legal_document/`, dengan nama file diprefix
    `{document_id}_{timestamp}_` supaya tidak tabrakan kalau ada nama file
    yang sama pernah dihapus lebih dari sekali. Folder recycle ini otomatis
    dibuat kalau belum ada (`mkdir` rekursif), sama seperti pola di
    `delete_customer.php`.
    - **Belum ada** (sengaja ditunda, sama seperti catatan recycle Customer
      di atas): fitur restore dari recycle, retention/cleanup otomatis
      (auto-hapus setelah X hari), atau UI untuk lihat isi recycle. File yang
      sudah masuk situ murni "aman dari kehapus" untuk sementara, harus
      ditangani manual lewat filesystem kalau perlu dikembalikan atau
      dibersihkan.
  - **Icon font Tabler ternyata tidak lengkap untuk beberapa class** — sudah
    ketauan 2x: `ti-brand-whatsapp` (tombol share) dan `ti-plus` (tombol Add
    Currency) sama-sama tampil kotak kosong (tofu glyph) di browser. Kedua
    tombol itu sekarang pakai **SVG inline** langsung di `settings_content.php`
    (bukan `<i class="ti ...">` lagi), jadi tidak bergantung sama sekali ke
    ketersediaan icon font. **Catatan untuk ke depan**: kalau nanti nambah
    tombol dengan icon baru dan ternyata muncul kotak kosong juga, kemungkinan
    besar itu class `ti-*` yang tidak ada di versi font yang di-load project
    ini — solusinya sama, ganti ke SVG inline, jangan asumsikan semua class
    Tabler Icons otomatis tersedia (icon non-brand yang dipakai sejauh ini —
    `ti-search`, `ti-settings`, `ti-pencil`, `ti-trash`, `ti-download`,
    `ti-chevron-down`, dll — semuanya aman/terbukti render, tapi belum tentu
    seluruh katalog Tabler Icons ikut ter-bundle).
  - **List Bank Accounts diubah dari table jadi accordion card, PERSIS
    seperti Company Documents** (atas permintaan user eksplisit "sama
    persis"): `renderBankGroups()` sekarang membangun elemen dengan class
    yang **sama persis dipakai ulang** dari accordion dokumen
    (`.doc-accordion`, `.doc-accordion-item`, `.doc-accordion-header`,
    `.doc-accordion-name`, `.doc-accordion-chevron`, `.doc-accordion-body`,
    `.doc-accordion-body-inner`, `.doc-meta-row`, `.doc-accordion-actions`)
    — bukan bikin CSS/class baru yang mirip-mirip, tapi betul-betul class
    yang sama, supaya tampilannya identik tanpa duplikasi style.
    - **Pengelompokan per currency tetap ada** (`.settings-bank-group`),
      tapi sekarang tiap grup currency = judul + `.doc-accordion` list
      sendiri, bukan `<table>` lagi.
    - **Header tiap grup** (`.settings-bank-group-header`, flex
      `justify-content:space-between`) isinya nama currency di kiri + label
      "Select All" (checkbox) di kanan — select-all ini **scoped per grup**
      (cuma centang semua checkbox item di dalam grup currency itu saja,
      bukan lintas grup) — mempertahankan perilaku select-all per grup yang
      sudah ada sejak versi table sebelumnya.
    - **Collapsed**: header card = checkbox + label `"{Bank Name} —
      {Account Number}"` (mis. `"BCA — 1234 5678 9012"`) + chevron — dipilih
      supaya langsung kelihatan bank & nomornya tanpa perlu expand, karena
      itu yang paling sering dicari user secara sekilas.
    - **Expanded**: body berisi Account Name, Currency, dan SWIFT/Address
      (cuma muncul kalau bukan IDR) sebagai `.doc-meta-row`, lalu tombol
      Edit/Delete sejajar horizontal dengan label teks (`.doc-accordion-
      actions`), sama seperti dokumen.
    - **Hanya satu card yang boleh terbuka dalam satu waktu** — tapi
      cakupannya **lintas semua grup currency sekaligus** (`openBankItem()`
      / `closeBankItem()` query ke `bankGroupsWrapper` secara keseluruhan,
      bukan per grup), konsisten dengan aturan accordion dokumen &
      dua card besar Settings di level atas.
    - Card ini juga sudah tidak pakai `<table>`/`.table-wrapper` sama
      sekali, jadi (sama seperti dokumen) sudah lepas dari aturan collapse
      mobile `td[data-label]` milik `responsive.css` — override khusus yang
      sebelumnya ditulis untuk itu jadi otomatis tidak lagi relevan buat
      Bank Accounts (masih relevan kalau suatu saat ada tabel lain di
      Settings).

  - **Menu Logistic ditambahkan**, sejajar Dashboard/Transactions/Report di
    sidebar & bottom-nav (`logistic_content.php`, di-include dari `index.php`
    antara Transactions dan Report — pola wrap-`<div class="menu-section"
    data-section="logistic">` sendiri, sama seperti Transactions).
    - **Dua tab**: `Logistic List` (preview, **default aktif** saat menu
      dibuka — sengaja dinamai begini, bukan "Preview Logistic", supaya
      konsisten dengan pola "List" di tempat lain) dan `Create New
      Logistic`.
    - **Saat ini logistic cuma didukung untuk departemen `dates`** (key JSON
      di `departments.json` memang `dates`, jamak, bukan `date`) —
      departemen lain tampil di dropdown tapi ditandai "(not available
      yet)" dan Activity Code-nya tidak bisa dipilih. Kalau nanti departemen
      lain mau diaktifkan, cari `LOG_SUPPORTED_DEPARTMENT` di
      `logistic_content.php` dan validasi department di
      `ajax/create_logistic.php`.
    - **Activity Code dipilih dari list klik (bukan `<select>`)** —
      `ajax/list_logistic_activities.php` mengembalikan semua activity code
      departemen terpilih dengan flag `has_logistic`; yang sudah punya
      logistic tampil redup/disabled dengan label "(logistic already
      exists)" (class `.log-code-option.disabled`), yang belum tampil
      normal dan bisa diklik untuk pilih.
    - **Import Document**: bagian collapsible (default collapsed, toggle
      manual lewat `#logDocToggle`/`#logDocChevron`/`#logDocBody`, BUKAN
      pakai fungsi accordion item yang sama dengan Logistic List — beda
      mekanisme karena ini cuma satu section, bukan list banyak item).
      Ada 3 grup dokumen (Shipper/Custom/Consignee), upload lewat
      `ajax/upload_logistic_document.php` yang otomatis bikin folder
      `{relative_path activity}/import_documents/{type}/` kalau belum ada,
      lalu simpan baris ke `logistic_documents` (key `activity_id`, BUKAN
      `logistic_id` — sengaja, supaya dokumen bisa diupload sebelum tombol
      Save utama logistic ditekan).
    - **Primary/Secondary Packaging**: qty Primary diinput manual, qty
      Secondary **selalu auto-hitung** dari `primary_qty × ratio_per_primary`
      punya unit yang dipilih — user tidak pernah input qty secondary
      manual. Berat total kedua-duanya juga auto-hitung di client
      (`recalcPackaging()`) untuk preview real-time, tapi **field weight
      total TIDAK dikirim ke server** — server cuma menyimpan rate
      (`primary_qty`, `primary_unit_weight_kg`, `secondary_ratio_per_primary`,
      `secondary_unit_weight_kg`) dan totalnya dihitung ulang tiap kali
      `ajax/list_logistics.php` dipanggil, supaya tidak ada data
      kalkulasi yang tersimpan.
    - **Satuan Primary/Secondary dikelola lewat program**, BUKAN edit
      manual `json_file/primary_packaging_units.json` /
      `secondary_packaging_units.json` — kedua file itu sekarang cuma
      storage, satu-satunya cara mengubah isinya adalah tombol **Edit** di
      sebelah masing-masing dropdown satuan, yang membuka fly window
      (`.modal-overlay`, reuse class yang sudah ada) untuk
      Add/Edit/Delete via `ajax/manage_packaging_units.php?kind=primary|
      secondary`. Tiap unit punya `id` stabil di JSON (bukan index array)
      supaya edit/delete tidak salah sasaran kalau ada unit lain
      dihapus duluan.
    - **DB: 3 tabel tetap** di `lisani_aos_db` (`sql/lisani_aos_logistics.sql`),
      **jumlahnya TIDAK bertambah** seiring bertambahnya activity code —
      dibedakan lewat kolom relasi, bukan tabel per activity code:
      - `logistics` — 1 baris per activity code yang sudah dibuatkan
        logistic (`UNIQUE(activity_id)`). Cuma simpan rate/snapshot:
        `primary_qty`, `primary_unit_label`, `primary_unit_weight_kg`,
        `remaining_primary_qty`, `secondary_unit_label`,
        `secondary_unit_weight_kg`, `secondary_ratio_per_primary`.
        **Tidak ada** kolom `primary_total_weight_kg` / `secondary_qty` /
        `secondary_total_weight_kg` — itu semua dihitung program, bukan
        kolom DB (versi awal sempat ada, sudah dihapus di v2 SQL).
      - `remaining_primary_qty` — nilai awal = `primary_qty` saat
        create, nantinya berkurang/bertambah tiap ada baris baru di
        `logistic_movements` (out mengurangi, in menambah). **UI untuk
        menambah movement belum dibuat** — baru kolomnya saja yang
        disiapkan & di-set awal di `ajax/create_logistic.php`.
      - `logistic_documents` — many-to-one ke `activities` lewat
        `activity_id` (bukan ke `logistics`), simpan lokasi file saja
        (`file_path`), bukan isi filenya.
      - `logistic_movements` — many-to-one ke `logistics` lewat
        `logistic_id`. Satu activity code (via satu baris `logistics`)
        bisa punya banyak baris movement seiring waktu (tiap barang
        masuk/keluar = 1 baris baru, BUKAN update baris yang sama).
        Kolom sudah siap (`movement_type` in/out, `movement_date`,
        `customer_name`, `driver_name`, `police_number`,
        `qty_primary_package`) tapi **belum ada UI/endpoint** untuk
        insert — itu rencananya jadi tab ketiga di menu Logistic nanti,
        dan setiap insert baru wajib juga update
        `logistics.remaining_primary_qty` (in: `+qty`, out: `-qty`) dalam
        satu transaksi DB biar tidak pernah out-of-sync.
      - Kalau `logistics`/dkk sudah pernah dibuat dari SQL versi pertama
        (yang masih ada `primary_total_weight_kg` dkk, belum ada
        `remaining_primary_qty`), jalankan bagian **MIGRATION** (blok
        `ALTER TABLE ... IF EXISTS/IF NOT EXISTS`) di
        `sql/lisani_aos_logistics.sql`, bukan jalankan ulang `CREATE
        TABLE`-nya.
    - **Icon sidebar/bottom-nav Logistic**: `ti-truck` juga tofu (kotak
      kosong) seperti `ti-brand-whatsapp`/`ti-plus` sebelumnya — sudah
      diganti SVG inline langsung di `partials/sidebar.php`. Kalau nanti
      nambah icon baru dan tofu lagi, langsung asumsikan itu class yang
      tidak ke-bundle, jangan coba nama class Tabler lain dulu — langsung
      SVG inline saja.
    - **Activity Code picker diganti jadi `<select>` biasa, sama seperti
      Department** — sebelumnya `#logActivityCodeList` (div `.log-code-option`
      yang diklik satu-satu, dengan class `.selected`/`.disabled`). Sekarang
      `<select id="logActivityCode">` polos di `logistic_content.php`, opsi
      di-generate dari response `list_logistic_activities.php` (label
      `"{year} — {activity_code} — {activity_name}"`), code yang sudah
      punya logistic (`has_logistic`) di-set `disabled` di level `<option>`
      (bukan lagi class CSS terpisah). CSS `.log-code-option` (beserta
      turunannya `.selected`/`.disabled`/`:hover`) sudah dihapus, tidak
      dipakai lagi.
      - `selectedActivity` sekarang di-set lewat event `change` pada
        `<select>`-nya (bukan `click` per-item), diambil dari map
        `activityById[id]` yang diisi ulang tiap kali `loadActivityCodes()`
        jalan — supaya kode-kode lain yang bergantung pada bentuk object
        `selectedActivity` (submit create logistic, load/upload dokumen)
        tidak perlu berubah sama sekali.
      - `resetCreateLogisticForm()` disesuaikan: reset `logActivityCode.value
        = ''` (bukan lagi hapus class `.selected` dari elemen div).
      - Saat department dipilih yang belum didukung Logistic, select-nya
        ikut di-`disabled` (sebelumnya cuma pesan empty-state yang tampil,
        list div-nya otomatis kosong karena belum di-render).
    - **Semua input teks bebas di form Logistic dipaksa uppercase saat
      diketik, kecuali yang berkaitan dengan folder/path fisik** (aturan
      global baru, ikuti konvensi `departments.json` `key` yang sengaja
      tetap huruf kecil karena jadi nama folder):
      - Field yang di-uppercase: `logDocName` (Document Name), 
        `primaryUnitNewLabel`, `secondaryUnitNewLabel` — ditandai class CSS
        `.input-uppercase` di `logistic_content.php`, dipaksa uppercase via
        satu listener `input` global (`querySelectorAll('.input-uppercase')`,
        di awal `<script>`) yang me-replace `el.value` dan menjaga posisi
        kursor (`selectionStart`/`setSelectionRange`), plus CSS
        `text-transform: uppercase` untuk tampilan. Pola ini jadi standar
        untuk field free-text baru ke depan: cukup tambah class
        `input-uppercase`, tidak perlu listener baru per field.
      - **`logDocName` sengaja tetap termasuk yang di-uppercase** meskipun
        nilainya dipakai server untuk membangun nama file fisik di storage
        — karena `upload_logistic_document.php` sudah **`strtolower()`**
        nama file fisiknya secara terpisah (lihat entri sebelumnya di atas),
        uppercase di form ini tidak konflik dengan aturan "kecuali yang
        berkaitan dengan folder".
      - **Server-side juga ikut di-uppercase sebagai safety net** (client
        JS bisa saja dilewati — request langsung/JS disabled):
        `upload_logistic_document.php` sekarang `strtoupper()` 
        `$documentName` sebelum dipakai untuk cek duplikat & disimpan ke
        kolom `document_name` (nama file fisik tetap lowercase, tidak
        terpengaruh — itu proses terpisah setelahnya). `create_logistic.php`
        sekarang `strtoupper()` `primary_unit_label` dan
        `secondary_unit_label` sebelum di-INSERT.
      - **Belum sempat disentuh** (file-nya belum ada/di-upload ke chat
        ini): `ajax/manage_packaging_units.php` — endpoint yang benar-benar
        menyimpan unit label baru (dipanggil dari `primaryUnitNewLabel`/
        `secondaryUnitNewLabel` submit) belum diperiksa/di-uppercase-kan di
        sisi server-nya. Kalau nanti diminta lanjutkan aturan uppercase ini
        secara konsisten, mulai dari situ.
    - **Fix: list "sudah diupload" di card Import Document tidak kelihatan
      setelah upload sukses, padahal sudah ter-render** — `logDocUploadedList`
      (list collapsible dokumen yang sudah diupload untuk activity code
      terpilih) ada **di dalam** `logDocBody`, panel collapsible yang sama
      dengan form upload-nya. `logDocBody` pakai pola `max-height =
      scrollHeight` yang **cuma dihitung ulang saat toggle diklik**
      (`logDocToggle` click handler) — jadi kalau panel sudah terbuka lalu
      user upload dokumen baru, `renderUploadedDocuments()` berhasil
      menambah baris ke DOM, tapi `max-height` lama (dihitung sebelum ada
      baris itu) tetap kepakai → baris barunya ada tapi terpotong/ketutup
      `overflow:hidden`, kelihatan seperti "tidak muncul". Fix-nya:
      `renderUploadedDocuments()` sekarang recalculate
      `logDocBody.style.maxHeight = logDocBody.scrollHeight + 'px'` di
      akhir fungsi, tapi **hanya kalau `logDocOpen` true** (panel sedang
      terbuka) — supaya tidak korupsi state collapsed (`max-height:0px`)
      kalau `loadUploadedDocuments()`/`renderUploadedDocuments()` dipanggil
      saat panel masih tertutup (misal langsung setelah pilih Activity
      Code, sebelum user buka card Import Document sama sekali).
      **Pola ini perlu diingat untuk collapsible lain ke depan**: kalau ada
      collapsible yang isinya bisa berubah (bukan cuma dibuka/ditutup),
      apa pun yang mengubah kontennya harus ikut resync `max-height`
      (dengan syarat panel-nya sedang terbuka), bukan cuma toggle click
      handler-nya saja.
    - **Primary/Secondary Qty sekarang dua arah, bukan cuma Primary →
      Secondary** — sebelumnya `logSecondaryQty` selalu `disabled`
      (`placeholder="Qty (auto)"`), cuma bisa dihitung dari Primary Qty ×
      rasio unit. Sekarang `logSecondaryQty` jadi input teks biasa
      (`placeholder="Qty"`, tidak lagi `disabled` secara statis di HTML).
      Arahnya ditentukan **otomatis dari field mana yang mulai diisi
      duluan** (pola yang sama dipakai lagi seperti kasus lain di project
      ini — konsisten dengan prinsip "yang diisi duluan jadi sumber"):
      - Variabel state baru `qtySource` (`'primary'` / `'secondary'` /
        `null`) di-set di listener `input` masing-masing qty field —
        kalau field itu ditulisi jadi sumbernya, kalau dikosongkan lagi
        `qtySource` balik `null` (unlock kedua field).
      - `updateQtySourceLock()`: men-disable field qty yang BUKAN sumber
        selama `qtySource` bukan `null`; kalau `null`, dua-duanya
        enabled/disabled ikut `selectedActivity` seperti biasa (dipanggil
        juga dari `updatePackagingAvailability()` supaya lock-nya
        ter-reapply kalau Activity Code diganti).
      - `recalcPackaging()` ditulis ulang jadi dua cabang: kalau
        `qtySource === 'secondary'`, Primary Qty dihitung mundur
        (`secondaryQty / ratio`) dan ditulis ke `logPrimaryQty.value`;
        selain itu (termasuk `null`, default) tetap seperti sebelumnya,
        Primary → Secondary. Primary Weight & Secondary Weight tetap
        selalu dihitung dari qty efektif masing-masing × `weight_kg`
        unitnya — logikanya sendiri tidak berubah, cuma qty sumbernya
        yang bisa dari dua arah sekarang.
      - **Bug kecil yang ikut kebenerin saat nulis arah baru ini**: hasil
        hitung-mundur Primary Qty sempat ditulis pakai
        `toLocaleString('en-US')` (ada pemisah ribuan seperti `1,234.5`)
        — ini akan lolos ke `create_logistic.php` sebagai `primary_qty`
        (dikirim apa adanya dari `logPrimaryQty.value`, lihat payload
        submit) dan gagal validasi `is_numeric()` di server untuk angka
        besar. Sudah diganti `toFixed(3)` + strip trailing zero
        (`.replace(/\.?0+$/, '')`), tanpa pemisah ribuan. **Field lain
        yang masih pakai `toLocaleString('en-US')`
        (`logSecondaryQty`/`logPrimaryWeight`/`logSecondaryWeight`) aman
        dibiarkan** karena field-field itu tidak pernah dikirim ke server
        apa adanya (lihat komentar di payload submit: "Only rates are
        sent — totals ... calculated by the server").
      - `resetCreateLogisticForm()` ikut di-update: reset `qtySource =
        null` dan panggil `updateQtySourceLock()` supaya kedua qty field
        ter-unlock lagi untuk entry berikutnya.
    - **Fix: Edit unit (Manage Primary/Secondary Units) tidak kasih feedback
      apa pun setelah Save berhasil** — Save handler di `startEditUnitRow()`
      (dipanggil dari tombol Edit tiap baris unit) sebenarnya **sudah**
      berhasil menyimpan dan me-refresh list (`renderUnitManageList()` +
      `refreshUnitSelects()`), cuma tidak ada indikasi visual apa pun kalau
      request-nya sukses — jadi kelihatan seperti tidak terjadi apa-apa.
      Sekarang di-tambah pesan sukses singkat ("Unit updated."), reuse
      elemen `primaryUnitError`/`secondaryUnitError` yang sudah ada (dipakai
      dual-purpose: hijau + "Unit updated." selama 2 detik lalu
      auto-hide via `setTimeout`, atau merah + pesan error seperti
      sebelumnya kalau gagal).
    - **Fix: Delete unit sama persis kasusnya dengan Edit unit di atas** —
      `deleteUnit()` juga sudah berhasil menghapus dan me-refresh list
      (`renderUnitManageList()` + `refreshUnitSelects()`) dari dulu, cuma
      tanpa feedback visual apa pun, jadi row yang terhapus kelihatan
      "masih ada" sampai modal ditutup baru ketahuan sudah hilang. Dicek
      juga tidak ada `max-height`/`overflow` di `.modal-body` atau
      `.log-unit-row` yang bisa nyebabin clipping seperti kasus
      `logDocBody` sebelumnya — murni tidak ada feedback saja. Fix-nya
      sama seperti Edit: tambah pesan sukses "Unit deleted." (hijau, reuse
      `primaryUnitError`/`secondaryUnitError`, auto-hide 2 detik).
    - **Helper text packaging diganti jadi ikon info "!" bulat, klik untuk
      buka** — sebelumnya kalimat "Fill in either Primary or Secondary
      Qty — the other one is calculated automatically..." nampil permanen
      di bawah form Secondary Packaging (`.empty-sub` statis). Sekarang
      dihapus dari situ, diganti `<span class="info-icon">!</span>`
      (CSS baru, class `.info-icon`) di sebelah label **Primary Packaging**
      dan **Secondary Packaging** masing-masing (dua icon terpisah, isi
      tooltip-nya sama persis). Klik/tap (atau Enter/Space kalau fokus
      keyboard, ada `tabindex="0"`) toggle class `.open` di
      `.info-tooltip` yang berhubungan (`logPrimaryInfoTooltip` /
      `logSecondaryInfoTooltip`) — collapsed by default (`display:none`
      dari CSS `.info-tooltip`, baru `display:block` saat `.open`).
      **Konvensi baru untuk helper text serupa ke depan**: kalau helper
      text-nya cuma relevan untuk sebagian user / bisa bikin form berasa
      penuh, pertimbangkan pola icon info "!" + tooltip klik ini
      (`.info-icon` + `.info-tooltip`), bukan `.empty-sub` statis yang
      selalu tampil.
    - **Semua input angka di form Logistic sekarang auto-format koma ribuan
      saat mengetik** (`logPrimaryQty`, `logSecondaryQty`,
      `primaryUnitNewWeight`, `secondaryUnitNewRatio`,
      `secondaryUnitNewWeight`, plus `weightInput`/`ratioInput` dinamis di
      `startEditUnitRow()`):
      - Field-field ini diubah dari `type="number"` (yang **tidak bisa**
        menampilkan koma sama sekali — browser menolak karakter non-digit)
        jadi `type="text" inputmode="decimal"` + class baru
        `.input-number-comma` (`inputmode` supaya keyboard numerik tetap
        muncul di HP meski `type` bukan `number`).
      - Helper baru di awal `<script>`: `formatNumberInput(raw)` (susun
        ulang jadi string dengan koma ribuan, cursor-safe),
        `parseNumberInput(raw)` (strip koma balik jadi angka polos untuk
        dipakai di kalkulasi/dikirim ke server — **selalu dipakai ganti
        `parseFloat(el.value)` untuk field ber-class ini**, jangan baca
        `.value` mentah), dan `initNumberCommaInput(el)` (pasang listener
        `input` yang format on-the-fly, jaga posisi kursor dengan hitung
        mundur jumlah digit sebelum kursor — pola sama dengan listener
        `.input-uppercase` yang sudah ada). Semua elemen `.input-number-comma`
        yang ada di HTML saat load di-`querySelectorAll` otomatis; elemen
        yang dibuat dinamis (`startEditUnitRow()`) manggil
        `initNumberCommaInput()` sendiri saat dibuat.
      - **Semua pemakai field-field ini disesuaikan** supaya baca lewat
        `parseNumberInput()` dan tulis hasil kalkulasi lewat
        `formatNumberInput()`: `recalcPackaging()` (dua arah, Primary↔
        Secondary), payload submit `btnLogCreateSubmit` (`primary_qty`
        di-`parseNumberInput()` dulu sebelum masuk `URLSearchParams`, biar
        tidak kekirim ke server dengan koma), Add Unit handler primary &
        secondary, Save handler `startEditUnitRow()`.
      - **Server-side**: `create_logistic.php` sekarang
        `str_replace(',', '', ...)` di `primary_qty`,
        `primary_unit_weight_kg`, `secondary_unit_weight_kg`,
        `secondary_ratio_per_primary` sebelum validasi `is_numeric()` —
        safety net kalau request bypass client JS (klien sebenarnya sudah
        kirim angka bersih via `parseNumberInput()`, tapi tidak ada
        salahnya jaga-jaga di server juga, sama pola dengan
        `strtoupper()` sebelumnya).
      - **Belum sempat disentuh**: `ajax/manage_packaging_units.php` (yang
        terima `weight_kg`/`ratio_per_primary` dari Add/Edit Unit) belum
        ada strip-koma server-side-nya — file-nya belum di-upload ke chat
        ini. Kalau nanti diminta lanjutkan safety net ini secara
        konsisten, mulai dari situ (sama seperti catatan uppercase
        sebelumnya untuk file yang sama).
    - **Sempat dicurigai ada bug "activity code cuma satu yang muncul"**
      di picker Create New Logistic — sudah ditelusuri sampai ke query
      `list_logistic_activities.php` (tidak ada `LIMIT`, `LEFT JOIN` ke
      `logistics` tidak mengurangi baris) dan loop render di
      `logistic_content.php` (`res.data.forEach()`, tidak ada slice/filter)
      — **tidak ditemukan bug di kedua file itu**. Kemungkinan penyebabnya
      saat itu memang baru ada satu activity code untuk departemen `dates`
      di database, bukan bug software. Kalau muncul lagi dengan activity
      code `dates` yang jumlahnya sudah lebih dari satu di DB, curigai
      dulu `relative_path` yang ke-generate salah dari
      `create_activity_code.php` (typo atau format beda dari
      `input/[year]/[dept]/[code]/`), bukan file-file yang sudah dicek
      di atas.
    - **Import Document — nama file di server sekarang ikut Document
      Name (huruf kecil), bukan nama file asli** —
      `ajax/upload_logistic_document.php`: `$storedName` (yang jadi
      `file_path` di kolom `logistic_documents`) sebelumnya dibangun dari
      `pathinfo($_FILES['file']['name'], PATHINFO_FILENAME)` (nama file
      upload asli). Sekarang dibangun dari `$documentName` (input user di
      field Document Name), disanitasi (`[^A-Za-z0-9_\-]` jadi `_`) lalu
      di-`strtolower()`. Ekstensi tetap dari file asli, juga di-lowercase.
      Prefix timestamp (`date('YmdHis')`) tetap dipertahankan supaya
      re-upload/nama dokumen yang dipakai ulang tidak saling menimpa file
      fisiknya. Kolom `document_name` di DB (yang dipakai untuk cek
      duplikat & ditampilkan di UI) **tidak berubah** — tetap simpan versi
      asli dari input user (termasuk huruf besar/kecilnya), cuma nama
      file fisik di storage yang di-lowercase.
    - **Logistic List: tombol Edit & Delete ditambahkan, keduanya gated
      password reverify** — dua file baru: `ajax/update_logistic.php` dan
      `ajax/delete_logistic.php`, plus perubahan besar di
      `logistic_content.php`.
      - **Pola verifikasi yang dipakai**: `verify_password.php` +
        `aos_require_recent_reverify()` (window 120 detik,
        `ajax/_require_reverify.php`) — **bukan** pola
        `delete_activity_code.php`/`delete_customer.php` (`password_verify()`
        langsung di endpoint aksi). Ini pilihan sadar user, dikonfirmasi
        eksplisit saat fitur ini diminta — dicatat di sini supaya kalau ada
        fitur destructive baru lagi ke depan dan user tidak menyebutkan
        pola mana, **tanya dulu**, jangan asumsikan salah satu (ini fitur
        ketiga yang butuh delete-with-password setelah Activity Code dan
        Company Documents, dan sekarang makin jelas keduanya
        hidup berdampingan sebagai pilihan sadar per-fitur, bukan salah
        satu yang "benar").
      - **Response contract endpoint baru berbeda dari
        `create_logistic.php`/dkk yang sudah ada**: kalau reverify
        expired/belum pernah, `aos_require_recent_reverify()` langsung
        `echo` + `exit` dengan bentuk `{success:false, message:...}`
        (bukan `{ok:false,...}` seperti pola endpoint lain di app ini) —
        ini API kontrak dari `_require_reverify.php` yang sudah ada
        duluan, tidak diubah. Endpoint sendiri (setelah lolos reverify)
        tetap balikin `{ok:true/false,...}` seperti biasa. **Client-side
        HARUS cek `res.success === false` DULU sebelum cek `res.ok`** —
        kalau lupa, response reverify-expired akan salah diparse sebagai
        error endpoint biasa. Pola ini dipakai di kedua handler Save/Delete
        di `logistic_content.php`.
      - **`update_logistic.php`**: update rate-columns yang sama dengan
        `create_logistic.php` (`incoming_date`, `primary_qty`,
        `primary_unit_label`, `primary_unit_weight_kg`,
        `secondary_unit_label`, `secondary_unit_weight_kg`,
        `secondary_ratio_per_primary`), **kecuali Activity Code sendiri
        tidak bisa diubah** (`UNIQUE(activity_id)` di tabel `logistics`,
        ganti activity code berarti pindah row ke code lain sama sekali,
        bukan makna "edit"). `remaining_primary_qty` dihitung ulang kalau
        `primary_qty` berubah, **dengan offset tetap, bukan rasio**:
        `used = primary_qty_lama - remaining_qty_lama`, lalu
        `remaining_baru = primary_qty_baru - used`. Kalau hasilnya negatif
        (primary_qty baru lebih kecil dari yang sudah "keluar"), ditolak
        dengan pesan error, tidak dipaksa jadi 0 atau negatif. Contoh dari
        diskusi: qty lama 1000, remaining lama 900 (sudah keluar 100) →
        edit qty jadi 1100 → remaining baru = 1100 - 100 = 1000.
      - **`delete_logistic.php`**: **tidak menghapus activity code**, cuma
        row `logistics` + semua row `logistic_documents` untuk activity_id
        itu. Urutan: DELETE dari DB dulu (dalam transaction,
        `begin_transaction()`/`commit()`/`rollback()`) — baru kalau DB
        berhasil, folder fisik `import_documents/` di bawah activity code
        itu **dipindah** (bukan dihapus) ke
        `AOS_STORAGE_BASE/recycle/{relative_path}import_documents/` —
        pola sama persis dengan `delete_activity_code.php` (pertahankan
        segmen `input/[year]/[dept]/[code]/` di path recycle, tabrakan
        nama folder tujuan ditambah suffix `-YmdHis`). Kalau folder tidak
        ada sama sekali (logistic tanpa dokumen ter-upload), `folder_action`
        balik `'none'`, tidak dianggap error.
      - **`notes.txt` di dalam folder recycle** — sesuai diskusi eksplisit
        saat fitur ini diminta: file teks polos ditulis **di dalam folder
        yang baru dipindah ke recycle** (jadi ikut folder itu, bukan
        entry DB terpisah — file INI yang jadi satu-satunya "audit trail"
        untuk penghapusan logistic, tidak ada tabel log di database).
        Isinya: waktu hapus, `user_id` yang menghapus, activity
        code/name, semua rate-column logistic yang dihapus, dan daftar
        semua dokumen yang ikut dipindah (type, name, date, path aslinya)
        — snapshot data logistic-nya **diambil dari DB SEBELUM DELETE
        dijalankan**, bukan dari file fisik (jadi tetap akurat meskipun
        foldernya kosong/gagal dipindah).
      - **UI baru di `logistic_content.php`**:
        - Setiap baris di accordion Logistic List sekarang punya baris
          tombol Edit + Delete di bagian bawah body-nya (`actionsRow`,
          `e.stopPropagation()` supaya klik tombol tidak ikut
          toggle/collapse accordion row-nya).
        - Tombol Delete **sekarang pakai `.btn-danger` asli** — sempat pakai
          `.btn-secondary` + inline `color` sebagai workaround karena
          `theme.css` belum di-upload ke chat ini untuk dicek. Setelah
          `theme.css` di-upload, terkonfirmasi `.btn-danger` memang ada
          (`background: var(--danger); color: #fff;`), jadi workaround-nya
          dicabut. Sekaligus tombol Edit/Delete di tiap baris Logistic
          List, dan tombol Delete di modal konfirmasi (`btnLogDeleteConfirm`,
          sempat override `background`/`border-color` inline, sekarang
          cukup `class="btn btn-danger"`), dikecilkan ukurannya
          (`padding:var(--space-2) var(--space-3); font-size:var(--text-sm)`)
          — default `.btn` cukup besar untuk tombol utama, kekecilan untuk
          sepasang tombol berdampingan di dalam accordion row — dan
          ditambah `justify-content:center` karena `.btn` di `theme.css`
          cuma set `align-items:center` (vertical), bukan
          `justify-content:center` (horizontal), jadi teksnya rata kiri
          kalau lebar tombolnya dipaksa (`flex:1`) melebihi lebar teksnya
          sendiri.
        - 3 modal baru, pola sama (`.modal-overlay`/`.modal`/
          `.modal-header`/`.modal-body`/`.modal-footer`) dengan Manage
          Units yang sudah ada duluan:
          `#logReverifyOverlay` (satu modal password, dipakai bareng
          untuk Edit maupun Delete — `pendingLogisticAction` +
          `pendingLogisticRow` nyimpen aksi mana & baris mana yang lagi
          diproses, di-set di `openLogisticReverify()`), `#logEditOverlay`
          (form edit, field & kalkulasi qty dua-arahnya **duplikat
          terpisah** dari form Create — `logEditPrimaryQty`/
          `logEditSecondaryQty`/dst, `editQtySource` state sendiri,
          `recalcEditPackaging()` fungsi sendiri — sengaja tidak reuse
          elemen form Create karena keduanya modal berbeda yang bisa
          saja perlu tampil grafik yang berbeda ke depan; kalau dirasa
          duplikasinya mengganggu, bisa direfactor jadi satu form
          reusable nanti), `#logDeleteOverlay` (cuma teks konfirmasi +
          hitungan jumlah dokumen yang akan pindah, dibangun on-the-fly
          di `openDeleteLogistic()` dari `row.documents.{shipper,custom,
          consignee}`).
        - **Unit select di form Edit** (`logEditPrimaryUnit`/
          `logEditSecondaryUnit`) diisi ulang tiap kali modal dibuka
          (`Promise.all` dua fetch ke `manage_packaging_units.php`), lalu
          unit yang sudah tersimpan di row (`row.primary_unit_label`,
          string label) dicocokkan ke `<option>` yang match via
          **`selectUnitByLabel()`** — helper baru yang bandingkan
          `data-label` tiap option (bukan langsung `select.value = label`,
          karena `<option value>` di form ini isinya **unit ID**, bukan
          label — lihat `fillUnitSelect()`).
      - **Belum ada** (di luar scope yang diminta): fitur restore dari
        recycle untuk logistic yang terhapus (sama seperti catatan recycle
        Activity Code/Customer/Company Documents sebelumnya — retention/
        cleanup/restore semuanya masih tertunda, bukan cuma untuk
        Logistic).
  - **Fix — Primary/Secondary unit duplicate check jadi field-aware**
    (`ajax/manage_packaging_units.php`): sebelumnya `log_units_label_taken()`
    cuma bandingin `label` (case-insensitive) doang, jadi "Master Carton
    @ 12 kg" dan "Master Carton @ 10 kg" ketolak sebagai duplikat padahal
    beratnya beda. Diganti jadi `log_units_taken()` — duplikat cuma
    kalau **label DAN weight_kg** sama persis (primary), atau **label DAN
    weight_kg DAN ratio_per_primary** sama persis (secondary). Berlaku di
    action `add` maupun `edit`. Pesan error juga disesuaikan
    ("Satuan dengan nama, berat[, dan rasio] yang sama sudah ada.").
  - **Fix — tombol "Add Document" baru, sejajar Edit/Delete di tiap row
    Logistic List** (`logistic_content.php`): sebelumnya upload Import
    Document cuma bisa lewat tab **Create New Logistic**, dan begitu
    logistic activity code itu sudah tersimpan, opsinya di-disable di
    dropdown Activity Code (`has_logistic` → `opt.disabled = true`) — jadi
    tidak ada jalan lagi buat nambah dokumen impor susulan untuk logistic
    yang sudah ada.
    - Modal baru **`#logAddDocOverlay`** (field sama persis dengan panel
      Import Document di tab Create: Document Group/Name/Date/File),
      dibuka langsung dari tombol **Add Document** di action row
      accordion `logPreviewList` (sejajar dengan Edit/Delete, bukan
      menggantikan), target ke `activity_id` row itu langsung (dari
      `list_logistics.php`, field `activity_id` sudah ada di tiap row) —
      tidak lewat dropdown Activity Code sama sekali, jadi tidak
      kena-block oleh `has_logistic`.
    - **Tidak pakai password reverify** — sama seperti Import Document di
      tab Create (`upload_logistic_document.php` yang dipanggil juga
      persis sama), beda dari Edit/Delete logistic yang wajib
      `aos_require_recent_reverify()`. Ini keputusan eksplisit dari user,
      bukan default kebiasaan project.
    - Modal nampilin histori dokumen yang sudah ada juga (fetch
      `list_logistic_documents.php?activity_id=`), reuse pola row yang
      sama dengan `logDocUploadedList` di tab Create tapi elemen/variabel
      terpisah (`logAddDocUploadedList`, dst) — supaya tidak bentrok state
      kalau kedua form (tab Create & modal row) kebetulan kebuka
      bersamaan.
    - Saat modal ditutup (**Close**), `loadLogisticList()` dipanggil ulang
      supaya angka "Documents: Shipper: x · Custom: x · Consignee: x" di
      accordion row langsung ke-refresh tanpa perlu pindah tab.
    - **Belum diupload/dites** di environment user — sama seperti fix unit
      duplicate di atas, murni hasil edit surgical di chat.

- **Transactions — Customer Itemized Pricing (SUDAH DIBUAT: tabel DB + 4
  endpoint + UI di `transaction_content.php`, BELUM di-upload/dites di
  environment nyata user)**:
  - Tujuan: tiap customer di Customer List bisa punya daftar harga jual per
    produk (produk = yang sudah terdaftar lewat Activity Code + `logistics`,
    sama sumbernya dengan picker `#logActivityCode` di menu Logistic).
  - **UI** (`transaction_content.php`):
    - Tombol **Itemized Pricing** di-drop dari rencana awal (sejajar Edit/
      Delete) — implementasi final malah: setiap item accordion
      `#clPreviewList`, di bawah baris Edit/Delete, ada baris baru "Itemized
      Pricing" + tombol **Add Price**, lalu nested `.accordion-list` kosong
      di bawahnya (accordion-dalam-accordion, sama style dengan
      `clPreviewList`/`acPreviewList`).
    - **Lazy-load**: histori harga customer itu **baru di-fetch saat row
      customer-nya pertama kali dibuka** (`item.dataset.pricesLoaded` sebagai
      flag, di dalam header click handler `clPreviewList`) — bukan sekaligus
      saat render list customer, supaya tidak N+1 request tiap kali
      `renderCustomerList()` jalan.
    - **`refreshOpenHeight(item)`** — helper baru di samping
      `openAccordionItem`/`closeAccordionItem`/`toggleAccordionItem` yang
      sudah ada, untuk re-measure `max-height` accordion parent setelah
      konten nested list-nya berubah async (fetch prices selesai setelah
      row sudah kebuka, animasi max-height lama jadi tidak akurat kalau
      tidak di-refresh).
    - **Add Price** (`#itemPriceAddOverlay`): dropdown Product (isi dari
      `ajax/list_priceable_products.php`, **bukan** dari
      `list_logistic_activities.php` yang scoped per department — Itemized
      Pricing lintas department), Price (`.input-number-comma`, pola sama
      dengan qty di Logistic), Date, Unit (readonly, auto dari
      `data-unit` attribute produk terpilih). **Tidak butuh reverify**
      (sama seperti Create New Logistic) — hanya Edit/Delete di bawah yang
      digerbang password.
    - Tiap entry harga (nested accordion item) header-nya nampilin
      `activity_name — price / unit_label`, body-nya Price/Date/Unit +
      tombol **Edit**/**Delete**.
    - Edit & Delete **wajib password re-verify** — pola
      `aos_require_recent_reverify()` (`ajax/_require_reverify.php`) +
      `ajax/verify_password.php`, **client-side cek `res.success === false`
      dulu sebelum cek `res.ok`** (kontrak sama dengan pola Logistic
      Edit/Delete — lihat catatan "Pola verifikasi yang dipakai" di poin
      Logistic). 3 modal baru: `#itemPriceReverifyOverlay` (satu untuk
      Edit maupun Delete, `pendingItemPriceAction`/`pendingItemPriceRow`
      nyimpen state, persis pola `pendingLogisticAction`/`pendingLogisticRow`
      di Logistic), `#itemPriceEditOverlay` (cuma Price & Date yang bisa
      diubah — Product/Unit dikunci, ditampilkan readonly), dan
      `#itemPriceDeleteOverlay`.
    - Ke-4 modal baru didaftarkan ke `flexOverlays` (array baru, gantiin
      kondisi hardcoded panjang di `show()`) dan ke daftar `hide()` di
      `MutationObserver` supaya ikut tertutup otomatis saat section
      Transactions ditinggalkan — sama seperti modal lain di file ini.
    - `formatNumberInput()`/`parseNumberInput()`/`initNumberCommaInput()`
      **di-duplikasi** ke `transaction_content.php` (sebelumnya cuma ada
      di `logistic_content.php`) — tiap `*_content.php` file-nya
      self-contained (IIFE sendiri-sendiri), jadi tidak saling share
      function.
  - **DB: tabel baru `customer_item_prices`** (sudah dibuat SQL-nya,
    `sql/customer_item_prices.sql` — **belum dijalankan** di
    `lisani_aos_db` user, masih menunggu dieksekusi manual):
    ```sql
    CREATE TABLE IF NOT EXISTS customer_item_prices (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      customer_id INT UNSIGNED NOT NULL,     -- relasi ke customers.id
      logistic_id INT UNSIGNED NOT NULL,     -- relasi ke logistics.id
      price DECIMAL(15,2) NOT NULL,
      price_date DATE NOT NULL,
      unit_label VARCHAR(50) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_customer (customer_id),
      KEY idx_logistic (logistic_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ```
    - **`unit_label` sengaja disimpan sebagai snapshot** (bukan live-lookup ke
      `logistics.primary_unit_label`) — supaya kalau satuan produk diubah lewat
      Manage Units di kemudian hari, histori harga lama tetap menampilkan
      satuan yang berlaku saat harga itu disimpan.
    - **Tidak ada `UNIQUE(customer_id, logistic_id)`** — sengaja, tabel ini
      histori (banyak baris per pasangan customer+produk seiring waktu harga
      berubah), bukan snapshot satu baris terbaru.
    - **Tidak pakai `FOREIGN KEY ... REFERENCES`** eksplisit, cuma index biasa
      — mengikuti pola tabel lain di project ini (`logistic_movements`, dkk)
      yang relasinya dijaga di level PHP/transaction, bukan constraint DB.
    - `created_by`/`updated_by` (user_id) **belum ditambahkan** — belum
      dikonfirmasi perlu atau tidak.
  - **4 endpoint baru** (`ajax/`):
    - `list_priceable_products.php` — semua `logistics` JOIN `activities`,
      **tanpa filter department** (beda dari `list_logistic_activities.php`),
      untuk isi dropdown Product di Add/Edit Price.
    - `list_customer_item_prices.php` — histori harga 1 customer
      (`?customer_id=`), JOIN ke `activities` lewat `logistics` supaya ada
      `activity_name`, urut `price_date DESC, created_at DESC`.
    - `create_customer_item_price.php` — insert baris baru, **snapshot
      `primary_unit_label` dari `logistics` saat itu** ke kolom
      `unit_label`. Tidak reverify.
    - `update_customer_item_price.php` / `delete_customer_item_price.php` —
      **wajib `aos_require_recent_reverify()`** sebelum eksekusi (persis
      pola `update_logistic.php`/`delete_logistic.php`), update cuma
      `price`+`price_date` (product/unit dikunci by design).
  - **Belum ada / belum dites**: file-file di atas belum pernah dijalankan
    di server user (localhost/Termux) — `sql/customer_item_prices.sql`
    belum dieksekusi, endpoint & UI baru murni hasil edit surgical di chat,
    belum di-upload ulang & dicoba. Juga belum ada: fitur restore/histori
    perubahan (kalau Delete kepencet salah, tidak ada recycle seperti
    dokumen — beda dari Logistic/Customer yang punya folder recycle,
    karena Itemized Pricing tidak punya file fisik untuk dipindah).

## Cara lanjut kerja di chat/akun baru
1. Upload file ini + `index.php` (atau zip `lisani_aos/` lengkap).
2. Sebutkan menu mana yang mau diisi dan alur kerjanya (proses bisnis).
3. Kalau tabel DB belum ada, ceritakan datanya seperti apa — akan dirancang skema tabelnya.