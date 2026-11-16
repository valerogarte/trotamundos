<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_ecommerce\SkillLocal\SalesAnalytics;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Aggregates commerce sales figures for the analytics skill.
 *
 * Every number shown by the skill is produced here: the language model only
 * chooses the period and the focus, never the values.
 */
final class SalesStatsBuilder {

  use StringTranslationTrait;

  /**
   * Order states counted as real sales.
   */
  private const SALE_STATES = ['completed', 'fulfillment'];

  /**
   * Hard ceiling on the orders loaded for one report.
   *
   * Ponytail: aggregating in PHP is fine at demo scale. Past this many orders
   * the aggregation belongs in a SQL query over commerce_order_item.
   */
  private const MAX_ORDERS = 2000;

  /**
   * How many entries the ranking charts show.
   */
  private const TOP_LIMIT = 8;

  /**
   * How many orders the detail table lists.
   */
  private const TABLE_LIMIT = 10;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CurrencyFormatterInterface $currencyFormatter,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly AccountProxyInterface $currentUser,
    private readonly ModuleHandlerInterface $moduleHandler,
    TranslationInterface $string_translation,
  ) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * Checks whether the current user may see site-wide sales figures.
   */
  public function hasAccess(): bool {
    return $this->currentUser->hasPermission('access commerce administration pages');
  }

  /**
   * Builds the full report for one period.
   *
   * @param string $range
   *   One of month, quarter, year, all or custom.
   * @param string $from
   *   Start date as Y-m-d, only used when $range is custom.
   * @param string $to
   *   End date as Y-m-d, only used when $range is custom.
   *
   * @return array<string, mixed>
   *   The report data consumed by the template.
   */
  public function build(string $range, string $from = '', string $to = ''): array {
    [$start, $end, $label] = $this->resolvePeriod($range, $from, $to);
    [$previous_start, $previous_end, $previous_label] = $this->resolvePreviousPeriod($start, $end);

    $current = $this->loadOrders($start, $end);
    $previous = $this->loadOrders($previous_start, $previous_end);

    $current_totals = $this->summarize($current);
    $previous_totals = $this->summarize($previous);

    return [
      'range' => $range,
      'period_label' => $label,
      'previous_label' => $previous_label,
      'from' => date('Y-m-d', $start),
      'to' => date('Y-m-d', $end),
      'currency' => $current_totals['currency'],
      'kpis' => $this->buildKpis($current_totals, $previous_totals),
      'monthly' => $this->buildMonthlySeries($current, $previous, $start, $end),
      'categories' => $this->buildBreakdown($current, 'category'),
      'products' => $this->buildBreakdown($current, 'product'),
      'stores' => $this->buildBreakdown($current, 'store'),
      'orders' => $this->buildOrderTable($current),
      'has_data' => $current !== [],
      'has_previous' => $previous !== [],
      'truncated' => count($current) >= self::MAX_ORDERS,
    ];
  }

  /**
   * Resolves a named range into a start and end timestamp.
   *
   * @return array{0: int, 1: int, 2: string}
   *   Start timestamp, end timestamp and a human label.
   */
  private function resolvePeriod(string $range, string $from, string $to): array {
    $now = time();

    return match ($range) {
      'month' => [
        strtotime('first day of this month 00:00:00'),
        $now,
        (string) $this->t('Este mes'),
      ],
      'quarter' => [
        strtotime('-3 months', strtotime('today 00:00:00')),
        $now,
        (string) $this->t('Últimos 3 meses'),
      ],
      'custom' => $this->resolveCustomPeriod($from, $to),
      'all' => [
        $this->getFirstOrderTimestamp(),
        $now,
        (string) $this->t('Histórico completo'),
      ],
      default => [
        strtotime('first day of January this year 00:00:00'),
        $now,
        (string) $this->t('Año @year', ['@year' => date('Y')]),
      ],
    };
  }

  /**
   * Validates a model-supplied custom range, falling back to the current year.
   *
   * @return array{0: int, 1: int, 2: string}
   *   Start timestamp, end timestamp and a human label.
   */
  private function resolveCustomPeriod(string $from, string $to): array {
    $start = $from !== '' ? strtotime($from . ' 00:00:00') : FALSE;
    $end = $to !== '' ? strtotime($to . ' 23:59:59') : FALSE;

    if ($start === FALSE || $end === FALSE || $end <= $start) {
      return [
        strtotime('first day of January this year 00:00:00'),
        time(),
        (string) $this->t('Año @year', ['@year' => date('Y')]),
      ];
    }

    return [
      $start,
      $end,
      (string) $this->t('@from – @to', [
        '@from' => $this->dateFormatter->format($start, 'custom', 'd/m/Y'),
        '@to' => $this->dateFormatter->format($end, 'custom', 'd/m/Y'),
      ]),
    ];
  }

  /**
   * Shifts a period back by its own length to build the comparison baseline.
   *
   * @return array{0: int, 1: int, 2: string}
   *   Start timestamp, end timestamp and a human label.
   */
  private function resolvePreviousPeriod(int $start, int $end): array {
    // A period that covers whole years compares against the same months of the
    // year before, which is what "versus last year" means to a shop owner.
    $previous_start = strtotime('-1 year', $start);
    $previous_end = strtotime('-1 year', $end);

    return [
      $previous_start,
      $previous_end,
      (string) $this->t('Mismo periodo de @year', ['@year' => date('Y', $previous_start)]),
    ];
  }

  /**
   * Gets the timestamp of the oldest sale, or one year ago when there is none.
   */
  private function getFirstOrderTimestamp(): int {
    $ids = $this->entityTypeManager->getStorage('commerce_order')->getQuery()
      ->accessCheck(TRUE)
      ->condition('state', self::SALE_STATES, 'IN')
      ->sort('created', 'ASC')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return strtotime('-1 year');
    }

    $order = $this->entityTypeManager->getStorage('commerce_order')->load(reset($ids));

    return $order instanceof OrderInterface ? $order->getCreatedTime() : strtotime('-1 year');
  }

  /**
   * Loads the sales placed within a period.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface[]
   *   The matching orders.
   */
  private function loadOrders(int $start, int $end): array {
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('state', self::SALE_STATES, 'IN')
      ->condition('created', $start, '>=')
      ->condition('created', $end, '<=')
      ->sort('created', 'DESC')
      ->range(0, self::MAX_ORDERS)
      ->execute();

    return $ids === [] ? [] : $storage->loadMultiple($ids);
  }

  /**
   * Reduces a set of orders to its headline figures.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $orders
   *   Orders to summarize.
   *
   * @return array<string, mixed>
   *   Revenue, order count, unit count and currency.
   */
  private function summarize(array $orders): array {
    $revenue = 0.0;
    $units = 0.0;
    $currency = 'EUR';

    foreach ($orders as $order) {
      $total = $order->getTotalPrice();
      if ($total !== NULL) {
        $revenue += (float) $total->getNumber();
        $currency = $total->getCurrencyCode();
      }
      foreach ($order->getItems() as $item) {
        $units += (float) $item->getQuantity();
      }
    }

    return [
      'revenue' => $revenue,
      'orders' => count($orders),
      'units' => $units,
      'average' => $orders === [] ? 0.0 : $revenue / count($orders),
      'currency' => $currency,
    ];
  }

  /**
   * Builds the KPI tiles with their period-over-period variation.
   *
   * @param array<string, mixed> $current
   *   Current period summary.
   * @param array<string, mixed> $previous
   *   Baseline period summary.
   *
   * @return array<int, array<string, mixed>>
   *   One entry per tile.
   */
  private function buildKpis(array $current, array $previous): array {
    $currency = $current['currency'];

    return [
      [
        'key' => 'revenue',
        'label' => (string) $this->t('Facturación'),
        'value' => $this->formatMoney($current['revenue'], $currency),
        'delta' => $this->buildDelta($current['revenue'], $previous['revenue']),
      ],
      [
        'key' => 'orders',
        'label' => (string) $this->t('Pedidos'),
        'value' => (string) $current['orders'],
        'delta' => $this->buildDelta((float) $current['orders'], (float) $previous['orders']),
      ],
      [
        'key' => 'average',
        'label' => (string) $this->t('Ticket medio'),
        'value' => $this->formatMoney($current['average'], $currency),
        'delta' => $this->buildDelta($current['average'], $previous['average']),
      ],
      [
        'key' => 'units',
        'label' => (string) $this->t('Unidades vendidas'),
        'value' => rtrim(rtrim(number_format($current['units'], 2, ',', '.'), '0'), ','),
        'delta' => $this->buildDelta($current['units'], $previous['units']),
      ],
    ];
  }

  /**
   * Expresses a variation as a signed percentage.
   *
   * @return array<string, mixed>|null
   *   The variation, or NULL when there is no baseline to compare against.
   */
  private function buildDelta(float $current, float $previous): ?array {
    if ($previous <= 0.0) {
      return NULL;
    }

    $percent = (($current - $previous) / $previous) * 100;

    return [
      'percent' => round($percent, 1),
      'direction' => $percent >= 0 ? 'up' : 'down',
      'formatted' => sprintf('%+.1f %%', $percent),
    ];
  }

  /**
   * Builds the monthly revenue series and its previous-year counterpart.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $current
   *   Orders of the reported period.
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $previous
   *   Orders of the baseline period.
   * @param int $start
   *   First timestamp of the reported period.
   * @param int $end
   *   Last timestamp of the reported period.
   *
   * @return array<string, mixed>
   *   Chart labels and both series.
   */
  private function buildMonthlySeries(array $current, array $previous, int $start, int $end): array {
    $months = $this->listMonths($start, $end);
    $current_totals = array_fill_keys(array_keys($months), 0.0);
    $previous_totals = array_fill_keys(array_keys($months), 0.0);

    foreach ($current as $order) {
      $key = date('Y-m', $order->getCreatedTime());
      if (isset($current_totals[$key])) {
        $current_totals[$key] += (float) ($order->getTotalPrice()?->getNumber() ?? 0);
      }
    }

    // The baseline is shifted forward one year so it lines up month by month
    // with the reported period.
    foreach ($previous as $order) {
      $key = date('Y-m', strtotime('+1 year', $order->getCreatedTime()));
      if (isset($previous_totals[$key])) {
        $previous_totals[$key] += (float) ($order->getTotalPrice()?->getNumber() ?? 0);
      }
    }

    return [
      'labels' => array_values($months),
      'current' => array_map(static fn(float $value): float => round($value, 2), array_values($current_totals)),
      'previous' => array_map(static fn(float $value): float => round($value, 2), array_values($previous_totals)),
    ];
  }

  /**
   * Lists the months of a period as machine keys mapped to display labels.
   *
   * @return array<string, string>
   *   Labels keyed by Y-m.
   */
  private function listMonths(int $start, int $end): array {
    $months = [];
    $cursor = strtotime(date('Y-m-01 00:00:00', $start));
    // A very long "all time" range would produce an unreadable axis.
    $guard = 0;

    while ($cursor <= $end && $guard < 36) {
      $months[date('Y-m', $cursor)] = $this->dateFormatter->format($cursor, 'custom', 'M Y');
      $cursor = strtotime('+1 month', $cursor);
      $guard++;
    }

    return $months;
  }

  /**
   * Groups revenue by category, product or store.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $orders
   *   Orders to break down.
   * @param string $dimension
   *   One of category, product or store.
   *
   * @return array<int, array<string, mixed>>
   *   Ranked entries, largest first.
   */
  private function buildBreakdown(array $orders, string $dimension): array {
    $totals = [];

    foreach ($orders as $order) {
      $store_label = $order->getStore()?->label() ?? (string) $this->t('Sin tienda');

      foreach ($order->getItems() as $item) {
        $amount = (float) ($item->getTotalPrice()?->getNumber() ?? 0);
        if ($amount === 0.0) {
          continue;
        }

        $label = match ($dimension) {
          'store' => $store_label,
          'product' => $item->getTitle(),
          default => $this->getCategoryLabel($item),
        };

        $totals[$label] = ($totals[$label] ?? 0.0) + $amount;
      }
    }

    arsort($totals);
    $currency = $this->getCurrency($orders);
    $ranked = [];
    foreach (array_slice($totals, 0, self::TOP_LIMIT, TRUE) as $label => $amount) {
      $ranked[] = [
        'name' => (string) $label,
        'value' => round($amount, 2),
        'formatted' => $this->formatMoney($amount, $currency),
      ];
    }

    return $ranked;
  }

  /**
   * Reads the product category of an order item.
   */
  private function getCategoryLabel(mixed $item): string {
    $variation = $item->getPurchasedEntity();
    $product = $variation && method_exists($variation, 'getProduct') ? $variation->getProduct() : NULL;

    if (!$product || !$product->hasField('field_product_category') || $product->get('field_product_category')->isEmpty()) {
      return (string) $this->t('Sin categoría');
    }

    $term = $product->get('field_product_category')->entity;

    return $term ? (string) $term->label() : (string) $this->t('Sin categoría');
  }

  /**
   * Builds the most recent orders shown under the charts.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $orders
   *   Orders of the reported period, newest first.
   *
   * @return array<int, array<string, string>>
   *   Table rows.
   */
  private function buildOrderTable(array $orders): array {
    $rows = [];

    foreach (array_slice($orders, 0, self::TABLE_LIMIT) as $order) {
      $total = $order->getTotalPrice();
      $rows[] = [
        'number' => (string) ($order->getOrderNumber() ?: $order->id()),
        'date' => $this->dateFormatter->format($order->getCreatedTime(), 'custom', 'd/m/Y'),
        'customer' => (string) ($order->getEmail() ?: $this->t('Sin email')),
        'store' => (string) ($order->getStore()?->label() ?? ''),
        'state' => (string) $order->getState()->getLabel(),
        'total' => $total ? $this->formatMoney((float) $total->getNumber(), $total->getCurrencyCode()) : '',
        'pdf_url' => $this->buildInvoiceUrl($order),
      ];
    }

    return $rows;
  }

  /**
   * Builds the invoice PDF link for one order.
   *
   * Access is checked per order rather than assumed from the analytics
   * permission: printing an order is a separate grant.
   *
   * @return string
   *   The PDF URL, or an empty string when it is not available.
   */
  private function buildInvoiceUrl(OrderInterface $order): string {
    if (!$this->moduleHandler->moduleExists('entity_print')) {
      return '';
    }

    $url = Url::fromRoute('entity_print.view', [
      'export_type' => 'pdf',
      'entity_type' => 'commerce_order',
      'entity_id' => $order->id(),
    ]);

    return $url->access($this->currentUser) ? $url->toString() : '';
  }

  /**
   * Reads the currency used by a set of orders.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $orders
   *   Orders to inspect.
   */
  private function getCurrency(array $orders): string {
    foreach ($orders as $order) {
      $total = $order->getTotalPrice();
      if ($total !== NULL) {
        return $total->getCurrencyCode();
      }
    }

    return 'EUR';
  }

  /**
   * Formats an amount with the shop currency.
   */
  private function formatMoney(float $amount, string $currency): string {
    return $this->currencyFormatter->format((string) round($amount, 2), $currency);
  }

}
