<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_documents\Service;

use Drupal\file\FileInterface;

/**
 * Extracts plain text from files for a configured extension process.
 */
interface DocumentTextExtractorInterface {

  /**
   * Gets the extension process handled by this extractor.
   */
  public function getProcessId(): string;

  /**
   * Gets the human readable label for the extension process.
   */
  public function getLabel(): string;

  /**
   * Gets the required file extension for this extractor.
   */
  public function getRequiredExtension(): string;

  /**
   * Extracts normalized text from a file.
   */
  public function extract(FileInterface $file, int $max_length = 50000): string;

}
