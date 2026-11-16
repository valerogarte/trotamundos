<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Skill;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\Handler\SkillHandlerInterface;

/**
 * Resolves the handler responsible for a skill.
 */
final class SkillHandlerResolver {

  /**
   * Handler instances keyed by class name.
   *
   * @var array<string, \Drupal\trotamundos_assistant\Skill\Handler\SkillHandlerInterface>
   */
  private array $instances = [];

  public function __construct(
    private readonly SkillRuntime $runtime,
    private readonly SkillClassLoader $classLoader,
  ) {
  }

  /**
   * Resolves the handler for a skill.
   *
   * @param array<string, mixed>|null $skill
   *   Skill definition.
   * @param string $template
   *   Template machine name used by the selected skill.
   */
  public function resolve(?array $skill, string $template): SkillHandlerInterface {
    $metadata = is_array($skill['metadata'] ?? NULL) ? $skill['metadata'] : [];
    $definition = $metadata['handler'] ?? [];
    $handler_class = is_array($definition)
      ? (string) ($definition['class'] ?? '')
      : (string) $definition;

    if (is_array($definition)) {
      $this->classLoader->requireFile($skill, (string) ($definition['file'] ?? ''));
    }

    if ($handler_class === '' || !is_subclass_of($handler_class, SkillHandlerInterface::class)) {
      return new class($this->runtime) extends AbstractSkillHandler {
      };
    }

    return $this->instances[$handler_class] ??= new $handler_class($this->runtime);
  }

}
