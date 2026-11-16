<?php

declare(strict_types=1);

namespace Drupal\trotamundos_experiences;

use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasRepositoryInterface;

/**
 * Gives experiences and destinations readable URLs when editors leave the
 * alias empty: /experiencias/termales-espiritu-santo, /en/experiences/...
 */
final class AliasGenerator {

  /**
   * URL prefixes per bundle and language.
   */
  private const PREFIXES = [
    'experiencia' => ['es' => '/experiencias/', 'en' => '/experiences/'],
    'destino' => ['es' => '/destinos/', 'en' => '/destinations/'],
  ];

  public function __construct(
    private readonly AliasRepositoryInterface $aliasRepository,
    private readonly ExperienceRepository $experiences,
  ) {}

  /**
   * Fills empty aliases on every translation of the node.
   */
  public function ensureAlias(NodeInterface $node): void {
    $prefixes = self::PREFIXES[$node->bundle()] ?? NULL;
    if (!$prefixes || !$node->hasField('path')) {
      return;
    }
    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      $translation = $node->getTranslation($langcode);
      $current = trim((string) ($translation->get('path')->alias ?? ''));
      if ($current !== '') {
        continue;
      }
      $slug = $this->experiences->slug(ExperienceRepository::cleanTitle((string) $translation->label()));
      if ($slug === '') {
        continue;
      }
      $prefix = $prefixes[$langcode] ?? $prefixes['es'];
      $translation->get('path')->alias = $this->unique($prefix . $slug, $langcode, $node);
      $translation->get('path')->langcode = $langcode;
    }
  }

  /**
   * Appends a counter when another path already uses the alias.
   */
  private function unique(string $alias, string $langcode, NodeInterface $node): string {
    $own_path = $node->isNew() ? NULL : '/node/' . $node->id();
    $candidate = $alias;
    $suffix = 2;
    while (($existing = $this->aliasRepository->lookupByAlias($candidate, $langcode)) && $existing['path'] !== $own_path) {
      $candidate = $alias . '-' . $suffix++;
    }
    return $candidate;
  }

}
