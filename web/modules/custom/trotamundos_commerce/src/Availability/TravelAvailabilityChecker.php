<?php

declare(strict_types=1);

namespace Drupal\trotamundos_commerce\Availability;

use Drupal\commerce\Context;
use Drupal\commerce_order\AvailabilityCheckerInterface;
use Drupal\commerce_order\AvailabilityResult;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\trotamundos_experiences\Setup\ProductTypes;

/**
 * Rechecks itinerary dates and per-line capacity through native Commerce.
 */
final class TravelAvailabilityChecker implements AvailabilityCheckerInterface {

  use StringTranslationTrait;

  public function applies(OrderItemInterface $order_item): bool {
    $variation = $order_item->getPurchasedEntity();
    return $variation instanceof ProductVariationInterface && in_array($variation->bundle(), ProductTypes::BUNDLES, TRUE);
  }

  public function check(OrderItemInterface $order_item, Context $context): AvailabilityResult {
    $node = _trotamundos_commerce_plan_for_variation($order_item->getPurchasedEntity());
    $quantity = $order_item->getQuantity();
    $capacity = $node ? (int) $node->get('field_capacidad')->value : 0;
    if (!$node || $capacity < 1 || !preg_match('/^\d+(?:\.0+)?$/D', $quantity) || Calculator::compare($quantity, '1') < 0
      || Calculator::compare($quantity, (string) $capacity) > 0) {
      return AvailabilityResult::unavailable($this->t('El número de viajeros supera la capacidad de este plan.'));
    }
    // Variation discovery also checks unsaved items before a date is chosen.
    if ($order_item->isNew() && !$order_item->getData('tm_trip_date')) {
      return AvailabilityResult::neutral();
    }
    $end = $this->tripEnd($node, $order_item->getData('tm_trip_date'));
    if ($end === NULL || $end !== $order_item->getData('tm_trip_end')) {
      return AvailabilityResult::unavailable($this->t('Revisa las fechas: el viaje ya no cabe en un periodo disponible de este plan.'));
    }
    return AvailabilityResult::neutral();
  }

  public function tripEnd(NodeInterface $node, mixed $value): ?string {
    $timezone = new \DateTimeZone('America/Bogota');
    $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)
      ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone) : FALSE;
    $nights = (int) $node->get('field_noches')->value;
    if (!$date || $date->format('Y-m-d') !== $value || $value < (new \DateTimeImmutable('today', $timezone))->format('Y-m-d') || $nights < 0) {
      return NULL;
    }
    $end = $date->modify('+' . $nights . ' days')->format('Y-m-d');
    foreach ($node->get('field_fechas') as $range) {
      if ($range->value && $range->end_value && $value >= $range->value && $end <= $range->end_value) {
        return $end;
      }
    }
    return NULL;
  }

}
