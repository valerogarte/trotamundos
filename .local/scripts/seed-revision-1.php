<?php

/**
 * @file
 * Demo content for the first client revision (16-11-2026).
 *
 * Run with: ddev drush php:script .local/scripts/seed-revision-1.php
 *
 * Creates the four pilot experiences (accommodation and package in Termales
 * Espíritu Santo and in Concepción) with the client's own photographs, and
 * completes the destinations with region and coordinates. Prices, dates,
 * hosts and policies are examples: every experience is flagged as such.
 * The script is idempotent: it updates nodes that already exist by title.
 */

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

$file_for = static function (string $name): int {
  $uri = 'public://experiencias/2026-11/' . $name . '.webp';
  $existing = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
  if ($existing) {
    return (int) reset($existing)->id();
  }
  $file = File::create(['uri' => $uri, 'uid' => 1, 'status' => 1, 'filename' => $name . '.webp']);
  $file->setPermanent();
  $file->save();
  return (int) $file->id();
};

$term = static function (string $vid, string $name) use ($term_storage): int {
  $terms = $term_storage->loadByProperties(['vid' => $vid, 'name' => $name]);
  if (!$terms) {
    throw new \RuntimeException("Falta el término $name en $vid.");
  }
  return (int) reset($terms)->id();
};

$find = static function (string $bundle, array $titles) use ($node_storage): ?NodeInterface {
  foreach ($titles as $title) {
    $nodes = $node_storage->loadByProperties(['type' => $bundle, 'title' => $title]);
    if ($nodes) {
      return reset($nodes);
    }
  }
  return NULL;
};

$alt = [
  'termales-aerea-complejo' => 'Vista aérea de Termales Espíritu Santo: pozas, cabañas y río entre la selva.',
  'termales-poza-cueva' => 'Poza de agua termal bajo una bóveda de roca, con el bosque al fondo.',
  'termales-pozas-rio' => 'Pozas termales junto al río, rodeadas de palmas y montaña.',
  'termales-restaurante' => 'Restaurante de madera abierto al jardín en Termales Espíritu Santo.',
  'termales-corredor' => 'Corredor de madera con vista a la vegetación.',
  'termales-bano-termal' => 'Un viajero disfruta del agua termal junto al río.',
  'termales-poza-piedra' => 'Poza de piedra a la orilla del río, bajo los árboles.',
  'termales-tunel' => 'Sendero que atraviesa un túnel de roca hacia el bosque.',
  'termales-desayuno' => 'Desayuno servido en una terraza de madera frente a la montaña.',
  'termales-selva-aerea' => 'El río abriéndose paso entre la selva, visto desde el aire.',
  'termales-aerea-vertical' => 'Vista cenital de las pozas termales y el río.',
  'termales-poza-mural' => 'Poza termal de agua dorada junto a un muro con un mensaje pintado.',
  'termales-poza-montana' => 'Poza termal con la montaña y el bosque al fondo.',
  'termales-tunel-luz' => 'Salida de un túnel de roca hacia el valle verde.',
  'termales-rio' => 'El río corre entre piedras y vegetación cerca de las pozas.',
  'termales-cascada' => 'Cascada de varios saltos en medio del bosque tropical.',
  'termales-cascada-piedras' => 'Cascada que cae entre grandes piedras y helechos.',
  'termales-cascada-alta' => 'Caminante frente a una cascada alta entre la vegetación.',
  'termales-boveda' => 'Viajero en una poza termal bajo una bóveda de roca.',
  'termales-manantial' => 'Manantial de agua termal que cae sobre la piedra, bajo los árboles.',
  'concepcion-casa-jardin' => 'Casa campesina de puertas rojas con un jardín de plantas tropicales.',
  'concepcion-corredor' => 'Corredor de madera con barandas de color, abierto al bosque.',
  'concepcion-habitacion' => 'Habitación con cama de madera, colcha tejida y muebles antiguos.',
  'concepcion-sala-antiguedades' => 'Sala llena de antigüedades y cuadros, con café sobre la mesa.',
  'concepcion-cena' => 'Cena servida sobre una mesa de madera, entre flores y objetos antiguos.',
  'concepcion-casita' => 'Pequeña casa blanca de puertas verdes al final de un camino de madera.',
  'concepcion-jardin-flores' => 'Flores amarillas en el jardín de una casa entre árboles.',
  'concepcion-vecina-geranios' => 'Una vecina de Concepción en el corredor de su casa, entre geranios.',
  'concepcion-carro-antiguo' => 'Un carro antiguo entre la vegetación del jardín.',
  'concepcion-cafe-balcon' => 'Taza de café en un balcón con vista a las montañas de Antioquia.',
  'concepcion-panoramica' => 'Vista del casco urbano de Concepción entre montañas.',
  'concepcion-calle' => 'Calle empedrada con fachadas blancas y balcones de colores en Concepción.',
  'concepcion-iglesia-noche' => 'Juan Trotamundos frente a la iglesia de Concepción, iluminada de noche.',
  'concepcion-calle-noche' => 'Calle de Concepción de noche, con la iglesia iluminada al fondo.',
  'concepcion-balcon' => 'Balcón de madera con barandas verdes que mira al pueblo.',
  'concepcion-piedra-quebrada' => 'Dos viajeros sobre una gran piedra junto a una quebrada y un puente de piedra.',
  'concepcion-quebrada' => 'Quebrada que serpentea entre potreros verdes.',
  'concepcion-charco' => 'Charco natural de agua cristalina en medio del bosque.',
  'juan-trotamundos' => 'Juan Trotamundos sentado a la puerta de una casa campesina en Concepción.',
  'concepcion-corredor-descanso' => 'Un viajero descansa en el corredor de una casa campesina.',
];

$images = static function (array $names) use ($file_for, $alt): array {
  return array_map(static fn (string $name): array => ['target_id' => $file_for($name), 'alt' => $alt[$name]], $names);
};

// Destinations: hierarchy, coordinates and real photographs for the pilot.
$destinations = [
  'concepcion' => [
    'titles' => ['[DEMO] Concepción, Antioquia', 'Concepción, Antioquia'],
    'values' => [
      'title' => 'Concepción, Antioquia',
      'field_municipio' => 'Concepción',
      'field_departamento' => 'Antioquia',
      'field_region' => 'andina',
      'field_latitud' => '6.395000',
      'field_longitud' => '-75.258333',
      'field_image' => $images(['concepcion-calle'])[0],
      'body' => [
        'value' => '<p>Concepción es uno de los pueblos más tradicionales del oriente antioqueño: calles empedradas, casas de bahareque con balcones de colores y una iglesia que se ilumina al caer la noche. Aquí nació el general José María Córdova.</p><p>Alrededor del pueblo hay veredas, quebradas y charcos de agua cristalina, y una comunidad que recibe al visitante con café y conversación.</p>',
        'summary' => 'Calles empedradas, balcones de colores, quebradas y una comunidad que recibe al visitante con café y conversación.',
        'format' => 'basic_html',
      ],
    ],
  ],
  'narino' => [
    'titles' => ['Nariño, Antioquia'],
    'values' => [
      'title' => 'Nariño, Antioquia',
      'field_municipio' => 'Nariño',
      'field_departamento' => 'Antioquia',
      'field_region' => 'andina',
      'field_latitud' => '5.610600',
      'field_longitud' => '-75.176100',
      'field_image' => $images(['termales-selva-aerea'])[0],
      'body' => [
        'value' => '<p>Nariño está en el oriente de Antioquia, en una zona de montaña, ríos y bosque húmedo. Entre el río y la selva están las pozas de Termales Espíritu Santo, con túneles de roca, senderos y cascadas que se descubren caminando.</p>',
        'summary' => 'Montaña, ríos, bosque húmedo y las pozas de Termales Espíritu Santo.',
        'format' => 'basic_html',
      ],
    ],
  ],
  'santa-rosa' => [
    'titles' => ['[DEMO] Santa Rosa de Cabal, Risaralda'],
    'values' => ['field_region' => 'andina', 'field_latitud' => '4.868100', 'field_longitud' => '-75.621400'],
  ],
  'cocora' => [
    'titles' => ['[DEMO] Valle del Cocora, Quindío'],
    'values' => ['field_region' => 'andina', 'field_latitud' => '4.636900', 'field_longitud' => '-75.486600'],
  ],
  'rosario' => [
    'titles' => ['[DEMO] Islas del Rosario, Bolívar'],
    'values' => ['field_region' => 'caribe', 'field_latitud' => '10.175000', 'field_longitud' => '-75.750000'],
  ],
];

$destination_ids = [];
foreach ($destinations as $key => $definition) {
  $node = $find('destino', $definition['titles']);
  if (!$node) {
    $node = Node::create(['type' => 'destino', 'langcode' => 'es', 'uid' => 1, 'status' => 1]);
  }
  foreach ($definition['values'] as $field => $value) {
    $node->set($field, $value);
  }
  $node->save();
  $destination_ids[$key] = (int) $node->id();
  echo "Destino {$node->label()} → {$node->toUrl()->toString()}\n";
}

$booking_policy = '<p>Reserva con al menos 3 días de anticipación. Confirmamos la disponibilidad con los anfitriones en menos de 24 horas y te enviamos un enlace de pago seguro.</p><p>La reserva queda confirmada con el pago del 50 % (o del 100 % si faltan menos de 7 días para el viaje). Recibirás por correo y WhatsApp la confirmación y una guía práctica del viaje.</p>';
$cancellation_policy = '<p>Cancelación gratuita hasta 7 días antes de la llegada. Entre 7 y 3 días antes, te devolvemos el 50 %. Con menos de 3 días no hay reembolso, pero puedes cambiar la fecha una vez, según disponibilidad.</p><p>Si los anfitriones cancelan por clima o fuerza mayor, te devolvemos el 100 % o buscamos otra fecha contigo.</p>';

$termales_story = '<p>En las montañas de Nariño, el agua sale caliente de la tierra y se encuentra con el río. Alrededor de las pozas hay túneles de roca, senderos entre el bosque y cascadas que se descubren caminando.</p><p>Es un lugar para bañarse al aire libre, escuchar el río y conocer una parte del oriente antioqueño que todavía pocos viajeros visitan.</p>';
$termales_host = '<p>Un equipo de la región cuida las pozas, la cocina y los senderos. Conocen cada rincón del cañón y te dirán qué cascada visitar según el clima del día.</p><p>Al reservar con Trotamundos apoyas directamente a este emprendimiento local y a las familias que trabajan en él.</p>';
$termales_route = '<p>Termales Espíritu Santo está en el municipio de Nariño, en el oriente de Antioquia. Se llega por carretera desde Medellín. Al confirmar la reserva te enviamos la ruta, las opciones de transporte y el punto exacto de llegada.</p>';
$termales_tips = [
  'Lleva vestido de baño, sandalias y una toalla de secado rápido.',
  'Usa repelente y bloqueador: estás en medio del bosque húmedo.',
  'Calzado con buen agarre para los senderos y las cascadas.',
  'Ropa ligera para el día y un abrigo para la noche.',
  'Bebe agua entre baños termales.',
  'Si estás embarazada o tienes problemas del corazón, consulta antes con tu médico.',
];

$concepcion_story = '<p>Concepción es uno de los pueblos más tradicionales del oriente antioqueño: calles empedradas, casas de bahareque con balcones de colores y una iglesia que se ilumina al caer la noche. Aquí nació el general José María Córdova.</p><p>Su gente conserva recetas, oficios y la costumbre de recibir a quien llega como si fuera de la familia.</p>';
$concepcion_host = '<p>La casa la cuida una familia del pueblo que ha llenado sus corredores de flores, antigüedades y recuerdos. Te recibirán con café y te contarán los rincones que no aparecen en las guías.</p><p>Tu estadía apoya a esta familia y a los pequeños negocios del pueblo con los que trabajamos.</p>';
$concepcion_route = '<p>Concepción está en el oriente de Antioquia y se llega por carretera desde Medellín. La casa está a pocos minutos a pie del parque principal. Al confirmar la reserva te enviamos la ubicación exacta y las opciones de transporte.</p>';
$concepcion_tips = [
  'Calzado cómodo para las calles empedradas y los caminos de las veredas.',
  'Un abrigo ligero: en la noche refresca.',
  'Algo de efectivo para las tiendas y cafés pequeños.',
  'Pregunta antes de fotografiar a las personas.',
  'Deja tiempo libre: muchos planes surgen de una conversación en la plaza.',
];

$experiences = [
  [
    'title' => 'Noche en Termales Espíritu Santo',
    'destino' => 'narino',
    'tipo' => 'Alojamiento',
    'estilos' => ['Bienestar', 'Naturaleza'],
    'resumen' => 'Una noche junto al río, con acceso a pozas de agua termal, túneles de roca y desayuno típico en el oriente antioqueño.',
    'body' => '<p>Llegas a media tarde, dejas la maleta y bajas a las pozas: agua termal al aire libre, con el río y la selva alrededor. Puedes recorrer los túneles de roca, descansar en los corredores de madera y volver al agua cuando cae la noche.</p><p>A la mañana siguiente te espera un desayuno típico y tiempo libre para un último baño antes de seguir tu camino.</p>',
    'precio' => 290000,
    'duracion' => '1 noche',
    'noches' => 1,
    'capacidad' => 4,
    'galeria' => ['termales-aerea-complejo', 'termales-poza-cueva', 'termales-pozas-rio', 'termales-bano-termal', 'termales-restaurante', 'termales-corredor', 'termales-poza-piedra', 'termales-tunel', 'termales-desayuno', 'termales-selva-aerea'],
    'incluye' => ['Una noche en habitación doble con baño privado', 'Desayuno típico', 'Acceso a las pozas termales durante tu estadía', 'Acompañamiento de Trotamundos por WhatsApp antes y durante el viaje'],
    'no_incluye' => ['Transporte hasta los termales', 'Almuerzos, cenas y bebidas', 'Seguro de viaje'],
    'story' => $termales_story,
    'host_name' => 'El equipo de Termales Espíritu Santo',
    'host_photo' => 'termales-restaurante',
    'host_story' => $termales_host,
    'tips' => $termales_tips,
    'route' => $termales_route,
    'fechas' => [['2026-11-20', '2027-06-30']],
    'disponibilidad' => 'Todos los días, sujeto a cupo.',
  ],
  [
    'title' => 'Escapada de dos noches a Termales Espíritu Santo',
    'destino' => 'narino',
    'tipo' => 'Paquete',
    'estilos' => ['Bienestar', 'Naturaleza', 'Aventura'],
    'resumen' => 'Dos noches con alimentación completa, baños termales y una caminata guiada a las cascadas del cañón.',
    'body' => '<h3>Día 1 · Llegada y primer baño termal</h3><p>Te instalas, almuerzas y tienes la tarde para las pozas y los senderos cercanos.</p><h3>Día 2 · Caminata a las cascadas</h3><p>Con un guía local caminas por el bosque hasta las cascadas del cañón. Después del almuerzo, tarde libre para el agua termal.</p><h3>Día 3 · Despedida</h3><p>Desayuno, último baño y regreso.</p>',
    'precio' => 780000,
    'duracion' => '3 días · 2 noches',
    'noches' => 2,
    'capacidad' => 8,
    'galeria' => ['termales-cascada', 'termales-aerea-vertical', 'termales-poza-mural', 'termales-cascada-piedras', 'termales-poza-montana', 'termales-tunel-luz', 'termales-rio', 'termales-cascada-alta', 'termales-boveda', 'termales-manantial', 'termales-desayuno'],
    'incluye' => ['Dos noches en habitación doble con baño privado', 'Alimentación completa: desayunos, almuerzos y cenas', 'Acceso a las pozas termales', 'Caminata guiada a las cascadas con un guía local', 'Vídeo de bienvenida y un detalle Trotamundos', 'Acompañamiento por WhatsApp antes y durante el viaje'],
    'no_incluye' => ['Transporte hasta los termales', 'Bebidas y propinas', 'Seguro de viaje'],
    'story' => $termales_story,
    'host_name' => 'El equipo de Termales Espíritu Santo',
    'host_photo' => 'termales-restaurante',
    'host_story' => $termales_host,
    'tips' => $termales_tips,
    'route' => $termales_route,
    'fechas' => [['2026-12-04', '2027-06-27']],
    'disponibilidad' => 'Salidas todos los viernes y en puentes festivos.',
  ],
  [
    'title' => 'Noche en una casa campesina de Concepción',
    'destino' => 'concepcion',
    'tipo' => 'Alojamiento',
    'estilos' => ['Pueblos con alma', 'Cultura y tradición'],
    'resumen' => 'Duerme en una casa campesina llena de flores y antigüedades, a pocos minutos del centro de uno de los pueblos más tradicionales de Antioquia.',
    'body' => '<p>Una casa campesina tradicional, con corredores de madera, jardín y habitaciones llenas de historia, a pocos minutos a pie del parque principal.</p><p>Es una base perfecta para recorrer el pueblo, tomar un café en la plaza, ver cómo se ilumina la iglesia al caer la tarde y cenar algo casero.</p>',
    'precio' => 220000,
    'duracion' => '1 noche',
    'noches' => 1,
    'capacidad' => 4,
    'galeria' => ['concepcion-casa-jardin', 'concepcion-corredor', 'concepcion-habitacion', 'concepcion-sala-antiguedades', 'concepcion-cena', 'concepcion-casita', 'concepcion-jardin-flores', 'concepcion-carro-antiguo', 'concepcion-cafe-balcon', 'concepcion-corredor-descanso'],
    'incluye' => ['Una noche en habitación doble en una casa campesina tradicional', 'Desayuno casero', 'Café de la zona a tu llegada', 'Recomendaciones de Juan para recorrer el pueblo', 'Acompañamiento por WhatsApp antes y durante el viaje'],
    'no_incluye' => ['Transporte hasta Concepción', 'Almuerzo y cena', 'Recorridos guiados'],
    'story' => $concepcion_story,
    'host_name' => 'Una familia anfitriona de Concepción',
    'host_photo' => 'concepcion-casa-jardin',
    'host_story' => $concepcion_host,
    'tips' => $concepcion_tips,
    'route' => $concepcion_route,
    'fechas' => [['2026-11-20', '2027-06-30']],
    'disponibilidad' => 'Todos los días, sujeto a cupo.',
  ],
  [
    'title' => 'Concepción: pueblo, historia y naturaleza en dos días',
    'destino' => 'concepcion',
    'tipo' => 'Paquete',
    'estilos' => ['Pueblos con alma', 'Cultura y tradición', 'Naturaleza', 'Gastronomía'],
    'resumen' => 'Recorre el pueblo con un guía local, comparte la mesa con una familia anfitriona y báñate en un charco natural de agua cristalina.',
    'body' => '<h3>Día 1 · El pueblo y su gente</h3><p>Llegas a la casa, te recibe la familia anfitriona y sales con un guía local a recorrer las calles empedradas, la plaza y la iglesia. Por la noche, cena casera en la casa.</p><h3>Día 2 · Agua y montaña</h3><p>Después del desayuno, caminata por las veredas hasta un charco natural para bañarte en agua cristalina. Regreso al pueblo a mediodía.</p>',
    'precio' => 520000,
    'duracion' => '2 días · 1 noche',
    'noches' => 1,
    'capacidad' => 8,
    'galeria' => ['concepcion-panoramica', 'concepcion-calle', 'concepcion-iglesia-noche', 'concepcion-vecina-geranios', 'concepcion-piedra-quebrada', 'concepcion-charco', 'concepcion-balcon', 'concepcion-calle-noche', 'concepcion-quebrada', 'concepcion-cena', 'concepcion-cafe-balcon'],
    'incluye' => ['Una noche en una casa campesina tradicional', 'Desayuno y cena caseros', 'Recorrido por el pueblo con un guía local', 'Caminata a un charco natural de la zona', 'Vídeo de bienvenida y un detalle Trotamundos', 'Acompañamiento por WhatsApp antes y durante el viaje'],
    'no_incluye' => ['Transporte desde Medellín', 'Almuerzos y bebidas', 'Seguro de viaje'],
    'story' => $concepcion_story,
    'host_name' => 'Una familia anfitriona de Concepción',
    'host_photo' => 'concepcion-casa-jardin',
    'host_story' => $concepcion_host,
    'tips' => $concepcion_tips,
    'route' => $concepcion_route,
    'fechas' => [['2027-01-09', '2027-06-26']],
    'disponibilidad' => 'Salidas los sábados, desde enero de 2027.',
  ],
];

foreach ($experiences as $data) {
  $node = $find('experiencia', [$data['title']]) ?? Node::create(['type' => 'experiencia', 'langcode' => 'es', 'uid' => 1, 'status' => 1]);
  $html = static fn (string $value): array => ['value' => $value, 'format' => 'basic_html'];
  $node->set('title', $data['title']);
  $node->set('field_resumen', $data['resumen']);
  $node->set('body', $html($data['body']));
  $node->set('field_galeria', $images($data['galeria']));
  $node->set('field_destino', $destination_ids[$data['destino']]);
  $node->set('field_tipo_plan', $term('tipo_plan', $data['tipo']));
  $node->set('field_estilo', array_map(static fn (string $name): int => $term('estilo_viaje', $name), $data['estilos']));
  $node->set('field_precio', $data['precio']);
  $node->set('field_precio_nota', 'por persona, en habitación doble');
  $node->set('field_duracion', $data['duracion']);
  $node->set('field_noches', $data['noches']);
  $node->set('field_capacidad', $data['capacidad']);
  $node->set('field_incluye', $data['incluye']);
  $node->set('field_no_incluye', $data['no_incluye']);
  $node->set('field_historia_destino', $html($data['story']));
  $node->set('field_anfitrion_nombre', $data['host_name']);
  $node->set('field_anfitrion_foto', $images([$data['host_photo']])[0]);
  $node->set('field_anfitrion_historia', $html($data['host_story']));
  $node->set('field_recomendaciones', $data['tips']);
  $node->set('field_como_llegar', $html($data['route']));
  $node->set('field_fechas', array_map(static fn (array $range): array => ['value' => $range[0], 'end_value' => $range[1]], $data['fechas']));
  $node->set('field_disponibilidad', $data['disponibilidad']);
  $node->set('field_politica_reserva', $html($booking_policy));
  $node->set('field_politica_cancelacion', $html($cancellation_policy));
  $node->set('field_destacada', TRUE);
  $node->set('field_ejemplo', TRUE);
  $node->set('promote', FALSE);
  $node->save();
  echo "Experiencia {$node->label()} → {$node->toUrl()->toString()}\n";
}
