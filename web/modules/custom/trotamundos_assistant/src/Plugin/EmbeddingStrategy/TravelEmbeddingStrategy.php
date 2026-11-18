<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Plugin\EmbeddingStrategy;

use Drupal\ai_search\Attribute\EmbeddingStrategy;
use Drupal\ai_search\Plugin\EmbeddingStrategy\EmbeddingBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\SearchApiException;

/**
 * Refuses to mark a document indexed when any embedding is missing.
 */
#[EmbeddingStrategy(
  id: 'trotamundos_travel',
  label: new TranslatableMarkup('Trotamundos public travel'),
  description: new TranslatableMarkup('Indexes all chunks and reports incomplete embedding requests.'),
)]
final class TravelEmbeddingStrategy extends EmbeddingBase {

  protected function getRawEmbeddings(array $chunks): array {
    $embeddings = parent::getRawEmbeddings($chunks);
    if ($chunks === [] || count($embeddings) !== count($chunks)) {
      throw new SearchApiException('The embedding provider did not return every requested travel-content vector.');
    }
    foreach (array_keys($chunks) as $key) {
      if (empty($embeddings[$key])) {
        throw new SearchApiException('The embedding provider returned an empty travel-content vector.');
      }
    }
    return $embeddings;
  }

}
