<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_ecommerce\SkillLocal\Payment;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Handles payment payloads.
 */
final class PaymentSkillHandler extends AbstractSkillHandler {

  /**
   * {@inheritdoc}
   */
  public function buildVariables(SkillContext $context): array {
    return [
      '#description' => (string) ($context->result['description'] ?? ($context->parsedFallback['intro'] ?: NULL)),
      '#intro' => (string) ($context->result['intro'] ?? $context->parsedFallback['intro']),
      '#outro' => (string) ($context->result['outro'] ?? $context->parsedFallback['outro']),
      '#product_items' => $this->runtime->normalizeItems($context->result['items'] ?? $context->parsedFallback['items'], TRUE),
      '#summary_points' => $this->runtime->normalizeItems($context->result['steps'] ?? $context->result['payment_options'] ?? []),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildChatReply(SkillContext $context): string {
    return $context->structuredChatReply() ?: $this->getFallbackChatReply($context);
  }

}
