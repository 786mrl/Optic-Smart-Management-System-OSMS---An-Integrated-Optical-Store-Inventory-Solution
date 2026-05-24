<?php
// pwa_head.php — Include di dalam <head> semua file PHP
?>

<!-- PWA: Manifest -->
<link rel="manifest" href="/optic_pos/manifest.json">

<!-- PWA: Theme color -->
<meta name="theme-color" content="#1a1a2e">

<!-- PWA: iOS support -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="OpticPOS">
<link rel="apple-touch-icon" href="/optic_pos/image/icon-192.png">

<!-- PWA: Registrasi Service Worker -->
<script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      // sw.js HARUS didaftarkan dari path /optic_pos/sw.js
      // agar scope-nya mencakup seluruh folder /optic_pos/
      navigator.serviceWorker.register('/optic_pos/sw.js', {
        scope: '/optic_pos/'
      }).then(function(reg) {
        console.log('[PWA] Service Worker OK, scope:', reg.scope);
      }).catch(function(err) {
        console.warn('[PWA] Service Worker gagal:', err);
      });
    });
  }
</script>