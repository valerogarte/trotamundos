<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Usage;

/**
 * Builds normalized usage report structures from raw usage rows.
 */
final class UsageReportBuilder {

  /**
   * Builds overview statistics from an aggregate database row.
   *
   * @param array<string, mixed> $summary
   *   Aggregate database row.
   *
   * @return array<string, float|int>
   *   Normalized overview statistics.
   */
  public function buildOverviewStats(array $summary): array {
    $provider_calls = (int) ($summary['provider_calls'] ?? 0);
    $total_cost = (float) ($summary['total_cost'] ?? 0);

    return [
      'provider_calls' => $provider_calls,
      'total_iterations' => (int) ($summary['total_iterations'] ?? 0),
      'total_cost' => $total_cost,
      'total_tokens' => (int) ($summary['total_tokens'] ?? 0),
      'input_tokens' => (int) ($summary['input_tokens'] ?? 0),
      'output_tokens' => (int) ($summary['output_tokens'] ?? 0),
      'average_cost' => $provider_calls > 0 ? $total_cost / $provider_calls : 0,
    ];
  }

  /**
   * Builds a conversation detail report from raw provider rows.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Raw detail rows.
   *
   * @return array<string, mixed>
   *   Summary, identifiers and turns for the conversation.
   */
  public function buildConversationDetail(array $rows): array {
    $summary = [
      'provider_calls' => count($rows),
      'input_tokens' => 0,
      'output_tokens' => 0,
      'total_tokens' => 0,
      'cached_tokens' => 0,
      'reasoning_tokens' => 0,
      'total_cost_usd' => 0.0,
    ];
    $provider_ids = [];
    $model_ids = [];
    $call_ids = [];
    $turns = [];

    foreach ($rows as $row) {
      $call_id = (string) ($row['call_id'] ?? '');
      if ($call_id !== '') {
        $call_ids[$call_id] = $call_id;
        if (!isset($turns[$call_id])) {
          $iteration_number = (int) ($row['iteration_number'] ?? 0);
          $turns[$call_id] = [
            'call_id' => $call_id,
            'iteration_number' => $iteration_number > 0 ? $iteration_number : count($turns) + 1,
            'created' => (int) $row['created'],
            'request_text' => (string) ($row['request_text'] ?? ''),
            'response_text' => (string) ($row['response_text'] ?? ''),
            'provider_calls' => 0,
            'provider_rows' => [],
            'total_tokens' => 0,
            'total_cost_usd' => 0.0,
            'currency' => (string) ($row['currency'] ?? 'USD'),
          ];
        }
        $turns[$call_id]['created'] = min($turns[$call_id]['created'], (int) $row['created']);
        $turns[$call_id]['provider_calls']++;
        $turns[$call_id]['provider_rows'][] = $row;
        $turns[$call_id]['total_tokens'] += (int) ($row['total_tokens'] ?? 0);
        $turns[$call_id]['total_cost_usd'] += (float) ($row['total_cost_usd'] ?? 0);
        if ($turns[$call_id]['request_text'] === '' && !empty($row['request_text'])) {
          $turns[$call_id]['request_text'] = (string) $row['request_text'];
        }
        if ($turns[$call_id]['response_text'] === '' && !empty($row['response_text'])) {
          $turns[$call_id]['response_text'] = (string) $row['response_text'];
        }
      }

      if (!empty($row['provider_id'])) {
        $provider_ids[(string) $row['provider_id']] = (string) $row['provider_id'];
      }
      if (!empty($row['model_id'])) {
        $model_ids[(string) $row['model_id']] = (string) $row['model_id'];
      }

      $summary['input_tokens'] += (int) ($row['input_tokens'] ?? 0);
      $summary['output_tokens'] += (int) ($row['output_tokens'] ?? 0);
      $summary['total_tokens'] += (int) ($row['total_tokens'] ?? 0);
      $summary['cached_tokens'] += (int) ($row['cached_tokens'] ?? 0);
      $summary['reasoning_tokens'] += (int) ($row['reasoning_tokens'] ?? 0);
      $summary['total_cost_usd'] += (float) ($row['total_cost_usd'] ?? 0);
    }

    uasort($turns, static function (array $left, array $right): int {
      return [$left['iteration_number'], $left['created']] <=> [$right['iteration_number'], $right['created']];
    });

    foreach ($turns as &$turn) {
      $turn['provider_rows'] = $this->annotateProviderRows($turn['provider_rows']);
    }
    unset($turn);

    return [
      'summary' => $summary,
      'provider_ids' => $provider_ids,
      'model_ids' => $model_ids,
      'call_ids' => $call_ids,
      'turns' => array_values($turns),
    ];
  }

  /**
   * Adds display-oriented execution phases to the provider rows of one turn.
   *
   * New streaming audit records contain the selected tool directly. Older
   * records may only preserve it in the next chat request, where the agent
   * sends its assistant tool call and the tool result back to the provider.
   * That reconstruction is deliberately marked as inferred in the output.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Provider rows in chronological order.
   *
   * @return array<int, array<string, mixed>>
   *   Rows enriched with execution metadata.
   */
  private function annotateProviderRows(array $rows): array {
    $last_chat_index = NULL;
    foreach ($rows as $index => $row) {
      if ($this->getOperationType($row) === 'chat') {
        $last_chat_index = $index;
      }

      $rows[$index]['execution_tool_calls'] = $this->extractToolCallsFromResponse(
        (string) ($row['provider_response_raw'] ?? ''),
      );
      $rows[$index]['execution_evidence'] = $rows[$index]['execution_tool_calls'] === []
        ? NULL
        : 'captured';
      $rows[$index]['execution_legacy_stream_fallback'] = $this->isLegacyStreamFallback(
        (string) ($row['provider_response_raw'] ?? ''),
      );
    }

    // A historical streamed response can no longer be recovered after the
    // former global fallback replaced it. The next provider request still
    // includes the assistant's selected tool, so associate it with the
    // preceding chat call and label that relationship as reconstructed.
    foreach ($rows as $index => $row) {
      $tool_calls = $this->extractToolCallsFromRequest(
        (string) ($row['provider_request_raw'] ?? ''),
      );
      if ($tool_calls === []) {
        continue;
      }

      for ($previous = $index - 1; $previous >= 0; $previous--) {
        if ($this->getOperationType($rows[$previous]) !== 'chat') {
          continue;
        }

        if ($rows[$previous]['execution_tool_calls'] === []) {
          $rows[$previous]['execution_tool_calls'] = $tool_calls;
          $rows[$previous]['execution_evidence'] = 'reconstructed';
        }
        break;
      }
    }

    foreach ($rows as $index => $row) {
      $operation_type = $this->getOperationType($row);
      $tool_calls = $row['execution_tool_calls'];
      if ($operation_type === 'embeddings') {
        $phase = 'embedding';
      }
      elseif ($operation_type === 'chat' && $tool_calls !== []) {
        $phase = 'tool_selection';
      }
      elseif ($operation_type === 'chat' && $last_chat_index === $index) {
        $phase = 'final_answer';
      }
      elseif ($operation_type === 'chat' && $this->hasEmbeddingAfter($rows, $index)) {
        $phase = 'retrieval_decision';
      }
      elseif ($operation_type === 'chat') {
        $phase = 'model_step';
      }
      else {
        $phase = 'provider_operation';
      }

      $rows[$index]['execution_phase'] = $phase;
    }

    return $rows;
  }

  /**
   * Returns the normalized operation type for one provider row.
   *
   * @param array<string, mixed> $row
   *   Provider usage row.
   */
  private function getOperationType(array $row): string {
    return mb_strtolower(trim((string) ($row['operation_type'] ?? '')));
  }

  /**
   * Determines whether an embeddings operation follows a given row.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Provider rows.
   * @param int $index
   *   Position of the current provider row.
   */
  private function hasEmbeddingAfter(array $rows, int $index): bool {
    foreach (array_slice($rows, $index + 1) as $row) {
      if ($this->getOperationType($row) === 'embeddings') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Extracts tool calls recorded in the response audit payload.
   *
   * @return array<int, array{id: string, name: string, arguments: mixed}>
   *   Normalized tool calls.
   */
  private function extractToolCallsFromResponse(string $payload): array {
    $decoded = $this->decodeAuditJson($payload);
    if ($decoded === []) {
      return [];
    }

    $sources = [
      $decoded['normalized']['tool_calls'] ?? NULL,
      $decoded['tool_calls'] ?? NULL,
      $decoded['choices'][0]['message']['tool_calls'] ?? NULL,
      $decoded['raw_output']['choices'][0]['message']['tool_calls'] ?? NULL,
    ];

    foreach ($sources as $source) {
      $tool_calls = $this->normalizeToolCalls($source);
      if ($tool_calls !== []) {
        return $tool_calls;
      }
    }

    return [];
  }

  /**
   * Extracts assistant tool calls replayed in a later provider request.
   *
   * @return array<int, array{id: string, name: string, arguments: mixed}>
   *   Normalized tool calls.
   */
  private function extractToolCallsFromRequest(string $payload): array {
    $decoded = $this->decodeAuditJson($payload);
    $messages = $decoded['data']['messages'] ?? $decoded['messages'] ?? [];
    if (!is_array($messages)) {
      return [];
    }

    $tool_calls = [];
    foreach ($messages as $message) {
      if (!is_array($message) || ($message['role'] ?? '') !== 'assistant') {
        continue;
      }

      $tool_calls = array_merge($tool_calls, $this->normalizeToolCalls($message['tools'] ?? NULL));
    }

    return $this->deduplicateToolCalls($tool_calls);
  }

  /**
   * Converts OpenAI and Drupal AI tool-call structures into one shape.
   *
   * @return array<int, array{id: string, name: string, arguments: mixed}>
   *   Normalized tool calls.
   */
  private function normalizeToolCalls(mixed $tool_calls): array {
    if (!is_array($tool_calls)) {
      return [];
    }

    $normalized = [];
    foreach ($tool_calls as $tool_call) {
      if (!is_array($tool_call)) {
        continue;
      }

      $function = is_array($tool_call['function'] ?? NULL) ? $tool_call['function'] : $tool_call;
      $name = trim((string) ($function['name'] ?? $tool_call['name'] ?? ''));
      if ($name === '') {
        continue;
      }

      $arguments = $function['arguments'] ?? $tool_call['arguments'] ?? [];
      if (is_string($arguments)) {
        $decoded_arguments = json_decode($arguments, TRUE);
        if (json_last_error() === JSON_ERROR_NONE) {
          $arguments = $decoded_arguments;
        }
      }

      $normalized[] = [
        'id' => (string) ($tool_call['id'] ?? $tool_call['tool_id'] ?? ''),
        'name' => $name,
        'arguments' => $arguments,
      ];
    }

    return $this->deduplicateToolCalls($normalized);
  }

  /**
   * Removes duplicated tool calls while preserving their order.
   *
   * @param array<int, array{id: string, name: string, arguments: mixed}> $tool_calls
   *   Tool calls to normalize.
   *
   * @return array<int, array{id: string, name: string, arguments: mixed}>
   *   Unique tool calls.
   */
  private function deduplicateToolCalls(array $tool_calls): array {
    $unique = [];
    $seen = [];
    foreach ($tool_calls as $tool_call) {
      $key = $tool_call['id'] !== ''
        ? $tool_call['id']
        : $tool_call['name'] . ':' . json_encode($tool_call['arguments']);
      if (isset($seen[$key])) {
        continue;
      }

      $seen[$key] = TRUE;
      $unique[] = $tool_call;
    }

    return $unique;
  }

  /**
   * Decodes one JSON audit payload, including JSON strings wrapped in JSON.
   *
   * @return array<string, mixed>
   *   Decoded payload or an empty array.
   */
  private function decodeAuditJson(string $payload): array {
    $decoded = json_decode(trim($payload), TRUE);
    if (is_string($decoded)) {
      $decoded = json_decode(trim($decoded), TRUE);
    }

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Identifies the former fallback that copied the final reply into all rows.
   */
  private function isLegacyStreamFallback(string $payload): bool {
    $decoded = $this->decodeAuditJson($payload);
    return ($decoded['_source'] ?? '') === 'assistant_response_text_fallback';
  }

}
