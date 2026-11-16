<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Plugin\search_api\processor;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\search_api\SearchApiException;

/**
 * Adds catalog-specific AI fields for RAG indexing.
 */
#[SearchApiProcessor(
  id: 'trotamundos_assistant_catalog_document',
  label: new TranslatableMarkup('Trotamundos Assistant catalog document'),
  description: new TranslatableMarkup('Adds rich product and variation fields for AI Search and Pinecone metadata.'),
  stages: [
    'add_properties' => 0,
    'alter_items' => 0,
  ],
  locked: TRUE,
  hidden: TRUE,
)]
final class CatalogDocument extends ProcessorPluginBase {

  /**
   * Product bundle excluded from AI catalog indexing.
   */
  private const EXCLUDED_PRODUCT_BUNDLE = 'deposit';

  /**
   * {@inheritdoc}
   */
  public static function supportsIndex(IndexInterface $index): bool {
    $datasources = $index->getDatasources();
    return isset($datasources['entity:commerce_product'])
      || isset($datasources['entity:commerce_product_variation']);
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL): array {
    if ($datasource !== NULL) {
      return [];
    }

    $properties = [];
    foreach ($this->getPropertyDefinitionsMap() as $name => $definition) {
      $definition['processor_id'] = $this->getPluginId();
      $properties[$name] = new ProcessorProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item): void {
    try {
      $entity = $item->getOriginalObject()->getValue();
    }
    catch (SearchApiException) {
      return;
    }

    $values = match (TRUE) {
      $entity instanceof ProductInterface => $this->buildProductValues($entity),
      $entity instanceof ProductVariationInterface => $this->buildVariationValues($entity),
      $entity instanceof NodeInterface => $this->buildNodeValues($entity),
      default => NULL,
    };
    if ($values === NULL) {
      return;
    }
    $fields = $item->getFields(FALSE);
    foreach ($values as $property_path => $property_values) {
      $matching_fields = $this->getFieldsHelper()
        ->filterForPropertyPath($fields, NULL, $property_path);
      foreach ($matching_fields as $field) {
        foreach ((array) $property_values as $value) {
          if ($value !== NULL && $value !== '') {
            $field->addValue($value);
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterIndexedItems(array &$items): void {
    foreach ($items as $item_id => $item) {
      try {
        $entity = $item->getOriginalObject()->getValue();
      }
      catch (SearchApiException) {
        continue;
      }

      if ($this->isExcludedEntity($entity)) {
        unset($items[$item_id]);
      }
    }
  }

  /**
   * Returns processor property definitions keyed by property path.
   *
   * @return array<string, array<string, mixed>>
   *   Search API property definitions.
   */
  private function getPropertyDefinitionsMap(): array {
    return [
      'text_for_embedding' => [
        'label' => $this->t('Texto enriquecido para embedding'),
        'description' => $this->t('Texto semántico del producto y sus variaciones.'),
        'type' => 'text',
      ],
      'catalog_title' => [
        'label' => $this->t('Título comercial'),
        'description' => $this->t('Título contextual del producto o variación.'),
        'type' => 'text',
      ],
      'document_id' => [
        'label' => $this->t('ID documental'),
        'description' => $this->t('ID lógico usado por Pinecone para producto o variación.'),
        'type' => 'string',
      ],
      'variation_document_id' => [
        'label' => $this->t('IDs documentales de variación'),
        'description' => $this->t('IDs lógicos usados por Pinecone para variaciones.'),
        'type' => 'string',
        'is_list' => TRUE,
      ],
      'variation_id' => [
        'label' => $this->t('IDs de variación'),
        'description' => $this->t('Variaciones Commerce relacionadas con el producto.'),
        'type' => 'integer',
        'is_list' => TRUE,
      ],
      'product_id' => [
        'label' => $this->t('ID de producto'),
        'description' => $this->t('Producto Commerce padre.'),
        'type' => 'integer',
      ],
      'sku' => [
        'label' => $this->t('SKU'),
        'description' => $this->t('SKUs de las variaciones del producto.'),
        'type' => 'string',
        'is_list' => TRUE,
      ],
      'category' => [
        'label' => $this->t('Categoría'),
        'description' => $this->t('Categoría textual del producto.'),
        'type' => 'string',
      ],
      'brand' => [
        'label' => $this->t('Marca'),
        'description' => $this->t('Marca textual del producto.'),
        'type' => 'string',
      ],
      'price' => [
        'label' => $this->t('Precios'),
        'description' => $this->t('Precios numéricos de las variaciones.'),
        'type' => 'decimal',
        'is_list' => TRUE,
      ],
      'catalog_status' => [
        'label' => $this->t('Publicado'),
        'description' => $this->t('Estado publicado del producto.'),
        'type' => 'boolean',
      ],
    ];
  }

  /**
   * Builds all catalog values for one product document.
   *
   * @return array<string, mixed>
   *   Values keyed by processor property path.
   */
  private function buildProductValues(ProductInterface $product): array {
    $product_id = (int) $product->id();
    $variations = $product->getVariations();
    $variation_ids = [];
    $variation_document_ids = [];
    $skus = [];
    $prices = [];
    $variation_lines = [];

    foreach ($variations as $variation) {
      if (!$variation instanceof ProductVariationInterface) {
        continue;
      }

      $variation_id = (int) $variation->id();
      $variation_ids[] = $variation_id;
      $variation_document_ids[] = "product:$product_id:variation:$variation_id";
      $skus[] = $variation->getSku();
      $price = $variation->getPrice();
      if ($price) {
        $prices[] = (float) $price->getNumber();
      }

      $variation_lines[] = $this->buildVariationText($variation);
    }

    $text_parts = [
      'Tipo documental: commerce_product',
      'ID producto: ' . $product_id,
      'Nombre: ' . $product->getTitle(),
      'Categoría: ' . $this->getReferencedEntityLabel($product, 'field_product_category'),
      'Marca: ' . $this->getReferencedEntityLabel($product, 'field_brand'),
      'Descripción: ' . $this->getProductBody($product),
      'Variaciones: ' . implode(' | ', array_filter($variation_lines)),
    ];

    return [
      'text_for_embedding' => implode("\n", array_filter($text_parts)),
      'catalog_title' => $product->getTitle(),
      'document_id' => 'product:' . $product_id,
      'variation_document_id' => array_values(array_filter($variation_document_ids)),
      'product_id' => $product_id,
      'variation_id' => array_values(array_filter($variation_ids)),
      'sku' => array_values(array_filter($skus)),
      'category' => $this->getReferencedEntityLabel($product, 'field_product_category'),
      'brand' => $this->getReferencedEntityLabel($product, 'field_brand'),
      'price' => array_values($prices),
      'catalog_status' => $product->isPublished(),
    ];
  }

  /**
   * Builds embedding text + metadata for one content node.
   *
   * @return array<string, mixed>
   *   Values keyed by processor property path.
   */
  private function buildNodeValues(NodeInterface $node): array {
    // ponytail: index every text/string field so natural-language queries hit
    // any WYSIWYG or plain-text content; extend the type list if needed.
    $text_types = ['text', 'text_long', 'text_with_summary', 'string', 'string_long'];
    $text_parts = [
      'Tipo documental: ' . $node->bundle(),
      'Título: ' . $node->getTitle(),
    ];
    foreach ($node->getFields() as $name => $items) {
      if ($name === 'title' || !in_array($items->getFieldDefinition()->getType(), $text_types, TRUE)) {
        continue;
      }
      foreach ($items as $item) {
        $value = trim(strip_tags((string) ($item->value ?? '')));
        if ($value !== '') {
          $text_parts[] = $value;
        }
      }
    }

    return [
      'text_for_embedding' => implode("\n", array_filter($text_parts)),
      'catalog_title' => $node->getTitle(),
      'document_id' => 'node:' . $node->id(),
      // Nodes are not products; 0 is the sentinel so product_id filters skip
      // them. Typed pgvector columns reject empty strings, so set explicitly.
      'product_id' => 0,
      'catalog_status' => TRUE,
    ];
  }

  /**
   * Checks whether an indexed Commerce entity must be omitted.
   */
  private function isExcludedEntity(mixed $entity): bool {
    if ($entity instanceof NodeInterface) {
      return !$entity->isPublished();
    }

    if ($entity instanceof ProductInterface) {
      return $entity->bundle() === self::EXCLUDED_PRODUCT_BUNDLE;
    }

    if ($entity instanceof ProductVariationInterface) {
      $product = $entity->getProduct();
      return $product instanceof ProductInterface
        && $product->bundle() === self::EXCLUDED_PRODUCT_BUNDLE;
    }

    return FALSE;
  }

  /**
   * Builds all catalog values for one variation document.
   *
   * @return array<string, mixed>
   *   Values keyed by processor property path.
   */
  private function buildVariationValues(ProductVariationInterface $variation): array {
    $product = $variation->getProduct();
    if (!$product instanceof ProductInterface) {
      return [];
    }

    $product_id = (int) $product->id();
    $variation_id = (int) $variation->id();
    $price = $variation->getPrice();
    $price_number = $price ? (float) $price->getNumber() : NULL;
    $text_parts = [
      'Tipo documental: commerce_product_variation',
      'ID producto: ' . $product_id,
      'ID variación: ' . $variation_id,
      'SKU: ' . $variation->getSku(),
      'Producto: ' . $product->getTitle(),
      'Variación: ' . $variation->getTitle(),
      'Categoría: ' . $this->getReferencedEntityLabel($product, 'field_product_category'),
      'Marca: ' . $this->getReferencedEntityLabel($product, 'field_brand'),
      'Precio: ' . ($price ? $price->getNumber() . ' ' . $price->getCurrencyCode() : ''),
      'Descripción: ' . $this->getProductBody($product),
      'Atributos: ' . $this->buildVariationAttributesText($variation),
    ];

    return [
      'text_for_embedding' => implode("\n", array_filter($text_parts)),
      'catalog_title' => $product->getTitle() . ' - ' . $variation->getTitle(),
      'document_id' => "product:$product_id:variation:$variation_id",
      'variation_document_id' => "product:$product_id:variation:$variation_id",
      'product_id' => $product_id,
      'variation_id' => $variation_id,
      'sku' => $variation->getSku(),
      'category' => $this->getReferencedEntityLabel($product, 'field_product_category'),
      'brand' => $this->getReferencedEntityLabel($product, 'field_brand'),
      'price' => $price_number,
      'catalog_status' => $product->isPublished() && $variation->isPublished(),
    ];
  }

  /**
   * Builds searchable text for one variation.
   */
  private function buildVariationText(ProductVariationInterface $variation): string {
    $parts = [
      'ID variación: ' . $variation->id(),
      'SKU: ' . $variation->getSku(),
      'Título variación: ' . $variation->getTitle(),
    ];

    $price = $variation->getPrice();
    if ($price) {
      $parts[] = 'Precio: ' . $price->getNumber() . ' ' . $price->getCurrencyCode();
    }

    foreach ($variation->getAttributeFieldNames() as $field_name) {
      $label = $this->getReferencedEntityLabel($variation, $field_name);
      if ($label !== '') {
        $parts[] = $variation->getFieldDefinition($field_name)->getLabel() . ': ' . $label;
      }
    }

    return implode(', ', array_filter($parts));
  }

  /**
   * Builds plain text with all variation attributes.
   */
  private function buildVariationAttributesText(ProductVariationInterface $variation): string {
    $parts = [];
    foreach ($variation->getAttributeFieldNames() as $field_name) {
      $label = $this->getReferencedEntityLabel($variation, $field_name);
      if ($label !== '') {
        $parts[] = $variation->getFieldDefinition($field_name)->getLabel() . ': ' . $label;
      }
    }

    return implode(', ', $parts);
  }

  /**
   * Gets the product body as plain text.
   */
  private function getProductBody(ProductInterface $product): string {
    if (!$product->hasField('body') || $product->get('body')->isEmpty()) {
      return '';
    }

    return trim(strip_tags((string) $product->get('body')->value));
  }

  /**
   * Gets a referenced entity label from a field.
   */
  private function getReferencedEntityLabel(EntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    $referenced = $entity->get($field_name)->entity;
    return $referenced instanceof EntityInterface ? (string) $referenced->label() : '';
  }

}
