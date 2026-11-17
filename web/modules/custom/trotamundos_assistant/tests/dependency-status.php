<?php

declare(strict_types=1);

/**
 * @file
 * Run with drush php:script. Configuration overrides are process-local.
 */

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

if (PHP_SAPI !== 'cli' || !class_exists('Drupal')) {
  throw new RuntimeException('Run this test with drush php:script.');
}

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$builder = \Drupal::service('trotamundos_assistant.dependency_status_builder');
$factory = \Drupal::configFactory();
$find = static function (string $label) use ($builder): ?array {
  foreach ($builder->buildChecks() as $check) {
    if ((string) $check['label'] === $label) {
      return $check;
    }
  }
  return NULL;
};

$qdrant = $find('Clave de Qdrant');
$assert($qdrant !== NULL && $qdrant['status'] === TRUE, 'The configured Qdrant key cannot be read.');
$assert($qdrant['url']->getRouteName() === 'entity.key.edit_form', 'The available key does not link to its edit form.');
$assert($find('Módulo: AI VDB Provider Qdrant')['status'] === TRUE, 'The Qdrant provider is not enabled.');
$assert($find('Variable de entorno PGVECTOR_PASSWORD') === NULL, 'The status page still requires the old pgvector key.');

$override = new class implements ConfigFactoryOverrideInterface {
  public string $keyId = '';

  public function loadOverrides($names) {
    return in_array('ai_vdb_provider_qdrant.settings', $names, TRUE)
      ? ['ai_vdb_provider_qdrant.settings' => ['api_key' => $this->keyId]]
      : [];
  }

  public function getCacheSuffix() {
    return 'qdrant_key_test';
  }

  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

  public function getCacheableMetadata($name) {
    return new CacheableMetadata();
  }
};
$factory->addOverride($override);

foreach (['', 'nonexistent_qdrant_test_key'] as $key_id) {
  $override->keyId = $key_id;
  $factory->reset('ai_vdb_provider_qdrant.settings');
  $qdrant = $find('Clave de Qdrant');
  $assert($qdrant !== NULL && $qdrant['status'] === FALSE, 'A missing Qdrant key was reported as available.');
  $assert($qdrant['url']->getRouteName() === 'entity.key.collection', 'A missing key links to a nonexistent edit form.');
}

echo "Dependency status checks passed.\n";
