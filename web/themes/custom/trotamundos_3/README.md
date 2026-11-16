# Trotamundos 3

Tema independiente de Drupal 11. La paleta original se interpreta con una atmósfera inmersiva: fondos Selva y Carbón, titulares Fraunces, cuerpo Plus Jakarta Sans, navegación flotante y fotografía con bordes suaves.

Las fichas iniciales son inspiraciones. No representan ofertas con reserva, precio o disponibilidad confirmados.

## Activación

```sh
ddev drush theme:enable trotamundos_3 -y
ddev drush config:set system.theme default trotamundos_3 -y
ddev drush cr
```

Para recuperar otra propuesta, sustituir `trotamundos_3` por `trotamundos` o `trotamundos_2` en `config:set`, y reconstruir la caché.

## Identidad

Los colores y la tipografía están en `css/tokens.css`. La guía del tema activo se encuentra en `/guia-de-marca`, con su propio PDF y kit. Las fuentes y fotografías son locales; sus licencias están en `assets/CREDITS.txt`.

El menú mantiene su posición en la parte superior. El catálogo tiene filtros, los detalles usan diálogos nativos y las preguntas frecuentes se abren mediante controles HTML.
