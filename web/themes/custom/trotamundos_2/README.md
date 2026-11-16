# Trotamundos 2

Tema independiente para Drupal 11. Conserva los cinco colores originales y propone una identidad gráfica con Bricolage Grotesque, DM Sans, fotografía rectangular, tipografía expresiva y composiciones asimétricas.

Las fichas iniciales son inspiraciones editoriales. No representan productos con precio, disponibilidad ni reserva confirmada.

## Activar

Desde la raíz del proyecto:

```sh
ddev drush theme:enable trotamundos_2 -y
ddev drush config:set system.theme default trotamundos_2 -y
ddev drush cr
```

## Volver al diseño anterior

```sh
ddev drush config:set system.theme default trotamundos -y
ddev drush cr
```

## Identidad

- `css/tokens.css`: colores, fuentes, escala y espacios compartidos.
- `brand.yml`: instrucciones editoriales de la identidad.
- `/guia-de-marca`: guía del tema activo y descarga de su PDF.
- `assets/CREDITS.txt`: fuentes, fotografías y licencias.

La cabecera permanece arriba en escritorio y móvil. Las experiencias tienen filtros y diálogos accesibles; el menú móvil y las preguntas frecuentes funcionan con teclado.
