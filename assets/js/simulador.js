/* ============================================
   Ligna Milano — Solicitud de diseño personalizado
   El cliente sube su foto, elige producto y efecto,
   añade instrucciones y pide presupuesto.
   El equipo procesa la imagen y monta el producto real.
   Sin filtros de navegador: la foto se envía tal cual.
   ============================================ */
(() => {
  'use strict';

  const MAX_BYTES = 10 * 1024 * 1024;        // 10 MB
  const TIPOS = ['image/png', 'image/jpeg'];  // solo PNG y JPG

  const $ = sel => document.querySelector(sel);

  const dropzone   = $('[data-dropzone]');
  const fileInput  = $('[data-file]');
  const preview    = $('[data-preview]');
  const previewImg = $('[data-preview-img]');
  const placeholder= $('[data-preview-placeholder]');
  const fileName   = $('[data-file-name]');
  const productSel = $('[data-product]');
  const styleSel   = $('[data-style]');
  const notesEl    = $('[data-notes]');
  const notesCount = $('[data-notes-count]');
  const statusEl   = $('[data-sim-status]');
  const form       = $('[data-sim-form]');
  const submitBtn  = $('[data-sim-submit]');
  const hiddenImg  = $('[data-orig-image]');
  const modal      = $('[data-sim-modal]');

  if (!dropzone || !form) return;

  let originalDataUrl = '';

  /* ---------- Carga y validación ---------- */
  function validar(file) {
    if (!file) return 'No se ha seleccionado ninguna imagen.';
    if (!TIPOS.includes(file.type)) return 'Formato no válido. Sube un archivo PNG o JPG.';
    if (file.size > MAX_BYTES) return 'La imagen supera los 10 MB. Prueba con una más ligera.';
    return '';
  }

  function cargar(file) {
    const err = validar(file);
    if (err) { setStatus(err, true); return; }
    const reader = new FileReader();
    reader.onload = e => {
      originalDataUrl = e.target.result;
      if (hiddenImg) hiddenImg.value = originalDataUrl;
      if (previewImg) previewImg.src = originalDataUrl;
      if (preview) preview.hidden = false;
      if (placeholder) placeholder.style.display = 'none';
      if (fileName) fileName.textContent = file.name;
      dropzone.classList.add('has-image');
      setStatus('Imagen cargada. Elige producto y, si quieres, un efecto.', false);
      actualizarBoton();
    };
    reader.onerror = () => setStatus('No se pudo leer la imagen.', true);
    reader.readAsDataURL(file);
  }

  function setStatus(msg, isError) {
    if (!statusEl) return;
    statusEl.textContent = msg;
    statusEl.classList.toggle('is-error', !!isError);
  }

  /* ---------- Eventos de carga ---------- */
  dropzone.addEventListener('click', () => fileInput.click());
  dropzone.addEventListener('keydown', e => { if (e.key==='Enter'||e.key===' ') { e.preventDefault(); fileInput.click(); } });
  fileInput.addEventListener('change', e => { if (e.target.files[0]) cargar(e.target.files[0]); });

  ['dragover','dragenter'].forEach(ev => dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.add('is-drag'); }));
  ['dragleave','drop'].forEach(ev => dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.remove('is-drag'); }));
  dropzone.addEventListener('drop', e => { const f = e.dataTransfer.files[0]; if (f) cargar(f); });

  productSel.addEventListener('change', actualizarBoton);

  /* ---------- Efecto "Otro" resalta el campo de notas ---------- */
  if (styleSel) {
    styleSel.addEventListener('change', () => {
      if (styleSel.value === 'otro' && notesEl) {
        notesEl.focus();
        setStatus('Describe el efecto que quieres en el campo de instrucciones.', false);
      }
    });
  }

  /* ---------- Contador del textarea ---------- */
  if (notesEl && notesCount) {
    const actualizarContador = () => {
      const n = notesEl.value.length;
      notesCount.textContent = n + ' / 2000';
    };
    notesEl.addEventListener('input', actualizarContador);
    actualizarContador();
  }

  function actualizarBoton() {
    const listo = originalDataUrl && productSel.value;
    if (submitBtn) submitBtn.disabled = !listo;
  }

  /* ---------- Envío ---------- */
  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (!originalDataUrl) { setStatus('Primero sube una imagen.', true); return; }
    if (!productSel.value) { setStatus('Elige un producto.', true); return; }
    if (!form.checkValidity()) { form.reportValidity(); return; }

    const original = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Enviando...';

    const fd = new FormData(form);
    fd.set('productLabel', productSel.options[productSel.selectedIndex]?.textContent || productSel.value);
    fd.set('styleLabel', styleSel.options[styleSel.selectedIndex]?.textContent || 'Ninguno');
    fd.set('notas', notesEl ? notesEl.value : '');

    try {
      const resp = await fetch(form.getAttribute('action'), { method:'POST', body: fd, headers:{'Accept':'application/json'} });
      let r = {}; try { r = await resp.json(); } catch(_) {}
      if (!resp.ok || r.ok === false) throw new Error(r.message || 'No se pudo enviar.');
      if (modal) modal.removeAttribute('hidden');
      form.reset();
      originalDataUrl = '';
      if (hiddenImg) hiddenImg.value = '';
      if (preview) preview.hidden = true;
      if (placeholder) placeholder.style.display = 'flex';
      if (fileName) fileName.textContent = '';
      dropzone.classList.remove('has-image');
      if (notesCount) notesCount.textContent = '0 / 2000';
      actualizarBoton();
      setStatus('Solicitud enviada. Te responderemos con el presupuesto.', false);
    } catch (err) {
      setStatus('No se pudo enviar la solicitud. Escríbenos por WhatsApp y te ayudamos.', true);
    } finally {
      submitBtn.textContent = original;
      actualizarBoton();
    }
  });

  const modalClose = $('[data-sim-modal-close]');
  if (modalClose) modalClose.addEventListener('click', () => modal.setAttribute('hidden',''));
  if (modal) modal.addEventListener('click', e => { if (e.target === modal) modal.setAttribute('hidden',''); });
})();
