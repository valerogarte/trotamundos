<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai_search\Plugin\AiFunctionCall\RagTool;

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
    parent::execute();
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
