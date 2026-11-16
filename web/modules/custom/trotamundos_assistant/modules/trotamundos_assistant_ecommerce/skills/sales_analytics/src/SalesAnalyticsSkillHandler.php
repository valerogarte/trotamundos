<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_ecommerce\SkillLocal\SalesAnalytics;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Builds the sales analytics dashboard shown to shop administrators.
 *
 * The model only picks the reporting period; every figure comes from
 * SalesStatsBuilder.
 */
final class SalesAnalyticsSkillHandler extends AbstractSkillHandler {

  /**
   * Reporting periods the model is allowed to ask for.
   */
  private const ALLOWED_RANGES = ['month', 'quarter', 'year', 'all', 'custom'];

  /**
   * Sections rendered for each focus the model can ask for.
   *
   * The KPI tiles are always shown: they are the headline answer whatever the
   * question was.
   */
  private const FOCUS_SECTIONS = [
    'overview' => ['monthly', 'categories', 'stores', 'products', 'orders'],
    'revenue' => ['monthly'],
    'categories' => ['categories'],
    'products' => ['products'],
    'stores' => ['stores'],
    'orders' => ['orders'],
  ];

  /**
   * {@inheritdoc}
   */
  public function buildVariables(SkillContext $context): array {
    $stats = $this->getStatsBuilder();

    if (!$stats->hasAccess()) {
      return [
        '#intro' => (string) $this->t('Esta información solo está disponible para el equipo de administración de la tienda.'),
        '#report' => [],
        '#denied' => TRUE,
      ];
    }

    $report = $stats->build(
      $this->getRange($context),
      $this->getDate($context, 'from'),
      $this->getDate($context, 'to'),
    );
    $focus = $this->getFocus($context);

    return [
      '#intro' => (string) ($context->result['intro'] ?? ''),
      '#report' => $report,
      '#focus' => $focus,
      '#sections' => array_fill_keys(self::FOCUS_SECTIONS[$focus], TRUE),
      '#denied' => FALSE,
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['commerce_order_list'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildChatReply(SkillContext $context): string {
    return $context->structuredChatReply() ?: $this->getFallbackChatReply($context);
  }

  /**
   * Reads the reporting period, rejecting anything outside the enum.
   */
  private function getRange(SkillContext $context): string {
    $range = strtolower(trim((string) ($context->result['range'] ?? '')));

    return in_array($range, self::ALLOWED_RANGES, TRUE) ? $range : 'year';
  }

  /**
   * Reads which part of the report the user asked about.
   */
  private function getFocus(SkillContext $context): string {
    $focus = strtolower(trim((string) ($context->result['focus'] ?? '')));

    return isset(self::FOCUS_SECTIONS[$focus]) ? $focus : 'overview';
  }

  /**
   * Reads a boundary date, keeping only a well formed Y-m-d value.
   */
  private function getDate(SkillContext $context, string $key): string {
    $value = trim((string) ($context->result[$key] ?? ''));

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
  }

  /**
   * Gets the aggregator.
   *
   * Skill handlers are built by SkillHandlerResolver with the runtime as their
   * only argument, so there is no constructor injection to hook into here.
   *
   * @see \Drupal\trotamundos_assistant\Skill\SkillHandlerResolver::resolve()
   */
  private function getStatsBuilder(): SalesStatsBuilder {
    return \Drupal::service('trotamundos_assistant_ecommerce.sales_stats_builder');
  }

}
