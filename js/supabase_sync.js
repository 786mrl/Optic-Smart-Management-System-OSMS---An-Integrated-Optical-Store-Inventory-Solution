// ============================================================
// supabase_sync.js — Sinkronisasi ke Supabase Cloud
// Letakkan di: C:\xampp\htdocs\optic_pos\js\supabase_sync.js
// ============================================================

const SupabaseSync = (() => {

  // ── Konfigurasi ───────────────────────────────────────────
  const SUPABASE_URL = 'https://rnuyhoxlmpivkoxwyxln.supabase.co';
  const SUPABASE_KEY = 'sb_publishable_dTU5WLDoTWdLhN68x8Pn6g_SwJvFeRs';
  const HEADERS = {
    'Content-Type':  'application/json',
    'apikey':        SUPABASE_KEY,
    'Authorization': `Bearer ${SUPABASE_KEY}`,
    'Prefer':        'resolution=merge-duplicates'
  };

  // Tabel yang di-sync ke cloud
  const CLOUD_TABLES = [
    'settings',
    'users',
    'frames_main',
    'frame_staging',
    'customer_examinations',
    'customer_orders',
    'custom_frames',
    'prescription_modifications'
  ];

  // ── Cek koneksi ke Supabase ───────────────────────────────
  async function isReachable() {
    try {
      const res = await fetch(`${SUPABASE_URL}/rest/v1/settings?limit=1`, {
        headers: HEADERS,
        signal: AbortSignal.timeout(5000)
      });
      return res.ok || res.status === 406;
    } catch {
      return false;
    }
  }

  // ── PULL: ambil data dari cloud → simpan ke IndexedDB ─────
  async function pullTable(tableName, since = null) {
    let url = `${SUPABASE_URL}/rest/v1/${tableName}?select=*`;
    if (since) {
      url += `&updated_at=gte.${encodeURIComponent(since)}`;
    }

    const res  = await fetch(url, { headers: HEADERS });
    if (!res.ok) throw new Error(`Pull ${tableName} failed: ${res.status}`);

    const data = await res.json();
    if (data.length > 0) {
      await LocalDB.saveMany(tableName, data);
      console.log(`[Cloud] Pull ${tableName}: ${data.length} records`);
    }
    return data.length;
  }

  async function pullAll(onProgress = null) {
    const lastSync  = await LocalDB.getMeta('last_cloud_sync_at');
    let totalPulled = 0;

    for (let i = 0; i < CLOUD_TABLES.length; i++) {
      const table = CLOUD_TABLES[i];
      try {
        const count = await pullTable(table, lastSync);
        totalPulled += count;
        if (onProgress) onProgress(table, i + 1, CLOUD_TABLES.length, count);
      } catch (err) {
        console.warn(`[Cloud] Pull ${table} failed:`, err.message);
      }
    }

    await LocalDB.setMeta('last_cloud_sync_at', new Date().toISOString());
    return totalPulled;
  }

  // ── PUSH: kirim data dari IndexedDB → cloud ───────────────
  async function pushTable(tableName) {
    const records = await LocalDB.getAll(tableName);
    if (!records || records.length === 0) return 0;

    // Bersihkan field internal IndexedDB
    const cleaned = records.map(r => {
      const c = { ...r };
      delete c._updated_at;
      return c;
    });

    // Kirim dalam batch 100 record
    const BATCH = 100;
    let pushed  = 0;

    for (let i = 0; i < cleaned.length; i += BATCH) {
      const batch = cleaned.slice(i, i + BATCH);
      const res   = await fetch(`${SUPABASE_URL}/rest/v1/${tableName}`, {
        method:  'POST',
        headers: { ...HEADERS, 'Prefer': 'resolution=merge-duplicates,return=minimal' },
        body:    JSON.stringify(batch)
      });
      if (res.ok || res.status === 201) {
        pushed += batch.length;
      } else {
        const err = await res.text();
        console.warn(`[Cloud] Push ${tableName} batch failed:`, err);
      }
    }

    console.log(`[Cloud] Push ${tableName}: ${pushed}/${records.length}`);
    return pushed;
  }

  // Hanya push tabel yang bisa diubah dari HP
  const PUSHABLE = [
    'customer_examinations',
    'customer_orders',
    'custom_frames',
    'prescription_modifications'
  ];

  async function pushAll(onProgress = null) {
    let totalPushed = 0;
    for (let i = 0; i < PUSHABLE.length; i++) {
      const table = PUSHABLE[i];
      try {
        const count = await pushTable(table);
        totalPushed += count;
        if (onProgress) onProgress(table, i + 1, PUSHABLE.length, count);
      } catch (err) {
        console.warn(`[Cloud] Push ${table} failed:`, err.message);
      }
    }
    return totalPushed;
  }

  // ── AUTO SYNC: pull + push ke cloud ──────────────────────
  async function autoSync(showUI = true) {
    const reachable = await isReachable();
    if (!reachable) {
      console.log('[Cloud] Supabase tidak terjangkau');
      return { pulled: 0, pushed: 0, online: false };
    }

    console.log('[Cloud] Supabase terjangkau, mulai sync...');
    if (showUI && typeof SyncManager !== 'undefined') {
      SyncManager.showSyncBadge('syncing');
    }

    try {
      const pushed = await pushAll();
      const pulled = await pullAll();

      await LocalDB.setMeta('last_cloud_sync_at', new Date().toISOString());
      console.log(`[Cloud] Selesai — Push: ${pushed}, Pull: ${pulled}`);

      if (showUI && typeof SyncManager !== 'undefined') {
        SyncManager.showSyncBadge('synced', pushed, pulled);
      }

      return { pulled, pushed, online: true };
    } catch (err) {
      console.error('[Cloud] Sync error:', err);
      if (showUI && typeof SyncManager !== 'undefined') {
        SyncManager.showSyncBadge('error');
      }
      return { pulled: 0, pushed: 0, online: true, error: err.message };
    }
  }

  // ── OFFLINE LOGIN: verifikasi user dari IndexedDB ─────────
  async function verifyOfflineLogin(username, passwordHash) {
    try {
      const users = await LocalDB.getByIndex('users', 'username', username);
      if (users && users.length > 0) {
        const user = users[0];
        return user.is_approved ? user : null;
      }
    } catch (err) {
      console.warn('[Cloud] Offline login check failed:', err);
    }
    return null;
  }

  // ── PUBLIC API ─────────────────────────────────────────────
  return {
    isReachable,
    pullAll,
    pullTable,
    pushAll,
    pushTable,
    autoSync,
    verifyOfflineLogin,
    SUPABASE_URL,
    CLOUD_TABLES
  };

})();
