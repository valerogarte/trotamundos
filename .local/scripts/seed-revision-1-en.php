<?php

/**
 * @file
 * English translations of the demo content (first client revision).
 *
 * Run after seed-revision-1.php:
 * ddev drush php:script .local/scripts/seed-revision-1-en.php
 */

$entity_type_manager = \Drupal::entityTypeManager();
$node_storage = $entity_type_manager->getStorage('node');
$term_storage = $entity_type_manager->getStorage('taxonomy_term');

$translate = static function ($entity, array $values): void {
  $translation = $entity->hasTranslation('en') ? $entity->getTranslation('en') : $entity->addTranslation('en');
  foreach ($values as $field => $value) {
    $translation->set($field, $value);
  }
  // Untranslated fields such as "promote" keep the source values.
  foreach (['uid', 'promote', 'sticky'] as $field) {
    if ($translation->hasField($field)) {
      $translation->set($field, $entity->getUntranslated()->get($field)->getValue());
    }
  }
  $translation->set('status', 1);
  // The alias generator fills /en/experiences/... and /en/destinations/...
  $translation->save();
};

// Terms.
$terms = [
  'tipo_plan' => ['Alojamiento' => 'Stay', 'Paquete' => 'Package', 'Pasadía' => 'Day trip', 'Recorrido guiado' => 'Guided tour'],
  'estilo_viaje' => ['Pueblos con alma' => 'Villages with soul', 'Naturaleza' => 'Nature', 'Bienestar' => 'Wellness', 'Aventura' => 'Adventure', 'Cultura y tradición' => 'Culture and tradition', 'Gastronomía' => 'Food and drink'],
];
foreach ($terms as $vid => $names) {
  foreach ($names as $es => $en) {
    foreach ($term_storage->loadByProperties(['vid' => $vid, 'name' => $es]) as $term) {
      if ($term->hasTranslation('en')) {
        $term->getTranslation('en')->setName($en);
        $term->save();
      }
      else {
        $term->addTranslation('en', ['name' => $en])->save();
      }
      echo "Term $es → $en\n";
    }
  }
}

$html = static fn (string $value): array => ['value' => $value, 'format' => 'basic_html'];

// Destinations.
$destinations = [
  'Concepción, Antioquia' => [
    'title' => 'Concepción, Antioquia',
    'body' => [
      'value' => '<p>Concepción is one of the most traditional towns in eastern Antioquia: cobbled streets, wattle-and-daub houses with colourful balconies and a church that lights up at nightfall. It is the birthplace of General José María Córdova.</p><p>Around the town there are rural paths, streams and crystal-clear natural pools, and a community that welcomes visitors with coffee and conversation.</p>',
      'summary' => 'Cobbled streets, colourful balconies, streams and a community that welcomes visitors with coffee and conversation.',
      'format' => 'basic_html',
    ],
    'alt' => 'Cobbled street with white façades and colourful balconies in Concepción.',
  ],
  'Nariño, Antioquia' => [
    'title' => 'Nariño, Antioquia',
    'body' => [
      'value' => '<p>Nariño lies in eastern Antioquia, in an area of mountains, rivers and humid forest. Between the river and the jungle are the pools of Termales Espíritu Santo, with rock tunnels, trails and waterfalls you discover on foot.</p>',
      'summary' => 'Mountains, rivers, humid forest and the pools of Termales Espíritu Santo.',
      'format' => 'basic_html',
    ],
    'alt' => 'The river winding through the jungle, seen from the air.',
  ],
  '[DEMO] Santa Rosa de Cabal, Risaralda' => [
    'title' => '[DEMO] Santa Rosa de Cabal, Risaralda',
    'body' => [
      'value' => '<p>Santa Rosa de Cabal, in Risaralda, is known for its hot springs, waterfalls and coffee-growing landscape.</p><p>Sample page: experiences in this destination are coming soon.</p>',
      'summary' => 'Hot springs, waterfalls and coffee landscape in Risaralda.',
      'format' => 'basic_html',
    ],
  ],
  '[DEMO] Valle del Cocora, Quindío' => [
    'title' => '[DEMO] Cocora Valley, Quindío',
    'body' => [
      'value' => '<p>The Cocora Valley, in the countryside around Salento, is home to the tall wax palms, Colombia’s national tree, among mist and mountain paths.</p><p>Sample page: experiences in this destination are coming soon.</p>',
      'summary' => 'Wax palms, mist and mountain paths in the countryside around Salento.',
      'format' => 'basic_html',
    ],
  ],
  '[DEMO] Islas del Rosario, Bolívar' => [
    'title' => '[DEMO] Rosario Islands, Bolívar',
    'body' => [
      'value' => '<p>The Rosario Islands, off the coast of Cartagena, bring together turquoise water, mangroves and small islands in the Colombian Caribbean.</p><p>Sample page: experiences in this destination are coming soon.</p>',
      'summary' => 'Turquoise water, mangroves and small islands off Cartagena.',
      'format' => 'basic_html',
    ],
  ],
];
foreach ($destinations as $title => $values) {
  $node = current($node_storage->loadByProperties(['type' => 'destino', 'title' => $title]));
  if (!$node) {
    echo "Missing destination $title\n";
    continue;
  }
  $fields = ['title' => $values['title'], 'body' => $values['body']];
  if (isset($values['alt']) && !$node->get('field_image')->isEmpty()) {
    $image = $node->get('field_image')->first()->getValue();
    $image['alt'] = $values['alt'];
    $fields['field_image'] = $image;
  }
  $translate($node, $fields);
  echo "Destination {$values['title']} → " . $node->getTranslation('en')->toUrl()->toString() . "\n";
}

$alt = [
  'termales-aerea-complejo' => 'Aerial view of Termales Espíritu Santo: pools, cabins and river in the jungle.',
  'termales-poza-cueva' => 'Hot spring pool under a rock vault, with the forest behind.',
  'termales-pozas-rio' => 'Hot spring pools by the river, surrounded by palms and mountains.',
  'termales-restaurante' => 'Wooden restaurant open to the garden at Termales Espíritu Santo.',
  'termales-corredor' => 'Wooden porch overlooking the vegetation.',
  'termales-bano-termal' => 'A traveller enjoys the hot water by the river.',
  'termales-poza-piedra' => 'Stone pool on the riverbank, under the trees.',
  'termales-tunel' => 'Trail through a rock tunnel towards the forest.',
  'termales-desayuno' => 'Breakfast served on a wooden terrace facing the mountains.',
  'termales-selva-aerea' => 'The river winding through the jungle, seen from the air.',
  'termales-aerea-vertical' => 'Overhead view of the hot spring pools and the river.',
  'termales-poza-mural' => 'Golden hot spring pool next to a wall with a painted message.',
  'termales-poza-montana' => 'Hot spring pool with the mountain and forest behind.',
  'termales-tunel-luz' => 'The end of a rock tunnel opening onto the green valley.',
  'termales-rio' => 'The river flowing between rocks and vegetation near the pools.',
  'termales-cascada' => 'Multi-tiered waterfall in the middle of the tropical forest.',
  'termales-cascada-piedras' => 'Waterfall tumbling between large rocks and ferns.',
  'termales-cascada-alta' => 'A walker in front of a tall waterfall among the vegetation.',
  'termales-boveda' => 'A traveller in a hot spring pool under a rock vault.',
  'termales-manantial' => 'Hot spring water flowing over the rock, under the trees.',
  'concepcion-casa-jardin' => 'Farmhouse with red doors and a garden of tropical plants.',
  'concepcion-corredor' => 'Wooden porch with colourful railings, open to the forest.',
  'concepcion-habitacion' => 'Bedroom with a wooden bed, a woven bedspread and antique furniture.',
  'concepcion-sala-antiguedades' => 'Room full of antiques and paintings, with coffee on the table.',
  'concepcion-cena' => 'Dinner served on a wooden table among flowers and old objects.',
  'concepcion-casita' => 'Small white house with green doors at the end of a wooden path.',
  'concepcion-jardin-flores' => 'Yellow flowers in the garden of a house among trees.',
  'concepcion-vecina-geranios' => 'A resident of Concepción on the porch of her house, among geraniums.',
  'concepcion-carro-antiguo' => 'A vintage car among the garden plants.',
  'concepcion-cafe-balcon' => 'A cup of coffee on a balcony overlooking the mountains of Antioquia.',
  'concepcion-panoramica' => 'View of the town of Concepción among the mountains.',
  'concepcion-calle' => 'Cobbled street with white façades and colourful balconies in Concepción.',
  'concepcion-iglesia-noche' => 'Juan Trotamundos in front of the church of Concepción, lit up at night.',
  'concepcion-calle-noche' => 'A street in Concepción at night, with the illuminated church behind.',
  'concepcion-balcon' => 'Wooden balcony with green railings overlooking the town.',
  'concepcion-piedra-quebrada' => 'Two travellers on a large rock by a stream and a stone bridge.',
  'concepcion-quebrada' => 'A stream winding through green pastures.',
  'concepcion-charco' => 'Natural pool of crystal-clear water in the middle of the forest.',
  'juan-trotamundos' => 'Juan Trotamundos sitting at the door of a farmhouse in Concepción.',
  'concepcion-corredor-descanso' => 'A traveller resting on the porch of a farmhouse.',
];

$translated_images = static function ($items) use ($alt): array {
  $values = [];
  foreach ($items as $item) {
    $value = $item->getValue();
    $name = pathinfo((string) $item->entity?->getFilename(), PATHINFO_FILENAME);
    if (isset($alt[$name])) {
      $value['alt'] = $alt[$name];
    }
    $values[] = $value;
  }
  return $values;
};

$booking = '<p>Book at least 3 days in advance. We confirm availability with the hosts within 24 hours and send you a secure payment link.</p><p>Your booking is confirmed with a 50% payment (or 100% if the trip is less than 7 days away). You will receive the confirmation and a practical travel guide by email and WhatsApp.</p>';
$cancellation = '<p>Free cancellation up to 7 days before arrival. Between 7 and 3 days before, we refund 50%. With less than 3 days’ notice there is no refund, but you can change the date once, subject to availability.</p><p>If the hosts cancel because of the weather or force majeure, we refund 100% or find another date with you.</p>';

$termales_story = '<p>In the mountains of Nariño, hot water rises from the earth and meets the river. Around the pools there are rock tunnels, forest trails and waterfalls you discover on foot.</p><p>It is a place to bathe in the open air, listen to the river and get to know a part of eastern Antioquia that few travellers visit yet.</p>';
$termales_host = '<p>A local team looks after the pools, the kitchen and the trails. They know every corner of the canyon and will tell you which waterfall to visit depending on the weather.</p><p>When you book with Trotamundos you directly support this local business and the families who work in it.</p>';
$termales_route = '<p>Termales Espíritu Santo is in the municipality of Nariño, in eastern Antioquia, and is reached by road from Medellín. Once your booking is confirmed we send you the route, the transport options and the exact arrival point.</p>';
$termales_tips = [
  'Bring a swimsuit, sandals and a quick-dry towel.',
  'Use insect repellent and sunscreen: you are in the middle of the humid forest.',
  'Shoes with good grip for the trails and waterfalls.',
  'Light clothes for the day and a jacket for the evening.',
  'Drink water between hot spring baths.',
  'If you are pregnant or have heart problems, check with your doctor first.',
];
$concepcion_story = '<p>Concepción is one of the most traditional towns in eastern Antioquia: cobbled streets, wattle-and-daub houses with colourful balconies and a church that lights up at nightfall. It is the birthplace of General José María Córdova.</p><p>Its people keep recipes, crafts and the habit of welcoming newcomers as if they were family.</p>';
$concepcion_host = '<p>The house is looked after by a local family who have filled its porches with flowers, antiques and memories. They will welcome you with coffee and tell you about the corners that are not in the guidebooks.</p><p>Your stay supports this family and the small businesses in town we work with.</p>';
$concepcion_route = '<p>Concepción is in eastern Antioquia and is reached by road from Medellín. The house is a few minutes’ walk from the main square. Once your booking is confirmed we send you the exact location and the transport options.</p>';
$concepcion_tips = [
  'Comfortable shoes for the cobbled streets and rural paths.',
  'A light jacket: it gets cool at night.',
  'Some cash for small shops and cafés.',
  'Ask before photographing people.',
  'Leave free time: many plans start with a chat in the square.',
];

$experiences = [
  'Noche en Termales Espíritu Santo' => [
    'title' => 'A night at Termales Espíritu Santo',
    'field_resumen' => 'A night by the river with access to hot spring pools, rock tunnels and a traditional breakfast in eastern Antioquia.',
    'body' => $html('<p>You arrive in the afternoon, drop off your bags and head down to the pools: open-air hot springs with the river and the jungle all around. Walk through the rock tunnels, rest on the wooden porches and go back into the water as night falls.</p><p>The next morning a traditional breakfast awaits, with free time for one last soak before you continue your journey.</p>'),
    'field_duracion' => '1 night',
    'field_incluye' => ['One night in a double room with private bathroom', 'Traditional breakfast', 'Access to the hot spring pools during your stay', 'Trotamundos support on WhatsApp before and during the trip'],
    'field_no_incluye' => ['Transport to the hot springs', 'Lunches, dinners and drinks', 'Travel insurance'],
    'field_historia_destino' => $html($termales_story),
    'field_anfitrion_nombre' => 'The Termales Espíritu Santo team',
    'field_anfitrion_historia' => $html($termales_host),
    'field_recomendaciones' => $termales_tips,
    'field_como_llegar' => $html($termales_route),
    'field_disponibilidad' => 'Every day, subject to availability.',
  ],
  'Escapada de dos noches a Termales Espíritu Santo' => [
    'title' => 'Two-night getaway to Termales Espíritu Santo',
    'field_resumen' => 'Two nights with full board, hot spring baths and a guided walk to the canyon’s waterfalls.',
    'body' => $html('<h3>Day 1 · Arrival and first soak</h3><p>Check in, have lunch and spend the afternoon in the pools and on the nearby trails.</p><h3>Day 2 · Walk to the waterfalls</h3><p>With a local guide you walk through the forest to the canyon’s waterfalls. After lunch, a free afternoon for the hot springs.</p><h3>Day 3 · Farewell</h3><p>Breakfast, one last soak and the journey home.</p>'),
    'field_duracion' => '3 days · 2 nights',
    'field_incluye' => ['Two nights in a double room with private bathroom', 'Full board: breakfasts, lunches and dinners', 'Access to the hot spring pools', 'Guided walk to the waterfalls with a local guide', 'Welcome video and a Trotamundos gift', 'Support on WhatsApp before and during the trip'],
    'field_no_incluye' => ['Transport to the hot springs', 'Drinks and tips', 'Travel insurance'],
    'field_historia_destino' => $html($termales_story),
    'field_anfitrion_nombre' => 'The Termales Espíritu Santo team',
    'field_anfitrion_historia' => $html($termales_host),
    'field_recomendaciones' => $termales_tips,
    'field_como_llegar' => $html($termales_route),
    'field_disponibilidad' => 'Departures every Friday and on public holiday weekends.',
  ],
  'Noche en una casa campesina de Concepción' => [
    'title' => 'A night in a farmhouse in Concepción',
    'field_resumen' => 'Sleep in a farmhouse full of flowers and antiques, a few minutes from the centre of one of Antioquia’s most traditional towns.',
    'body' => $html('<p>A traditional farmhouse with wooden porches, a garden and rooms full of history, a few minutes’ walk from the main square.</p><p>It is the perfect base to explore the town, have a coffee in the square, watch the church light up at dusk and enjoy a home-cooked dinner.</p>'),
    'field_duracion' => '1 night',
    'field_incluye' => ['One night in a double room in a traditional farmhouse', 'Home-made breakfast', 'Local coffee on arrival', 'Juan’s tips for exploring the town', 'Support on WhatsApp before and during the trip'],
    'field_no_incluye' => ['Transport to Concepción', 'Lunch and dinner', 'Guided tours'],
    'field_historia_destino' => $html($concepcion_story),
    'field_anfitrion_nombre' => 'A host family in Concepción',
    'field_anfitrion_historia' => $html($concepcion_host),
    'field_recomendaciones' => $concepcion_tips,
    'field_como_llegar' => $html($concepcion_route),
    'field_disponibilidad' => 'Every day, subject to availability.',
  ],
  'Concepción: pueblo, historia y naturaleza en dos días' => [
    'title' => 'Concepción: town, history and nature in two days',
    'field_resumen' => 'Explore the town with a local guide, share a meal with a host family and swim in a crystal-clear natural pool.',
    'body' => $html('<h3>Day 1 · The town and its people</h3><p>You arrive at the house, meet the host family and set off with a local guide to explore the cobbled streets, the square and the church. In the evening, a home-cooked dinner at the house.</p><h3>Day 2 · Water and mountains</h3><p>After breakfast, a walk along rural paths to a natural pool for a swim in crystal-clear water. Back in town by midday.</p>'),
    'field_duracion' => '2 days · 1 night',
    'field_incluye' => ['One night in a traditional farmhouse', 'Home-made breakfast and dinner', 'Walking tour of the town with a local guide', 'Walk to a natural pool in the area', 'Welcome video and a Trotamundos gift', 'Support on WhatsApp before and during the trip'],
    'field_no_incluye' => ['Transport from Medellín', 'Lunches and drinks', 'Travel insurance'],
    'field_historia_destino' => $html($concepcion_story),
    'field_anfitrion_nombre' => 'A host family in Concepción',
    'field_anfitrion_historia' => $html($concepcion_host),
    'field_recomendaciones' => $concepcion_tips,
    'field_como_llegar' => $html($concepcion_route),
    'field_disponibilidad' => 'Departures on Saturdays, from January 2027.',
  ],
];

foreach ($experiences as $title => $values) {
  $node = current($node_storage->loadByProperties(['type' => 'experiencia', 'title' => $title]));
  if (!$node) {
    echo "Missing experience $title\n";
    continue;
  }
  $values += [
    'field_precio_nota' => 'per person, in a double room',
    'field_politica_reserva' => $html($booking),
    'field_politica_cancelacion' => $html($cancellation),
    'field_galeria' => $translated_images($node->get('field_galeria')),
    'field_anfitrion_foto' => $translated_images($node->get('field_anfitrion_foto')),
  ];
  $translate($node, $values);
  echo "Experience {$values['title']} → " . $node->getTranslation('en')->toUrl()->toString() . "\n";
}
