<?php

declare(strict_types=1);

namespace Drupal\trotamundos_commerce\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\trotamundos_commerce\Controller\TravelProfileController;
use Drupal\trotamundos_commerce\Controller\ProductPageController;
use Drupal\trotamundos_commerce\Controller\TravelCheckoutController;
use Symfony\Component\Routing\RouteCollection;

/**
 * Keeps native account URLs, access checks, editing and local tasks.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('entity.user.canonical')) {
      $defaults = $route->getDefaults();
      unset($defaults['_entity_view']);
      $defaults['_controller'] = TravelProfileController::class . '::view';
      $defaults['_title_callback'] = TravelProfileController::class . '::title';
      $route->setDefaults($defaults);
    }
    if ($route = $collection->get('entity.commerce_product.canonical')) {
      $defaults = $route->getDefaults();
      unset($defaults['_entity_view']);
      $defaults['_controller'] = ProductPageController::class . '::view';
      $defaults['_title_callback'] = ProductPageController::class . '::title';
      $route->setDefaults($defaults);
    }
    if ($route = $collection->get('commerce_checkout.form')) {
      $route->setDefault('_controller', TravelCheckoutController::class . '::formPage');
    }
  }

}
