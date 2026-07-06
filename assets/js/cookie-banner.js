/* ============================================
   Ligna Milano — Banner de consentimiento de cookies
   Autónomo, sin dependencias externas.
   Guarda la decisión en localStorage.
   ============================================ */
(() => {
  'use strict';

  const STORAGE_KEY = 'lm_cookie_consent';
  const VERSION = '1';

  const readConsent = () => {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return null;
      const data = JSON.parse(raw);
      if (!data || data.version !== VERSION) return null;
      return data;
    } catch (_) {
      return null;
    }
  };

  const saveConsent = (status) => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({
        status: status,
        version: VERSION,
        date: new Date().toISOString()
      }));
    } catch (_) {}
  };

  const buildBanner = () => {
    const banner = document.createElement('section');
    banner.className = 'cookie-banner';
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-live', 'polite');
    banner.setAttribute('aria-label', 'Aviso de cookies');
    banner.innerHTML = `
      <div class="cookie-banner__text">
        <strong>Cookies y privacidad</strong>
        Usamos cookies técnicas necesarias para el funcionamiento del sitio. No utilizamos cookies de seguimiento ni publicidad sin tu permiso. Puedes aceptar o rechazar el uso de cookies no esenciales. Más información en nuestra <a href="cookies.html">Política de Cookies</a>.
      </div>
      <div class="cookie-banner__actions">
        <button type="button" class="cookie-banner__btn cookie-banner__btn--reject" data-cookie-reject>Rechazar</button>
        <button type="button" class="cookie-banner__btn cookie-banner__btn--accept" data-cookie-accept>Aceptar</button>
      </div>
    `;
    return banner;
  };

  let bannerEl = null;

  const showBanner = () => {
    if (!bannerEl) {
      bannerEl = buildBanner();
      document.body.appendChild(bannerEl);

      bannerEl.querySelector('[data-cookie-accept]').addEventListener('click', () => {
        saveConsent('accepted');
        hideBanner();
        // Punto de activación: aquí se cargarían scripts no esenciales
        // (analítica, píxeles) si en el futuro se añaden.
        window.dispatchEvent(new CustomEvent('lm:cookies-accepted'));
      });

      bannerEl.querySelector('[data-cookie-reject]').addEventListener('click', () => {
        saveConsent('rejected');
        hideBanner();
        window.dispatchEvent(new CustomEvent('lm:cookies-rejected'));
      });
    }
    requestAnimationFrame(() => bannerEl.classList.add('is-visible'));
  };

  const hideBanner = () => {
    if (bannerEl) bannerEl.classList.remove('is-visible');
  };

  const openPreferences = (event) => {
    if (event) event.preventDefault();
    showBanner();
  };

  // Enlace "Gestionar cookies" del pie
  document.querySelectorAll('[data-cookie-preferences]').forEach(control => {
    control.addEventListener('click', openPreferences);
  });

  // Mostrar el banner solo si aún no hay decisión guardada
  if (!readConsent()) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', showBanner);
    } else {
      showBanner();
    }
  }
})();
