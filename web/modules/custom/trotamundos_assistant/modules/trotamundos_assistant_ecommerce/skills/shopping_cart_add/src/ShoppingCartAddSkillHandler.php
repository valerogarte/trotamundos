<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_ecommerce\SkillLocal\ShoppingCartAdd;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Handles shopping cart add payloads.
 */
final class ShoppingCartAddSkillHandler extends AbstractSkillHandler {

  /**
   * {@inheritdoc}
   */
  public function buildVariables(SkillContext $context): array {
    return [
      '#description' => (string) ($context->result['description'] ?? ($context->parsedFallback['intro'] ?: NULL)),
      '#intro' => (string) ($context->result['intro'] ?? $context->parsedFallback['intro']),
      '#outro' => (string) ($context->result['outro'] ?? $context->parsedFallback['outro']),
      '#product_items' => $this->runtime->normalizeItems($context->result['items'] ?? $context->parsedFallback['items'], TRUE),
      '#cart_items' => [],
      '#cart_groups' => [],
      '#cart_total' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildChatReply(SkillContext $context): string {
    return $context->structuredChatReply() ?: $this->getFallbackChatReply($context);
  }

}
