<?php

declare(strict_types=1);

namespace Drupal\trotamundos_commerce\Setup;

use Drupal\commerce_product\Entity\ProductType;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\trotamundos_experiences\Setup\ProductTypes;

/**
 * Prepares saleable types while keeping editorial content and prices intact.
 */
final class CommerceModel {

  public static function ensure(): void {
    \Drupal::service('commerce_price.currency_importer')->import('COP');
    $field_manager = \Drupal::service('entity_field.manager');

    $reference = FieldStorageConfig::loadByName('node', 'field_commerce_product');
    if (!$reference) {
      FieldStorageConfig::create([
        'entity_type' => 'node',
        'field_name' => 'field_commerce_product',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'commerce_product'],
        'cardinality' => 1,
        'translatable' => FALSE,
      ])->save();
    }
    elseif ($reference->getSetting('target_type') !== 'commerce_product') {
      throw new \LogicException('The Commerce reference field has an incompatible target type.');
    }

    foreach (ProductTypes::BUNDLES as $bundle) {
      $editorial_type = NodeType::load($bundle);
      if (!$editorial_type) {
        throw new \LogicException('Install the editorial product type first: ' . $bundle);
      }

      if (!ProductVariationType::load($bundle)) {
        ProductVariationType::create([
          'id' => $bundle,
          'label' => $editorial_type->label(),
          'langcode' => 'es',
          'orderItemType' => 'default',
          'generateTitle' => TRUE,
          'traits' => [],
        ])->save();
      }

      $product_type = ProductType::load($bundle);
      if (!$product_type) {
        ProductType::create([
          'id' => $bundle,
          'label' => $editorial_type->label(),
          'langcode' => 'es',
          'description' => $editorial_type->getDescription(),
          'variationTypes' => [$bundle],
          'multipleVariations' => TRUE,
          'injectVariationFields' => TRUE,
          'traits' => [],
        ])->save();
      }
      elseif (!in_array($bundle, $product_type->getVariationTypeIds(), TRUE)) {
        throw new \LogicException('The Commerce product type has incompatible variations: ' . $bundle);
      }

      foreach (['commerce_product', 'commerce_product_variation'] as $entity_type) {
        if (!BaseFieldOverride::load($entity_type . '.' . $bundle . '.status')) {
          $status = $field_manager->getBaseFieldDefinitions($entity_type)['status'];
          BaseFieldOverride::createFromBaseFieldDefinition($status, $bundle)
            ->setDefaultValue(FALSE)
            ->save();
        }
      }

      if (!FieldConfig::loadByName('node', $bundle, 'field_commerce_product')) {
        FieldConfig::create([
          'entity_type' => 'node',
          'bundle' => $bundle,
          'field_name' => 'field_commerce_product',
          'label' => 'Producto de Commerce',
          'description' => 'Vincula un producto del mismo tipo cuando su tienda y sus tarifas definitivas estén preparados. No copies automáticamente el precio «desde».',
          'required' => FALSE,
          'translatable' => FALSE,
          'settings' => [
            'handler' => 'default:commerce_product',
            'handler_settings' => [
              'target_bundles' => [$bundle => $bundle],
              'auto_create' => FALSE,
            ],
          ],
        ])->save();
      }

      $display = EntityFormDisplay::load('node.' . $bundle . '.default');
      if (!$display) {
        throw new \LogicException('The editorial product form is missing: ' . $bundle);
      }
      if (!$display->getComponent('field_commerce_product')) {
        $display->setComponent('field_commerce_product', [
          'type' => 'entity_reference_autocomplete',
          'weight' => 60,
        ])->save();
      }
    }
  }

}
