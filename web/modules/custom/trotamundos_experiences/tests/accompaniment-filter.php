<?php

declare(strict_types=1);

/**
 * @file
 * Run with drush php:script. All fixture writes are rolled back.
 */

use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\Request;

if (PHP_SAPI !== 'cli' || !class_exists('Drupal')) {
  throw new RuntimeException('Run this test with drush php:script.');
}

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};

$transaction = \Drupal::database()->startTransaction();
$created_ids = [];
$storage = \Drupal::entityTypeManager()->getStorage('node');

try {
  $destination = Node::create([
    'type' => 'destino',
    'title' => 'Companion filter test',
    'langcode' => 'es',
    'status' => 1,
    'field_municipio' => 'Companion filter test',
    'field_departamento' => 'Test department',
    'field_region' => 'andina',
  ]);
  $destination->save();
  $created_ids[] = $destination->id();

  $fixtures = [];
  foreach (['couple-family' => ['pareja', 'familia'], 'solo' => ['solo'], 'unassigned' => [], 'draft' => ['grupo']] as $name => $companions) {
    $node = Node::create([
      'type' => 'experiencia',
      'title' => 'Companion filter test ' . $name,
      'langcode' => 'es',
      'status' => $name === 'draft' ? 0 : 1,
      'field_destino' => $destination->id(),
      'field_acompanamiento' => $companions,
    ]);
    $node->save();
    $created_ids[] = $node->id();
    $fixtures[$name] = (int) $node->id();
  }

  $repository = \Drupal::service('trotamundos_experiences.repository');
  $place = $repository->destinationIndex()[(int) $destination->id()];
  $destination_slug = $place['department_slug'] . '/' . $place['municipality_slug'];
  $cases = [
    '' => [$fixtures['couple-family'], $fixtures['solo'], $fixtures['unassigned']],
    'pareja' => [$fixtures['couple-family']],
    'familia' => [$fixtures['couple-family']],
    'solo' => [$fixtures['solo']],
    'grupo' => [],
  ];

  foreach ($cases as $companion => $expected) {
    $filters = $repository->filtersFromRequest(Request::create('/experiencias', 'GET', [
      'destino' => $destination_slug,
      'acompanamiento' => $companion,
    ]));
    $result = $repository->search($filters);
    $actual = array_column($result['cards'], 'id');
    sort($actual);
    sort($expected);
    $assert($actual === $expected && $result['total'] === count($expected), 'Incorrect companion results: ' . $companion);
  }

  $filters = $repository->filtersFromRequest(Request::create('/experiencias', 'GET', [
    'destino' => $destination_slug,
    'acompanamiento' => 'grupo',
  ]));
  $options = $repository->filterOptions($filters);
  $selected = array_filter($options['companions'], static fn (array $option): bool => $option['selected']);
  $assert(count($selected) === 1 && reset($selected)['value'] === 'grupo', 'A modality without results must remain selectable.');

  $active = array_values(array_filter($repository->activeFilters($filters, $options), static fn (array $filter): bool => $filter['key'] === 'acompanamiento'));
  $assert(count($active) === 1, 'The companion filter must have a removable active chip.');
  parse_str((string) parse_url($active[0]['remove_url'], PHP_URL_QUERY), $remaining);
  $assert(!isset($remaining['acompanamiento']) && ($remaining['destino'] ?? '') === $destination_slug, 'Removing companions must preserve the destination.');

  $invalid = $repository->filtersFromRequest(Request::create('/experiencias', 'GET', ['acompanamiento' => 'unconfirmed']));
  $assert($invalid['acompanamiento'] === '', 'Unknown companion values must not reach the query.');

  print "Companion filters passed: optional and multiple values, unpublished content, empty results and filter removal.\n";
}
finally {
  $transaction->rollBack();
  $storage->resetCache($created_ids);
  \Drupal::service('path_alias.manager')->cacheClear();
}
