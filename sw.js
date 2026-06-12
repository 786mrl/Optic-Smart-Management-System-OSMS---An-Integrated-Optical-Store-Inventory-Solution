// sw.js — Service Worker OpticPOS v3
// Letakkan di: C:\xampp\htdocs\optic_pos\sw.js

const CACHE_NAME = 'opticpos-v4';

// File yang WAJIB di-cache saat install
const STATIC_ASSETS = [
  '/optic_pos/app.html',
  '/optic_pos/offline.html',
  '/optic_pos/manifest.json',
  '/optic_pos/style.css',
  '/optic_pos/js/db_local.js',
  '/optic_pos/js/sync_manager.js',
  '/optic_pos/js/supabase_sync.js',
  '/optic_pos/image/icon-192.png',
  '/optic_pos/image/icon-512.png'
];

// ── INSTALL ──────────────────────────────────────────────────
self.addEventListener('install', event => {
  console.log('[SW] Installing v3...');
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return Promise.allSettled(
        STATIC_ASSETS.map(url =>
          cache.add(url)
            .then(() => console.log('[SW] Cached:', url))
            .catch(err => console.warn('[SW] Skip:', url, err.message))
        )
      );
    }).then(() => {
      console.log('[SW] Install complete');
      return self.skipWaiting();
    })
  );
});

// ── ACTIVATE ─────────────────────────────────────────────────
self.addEventListener('activate', event => {
  console.log('[SW] Activating v3...');
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.filter(k => k !== CACHE_NAME).map(k => {
          console.log('[SW] Delete old cache:', k);
          return caches.delete(k);
        })
      )
    ).then(() => self.clients.claim())
  );
});

// ── FETCH ─────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);
  if (url.hostname !== self.location.hostname) return;
  if (!url.pathname.startsWith('/optic_pos/')) return;

  // app.html → Cache First (prioritas tinggi)
  if (url.pathname === '/optic_pos/app.html' || url.pathname === '/optic_pos/') {
    event.respondWith(
      caches.match('/optic_pos/app.html').then(cached => {
        if (cached) {
          // Update cache di background
          fetch(event.request).then(res => {
            if (res.ok) caches.open(CACHE_NAME).then(c => c.put(event.request, res));
          }).catch(() => {});
          return cached;
        }
        return fetch(event.request);
      })
    );
    return;
  }

  // Aset statis (JS, CSS, gambar) → Cache First
  if (/\.(js|css|png|jpg|jpeg|gif|svg|ico|woff2?|ttf)(\?.*)?$/.test(url.pathname)) {
    event.respondWith(
      caches.match(event.request).then(cached => {
        if (cached) return cached;
        return fetch(event.request).then(res => {
          if (res.ok) {
            const clone = res.clone();
            caches.open(CACHE_NAME).then(c => c.put(event.request, clone));
          }
          return res;
        }).catch(() => caches.match('/optic_pos/offline.html'));
      })
    );
    return;
  }

  // Halaman PHP → Network First, fallback ke cache, fallback ke app.html
  event.respondWith(
    fetch(event.request)
      .then(res => {
        if (res.ok) {
          const clone = res.clone();
          caches.open(CACHE_NAME).then(c => c.put(event.request, clone));
        }
        return res;
      })
      .catch(() =>
        caches.match(event.request)
          .then(cached => cached || caches.match('/optic_pos/app.html'))
      )
  );
});