# Trotamundos Assistant

Módulo custom de Drupal para integrar un asistente IA dentro de la experiencia
web. La interfaz usa un chat lateral colapsable y un contenedor de resultado
externo donde Drupal renderiza respuestas visuales mediante templates, skills y
acciones controladas.

Idea base:

```text
La IA interpreta la intención y devuelve datos estructurados.
Drupal gobierna configuración, permisos, skills activas, plantillas, acciones,
persistencia, coste, auditoría y renderizado final.
```

## Alcance

- Página pública `/home-ai` con el bloque `trotamundos_assistant`.
- Bloque reutilizable en páginas del sitio mediante regiones Drupal.
- Chat lateral colapsable con pestaña configurable y botón de nuevo chat.
- Envío de mensajes por botón y por `Ctrl + Enter`.
- Panel de resultado externo, identificado por selector CSS configurable.
- El contenido original del panel externo se conserva en la carga inicial.
- Integración con el assistant configurado en `trotamundos_assistant.settings`.
- Assistant por defecto: `minsait_buscador_nodes`.
- Persistencia de conversación mediante `thread_id`, `sessionStorage` y tempstore.
- Control de acceso por roles configurables.
- Límite de longitud de mensaje y rate limit por usuario/IP.
- Sistema de skills descubiertas desde carpetas `skills/` de módulos activos.
- Activación/desactivación de skills desde administración.
- Envío al proveedor únicamente de skills activas y con dependencias satisfechas.
- Contrato de respuesta estructurada con `skill`, `chat_reply` y `result`.
- Renderizado de resultados con Twig y handlers PHP por skill.
- Acciones AJAX por skill con validación de método, acceso y CSRF cuando aplica.
- Registro de llamadas reales al proveedor, tokens, coste estimado y payloads.
- Pantallas admin de flujo, estado, coste, estadísticas, configuración, skills y extensiones.
- Submódulo ecommerce con skills para catálogo, productos, carrito y pago.
- Submódulo documents con reglas de extracción de texto PDF y skill documental.
- Colores corporativos Minsait y tipografía ForFuture Sans configurable.

## Módulos

### `trotamundos_assistant`

Módulo base. Contiene:

- UI del asistente.
- Endpoints de chat e historial.
- Sistema de discovery/matching/render de skills.
- Configuración administrativa.
- Control de acceso.
- Registro de uso y coste.
- Processor Search API para enriquecer documentos de catálogo.
- Skills base de Drupal: `default`, `content_create`, `content_edit` y `user_profile`.

### `trotamundos_assistant_ecommerce`

Submódulo de capacidades ecommerce. Aporta skills para:

- búsqueda de productos,
- resumen de producto,
- recomendaciones,
- alternativas,
- comparativas,
- aclaraciones,
- añadir al carrito,
- ver/editar carrito,
- guía hacia checkout/pago.

También define librerías compartidas para acciones de producto y carrito.

### `trotamundos_assistant_documents`

Submódulo documental. Aporta:

- configuración de reglas de extracción de texto desde Media PDF,
- creación de campos técnicos de texto extraído,
- cola de extracción,
- extractor PDF basado en `smalot/pdfparser`,
- reindexación de entidades referenciantes en Search API,
- skill `document_lookup` para responder con citas, documentos y referencias.

## Rutas

Experiencia pública:

```text
GET /home-ai
```

Endpoints AJAX:

```text
POST /trotamundos-assistant/chat
GET  /trotamundos-assistant/chat/history?thread_id={thread_id}
POST /trotamundos-assistant/chat/reset
GET  /trotamundos-assistant/skills/{machine_name}/{action}
POST /trotamundos-assistant/skills/{machine_name}/{action}
POST /trotamundos-assistant/cart/add
GET  /trotamundos-assistant/cart/panel
```

Administración del módulo base:

```text
/admin/config/services/trotamundos-assistant
/admin/config/services/trotamundos-assistant/flow
/admin/config/services/trotamundos-assistant/status
/admin/config/services/trotamundos-assistant/usage
/admin/config/services/trotamundos-assistant/statistics
/admin/config/services/trotamundos-assistant/usage/{conversation_id}
/admin/config/services/trotamundos-assistant/settings
/admin/config/services/trotamundos-assistant/skills
/admin/config/services/trotamundos-assistant/extensions
```

Administración documental:

```text
/admin/config/services/trotamundos-assistant/documents
/admin/config/services/trotamundos-assistant/documents/add
/admin/config/services/trotamundos-assistant/documents/rules/{rule_id}/delete
```

## Administración

La administración usa navegación por pastillas:

- `Flujo`: explica el flujo completo desde el mensaje de usuario hasta el render final.
- `Estado`: comprueba módulos, configuración, keys y tabla de uso.
- `Coste`: muestra uso, tokens, coste estimado, conversaciones y payloads.
- `Estadísticas`: vista agregada del uso registrado.
- `Configuración`: experiencia, assistant, roles, apariencia, límites y precios.
- `Skills`: activación/desactivación de capacidades descubiertas.
- `Extensiones`: visión de módulos/submódulos y capacidades disponibles.
- `Documentos`: reglas de extracción documental cuando el submódulo está activo.

## Configuración

Archivos:

```text
config/install/trotamundos_assistant.settings.yml
config/schema/trotamundos_assistant.schema.yml
```

Valores principales:

| Clave | Descripción | Valor por defecto |
| --- | --- | --- |
| `welcome_message` | Mensaje inicial del asistente. | `Bienvenido a tu nueva manera de navegar. ¿Qué necesitas?` |
| `input_placeholder` | Placeholder del campo donde el usuario escribe la consulta. | `Escribe tu consulta...` |
| `privacy_notice` | Aviso legal y privacidad mostrado junto al campo de consulta. | `Al enviar una consulta aceptas que se procese para generar la respuesta del asistente. No incluyas datos personales, confidenciales o de pago.` |
| `assistant_id` | Assistant de `ai_assistant_api` usado por el chat. | `minsait_buscador_nodes` |
| `ai_assistant.roles` | Roles configurados en el AI Assistant seleccionado que pueden usar el asistente. Si no hay roles seleccionados, se permite el acceso a todos. | — |
| `login_required_message` | Mensaje para usuarios anónimos sin acceso. | `Para usar el asistente de catálogo debes estar logado.` |
| `role_denied_chat_message` | Mensaje para usuarios autenticados sin rol permitido. | `No tienes permisos para usar este asistente.` |
| `use_minsait_font` | Carga la tipografía ForFuture Sans. | `true` |
| `result_target_selector` | Selector CSS del contenedor externo donde se pintan los resultados tras una acción del asistente. | `#trotamundos-assistant-result-panel` |
| `header_sticky` | Selector CSS opcional del header fijo o sticky usado para calcular automáticamente el offset superior del chat. | `header` |
| `extra_shift_selectors` | Selectores CSS adicionales que se desplazan al abrir el chat en escritorio. Se separan con `;`. | `footer` |
| `desktop_always_open` | Abre el chat automáticamente en escritorio. En móvil permanece como drawer cerrado. | `false` |
| `toggle_label` | Texto de la pestaña lateral que abre el chat. | `Trotamundos Assistant` |
| `chat_logo_path` | Ruta web del logo mostrado en la cabecera del chat. | `/modules/custom/trotamundos_assistant/assets/images/minsait-chatbot-logo-head.png` |
| `admin_currency` | Moneda visual para administración de coste. | `USD` |
| `enabled_skills` | Skills activas. | `default` |
| `max_message_length` | Longitud máxima de consulta. | `800` |
| `rate_limit_requests` | Número máximo de consultas por ventana. | `20` |
| `rate_limit_window` | Ventana temporal del rate limit, en segundos. | `3600` |
| `model_pricing` | Precios por millón de tokens para estimación de coste. | Tabla instalada |

La pantalla `Configuración` permite editar estos valores. La pantalla `Skills`
gestiona `enabled_skills`.

## Control De Acceso

El acceso al asistente no depende solo de `_permission: access content`.
`AssistantAccessManager` comprueba los roles configurados en el campo
`roles` del AI Assistant seleccionado en `assistant_id`. La pantalla de
configuración de Trotamundos Assistant muestra ese mismo campo en la sección
`Acceso y permisos`, pero no mantiene una copia propia.

Comportamiento:

- Si el usuario anónimo no está permitido, los endpoints devuelven `login_required_message`.
- Si el usuario autenticado no tiene rol permitido, los endpoints devuelven `role_denied_chat_message`.
- Aunque el acceso esté denegado, el formulario de chat permanece disponible y muestra ese mensaje como respuesta del asistente.
- Las acciones de skills pasan también por `AssistantAccessManager`.
- Las acciones `POST` que declaran `csrf: true` validan `X-CSRF-Token`.

Permisos administrativos:

```text
administer trotamundos assistant settings
view trotamundos assistant usage
administer trotamundos assistant documents
```

## Bloque `trotamundos_assistant`

Plugin:

```text
src/Plugin/Block/TrotamundosAssistantBlock.php
```

Templates:

```text
templates/trotamundos-assistant.html.twig
templates/trotamundos-assistant-welcome.html.twig
```

El bloque renderiza el chat, la pestaña lateral y una plantilla de bienvenida
disponible para resultados. El contenido visual se pinta en el contenedor
externo indicado por `result_target_selector`.

Comportamiento del contenedor externo:

- debe existir en la página y no debe envolver al bloque del asistente,
- conserva su contenido original al cargar la página,
- se reemplaza por `result_html` cuando el usuario ejecuta una acción del asistente,
- se restaura a su contenido original al pulsar `Nuevo chat`,
- si no existe, el chat muestra un error de configuración y deshabilita controles.

La página `/home-ai` crea el contenedor `#trotamundos-assistant-result-panel`. En
páginas normales puede usarse un selector existente, por ejemplo el contenedor
principal del contenido.

El chat es un drawer fijo a la izquierda. En escritorio puede abrirse por defecto
con `desktop_always_open`; en móvil se mantiene cerrado hasta que el usuario
pulsa la pestaña. Si `header_sticky` apunta a un header visible, el frontend mide
su borde inferior y coloca el chat justo debajo.

En escritorio, cuando el chat se abre, el módulo calcula cuánto debe desplazar el
contenido comparando el borde del chat con el primer hijo directo del panel de
resultado. Si el panel no tiene hijos directos, usa el propio panel. Los
elementos configurados en `extra_shift_selectors` reciben el mismo
desplazamiento.

El bloque adjunta:

- `trotamundos_assistant/assistant`,
- `trotamundos_assistant/font` si `use_minsait_font` está activo,
- `drupalSettings.trotamundosAssistant`.

Las librerías específicas de cada skill se adjuntan cuando Drupal renderiza el
resultado de esa skill.

Settings expuestos al frontend:

- `endpoint`,
- `historyEndpoint`,
- `resetEndpoint`,
- `welcomeMessage`,
- `accessAllowed`,
- `resultTargetSelector`,
- `desktopAlwaysOpen`,
- `toggleLabel`.

## Contrato De Respuesta IA

La IA debe devolver una respuesta JSON estructurada:

```json
{
  "skill": "product_recommendations",
  "chat_reply": "Te dejo recomendaciones en el panel.",
  "result": {
    "intro": "Estas opciones encajan con lo que buscas.",
    "items": [
      {
        "product_id": 123,
        "variation_id": 456,
        "title": "Producto recomendado",
        "body": "Motivo breve de recomendación."
      }
    ],
    "outro": "Puedes pedirme que compare dos opciones."
  }
}
```

Uso de cada campo:

- `skill`: referencia de la skill seleccionada.
- `chat_reply`: texto breve guardado y mostrado en el chat lateral.
- `result`: datos que Drupal transforma en variables Twig.

El JSON completo se guarda aparte en tempstore. El historial visible conserva
solo el mensaje original del usuario y la respuesta breve del asistente. Al
cargar una página, el frontend recupera los mensajes del chat si hay
`thread_id`, pero mantiene intacto el contenido inicial del contenedor externo.

Si el proveedor devuelve una respuesta vacía, el controller genera un fallback
local con la skill `default`.

## Skills

Las skills no son entidades Drupal. Se descubren desde el sistema de archivos en
cualquier módulo habilitado que tenga una carpeta `skills/`.

Estructura mínima:

```text
skills/{machine_name}/
skills/{machine_name}/skill.yml
skills/{machine_name}/example.json
skills/{machine_name}/{machine_name}.html.twig
skills/{machine_name}/src/{SkillHandler}.php
```

Assets opcionales:

```text
skills/{machine_name}/css/{machine_name}.css
skills/{machine_name}/js/{machine_name}.js
```

`SkillDiscovery` valida:

- directorio,
- `skill.yml`,
- `example.json`,
- template Twig,
- `machine_name`,
- dependencias de módulos.

`SkillMatcher` marca una skill como activa solo si:

- está incluida en `enabled_skills`,
- sus dependencias están satisfechas.

Cada `skill.yml` puede declarar `btn_responsive_title`. Si tiene valor, el
frontend añade un botón con ese texto al último mensaje del asistente en
responsive. El botón cierra el drawer del chat para mostrar el panel de resultado
completo.

El bloque `provider` debe indicar si la skill necesita recuperar información:

```yaml
provider:
  requires_rag: true
  intent: '...'
  when_to_use:
    - '...'
```

`requires_rag: false` permite resolver la petición en una sola llamada sin
consultar el índice. Si la propiedad no existe, se asume `true` para conservar
el comportamiento seguro de skills de terceros.

`SkillTemplateRenderer`:

- genera un catálogo compacto de enrutado para la primera llamada,
- después de RAG envía solo el contrato de la skill seleccionada,
- parsea la respuesta estructurada,
- resuelve el handler,
- renderiza Twig en aislamiento,
- devuelve HTML y comandos AJAX para assets adjuntos.

La tool `trotamundos_assistant:skill_rag_search` añade a la búsqueda el identificador
exacto de la skill. Así, la siguiente iteración no vuelve a recibir todos los
ejemplos y contratos. Si una respuesta selecciona una skill con RAG sin haber
ejecutado la tool, el controller hace un único reintento protegido con RAG
obligatorio.

## Skills Incluidas

### Base Drupal

| Skill | Descripción |
| --- | --- |
| `default` | Respuesta general de reserva. |
| `content_create` | Abre formularios de creación de entidades de contenido Drupal. |
| `content_edit` | Busca contenido por título, tipo o ID y muestra formulario de edición si hay permisos. |
| `user_profile` | Muestra el formulario de edición del perfil del usuario actual. |

### Ecommerce

| Skill | Descripción |
| --- | --- |
| `clarification` | Pide datos adicionales cuando la petición de producto es ambigua. |
| `product_results` | Lista productos encontrados a partir de búsqueda o filtros. |
| `product_summary` | Resume atributos e información de un producto concreto. |
| `product_recommendations` | Recomienda productos según necesidades o contexto. |
| `event_product_selection` | Prepara una selección completa de productos para un evento, agrupada por categorías. |
| `product_alternatives` | Muestra sustitutos o productos similares. |
| `product_comparison` | Compara dos o más productos. |
| `shopping_cart_add` | Prepara la confirmación para añadir productos al carrito. |
| `shopping_cart_view` | Muestra, actualiza o elimina productos del carrito actual. |
| `payment` | Guía hacia revisión de carrito y checkout. |

`product_common` no es una skill conversacional seleccionable: funciona como
librería compartida para acciones de producto.

### Documentos

| Skill | Descripción |
| --- | --- |
| `document_lookup` | Responde usando texto extraído de documentos, con citas, documentos y entidades referenciadas. |

## Acciones AJAX Por Skill

Las skills pueden declarar acciones en `skill.yml`:

```yaml
actions:
  add_to_cart:
    class: 'Drupal\...\ShoppingCartAddActionHandler'
    file: 'src/ShoppingCartAddActionHandler.php'
    method: addToCart
    http_method: POST
    route: trotamundos_assistant.skill_action
    csrf: true
    arguments:
      - entity_type.manager
      - commerce_cart.cart_manager
```

`SkillActionDispatcher` valida:

- acceso al asistente,
- skill activa,
- acción declarada,
- método HTTP (`GET` o `POST`),
- CSRF si aplica,
- clase/servicio handler,
- servicios de constructor.

Acciones destacadas:

- `shopping_cart_add:add_to_cart`,
- `shopping_cart_view:panel`,
- `shopping_cart_view:update_item`,
- `shopping_cart_view:remove_item`.

## Flujo De Chat

```text
Usuario envía mensaje
  -> JS llama a POST /trotamundos-assistant/chat
  -> Controller valida acceso, CSRF, mensaje, longitud y rate limit
  -> Se prepara o genera thread_id
  -> Se carga el assistant configurado
  -> SkillMatcher carga solo skills activas y disponibles
  -> SkillTemplateRenderer añade catálogo de skills y ejemplos al mensaje
  -> UsageLogger abre la interacción con call_id e iteration_number
  -> ai_assistant_api.runner ejecuta el assistant/agente
  -> AiUsageSubscriber registra llamadas chat/embeddings/excepciones
  -> El proveedor devuelve JSON con skill, chat_reply y result
  -> SkillMatcher valida la skill seleccionada
  -> SkillTemplateRenderer renderiza la template Twig
  -> Controller guarda chat_reply y payload completo por separado
  -> JSON AJAX devuelve reply, thread_id, usage, result_html y comandos AJAX
  -> JS actualiza chat, sessionStorage y contenedor externo de resultado
```

Respuesta AJAX:

```json
{
  "reply": "Te dejo recomendaciones en el panel.",
  "thread_id": "trotamundos_assistant_thread_1_...",
  "call_id": "...",
  "usage": {
    "provider_calls": 2,
    "input_tokens": 776,
    "output_tokens": 168,
    "total_tokens": 944,
    "cached_tokens": 0,
    "reasoning_tokens": 0,
    "total_cost_usd": "0.0002172000"
  },
  "skill": "product_recommendations",
  "machine_name": "product_recommendations",
  "result_html": "<article>...</article>",
  "result_ajax_commands": []
}
```

## Persistencia

El identificador de hilo tiene formato:

```text
trotamundos_assistant_thread_{uid}_{uuid32}
```

Persistencia:

```text
/chat devuelve thread_id
  -> JS guarda thread_id en sessionStorage
  -> Al recargar una página con el bloque, JS llama a /chat/history
  -> Controller lee tempstore del assistant
  -> Se restauran mensajes visibles sin reemplazar el contenido inicial de la página
```

El historial conversacional no se guarda como entidad propia. Se apoya en la
sesión/tempstore del assistant.

## Uso, Coste Y Auditoría

Tabla:

```text
trotamundos_assistant_usage
```

Cada fila representa una llamada real al proveedor IA. Una interacción del
usuario puede generar varias filas si el agente usa herramientas, embeddings o
RAG.

Campos principales:

- Identidad: `id`, `call_id`, `thread_id`, `iteration_number`, `uid`.
- Proveedor: `assistant_id`, `provider_request_id`, `provider_id`, `model_id`, `operation_type`.
- Tokens: `input_tokens`, `output_tokens`, `total_tokens`, `cached_tokens`, `reasoning_tokens`.
- Coste: `input_cost_usd`, `output_cost_usd`, `cached_cost_usd`, `total_cost_usd`, `currency`, `pricing_source`.
- Auditoría: `request_text`, `response_text`, `provider_request_raw`, `provider_response_raw`.

`AiUsageSubscriber` escucha:

- `PostGenerateResponseEvent`,
- `PostStreamingResponseEvent`,
- `AiExceptionEvent`.

La pantalla `Coste` permite revisar conversaciones, llamadas internas, tokens,
coste estimado y payloads. La pantalla `Estadísticas` ofrece visión agregada.

## RAG De Catálogo

Processor:

```text
src/Plugin/search_api/processor/CatalogDocument.php
```

ID:

```text
trotamundos_assistant_catalog_document
```

El processor añade campos enriquecidos para Search API/AI Search:

- `text_for_embedding`,
- `catalog_title`,
- `document_id`,
- `variation_document_id`,
- `variation_id`,
- `product_id`,
- `sku`,
- `category`,
- `brand`,
- `price`,
- `catalog_status`.

También excluye del indexado productos del bundle `deposit`.

Drupal Commerce sigue siendo la fuente de verdad para precio, imagen, URL,
publicación, favoritos y carrito. La capa vectorial aporta recuperación
semántica para el assistant.

### Qdrant local en DDEV

El proyecto incluye un servicio Qdrant con almacenamiento persistente en un
volumen Docker y sin puertos publicados en el host. Está protegido con una
clave local porque DDEV comparte una red entre proyectos. Es una configuración
de desarrollo, no un despliegue de producción.

En una copia nueva, antes de arrancar DDEV, genera la clave una sola vez:

```bash
test -e .ddev/qdrant.local.key || (umask 077; openssl rand -hex 32 > .ddev/qdrant.local.key)
ddev start
```

El archivo está fuera del directorio web y está excluido de Git. No lo
sobrescribas mientras el servicio esté en uso. Para conectar Drupal:

- Crea la key `qdrant_local` con proveedor `File`, ruta
  `/var/www/html/.ddev/qdrant.local.key` y eliminación de saltos de línea.
- Configura el proveedor Qdrant con host `qdrant`, puerto `6333` y esa key.
- Crea la key `openai` con proveedor `Environment` y variable
  `OPENAI_API_KEY`, y selecciónala en el proveedor OpenAI.
- Define una credencial OpenAI nueva en el entorno privado de DDEV, por ejemplo
  en `.ddev/config.local.yaml`, y reinicia el proyecto para que PHP la lea.
  No guardes credenciales en el repositorio ni las pegues en documentación.

La integración usa `ai_vdb_provider_qdrant` y el módulo independiente
`ai_search` 1.3.0-alpha6. Ambos deben evaluarse antes de un uso en producción.
El modelo de embeddings previsto es `text-embedding-3-small` (1536 dimensiones).
El assistant, el agente y el índice deben configurarse y verificarse por
separado; disponer de Qdrant no significa que el contenido ya esté indexado.
Antes de indexar, limita el contenido a datos publicados y autorizados y revisa
los campos que se enviarán al proveedor externo.

### Despliegues heredados con pgvector

Esta sección describe la configuración anterior y no se aplica al Qdrant
local de este proyecto.

El provider `ai_vdb_provider_postgres` mapea `collection` a una tabla de
Postgres y las búsquedas vectoriales no filtran por `server_id` ni `index_id`.
La tabla es, por tanto, el único límite de aislamiento entre sites.

Convención obligatoria: cada site usa `collection: <site_id>_products` en
`config/<site>/sync/search_api.server.minsait_nodes_ai.yml`. Actualmente
`minsait_products`, `indra_products` y `eroski_products` sobre la misma base
`ddev_embedding_db`.

Al dar de alta un site nuevo hay que cambiar esa clave junto con
`config_sync_directory` y `file_public_path` en su `settings.php`. Después del
primer `drush cim` la tabla no existe todavía: `createCollection()` solo se
ejecuta al guardar el formulario del servidor, así que hay que entrar una vez
en `/admin/config/search/search-api/server/minsait_nodes_ai/edit` y guardar.

Aviso: `drush search-api:clear` sobre este backend hace `DROP TABLE` +
`CREATE TABLE` de la collection completa, no un borrado por índice. Si dos
sites comparten collection, vaciar uno destruye el índice del otro.

## Documentos

El submódulo `trotamundos_assistant_documents` permite definir reglas de extracción
en:

```text
/admin/config/services/trotamundos-assistant/documents
```

Una regla define:

- tipo de Media,
- campo file del Media,
- proceso de extracción (`pdf`),
- entidad/bundle que referencia ese Media,
- campo Media origen,
- campo técnico destino,
- longitud máxima del texto.

Al guardar una regla activa:

- se crea el campo técnico `text_long` en el bundle de Media si no existe,
- se oculta el campo en formularios/vistas,
- se encola la extracción cuando cambian referencias o ficheros,
- se procesa con `trotamundos_assistant_documents_extract`,
- se reindexan en Search API las entidades que referencian el Media.

Extractor PDF:

```text
Drupal\trotamundos_assistant_documents\Service\PdfTextExtractor
```

## Frontend Y Assets

Librerías base:

```text
trotamundos_assistant/brand
trotamundos_assistant/font
trotamundos_assistant/assistant
trotamundos_assistant/template.base
trotamundos_assistant/usage_admin
```

Archivos principales:

```text
assets/js/trotamundos-assistant.js
assets/js/trotamundos-assistant-usage.js
assets/css/trotamundos-assistant.css
assets/css/trotamundos-assistant-usage.css
assets/css/trotamundos-assistant-brand.css
assets/css/trotamundos-assistant-font.css
skills/default/css/template_base.css
```

El módulo genera librerías dinámicas para skills del módulo base mediante
`hook_library_info_build()`.

El submódulo ecommerce genera sus propias librerías dinámicas con
`trotamundos_assistant_ecommerce_library_info_build()`.

El frontend:

- ajusta la altura disponible del drawer y del contenedor externo,
- calcula el offset superior del chat desde `header_sticky`,
- restaura mensajes de historial desde `sessionStorage`,
- envía mensajes,
- pinta loaders y mensajes,
- aplica comandos AJAX devueltos por Drupal,
- reemplaza el contenedor externo con `result_html` solo tras acciones explícitas,
- restaura el contenido original del contenedor externo al iniciar un nuevo chat,
- ejecuta acciones de skills.

## Dependencias

El módulo base requiere:

- `ai_assistant_api`,
- `ai_search`,
- `text`,
- `user`,
- `key`,
- `search_api`.

La pantalla `Estado` valida:

- módulos AI/Search/Key, OpenAI y Qdrant,
- configuración de assistant,
- configuración de agent,
- índice AI Search,
- índice Search API,
- servidor Search API,
- key `openai` y la key configurada en el proveedor Qdrant,
- tabla `trotamundos_assistant_usage`.

El submódulo ecommerce depende de `trotamundos_assistant` y sus skills declaran
dependencias Commerce por skill.

El submódulo documents depende de:

- `file`,
- `field`,
- `media`,
- `text`,
- `trotamundos_assistant`.

## Instalación Y Mantenimiento

Comandos habituales:

```bash
ddev drush updb -y
ddev drush cr
```

Verificar la disponibilidad de las keys sin mostrar sus valores:

```bash
ddev drush ev '$key = \Drupal::service("key.repository")->getKey("openai"); echo $key && $key->getKeyValue() ? "openai ok" : "openai missing";'
ddev drush ev '$key = \Drupal::service("key.repository")->getKey("qdrant_local"); echo $key && $key->getKeyValue() ? "qdrant ok" : "qdrant missing";'
```

Validación de calidad del proyecto:

```bash
ddev exec vendor/bin/grumphp git:pre-commit --skip-success-output
```

## Tests

Tests incluidos:

```text
tests/dependency-status.php
```

Ejecutar la comprobación de estado con Qdrant local configurado:

```bash
ddev drush php:script modules/custom/trotamundos_assistant/tests/dependency-status.php
```

## Evolución Recomendada

- Entidad persistente de conversación.
- Filtros avanzados de coste por fecha, modelo, usuario y thread.
- Validación JSON por schema específico de cada skill.
- Más tests funcionales para UI completa de `/home-ai`.
- Exportación de informes de uso.
- Nuevos extractores documentales además de PDF.
- Nuevas skills verticales por cliente o dominio.
