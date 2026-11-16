<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_ecommerce\SkillLocal\ShoppingCartAdd;

use Drupal\commerce\EntityHelper;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\OrderRefreshInterface;
use Drupal\commerce_order\Resolver\ChainOrderTypeResolverInterface;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\trotamundos_assistant\Skill\Action\SkillActionHandlerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles AJAX actions owned by the shopping cart add skill.
 */
final class ShoppingCartAddActionHandler implements SkillActionHandlerInterface {

  use StringTranslationTrait;

  private const EXCLUDED_PRODUCT_BUNDLE = 'deposit';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly CartManagerInterface $cartManager,
    private readonly CartProviderInterface $cartProvider,
    private readonly ChainOrderTypeResolverInterface $orderTypeResolver,
    private readonly CurrentStoreInterface $currentStore,
    private readonly BlockManagerInterface $blockManager,
    private readonly RendererInterface $renderer,
    private readonly OrderRefreshInterface $orderRefresh,
  ) {
  }

  /**
   * Adds a Commerce product variation to the active cart.
   */
  public function addToCart(Request $request, AccountInterface $account): JsonResponse {
    if (!$this->moduleHandler->moduleExists('commerce_cart') || !$this->entityTypeManager->hasDefinition('commerce_product_variation')) {
      return new JsonResponse([
        'error' => $this->t('El carrito no está disponible.'),
      ], 400);
    }

    $data = Json::decode($request->getContent()) ?? [];
    $variation_id = (int) ($data['variation_id'] ?? 0);
    $quantity = max(1, (float) ($data['quantity'] ?? 1));

    try {
      $variation = $variation_id > 0
        ? $this->entityTypeManager->getStorage('commerce_product_variation')->load($variation_id)
        : NULL;

      if (!$variation instanceof PurchasableEntityInterface) {
        return new JsonResponse([
          'error' => $this->t('No se ha encontrado la variación del producto.'),
        ], 404);
      }

      if (method_exists($variation, 'isPublished') && !$variation->isPublished()) {
        return new JsonResponse([
          'error' => $this->t('Esta variación no está disponible.'),
        ], 400);
      }

      if ($this->isExcludedPurchasableEntity($variation)) {
        return new JsonResponse([
          'error' => $this->t('Este producto no se puede añadir desde el asistente.'),
        ], 400);
      }

      $store = $this->selectStoreForPurchasableEntity($variation);
      $order_item = $this->cartManager->createOrderItem($variation, (string) $quantity);
      $order_type_id = $this->orderTypeResolver->resolve($order_item);
      $cart = $this->cartProvider->getCart($order_type_id, $store, $account);
      if (!$cart) {
        $cart = $this->cartProvider->createCart($order_type_id, $store, $account);
      }

      $saved_order_item = $this->cartManager->addEntity($cart, $variation, (string) $quantity);

      // Recalculate order totals/adjustments so the header block reflects the
      // real amount instead of a stale 0,00 €.
      $this->orderRefresh->refresh($cart);
      $cart->save();

      return new JsonResponse([
        'status' => 'ok',
        // Render Drupal's own status messages (the "added to your cart" notice
        // Commerce just queued). This drains the messenger queue so it is not
        // shown again on the next page load, and reuses the theme's message
        // markup so the AJAX toast is identical to the native one.
        'message_html' => $this->renderStatusMessages(),
        'order_item_id' => (int) $saved_order_item->id(),
        'cart_id' => (int) $cart->id(),
        'cart_count' => $this->getCartItemCount($account),
        'cart_block' => $this->renderCartBlock(),
      ]);
    }
    catch (\Throwable) {
      return new JsonResponse([
        'error' => $this->t('No se ha podido añadir el producto al carrito.'),
      ], 500);
    }
  }

  /**
   * Checks whether a purchasable entity belongs to an excluded product bundle.
   */
  private function isExcludedPurchasableEntity(PurchasableEntityInterface $entity): bool {
    if (method_exists($entity, 'getProduct')) {
      $product = $entity->getProduct();
      return is_object($product)
        && method_exists($product, 'bundle')
        && $product->bundle() === self::EXCLUDED_PRODUCT_BUNDLE;
    }

    return method_exists($entity, 'bundle')
      && $entity->bundle() === self::EXCLUDED_PRODUCT_BUNDLE;
  }

  /**
   * Selects the store used to add a purchasable entity to cart.
   */
  private function selectStoreForPurchasableEntity(PurchasableEntityInterface $entity): object {
    $stores = $entity->getStores();
    if (count($stores) === 1) {
      $store = reset($stores);
    }
    elseif (count($stores) === 0) {
      throw new \RuntimeException('The purchasable entity is not assigned to any store.');
    }
    else {
      $store = $this->currentStore->getStore();
      $store_ids = EntityHelper::extractIds($stores);
      if (!$store || !in_array($store->id(), $store_ids, TRUE)) {
        throw new \RuntimeException('The purchasable entity cannot be purchased from the current store.');
      }
    }

    if (method_exists($store, 'isPublished') && !$store->isPublished()) {
      throw new \RuntimeException('The selected store is not published.');
    }

    return $store;
  }

  /**
   * Renders queued Drupal status messages and clears them from the queue.
   */
  private function renderStatusMessages(): string {
    try {
      $build = ['#type' => 'status_messages'];
      return (string) $this->renderer->renderRoot($build);
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Renders the commerce cart block so the header dropdown reflects the change.
   */
  private function renderCartBlock(): string {
    try {
      $plugin = $this->blockManager->createInstance('commerce_cart', ['dropdown' => TRUE]);
      $build = $plugin->build();
      if (!$build) {
        return '';
      }

      return (string) $this->renderer->renderRoot($build);
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Gets the current cart item count.
   */
  private function getCartItemCount(AccountInterface $account): int {
    if (!$this->moduleHandler->moduleExists('commerce_cart')) {
      return 0;
    }

    $count = 0;
    try {
      foreach ($this->cartProvider->getCarts($account) as $cart) {
        foreach ($cart->getItems() as $order_item) {
          $count += (float) $order_item->getQuantity();
        }
      }
    }
    catch (\Throwable) {
      return 0;
    }

    return (int) round($count);
  }

}
