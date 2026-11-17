<?php

declare(strict_types=1);

namespace Drupal\trotamundos_commerce\Setup;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\trotamundos_experiences\Setup\ContentModel;
use Drupal\trotamundos_experiences\Setup\ProductTypes;

/**
 * Optional, private travel preferences; existing accounts remain unassigned.
 */
final class TravelProfileModel {

  public const STYLES = 'field_tm_travel_styles';
  public const COMPANIONS = 'field_tm_travel_companions';
  public const SAVED = 'field_tm_saved_plans';
  public const FIELDS = [self::STYLES, self::COMPANIONS, self::SAVED];

  public static function ensure(): void {
    $definitions = [
      self::STYLES => [
        'label' => 'Estilos de viaje',
        'type' => 'entity_reference',
        'storage' => ['target_type' => 'taxonomy_term'],
        'settings' => [
          'handler' => 'default:taxonomy_term',
          'handler_settings' => ['target_bundles' => ['estilo_viaje' => 'estilo_viaje']],
        ],
      ],
      self::COMPANIONS => [
        'label' => 'Acompañamiento preferido',
        'type' => 'list_string',
        'storage' => ['allowed_values' => ContentModel::COMPANIONS],
        'settings' => [],
      ],
      self::SAVED => [
        'label' => 'Planes guardados',
        'type' => 'entity_reference',
        'storage' => ['target_type' => 'node'],
        'settings' => [
          'handler' => 'default:node',
          'handler_settings' => [
            'target_bundles' => array_combine(ProductTypes::BUNDLES, ProductTypes::BUNDLES),
          ],
        ],
      ],
    ];
    foreach ($definitions as $name => $definition) {
      if (!FieldStorageConfig::loadByName('user', $name)) {
        FieldStorageConfig::create([
          'entity_type' => 'user',
          'field_name' => $name,
          'type' => $definition['type'],
          'settings' => $definition['storage'],
          'cardinality' => -1,
          'translatable' => FALSE,
        ])->save();
      }
      if (!FieldConfig::loadByName('user', 'user', $name)) {
        FieldConfig::create([
          'entity_type' => 'user',
          'bundle' => 'user',
          'field_name' => $name,
          'label' => $definition['label'],
          'langcode' => 'es',
          'required' => FALSE,
          'translatable' => FALSE,
          'settings' => $definition['settings'],
        ])->save();
      }
    }

    // The optional Commerce view is installed in the site's default language.
    if (\Drupal::moduleHandler()->moduleExists('language') && \Drupal::languageManager()->getLanguage('en')) {
      $name = 'views.view.commerce_user_orders';
      $source = \Drupal::configFactory()->getEditable($name);
      $override = \Drupal::service('language.config_factory_override')->getOverride('en', $name);
      $changed = FALSE;
      foreach (['display.default.display_options.title', 'display.order_page.display_options.menu.title'] as $key) {
        if ($source->get($key) === 'Pedidos' && $override->get($key) === NULL) {
          $override->set($key, 'Orders');
          $changed = TRUE;
        }
      }
      if ($changed) {
        $override->save();
      }
    }
  }

}
