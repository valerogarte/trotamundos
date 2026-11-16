<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_ecommerce\SkillLocal\ProductSummary;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Handles product summary payloads.
 */
final class ProductSummarySkillHandler extends AbstractSkillHandler {

  /**
   * {@inheritdoc}
   */
  public function buildVariables(SkillContext $context): array {
    $is_structured = $context->structuredReply !== [];
    $product_items = $context->result['items']
      ?? $this->summaryPointsToItems($context->result['points'] ?? ($is_structured ? [] : $context->parsedFallback['items']));
    $summary_points = $context->result['points'] ?? ($is_structured ? [] : $context->parsedFallback['items']);

    return [
      '#description' => (string) ($context->result['description'] ?? ($is_structured ? '' : ($context->parsedFallback['intro'] ?: $context->assistantReply))),
      '#product_items' => $this->runtime->normalizeItems($product_items),
      '#summary_points' => $this->runtime->normalizeItems($summary_points),
      '#outro' => (string) ($context->result['outro'] ?? ($is_structured ? '' : $context->parsedFallback['outro'])),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildChatReply(SkillContext $context): string {
    return $context->structuredChatReply() ?: $this->getFallbackChatReply($context);
  }

  /**
   * Converts summary points into product-like items.
   *
   * @return array<int, array<string, string|int>>
   *   Product-like items.
   */
  private function summaryPointsToItems(mixed $points): array {
    if (!is_array($points)) {
      return [];
    }

    $items = [];
    foreach ($points as $point) {
      if (!is_array($point)) {
        continue;
      }

      $title = trim((string) ($point['product'] ?? $point['product_title'] ?? ''));
      if ($title === '') {
        continue;
      }

      $items[] = [
        'title' => $title,
        'body' => trim((string) ($point['body'] ?? $point['description'] ?? $point['summary'] ?? '')),
        'product_id' => (int) ($point['product_id'] ?? 0),
        'variation_id' => (int) ($point['variation_id'] ?? 0),
      ];
    }

    return $items;
  }

}
