<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\EventSubscriber;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Makes OpenAI Luna requests compatible with function tools.
 *
 * The Drupal OpenAI provider uses Chat Completions for the agent execution.
 * This endpoint requires `reasoning_effort: none` when gpt-6-luna receives
 * function tools. AI Agents does not propagate the Assistant configuration to
 * that provider call, so this is applied immediately before the request.
 */
final class OpenAiLunaToolCompatibilitySubscriber implements EventSubscriberInterface {

  /**
   * The provider model that needs this compatibility setting.
   */
  private const MODEL_ID = 'gpt-6-luna';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreGenerateResponseEvent::EVENT_NAME => 'onPreGenerateResponse',
    ];
  }

  /**
   * Disables reasoning effort for Luna Chat Completions requests with tools.
   */
  public function onPreGenerateResponse(PreGenerateResponseEvent $event): void {
    if (
      $event->getProviderId() !== 'openai'
      || $event->getOperationType() !== 'chat'
      || $event->getModelId() !== self::MODEL_ID
    ) {
      return;
    }

    $input = $event->getInput();
    if (!$input instanceof ChatInput || !$input->getChatTools()) {
      return;
    }

    $configuration = $event->getConfiguration();
    $configuration['reasoning_effort'] = 'none';
    $event->setConfiguration($configuration);
  }

}
