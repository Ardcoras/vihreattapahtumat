(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.querySelector('.site-header__nav-toggle');
    if (!toggle) return;

    toggle.addEventListener('click', function () {
      var expanded = this.getAttribute('aria-expanded') === 'true';
      this.setAttribute('aria-expanded', String(!expanded));
      document.documentElement.classList.toggle('nav-open', !expanded);
    });
  });
})();
