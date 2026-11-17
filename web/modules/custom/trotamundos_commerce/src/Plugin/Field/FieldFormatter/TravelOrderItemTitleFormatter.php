<?php

declare(strict_types=1);

namespace Drupal\trotamundos_commerce\Plugin\Field\FieldFormatter;

use Drupal\commerce_order\Plugin\Field\FieldFormatter\OrderItemTitleFormatter;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[FieldFormatter(
  id: 'trotamundos_order_item_title',
  label: new TranslatableMarkup('Travel plan title and date'),
  field_types: ['string'],
)]
final class TravelOrderItemTitleFormatter extends OrderItemTitleFormatter {

  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);
    $item = $items->getEntity();
    if ($purchased = $item->getPurchasedEntity()) {
      $elements[0]['#label'] = Link::fromTextAndUrl($item->label(), $purchased->toUrl())->toRenderable();
      CacheableMetadata::createFromRenderArray($elements[0])->addCacheableDependency($purchased)->applyTo($elements[0]);
    }
    return $elements;
  }

}
