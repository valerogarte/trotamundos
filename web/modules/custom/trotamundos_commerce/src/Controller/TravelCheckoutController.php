<?php

declare(strict_types=1);

namespace Drupal\trotamundos_commerce\Controller;

use Drupal\commerce_checkout\Controller\CheckoutController;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Response;

/**
 * Does not build payment panes while the store is explicitly unready.
 */
final class TravelCheckoutController extends CheckoutController {

  public function formPage(RouteMatchInterface $route_match): array|Response {
    $order = $route_match->getParameter('commerce_order');
    $ledger = \Drupal::keyValue('trotamundos_commerce.provisioning');
    if ($order->getState()->getId() === 'draft' && $order->getStore()->uuid() === $ledger->get('store_uuid')) {
      $gateways = \Drupal::entityTypeManager()->getStorage('commerce_payment_gateway')
        ->getQuery()->accessCheck(FALSE)->condition('status', TRUE)->count()->execute();
      if ($ledger->get('production_address_pending') || !$gateways) {
        $message = $this->t('Tienda en desarrollo: la dirección comercial y la pasarela online están pendientes. No se pueden finalizar compras ni confirmar reservas.');
        if (\Drupal::request()->isMethod('POST')) {
          return new Response((string) $message, Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        return [
          '#type' => 'container',
          '#attributes' => ['class' => ['tm-notice'], 'role' => 'alert'],
          '#cache' => ['max-age' => 0],
          'message' => ['#markup' => $message],
          'cart' => [
            '#type' => 'link', '#title' => $this->t('Ver carrito'),
            '#url' => Url::fromRoute('commerce_cart.page'),
            '#attributes' => ['class' => ['button', 'button--secondary']],
          ],
        ];
      }
    }
    return parent::formPage($route_match);
  }

}
