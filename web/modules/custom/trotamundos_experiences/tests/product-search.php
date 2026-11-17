<?php

declare(strict_types=1);

/**
 * @file
 * Run with drush php:script after installing the shared product definitions.
 * Fixture content and migration writes are rolled back.
 */

use Drupal\node\Entity\Node;
use Drupal\trotamundos_experiences\Setup\ContentModel;
use Drupal\trotamundos_experiences\Setup\ProductTypes;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

if (PHP_SAPI !== 'cli' || !class_exists('Drupal')) {
  throw new RuntimeException('Run this test with drush php:script.');
}

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$storage = \Drupal::entityTypeManager()->getStorage('node');
$style_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$transaction = \Drupal::database()->startTransaction();
$create = static function (array $values): Node {
  $node = Node::create($values + ['langcode' => 'es', 'status' => 1]);
  $node->save();
  return $node;
};

try {
  $cultures = $style_storage->loadByProperties(['vid' => 'estilo_viaje', 'name' => ['Cultura viva', 'Cultura y tradición']]);
  $assert(count($cultures) === 1, 'The cultural style must have one existing identity.');
  $culture = reset($cultures);
  $culture_id = $culture->id();
  $culture->setName('Cultura y tradición');
  $culture->getTranslation('en')->setName('Culture and tradition');
  $culture->save();
  $coffee = $style_storage->loadByProperties(['vid' => 'estilo_viaje', 'name' => 'Café y tradición']);
  $style_storage->delete($coffee);
  $references = [];
  $product_ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', ProductTypes::BUNDLES, 'IN')->execute();
  foreach ($storage->loadMultiple($product_ids) as $node) {
    $references[$node->id()] = $node->get('field_estilo')->getValue();
  }
  ContentModel::ensureTravelStyles(TRUE);
  $cultures = $style_storage->loadByProperties(['vid' => 'estilo_viaje', 'name' => 'Cultura viva']);
  $assert(count($cultures) === 1 && reset($cultures)->id() === $culture_id && reset($cultures)->getTranslation('en')->getName() === 'Living culture', 'Renaming the cultural style lost its identity or translation.');
  $coffee = $style_storage->loadByProperties(['vid' => 'estilo_viaje', 'name' => 'Café y tradición']);
  $assert(count($coffee) === 1 && reset($coffee)->getTranslation('en')->getName() === 'Coffee and tradition', 'The missing coffee style was not created with its translation.');
  $style_ids = array_keys($style_storage->loadByProperties(['vid' => 'estilo_viaje']));
  ContentModel::ensureTravelStyles(TRUE);
  $assert(array_keys($style_storage->loadByProperties(['vid' => 'estilo_viaje'])) === $style_ids, 'Travel-style setup created duplicate terms on repetition.');
  foreach ($references as $id => $values) {
    $assert($storage->loadUnchanged($id)->get('field_estilo')->getValue() === $values, 'Travel-style setup changed existing product assignments.');
  }

  $alias_storage = \Drupal::entityTypeManager()->getStorage('path_alias');
  $catalog_aliases = ['/planes' => '/plans', '/alojamientos' => '/stays', '/experiencias' => '/experiences', '/paquetes' => '/packages'];
  $alias_storage->delete($alias_storage->loadByProperties(['path' => array_keys($catalog_aliases), 'langcode' => 'en']));
  ProductTypes::ensure();
  ProductTypes::ensure();
  foreach ($catalog_aliases as $path => $alias) {
    $installed = $alias_storage->loadByProperties(['path' => $path, 'langcode' => 'en']);
    $assert(count($installed) === 1 && reset($installed)->getAlias() === $alias, 'Setup must install each English catalog alias exactly once, independently of copied role permissions.');
  }

  $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'tipo_plan']);
  $term_ids = [];
  foreach ($terms as $term) {
    $term_ids[$term->getUntranslated()->label()] = $term->id();
  }
  $legacy = [];
  foreach (['stay' => 'Alojamiento', 'package' => 'Paquete', 'experience' => 'Pasadía', 'draft' => 'Alojamiento'] as $name => $type) {
    $legacy[$name] = $create([
      'type' => 'experiencia',
      'title' => 'Product migration fixture ' . $name,
      'status' => $name === 'draft' ? 0 : 1,
      'field_tipo_plan' => $term_ids[$type],
      'field_resumen' => 'Resumen original.',
    ]);
  }
  $stay = $legacy['stay'];
  $stay->addTranslation('en', ['title' => 'Translated migration fixture', 'field_resumen' => 'Original translated summary.']);
  $stay->set('field_acompanamiento', ['pareja', 'familia']);
  $stay->setNewRevision(TRUE);
  $stay->setRevisionLogMessage('Migration fixture revision.');
  $stay->save();
  $revision_ids = $storage->revisionIds($stay);
  $uuid = $stay->uuid();
  $aliases = [];
  foreach (['es', 'en'] as $langcode) {
    $aliases[$langcode] = $stay->getTranslation($langcode)->get('path')->alias;
  }
  ProductTypes::migrate();
  $expected_types = ['stay' => 'alojamiento', 'package' => 'paquete', 'experience' => 'experiencia', 'draft' => 'alojamiento'];
  foreach ($legacy as $name => $node) {
    $converted = $storage->loadUnchanged($node->id());
    $assert($converted->bundle() === $expected_types[$name], 'Incorrect migrated bundle: ' . $name);
    $assert($converted->isPublished() === ($name !== 'draft'), 'Migration changed publication status.');
  }
  $stay = $storage->loadUnchanged($stay->id());
  $assert($stay->uuid() === $uuid && $storage->revisionIds($stay) === $revision_ids, 'Migration changed identity or revision history.');
  $assert(array_column($stay->get('field_acompanamiento')->getValue(), 'value') === ['pareja', 'familia'], 'Migration changed multivalue fields.');
  $assert($storage->loadUnchanged($legacy['package']->id())->get('field_acompanamiento')->isEmpty(), 'Migration assigned an unreviewed companion.');
  foreach ($aliases as $langcode => $alias) {
    $assert($stay->getTranslation($langcode)->get('path')->alias === $alias, 'Migration changed a translated alias.');
  }
  foreach ($revision_ids as $revision_id) {
    $assert($storage->loadRevision($revision_id)->bundle() === 'alojamiento', 'A historical revision retained the wrong bundle.');
  }
  $assert($stay->getTranslation('en')->get('field_resumen')->value === 'Original translated summary.', 'Migration changed translated content.');
  $assert(ProductTypes::migrate() === 0, 'Migration is not idempotent.');

  $destination = $create([
    'type' => 'destino',
    'title' => 'Product search fixture',
    'field_municipio' => 'Product search fixture',
    'field_departamento' => 'Test department',
    'field_region' => 'andina',
  ]);
  $today = new DateTimeImmutable(\Drupal::service('trotamundos_experiences.repository')->today());
  $date = static fn (int $offset): string => $today->modify(sprintf('%+d days', $offset))->format('Y-m-d');
  $dated = [];
  $offsets = [-15, -14, -8, -7, -4, -3, -2, -1, 0, 1, 2, 3, 4, 7, 8, 14, 15];
  foreach ($offsets as $index => $offset) {
    $bundle = ProductTypes::BUNDLES[$index % 3];
    $node = $create([
      'type' => $bundle,
      'title' => 'Product range fixture ' . $index,
      'field_destino' => $destination->id(),
      'field_acompanamiento' => ['grupo'],
      'field_fechas' => [['value' => $date(30 + $offset), 'end_value' => $date(34 + $offset)]],
    ]);
    $dated[$offset] = ['id' => (int) $node->id(), 'bundle' => $bundle];
    if ($index < 3) {
      $prefix = ['alojamiento' => '/alojamientos/', 'experiencia' => '/experiencias/', 'paquete' => '/paquetes/'][$bundle];
      $assert(str_starts_with($node->get('path')->alias, $prefix), 'A new product received the wrong alias prefix.');
    }
  }
  foreach ([
    'short' => [['value' => $date(30), 'end_value' => $date(32)]],
    'split' => [['value' => $date(30), 'end_value' => $date(31)], ['value' => $date(33), 'end_value' => $date(34)]],
    'draft' => [['value' => $date(10), 'end_value' => $date(60)]],
  ] as $name => $ranges) {
    $create([
      'type' => 'alojamiento',
      'title' => 'Product invalid range fixture ' . $name,
      'status' => $name === 'draft' ? 0 : 1,
      'field_destino' => $destination->id(),
      'field_acompanamiento' => ['grupo'],
      'field_fechas' => $ranges,
    ]);
  }
  $clipped = $create([
    'type' => 'paquete',
    'title' => 'Product past shift fixture',
    'field_destino' => $destination->id(),
    'field_acompanamiento' => ['pareja'],
    'field_fechas' => [['value' => $date(-3), 'end_value' => $date(1)]],
  ]);
  $create([
    'type' => 'experiencia',
    'title' => 'Product past availability fixture',
    'field_destino' => $destination->id(),
    'field_acompanamiento' => ['pareja'],
    'field_fechas' => [['value' => $date(-30), 'end_value' => $date(-1)]],
  ]);

  $repository = \Drupal::service('trotamundos_experiences.repository');
  $place = $repository->destinationIndex()[(int) $destination->id()];
  $slug = $place['department_slug'] . '/' . $place['municipality_slug'];
  $base = ['destino' => $slug, 'acompanamiento' => 'grupo', 'desde' => $date(30), 'hasta' => $date(34)];
  $legacy_style = $repository->filtersFromRequest(Request::create('/planes', 'GET', ['estilo' => 'cultura-y-tradicion']));
  $assert($legacy_style['estilo'] === 'cultura-viva', 'An existing cultural-style bookmark lost its filter.');
  $style_options = array_column($repository->filterOptions([])['styles'], NULL, 'slug');
  $assert(isset($style_options['cafe-y-tradicion']) && $style_options['cafe-y-tradicion']['count'] === 0, 'Unassigned travel styles must remain available to the homepage slider and catalog.');
  foreach ([0, 1, 2, 3, 7, 14] as $margin) {
    $filters = $repository->filtersFromRequest(Request::create('/planes', 'GET', $base + ['margen' => (string) $margin]));
    $expected = array_column(array_filter($dated, static fn (array $item, int $offset): bool => abs($offset) <= $margin, ARRAY_FILTER_USE_BOTH), 'id');
    $result = $repository->search($filters, count($dated));
    $actual = array_column($result['cards'], 'id');
    sort($actual);
    sort($expected);
    $assert($actual === $expected && $result['total'] === count($expected), 'Incorrect whole-range matching at margin ' . $margin);
  }
  foreach (ProductTypes::BUNDLES as $bundle) {
    $filters = $repository->filtersFromRequest(Request::create('/planes', 'GET', $base + ['tipo' => $bundle, 'margen' => '3']));
    $actual = array_column($repository->search($filters)['cards'], 'id');
    $expected = array_column(array_filter($dated, static fn (array $item, int $offset): bool => $item['bundle'] === $bundle && abs($offset) <= 3, ARRAY_FILTER_USE_BOTH), 'id');
    sort($actual);
    sort($expected);
    $assert($actual === $expected, 'A product listing mixed content types: ' . $bundle);
  }
  $near_today = ['destino' => $slug, 'acompanamiento' => 'pareja', 'desde' => $date(0), 'hasta' => $date(4), 'margen' => '14'];
  $filters = $repository->filtersFromRequest(Request::create('/planes', 'GET', $near_today));
  $assert($repository->search($filters)['total'] === 0, 'Flexibility moved the trip into the past.');
  $near_today['hasta'] = $date(0);
  $near_today['margen'] = '';
  $filters = $repository->filtersFromRequest(Request::create('/planes', 'GET', $near_today));
  $assert(array_column($repository->search($filters)['cards'], 'id') === [(int) $clipped->id()], 'A same-day trip must remain valid.');

  foreach ([
    ['desde' => $date(30)],
    ['hasta' => $date(34)],
    ['desde' => $date(-1), 'hasta' => $date(4)],
    ['desde' => $date(34), 'hasta' => $date(30)],
    ['desde' => '2030-02-30', 'hasta' => '2030-03-01'],
    ['margen' => '1'],
    ['desde' => $date(30), 'hasta' => $date(34), 'margen' => '4'],
    ['desde' => [$date(30)], 'hasta' => $date(34)],
  ] as $invalid) {
    $rejected = FALSE;
    try {
      $repository->filtersFromRequest(Request::create('/planes', 'GET', $invalid));
    }
    catch (BadRequestHttpException | BadRequestException) {
      $rejected = TRUE;
    }
    $assert($rejected, 'An invalid or incomplete date range was accepted.');
  }
  $filters = $repository->filtersFromRequest(Request::create('/planes', 'GET', $base + ['margen' => '7', 'fecha' => $today->format('Y-m'), 'orden' => 'precio-asc']));
  $assert($filters['fecha'] === '', 'An explicit range must replace the legacy month filter.');
  $active = $repository->activeFilters($filters, $repository->filterOptions($filters), ProductTypes::ROUTES['alojamiento']);
  $range = array_values(array_filter($active, static fn (array $item): bool => $item['key'] === 'fechas'));
  $assert(count($range) === 1, 'The range and margin must form one removable filter.');
  parse_str((string) parse_url($range[0]['remove_url'], PHP_URL_QUERY), $remaining);
  $assert(parse_url($range[0]['remove_url'], PHP_URL_PATH) === '/alojamientos', 'Removing a filter lost the product page.');
  $assert(!isset($remaining['desde']) && !isset($remaining['hasta']) && !isset($remaining['margen']) && ($remaining['destino'] ?? '') === $slug && ($remaining['acompanamiento'] ?? '') === 'grupo' && ($remaining['orden'] ?? '') === 'precio-asc', 'Removing dates lost other filters.');

  print "Product search passed: idempotent travel-style migration and legacy filters, English catalog setup, lossless multilingual migration, revisions and aliases, real bundle listings, exact/flexible complete ranges, invalid inputs and scoped filter removal.\n";
}
finally {
  $transaction->rollBack();
  $storage->resetCache();
  $style_storage->resetCache();
  \Drupal::service('path_alias.manager')->cacheClear();
}
