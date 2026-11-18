<?php

declare(strict_types=1);

namespace Drupal\trotamundos_commerce\Setup;

use Drupal\node\Entity\Node;
use Drupal\trotamundos_experiences\Setup\ProductTypes;

/**
 * Creates identifiable demo plans and refreshes untouched generated text.
 */
final class DemoCatalog {

  /**
   * Destination, style, nights, price, titles and summaries in both languages.
   */
  private const PLANS = [
    'alojamiento' => [
      ['Concepción', 'Pueblos con alma', 1, 240000, 'Posada de los balcones en Concepción', 'Balcony guesthouse in Concepción',
        'Despierta entre balcones y regálate tiempo para descubrir el alma de Concepción.', 'Wake up among balconies and take your time discovering the spirit of Concepción.'],
      ['Nariño', 'Naturaleza', 2, 390000, 'Cabaña junto al bosque de Nariño', 'Forest cabin in Nariño',
        'Una pausa junto al bosque para respirar hondo y disfrutar del verde de Nariño.', 'A pause by the forest to breathe deeply and enjoy the greenery of Nariño.'],
      ['Cocora', 'Café y tradición', 1, 280000, 'Estancia cafetera entre montañas', 'Coffee-country mountain stay',
        'El paisaje cafetero y las montañas ponen el ritmo a una estancia sin prisas.', 'Coffee-country landscapes and mountains set the pace for an unhurried stay.'],
      ['Santa Rosa', 'Bienestar', 2, 470000, 'Refugio de descanso en Santa Rosa', 'Restful retreat in Santa Rosa',
        'Un refugio para bajar el ritmo y regalarte un descanso en Santa Rosa.', 'A retreat in Santa Rosa for slowing down and giving yourself a proper rest.'],
      ['Rosario', 'Naturaleza', 2, 650000, 'Ecoestancia de brisa caribeña', 'Caribbean-breeze eco stay',
        'Déjate acompañar por la brisa caribeña y la calma de las islas.', 'Let the Caribbean breeze and the calm of the islands keep you company.'],
      ['Concepción', 'Cultura viva', 1, 210000, 'Casa de patios y memorias', 'Courtyard and memories house',
        'Patios y recuerdos para sentir de cerca la vida cotidiana de Concepción.', 'Courtyards and memories bring you closer to everyday life in Concepción.'],
      ['Cocora', 'Naturaleza', 2, 420000, 'Mirador de las palmas de cera', 'Wax-palm lookout stay',
        'Las palmas de cera y las montañas inspiran una escapada para mirar con calma.', 'Wax palms and mountains inspire a getaway made for taking in the view.'],
      ['Nariño', 'Bienestar', 1, 310000, 'Alojamiento de río y silencio', 'River and silence accommodation',
        'El sonido del río y el silencio son una invitación a desconectar.', 'The sound of the river and the quiet invite you to switch off.'],
      ['Santa Rosa', 'Café y tradición', 1, 260000, 'Hospedaje de aromas cafeteros', 'Coffee-aroma guesthouse',
        'Para amantes del café que disfrutan de los pequeños rituales y del descanso.', 'For coffee lovers who enjoy small rituals and taking time to rest.'],
      ['Concepción', 'Gastronomía', 2, 380000, 'Casa de cocina campesina', 'Country-kitchen house',
        'Una estancia para acercarte a los sabores y al calor de la cocina campesina.', 'A stay inspired by the flavours and warmth of a country kitchen.'],
      ['Rosario', 'Bienestar', 3, 890000, 'Retiro de amaneceres junto al mar', 'Seaside sunrise retreat',
        'Deja que los amaneceres y el horizonte caribeño marquen tus días de descanso.', 'Let Caribbean sunrises and horizons set the rhythm of your days off.'],
      ['Cocora', 'Aventura', 1, 230000, 'Base de caminantes del Cocora', 'Cocora walkers lodge',
        'Tu punto de partida para disfrutar a tu ritmo de los caminos del Cocora.', 'Your starting point for enjoying the paths of Cocora at your own pace.'],
    ],
    'experiencia' => [
      ['Concepción', 'Pueblos con alma', 0, 95000, 'Concepción a través de sus balcones', 'Concepción through its balconies',
        'Mira hacia arriba y descubre las historias que guarda cada balcón del pueblo.', 'Look up and discover the stories held in the village balconies.'],
      ['Cocora', 'Café y tradición', 0, 135000, 'Del grano a la taza en el Eje Cafetero', 'From bean to cup in coffee country',
        'Una mirada cercana al recorrido del café, desde su origen hasta la taza.', 'A closer look at the journey of coffee, from its origins to the cup.'],
      ['Nariño', 'Naturaleza', 0, 120000, 'Lectura del bosque junto al río', 'Discovering the riverside forest',
        'Acércate al bosque y al río con la curiosidad de quien aprende a mirar de nuevo.', 'Explore the forest and river with the curiosity of seeing them anew.'],
      ['Concepción', 'Gastronomía', 0, 150000, 'Sabores de una cocina campesina', 'Flavours of a country kitchen',
        'Sabores de campo para conocer una tradición que también se comparte en la mesa.', 'Country flavours introduce a tradition shared around the table.'],
      ['Cocora', 'Aventura', 0, 180000, 'Senderos entre palmas de cera', 'Trails among wax palms',
        'Camina entre palmas de cera y déjate sorprender por el paisaje del Cocora.', 'Walk among wax palms and let the Cocora landscape surprise you.'],
      ['Santa Rosa', 'Bienestar', 0, 165000, 'Pausa de bienestar en Santa Rosa', 'A wellness pause in Santa Rosa',
        'Una pausa para escucharte, respirar y volver a tu propio ritmo.', 'A moment to listen to yourself, breathe and find your own rhythm again.'],
      ['Concepción', 'Cultura viva', 0, 110000, 'Oficios e historias del pueblo', 'Village crafts and stories',
        'Un acercamiento a los oficios y las historias que mantienen vivo el pueblo.', 'Discover the crafts and stories that keep the village spirit alive.'],
      ['Rosario', 'Naturaleza', 0, 190000, 'Miradas al paisaje del Caribe', 'Discovering Caribbean landscapes',
        'Mira el Caribe con calma y descubre los matices de su paisaje.', 'Take your time to notice the many colours of the Caribbean landscape.'],
      ['Santa Rosa', 'Gastronomía', 0, 145000, 'Mesa y tradición de Risaralda', 'Food and tradition in Risaralda',
        'La mesa es la excusa perfecta para acercarte a la tradición de Risaralda.', 'The table is the perfect place to get closer to the traditions of Risaralda.'],
      ['Nariño', 'Aventura', 0, 175000, 'Caminata por las montañas de Nariño', 'Walking the mountains of Nariño',
        'Ponte en camino y disfruta de las montañas de Nariño paso a paso.', 'Set out and enjoy the mountains of Nariño one step at a time.'],
      ['Cocora', 'Cultura viva', 0, 105000, 'Historias de caminos cafeteros', 'Stories from coffee-country paths',
        'Historias y caminos para sentir de cerca la memoria cafetera.', 'Stories and paths bring the heritage of coffee country a little closer.'],
      ['Rosario', 'Bienestar', 0, 155000, 'Atardecer consciente frente al mar', 'A mindful sunset by the sea',
        'Un momento frente al mar para respirar hondo y despedir el día sin prisa.', 'A moment by the sea to breathe deeply and see the day out at your own pace.'],
    ],
    'paquete' => [
      ['Concepción', 'Cultura viva', 2, 690000, 'Tres días entre patios e historias', 'Three days of courtyards and stories',
        'Tres días para dejarte llevar por los patios y las historias de Concepción.', 'Three days to get carried away by the courtyards and stories of Concepción.'],
      ['Cocora', 'Café y tradición', 2, 790000, 'Escapada de café y palmas de cera', 'Coffee and wax-palm getaway',
        'El café y las palmas de cera se encuentran en una escapada de montaña.', 'Coffee and wax palms come together on a mountain getaway.'],
      ['Nariño', 'Naturaleza', 3, 980000, 'Cuatro días de bosque y río', 'Four days of forest and river',
        'Cuatro días para cambiar el ruido por los paisajes de bosque y río.', 'Four days to trade the noise for landscapes of forest and river.'],
      ['Santa Rosa', 'Bienestar', 2, 850000, 'Fin de semana para bajar el ritmo', 'A weekend to slow down',
        'Un fin de semana para soltar el reloj y darte el descanso que mereces.', 'A weekend to put the clock aside and give yourself the rest you deserve.'],
      ['Rosario', 'Naturaleza', 3, 1350000, 'Caribe sin prisa en cuatro días', 'Four unhurried days in the Caribbean',
        'Cuatro días para disfrutar del Caribe a tu manera y sin mirar el reloj.', 'Four days to enjoy the Caribbean your way, without watching the clock.'],
      ['Concepción', 'Gastronomía', 1, 490000, 'Dos días de cocina y vida de pueblo', 'Two days of cooking and village life',
        'Dos días para saborear el pueblo y acercarte a su cocina cotidiana.', 'Two days to savour village life and get closer to its everyday cooking.'],
      ['Cocora', 'Aventura', 3, 1120000, 'Travesía de senderos cafeteros', 'Coffee-country trail journey',
        'Para quienes disfrutan descubriendo el paisaje cafetero a pie.', 'For those who enjoy discovering the coffee-country landscape on foot.'],
      ['Nariño', 'Bienestar', 2, 760000, 'Escapada de agua, descanso y montaña', 'Water, rest and mountains getaway',
        'Agua y montaña como compañía para una escapada de descanso.', 'Water and mountains keep you company on a restful getaway.'],
      ['Santa Rosa', 'Café y tradición', 3, 1080000, 'Risaralda entre café y naturaleza', 'Coffee and nature in Risaralda',
        'Café y naturaleza para descubrir Risaralda con todos los sentidos.', 'Coffee and nature invite you to discover Risaralda with all your senses.'],
      ['Rosario', 'Bienestar', 4, 1790000, 'Cinco días de horizontes caribeños', 'Five days of Caribbean horizons',
        'Cinco días para dejar que el Caribe te regale horizonte y tranquilidad.', 'Five days to let Caribbean horizons bring you a little peace.'],
      ['Concepción', 'Pueblos con alma', 2, 720000, 'Concepción para compartir en familia', 'Sharing Concepción with the family',
        'Un viaje para disfrutar de Concepción juntos y guardar recuerdos en familia.', 'A trip to enjoy Concepción together and make memories as a family.'],
      ['Cocora', 'Naturaleza', 4, 1490000, 'Cinco días entre montañas y palmares', 'Five days among mountains and palms',
        'Cinco días para perder la mirada entre montañas y palmas de cera.', 'Five days to let your gaze wander among mountains and wax palms.'],
    ],
  ];

  /**
   * Returns the original and updated text for each generated field.
   */
  private static function messageReplacements(string $place, string $style, string $title, string $english_title): array {
    return [
      'es' => [
        'body' => [
          $title . "\n\nUn itinerario de muestra inspirado en " . mb_strtolower($style) . '. La propuesta combina tiempo para explorar ' . $place . " y encuentros de proximidad.\n\nContenido DEMO: alojamiento, actividades, anfitriones, precios y fechas deben confirmarse antes de ofrecer este plan como un servicio real.",
          $title . "\n\nUna propuesta inspirada en " . mb_strtolower($style) . ' para explorar ' . $place . ' y descubrir su vida local.',
        ],
        'field_precio_nota' => [
          'Precio DEMO por persona; no constituye una oferta confirmada.',
          'Precio por persona sujeto a confirmación.',
        ],
        'field_disponibilidad' => [
          'Calendario de demostración, sin cupos reales confirmados.',
          'Disponibilidad pendiente de confirmación.',
        ],
        'field_incluye' => [
          'Propuesta de itinerario de demostración',
          'Propuesta de itinerario',
        ],
        'field_politica_reserva' => [
          'Ficha DEMO. No confirma disponibilidad ni una reserva. Antes de vender, verificar servicios, tarifa, condiciones y pasarela de pago.',
          'La disponibilidad y la reserva requieren confirmación. Antes de cualquier venta, el equipo debe verificar alojamiento, actividades, anfitriones, precios, fechas, condiciones y pasarela de pago.',
        ],
        'field_galeria' => [
          'Ambientación del catálogo demo; no representa un establecimiento confirmado.',
          'Imagen de ambientación; no representa un establecimiento confirmado.',
        ],
      ],
      'en' => [
        'body' => [
          $english_title . "\n\nA sample itinerary with time to explore and discover local life.\n\nDEMO content: accommodation, activities, hosts, prices and dates require confirmation before this plan can be offered as a real service.",
          $english_title . "\n\nAn itinerary with time to explore and discover local life.",
        ],
        'field_precio_nota' => [
          'DEMO price per person; not a confirmed offer.',
          'Price per person, subject to confirmation.',
        ],
        'field_disponibilidad' => [
          'Demonstration calendar; no confirmed availability.',
          'Availability is subject to confirmation.',
        ],
        'field_incluye' => [
          'Demonstration itinerary',
          'Itinerary proposal',
        ],
        'field_politica_reserva' => [
          'DEMO listing. It does not confirm availability or a booking. Verify services, prices, terms and payment configuration before selling.',
          'Availability and booking require confirmation. Before any sale, the team must verify accommodation, activities, hosts, prices, dates, terms and the payment gateway.',
        ],
        'field_galeria' => [
          'Demo catalog illustration; not a confirmed property.',
          'Illustrative image; not a confirmed property.',
        ],
      ],
    ];
  }

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
      foreach ($definitions as $index => [$place, $style, $nights, $price, $title, $english_title, $summary, $english_summary]) {
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
        $text = self::messageReplacements($place, $style, $title, $english_title);
        $photos = [];
        for ($offset = 0; $offset < min(4, count($gallery)); $offset++) {
          $photo = $gallery[($index * 3 + $offset) % count($gallery)];
          $photo['alt'] = $text['es']['field_galeria'][1];
          $photos[] = $photo;
        }
        $duration = $nights ? ($nights + 1) . ' días · ' . $nights . ($nights === 1 ? ' noche' : ' noches') : 'Medio día';
        $node = Node::create([
          'type' => $bundle, 'title' => $title, 'langcode' => 'es', 'uid' => 1, 'status' => 1,
          'field_resumen' => $summary,
          'body' => ['value' => $text['es']['body'][1], 'format' => 'plain_text'],
          'field_destino' => reset($matches)->id(),
          'field_estilo' => [$styles[$style]],
          'field_acompanamiento' => [['pareja', 'familia'], ['grupo', 'solo'], ['familia', 'grupo']][$index % 3],
          'field_precio' => $price, 'field_precio_nota' => $text['es']['field_precio_nota'][1],
          'field_duracion' => $duration, 'field_noches' => $nights, 'field_capacidad' => [6, 8, 10, 12][$index % 4],
          'field_galeria' => $photos,
          'field_fechas' => [['value' => $today->modify('+1 day')->format('Y-m-d'), 'end_value' => $today->modify('+1 year')->format('Y-m-d')]],
          'field_disponibilidad' => $text['es']['field_disponibilidad'][1],
          'field_incluye' => [$text['es']['field_incluye'][1], 'Acompañamiento local pendiente de confirmar'],
          'field_no_incluye' => ['Transportes no indicados', 'Servicios adicionales no confirmados'],
          'field_recomendaciones' => ['Confirmar condiciones y disponibilidad con el equipo.', 'Llevar calzado cómodo y protección para el clima.'],
          'field_politica_reserva' => ['value' => $text['es']['field_politica_reserva'][1], 'format' => 'plain_text'],
          'field_politica_cancelacion' => ['value' => 'Las condiciones de cancelación deben acordarse con el proveedor antes de confirmar una reserva real.', 'format' => 'plain_text'],
          'field_ejemplo' => TRUE, 'field_destacada' => FALSE,
        ]);
        if (\Drupal::languageManager()->getLanguage('en')) {
          $english_photos = array_map(static function (array $photo) use ($text): array {
            $photo['alt'] = $text['en']['field_galeria'][1];
            return $photo;
          }, $photos);
          $node->addTranslation('en', [
            'title' => $english_title,
            'field_resumen' => $english_summary,
            'body' => ['value' => $text['en']['body'][1], 'format' => 'plain_text'],
            'field_galeria' => $english_photos,
            'field_duracion' => $nights ? ($nights + 1) . ' days · ' . $nights . ($nights === 1 ? ' night' : ' nights') : 'Half day',
            'field_precio_nota' => $text['en']['field_precio_nota'][1],
            'field_disponibilidad' => $text['en']['field_disponibilidad'][1],
            'field_incluye' => [$text['en']['field_incluye'][1], 'Local accompaniment to be confirmed'],
            'field_no_incluye' => ['Unlisted transport', 'Unconfirmed additional services'],
            'field_recomendaciones' => ['Confirm conditions and availability with the team.', 'Bring comfortable footwear and weather protection.'],
            'field_politica_reserva' => ['value' => $text['en']['field_politica_reserva'][1], 'format' => 'plain_text'],
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

  /**
   * Refreshes only unedited generated summaries on recorded demo plans.
   */
  public static function refreshDefaultSummaries(): int {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ledger = \Drupal::keyValue('trotamundos_commerce.demo_plans');
    $updated = 0;
    foreach (self::PLANS as $bundle => $definitions) {
      foreach ($definitions as $index => [$place, , , , $title, $english_title, $summary, $english_summary]) {
        $uuid = $ledger->get($bundle . ':' . $index);
        $nodes = $uuid ? $storage->loadByProperties(['uuid' => $uuid]) : [];
        if (!$nodes) {
          continue;
        }
        $node = reset($nodes);
        if ($node->bundle() !== $bundle || !$node->get('field_ejemplo')->value
          || (int) $storage->getLatestRevisionId($node->id()) !== (int) $node->getRevisionId()) {
          continue;
        }
        $translations = [
          'es' => [$title, $title . ': una propuesta de demostración para descubrir ' . $place . ' con calma y mirada local.', $summary],
          'en' => [$english_title, $english_title . ': a demonstration itinerary with a slow pace and a local perspective.', $english_summary],
        ];
        $changed = FALSE;
        foreach ($translations as $langcode => [$expected_title, $original_summary, $new_summary]) {
          if (!$node->hasTranslation($langcode)) {
            continue;
          }
          $translation = $node->getTranslation($langcode);
          if ($translation->label() === $expected_title && $translation->get('field_resumen')->value === $original_summary) {
            $translation->set('field_resumen', $new_summary);
            $changed = TRUE;
          }
        }
        if ($changed) {
          $node->setNewRevision(TRUE);
          $node->setRevisionLogMessage('Actualización de resúmenes de demostración.');
          $node->save();
          $updated++;
        }
      }
    }
    return $updated;
  }

  /**
   * Refreshes recognized generated text, preserving edits and pending drafts.
   *
   * Run explicitly inside a transaction after backing up the affected content.
   */
  public static function refreshDefaultMessages(): int {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ledger = \Drupal::keyValue('trotamundos_commerce.demo_plans');
    $updated = 0;
    foreach (self::PLANS as $bundle => $definitions) {
      foreach ($definitions as $index => [$place, $style, , , $title, $english_title]) {
        $uuid = $ledger->get($bundle . ':' . $index);
        $nodes = $uuid ? $storage->loadByProperties(['uuid' => $uuid]) : [];
        if (!$nodes) {
          continue;
        }
        $node = reset($nodes);
        if ($node->bundle() !== $bundle || !$node->isPublished() || !$node->get('field_ejemplo')->value
          || (int) $storage->getLatestRevisionId($node->id()) !== (int) $node->getRevisionId()) {
          continue;
        }
        $replacements = self::messageReplacements($place, $style, $title, $english_title);
        foreach (array_keys($replacements['es']) as $field) {
          $definition = $node->getFieldDefinition($field);
          if (!$definition || !$definition->isTranslatable()) {
            throw new \LogicException('A generated text field is missing or not translatable: ' . $field);
          }
        }
        $changed = FALSE;
        foreach ($replacements as $langcode => $fields) {
          if (!$node->hasTranslation($langcode)) {
            continue;
          }
          $translation = $node->getTranslation($langcode);
          foreach ($fields as $field => [$original, $replacement]) {
            if ($field === 'body' && $translation->label() !== ($langcode === 'es' ? $title : $english_title)) {
              continue;
            }
            $items = $translation->get($field)->getValue();
            $property = $field === 'field_galeria' ? 'alt' : 'value';
            $field_changed = FALSE;
            foreach ($items as &$item) {
              if (($item[$property] ?? NULL) === $original
                && (!array_key_exists('format', $item) || $item['format'] === 'plain_text')) {
                $item[$property] = $replacement;
                $field_changed = TRUE;
              }
            }
            unset($item);
            if ($field_changed) {
              $translation->set($field, $items);
              $changed = TRUE;
            }
          }
        }
        if ($changed) {
          $violations = $node->validate();
          if ($violations->count()) {
            throw new \RuntimeException('Invalid updated demo plan ' . $node->id() . ': ' . (string) $violations);
          }
          $node->setNewRevision(TRUE);
          $node->setRevisionLogMessage('Actualización de textos generados.');
          $node->save();
          $updated++;
        }
      }
    }
    return $updated;
  }

}
