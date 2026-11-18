<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai_search\Plugin\AiFunctionCall\RagTool;
use Drupal\search_api\SearchApiException;

/**
 * RAG search that carries the selected Trotamundos Assistant skill between agent loops.
 */
#[FunctionCall(
  id: self::PLUGIN_ID,
  function_name: self::FUNCTION_NAME,
  name: 'Trotamundos Assistant skill-aware RAG search',
  description: 'Selects one Trotamundos Assistant skill and searches the configured vector index before composing that skill response.',
  group: 'trotamundos_assistant',
  module_dependencies: ['ai_search'],
  context_definitions: [
    'skill' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Selected skill'),
      description: new TranslatableMarkup('Exact skill identifier from the Trotamundos Assistant routing catalogue.'),
      required: TRUE,
    ),
    'index' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Index'),
      description: new TranslatableMarkup('The index to search in.'),
      required: TRUE,
    ),
    'search_string' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Search string'),
      description: new TranslatableMarkup('Focused semantic search query for this skill.'),
      required: TRUE,
    ),
    'amount' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Amount'),
      description: new TranslatableMarkup('The amount of results to find.'),
      required: FALSE,
      default_value: 10,
    ),
    'min_score' => new ContextDefinition(
      data_type: 'float',
      label: new TranslatableMarkup('Minimal score'),
      description: new TranslatableMarkup('The minimal score threshold to pass.'),
      required: FALSE,
      default_value: 0.5,
    ),
  ],
)]
final class SkillAwareRagTool extends RagTool {

  public const PLUGIN_ID = 'trotamundos_assistant:skill_rag_search';

  public const FUNCTION_NAME = 'trotamundos_assistant_skill_rag_search';

  /**
   * Exact skill reference selected by the provider.
   */
  private string $skillReference = '';

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $this->skillReference = trim((string) $this->getContextValue('skill'));
    $this->index = (string) $this->getContextValue('index');
    $this->searchString = trim((string) $this->getContextValue('search_string'));
    $amount = max(1, min(20, (int) $this->getContextValue('amount')));
    $min_score = (float) $this->getContextValue('min_score');
    $this->results = [];

    $index = $this->entityTypeManager->getStorage('search_api_index')->load($this->index);
    if (!$index || !$index->status()) {
      throw new SearchApiException('The configured search index is unavailable.');
    }
    if ($this->searchString === '') {
      throw new SearchApiException('A search query is required.');
    }

    $query = $index->query(['limit' => $amount]);
    $query->setOption('search_api_ai_get_chunks_result', TRUE);
    $query->keys($this->searchString);
    $results = $query->execute();
    if (in_array($results->getExtraData('reason_for_finish'), ['client_error', 'search_error'], TRUE)) {
      throw new SearchApiException('The vector search backend could not complete the query.');
    }

    $formatted = [];
    foreach ($results->getResultItems() as $result) {
      if ($min_score > $result->getScore()) {
        continue;
      }
      $content = (string) $result->getExtraData('content');
      [$entity_type, $entity_id, $langcode] = $this->parseDrupalEntityId((string) $result->getExtraData('drupal_entity_id'));
      $this->results[] = [
        'entity_type' => $entity_type,
        'entity_id' => $entity_id,
        'langcode' => $langcode,
        'score' => $result->getScore(),
        'content' => $content,
      ];
      $formatted[] = 'Search result: #' . count($this->results) . ":\n```\n" . $content . "\n```\n\n";
    }

    $this->setOutput($formatted
      ? 'Results from searching in the rag index ' . $this->index . ":\n" . implode("\n", $formatted)
      : 'No results were found when searching in the rag index ' . $this->index . '.');
  }

  /**
   * {@inheritdoc}
   */
  public function getStructuredOutput(): array {
    return ['skill' => $this->skillReference] + parent::getStructuredOutput();
  }

  /**
   * {@inheritdoc}
   */
  public function setStructuredOutput(array $output): void {
    $this->skillReference = trim((string) ($output['skill'] ?? ''));
    parent::setStructuredOutput($output);
  }

}
