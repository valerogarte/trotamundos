<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Skill;

/**
 * Immutable data passed to skill handlers.
 */
final class SkillContext {

  /**
   * Constructs a skill context.
   *
   * @param array<string, mixed>|null $skill
   *   Matched skill definition.
   * @param string $template
   *   Template machine name resolved for the skill.
   * @param string $assistantReply
   *   Raw assistant response used to build the result.
   * @param array<string, mixed> $structuredReply
   *   Full structured assistant reply.
   * @param array<string, mixed> $result
   *   The result payload from the structured reply.
   * @param array{intro: string, items: array<int, array<string, mixed>>, outro: string} $parsedFallback
   *   Parsed fallback data for non-structured replies.
   */
  public function __construct(
    public readonly ?array $skill,
    public readonly string $template,
    public readonly string $assistantReply,
    public readonly array $structuredReply,
    public readonly array $result,
    public readonly array $parsedFallback,
  ) {
  }

  /**
   * Gets the assistant's concise chat reply from the structured payload.
   */
  public function structuredChatReply(): string {
    return trim((string) ($this->structuredReply['chat_reply'] ?? ''));
  }

}
