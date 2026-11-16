<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Skill;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Discovers filesystem skills exposed by enabled Drupal modules.
 */
final class SkillDiscovery {

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
  }

  /**
   * Discovers every valid skill declared in enabled modules.
   *
   * @return array<string, array<string, mixed>>
   *   Skill definitions keyed by the namespaced skill id.
   */
  public function discover(): array {
    $definitions = [];

    foreach ($this->moduleHandler->getModuleList() as $module_name => $extension) {
      $root = $this->moduleExtensionList->getPath($module_name) . '/skills';
      if (!is_dir($root)) {
        continue;
      }

      foreach (scandir($root) ?: [] as $directory) {
        if ($directory === '.' || $directory === '..') {
          continue;
        }

        $definition = $this->discoverSkill($module_name, $root, $directory);
        if ($definition === NULL) {
          continue;
        }

        $definitions[$definition['id']] = $definition;
      }
    }

    ksort($definitions);

    return $definitions;
  }

  /**
   * Discovers one skill from a module skills directory.
   *
   * @return array<string, mixed>|null
   *   Skill definition, or NULL when the directory is not a valid skill.
   */
  private function discoverSkill(string $provider_module, string $root, string $directory): ?array {
    $machine_name = $this->sanitizeMachineName($directory);
    $path = $root . '/' . $directory;
    $template = $path . '/' . $machine_name . '.html.twig';
    $example = $path . '/example.json';
    $metadata_file = $path . '/skill.yml';
    if ($machine_name === '' || !is_dir($path) || !is_file($template) || !is_file($example) || !is_file($metadata_file)) {
      return NULL;
    }

    $metadata = $this->loadMetadata($metadata_file);
    $declared_machine_name = $this->sanitizeMachineName((string) ($metadata['machine_name'] ?? ''));
    if ($declared_machine_name === '' || $declared_machine_name !== $machine_name) {
      return NULL;
    }

    $metadata['libraries'] = $this->resolveDeclaredLibraries($provider_module, $machine_name, $metadata);
    $required_modules = $this->normalizeRequiredModules($metadata['dependencies']['modules'] ?? []);
    $missing_modules = array_values(array_filter(
      $required_modules,
      fn(string $module): bool => !$this->moduleHandler->moduleExists($module),
    ));
    $id = $this->buildSkillId($provider_module, $machine_name);
    $provider_metadata = is_array($metadata['provider'] ?? NULL) ? $metadata['provider'] : [];
    $requires_rag = !array_key_exists('requires_rag', $provider_metadata)
      || (bool) $provider_metadata['requires_rag'];

    return [
      'id' => $id,
      'machine_name' => $machine_name,
      'template_hook' => $this->buildTemplateHook($provider_module, $machine_name),
      'provider_module' => $provider_module,
      'provider_module_label' => $this->getModuleLabel($provider_module),
      'title' => (string) ($metadata['title'] ?? $machine_name),
      'btn_responsive_title' => (string) ($metadata['btn_responsive_title'] ?? ''),
      'description' => (string) ($metadata['description'] ?? ''),
      'group' => $this->normalizeGroup($metadata['group'] ?? NULL, $provider_module),
      'dependencies' => [
        'modules' => $required_modules,
      ],
      'missing_dependencies' => [
        'modules' => $missing_modules,
      ],
      'dependencies_met' => $missing_modules === [],
      'configured_enabled' => FALSE,
      'provider_intent' => (string) ($metadata['provider']['intent'] ?? ''),
      'when_to_use' => is_array($metadata['provider']['when_to_use'] ?? NULL) ? $metadata['provider']['when_to_use'] : [],
      // Unknown third-party skills keep the conservative behaviour: they
      // require retrieval until their metadata explicitly opts out.
      'requires_rag' => $requires_rag,
      'metadata' => $metadata,
      'path' => $path,
      'metadata_file' => $metadata_file,
      'template' => $template,
      'example' => $example,
      'enabled' => FALSE,
    ];
  }

  public function buildSkillId(string $provider_module, string $machine_name): string {
    return $this->sanitizeModuleName($provider_module) . ':' . $this->sanitizeMachineName($machine_name);
  }

  public function buildTemplateHook(string $provider_module, string $machine_name): string {
    $machine_name = $this->sanitizeMachineName($machine_name);
    if ($provider_module === 'trotamundos_assistant') {
      return $machine_name;
    }

    return $this->sanitizeModuleName($provider_module) . '__' . $machine_name;
  }

  /**
   * Resolves the Drupal libraries declared or generated for a skill.
   *
   * @param array<string, mixed> $metadata
   *   Skill metadata.
   *
   * @return string[]
   *   Library names.
   */
  private function resolveDeclaredLibraries(string $provider_module, string $machine_name, array $metadata): array {
    $libraries = is_array($metadata['libraries'] ?? NULL) ? $metadata['libraries'] : [];
    $libraries = array_values(array_filter(array_map('strval', $libraries)));
    if ($libraries !== []) {
      return $libraries;
    }

    return is_array($metadata['assets'] ?? NULL) && $metadata['assets'] !== []
      ? [$provider_module . '/skill.' . $machine_name]
      : [];
  }

  /**
   * @return array<int, string>
   *   Required module machine names.
   */
  private function normalizeRequiredModules(mixed $modules): array {
    if (!is_array($modules)) {
      return [];
    }

    $normalized = [];
    foreach ($modules as $module) {
      $module = trim((string) $module);
      if ($module !== '') {
        $normalized[] = $module;
      }
    }

    return array_values(array_unique($normalized));
  }

  /**
   * @return array{id: string, label: string}
   *   Group display data.
   */
  private function normalizeGroup(mixed $group, string $provider_module): array {
    $id = 'module_' . $this->sanitizeModuleName($provider_module);
    $label = $this->getModuleLabel($provider_module);

    if (is_string($group) && trim($group) !== '') {
      $id = $this->sanitizeMachineName($group);
      $label = $this->buildGroupLabel($id);
    }
    elseif (is_array($group)) {
      $configured_id = $this->sanitizeMachineName((string) ($group['id'] ?? ''));
      if ($configured_id !== '') {
        $id = $configured_id;
      }
      $configured_label = trim((string) ($group['label'] ?? ''));
      $label = $configured_label !== '' ? $configured_label : $this->buildGroupLabel($id);
    }

    return [
      'id' => $id,
      'label' => $label,
    ];
  }

  private function buildGroupLabel(string $group_id): string {
    $words = array_filter(explode('_', $group_id));
    $words = array_map(static fn(string $word): string => ucfirst($word), $words);

    return $words === [] ? 'General' : implode(' ', $words);
  }

  private function getModuleLabel(string $module_name): string {
    try {
      $info = $this->moduleExtensionList->getExtensionInfo($module_name);
    }
    catch (\Throwable) {
      return $this->buildGroupLabel($this->sanitizeModuleName($module_name));
    }

    $label = trim((string) ($info['name'] ?? ''));

    return $label !== '' ? $label : $this->buildGroupLabel($this->sanitizeModuleName($module_name));
  }

  /**
   * @return array<string, mixed>
   *   Metadata declared by the skill.
   */
  private function loadMetadata(string $metadata_file): array {
    try {
      $metadata = Yaml::decode((string) file_get_contents($metadata_file));
    }
    catch (\Throwable) {
      return [];
    }

    return is_array($metadata) ? $metadata : [];
  }

  private function sanitizeMachineName(string $machine_name): string {
    $machine_name = mb_strtolower(trim($machine_name));
    $machine_name = preg_replace('/[^a-z0-9_]+/', '_', $machine_name) ?? '';

    return trim($machine_name, '_');
  }

  private function sanitizeModuleName(string $module_name): string {
    $module_name = mb_strtolower(trim($module_name));
    $module_name = preg_replace('/[^a-z0-9_]+/', '_', $module_name) ?? '';

    return trim($module_name, '_');
  }

}
