<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\EventSubscriber;

use Drupal\Component\Serialization\Json;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\Event\AiExceptionEvent;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PostStreamingResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\ai\OperationType\OutputInterface;
use Drupal\trotamundos_assistant\Usage\UsageLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Captures AI provider usage while a Trotamundos Assistant call is running.
 */
final class AiUsageSubscriber implements EventSubscriberInterface {

  /**
   * Streamed provider outputs received before their usage row exists.
   *
   * In the Fiber path, the stream is consumed inside the provider call. Its
   * post-stream event therefore happens before ProviderProxy dispatches the
   * post-generate event where this subscriber creates the usage row.
   *
   * @var array<string, mixed>
   */
  private array $streamedOutputs = [];

  /**
   * Streamed outputs whose provider event has no request id yet.
   *
   * Some providers consume a stream inside a Fiber before ProviderProxy can
   * attach its event id. Those events are emitted immediately before the
   * matching post-generate event, so a FIFO queue preserves the association.
   *
   * @var array<int, mixed>
   */
  private array $unlinkedStreamedOutputs = [];

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private readonly UsageLogger $usageLogger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AiExceptionEvent::class => 'onAiException',
      PostGenerateResponseEvent::EVENT_NAME => 'onPostGenerateResponse',
      PostStreamingResponseEvent::EVENT_NAME => 'onPostStreamingResponse',
    ];
  }

  /**
   * Records token usage from provider responses.
   */
  public function onPostGenerateResponse(PostGenerateResponseEvent $event): void {
    $output = $event->getOutput();
    $input = $event->getInput();
    $provider_request_id = $event->getRequestThreadId();
    $streamed_output = $this->consumeStreamedOutput($provider_request_id);
    if ($streamed_output === NULL && $output instanceof ChatOutput && $this->isStreamResponseWrapper($output->getRawOutput())) {
      $streamed_output = $this->consumeUnlinkedStreamedOutput();
    }

    $this->usageLogger->recordProviderUsage(
      $provider_request_id,
      $event->getProviderId(),
      $event->getModelId(),
      $event->getOperationType(),
      $this->extractTokenUsage($output),
      $this->stringifyRawValue($input),
      $this->stringifyRawValue($streamed_output ?? $this->buildRawOutputForLog($output)),
      $this->extractSystemPrompt($input),
    );
  }

  /**
   * Replaces the initial stream object with the final reconstructed response.
   */
  public function onPostStreamingResponse(PostStreamingResponseEvent $event): void {
    $output = $event->getOutput();
    if (!$output instanceof ChatOutput) {
      return;
    }

    $provider_request_id = $event->getRequestThreadId();
    $payload = $this->buildStreamedRawOutput($output);
    if ($provider_request_id !== '') {
      $this->streamedOutputs[$provider_request_id] = $payload;
      $this->usageLogger->updateProviderResponseRaw(
        $provider_request_id,
        $this->stringifyRawValue($payload),
      );
      return;
    }

    $this->unlinkedStreamedOutputs[] = $payload;
  }

  /**
   * Records failed provider requests while preserving the request context.
   */
  public function onAiException(AiExceptionEvent $event): void {
    $input = $event->getInput();

    $this->usageLogger->recordProviderUsage(
      $event->getRequestThreadId(),
      $event->getProviderId(),
      $event->getModelId(),
      $event->getOperationType(),
      new TokenUsageDto(),
      $this->stringifyRawValue($input),
      $this->stringifyRawValue($this->buildExceptionRawOutput($event)),
      $this->extractSystemPrompt($input),
    );
  }

  /**
   * Extracts the system prompt from a chat input for the audit record.
   */
  private function extractSystemPrompt(mixed $input): string {
    return $input instanceof ChatInput ? $input->getSystemPrompt() : '';
  }

  /**
   * Gets token usage from any AI output type supported by the provider event.
   */
  private function extractTokenUsage(OutputInterface $output): TokenUsageDto {
    if ($output instanceof ChatOutput) {
      return $output->getTokenUsage();
    }

    return $this->buildEmbeddingTokenUsage($output->getRawOutput());
  }

  /**
   * Builds the response payload stored for auditing.
   */
  private function buildRawOutputForLog(OutputInterface $output): mixed {
    if ($output instanceof ChatOutput) {
      // In the Fiber path the provider returns an SDK StreamResponse wrapper
      // after its stream has already been consumed. It is not the response for
      // this particular provider call. Keep the normalized message and tool
      // calls instead of later replacing it with the interaction's final text.
      if ($this->isStreamResponseWrapper($output->getRawOutput())) {
        return $this->buildStreamedRawOutput($output);
      }

      return $output->getRawOutput();
    }

    if ($output instanceof EmbeddingsOutput) {
      return $this->buildEmbeddingRawOutputSummary($output);
    }

    return [
      '_class' => $output::class,
      'rawOutput' => $this->normalizeRawValue($output->getRawOutput()),
      'metadata' => $this->normalizeRawValue($output->getMetadata()),
    ];
  }

  /**
   * Builds a provider-response payload for failed requests.
   */
  private function buildExceptionRawOutput(AiExceptionEvent $event): array {
    $exception = $event->getException();
    $previous = $exception->getPrevious();

    return [
      '_source' => 'ai_provider_exception',
      'exception_class' => $exception::class,
      'message' => $exception->getMessage(),
      'code' => $exception->getCode(),
      'previous_exception_class' => $previous ? $previous::class : NULL,
      'provider_id' => $event->getProviderId(),
      'model_id' => $event->getModelId(),
      'operation_type' => $event->getOperationType(),
    ];
  }

  /**
   * Builds an auditable payload for one streamed provider response.
   *
   * The normalized message is deliberately stored alongside the raw chunks.
   * It carries the tool call that selected RAG even when a provider's SDK has
   * already consumed the individual stream chunks.
   */
  private function buildStreamedRawOutput(ChatOutput $output): mixed {
    $raw_output = $output->getRawOutput();
    return [
      '_source' => 'streamed_provider_response',
      'raw_response_available' => !$this->isStreamResponseWrapper($raw_output) && !empty($raw_output),
      'raw_output' => $this->isStreamResponseWrapper($raw_output)
        ? NULL
        : $this->normalizeRawValue($raw_output),
      'normalized' => $this->buildNormalizedChatOutput($output),
      'token_usage' => $this->normalizeRawValue($output->getTokenUsage()),
      'raw_output_note' => $this->isStreamResponseWrapper($raw_output)
        ? 'The provider SDK exposed a consumed stream wrapper. The normalized message is the reliable audit record for this request.'
        : NULL,
    ];
  }

  /**
   * Converts a streamed chat message into a compact, call-specific audit row.
   */
  private function buildNormalizedChatOutput(ChatOutput $output): array {
    $normalized = $output->getNormalized();
    if (!$normalized instanceof ChatMessage) {
      return [
        '_class' => $normalized::class,
        'note' => 'The streamed response was not reconstructed as a chat message.',
      ];
    }

    $tool_calls = [];
    foreach ($normalized->getTools() ?? [] as $tool) {
      if (!is_object($tool) || !method_exists($tool, 'getOutputRenderArray')) {
        $tool_calls[] = $this->normalizeRawValue($tool);
        continue;
      }

      $rendered = $tool->getOutputRenderArray();
      $arguments = $rendered['function']['arguments'] ?? [];
      if (is_string($arguments)) {
        $decoded_arguments = json_decode($arguments, TRUE);
        if (json_last_error() === JSON_ERROR_NONE) {
          $arguments = $decoded_arguments;
        }
      }
      $tool_calls[] = [
        'id' => $rendered['id'] ?? NULL,
        'name' => $rendered['function']['name'] ?? NULL,
        'arguments' => $this->normalizeRawValue($arguments),
      ];
    }

    return [
      'role' => $normalized->getRole(),
      'text' => $normalized->getText(),
      'tool_calls' => $tool_calls,
    ];
  }

  /**
   * Determines whether a provider raw output is an uninspectable SDK stream.
   */
  private function isStreamResponseWrapper(mixed $raw_output): bool {
    return is_object($raw_output) && str_contains($raw_output::class, 'StreamResponse');
  }

  /**
   * Gets and removes a completed stream payload for one provider request.
   */
  private function consumeStreamedOutput(string $provider_request_id): mixed {
    if ($provider_request_id === '' || !array_key_exists($provider_request_id, $this->streamedOutputs)) {
      return NULL;
    }

    $payload = $this->streamedOutputs[$provider_request_id];
    unset($this->streamedOutputs[$provider_request_id]);

    return $payload;
  }

  /**
   * Gets the next stream emitted before ProviderProxy assigned its request id.
   */
  private function consumeUnlinkedStreamedOutput(): mixed {
    return array_shift($this->unlinkedStreamedOutputs);
  }

  /**
   * Builds token usage for providers that expose embedding usage in raw output.
   */
  private function buildEmbeddingTokenUsage(mixed $raw_output): TokenUsageDto {
    $usage = is_array($raw_output) && isset($raw_output['usage']) && is_array($raw_output['usage'])
      ? $raw_output['usage']
      : [];

    $input_tokens = $this->getUsageTokenValue($usage, ['prompt_tokens', 'input_tokens', 'promptTokens', 'inputTokens']);
    $total_tokens = $this->getUsageTokenValue($usage, ['total_tokens', 'totalTokens']);

    if ($total_tokens === 0) {
      $total_tokens = $input_tokens;
    }

    return new TokenUsageDto(
      input: $input_tokens,
      output: 0,
      total: $total_tokens,
    );
  }

  /**
   * Gets the first available integer token value from a provider usage array.
   *
   * @param array<string, mixed> $usage
   *   Provider usage data.
   * @param array<int, string> $keys
   *   Candidate keys.
   */
  private function getUsageTokenValue(array $usage, array $keys): int {
    foreach ($keys as $key) {
      if (isset($usage[$key]) && is_numeric($usage[$key])) {
        return (int) $usage[$key];
      }
    }

    return 0;
  }

  /**
   * Summarizes embedding responses without storing the full vector payload.
   */
  private function buildEmbeddingRawOutputSummary(EmbeddingsOutput $output): array {
    $raw_output = $output->getRawOutput();
    if (!is_array($raw_output)) {
      return [
        '_class' => $output::class,
        'rawOutput' => $this->normalizeRawValue($raw_output),
        'usage' => $this->normalizeRawValue($this->buildEmbeddingTokenUsage($raw_output)),
      ];
    }

    $data = $raw_output['data'] ?? [];
    $first_embedding = is_array($data) && isset($data[0]['embedding']) && is_array($data[0]['embedding'])
      ? $data[0]['embedding']
      : [];

    return [
      '_class' => $output::class,
      'object' => $raw_output['object'] ?? NULL,
      'model' => $raw_output['model'] ?? NULL,
      'usage' => $raw_output['usage'] ?? NULL,
      'data_count' => is_array($data) ? count($data) : 0,
      'embedding_dimensions' => count($first_embedding),
      'rawOutput_note' => 'Embedding vectors omitted from usage log payload.',
    ];
  }

  /**
   * Serializes provider event data into readable JSON for auditing.
   */
  private function stringifyRawValue(mixed $value): string {
    $normalized = $this->normalizeRawValue($value);
    $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (is_string($encoded)) {
      return $encoded;
    }

    try {
      return Json::encode($normalized);
    }
    catch (\Throwable) {
      return (string) print_r($normalized, TRUE);
    }
  }

  /**
   * Converts DTOs and objects into arrays before serializing them.
   */
  private function normalizeRawValue(mixed $value, int $depth = 0): mixed {
    if ($depth > 8) {
      return '[max depth reached]';
    }

    if (is_null($value) || is_scalar($value)) {
      return $value;
    }

    if (is_resource($value)) {
      return '[resource]';
    }

    if (is_array($value)) {
      $normalized = [];
      foreach ($value as $key => $item) {
        $normalized[$key] = $this->normalizeRawValue($item, $depth + 1);
      }
      return $normalized;
    }

    if ($value instanceof \JsonSerializable) {
      return $this->normalizeRawValue($value->jsonSerialize(), $depth + 1);
    }

    if (is_object($value) && method_exists($value, 'toArray')) {
      return [
        '_class' => $value::class,
        'data' => $this->normalizeRawValue($value->toArray(), $depth + 1),
      ];
    }

    if (is_object($value) && method_exists($value, '__toString')) {
      return [
        '_class' => $value::class,
        'value' => (string) $value,
      ];
    }

    if (is_object($value)) {
      return [
        '_class' => $value::class,
        'properties' => $this->normalizeRawValue(get_object_vars($value), $depth + 1),
      ];
    }

    return (string) $value;
  }

}
