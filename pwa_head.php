<?php
// pwa_head.php — versi terbaru (Fase 1 + Fase 2)
// Include di dalam <head> semua file PHP Anda
?>

<!-- PWA: Manifest -->
<link rel="manifest" href="/optic_pos/manifest.json">

<!-- PWA: Theme color -->
<meta name="theme-color" content="#1a1a2e">

<!-- PWA: iOS support -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="LenZa">
<link rel="apple-touch-icon" href="/optic_pos/image/icon-192.png">

<!-- Fase 2: IndexedDB local database -->
<script src="/optic_pos/js/db_local.js"></script>

<!-- Fase 2: Sync Manager (HP ↔ PC) -->
<script src="/optic_pos/js/sync_manager.js"></script>

<!-- PWA: Registrasi Service Worker -->
<script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      navigator.serviceWorker.register('/optic_pos/sw.js', {
        scope: '/optic_pos/'
      }).then(function(reg) {
        console.log('[PWA] Service Worker OK, scope:', reg.scope);
      }).catch(function(err) {
        console.warn('[PWA] Service Worker gagal:', err);
      });
    });
  }

  // Auto sync saat halaman pertama kali dibuka
  window.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
      if (typeof SyncManager !== 'undefined') {
        SyncManager.autoSync(true);
      }
    }, 2000);
  });
</script>
