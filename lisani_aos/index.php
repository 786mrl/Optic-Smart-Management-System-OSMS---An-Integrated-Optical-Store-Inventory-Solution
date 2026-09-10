<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Pembukuan — Dark Neomorphism</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/@tabler/icons-webfont/3.1.0/tabler-icons.min.css">
<style>
:root {
  --bg-base:        #1E2128;
  --bg-surface:     #23262E;
  --bg-surface-alt: #262A33;
  --bg-recessed:    #1A1D23;

  --shadow-light: rgba(255, 255, 255, 0.04);
  --shadow-dark:  rgba(0, 0, 0, 0.6);

  --text-primary:   #E8E9ED;
  --text-secondary: #9AA0AC;
  --text-muted:     #6B7280;

  --accent:         #5B8DEF;
  --accent-hover:   #6F9CFA;
  --accent-soft:    rgba(91, 141, 239, 0.12);

  --success:        #34C77B;
  --success-soft:   rgba(52, 199, 123, 0.12);
  --danger:         #EF5350;
  --danger-soft:    rgba(239, 83, 80, 0.12);
  --warning:        #F5A623;
  --warning-soft:   rgba(245, 166, 35, 0.12);

  --radius-sm: 8px;
  --radius-md: 14px;
  --radius-lg: 20px;
  --radius-full: 999px;

  --space-1: 4px; --space-2: 8px; --space-3: 12px;
  --space-4: 16px; --space-5: 24px; --space-6: 32px;

  --font-body: 'Inter', -apple-system, sans-serif;
  --font-mono: 'JetBrains Mono', 'Roboto Mono', monospace;
}

* { box-sizing: border-box; }

body {
  margin: 0;
  background: var(--bg-base);
  color: var(--text-primary);
  font-family: var(--font-body);
  font-size: 14px;
}

.app {
  display: grid;
  grid-template-columns: 240px 1fr;
  min-height: 100vh;
}

/* ===== Sidebar ===== */
.sidebar {
  background: var(--bg-surface);
  padding: var(--space-5) var(--space-4);
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}
.brand {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: 0 var(--space-2);
  margin-bottom: var(--space-6);
}
.brand-mark {
  width: 36px; height: 36px;
  border-radius: var(--radius-sm);
  background: var(--bg-surface);
  box-shadow: 3px 3px 6px var(--shadow-dark), -2px -2px 5px var(--shadow-light);
  display: flex; align-items: center; justify-content: center;
  color: var(--accent);
  font-size: 18px;
}
.brand-name { font-weight: 600; font-size: 15px; }
.brand-sub { font-size: 11px; color: var(--text-muted); }

.nav-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--text-muted);
  padding: var(--space-4) var(--space-3) var(--space-2);
}
.sidebar-item {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border-radius: var(--radius-sm);
  color: var(--text-secondary);
  text-decoration: none;
  font-size: 13px;
  transition: all .18s ease;
}
.sidebar-item i { font-size: 18px; }
.sidebar-item:hover { background: var(--bg-surface-alt); color: var(--text-primary); }
.sidebar-item.active {
  box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
  color: var(--accent);
  font-weight: 600;
}

/* ===== Main ===== */
.main { display: flex; flex-direction: column; min-height: 100vh; }

.app-header {
  background: var(--bg-surface);
  height: 64px;
  padding: 0 var(--space-5);
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: 0 4px 12px var(--shadow-dark);
  position: relative;
  z-index: 2;
}
.header-title { font-size: 17px; font-weight: 600; }
.header-sub { font-size: 12px; color: var(--text-muted); }
.header-right { display: flex; align-items: center; gap: var(--space-4); }

.search-box {
  display: flex; align-items: center; gap: var(--space-2);
  background: var(--bg-recessed);
  border-radius: var(--radius-md);
  padding: var(--space-2) var(--space-4);
  box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
  width: 240px;
}
.search-box input {
  background: transparent; border: none; outline: none;
  color: var(--text-primary); font-size: 13px; width: 100%;
}
.search-box input::placeholder { color: var(--text-muted); }
.search-box i { color: var(--text-muted); font-size: 16px; }

.icon-btn {
  width: 36px; height: 36px;
  border-radius: var(--radius-full);
  background: var(--bg-surface);
  box-shadow: 3px 3px 6px var(--shadow-dark), -2px -2px 5px var(--shadow-light);
  display: flex; align-items: center; justify-content: center;
  color: var(--text-secondary);
  cursor: pointer;
  border: none;
  position: relative;
}
.icon-btn i { font-size: 17px; }
.icon-btn .dot {
  position: absolute; top: 6px; right: 7px;
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--danger);
}
.avatar {
  width: 36px; height: 36px; border-radius: 50%;
  background: var(--accent-soft);
  color: var(--accent);
  display: flex; align-items: center; justify-content: center;
  font-weight: 600; font-size: 13px;
  box-shadow: 2px 2px 5px var(--shadow-dark);
}

.content { padding: var(--space-5); display: flex; flex-direction: column; gap: var(--space-5); }

/* ===== Stat cards ===== */
.stat-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-4);
}
.card {
  background: var(--bg-surface);
  border-radius: var(--radius-lg);
  padding: var(--space-5);
  box-shadow: 6px 6px 14px var(--shadow-dark), -5px -5px 12px var(--shadow-light);
}
.stat-top {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: var(--space-4);
}
.stat-icon {
  width: 38px; height: 38px;
  border-radius: var(--radius-sm);
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
}
.stat-label { font-size: 12px; color: var(--text-secondary); margin-bottom: var(--space-1); }
.stat-value {
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
  font-size: 24px; font-weight: 700;
}
.stat-delta { font-size: 12px; margin-top: var(--space-2); display: flex; align-items: center; gap: 4px; }
.stat-delta.up { color: var(--success); }
.stat-delta.down { color: var(--danger); }

/* ===== Tabs ===== */
.tab-group {
  display: inline-flex;
  gap: var(--space-2);
  padding: var(--space-2);
  background: var(--bg-recessed);
  border-radius: var(--radius-md);
  box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
  width: fit-content;
}
.tab {
  padding: var(--space-2) var(--space-4);
  border-radius: var(--radius-sm);
  color: var(--text-secondary);
  font-size: 13px;
  cursor: pointer;
  border: none;
  background: transparent;
  font-family: inherit;
  transition: all .18s ease;
}
.tab.active {
  background: var(--bg-surface);
  color: var(--accent);
  box-shadow: 3px 3px 6px var(--shadow-dark), -2px -2px 5px var(--shadow-light);
  font-weight: 600;
}

/* ===== Table panel ===== */
.panel-row { display: grid; grid-template-columns: 1.6fr 1fr; gap: var(--space-4); }

.panel-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: var(--space-4);
}
.panel-title { font-size: 14px; font-weight: 600; }

.btn {
  padding: var(--space-2) var(--space-4);
  border-radius: var(--radius-md);
  font-weight: 600;
  font-size: 13px;
  border: none;
  cursor: pointer;
  font-family: inherit;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all .15s ease;
}
.btn-primary {
  background: var(--accent);
  color: #fff;
  box-shadow: 4px 4px 10px rgba(0,0,0,0.5), -2px -2px 6px rgba(255,255,255,0.03);
}
.btn-primary:hover { background: var(--accent-hover); }
.btn-secondary {
  background: var(--bg-surface);
  color: var(--text-primary);
  box-shadow: 5px 5px 10px var(--shadow-dark), -4px -4px 8px var(--shadow-light);
}

table { width: 100%; border-collapse: collapse; }
thead th {
  background: var(--bg-surface-alt);
  color: var(--text-secondary);
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  padding: var(--space-3) var(--space-4);
  text-align: left;
  font-weight: 600;
}
thead th:first-child { border-radius: var(--radius-sm) 0 0 var(--radius-sm); }
thead th:last-child { border-radius: 0 var(--radius-sm) var(--radius-sm) 0; text-align: right; }
tbody td {
  padding: var(--space-3) var(--space-4);
  font-size: 13px;
  color: var(--text-primary);
  border-bottom: 1px solid rgba(255,255,255,0.03);
}
tbody tr:hover { background: var(--bg-surface-alt); }
tbody tr:last-child td { border-bottom: none; }

.cell-amount {
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
  text-align: right;
}
.cell-amount.pos { color: var(--success); }
.cell-amount.neg { color: var(--danger); }

.badge {
  display: inline-flex; align-items: center;
  padding: 3px var(--space-3);
  border-radius: var(--radius-full);
  font-size: 11px; font-weight: 600;
}
.badge-success { background: var(--success-soft); color: var(--success); }
.badge-danger  { background: var(--danger-soft);  color: var(--danger); }
.badge-warning { background: var(--warning-soft); color: var(--warning); }

.tx-row { display: flex; align-items: center; gap: var(--space-3); }
.tx-icon {
  width: 32px; height: 32px; border-radius: var(--radius-sm);
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; flex-shrink: 0;
}
.tx-name { font-weight: 500; }
.tx-sub { font-size: 11px; color: var(--text-muted); }

/* ===== Side panel: quick input form ===== */
.form-group { margin-bottom: var(--space-4); }
.label {
  font-size: 12px;
  color: var(--text-secondary);
  margin-bottom: var(--space-2);
  display: block;
}
.input, .select {
  width: 100%;
  background: var(--bg-recessed);
  color: var(--text-primary);
  border: none;
  border-radius: var(--radius-sm);
  padding: var(--space-3) var(--space-4);
  box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
  font-family: inherit;
  font-size: 13px;
  outline: none;
}
.input::placeholder { color: var(--text-muted); }
.input:focus, .select:focus {
  box-shadow:
    inset 3px 3px 6px var(--shadow-dark),
    inset -2px -2px 5px var(--shadow-light),
    0 0 0 2px var(--accent-soft);
}
.input-row { display: flex; gap: var(--space-3); }
.input-row > div { flex: 1; }

.toggle-row { display: flex; align-items: center; justify-content: space-between; margin-top: var(--space-5); }
.toggle-track {
  width: 44px; height: 24px;
  border-radius: var(--radius-full);
  background: var(--accent-soft);
  box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -1px -1px 3px var(--shadow-light);
  position: relative;
  cursor: pointer;
}
.toggle-thumb {
  position: absolute; top: 3px; right: 3px;
  width: 18px; height: 18px; border-radius: 50%;
  background: var(--accent);
  box-shadow: 2px 2px 4px var(--shadow-dark);
}

/* ===================================================== */
/* RESPONSIVE                                              */
/* ===================================================== */
.bottom-nav { display: none; }

/* Tablet portrait 768-1023: sidebar jadi rail icon-only, panel ditumpuk, tabel jadi list */
@media (max-width: 1023px) and (min-width: 768px) {
  .app { grid-template-columns: 64px 1fr; }
  .sidebar { width: 64px; padding: var(--space-4) var(--space-2); }
  .brand-name, .brand-sub, .nav-label, .sidebar-item span { display: none; }
  .sidebar-item { justify-content: center; padding: var(--space-3); gap: 0; }
  .panel-row { grid-template-columns: 1fr; }
  table, thead { display: none; }
  tbody, tr, td { display: block; width: 100%; }
  tbody tr { background: var(--bg-surface-alt); border-radius: var(--radius-md); padding: var(--space-3) var(--space-4); margin-bottom: var(--space-2); }
  tbody td { border-bottom: none; padding: var(--space-1) 0; }
  .cell-amount { text-align: left; font-size: 15px; font-weight: 600; }
}

/* HP <768: sidebar hilang, bottom nav, semua ditumpuk */
@media (max-width: 767px) {
  .app { grid-template-columns: 1fr; }
  .sidebar { display: none; }
  .bottom-nav {
    display: flex; justify-content: space-around; align-items: center;
    position: fixed; bottom: 0; left: 0; right: 0; height: 60px;
    background: var(--bg-surface);
    box-shadow: 0 -4px 12px var(--shadow-dark);
    z-index: 50;
  }
  .bottom-nav-item { display: flex; flex-direction: column; align-items: center; gap: 2px; color: var(--text-muted); font-size: 10px; }
  .bottom-nav-item i { font-size: 20px; }
  .bottom-nav-item.active { color: var(--accent); }

  .app-header { height: 56px; padding: 0 var(--space-4); }
  .search-box { display: none; }
  .header-title { font-size: 15px; }

  .content { padding: var(--space-4); padding-bottom: 76px; gap: var(--space-4); }
  .stat-grid { grid-template-columns: repeat(2, 1fr); gap: var(--space-3); }
  .card { padding: var(--space-4); }
  .stat-value { font-size: 19px; }

  .panel-row { grid-template-columns: 1fr; }

  table, thead { display: none; }
  tbody, tr, td { display: block; width: 100%; }
  tbody tr { background: var(--bg-surface-alt); border-radius: var(--radius-md); padding: var(--space-3) var(--space-4); margin-bottom: var(--space-2); }
  tbody td { border-bottom: none; padding: var(--space-1) 0; }
  .cell-amount { text-align: left; font-size: 15px; font-weight: 600; }

  .input-row { flex-direction: column; }
}

@media (max-width: 420px) {
  .stat-grid { grid-template-columns: 1fr; }
}

/* Monitor besar: batasi lebar konten */
@media (min-width: 1536px) {
  .content { max-width: 1600px; margin: 0 auto; width: 100%; }
}
</style>
</head>
<body>

<div class="app">
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-mark"><i class="ti ti-book-2"></i></div>
      <div>
        <div class="brand-name">BukuKita</div>
        <div class="brand-sub">Pembukuan perusahaan</div>
      </div>
    </div>

    <div class="nav-label">Menu utama</div>
    <a class="sidebar-item active"><i class="ti ti-layout-dashboard"></i> Dashboard</a>
    <a class="sidebar-item"><i class="ti ti-arrows-exchange"></i> Transaksi</a>
    <a class="sidebar-item"><i class="ti ti-file-invoice"></i> Invoice</a>
    <a class="sidebar-item"><i class="ti ti-users"></i> Pelanggan</a>
    <a class="sidebar-item"><i class="ti ti-report-money"></i> Laporan</a>

    <div class="nav-label">Lainnya</div>
    <a class="sidebar-item"><i class="ti ti-settings"></i> Pengaturan</a>
    <a class="sidebar-item"><i class="ti ti-logout"></i> Keluar</a>
  </aside>

  <div class="main">
    <header class="app-header">
      <div>
        <div class="header-title">Dashboard</div>
        <div class="header-sub">Ringkasan keuangan — Agustus 2026</div>
      </div>
      <div class="header-right">
        <div class="search-box">
          <i class="ti ti-search"></i>
          <input type="text" placeholder="Cari transaksi...">
        </div>
        <button class="icon-btn"><i class="ti ti-bell"></i><span class="dot"></span></button>
        <div class="avatar">RS</div>
      </div>
    </header>

    <div class="content">

      <div class="stat-grid">
        <div class="card">
          <div class="stat-top">
            <div class="stat-icon" style="background: var(--success-soft); color: var(--success);"><i class="ti ti-arrow-down-left"></i></div>
          </div>
          <div class="stat-label">Total pemasukan</div>
          <div class="stat-value">Rp 84.250.000</div>
          <div class="stat-delta up"><i class="ti ti-trending-up"></i> +12,4% dari bulan lalu</div>
        </div>

        <div class="card">
          <div class="stat-top">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger);"><i class="ti ti-arrow-up-right"></i></div>
          </div>
          <div class="stat-label">Total pengeluaran</div>
          <div class="stat-value">Rp 31.780.000</div>
          <div class="stat-delta down"><i class="ti ti-trending-down"></i> -4,1% dari bulan lalu</div>
        </div>

        <div class="card">
          <div class="stat-top">
            <div class="stat-icon" style="background: var(--accent-soft); color: var(--accent);"><i class="ti ti-wallet"></i></div>
          </div>
          <div class="stat-label">Saldo bersih</div>
          <div class="stat-value">Rp 52.470.000</div>
          <div class="stat-delta up"><i class="ti ti-trending-up"></i> +8,9% dari bulan lalu</div>
        </div>

        <div class="card">
          <div class="stat-top">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning);"><i class="ti ti-clock-hour-4"></i></div>
          </div>
          <div class="stat-label">Invoice belum lunas</div>
          <div class="stat-value">7</div>
          <div class="stat-delta" style="color: var(--text-muted);"><i class="ti ti-alert-circle"></i> Rp 14.600.000 tertunda</div>
        </div>
      </div>

      <div class="tab-group">
        <button class="tab active">Semua transaksi</button>
        <button class="tab">Pemasukan</button>
        <button class="tab">Pengeluaran</button>
        <button class="tab">Tertunda</button>
      </div>

      <div class="panel-row">
        <div class="card">
          <div class="panel-header">
            <div class="panel-title">Transaksi terbaru</div>
            <button class="btn btn-primary"><i class="ti ti-plus"></i> Transaksi baru</button>
          </div>
          <table>
            <thead>
              <tr>
                <th>Deskripsi</th>
                <th>Tanggal</th>
                <th>Status</th>
                <th>Nominal</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>
                  <div class="tx-row">
                    <div class="tx-icon" style="background: var(--success-soft); color: var(--success);"><i class="ti ti-receipt-2"></i></div>
                    <div>
                      <div class="tx-name">Penjualan frame - PT Optik Jaya</div>
                      <div class="tx-sub">INV-2026-0842</div>
                    </div>
                  </div>
                </td>
                <td style="color: var(--text-secondary);">24 Agu 2026</td>
                <td><span class="badge badge-success">Lunas</span></td>
                <td class="cell-amount pos">+Rp 3.450.000</td>
              </tr>
              <tr>
                <td>
                  <div class="tx-row">
                    <div class="tx-icon" style="background: var(--danger-soft); color: var(--danger);"><i class="ti ti-shopping-cart"></i></div>
                    <div>
                      <div class="tx-name">Pembelian stok lensa</div>
                      <div class="tx-sub">PO-2026-0311</div>
                    </div>
                  </div>
                </td>
                <td style="color: var(--text-secondary);">23 Agu 2026</td>
                <td><span class="badge badge-success">Lunas</span></td>
                <td class="cell-amount neg">-Rp 5.200.000</td>
              </tr>
              <tr>
                <td>
                  <div class="tx-row">
                    <div class="tx-icon" style="background: var(--success-soft); color: var(--success);"><i class="ti ti-receipt-2"></i></div>
                    <div>
                      <div class="tx-name">Pemeriksaan mata - Klinik Sehat Mata</div>
                      <div class="tx-sub">INV-2026-0841</div>
                    </div>
                  </div>
                </td>
                <td style="color: var(--text-secondary);">22 Agu 2026</td>
                <td><span class="badge badge-warning">Menunggu</span></td>
                <td class="cell-amount pos">+Rp 1.800.000</td>
              </tr>
              <tr>
                <td>
                  <div class="tx-row">
                    <div class="tx-icon" style="background: var(--danger-soft); color: var(--danger);"><i class="ti ti-bolt"></i></div>
                    <div>
                      <div class="tx-name">Listrik & operasional toko</div>
                      <div class="tx-sub">OPS-2026-0087</div>
                    </div>
                  </div>
                </td>
                <td style="color: var(--text-secondary);">21 Agu 2026</td>
                <td><span class="badge badge-success">Lunas</span></td>
                <td class="cell-amount neg">-Rp 890.000</td>
              </tr>
              <tr>
                <td>
                  <div class="tx-row">
                    <div class="tx-icon" style="background: var(--danger-soft); color: var(--danger);"><i class="ti ti-x"></i></div>
                    <div>
                      <div class="tx-name">Refund pelanggan - kacamata cacat</div>
                      <div class="tx-sub">RF-2026-0019</div>
                    </div>
                  </div>
                </td>
                <td style="color: var(--text-secondary);">20 Agu 2026</td>
                <td><span class="badge badge-danger">Dibatalkan</span></td>
                <td class="cell-amount neg">-Rp 450.000</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="card">
          <div class="panel-header">
            <div class="panel-title">Catat transaksi cepat</div>
          </div>

          <div class="form-group">
            <label class="label">Jenis transaksi</label>
            <select class="select">
              <option>Pemasukan</option>
              <option>Pengeluaran</option>
            </select>
          </div>

          <div class="form-group">
            <label class="label">Deskripsi</label>
            <input class="input" type="text" placeholder="Mis. Penjualan frame kacamata">
          </div>

          <div class="input-row">
            <div class="form-group">
              <label class="label">Nominal</label>
              <input class="input" type="text" placeholder="Rp 0">
            </div>
            <div class="form-group">
              <label class="label">Tanggal</label>
              <input class="input" type="text" placeholder="26/08/2026">
            </div>
          </div>

          <div class="form-group">
            <label class="label">Kategori</label>
            <select class="select">
              <option>Penjualan frame</option>
              <option>Pemeriksaan mata</option>
              <option>Operasional</option>
              <option>Lain-lain</option>
            </select>
          </div>

          <div class="toggle-row">
            <label class="label" style="margin: 0;">Tandai sudah lunas</label>
            <div class="toggle-track"><div class="toggle-thumb"></div></div>
          </div>

          <div style="display: flex; gap: var(--space-3); margin-top: var(--space-6);">
            <button class="btn btn-secondary" style="flex:1; justify-content:center;">Batal</button>
            <button class="btn btn-primary" style="flex:1; justify-content:center;">Simpan</button>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<nav class="bottom-nav">
  <div class="bottom-nav-item active"><i class="ti ti-layout-dashboard"></i>Dashboard</div>
  <div class="bottom-nav-item"><i class="ti ti-arrows-exchange"></i>Transaksi</div>
  <div class="bottom-nav-item"><i class="ti ti-file-invoice"></i>Invoice</div>
  <div class="bottom-nav-item"><i class="ti ti-report-money"></i>Laporan</div>
  <div class="bottom-nav-item"><i class="ti ti-menu-2"></i>Lainnya</div>
</nav>

</body>
</html>
