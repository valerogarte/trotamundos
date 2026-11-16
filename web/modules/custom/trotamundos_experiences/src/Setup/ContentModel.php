<?php

declare(strict_types=1);

namespace Drupal\trotamundos_experiences\Setup;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Creates the experience catalog content model in an idempotent way.
 *
 * Running ensure() again only adds what is missing, so it is safe to call
 * from hook_install() and from later update hooks.
 */
final class ContentModel {

  /**
   * Region keys shared by destinations, filters and translations.
   */
  public const REGIONS = [
    'andina' => 'Andina',
    'caribe' => 'Caribe',
    'pacifica' => 'Pacífica',
    'orinoquia' => 'Orinoquía',
    'amazonia' => 'Amazonía',
    'insular' => 'Insular',
  ];

  /**
   * Icon keys available for travel styles; they match icons.html.twig.
   */
  public const STYLE_ICONS = [
    'village' => 'Pueblo',
    'leaf' => 'Naturaleza',
    'sun' => 'Bienestar',
    'mountain' => 'Montaña',
    'heart' => 'Cultura',
    'cup' => 'Gastronomía',
    'wave' => 'Agua',
  ];

  /**
   * Builds or completes the whole content model.
   */
  public static function ensure(): void {
    self::ensureVocabularies();
    self::ensureNodeType();
    self::ensureFields();
    self::ensureDisplays();
    self::ensureImageStyles();
  }

  /**
   * Vocabularies for the kind of plan and for "what would you like to discover".
   */
  private static function ensureVocabularies(): void {
    $vocabularies = [
      'tipo_plan' => [
        'name' => 'Tipo de plan',
        'description' => 'Formato de la experiencia: alojamiento, paquete, pasadía o recorrido.',
        'terms' => ['Alojamiento', 'Paquete', 'Pasadía', 'Recorrido guiado'],
      ],
      'estilo_viaje' => [
        'name' => 'Estilo de viaje',
        'description' => 'Responde a «¿Qué te gustaría descubrir?» en la portada y en el catálogo.',
        'terms' => [
          'Pueblos con alma' => 'village',
          'Naturaleza' => 'leaf',
          'Bienestar' => 'sun',
          'Aventura' => 'mountain',
          'Cultura y tradición' => 'heart',
          'Gastronomía' => 'cup',
        ],
      ],
    ];

    foreach ($vocabularies as $vid => $definition) {
      if (!Vocabulary::load($vid)) {
        Vocabulary::create([
          'vid' => $vid,
          'name' => $definition['name'],
          'description' => $definition['description'],
          'langcode' => 'es',
        ])->save();
      }
    }

    self::ensureField('taxonomy_term', 'estilo_viaje', 'field_icono', 'list_string', 'Icono', [
      'storage' => ['allowed_values' => self::STYLE_ICONS],
      'description' => 'Icono que acompaña al estilo en los filtros.',
    ]);

    foreach ($vocabularies as $vid => $definition) {
      $weight = 0;
      foreach ($definition['terms'] as $key => $value) {
        $name = is_int($key) ? $value : $key;
        $existing = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid, 'name' => $name]);
        if (!$existing) {
          $values = ['vid' => $vid, 'name' => $name, 'weight' => $weight, 'langcode' => 'es'];
          if (!is_int($key)) {
            $values['field_icono'] = $value;
          }
          Term::create($values)->save();
        }
        $weight++;
      }
    }
  }

  /**
   * The experience content type.
   */
  private static function ensureNodeType(): void {
    if (NodeType::load('experiencia')) {
      return;
    }
    NodeType::create([
      'type' => 'experiencia',
      'name' => 'Experiencia',
      'description' => 'Un plan reservable: alojamiento, paquete, pasadía o recorrido, con sus fotos, anfitriones, precios, fechas y políticas.',
      'new_revision' => TRUE,
      'display_submitted' => FALSE,
      'preview_mode' => 1,
      'help' => 'Completa la ficha como la vería un viajero: qué vivirá, qué incluye, cuánto cuesta, cuándo está disponible y con quién.',
      'langcode' => 'es',
    ])->save();
  }

  /**
   * Field definitions for experiences and the destination additions.
   */
  private static function ensureFields(): void {
    // Destinations gain the hierarchy used by the destinations page.
    self::ensureField('node', 'destino', 'field_region', 'list_string', 'Región', [
      'storage' => ['allowed_values' => self::REGIONS],
      'description' => 'Región natural de Colombia. Organiza la página de destinos.',
    ]);
    self::ensureField('node', 'destino', 'field_latitud', 'decimal', 'Latitud', [
      'storage' => ['precision' => 10, 'scale' => 6],
      'description' => 'Coordenada aproximada del municipio, para situarlo en el mapa. Ejemplo: 6.395.',
    ]);
    self::ensureField('node', 'destino', 'field_longitud', 'decimal', 'Longitud', [
      'storage' => ['precision' => 10, 'scale' => 6],
      'description' => 'Coordenada aproximada del municipio. Ejemplo: -75.258.',
    ]);

    $image_settings = [
      'file_extensions' => 'png jpg jpeg webp',
      'alt_field' => TRUE,
      'alt_field_required' => TRUE,
      'title_field' => FALSE,
      'max_filesize' => '12 MB',
    ];

    $fields = [
      'body' => ['text_with_summary', 'Descripción del plan', [
        'settings' => ['display_summary' => FALSE],
        'description' => 'Qué vivirá el viajero, en orden y con palabras sencillas. En los paquetes, puedes contar el plan día a día.',
      ]],
      'field_resumen' => ['string_long', 'Resumen', [
        'required' => TRUE,
        'description' => 'Una o dos frases para las tarjetas y los buscadores (máximo recomendado: 160 caracteres).',
      ]],
      'field_galeria' => ['image', 'Galería de fotos', [
        'cardinality' => -1,
        'required' => TRUE,
        'settings' => $image_settings + ['file_directory' => 'experiencias/[date:custom:Y]-[date:custom:m]'],
        'description' => 'La primera foto es la portada. Recomendado: 10–12 fotos horizontales y verticales, de al menos 1600 px.',
        'sync' => TRUE,
      ]],
      'field_videos' => ['file', 'Vídeos', [
        'cardinality' => -1,
        'settings' => ['file_extensions' => 'mp4 webm', 'file_directory' => 'experiencias/videos', 'max_filesize' => '80 MB', 'description_field' => TRUE],
        'description' => 'Vídeos cortos en MP4 (H.264). Se muestran en la galería. La descripción se usa como texto alternativo.',
        'translatable' => FALSE,
      ]],
      'field_destino' => ['entity_reference', 'Destino', [
        'required' => TRUE,
        'storage' => ['target_type' => 'node'],
        'settings' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['destino' => 'destino'], 'sort' => ['field' => 'title', 'direction' => 'ASC']]],
        'description' => 'Municipio donde ocurre la experiencia. Determina el departamento y la región.',
        'translatable' => FALSE,
      ]],
      'field_tipo_plan' => ['entity_reference', 'Tipo de plan', [
        'required' => TRUE,
        'storage' => ['target_type' => 'taxonomy_term'],
        'settings' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['tipo_plan' => 'tipo_plan'], 'auto_create' => FALSE]],
        'translatable' => FALSE,
      ]],
      'field_estilo' => ['entity_reference', '¿Qué se descubre?', [
        'cardinality' => -1,
        'required' => TRUE,
        'storage' => ['target_type' => 'taxonomy_term'],
        'settings' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['estilo_viaje' => 'estilo_viaje'], 'auto_create' => FALSE]],
        'description' => 'Estilos de viaje para los filtros «¿Qué te gustaría descubrir?».',
        'translatable' => FALSE,
      ]],
      'field_precio' => ['integer', 'Precio desde (COP)', [
        'required' => TRUE,
        'settings' => ['min' => 0, 'suffix' => ' COP'],
        'description' => 'Precio mínimo por persona, en pesos colombianos y sin puntos. Ejemplo: 320000.',
        'translatable' => FALSE,
      ]],
      'field_precio_nota' => ['string', 'Nota del precio', [
        'description' => 'Ejemplo: «por persona, en acomodación doble».',
      ]],
      'field_duracion' => ['string', 'Duración', [
        'required' => TRUE,
        'description' => 'Tal como la leerá el viajero. Ejemplo: «2 días · 1 noche».',
      ]],
      'field_noches' => ['integer', 'Noches', [
        'required' => TRUE,
        'settings' => ['min' => 0],
        'description' => 'Número de noches. Usa 0 para los planes de un día. Se usa en el filtro de duración.',
        'translatable' => FALSE,
      ]],
      'field_capacidad' => ['integer', 'Viajeros por reserva (máximo)', [
        'settings' => ['min' => 1],
        'description' => 'Límite de personas que se pueden elegir al reservar.',
        'translatable' => FALSE,
      ]],
      'field_incluye' => ['string', 'Incluye', [
        'cardinality' => -1,
        'description' => 'Un servicio por línea.',
      ]],
      'field_no_incluye' => ['string', 'No incluye', [
        'cardinality' => -1,
        'description' => 'Lo que el viajero debe prever por su cuenta.',
      ]],
      'field_historia_destino' => ['text_long', 'Historia del destino', [
        'description' => 'Lo que hace especial al lugar: su historia, su gente y su paisaje.',
      ]],
      'field_anfitrion_nombre' => ['string', 'Anfitriones', [
        'description' => 'Nombre de la persona, familia o emprendimiento que recibe al viajero.',
      ]],
      'field_anfitrion_foto' => ['image', 'Foto de los anfitriones', [
        'settings' => $image_settings + ['file_directory' => 'experiencias/anfitriones'],
        'sync' => TRUE,
      ]],
      'field_anfitrion_historia' => ['text_long', 'Historia de los anfitriones', [
        'description' => 'Quiénes son, cómo empezaron y qué quieren compartir.',
      ]],
      'field_recomendaciones' => ['string', 'Recomendaciones', [
        'cardinality' => -1,
        'description' => 'Qué llevar, nivel de esfuerzo, clima… Una recomendación por línea.',
      ]],
      'field_como_llegar' => ['text_long', 'Ubicación y cómo llegar', []],
      'field_fechas' => ['daterange', 'Fechas disponibles', [
        'cardinality' => -1,
        'storage' => ['datetime_type' => 'date'],
        'description' => 'Periodos en los que se puede reservar. Se usan en el filtro «¿Cuándo?» y en el calendario de reserva.',
        'translatable' => FALSE,
      ]],
      'field_disponibilidad' => ['string', 'Nota de disponibilidad', [
        'description' => 'Ejemplo: «Todos los días, sujeto a cupo» o «Salidas de viernes a domingo».',
      ]],
      'field_politica_reserva' => ['text_long', 'Política de reserva', []],
      'field_politica_cancelacion' => ['text_long', 'Política de cancelación', []],
      'field_destacada' => ['boolean', 'Destacada en la portada', [
        'settings' => ['on_label' => 'Sí', 'off_label' => 'No'],
        'translatable' => FALSE,
      ]],
      'field_ejemplo' => ['boolean', 'Ficha de ejemplo', [
        'settings' => ['on_label' => 'Sí', 'off_label' => 'No'],
        'description' => 'Muestra un aviso de que los precios, fechas y textos son provisionales. Desmárcalo cuando la ficha esté confirmada con el aliado.',
        'translatable' => FALSE,
      ]],
    ];

    foreach ($fields as $name => [$type, $label, $options]) {
      self::ensureField('node', 'experiencia', $name, $type, $label, $options);
    }
  }

  /**
   * Creates a field storage and instance when they do not exist yet.
   */
  private static function ensureField(string $entity_type, string $bundle, string $name, string $type, string $label, array $options = []): void {
    $storage = FieldStorageConfig::loadByName($entity_type, $name);
    if (!$storage) {
      $storage = FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entity_type,
        'type' => $type,
        'cardinality' => $options['cardinality'] ?? 1,
        'settings' => $options['storage'] ?? [],
        'translatable' => TRUE,
      ]);
      $storage->save();
    }

    if (FieldConfig::loadByName($entity_type, $bundle, $name)) {
      return;
    }

    $field = FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => $bundle,
      'label' => $label,
      'description' => $options['description'] ?? '',
      'required' => $options['required'] ?? FALSE,
      'settings' => $options['settings'] ?? [],
      'translatable' => $options['translatable'] ?? TRUE,
    ]);
    if (!empty($options['sync'])) {
      // Images are shared by every translation; only alt texts are translated.
      $field->setThirdPartySetting('content_translation', 'translation_sync', [
        'file' => 'file',
        'alt' => '0',
        'title' => '0',
      ]);
    }
    $field->save();
  }

  /**
   * Editorial form and minimal view displays.
   */
  private static function ensureDisplays(): void {
    $repository = \Drupal::service('entity_display.repository');

    $widgets = [
      'title' => ['string_textfield', []],
      'field_resumen' => ['string_textarea', ['rows' => 2]],
      'field_galeria' => ['image_image', ['preview_image_style' => 'thumbnail', 'progress_indicator' => 'throbber']],
      'field_videos' => ['file_generic', ['progress_indicator' => 'throbber']],
      'field_destino' => ['options_select', []],
      'field_tipo_plan' => ['options_select', []],
      'field_estilo' => ['options_buttons', []],
      'field_precio' => ['number', ['placeholder' => '320000']],
      'field_precio_nota' => ['string_textfield', []],
      'field_duracion' => ['string_textfield', []],
      'field_noches' => ['number', []],
      'field_capacidad' => ['number', []],
      'field_fechas' => ['daterange_default', []],
      'field_disponibilidad' => ['string_textfield', []],
      'body' => ['text_textarea_with_summary', ['rows' => 9, 'summary_rows' => 3, 'show_summary' => FALSE]],
      'field_incluye' => ['string_textfield', []],
      'field_no_incluye' => ['string_textfield', []],
      'field_recomendaciones' => ['string_textfield', []],
      'field_historia_destino' => ['text_textarea', ['rows' => 5]],
      'field_anfitrion_nombre' => ['string_textfield', []],
      'field_anfitrion_foto' => ['image_image', ['preview_image_style' => 'thumbnail', 'progress_indicator' => 'throbber']],
      'field_anfitrion_historia' => ['text_textarea', ['rows' => 5]],
      'field_como_llegar' => ['text_textarea', ['rows' => 4]],
      'field_politica_reserva' => ['text_textarea', ['rows' => 4]],
      'field_politica_cancelacion' => ['text_textarea', ['rows' => 4]],
      'field_destacada' => ['boolean_checkbox', ['display_label' => TRUE]],
      'field_ejemplo' => ['boolean_checkbox', ['display_label' => TRUE]],
    ];

    $form = $repository->getFormDisplay('node', 'experiencia', 'default');
    $weight = 0;
    foreach ($widgets as $name => [$widget, $settings]) {
      $form->setComponent($name, ['type' => $widget, 'weight' => $weight++, 'settings' => $settings]);
    }
    foreach (['langcode', 'path', 'status', 'uid', 'created', 'promote', 'sticky'] as $base_field) {
      if (!$form->getComponent($base_field)) {
        $form->setComponent($base_field, ['weight' => 100 + $weight++]);
      }
    }
    $form->save();

    // The theme renders the full page from prepared data; keep the stored
    // display minimal so search indexes and feeds get readable text.
    foreach (['default', 'teaser'] as $mode) {
      $view = $repository->getViewDisplay('node', 'experiencia', $mode);
      foreach (array_keys($widgets) as $name) {
        if ($name !== 'title') {
          $view->removeComponent($name);
        }
      }
      $view->setComponent('field_resumen', ['type' => 'basic_string', 'label' => 'hidden', 'weight' => 0]);
      if ($mode === 'default') {
        $view->setComponent('body', ['type' => 'text_default', 'label' => 'hidden', 'weight' => 1]);
      }
      $view->save();
    }

    $destino_form = $repository->getFormDisplay('node', 'destino', 'default');
    foreach (['field_region' => 'options_select', 'field_latitud' => 'number', 'field_longitud' => 'number'] as $name => $widget) {
      if (!$destino_form->getComponent($name)) {
        $destino_form->setComponent($name, ['type' => $widget, 'weight' => 20]);
      }
    }
    $destino_form->save();

    $term_form = $repository->getFormDisplay('taxonomy_term', 'estilo_viaje', 'default');
    if (!$term_form->getComponent('field_icono')) {
      $term_form->setComponent('field_icono', ['type' => 'options_select', 'weight' => 5])->save();
    }
  }

  /**
   * Responsive derivatives in WebP for cards, galleries and hosts.
   */
  private static function ensureImageStyles(): void {
    $styles = [
      'tm_card' => ['Trotamundos · tarjeta 4:5', [['image_scale_and_crop', ['width' => 720, 'height' => 900, 'anchor' => 'center-center']]]],
      'tm_tile' => ['Trotamundos · mosaico 4:3', [['image_scale_and_crop', ['width' => 960, 'height' => 720, 'anchor' => 'center-center']]]],
      'tm_wide' => ['Trotamundos · ancho completo', [['image_scale', ['width' => 1920, 'height' => 1920, 'upscale' => FALSE]]]],
      'tm_square' => ['Trotamundos · cuadrado', [['image_scale_and_crop', ['width' => 560, 'height' => 560, 'anchor' => 'center-center']]]],
      'tm_thumb' => ['Trotamundos · miniatura', [['image_scale_and_crop', ['width' => 240, 'height' => 180, 'anchor' => 'center-center']]]],
    ];
    foreach ($styles as $id => [$label, $effects]) {
      if (ImageStyle::load($id)) {
        continue;
      }
      $style = ImageStyle::create(['name' => $id, 'label' => $label]);
      $weight = 0;
      foreach ($effects as [$plugin, $data]) {
        $style->addImageEffect(['id' => $plugin, 'weight' => $weight++, 'data' => $data]);
      }
      $style->addImageEffect(['id' => 'image_convert', 'weight' => $weight, 'data' => ['extension' => 'webp']]);
      $style->save();
    }
  }

}
