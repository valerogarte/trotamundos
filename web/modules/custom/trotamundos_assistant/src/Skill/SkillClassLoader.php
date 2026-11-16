<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Skill;

/**
 * Loads PHP classes declared inside a skill folder.
 */
final class SkillClassLoader {

  /**
   * Loads a PHP file declared by the skill.
   *
   * @param array<string, mixed>|null $skill
   *   Skill definition.
   * @param string $file
   *   File path relative to the skill folder.
   */
  public function requireFile(?array $skill, string $file): void {
    if ($file === '') {
      return;
    }

    $base_path = realpath((string) ($skill['path'] ?? ''));
    if ($base_path === FALSE) {
      return;
    }

    $path = realpath($base_path . '/' . ltrim($file, '/'));
    if ($path === FALSE || !str_starts_with($path, $base_path . DIRECTORY_SEPARATOR)) {
      return;
    }

    require_once $path;
  }

}
