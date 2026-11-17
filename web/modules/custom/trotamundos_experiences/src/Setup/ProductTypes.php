<?php

declare(strict_types=1);

namespace Drupal\trotamundos_experiences\Setup;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;

/**
 * Shared editorial fields and lossless conversion of the three product types.
 */
final class ProductTypes {

  public const BUNDLES = ['alojamiento', 'experiencia', 'paquete'];

  public const ROUTES = [
    'alojamiento' => 'trotamundos_experiences.stays',
    'experiencia' => 'trotamundos_experiences.experiences',
    'paquete' => 'trotamundos_experiences.packages',
  ];

  public const CACHE_TAGS = [
    'node_list:alojamiento',
    'node_list:experiencia',
    'node_list:paquete',
    'node_list:destino',
    'taxonomy_term_list:estilo_viaje',
    'taxonomy_term_list:tipo_plan',
  ];

  public static function ensure(): void {
    $manager = \Drupal::entityTypeManager();
    $legacy_type = FieldConfig::loadByName('node', 'experiencia', 'field_tipo_plan');
    if (!$legacy_type) {
      throw new \LogicException('The shared experience fields must be installed before the product types.');
    }
    if ($legacy_type->isRequired()) {
      $legacy_type->setRequired(FALSE)->save();
    }
    $source_form = $manager->getStorage('entity_form_display')->load('node.experiencia.default');
    if ($source_form && $source_form->getComponent('field_tipo_plan')) {
      $source_form->removeComponent('field_tipo_plan')->save();
    }

    $definitions = [
      'alojamiento' => ['Alojamiento', 'Estancias con anfitriones locales: fotos, servicios, precios, disponibilidad y reserva.'],
      'experiencia' => ['Experiencia', 'Pasadías y recorridos con anfitriones locales, con sus precios, fechas y condiciones.'],
      'paquete' => ['Paquete', 'Viajes que combinan alojamiento y actividades, con su itinerario, precios y fechas.'],
    ];
    $fields = $manager->getStorage('field_config')->loadByProperties(['entity_type' => 'node', 'bundle' => 'experiencia']);
    foreach ($definitions as $bundle => [$label, $description]) {
      $type = NodeType::load($bundle);
      if (!$type) {
        $type = NodeType::create([
          'type' => $bundle,
          'name' => $label,
          'new_revision' => TRUE,
          'display_submitted' => FALSE,
          'preview_mode' => 1,
          'langcode' => 'es',
        ]);
      }
      if ($type->isNew() || $type->getDescription() !== $description) {
        $type->set('description', $description);
        $type->set('help', 'Completa la ficha con fotos, anfitriones, precio, fechas disponibles y condiciones de reserva.');
        $type->save();
      }
      if ($bundle === 'experiencia') {
        continue;
      }

      foreach ($fields as $field) {
        if (!FieldConfig::loadByName('node', $bundle, $field->getName())) {
          $values = $field->toArray();
          unset($values['id'], $values['uuid'], $values['dependencies']);
          $values['bundle'] = $bundle;
          FieldConfig::create($values)->save();
        }
      }
      $base_overrides = $manager->getStorage('base_field_override');
      foreach ($base_overrides->loadByProperties(['entity_type' => 'node', 'bundle' => 'experiencia']) as $override) {
        $id = 'node.' . $bundle . '.' . $override->getName();
        if (!$base_overrides->load($id)) {
          $values = $override->toArray();
          unset($values['uuid'], $values['dependencies']);
          $values['id'] = $id;
          $values['bundle'] = $bundle;
          $base_overrides->create($values)->save();
        }
      }
      foreach (['entity_form_display', 'entity_view_display'] as $entity_type) {
        $storage = $manager->getStorage($entity_type);
        foreach ($storage->loadByProperties(['targetEntityType' => 'node', 'bundle' => 'experiencia']) as $display) {
          $id = 'node.' . $bundle . '.' . $display->getMode();
          if (!$storage->load($id)) {
            $values = $display->toArray();
            unset($values['uuid'], $values['dependencies']);
            $values['id'] = $id;
            $values['bundle'] = $bundle;
            $storage->create($values)->save();
          }
        }
      }
      if ($manager->hasDefinition('language_content_settings')) {
        $storage = $manager->getStorage('language_content_settings');
        $source = $storage->load('node.experiencia');
        if ($source && !$storage->load('node.' . $bundle)) {
          $values = $source->toArray();
          unset($values['uuid'], $values['dependencies']);
          $values['id'] = 'node.' . $bundle;
          $values['target_bundle'] = $bundle;
          $storage->create($values)->save();
        }
      }
    }

    foreach (Role::loadMultiple() as $role) {
      $changed = FALSE;
      foreach ($role->getPermissions() as $permission) {
        if (!preg_match('/^(create|edit own|edit any|delete own|delete any) experiencia content$|^(view|revert|delete) experiencia revisions$|^translate experiencia node$/', $permission)) {
          continue;
        }
        foreach (['alojamiento', 'paquete'] as $bundle) {
          $copy = str_replace('experiencia', $bundle, $permission);
          if (!$role->hasPermission($copy)) {
            $role->grantPermission($copy);
            $changed = TRUE;
          }
        }

      }
      if ($changed) {
        $role->save();
      }
    }

    $aliases = $manager->getStorage('path_alias');
    foreach (['/planes' => '/plans', '/alojamientos' => '/stays', '/experiencias' => '/experiences', '/paquetes' => '/packages'] as $path => $alias) {
      if (!$aliases->loadByProperties(['path' => $path, 'langcode' => 'en'])) {
        if ($aliases->loadByProperties(['alias' => $alias, 'langcode' => 'en'])) {
          throw new \RuntimeException('The English catalog alias is already used: ' . $alias);
        }
        $aliases->create(['path' => $path, 'alias' => $alias, 'langcode' => 'en'])->save();
      }
    }
  }

  /**
   * Converts classified legacy nodes without resaving or renumbering them.
   */
  public static function migrate(): int {
    $manager = \Drupal::entityTypeManager();
    $storage = $manager->getStorage('node');
    if (!$storage instanceof SqlContentEntityStorage) {
      throw new \LogicException('Product conversion requires Drupal SQL node storage.');
    }
    $groups = [];
    foreach (['alojamiento' => 'Alojamiento', 'paquete' => 'Paquete'] as $bundle => $label) {
      $terms = $manager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'tipo_plan', 'name' => $label]);
      $groups[$bundle] = $terms
        ? array_values($storage->getQuery()->accessCheck(FALSE)->condition('type', 'experiencia')->condition('field_tipo_plan', array_keys($terms), 'IN')->execute())
        : [];
    }
    $ids = array_merge(...array_values($groups));
    if (!$ids) {
      return 0;
    }

    $before = self::snapshot($ids);
    $database = \Drupal::database();
    $transaction = $database->startTransaction();
    try {
      // Bundle keys are read-only in the entity API. Only storage metadata is
      // changed after sharing all fields; values, revisions and IDs stay intact.
      $definition = $manager->getDefinition('node');
      $mapping = $storage->getTableMapping();
      $fields = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('node');
      foreach ($groups as $bundle => $node_ids) {
        if (!$node_ids) {
          continue;
        }
        foreach ([$definition->getBaseTable(), $definition->getDataTable(), $definition->getRevisionTable(), $definition->getRevisionDataTable()] as $table) {
          if ($table && $database->schema()->fieldExists($table, 'type')) {
            $database->update($table)->fields(['type' => $bundle])->condition('nid', $node_ids, 'IN')->execute();
          }
        }
        foreach ($fields as $field) {
          if (!$mapping->requiresDedicatedTableStorage($field)) {
            continue;
          }
          foreach ([$mapping->getDedicatedDataTableName($field), $mapping->getDedicatedRevisionTableName($field)] as $table) {
            if ($database->schema()->tableExists($table)) {
              $database->update($table)->fields(['bundle' => $bundle])
                ->condition('entity_id', $node_ids, 'IN')->condition('bundle', 'experiencia')->execute();
            }
          }
        }
      }
      $storage->resetCache($ids);
      foreach ($groups as $bundle => $node_ids) {
        foreach ($storage->loadMultiple($node_ids) as $node) {
          if ($node->bundle() !== $bundle) {
            throw new \RuntimeException('The product type could not be converted.');
          }
        }
      }
      if ($before !== self::snapshot($ids)) {
        throw new \RuntimeException('Product conversion changed content, translations, revisions or aliases.');
      }
    }
    catch (\Throwable $error) {
      $transaction->rollBack();
      $storage->resetCache($ids);
      throw $error;
    }
    unset($transaction);
    Cache::invalidateTags(array_merge(['node_list'], array_map(static fn ($id): string => 'node:' . $id, $ids)));
    return count($ids);
  }

  private static function snapshot(array $ids): array {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $snapshot = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $snapshot['nodes'][$node->id()] = self::contentHash($node);
    }
    ksort($snapshot['nodes']);
    $revision_ids = \Drupal::database()->select('node_revision', 'revision')
      ->fields('revision', ['vid'])->condition('nid', $ids, 'IN')->orderBy('vid')->execute()->fetchCol();
    foreach ($revision_ids as $revision_id) {
      $snapshot['revisions'][$revision_id] = self::contentHash($storage->loadRevision($revision_id));
    }
    $snapshot['aliases'] = \Drupal::database()->select('path_alias', 'alias')
      ->fields('alias')->condition('path', array_map(static fn ($id): string => '/node/' . $id, $ids), 'IN')
      ->orderBy('id')->execute()->fetchAll(\PDO::FETCH_ASSOC);
    return $snapshot;
  }

  private static function contentHash(NodeInterface $node): string {
    $translations = [];
    foreach ($node->getTranslationLanguages() as $langcode => $language) {
      $values = $node->getTranslation($langcode)->toArray();
      unset($values['type']);
      ksort($values);
      $translations[$langcode] = $values;
    }
    ksort($translations);
    return hash('sha256', serialize($translations));
  }

}
