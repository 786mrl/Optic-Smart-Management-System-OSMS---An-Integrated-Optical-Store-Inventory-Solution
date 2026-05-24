// ============================================================
// sync_manager.js — Manajer Sinkronisasi HP ↔ PC
// Letakkan di: C:\xampp\htdocs\optic_pos\js\sync_manager.js
//
// Cara pakai:
//   SyncManager.pullAll()         — tarik semua data dari PC ke HP
//   SyncManager.pushPending()     — send pending data from device to PC
//   SyncManager.autoSync()        — pull + push otomatis
//   SyncManager.getStatus()       — cek status sync
// ============================================================

const SyncManager = (() => {

  // ── Konfigurasi ────────────────────────────────────────────────────────────
  // Auto-detect: pakai origin dari URL yang sedang dibuka
  // Ini otomatis cocok baik pakai IP lokal, ngrok, maupun domain apapun
  const PC_BASE_URL   = window.location.origin + '/optic_pos';
  const API_ENDPOINT  = PC_BASE_URL + '/api_local_sync.php';

  // Tabel yang di-pull dari PC ke HP (urutan penting: settings dulu)
  const PULL_TABLES = [
    'settings',
    'users',
    'frames_main',
    'frame_staging',
    'customer_examinations',
    'customer_orders',
    'custom_frames',
    'prescription_modifications'
  ];

  // Tabel yang boleh di-push dari HP ke PC
  const PUSH_TABLES = [
    'customer_examinations',
    'customer_orders',
    'custom_frames',
    'prescription_modifications'
  ];

  let _isSyncing = false;

  // ── Cek apakah PC bisa dijangkau ─────────────────────────────────────────
  async function isPCReachable() {
    try {
      const res = await fetch(`${API_ENDPOINT}?action=status`, {
        method: 'GET',
        signal: AbortSignal.timeout(3000) // timeout 3 detik
      });
      return res.ok;
    } catch {
      return false;
    }
  }

  // ── PULL: tarik data dari PC, simpan ke IndexedDB ────────────────────────
  async function pullTable(tableName, since = null) {
    let url = `${API_ENDPOINT}?action=pull&table=${tableName}`;
    if (since) url += `&since=${encodeURIComponent(since)}`;

    const res  = await fetch(url);
    const json = await res.json();

    if (!json.success) throw new Error(json.error || 'Pull gagal');

    if (json.data && json.data.length > 0) {
      await LocalDB.saveMany(tableName, json.data);
      console.log(`[Sync] Pull ${tableName}: ${json.count} records`);
    }

    return json.count || 0;
  }

  async function pullAll(onProgress = null) {
    const lastSync = await LocalDB.getMeta('last_sync_at');
    let totalPulled = 0;

    for (let i = 0; i < PULL_TABLES.length; i++) {
      const table = PULL_TABLES[i];
      try {
        const count = await pullTable(table, lastSync);
        totalPulled += count;
        if (onProgress) onProgress(table, i + 1, PULL_TABLES.length, count);
      } catch (err) {
        console.warn(`[Sync] Pull ${table} gagal:`, err.message);
      }
    }

    await LocalDB.setMeta('last_sync_at', new Date().toISOString());
    return totalPulled;
  }

  // ── PUSH: send pending data from device to PC ────────────────────────────────
  async function pushPending() {
    const queue = await LocalDB.getPendingSyncQueue();
    if (queue.length === 0) {
      console.log('[Sync] No pending data to push');
      return 0;
    }

    // Kelompokkan berdasarkan tabel
    const byTable = {};
    for (const item of queue) {
      if (!byTable[item.store_name]) byTable[item.store_name] = [];
      byTable[item.store_name].push(item);
    }

    let totalPushed = 0;

    for (const [tableName, items] of Object.entries(byTable)) {
      if (!PUSH_TABLES.includes(tableName)) continue;

      const records = items.map(item => item.data);

      try {
        const res  = await fetch(`${API_ENDPOINT}?action=push`, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify({ table: tableName, records })
        });
        const json = await res.json();

        if (json.success) {
          // Tandai semua item di queue ini sebagai synced
          for (const item of items) {
            await LocalDB.markSyncDone(item.queue_id);
          }
          totalPushed += json.saved;
          console.log(`[Sync] Push ${tableName}: ${json.saved} records terkirim`);
        }
      } catch (err) {
        console.warn(`[Sync] Push ${tableName} gagal:`, err.message);
      }
    }

    return totalPushed;
  }

  // ── AUTO SYNC: pull + push, tampilkan notifikasi ──────────────────────────
  async function autoSync(showUI = true) {
    if (_isSyncing) {
      console.log('[Sync] Sync sudah berjalan...');
      return;
    }

    _isSyncing = true;

    // Tampilkan indikator sync di UI
    if (showUI) showSyncBadge('syncing');

    try {
      const reachable = await isPCReachable();

      if (!reachable) {
        console.log('[Sync] PC unreachable — offline mode');
        if (showUI) showSyncBadge('offline');
        _isSyncing = false;
        return;
      }

      console.log('[Sync] PC reachable, starting sync...');

      // 1. Push pending data first
      const pushed = await pushPending();

      // 2. Pull data terbaru
      const pulled = await pullAll((table, current, total, count) => {
        console.log(`[Sync] Pull ${current}/${total}: ${table} (${count} records)`);
      });

      await LocalDB.setMeta('last_sync_at', new Date().toISOString());
      await LocalDB.setMeta('last_sync_pushed', pushed);
      await LocalDB.setMeta('last_sync_pulled', pulled);

      console.log(`[Sync] Selesai — Push: ${pushed}, Pull: ${pulled}`);
      if (showUI) showSyncBadge('synced', pushed, pulled);

    } catch (err) {
      console.error('[Sync] Error:', err);
      if (showUI) showSyncBadge('error');
    } finally {
      _isSyncing = false;
    }
  }

  // ── UI: tampilkan badge status sync ──────────────────────────────────────
  function showSyncBadge(status, pushed = 0, pulled = 0) {
    let badge = document.getElementById('sync-status-badge');

    if (!badge) {
      badge = document.createElement('div');
      badge.id = 'sync-status-badge';
      badge.style.cssText = `
        position: fixed;
        bottom: 16px;
        right: 16px;
        padding: 8px 14px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        z-index: 9999;
        transition: all 0.3s ease;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(0,0,0,0.3);
      `;
      badge.onclick = () => badge.style.display = 'none';
      document.body.appendChild(badge);
    }

    const configs = {
      syncing: { bg: '#1a3a5c', color: '#7eb8f7', text: '⟳ Syncing...' },
      synced:  { bg: '#1a3a2c', color: '#7effa0', text: `✓ Sync done (↑${pushed} ↓${pulled})` },
      offline: { bg: '#3a1a1a', color: '#ff9e9e', text: '⚠ Offline — local data' },
      error:   { bg: '#3a1a1a', color: '#ff6b6b', text: '✗ Sync failed' }
    };

    const cfg = configs[status] || configs.offline;
    badge.style.background = cfg.bg;
    badge.style.color      = cfg.color;
    badge.textContent      = cfg.text;
    badge.style.display    = 'block';

    // Sembunyikan otomatis setelah 4 detik (kecuali offline/error)
    if (status === 'synced') {
      setTimeout(() => { badge.style.display = 'none'; }, 4000);
    }
  }

  // ── STATUS: ringkasan ─────────────────────────────────────────────────────
  async function getStatus() {
    const dbStatus   = await LocalDB.getStatus();
    const lastSync   = await LocalDB.getMeta('last_sync_at');
    const reachable  = await isPCReachable();

    return {
      pc_reachable:  reachable,
      last_sync_at:  lastSync,
      pending_push:  dbStatus._pending_sync,
      local_records: dbStatus
    };
  }

  // ── AUTO SYNC saat online kembali ─────────────────────────────────────────
  window.addEventListener('online', () => {
    console.log('[Sync] Connection restored — starting auto sync...');
    setTimeout(() => autoSync(true), 1500);
  });

  window.addEventListener('offline', () => {
    showSyncBadge('offline');
  });

  // ── PUBLIC API ────────────────────────────────────────────────────────────
  return {
    isPCReachable,
    pullAll,
    pullTable,
    pushPending,
    autoSync,
    getStatus,
    showSyncBadge,
    PC_BASE_URL,
    PULL_TABLES,
    PUSH_TABLES
  };

})();