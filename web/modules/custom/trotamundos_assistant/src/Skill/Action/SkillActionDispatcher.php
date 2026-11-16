<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Skill\Action;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\trotamundos_assistant\Access\AssistantAccessManager;
use Drupal\trotamundos_assistant\Skill\SkillClassLoader;
use Drupal\trotamundos_assistant\Skill\SkillMatcher;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dispatches generic skill action routes to skill-declared action handlers.
 */
final class SkillActionDispatcher implements ContainerInjectionInterface {

  /**
   * HTTP methods that are allowed in skill action metadata.
   */
  private const ALLOWED_HTTP_METHODS = ['GET', 'POST'];

  public function __construct(
    private readonly SkillMatcher $skillMatcher,
    private readonly AccountProxyInterface $currentUser,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly ContainerInterface $container,
    private readonly SkillClassLoader $classLoader,
    private readonly AssistantAccessManager $accessManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('trotamundos_assistant.skill_matcher'),
      $container->get('current_user'),
      $container->get('csrf_token'),
      $container,
      $container->get('trotamundos_assistant.skill_class_loader'),
      $container->get('trotamundos_assistant.access_manager'),
    );
  }

  /**
   * Dispatches a generic skill action.
   */
  public function handle(string $machine_name, string $action, Request $request): JsonResponse {
    if (!$this->accessManager->isAllowed($this->currentUser)) {
      return new JsonResponse([
        'error' => $this->accessManager->getAccessDeniedMessage($this->currentUser),
      ], 403);
    }

    $skill = $this->skillMatcher->getByReference($machine_name, TRUE);
    if (!$skill) {
      return new JsonResponse(['error' => 'Skill not found.'], 404);
    }

    $metadata = is_array($skill['metadata'] ?? NULL) ? $skill['metadata'] : [];
    $actions = is_array($metadata['actions'] ?? NULL) ? $metadata['actions'] : [];
    $definition = is_array($actions[$action] ?? NULL) ? $actions[$action] : [];
    if ($definition === []) {
      return new JsonResponse(['error' => 'Skill action not found.'], 404);
    }

    $http_method_error = $this->validateHttpMethod($definition, $request);
    if ($http_method_error instanceof JsonResponse) {
      return $http_method_error;
    }

    if (!empty($definition['csrf'])) {
      $token = (string) $request->headers->get('X-CSRF-Token', '');
      if (!$this->csrfToken->validate($token, CsrfRequestHeaderAccessCheck::TOKEN_KEY)
        && !$this->csrfToken->validate($token, 'rest')) {
        return new JsonResponse(['error' => 'Invalid security token.'], 403);
      }
    }

    $method = (string) ($definition['method'] ?? '');
    $handler = $this->resolveHandler($skill, $definition);
    if (!$handler instanceof SkillActionHandlerInterface || !method_exists($handler, $method)) {
      return new JsonResponse(['error' => 'Invalid skill action handler.'], 500);
    }

    return $handler->{$method}($request, $this->currentUser);
  }

  /**
   * Validates the HTTP contract declared by the skill action.
   *
   * @param array<string, mixed> $definition
   *   Skill action metadata.
   */
  private function validateHttpMethod(array $definition, Request $request): ?JsonResponse {
    $declared_method = strtoupper(trim((string) ($definition['http_method'] ?? '')));
    $csrf_required = !empty($definition['csrf']);

    if ($declared_method === '') {
      return new JsonResponse(['error' => 'Skill action HTTP method is not configured.'], 500);
    }

    if (!in_array($declared_method, self::ALLOWED_HTTP_METHODS, TRUE)) {
      return new JsonResponse(['error' => 'Skill action HTTP method is not supported.'], 500);
    }

    if ($csrf_required && $declared_method !== 'POST') {
      return new JsonResponse(['error' => 'Skill action with CSRF must use POST.'], 500);
    }

    if ($request->getMethod() !== $declared_method) {
      return new JsonResponse(['error' => 'HTTP method not allowed for this skill action.'], 405);
    }

    return NULL;
  }

  /**
   * Resolves the action handler declared by the skill.
   *
   * @param array<string, mixed> $skill
   *   Skill definition.
   * @param array<string, mixed> $definition
   *   Action definition.
   */
  private function resolveHandler(array $skill, array $definition): ?SkillActionHandlerInterface {
    $service_id = (string) ($definition['service'] ?? '');
    if ($service_id !== '' && $this->container->has($service_id)) {
      $handler = $this->container->get($service_id);
      return $handler instanceof SkillActionHandlerInterface ? $handler : NULL;
    }

    $this->classLoader->requireFile($skill, (string) ($definition['file'] ?? ''));
    $class = (string) ($definition['class'] ?? '');
    if ($class === '' || !is_subclass_of($class, SkillActionHandlerInterface::class)) {
      return NULL;
    }

    $arguments = [];
    foreach ($this->normalizeServiceIds($definition['arguments'] ?? []) as $service_id) {
      if (!$this->container->has($service_id)) {
        return NULL;
      }
      $arguments[] = $this->container->get($service_id);
    }

    return new $class(...$arguments);
  }

  /**
   * Normalizes service IDs declared as action constructor arguments.
   *
   * @return array<int, string>
   *   Service IDs.
   */
  private function normalizeServiceIds(mixed $arguments): array {
    if (!is_array($arguments)) {
      return [];
    }

    return array_values(array_filter(array_map(
      static fn(mixed $argument): string => ltrim((string) $argument, '@'),
      $arguments,
    )));
  }

}
