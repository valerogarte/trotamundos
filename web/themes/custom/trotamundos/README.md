# Trotamundos

Tema activo de la web. Versión 2.2, con cabecera común y tres tipos de contenido comerciales.

## Estructura de la web

| Ruta | Qué es | Plantilla |
| --- | --- | --- |
| `/` | Portada: vídeo, buscador, experiencias, propósito, destinos, Juan y confianza | `templates/layout/page--front.html.twig` |
| `/alojamientos` | Solo contenidos de tipo `alojamiento` | `templates/pages/trotamundos-catalog.html.twig` |
| `/experiencias` | Solo contenidos de tipo `experiencia` | `templates/pages/trotamundos-catalog.html.twig` |
| `/paquetes` | Solo contenidos de tipo `paquete` | `templates/pages/trotamundos-catalog.html.twig` |
| `/planes` | Búsqueda conjunta con filtros, orden y paginación | `templates/pages/trotamundos-catalog.html.twig` |
| `/alojamientos/{nombre}`, `/experiencias/{nombre}`, `/paquetes/{nombre}` | Ficha con galería, anfitriones, precios, disponibilidad, políticas y reserva; las tres reutilizan la misma presentación | `templates/content/node--experiencia--full.html.twig` |
| `/destinos` | Destinos por región, departamento y municipio | `templates/pages/trotamundos-destinations.html.twig` |
| `/destinos/{nombre}` | Página de destino con sus experiencias | `templates/content/node--destino--full.html.twig` |
| `/nuestra-esencia` | Quiénes somos, propósito, comunidades y aliados | `templates/pages/trotamundos-esencia.html.twig` |
| `/ayuda` | Contacto, preguntas frecuentes y atención al viajero | `templates/pages/trotamundos-ayuda.html.twig` |
| `/guia-de-marca` | Guía de marca viva (lee `brand.yml` y `css/tokens.css`) | `templates/brand/trotamundos-brand-guide.html.twig` |

Módulos propios: `trotamundos_experiences` (tres tipos de contenido, catálogos, datos de las fichas y alias automáticos), `trotamundos_destinations` (página de destinos), `trotamundos_pages` (Nuestra esencia y Ayuda) y `trotamundos_brand` (guía de marca).

## Estilos

- `css/tokens.css`: colores, fuentes y escala tipográfica. Lectura a 17 px (16 px en móvil); ningún texto baja de 13 px.
- `css/base.css`, `css/layout.css`, `css/components.css`: base común (cabecera, pie, botones, tarjetas…).
- `css/discovery.css` y `js/discovery.js`: buscador común, destinos y calendario, sin dependencias externas.
- `css/front.css`, `css/immersive-hero.css`, `css/catalog.css`, `css/experience.css`, `css/pages.css`: cada página carga solo lo suyo.

## Cabecera y búsqueda

La biblioteca `header-layout` carga [header-layout.js](js/header-layout.js) en el `<head>`, sin esperar a los behaviors de Drupal. Su clase selecciona desde el primer pintado el menú cerrado y las dimensiones definitivas del buscador. Sin JavaScript se mantienen el menú visible y los controles nativos, incluido el envío GET de fechas.

Todas las páginas públicas comparten la navegación «Alojamientos, Experiencias, Paquetes» y el buscador. En la portada se superponen al vídeo; en las páginas interiores, un contenedor de altura estable evita saltos al compactar. Al superar 72 px de scroll, el mismo formulario anima su posición y anchura durante 420 ms, conservando las selecciones y respetando la preferencia de movimiento reducido. La animación se puede invertir al volver arriba y se completa antes de abrir un panel. En móvil, una pastilla despliega los campos; el menú mantiene accesibles las tres categorías al bajar.

El icono del globo abre el selector de idioma en todos los tamaños de pantalla, también sin JavaScript. Con JavaScript se cierra al salir o abrir otro menú; Escape lo cierra y devuelve el foco al icono. No hay selección de moneda por ahora: los precios siguen en COP.

Las categorías usan una cama para Alojamientos, una brújula para Experiencias y una maleta para Paquetes, tanto en el menú principal como en el desplegable. El globo se reserva al idioma; los iconos conservan el trazo y color comunes y son decorativos junto a su etiqueta.

Destino y Fechas abren paneles del mismo ancho que la barra, también en modo compacto. Destino incluye todos los destinos publicados, aunque todavía no tengan planes, y una llamada a conocerlos todos. El calendario muestra dos meses si hay al menos 520 px disponibles y uno en anchos menores. Admite teclado, cierre con Escape, fechas de inicio/fin y márgenes de ±1, ±2, ±3, ±7 o ±14 días.

Los parámetros son `desde=AAAA-MM-DD`, `hasta=AAAA-MM-DD` y `margen`. El margen desplaza el intervalo completo sin acortarlo: debe caber en un único periodo de disponibilidad y no comenzar en el pasado. Un rango parcial, inválido o con un margen no permitido produce un error de validación. Se mantienen los enlaces antiguos por mes (`fecha=AAAA-MM`). Sin JavaScript siguen disponibles los controles nativos.

El acompañamiento abre un panel compacto que solo contiene «Sin preferencia», «Pareja», «Familia», «Grupo» o «Solo». Seleccionar una opción cierra el panel y sincroniza el único selector GET nativo; las flechas, Inicio/Fin y Escape permiten manejarlo con teclado. Sin JavaScript se conserva el desplegable nativo. El acompañamiento es opcional y multivalor en los tres tipos. Las fichas existentes siguen sin asignar hasta revisarlas: aparecen sin ese filtro y no se presentan como compatibles con una modalidad sin confirmar.

En cada catálogo y ficha, el buscador conserva el tipo de producto; desde portada, destinos y páginas informativas busca conjuntamente en `/planes`.

Los catálogos de alojamientos, experiencias y paquetes usan cuatro columnas desde 1024 px. Los arcos siguen un ciclo de dos filas: exteriores en la primera, centrales en la segunda, repitiéndose sin cambiar el tamaño de las imágenes. Los distintivos bajan en las tarjetas con arco para no quedar recortados. En tablet y móvil se mantiene la cuadrícula adaptable.

En los tres catálogos, el encabezado editorial se sustituye por filtros complementarios de estilo, precio y duración; el H1 se conserva para lectores de pantalla. Estos controles y la ordenación pertenecen al formulario común del header mediante el atributo HTML `form`, por lo que conservan destino, fechas, acompañamiento y región en ambas direcciones, también sin JavaScript y sin campos duplicados. El catálogo conjunto añade el tipo de plan. Con JavaScript, los filtros complementarios se aplican al cambiarlos; sin él, mantienen el botón «Ver resultados».

Los catálogos muestran esqueletos con las dimensiones y los arcos de las tarjetas al cargar y mientras se aplican filtros o se navega entre catálogos. El estado se anuncia a lectores de pantalla, respeta movimiento reducido y se restablece al volver con el historial. Si no hay resultados se usa una cuadrícula provisional; sin JavaScript se muestran directamente los contenidos y los filtros nativos.

La portada muestra «Planes en Colombia» con un slider de términos publicados de `estilo_viaje`, incluidos los que todavía no tienen planes. Los nombres, iconos, traducciones y orden se leen de Drupal, no de etiquetas fijas de la plantilla. Cada tarjeta abre el catálogo completo filtrado; las flechas respetan movimiento reducido y, sin JavaScript, siguen disponibles el desplazamiento nativo y los enlaces.

Las fotografías de «Explora Colombia por destino» usan una proporción vertical 3:5 y una altura mínima de 420 px (320 px en móvil). Sus esquinas suman 8 px al radio base, sin modificar las demás tarjetas.

«Nuestro compromiso» sustituye la presentación personal de la portada. Habla de comunidades y emprendimientos locales, utiliza la fotografía cedida [mesa-concepcion.webp](assets/images/mesa-concepcion.webp) y enlaza a Nuestra esencia, sin añadir métricas de impacto ni promesas de ingresos.

La actualización `11003` añade «Café y tradición» y renombra «Cultura y tradición» a «Cultura viva» conservando su ID y las referencias existentes; mantiene operativos los filtros antiguos `estilo=cultura-y-tradicion`. Ordena las siete categorías solicitadas una vez, sin asignar categorías nuevas a productos ni crear experiencias.

### Actualización del modelo

Guardar una copia de la base de datos antes de aplicar la actualización `11002`:

```sh
mkdir -p .local/backups
ddev export-db --file=.local/backups/pre-product-types.sql.gz
ddev drush updatedb -y
ddev drush cr
```

La actualización crea los tipos y comparte campos, formularios, traducciones y permisos editoriales. Traslada las fichas clasificadas como Alojamiento o Paquete conservando IDs, archivos, traducciones, revisiones y alias existentes, incluso los que empiezan por `/experiencias/`. Verifica su contenido antes de confirmar la transacción. El antiguo campo «Tipo de plan» queda como dato histórico, opcional y oculto: el tipo de contenido es la clasificación efectiva.

Las comprobaciones usan datos temporales y revierten todas sus escrituras:

```sh
ddev drush php:script modules/custom/trotamundos_experiences/tests/product-search.php
ddev drush php:script modules/custom/trotamundos_experiences/tests/accompaniment-filter.php
```

## Drupal Commerce: catálogo y tienda en desarrollo

La instalación de la pasarela online queda aplazada por indicación del propietario. No se debe instalar ni activar una pasarela, tampoco manual, sin una nueva autorización.

El comprobador nativo de disponibilidad vuelve a validar fechas completas y viajeros enteros dentro de la capacidad, también al actualizar el carrito. No sustituye un inventario compartido por fecha ni confirma plazas: esa operativa debe revisarse antes de activar pagos. Mientras la tienda está pendiente, el checkout no construye formularios de pago y rechaza los POST con HTTP 503.

`trotamundos_commerce` habilita Commerce Product y Order y prepara un tipo de producto y de variación para cada ficha: `alojamiento`, `experiencia` y `paquete`. Los productos nuevos de estos tres tipos empiezan en borrador y sus variaciones inactivas; cada uno admite varias tarifas. COP está habilitado, sin añadir un selector de moneda al sitio.

Las fichas editoriales conservan contenido, traducciones, URL e historial. Cada una está vinculada a un producto de su mismo tipo con una variación por persona. Por autorización expresa se han importado los precios de demostración y completado doce fichas distintas por tipo: 36 fichas y 36 productos. Las nuevas fotografías son ambientaciones reutilizadas del material autorizado, no establecimientos confirmados.

La tienda Trotamundos usa COP, el correo de contacto y La Ceja, Antioquia. **La calle está explícitamente marcada como pendiente, solo para desarrollo, con autorización del propietario; no es una dirección fiscal verificada.** Carrito, checkout y Commerce Payment están habilitados, pero no hay una pasarela ni credenciales y la finalización de compras está bloqueada. No se ha configurado pago manual, generado cobros ni confirmado reservas.

Commerce es la fuente del precio: `field_tm_catalog_price` es únicamente un índice decimal para filtros y ordenación, actualizado al modificar la variación o el producto. El antiguo precio editorial se conserva, pero se oculta en el formulario al vincular Commerce. Los títulos se editan en la ficha editorial y se sincronizan con el producto. Los nuevos productos creados manualmente siguen empezando en borrador.

La provisión es explícita e idempotente: `CatalogImport::developmentStore($email)`, `DemoCatalog::ensure()`, `CatalogImport::import($store)` y, tras activar Cart, `CatalogImport::configureCart()`. Ejecutar las operaciones de datos dentro de una transacción, con copia de seguridad previa; no forman parte de una visita web ni se repiten en cada despliegue. El registro `trotamundos_commerce.provisioning` conserva el UUID de la tienda y `production_address_pending`. No retirar ese bloqueo hasta verificar dirección, tarifas, disponibilidad, fiscalidad y pasarela.

Desde la raíz del proyecto:

```sh
ddev composer install
ddev drush en trotamundos_commerce -y
ddev drush php:script modules/custom/trotamundos_commerce/tests/commerce-products.php
ddev drush php:script modules/custom/trotamundos_commerce/tests/catalog-commerce.php
```

Las pruebas revierten sus datos: verifican tipos, repetición, doce fichas por tipo, títulos traducidos, conservación de URL/revisiones y precios decimales procedentes de Commerce.

### Perfil viajero privado

La URL de cuenta de Drupal (`/user/{uid}`) muestra al propietario sus pedidos no borradores de Commerce, preferencias opcionales de estilos y acompañamiento, planes guardados y enlaces a cuenta y ayuda. Conserva los permisos, pestañas y edición nativos; al consultar otro usuario se mantiene su presentación normal, nunca su panel privado.

Los tres campos de viaje solo son accesibles por su propietario o quien administra usuarios, también fuera de la plantilla. No se asignan preferencias a cuentas existentes ni se aplican filtros automáticamente. Las solicitudes por WhatsApp no se presentan como pedidos o reservas confirmadas.

Las tarjetas de alojamientos, experiencias y paquetes tienen un corazón independiente del enlace de la ficha. Su estado corresponde a los planes guardados del perfil; «Guardar en mis planes» sigue disponible en las fichas. Sin iniciar sesión, el corazón conduce al acceso conservando la página, filtros y paginación. Guardar y quitar usan formularios POST con protección CSRF, funcionan sin JavaScript y son idempotentes. Con JavaScript, las tarjetas utilizan el AJAX de Drupal sin recargar, sincronizan los corazones del mismo plan y actualizan la lista del perfil; las operaciones simultáneas del mismo usuario se serializan. Las tarjetas envían a una ruta propia para procesar la acción aunque cambie la lista; sin JavaScript, Drupal devuelve al destino local original. Los controles privados se generan mediante placeholders fuera de la caché compartida del catálogo. Se conserva un botón para quitar favoritos cuya ficha haya sido borrada o retirada.

```sh
ddev drush updatedb -y
ddev drush cache:rebuild
ddev drush php:script modules/custom/trotamundos_commerce/tests/travel-profile.php
```

## Tareas habituales

**Añadir un plan.** Contenido › Añadir › Alojamiento, Experiencia o Paquete. La primera foto de la galería es la portada. Mientras precios y fechas no estén confirmados con el aliado, deja marcada «Ficha de ejemplo»: la web muestra un aviso. El alias se genera según el tipo solo si se deja vacío.

**Acompañamiento.** Al editar un alojamiento, experiencia o paquete, marcar únicamente las modalidades confirmadas. Se puede dejar vacío y no cambia la capacidad máxima de viajeros ni las condiciones de reserva.

**Añadir un destino.** Contenido › Añadir › Destino, con municipio, departamento, región y coordenadas (para el mapa). Aparece en `/destinos` como «Próximamente» hasta que tenga experiencias.

**WhatsApp, correo e Instagram.** Apariencia › Ajustes › Trotamundos › Canales de contacto (configuración `trotamundos.settings`).

**Vídeo de portada.** Sustituir `assets/video/hero-naturaleza.mp4`, `hero-naturaleza-mobile.mp4` y sus carteles `.webp` manteniendo los nombres. Formato recomendado en `brand.yml` (apartado `audiovisual`).

**Reserva y pago.** La ficha ofrece el formulario nativo de carrito con fecha de llegada y viajeros. Se valida que el viaje completo quepa en un periodo y se guarda la fecha en los datos de la línea; distintas incorporaciones no se fusionan. La consulta por WhatsApp se conserva como alternativa desplegable. El carrito no confirma disponibilidad. La finalización sigue bloqueada hasta completar los datos comerciales y configurar una pasarela online segura.

## Idiomas

Español sin prefijo e inglés en `/en`. Los textos de las plantillas están en español y se traducen con `modules/custom/trotamundos_experiences/translations/en.po`:

```sh
ddev drush locale:import en /var/www/html/web/modules/custom/trotamundos_experiences/translations/en.po --type=customized --override=all
```

El contenido (alojamientos, experiencias, paquetes, destinos y términos) se traduce desde la pestaña «Traducir» de cada elemento. Los catálogos ingleses usan `/en/stays`, `/en/experiences`, `/en/packages` y `/en/plans`. La guía de marca es un documento interno y está solo en español.

## Guía de marca en PDF

El PDF de `assets/brand/` se genera imprimiendo `/guia-de-marca` (estilos `@media print` de `css/brand-guide.css`) y se comprime con Ghostscript. Después hay que reconstruir `assets/brand/trotamundos-brand-kit.zip`.

## Contenido de ejemplo

Las cuatro fichas del piloto se conservan como dos alojamientos y dos paquetes. Los scripts históricos de `.local/scripts/seed-revision-1*.php` usan el modelo anterior: no deben reejecutarse sobre una instalación migrada sin adaptarlos a los tres tipos.

Créditos de imágenes, vídeo y mapa: `assets/CREDITS.txt`.
