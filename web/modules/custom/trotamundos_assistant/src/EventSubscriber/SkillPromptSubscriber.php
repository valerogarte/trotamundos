<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\EventSubscriber;

use Drupal\ai_assistant_api\Event\AiAssistantSystemRoleEvent;
use Drupal\trotamundos_assistant\Plugin\AiFunctionCall\SkillAwareRagTool;
use Drupal\trotamundos_assistant\Setup\TravelAssistantSetup;
use Drupal\trotamundos_assistant\Skill\SkillMatcher;
use Drupal\trotamundos_assistant\Skill\SkillTemplateRenderer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Adds progressive Trotamundos Assistant skill instructions to provider prompts.
 *
 * The first agent iteration receives a compact routing catalogue. A RAG tool
 * call carries the selected skill into later iterations, where only that
 * skill's detailed response contract is disclosed. Invalid or legacy tool
 * calls fall back to the complete catalogue.
 */
final class SkillPromptSubscriber implements EventSubscriberInterface {

  /**
   * Request attribute holding instructions for the current chat request.
   */
  public const REQUEST_ATTRIBUTE = 'trotamundos_assistant.skill_prompt';

  /**
   * Legacy contrib tool kept as a safe transition path.
   */
  private const LEGACY_RAG_PLUGIN_ID = 'ai_search:rag_search';

  /**
   * Legacy contrib function name kept as a safe transition path.
   */
  private const LEGACY_RAG_FUNCTION_NAME = 'ai_search_rag_search';

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly SkillMatcher $skillMatcher,
    private readonly SkillTemplateRenderer $skillTemplateRenderer,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AiAssistantSystemRoleEvent::EVENT_NAME => 'onAssistantSystemPrompt',
      // AI Agents is optional at runtime, so its event names stay as strings.
      'ai_agents.request' => 'onAgentRequest',
      'ai_agents.started_execution' => 'onAgentStarted',
      'ai_agents.tool_finished_executed' => 'onAgentToolFinished',
    ];
  }

  /**
   * Adds the complete instructions when no AI Agent is configured.
   */
  public function onAssistantSystemPrompt(AiAssistantSystemRoleEvent $event): void {
    $context = $this->getContext();
    $instructions = trim((string) ($context['instructions'] ?? ''));
    if ($instructions !== '') {
      $event->setSystemPrompt($this->appendInstructions($event->getSystemPrompt(), $instructions));
    }
  }

  /**
   * Injects a compact router or one selected skill into the actual ChatInput.
   *
   * The event is intentionally untyped because AI Agents is optional.
   */
  public function onAgentRequest(object $event): void {
    if (!method_exists($event, 'getAgentId') || !method_exists($event, 'getLoopCount') || !method_exists($event, 'getChatInput')) {
      return;
    }

    $context = $this->getContext((string) $event->getAgentId());
    $skills = is_array($context['skills'] ?? NULL) ? $context['skills'] : [];
    if ($skills === []) {
      return;
    }

    $last_result_reply = (string) ($context['last_result_reply'] ?? '');
    $selected_reference = !empty($context['selection_conflict'])
      ? ''
      : trim((string) ($context['selected_skill'] ?? ''));
    $selected_skill = $selected_reference === ''
      ? NULL
      : $this->skillMatcher->getByReference($selected_reference, TRUE);
    $loop_count = (int) $event->getLoopCount();
    $force_rag = !empty($context['force_rag']);

    if ($selected_skill !== NULL && ($force_rag || $loop_count > 1)) {
      $instructions = $this->skillTemplateRenderer->buildProviderSelectedSkillInstructions(
        $selected_skill,
        $last_result_reply,
        $selected_reference,
      );
    }
    elseif ($loop_count <= 1) {
      $instructions = $this->skillTemplateRenderer->buildProviderRoutingInstructions(
        $skills,
        $last_result_reply,
        $force_rag,
      );
    }
    else {
      // Old tools do not carry a skill. Preserve the former complete prompt
      // rather than guessing and risking a different response contract.
      $instructions = $this->skillTemplateRenderer->buildProviderInstructions($skills, $last_result_reply);
    }

    $input = $event->getChatInput();
    if (!is_object($input) || !method_exists($input, 'getSystemPrompt') || !method_exists($input, 'setSystemPrompt')) {
      return;
    }

    $base_prompt = $this->normalizeAgentLoopPrompt((string) $input->getSystemPrompt());
    $input->setSystemPrompt($this->prependInstructions($base_prompt, $instructions));
  }

  /**
   * Makes RAG mandatory for one guarded retry after a missing retrieval.
   *
   * The event is intentionally untyped because AI Agents is optional.
   */
  public function onAgentStarted(object $event): void {
    if (!method_exists($event, 'getAgentId') || !method_exists($event, 'getAgent')) {
      return;
    }

    $context = $this->getContext((string) $event->getAgentId());
    if (empty($context['force_rag'])) {
      return;
    }

    $agent = $event->getAgent();
    if (!is_object($agent) || !method_exists($agent, 'getAiAgentEntity') || !method_exists($agent, 'overrideFunctions')) {
      return;
    }

    $entity = $agent->getAiAgentEntity();
    if (!is_object($entity) || !method_exists($entity, 'get')) {
      return;
    }

    $tools = is_array($entity->get('tools')) ? $entity->get('tools') : [];
    $tool_settings = is_array($entity->get('tool_settings')) ? $entity->get('tool_settings') : [];
    $tool_limits = is_array($entity->get('tool_usage_limits')) ? $entity->get('tool_usage_limits') : [];
    $rag_plugin_id = !empty($tools[SkillAwareRagTool::PLUGIN_ID])
      ? SkillAwareRagTool::PLUGIN_ID
      : self::LEGACY_RAG_PLUGIN_ID;

    if (empty($tools[$rag_plugin_id])) {
      return;
    }

    $tool_settings[$rag_plugin_id]['require_usage'] = 1;
    $agent->overrideFunctions([
      'tools' => $tools,
      'tool_settings' => $tool_settings,
      'tool_usage_limits' => $tool_limits,
    ]);
  }

  /**
   * Records RAG execution and the explicit skill carried by the new tool.
   *
   * The event is intentionally untyped because AI Agents is optional.
   */
  public function onAgentToolFinished(object $event): void {
    if (!method_exists($event, 'getAgentId') || !method_exists($event, 'getTool')) {
      return;
    }

    $context = $this->getContext((string) $event->getAgentId());
    if ($context === []) {
      return;
    }

    $tool = $event->getTool();
    if (!is_object($tool) || !method_exists($tool, 'getFunctionName')) {
      return;
    }

    $function_name = (string) $tool->getFunctionName();
    if (!in_array($function_name, [SkillAwareRagTool::FUNCTION_NAME, self::LEGACY_RAG_FUNCTION_NAME], TRUE)) {
      return;
    }

    $context['rag_used'] = TRUE;
    if (method_exists($tool, 'getStructuredOutput')) {
      $output = $tool->getStructuredOutput();
      if (is_array($output) && ($output['index'] ?? NULL) === TravelAssistantSetup::INDEX_ID) {
        $context['retrieved_nodes'] = is_array($context['retrieved_nodes'] ?? NULL) ? $context['retrieved_nodes'] : [];
        foreach (is_array($output['results'] ?? NULL) ? $output['results'] : [] as $result) {
          if (!is_array($result) || ($result['entity_type'] ?? NULL) !== 'node') {
            continue;
          }
          $id = filter_var($result['entity_id'] ?? NULL, FILTER_VALIDATE_INT);
          $langcode = (string) ($result['langcode'] ?? '');
          if ($id !== FALSE && $id > 0 && $langcode !== '') {
            $context['retrieved_nodes'][$id][$langcode] = TRUE;
          }
        }
      }
    }
    if ($function_name === SkillAwareRagTool::FUNCTION_NAME && method_exists($tool, 'getContextValue')) {
      $selected_reference = trim((string) $tool->getContextValue('skill'));
      if ($this->skillMatcher->getByReference($selected_reference, TRUE) !== NULL) {
        $existing = trim((string) ($context['selected_skill'] ?? ''));
        if ($existing !== '' && $existing !== $selected_reference) {
          $context['selection_conflict'] = TRUE;
          $context['selected_skill'] = '';
        }
        elseif (empty($context['selection_conflict'])) {
          $context['selected_skill'] = $selected_reference;
        }
      }
    }

    $this->setContext($context);
  }

  /**
   * Gets the current Trotamundos Assistant request context for an optional agent.
   *
   * @return array<string, mixed>
   *   Request-scoped prompt state.
   */
  private function getContext(?string $agent_id = NULL): array {
    $context = $this->requestStack->getCurrentRequest()?->attributes->get(self::REQUEST_ATTRIBUTE);
    if (!is_array($context)) {
      return [];
    }

    $configured_agent_id = trim((string) ($context['agent_id'] ?? ''));
    if ($agent_id !== NULL && ($configured_agent_id === '' || $configured_agent_id !== $agent_id)) {
      return [];
    }

    return $context;
  }

  /**
   * Persists request-scoped prompt state for subsequent agent iterations.
   *
   * @param array<string, mixed> $context
   *   Updated context.
   */
  private function setContext(array $context): void {
    $this->requestStack->getCurrentRequest()?->attributes->set(self::REQUEST_ATTRIBUTE, $context);
  }

  /**
   * Replaces the changing ordinal that prevents provider prompt caching.
   */
  private function normalizeAgentLoopPrompt(string $system_prompt): string {
    return trim((string) preg_replace(
      '/This is the (?:first|second|third|\d+th) time that this agent has been run\.\s*/',
      "Agent execution loop.\n",
      $system_prompt,
    ));
  }

  /**
   * Prepends dynamic instructions so stable routing data forms the cache key.
   */
  private function prependInstructions(string $system_prompt, string $instructions): string {
    $instructions = trim($instructions);
    $system_prompt = trim($system_prompt);

    return $system_prompt === ''
      ? $instructions
      : $instructions . "\n\n" . $system_prompt;
  }

  /**
   * Appends instructions for the non-agent Assistant API path.
   */
  private function appendInstructions(string $system_prompt, string $instructions): string {
    $system_prompt = rtrim($system_prompt);

    return $system_prompt === ''
      ? $instructions
      : $system_prompt . "\n\n" . $instructions;
  }

}
