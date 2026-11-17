<?php

declare(strict_types=1);

namespace Drupal\trotamundos_experiences;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\trotamundos_experiences\Setup\ContentModel;
use Drupal\trotamundos_experiences\Setup\ProductTypes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Reads experiences and destinations and prepares them for presentation.
 *
 * Every public page (home, catalog, destinations, experience detail and the
 * brand guide samples) uses the same card data, so the catalog behaves the
 * same with three experiences or with three hundred.
 */
final class ExperienceRepository {

  use StringTranslationTrait;

  /**
   * Cards per catalog page.
   */
  public const PAGE_SIZE = 12;

  /**
   * Price ranges, in Colombian pesos per person.
   */
  public const PRICE_RANGES = [
    'hasta-300000' => [0, 300000],
    '300000-600000' => [300000, 600000],
    'desde-600000' => [600000, NULL],
  ];

  /**
   * Duration ranges, in nights.
   */
  public const DURATIONS = [
    'un-dia' => [0, 0],
    'una-noche' => [1, 1],
    'dos-tres-noches' => [2, 3],
    'cuatro-noches' => [4, NULL],
  ];

  /**
   * Sort options.
   */
  public const SORTS = ['recomendadas', 'precio-asc', 'precio-desc'];

  public const DATE_MARGINS = [1, 2, 3, 7, 14];

  /**
   * Published experiences, loaded once per request for counts and options.
   *
   * @var \Drupal\node\NodeInterface[]|null
   */
  private ?array $published = NULL;

  /**
   * Destination summaries keyed by node ID.
   */
  private ?array $destinations = NULL;

  /**
   * Terms of the catalog vocabularies keyed by slug.
   */
  private ?array $terms = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly LanguageManagerInterface $languageManager,
    private readonly TransliterationInterface $transliteration,
    private readonly TimeInterface $time,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Reads and validates the catalog filters from the query string.
   */
  public function filtersFromRequest(Request $request): array {
    $read = static fn (string $key): string => trim((string) $request->query->get($key, ''));
    $filters = [
      'estilo' => $read('estilo'),
      'tipo' => $read('tipo'),
      'destino' => $read('destino'),
      'region' => $read('region'),
      'precio' => $read('precio'),
      'duracion' => $read('duracion'),
      'fecha' => $read('fecha'),
      'desde' => $read('desde'),
      'hasta' => $read('hasta'),
      'margen' => $read('margen'),
      'acompanamiento' => $read('acompanamiento'),
      'orden' => $read('orden'),
    ];

    $terms = $this->termIndex();
    if ($filters['estilo'] === 'cultura-y-tradicion'
      && !isset($terms['estilo_viaje']['cultura-y-tradicion'])
      && isset($terms['estilo_viaje']['cultura-viva'])) {
      $filters['estilo'] = 'cultura-viva';
    }
    if (!isset($terms['estilo_viaje'][$filters['estilo']])) {
      $filters['estilo'] = '';
    }
    if (!in_array($filters['tipo'], ProductTypes::BUNDLES, TRUE)) {
      $filters['tipo'] = isset($terms['tipo_plan'][$filters['tipo']]) ? 'experiencia' : '';
    }
    if ($filters['destino'] !== '' && !$this->destinationIdsForSlug($filters['destino'])) {
      $filters['destino'] = '';
    }
    if (!array_key_exists($filters['region'], self::regionLabels())) {
      $filters['region'] = '';
    }
    if (!isset(self::PRICE_RANGES[$filters['precio']])) {
      $filters['precio'] = '';
    }
    if (!isset(self::DURATIONS[$filters['duracion']])) {
      $filters['duracion'] = '';
    }
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $filters['fecha'])) {
      $filters['fecha'] = '';
    }
    if ($filters['desde'] !== '' || $filters['hasta'] !== '') {
      foreach (['desde', 'hasta'] as $key) {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $filters[$key], $parts)
          || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
          throw new BadRequestHttpException((string) $this->t('Selecciona fechas válidas de inicio y fin.'));
        }
      }
      if ($filters['desde'] < $this->today() || $filters['hasta'] < $filters['desde']) {
        throw new BadRequestHttpException((string) $this->t('El viaje debe empezar hoy o más adelante y no terminar antes de empezar.'));
      }
      $filters['fecha'] = '';
    }
    if ($filters['margen'] === '0') {
      $filters['margen'] = '';
    }
    if ($filters['margen'] !== ''
      && (!in_array($filters['margen'], array_map('strval', self::DATE_MARGINS), TRUE) || $filters['desde'] === '')) {
      throw new BadRequestHttpException((string) $this->t('Elige un rango de fechas y un margen válido.'));
    }
    if (!isset(ContentModel::COMPANIONS[$filters['acompanamiento']])) {
      $filters['acompanamiento'] = '';
    }
    if (!in_array($filters['orden'], self::SORTS, TRUE)) {
      $filters['orden'] = '';
    }
    return $filters;
  }

  /**
   * Runs a catalog search and returns cards for the current pager page.
   */
  public function search(array $filters, int $limit = self::PAGE_SIZE): array {
    $query = $this->baseQuery();
    if (!$this->applyFilters($query, $filters)) {
      return ['cards' => [], 'total' => 0];
    }
    $total = (int) (clone $query)->count()->execute();
    $this->applySort($query, $filters['orden'] ?? '');
    $query->pager($limit);
    return ['cards' => $this->cards($query->execute()), 'total' => $total];
  }

  /**
   * Experiences for the home page: featured first, then the newest ones.
   */
  public function featured(int $limit = 6): array {
    $query = $this->baseQuery();
    $this->applySort($query, 'recomendadas');
    $query->range(0, $limit);
    return $this->cards($query->execute());
  }

  /**
   * Experiences in one destination.
   */
  public function forDestination(int $destination_id, ?int $exclude = NULL, int $limit = 12): array {
    $query = $this->baseQuery()->condition('field_destino', $destination_id);
    if ($exclude) {
      $query->condition('nid', $exclude, '<>');
    }
    $this->applySort($query, 'recomendadas');
    $query->range(0, $limit);
    return $this->cards($query->execute());
  }

  /**
   * Related experiences: same destination first, then the same department.
   */
  public function related(NodeInterface $node, int $limit = 3): array {
    $destination = $this->destinationFor($node);
    $cards = $destination ? $this->forDestination($destination['id'], (int) $node->id(), $limit) : [];
    if (count($cards) < $limit && $destination) {
      $ids = array_keys(array_filter($this->destinationIndex(), static fn (array $item): bool => $item['department_slug'] === $destination['department_slug'] && $item['id'] !== $destination['id']));
      if ($ids) {
        $query = $this->baseQuery()->condition('field_destino', $ids, 'IN')->condition('nid', $node->id(), '<>');
        $this->applySort($query, 'recomendadas');
        $query->range(0, $limit - count($cards));
        $cards = array_merge($cards, $this->cards($query->execute()));
      }
    }
    return $cards;
  }

  /**
   * Builds card data for a list of node IDs, keeping their order.
   */
  public function cards(array $ids): array {
    if (!$ids) {
      return [];
    }
    $nodes = $this->nodeStorage()->loadMultiple(array_values($ids));
    $cards = [];
    foreach (array_values($ids) as $id) {
      if (isset($nodes[$id]) && $nodes[$id]->access('view')) {
        $cards[] = $this->card($nodes[$id]);
      }
    }
    return $cards;
  }

  /**
   * The data every experience card needs.
   */
  public function card(NodeInterface $node): array {
    $node = $this->translated($node);
    $destination = $this->destinationFor($node);
    $type = $this->productType($node);
    $styles = $this->referencedTerms($node, 'field_estilo');
    $cover = $node->hasField('field_galeria') && !$node->get('field_galeria')->isEmpty() ? $node->get('field_galeria')->first() : NULL;
    $price = (float) ($node->get($this->priceField())->value ?? 0);

    $card = [
      'id' => (int) $node->id(),
      'url' => $node->toUrl()->toString(),
      'title' => $node->label(),
      'summary' => (string) ($node->get('field_resumen')->value ?? ''),
      'image' => $cover instanceof ImageItem ? $this->image($cover, 'tm_card', 720, 900) : NULL,
      'location' => $destination ? $destination['location'] : '',
      'municipality' => $destination['municipality'] ?? '',
      'department' => $destination['department'] ?? '',
      'region' => $destination['region'] ?? '',
      'type' => $type,
      'styles' => $styles,
      'style_slugs' => implode(' ', array_column($styles, 'slug')),
      'price' => $price,
      'price_label' => $this->formatPrice($price),
      'price_note' => (string) ($node->get('field_precio_nota')->value ?? ''),
      'duration' => (string) ($node->get('field_duracion')->value ?? ''),
      'nights' => (int) ($node->get('field_noches')->value ?? 0),
      'is_example' => (bool) ($node->get('field_ejemplo')->value ?? FALSE),
    ];
    \Drupal::moduleHandler()->alter('trotamundos_experience_card', $card, $node);
    return $card;
  }

  /**
   * Everything the experience page shows.
   */
  public function detail(NodeInterface $node): array {
    $node = $this->translated($node);
    $card = $this->card($node);
    $destination = $this->destinationFor($node);
    $langcode = $node->language()->getId();

    $gallery = [];
    foreach ($node->get('field_galeria') as $delta => $item) {
      if ($item instanceof ImageItem && ($image = $this->image($item, 'tm_tile', 960, 720))) {
        $image['wide'] = $this->styleUrl($item, 'tm_wide');
        $image['thumb'] = $this->styleUrl($item, 'tm_thumb');
        $image['ratio'] = ($item->width && $item->height) ? round($item->width / $item->height, 4) : 1.333;
        $image['delta'] = $delta;
        $gallery[] = $image;
      }
    }

    $videos = [];
    foreach ($node->get('field_videos') as $item) {
      $file = $item->entity;
      if ($file instanceof FileInterface) {
        $videos[] = [
          'src' => $this->fileUrlGenerator->generateString($file->getFileUri()),
          'type' => $file->getMimeType(),
          'label' => $item->description ?: $node->label(),
        ];
      }
    }

    $ranges = [];
    foreach ($this->availableRanges($node) as $range) {
      ['start' => $start, 'end' => $end] = $range;
      $ranges[] = $range + [
        'label' => $start === $end
          ? $this->formatDate($start, $langcode)
          : $this->t('Del @start al @end', ['@start' => $this->formatDate($start, $langcode), '@end' => $this->formatDate($end, $langcode)]),
      ];
    }

    $host_photo = NULL;
    if (!$node->get('field_anfitrion_foto')->isEmpty() && ($item = $node->get('field_anfitrion_foto')->first()) instanceof ImageItem) {
      $host_photo = $this->image($item, 'tm_square', 560, 560);
    }

    $breadcrumb = [
      ['label' => $this->t('Inicio'), 'url' => Url::fromRoute('<front>')->toString()],
      ['label' => $card['type']['plural'], 'url' => $card['type']['url']],
    ];
    if ($destination) {
      $breadcrumb[] = ['label' => $destination['department'], 'url' => Url::fromRoute('trotamundos_experiences.catalog', [], ['query' => ['destino' => $destination['department_slug']]])->toString()];
      $breadcrumb[] = ['label' => $destination['title'], 'url' => $destination['url']];
    }

    return $card + [
      'langcode' => $langcode,
      'gallery' => $gallery,
      'videos' => $videos,
      'description' => $this->processed($node, 'body'),
      'includes' => $this->stringValues($node, 'field_incluye'),
      'excludes' => $this->stringValues($node, 'field_no_incluye'),
      'story' => $this->processed($node, 'field_historia_destino'),
      'host' => [
        'name' => (string) ($node->get('field_anfitrion_nombre')->value ?? ''),
        'photo' => $host_photo,
        'story' => $this->processed($node, 'field_anfitrion_historia'),
      ],
      'recommendations' => $this->stringValues($node, 'field_recomendaciones'),
      'how_to_get' => $this->processed($node, 'field_como_llegar'),
      'ranges' => $ranges,
      'availability' => (string) ($node->get('field_disponibilidad')->value ?? ''),
      'capacity' => (int) ($node->get('field_capacidad')->value ?: 12),
      'booking_policy' => $this->processed($node, 'field_politica_reserva'),
      'cancellation_policy' => $this->processed($node, 'field_politica_cancelacion'),
      'destination' => $destination,
      'breadcrumb' => $breadcrumb,
      'whatsapp_text' => (string) $this->t('Hola, me gustaría saber más sobre «@title» (@location).', [
        '@title' => $node->label(),
        '@location' => $card['location'],
      ]),
    ];
  }

  /**
   * Options for the catalog filters, with counts from published experiences.
   */
  public function filterOptions(array $filters, string $product_type = ''): array {
    $styles = $types = $destinations = [];
    $destination_index = $this->destinationIndex();

    foreach ($this->published() as $node) {
      if ($product_type !== '' && $node->bundle() !== $product_type) {
        continue;
      }
      foreach ($node->get('field_estilo') as $item) {
        $styles[(int) $item->target_id] = ($styles[(int) $item->target_id] ?? 0) + 1;
      }
      $types[$node->bundle()] = ($types[$node->bundle()] ?? 0) + 1;
      $destination_id = (int) ($node->get('field_destino')->target_id ?? 0);
      if (isset($destination_index[$destination_id])) {
        $destinations[$destination_id] = ($destinations[$destination_id] ?? 0) + 1;
      }
    }

    $term_options = function (string $vid, array $counts, string $selected): array {
      $options = [];
      foreach ($this->termIndex()[$vid] as $slug => $term) {
        $options[] = $term + ['count' => $counts[$term['id']] ?? 0, 'selected' => $slug === $selected];
      }
      return $options;
    };

    // Include published destinations that do not have a bookable plan yet.
    $tree = [];
    foreach ($destination_index as $destination_id => $destination) {
      $count = $destinations[$destination_id] ?? 0;
      $region = $destination['region'] ?: 'otras';
      $department = $destination['department_slug'];
      $tree[$region]['label'] ??= $destination['region_label'] ?: (string) $this->t('Otras regiones');
      $tree[$region]['departments'][$department]['label'] ??= $destination['department'];
      $tree[$region]['departments'][$department]['value'] = $department;
      $tree[$region]['departments'][$department]['count'] = ($tree[$region]['departments'][$department]['count'] ?? 0) + $count;
      $value = $department . '/' . $destination['municipality_slug'];
      $municipalities = &$tree[$region]['departments'][$department]['municipalities'];
      $municipalities[$value] = [
        'value' => $value,
        'label' => $destination['municipality'],
        'count' => ($municipalities[$value]['count'] ?? 0) + $count,
      ];
      unset($municipalities);
    }
    ksort($tree);
    foreach ($tree as &$region) {
      ksort($region['departments']);
      foreach ($region['departments'] as &$department) {
        ksort($department['municipalities']);
        $department['municipalities'] = array_values($department['municipalities']);
      }
      unset($department);
      $region['departments'] = array_values($region['departments']);
    }
    unset($region);

    $months = [];
    $start = new \DateTimeImmutable(date('Y-m-01', $this->time->getRequestTime()));
    for ($i = 0; $i < 12; $i++) {
      $month = $start->modify("+$i month");
      $months[] = ['value' => $month->format('Y-m'), 'label' => $this->formatMonth($month)];
    }

    $companions = [];
    foreach (self::accompanimentLabels() as $value => $label) {
      $companions[] = [
        'value' => $value,
        'label' => $label,
        'selected' => ($filters['acompanamiento'] ?? '') === $value,
      ];
    }

    $product_options = [];
    foreach (self::productLabels() as $bundle => $label) {
      $product_options[] = [
        'slug' => $bundle,
        'label' => $label,
        'count' => $types[$bundle] ?? 0,
        'selected' => ($filters['tipo'] ?? '') === $bundle,
      ];
    }

    return [
      'styles' => $term_options('estilo_viaje', $styles, $filters['estilo'] ?? ''),
      'types' => $product_options,
      'destinations' => array_values($tree),
      'prices' => [
        ['value' => 'hasta-300000', 'label' => $this->t('Hasta @amount', ['@amount' => $this->formatPrice(300000)])],
        ['value' => '300000-600000', 'label' => $this->t('De @min a @max', ['@min' => $this->formatPrice(300000), '@max' => $this->formatPrice(600000)])],
        ['value' => 'desde-600000', 'label' => $this->t('Más de @amount', ['@amount' => $this->formatPrice(600000)])],
      ],
      'durations' => [
        ['value' => 'un-dia', 'label' => $this->t('Plan de un día')],
        ['value' => 'una-noche', 'label' => $this->t('1 noche')],
        ['value' => 'dos-tres-noches', 'label' => $this->t('2 o 3 noches')],
        ['value' => 'cuatro-noches', 'label' => $this->t('4 noches o más')],
      ],
      'months' => $months,
      'companions' => $companions,
      'today' => $this->today(),
      'margins' => self::DATE_MARGINS,
      'sorts' => [
        ['value' => '', 'label' => $this->t('Recomendadas')],
        ['value' => 'precio-asc', 'label' => $this->t('Precio: de menor a mayor')],
        ['value' => 'precio-desc', 'label' => $this->t('Precio: de mayor a menor')],
      ],
      'total' => array_sum($types),
    ];
  }

  /**
   * Human labels for the active filters, each with a link that removes it.
   */
  public function activeFilters(array $filters, array $options, string $route = 'trotamundos_experiences.catalog'): array {
    $labels = [];
    foreach ($options['styles'] as $option) {
      $labels['estilo'][$option['slug']] = $option['label'];
    }
    foreach ($options['types'] as $option) {
      $labels['tipo'][$option['slug']] = $option['label'];
    }
    foreach ($options['destinations'] as $region) {
      foreach ($region['departments'] as $department) {
        $labels['destino'][$department['value']] = $department['label'];
        foreach ($department['municipalities'] as $municipality) {
          $labels['destino'][$municipality['value']] = $municipality['label'] . ', ' . $department['label'];
        }
      }
    }
    $labels['region'] = self::regionLabels();
    foreach (['prices' => 'precio', 'durations' => 'duracion', 'months' => 'fecha', 'companions' => 'acompanamiento'] as $group => $key) {
      foreach ($options[$group] as $option) {
        $labels[$key][$option['value']] = $option['label'];
      }
    }

    $query = array_filter($filters, static fn ($value): bool => $value !== '');
    $active = [];
    foreach ($query as $key => $value) {
      if ($key === 'orden' || !isset($labels[$key][$value])) {
        continue;
      }
      $remaining = $query;
      unset($remaining[$key]);
      $active[] = [
        'key' => $key,
        'label' => $labels[$key][$value],
        'remove_url' => Url::fromRoute($route, [], ['query' => $remaining])->toString(),
      ];
    }
    if (!empty($filters['desde'])) {
      $remaining = array_diff_key($query, array_flip(['desde', 'hasta', 'margen']));
      $active[] = [
        'key' => 'fechas',
        'label' => $this->dateLabel($filters),
        'remove_url' => Url::fromRoute($route, [], ['query' => $remaining])->toString(),
      ];
    }
    return $active;
  }

  public static function productLabels(): array {
    return [
      'alojamiento' => (string) t('Alojamientos'),
      'experiencia' => (string) t('Experiencias'),
      'paquete' => (string) t('Paquetes'),
    ];
  }

  public function today(): string {
    return date('Y-m-d', $this->time->getRequestTime());
  }

  public function dateLabel(array $filters): string {
    if (!empty($filters['desde']) && !empty($filters['hasta'])) {
      $start = (new \DateTimeImmutable($filters['desde']))->format('d/m/Y');
      $end = (new \DateTimeImmutable($filters['hasta']))->format('d/m/Y');
      $label = $start === $end ? $start : $start . ' – ' . $end;
      return !empty($filters['margen'])
        ? (string) $this->formatPlural((int) $filters['margen'], '@dates (±1 día)', '@dates (±@count días)', ['@dates' => $label])
        : $label;
    }
    return !empty($filters['fecha'])
      ? $this->formatMonth(new \DateTimeImmutable($filters['fecha'] . '-01'))
      : (string) $this->t('Cualquier fecha');
  }

  private function productType(NodeInterface $node): array {
    $labels = [
      'alojamiento' => $this->t('Alojamiento'),
      'experiencia' => $this->t('Experiencia'),
      'paquete' => $this->t('Paquete'),
    ];
    return [
      'slug' => $node->bundle(),
      'label' => $labels[$node->bundle()],
      'plural' => self::productLabels()[$node->bundle()],
      'url' => Url::fromRoute(ProductTypes::ROUTES[$node->bundle()])->toString(),
    ];
  }

  /**
   * Published destinations with their place in the region/department tree.
   */
  public function destinationIndex(): array {
    if ($this->destinations !== NULL) {
      return $this->destinations;
    }
    $storage = $this->nodeStorage();
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'destino')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('title')
      ->execute();

    $counts = [];
    foreach ($this->published() as $experience) {
      $target = (int) ($experience->get('field_destino')->target_id ?? 0);
      $counts[$target] = ($counts[$target] ?? 0) + 1;
    }

    $this->destinations = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        continue;
      }
      $default = $node->getUntranslated();
      $node = $this->translated($node);
      $title = self::cleanTitle($node->label());
      $municipality = (string) ($node->get('field_municipio')->value ?? $title);
      $department = (string) ($node->get('field_departamento')->value ?? '');
      $region = $node->hasField('field_region') ? (string) ($node->get('field_region')->value ?? '') : '';
      $image = NULL;
      if ($node->hasField('field_image') && !$node->get('field_image')->isEmpty() && ($item = $node->get('field_image')->first()) instanceof ImageItem) {
        $image = $this->image($item, 'tm_card', 720, 900);
      }
      $place = trim(explode(',', $title)[0]);
      $this->destinations[(int) $node->id()] = [
        'id' => (int) $node->id(),
        'title' => $title,
        'place' => $place,
        'url' => $node->toUrl()->toString(),
        'municipality' => $municipality,
        'department' => $department,
        'municipality_slug' => $this->slug((string) ($default->get('field_municipio')->value ?? $municipality)),
        'department_slug' => $this->slug((string) ($default->get('field_departamento')->value ?? $department)),
        'region' => $region,
        'region_label' => self::regionLabels()[$region] ?? '',
        'location' => $place === $municipality ? $municipality . ' · ' . $department : $place . ' · ' . $department,
        'image' => $image,
        'summary' => $this->summary($node),
        'latitude' => $node->hasField('field_latitud') ? $node->get('field_latitud')->value : NULL,
        'longitude' => $node->hasField('field_longitud') ? $node->get('field_longitud')->value : NULL,
        'count' => $counts[(int) $node->id()] ?? 0,
      ];
    }
    return $this->destinations;
  }

  /**
   * Destinations grouped by region and department, for the destinations page.
   */
  public function destinationTree(): array {
    $tree = [];
    foreach ($this->destinationIndex() as $destination) {
      $region = $destination['region'] ?: 'otras';
      $tree[$region]['key'] = $region;
      $tree[$region]['label'] ??= $destination['region_label'] ?: (string) $this->t('Otras regiones');
      $tree[$region]['count'] = ($tree[$region]['count'] ?? 0) + $destination['count'];
      $department = &$tree[$region]['departments'][$destination['department_slug']];
      $department['label'] = $destination['department'];
      $department['slug'] = $destination['department_slug'];
      $department['count'] = ($department['count'] ?? 0) + $destination['count'];
      $department['destinations'][] = $destination;
      unset($department);
    }
    // Regions with experiences first, then alphabetically.
    uasort($tree, static fn (array $a, array $b): int => [$b['count'] > 0, $a['label']] <=> [$a['count'] > 0, $b['label']]);
    foreach ($tree as &$region) {
      uasort($region['departments'], static fn (array $a, array $b): int => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);
      foreach ($region['departments'] as &$department) {
        usort($department['destinations'], static fn (array $a, array $b): int => [$b['count'], $a['title']] <=> [$a['count'], $b['title']]);
      }
      unset($department);
      $region['departments'] = array_values($region['departments']);
    }
    unset($region);
    return array_values($tree);
  }

  /**
   * The destination summary for an experience.
   */
  public function destinationFor(NodeInterface $node): ?array {
    $id = (int) ($node->get('field_destino')->target_id ?? 0);
    return $this->destinationIndex()[$id] ?? NULL;
  }

  /**
   * Formats a price in Colombian pesos for the current language.
   */
  public function formatPrice(int|float $amount): string {
    $decimals = floor($amount) === (float) $amount ? 0 : 2;
    // Templates add "COP" next to the amount; only the separators change.
    if ($this->currentLangcode() === 'en') {
      return '$' . number_format($amount, $decimals, '.', ',');
    }
    return '$' . number_format($amount, $decimals, ',', '.');
  }

  private function priceField(): string {
    return $this->entityTypeManager->getStorage('field_storage_config')->load('node.field_tm_catalog_price')
      ? 'field_tm_catalog_price' : 'field_precio';
  }

  /**
   * Translated labels for the natural regions of Colombia.
   */
  public static function regionLabels(): array {
    return [
      'andina' => (string) t('Región Andina'),
      'caribe' => (string) t('Región Caribe'),
      'pacifica' => (string) t('Región Pacífica'),
      'orinoquia' => (string) t('Orinoquía'),
      'amazonia' => (string) t('Amazonía'),
      'insular' => (string) t('Región Insular'),
    ];
  }

  /**
   * Translated labels for the optional travel companion choices.
   */
  public static function accompanimentLabels(): array {
    return [
      'pareja' => (string) t('Pareja'),
      'familia' => (string) t('Familia'),
      'grupo' => (string) t('Grupo'),
      'solo' => (string) t('Solo'),
    ];
  }

  /**
   * Removes the editorial demo prefix from titles.
   */
  public static function cleanTitle(string $title): string {
    return preg_replace('/^\[DEMO\]\s*/u', '', $title) ?: $title;
  }

  /**
   * URL-friendly slug, transliterated from Spanish.
   */
  public function slug(string $text): string {
    $text = $this->transliteration->transliterate($text, 'es', '-');
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-');
  }

  /**
   * The base query for published experiences.
   */
  private function baseQuery(): QueryInterface {
    // Filters use untranslatable fields; sorting and status follow the
    // original version so translations never reorder or duplicate results.
    return $this->nodeStorage()->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', ProductTypes::BUNDLES, 'IN')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('default_langcode', 1);
  }

  /**
   * Adds filter conditions. Returns FALSE when no experience can match.
   */
  private function applyFilters(QueryInterface $query, array $filters): bool {
    $terms = $this->termIndex();
    if (!empty($filters['estilo'])) {
      $query->condition('field_estilo', $terms['estilo_viaje'][$filters['estilo']]['id']);
    }
    if (!empty($filters['tipo'])) {
      $query->condition('type', $filters['tipo']);
    }
    if (!empty($filters['acompanamiento'])) {
      $query->condition('field_acompanamiento', $filters['acompanamiento']);
    }

    $destination_ids = NULL;
    if (!empty($filters['destino'])) {
      $destination_ids = $this->destinationIdsForSlug($filters['destino']);
    }
    if (!empty($filters['region'])) {
      $region_ids = array_keys(array_filter($this->destinationIndex(), static fn (array $item): bool => $item['region'] === $filters['region']));
      $destination_ids = $destination_ids === NULL ? $region_ids : array_values(array_intersect($destination_ids, $region_ids));
    }
    if ($destination_ids !== NULL) {
      if (!$destination_ids) {
        return FALSE;
      }
      $query->condition('field_destino', $destination_ids, 'IN');
    }

    if (!empty($filters['precio'])) {
      [$min, $max] = self::PRICE_RANGES[$filters['precio']];
      $query->condition($this->priceField(), $min, '>=');
      if ($max !== NULL) {
        $query->condition($this->priceField(), $max, '<=');
      }
    }
    if (!empty($filters['duracion'])) {
      [$min, $max] = self::DURATIONS[$filters['duracion']];
      $query->condition('field_noches', $min, '>=');
      if ($max !== NULL) {
        $query->condition('field_noches', $max, '<=');
      }
    }
    if (!empty($filters['fecha'])) {
      $month_start = $filters['fecha'] . '-01';
      $month_end = date('Y-m-t', strtotime($month_start));
      $today = date('Y-m-d', $this->time->getRequestTime());
      // Both conditions share the same table join, so they apply to the same
      // availability period of a multi-value field.
      $query->condition('field_fechas.value', $month_end, '<=');
      $query->condition('field_fechas.end_value', max($month_start, $today), '>=');
    }
    if (!empty($filters['desde'])) {
      $ids = $this->matchingDateIds($filters);
      if (!$ids) {
        return FALSE;
      }
      $query->condition('nid', $ids, 'IN');
    }
    return TRUE;
  }

  /**
   * A flexible trip can move as a whole, but cannot shrink or bridge periods.
   */
  private function matchingDateIds(array $filters): array {
    $start = new \DateTimeImmutable($filters['desde']);
    $end = new \DateTimeImmutable($filters['hasta']);
    $duration = (int) $start->diff($end)->days;
    $margin = (int) ($filters['margen'] ?? 0);
    $earliest = max($this->today(), $start->modify("-$margin days")->format('Y-m-d'));
    $latest = $start->modify("+$margin days")->format('Y-m-d');
    $ids = [];
    foreach ($this->published() as $node) {
      foreach ($this->availableRanges($node) as $range) {
        $last_start = (new \DateTimeImmutable($range['end']))->modify("-$duration days")->format('Y-m-d');
        if (max($earliest, $range['start']) <= min($latest, $last_start)) {
          $ids[] = (int) $node->id();
          break;
        }
      }
    }
    return $ids;
  }

  private function availableRanges(NodeInterface $node): array {
    $ranges = [];
    foreach ($node->get('field_fechas') as $item) {
      $end = (string) ($item->end_value ?: $item->value);
      if ($end >= $this->today()) {
        $ranges[] = ['start' => max((string) $item->value, $this->today()), 'end' => $end];
      }
    }
    usort($ranges, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
    return $ranges;
  }

  /**
   * Sorting for the catalog and for featured lists.
   */
  private function applySort(QueryInterface $query, string $sort): void {
    match ($sort) {
      'precio-asc' => $query->sort($this->priceField(), 'ASC')->sort('title', 'ASC'),
      'precio-desc' => $query->sort($this->priceField(), 'DESC')->sort('title', 'ASC'),
      default => $query->sort('field_destacada', 'DESC')->sort('sticky', 'DESC')->sort('created', 'DESC')->sort('nid', 'DESC'),
    };
  }

  /**
   * Destination IDs for "department" or "department/municipality" slugs.
   */
  private function destinationIdsForSlug(string $value): array {
    [$department, $municipality] = array_pad(explode('/', $value, 2), 2, NULL);
    $ids = [];
    foreach ($this->destinationIndex() as $id => $destination) {
      if ($destination['department_slug'] !== $department) {
        continue;
      }
      if ($municipality !== NULL && $destination['municipality_slug'] !== $municipality) {
        continue;
      }
      $ids[] = $id;
    }
    return $ids;
  }

  /**
   * Terms of both vocabularies keyed by slug of their source (Spanish) name.
   */
  private function termIndex(): array {
    if ($this->terms !== NULL) {
      return $this->terms;
    }
    $index = ['estilo_viaje' => [], 'tipo_plan' => []];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    foreach (array_keys($index) as $vid) {
      $ids = $storage->getQuery()->accessCheck(TRUE)->condition('vid', $vid)->condition('status', 1)->sort('weight')->sort('name')->execute();
      foreach ($storage->loadMultiple($ids) as $term) {
        if ($term instanceof TermInterface) {
          $index[$vid][$this->slug($term->getUntranslated()->label())] = $this->termData($term);
        }
      }
    }
    return $this->terms = $index;
  }

  /**
   * Presentation data for a term.
   */
  private function termData(TermInterface $term): array {
    $translated = $this->translated($term);
    return [
      'id' => (int) $term->id(),
      'slug' => $this->slug($term->getUntranslated()->label()),
      'label' => $translated->label(),
      'icon' => $term->hasField('field_icono') ? (string) ($term->get('field_icono')->value ?? 'sun') : '',
    ];
  }

  /**
   * Several referenced terms as presentation data.
   */
  private function referencedTerms(NodeInterface $node, string $field): array {
    $terms = [];
    foreach ($node->get($field)->referencedEntities() as $term) {
      if ($term instanceof TermInterface) {
        $terms[] = $this->termData($term);
      }
    }
    return $terms;
  }

  /**
   * All published experiences (for option counts).
   */
  private function published(): array {
    if ($this->published === NULL) {
      $ids = $this->baseQuery()->execute();
      $this->published = $ids ? $this->nodeStorage()->loadMultiple($ids) : [];
    }
    return $this->published;
  }

  /**
   * An image field item rendered through an image style.
   */
  private function image(ImageItem $item, string $style, int $width, int $height): ?array {
    $url = $this->styleUrl($item, $style);
    if (!$url) {
      return NULL;
    }
    return [
      'src' => $url,
      'alt' => (string) ($item->alt ?? ''),
      'width' => $width,
      'height' => $height,
    ];
  }

  /**
   * Root-relative URL of an image style derivative.
   */
  private function styleUrl(ImageItem $item, string $style_name): ?string {
    $file = $item->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    $style = ImageStyle::load($style_name);
    $uri = $file->getFileUri();
    $url = $style ? $style->buildUrl($uri) : $this->fileUrlGenerator->generateAbsoluteString($uri);
    return $this->fileUrlGenerator->transformRelative($url);
  }

  /**
   * Processed text field, ready to render.
   */
  private function processed(NodeInterface $node, string $field): ?array {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return NULL;
    }
    $item = $node->get($field)->first();
    return [
      '#type' => 'processed_text',
      '#text' => $item->value,
      '#format' => $item->format ?: 'basic_html',
      '#langcode' => $node->language()->getId(),
    ];
  }

  /**
   * Values of a multi-value string field.
   */
  private function stringValues(NodeInterface $node, string $field): array {
    $values = [];
    foreach ($node->get($field) as $item) {
      if (trim((string) $item->value) !== '') {
        $values[] = (string) $item->value;
      }
    }
    return $values;
  }

  /**
   * A short plain-text summary of a node body.
   */
  private function summary(NodeInterface $node): string {
    if (!$node->hasField('body') || $node->get('body')->isEmpty()) {
      return '';
    }
    $item = $node->get('body')->first();
    $text = trim(strip_tags((string) ($item->summary ?: $item->value)));
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    if (mb_strlen($text) <= 180) {
      return $text;
    }
    $cut = mb_substr($text, 0, 180);
    return rtrim(mb_substr($cut, 0, (int) mb_strrpos($cut, ' ')), ' ,.;:') . '…';
  }

  /**
   * Long date for the current language: "12 de diciembre de 2026".
   */
  private function formatDate(string $ymd, string $langcode): string {
    $date = new \DateTimeImmutable($ymd);
    $month = $this->monthName((int) $date->format('n'), $langcode);
    if ($langcode === 'en') {
      return $month . ' ' . $date->format('j, Y');
    }
    return $date->format('j') . ' de ' . $month . ' de ' . $date->format('Y');
  }

  /**
   * Month label for selects: "Diciembre 2026".
   */
  private function formatMonth(\DateTimeImmutable $date): string {
    $month = $this->monthName((int) $date->format('n'), $this->currentLangcode());
    return mb_strtoupper(mb_substr($month, 0, 1)) . mb_substr($month, 1) . ' ' . $date->format('Y');
  }

  /**
   * Month names without depending on server locales.
   */
  private function monthName(int $month, string $langcode): string {
    $names = $langcode === 'en'
      ? ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
      : ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return $names[$month - 1];
  }

  /**
   * The current content language.
   */
  private function currentLangcode(): string {
    return $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
  }

  /**
   * The entity in the current content language, when it is translated.
   *
   * @template T of \Drupal\Core\Entity\EntityInterface
   * @param T $entity
   * @return T
   */
  private function translated($entity) {
    return $this->entityRepository->getTranslationFromContext($entity);
  }

  /**
   * Node storage.
   */
  private function nodeStorage() {
    return $this->entityTypeManager->getStorage('node');
  }

}
