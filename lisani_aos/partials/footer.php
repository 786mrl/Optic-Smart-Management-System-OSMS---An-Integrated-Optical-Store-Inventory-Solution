<script>
(function () {
  var sections = document.querySelectorAll('.menu-section');

  function setActive(target) {
    document.querySelectorAll('[data-target]').forEach(function (el) {
      el.classList.toggle('active', el.dataset.target === target);
    });
    sections.forEach(function (el) {
      el.style.display = (el.dataset.section === target) ? 'flex' : 'none';
    });

    // Each *_content.php is its own IIFE that only fetches its data once, at
    // page load — switching sections here is a plain show/hide, not a page
    // reload, so a section a user left earlier (e.g. Logistic, after placing
    // an order from Transactions) kept showing stale data until a manual
    // page refresh. This event lets any section listen for "I've just been
    // shown" and refresh itself live instead.
    document.dispatchEvent(new CustomEvent('aos:section-shown', { detail: { target: target } }));
  }

  document.querySelectorAll('[data-target]').forEach(function (el) {
    el.addEventListener('click', function () {
      setActive(el.dataset.target);

      // If the click came from inside the avatar dropdown, close it too.
      var userMenu = el.closest('.user-menu');
      if (userMenu) {
        userMenu.classList.remove('open');
        var trigger = userMenu.querySelector('#userMenuTrigger');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
      }
    });
  });

  setActive('dashboard');
})();
</script>
</body>
</html>