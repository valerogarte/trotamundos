<?php

declare(strict_types=1);

namespace Drupal\trotamundos_experiences\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\trotamundos_experiences\ExperienceRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The complete experience catalog with filters, sorting and pager.
 */
final class CatalogController extends ControllerBase {

  public function __construct(
    private readonly ExperienceRepository $experiences,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('trotamundos_experiences.repository'));
  }

  /**
   * Builds the catalog. Filters work as a GET form, with or without JS.
   */
  public function catalog(Request $request): array {
    $filters = $this->experiences->filtersFromRequest($request);
    $options = $this->experiences->filterOptions($filters);
    $result = $this->experiences->search($filters);
    $active = $this->experiences->activeFilters($filters, $options);
    $has_examples = (bool) array_filter($result['cards'], static fn (array $card): bool => $card['is_example']);

    return [
      '#theme' => 'trotamundos_catalog',
      '#cards' => $result['cards'],
      '#total' => $result['total'],
      '#filters' => $filters,
      '#options' => $options,
      '#active' => $active,
      '#has_examples' => $has_examples,
      '#reset_url' => Url::fromRoute('trotamundos_experiences.catalog')->toString(),
      '#pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['trotamundos/catalog']],
      '#cache' => [
        'contexts' => ['url.query_args', 'languages:language_interface', 'languages:language_content', 'user.permissions'],
        'tags' => ['node_list:experiencia', 'node_list:destino', 'taxonomy_term_list:estilo_viaje', 'taxonomy_term_list:tipo_plan'],
      ],
    ];
  }

}
