// sw.js — Service Worker OpticPOS
// File ini HARUS berada di: C:\xampp\htdocs\optic_pos\sw.js

const CACHE_NAME = 'opticpos-v2';

const STATIC_ASSETS = [
  '/optic_pos/',
  '/optic_pos/index.php',
  '/optic_pos/login.php',
  '/optic_pos/offline.html',
  '/optic_pos/style.css',
  '/optic_pos/manifest.json',
  '/optic_pos/image/icon-192.png',
  '/optic_pos/image/icon-512.png'
];

// ── INSTALL ──────────────────────────────────────────────────────────────────
self.addEventListener('install', event => {
  console.log('[SW] Install...');
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return Promise.allSettled(
        STATIC_ASSETS.map(url =>
          cache.add(url).catch(err => console.warn('[SW] Skip cache:', url, err))
        )
      );
    }).then(() => self.skipWaiting())
  );
});

// ── ACTIVATE ─────────────────────────────────────────────────────────────────
self.addEventListener('activate', event => {
  console.log('[SW] Activate...');
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ── FETCH ─────────────────────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
  // Hanya handle GET request di dalam scope /optic_pos/
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);
  if (url.hostname !== self.location.hostname) return;
  if (!url.pathname.startsWith("/optic_pos/")) return;

  // Aset statis → Cache First
  if (/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf)(\?.*)?$/.test(url.pathname)) {
    event.respondWith(
      caches.match(event.request).then(cached => {
        if (cached) return cached;
        return fetch(event.request).then(res => {
          if (res.ok) {
            const clone = res.clone();
            caches.open(CACHE_NAME).then(c => c.put(event.request, clone));
          }
          return res;
        });
      })
    );
    return;
  }

  // Halaman PHP → Network First, fallback cache, fallback offline
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
          .then(cached => cached || caches.match('/optic_pos/offline.html'))
      )
  );
});

// ── BACKGROUND SYNC (dipakai di Fase 4) ──────────────────────────────────────
self.addEventListener('sync', event => {
  if (event.tag === 'sync-pending-data') {
    event.waitUntil(syncPendingData());
  }
});

async function syncPendingData() {
  // Implementasi di Fase 4 — Sync Engine
  console.log('[SW] Background sync siap — implementasi Fase 4');
}
