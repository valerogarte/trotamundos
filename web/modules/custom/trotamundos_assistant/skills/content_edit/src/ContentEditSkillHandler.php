<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\SkillLocal\ContentEdit;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Handles content edit payloads.
 */
final class ContentEditSkillHandler extends AbstractSkillHandler {

  /**
   * {@inheritdoc}
   */
  public function buildVariables(SkillContext $context): array {
    $entity_type = trim((string) ($context->result['entity_type'] ?? ''));
    $entity_id = (int) ($context->result['entity_id'] ?? 0);
    $content_title = trim((string) ($context->result['content_title'] ?? $context->result['title'] ?? $context->result['name'] ?? ''));
    $content = $this->runtime->buildContentEditForm($content_title, $entity_id, $entity_type);

    return [
      '#description' => (string) ($context->result['description'] ?? ($context->structuredChatReply() ?: $context->assistantReply)),
      '#found' => (bool) ($content['found'] ?? FALSE),
      '#access_denied' => (bool) ($content['access_denied'] ?? FALSE),
      '#ambiguous' => (bool) ($content['ambiguous'] ?? FALSE),
      '#content_title' => (string) ($content['content_title'] ?? $content_title),
      '#content_type' => (string) ($content['content_type'] ?? ''),
      '#edit_url' => (string) ($content['edit_url'] ?? ''),
      '#content_form' => is_array($content['content_form'] ?? NULL) ? $content['content_form'] : [],
      '#matches' => is_array($content['matches'] ?? NULL) ? $content['matches'] : [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildChatReply(SkillContext $context): string {
    return $context->structuredChatReply() ?: $this->getFallbackChatReply($context);
  }

}
