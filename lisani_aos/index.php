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

<div class="app">
  <?php include 'partials/sidebar.php'; ?>

  <div class="main">
    <header class="app-header">
      <div>
        <div class="header-title">Lisani AOS</div>
        <div class="header-sub">Optical store management</div>
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

      <!-- Report -->
      <div class="menu-section" data-section="report" style="display:none;">
        <div class="card" style="width:100%;">
          <div class="empty-state">
            <i class="ti ti-report-money"></i>
            <div class="empty-title">Report</div>
            <div class="empty-sub">No content yet.</div>
          </div>
        </div>
      </div>

      <!-- Settings -->
      <?php include 'settings_content.php'; ?>

    </div>
  </div>
</div>

<script>
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