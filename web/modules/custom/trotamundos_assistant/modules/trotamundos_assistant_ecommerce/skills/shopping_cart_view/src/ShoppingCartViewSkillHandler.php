<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_ecommerce\SkillLocal\ShoppingCartView;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Handles shopping cart view payloads.
 */
final class ShoppingCartViewSkillHandler extends AbstractSkillHandler {

  /**
   * {@inheritdoc}
   */
  public function buildVariables(SkillContext $context): array {
    $cart_summary = $this->runtime->buildCurrentCartSummary();
    $cart_items = $cart_summary['items'];

    return [
      '#description' => (string) ($context->result['description'] ?? ($context->parsedFallback['intro'] ?: NULL)),
      '#intro' => $cart_items !== []
        ? (string) $this->t('Estos son los productos que tienes ahora mismo en el carrito.')
        : (string) ($context->result['intro'] ?? $context->parsedFallback['intro']),
      '#outro' => $cart_items !== []
        ? ''
        : (string) ($context->result['outro'] ?? $context->parsedFallback['outro']),
      '#product_items' => [],
      '#cart_items' => $cart_items,
      '#cart_groups' => $cart_summary['groups'],
      '#cart_total' => $cart_summary['total'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildChatReply(SkillContext $context): string {
    return $context->structuredChatReply() ?: $this->getFallbackChatReply($context);
  }

}
