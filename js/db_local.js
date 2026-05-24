// ============================================================
// db_local.js — IndexedDB Manager untuk OpticPOS
// Letakkan di: C:\xampp\htdocs\optic_pos\js\db_local.js
//
// Fungsi utama:
//   LocalDB.init()          — buka/buat database lokal
//   LocalDB.save(tabel, data)   — simpan/update satu record
//   LocalDB.getAll(tabel)       — ambil semua record
//   LocalDB.getById(tabel, id)  — ambil satu record by primary key
//   LocalDB.delete(tabel, id)   — hapus satu record
//   LocalDB.clear(tabel)        — kosongkan satu tabel
//   LocalDB.count(tabel)        — hitung jumlah record
// ============================================================

const LocalDB = (() => {

  const DB_NAME    = 'opticpos_local';
  const DB_VERSION = 1;
  let   _db        = null;

  // ── Definisi semua store (tabel) ──────────────────────────────────────────
  const STORES = {

    customer_examinations: {
      keyPath: 'id',
      autoIncrement: true,
      indexes: [
        { name: 'examination_code', keyPath: 'examination_code', unique: true },
        { name: 'invoice_number',   keyPath: 'invoice_number',   unique: false },
        { name: 'customer_name',    keyPath: 'customer_name',    unique: false },
        { name: 'examination_date', keyPath: 'examination_date', unique: false },
      ]
    },

    customer_orders: {
      keyPath: 'id',
      autoIncrement: true,
      indexes: [
        { name: 'customer_number', keyPath: 'customer_number', unique: true },
        { name: 'invoice_number',  keyPath: 'invoice_number',  unique: true },
        { name: 'order_status',    keyPath: 'order_status',    unique: false },
        { name: 'order_date',      keyPath: 'order_date',      unique: false },
      ]
    },

    custom_frames: {
      keyPath: 'id',
      autoIncrement: true,
      indexes: [
        { name: 'invoice_number', keyPath: 'invoice_number', unique: false },
        { name: 'brand_key',      keyPath: 'brand_key',      unique: false },
        { name: 'is_purchased',   keyPath: 'is_purchased',   unique: false },
      ]
    },

    frames_main: {
      keyPath: 'ufc',
      autoIncrement: false,
      indexes: [
        { name: 'brand',    keyPath: 'brand',    unique: false },
        { name: 'stock',    keyPath: 'stock',    unique: false },
        { name: 'structure',keyPath: 'structure',unique: false },
      ]
    },

    frame_staging: {
      keyPath: 'ufc',
      autoIncrement: false,
      indexes: [
        { name: 'brand', keyPath: 'brand', unique: false },
        { name: 'stock', keyPath: 'stock', unique: false },
      ]
    },

    prescription_modifications: {
      keyPath: 'modification_id',
      autoIncrement: true,
      indexes: [
        { name: 'invoice_number', keyPath: 'invoice_number', unique: false },
      ]
    },

    settings: {
      keyPath: 'setting_key',
      autoIncrement: false,
      indexes: []
    },

    users: {
      keyPath: 'user_id',
      autoIncrement: true,
      indexes: [
        { name: 'username', keyPath: 'username', unique: true },
      ]
    },

    // ── Tabel khusus untuk antrian sync (data yang belum terkirim ke server) ──
    _sync_queue: {
      keyPath: 'queue_id',
      autoIncrement: true,
      indexes: [
        { name: 'store_name',  keyPath: 'store_name',  unique: false },
        { name: 'status',      keyPath: 'status',      unique: false },
        { name: 'created_at',  keyPath: 'created_at',  unique: false },
      ]
    },

    // ── Tabel untuk menyimpan metadata sync terakhir ──
    _sync_meta: {
      keyPath: 'meta_key',
      autoIncrement: false,
      indexes: []
    }

  };

  // ── INIT: buka database ───────────────────────────────────────────────────
  function init() {
    return new Promise((resolve, reject) => {
      if (_db) { resolve(_db); return; }

      const request = indexedDB.open(DB_NAME, DB_VERSION);

      // Dipanggil saat database dibuat pertama kali atau versi naik
      request.onupgradeneeded = (event) => {
        const db = event.target.result;
        console.log('[LocalDB] Membuat/upgrade database...');

        for (const [storeName, config] of Object.entries(STORES)) {
          if (!db.objectStoreNames.contains(storeName)) {
            const store = db.createObjectStore(storeName, {
              keyPath:       config.keyPath,
              autoIncrement: config.autoIncrement
            });
            for (const idx of config.indexes) {
              store.createIndex(idx.name, idx.keyPath, { unique: idx.unique });
            }
            console.log('[LocalDB] Store dibuat:', storeName);
          }
        }
      };

      request.onsuccess = (event) => {
        _db = event.target.result;
        console.log('[LocalDB] Database siap:', DB_NAME, 'v' + DB_VERSION);
        resolve(_db);
      };

      request.onerror = (event) => {
        console.error('[LocalDB] Gagal buka database:', event.target.error);
        reject(event.target.error);
      };
    });
  }

  // ── Helper: buka transaksi ────────────────────────────────────────────────
  function _tx(storeName, mode = 'readonly') {
    const tx    = _db.transaction(storeName, mode);
    const store = tx.objectStore(storeName);
    return { tx, store };
  }

  function _promisify(request) {
    return new Promise((resolve, reject) => {
      request.onsuccess = () => resolve(request.result);
      request.onerror   = () => reject(request.error);
    });
  }

  // ── SAVE: tambah atau update satu record ──────────────────────────────────
  // data harus berisi primary key jika ingin update
  async function save(storeName, data) {
    await init();
    // Tambahkan timestamp updated_at otomatis
    data._updated_at = new Date().toISOString();
    const { store } = _tx(storeName, 'readwrite');
    return _promisify(store.put(data));
  }

  // ── SAVE MANY: simpan array of records sekaligus ──────────────────────────
  async function saveMany(storeName, records) {
    await init();
    const tx    = _db.transaction(storeName, 'readwrite');
    const store = tx.objectStore(storeName);
    const now   = new Date().toISOString();

    return new Promise((resolve, reject) => {
      let count = 0;
      for (const rec of records) {
        rec._updated_at = now;
        const req = store.put(rec);
        req.onsuccess = () => { count++; };
        req.onerror   = (e) => console.warn('[LocalDB] saveMany error:', e.target.error);
      }
      tx.oncomplete = () => {
        console.log(`[LocalDB] saveMany ${storeName}: ${count} records`);
        resolve(count);
      };
      tx.onerror = () => reject(tx.error);
    });
  }

  // ── GET ALL: ambil semua record dari satu store ───────────────────────────
  async function getAll(storeName) {
    await init();
    const { store } = _tx(storeName, 'readonly');
    return _promisify(store.getAll());
  }

  // ── GET BY ID: ambil satu record by primary key ───────────────────────────
  async function getById(storeName, id) {
    await init();
    const { store } = _tx(storeName, 'readonly');
    return _promisify(store.get(id));
  }

  // ── GET BY INDEX: ambil records berdasarkan index ─────────────────────────
  async function getByIndex(storeName, indexName, value) {
    await init();
    const { store } = _tx(storeName, 'readonly');
    const index     = store.index(indexName);
    return _promisify(index.getAll(value));
  }

  // ── DELETE: hapus satu record by primary key ──────────────────────────────
  async function remove(storeName, id) {
    await init();
    const { store } = _tx(storeName, 'readwrite');
    return _promisify(store.delete(id));
  }

  // ── CLEAR: kosongkan semua record di satu store ───────────────────────────
  async function clear(storeName) {
    await init();
    const { store } = _tx(storeName, 'readwrite');
    return _promisify(store.clear());
  }

  // ── COUNT: hitung jumlah record ───────────────────────────────────────────
  async function count(storeName) {
    await init();
    const { store } = _tx(storeName, 'readonly');
    return _promisify(store.count());
  }

  // ── SYNC QUEUE: tambahkan operasi ke antrian sync ─────────────────────────
  // action: 'INSERT' | 'UPDATE' | 'DELETE'
  async function addToSyncQueue(storeName, action, data) {
    await init();
    const { store } = _tx('_sync_queue', 'readwrite');
    return _promisify(store.put({
      store_name: storeName,
      action:     action,
      data:       data,
      status:     'pending',   // pending | synced | failed
      created_at: new Date().toISOString(),
      retry_count: 0
    }));
  }

  // ── SYNC QUEUE: ambil semua yang masih pending ────────────────────────────
  async function getPendingSyncQueue() {
    await init();
    const { store } = _tx('_sync_queue', 'readonly');
    const index     = store.index('status');
    return _promisify(index.getAll('pending'));
  }

  // ── SYNC QUEUE: tandai item sebagai synced ────────────────────────────────
  async function markSyncDone(queueId) {
    await init();
    const item = await getById('_sync_queue', queueId);
    if (item) {
      item.status = 'synced';
      item.synced_at = new Date().toISOString();
      const { store } = _tx('_sync_queue', 'readwrite');
      return _promisify(store.put(item));
    }
  }

  // ── SYNC META: simpan/ambil metadata ─────────────────────────────────────
  async function setMeta(key, value) {
    await init();
    const { store } = _tx('_sync_meta', 'readwrite');
    return _promisify(store.put({ meta_key: key, value, updated_at: new Date().toISOString() }));
  }

  async function getMeta(key) {
    await init();
    const { store } = _tx('_sync_meta', 'readonly');
    const result = await _promisify(store.get(key));
    return result ? result.value : null;
  }

  // ── STATUS: ringkasan semua store ────────────────────────────────────────
  async function getStatus() {
    await init();
    const status = {};
    for (const storeName of Object.keys(STORES)) {
      if (!storeName.startsWith('_')) {
        status[storeName] = await count(storeName);
      }
    }
    status._pending_sync = (await getPendingSyncQueue()).length;
    status._last_sync    = await getMeta('last_sync_at');
    return status;
  }

  // ── PUBLIC API ─────────────────────────────────────────────────────────────
  return {
    init,
    save,
    saveMany,
    getAll,
    getById,
    getByIndex,
    remove,
    clear,
    count,
    addToSyncQueue,
    getPendingSyncQueue,
    markSyncDone,
    setMeta,
    getMeta,
    getStatus,
    STORES
  };

})();

// Auto-init saat file dimuat
LocalDB.init().catch(err => console.error('[LocalDB] Init gagal:', err));
