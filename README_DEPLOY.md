# Ligna Milano — despliegue (impresión UV + legal + cookies propias)

## Archivos principales

- `index.html`, landing principal (enfoque impresión UV con eufyMake E1).
- `aviso-legal.html`, aviso legal base.
- `privacidad.html`, política de privacidad y datos personales.
- `cookies.html`, política de cookies.
- `condiciones.html`, condiciones de servicio para presupuestos y encargos personalizados.
- `sitemap.xml`, sitemap para Google Search Console.
- `robots.txt`, reglas para buscadores.
- `llms.txt`, contexto de marca para crawlers y asistentes IA.

## SEO incluido

- Título y description optimizados.
- Canonical.
- OpenGraph y Twitter Card.
- Hreflang es-ES y x-default.
- JSON-LD con Organization, LocalBusiness, WebSite, Service y FAQPage.
- Sitemap con home y páginas legales.
- Robots.txt con exclusión de archivos técnicos.

## Legal incluido

- Footer con enlaces legales propios (aviso legal, privacidad, cookies, condiciones).
- Política de privacidad.
- Política de cookies.
- Aviso legal.
- Condiciones de servicio.
- Checkbox obligatorio de privacidad en el formulario.
- El formulario registra aceptación de privacidad y la incluye en el email.
- El chatbot queda instruido para informar de forma natural cuando solicite email o teléfono.

## Consentimiento de cookies (sistema propio)

iubenda se retiró del proyecto. El consentimiento lo gestiona un banner propio,
sin dependencias externas:

- `assets/css/cookie-banner.css`, estilos del banner.
- `assets/js/cookie-banner.js`, lógica (aceptar / rechazar / gestionar preferencias).

La decisión del usuario se guarda en `localStorage` (`lm_cookie_consent`). El
enlace "Gestionar cookies" del pie (`data-cookie-preferences`) reabre el banner.
Si en el futuro se añaden scripts que instalen cookies no esenciales, deben
cargarse solo tras el consentimiento, usando los eventos `lm:cookies-accepted` /
`lm:cookies-rejected`.

## Muy importante antes de publicar

Completar en `aviso-legal.html` y `privacidad.html` los datos reales:

- Titular legal, nombre y apellidos o razón social.
- NIF/CIF.
- Domicilio fiscal.
- Datos registrales si aplica.

Sin esos datos, el sitio está técnicamente preparado, pero la capa legal no queda cerrada.

## Google Search Console

Al subir la web:

1. Verificar propiedad de `https://lignamilano.com/`.
2. Enviar sitemap: `https://lignamilano.com/sitemap.xml`.
3. Solicitar indexación de la home.
4. Revisar cobertura, experiencia móvil y resultados enriquecidos.

## Archivos sensibles

No publicar ni compartir fuera del hosting:

- `config.local.php`
- cualquier archivo con credenciales SMTP
- cualquier API key

`config.local.php` está excluido en `.gitignore` y no debe subirse al repositorio.
Si ya tienes el hosting funcionando con API y SMTP, no sobrescribas `config.local.php`
salvo que sepas exactamente qué cambias.

## Cache busting actual

- CSS: `styles.css?v=10`, `uv-update.css?v=1`, `cookie-banner.css?v=1`.
- JS: `site-config.js?v=10`, `legal.js?v=11`, `cookie-banner.js?v=1`, `main.js?v=10`.

Al modificar un archivo estático, sube su número de versión para forzar recarga en navegadores.
