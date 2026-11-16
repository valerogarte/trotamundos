<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Usage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;

/**
 * Reads Trotamundos Assistant usage information from storage.
 */
final class UsageRepository {

  /**
   * Constructs the usage repository.
   */
  public function __construct(
    private readonly Connection $database,
  ) {
  }

  /**
   * Fetches aggregate usage summary.
   *
   * @return array<string, mixed>
   *   Aggregate values keyed by alias.
   */
  public function fetchSummary(?int $uid = NULL): array {
    $query = $this->database->select('trotamundos_assistant_usage', 'u');
    if ($uid !== NULL) {
      $query->condition('uid', $uid);
    }
    $query->addExpression('COUNT(*)', 'provider_calls');
    $query->addExpression('COUNT(DISTINCT call_id)', 'total_iterations');
    $query->addExpression('SUM(total_cost_usd)', 'total_cost');
    $query->addExpression('SUM(total_tokens)', 'total_tokens');
    $query->addExpression('SUM(input_tokens)', 'input_tokens');
    $query->addExpression('SUM(output_tokens)', 'output_tokens');

    return $query->execute()->fetchAssoc() ?: [];
  }

  /**
   * Counts conversations available for the usage overview.
   */
  public function countConversations(?int $uid = NULL): int {
    $query = $this->database->select('trotamundos_assistant_usage', 'u');
    $this->applyUsageFilter($query, $uid);
    $query->addExpression('COUNT(DISTINCT ' . $this->getConversationExpression() . ')', 'conversation_count');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Fetches paginated conversation summaries for the usage overview.
   *
   * @return array<int, array<string, mixed>>
   *   Conversation summaries.
   */
  public function fetchConversationSummaries(?int $uid = NULL, int $limit = 25, int $offset = 0, string $sort = 'last_iteration_created', string $direction = 'DESC'): array {
    $conversation_expression = $this->getConversationExpression();
    $sort_fields = [
      'created' => 'created',
      'last_iteration_created' => 'last_iteration_created',
      'uid' => 'uid',
      'thread_id' => 'thread_id',
      'models' => 'models',
      'iteration_count' => 'iteration_count',
      'provider_calls' => 'provider_calls',
      'total_tokens' => 'total_tokens',
      'total_cost_usd' => 'total_cost_usd',
    ];
    $sort = $sort_fields[$sort] ?? 'last_iteration_created';
    $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
    $query = $this->database->select('trotamundos_assistant_usage', 'u');
    $this->applyUsageFilter($query, $uid);
    $query->addExpression($conversation_expression, 'conversation_id');
    $query->addExpression('MIN(created)', 'created');
    $query->addExpression('MAX(created)', 'last_iteration_created');
    $query->addExpression('MIN(uid)', 'uid');
    $query->addExpression('MAX(thread_id)', 'thread_id');
    $query->addExpression('COUNT(DISTINCT call_id)', 'iteration_count');
    $query->addExpression('COUNT(*)', 'provider_calls');
    $query->addExpression('SUM(input_tokens)', 'input_tokens');
    $query->addExpression('SUM(output_tokens)', 'output_tokens');
    $query->addExpression('SUM(total_tokens)', 'total_tokens');
    $query->addExpression('SUM(cached_tokens)', 'cached_tokens');
    $query->addExpression('SUM(total_cost_usd)', 'total_cost_usd');
    $query->addExpression('MAX(currency)', 'currency');
    $query->addExpression("GROUP_CONCAT(DISTINCT model_id ORDER BY model_id SEPARATOR ', ')", 'models');

    return $query
      ->groupBy('conversation_id')
      ->orderBy($sort, $direction)
      ->orderBy('last_iteration_created', 'DESC')
      ->range($offset, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Fetches daily usage aggregates.
   *
   * @return array<int, array<string, mixed>>
   *   Daily rows ordered chronologically.
   */
  public function fetchDailyRows(?int $uid = NULL, int $limit = 14): array {
    $query = $this->database->select('trotamundos_assistant_usage', 'u')
      ->condition('currency', 'USD');
    if ($uid !== NULL) {
      $query->condition('uid', $uid);
    }
    $query->addExpression('DATE(FROM_UNIXTIME(created))', 'usage_day');
    $query->addExpression('COUNT(*)', 'provider_calls');
    $query->addExpression('SUM(total_cost_usd)', 'total_cost');
    $query->addExpression('SUM(total_tokens)', 'total_tokens');
    $rows = $query
      ->groupBy('usage_day')
      ->orderBy('usage_day', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    return array_reverse($rows);
  }

  /**
   * Fetches usage aggregates grouped by provider and model.
   *
   * @return array<int, array<string, mixed>>
   *   Model aggregate rows.
   */
  public function fetchModelRows(?int $uid = NULL): array {
    $query = $this->database->select('trotamundos_assistant_usage', 'u')
      ->condition('currency', 'USD');
    if ($uid !== NULL) {
      $query->condition('uid', $uid);
    }
    $query->fields('u', ['provider_id', 'model_id']);
    $query->addExpression('COUNT(*)', 'provider_calls');
    $query->addExpression('SUM(input_tokens)', 'input_tokens');
    $query->addExpression('SUM(output_tokens)', 'output_tokens');
    $query->addExpression('SUM(total_tokens)', 'total_tokens');
    $query->addExpression('SUM(total_cost_usd)', 'total_cost');

    return $query
      ->groupBy('provider_id')
      ->groupBy('model_id')
      ->orderBy('total_cost', 'DESC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Fetches assistant skill usage aggregated by selected skill.
   *
   * @return array<int, array{machine_name: string, usage_count: int}>
   *   Skill usage rows ordered by most used first.
   */
  public function fetchSkillUsageRows(?int $uid = NULL, array $valid_skill_ids = []): array {
    $valid_skill_ids = array_fill_keys(array_values(array_map('strval', $valid_skill_ids)), TRUE);
    $query = $this->database->select('trotamundos_assistant_usage', 'u')
      ->fields('u', ['id', 'call_id', 'response_text'])
      ->condition('response_text', '', '<>');
    if ($uid !== NULL) {
      $query->condition('uid', $uid);
    }
    $query->orderBy('created', 'DESC');
    $query->orderBy('id', 'DESC');

    $counts = [];
    $seen_calls = [];
    foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      $call_id = (string) ($row['call_id'] ?? '');
      if ($call_id === '' || isset($seen_calls[$call_id])) {
        continue;
      }

      $machine_name = $this->extractSkillMachineName((string) ($row['response_text'] ?? ''));
      if ($machine_name === '') {
        continue;
      }

      $seen_calls[$call_id] = TRUE;
      if ($valid_skill_ids !== [] && !isset($valid_skill_ids[$machine_name])) {
        continue;
      }

      $counts[$machine_name] = ($counts[$machine_name] ?? 0) + 1;
    }

    arsort($counts, SORT_NUMERIC);
    $rows = [];
    foreach ($counts as $machine_name => $usage_count) {
      $rows[] = [
        'machine_name' => $machine_name,
        'usage_count' => (int) $usage_count,
      ];
    }

    return $rows;
  }

  /**
   * Fetches rows for a conversation, call, or provider request detail.
   *
   * @return array<int, array<string, mixed>>
   *   Matching usage rows ordered chronologically.
   */
  public function fetchDetailRows(string $lookup_id, bool $is_call_detail, bool $is_provider_call_detail): array {
    $query = $this->database->select('trotamundos_assistant_usage', 'u')
      ->fields('u', $this->getDetailFields());
    if ($is_provider_call_detail) {
      $query->condition('id', (int) $lookup_id);
    }
    else {
      $query->condition($is_call_detail ? 'call_id' : 'thread_id', $lookup_id);
    }

    return $query
      ->orderBy('created', 'ASC')
      ->orderBy('id', 'ASC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Fetches user ids with recorded usage.
   *
   * @return array<int, mixed>
   *   User ids returned by the database.
   */
  public function fetchUsageUserIds(): array {
    return $this->database->select('trotamundos_assistant_usage', 'u')
      ->distinct()
      ->fields('u', ['uid'])
      ->orderBy('uid')
      ->execute()
      ->fetchCol();
  }

  /**
   * Applies common filters for usage overview queries.
   */
  private function applyUsageFilter(SelectInterface $query, ?int $uid = NULL): void {
    $query->condition('call_id', '', '<>');
    if ($uid !== NULL) {
      $query->condition('uid', $uid);
    }
  }

  /**
   * Gets the SQL expression used as a stable conversation identifier.
   */
  private function getConversationExpression(): string {
    return "COALESCE(NULLIF(thread_id, ''), CONCAT('call:', call_id))";
  }

  /**
   * Extracts the selected skill from a stored assistant structured response.
   */
  private function extractSkillMachineName(string $response_text): string {
    $text = trim($response_text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text) ?? $text;
    $decoded = json_decode($text, TRUE);
    if (!is_array($decoded)) {
      return '';
    }

    $selected = (string) ($decoded['skill'] ?? $decoded['machine_name'] ?? '');
    $result = is_array($decoded['result'] ?? NULL) ? $decoded['result'] : [];
    if ($selected === '' && isset($result['skill'])) {
      $selected = (string) $result['skill'];
    }

    $selected = mb_strtolower(trim($selected));
    $selected = preg_replace('/[^a-z0-9_]+/', '_', $selected) ?? '';

    return trim($selected, '_');
  }

  /**
   * Gets fields used by the detail pages.
   *
   * @return array<int, string>
   *   Usage table fields.
   */
  private function getDetailFields(): array {
    $fields = [
      'id',
      'call_id',
      'created',
      'uid',
      'thread_id',
      'assistant_id',
      'provider_request_id',
      'provider_id',
      'model_id',
      'operation_type',
      'input_tokens',
      'output_tokens',
      'total_tokens',
      'cached_tokens',
      'reasoning_tokens',
      'input_cost_usd',
      'output_cost_usd',
      'cached_cost_usd',
      'total_cost_usd',
      'currency',
      'pricing_source',
      'request_text',
      'response_text',
      'provider_request_raw',
      'provider_response_raw',
    ];
    if ($this->database->schema()->fieldExists('trotamundos_assistant_usage', 'provider_system_prompt')) {
      $fields[] = 'provider_system_prompt';
    }
    if ($this->database->schema()->fieldExists('trotamundos_assistant_usage', 'iteration_number')) {
      $fields[] = 'iteration_number';
    }

    return $fields;
  }

}
