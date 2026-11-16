<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Skill\Handler;

use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Builds template variables and chat copy for one skill.
 */
interface SkillHandlerInterface {

  /**
   * Builds render variables expected by the skill Twig template.
   *
   * @return array<string, mixed>
   *   Render-array variables keyed with their Drupal render keys.
   */
  public function buildVariables(SkillContext $context): array;

  /**
   * Builds the fallback concise chat reply for this skill.
   */
  public function buildChatReply(SkillContext $context): string;

  /**
   * Returns Drupal libraries required by this skill.
   *
   * @return array<int, string>
   *   Library names.
   */
  public function getLibraries(SkillContext $context): array;

}
