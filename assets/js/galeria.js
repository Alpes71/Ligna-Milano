/* ============================================
   Ligna Milano — Visor de la galería de piezas
   Sin dependencias externas.
   ============================================ */
(() => {
  'use strict';

  const disparadores = [...document.querySelectorAll('[data-piece]')];
  if (!disparadores.length) return;

  let visor = null;
  let imagenes = [];
  let indice = 0;
  let titulo = '';
  let ultimoFoco = null;

  const construir = () => {
    const nodo = document.createElement('div');
    nodo.className = 'lightbox';
    nodo.setAttribute('role', 'dialog');
    nodo.setAttribute('aria-modal', 'true');
    nodo.setAttribute('aria-label', 'Detalle de la pieza');
    nodo.hidden = true;
    nodo.innerHTML = `
      <div class="lightbox-stage">
        <button type="button" class="lb-close" aria-label="Cerrar">&times;</button>
        <button type="button" class="lb-prev" aria-label="Anterior">&#8249;</button>
        <img alt="">
        <button type="button" class="lb-next" aria-label="Siguiente">&#8250;</button>
        <p class="lightbox-caption"><strong></strong><span></span></p>
      </div>
    `;
    document.body.appendChild(nodo);

    nodo.querySelector('.lb-close').addEventListener('click', cerrar);
    nodo.querySelector('.lb-prev').addEventListener('click', () => mover(-1));
    nodo.querySelector('.lb-next').addEventListener('click', () => mover(1));
    nodo.addEventListener('click', e => { if (e.target === nodo) cerrar(); });
    return nodo;
  };

  const pintar = () => {
    const img = visor.querySelector('img');
    const foto = imagenes[indice];
    img.src = foto.src;
    img.alt = foto.alt;
    visor.querySelector('.lightbox-caption strong').textContent = titulo;
    visor.querySelector('.lightbox-caption span').textContent =
      imagenes.length > 1 ? `Imagen ${indice + 1} de ${imagenes.length}` : '';
    const varias = imagenes.length > 1;
    visor.querySelector('.lb-prev').hidden = !varias;
    visor.querySelector('.lb-next').hidden = !varias;
  };

  const mover = paso => {
    indice = (indice + paso + imagenes.length) % imagenes.length;
    pintar();
  };

  const abrir = boton => {
    ultimoFoco = boton;
    titulo = boton.dataset.pieceTitle || '';
    imagenes = JSON.parse(boton.dataset.piece);
    indice = 0;
    if (!visor) visor = construir();
    pintar();
    visor.hidden = false;
    document.body.style.overflow = 'hidden';
    visor.querySelector('.lb-close').focus();
  };

  const cerrar = () => {
    if (!visor) return;
    visor.hidden = true;
    document.body.style.overflow = '';
    ultimoFoco?.focus();
  };

  disparadores.forEach(boton => boton.addEventListener('click', () => abrir(boton)));

  document.addEventListener('keydown', e => {
    if (!visor || visor.hidden) return;
    if (e.key === 'Escape') cerrar();
    if (e.key === 'ArrowLeft' && imagenes.length > 1) mover(-1);
    if (e.key === 'ArrowRight' && imagenes.length > 1) mover(1);
  });
})();
