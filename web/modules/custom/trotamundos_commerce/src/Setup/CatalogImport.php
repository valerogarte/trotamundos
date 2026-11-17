<?php

declare(strict_types=1);

namespace Drupal\trotamundos_commerce\Setup;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_store\Entity\Store;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\trotamundos_experiences\Setup\ProductTypes;
use Drupal\node\NodeInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\language\ConfigurableLanguageManagerInterface;

/**
 * Explicit, repeatable provisioning; never creates orders or payment gateways.
 */
final class CatalogImport {

  public static function configureCart(): void {
    if (!\Drupal::moduleHandler()->moduleExists('commerce_cart')) {
      throw new \LogicException('Enable Commerce Cart before configuring its form.');
    }
    $display = EntityFormDisplay::load('commerce_order_item.default.add_to_cart');
    if (!$display) {
      throw new \LogicException('The native add-to-cart display is missing.');
    }
    if (!$display->getComponent('quantity')) {
      $display->setComponent('quantity', ['type' => 'commerce_quantity', 'weight' => 1, 'settings' => ['step' => '1']])->save();
    }
    $view = \Drupal::entityTypeManager()->getStorage('view')->loadOverrideFree('commerce_cart_form');
    if (!$view) {
      throw new \LogicException('The native cart view is missing.');
    }
    $displays = $view->get('display');
    $fields = &$displays['default']['display_options']['fields'];
    if (isset($fields['purchased_entity'])) {
      $updated = [];
      foreach ($fields as $key => $field) {
        if ($key === 'purchased_entity') {
          $field['id'] = $field['field'] = $field['entity_field'] = 'title';
          $field['type'] = 'trotamundos_order_item_title';
          $field['settings'] = [];
          $field['click_sort_column'] = $field['group_column'] = 'value';
          $updated['title'] = $field;
        }
        else {
          $updated[$key] = $field;
        }
      }
      $fields = $updated;
      $view->set('display', $displays)->save();
    }
    elseif (!isset($fields['title'])) {
      throw new \LogicException('Review the customized cart view before configuring its plan title.');
    }
    $languages = \Drupal::languageManager();
    if ($languages instanceof ConfigurableLanguageManagerInterface && $languages->getLanguage('en') && $view->language()->getId() !== 'en') {
      $override = $languages->getLanguageConfigOverride('en', 'views.view.commerce_cart_form');
      foreach (['title' => 'Plan', 'unit_price__number' => 'Price', 'edit_quantity' => 'Travellers', 'remove_button' => 'Remove', 'total_price__number' => 'Total'] as $field => $label) {
        $override->set('display.default.display_options.fields.' . $field . '.label', $label);
      }
      $override->save();
    }
  }

  public static function syncTitle(NodeInterface $node): void {
    if (!in_array($node->bundle(), ProductTypes::BUNDLES, TRUE) || $node->get('field_commerce_product')->isEmpty()) {
      return;
    }
    $product = $node->get('field_commerce_product')->entity;
    if (!$product || $product->bundle() !== $node->bundle()) {
      throw new \LogicException('The linked product must match the editorial plan type.');
    }
    $changed = FALSE;
    foreach ($node->getTranslationLanguages() as $langcode => $language) {
      if (!$product->hasTranslation($langcode)) {
        $product->addTranslation($langcode, [
          'title' => $node->getTranslation($langcode)->label(),
          'status' => $product->isPublished(),
        ]);
        $changed = TRUE;
      }
      elseif ($product->getTranslation($langcode)->label() !== $node->getTranslation($langcode)->label()) {
        $product->getTranslation($langcode)->setTitle($node->getTranslation($langcode)->label());
        $changed = TRUE;
      }
    }
    if ($changed) {
      $product->save();
    }
    foreach ($product->getVariations() as $variation) {
      $variation_changed = FALSE;
      foreach ($node->getTranslationLanguages() as $langcode => $language) {
        if (!$variation->hasTranslation($langcode)) {
          $variation->addTranslation($langcode, [
            'title' => $node->getTranslation($langcode)->label(),
            'status' => $variation->isPublished(),
          ]);
          $variation_changed = TRUE;
        }
      }
      if ($variation_changed) {
        $variation->save();
      }
    }
  }

  public static function developmentStore(string $contact_email): StoreInterface {
    if (!\Drupal::service('email.validator')->isValid($contact_email)) {
      throw new \InvalidArgumentException('A valid store contact email is required.');
    }
    $ledger = \Drupal::keyValue('trotamundos_commerce.provisioning');
    $storage = \Drupal::entityTypeManager()->getStorage('commerce_store');
    if ($uuid = $ledger->get('store_uuid')) {
      $stores = $storage->loadByProperties(['uuid' => $uuid]);
      if (count($stores) !== 1) {
        throw new \RuntimeException('The provisioned store was removed; review it before provisioning again.');
      }
      return reset($stores);
    }
    if ($storage->getQuery()->accessCheck(FALSE)->count()->execute()) {
      throw new \RuntimeException('Select an existing store explicitly instead of creating another one.');
    }
    $store = Store::create([
      'type' => 'online', 'name' => 'Trotamundos', 'uid' => 1,
      'mail' => $contact_email, 'default_currency' => 'COP', 'timezone' => 'America/Bogota',
      'address' => [
        'country_code' => 'CO', 'administrative_area' => 'ANT', 'locality' => 'La Ceja',
        'address_line1' => 'Dirección pendiente de completar — SOLO DESARROLLO',
      ],
      'is_default' => TRUE,
    ]);
    $errors = $store->validate();
    if ($errors->count()) {
      throw new \RuntimeException('Invalid store configuration: ' . (string) $errors);
    }
    $store->save();
    $ledger->set('store_uuid', $store->uuid());
    $ledger->set('production_address_pending', TRUE);
    return $store;
  }

  public static function import(StoreInterface $store): int {
    if ($store->isNew() || $store->getDefaultCurrencyCode() !== 'COP' || $store->validate()->count()) {
      throw new \InvalidArgumentException('Import requires a saved, valid COP store.');
    }
    $manager = \Drupal::entityTypeManager();
    $nodes = $manager->getStorage('node');
    $ids = $nodes->getQuery()->accessCheck(FALSE)->condition('type', ProductTypes::BUNDLES, 'IN')->execute();
    $created = 0;
    foreach ($nodes->loadMultiple($ids) as $node) {
      if (!$node->get('field_commerce_product')->isEmpty()) {
        $existing = $node->get('field_commerce_product')->entity;
        if (!$existing || $existing->bundle() !== $node->bundle()) {
          throw new \RuntimeException('Review the existing Commerce link on node ' . $node->id());
        }
        self::syncTitle($node);
        continue;
      }
      if ($node->get('field_precio')->isEmpty()) {
        throw new \RuntimeException('Missing indicative price on node ' . $node->id());
      }
      $sku = 'TM-' . $node->id() . '-' . substr($node->uuid(), 0, 8);
      if ($manager->getStorage('commerce_product_variation')->loadByProperties(['sku' => $sku])) {
        throw new \RuntimeException('An unlinked variation already uses SKU ' . $sku . '; review before importing.');
      }
      $variation = ProductVariation::create([
        'type' => $node->bundle(), 'sku' => $sku, 'langcode' => $node->language()->getId(),
        'uid' => $node->getOwnerId(), 'status' => $node->isPublished(),
        'price' => new Price((string) $node->get('field_precio')->value, 'COP'),
      ]);
      $product = Product::create([
        'type' => $node->bundle(), 'title' => $node->label(), 'langcode' => $node->language()->getId(),
        'uid' => $node->getOwnerId(), 'status' => $node->isPublished(),
        'stores' => [$store], 'variations' => [$variation],
      ]);
      foreach ($node->getTranslationLanguages() as $langcode => $language) {
        if ($langcode !== $node->language()->getId()) {
          $product->addTranslation($langcode, [
            'title' => $node->getTranslation($langcode)->label(),
            'status' => $node->getTranslation($langcode)->isPublished(),
          ]);
        }
      }
      $product->save();
      foreach ([$variation, $product] as $entity) {
        if ($entity->validate()->count()) {
          throw new \RuntimeException('Invalid imported Commerce entity: ' . (string) $entity->validate());
        }
      }
      $node->set('field_commerce_product', $product);
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage('Vinculación a Commerce; contenido editorial conservado.');
      $node->save();
      $created++;
    }
    return $created;
  }

}
