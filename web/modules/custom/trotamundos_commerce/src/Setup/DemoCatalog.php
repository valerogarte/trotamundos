<?php

declare(strict_types=1);

namespace Drupal\trotamundos_commerce\Setup;

use Drupal\node\Entity\Node;
use Drupal\trotamundos_experiences\Setup\ProductTypes;

/**
 * Adds identifiable demo plans without replacing or rewriting existing content.
 */
final class DemoCatalog {

  /**
   * Destination, style, nights, price, Spanish title and English title.
   */
  private const PLANS = [
    'alojamiento' => [
      ['Concepción', 'Pueblos con alma', 1, 240000, 'Posada de los balcones en Concepción', 'Balcony guesthouse in Concepción'],
      ['Nariño', 'Naturaleza', 2, 390000, 'Cabaña junto al bosque de Nariño', 'Forest cabin in Nariño'],
      ['Cocora', 'Café y tradición', 1, 280000, 'Estancia cafetera entre montañas', 'Coffee-country mountain stay'],
      ['Santa Rosa', 'Bienestar', 2, 470000, 'Refugio de descanso en Santa Rosa', 'Restful retreat in Santa Rosa'],
      ['Rosario', 'Naturaleza', 2, 650000, 'Ecoestancia de brisa caribeña', 'Caribbean-breeze eco stay'],
      ['Concepción', 'Cultura viva', 1, 210000, 'Casa de patios y memorias', 'Courtyard and memories house'],
      ['Cocora', 'Naturaleza', 2, 420000, 'Mirador de las palmas de cera', 'Wax-palm lookout stay'],
      ['Nariño', 'Bienestar', 1, 310000, 'Alojamiento de río y silencio', 'River and silence accommodation'],
      ['Santa Rosa', 'Café y tradición', 1, 260000, 'Hospedaje de aromas cafeteros', 'Coffee-aroma guesthouse'],
      ['Concepción', 'Gastronomía', 2, 380000, 'Casa de cocina campesina', 'Country-kitchen house'],
      ['Rosario', 'Bienestar', 3, 890000, 'Retiro de amaneceres junto al mar', 'Seaside sunrise retreat'],
      ['Cocora', 'Aventura', 1, 230000, 'Base de caminantes del Cocora', 'Cocora walkers lodge'],
    ],
    'experiencia' => [
      ['Concepción', 'Pueblos con alma', 0, 95000, 'Concepción a través de sus balcones', 'Concepción through its balconies'],
      ['Cocora', 'Café y tradición', 0, 135000, 'Del grano a la taza en el Eje Cafetero', 'From bean to cup in coffee country'],
      ['Nariño', 'Naturaleza', 0, 120000, 'Lectura del bosque junto al río', 'Discovering the riverside forest'],
      ['Concepción', 'Gastronomía', 0, 150000, 'Sabores de una cocina campesina', 'Flavours of a country kitchen'],
      ['Cocora', 'Aventura', 0, 180000, 'Senderos entre palmas de cera', 'Trails among wax palms'],
      ['Santa Rosa', 'Bienestar', 0, 165000, 'Pausa de bienestar en Santa Rosa', 'A wellness pause in Santa Rosa'],
      ['Concepción', 'Cultura viva', 0, 110000, 'Oficios e historias del pueblo', 'Village crafts and stories'],
      ['Rosario', 'Naturaleza', 0, 190000, 'Miradas al paisaje del Caribe', 'Discovering Caribbean landscapes'],
      ['Santa Rosa', 'Gastronomía', 0, 145000, 'Mesa y tradición de Risaralda', 'Food and tradition in Risaralda'],
      ['Nariño', 'Aventura', 0, 175000, 'Caminata por las montañas de Nariño', 'Walking the mountains of Nariño'],
      ['Cocora', 'Cultura viva', 0, 105000, 'Historias de caminos cafeteros', 'Stories from coffee-country paths'],
      ['Rosario', 'Bienestar', 0, 155000, 'Atardecer consciente frente al mar', 'A mindful sunset by the sea'],
    ],
    'paquete' => [
      ['Concepción', 'Cultura viva', 2, 690000, 'Tres días entre patios e historias', 'Three days of courtyards and stories'],
      ['Cocora', 'Café y tradición', 2, 790000, 'Escapada de café y palmas de cera', 'Coffee and wax-palm getaway'],
      ['Nariño', 'Naturaleza', 3, 980000, 'Cuatro días de bosque y río', 'Four days of forest and river'],
      ['Santa Rosa', 'Bienestar', 2, 850000, 'Fin de semana para bajar el ritmo', 'A weekend to slow down'],
      ['Rosario', 'Naturaleza', 3, 1350000, 'Caribe sin prisa en cuatro días', 'Four unhurried days in the Caribbean'],
      ['Concepción', 'Gastronomía', 1, 490000, 'Dos días de cocina y vida de pueblo', 'Two days of cooking and village life'],
      ['Cocora', 'Aventura', 3, 1120000, 'Travesía de senderos cafeteros', 'Coffee-country trail journey'],
      ['Nariño', 'Bienestar', 2, 760000, 'Escapada de agua, descanso y montaña', 'Water, rest and mountains getaway'],
      ['Santa Rosa', 'Café y tradición', 3, 1080000, 'Risaralda entre café y naturaleza', 'Coffee and nature in Risaralda'],
      ['Rosario', 'Bienestar', 4, 1790000, 'Cinco días de horizontes caribeños', 'Five days of Caribbean horizons'],
      ['Concepción', 'Pueblos con alma', 2, 720000, 'Concepción para compartir en familia', 'Sharing Concepción with the family'],
      ['Cocora', 'Naturaleza', 4, 1490000, 'Cinco días entre montañas y palmares', 'Five days among mountains and palms'],
    ],
  ];

  public static function ensure(int $minimum = 12): int {
    if ($minimum < 1 || $minimum > 12) {
      throw new \InvalidArgumentException('This demo collection supports between one and twelve plans per type.');
    }
    $manager = \Drupal::entityTypeManager();
    $storage = $manager->getStorage('node');
    $destinations = $storage->loadByProperties(['type' => 'destino', 'status' => 1]);
    $terms = $manager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'estilo_viaje']);
    $styles = [];
    foreach ($terms as $term) {
      $styles[$term->getName()] = $term->id();
    }
    $templates = $storage->loadByProperties(['type' => ProductTypes::BUNDLES]);
    $gallery = [];
    foreach ($templates as $template) {
      foreach ($template->get('field_galeria')->getValue() as $image) {
        $gallery[$image['target_id']] = $image;
      }
    }
    if (!$gallery) {
      throw new \RuntimeException('Demo plans require existing, authorized catalog photographs.');
    }
    $gallery = array_values($gallery);
    $ledger = \Drupal::keyValue('trotamundos_commerce.demo_plans');
    $today = new \DateTimeImmutable('today', new \DateTimeZone('America/Bogota'));
    $created = 0;
    foreach (self::PLANS as $bundle => $definitions) {
      $count = (int) $storage->getQuery()->accessCheck(FALSE)->condition('type', $bundle)->condition('status', 1)->count()->execute();
      foreach ($definitions as $index => [$place, $style, $nights, $price, $title, $english_title]) {
        if ($count >= $minimum) {
          break;
        }
        $key = $bundle . ':' . $index;
        if ($uuid = $ledger->get($key)) {
          if ($storage->loadByProperties(['uuid' => $uuid])) {
            continue;
          }
        }
        $matches = array_filter($destinations, static fn ($destination): bool => str_contains($destination->label(), $place));
        if (count($matches) !== 1 || !isset($styles[$style])) {
          throw new \RuntimeException('Review demo destination/style mapping: ' . $place . ' / ' . $style);
        }
        $photos = [];
        for ($offset = 0; $offset < min(4, count($gallery)); $offset++) {
          $photo = $gallery[($index * 3 + $offset) % count($gallery)];
          $photo['alt'] = 'Ambientación del catálogo demo; no representa un establecimiento confirmado.';
          $photos[] = $photo;
        }
        $duration = $nights ? ($nights + 1) . ' días · ' . $nights . ($nights === 1 ? ' noche' : ' noches') : 'Medio día';
        $node = Node::create([
          'type' => $bundle, 'title' => $title, 'langcode' => 'es', 'uid' => 1, 'status' => 1,
          'field_resumen' => $title . ': una propuesta de demostración para descubrir ' . $place . ' con calma y mirada local.',
          'body' => ['value' => $title . "\n\nUn itinerario de muestra inspirado en " . mb_strtolower($style) . '. La propuesta combina tiempo para explorar ' . $place . " y encuentros de proximidad.\n\nContenido DEMO: alojamiento, actividades, anfitriones, precios y fechas deben confirmarse antes de ofrecer este plan como un servicio real.", 'format' => 'plain_text'],
          'field_destino' => reset($matches)->id(),
          'field_estilo' => [$styles[$style]],
          'field_acompanamiento' => [['pareja', 'familia'], ['grupo', 'solo'], ['familia', 'grupo']][$index % 3],
          'field_precio' => $price, 'field_precio_nota' => 'Precio DEMO por persona; no constituye una oferta confirmada.',
          'field_duracion' => $duration, 'field_noches' => $nights, 'field_capacidad' => [6, 8, 10, 12][$index % 4],
          'field_galeria' => $photos,
          'field_fechas' => [['value' => $today->modify('+1 day')->format('Y-m-d'), 'end_value' => $today->modify('+1 year')->format('Y-m-d')]],
          'field_disponibilidad' => 'Calendario de demostración, sin cupos reales confirmados.',
          'field_incluye' => ['Propuesta de itinerario de demostración', 'Acompañamiento local pendiente de confirmar'],
          'field_no_incluye' => ['Transportes no indicados', 'Servicios adicionales no confirmados'],
          'field_recomendaciones' => ['Confirmar condiciones y disponibilidad con el equipo.', 'Llevar calzado cómodo y protección para el clima.'],
          'field_politica_reserva' => ['value' => 'Ficha DEMO. No confirma disponibilidad ni una reserva. Antes de vender, verificar servicios, tarifa, condiciones y pasarela de pago.', 'format' => 'plain_text'],
          'field_politica_cancelacion' => ['value' => 'Las condiciones de cancelación deben acordarse con el proveedor antes de confirmar una reserva real.', 'format' => 'plain_text'],
          'field_ejemplo' => TRUE, 'field_destacada' => FALSE,
        ]);
        if (\Drupal::languageManager()->getLanguage('en')) {
          $english_photos = array_map(static function (array $photo): array {
            $photo['alt'] = 'Demo catalog illustration; not a confirmed property.';
            return $photo;
          }, $photos);
          $node->addTranslation('en', [
            'title' => $english_title,
            'field_resumen' => $english_title . ': a demonstration itinerary with a slow pace and a local perspective.',
            'body' => ['value' => $english_title . "\n\nA sample itinerary with time to explore and discover local life.\n\nDEMO content: accommodation, activities, hosts, prices and dates require confirmation before this plan can be offered as a real service.", 'format' => 'plain_text'],
            'field_galeria' => $english_photos,
            'field_duracion' => $nights ? ($nights + 1) . ' days · ' . $nights . ($nights === 1 ? ' night' : ' nights') : 'Half day',
            'field_precio_nota' => 'DEMO price per person; not a confirmed offer.',
            'field_disponibilidad' => 'Demonstration calendar; no confirmed availability.',
            'field_incluye' => ['Demonstration itinerary', 'Local accompaniment to be confirmed'],
            'field_no_incluye' => ['Unlisted transport', 'Unconfirmed additional services'],
            'field_recomendaciones' => ['Confirm conditions and availability with the team.', 'Bring comfortable footwear and weather protection.'],
            'field_politica_reserva' => ['value' => 'DEMO listing. It does not confirm availability or a booking. Verify services, prices, terms and payment configuration before selling.', 'format' => 'plain_text'],
            'field_politica_cancelacion' => ['value' => 'Cancellation conditions must be agreed with the provider before a real booking is confirmed.', 'format' => 'plain_text'],
          ]);
        }
        $errors = $node->validate();
        if ($errors->count()) {
          throw new \RuntimeException('Invalid demo plan ' . $title . ': ' . (string) $errors);
        }
        $node->save();
        $ledger->set($key, $node->uuid());
        $count++;
        $created++;
      }
      if ($count < $minimum) {
        throw new \RuntimeException('Not enough published demo candidates for ' . $bundle . '; existing drafts were preserved.');
      }
    }
    return $created;
  }

}
