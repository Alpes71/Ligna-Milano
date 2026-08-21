/* ============================================
   Ligna Milano — Banderas para el selector
   Autonómicas dibujadas en SVG (no existen como emoji).
   Devuelve markup SVG por código de idioma.
   ============================================ */
window.LM_FLAGS = {
  // Castellano → España
  es: `<svg viewBox="0 0 60 40" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="España">
    <rect width="60" height="40" fill="#c60b1e"/>
    <rect y="10" width="60" height="20" fill="#ffc400"/>
  </svg>`,

  // Català → Senyera
  ca: `<svg viewBox="0 0 60 40" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Catalunya">
    <rect width="60" height="40" fill="#fcdd09"/>
    <g fill="#da121a">
      <rect y="4.44" width="60" height="4.44"/>
      <rect y="13.33" width="60" height="4.44"/>
      <rect y="22.22" width="60" height="4.44"/>
      <rect y="31.11" width="60" height="4.44"/>
    </g>
  </svg>`,

  // Valencià → Senyera coronada (franja azul con corona estilizada)
  va: `<svg viewBox="0 0 60 40" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Comunitat Valenciana">
    <rect width="60" height="40" fill="#fcdd09"/>
    <g fill="#da121a">
      <rect y="4.44" width="42" height="4.44"/>
      <rect y="13.33" width="42" height="4.44"/>
      <rect y="22.22" width="42" height="4.44"/>
      <rect y="31.11" width="42" height="4.44"/>
    </g>
    <rect x="42" width="18" height="40" fill="#0053a5"/>
    <g fill="#fcdd09" transform="translate(51 19)">
      <path d="M-5 -2 L-3 2 L-1 -2 L1 2 L3 -2 L5 2 L5 4 L-5 4 Z"/>
      <rect x="-6" y="5" width="12" height="2"/>
    </g>
  </svg>`,

  // Euskera → Ikurriña
  eu: `<svg viewBox="0 0 60 40" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Euskadi">
    <rect width="60" height="40" fill="#d52b1e"/>
    <path d="M0 0 L60 40 M60 0 L0 40" stroke="#009b48" stroke-width="6"/>
    <path d="M30 0 V40 M0 20 H60" stroke="#fff" stroke-width="6"/>
  </svg>`,

  // Galego → bandera de Galicia
  gl: `<svg viewBox="0 0 60 40" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Galicia">
    <rect width="60" height="40" fill="#fff"/>
    <path d="M8 4 L52 36" stroke="#0080c8" stroke-width="6"/>
  </svg>`,

  // English → Reino Unido (Union Jack simplificada)
  en: `<svg viewBox="0 0 60 40" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="United Kingdom">
    <rect width="60" height="40" fill="#012169"/>
    <path d="M0 0 L60 40 M60 0 L0 40" stroke="#fff" stroke-width="8"/>
    <path d="M0 0 L60 40 M60 0 L0 40" stroke="#c8102e" stroke-width="4"/>
    <path d="M30 0 V40 M0 20 H60" stroke="#fff" stroke-width="12"/>
    <path d="M30 0 V40 M0 20 H60" stroke="#c8102e" stroke-width="7"/>
  </svg>`,

  // Français → Francia
  fr: `<svg viewBox="0 0 60 40" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="France">
    <rect width="60" height="40" fill="#fff"/>
    <rect width="20" height="40" fill="#0055a4"/>
    <rect x="40" width="20" height="40" fill="#ef4135"/>
  </svg>`,

  // Deutsch → Alemania
  de: `<svg viewBox="0 0 60 40" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Deutschland">
    <rect width="60" height="40" fill="#000"/>
    <rect y="13.33" width="60" height="13.33" fill="#dd0000"/>
    <rect y="26.66" width="60" height="13.34" fill="#ffce00"/>
  </svg>`
};
