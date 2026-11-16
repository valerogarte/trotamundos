<?php

declare(strict_types=1);

namespace Drupal\trotamundos_destinations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\trotamundos_experiences\ExperienceRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Destinations by region, department and municipality.
 */
final class DestinationsController extends ControllerBase {

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
   * Builds the destinations page from published destination nodes.
   */
  public function listing(): array {
    $tree = $this->experiences->destinationTree();
    return [
      '#theme' => 'trotamundos_destinations',
      '#tree' => $tree,
      '#total' => array_sum(array_column($tree, 'count')),
      '#catalog_url' => Url::fromRoute('trotamundos_experiences.catalog')->toString(),
      '#attached' => ['library' => ['trotamundos/pages']],
      '#cache' => [
        'contexts' => ['languages:language_interface', 'languages:language_content', 'user.permissions'],
        'tags' => ['node_list:destino', 'node_list:experiencia'],
      ],
    ];
  }

}
