<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_documents\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\search_api\Utility\Utility;
use Psr\Log\LoggerInterface;

/**
 * Enqueues and processes document text extraction rules.
 */
final class DocumentExtractionManager {

  private const QUEUE_NAME = 'trotamundos_assistant_documents_extract';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly DocumentTextExtractorRegistry $textExtractorRegistry,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Enqueues extraction for configured Media references on an entity.
   */
  public function enqueueEntity(EntityInterface $entity): void {
    if (!$entity instanceof ContentEntityInterface) {
      return;
    }

    $rules = $this->getMatchingRules($entity);
    if ($rules === []) {
      return;
    }

    foreach ($rules as $rule_id => $rule) {
      $source_field = (string) ($rule['source_media_field'] ?? '');
      $destination_field = (string) ($rule['destination_field'] ?? '');
      if ($source_field === '' || $destination_field === '') {
        continue;
      }

      if (!$entity->hasField($source_field)) {
        continue;
      }

      $source_references_changed = $this->haveSourceReferencesChanged($entity, $source_field);

      foreach ($entity->get($source_field)->referencedEntities() as $media) {
        if (!$media instanceof MediaInterface || $media->bundle() !== ($rule['media_type'] ?? '')) {
          continue;
        }

        if ($source_references_changed || $this->mediaNeedsExtraction($media, $destination_field)) {
          $this->enqueueExtraction($media, (string) $rule_id, $source_references_changed);
        }
      }
    }
  }

  /**
   * Enqueues extraction when a configured Media file changes.
   */
  public function enqueueMediaUpdate(MediaInterface $media): void {
    $rules = $this->getRulesForMedia($media);
    if ($rules === []) {
      return;
    }

    foreach ($rules as $rule_id => $rule) {
      $media_file_field = (string) ($rule['media_file_field'] ?? '');
      if (!$this->hasMediaFileChanged($media, $media_file_field)) {
        continue;
      }

      $this->enqueueExtraction($media, (string) $rule_id, TRUE);
    }
  }

  /**
   * Processes one queued extraction item.
   *
   * @param array<string, mixed> $item
   *   Queue item data.
   */
  public function processQueueItem(array $item): void {
    $media_id = (int) ($item['media_id'] ?? 0);
    $rule_id = (string) ($item['rule_id'] ?? '');
    $force = !empty($item['force']);
    if ($media_id <= 0 || $rule_id === '') {
      return;
    }

    $rules = $this->getConfiguredRules();
    $rule = is_array($rules[$rule_id] ?? NULL) ? $rules[$rule_id] : [];
    if ($rule === [] || empty($rule['enabled'])) {
      return;
    }

    $media = $this->entityTypeManager->getStorage('media')->load($media_id);
    if (!$media instanceof MediaInterface || $media->bundle() !== ($rule['media_type'] ?? '')) {
      return;
    }

    $changed = $this->updateMediaExtractedText(
      $media,
      (string) ($rule['media_file_field'] ?? ''),
      (string) ($rule['destination_field'] ?? ''),
      (string) ($rule['extension_process'] ?? 'pdf'),
      (int) ($rule['max_text_length'] ?? 50000),
      $force,
    );

    if ($changed) {
      $this->reindexReferencingEntities($media, $rule);
    }
  }

  /**
   * @return array<int, array<string, mixed>>
   *   Matching enabled rules.
   */
  private function getMatchingRules(ContentEntityInterface $entity): array {
    $rules = $this->getConfiguredRules();
    $entity_type_id = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    return array_filter($rules, static function (mixed $rule) use ($entity_type_id, $bundle): bool {
      return is_array($rule)
        && !empty($rule['enabled'])
        && ($rule['source_entity_type'] ?? '') === $entity_type_id
        && ($rule['source_bundle'] ?? '') === $bundle;
    });
  }

  /**
   * @return array<int, array<string, mixed>>
   *   Enabled rules for the Media bundle.
   */
  private function getRulesForMedia(MediaInterface $media): array {
    $rules = $this->getConfiguredRules();
    $bundle = $media->bundle();
    return array_filter($rules, static function (mixed $rule) use ($bundle): bool {
      return is_array($rule)
        && !empty($rule['enabled'])
        && ($rule['media_type'] ?? '') === $bundle;
    });
  }

  /**
   * @return array<string, array<string, mixed>>
   *   Configured extraction rules keyed by rule ID.
   */
  private function getConfiguredRules(): array {
    $settings = $this->configFactory->get('trotamundos_assistant_documents.settings');
    $rules = $settings->get('rules') ?: [];
    if (!is_array($rules)) {
      return [];
    }

    return $rules;
  }

  private function extractMediaText(MediaInterface $media, string $media_file_field, string $extension_process, int $max_length): string {
    if ($media_file_field === '' || !$media->hasField($media_file_field) || $media->get($media_file_field)->isEmpty()) {
      return '';
    }

    $file = $media->get($media_file_field)->entity;
    if (!$file instanceof FileInterface) {
      return '';
    }

    $extractor = $this->textExtractorRegistry->get($extension_process);
    if (!$extractor instanceof DocumentTextExtractorInterface) {
      $this->logger->warning('No hay extractor configurado para el proceso @process.', [
        '@process' => $extension_process,
      ]);
      return '';
    }

    try {
      return $extractor->extract($file, $max_length);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Error extrayendo texto del Media @media_id con el proceso @process: @message', [
        '@media_id' => $media->id(),
        '@process' => $extension_process,
        '@message' => $exception->getMessage(),
      ]);
      return '';
    }
  }

  private function haveSourceReferencesChanged(ContentEntityInterface $entity, string $source_field): bool {
    if ($entity->isNew()) {
      return TRUE;
    }

    if (!isset($entity->original) || !$entity->original instanceof ContentEntityInterface) {
      return TRUE;
    }

    if (!$entity->original->hasField($source_field)) {
      return TRUE;
    }

    return $this->getTargetIds($entity, $source_field) !== $this->getTargetIds($entity->original, $source_field);
  }

  private function updateMediaExtractedText(MediaInterface $media, string $media_file_field, string $destination_field, string $extension_process, int $max_length, bool $force = FALSE): bool {
    if ($destination_field === '' || !$media->hasField($destination_field)) {
      return FALSE;
    }

    if (!$force && !$media->get($destination_field)->isEmpty()) {
      return FALSE;
    }

    $text = $this->extractMediaText($media, $media_file_field, $extension_process, $max_length);
    if ($text === '') {
      return FALSE;
    }

    $current = $media->get($destination_field)->value;
    if ((string) $current === $text) {
      return FALSE;
    }

    $media->set($destination_field, $text);
    $media->save();
    return TRUE;
  }

  private function hasMediaFileChanged(MediaInterface $media, string $media_file_field): bool {
    if ($media_file_field === '' || !$media->hasField($media_file_field)) {
      return FALSE;
    }

    if (!isset($media->original) || !$media->original instanceof MediaInterface || !$media->original->hasField($media_file_field)) {
      return TRUE;
    }

    return $this->getTargetIds($media, $media_file_field) !== $this->getTargetIds($media->original, $media_file_field);
  }

  /**
   * @param array<string, mixed> $rule
   *   Extraction rule.
   */
  private function reindexReferencingEntities(MediaInterface $media, array $rule): void {
    foreach ($this->loadReferencingEntities($media, $rule) as $entity) {
      $this->reindexEntity($entity);
    }
  }

  /**
   * @param array<string, mixed> $rule
   *   Extraction rule.
   *
   * @return array<int, \Drupal\Core\Entity\ContentEntityInterface>
   *   Entities referencing the Media for this rule.
   */
  private function loadReferencingEntities(MediaInterface $media, array $rule): array {
    $entity_type_id = (string) ($rule['source_entity_type'] ?? '');
    $bundle = (string) ($rule['source_bundle'] ?? '');
    $source_field = (string) ($rule['source_media_field'] ?? '');
    if ($entity_type_id === '' || $bundle === '' || $source_field === '') {
      return [];
    }

    try {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()
        ->accessCheck(FALSE)
        ->condition($source_field . '.target_id', $media->id());
      if ($bundle_key = $entity_type->getKey('bundle')) {
        $query->condition($bundle_key, $bundle);
      }

      $ids = $query->execute();
      if ($ids === []) {
        return [];
      }

      $entities = $this->entityTypeManager->getStorage($entity_type_id)->loadMultiple($ids);
      return array_values(array_filter($entities, static fn($entity): bool => $entity instanceof ContentEntityInterface));
    }
    catch (\Throwable $exception) {
      $this->logger->warning('No se pudieron refrescar entidades que referencian el Media @media_id: @message', [
        '@media_id' => $media->id(),
        '@message' => $exception->getMessage(),
      ]);
      return [];
    }
  }

  private function reindexEntity(ContentEntityInterface $entity): void {
    if (!$this->entityTypeManager->hasDefinition('search_api_index')) {
      return;
    }

    $datasource_id = 'entity:' . $entity->getEntityTypeId();
    try {
      $indexes = $this->entityTypeManager->getStorage('search_api_index')->loadMultiple();
      foreach ($indexes as $index) {
        if (method_exists($index, 'status') && !$index->status()) {
          continue;
        }
        if (!$index->isValidDatasource($datasource_id)) {
          continue;
        }

        $datasource = $index->getDatasourceIfAvailable($datasource_id);
        $item_id = $datasource?->getItemId($entity->getTypedData());
        if ($item_id === NULL) {
          continue;
        }

        $index->trackItemsUpdated($datasource_id, [$item_id]);
        $combined_id = Utility::createCombinedId($datasource_id, $item_id);
        $index->indexSpecificItems([$combined_id => $entity->getTypedData()]);
      }
    }
    catch (\Throwable $exception) {
      $this->logger->warning('No se pudo reindexar la entidad @entity_type:@entity_id en Search API: @message', [
        '@entity_type' => $entity->getEntityTypeId(),
        '@entity_id' => $entity->id(),
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  private function enqueueExtraction(MediaInterface $media, string $rule_id, bool $force): void {
    $media_id = (int) $media->id();
    if ($media_id <= 0 || $rule_id === '') {
      return;
    }

    $this->queueFactory
      ->get(self::QUEUE_NAME)
      ->createItem([
        'media_id' => $media_id,
        'rule_id' => $rule_id,
        'force' => $force,
      ]);
  }

  private function mediaNeedsExtraction(MediaInterface $media, string $destination_field): bool {
    return $destination_field !== ''
      && $media->hasField($destination_field)
      && $media->get($destination_field)->isEmpty();
  }

  /**
   * @return array<int, int>
   *   Referenced target IDs.
   */
  private function getTargetIds(ContentEntityInterface $entity, string $field_name): array {
    $target_ids = [];
    foreach ($entity->get($field_name) as $item) {
      if (!empty($item->target_id)) {
        $target_ids[] = (int) $item->target_id;
      }
    }

    sort($target_ids);
    return $target_ids;
  }

}
