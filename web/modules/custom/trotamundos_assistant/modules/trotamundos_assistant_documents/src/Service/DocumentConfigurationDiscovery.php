<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_documents\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Discovers Media file fields and entity bundles that reference Media.
 */
final class DocumentConfigurationDiscovery {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {
  }

  /**
   * Returns available Media types keyed by bundle ID.
   *
   * @return array<string, string>
   *   Media type labels keyed by media bundle.
   */
  public function getMediaTypeOptions(): array {
    $options = [];
    $media_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();
    foreach ($media_types as $media_type) {
      $options[(string) $media_type->id()] = (string) $media_type->label();
    }

    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Returns file fields for one Media type.
   *
   * @return array<string, string>
   *   File field labels keyed by field name.
   */
  public function getMediaFileFieldOptions(string $media_type): array {
    $options = [];
    foreach ($this->entityFieldManager->getFieldDefinitions('media', $media_type) as $field_name => $definition) {
      if ($definition->getType() === 'file') {
        $options[$field_name] = $this->formatFieldOption($field_name, $definition);
      }
    }

    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Returns allowed file extensions for a Media file field.
   *
   * @return array<int, string>
   *   Lowercase file extensions.
   */
  public function getMediaFileFieldExtensions(string $media_type, string $field_name): array {
    $definitions = $this->entityFieldManager->getFieldDefinitions('media', $media_type);
    if (!isset($definitions[$field_name]) || $definitions[$field_name]->getType() !== 'file') {
      return [];
    }

    $extensions = preg_split('/\s+/', strtolower((string) ($definitions[$field_name]->getSetting('file_extensions') ?? ''))) ?: [];
    return array_values(array_filter($extensions, static fn (string $extension): bool => $extension !== ''));
  }

  /**
   * Returns all candidate rules for a Media type and one of its file fields.
   *
   * @return array<string, array<string, string>>
   *   Candidate metadata keyed by stable rule ID.
   */
  public function getRuleCandidates(string $media_type, string $media_file_field): array {
    $candidates = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if (!$entity_type->entityClassImplements('\Drupal\Core\Entity\ContentEntityInterface')) {
        continue;
      }

      foreach ($this->bundleInfo->getBundleInfo($entity_type_id) as $bundle => $bundle_info) {
        $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
        foreach ($field_definitions as $field_name => $definition) {
          if (!$this->isMediaReferenceField($definition, $media_type)) {
            continue;
          }

          $candidate = [
            'media_type' => $media_type,
            'media_file_field' => $media_file_field,
            'source_entity_type' => $entity_type_id,
            'source_entity_type_label' => (string) $entity_type->getLabel(),
            'source_bundle' => (string) $bundle,
            'source_bundle_label' => (string) ($bundle_info['label'] ?? $bundle),
            'source_media_field' => $field_name,
            'source_media_field_label' => $this->formatFieldOption($field_name, $definition),
          ];
          $candidates[$this->buildRuleId($candidate)] = $candidate;
        }
      }
    }

    uasort($candidates, static function (array $a, array $b): int {
      return strnatcasecmp(
        $a['source_entity_type_label'] . $a['source_bundle_label'] . $a['source_media_field_label'],
        $b['source_entity_type_label'] . $b['source_bundle_label'] . $b['source_media_field_label'],
      );
    });
    return $candidates;
  }

  /**
   * Returns text-like destination fields for an entity bundle.
   *
   * @return array<string, string>
   *   Field labels keyed by field name.
   */
  public function getDestinationFieldOptions(string $entity_type_id, string $bundle): array {
    $options = [];
    foreach ($this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle) as $field_name => $definition) {
      if (in_array($definition->getType(), ['text', 'text_long', 'string', 'string_long'], TRUE)) {
        $options[$field_name] = $this->formatFieldOption($field_name, $definition);
      }
    }

    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Returns a readable entity type and bundle label.
   */
  public function getSourceLabel(string $entity_type_id, string $bundle): string {
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return $entity_type_id . ': ' . $bundle;
    }

    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $bundle_info = $this->bundleInfo->getBundleInfo($entity_type_id);
    return sprintf('%s: %s', (string) $entity_type->getLabel(), (string) ($bundle_info[$bundle]['label'] ?? $bundle));
  }

  /**
   * Returns a readable field label for a bundle field.
   */
  public function getFieldLabel(string $entity_type_id, string $bundle, string $field_name): string {
    $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    if (!isset($definitions[$field_name])) {
      return $field_name;
    }

    return $this->formatFieldOption($field_name, $definitions[$field_name]);
  }

  /**
   * Returns a readable Media type label.
   */
  public function getMediaTypeLabel(string $media_type): string {
    $options = $this->getMediaTypeOptions();
    return (string) ($options[$media_type] ?? $media_type);
  }

  /**
   * Builds a stable ID for a rule candidate.
   *
   * @param array<string, string> $values
   *   Rule values.
   */
  public function buildRuleId(array $values): string {
    return implode(':', [
      $values['media_type'] ?? '',
      $values['media_file_field'] ?? '',
      $values['source_entity_type'] ?? '',
      $values['source_bundle'] ?? '',
      $values['source_media_field'] ?? '',
    ]);
  }

  private function isMediaReferenceField(FieldDefinitionInterface $definition, string $media_type): bool {
    if (!in_array($definition->getType(), ['entity_reference', 'entity_reference_revisions'], TRUE)) {
      return FALSE;
    }

    if (($definition->getSetting('target_type') ?? '') !== 'media') {
      return FALSE;
    }

    $handler_settings = $definition->getSetting('handler_settings') ?? [];
    $target_bundles = $handler_settings['target_bundles'] ?? [];
    return empty($target_bundles) || isset($target_bundles[$media_type]) || in_array($media_type, $target_bundles, TRUE);
  }

  private function formatFieldOption(string $field_name, FieldDefinitionInterface $definition): string {
    return sprintf('%s (%s)', (string) $definition->getLabel(), $field_name);
  }

}
