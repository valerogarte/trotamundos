<?php

declare(strict_types=1);

/**
 * @file
 * Run with drush php:script. All fixture data is rolled back.
 */

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_store\Entity\Store;
use Drupal\node\Entity\Node;
use Drupal\trotamundos_commerce\Setup\CommerceModel;
use Drupal\trotamundos_experiences\Setup\ProductTypes;
use Drupal\user\Entity\User;

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
$snapshot = static function () use ($nodes, $ids): array {
  $nodes->resetCache($ids);
  return array_map(static fn ($node) => $node->toArray(), $nodes->loadMultiple($ids));
};
$before = $snapshot();
$sales_modules = array_map(static fn ($module) => \Drupal::moduleHandler()->moduleExists($module), [
  'commerce_cart', 'commerce_checkout', 'commerce_payment',
]);
$transaction = \Drupal::database()->startTransaction();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(User::load(1));

try {
  CommerceModel::ensure();
  CommerceModel::ensure();
  $assert($snapshot() === $before, 'Commerce setup modified existing editorial content.');
  $modules = \Drupal::moduleHandler();
  foreach (['commerce_cart', 'commerce_checkout', 'commerce_payment'] as $index => $module) {
    $assert($modules->moduleExists($module) === $sales_modules[$index], 'Model preparation must not change sales-module activation.');
  }

  $store = Store::create([
    'type' => 'online',
    'name' => 'Transactional test fixture',
    'mail' => 'commerce-test@example.invalid',
    'default_currency' => 'COP',
    'timezone' => 'America/Bogota',
    'address' => ['country_code' => 'CO'],
    'is_default' => FALSE,
  ]);
  $store->save();
  $products = [];
  foreach (ProductTypes::BUNDLES as $bundle) {
    $variation = ProductVariation::create([
      'type' => $bundle,
      'sku' => 'transactional-test-' . $bundle,
      'price' => new Price('100000', 'COP'),
    ]);
    $product = Product::create([
      'type' => $bundle,
      'title' => 'Transactional test ' . $bundle,
      'stores' => [$store],
      'variations' => [$variation],
    ]);
    $assert(!$product->isPublished() && !$variation->isPublished(), 'New products and variations must start unpublished.');
    $product->save();
    $violations = $product->validate();
    $assert($violations->count() === 0, 'Invalid Commerce model: ' . (string) $violations);
    $products[$bundle] = $product;
  }

  foreach ($products as $bundle => $product) {
    $node = Node::create(['type' => $bundle, 'title' => 'Reference fixture']);
    $node->set('field_commerce_product', $product);
    $assert($node->get('field_commerce_product')->validate()->count() === 0, 'A matching draft product cannot be linked.');
    $other = $products[$bundle === 'paquete' ? 'alojamiento' : 'paquete'];
    $node->set('field_commerce_product', $other);
    $assert($node->get('field_commerce_product')->validate()->count() > 0, 'A product of another type was accepted.');
  }

  $reviewed = $products['alojamiento'];
  $reviewed->setPublished()->save();
  $variation = $reviewed->getDefaultVariation();
  $variation->setPublished();
  $variation->setPrice(new Price('125000', 'COP'));
  $variation->save();
  CommerceModel::ensure();
  $manager->getStorage('commerce_product')->resetCache();
  $manager->getStorage('commerce_product_variation')->resetCache();
  $reviewed = Product::load($reviewed->id());
  $assert($reviewed->isPublished() && $reviewed->getDefaultVariation()->isPublished(), 'Repeated setup reset reviewed publication states.');
  $assert($reviewed->getDefaultVariation()->getPrice()->equals(new Price('125000', 'COP')), 'Repeated setup overwrote a reviewed price.');
  $assert($snapshot() === $before, 'Commerce setup changed an existing editorial record.');
  print "Commerce product preparation checks passed.\n";
}
finally {
  $transaction->rollBack();
  $switcher->switchBack();
  foreach (['node', 'commerce_product', 'commerce_product_variation', 'commerce_store'] as $entity_type) {
    $manager->getStorage($entity_type)->resetCache();
  }
}
