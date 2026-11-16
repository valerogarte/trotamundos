<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\SkillLocal\ContentCreate;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Handles content creation payloads.
 */
final class ContentCreateSkillHandler extends AbstractSkillHandler {

  /**
   * {@inheritdoc}
   */
  public function buildVariables(SkillContext $context): array {
    $entity_type = trim((string) ($context->result['entity_type'] ?? ''));
    $bundle = trim((string) ($context->result['bundle'] ?? $context->result['content_type'] ?? $context->result['type'] ?? ''));
    $content_title = trim((string) ($context->result['content_title'] ?? $context->result['title'] ?? $context->result['name'] ?? ''));
    $content = $this->runtime->buildContentCreateForm($entity_type, $bundle, $content_title);

    return [
      '#description' => (string) ($context->result['description'] ?? ($context->structuredChatReply() ?: $context->assistantReply)),
      '#available' => (bool) ($content['available'] ?? FALSE),
      '#access_denied' => (bool) ($content['access_denied'] ?? FALSE),
      '#ambiguous' => (bool) ($content['ambiguous'] ?? FALSE),
      '#entity_type' => (string) ($content['entity_type'] ?? $entity_type),
      '#entity_type_label' => (string) ($content['entity_type_label'] ?? ''),
      '#bundle' => (string) ($content['bundle'] ?? $bundle),
      '#bundle_label' => (string) ($content['bundle_label'] ?? ''),
      '#content_title' => $content_title,
      '#add_url' => (string) ($content['add_url'] ?? ''),
      '#content_form' => is_array($content['content_form'] ?? NULL) ? $content['content_form'] : [],
      '#bundle_options' => is_array($content['bundle_options'] ?? NULL) ? $content['bundle_options'] : [],
      '#entity_type_options' => is_array($content['entity_type_options'] ?? NULL) ? $content['entity_type_options'] : [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildChatReply(SkillContext $context): string {
    return $context->structuredChatReply() ?: $this->getFallbackChatReply($context);
  }

}
