<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Skill;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\trotamundos_assistant\Config\TrotamundosAssistantDefaults;

/**
 * Loads and matches Trotamundos Assistant skills from the module filesystem.
 */
final class SkillMatcher {

  private const CACHE_TAG = 'trotamundos_assistant:skills';

  /**
   * Constructs the skill matcher.
   */
  public function __construct(
    private readonly SkillDiscovery $skillDiscovery,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
    private readonly TrotamundosAssistantDefaults $defaults,
  ) {
  }

  /**
   * Loads enabled skills from the module skill directory.
   *
   * @return array<string, array<string, mixed>>
   *   Skills keyed by machine name.
   */
  public function loadAll(): array {
    return array_filter(
      $this->loadDefinitions(),
      static fn(array $skill): bool => (bool) $skill['enabled'],
    );
  }

  /**
   * Loads every filesystem skill, enabled or disabled.
   *
   * @return array<string, array<string, mixed>>
   *   Skills keyed by machine name.
   */
  public function loadDefinitions(): array {
    $settings = $this->configFactory->get('trotamundos_assistant.settings');
    $configured = $settings->get('enabled_skills');
    $enabled_skills = $this->normalizeEnabledSkills(is_array($configured) ? $configured : $this->defaults->getEnabledSkills());
    $enabled_lookup = array_fill_keys($enabled_skills, TRUE);
    $extension_config = $this->configFactory->get('core.extension');
    $cache_id = $this->buildDefinitionsCacheId($enabled_skills, $extension_config->get('module') ?: []);
    if ($cache = $this->cache->get($cache_id)) {
      return is_array($cache->data) ? $cache->data : [];
    }

    $definitions = [];
    foreach ($this->skillDiscovery->discover() as $skill_id => $definition) {
      $configured_enabled = $this->isSkillConfigured($definition, $enabled_lookup);
      $definition['configured_enabled'] = $configured_enabled;
      $definition['enabled'] = $configured_enabled && !empty($definition['dependencies_met']);
      $definitions[$skill_id] = $definition;
    }

    ksort($definitions);
    $this->cache->set($cache_id, $definitions, CacheBackendInterface::CACHE_PERMANENT, Cache::mergeTags(
      $settings->getCacheTags(),
      $extension_config->getCacheTags(),
      [self::CACHE_TAG],
    ));

    return $definitions;
  }

  /**
   * Resolves the skill selected by the provider in its structured response.
   *
   * @return array<string, mixed>|null
   *   The selected enabled skill definition.
   */
  public function matchProviderSelection(string $assistant_reply): ?array {
    $data = $this->decodeAssistantReply($assistant_reply);
    if ($data === []) {
      return NULL;
    }

    $selected = $this->sanitizeSkillReference((string) ($data['skill'] ?? $data['machine_name'] ?? ''));
    $result = is_array($data['result'] ?? NULL) ? $data['result'] : [];
    if ($selected === '' && isset($result['skill'])) {
      $selected = $this->sanitizeSkillReference((string) $result['skill']);
    }

    if ($selected === '') {
      return NULL;
    }

    $skills = $this->loadAll();
    return $this->resolveSkill($selected, $skills);
  }

  /**
   * Gets a skill by namespaced id or legacy machine name.
   *
   * @return array<string, mixed>|null
   *   Matching skill definition.
   */
  public function getByReference(string $reference, bool $enabled_only = FALSE): ?array {
    $reference = $this->sanitizeSkillReference($reference);
    if ($reference === '') {
      return NULL;
    }

    return $this->resolveSkill($reference, $enabled_only ? $this->loadAll() : $this->loadDefinitions());
  }

  /**
   * Finds the most relevant enabled skill for a user message.
   *
   * This remains a local fallback. The runtime lets the provider choose.
   *
   * @return array<string, mixed>|null
   *   The best skill definition.
   */
  public function match(string $message): ?array {
    $message = $this->normalize($message);
    if ($message === '') {
      return NULL;
    }

    $best_skill = NULL;
    $best_score = 0;
    foreach ($this->loadAll() as $skill) {
      $score = $this->scoreSkill($skill, $message);
      if ($score > $best_score) {
        $best_score = $score;
        $best_skill = $skill;
      }
    }

    return $best_score > 0 ? $best_skill : NULL;
  }

  /**
   * Builds a cache ID that varies by enabled skills and active module set.
   *
   * @param string[] $enabled_skills
   *   Enabled skill machine names.
   * @param array<string, mixed> $modules
   *   Active modules from core.extension.
   */
  private function buildDefinitionsCacheId(array $enabled_skills, array $modules): string {
    sort($enabled_skills);
    ksort($modules);

    return 'trotamundos_assistant:skill_definitions:' . hash('sha256', serialize([
      'enabled_skills' => $enabled_skills,
      'modules' => array_keys($modules),
    ]));
  }

  /**
   * Checks whether a discovered skill is enabled in configuration.
   */
  private function isSkillConfigured(array $skill, array $enabled_lookup): bool {
    $id = (string) ($skill['id'] ?? '');
    $machine_name = (string) ($skill['machine_name'] ?? '');
    $provider_module = (string) ($skill['provider_module'] ?? 'trotamundos_assistant');
    $legacy_base_skill = $provider_module === 'trotamundos_assistant' && isset($enabled_lookup[$machine_name]);

    return isset($enabled_lookup[$id])
      || $legacy_base_skill
      || isset($enabled_lookup[$this->skillDiscovery->buildSkillId($provider_module, $machine_name)]);
  }

  /**
   * @param array<int, mixed> $enabled_skills
   *   Configured enabled skill references.
   *
   * @return string[]
   *   Normalized enabled skill references.
   */
  private function normalizeEnabledSkills(array $enabled_skills): array {
    $normalized = [];
    foreach ($enabled_skills as $skill) {
      $skill = $this->sanitizeSkillReference((string) $skill);
      if ($skill !== '') {
        $normalized[] = $skill;
      }
    }

    return array_values(array_unique($normalized));
  }

  /**
   * Resolves a skill by id or legacy machine name.
   *
   * @param array<string, array<string, mixed>> $skills
   *   Available skill definitions.
   *
   * @return array<string, mixed>|null
   *   Matching skill definition.
   */
  private function resolveSkill(string $selected, array $skills): ?array {
    if (isset($skills[$selected])) {
      return $skills[$selected];
    }

    foreach ($skills as $skill) {
      if ((string) ($skill['machine_name'] ?? '') === $selected && (string) ($skill['provider_module'] ?? '') === 'trotamundos_assistant') {
        return $skill;
      }
    }

    foreach ($skills as $skill) {
      if ((string) ($skill['machine_name'] ?? '') === $selected) {
        return $skill;
      }
    }

    return NULL;
  }

  /**
   * Decodes a JSON assistant response.
   *
   * @return array<string, mixed>
   *   Decoded data.
   */
  private function decodeAssistantReply(string $assistant_reply): array {
    $text = trim($assistant_reply);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text) ?? $text;
    $decoded = json_decode($text, TRUE);

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Scores a skill against a normalized user message.
   *
   * @param array<string, mixed> $skill
   *   Skill definition.
   * @param string $message
   *   Normalized user message.
   */
  private function scoreSkill(array $skill, string $message): int {
    $score = 0;
    $terms = [
      (string) ($skill['title'] ?? ''),
      (string) ($skill['machine_name'] ?? ''),
    ];

    foreach (array_unique(array_map($this->normalize(...), $terms)) as $term) {
      if ($term === '') {
        continue;
      }
      if (str_contains($message, $term)) {
        $score = max($score, 100 + strlen($term));
        continue;
      }

      $words = array_values(array_filter(explode(' ', $term)));
      if (count($words) < 2) {
        continue;
      }

      $matched_words = 0;
      foreach ($words as $word) {
        $stem = strlen($word) > 4 ? substr($word, 0, 4) : $word;
        if (strlen($word) > 2 && (str_contains($message, $word) || str_contains($message, $stem))) {
          $matched_words++;
        }
      }

      if ($matched_words === count($words)) {
        $score = max($score, 50 + $matched_words);
      }
    }

    return $score;
  }

  /**
   * Sanitizes a skill machine name.
   */
  private function sanitizeSkillReference(string $reference): string {
    $reference = mb_strtolower(trim($reference));
    $reference = preg_replace('/[^a-z0-9_:]+/', '_', $reference) ?? '';
    $reference = preg_replace('/:+/', ':', $reference) ?? '';

    return trim($reference, '_:');
  }

  /**
   * Normalizes text for simple intent matching.
   */
  private function normalize(string $text): string {
    $text = mb_strtolower(trim($text));
    $text = strtr($text, [
      'á' => 'a',
      'é' => 'e',
      'í' => 'i',
      'ó' => 'o',
      'ú' => 'u',
      'ü' => 'u',
      'ñ' => 'n',
    ]);
    $text = preg_replace('/[^a-z0-9 ]+/', ' ', $text) ?? '';
    $text = preg_replace('/\s+/', ' ', $text) ?? '';

    return trim($text);
  }

}
