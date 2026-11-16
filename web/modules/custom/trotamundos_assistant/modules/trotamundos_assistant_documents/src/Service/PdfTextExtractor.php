<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_documents\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;

/**
 * Extracts plain text from PDF files.
 */
final class PdfTextExtractor implements DocumentTextExtractorInterface {

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getProcessId(): string {
    return 'pdf';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return 'PDF';
  }

  /**
   * {@inheritdoc}
   */
  public function getRequiredExtension(): string {
    return 'pdf';
  }

  /**
   * {@inheritdoc}
   */
  public function extract(FileInterface $file, int $max_length = 50000): string {
    if (!$this->isPdf($file)) {
      return '';
    }

    $path = $this->fileSystem->realpath($file->getFileUri());
    if (!$path || !is_readable($path)) {
      $this->logger->warning('No se puede leer el PDF @uri.', ['@uri' => $file->getFileUri()]);
      return '';
    }

    $text = $this->extractWithSmalot($path);
    $text = $this->normalizeText($text);
    if ($max_length > 0 && strlen($text) > $max_length) {
      $text = substr($text, 0, $max_length);
    }

    return $text;
  }

  private function isPdf(FileInterface $file): bool {
    $mime_type = (string) $file->getMimeType();
    $filename = strtolower((string) $file->getFilename());
    return $mime_type === 'application/pdf' || str_ends_with($filename, '.pdf');
  }

  private function extractWithSmalot(string $path): string {
    if (!class_exists('\Smalot\PdfParser\Parser')) {
      return '';
    }

    try {
      $parser = new \Smalot\PdfParser\Parser();
      return (string) $parser->parseFile($path)->getText();
    }
    catch (\Throwable $exception) {
      $this->logger->warning('No se pudo extraer el PDF con smalot/pdfparser: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return '';
    }
  }

  private function normalizeText(string $text): string {
    $text = str_replace("\0", '', $text);
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/\R{3,}/', "\n\n", $text) ?? $text;
    return trim($text);
  }

}
