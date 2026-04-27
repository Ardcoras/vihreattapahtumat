(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.querySelector('.site-header__nav-toggle');
    if (toggle) {
      toggle.addEventListener('click', function () {
        var expanded = this.getAttribute('aria-expanded') === 'true';
        this.setAttribute('aria-expanded', String(!expanded));
        document.documentElement.classList.toggle('nav-open', !expanded);
      });
    }

    document.querySelectorAll('[data-past-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var list = btn.nextElementSibling;
        var open = !list.hidden;
        list.hidden = open;
        btn.classList.toggle('open', !open);
      });
    });
  });
})();
