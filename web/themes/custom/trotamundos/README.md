# Trotamundos

Tema activo de la web. Versión 2.2, con cabecera común y tres tipos de contenido comerciales.

## Estructura de la web

| Ruta | Qué es | Plantilla |
| --- | --- | --- |
| `/` | Portada: vídeo, buscador, experiencias, propósito, destinos, compromiso local y confianza | `templates/layout/page--front.html.twig` |
| `/alojamientos` | Solo contenidos de tipo `alojamiento` | `templates/pages/trotamundos-catalog.html.twig` |
| `/experiencias` | Solo contenidos de tipo `experiencia` | `templates/pages/trotamundos-catalog.html.twig` |
| `/paquetes` | Solo contenidos de tipo `paquete` | `templates/pages/trotamundos-catalog.html.twig` |
| `/planes` | Búsqueda conjunta con filtros, orden y paginación | `templates/pages/trotamundos-catalog.html.twig` |
| `/alojamientos/{nombre}`, `/experiencias/{nombre}`, `/paquetes/{nombre}` | Ficha con galería, anfitriones, precios, disponibilidad, políticas y reserva; las tres reutilizan la misma presentación | `templates/content/node--experiencia--full.html.twig` |
| `/destinos` | Mapa de destinos y directorio por región y departamento | `templates/pages/trotamundos-destinations.html.twig` |
| `/destinos/{nombre}` | Página de destino con sus experiencias | `templates/content/node--destino--full.html.twig` |
| `/nuestra-esencia` | Quiénes somos, propósito, comunidades y aliados | `templates/pages/trotamundos-esencia.html.twig` |
| `/compromiso-local` | Compromiso con las comunidades y contacto para emprendedores | `templates/pages/trotamundos-compromiso.html.twig` |
| `/ayuda` | Contacto, preguntas frecuentes y atención al viajero | `templates/pages/trotamundos-ayuda.html.twig` |
| `/politica-de-privacidad`, `/aviso-legal`, `/politica-de-cookies` | Información legal provisional pendiente de revisión | `templates/pages/trotamundos-legal.html.twig` |
| `/guia-de-marca` | Guía de marca viva (lee `brand.yml` y `css/tokens.css`) | `templates/brand/trotamundos-brand-guide.html.twig` |

Módulos propios: `trotamundos_experiences` (tres tipos de contenido, catálogos, datos de las fichas y alias automáticos), `trotamundos_destinations` (página de destinos), `trotamundos_pages` (páginas editoriales y legales) y `trotamundos_brand` (guía de marca).

Las tres páginas legales son marcadores provisionales, no políticas completas: sus contenidos deben verificarse y completarse antes de publicar el sitio. El pie enlaza a las tres y el asistente del buscador enlaza directamente a privacidad; la advertencia de no enviar información sensible se conserva allí.

## Estilos

- `css/tokens.css`: colores, fuentes y escala tipográfica. Lectura a 17 px (16 px en móvil); ningún texto baja de 13 px.
- `css/base.css`, `css/layout.css`, `css/components.css`: base común (cabecera, pie, botones, tarjetas…).
- `css/discovery.css` y `js/discovery.js`: buscador común, destinos y calendario, sin dependencias externas.
- `css/destinations.css` y `js/destinations.js`: atlas y directorio de destinos.
- `css/front.css`, `css/immersive-hero.css`, `css/catalog.css`, `css/experience.css`, `css/pages.css`: cada página carga solo lo suyo.

## Cabecera y búsqueda

La biblioteca `header-layout` carga [header-layout.js](js/header-layout.js) en el `<head>`, sin esperar a los behaviors de Drupal. Su clase selecciona desde el primer pintado el menú cerrado y las dimensiones definitivas del buscador. Sin JavaScript se mantienen el menú visible y los controles nativos, incluido el envío GET de fechas.

Todas las páginas públicas comparten la navegación «Alojamientos, Experiencias, Paquetes» y el buscador. En la portada se superponen al vídeo, con un fondo verde y un desenfoque que se desvanecen hacia abajo cuando la cabecera está arriba; en las páginas interiores, un contenedor de altura estable evita saltos al compactar. Al superar 72 px de scroll, el formulario de escritorio anima su posición y anchura durante 420 ms, conservando las selecciones y respetando la preferencia de movimiento reducido. La animación se puede invertir al volver arriba y se completa antes de abrir un panel. En móvil, la pastilla y el icono del asistente mantienen la misma distribución al desplazarse; la pastilla despliega los campos y el menú conserva las tres categorías.

El menú se abre bajo su botón y por encima del buscador, con un fondo degradado de verde a transparente. Las categorías adicionales aparecen animadas al compactar, salvo si se prefiere movimiento reducido. Al cambiar de catálogo, los enlaces conservan los valores del buscador principal, pero no los filtros exclusivos del catálogo anterior; también funcionan sin JavaScript.

Al iniciar sesión, el mismo menú ofrece «Cerrar sesión» junto a «Mi cuenta» mediante la ruta protegida de Drupal. La opción no aparece a visitantes anónimos y funciona también sin JavaScript.

El icono del globo abre el selector de idioma en todos los tamaños de pantalla, también sin JavaScript. Con JavaScript se cierra al salir o abrir otro menú; Escape lo cierra y devuelve el foco al icono. No hay selección de moneda por ahora: los precios siguen en COP.

Las categorías usan una cama para Alojamientos, una brújula para Experiencias y una maleta para Paquetes, tanto en el menú principal como en el desplegable. El globo se reserva al idioma; los iconos conservan el trazo y color comunes y son decorativos junto a su etiqueta.

Destino y Fechas abren paneles del mismo ancho que la barra, también en modo compacto. Destino incluye todos los destinos publicados, aunque todavía no tengan planes, y una llamada a conocerlos todos; mientras está abierto se ocultan los accesos inferiores para que el selector quede por encima. El calendario muestra dos meses si hay al menos 520 px disponibles y uno en anchos menores. Admite teclado, cierre con Escape, fechas de inicio/fin y márgenes de ±1, ±2, ±3, ±7 o ±14 días. Los paneles permanecen abiertos al interactuar en su interior, seleccionar opciones, aplicar fechas, desplazarse o cambiar el tamaño de la ventana, y se recolocan cuando cambia la disposición del buscador; se cierran con su X, al hacer clic fuera o con Escape.

Los parámetros son `desde=AAAA-MM-DD`, `hasta=AAAA-MM-DD` y `margen`. El margen desplaza el intervalo completo sin acortarlo: debe caber en un único periodo de disponibilidad y no comenzar en el pasado. Un rango parcial, inválido o con un margen no permitido produce un error de validación. Se mantienen los enlaces antiguos por mes (`fecha=AAAA-MM`). Sin JavaScript siguen disponibles los controles nativos.

El acompañamiento abre un panel compacto con «Sin preferencia», «Pareja», «Familia», «Grupo» o «Solo». Seleccionar una opción sincroniza el único selector GET nativo sin cerrar el panel; las flechas, Inicio/Fin y Escape permiten manejarlo con teclado. Sin JavaScript se conserva el desplegable nativo. El acompañamiento es opcional y multivalor en los tres tipos. Las fichas existentes siguen sin asignar hasta revisarlas: aparecen sin ese filtro y no se presentan como compatibles con una modalidad sin confirmar.

En cada catálogo y ficha, el buscador conserva el tipo de producto; desde portada, destinos y páginas informativas busca conjuntamente en `/planes`.

Los cuatro catálogos, las tarjetas destacadas de la portada y los planes guardados usan cuatro columnas desde 1200 px, tres desde 900 px, dos desde 640 px y una por debajo. En las cuadrículas de catálogo y portada, los arcos se adaptan al número de columnas sin cambiar el tamaño de las imágenes.

Las tarjetas muestran el resumen editorial traducido en cursiva sobre un degradado en la parte inferior de la imagen al pasar el cursor o enfocar la tarjeta; en pantallas táctiles permanece visible. Los resúmenes muy largos se abrevian visualmente y siguen completos en la ficha y para lectores de pantalla. No se muestran distintivos de tipo o demo en las tarjetas. El precio aparece como «Desde $…», sin añadir moneda por persona ni flecha. Los campos y el separador del precio se alinean por fila en navegadores compatibles; en los demás el precio queda al pie de cada tarjeta. El resumen también se usa en la búsqueda, por lo que debe describir el plan con brevedad y sin prometer servicios no confirmados.

En todos los catálogos, el panel «Filtros» pertenece al buscador compartido e incluye la ordenación; el catálogo conjunto añade el tipo de plan. En móvil se accede a «Filtros» bajo los campos de fecha y acompañamiento al desplegar la búsqueda. En pantallas horizontales bajas, las categorías se ocultan temporalmente al desplegar los campos para mantener accesibles los controles. El H1 se conserva para lectores de pantalla y el número de resultados se anuncia sin mostrarse visualmente. Los estilos se presentan como tarjetas y el gráfico de precios representa los planes que coinciden con los demás filtros. Los límites enteros en COP se envían como `precio_min` y `precio_max`; el rango requiere pulsar «Ver resultados», mientras que los estilos, el tipo y el orden se aplican al cambiarlos. Con JavaScript, los resultados y la URL se actualizan sin recargar; la paginación, los chips y el historial también se sincronizan. Los enlaces anteriores por intervalos de precio o duración siguen funcionando, aunque duración ya no se ofrece como filtro. Sin JavaScript se pueden introducir los límites y enviar todos los controles con el botón.

Cuando está activo `trotamundos_assistant`, todas las cabeceras con buscador incluyen su conversación en un panel compacto alineado con la búsqueda. En la portada de escritorio, el acceso es un icono circular a la derecha del buscador, que solo se estrecha por ese lado cuando necesita espacio. En escritorio, al compactarse la cabecera con el scroll, el panel aparece debajo y se extiende desde el extremo de Filtros hasta el del Asistente. Al abrirlo, el botón se desplaza hasta el panel si es necesario y se transforma suavemente sin saltos; el contenido aparece después, el foco pasa al campo de texto, se ocultan los filtros y aparecen degradados animados de distintas intensidades sobre los laterales de la página y la cabecera, por debajo del panel y sin bloquear la interacción; el cierre queda en la cabecera. Si el campo no está disponible, el foco pasa al botón de cierre. La interfaz muestra únicamente la última respuesta y sus resultados, mientras el contexto permanece en la sesión del módulo. El campo crece automáticamente hasta cinco líneas y después permite desplazarse; el botón circular de envío conserva un nombre accesible. Durante la carga también aparecen degradados animados; se respeta la preferencia de movimiento reducido. «Nuevo chat» usa los mismos endpoints y permisos del módulo. En `/home-ai`, las consultas sugeridas abren este mismo asistente en vez de un segundo chat lateral. La interfaz del chat necesita JavaScript y lo indica si no está disponible. Su renderizado es individual mediante un placeholder y no inicia una sesión anónima hasta solicitar el token para interactuar.

La prueba `ddev exec drush php:script web/modules/custom/trotamundos_experiences/tests/catalog-header.php` comprueba los controles, la conservación de la búsqueda entre catálogos y el contenido de las tarjetas. `node --test web/modules/custom/trotamundos_experiences/tests/discovery-assistant-input.test.js` verifica el ajuste automático del campo y su limpieza. Para comprobar los paneles y filtros AJAX en el navegador, abrir una pestaña de prueba en `/planes` y ejecutar en su consola `await import('/modules/custom/trotamundos_experiences/tests/discovery-panels.browser.js').then(m => m.runDiscoveryPanelTests())`; la prueba cambia la búsqueda y la URL de esa pestaña. `runCatalogCardTests()` comprueba las tarjetas del catálogo al ancho actual; en la portada, `runHomeAssistantTests()` y `runHomeSliderTests()` comprueban respectivamente el asistente y los sliders.

Los catálogos muestran esqueletos con las dimensiones y los arcos de las tarjetas al cargar y mientras se aplican filtros o se navega entre catálogos. El estado se anuncia a lectores de pantalla, respeta movimiento reducido y se restablece al volver con el historial. Si no hay resultados se usa una cuadrícula provisional; sin JavaScript se muestran directamente los contenidos y los filtros nativos.

En la portada, «Una Colombia por descubrir» muestra los términos publicados de `estilo_viaje`, incluidos los que todavía no tienen planes. Los nombres, iconos, traducciones y orden se leen de Drupal. Las tarjetas verticales usan fotografías con un degradado verde, texto blanco y detalles terracota; la asignación por slug está en `templates/layout/page--front.html.twig` y los nuevos estilos usan una imagen genérica hasta asignarles otra. Cada tarjeta abre el catálogo completo filtrado. Con JavaScript, las flechas recorren una tarjeta cada vez en bucle y el carrusel avanza automáticamente cada 2,5 segundos, sin desplazamiento manual. Se puede pausar; también se detiene mientras el cursor está sobre el carrusel o el foco en la lista de tarjetas, con la pestaña oculta y cuando se prefiere movimiento reducido. Sin JavaScript se mantienen el desplazamiento nativo y todos los enlaces.

Hasta cinco tarjetas destacadas de «Explora Colombia por destino» forman un slider horizontal con flechas, desplazamiento táctil y navegación por teclado. Sin JavaScript se mantienen el desplazamiento nativo y los enlaces. Las fotografías usan una proporción vertical 3:5 y una altura mínima de 420 px (320 px en móvil). Sus esquinas suman 8 px al radio base, sin modificar las demás tarjetas.

«Nuestro compromiso» sustituye la presentación personal de la portada. Habla de comunidades y emprendimientos locales, utiliza la fotografía cedida [mesa-concepcion.webp](assets/images/mesa-concepcion.webp) y enlaza a la página de compromiso local, sin añadir métricas de impacto ni promesas de ingresos.

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

Los nuevos planes demo tienen resúmenes diferenciados en ambos idiomas. Para actualizar los anteriores existe la operación explícita `DemoCatalog::refreshDefaultSummaries()`: solo cambia traducciones cuyos títulos y resúmenes siguen siendo los originales generados, en fichas aún marcadas como demo, sin borradores pendientes y registradas en `trotamundos_commerce.demo_plans`. Conserva las ediciones y crea una revisión; antes de ejecutarla hay que comprobar también la configuración de indexación.

La prueba sin escrituras `ddev exec drush php:script web/modules/custom/trotamundos_commerce/tests/demo-summaries.php` verifica que la actualización respete ediciones, borradores pendientes, traducciones ausentes y fichas que ya no son demo.

`DemoCatalog::refreshDefaultMessages()` sustituye únicamente textos generados reconocibles en fichas demo publicadas y registradas, sin borradores pendientes. Conserva los cambios editoriales, los importes, las referencias de imágenes y las advertencias de confirmación, y crea una revisión por ficha modificada. Antes de ejecutarla hay que comprobar la indexación, respaldar los contenidos y utilizar una transacción. La prueba sin escrituras `ddev exec drush php:script web/modules/custom/trotamundos_commerce/tests/demo-messages.php` comprueba estas garantías y la idempotencia.

Desde la raíz del proyecto:

```sh
ddev composer install
ddev drush en trotamundos_commerce -y
ddev drush php:script modules/custom/trotamundos_commerce/tests/commerce-products.php
ddev drush php:script modules/custom/trotamundos_commerce/tests/catalog-commerce.php
```

Las pruebas revierten sus datos: verifican tipos, repetición, doce fichas por tipo, títulos traducidos, conservación de URL/revisiones y precios decimales procedentes de Commerce.

### Perfil viajero privado

La URL de cuenta de Drupal (`/user/{uid}`) muestra al propietario sus pedidos no borradores de Commerce. Los planes guardados (`/user/{uid}/planes-guardados`) y las preferencias opcionales (`/user/{uid}/preferencias-viaje`) tienen páginas propias y una navegación común con la sección actual marcada. «Ajustes de cuenta» abre `/user/{uid}/ajustes` con el tema frontal y la validación nativa del usuario, incluida la contraseña actual para modificar el correo o la contraseña. Estas páginas solo son accesibles por su propietario. La edición administrativa (`/user/{uid}/edit`) y sus permisos se mantienen; al consultar otro usuario, se conserva su presentación normal, nunca su panel privado.

En el entorno local, la cuenta de administración dispone del pedido de prueba `DEMO-PERFIL-ADMIN-001`. Se creó directamente, sin pasar por el checkout ni enviar recibos; no implica ningún pago ni reserva.

Los tres campos de viaje solo son accesibles por su propietario o quien administra usuarios, también fuera de la plantilla. No se asignan preferencias a cuentas existentes ni se aplican filtros automáticamente. Las solicitudes por WhatsApp no se presentan como pedidos o reservas confirmadas.

Las tarjetas de alojamientos, experiencias y paquetes tienen un corazón independiente del enlace de la ficha. Su estado corresponde a los planes guardados del perfil; «Guardar en mis planes» sigue disponible en las fichas. Sin iniciar sesión, el corazón conduce al acceso conservando la página, filtros y paginación. Guardar y quitar usan formularios POST con protección CSRF, funcionan sin JavaScript y son idempotentes. Con JavaScript, las tarjetas utilizan el AJAX de Drupal sin recargar, sincronizan los corazones del mismo plan y actualizan la lista del perfil; las operaciones simultáneas del mismo usuario se serializan. Las tarjetas envían a una ruta propia para procesar la acción aunque cambie la lista; sin JavaScript, Drupal devuelve al destino local original. Los controles privados se generan mediante placeholders fuera de la caché compartida del catálogo. Se conserva un botón para quitar favoritos cuya ficha haya sido borrada o retirada.

```sh
ddev drush updatedb -y
ddev drush locale:import es modules/custom/trotamundos_commerce/translations/es.po --override=none
ddev drush cache:rebuild
ddev drush php:script modules/custom/trotamundos_commerce/tests/travel-profile.php
```

## Tareas habituales

**Añadir un plan.** Contenido › Añadir › Alojamiento, Experiencia o Paquete. La primera foto de la galería es la portada. Mientras precios y fechas no estén confirmados con el aliado, deja marcada «Ficha de ejemplo»: las tarjetas muestran «DEMO» y el carrito advierte de que no confirma disponibilidad ni reserva. El alias se genera según el tipo solo si se deja vacío.

**Acompañamiento.** Al editar un alojamiento, experiencia o paquete, marcar únicamente las modalidades confirmadas. Se puede dejar vacío y no cambia la capacidad máxima de viajeros ni las condiciones de reserva.

**Añadir un destino.** Contenido › Añadir › Destino, con municipio, departamento, región y coordenadas aproximadas. El atlas de `/destinos` utiliza el contorno local de Natural Earth y muestra un punto numerado por destino; las líneas separan las etiquetas cercanas de su posición real. Los enlaces del mapa y la lista funcionan sin JavaScript y no necesitan un servicio externo de mapas. Los destinos sin coordenadas válidas siguen en la lista con «Ubicación pendiente». Aparecen como «Próximamente» hasta que tengan planes.

La prueba `ddev exec drush php:script web/modules/custom/trotamundos_destinations/tests/destinations-map.php` comprueba los destinos publicados, las coordenadas coincidentes, las islas y los datos ausentes.

**WhatsApp, correo e Instagram.** Apariencia › Ajustes › Trotamundos › Canales de contacto (configuración `trotamundos.settings`).

**Vídeo de portada.** El montaje actual es provisional y utiliza imágenes de archivo; sus fuentes están en `assets/CREDITS.txt`. El vídeo y sus carteles llevan una viñeta y un velo negro sutil, por debajo del texto y los controles. En pantallas estrechas o en orientación vertical se usa la versión vertical, con la precarga del cartel correspondiente. Sin JavaScript se muestra el cartel adaptado; también se respeta la preferencia de movimiento reducido. Para sustituirlo, reemplazar `assets/video/hero-naturaleza.mp4`, `hero-naturaleza-mobile.mp4` y sus carteles `.webp` manteniendo los nombres. Actualizar también el mismo parámetro `v` en `templates/components/immersive-hero.html.twig` y en las precargas de `templates/layout/html.html.twig` para evitar recursos antiguos en caché. Formato recomendado en `brand.yml` (apartado `audiovisual`).

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
