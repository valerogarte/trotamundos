<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_ecommerce\SkillLocal\SalesAnalytics;

use Drupal\Core\Session\AccountInterface;
use Drupal\trotamundos_assistant\Skill\Action\SkillActionHandlerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves refreshed sales figures when the dashboard filters change.
 *
 * Changing a filter must not cost a model call, so the panel asks this
 * endpoint directly and redraws the charts with the response.
 */
final class SalesAnalyticsActionHandler implements SkillActionHandlerInterface {

  /**
   * Reporting periods this endpoint accepts.
   */
  private const ALLOWED_RANGES = ['month', 'quarter', 'year', 'all', 'custom'];

  public function __construct(
    private readonly SalesStatsBuilder $statsBuilder,
  ) {
  }

  /**
   * Returns the report for the requested period as JSON.
   */
  public function report(Request $request, AccountInterface $account): JsonResponse {
    if (!$this->statsBuilder->hasAccess()) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }

    $range = strtolower(trim((string) $request->query->get('range', 'year')));
    if (!in_array($range, self::ALLOWED_RANGES, TRUE)) {
      $range = 'year';
    }

    return new JsonResponse([
      'report' => $this->statsBuilder->build(
        $range,
        $this->readDate($request, 'from'),
        $this->readDate($request, 'to'),
      ),
    ]);
  }

  /**
   * Reads a boundary date, keeping only a well formed Y-m-d value.
   */
  private function readDate(Request $request, string $key): string {
    $value = trim((string) $request->query->get($key, ''));

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
  }

}
