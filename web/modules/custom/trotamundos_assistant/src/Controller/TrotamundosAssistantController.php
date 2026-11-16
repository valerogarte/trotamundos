<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\Php;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\ai_assistant_api\Data\UserMessage;
use Drupal\ai_assistant_api\Entity\AiAssistant;
use Drupal\trotamundos_assistant\Access\AssistantAccessManager;
use Drupal\trotamundos_assistant\Agent\AgentResponseGuard;
use Drupal\trotamundos_assistant\Config\TrotamundosAssistantDefaults;
use Drupal\trotamundos_assistant\EventSubscriber\SkillPromptSubscriber;
use Drupal\trotamundos_assistant\Skill\SkillMatcher;
use Drupal\trotamundos_assistant\Skill\SkillTemplateRenderer;
use Drupal\trotamundos_assistant\Usage\UsageLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles the embedded Trotamundos Assistant frontend endpoints.
 */
final class TrotamundosAssistantController extends ControllerBase {

  /**
   * Flood event name for assistant chat requests.
   */
  private const CHAT_FLOOD_EVENT = 'trotamundos_assistant.chat';

  /**
   * Maximum length of a chat-only answer for out-of-scope questions.
   */
  private const OUT_OF_SCOPE_REPLY_LENGTH = 600;

  /**
   * Marker used to identify an internal skill prompt accidentally persisted.
   */
  private const SKILL_INSTRUCTIONS_MARKER = 'INSTRUCCIONES DE SKILLS PARA DRUPAL:';

  /**
   * Error thrown by AI Assistant API when its own role restriction fails.
   */
  private const ASSISTANT_ROLE_DENIED_EXCEPTION_MESSAGE = 'User does not have the required role to run the assistant.';

  /**
   * Maps AI exception classes to the Assistant specific error message keys.
   */
  private const SPECIFIC_ERROR_MESSAGE_KEYS = [
    AiBadRequestException::class => 'AiBadRequestException',
    AiRateLimitException::class => 'AiRateLimitException',
    AiQuotaException::class => 'AiQuotaException',
    AiSetupFailureException::class => 'AiSetupFailureException',
    AiRequestErrorException::class => 'AiRequestErrorException',
  ];

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly BlockManagerInterface $blockManager,
    private readonly EntityTypeManagerInterface $entityTypeManagerService,
    private readonly AiAssistantApiRunner $assistantRunner,
    private readonly SkillMatcher $skillMatcher,
    private readonly SkillTemplateRenderer $skillTemplateRenderer,
    private readonly UsageLogger $usageLogger,
    private readonly ConfigFactoryInterface $configFactoryService,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly TimeInterface $time,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly FloodInterface $flood,
    private readonly AssistantAccessManager $accessManager,
    private readonly TrotamundosAssistantDefaults $defaults,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('plugin.manager.block'),
      $container->get('entity_type.manager'),
      $container->get('ai_assistant_api.runner'),
      $container->get('trotamundos_assistant.skill_matcher'),
      $container->get('trotamundos_assistant.skill_template_renderer'),
      $container->get('trotamundos_assistant.usage_logger'),
      $container->get('config.factory'),
      $container->get('csrf_token'),
      $container->get('datetime.time'),
      $container->get('tempstore.private'),
      $container->get('flood'),
      $container->get('trotamundos_assistant.access_manager'),
      $container->get('trotamundos_assistant.defaults'),
    );
  }

  /**
   * Builds the Home AI page.
   */
  public function home(): array {
    $block = $this->blockManager->createInstance('trotamundos_assistant');

    return [
      '#attributes' => [
        'class' => [
          'trotamundos-assistant-page',
        ],
      ],
      'result_panel' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'trotamundos-assistant-result-panel',
        ],
      ],
      'trotamundos_assistant' => $block->build(),
    ];
  }

  /**
   * Handles embedded assistant chat requests.
   */
  public function chat(Request $request): JsonResponse {
    if (!$this->isAssistantAccessAllowed()) {
      $access_denied_message = $this->getAccessDeniedChatMessage();

      return new JsonResponse([
        'error' => $access_denied_message,
        'reply' => $access_denied_message,
      ], 403);
    }

    if (!$this->validateCsrfToken($request)) {
      return new JsonResponse(['error' => 'Invalid security token.'], 403);
    }

    $data = Json::decode($request->getContent()) ?? [];
    $message = trim((string) ($data['message'] ?? ''));

    if ($message === '') {
      return new JsonResponse([
        'error' => $this->t('The message cannot be empty.'),
      ], 400);
    }

    $max_message_length = $this->getMaxMessageLength();
    if (mb_strlen($message) > $max_message_length) {
      return new JsonResponse([
        'error' => $this->t('La consulta no puede superar los @limit caracteres.', [
          '@limit' => $max_message_length,
        ]),
      ], 400);
    }

    if (!$this->isChatRequestAllowed($request)) {
      return new JsonResponse([
        'error' => $this->t('Has alcanzado el límite temporal de consultas. Inténtalo de nuevo más tarde.'),
      ], 429);
    }

    if (!$this->prepareAssistantRunner()) {
      $error_message = $this->buildConfiguredAssistantErrorMessage(
        new AiSetupFailureException('The configured assistant is not available.')
      );

      return new JsonResponse([
        'error' => $error_message,
        'reply' => $error_message,
      ], 500);
    }

    $this->registerChatRequest($request);

    $this->assistantRunner->setThrowException(TRUE);

    if (!empty($data['thread_id']) && is_string($data['thread_id'])) {
      if (!$this->isValidThreadId($data['thread_id'])) {
        return new JsonResponse(['error' => 'Invalid thread ID.'], 400);
      }
      $this->assistantRunner->setThreadsKey($data['thread_id']);
    }
    else {
      $this->assistantRunner->setThreadsKey($this->generateThreadId());
    }

    $this->sanitizeThreadHistory($this->assistantRunner->getThreadsKey());

    // The thread history only keeps the concise chat reply, so the previous
    // full answer is what carries the already offered products forward.
    $skills = $this->skillMatcher->loadAll();
    $session = $this->assistantRunner->getTempStore()->get($this->assistantRunner->getThreadsKey()) ?? [];
    $last_result_reply = $this->getLastResultReply($session);
    $provider_context_reply = $this->skillTemplateRenderer->getProviderContextReply(
      is_array($session['trotamundos_assistant_results'] ?? NULL) ? $session['trotamundos_assistant_results'] : [],
      $last_result_reply,
    );
    $provider_message = $this->skillTemplateRenderer->buildProviderMessage(
      $skills,
      $message,
      $provider_context_reply,
    );
    $agent_id = trim((string) ($this->assistantRunner->getAssistant()?->get('ai_agent') ?? ''));
    $request->attributes->set(SkillPromptSubscriber::REQUEST_ATTRIBUTE, [
      'agent_id' => $agent_id,
      'instructions' => $this->skillTemplateRenderer->buildProviderInstructions($skills, $provider_context_reply),
      'skills' => $skills,
      'last_result_reply' => $provider_context_reply,
      'selected_skill' => '',
      'selection_conflict' => FALSE,
      'rag_used' => FALSE,
      'force_rag' => FALSE,
    ]);

    $this->clearAgentExecutionState($this->assistantRunner->getThreadsKey());

    try {
      $call_id = $this->usageLogger->beginCall(
        $this->assistantRunner->getThreadsKey(),
        $this->getAssistantId(),
        $message,
      );
      $this->assistantRunner->setUserMessage(new UserMessage($provider_message));
      $rag_retry_attempted = FALSE;
      do {
        $response = $this->assistantRunner->process();
        $normalized_response = $response->getNormalized();

        if ($agent_id !== '' && AgentResponseGuard::isProviderFailureResponse($normalized_response, $message)) {
          throw new AiRequestErrorException('AI Agents returned the user message instead of an assistant response.');
        }

        $assistant_reply = $normalized_response instanceof ChatMessage
          ? $normalized_response->getText()
          : (string) $normalized_response;
        $selected_skill = $this->skillMatcher->matchProviderSelection($assistant_reply);
        $prompt_context = $request->attributes->get(SkillPromptSubscriber::REQUEST_ATTRIBUTE);
        $rag_used = is_array($prompt_context) && !empty($prompt_context['rag_used']);

        if (
          $agent_id !== ''
          && !$rag_retry_attempted
          && $selected_skill !== NULL
          && $this->skillTemplateRenderer->skillRequiresRag($selected_skill)
          && !$rag_used
        ) {
          $rag_retry_attempted = TRUE;
          $prompt_context = is_array($prompt_context) ? $prompt_context : [];
          $prompt_context['force_rag'] = TRUE;
          $prompt_context['rag_used'] = FALSE;
          $prompt_context['selected_skill'] = (string) ($selected_skill['id'] ?? $selected_skill['machine_name'] ?? '');
          $prompt_context['selection_conflict'] = FALSE;
          $request->attributes->set(SkillPromptSubscriber::REQUEST_ATTRIBUTE, $prompt_context);
          $this->getLogger('trotamundos_assistant')->notice('Retrying a @skill response with mandatory RAG because the first result had no retrieval evidence.', [
            '@skill' => (string) ($selected_skill['machine_name'] ?? 'unknown'),
          ]);
          continue;
        }

        break;
      } while (TRUE);

      if ($this->containsInternalSkillInstructions($assistant_reply)) {
        $this->getLogger('trotamundos_assistant')->warning('Discarded an assistant response that exposed the internal skill prompt.');
        $assistant_reply = '';
      }

      $this->assistantRunner->setAssistantMessage($assistant_reply);
      $this->usageLogger->storeResponseText($assistant_reply);
      $usage = $this->usageLogger->endCall();

      if (trim($assistant_reply) === '') {
        $fallback_reply = $this->buildAssistantLimitFallbackReply();
        $fallback_payload = $this->buildAssistantLimitFallbackPayload($fallback_reply);
        $skill = $this->skillMatcher->matchProviderSelection($fallback_payload);
        $rendered_result = $this->skillTemplateRenderer->renderWithAttachments($skill, $fallback_payload);
        $this->replaceLastUserMessage($this->assistantRunner->getThreadsKey(), $message);
        $this->replaceLastAssistantMessage($this->assistantRunner->getThreadsKey(), $fallback_reply);
        $this->storeResultReply($this->assistantRunner->getThreadsKey(), $fallback_payload);

        $this->getLogger('trotamundos_assistant')->warning('The assistant returned an empty response. This usually means the agent exhausted its reasoning or tool loop budget.');

        return new JsonResponse([
          'reply' => $fallback_reply,
          'thread_id' => $this->assistantRunner->getThreadsKey(),
          'call_id' => $call_id,
          'usage' => $usage,
          'skill' => $skill['machine_name'] ?? NULL,
          'machine_name' => $skill['machine_name'] ?? 'default',
          'btn_responsive_title' => $this->getResponsiveButtonTitle($skill),
          'result_html' => $rendered_result['html'],
          'result_ajax_commands' => $rendered_result['ajax_commands'],
        ]);
      }

      $skill = $this->skillMatcher->matchProviderSelection($assistant_reply);

      // Out-of-scope questions never touch the result area: the panel keeps the
      // products or content of the previous turn and the answer stays in chat.
      // The provider flag is the intended signal but gets dropped often, so a
      // painted panel is also kept when the answer has nothing to paint or
      // falls back to `default`, whose whole output is the plain text the chat
      // bubble already shows.
      $keep_result = $this->skillTemplateRenderer->isOutOfScope($assistant_reply)
        || ($last_result_reply !== '' && (
          $this->isGenericSkill($skill)
          || !$this->skillTemplateRenderer->hasRenderableResult($assistant_reply)
        ));
      if ($keep_result) {
        $chat_reply = $this->skillTemplateRenderer->extractChatReply($assistant_reply);
        if ($chat_reply === '') {
          $chat_reply = $this->buildConciseReply($skill, $assistant_reply);
        }
        $chat_reply = $this->limitReply($chat_reply, self::OUT_OF_SCOPE_REPLY_LENGTH);
        $this->replaceLastUserMessage($this->assistantRunner->getThreadsKey(), $message);
        $this->replaceLastAssistantMessage($this->assistantRunner->getThreadsKey(), $chat_reply);

        // The stored result reply is deliberately untouched so the active
        // product collection survives the off-topic turn.
        return new JsonResponse([
          'reply' => $chat_reply,
          'thread_id' => $this->assistantRunner->getThreadsKey(),
          'call_id' => $call_id,
          'usage' => $usage,
          'skill' => NULL,
          'machine_name' => 'out_of_scope',
          'keep_result' => TRUE,
        ]);
      }

      $rendered_result = $this->skillTemplateRenderer->renderWithAttachments($skill, $assistant_reply);
      $chat_reply = $this->buildConciseReply($skill, $assistant_reply);
      $this->replaceLastUserMessage($this->assistantRunner->getThreadsKey(), $message);
      $this->replaceLastAssistantMessage($this->assistantRunner->getThreadsKey(), $chat_reply);
      $this->storeResultReply($this->assistantRunner->getThreadsKey(), $assistant_reply);

      return new JsonResponse([
        'reply' => $chat_reply,
        'thread_id' => $this->assistantRunner->getThreadsKey(),
        'call_id' => $call_id,
        'usage' => $usage,
        'skill' => $skill['machine_name'] ?? NULL,
        'machine_name' => $skill['machine_name'] ?? 'default',
        'btn_responsive_title' => $this->getResponsiveButtonTitle($skill),
        'result_html' => $rendered_result['html'],
        'result_ajax_commands' => $rendered_result['ajax_commands'],
      ]);
    }
    catch (\Throwable $throwable) {
      $this->replaceLastUserMessage($this->assistantRunner->getThreadsKey(), $message);
      $this->getLogger('trotamundos_assistant')->error('The embedded assistant failed: @message', [
        '@message' => $throwable->getMessage(),
      ]);
      $error_message = $this->buildConfiguredAssistantErrorMessage($throwable);
      $this->usageLogger->storeResponseText($error_message);
      $usage = $this->usageLogger->endCall();

      return new JsonResponse([
        'error' => $error_message,
        'reply' => $error_message,
        'usage' => $usage,
      ], 500);
    }
  }

  /**
   * Gets the configured maximum message length.
   */
  private function getMaxMessageLength(): int {
    return max(1, (int) ($this->configFactoryService
      ->get('trotamundos_assistant.settings')
      ->get('max_message_length') ?? $this->defaults->getMaxMessageLength()));
  }

  /**
   * Checks whether the requester has remaining chat quota.
   */
  private function isChatRequestAllowed(Request $request): bool {
    $limit = $this->getRateLimitRequests();
    if ($limit <= 0) {
      return TRUE;
    }

    return $this->flood->isAllowed(
      self::CHAT_FLOOD_EVENT,
      $limit,
      $this->getRateLimitWindow(),
      $this->getChatFloodIdentifier($request),
    );
  }

  /**
   * Registers one chat request in the flood backend.
   */
  private function registerChatRequest(Request $request): void {
    if ($this->getRateLimitRequests() <= 0) {
      return;
    }

    $this->flood->register(
      self::CHAT_FLOOD_EVENT,
      $this->getRateLimitWindow(),
      $this->getChatFloodIdentifier($request),
    );
  }

  /**
   * Gets the configured number of allowed chat requests.
   */
  private function getRateLimitRequests(): int {
    return max(0, (int) ($this->configFactoryService
      ->get('trotamundos_assistant.settings')
      ->get('rate_limit_requests') ?? $this->defaults->getRateLimitRequests()));
  }

  /**
   * Gets the configured chat rate limit window.
   */
  private function getRateLimitWindow(): int {
    return max(1, (int) ($this->configFactoryService
      ->get('trotamundos_assistant.settings')
      ->get('rate_limit_window') ?? $this->defaults->getRateLimitWindow()));
  }

  /**
   * Builds a stable flood identifier per user or visitor IP.
   */
  private function getChatFloodIdentifier(Request $request): string {
    if ($this->currentUser()->isAuthenticated()) {
      return 'uid:' . $this->currentUser()->id();
    }

    return 'ip:' . ($request->getClientIp() ?: 'unknown');
  }

  /**
   * Checks whether the current user can use the assistant.
   */
  private function isAssistantAccessAllowed(): bool {
    return $this->accessManager->isAllowed($this->currentUser());
  }

  /**
   * Gets the access denied chat response for the current user.
   */
  private function getAccessDeniedChatMessage(): string {
    return $this->accessManager->getAccessDeniedMessage($this->currentUser());
  }

  /**
   * Returns the stored messages for a thread.
   */
  public function history(Request $request): JsonResponse {
    if (!$this->isAssistantAccessAllowed()) {
      return new JsonResponse([
        'error' => $this->getAccessDeniedChatMessage(),
        'messages' => [],
        'result_html' => '',
      ], 403);
    }

    $thread_id = trim((string) $request->query->get('thread_id', ''));
    if ($thread_id === '') {
      return new JsonResponse([
        'messages' => [],
        'result_html' => '',
      ]);
    }

    if (!$this->isValidThreadId($thread_id)) {
      return new JsonResponse([
        'error' => 'Invalid thread ID.',
        'messages' => [],
        'result_html' => '',
      ], 400);
    }

    if (!$this->prepareAssistantRunner($thread_id)) {
      return new JsonResponse([
        'error' => $this->t('The configured assistant is not available.'),
      ], 500);
    }

    $this->sanitizeThreadHistory($thread_id);
    $session = $this->assistantRunner->getTempStore()->get($thread_id) ?? [];
    $messages = $this->normalizeHistoryMessages($session['messages'] ?? []);
    $last_assistant_message = $this->getLastMessageByRole($messages, 'assistant');
    $last_result_reply = $this->getLastResultReply($session);
    $skill = $last_result_reply
      ? $this->skillMatcher->matchProviderSelection($last_result_reply)
      : NULL;
    $rendered_result = $last_assistant_message
      ? $this->skillTemplateRenderer->renderWithAttachments($skill, $last_result_reply ?: $last_assistant_message['message'])
      : ['html' => '', 'ajax_commands' => []];

    return new JsonResponse([
      'thread_id' => $thread_id,
      'messages' => $messages,
      'skill' => $skill['machine_name'] ?? NULL,
      'machine_name' => $skill['machine_name'] ?? 'default',
      'btn_responsive_title' => $this->getResponsiveButtonTitle($skill),
      'result_html' => $rendered_result['html'],
      'result_ajax_commands' => $rendered_result['ajax_commands'],
    ]);
  }

  /**
   * Gets the responsive panel button label declared by a skill.
   *
   * @param array<string, mixed>|null $skill
   *   Matched skill metadata.
   */
  private function getResponsiveButtonTitle(?array $skill): string {
    return trim((string) ($skill['btn_responsive_title'] ?? ''));
  }

  /**
   * Resets a stored assistant thread.
   */
  public function reset(Request $request): JsonResponse {
    if (!$this->isAssistantAccessAllowed()) {
      return new JsonResponse([
        'error' => $this->getAccessDeniedChatMessage(),
      ], 403);
    }

    if (!$this->validateCsrfToken($request)) {
      return new JsonResponse(['error' => 'Invalid security token.'], 403);
    }

    $data = Json::decode($request->getContent()) ?? [];
    $thread_id = trim((string) ($data['thread_id'] ?? ''));

    if ($thread_id !== '' && !$this->isValidThreadId($thread_id)) {
      return new JsonResponse(['error' => 'Invalid thread ID.'], 400);
    }

    if (!$this->prepareAssistantRunner($thread_id ?: NULL)) {
      return new JsonResponse([
        'error' => $this->t('The configured assistant is not available.'),
      ], 500);
    }

    if ($thread_id !== '') {
      try {
        $this->assistantRunner->getTempStore()->delete($thread_id);
        $this->clearAgentExecutionState($thread_id);
      }
      catch (\Throwable) {
        // Reset is best-effort: the frontend can safely start a new thread.
      }
    }

    $new_thread_id = $this->generateThreadId();
    $this->assistantRunner->setThreadsKey($new_thread_id);

    return new JsonResponse([
      'thread_id' => $new_thread_id,
      'messages' => [],
    ]);
  }

  /**
   * Generates a non-reusable thread id for a new embedded conversation.
   */
  private function generateThreadId(): string {
    $uid = (int) $this->currentUser()->id();
    $uuid = str_replace('-', '', (new Php())->generate());

    return sprintf('trotamundos_assistant_thread_%d_%s', $uid, $uuid);
  }

  /**
   * Validates that a thread ID matches the expected module format.
   */
  private function isValidThreadId(string $thread_id): bool {
    return (bool) preg_match('/^trotamundos_assistant_thread_\d+_[a-f0-9]{32}$/', $thread_id);
  }

  /**
   * Loads the configured assistant and prepares the shared runner.
   */
  private function prepareAssistantRunner(?string $thread_id = NULL): bool {
    $assistant = $this->entityTypeManagerService
      ->getStorage('ai_assistant')
      ->load($this->getAssistantId());

    if (!$assistant instanceof AiAssistant) {
      return FALSE;
    }

    $this->assistantRunner->setAssistant($assistant);
    $this->assistantRunner->startSession();

    if ($thread_id !== NULL && $thread_id !== '') {
      $this->assistantRunner->setThreadsKey($thread_id);
    }

    return TRUE;
  }

  /**
   * Gets the configured assistant id.
   */
  private function getAssistantId(): string {
    $assistant_id = trim((string) $this->configFactoryService
      ->get('trotamundos_assistant.settings')
      ->get('assistant_id'));

    return $assistant_id ?: $this->defaults->getAssistantId();
  }

  /**
   * Builds the user-facing error message from the configured Assistant.
   */
  private function buildConfiguredAssistantErrorMessage(\Throwable $throwable): string {
    if ($this->isAssistantRoleDeniedException($throwable)) {
      return $this->getAccessDeniedChatMessage();
    }

    $config = $this->configFactoryService->get('ai_assistant_api.ai_assistant.' . $this->getAssistantId());
    $message = trim((string) ($config->get('error_message') ?: ''));
    if ($message === '') {
      $message = (string) $this->t('Se produjo un error al generar la respuesta. Inténtalo de nuevo.');
    }

    $specific_messages = $config->get('specific_error_messages');
    $specific_messages = is_array($specific_messages) ? $specific_messages : [];

    foreach (self::SPECIFIC_ERROR_MESSAGE_KEYS as $exception_class => $message_key) {
      if ($throwable instanceof $exception_class) {
        $specific_message = trim((string) ($specific_messages[$message_key] ?? ''));
        if ($specific_message !== '') {
          $message = $specific_message;
        }
        break;
      }
    }

    return str_replace('[error_message]', $throwable->getMessage(), $message);
  }

  /**
   * Checks whether AI Assistant API rejected the request by its role gate.
   */
  private function isAssistantRoleDeniedException(\Throwable $throwable): bool {
    return $throwable->getMessage() === self::ASSISTANT_ROLE_DENIED_EXCEPTION_MESSAGE;
  }

  /**
   * Validates the CSRF request header used by AJAX endpoints.
   */
  private function validateCsrfToken(Request $request): bool {
    if ($request->isMethodSafe()) {
      return TRUE;
    }

    $token = (string) $request->headers->get('X-CSRF-Token', '');

    return $this->csrfToken->validate($token, CsrfRequestHeaderAccessCheck::TOKEN_KEY)
      || $this->csrfToken->validate($token, 'rest');
  }

  /**
   * Clears persisted AI Agent execution state for a thread.
   */
  private function clearAgentExecutionState(string $thread_id): void {
    if ($thread_id === '') {
      return;
    }

    try {
      $this->tempStoreFactory
        ->get('ai_assistant_threads')
        ->delete($thread_id);
    }
    catch (\Throwable) {
      // Agent temp state is best-effort; the assistant history remains intact.
    }
  }

  /**
   * Checks whether a matched skill only produces plain text.
   *
   * `default` renders a title and a description, and an unmatched answer falls
   * back to the same template, so both are fully carried by the chat bubble.
   *
   * @param array<string, mixed>|null $skill
   *   Matched skill definition.
   */
  private function isGenericSkill(?array $skill): bool {
    return $skill === NULL || (string) ($skill['machine_name'] ?? '') === 'default';
  }

  /**
   * Builds the short chat answer while the full answer feeds the visual result.
   */
  private function buildConciseReply(mixed $skill, string $assistant_reply): string {
    $reply = $this->skillTemplateRenderer->buildChatReply(is_array($skill) ? $skill : NULL, $assistant_reply);
    if ($reply === '') {
      $reply = $this->extractFirstSentence($assistant_reply);
    }

    return $this->limitReply((string) $reply);
  }

  /**
   * Builds the user-facing reply for empty provider outputs.
   */
  private function buildAssistantLimitFallbackReply(): string {
    return (string) $this->t('La consulta es demasiado exigente para resolverla de una sola vez. Prueba a concretarla un poco más.');
  }

  /**
   * Builds a structured default payload for empty provider outputs.
   */
  private function buildAssistantLimitFallbackPayload(string $message): string {
    return Json::encode([
      'skill' => 'default',
      'chat_reply' => $message,
      'result' => [
        'title' => (string) $this->t('Consulta demasiado exigente'),
        'description' => $message,
      ],
    ]);
  }

  /**
   * Extracts a short fallback sentence from the full assistant answer.
   */
  private function extractFirstSentence(string $text): string {
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
    if ($text === '') {
      return (string) $this->t('He preparado la respuesta en el panel.');
    }

    $parts = preg_split('/(?<=[.!?])\s+/u', $text, 2) ?: [];

    return $this->limitReply($parts[0] ?? $text);
  }

  /**
   * Keeps chat answers concise.
   */
  private function limitReply(string $text, int $max_length = 140): string {
    $text = trim($text);
    if (mb_strlen($text) <= $max_length) {
      return $text;
    }

    return rtrim(mb_substr($text, 0, $max_length - 3), " \t\n\r\0\x0B.,;:") . '...';
  }

  /**
   * Gets the last full result reply stored for a thread.
   */
  private function getLastResultReply(array $session): string {
    $results = $session['trotamundos_assistant_results'] ?? [];
    if (!is_array($results) || $results === []) {
      return '';
    }

    $last = end($results);

    $reply = is_array($last) ? (string) ($last['reply'] ?? '') : '';

    return $this->containsInternalSkillInstructions($reply) ? '' : $reply;
  }

  /**
   * Removes an old internal prompt from persisted chat history.
   *
   * Older requests stored the full provider prompt as a user message. An LLM
   * could also have echoed that prompt as an assistant response. Clean both
   * forms so reloads and follow-up turns cannot surface it again. Result
   * payloads are cleaned too, because they are used to reconstruct the panel.
   */
  private function sanitizeThreadHistory(string $thread_id): void {
    $session = $this->assistantRunner->getTempStore()->get($thread_id) ?? [];
    $changed = FALSE;
    $messages = is_array($session['messages'] ?? NULL) ? $session['messages'] : [];
    foreach ($messages as $index => $stored_message) {
      if (!is_array($stored_message)) {
        continue;
      }

      $role = (string) ($stored_message['role'] ?? '');
      $text = (string) ($stored_message['message'] ?? '');
      if (!$this->containsInternalSkillInstructions($text)) {
        continue;
      }

      if ($role === 'user') {
        $session['messages'][$index]['message'] = $this->stripInternalSkillInstructions($text);
      }
      elseif ($role === 'assistant') {
        $session['messages'][$index]['message'] = (string) $this->t('No se pudo procesar la respuesta anterior. Vuelve a enviar la consulta.');
      }
      else {
        continue;
      }
      $changed = TRUE;
    }

    if (is_array($session['trotamundos_assistant_results'] ?? NULL)) {
      $clean_results = array_values(array_filter(
        $session['trotamundos_assistant_results'],
        fn (mixed $result): bool => !is_array($result)
          || !$this->containsInternalSkillInstructions((string) ($result['reply'] ?? '')),
      ));
      if ($clean_results !== $session['trotamundos_assistant_results']) {
        $session['trotamundos_assistant_results'] = $clean_results;
        $changed = TRUE;
      }
    }

    if ($changed) {
      $this->assistantRunner->getTempStore()->set($thread_id, $session);
    }
  }

  /**
   * Checks whether text contains the private skill prompt marker.
   */
  private function containsInternalSkillInstructions(string $text): bool {
    return str_contains($text, self::SKILL_INSTRUCTIONS_MARKER);
  }

  /**
   * Keeps only the visible user text before the first private prompt block.
   */
  private function stripInternalSkillInstructions(string $text): string {
    $markers = [
      'COLECCIÓN ACTIVA (productos ya ofrecidos en este hilo):',
      self::SKILL_INSTRUCTIONS_MARKER,
    ];
    $positions = [];
    foreach ($markers as $marker) {
      $position = strpos($text, $marker);
      if ($position !== FALSE) {
        $positions[] = $position;
      }
    }

    if ($positions === []) {
      return trim($text);
    }

    return rtrim(substr($text, 0, min($positions)));
  }

  /**
   * Replaces the last stored user message with the visible chat text.
   */
  private function replaceLastUserMessage(string $thread_id, string $message): void {
    $session = $this->assistantRunner->getTempStore()->get($thread_id) ?? [];
    if (empty($session['messages']) || !is_array($session['messages'])) {
      return;
    }

    for ($index = count($session['messages']) - 1; $index >= 0; $index--) {
      if (($session['messages'][$index]['role'] ?? '') === 'user') {
        $session['messages'][$index]['message'] = $message;
        $this->assistantRunner->getTempStore()->set($thread_id, $session);
        return;
      }
    }
  }

  /**
   * Replaces the last stored assistant message with the concise chat answer.
   */
  private function replaceLastAssistantMessage(string $thread_id, string $chat_reply): void {
    $session = $this->assistantRunner->getTempStore()->get($thread_id) ?? [];
    if (empty($session['messages']) || !is_array($session['messages'])) {
      return;
    }

    for ($index = count($session['messages']) - 1; $index >= 0; $index--) {
      if (($session['messages'][$index]['role'] ?? '') === 'assistant') {
        $session['messages'][$index]['message'] = $chat_reply;
        $this->assistantRunner->getTempStore()->set($thread_id, $session);
        return;
      }
    }
  }

  /**
   * Stores the full assistant answer separately for result reconstruction.
   */
  private function storeResultReply(string $thread_id, string $assistant_reply): void {
    $session = $this->assistantRunner->getTempStore()->get($thread_id) ?? [];
    $session['trotamundos_assistant_results'] ??= [];
    $session['trotamundos_assistant_results'][] = [
      'reply' => $assistant_reply,
      'timestamp' => $this->time->getRequestTime(),
    ];
    $session['trotamundos_assistant_results'] = array_slice($session['trotamundos_assistant_results'], -20);

    $this->assistantRunner->getTempStore()->set($thread_id, $session);
  }

  /**
   * Normalizes tempstore history messages for the frontend.
   *
   * @param array<int, array<string, mixed>> $messages
   *   Stored messages.
   *
   * @return array<int, array<string, string>>
   *   Frontend-safe messages.
   */
  private function normalizeHistoryMessages(array $messages): array {
    $normalized = [];

    foreach ($messages as $message) {
      $role = (string) ($message['role'] ?? '');
      $text = trim((string) ($message['message'] ?? ''));
      if (!in_array($role, ['assistant', 'user'], TRUE) || $text === '') {
        continue;
      }

      $normalized[] = [
        'role' => $role,
        'message' => $text,
      ];
    }

    return $normalized;
  }

  /**
   * Gets the last normalized message for a role.
   *
   * @param array<int, array<string, string>> $messages
   *   Normalized messages.
   * @param string $role
   *   Message role to search.
   */
  private function getLastMessageByRole(array $messages, string $role): ?array {
    for ($index = count($messages) - 1; $index >= 0; $index--) {
      if (($messages[$index]['role'] ?? '') === $role) {
        return $messages[$index];
      }
    }

    return NULL;
  }

}
