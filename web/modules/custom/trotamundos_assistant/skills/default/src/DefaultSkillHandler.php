<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\SkillLocal\DefaultSkill;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Handles generic default payloads.
 */
final class DefaultSkillHandler extends AbstractSkillHandler {

  /**
   * {@inheritdoc}
   */
  public function buildVariables(SkillContext $context): array {
    return [
      '#title' => (string) ($context->result['title'] ?? ($context->skill['title'] ?? '')),
      '#description' => (string) ($context->result['description'] ?? ($context->structuredChatReply() ?: $context->assistantReply)),
    ];
  }

}
