<?php

declare(strict_types=1);

/**
 * @file
 * Checks demo-text updates without writing to the Drupal database.
 */

use Drupal\trotamundos_commerce\Setup\DemoCatalog;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\ConstraintViolationList;

if (PHP_SAPI !== 'cli' || !class_exists('Drupal')) {
  throw new RuntimeException('Run this test with drush php:script.');
}

class DemoMessageFieldFixture {

  public function __construct(private array $values) {}

  public function getValue(): array {
    return $this->values;
  }

}

class DemoMessageTranslationFixture {

  public function __construct(public string $title, public array $fields) {}

  public function label(): string {
    return $this->title;
  }

  public function get(string $field): DemoMessageFieldFixture {
    return new DemoMessageFieldFixture($this->fields[$field]);
  }

  public function set(string $field, array $items): void {
    $this->fields[$field] = $items;
  }

}

class DemoMessageNodeFixture {

  public int $saves = 0;
  public int $latestRevision = 1;
  public bool $newRevision = FALSE;
  public ?string $untranslatable = NULL;

  public function __construct(public string $type, public bool $example, public bool $published, public array $translations) {}

  public function bundle(): string {
    return $this->type;
  }

  public function id(): int {
    return spl_object_id($this);
  }

  public function getRevisionId(): int {
    return 1;
  }

  public function isPublished(): bool {
    return $this->published;
  }

  public function get(string $field): object {
    return (object) ['value' => $this->example];
  }

  public function getFieldDefinition(string $field): object {
    return new class($field !== $this->untranslatable) {
      public function __construct(private bool $translatable) {}
      public function isTranslatable(): bool {
        return $this->translatable;
      }
    };
  }

  public function hasTranslation(string $langcode): bool {
    return isset($this->translations[$langcode]);
  }

  public function getTranslation(string $langcode): DemoMessageTranslationFixture {
    return $this->translations[$langcode];
  }

  public function validate(): ConstraintViolationList {
    return new ConstraintViolationList();
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
$spanish = static fn (string $title, string $place, string $style): DemoMessageTranslationFixture => new DemoMessageTranslationFixture($title, [
  'body' => [['value' => $title . "\n\nUn itinerario de muestra inspirado en " . mb_strtolower($style) . '. La propuesta combina tiempo para explorar ' . $place . " y encuentros de proximidad.\n\nContenido DEMO: alojamiento, actividades, anfitriones, precios y fechas deben confirmarse antes de ofrecer este plan como un servicio real.", 'format' => 'plain_text', 'summary' => 'Resumen editorial']],
  'field_precio_nota' => [['value' => 'Precio DEMO por persona; no constituye una oferta confirmada.']],
  'field_disponibilidad' => [['value' => 'Calendario de demostración, sin cupos reales confirmados.']],
  'field_incluye' => [['value' => 'Propuesta de itinerario de demostración'], ['value' => 'Elemento añadido por el equipo']],
  'field_politica_reserva' => [['value' => 'Ficha DEMO. No confirma disponibilidad ni una reserva. Antes de vender, verificar servicios, tarifa, condiciones y pasarela de pago.', 'format' => 'plain_text']],
  'field_galeria' => [
    ['target_id' => 101, 'alt' => 'Ambientación del catálogo demo; no representa un establecimiento confirmado.', 'title' => 'Foto', 'width' => 960, 'height' => 640],
    ['target_id' => 102, 'alt' => 'Fotografía elegida por el equipo', 'title' => 'Otra'],
  ],
  'field_precio' => [['value' => '240000']],
]);
$english = static fn (string $title): DemoMessageTranslationFixture => new DemoMessageTranslationFixture($title, [
  'body' => [['value' => $title . "\n\nA sample itinerary with time to explore and discover local life.\n\nDEMO content: accommodation, activities, hosts, prices and dates require confirmation before this plan can be offered as a real service.", 'format' => 'plain_text']],
  'field_precio_nota' => [['value' => 'DEMO price per person; not a confirmed offer.']],
  'field_disponibilidad' => [['value' => 'Demonstration calendar; no confirmed availability.']],
  'field_incluye' => [['value' => 'Demonstration itinerary'], ['value' => 'Local accompaniment to be confirmed']],
  'field_politica_reserva' => [['value' => 'DEMO listing. It does not confirm availability or a booking. Verify services, prices, terms and payment configuration before selling.', 'format' => 'plain_text']],
  'field_galeria' => [['target_id' => 101, 'alt' => 'Demo catalog illustration; not a confirmed property.', 'title' => 'Photo']],
]);
$nodes = [
  'eligible' => new DemoMessageNodeFixture('alojamiento', TRUE, TRUE, [
    'es' => $spanish('Posada de los balcones en Concepción', 'Concepción', 'Pueblos con alma'),
    'en' => $english('Balcony guesthouse in Concepción'),
  ]),
  'edited-title' => new DemoMessageNodeFixture('alojamiento', TRUE, TRUE, [
    'es' => $spanish('Cabaña junto al bosque de Nariño', 'Nariño', 'Naturaleza'),
  ]),
  'not-demo' => new DemoMessageNodeFixture('alojamiento', FALSE, TRUE, [
    'es' => $spanish('Estancia cafetera entre montañas', 'Cocora', 'Café y tradición'),
  ]),
  'pending-draft' => new DemoMessageNodeFixture('alojamiento', TRUE, TRUE, [
    'es' => $spanish('Refugio de descanso en Santa Rosa', 'Santa Rosa', 'Bienestar'),
  ]),
  'unpublished' => new DemoMessageNodeFixture('alojamiento', TRUE, FALSE, [
    'es' => $spanish('Ecoestancia de brisa caribeña', 'Rosario', 'Naturaleza'),
  ]),
  'wrong-type' => new DemoMessageNodeFixture('experiencia', TRUE, TRUE, [
    'es' => $spanish('Casa de patios y memorias', 'Concepción', 'Cultura viva'),
  ]),
  'no-english' => new DemoMessageNodeFixture('alojamiento', TRUE, TRUE, [
    'es' => $spanish('Mirador de las palmas de cera', 'Cocora', 'Naturaleza'),
  ]),
  'edited-fields' => new DemoMessageNodeFixture('alojamiento', TRUE, TRUE, [
    'es' => $spanish('Alojamiento de río y silencio', 'Nariño', 'Bienestar'),
  ]),
];
$nodes['edited-title']->translations['es']->title = 'Nombre editorial';
$nodes['pending-draft']->latestRevision = 2;
$nodes['eligible']->translations['en']->fields['body'][0]['value'] = 'An editorial description.';
$nodes['eligible']->translations['en']->fields['field_precio_nota'][0]['value'] = 'An editorial price note.';
$nodes['eligible']->translations['en']->fields['field_galeria'][0]['alt'] = 'An editorial photograph.';
$nodes['edited-fields']->translations['es']->fields['body'][0]['value'] .= "\n\nNota del equipo.";
$nodes['edited-fields']->translations['es']->fields['field_politica_reserva'][0]['format'] = 'filtered_html';
$nodes['edited-fields']->translations['es']->fields['field_incluye'][0]['value'] .= ' editada';
$old_edited_body = $nodes['edited-fields']->translations['es']->fields['body'];
$old_title_body = $nodes['edited-title']->translations['es']->fields['body'];
$old_photo = $nodes['eligible']->translations['es']->fields['field_galeria'][0];
unset($old_photo['alt']);
$ledger = [
  'alojamiento:0' => 'eligible',
  'alojamiento:1' => 'edited-title',
  'alojamiento:2' => 'not-demo',
  'alojamiento:3' => 'pending-draft',
  'alojamiento:4' => 'unpublished',
  'alojamiento:5' => 'wrong-type',
  'alojamiento:6' => 'no-english',
  'alojamiento:7' => 'edited-fields',
  'alojamiento:8' => 'missing-node',
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
  $assert(DemoCatalog::refreshDefaultMessages() === 4, 'Only published, recorded demo nodes without pending drafts should be revised.');
  $es = $nodes['eligible']->translations['es']->fields;
  $en = $nodes['eligible']->translations['en']->fields;
  $assert(!str_contains($es['body'][0]['value'], 'DEMO') && !str_contains($es['body'][0]['value'], 'muestra'), 'The generated Spanish description was not cleaned.');
  $assert($es['body'][0]['summary'] === 'Resumen editorial', 'The existing body summary was overwritten.');
  $assert(str_contains($es['field_precio_nota'][0]['value'], 'confirmación') && str_contains($es['field_disponibilidad'][0]['value'], 'confirmación'), 'Unconfirmed prices and availability must remain clear.');
  $assert(str_contains($es['field_politica_reserva'][0]['value'], 'reserva requieren confirmación'), 'The booking policy must still require confirmation.');
  $assert($es['field_incluye'][1]['value'] === 'Elemento añadido por el equipo', 'An edited list item was overwritten.');
  $photo = $es['field_galeria'][0];
  $assert(str_contains($photo['alt'], 'no representa un establecimiento confirmado'), 'The photograph must remain identified as illustrative.');
  unset($photo['alt']);
  $assert($photo === $old_photo && $es['field_galeria'][1]['alt'] === 'Fotografía elegida por el equipo', 'Image references, metadata and edited alt text must be preserved.');
  $assert($es['field_precio'][0]['value'] === '240000', 'A price was changed.');
  $assert($en['body'][0]['value'] === 'An editorial description.' && $en['field_precio_nota'][0]['value'] === 'An editorial price note.' && $en['field_galeria'][0]['alt'] === 'An editorial photograph.', 'Edited English fields were overwritten.');
  $assert(str_contains($en['field_politica_reserva'][0]['value'], 'require confirmation'), 'The English booking policy lost its confirmation warning.');
  $assert($nodes['edited-title']->translations['es']->fields['body'] === $old_title_body, 'A description whose title was edited must be preserved.');
  $assert($nodes['edited-fields']->translations['es']->fields['body'] === $old_edited_body, 'A partially edited description was overwritten.');
  $assert($nodes['edited-fields']->translations['es']->fields['field_politica_reserva'][0]['format'] === 'filtered_html'
    && str_starts_with($nodes['edited-fields']->translations['es']->fields['field_politica_reserva'][0]['value'], 'Ficha DEMO.'), 'A policy with an edited text format was overwritten.');
  $assert($nodes['edited-fields']->translations['es']->fields['field_incluye'][0]['value'] === 'Propuesta de itinerario de demostración editada', 'An edited list item was overwritten.');
  $assert(!isset($nodes['no-english']->translations['en']), 'A missing translation must not be created.');
  foreach (['not-demo', 'pending-draft', 'unpublished', 'wrong-type'] as $name) {
    $assert($nodes[$name]->saves === 0, 'An ineligible node was saved: ' . $name);
  }
  foreach (['eligible', 'edited-title', 'no-english', 'edited-fields'] as $name) {
    $assert($nodes[$name]->saves === 1 && $nodes[$name]->newRevision, 'A changed node must receive exactly one new revision.');
  }
  $assert(DemoCatalog::refreshDefaultMessages() === 0, 'Refreshing messages twice must be idempotent.');
  $nodes['eligible']->untranslatable = 'field_galeria';
  try {
    DemoCatalog::refreshDefaultMessages();
    throw new RuntimeException('An untranslatable generated field was accepted.');
  }
  catch (LogicException $expected) {
    $assert(str_contains($expected->getMessage(), 'field_galeria'), 'The invalid field must be identified.');
  }
}
finally {
  \Drupal::setContainer($original_container);
}

echo "Demo message preservation checks passed.\n";
