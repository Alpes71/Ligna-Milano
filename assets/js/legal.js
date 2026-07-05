(() => {
  'use strict';

  const year = document.querySelector('#year');
  if (year) year.textContent = new Date().getFullYear();

  const openCookiePreferences = (event) => {
    if (event) event.preventDefault();
    try {
      if (window._iub?.cs?.api && typeof window._iub.cs.api.openPreferences === 'function') {
        window._iub.cs.api.openPreferences();
        return;
      }
      if (window._iub?.cs?.ui && typeof window._iub.cs.ui.openPreferences === 'function') {
        window._iub.cs.ui.openPreferences();
        return;
      }
      if (window._iub?.cs && typeof window._iub.cs.openPreferences === 'function') {
        window._iub.cs.openPreferences();
        return;
      }
    } catch (_) {}
    alert('El panel de cookies de iubenda todavía no terminó de cargar. Recarga la página o vuelve a intentarlo en unos segundos.');
  };

  document.querySelectorAll('[data-cookie-preferences]').forEach(control => {
    control.addEventListener('click', openCookiePreferences);
  });
})();
