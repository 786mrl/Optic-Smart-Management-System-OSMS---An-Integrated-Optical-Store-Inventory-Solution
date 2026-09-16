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
  oleh `delete_customer.php` tapi belum ada pengelolaan lanjutannya.
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

## Cara lanjut kerja di chat/akun baru
1. Upload file ini + `index.php` (atau zip `lisani_aos/` lengkap).
2. Sebutkan menu mana yang mau diisi dan alur kerjanya (proses bisnis).
3. Kalau tabel DB belum ada, ceritakan datanya seperti apa — akan dirancang skema tabelnya.