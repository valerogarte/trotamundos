<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\trotamundos_assistant\Skill\SkillDiscovery;

/**
 * Theme hook implementations for Trotamundos Assistant.
 */
final class ThemeHooks {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly SkillDiscovery $skillDiscovery,
  ) {
  }

  /**
   * Implements hook_preprocess_HOOK() for page templates.
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    $variables['trotamundos_assistant_full_bleed'] = $this->routeMatch->getRouteName() === 'trotamundos_assistant.home';
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(array $existing, string $type, string $theme, string $path): array {
    $hooks = [
      'trotamundos_assistant' => [
        'render element' => 'elements',
        'template' => 'trotamundos-assistant',
      ],
      'trotamundos_assistant_welcome' => [
        'variables' => [
          'title' => NULL,
          'intro' => NULL,
          'features' => [],
          'suggestions' => [],
        ],
        'template' => 'trotamundos-assistant-welcome',
      ],
    ];

    foreach ($this->skillDiscovery->discover() as $skill) {
      $template_file = (string) ($skill['template'] ?? '');
      $template_path = (string) ($skill['path'] ?? '');
      if (!is_dir($template_path) || !is_file($template_file)) {
        continue;
      }

      $hook_suffix = preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower((string) ($skill['template_hook'] ?? $skill['machine_name'] ?? ''))) ?: 'default';
      $hooks['trotamundos_assistant_template_' . trim($hook_suffix, '_')] = [
        'variables' => $this->getSkillThemeVariables($skill),
        'template' => (string) ($skill['machine_name'] ?? 'default'),
        'path' => $template_path,
      ];
    }

    return $hooks;
  }

  /**
   * Gets the Twig variables declared by a skill.
   *
   * @param array<string, mixed> $skill
   *   Discovered skill definition.
   *
   * @return array<string, mixed>
   *   Theme variables keyed by variable name.
   */
  private function getSkillThemeVariables(array $skill): array {
    $metadata = is_array($skill['metadata'] ?? NULL) ? $skill['metadata'] : [];
    $theme = is_array($metadata['theme'] ?? NULL) ? $metadata['theme'] : [];
    $variables = is_array($theme['variables'] ?? NULL) ? $theme['variables'] : [];

    return array_replace($this->getBaseSkillThemeVariables(), $this->normalizeThemeVariables($variables));
  }

  /**
   * Gets variables available to every skill template.
   *
   * @return array<string, mixed>
   *   Base theme variables.
   */
  private function getBaseSkillThemeVariables(): array {
    return [
      'title' => NULL,
      'description' => NULL,
      'content' => NULL,
    ];
  }

  /**
   * Normalizes variables declared in skill.yml.
   *
   * @param array<int|string, mixed> $variables
   *   Variables declared either as a mapping or a list of names.
   *
   * @return array<string, mixed>
   *   Safe variables keyed by Twig variable name.
   */
  private function normalizeThemeVariables(array $variables): array {
    $normalized = [];
    foreach ($variables as $name => $default) {
      if (is_int($name)) {
        $name = (string) $default;
        $default = NULL;
      }

      $name = (string) $name;
      if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
        continue;
      }

      $normalized[$name] = $default;
    }

    return $normalized;
  }

}
