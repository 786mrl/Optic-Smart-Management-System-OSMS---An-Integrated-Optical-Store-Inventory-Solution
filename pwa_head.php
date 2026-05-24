<?php
// pwa_head.php — Fase 1 + 2 + 3
?>

<!-- PWA: Manifest -->
<link rel="manifest" href="/optic_pos/manifest.json">
<meta name="theme-color" content="#1a1a2e">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="LenZa">
<link rel="apple-touch-icon" href="/optic_pos/image/icon-192.png">

<!-- Fase 2: IndexedDB -->
<script src="/optic_pos/js/db_local.js"></script>

<!-- Fase 2: Sync WiFi lokal -->
<script src="/optic_pos/js/sync_manager.js"></script>

<!-- Fase 3: Supabase cloud sync -->
<script src="/optic_pos/js/supabase_sync.js"></script>

<!-- PWA: Service Worker -->
<script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      navigator.serviceWorker.register('/optic_pos/sw.js', {
        scope: '/optic_pos/'
      }).then(function(reg) {
        console.log('[PWA] Service Worker OK:', reg.scope);
      }).catch(function(err) {
        console.warn('[PWA] Service Worker failed:', err);
      });
    });
  }

  // Auto sync saat halaman dibuka — coba WiFi lokal dulu, lalu cloud
  window.addEventListener('DOMContentLoaded', function() {
    setTimeout(async function() {
      if (typeof SyncManager === 'undefined') return;

      // Coba sync WiFi lokal (PC) dulu
      const pcReachable = await SyncManager.isPCReachable();
      if (pcReachable) {
        await SyncManager.autoSync(true);
      } else {
        // PC tidak terjangkau → coba Supabase cloud
        if (typeof SupabaseSync !== 'undefined') {
          await SupabaseSync.autoSync(true);
        }
      }
    }, 2000);
  });
</script>
