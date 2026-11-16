<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_ecommerce\SkillLocal\EventProductSelection;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Handles complete product selections for events.
 */
final class EventProductSelectionSkillHandler extends AbstractSkillHandler {

  /**
   * {@inheritdoc}
   */
  public function buildVariables(SkillContext $context): array {
    $result = $context->result;
    $categories = $this->normalizeCategories($result['categories'] ?? []);

    if ($categories === [] && !empty($result['items']) && is_array($result['items'])) {
      $items = $this->runtime->normalizeItems($result['items'], TRUE);
      if ($items !== []) {
        $categories[] = [
          'name' => (string) $this->t('Selección recomendada'),
          'description' => '',
          'items' => $items,
        ];
      }
    }

    $is_structured = $context->structuredReply !== [];
    $total_products = 0;
    $total_amount = 0.0;
    $has_addable_items = FALSE;
    foreach ($categories as $category) {
      foreach ($category['items'] as $item) {
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $total_products += $quantity;
        $total_amount += ((float) ($item['price_amount'] ?? 0)) * $quantity;
        if (!empty($item['add_to_cart_url']) && !empty($item['variation_id'])) {
          $has_addable_items = TRUE;
        }
      }
    }

    return [
      '#intro' => (string) ($result['intro'] ?? ($is_structured ? '' : $context->parsedFallback['intro'])),
      '#event_name' => trim((string) ($result['event_name'] ?? $result['event'] ?? '')),
      '#people' => max(0, (int) ($result['people'] ?? $result['attendees'] ?? 0)),
      '#vegetarian_people' => max(0, (int) ($result['vegetarian_people'] ?? $result['vegetarians'] ?? 0)),
      '#dietary_restrictions' => $this->normalizeTextList($result['dietary_restrictions'] ?? $result['restrictions'] ?? []),
      '#budget' => trim((string) ($result['budget'] ?? $result['estimated_budget'] ?? '')),
      '#notes' => trim((string) ($result['notes'] ?? '')),
      '#categories' => $categories,
      '#total_products' => $total_products,
      '#total_amount' => $total_amount > 0
        ? number_format($total_amount, 2, ',', '.') . ' €'
        : (string) $this->t('Consultar'),
      '#has_addable_items' => $has_addable_items,
      '#outro' => (string) ($result['outro'] ?? ($is_structured ? '' : $context->parsedFallback['outro'])),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildChatReply(SkillContext $context): string {
    return $context->structuredChatReply() ?: $this->getFallbackChatReply($context);
  }

  /**
   * Normalizes event categories and their catalog products.
   *
   * @return array<int, array{name: string, description: string, items: array}>
   *   Categories with resolved product card data.
   */
  private function normalizeCategories(mixed $categories): array {
    if (!is_array($categories)) {
      return [];
    }

    $normalized = [];
    foreach ($categories as $category) {
      if (!is_array($category)) {
        continue;
      }

      $items = $category['items'] ?? $category['products'] ?? [];
      $items = $this->runtime->normalizeItems($items, TRUE);
      if ($items === []) {
        continue;
      }

      $name = trim((string) ($category['name'] ?? $category['title'] ?? $category['category'] ?? ''));
      $normalized[] = [
        'name' => $name !== '' ? $name : (string) $this->t('Otros productos'),
        'description' => trim((string) ($category['description'] ?? $category['body'] ?? '')),
        'items' => $items,
      ];
    }

    return $normalized;
  }

  /**
   * Normalizes restrictions or other short text lists.
   *
   * @return string[]
   *   Clean text values.
   */
  private function normalizeTextList(mixed $values): array {
    if (is_string($values)) {
      $values = preg_split('/[,;\n]+/', $values) ?: [];
    }
    if (!is_array($values)) {
      return [];
    }

    $normalized = [];
    foreach ($values as $value) {
      $value = trim((string) (is_array($value) ? ($value['name'] ?? $value['label'] ?? '') : $value));
      if ($value !== '') {
        $normalized[] = $value;
      }
    }

    return array_values(array_unique($normalized));
  }

}
