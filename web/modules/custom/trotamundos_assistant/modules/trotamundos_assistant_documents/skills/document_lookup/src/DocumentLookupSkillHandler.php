<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_documents\SkillLocal\DocumentLookup;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Handles document lookup payloads.
 */
final class DocumentLookupSkillHandler extends AbstractSkillHandler {

  /**
   * {@inheritdoc}
   */
  public function buildVariables(SkillContext $context): array {
    $references = $this->normalizeReferences($context->result['references'] ?? []);
    $documents = $this->normalizeDocuments($context->result['documents'] ?? [], $references);
    $citations = $this->normalizeCitations($context->result['citations'] ?? [], $documents);

    return [
      '#answer' => (string) ($context->result['answer'] ?? $context->structuredChatReply()),
      '#summary' => (string) ($context->result['summary'] ?? $context->result['description'] ?? ''),
      '#citations' => $citations,
      '#documents' => $documents,
      '#references' => $references,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildChatReply(SkillContext $context): string {
    return $context->structuredChatReply() ?: $this->getFallbackChatReply($context);
  }

  /**
   * @return array<int, array<string, mixed>>
   *   Normalized document links.
   */
  private function normalizeDocuments(mixed $documents, array $references): array {
    if (!is_array($documents)) {
      return [];
    }

    $fallback_reference_url = count($documents) === 1 && count($references) === 1
      ? $this->normalizeSourceUrl($references[0]['url'] ?? '')
      : '';
    $normalized = [];
    foreach ($documents as $document) {
      if (!is_array($document)) {
        continue;
      }

      $media_id = (int) ($document['media_id'] ?? 0);
      $file_id = (int) ($document['file_id'] ?? 0);
      $entity_type = trim((string) ($document['entity_type'] ?? ''));
      $entity_id = (int) ($document['entity_id'] ?? 0);
      $title = trim((string) ($document['title'] ?? $document['document_title'] ?? ''));
      $source_url = $this->normalizeSourceUrl($document['document_url'] ?? $document['url'] ?? '');
      [$source_entity_type, $source_entity_id] = $this->resolveSourceEntity($source_url);
      if ($entity_type === '' || $entity_id <= 0) {
        [$entity_type, $entity_id] = [$source_entity_type, $source_entity_id];
      }
      $entity_url = $this->normalizeSourceUrl($document['entity_url'] ?? '');
      if ($entity_url === '' && $entity_type !== '' && $entity_id > 0) {
        $entity_url = $this->runtime->getEntityUrl($entity_type, $entity_id);
      }

      $document_url = $file_id > 0 ? $this->getPublicFileUrl($file_id) : '';
      if ($document_url === '' && ($source_url === '' || ($source_entity_type === $entity_type && $source_entity_id === $entity_id))) {
        $resolved = $this->resolveEntityDocument($entity_type, $entity_id);
        if ($resolved !== []) {
          $document_url = $resolved['url'];
          $media_id = $resolved['media_id'];
          $file_id = $resolved['file_id'];
        }
      }
      if ($document_url === '') {
        $document_url = $source_url;
      }
      if ($document_url === '') {
        $document_url = $entity_url;
      }
      if ($document_url === '' && $media_id > 0) {
        $media = \Drupal::entityTypeManager()->getStorage('media')->load($media_id);
        if ($media instanceof MediaInterface && $media->isPublished() && $media->access('view')) {
          $document_url = $this->runtime->getEntityUrl('media', $media_id);
        }
      }
      if ($document_url === '') {
        $document_url = $fallback_reference_url;
      }

      if ($title === '' && $media_id > 0) {
        $title = $this->runtime->getEntityLabel('media', $media_id);
      }

      $normalized[] = [
        'title' => $title ?: (string) $this->t('Documento'),
        'document_url' => $document_url,
        'media_id' => $media_id,
        'file_id' => $file_id,
        'entity_type' => $entity_type,
        'entity_id' => $entity_id,
        'source_url' => $source_url,
      ];
    }

    return $normalized;
  }

  /**
   * @param array<int, array<string, mixed>> $documents
   *   Normalized document links.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized citations.
   */
  private function normalizeCitations(mixed $citations, array $documents): array {
    if (!is_array($citations)) {
      return [];
    }

    $normalized = [];
    foreach ($citations as $citation) {
      if (!is_array($citation)) {
        continue;
      }

      $fallback_document = count($documents) === 1 ? $documents[0] : [];
      $document_title = trim((string) ($citation['document_title'] ?? ''));
      $matching_documents = array_values(array_filter($documents, static fn(array $document): bool => $document_title !== '' && $document_title === $document['title']));
      if (count($matching_documents) === 1) {
        $fallback_document = $matching_documents[0];
      }
      elseif (count($matching_documents) > 1) {
        $fallback_document = [];
      }
      $citation_url = $this->normalizeSourceUrl($citation['document_url'] ?? $fallback_document['document_url'] ?? '');
      $source_matches = array_values(array_filter($documents, static fn(array $document): bool => $citation_url !== '' && $citation_url === $document['source_url']));
      if (count($source_matches) === 1) {
        $citation_url = $source_matches[0]['document_url'];
      }

      $page = (int) ($citation['page'] ?? 0);
      $location = trim((string) ($citation['location'] ?? ''));
      if ($location === '' && $page > 0) {
        $location = (string) $this->t('Página @page', ['@page' => $page]);
      }

      $normalized[] = [
        'document_title' => trim((string) ($citation['document_title'] ?? $fallback_document['title'] ?? '')),
        'document_url' => $citation_url,
        'location' => $location,
        'page' => $page,
        'quote' => trim((string) ($citation['quote'] ?? $citation['excerpt'] ?? $citation['text'] ?? '')),
      ];
    }

    return $normalized;
  }

  /**
   * Accepts web URLs and local absolute paths for document links.
   */
  private function normalizeSourceUrl(mixed $value): string {
    $url = trim((string) $value);
    if (preg_match('#^https?://#i', $url) === 1 && UrlHelper::isValid($url, TRUE)) {
      return $url;
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//') && UrlHelper::isValid($url)) {
      return $url;
    }

    return '';
  }

  /**
   * Resolves a same-host canonical URL to its own accessible entity.
   */
  private function resolveSourceEntity(string $url): array {
    if ($url === '') {
      return ['', 0];
    }
    $parts = parse_url($url);
    if ($parts === FALSE || (isset($parts['host']) && strcasecmp($parts['host'], \Drupal::request()->getHost()) !== 0)) {
      return ['', 0];
    }
    $route_url = \Drupal::service('path.validator')->getUrlIfValid($parts['path'] ?? '');
    if (!$route_url || !$route_url->isRouted() || !preg_match('/^entity\.([a-z_]+)\.canonical$/', $route_url->getRouteName(), $match)) {
      return ['', 0];
    }
    return [$match[1], (int) ($route_url->getRouteParameters()[$match[1]] ?? 0)];
  }

  /**
   * Resolves an existing public file the current account can view.
   */
  private function getPublicFileUrl(int $file_id): string {
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
    if (!$file instanceof FileInterface || !str_starts_with($file->getFileUri(), 'public://') || !$file->access('view')) {
      return '';
    }

    return $this->runtime->getFileUrl($file_id);
  }

  /**
   * Finds one accessible PDF referenced by the entity's extraction rules.
   */
  private function resolveEntityDocument(string $entity_type, int $entity_id): array {
    if ($entity_type === '' || $entity_id <= 0 || !\Drupal::entityTypeManager()->hasDefinition($entity_type)) {
      return [];
    }
    $entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id);
    if (!$entity instanceof ContentEntityInterface || !$entity->access('view')) {
      return [];
    }
    $documents = [];
    foreach (\Drupal::config('trotamundos_assistant_documents.settings')->get('rules') ?? [] as $rule) {
      $field = (string) ($rule['source_media_field'] ?? '');
      if (empty($rule['enabled']) || ($rule['source_entity_type'] ?? '') !== $entity_type
        || ($rule['source_bundle'] ?? '') !== $entity->bundle()
        || !$entity->hasField($field) || !$entity->get($field)->access('view')) {
        continue;
      }
      foreach ($entity->get($field)->referencedEntities() as $media) {
        if (!$media instanceof MediaInterface || $media->bundle() !== ($rule['media_type'] ?? '')) {
          continue;
        }
        $resolved = $this->resolveMediaDocument($media, (string) ($rule['media_file_field'] ?? ''));
        if ($resolved !== []) {
          $documents[$resolved['file_id']] = $resolved;
        }
      }
    }

    return count($documents) === 1 ? reset($documents) : [];
  }

  /**
   * Resolves a published Media document and its accessible file field.
   */
  private function resolveMediaDocument(MediaInterface $media, string $file_field): array {
    if (!$media->isPublished() || !$media->access('view') || !$media->hasField($file_field)
      || !$media->get($file_field)->access('view') || count($media->get($file_field)) !== 1) {
      return [];
    }
    $file = $media->get($file_field)->entity;
    if (!$file instanceof FileInterface || $file->getMimeType() !== 'application/pdf') {
      return [];
    }
    $url = $this->getPublicFileUrl((int) $file->id());

    return $url !== '' ? ['url' => $url, 'file_id' => (int) $file->id(), 'media_id' => (int) $media->id()] : [];
  }

  /**
   * @return array<int, array<string, mixed>>
   *   Normalized referencing entities.
   */
  private function normalizeReferences(mixed $references): array {
    if (!is_array($references)) {
      return [];
    }

    $normalized = [];
    foreach ($references as $reference) {
      if (!is_array($reference)) {
        continue;
      }

      $entity_type = trim((string) ($reference['entity_type'] ?? ''));
      $entity_id = (int) ($reference['entity_id'] ?? 0);
      $url = trim((string) ($reference['url'] ?? ''));
      if ($url === '' && $entity_type !== '' && $entity_id > 0) {
        $url = $this->runtime->getEntityUrl($entity_type, $entity_id);
      }

      $label = trim((string) ($reference['label'] ?? ''));
      if ($label === '' && $entity_type !== '' && $entity_id > 0) {
        $label = $this->runtime->getEntityLabel($entity_type, $entity_id);
      }

      $normalized[] = [
        'label' => $label ?: (string) $this->t('Referencia'),
        'url' => $url,
        'field' => trim((string) ($reference['field'] ?? $reference['field_label'] ?? '')),
        'entity_type' => $entity_type,
        'entity_id' => $entity_id,
      ];
    }

    return $normalized;
  }

}
