<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Usage;

use Drupal\Component\Uuid\Php;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\trotamundos_assistant\Config\TrotamundosAssistantDefaults;

/**
 * Records token usage and estimated cost for embedded assistant calls.
 */
final class UsageLogger {

  /**
   * Current frontend request context.
   *
   * @var array<string, mixed>|null
   */
  private ?array $context = NULL;

  /**
   * Whether the usage table has the iteration_number field.
   */
  private ?bool $hasIterationNumberField = NULL;

  /**
   * Whether the usage table has the provider_system_prompt field.
   */
  private ?bool $hasProviderSystemPromptField = NULL;

  /**
   * Constructs the usage logger.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TrotamundosAssistantDefaults $defaults,
  ) {
  }

  /**
   * Starts collecting provider calls for one assistant request.
   */
  public function beginCall(string $thread_id, string $assistant_id, string $request_text = ''): string {
    $call_id = (new Php())->generate();
    $this->context = [
      'call_id' => $call_id,
      'thread_id' => $thread_id,
      'iteration_number' => $this->getNextIterationNumber($thread_id),
      'assistant_id' => $assistant_id,
      'request_text' => $request_text,
      'usage_ids' => [],
    ];

    return $call_id;
  }

  /**
   * Stops collecting provider calls.
   */
  public function endCall(): array {
    $summary = $this->getCurrentCallSummary();
    $this->context = NULL;

    return $summary;
  }

  /**
   * Records one provider response inside the current assistant request.
   */
  public function recordProviderUsage(
    string $provider_request_id,
    string $provider_id,
    string $model_id,
    string $operation_type,
    TokenUsageDto $usage,
    string $provider_request_raw = '',
    string $provider_response_raw = '',
    string $provider_system_prompt = '',
  ): ?int {
    if (!$this->context) {
      return NULL;
    }

    $cost = $this->calculateCost($model_id, $usage);
    $fields = [
      'call_id' => $this->context['call_id'],
      'created' => $this->time->getRequestTime(),
      'uid' => (int) $this->currentUser->id(),
      'thread_id' => $this->context['thread_id'] ?: NULL,
      'assistant_id' => $this->context['assistant_id'] ?: NULL,
      'provider_request_id' => $provider_request_id,
      'provider_id' => $provider_id,
      'model_id' => $model_id,
      'operation_type' => $operation_type,
      'input_tokens' => $usage->input,
      'output_tokens' => $usage->output,
      'total_tokens' => $usage->total,
      'cached_tokens' => $usage->cached,
      'reasoning_tokens' => $usage->reasoning,
      'input_cost_usd' => $this->formatMoney($cost['input']),
      'output_cost_usd' => $this->formatMoney($cost['output']),
      'cached_cost_usd' => $this->formatMoney($cost['cached']),
      'total_cost_usd' => $this->formatMoney($cost['total']),
      'currency' => 'USD',
      'pricing_source' => $cost['pricing_source'],
      'request_text' => $this->context['request_text'] ?: NULL,
      'response_text' => NULL,
      'provider_request_raw' => $provider_request_raw ?: NULL,
      'provider_response_raw' => $provider_response_raw ?: NULL,
    ];

    if ($this->hasProviderSystemPromptField()) {
      $fields['provider_system_prompt'] = $provider_system_prompt ?: NULL;
    }

    if ($this->hasIterationNumberField()) {
      $fields['iteration_number'] = (int) ($this->context['iteration_number'] ?? 0);
    }

    $id = (int) $this->database->insert('trotamundos_assistant_usage')
      ->fields($fields)
      ->execute();

    $this->context['usage_ids'][] = $id;

    return $id;
  }

  /**
   * Stores the full assistant response for all rows in the current call.
   */
  public function storeResponseText(string $response_text): void {
    if (!$this->context || empty($this->context['call_id'])) {
      return;
    }

    $this->database->update('trotamundos_assistant_usage')
      ->fields([
        'response_text' => $response_text,
      ])
      ->condition('call_id', (string) $this->context['call_id'])
      ->execute();
  }

  /**
   * Updates the stored raw provider response for a streamed request.
   */
  public function updateProviderResponseRaw(string $provider_request_id, string $provider_response_raw): void {
    if (!$this->context || empty($this->context['call_id']) || $provider_response_raw === '') {
      return;
    }

    $this->database->update('trotamundos_assistant_usage')
      ->fields([
        'provider_response_raw' => $provider_response_raw,
      ])
      ->condition('call_id', (string) $this->context['call_id'])
      ->condition('provider_request_id', $provider_request_id)
      ->execute();
  }

  /**
   * Returns the current call summary.
   *
   * @return array<string, mixed>
   *   Aggregated usage and cost.
   */
  public function getCurrentCallSummary(): array {
    if (!$this->context) {
      return $this->emptySummary();
    }

    return $this->getCallSummary((string) $this->context['call_id']);
  }

  /**
   * Returns an aggregate summary for a stored call id.
   */
  public function getCallSummary(string $call_id): array {
    $rows = $this->database->select('trotamundos_assistant_usage', 'u')
      ->fields('u', [
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'cached_tokens',
        'reasoning_tokens',
        'total_cost_usd',
      ])
      ->condition('call_id', $call_id)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $summary = $this->emptySummary();
    $summary['call_id'] = $call_id;
    $summary['provider_calls'] = count($rows);

    foreach ($rows as $row) {
      $summary['input_tokens'] += (int) ($row['input_tokens'] ?? 0);
      $summary['output_tokens'] += (int) ($row['output_tokens'] ?? 0);
      $summary['total_tokens'] += (int) ($row['total_tokens'] ?? 0);
      $summary['cached_tokens'] += (int) ($row['cached_tokens'] ?? 0);
      $summary['reasoning_tokens'] += (int) ($row['reasoning_tokens'] ?? 0);
      $summary['total_cost_usd'] += (float) ($row['total_cost_usd'] ?? 0);
    }

    $summary['total_cost_usd'] = $this->formatMoney($summary['total_cost_usd']);

    return $summary;
  }

  /**
   * Gets the next iteration number for a thread.
   */
  private function getNextIterationNumber(string $thread_id): int {
    $thread_id = trim($thread_id);
    if ($thread_id === '' || !$this->hasIterationNumberField()) {
      return 1;
    }

    $max = $this->database->select('trotamundos_assistant_usage', 'u')
      ->condition('thread_id', $thread_id)
      ->condition('iteration_number', 0, '>')
      ->range(0, 1);
    $max->addExpression('MAX(iteration_number)', 'max_iteration');

    return ((int) $max->execute()->fetchField()) + 1;
  }

  /**
   * Checks whether the usage table has the iteration field.
   */
  private function hasIterationNumberField(): bool {
    return $this->hasIterationNumberField ??= $this->database
      ->schema()
      ->fieldExists('trotamundos_assistant_usage', 'iteration_number');
  }

  /**
   * Checks whether the optional system-prompt audit field is available.
   *
   * This keeps provider calls working between deploying the code and running
   * the database update.
   */
  private function hasProviderSystemPromptField(): bool {
    return $this->hasProviderSystemPromptField ??= $this->database
      ->schema()
      ->fieldExists('trotamundos_assistant_usage', 'provider_system_prompt');
  }

  /**
   * Calculates estimated USD cost for a model and token usage.
   *
   * @return array<string, float|string>
   *   Cost split.
   */
  private function calculateCost(string $model_id, TokenUsageDto $usage): array {
    $prices = $this->getConfiguredModelPrices($model_id);
    $pricing_source = $this->getPricingSource();
    if ($prices === NULL) {
      return [
        'input' => 0.0,
        'cached' => 0.0,
        'output' => 0.0,
        'total' => 0.0,
        'pricing_source' => sprintf('No pricing configured for model "%s".', $model_id),
      ];
    }

    $cached_tokens = $usage->cached ?? 0;
    $input_tokens = max(0, ($usage->input ?? 0) - $cached_tokens);
    $output_tokens = $usage->output ?? 0;

    $input_cost = ($input_tokens / 1000000) * $prices['input'];
    $cached_cost = ($cached_tokens / 1000000) * $prices['cached'];
    $output_cost = ($output_tokens / 1000000) * $prices['output'];

    return [
      'input' => $input_cost,
      'cached' => $cached_cost,
      'output' => $output_cost,
      'total' => $input_cost + $cached_cost + $output_cost,
      'pricing_source' => $pricing_source,
    ];
  }

  /**
   * Gets configured prices for one model.
   *
   * @return array<string, float>|null
   *   Model prices or NULL when missing.
   */
  private function getConfiguredModelPrices(string $model_id): ?array {
    $pricing = $this->configFactory
      ->get('trotamundos_assistant.settings')
      ->get('model_pricing');

    if (!is_array($pricing)) {
      return NULL;
    }

    foreach ($pricing as $row) {
      if (!is_array($row) || (string) ($row['model_id'] ?? '') !== $model_id) {
        continue;
      }

      return [
        'input' => (float) ($row['input'] ?? 0),
        'cached' => (float) ($row['cached'] ?? 0),
        'output' => (float) ($row['output'] ?? 0),
      ];
    }

    return NULL;
  }

  /**
   * Gets the configured pricing source label.
   */
  private function getPricingSource(): string {
    $timestamp = (int) ($this->configFactory
      ->get('trotamundos_assistant.settings')
      ->get('pricing_last_saved') ?: $this->defaults->getPricingLastSaved());

    return sprintf('Model prices last reviewed on %s.', date('Y-m-d', $timestamp));
  }

  /**
   * Formats costs for numeric database fields and API output.
   */
  private function formatMoney(int|float|string|null $value): string {
    return number_format((float) ($value ?? 0), 10, '.', '');
  }

  /**
   * Empty usage summary.
   *
   * @return array<string, mixed>
   *   Empty summary.
   */
  private function emptySummary(): array {
    return [
      'call_id' => NULL,
      'provider_calls' => 0,
      'input_tokens' => 0,
      'output_tokens' => 0,
      'total_tokens' => 0,
      'cached_tokens' => 0,
      'reasoning_tokens' => 0,
      'total_cost_usd' => 0.0,
      'currency' => 'USD',
    ];
  }

}
