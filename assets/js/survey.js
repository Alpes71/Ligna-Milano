/* ============================================
   Ligna Milano — Encuesta de satisfacción
   ============================================ */
(() => {
  'use strict';

  const I18N = window.LM_SURVEY_I18N || {};
  const FLAGS = window.LM_FLAGS || {};
  const TRUSTPILOT_URL = 'https://es.trustpilot.com/evaluate/lignamilano.com';

  const $ = s => document.querySelector(s);
  const landing = $('[data-landing]');
  const formWrap = $('[data-form-wrap]');
  let lang = 'es';
  const ratings = { exp: 0, calidad: 0, nps: -1 };

  /* ---------- Selector de idioma ---------- */
  function pintarSelector() {
    const grid = $('[data-lang-grid]');
    const orden = ['es','ca','va','eu','gl','en','fr','de'];
    grid.innerHTML = orden.map(code => {
      const t = I18N[code];
      return `<button type="button" class="lang-btn" data-lang="${code}">
        <span class="flag">${FLAGS[code] || ''}</span>
        <span>${t.label}</span>
      </button>`;
    }).join('');
    grid.querySelectorAll('[data-lang]').forEach(btn =>
      btn.addEventListener('click', () => elegirIdioma(btn.dataset.lang)));
  }

  /* ---------- Traducción ---------- */
  function traducir(code) {
    const t = I18N[code];
    if (!t) return;
    document.documentElement.lang = code;
    document.title = t.docTitle;

    document.querySelectorAll('[data-t]').forEach(el => {
      const k = el.getAttribute('data-t');
      if (t[k] != null) el.textContent = t[k];
    });
    document.querySelectorAll('[data-t-ph]').forEach(el => {
      const k = el.getAttribute('data-t-ph');
      if (t[k] != null) el.setAttribute('placeholder', t[k]);
    });
    document.querySelectorAll('[data-t-req]').forEach(el => {
      const k = el.getAttribute('data-t-req');
      if (t[k] != null) el.innerHTML = t[k] + ' <span class="req" title="' + t.required + '">*</span>';
    });
    document.querySelectorAll('[data-t-opt]').forEach(el => {
      const k = el.getAttribute('data-t-opt');
      if (t[k] != null) el.innerHTML = t[k] + ' <span class="opt">(' + t.optional + ')</span>';
    });

    // Pregunta 1: opciones del desplegable
    const q1 = $('[data-q1]');
    if (q1) {
      q1.innerHTML = '<option value="" selected disabled>' + t.selectOption + '</option>'
        + t.q1opts.map(o => `<option value="${o}">${o}</option>`).join('');
    }

    // Escalas NPS: etiquetas bajo/alto
    const lo = $('[data-nps-low]'), hi = $('[data-nps-high]');
    if (lo) lo.textContent = t.q5low;
    if (hi) hi.textContent = t.q5high;

    // Privacidad
    const priv = $('[data-privacy]');
    if (priv) {
      const link = '<a href="privacidad.html" target="_blank" rel="noopener">' + t.privacyLink + '</a>';
      priv.innerHTML = t.privacySuffix ? t.privacy + ' ' + link + ' ' + t.privacySuffix + '.' : t.privacy + ' ' + link + '.';
    }

    // Pistas de estrellas
    document.querySelectorAll('[data-star-hint]').forEach(el => el.textContent = t.starHint);
  }

  /* ---------- Estrellas ---------- */
  function initStars() {
    document.querySelectorAll('[data-stars]').forEach(group => {
      const key = group.getAttribute('data-stars');
      const svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
      let html = '';
      for (let i = 1; i <= 5; i++) html += `<button type="button" data-val="${i}" aria-label="${i}">${svg}</button>`;
      group.innerHTML = html;
      group.querySelectorAll('button').forEach(b => {
        b.addEventListener('click', () => {
          ratings[key] = parseInt(b.dataset.val, 10);
          pintarEstrellas(group, ratings[key]);
        });
        b.addEventListener('mouseenter', () => pintarEstrellas(group, parseInt(b.dataset.val, 10)));
      });
      group.addEventListener('mouseleave', () => pintarEstrellas(group, ratings[key]));
    });
  }
  function pintarEstrellas(group, n) {
    group.querySelectorAll('button').forEach(b => {
      b.classList.toggle('is-on', parseInt(b.dataset.val, 10) <= n);
    });
  }

  /* ---------- NPS ---------- */
  function initNps() {
    const nps = $('[data-nps]');
    if (!nps) return;
    let html = '';
    for (let i = 0; i <= 10; i++) html += `<button type="button" data-val="${i}">${i}</button>`;
    nps.innerHTML = html;
    nps.querySelectorAll('button').forEach(b => {
      b.addEventListener('click', () => {
        ratings.nps = parseInt(b.dataset.val, 10);
        nps.querySelectorAll('button').forEach(x => x.classList.toggle('is-on', x === b));
      });
    });
  }

  /* ---------- Idioma → formulario ---------- */
  function elegirIdioma(code) {
    lang = code;
    traducir(code);
    landing.classList.add('is-leaving');
    setTimeout(() => { landing.style.display = 'none'; formWrap.classList.add('is-active'); window.scrollTo(0,0); }, 480);
  }
  function volver() {
    formWrap.classList.remove('is-active');
    landing.style.display = 'flex';
    landing.classList.remove('is-leaving');
  }

  function setStatus(msg, isError) {
    const el = $('[data-survey-status]');
    if (!el) return;
    el.textContent = msg; el.classList.toggle('is-error', !!isError);
  }

  /* ---------- Envío ---------- */
  function initForm() {
    const form = $('[data-survey-form]');
    const submitBtn = $('[data-survey-submit]');
    if (!form) return;

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const t = I18N[lang];

      // Obligatorias: exp, calidad, nps
      if (ratings.exp === 0 || ratings.calidad === 0 || ratings.nps < 0) {
        setStatus(t.errRequired, true);
        return;
      }
      if (!form.checkValidity()) { form.reportValidity(); return; }

      const original = submitBtn.textContent;
      submitBtn.disabled = true; submitBtn.textContent = t.sending;
      setStatus('', false);

      const fd = new FormData(form);
      fd.set('idioma', lang);
      fd.set('idiomaLabel', t.label);
      fd.set('expCompra', String(ratings.exp));
      fd.set('calidadProducto', String(ratings.calidad));
      fd.set('nps', String(ratings.nps));
      const q1 = $('[data-q1]');
      fd.set('comoConociste', q1 ? q1.value : '');

      try {
        const resp = await fetch(form.getAttribute('action'), { method:'POST', body: fd, headers:{'Accept':'application/json'} });
        let r = {}; try { r = await resp.json(); } catch(_) {}
        if (!resp.ok || r.ok === false) throw new Error(r.message || 'error');
        // Preparar modal con enlace de Trustpilot
        const tp = $('[data-tp-btn]');
        if (tp) tp.setAttribute('href', TRUSTPILOT_URL);
        $('[data-survey-modal]').removeAttribute('hidden');
      } catch (err) {
        setStatus(t.errSend, true);
      } finally {
        submitBtn.textContent = original; submitBtn.disabled = false;
      }
    });
  }

  /* ---------- Arranque ---------- */
  document.addEventListener('DOMContentLoaded', () => {
    pintarSelector();
    traducir('es');
    initStars();
    initNps();
    initForm();

    const back = $('[data-change-lang]');
    if (back) back.addEventListener('click', volver);

    const modal = $('[data-survey-modal]');
    const close = $('[data-survey-modal-close]');
    if (close) close.addEventListener('click', () => location.reload());
    if (modal) modal.addEventListener('click', e => { if (e.target === modal) location.reload(); });

    const y = $('[data-year]');
    if (y) y.textContent = new Date().getFullYear();
  });
})();
