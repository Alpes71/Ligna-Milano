# Ligna Milano, despliegue v9 SEO + legal

## Archivos principales

- `index.html`, landing principal.
- `aviso-legal.html`, aviso legal base.
- `privacidad.html`, política de privacidad y datos personales.
- `cookies.html`, política de cookies.
- `condiciones.html`, condiciones de servicio para presupuestos y encargos personalizados.
- `sitemap.xml`, sitemap para Google Search Console.
- `robots.txt`, reglas para buscadores.
- `llms.txt`, contexto de marca para crawlers y asistentes IA.
- `IUBENDA_SETUP.txt`, instrucciones para pegar el snippet real de iubenda.

## SEO incluido

- Título y description optimizados.
- Canonical.
- OpenGraph y Twitter Card.
- Hreflang es-ES y x-default.
- JSON-LD con Organization, LocalBusiness, WebSite, Service y FAQPage.
- Sitemap con home y páginas legales.
- Robots.txt con exclusión de archivos técnicos.

## Legal incluido

- Footer con enlaces legales.
- Política de privacidad.
- Política de cookies.
- Aviso legal.
- Condiciones de servicio.
- Checkbox obligatorio de privacidad en el formulario.
- El formulario registra aceptación de privacidad y la incluye en el email.
- El chatbot queda instruido para informar de forma natural cuando solicite email o teléfono.

## Muy importante antes de publicar

Completar en `aviso-legal.html` y `privacidad.html` los datos reales:

- Titular legal, nombre y apellidos o razón social.
- NIF/CIF.
- Domicilio fiscal.
- Datos registrales si aplica.

Sin esos datos, el sitio está técnicamente preparado, pero la capa legal no queda cerrada.

## Iubenda

Ligna Milano ya está dado de alta en iubenda. Hay que pegar el snippet oficial de iubenda en el `<head>` de las páginas HTML.

Busca este comentario en cada página:

```html
<!-- IUBENDA COOKIE BANNER: pegar aquí el snippet oficial de iubenda... -->
```

Pega ahí el código real entregado por iubenda. No uses IDs de ejemplo.

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

Si ya tienes el hosting funcionando con API y SMTP, no sobrescribas `config.local.php` salvo que sepas exactamente qué cambias.


## Actualización v10, iubenda y chat

- Snippet de iubenda añadido en el `<head>` de las páginas HTML principales.
- Enlaces oficiales de Política de Privacidad y Política de Cookies añadidos al footer.
- Enlace de gestión de preferencias de cookies conectado a iubenda.
- Botón del chat elevado para evitar superposición con el widget flotante de iubenda.
- `main.js`, `legal.js` y `styles.css` actualizados con cache busting `v=10`.

Archivos recomendados para subir si no quieres tocar configuración sensible: `index.html`, `aviso-legal.html`, `privacidad.html`, `cookies.html`, `condiciones.html`, `assets/css/styles.css`, `assets/js/legal.js`, `assets/js/main.js`, `IUBENDA_SETUP.txt`.
