# Trotamundos

Tema activo de la web. Versión 2.0, tras la primera revisión con Juan y Alejo (16-11-2026).

## Estructura de la web

| Ruta | Qué es | Plantilla |
| --- | --- | --- |
| `/` | Portada: vídeo, buscador, experiencias, propósito, destinos, Juan y confianza | `templates/layout/page--front.html.twig` |
| `/experiencias` | Catálogo con filtros (estilo, destino, tipo de plan, precio, duración, fecha), orden y paginación | `templates/pages/trotamundos-catalog.html.twig` |
| `/experiencias/{nombre}` | Ficha de experiencia con galería, plan, anfitriones, ubicación, fechas, políticas y reserva | `templates/content/node--experiencia--full.html.twig` |
| `/destinos` | Destinos por región, departamento y municipio | `templates/pages/trotamundos-destinations.html.twig` |
| `/destinos/{nombre}` | Página de destino con sus experiencias | `templates/content/node--destino--full.html.twig` |
| `/nuestra-esencia` | Quiénes somos, propósito, comunidades y aliados | `templates/pages/trotamundos-esencia.html.twig` |
| `/ayuda` | Contacto, preguntas frecuentes y atención al viajero | `templates/pages/trotamundos-ayuda.html.twig` |
| `/guia-de-marca` | Guía de marca viva (lee `brand.yml` y `css/tokens.css`) | `templates/brand/trotamundos-brand-guide.html.twig` |

Módulos propios: `trotamundos_experiences` (tipo de contenido, catálogo, datos de las fichas y alias automáticos), `trotamundos_destinations` (página de destinos), `trotamundos_pages` (Nuestra esencia y Ayuda) y `trotamundos_brand` (guía de marca).

## Estilos

- `css/tokens.css`: colores, fuentes y escala tipográfica. Lectura a 17 px (16 px en móvil); ningún texto baja de 13 px.
- `css/base.css`, `css/layout.css`, `css/components.css`: base común (cabecera, pie, botones, tarjetas…).
- `css/front.css`, `css/immersive-hero.css`, `css/catalog.css`, `css/experience.css`, `css/pages.css`: cada página carga solo lo suyo.

## Tareas habituales

**Añadir una experiencia.** Contenido › Añadir › Experiencia. La primera foto de la galería es la portada. Mientras precios y fechas no estén confirmados con el aliado, deja marcada «Ficha de ejemplo»: la web muestra un aviso. El alias (`/experiencias/...`) se genera solo si se deja vacío.

**Añadir un destino.** Contenido › Añadir › Destino, con municipio, departamento, región y coordenadas (para el mapa). Aparece en `/destinos` como «Próximamente» hasta que tenga experiencias.

**WhatsApp, correo e Instagram.** Apariencia › Ajustes › Trotamundos › Canales de contacto (configuración `trotamundos.settings`).

**Vídeo de portada.** Sustituir `assets/video/hero-naturaleza.mp4`, `hero-naturaleza-mobile.mp4` y sus carteles `.webp` manteniendo los nombres. Formato recomendado en `brand.yml` (apartado `audiovisual`).

**Reserva y pago.** «Reservar» valida la fecha y los viajeros, muestra el resumen y envía la solicitud por WhatsApp; el pago se hace con un enlace de pago seguro. Para conectar una pasarela, sustituir `openReview()` en `js/experience.js`.

## Idiomas

Español sin prefijo e inglés en `/en`. Los textos de las plantillas están en español y se traducen con `modules/custom/trotamundos_experiences/translations/en.po`:

```sh
ddev drush locale:import en /var/www/html/web/modules/custom/trotamundos_experiences/translations/en.po --type=customized --override=all
```

El contenido (experiencias, destinos y términos) se traduce desde la pestaña «Traducir» de cada elemento. La guía de marca es un documento interno y está solo en español.

## Guía de marca en PDF

El PDF de `assets/brand/` se genera imprimiendo `/guia-de-marca` (estilos `@media print` de `css/brand-guide.css`) y se comprime con Ghostscript. Después hay que reconstruir `assets/brand/trotamundos-brand-kit.zip`.

## Contenido de ejemplo

`code/.local/scripts/seed-revision-1.php` y `seed-revision-1-en.php` crean (o actualizan) las cuatro experiencias del piloto, los destinos y sus traducciones con las fotos de Juan:

```sh
ddev drush php:script .local/scripts/seed-revision-1.php
ddev drush php:script .local/scripts/seed-revision-1-en.php
```

Créditos de imágenes, vídeo y mapa: `assets/CREDITS.txt`.
