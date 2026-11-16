# Trotamundos 4

Tema independiente para Drupal 11 con dirección de atlas: navegación lateral en escritorio, tipografía condensada, mapa interactivo, búsqueda local y fichas de lugares. Conserva los cinco colores originales.

Las fichas son inspiraciones editoriales. No representan disponibilidad, precios ni reservas confirmadas.

## Activar

```sh
ddev drush theme:enable trotamundos_4 -y
ddev drush config:set system.theme default trotamundos_4 -y
ddev drush cr
```

Para recuperar otra propuesta, usar `trotamundos`, `trotamundos_2` o `trotamundos_3` en `config:set` y reconstruir la caché.

## Identidad y fuentes

`css/tokens.css` y `brand.yml` definen la identidad. `/guia-de-marca` muestra la guía del tema activo y permite descargar su PDF y kit. Las fuentes, fotografías y geometría del mapa son locales; las autorías y licencias están en `assets/CREDITS.txt`.

El mapa selecciona entre tres puntos de inspiración. El catálogo permite combinar nombre o región y categoría, con un estado vacío y restablecimiento. Los detalles usan diálogos nativos. Todo el sitio adapta su navegación a móvil.
