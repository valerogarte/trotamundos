<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\SkillLocal\ContentAnswer;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Searches WYSIWYG content and answers questions grounded on it.
 */
final class ContentAnswerSkillHandler extends AbstractSkillHandler {

  /**
   * {@inheritdoc}
   */
  public function buildVariables(SkillContext $context): array {
    $answer = (string) ($context->result['answer'] ?? ($context->structuredChatReply() ?: $context->assistantReply));

    // Prefer the sources the assistant actually cited; fall back to a live
    // keyword search only when it declares none.
    $sources = $context->result['sources'] ?? [];
    $matches = is_array($sources) && $sources !== []
      ? $this->runtime->resolveContentSources(array_map('strval', $sources))
      : $this->runtime->searchWysiwygContent(trim((string) ($context->result['query'] ?? $context->result['keywords'] ?? '')))['matches'];

    return [
      '#answer' => $answer,
      '#steps' => $this->normalizeSteps($context->result['steps'] ?? []),
      '#outro' => trim((string) ($context->result['outro'] ?? '')),
      '#found' => $matches !== [],
      '#matches' => $matches,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildChatReply(SkillContext $context): string {
    return $context->structuredChatReply() ?: $this->getFallbackChatReply($context);
  }

  /**
   * Normalizes the answer steps declared by the assistant.
   *
   * @param mixed $steps
   *   Raw steps: a list of {title, body} objects or of plain strings.
   *
   * @return array<int, array{title: string, body: string}>
   *   Renderable steps, empty entries removed.
   */
  private function normalizeSteps(mixed $steps): array {
    if (!is_array($steps)) {
      return [];
    }

    $normalized = [];
    foreach ($steps as $step) {
      // The model sometimes returns a flat list of sentences instead of
      // objects: render those as body-only steps.
      $title = is_array($step) ? trim((string) ($step['title'] ?? '')) : '';
      $body = is_array($step)
        ? trim((string) ($step['body'] ?? $step['description'] ?? ''))
        : trim((string) $step);

      if ($title === '' && $body === '') {
        continue;
      }

      $normalized[] = ['title' => $title, 'body' => $body];
    }

    return $normalized;
  }

}
