<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Agent;

use Drupal\ai\OperationType\Chat\ChatMessage;

/**
 * Detects provider failures that AI Agents exposes as chat history.
 */
final class AgentResponseGuard {

  /**
   * Checks whether an agent response is really the user's input.
   *
   * AI Agents can catch a provider exception and return the last item from
   * the chat history. In a new request that item is the user message, not an
   * assistant answer. Letting it through would render the generic skill as
   * though the provider had answered normally.
   */
  public static function isProviderFailureResponse(mixed $response, string $user_message): bool {
    if ($response instanceof ChatMessage && strtolower(trim($response->getRole())) !== 'assistant') {
      return TRUE;
    }

    $response_text = $response instanceof ChatMessage
      ? $response->getText()
      : (is_string($response) ? $response : '');

    return self::normalize($response_text) !== ''
      && self::normalize($response_text) === self::normalize($user_message);
  }

  /**
   * Normalizes whitespace for an exact response echo comparison.
   */
  private static function normalize(string $text): string {
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
  }

}
