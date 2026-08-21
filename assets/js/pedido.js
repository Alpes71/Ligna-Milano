/* ============================================
   Ligna Milano — Formulario de pedido
   Landing de idioma + formulario multiidioma + envío
   ============================================ */
(() => {
  'use strict';

  const I18N = window.LM_PEDIDO_I18N || {};
  const FLAGS = window.LM_FLAGS || {};
  const MAX_BYTES = 10 * 1024 * 1024;
  const TIPOS_FILE = ['image/png', 'image/jpeg', 'application/pdf'];

  const TIPOS_VIA = ['Calle','Avenida','Plaza','Paseo','Camino','Carretera','Travesía','Ronda',
    'Vía','Callejón','Pasaje','Rambla','Glorieta','Urbanización','Polígono','Bulevar','Cuesta',
    'Bajada','Subida','Rúa','Carrer','Avinguda','Plaça','Barrio','Sector','Parque'];
  const OTROS = { es:'Otros', ca:'Altres', va:'Altres', eu:'Bestelakoak', gl:'Outros',
    en:'Other', fr:'Autre', de:'Sonstige' };

  const $ = s => document.querySelector(s);
  const landing = $('[data-landing]');
  const formWrap = $('[data-form-wrap]');
  let lang = 'es';
  let receiptFile = null;

  /* ---------- Construir el selector de idioma ---------- */
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
    grid.querySelectorAll('[data-lang]').forEach(btn => {
      btn.addEventListener('click', () => elegirIdioma(btn.dataset.lang));
    });
  }

  /* ---------- Traducir todos los nodos [data-t] ---------- */
  function traducir(code) {
    const t = I18N[code];
    if (!t) return;
    document.documentElement.lang = code;
    document.title = t.docTitle;

    document.querySelectorAll('[data-t]').forEach(el => {
      const key = el.getAttribute('data-t');
      if (t[key] != null) el.textContent = t[key];
    });
    document.querySelectorAll('[data-t-ph]').forEach(el => {
      const key = el.getAttribute('data-t-ph');
      if (t[key] != null) el.setAttribute('placeholder', t[key]);
    });

    // Etiquetas con asterisco obligatorio / opcional
    document.querySelectorAll('[data-t-req]').forEach(el => {
      const key = el.getAttribute('data-t-req');
      if (t[key] != null) el.innerHTML = t[key] + ' <span class="req" title="' + t.required + '">*</span>';
    });
    document.querySelectorAll('[data-t-opt]').forEach(el => {
      const key = el.getAttribute('data-t-opt');
      if (t[key] != null) el.innerHTML = t[key] + ' <span class="opt">(' + t.optional + ')</span>';
    });

    // Privacidad (con enlace; alemán parte la frase)
    const priv = $('[data-privacy]');
    if (priv) {
      const link = '<a href="privacidad.html" target="_blank" rel="noopener">' + t.privacyLink + '</a>';
      priv.innerHTML = t.privacySuffix
        ? t.privacy + ' ' + link + ' ' + t.privacySuffix + '.'
        : t.privacy + ' ' + link + '.';
    }

    // Tipo de vía: opción vacía traducida + "Otros" traducido
    const via = $('[data-street-type]');
    if (via) {
      via.innerHTML = '<option value="" selected disabled>' + t.selectOption + '</option>'
        + TIPOS_VIA.map(v => `<option value="${v}">${v}</option>`).join('')
        + `<option value="${OTROS[code]}">${OTROS[code]}</option>`;
    }

    // Método de pago
    const pay = $('[data-payment]');
    if (pay) {
      pay.innerHTML = '<option value="" selected disabled>' + t.selectOption + '</option>'
        + `<option value="Bizum">${t.payBizum}</option>`
        + `<option value="Transferencia">${t.payTransfer}</option>`;
    }

    // País por defecto
    const country = $('[name="pais"]');
    if (country && !country.value) country.value = 'España';

    actualizarContadorArchivo();
  }

  /* ---------- Elegir idioma → mostrar formulario ---------- */
  function elegirIdioma(code) {
    lang = code;
    $('[name="idioma"]').value = code;
    traducir(code);
    landing.classList.add('is-leaving');
    setTimeout(() => {
      landing.style.display = 'none';
      formWrap.classList.add('is-active');
      window.scrollTo(0, 0);
    }, 480);
  }

  /* ---------- Volver a elegir idioma ---------- */
  function volver() {
    formWrap.classList.remove('is-active');
    landing.style.display = 'flex';
    landing.classList.remove('is-leaving');
  }

  /* ---------- Comprobante ---------- */
  function initReceipt() {
    const drop = $('[data-receipt-drop]');
    const input = $('[data-receipt-input]');
    if (!drop || !input) return;

    drop.addEventListener('click', () => input.click());
    drop.addEventListener('keydown', e => { if (e.key==='Enter'||e.key===' ') { e.preventDefault(); input.click(); } });
    input.addEventListener('change', e => { if (e.target.files[0]) setReceipt(e.target.files[0]); });
    ['dragover','dragenter'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('is-drag'); }));
    ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('is-drag'); }));
    drop.addEventListener('drop', e => { const f = e.dataTransfer.files[0]; if (f) setReceipt(f); });
  }

  function setReceipt(file) {
    const t = I18N[lang];
    if (!TIPOS_FILE.includes(file.type) || file.size > MAX_BYTES) {
      setStatus(t.errFile, true);
      return;
    }
    receiptFile = file;
    const drop = $('[data-receipt-drop]');
    drop.classList.add('has-file');
    actualizarContadorArchivo();
    setStatus('', false);
  }

  function actualizarContadorArchivo() {
    const el = $('[data-receipt-name]');
    if (el) el.textContent = receiptFile ? receiptFile.name : '';
  }

  function setStatus(msg, isError) {
    const el = $('[data-pedido-status]');
    if (!el) return;
    el.textContent = msg;
    el.classList.toggle('is-error', !!isError);
  }

  /* ---------- Leer archivo como base64 ---------- */
  function fileToDataUrl(file) {
    return new Promise((resolve, reject) => {
      const r = new FileReader();
      r.onload = () => resolve(r.result);
      r.onerror = reject;
      r.readAsDataURL(file);
    });
  }

  /* ---------- Envío ---------- */
  function initForm() {
    const form = $('[data-pedido-form]');
    const submitBtn = $('[data-pedido-submit]');
    if (!form) return;

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const t = I18N[lang];
      if (!form.checkValidity()) { form.reportValidity(); return; }

      const original = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = t.sending;
      setStatus('', false);

      const fd = new FormData(form);
      fd.set('idioma', lang);
      fd.set('idiomaLabel', t.label);
      // etiquetas legibles de los selects
      const via = $('[data-street-type]');
      const pay = $('[data-payment]');
      fd.set('tipoViaLabel', via.options[via.selectedIndex]?.textContent || '');
      fd.set('pagoLabel', pay.options[pay.selectedIndex]?.value || '');

      if (receiptFile) {
        try {
          fd.set('comprobante', await fileToDataUrl(receiptFile));
          fd.set('comprobanteNombre', receiptFile.name);
        } catch (_) {}
      }

      try {
        const resp = await fetch(form.getAttribute('action'), { method:'POST', body: fd, headers:{'Accept':'application/json'} });
        let r = {}; try { r = await resp.json(); } catch(_) {}
        if (!resp.ok || r.ok === false) throw new Error(r.message || 'error');
        $('[data-pedido-modal]').removeAttribute('hidden');
      } catch (err) {
        setStatus(t.errSend, true);
      } finally {
        submitBtn.textContent = original;
        submitBtn.disabled = false;
      }
    });
  }

  /* ---------- Arranque ---------- */
  document.addEventListener('DOMContentLoaded', () => {
    pintarSelector();
    traducir('es'); // pre-carga por si el CSS necesita medidas
    initReceipt();
    initForm();

    const back = $('[data-change-lang]');
    if (back) back.addEventListener('click', volver);

    const modalClose = $('[data-pedido-modal-close]');
    const modal = $('[data-pedido-modal]');
    if (modalClose) modalClose.addEventListener('click', () => location.reload());
    if (modal) modal.addEventListener('click', e => { if (e.target === modal) location.reload(); });

    const y = $('[data-year]');
    if (y) y.textContent = new Date().getFullYear();
  });
})();
