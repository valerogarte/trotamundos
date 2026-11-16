<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_ecommerce\SkillLocal\ProductAlternatives;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Handles product alternative payloads.
 */
final class ProductAlternativesSkillHandler extends AbstractSkillHandler {

  /**
   * {@inheritdoc}
   */
  public function buildVariables(SkillContext $context): array {
    return [
      '#description' => NULL,
      '#intro' => (string) ($context->result['intro'] ?? $context->parsedFallback['intro']),
      '#outro' => (string) ($context->result['outro'] ?? $context->parsedFallback['outro']),
      '#product_items' => $this->runtime->normalizeItems($context->result['items'] ?? $context->parsedFallback['items']),
    ];
  }

}
