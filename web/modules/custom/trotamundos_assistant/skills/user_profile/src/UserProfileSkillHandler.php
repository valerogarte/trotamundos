<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\SkillLocal\UserProfile;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Handles user profile payloads.
 */
final class UserProfileSkillHandler extends AbstractSkillHandler {

  /**
   * {@inheritdoc}
   */
  public function buildVariables(SkillContext $context): array {
    return [
      '#description' => (string) ($context->result['description'] ?? ($context->structuredChatReply() ?: $context->assistantReply)),
      '#profile_form' => $this->runtime->buildUserProfileForm(),
      '#profile_edit_url' => $this->runtime->getProfileEditUrl(),
      '#is_authenticated' => $this->runtime->isAuthenticated(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildChatReply(SkillContext $context): string {
    return $context->structuredChatReply() ?: $this->getFallbackChatReply($context);
  }

}
