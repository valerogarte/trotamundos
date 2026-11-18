<?php

declare(strict_types=1);

/**
 * @file
 * Checks the destination atlas without changing content.
 *
 * Run with drush php:script after provisioning the catalog.
 */

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

if (PHP_SAPI !== 'cli' || !class_exists('Drupal')) {
  throw new RuntimeException('Run this test with drush php:script.');
}

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$xpath_for = static function (string $html): DOMXPath {
  $document = new DOMDocument();
  if (!$document->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
    throw new RuntimeException('The destination page did not render HTML.');
  }
  return new DOMXPath($document);
};

$request = Request::create('/destinos');
$response = \Drupal::service('http_kernel')->handle($request, HttpKernelInterface::SUB_REQUEST);
$assert($response->getStatusCode() === 200, 'The destination page did not respond successfully.');
$xpath = $xpath_for($response->getContent());
$repository = \Drupal::service('trotamundos_experiences.repository');
$tree = $repository->destinationTree();
$atlas = _trotamundos_destination_atlas($tree);
$assert($atlas['places'] !== [], 'The test requires a published destination.');
$assert($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " destination-atlas__place ")]')->length === count($atlas['places']), 'The directory must include every published destination.');
$assert($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " destination-tile ")]')->length === count($atlas['places']), 'The regional directory must include every published destination.');
$assert($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " destination-atlas__marker ")]')->length === $atlas['mapped'], 'Every mappable destination must have a marker.');
foreach ($atlas['places'] as $place) {
  $id = (int) $place['id'];
  $directory_link = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " destination-atlas__place ") and @data-atlas-place="' . $id . '"]')->item(0);
  $assert($directory_link instanceof DOMElement && $directory_link->getAttribute('href') === $place['url'], "Destination $id must link to its detail page.");
  if ($place['map'] !== NULL) {
    $marker = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " destination-atlas__marker ") and @data-atlas-place="' . $id . '"]')->item(0);
    $assert($marker instanceof DOMElement && $marker->getAttribute('href') === $place['url'], "Marker $id must link to its detail page.");
  }
}

$base = $atlas['places'][0];
$examples = [];
foreach ([
  [1001, 6.395, -75.258],
  [1002, 6.395, -75.258],
  [1003, 6.395, -75.258],
  [1004, 12.5847, -81.7006],
  [1005, NULL, NULL],
  [1006, 'invalid', -75],
  [1007, 0, 0],
] as [$id, $latitude, $longitude]) {
  $examples[] = array_replace($base, [
    'id' => $id,
    'title' => 'Destino ' . $id,
    'place' => 'Destino ' . $id,
    'url' => '/destinos/' . $id,
    'latitude' => $latitude,
    'longitude' => $longitude,
    'count' => 0,
  ]);
}
$test_tree = [[
  'key' => 'andina',
  'label' => 'Región Andina',
  'count' => 0,
  'departments' => [[
    'label' => 'Antioquia',
    'slug' => 'antioquia',
    'count' => 0,
    'destinations' => $examples,
  ]],
]];
$test_atlas = _trotamundos_destination_atlas($test_tree);
$assert(count($test_atlas['places']) === 7 && $test_atlas['mapped'] === 4, 'Missing or invalid coordinates must not remove destinations from the directory.');
$assert($test_atlas['outline_left'] > 0 && $test_atlas['outline_top'] > 0, 'The map must expand to include offshore destinations.');
$labels = [];
foreach (array_slice($test_atlas['places'], 0, 4) as $place) {
  $point = $place['map'];
  $assert($point !== NULL && $point['x'] >= 0 && $point['x'] <= 100 && $point['y'] >= 0 && $point['y'] <= 100, 'A valid destination must be inside the map.');
  $labels[] = $point['label_x'] . ':' . $point['label_y'];
}
$assert(count(array_unique($labels)) === 4, 'Destinations with identical coordinates must have distinct map links.');
$assert($test_atlas['places'][0]['map']['x'] === $test_atlas['places'][1]['map']['x'] && $test_atlas['places'][0]['map']['y'] === $test_atlas['places'][1]['map']['y'], 'Separated labels must retain their true geographic point.');
foreach ([0, 3] as $index) {
  $original = _trotamundos_map_point($examples[$index]);
  $point = $test_atlas['places'][$index]['map'];
  $expected_x = $original['x'] * 6 / $test_atlas['width'] * 100 + $test_atlas['outline_left'];
  $expected_y = $original['y'] * 6.6 / $test_atlas['height'] * 100 + $test_atlas['outline_top'];
  $assert(abs($point['x'] - $expected_x) < 0.01 && abs($point['y'] - $expected_y) < 0.01, 'Map points must retain the outline projection, including offshore coordinates.');
}
foreach (array_slice($test_atlas['places'], 4) as $place) {
  $assert($place['map'] === NULL, 'Missing, malformed or out-of-area coordinates must not be plotted.');
}

$test_tree[0]['departments'][0]['destinations'] = array_slice($examples, 4);
$unmapped = _trotamundos_destination_atlas($test_tree);
$html = \Drupal::service('twig')->render('@trotamundos/pages/trotamundos-destinations.html.twig', [
  'tree' => $test_tree,
  'atlas' => $unmapped,
  'total' => 0,
  'catalog_url' => '/planes',
]);
$unmapped_xpath = $xpath_for($html);
$assert($unmapped_xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " destination-atlas__place ")]')->length === 3, 'Unmapped destinations must remain accessible.');
$assert($unmapped_xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " destination-atlas__missing ")]')->length === 3, 'Unmapped destinations must identify the missing location.');
$assert($unmapped_xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " destination-atlas__map-empty ")]')->length === 1, 'An empty map must explain that the directory is still available.');

$empty = \Drupal::service('twig')->render('@trotamundos/pages/trotamundos-destinations.html.twig', [
  'tree' => [],
  'atlas' => _trotamundos_destination_atlas([]),
  'total' => 0,
  'catalog_url' => '/planes',
]);
$empty_xpath = $xpath_for($empty);
$assert($empty_xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " destinations-empty ")]')->length === 1 && $empty_xpath->query('//*[@data-destination-atlas]')->length === 0, 'An empty catalog must show the empty state instead of an empty atlas.');

echo "Destination atlas checks passed.\n";
