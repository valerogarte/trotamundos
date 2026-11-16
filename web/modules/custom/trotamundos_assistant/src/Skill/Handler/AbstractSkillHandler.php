<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Skill\Handler;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\trotamundos_assistant\Skill\SkillContext;
use Drupal\trotamundos_assistant\Skill\SkillRuntime;

/**
 * Base behavior shared by skill handlers.
 */
abstract class AbstractSkillHandler implements SkillHandlerInterface {

  use StringTranslationTrait;

  public function __construct(
    protected readonly SkillRuntime $runtime,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function buildVariables(SkillContext $context): array {
    return [
      '#description' => (string) ($context->result['description'] ?? ($context->structuredChatReply() ?: $context->assistantReply)),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildChatReply(SkillContext $context): string {
    return $context->structuredChatReply() ?: $this->getFallbackChatReply($context);
  }

  /**
   * {@inheritdoc}
   */
  public function getLibraries(SkillContext $context): array {
    $metadata = is_array($context->skill['metadata'] ?? NULL) ? $context->skill['metadata'] : [];
    $libraries = is_array($metadata['libraries'] ?? NULL) ? $metadata['libraries'] : [];
    $libraries = array_values(array_filter(array_map('strval', $libraries)));

    if ($libraries !== []) {
      return $libraries;
    }

    $provider_module = (string) ($context->skill['provider_module'] ?? 'trotamundos_assistant');
    $machine_name = (string) ($context->skill['machine_name'] ?? $context->template);

    return [$provider_module . '/skill.' . $machine_name];
  }

  /**
   * Gets the skill-declared fallback chat reply.
   */
  protected function getFallbackChatReply(SkillContext $context): string {
    $metadata = is_array($context->skill['metadata'] ?? NULL) ? $context->skill['metadata'] : [];

    return (string) ($metadata['chat_reply_fallback'] ?? '');
  }

}
