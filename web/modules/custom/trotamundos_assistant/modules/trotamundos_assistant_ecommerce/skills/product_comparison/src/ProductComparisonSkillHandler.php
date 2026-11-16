<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_ecommerce\SkillLocal\ProductComparison;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Handles product comparison payloads.
 */
final class ProductComparisonSkillHandler extends AbstractSkillHandler {

  /**
   * {@inheritdoc}
   */
  public function buildVariables(SkillContext $context): array {
    return [
      '#description' => (string) ($context->result['description'] ?? ($context->parsedFallback['intro'] ?: NULL)),
      '#outro' => (string) ($context->result['outro'] ?? $context->parsedFallback['outro']),
      '#product_items' => $this->runtime->normalizeItems($context->result['items'] ?? $this->comparisonRowsToItems($context->result['rows'] ?? $context->parsedFallback['items'])),
      '#comparison_rows' => $this->runtime->normalizeRows($context->result['rows'] ?? $this->buildComparisonRows($context)),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildChatReply(SkillContext $context): string {
    return $context->structuredChatReply() ?: $this->getFallbackChatReply($context);
  }

  /**
   * Builds rows for the comparison template.
   *
   * @return array<int, array{label: string, value: string}>
   *   Comparison rows.
   */
  private function buildComparisonRows(SkillContext $context): array {
    if ($context->parsedFallback['items'] !== []) {
      return array_map(static fn(array $item): array => [
        'label' => $item['title'],
        'value' => $item['body'],
      ], $context->parsedFallback['items']);
    }

    return array_map(static fn(array $item): array => [
      'label' => $item['title'],
      'value' => $item['body'],
    ], $this->runtime->paragraphsAsItems($context->assistantReply));
  }

  /**
   * Converts comparison rows into product-like items for catalog cards.
   *
   * @return array<int, array<string, string|int>>
   *   Product-like items.
   */
  private function comparisonRowsToItems(mixed $rows): array {
    if (!is_array($rows)) {
      return [];
    }

    $items = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $items[] = [
        'title' => trim((string) ($row['label'] ?? $row['title'] ?? '')),
        'body' => trim((string) ($row['value'] ?? $row['body'] ?? $row['description'] ?? '')),
        'product_id' => (int) ($row['product_id'] ?? 0),
        'variation_id' => (int) ($row['variation_id'] ?? 0),
      ];
    }

    return $items;
  }

}
