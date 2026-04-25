(function () {
  const form = document.getElementById('ical-helper');
  if (!form) return;

  const base = form.dataset.feedBase;
  const urlInput = document.getElementById('ical-helper-url');
  const copyBtn = document.getElementById('ical-helper-copy');

  function buildUrl() {
    const params = new URLSearchParams();
    form.querySelectorAll('select[data-param]').forEach((select) => {
      if (select.value) {
        params.set(select.dataset.param, select.value);
      }
    });
    return params.size ? `${base}?${params}` : base;
  }

  form.querySelectorAll('select[data-param]').forEach((select) => {
    select.addEventListener('change', () => {
      urlInput.value = buildUrl();
    });
  });

  copyBtn.addEventListener('click', () => {
    navigator.clipboard.writeText(urlInput.value).then(() => {
      const original = copyBtn.textContent;
      copyBtn.textContent = 'Kopioitu!';
      setTimeout(() => {
        copyBtn.textContent = original;
      }, 2000);
    });
  });
})();
