<?php

declare(strict_types=1);

namespace Drupal\trotamundos_experiences\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\trotamundos_experiences\ExperienceRepository;
use Drupal\trotamundos_experiences\Setup\ProductTypes;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
  public function catalog(Request $request, string $product_type = ''): array|RedirectResponse {
    $filters = $this->experiences->filtersFromRequest($request);
    if ($product_type === 'experiencia' && in_array($filters['tipo'], ['alojamiento', 'paquete'], TRUE)) {
      $query = $request->query->all();
      unset($query['tipo'], $query['page']);
      return $this->redirect(ProductTypes::ROUTES[$filters['tipo']], [], ['query' => $query], 301);
    }
    $route = ProductTypes::ROUTES[$product_type] ?? 'trotamundos_experiences.catalog';
    if ($product_type !== '') {
      $filters['tipo'] = $product_type;
    }
    $request->attributes->set('_trotamundos_catalog_filters', $filters);
    $options = $this->experiences->filterOptions($filters, $product_type);
    $result = $this->experiences->search($filters);
    $removable = $filters;
    if ($product_type !== '') {
      unset($removable['tipo']);
    }
    $active = $this->experiences->activeFilters($removable, $options, $route);
    $has_examples = (bool) array_filter($result['cards'], static fn (array $card): bool => $card['is_example']);

    return [
      '#theme' => 'trotamundos_catalog',
      '#product_type' => $product_type,
      '#catalog_title' => ExperienceRepository::productLabels()[$product_type] ?? $this->t('Planes'),
      '#cards' => $result['cards'],
      '#total' => $result['total'],
      '#skeleton_count' => ExperienceRepository::PAGE_SIZE,
      '#filters' => $filters,
      '#options' => $options,
      '#active' => $active,
      '#has_examples' => $has_examples,
      '#reset_url' => Url::fromRoute($route)->toString(),
      '#pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['trotamundos/catalog']],
      '#cache' => [
        'contexts' => ['url.path', 'url.query_args', 'languages:language_interface', 'languages:language_content', 'user.permissions', 'user.node_grants:view'],
        'tags' => ProductTypes::CACHE_TAGS,
        'max-age' => strtotime('tomorrow', \Drupal::time()->getRequestTime()) - \Drupal::time()->getRequestTime(),
      ],
    ];
  }

}
