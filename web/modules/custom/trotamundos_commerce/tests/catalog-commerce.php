<?php

declare(strict_types=1);

/**
 * @file
 * Transactional catalog provisioning, preservation and price-source checks.
 */

use Drupal\commerce_price\Price;
use Drupal\commerce\Context;
use Drupal\commerce_order\Entity\Order;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteMatch;
use Drupal\trotamundos_commerce\Controller\TravelCheckoutController;
use Drupal\trotamundos_commerce\Controller\ProductPageController;
use Drupal\trotamundos_commerce\Setup\CatalogImport;
use Drupal\trotamundos_commerce\Setup\CatalogPrice;
use Drupal\trotamundos_commerce\Setup\DemoCatalog;
use Drupal\trotamundos_experiences\Setup\ProductTypes;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

if (PHP_SAPI !== 'cli' || !class_exists('Drupal')) {
  throw new RuntimeException('Run this test with drush php:script.');
}
$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$manager = \Drupal::entityTypeManager();
$nodes = $manager->getStorage('node');
$ids = $nodes->getQuery()->accessCheck(FALSE)->condition('type', ProductTypes::BUNDLES, 'IN')->execute();
$before = [];
foreach ($nodes->loadMultiple($ids) as $node) {
  $before[$node->id()] = [
    'data' => $node->toArray(),
    'revisions' => $nodes->revisionIds($node),
    'urls' => array_map(static fn ($language) => $node->getTranslation($language->getId())->toUrl()->toString(), $node->getTranslationLanguages()),
  ];
}
$transaction = \Drupal::database()->startTransaction();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(User::load(1));
try {
  $store = CatalogImport::developmentStore('catalog-fixture@example.invalid');
  $assert($store->validate()->count() === 0, 'The development store is invalid.');
  $assert(CatalogImport::developmentStore('catalog-fixture@example.invalid')->id() === $store->id(), 'Store provisioning duplicated the store.');
  DemoCatalog::ensure();
  $assert(DemoCatalog::ensure() === 0, 'Demo generation is not idempotent.');
  CatalogImport::import($store);
  $assert(CatalogImport::import($store) === 0, 'Repeated import duplicated products or variations.');
  foreach (ProductTypes::BUNDLES as $bundle) {
    $assert((int) $nodes->getQuery()->accessCheck(FALSE)->condition('type', $bundle)->condition('status', 1)->count()->execute() >= 12, 'Missing twelve published plans of type ' . $bundle);
    $bundle_ids = $nodes->getQuery()->accessCheck(FALSE)->condition('type', $bundle)->execute();
    foreach ($nodes->loadMultiple($bundle_ids) as $node) {
      $product = $node->get('field_commerce_product')->entity;
      $assert($product && $product->bundle() === $bundle && $product->validate()->count() === 0, 'Invalid or missing commercial plan.');
      $assert($product->getDefaultVariation() && $product->getDefaultVariation()->isPublished(), 'A published plan is not purchasable.');
      $assert($product->getTranslation('en')->label() === $node->getTranslation('en')->label(), 'English title was not imported.');
      $assert($product->getTranslation('en')->isPublished(), 'English products must preserve publication status.');
    }
  }
  $nodes->resetCache($ids);
  foreach ($before as $id => $snapshot) {
    $node = $nodes->load($id);
    $data = $node->toArray();
    $original = $snapshot['data'];
    foreach (['vid', 'revision_timestamp', 'revision_uid', 'revision_log', 'changed', 'field_commerce_product', CatalogPrice::FIELD] as $field) {
      unset($data[$field], $original[$field]);
    }
    $assert($data === $original, 'Import changed original editorial data on node ' . $id);
    $assert(!array_diff($snapshot['revisions'], $nodes->revisionIds($node)), 'Historical revisions were lost.');
    foreach ($snapshot['urls'] as $langcode => $url) {
      $assert($node->getTranslation($langcode)->toUrl()->toString() === $url, 'An original public URL changed.');
    }
  }
  $first = $nodes->load(reset($ids));
  $product = $first->get('field_commerce_product')->entity;
  $variation = $product->getDefaultVariation();
  $requests = \Drupal::service('request_stack');
  $requests->push(Request::create('/product/' . $product->id(), 'GET', ['v' => $variation->id()]));
  try {
    $redirect = ProductPageController::create(\Drupal::getContainer())->view($product);
    $assert($redirect->getTargetUrl() === $first->toUrl()->setOption('query', ['v' => $variation->id()])->toString(), 'Cart product links lost the editorial URL or selected variation.');
  }
  finally {
    $requests->pop();
  }
  $variation->setPrice(new Price('300000.50', 'COP'))->save();
  $nodes->resetCache([$first->id()]);
  $first = $nodes->load($first->id());
  $assert((new Price((string) $first->get(CatalogPrice::FIELD)->value, 'COP'))->equals(new Price('300000.50', 'COP')), 'Commerce price changes did not refresh the decimal index.');
  $repository = \Drupal::service('trotamundos_experiences.repository');
  $assert($repository->card($first)['price'] === 300000.5, 'The catalog did not use the Commerce price.');
  $filters = $repository->filtersFromRequest(Request::create('/planes', 'GET', ['precio' => 'hasta-300000']));
  $assert(!in_array((int) $first->id(), array_column($repository->search($filters, 1000)['cards'], 'id'), TRUE), 'A fractional price crossed the wrong budget boundary.');
  if (\Drupal::moduleHandler()->moduleExists('commerce_cart')) {
    $today = new DateTimeImmutable('today', new DateTimeZone('America/Bogota'));
    $first->set('field_noches', 2);
    $first->set('field_fechas', [['value' => $today->format('Y-m-d'), 'end_value' => $today->modify('+3 days')->format('Y-m-d')]])->save();
    $stateFor = static function () use ($manager, $variation): FormState {
      $item = $manager->getStorage('commerce_order_item')->createFromPurchasableEntity($variation);
      $object = $manager->getFormObject('commerce_order_item', 'add_to_cart')->setEntity($item);
      return (new FormState())->setFormObject($object);
    };
    $state = $stateFor();
    $element = ['#value' => $today->modify('+1 day')->format('Y-m-d'), '#min' => $today->format('Y-m-d'), '#parents' => ['tm_trip_date']];
    trotamundos_commerce_validate_trip_date($element, $state);
    $assert(!$state->hasAnyErrors() && $state->getFormObject()->getEntity()->getData('tm_trip_end') === $today->modify('+3 days')->format('Y-m-d'), 'A valid complete stay was not retained on the order item.');
    $item = $state->getFormObject()->getEntity();
    $availability = \Drupal::service('commerce_order.availability_manager');
    $context = new Context(\Drupal::currentUser(), $store);
    $assert(!$availability->check($item, $context)->isUnavailable(), 'A valid dated order item is unavailable.');
    foreach ([(string) ((int) $first->get('field_capacidad')->value + 1), '1.5'] as $quantity) {
      $item->setQuantity($quantity);
      $assert($availability->check($item, $context)->isUnavailable(), 'Native Commerce accepted excessive or fractional travelers.');
    }
    $item->setQuantity('1')->save();
    $item->set('order_id', Order::create(['type' => 'default', 'store_id' => $store->id(), 'state' => 'completed']));
    $item->setTitle('Original purchased title');
    trotamundos_commerce_commerce_order_item_presave($item);
    $assert($item->getTitle() === 'Original purchased title', 'A placed order lost its purchased title snapshot.');
    $item->set('order_id', NULL);
    $item->setData('tm_trip_end', $today->modify('+2 days')->format('Y-m-d'));
    $assert($availability->check($item, $context)->isUnavailable(), 'Native Commerce accepted an altered trip end.');
    $item->setData('tm_trip_date', NULL)->setData('tm_trip_end', NULL);
    $assert($availability->check($item, $context)->isUnavailable(), 'A saved line without travel dates remained purchasable.');
    foreach ([$today->modify('+3 days')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d'), "2026-01-01\0", ['invalid']] as $invalid) {
      $state = $stateFor();
      $element['#value'] = $invalid;
      trotamundos_commerce_validate_trip_date($element, $state);
      $assert($state->hasAnyErrors(), 'An invalid or incomplete available stay was accepted.');
      $state->clearErrors();
    }
    $cart = Order::create(['type' => 'default', 'store_id' => $store->id(), 'uid' => 1, 'state' => 'draft', 'cart' => TRUE]);
    $cart->save();
    $match = new RouteMatch('commerce_checkout.form', \Drupal::service('router.route_provider')->getRouteByName('commerce_checkout.form'), ['commerce_order' => $cart]);
    $controller = TravelCheckoutController::create(\Drupal::getContainer());
    $build = $controller->formPage($match);
    $assert(is_array($build) && isset($build['message']) && !isset($build['#form_id']), 'Development checkout built a payment form.');
    $requests = \Drupal::service('request_stack');
    $requests->push(Request::create('/checkout/' . $cart->id(), 'POST'));
    try {
      $response = $controller->formPage($match);
      $assert($response->getStatusCode() === 503 && $cart->getState()->getId() === 'draft', 'A crafted POST bypassed development checkout protection.');
    }
    finally {
      $requests->pop();
    }
  }
  print "Catalog Commerce checks passed: twelve plans per type, idempotency, translations, preserved content/URLs/revisions, decimal pricing, complete travel dates and blocked development checkout.\n";
}
finally {
  $transaction->rollBack();
  $switcher->switchBack();
  foreach (['node', 'commerce_product', 'commerce_product_variation', 'commerce_store', 'commerce_order', 'commerce_order_item'] as $type) {
    $manager->getStorage($type)->resetCache();
  }
}
