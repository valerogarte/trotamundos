# Modelo de contenidos de Trotamundos

Esta receta de Drupal 11 crea cuatro tipos:

| Tipo | Campos principales |
| --- | --- |
| Destino | Cuerpo, imagen, municipio y departamento |
| Establecimiento | Cuerpo, imagen, destino relacionado, tipo, dirección y sitio web |
| Guía de viaje | Cuerpo, imagen y uno o varios destinos relacionados |
| Página básica | Cuerpo |

Las revisiones se activan en los cuatro tipos. Las fechas y la disponibilidad no se modelan como confirmadas; quedan pendientes de una fuente de reservas fiable.

Para aplicar la receta en otro sitio Drupal: ddev drush recipe:apply web/recipes/trotamundos_content_model

El sitio de desarrollo de Juan Trota Mundos ya tiene estos tipos en su configuración activa.
