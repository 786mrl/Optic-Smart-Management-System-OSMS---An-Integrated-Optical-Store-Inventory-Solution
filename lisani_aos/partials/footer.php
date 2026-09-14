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