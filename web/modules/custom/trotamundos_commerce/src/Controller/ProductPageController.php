<?php

declare(strict_types=1);

namespace Drupal\trotamundos_commerce\Controller;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Cache\CacheableRedirectResponse;
use Drupal\trotamundos_experiences\Setup\ProductTypes;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Keeps cart product links on the existing editorial presentation and URL.
 */
final class ProductPageController extends ControllerBase {

  public function __construct(private readonly EntityRepositoryInterface $translations) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity.repository'));
  }

  public function view(ProductInterface $commerce_product): array|CacheableRedirectResponse {
    if (in_array($commerce_product->bundle(), ProductTypes::BUNDLES, TRUE)) {
      $storage = $this->entityTypeManager()->getStorage('node');
      $ids = $storage->getQuery()->accessCheck(TRUE)
        ->condition('type', $commerce_product->bundle())->condition('status', 1)
        ->condition('field_commerce_product', $commerce_product->id())->range(0, 2)->execute();
      if (count($ids) === 1) {
        $node = $this->translations->getTranslationFromContext($storage->load(reset($ids)));
        $url = $node->toUrl()->setOption('query', \Drupal::request()->query->all());
        $response = new CacheableRedirectResponse($url->toString(), 301);
        $response->addCacheableDependency($node)->addCacheableDependency($commerce_product)->addCacheableDependency($node->access('view', NULL, TRUE));
        $response->getCacheableMetadata()->addCacheContexts(['user.permissions', 'languages:language_interface', 'url.query_args']);
        return $response;
      }
    }
    return $this->entityTypeManager()->getViewBuilder('commerce_product')->view($commerce_product, 'full');
  }

  public function title(ProductInterface $commerce_product): string {
    return (string) $this->translations->getTranslationFromContext($commerce_product)->label();
  }

}
