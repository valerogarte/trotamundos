<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_ecommerce\SkillLocal\ShoppingCartView;

use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_order\OrderTotalSummaryInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\trotamundos_assistant\Skill\Action\SkillActionHandlerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles AJAX actions owned by the shopping cart view skill.
 */
final class ShoppingCartViewActionHandler implements SkillActionHandlerInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly CartManagerInterface $cartManager,
    private readonly CartProviderInterface $cartProvider,
    private readonly RendererInterface $renderer,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly OrderTotalSummaryInterface $orderTotalSummary,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {
  }

  /**
   * Returns the active cart rendered for the assistant result panel.
   */
  public function panel(Request $request, AccountInterface $account): JsonResponse {
    if (!$this->moduleHandler->moduleExists('commerce_cart')) {
      return new JsonResponse([
        'error' => $this->t('El carrito no está disponible.'),
      ], 400);
    }

    return $this->buildPanelResponse($account);
  }

  /**
   * Updates the quantity for a cart item owned by the current account.
   */
  public function updateItem(Request $request, AccountInterface $account): JsonResponse {
    if (!$this->moduleHandler->moduleExists('commerce_cart')) {
      return new JsonResponse([
        'error' => $this->t('El carrito no está disponible.'),
      ], 400);
    }

    $data = Json::decode($request->getContent()) ?? [];
    $order_item_id = (int) ($data['order_item_id'] ?? 0);
    $quantity = (float) ($data['quantity'] ?? 0);
    if ($order_item_id <= 0 || $quantity <= 0) {
      return new JsonResponse([
        'error' => $this->t('La cantidad debe ser mayor que cero.'),
      ], 400);
    }

    try {
      $cart_item = $this->findCartOrderItem($account, $order_item_id);
      if (!$cart_item) {
        return new JsonResponse([
          'error' => $this->t('No se ha encontrado el producto en tu carrito.'),
        ], 404);
      }

      $cart_item['order_item']->setQuantity($this->formatQuantityValue($quantity));
      $this->cartManager->updateOrderItem($cart_item['cart'], $cart_item['order_item']);

      return $this->buildPanelResponse($account, (string) $this->t('Cantidad actualizada.'));
    }
    catch (\Throwable) {
      return new JsonResponse([
        'error' => $this->t('No se ha podido actualizar la cantidad.'),
      ], 500);
    }
  }

  /**
   * Removes a cart item owned by the current account.
   */
  public function removeItem(Request $request, AccountInterface $account): JsonResponse {
    if (!$this->moduleHandler->moduleExists('commerce_cart')) {
      return new JsonResponse([
        'error' => $this->t('El carrito no está disponible.'),
      ], 400);
    }

    $data = Json::decode($request->getContent()) ?? [];
    $order_item_id = (int) ($data['order_item_id'] ?? 0);
    if ($order_item_id <= 0) {
      return new JsonResponse([
        'error' => $this->t('No se ha encontrado el producto en tu carrito.'),
      ], 400);
    }

    try {
      $cart_item = $this->findCartOrderItem($account, $order_item_id);
      if (!$cart_item) {
        return new JsonResponse([
          'error' => $this->t('No se ha encontrado el producto en tu carrito.'),
        ], 404);
      }

      $this->cartManager->removeOrderItem($cart_item['cart'], $cart_item['order_item']);

      return $this->buildPanelResponse($account, (string) $this->t('Producto eliminado del carrito.'));
    }
    catch (\Throwable) {
      return new JsonResponse([
        'error' => $this->t('No se ha podido eliminar el producto del carrito.'),
      ], 500);
    }
  }

  /**
   * Builds the current cart panel JSON response.
   */
  private function buildPanelResponse(AccountInterface $account, string $message = ''): JsonResponse {
    $items = [];
    $cart_groups = [];
    $cart_total = '';
    $cart_count = 0;

    try {
      foreach ($this->cartProvider->getCarts($account) as $cart) {
        $cart_items = [];
        foreach ($cart->getItems() as $order_item) {
          $quantity = (float) $order_item->getQuantity();
          $cart_count += $quantity;
          $purchased_entity = $order_item->getPurchasedEntity();
          $cart_items[] = [
            'order_item_id' => is_object($order_item) && method_exists($order_item, 'id') ? (int) $order_item->id() : 0,
            'title' => $purchased_entity instanceof PurchasableEntityInterface
              ? $purchased_entity->getOrderItemTitle()
              : $order_item->label(),
            'sku' => is_object($purchased_entity) && method_exists($purchased_entity, 'getSku') ? (string) $purchased_entity->getSku() : '',
            'image_url' => $this->getPurchasedEntityImageUrl($purchased_entity),
            'image_alt' => $purchased_entity instanceof PurchasableEntityInterface
              ? $purchased_entity->getOrderItemTitle()
              : $order_item->label(),
            'seller_label' => $this->getPurchasedEntitySellerLabel($purchased_entity),
            'stock_label' => (string) $this->t('En Stock'),
            'quantity' => $this->formatQuantity($quantity),
            'quantity_value' => $this->formatQuantityValue($quantity),
            'unit_price' => $this->formatCommercePrice($order_item->getUnitPrice()),
            'total_price' => $this->formatCommercePrice($order_item->getTotalPrice()),
            'url' => $this->getPurchasedEntityUrl($purchased_entity),
          ];
        }

        if ($cart_items === []) {
          continue;
        }

        $items = array_merge($items, $cart_items);
        $cart_groups[] = [
          'id' => is_object($cart) && method_exists($cart, 'id') ? (int) $cart->id() : NULL,
          'store_name' => $this->getCartStoreLabel($cart),
          'items' => $cart_items,
          'summary' => $cart instanceof OrderInterface ? $this->buildCartTotals($cart) : [],
          'total' => $this->formatCommercePrice($cart->getTotalPrice()),
        ];
      }
    }
    catch (\Throwable) {
      return new JsonResponse([
        'error' => $this->t('No se ha podido abrir el carrito.'),
      ], 500);
    }

    $build = [
      '#type' => 'inline_template',
      '#template' => $this->getCartPanelTemplate(),
      '#context' => [
        'title' => $this->t('Tu carrito'),
        'intro' => $items === []
          ? $this->t('Todavía no hay productos en el carrito.')
          : $this->t('Estos son los productos que tienes ahora mismo en el carrito.'),
        'items' => $items,
        'cart_groups' => $cart_groups,
        'cart_total' => $cart_total,
        'cart_url' => Url::fromRoute('commerce_cart.page')->toString(),
        'checkout_url' => Url::fromRoute('commerce_checkout.checkout')->toString(),
        'empty_label' => $this->t('Carrito vacío'),
        'quantity_label' => $this->t('Cantidad'),
        'unit_price_label' => $this->t('Precio unidad'),
        'subtotal_label' => $this->t('Subtotal'),
        'total_label' => $this->t('Total'),
        'cart_link_label' => $this->t('Ver carrito completo'),
        'checkout_link_label' => $this->t('Ir al pago'),
        'update_item_url' => Url::fromRoute('trotamundos_assistant.skill_action', [
          'machine_name' => 'shopping_cart_view',
          'action' => 'update_item',
        ])->toString(),
        'remove_item_url' => Url::fromRoute('trotamundos_assistant.skill_action', [
          'machine_name' => 'shopping_cart_view',
          'action' => 'remove_item',
        ])->toString(),
        'update_item_label' => $this->t('Actualizar'),
        'remove_item_label' => $this->t('Eliminar'),
      ],
    ];

    if (count($cart_groups) === 1) {
      $build['#context']['cart_total'] = (string) ($cart_groups[0]['total'] ?? '');
    }

    return new JsonResponse([
      'status' => 'ok',
      'message' => $message,
      'cart_count' => (int) round($cart_count),
      'result_html' => (string) $this->renderer->renderInIsolation($build),
    ]);
  }

  /**
   * Formats a Commerce price-like object.
   */
  private function formatCommercePrice(mixed $price): string {
    if (!is_object($price) || !method_exists($price, 'getNumber')) {
      return '';
    }

    $currency = method_exists($price, 'getCurrencyCode') ? (string) $price->getCurrencyCode() : 'EUR';
    $symbol = $currency === 'EUR' ? '€' : $currency;

    return number_format((float) $price->getNumber(), 2, ',', '.') . ' ' . $symbol;
  }

  /**
   * Formats a quantity without noisy decimals.
   */
  private function formatQuantity(float $quantity): string {
    if (floor($quantity) === $quantity) {
      return (string) (int) $quantity;
    }

    return rtrim(rtrim(number_format($quantity, 2, ',', '.'), '0'), ',');
  }

  /**
   * Formats a quantity for storage and AJAX payloads.
   */
  private function formatQuantityValue(float $quantity): string {
    if (floor($quantity) === $quantity) {
      return (string) (int) $quantity;
    }

    return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
  }

  /**
   * Builds subtotal, adjustments, and total display data for a cart order.
   *
   * @return array{subtotal: string, adjustments: array<int, array{label: string, amount: string, type: string}>, total: string}
   *   Cart total summary data.
   */
  private function buildCartTotals(OrderInterface $cart): array {
    $totals = $this->orderTotalSummary->buildTotals($cart);
    $adjustments = [];
    foreach (($totals['adjustments'] ?? []) as $adjustment) {
      if (!is_array($adjustment)) {
        continue;
      }

      $amount = $adjustment['total'] ?? $adjustment['amount'] ?? NULL;
      $adjustments[] = [
        'label' => (string) ($adjustment['label'] ?? $this->t('Ajuste')),
        'amount' => $this->formatCommercePrice($amount),
        'type' => (string) ($adjustment['type'] ?? ''),
      ];
    }

    return [
      'subtotal' => $this->formatCommercePrice($totals['subtotal'] ?? NULL),
      'adjustments' => $adjustments,
      'total' => $this->formatCommercePrice($totals['total'] ?? NULL),
    ];
  }

  /**
   * Finds an order item in the carts available to the current account.
   *
   * @return array{cart: \Drupal\commerce_order\Entity\OrderInterface, order_item: \Drupal\commerce_order\Entity\OrderItemInterface}|null
   *   The cart and item pair, or NULL if it cannot be found.
   */
  private function findCartOrderItem(AccountInterface $account, int $order_item_id): ?array {
    foreach ($this->cartProvider->getCarts($account) as $cart) {
      if (!$cart instanceof OrderInterface) {
        continue;
      }

      foreach ($cart->getItems() as $order_item) {
        if ($order_item instanceof OrderItemInterface && (int) $order_item->id() === $order_item_id) {
          return [
            'cart' => $cart,
            'order_item' => $order_item,
          ];
        }
      }
    }

    return NULL;
  }

  /**
   * Gets the product URL for a purchased entity when possible.
   */
  private function getPurchasedEntityUrl(mixed $purchased_entity): string {
    if (is_object($purchased_entity) && method_exists($purchased_entity, 'getProduct')) {
      $product = $purchased_entity->getProduct();
      return $product && method_exists($product, 'toUrl') ? $product->toUrl()->toString() : '';
    }

    return is_object($purchased_entity) && method_exists($purchased_entity, 'toUrl')
      ? $purchased_entity->toUrl()->toString()
      : '';
  }

  /**
   * Gets the product image for a purchased entity when possible.
   */
  private function getPurchasedEntityImageUrl(mixed $purchased_entity): string {
    if ($purchased_entity instanceof ProductVariationInterface) {
      $product = $purchased_entity->getProduct();
      if ($product instanceof ProductInterface) {
        return $this->getProductImageUrl($product, $purchased_entity);
      }
    }

    if ($purchased_entity instanceof ProductInterface) {
      return $this->getProductImageUrl($purchased_entity, NULL);
    }

    return '';
  }

  /**
   * Gets the seller label for a purchased entity when possible.
   */
  private function getPurchasedEntitySellerLabel(mixed $purchased_entity): string {
    if ($purchased_entity instanceof ProductVariationInterface) {
      $product = $purchased_entity->getProduct();
      if ($product instanceof ProductInterface) {
        return $this->getReferencedEntityLabel($product, 'field_store') ?: $product->getTitle();
      }
    }

    if ($purchased_entity instanceof ProductInterface) {
      return $this->getReferencedEntityLabel($purchased_entity, 'field_store') ?: $purchased_entity->getTitle();
    }

    return '';
  }

  /**
   * Gets the image URL from a product variation or its parent product.
   */
  private function getProductImageUrl(ProductInterface $product, ?ProductVariationInterface $variation): string {
    if ($variation) {
      $image_url = $this->getEntityImageUrl($variation);
      if ($image_url !== '') {
        return $image_url;
      }
    }

    return $this->getEntityImageUrl($product);
  }

  /**
   * Gets the image URL from an entity with a field_image media reference.
   */
  private function getEntityImageUrl(object $entity): string {
    if (!method_exists($entity, 'hasField') || !$entity->hasField('field_image') || $entity->get('field_image')->isEmpty()) {
      return '';
    }

    $media = $entity->get('field_image')->entity;
    if (!$media) {
      return '';
    }

    $source_field = 'field_media_image';
    if ($media->hasField($source_field) && !$media->get($source_field)->isEmpty()) {
      $file = $media->get($source_field)->entity;
      if ($file && file_exists($file->getFileUri())) {
        return $this->fileUrlGenerator->generateString($file->getFileUri());
      }
    }

    return '';
  }

  /**
   * Gets the label of a referenced entity field.
   */
  private function getReferencedEntityLabel(object $entity, string $field_name): string {
    if (!method_exists($entity, 'hasField') || !$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    return (string) ($entity->get($field_name)->entity?->label() ?? '');
  }

  /**
   * Gets the display label for the store attached to a cart.
   */
  private function getCartStoreLabel(mixed $cart): string {
    if (is_object($cart) && method_exists($cart, 'getStore')) {
      $store = $cart->getStore();
      if (is_object($store)) {
        return method_exists($store, 'label') ? (string) $store->label() : (string) $this->t('Vendedor');
      }
    }

    return (string) $this->t('Vendedor');
  }

  /**
   * Gets the inline template for the assistant cart panel.
   */
  private function getCartPanelTemplate(): string {
    $path = $this->moduleExtensionList->getPath('trotamundos_assistant_ecommerce') . '/skills/shopping_cart_view/action/cart-panel.html.twig';

    return is_file($path) ? (string) file_get_contents($path) : '';
  }

}
