<?php
session_start();

// Only allow sessions from "extended" (triple-click) login via ../login.php.
// Sessions from "normal" mode (optic_pos / welcome.php) must not be able to reach this.
if (!isset($_SESSION['app']) || $_SESSION['app'] !== 'lisani_aos' || !isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once 'db_config.php';

$pageTitle = 'Dashboard';
include 'partials/header.php';
?>
<style>
  /* Logo next to the topbar title/subtitle. theme.css doesn't have these
     classes yet, so kept inline here rather than guessing at theme.css. */
  .header-brand { display:flex; align-items:center; gap:12px; }
  .header-logo { height:36px; width:auto; object-fit:contain; }

  /* The logo itself is the sidebar show/hide toggle — no button container,
     just the image. Clickable (toggles) only on desktop (>=1024px); at the
     tablet rail (768–1023px) and mobile (<768px, uses .bottom-nav instead)
     the sidebar width is already fixed by responsive.css, so the logo stays
     visible there but the JS below skips the toggle (can't "push" it away). */
  #sidebarToggleBtn { cursor: pointer; opacity: 1; transition: opacity .15s ease; }
  @media (min-width: 1024px) { #sidebarToggleBtn.is-active { opacity: .6; } } /* sidebar currently hidden */

  @media (min-width: 1024px) {
    .app { transition: grid-template-columns .18s ease; }
    .sidebar { transition: width .18s ease, padding .18s ease, opacity .12s ease; overflow: hidden; }
    .app.sidebar-collapsed { grid-template-columns: 0 minmax(0, 1fr) !important; }
    .app.sidebar-collapsed .sidebar { width: 0; padding: 0; opacity: 0; }
  }
</style>

<div class="app">
  <?php include 'partials/sidebar.php'; ?>

  <div class="main">
    <header class="app-header">
      <div class="header-brand">
        <img src="assets/img/lisani_logo.png" alt="Lisani AOS" class="header-logo" id="sidebarToggleBtn">
        <div>
          <div class="header-title">LISANI AOS</div>
          <div class="header-sub">Lisani Administration Operating System</div>
        </div>
      </div>
      <div class="header-right">
        <div class="search-box">
          <i class="ti ti-search"></i>
          <input type="text" placeholder="Search...">
        </div>

        <div class="user-menu" id="userMenu">
          <button type="button" class="avatar" id="userMenuTrigger" aria-haspopup="true" aria-expanded="false">
            <?= htmlspecialchars(strtoupper(substr($_SESSION['username'] ?? 'U', 0, 2))) ?>
          </button>
          <div class="user-menu-dropdown">
            <div class="user-menu-header">
              <div class="user-menu-name"><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></div>
              <div class="user-menu-role"><?= htmlspecialchars($_SESSION['role'] ?? '') ?></div>
            </div>
            <button type="button" class="user-menu-item" data-target="settings">
              <i class="ti ti-settings"></i> Settings
            </button>
            <a class="user-menu-item user-menu-item-danger" href="../logout.php">
              <i class="ti ti-logout"></i> Exit
            </a>
          </div>
        </div>
      </div>
    </header>

    <div class="content">

      <!-- Dashboard -->
      <div class="menu-section" data-section="dashboard" style="display:flex;">
        <div class="card" style="width:100%;">
          <div class="empty-state">
            <i class="ti ti-layout-dashboard"></i>
            <div class="empty-title">Dashboard</div>
            <div class="empty-sub">No content yet.</div>
          </div>
        </div>
      </div>

      <!-- Transactions -->
      <?php include 'transaction_content.php'; ?>

      <!-- Logistic -->
      <?php include 'logistic_content.php'; ?>

      <!-- Document Tools -->
      <?php include 'document_tools_content.php'; ?>

      <!-- Report -->
      <?php include 'report_content.php'; ?>

      <!-- Investor -->
      <?php include 'investor_content.php'; ?>

      <!-- Settings -->
      <?php include 'settings_content.php'; ?>

    </div>
  </div>
</div>

<script>
// Sidebar show/hide toggle (desktop only, >=1024px — see CSS). Remembered
// across reloads via localStorage, so it doesn't reset every page load.
(function () {
  var app = document.querySelector('.app');
  var btn = document.getElementById('sidebarToggleBtn');
  var KEY = 'aos_sidebar_collapsed';

  function apply(collapsed) {
    app.classList.toggle('sidebar-collapsed', collapsed);
    btn.classList.toggle('is-active', collapsed);
  }

  // Default: hidden, unless the user explicitly showed it before ('0' saved).
  apply(localStorage.getItem(KEY) !== '0');

  btn.addEventListener('click', function () {
    // Tablet rail (768–1023px) and mobile (<768px) already fix the sidebar's
    // width/visibility via responsive.css — the logo stays visible there,
    // but clicking it must not "push" the sidebar away on those sizes.
    if (window.innerWidth < 1024) return;
    var collapsed = !app.classList.contains('sidebar-collapsed');
    apply(collapsed);
    localStorage.setItem(KEY, collapsed ? '1' : '0');
  });
})();

// Avatar dropdown open/close. Section switching for the "Settings" item
// inside it is handled by the shared [data-target] listener in footer.php.
(function () {
  var menu = document.getElementById('userMenu');
  var trigger = document.getElementById('userMenuTrigger');

  trigger.addEventListener('click', function (e) {
    e.stopPropagation();
    var isOpen = menu.classList.toggle('open');
    trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  });

  document.addEventListener('click', function (e) {
    if (!menu.contains(e.target)) {
      menu.classList.remove('open');
      trigger.setAttribute('aria-expanded', 'false');
    }
  });
})();
</script>

<?php include 'partials/footer.php'; ?>