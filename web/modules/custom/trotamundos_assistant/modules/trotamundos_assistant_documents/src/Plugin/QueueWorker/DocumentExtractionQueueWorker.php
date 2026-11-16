<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_documents\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\trotamundos_assistant_documents\Service\DocumentExtractionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued document text extraction jobs.
 *
 * @QueueWorker(
 *   id = "trotamundos_assistant_documents_extract",
 *   title = @Translation("Trotamundos Assistant Documents extraction"),
 *   cron = {"time" = 60}
 * )
 */
final class DocumentExtractionQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly DocumentExtractionManager $extractionManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('trotamundos_assistant_documents.extraction_manager'),
    );
  }

  public function processItem($data): void {
    if (!is_array($data)) {
      return;
    }

    $this->extractionManager->processQueueItem($data);
  }

}
