# Design System: Dark Neomorphism — Aplikasi Pembukuan Perusahaan

> Cara pakai: tempel/lampirkan file ini di awal chat baru, lalu bilang "gunakan tema ini" saat minta Claude membangun halaman/komponen. Semua token di bawah adalah acuan tunggal (single source of truth) agar tampilan konsisten di seluruh modul web.

---

## 1. Prinsip Dasar Neomorphism Gelap

Neomorphism = elemen terlihat "menyatu" dengan background, timbul (extruded) atau tenggelam (inset), dibentuk murni oleh dua arah cahaya bayangan (terang di satu sisi, gelap di sisi lain) — bukan oleh border atau warna kontras tajam.

Aturan wajib:
- **Background elemen ≈ background parent.** Beda warnanya sangat tipis (selisih lightness 2–5%), efek 3D datang dari shadow, bukan dari warna.
- **Selalu dua shadow berpasangan**: satu terang (highlight) di sudut kiri-atas, satu gelap di sudut kanan-bawah.
- **Tidak ada border keras.** Kalau perlu pembatas, pakai shadow tipis, bukan `border: 1px solid`.
- **Radius besar dan konsisten** — sudut tajam merusak ilusi "material lunak".
- **Kontras teks harus tetap AA-compliant** — neomorphism gelap gampang bikin teks tenggelam; teks & angka penting harus tetap terbaca (WCAG AA minimal 4.5:1).

---

## 2. Token Warna (CSS Variables)

```css
:root {
  /* Base surface */
  --bg-base:        #1E2128;   /* background utama app */
  --bg-surface:     #23262E;   /* card, panel, sidebar */
  --bg-surface-alt: #262A33;   /* elemen sedikit lebih terang, mis. table header */
  --bg-recessed:    #1A1D23;   /* area inset/tenggelam, mis. input field */

  /* Shadow pair (kunci neomorphism) */
  --shadow-light: rgba(255, 255, 255, 0.04);
  --shadow-dark:  rgba(0, 0, 0, 0.6);

  /* Teks */
  --text-primary:   #E8E9ED;
  --text-secondary: #9AA0AC;
  --text-muted:     #6B7280;
  --text-disabled:  #4B4F58;

  /* Aksen brand (bisa disesuaikan) */
  --accent:         #5B8DEF;   /* biru — tombol primer, link, fokus */
  --accent-hover:   #6F9CFA;
  --accent-soft:    rgba(91, 141, 239, 0.12);

  /* Status warna (untuk pembukuan: saldo, transaksi) */
  --success:        #34C77B;   /* pemasukan / lunas */
  --success-soft:   rgba(52, 199, 123, 0.12);
  --danger:         #EF5350;   /* pengeluaran / defisit */
  --danger-soft:    rgba(239, 83, 80, 0.12);
  --warning:        #F5A623;   /* jatuh tempo / pending */
  --warning-soft:   rgba(245, 166, 35, 0.12);
  --info:           #5B8DEF;

  /* Radius & spacing */
  --radius-sm: 8px;
  --radius-md: 14px;
  --radius-lg: 20px;
  --radius-full: 999px;

  --space-1: 4px;
  --space-2: 8px;
  --space-3: 12px;
  --space-4: 16px;
  --space-5: 24px;
  --space-6: 32px;
}
```

---

## 3. Shadow Utility (inti neomorphism)

```css
/* Elemen TIMBUL — default untuk card, button, header, tab non-aktif */
.neo-raised {
  background: var(--bg-surface);
  border-radius: var(--radius-md);
  box-shadow:
    6px 6px 12px var(--shadow-dark),
    -4px -4px 10px var(--shadow-light);
}

/* Elemen TENGGELAM — untuk input, search bar, area yang "menerima" data */
.neo-inset {
  background: var(--bg-recessed);
  border-radius: var(--radius-md);
  box-shadow:
    inset 4px 4px 8px var(--shadow-dark),
    inset -3px -3px 6px var(--shadow-light);
}

/* Elemen AKTIF/DITEKAN — tab aktif, button saat :active */
.neo-pressed {
  background: var(--bg-surface);
  border-radius: var(--radius-md);
  box-shadow:
    inset 3px 3px 6px var(--shadow-dark),
    inset -2px -2px 5px var(--shadow-light);
}

/* Shadow lembut untuk elemen kecil (chip, badge, icon button) */
.neo-soft {
  box-shadow:
    3px 3px 6px var(--shadow-dark),
    -2px -2px 5px var(--shadow-light);
}
```

**Aturan interaksi:** raised → hover (shadow sedikit membesar) → pressed (shadow jadi inset) saat diklik. Transisi `150–200ms ease`.

---

## 4. Tipografi

```css
--font-display: 'Inter', 'Plus Jakarta Sans', sans-serif;  /* judul, heading */
--font-body:    'Inter', sans-serif;                        /* teks umum */
--font-mono:    'JetBrains Mono', 'Roboto Mono', monospace; /* angka, nominal uang, kode invoice */

--text-xs:   12px;
--text-sm:   13px;
--text-base: 14px;
--text-lg:   16px;
--text-xl:   20px;
--text-2xl:  24px;
--text-3xl:  32px;
```

- **Nominal uang / angka pembukuan wajib pakai `--font-mono`** dengan `font-variant-numeric: tabular-nums;` agar kolom angka rapi sejajar.
- Heading pakai `font-weight: 600–700`, body `400–500`.

---

## 5. Komponen

### 5.1 Header (top bar)
```css
.app-header {
  background: var(--bg-surface);
  height: 64px;
  padding: 0 var(--space-5);
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: 0 4px 12px var(--shadow-dark);
  /* tidak pakai border-bottom, cukup shadow tipis ke bawah */
}
```
- App logo/name on the left, search (neo-inset) center-left, user avatar on the right.
- Avatar is a `.user-menu` dropdown trigger (not a static icon) — clicking it opens a `.user-menu-dropdown` with account-level actions (Settings, Exit/logout). Don't add a separate standalone notification bell button unless there's an actual notification feature behind it — an icon with no function shouldn't ship.
- Avatar: `border-radius: 50%`; dropdown panel: `.card`-style raised shadow (`--radius-md`, same shadow pair as `.neo-raised`), items use `.user-menu-item` (same visual language as `.sidebar-item`: transparent bg, hover → `--bg-surface-alt`, danger item hovers to `--danger`).

### 5.2 Sidebar / Navigasi
```css
.sidebar {
  background: var(--bg-surface);
  border-radius: 0 var(--radius-lg) var(--radius-lg) 0;
  padding: var(--space-4);
}
.sidebar-item {
  padding: var(--space-3) var(--space-4);
  border-radius: var(--radius-sm);
  color: var(--text-secondary);
  transition: all .18s ease;
}
.sidebar-item:hover {
  background: var(--bg-surface-alt);
  color: var(--text-primary);
}
.sidebar-item.active {
  /* neo-pressed */
  box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
  color: var(--accent);
  font-weight: 600;
}
```

### 5.3 Tab
```css
.tab-group {
  display: inline-flex;
  gap: var(--space-2);
  padding: var(--space-2);
  background: var(--bg-recessed);      /* wadah tab = inset */
  border-radius: var(--radius-md);
}
.tab {
  padding: var(--space-2) var(--space-4);
  border-radius: var(--radius-sm);
  color: var(--text-secondary);
  font-size: var(--text-sm);
  transition: all .18s ease;
}
.tab.active {
  background: var(--bg-surface);
  color: var(--accent);
  box-shadow: 3px 3px 6px var(--shadow-dark), -2px -2px 5px var(--shadow-light); /* raised, "terangkat" dari wadah inset */
}
```

### 5.4 Button
```css
.btn {
  padding: var(--space-3) var(--space-5);
  border-radius: var(--radius-md);
  font-weight: 600;
  font-size: var(--text-base);
  border: none;
  cursor: pointer;
  transition: all .15s ease;
}

/* Primary */
.btn-primary {
  background: var(--accent);
  color: #FFFFFF;
  box-shadow: 4px 4px 10px rgba(0,0,0,0.5), -2px -2px 6px rgba(255,255,255,0.03);
}
.btn-primary:hover { background: var(--accent-hover); }
.btn-primary:active {
  box-shadow: inset 2px 2px 5px rgba(0,0,0,0.4);
  transform: translateY(1px);
}

/* Secondary (netral, neomorphic murni) */
.btn-secondary {
  background: var(--bg-surface);
  color: var(--text-primary);
  box-shadow: 5px 5px 10px var(--shadow-dark), -4px -4px 8px var(--shadow-light);
}
.btn-secondary:active {
  box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
}

/* Danger (hapus, batal transaksi) */
.btn-danger {
  background: var(--danger);
  color: #fff;
  box-shadow: 4px 4px 10px rgba(0,0,0,0.5);
}

/* Icon button (bulat, untuk aksi cepat di tabel) */
.btn-icon {
  width: 36px; height: 36px;
  border-radius: var(--radius-full);
  background: var(--bg-surface);
  box-shadow: 3px 3px 6px var(--shadow-dark), -2px -2px 5px var(--shadow-light);
}
```

### 5.5 Input / Form (pembukuan butuh banyak form)
```css
.input {
  background: var(--bg-recessed);
  color: var(--text-primary);
  border: none;
  border-radius: var(--radius-sm);
  padding: var(--space-3) var(--space-4);
  box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
}
.input:focus {
  outline: none;
  box-shadow:
    inset 3px 3px 6px var(--shadow-dark),
    inset -2px -2px 5px var(--shadow-light),
    0 0 0 2px var(--accent-soft);
}
.input::placeholder { color: var(--text-muted); }

.label {
  font-size: var(--text-sm);
  color: var(--text-secondary);
  margin-bottom: var(--space-2);
}
```

### 5.6 Card / Panel (ringkasan saldo, statistik)
```css
.card {
  background: var(--bg-surface);
  border-radius: var(--radius-lg);
  padding: var(--space-5);
  box-shadow: 6px 6px 14px var(--shadow-dark), -5px -5px 12px var(--shadow-light);
}
.card-stat-value {
  font-family: var(--font-mono);
  font-size: var(--text-3xl);
  font-weight: 700;
  color: var(--text-primary);
}
.card-stat-value.positive { color: var(--success); }
.card-stat-value.negative { color: var(--danger); }
```

### 5.7 Tabel (transaksi, invoice, buku besar)
```css
.table-wrapper {
  background: var(--bg-surface);
  border-radius: var(--radius-lg);
  padding: var(--space-4);
  box-shadow: 5px 5px 12px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
}
thead th {
  background: var(--bg-surface-alt);
  color: var(--text-secondary);
  font-size: var(--text-sm);
  padding: var(--space-3) var(--space-4);
  text-align: left;
}
tbody td {
  padding: var(--space-3) var(--space-4);
  font-size: var(--text-sm);
  color: var(--text-primary);
  border-bottom: 1px solid rgba(255,255,255,0.03); /* satu-satunya pengecualian border, super tipis */
}
tbody tr:hover { background: var(--bg-surface-alt); }

/* Angka nominal di tabel */
.cell-amount {
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
  text-align: right;
}
```

### 5.8 Badge / Status (status pembayaran, jenis transaksi)
```css
.badge {
  display: inline-flex;
  align-items: center;
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-full);
  font-size: var(--text-xs);
  font-weight: 600;
}
.badge-success { background: var(--success-soft); color: var(--success); }
.badge-danger  { background: var(--danger-soft);  color: var(--danger); }
.badge-warning { background: var(--warning-soft); color: var(--warning); }
```

### 5.9 Modal / Dialog
```css
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(10, 11, 14, 0.65);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-4);
  z-index: 200;
}
.modal {
  background: var(--bg-surface);
  border-radius: var(--radius-lg);
  padding: var(--space-6);
  box-shadow: 10px 10px 24px var(--shadow-dark), -6px -6px 16px var(--shadow-light);
  width: min(480px, 90vw);
}
```
`.modal-overlay` **wajib** `position: fixed` + `inset: 0` + `display: flex` centering — tanpa ini `.modal` ikut alur dokumen normal (bukan fly window yang mengambang di tengah layar). `.modal` sendiri tidak butuh `position` apa pun, cukup diposisikan oleh parent flex-nya.

Struktur internal modal (opsional tapi disarankan untuk modal dengan judul + area aksi):
```css
.modal-header  { margin-bottom: var(--space-4); }
.modal-title   { font-family: var(--font-display); font-size: var(--text-xl); font-weight: 700; color: var(--text-primary); }
.modal-body    { display: flex; flex-direction: column; gap: var(--space-4); }
.modal-footer  { display: flex; gap: var(--space-3); justify-content: flex-end; margin-top: var(--space-5); }
```

### 5.11 Panel Header (untuk view yang di-embed langsung di halaman)
Dipakai saat sebuah alur kerja (form, wizard step) ditampilkan **langsung di dalam `.card` pada konten section**, bukan di dalam modal — mis. form yang tadinya modal tapi harus tetap terlihat sambil ada konteks section di sekitarnya. Visualnya senada dengan `.modal-header`/`.modal-title`, tapi berupa baris dengan aksi (mis. tombol Back) di kanan:
```css
.panel-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-4); }
.panel-title  { font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; color: var(--text-primary); }
```

### 5.10 Toggle / Checkbox
```css
.toggle-track {
  width: 44px; height: 24px;
  border-radius: var(--radius-full);
  background: var(--bg-recessed);
  box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -1px -1px 3px var(--shadow-light);
}
.toggle-track.on { background: var(--accent-soft); }
.toggle-thumb {
  width: 18px; height: 18px;
  border-radius: 50%;
  background: var(--bg-surface);
  box-shadow: 2px 2px 4px var(--shadow-dark);
}
```

---

## 6. Aturan Konsistensi Wajib

1. **Satu arah cahaya untuk seluruh web**: highlight selalu dari kiri-atas, shadow gelap selalu ke kanan-bawah. Jangan dibalik-balik antar komponen.
2. **Hierarki kedalaman:**
   - Level 0 (`--bg-base`): halaman.
   - Level 1 (`--bg-surface`, raised): card, header, sidebar, button.
   - Level 2 (`--bg-recessed`, inset): input, search, wadah tab.
   - Level 3 (raised di atas inset): tab aktif, elemen "terangkat kembali" dari area inset.
3. **Aksen warna (`--accent`) dipakai secukupnya** — hanya untuk elemen interaktif utama (tombol primer, link, item nav aktif, fokus input). Jangan dipakai untuk dekorasi.
4. **Warna status (success/danger/warning) konsisten di semua modul**: hijau selalu = pemasukan/lunas, merah selalu = pengeluaran/belum lunas, kuning selalu = pending/jatuh tempo — jangan pernah ditukar maknanya di modul lain.
5. **Radius seragam** sesuai ukuran elemen: kecil (badge, icon) → `--radius-sm/full`, elemen menengah (button, input, tab) → `--radius-md`, container besar (card, modal, tabel) → `--radius-lg`.
6. **Jangan campur neomorphism dengan flat design** (mis. tiba-tiba pakai `border: 1px solid #333` di satu komponen) — akan merusak konsistensi visual di seluruh web.
7. **Aksesibilitas:** teks di atas `--bg-recessed` atau `--bg-base` wajib pakai `--text-primary` atau `--text-secondary`, jangan `--text-muted` untuk informasi penting (nominal, status).

---

## 7. Aturan Responsive (Semua Ukuran Layar)

Breakpoint lengkap — pendekatan **mobile-first**, styling dasar untuk layar terkecil lalu ditambah `min-width` ke atas:

```css
--bp-xs: 360px;    /* HP kecil (lipat/compact) */
--bp-sm: 480px;    /* HP umum */
--bp-md: 768px;    /* tablet portrait / HP besar landscape */
--bp-lg: 1024px;   /* tablet landscape / laptop kecil */
--bp-xl: 1280px;   /* laptop/desktop standar */
--bp-2xl: 1536px;  /* monitor besar */
--bp-3xl: 1920px;  /* monitor lebar/ultra-wide, TV kantor */
```

Ringkasan perilaku layout di tiap rentang:

| Rentang | Perangkat tipikal | Sidebar | Grid stat card | Panel tabel+form | Tabel |
|---|---|---|---|---|---|
| `<480px` | HP kecil–umum | Bottom nav | 1 kolom | Tumpuk vertikal | List card |
| `480–767px` | HP besar/landscape | Bottom nav | 2 kolom | Tumpuk vertikal | List card |
| `768–1023px` | Tablet portrait | **Icon-only rail** (56px, tanpa label) | 2 kolom | Tumpuk vertikal | List card atau tabel ringkas (kolom dikurangi) |
| `1024–1279px` | Tablet landscape / laptop kecil | Sidebar penuh (200px) | 4 kolom | Side-by-side (60/40) | Tabel penuh |
| `1280–1535px` | Laptop/desktop standar | Sidebar penuh (240px) | 4 kolom | Side-by-side (65/35) | Tabel penuh |
| `≥1536px` | Monitor besar/ultra-wide | Sidebar penuh (260px) + **max-width konten** | 4–6 kolom | Side-by-side, kolom form tetap fixed-width | Tabel penuh, padding lebih lega |

### 7.1 Sidebar — 3 mode, bukan cuma on/off

Untuk tablet, sidebar penuh (dengan label teks) makan terlalu banyak ruang tapi bottom-nav terlalu terbatas (cuma cukup 4–5 menu). Solusinya: **rail mode** — sidebar tetap ada tapi hanya ikon.

```css
.sidebar { width: 240px; }
.sidebar .label-text { display: inline; }

/* Tablet: rail icon-only */
@media (max-width: 1023px) and (min-width: 768px) {
  .sidebar { width: 64px; padding: var(--space-4) var(--space-2); }
  .sidebar .label-text,
  .sidebar .brand-name, .sidebar .brand-sub,
  .sidebar .nav-label { display: none; }
  .sidebar-item { justify-content: center; padding: var(--space-3); }
  .app { grid-template-columns: 64px 1fr; }
}

/* HP: sidebar hilang, pakai bottom-nav (lihat 7.2) */
@media (max-width: 767px) {
  .sidebar { display: none; }
  .app { grid-template-columns: 1fr; }
}
```

### 7.2 Bottom Navigation (khusus HP, `<768px`)
```css
.bottom-nav { display: none; }
@media (max-width: 767px) {
  .bottom-nav {
    display: flex;
    justify-content: space-around;
    align-items: center;
    position: fixed;
    bottom: 0; left: 0; right: 0;
    height: 64px;
    background: var(--bg-surface);
    box-shadow: 0 -4px 12px var(--shadow-dark);
    padding-bottom: env(safe-area-inset-bottom);
    z-index: 50;
  }
  .bottom-nav-item {
    display: flex; flex-direction: column; align-items: center; gap: 2px;
    color: var(--text-muted);
    font-size: 10px;
  }
  .bottom-nav-item i { font-size: 20px; }
  .bottom-nav-item.active { color: var(--accent); }
  .content { padding-bottom: 80px; }
}
```

### 7.3 Header
| Layar | Tinggi header | Search bar |
|---|---|---|
| HP (`<480px`) | 56px | Disembunyikan → icon, buka jadi full-width overlay saat ditekan |
| Tablet (`768–1023px`) | 60px | Diperkecil (160px), tanpa placeholder panjang |
| Desktop (`≥1024px`) | 64px | Penuh (240px) |

### 7.4 Grid Stat Card
```css
.stat-grid { grid-template-columns: 1fr; gap: var(--space-3); }               /* default: HP kecil */
@media (min-width: 480px)  { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 1024px) { .stat-grid { grid-template-columns: repeat(4, 1fr); gap: var(--space-4); } }
@media (min-width: 1536px) { .stat-grid { grid-template-columns: repeat(4, 1fr); gap: var(--space-5); } } /* tetap 4, kolom lebih lebar+jarak lega, bukan nambah kolom */
```

### 7.5 Panel Dua-Kolom (tabel + form)
```css
.panel-row { grid-template-columns: 1fr; }                                     /* default: tumpuk */
@media (min-width: 1024px) { .panel-row { grid-template-columns: 1.6fr 1fr; } }
@media (min-width: 1536px) { .panel-row { grid-template-columns: minmax(0, 1fr) 380px; } } /* kolom form fixed, kolom tabel yang melar */
```
Di tablet portrait (768–1023px) tabel+form **tetap ditumpuk** — lebar tablet portrait (~768–834px) masih terlalu sempit untuk dua panel side-by-side yang nyaman dibaca.

### 7.6 Tabel
- `<1024px` (HP + tablet portrait): jadi **list card per baris** (lihat kode di bawah).
- `≥1024px` (tablet landscape ke atas): tabel penuh seperti desktop.
```css
@media (max-width: 1023px) {
  table, thead { display: none; }
  tbody, tr, td { display: block; width: 100%; }
  tr {
    background: var(--bg-surface-alt);
    border-radius: var(--radius-md);
    padding: var(--space-3) var(--space-4);
    margin-bottom: var(--space-2);
  }
  td { border-bottom: none; padding: var(--space-1) 0; }
  td.cell-amount { text-align: left; font-size: 16px; font-weight: 600; }
}
```

### 7.7 Modal / Dialog
| Layar | Bentuk |
|---|---|
| HP (`<480px`) | Bottom sheet, lebar penuh, muncul dari bawah |
| Tablet (`480–1023px`) | Modal tengah, lebar `min(480px, 90vw)` |
| Desktop (`≥1024px`) | Modal tengah, lebar tetap (`480–560px`) |

```css
.modal { width: min(480px, 90vw); border-radius: var(--radius-lg); }
@media (max-width: 479px) {
  .modal {
    width: 100%; margin: 0;
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    position: fixed; bottom: 0; left: 0; right: 0;
  }
}
```

### 7.8 Kontainer Max-Width (monitor besar & ultra-wide, `≥1536px`)
Di monitor besar, konten **jangan melebar tanpa batas** — teks/tabel jadi sulit dibaca kalau terlalu lebar. Beri max-width dan pusatkan, sisanya biarkan `--bg-base` polos.
```css
.content {
  max-width: 1600px;
  margin: 0 auto;
  width: 100%;
}
```

### 7.9 Orientasi & Perangkat Khusus
- **Tablet rotasi (portrait ↔ landscape)**: breakpoint di atas berbasis lebar viewport otomatis menyesuaikan — portrait iPad (~834px) masuk rentang tablet-landscape (sidebar rail → sidebar penuh jika ≥1024px pada mode landscape).
- **Foldable/HP layar lipat**: perlakukan sesuai lebar viewport aktual saat itu (breakpoint sudah cukup, tidak perlu media query khusus `foldable`).
- **Touch vs mouse**: tambahkan area sentuh lebih besar hanya jika perangkat mendukung sentuh, tanpa mengubah tampilan di perangkat mouse:
```css
@media (hover: none) and (pointer: coarse) {
  .sidebar-item, .tab, .btn, .bottom-nav-item, .icon-btn { min-height: 44px; }
}
```
- **Layar sangat lebar (TV kantor, monitor ultrawide `≥1920px`)**: tercakup oleh aturan max-width di 7.8 — konten tetap 1600px di tengah, tidak perlu breakpoint tambahan.

### 7.10 Tipografi Responsif
```css
--text-3xl: 24px;  /* default HP */
@media (min-width: 768px)  { :root { --text-3xl: 28px; } }
@media (min-width: 1280px) { :root { --text-3xl: 32px; } }
```
Body text (`14px`) **tidak berubah** di semua ukuran layar — hanya heading/angka statistik besar yang diskalakan naik di layar lebih besar.

### 7.11 Prinsip Umum
1. **Mobile-first**: tulis CSS dasar untuk layar sempit, tambahkan `min-width` untuk memperluas — bukan sebaliknya.
2. **Shadow neomorphism tetap dipakai di semua ukuran** — hanya intensitas yang menyesuaikan (sedikit lebih kecil di HP, sedikit lebih besar/lega di monitor besar).
3. **3 breakpoint struktural inti yang wajib diuji**: 375px (HP), 834px (tablet portrait), 1440px (desktop) — sisanya interpolasi otomatis lewat grid & flex yang fluid.
4. **Konten dan angka penting (nominal, status) selalu prioritas utama** di layar manapun; elemen dekoratif yang pertama disederhanakan saat ruang terbatas.

---

## 8. Implementasi — Wajib PHP, Bukan HTML Statis

Proyek ini adalah aplikasi PHP (selaras dengan cara kerja [[optic_pos]]: PHP + MySQL, dijalankan di XAMPP/localhost dan Termux). Semua output visual dari tema ini harus dibuatkan sebagai **file `.php`**, bukan `.html` statis. Aturan konkretnya:

### 8.1 Struktur file
- Ekstensi halaman selalu `.php`, walau untuk contoh tampilan/mockup sekalipun — bukan `.html`.
- **CSS tema dipisah ke file sendiri**, di-include lewat `<link>`, bukan ditulis ulang inline di setiap halaman:
  ```
  /assets/css/theme.css       <- semua token (:root) + shadow utility + komponen dari bagian 2-6
  /assets/css/responsive.css  <- semua media query dari bagian 7
  /partials/header.php        <- <header class="app-header">...</header>
  /partials/sidebar.php       <- <aside class="sidebar">...</aside> + <nav class="bottom-nav">
  /partials/footer.php        <- penutup </body></html> + script bersama
  ```
- Setiap halaman modul (`dashboard.php`, `transaksi.php`, `invoice.php`, dll) cukup:
  ```php
  <?php include 'partials/header.php'; ?>
  <div class="app">
    <?php include 'partials/sidebar.php'; ?>
    <div class="main">
      <!-- konten halaman di sini -->
    </div>
  </div>
  <?php include 'partials/footer.php'; ?>
  ```
- Tujuannya: kalau ada penyesuaian tema (warna, shadow, breakpoint), cukup ubah `theme.css`/`responsive.css` sekali, otomatis berlaku ke semua halaman — bukan copy-paste `<style>` ke tiap file.

### 8.2 Data dinamis, bukan hardcode
- Nilai yang tadinya contoh statis (nominal, status transaksi, badge, daftar menu sidebar) **wajib diisi dari PHP** (query ke `optic_pos_db` atau echo variabel), bukan ditulis tetap di markup:
  ```php
  <div class="stat-value">Rp <?= number_format($totalPemasukan, 0, ',', '.') ?></div>
  <span class="badge badge-<?= $status === 'lunas' ? 'success' : 'warning' ?>">
    <?= htmlspecialchars($labelStatus) ?>
  </span>
  ```
- Baris tabel transaksi dirender lewat loop (`foreach`/`while` dari hasil query), bukan ditulis satu-satu di HTML.
- Selalu `htmlspecialchars()` untuk data yang berasal dari database/input pengguna sebelum di-echo ke markup, agar aman dari XSS.

### 8.3 Konsisten dengan konvensi kode yang sudah berjalan
- Identifier PHP (nama variabel, fungsi, kolom/field) tetap **Bahasa Inggris**.
- **Update (per lisani_aos):** semua teks yang tampil ke pengguna di UI (label, placeholder, empty-state, komentar CSS/JS) juga **Bahasa Inggris** — ini beda dari konvensi [[optic_pos]] yang UI-nya Bahasa Indonesia. Jangan campur; untuk file-file di dalam `lisani_aos/`, default-nya Inggris kecuali diminta lain.
- Saat meminta Claude membuat halaman baru, sebutkan bahwa outputnya harus `.php` dan (jika sudah ada) sertakan `theme.css`/`responsive.css` yang sudah dibuat agar tidak digenerate ulang dari nol setiap kali.

---

## 9. Cara Memakai di Chat Baru

Contoh prompt singkat untuk chat berikutnya:

> "Buatkan halaman [nama modul] dalam bentuk **file PHP** (bukan HTML) pakai tema dark neomorphism sesuai file `theme-dark-neomorphism.md` yang saya lampirkan. Gunakan `theme.css`/`responsive.css` yang sudah ada, ikuti token warna, shadow, radius, dan aturan komponen di file itu persis."

Selama file ini dilampirkan/ditempel di awal chat, Claude bisa langsung mengikuti token & aturan yang sama tanpa perlu dijelaskan ulang dari nol.