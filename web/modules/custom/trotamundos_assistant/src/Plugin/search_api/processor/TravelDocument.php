<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Plugin\search_api\processor;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Builds an explicitly limited document from public travel content.
 */
#[SearchApiProcessor(
  id: 'trotamundos_assistant_travel_document',
  label: new TranslatableMarkup('Trotamundos public travel document'),
  description: new TranslatableMarkup('Indexes selected public fields from published travel content.'),
  stages: ['add_properties' => 0, 'alter_items' => 0],
)]
final class TravelDocument extends ProcessorPluginBase {

  public const BUNDLES = [
    'alojamiento', 'experiencia', 'paquete', 'destino', 'guia_viaje',
    'pagina_basica', 'establecimiento',
  ];

  private const TEXT_FIELDS = [
    'body', 'field_resumen', 'field_duracion', 'field_incluye',
    'field_no_incluye', 'field_historia_destino', 'field_como_llegar',
    'field_recomendaciones', 'field_disponibilidad', 'field_politica_reserva',
    'field_politica_cancelacion', 'field_precio_nota', 'field_departamento',
    'field_municipio', 'field_region', 'field_acompanamiento',
  ];

  private const REFERENCE_FIELDS = [
    'field_destino', 'field_destinations', 'field_destination', 'field_estilo',
    'field_tipo_plan',
  ];

  public static function supportsIndex(IndexInterface $index): bool {
    return isset($index->getDatasources()['entity:node']);
  }

  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL): array {
    if ($datasource !== NULL) {
      return [];
    }

    return [
      'travel_context' => new ProcessorProperty([
        'label' => $this->t('Identidad del contenido de viaje'),
        'type' => 'text',
        'processor_id' => $this->getPluginId(),
      ]),
      'travel_document' => new ProcessorProperty([
        'label' => $this->t('Contenido público de viaje'),
        'type' => 'text',
        'processor_id' => $this->getPluginId(),
      ]),
    ];
  }

  public function alterIndexedItems(array &$items): void {
    foreach ($items as $id => $item) {
      $node = $item->getOriginalObject()->getValue();
      if (!$node instanceof NodeInterface || !self::isPublic($node)) {
        unset($items[$id]);
      }
    }
  }

  public function addFieldValues(ItemInterface $item): void {
    $node = $item->getOriginalObject()->getValue();
    if (!$node instanceof NodeInterface || !self::isPublic($node)) {
      return;
    }

    $account = new AnonymousUserSession();
    $parts = [];
    foreach (self::TEXT_FIELDS as $name) {
      if (!$node->hasField($name) || !$node->get($name)->access('view', $account)) {
        continue;
      }
      $field = $node->get($name);
      $options = $field->getFieldDefinition()->getFieldStorageDefinition()->getSetting('allowed_values') ?? [];
      $values = [];
      foreach ($field as $value) {
        $raw = (string) ($value->value ?? '');
        $text = trim(Html::decodeEntities(strip_tags((string) ($options[$raw] ?? $raw))));
        if ($text !== '') {
          $values[] = $text;
        }
      }
      if ($values) {
        $parts[] = $field->getFieldDefinition()->getLabel() . ': ' . implode('; ', $values);
      }
    }

    foreach (self::REFERENCE_FIELDS as $name) {
      if (!$node->hasField($name) || !$node->get($name)->access('view', $account)) {
        continue;
      }
      $field = $node->get($name);
      $labels = [];
      foreach ($field->referencedEntities() as $reference) {
        if ($reference instanceof EntityInterface) {
          if ($reference instanceof TranslatableInterface && $reference->hasTranslation($node->language()->getId())) {
            $reference = $reference->getTranslation($node->language()->getId());
          }
          if ($reference->access('view', $account)
            && (!$reference instanceof NodeInterface || $reference->isPublished())) {
            $labels[] = (string) $reference->label();
          }
        }
      }
      if ($labels) {
        $parts[] = $field->getFieldDefinition()->getLabel() . ': ' . implode(', ', $labels);
      }
    }

    if ($node->hasField('field_tm_catalog_price') && !$node->get('field_tm_catalog_price')->isEmpty()
      && $node->get('field_tm_catalog_price')->access('view', $account)) {
      $parts[] = 'Precio desde: ' . $node->get('field_tm_catalog_price')->value . ' COP por persona';
    }
    if ($node->hasField('field_ejemplo') && $node->get('field_ejemplo')->access('view', $account)
      && (bool) $node->get('field_ejemplo')->value) {
      $parts[] = 'Ficha de ejemplo: precios, fechas y textos provisionales.';
    }
    if ($node->hasField('field_fechas') && $node->get('field_fechas')->access('view', $account)) {
      foreach ($node->get('field_fechas') as $range) {
        if ($range->value && $range->end_value) {
          $parts[] = 'Periodo publicado: ' . $range->value . ' a ' . $range->end_value . ' (sujeto a confirmación)';
        }
      }
    }

    $values = [
      'travel_context' => 'ID de contenido: ' . $node->id() . "\nTipo: " . $node->bundle() . "\nTítulo: " . $node->label(),
      'travel_document' => implode("\n", $parts),
    ];
    foreach ($values as $path => $value) {
      foreach ($this->getFieldsHelper()->filterForPropertyPath($item->getFields(FALSE), NULL, $path) as $field) {
        $field->addValue($value);
      }
    }
  }

  public static function isPublic(NodeInterface $node): bool {
    return in_array($node->bundle(), self::BUNDLES, TRUE)
      && $node->isPublished()
      && $node->access('view', new AnonymousUserSession());
  }

}
