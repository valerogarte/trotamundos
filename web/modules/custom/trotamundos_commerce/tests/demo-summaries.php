<?php

declare(strict_types=1);

/**
 * @file
 * Checks demo-summary refreshes without writing to the Drupal database.
 */

use Drupal\trotamundos_commerce\Setup\DemoCatalog;
use Symfony\Component\DependencyInjection\Container;

if (PHP_SAPI !== 'cli' || !class_exists('Drupal')) {
  throw new RuntimeException('Run this test with drush php:script.');
}

class DemoSummaryTranslationFixture {

  public function __construct(public string $title, public string $summary) {}

  public function label(): string {
    return $this->title;
  }

  public function get(string $field): object {
    return (object) ['value' => $this->summary];
  }

  public function set(string $field, string $value): void {
    $this->summary = $value;
  }

}

class DemoSummaryNodeFixture {

  public int $saves = 0;
  public bool $newRevision = FALSE;
  public int $latestRevision = 1;

  public function __construct(public string $type, public bool $example, public array $translations) {}

  public function bundle(): string {
    return $this->type;
  }

  public function id(): int {
    return spl_object_id($this);
  }

  public function getRevisionId(): int {
    return 1;
  }

  public function get(string $field): object {
    return (object) ['value' => $this->example];
  }

  public function hasTranslation(string $langcode): bool {
    return isset($this->translations[$langcode]);
  }

  public function getTranslation(string $langcode): DemoSummaryTranslationFixture {
    return $this->translations[$langcode];
  }

  public function setNewRevision(bool $new_revision): void {
    $this->newRevision = $new_revision;
  }

  public function setRevisionLogMessage(string $message): void {}

  public function save(): void {
    $this->saves++;
  }

}

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$spanish = static fn (string $title, string $place): DemoSummaryTranslationFixture => new DemoSummaryTranslationFixture(
  $title,
  $title . ': una propuesta de demostración para descubrir ' . $place . ' con calma y mirada local.',
);
$english = static fn (string $title): DemoSummaryTranslationFixture => new DemoSummaryTranslationFixture(
  $title,
  $title . ': a demonstration itinerary with a slow pace and a local perspective.',
);
$nodes = [
  'eligible' => new DemoSummaryNodeFixture('alojamiento', TRUE, [
    'es' => $spanish('Posada de los balcones en Concepción', 'Concepción'),
    'en' => new DemoSummaryTranslationFixture('Balcony guesthouse in Concepción', 'An editorial English summary.'),
  ]),
  'edited-title' => new DemoSummaryNodeFixture('alojamiento', TRUE, [
    'es' => $spanish('Cabaña junto al bosque de Nariño', 'Nariño'),
    'en' => $english('Forest cabin in Nariño'),
  ]),
  'not-demo' => new DemoSummaryNodeFixture('alojamiento', FALSE, [
    'es' => $spanish('Estancia cafetera entre montañas', 'Cocora'),
  ]),
  'wrong-type' => new DemoSummaryNodeFixture('experiencia', TRUE, [
    'es' => $spanish('Refugio de descanso en Santa Rosa', 'Santa Rosa'),
  ]),
  'no-english' => new DemoSummaryNodeFixture('alojamiento', TRUE, [
    'es' => $spanish('Ecoestancia de brisa caribeña', 'Rosario'),
  ]),
  'edited-summary' => new DemoSummaryNodeFixture('alojamiento', TRUE, [
    'es' => new DemoSummaryTranslationFixture('Mirador de las palmas de cera', 'Un texto editado por el equipo.'),
  ]),
  'pending-draft' => new DemoSummaryNodeFixture('alojamiento', TRUE, [
    'es' => $spanish('Alojamiento de río y silencio', 'Nariño'),
  ]),
];
$nodes['edited-title']->translations['es']->title = 'Otro nombre editorial';
$nodes['pending-draft']->latestRevision = 2;
$ledger = [
  'alojamiento:0' => 'eligible',
  'alojamiento:1' => 'edited-title',
  'alojamiento:2' => 'not-demo',
  'alojamiento:3' => 'wrong-type',
  'alojamiento:4' => 'no-english',
  'alojamiento:5' => 'missing-node',
  'alojamiento:6' => 'edited-summary',
  'alojamiento:7' => 'pending-draft',
];
$container = new Container();
$container->set('entity_type.manager', new class($nodes) {
  public function __construct(private array $nodes) {}
  public function getStorage(string $type): object {
    return new class($this->nodes) {
      public function __construct(private array $nodes) {}
      public function loadByProperties(array $properties): array {
        return isset($this->nodes[$properties['uuid']]) ? [$this->nodes[$properties['uuid']]] : [];
      }
      public function getLatestRevisionId(int $id): ?int {
        foreach ($this->nodes as $node) {
          if ($node->id() === $id) {
            return $node->latestRevision;
          }
        }
        return NULL;
      }
    };
  }
});
$container->set('keyvalue', new class($ledger) {
  public function __construct(private array $values) {}
  public function get(string $collection): object {
    return new class($this->values) {
      public function __construct(private array $values) {}
      public function get(string $key): ?string {
        return $this->values[$key] ?? NULL;
      }
    };
  }
});

$original_container = \Drupal::getContainer();
\Drupal::setContainer($container);
try {
  $assert(DemoCatalog::refreshDefaultSummaries() === 3, 'Only eligible demo nodes must be revised.');
  $assert($nodes['eligible']->translations['es']->summary === 'Despierta entre balcones y regálate tiempo para descubrir el alma de Concepción.', 'An untouched Spanish summary was not refreshed.');
  $assert($nodes['eligible']->translations['en']->summary === 'An editorial English summary.', 'An edited English summary was overwritten.');
  $assert($nodes['edited-title']->translations['es']->summary === $spanish('Cabaña junto al bosque de Nariño', 'Nariño')->summary, 'A summary with an edited title was overwritten.');
  $assert($nodes['edited-title']->translations['en']->summary === 'A pause by the forest to breathe deeply and enjoy the greenery of Nariño.', 'An untouched English translation was not refreshed independently.');
  $assert(!isset($nodes['no-english']->translations['en']), 'A missing translation must not be created.');
  foreach (['not-demo', 'wrong-type', 'edited-summary', 'pending-draft'] as $name) {
    $assert($nodes[$name]->saves === 0, 'An ineligible or edited node was saved: ' . $name);
  }
  foreach (['eligible', 'edited-title', 'no-english'] as $name) {
    $assert($nodes[$name]->saves === 1 && $nodes[$name]->newRevision, 'A changed node must receive exactly one new revision.');
  }
  $assert(DemoCatalog::refreshDefaultSummaries() === 0, 'Refreshing summaries twice must be idempotent.');
}
finally {
  \Drupal::setContainer($original_container);
}

echo "Demo summary preservation checks passed.\n";
