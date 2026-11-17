<?php

declare(strict_types=1);

namespace Drupal\trotamundos_commerce\Setup;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_price\Price;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\NodeInterface;
use Drupal\trotamundos_experiences\Setup\ProductTypes;

/**
 * Keeps a queryable price projection; Commerce remains the price authority.
 */
final class CatalogPrice {

  public const FIELD = 'field_tm_catalog_price';

  public static function ensure(): void {
    if (!FieldStorageConfig::loadByName('node', self::FIELD)) {
      FieldStorageConfig::create([
        'entity_type' => 'node',
        'field_name' => self::FIELD,
        'type' => 'decimal',
        'settings' => ['precision' => 19, 'scale' => 6],
        'translatable' => FALSE,
      ])->save();
    }
    foreach (ProductTypes::BUNDLES as $bundle) {
      if (!FieldConfig::loadByName('node', $bundle, self::FIELD)) {
        FieldConfig::create([
          'entity_type' => 'node',
          'bundle' => $bundle,
          'field_name' => self::FIELD,
          'label' => 'Precio del catálogo (índice)',
          'description' => 'Índice automático. El precio de venta se gestiona en la variación de Commerce.',
          'translatable' => FALSE,
        ])->save();
      }
    }
  }

  public static function project(NodeInterface $node): void {
    if (!in_array($node->bundle(), ProductTypes::BUNDLES, TRUE) || !$node->hasField(self::FIELD)) {
      return;
    }
    $number = (string) ($node->get('field_precio')->value ?? '0');
    $product = $node->get('field_commerce_product')->entity;
    if ($product instanceof ProductInterface) {
      $price = $product->getDefaultVariation()?->getPrice();
      if ($price) {
        if ($price->getCurrencyCode() !== 'COP') {
          throw new \LogicException('The travel catalog requires COP prices.');
        }
        $number = $price->getNumber();
      }
    }
    $node->set(self::FIELD, $number);
  }

  public static function refreshProduct(int $product_id): void {
    $manager = \Drupal::entityTypeManager();
    $manager->getStorage('commerce_product')->resetCache([$product_id]);
    $storage = $manager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', ProductTypes::BUNDLES, 'IN')
      ->condition('field_commerce_product', $product_id)->execute();
    $storage->resetCache($ids);
    foreach ($storage->loadMultiple($ids) as $node) {
      $before = $node->get(self::FIELD)->value;
      self::project($node);
      if ($before === NULL || !(new Price((string) $before, 'COP'))->equals(new Price((string) $node->get(self::FIELD)->value, 'COP'))) {
        $node->save();
      }
    }
  }

}
