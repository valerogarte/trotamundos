<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_documents\Service;

/**
 * Resolves text extractors by configured extension process.
 */
final class DocumentTextExtractorRegistry {

  /**
   * @var array<string, \Drupal\trotamundos_assistant_documents\Service\DocumentTextExtractorInterface>
   */
  private array $extractors = [];

  /**
   * @param iterable<\Drupal\trotamundos_assistant_documents\Service\DocumentTextExtractorInterface> $extractors
   *   Available text extractors.
   */
  public function __construct(iterable $extractors) {
    foreach ($extractors as $extractor) {
      $this->extractors[$extractor->getProcessId()] = $extractor;
    }
  }

  public function get(string $process_id): ?DocumentTextExtractorInterface {
    return $this->extractors[$process_id] ?? NULL;
  }

  /**
   * Gets selector options keyed by extension process.
   *
   * @return array<string, string>
   *   Extractor labels keyed by process ID.
   */
  public function getOptions(): array {
    $options = [];
    foreach ($this->extractors as $process_id => $extractor) {
      $options[$process_id] = $extractor->getLabel();
    }

    return $options;
  }

  public function getRequiredExtension(string $process_id): string {
    return $this->get($process_id)?->getRequiredExtension() ?? '';
  }

  public function getLabel(string $process_id): string {
    return $this->get($process_id)?->getLabel() ?? $process_id;
  }

}
