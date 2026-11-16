<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Usage;

use Drupal\Component\Utility\Html;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Builds SVG charts for Trotamundos Assistant usage pages.
 */
final class UsageChartBuilder {

  use StringTranslationTrait;

  /**
   * Constructs the usage chart builder.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds the daily usage chart with dates, cost, and provider calls.
   *
   * @param array<int, array<string, mixed>> $daily_rows
   *   Rows grouped by day.
   */
  public function buildDailyUsageChart(array $daily_rows, string $currency = 'USD'): string {
    $width = 1040;
    $height = 320;
    $left = 112;
    $right = 118;
    $top = 42;
    $bottom = 58;
    $plot_width = $width - $left - $right;
    $plot_height = $height - $top - $bottom;
    $count = count($daily_rows);
    $max_cost = 0.0;
    $max_calls = 0;

    foreach ($daily_rows as $row) {
      $max_cost = max($max_cost, (float) ($row['total_cost'] ?? 0));
      $max_calls = max($max_calls, (int) ($row['provider_calls'] ?? 0));
    }

    $max_cost = $max_cost > 0 ? $max_cost : 1.0;
    $max_calls = $max_calls > 0 ? $max_calls : 1;
    $bar_width = $count > 0 ? max(10, min(30, ($plot_width / max(1, $count)) * 0.2)) : 10;
    $slot_width = $count > 0 ? $plot_width / $count : $plot_width;
    $call_points = [];
    $bars = [];
    $x_labels = [];

    foreach (array_values($daily_rows) as $index => $row) {
      $x = $left + ($slot_width * $index) + ($slot_width / 2);
      $cost = (float) ($row['total_cost'] ?? 0);
      $calls = (int) ($row['provider_calls'] ?? 0);
      $bar_height = max($cost > 0 ? 3 : 0, ($cost / $max_cost) * $plot_height);
      $bar_x = $x - ($bar_width / 2);
      $bar_y = $top + $plot_height - $bar_height;
      $point_y = $top + $plot_height - (($calls / $max_calls) * $plot_height);
      $date = (string) ($row['usage_day'] ?? '');
      $label = $this->formatSpanishChartDate($date);
      $cost_tooltip = $label . ' · ' . $this->t('Coste: @currency @cost · Llamadas: @calls', [
        '@currency' => $this->formatChartCurrency($currency),
        '@cost' => $this->formatChartNumber($cost, 10),
        '@calls' => $this->formatChartNumber($calls),
      ]);
      $calls_label = $calls === 1
        ? $this->t('1 llamada al proveedor')
        : $this->t('@count llamadas al proveedor', ['@count' => $this->formatChartNumber($calls)]);
      $calls_tooltip = $label . ' · ' . $calls_label
        . ' · ' . $this->t('Coste: @currency @cost', [
          '@currency' => $this->formatChartCurrency($currency),
          '@cost' => $this->formatChartNumber($cost, 10),
        ]);

      $call_points[] = round($x, 2) . ',' . round($point_y, 2);
      $bars[] = '<rect class="trotamundos-assistant-usage-chart__bar" x="' . round($bar_x, 2) . '" y="' . round($bar_y, 2) . '" width="' . round($bar_width, 2) . '" height="' . round($bar_height, 2) . '" rx="9" tabindex="0" role="img" aria-label="' . Html::escape($cost_tooltip) . '" data-trotamundos-assistant-chart-tooltip="' . Html::escape($cost_tooltip) . '"></rect>';
      $bars[] = '<circle class="trotamundos-assistant-usage-chart__point" cx="' . round($x, 2) . '" cy="' . round($point_y, 2) . '" r="3.8" tabindex="0" role="img" aria-label="' . Html::escape($calls_tooltip) . '" data-trotamundos-assistant-chart-tooltip="' . Html::escape($calls_tooltip) . '"></circle>';
      $x_labels[] = '<text class="trotamundos-assistant-usage-chart__label" x="' . round($x, 2) . '" y="' . ($top + $plot_height + 24) . '" text-anchor="middle">' . Html::escape($label) . '</text>';
    }

    $grid = [];
    $cost_labels = [];
    $call_labels = [];
    for ($tick = 0; $tick <= 4; $tick++) {
      $ratio = $tick / 4;
      $y = $top + $plot_height - ($plot_height * $ratio);
      $grid[] = '<line class="trotamundos-assistant-usage-chart__grid" x1="' . $left . '" y1="' . round($y, 2) . '" x2="' . ($width - $right) . '" y2="' . round($y, 2) . '" />';
      $cost_labels[] = '<text class="trotamundos-assistant-usage-chart__axis-label" x="' . ($left - 16) . '" y="' . round($y + 3, 2) . '" text-anchor="end">' . Html::escape($this->formatChartCurrency($currency) . ' ' . $this->formatChartNumber($max_cost * $ratio, 3)) . '</text>';
      $call_labels[] = '<text class="trotamundos-assistant-usage-chart__axis-label" x="' . ($width - $right + 16) . '" y="' . round($y + 3, 2) . '">' . Html::escape($this->formatChartNumber((int) round($max_calls * $ratio))) . '</text>';
    }

    return '<div class="trotamundos-assistant-usage-chart">'
      . '<div class="trotamundos-assistant-usage-chart__legend">'
      . '<span><i class="trotamundos-assistant-usage-chart__legend-cost"></i>' . $this->t('Coste') . '</span>'
      . '<span><i class="trotamundos-assistant-usage-chart__legend-calls"></i>' . $this->t('Llamadas') . '</span>'
      . '</div>'
      . '<svg class="trotamundos-assistant-usage-chart__svg" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . Html::escape((string) $this->t('Coste y llamadas por fecha')) . '">'
      . '<text class="trotamundos-assistant-usage-chart__axis-title" x="' . $left . '" y="' . ($top - 14) . '">' . $this->t('Coste') . '</text>'
      . '<text class="trotamundos-assistant-usage-chart__axis-title" x="' . ($width - $right) . '" y="' . ($top - 14) . '" text-anchor="end">' . $this->t('Llamadas') . '</text>'
      . implode('', $grid)
      . '<line class="trotamundos-assistant-usage-chart__axis" x1="' . $left . '" y1="' . ($top + $plot_height) . '" x2="' . ($width - $right) . '" y2="' . ($top + $plot_height) . '" />'
      . implode('', $cost_labels)
      . implode('', $call_labels)
      . implode('', $bars)
      . '<polyline class="trotamundos-assistant-usage-chart__line" points="' . Html::escape(implode(' ', $call_points)) . '" />'
      . implode('', $x_labels)
      . '<text class="trotamundos-assistant-usage-chart__x-title" x="' . ($left + ($plot_width / 2)) . '" y="' . ($height - 10) . '" text-anchor="middle">' . $this->t('Fecha') . '</text>'
      . '</svg>'
      . '</div>';
  }

  /**
   * Builds a radar chart with the most used assistant skills.
   *
   * @param array<int, array{label: string, value: int, machine_name: string}> $rows
   *   Skill rows ordered by usage.
   */
  public function buildSkillUsageRadarChart(array $rows): string {
    $rows = array_slice(array_values($rows), 0, 8);
    $count = count($rows);
    if ($count === 0) {
      return '';
    }

    $width = 920;
    $height = 620;
    $center_x = 460;
    $center_y = 305;
    $radius = 180;
    $rings = 5;
    $max_value = max(1, ...array_map(static fn(array $row): int => (int) $row['value'], $rows));
    $grid = [];
    $axes = [];
    $labels = [];
    $points = [];
    $markers = [];

    for ($ring = 1; $ring <= $rings; $ring++) {
      $ring_radius = $radius * ($ring / $rings);
      $ring_points = [];
      for ($index = 0; $index < $count; $index++) {
        [$x, $y] = $this->getRadarPoint($center_x, $center_y, $ring_radius, $index, $count);
        $ring_points[] = round($x, 2) . ',' . round($y, 2);
      }
      $grid[] = '<polygon class="trotamundos-assistant-statistics-radar__grid-ring" points="' . Html::escape(implode(' ', $ring_points)) . '" />';
    }

    foreach ($rows as $index => $row) {
      [$axis_x, $axis_y] = $this->getRadarPoint($center_x, $center_y, $radius, $index, $count);
      [$label_x, $label_y] = $this->getRadarPoint($center_x, $center_y, $radius + 102, $index, $count);
      [$point_x, $point_y] = $this->getRadarPoint(
        $center_x,
        $center_y,
        $radius * ((int) $row['value'] / $max_value),
        $index,
        $count,
      );
      $anchor = $this->getRadarTextAnchor($label_x, $center_x);
      $tooltip = (string) $this->t('@skill: @count usos', [
        '@skill' => $row['label'],
        '@count' => $this->formatChartNumber((int) $row['value']),
      ]);

      $axes[] = '<line class="trotamundos-assistant-statistics-radar__axis" x1="' . $center_x . '" y1="' . $center_y . '" x2="' . round($axis_x, 2) . '" y2="' . round($axis_y, 2) . '" />';
      $labels[] = $this->buildRadarLabel((string) $row['label'], $label_x, $label_y, $anchor);
      $points[] = round($point_x, 2) . ',' . round($point_y, 2);
      $markers[] = '<circle class="trotamundos-assistant-statistics-radar__point" cx="' . round($point_x, 2) . '" cy="' . round($point_y, 2) . '" r="5" tabindex="0" role="img" aria-label="' . Html::escape($tooltip) . '" data-trotamundos-assistant-chart-tooltip="' . Html::escape($tooltip) . '"></circle>';
    }

    return '<div class="trotamundos-assistant-statistics-radar">'
      . '<svg class="trotamundos-assistant-statistics-radar__svg" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . Html::escape((string) $this->t('Skills más utilizadas')) . '">'
      . implode('', $grid)
      . implode('', $axes)
      . '<polygon class="trotamundos-assistant-statistics-radar__area" points="' . Html::escape(implode(' ', $points)) . '" />'
      . '<polyline class="trotamundos-assistant-statistics-radar__line" points="' . Html::escape(implode(' ', $points)) . ' ' . Html::escape($points[0] ?? '') . '" />'
      . implode('', $markers)
      . implode('', $labels)
      . '</svg>'
      . '</div>';
  }

  /**
   * Formats an ISO date as a compact Spanish chart label.
   */
  private function formatSpanishChartDate(string $date): string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
      return $date;
    }

    $months = [
      '01' => 'Ene',
      '02' => 'Feb',
      '03' => 'Mar',
      '04' => 'Abr',
      '05' => 'May',
      '06' => 'Jun',
      '07' => 'Jul',
      '08' => 'Ago',
      '09' => 'Sep',
      '10' => 'Oct',
      '11' => 'Nov',
      '12' => 'Dic',
    ];

    return ltrim($matches[3], '0') . '-' . ($months[$matches[2]] ?? $matches[2]);
  }

  /**
   * Formats a number for chart labels and tooltips.
   */
  private function formatChartNumber(float|int $value, int $decimals = 0): string {
    return number_format($value, $decimals, ',', '.');
  }

  /**
   * Formats a currency code for chart labels.
   */
  private function formatChartCurrency(string $currency): string {
    return strtoupper($currency) === 'EUR' ? '€' : 'USD';
  }

  /**
   * Gets a point in the radar coordinate system.
   *
   * @return array{0: float, 1: float}
   *   X and Y coordinates.
   */
  private function getRadarPoint(float $center_x, float $center_y, float $radius, int $index, int $count): array {
    $angle = (-M_PI / 2) + ((2 * M_PI * $index) / max(1, $count));

    return [
      $center_x + ($radius * cos($angle)),
      $center_y + ($radius * sin($angle)),
    ];
  }

  /**
   * Gets the best SVG text anchor for a radar label.
   */
  private function getRadarTextAnchor(float $label_x, float $center_x): string {
    if (abs($label_x - $center_x) < 16) {
      return 'middle';
    }

    return $label_x > $center_x ? 'start' : 'end';
  }

  /**
   * Builds a wrapped SVG text label for a radar axis.
   */
  private function buildRadarLabel(string $label, float $x, float $y, string $anchor): string {
    $lines = $this->wrapRadarLabel($label);
    $line_count = count($lines);
    $start_y = $y - (($line_count - 1) * 10);
    $tspans = [];

    foreach ($lines as $index => $line) {
      $dy = $index === 0 ? 0 : 20;
      $tspans[] = '<tspan x="' . round($x, 2) . '" dy="' . $dy . '">' . Html::escape($line) . '</tspan>';
    }

    return '<text class="trotamundos-assistant-statistics-radar__label" x="' . round($x, 2) . '" y="' . round($start_y, 2) . '" text-anchor="' . $anchor . '">' . implode('', $tspans) . '</text>';
  }

  /**
   * Wraps a radar label into short readable lines.
   *
   * @return array<int, string>
   *   Wrapped label lines.
   */
  private function wrapRadarLabel(string $label): array {
    $words = preg_split('/\s+/', trim($label)) ?: [];
    $lines = [];
    $current = '';

    foreach ($words as $word) {
      $candidate = $current === '' ? $word : $current . ' ' . $word;
      if (mb_strlen($candidate) > 18 && $current !== '') {
        $lines[] = $current;
        $current = $word;
        continue;
      }
      $current = $candidate;
    }

    if ($current !== '') {
      $lines[] = $current;
    }

    return $lines ?: [$label];
  }

}
