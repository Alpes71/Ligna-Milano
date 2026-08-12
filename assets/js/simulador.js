/* ============================================
   Ligna Milano — Simulador de producto
   Todo en el navegador. Sin llamadas externas.
   El estilo se aplica con filtros de canvas (aproximación),
   nunca con IA ni con nombres de marca.
   ============================================ */
(() => {
  'use strict';

  const MAX_BYTES = 5 * 1024 * 1024;       // 5 MB
  const TIPOS = ['image/png', 'image/jpeg']; // solo PNG y JPG
  const LADO = 1000;                          // lienzo de trabajo

  const $ = sel => document.querySelector(sel);

  const dropzone   = $('[data-dropzone]');
  const fileInput  = $('[data-file]');
  const productSel = $('[data-product]');
  const styleSel   = $('[data-style]');
  const canvas     = $('[data-canvas]');
  const placeholder= $('[data-canvas-placeholder]');
  const statusEl   = $('[data-sim-status]');
  const form       = $('[data-sim-form]');
  const submitBtn  = $('[data-sim-submit]');
  const hiddenSim  = $('[data-sim-image]');
  const hiddenOrig = $('[data-orig-image]');
  const modal      = $('[data-sim-modal]');
  const ideaNote   = $('[data-idea-note]');

  if (!dropzone || !canvas) return;
  const ctx = canvas.getContext('2d');
  canvas.width = LADO;
  canvas.height = LADO;

  let sourceImage = null;   // Image() con la foto original
  let originalDataUrl = ''; // JPEG comprimido de la foto original (para el email)

  /* ---------- Estilos: cada uno es una función sobre ImageData ---------- */
  const clamp = v => v < 0 ? 0 : v > 255 ? 255 : v;

  const estilos = {
    neutro: null, // sin cambios

    grises: d => { for (let i=0;i<d.length;i+=4){ const g=0.299*d[i]+0.587*d[i+1]+0.114*d[i+2]; d[i]=d[i+1]=d[i+2]=g; } },

    sepia: d => { for (let i=0;i<d.length;i+=4){ const r=d[i],g=d[i+1],b=d[i+2];
      d[i]=clamp(0.393*r+0.769*g+0.189*b); d[i+1]=clamp(0.349*r+0.686*g+0.168*b); d[i+2]=clamp(0.272*r+0.534*g+0.131*b); } },

    contraste: d => { const f=1.35; for (let i=0;i<d.length;i+=4){ for(let k=0;k<3;k++) d[i+k]=clamp((d[i+k]-128)*f+128); } },

    vibrante: d => { for (let i=0;i<d.length;i+=4){ const r=d[i],g=d[i+1],b=d[i+2]; const mx=Math.max(r,g,b),avg=(r+g+b)/3;
      const amt=0.55; d[i]=clamp(r+(r-avg)*amt+(r===mx?8:0)); d[i+1]=clamp(g+(g-avg)*amt); d[i+2]=clamp(b+(b-avg)*amt); } },

    calido: d => { for (let i=0;i<d.length;i+=4){ d[i]=clamp(d[i]+22); d[i+1]=clamp(d[i+1]+8); d[i+2]=clamp(d[i+2]-16); } },

    frio: d => { for (let i=0;i<d.length;i+=4){ d[i]=clamp(d[i]-16); d[i+1]=clamp(d[i+1]+4); d[i+2]=clamp(d[i+2]+24); } },

    desaturado: d => { for (let i=0;i<d.length;i+=4){ const g=0.299*d[i]+0.587*d[i+1]+0.114*d[i+2];
      d[i]=clamp(d[i]*0.45+g*0.55); d[i+1]=clamp(d[i+1]*0.45+g*0.55); d[i+2]=clamp(d[i+2]*0.45+g*0.55); } },

    umbral: d => { for (let i=0;i<d.length;i+=4){ const g=0.299*d[i]+0.587*d[i+1]+0.114*d[i+2]; const v=g>128?255:0; d[i]=d[i+1]=d[i+2]=v; } },

    posterizar: d => { const n=5, step=255/(n-1); for (let i=0;i<d.length;i+=4){ for(let k=0;k<3;k++) d[i+k]=clamp(Math.round(d[i+k]/step)*step); } },

    posterizarN: (d, n) => { const step=255/(n-1); for (let i=0;i<d.length;i+=4){ for(let k=0;k<3;k++) d[i+k]=clamp(Math.round(d[i+k]/step)*step); } },

    duotono: d => { for (let i=0;i<d.length;i+=4){ const g=(0.299*d[i]+0.587*d[i+1]+0.114*d[i+2])/255;
      d[i]=clamp(8+g*(231-8)); d[i+1]=clamp(8+g*(216-8)); d[i+2]=clamp(7+g*(196-7)); } }, // negro carbón -> champán

    cobre: d => { for (let i=0;i<d.length;i+=4){ const g=(0.299*d[i]+0.587*d[i+1]+0.114*d[i+2])/255;
      d[i]=clamp(20+g*(184-20)); d[i+1]=clamp(14+g*(115-14)); d[i+2]=clamp(10+g*(51-10)); } } // viraje a Copper Gold
  };

  // Estilos que además pintan con "pincelada" (convolución de realce de bordes suave sobre color)
  const realces = {
    oleo: true, acuarela: true, comic: true, boceto: true, grabado: true
  };

  /* ---------- Utilidades de dibujo ---------- */
  function coverDraw(img, w, h) {
    // Escala tipo object-fit: cover sobre un canvas w×h
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    const cc = c.getContext('2d');
    const ir = img.width / img.height, cr = w / h;
    let dw, dh, dx, dy;
    if (ir > cr) { dh = h; dw = h * ir; dx = (w - dw) / 2; dy = 0; }
    else { dw = w; dh = w / ir; dx = 0; dy = (h - dh) / 2; }
    cc.drawImage(img, dx, dy, dw, dh);
    return c;
  }

  function aplicarEstilo(baseCanvas, estilo) {
    const c = document.createElement('canvas');
    c.width = baseCanvas.width; c.height = baseCanvas.height;
    const cc = c.getContext('2d');
    cc.drawImage(baseCanvas, 0, 0);

    if (estilo === 'neutro' || !estilo) return c;

    // Realces con "pincelada": ligero desenfoque + subida de saturación/contraste antes del viraje
    if (realces[estilo]) {
      cc.filter = estilo === 'boceto'
        ? 'grayscale(1) contrast(1.2) brightness(1.05)'
        : estilo === 'grabado'
          ? 'grayscale(1) contrast(1.5)'
          : estilo === 'acuarela'
            ? 'saturate(1.4) contrast(0.9) blur(2px) brightness(1.08)'
            : estilo === 'comic'
              ? 'saturate(1.7) contrast(1.6)'
              : 'saturate(1.3) contrast(1.2) blur(1.4px)'; // oleo
      cc.drawImage(baseCanvas, 0, 0);
      cc.filter = 'none';

      const id = cc.getImageData(0, 0, c.width, c.height);
      if (estilo === 'oleo') brushDabs(cc, c.width, c.height);
      if (estilo === 'acuarela') { estilos.posterizarN(id.data, 7); paperBleed(id.data, c.width, c.height); cc.putImageData(id,0,0); }
      if (estilo === 'comic') { estilos.posterizarN(id.data, 6); cc.putImageData(id,0,0); inkEdges(cc, c.width, c.height); }
      if (estilo === 'boceto') { edgeSketch(id, c.width, c.height); cc.putImageData(id,0,0); }
      if (estilo === 'grabado') { estilos.posterizarN(id.data, 4); estilos.duotono(id.data); cc.putImageData(id,0,0); }
      return c;
    }

    // Estilos de color simples
    const fn = estilos[estilo];
    if (fn) {
      const id = cc.getImageData(0, 0, c.width, c.height);
      fn(id.data);
      cc.putImageData(id, 0, 0);
    }
    return c;
  }

  function brushDabs(cc, w, h) {
    // Pinceladas cortas siguiendo el color existente, para dar textura de óleo
    const src = cc.getImageData(0,0,w,h).data;
    cc.save();
    cc.globalAlpha = 0.5;
    const paso = 13;
    for (let y=paso; y<h; y+=paso) {
      for (let x=paso; x<w; x+=paso) {
        const o=(y*w+x)*4;
        const jx = x + (Math.random()*paso-paso/2);
        const jy = y + (Math.random()*paso-paso/2);
        const ang = Math.atan2((src[o-w*4+1]||0)-(src[o+1]||0), 1) + (Math.random()*0.6-0.3);
        cc.fillStyle = `rgb(${src[o]},${src[o+1]},${src[o+2]})`;
        cc.save();
        cc.translate(jx, jy); cc.rotate(ang);
        cc.fillRect(-paso*0.7, -paso*0.22, paso*1.4, paso*0.44);
        cc.restore();
      }
    }
    cc.restore();
  }

  function paperBleed(d, w, h) {
    // Suaviza y aclara los bordes internos, como pigmento sobre papel húmedo
    for (let i=0;i<d.length;i+=4){ d[i]=clamp(d[i]*0.92+18); d[i+1]=clamp(d[i+1]*0.92+18); d[i+2]=clamp(d[i+2]*0.92+18); }
  }

  function inkEdges(cc, w, h) {
    // Traza líneas oscuras en los bordes fuertes, tipo cómic
    const src = cc.getImageData(0,0,w,h);
    const g = new Float32Array(w*h);
    const s = src.data;
    for (let i=0,p=0;i<s.length;i+=4,p++) g[p]=0.299*s[i]+0.587*s[i+1]+0.114*s[i+2];
    cc.save(); cc.strokeStyle='rgba(10,8,7,.85)'; cc.lineWidth=1.4; cc.beginPath();
    for (let y=1;y<h-1;y++) for (let x=1;x<w-1;x++){
      const p=y*w+x;
      const mag=Math.abs(g[p-1]-g[p+1])+Math.abs(g[p-w]-g[p+w]);
      if (mag>42){ cc.moveTo(x,y); cc.lineTo(x+1,y+1); }
    }
    cc.stroke(); cc.restore();
  }

  function edgeSketch(imageData, w, h) {
    // Realza bordes e invierte para simular lápiz sobre papel
    const src = imageData.data;
    const gray = new Float32Array(w * h);
    for (let i = 0, p = 0; i < src.length; i += 4, p++) gray[p] = 0.299*src[i]+0.587*src[i+1]+0.114*src[i+2];
    const out = imageData.data;
    for (let y = 1; y < h-1; y++) {
      for (let x = 1; x < w-1; x++) {
        const p = y*w+x;
        const gx = gray[p-1] - gray[p+1];
        const gy = gray[p-w] - gray[p+w];
        const mag = Math.sqrt(gx*gx + gy*gy);
        const v = clamp(255 - mag*1.6);
        const o = p*4; out[o]=out[o+1]=out[o+2]=v;
      }
    }
  }

  /* ---------- Productos: cómo se “monta” la foto sobre el objeto ---------- */
  // forma: cómo se recorta la imagen. marco: color del borde/objeto. etiqueta.
  const productos = {
    'llavero-cuero':      { forma:'rect',   ratio:0.62, etiqueta:'Llavero de cuero' },
    'llavero-metal':      { forma:'circ',   ratio:1,    etiqueta:'Llavero de metal' },
    'llavero-madera':     { forma:'rect',   ratio:0.62, etiqueta:'Llavero de madera' },
    'moneda':             { forma:'circ',   ratio:1,    etiqueta:'Moneda 4 cm Ø' },
    'lienzo-rect':        { forma:'rect',   ratio:1.4,  etiqueta:'Lienzo rectangular' },
    'lienzo-cuadrado':    { forma:'rect',   ratio:1,    etiqueta:'Lienzo cuadrado' },
    'lienzo-redondo':     { forma:'circ',   ratio:1,    etiqueta:'Lienzo redondo' },
    'porta-10x15':        { forma:'rect',   ratio:0.67, etiqueta:'Portarretrato 10×15' },
    'porta-15x20':        { forma:'rect',   ratio:0.75, etiqueta:'Portarretrato 15×20' },
    'porta-20x25':        { forma:'rect',   ratio:0.8,  etiqueta:'Portarretrato 20×25' },
    'piedra-amorfa':      { forma:'blob',   ratio:1.15, etiqueta:'Piedra amorfa' },
    'piedra-rect':        { forma:'rect',   ratio:1.3,  etiqueta:'Piedra rectangular' },
    'piedra-redonda':     { forma:'circ',   ratio:1,    etiqueta:'Piedra redonda' },
    'piedra-cuadrada':    { forma:'rect',   ratio:1,    etiqueta:'Piedra cuadrada' },
    'acrilico':           { forma:'rect',   ratio:1.25, etiqueta:'Acrílico' },
    'caja-metalica':      { forma:'rect',   ratio:1.3,  etiqueta:'Caja metálica (tapa)' },
    'taza':               { forma:'mug',    ratio:1.2,  etiqueta:'Taza de té o café' },
    'vaso-whisky':        { forma:'glass',  ratio:0.9,  etiqueta:'Vaso de whisky' },
    'vaso-largo':         { forma:'glass',  ratio:0.5,  etiqueta:'Vaso de trago largo' },
    'botella-termica':    { forma:'bottle', ratio:0.4,  etiqueta:'Botella térmica ≤0,5 L' },
    'jarra-termica':      { forma:'mug',    ratio:1.1,  etiqueta:'Jarra térmica ≤0,5 L' },
    'ceramico-rect':      { forma:'rect',   ratio:1.33, etiqueta:'Cerámico rectangular ≤30×40' },
    'ceramico-cuadrado':  { forma:'rect',   ratio:1,    etiqueta:'Cerámico cuadrado ≤30×30' },
    'ceramico-redondo':   { forma:'circ',   ratio:1,    etiqueta:'Cerámico redondo ≤30 Ø' },
    'azulejo':            { forma:'rect',   ratio:1,    etiqueta:'Azulejo' },
    'posavasos':          { forma:'rect',   ratio:1,    etiqueta:'Posavasos' },
    'iman':               { forma:'rect',   ratio:1.35, etiqueta:'Imán' },
    'marcapaginas':       { forma:'rect',   ratio:0.32, etiqueta:'Marcapáginas' },
    'placa':              { forma:'rect',   ratio:1.5,  etiqueta:'Placa / plaquita' },
    'funda-movil':        { forma:'rect',   ratio:0.48, etiqueta:'Funda de móvil rígida' },
    'adorno-navideno':    { forma:'circ',   ratio:1,    etiqueta:'Adorno navideño plano' },
    'bandeja':            { forma:'rect',   ratio:1.4,  etiqueta:'Bandeja pequeña' }
  };

  function blobPath(ctx2, w, h) {
    // Silueta orgánica tipo piedra
    ctx2.beginPath();
    const cx=w/2, cy=h/2, rx=w*0.46, ry=h*0.44;
    const pts=8;
    for (let i=0;i<=pts;i++){
      const a=(i/pts)*Math.PI*2;
      const wob=0.86+0.14*Math.sin(a*3+1.3);
      const x=cx+Math.cos(a)*rx*wob, y=cy+Math.sin(a)*ry*wob;
      i?ctx2.lineTo(x,y):ctx2.moveTo(x,y);
    }
    ctx2.closePath();
  }

  function render() {
    if (!sourceImage) return;
    const prodKey = productSel.value;
    let styleKey = styleSel.value;
    const prod = productos[prodKey];

    // Estilos "idea" (grupo B): no se pueden aplicar con filtros; se muestra la foto tal cual + aviso
    const esIdea = styleKey.startsWith('idea-');
    if (ideaNote) {
      if (esIdea) {
        const nombre = styleSel.options[styleSel.selectedIndex]?.textContent || 'Ese estilo';
        const span = ideaNote.querySelector('[data-idea-name]');
        if (span) span.textContent = nombre;
        ideaNote.hidden = false;
      } else {
        ideaNote.hidden = true;
      }
    }
    if (esIdea) styleKey = 'neutro'; // la previsualización muestra la foto sin filtrar

    ctx.clearRect(0,0,LADO,LADO);
    // Fondo del escenario
    ctx.fillStyle = '#0d0b09';
    ctx.fillRect(0,0,LADO,LADO);

    if (!prod) { showPlaceholder(true); return; }
    showPlaceholder(false);

    // Área útil del objeto dentro del lienzo
    const margin = 150;
    const availW = LADO - margin*2, availH = LADO - margin*2;
    let w = availW, h = availW / prod.ratio;
    if (h > availH) { h = availH; w = availH * prod.ratio; }
    const x = (LADO - w)/2, y = (LADO - h)/2;

    // Imagen ya estilizada, recortada a "cover" del área
    const cover = coverDraw(sourceImage, Math.round(w), Math.round(h));
    const estilizada = aplicarEstilo(cover, styleKey);

    ctx.save();
    // Sombra del objeto
    ctx.shadowColor = 'rgba(0,0,0,.55)';
    ctx.shadowBlur = 42; ctx.shadowOffsetY = 26;

    if (prod.forma === 'circ') {
      ctx.beginPath();
      ctx.arc(LADO/2, LADO/2, Math.min(w,h)/2, 0, Math.PI*2);
      ctx.closePath();
      drawClipped(estilizada, x, y, w, h);
      strokeShape('#b87333', 6);
    } else if (prod.forma === 'blob') {
      blobPath(ctx, LADO, LADO);
      drawClipped(estilizada, x, y, w, h);
      strokeShape('rgba(155,136,116,.9)', 5);
    } else if (prod.forma === 'mug' || prod.forma === 'glass' || prod.forma === 'bottle') {
      drawVessel(prod.forma, estilizada, x, y, w, h);
    } else {
      // rect con esquinas suaves
      roundRect(x, y, w, h, 26);
      drawClipped(estilizada, x, y, w, h);
      strokeShape('rgba(247,240,230,.14)', 4);
    }
    ctx.restore();

    exportarSimulacion();
  }

  function drawClipped(srcCanvas, x, y, w, h) {
    ctx.save();
    ctx.clip();
    ctx.shadowColor='transparent';
    ctx.drawImage(srcCanvas, x, y, w, h);
    // brillo sutil de acabado
    const g = ctx.createLinearGradient(x, y, x+w, y+h);
    g.addColorStop(0, 'rgba(255,255,255,.10)');
    g.addColorStop(.5, 'rgba(255,255,255,0)');
    g.addColorStop(1, 'rgba(0,0,0,.12)');
    ctx.fillStyle = g;
    ctx.fillRect(x, y, w, h);
    ctx.restore();
  }

  function drawVessel(tipo, srcCanvas, x, y, w, h) {
    // Cuerpo curvo simplificado: la imagen se envuelve en un rectángulo con laterales sombreados
    roundRect(x, y, w, h, tipo==='glass'?14:20);
    ctx.save(); ctx.clip(); ctx.shadowColor='transparent';
    ctx.drawImage(srcCanvas, x, y, w, h);
    // curvatura: sombras laterales
    const gl = ctx.createLinearGradient(x, 0, x+w, 0);
    gl.addColorStop(0,'rgba(0,0,0,.38)'); gl.addColorStop(.18,'rgba(0,0,0,0)');
    gl.addColorStop(.82,'rgba(0,0,0,0)'); gl.addColorStop(1,'rgba(0,0,0,.38)');
    ctx.fillStyle=gl; ctx.fillRect(x,y,w,h);
    // reflejo vertical
    const gr = ctx.createLinearGradient(x,0,x+w,0);
    gr.addColorStop(.30,'rgba(255,255,255,0)'); gr.addColorStop(.40,'rgba(255,255,255,.22)');
    gr.addColorStop(.46,'rgba(255,255,255,0)');
    ctx.fillStyle=gr; ctx.fillRect(x,y,w,h);
    ctx.restore();
    strokeShape('rgba(247,240,230,.18)', 4);
    // asa para taza/jarra
    if (tipo==='mug') {
      ctx.save();
      ctx.strokeStyle='rgba(247,240,230,.5)'; ctx.lineWidth=18;
      ctx.beginPath();
      ctx.arc(x+w+6, y+h/2, h*0.26, -Math.PI/2.2, Math.PI/2.2);
      ctx.stroke(); ctx.restore();
    }
  }

  function roundRect(x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x+r,y);
    ctx.arcTo(x+w,y,x+w,y+h,r);
    ctx.arcTo(x+w,y+h,x,y+h,r);
    ctx.arcTo(x,y+h,x,y,r);
    ctx.arcTo(x,y,x+w,y,r);
    ctx.closePath();
  }

  function strokeShape(color, width) {
    ctx.save();
    ctx.shadowColor='transparent';
    ctx.strokeStyle=color; ctx.lineWidth=width; ctx.stroke();
    ctx.restore();
  }

  function showPlaceholder(v) {
    if (placeholder) placeholder.style.display = v ? 'flex' : 'none';
    canvas.style.display = v ? 'none' : 'block';
  }

  function exportarSimulacion() {
    try {
      const url = canvas.toDataURL('image/jpeg', 0.82);
      if (hiddenSim) hiddenSim.value = url;
    } catch (_) {}
  }

  /* ---------- Carga de archivo ---------- */
  function validar(file) {
    if (!file) return 'No se ha seleccionado ninguna imagen.';
    if (!TIPOS.includes(file.type)) return 'Formato no válido. Sube un archivo PNG o JPG.';
    if (file.size > MAX_BYTES) return 'La imagen supera los 5 MB. Prueba con una más ligera.';
    return '';
  }

  function cargar(file) {
    const err = validar(file);
    if (err) { setStatus(err, true); return; }
    const reader = new FileReader();
    reader.onload = e => {
      const img = new Image();
      img.onload = () => {
        sourceImage = img;
        // Guardamos una versión comprimida de la original para el email
        const oc = coverDraw(img, 1000, Math.round(1000 * img.height / img.width));
        try { originalDataUrl = oc.toDataURL('image/jpeg', 0.82); } catch (_) { originalDataUrl = ''; }
        if (hiddenOrig) hiddenOrig.value = originalDataUrl;
        dropzone.classList.add('has-image');
        setStatus('Imagen cargada. Elige producto y estilo.', false);
        render();
        actualizarBoton();
      };
      img.onerror = () => setStatus('No se pudo leer la imagen.', true);
      img.src = e.target.result;
    };
    reader.onerror = () => setStatus('Error al leer el archivo.', true);
    reader.readAsDataURL(file);
  }

  function setStatus(msg, isError) {
    if (!statusEl) return;
    statusEl.textContent = msg;
    statusEl.classList.toggle('is-error', !!isError);
  }

  /* ---------- Eventos ---------- */
  dropzone.addEventListener('click', () => fileInput.click());
  dropzone.addEventListener('keydown', e => { if (e.key==='Enter'||e.key===' ') { e.preventDefault(); fileInput.click(); } });
  fileInput.addEventListener('change', e => { if (e.target.files[0]) cargar(e.target.files[0]); });

  ['dragover','dragenter'].forEach(ev => dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.add('is-drag'); }));
  ['dragleave','drop'].forEach(ev => dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.remove('is-drag'); }));
  dropzone.addEventListener('drop', e => { const f = e.dataTransfer.files[0]; if (f) cargar(f); });

  productSel.addEventListener('change', () => { render(); actualizarBoton(); });
  styleSel.addEventListener('change', render);

  function actualizarBoton() {
    const listo = sourceImage && productSel.value;
    if (submitBtn) submitBtn.disabled = !listo;
  }

  /* ---------- Envío ---------- */
  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (!sourceImage) { setStatus('Primero sube una imagen.', true); return; }
    if (!productSel.value) { setStatus('Elige un producto.', true); return; }
    if (!form.checkValidity()) { form.reportValidity(); return; }

    exportarSimulacion();
    const original = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Enviando...';

    const fd = new FormData(form);
    fd.set('productLabel', productos[productSel.value]?.etiqueta || productSel.value);
    fd.set('styleLabel', styleSel.options[styleSel.selectedIndex]?.textContent || 'Neutro');

    try {
      const resp = await fetch(form.getAttribute('action'), { method:'POST', body: fd, headers:{'Accept':'application/json'} });
      let r = {}; try { r = await resp.json(); } catch(_) {}
      if (!resp.ok || r.ok === false) throw new Error(r.message || 'No se pudo enviar.');
      if (modal) modal.removeAttribute('hidden');
      form.reset();
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

  // Evitar arrastrar/guardar la imagen del canvas con facilidad (solo visible)
  canvas.addEventListener('contextmenu', e => e.preventDefault());
})();
