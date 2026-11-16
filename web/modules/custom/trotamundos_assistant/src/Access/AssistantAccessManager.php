<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Access;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\trotamundos_assistant\Config\TrotamundosAssistantDefaults;

/**
 * Centralizes frontend access rules for the assistant and its actions.
 */
final class AssistantAccessManager {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TrotamundosAssistantDefaults $defaults,
  ) {
  }

  /**
   * Checks whether an account can use the assistant frontend.
   */
  public function isAllowed(AccountInterface $account): bool {
    if ($account->id() === '1') {
      return TRUE;
    }

    $roles = $this->getAssistantRoles();
    if ($roles === NULL) {
      return FALSE;
    }
    if ($roles === []) {
      return TRUE;
    }

    if ($account->isAnonymous()) {
      return isset($roles['anonymous']);
    }

    foreach ($account->getRoles() as $role_id) {
      if (!empty($roles[$role_id])) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Gets the access denied message for the account.
   */
  public function getAccessDeniedMessage(AccountInterface $account): string {
    $settings = $this->configFactory->get('trotamundos_assistant.settings');
    $message = $account->isAnonymous()
      ? trim((string) $settings->get('login_required_message'))
      : trim((string) $settings->get('role_denied_chat_message'));

    if ($message !== '') {
      return $message;
    }

    return $account->isAnonymous()
      ? $this->defaults->getLoginRequiredMessage()
      : $this->defaults->getRoleDeniedChatMessage();
  }

  /**
   * Gets the roles configured on the selected AI Assistant.
   *
   * @return array<string, bool>|null
   *   Selected role IDs keyed by role ID, or NULL if the assistant cannot be
   *   loaded.
   */
  private function getAssistantRoles(): ?array {
    $assistant_id = trim((string) $this->configFactory
      ->get('trotamundos_assistant.settings')
      ->get('assistant_id'));
    if ($assistant_id === '' || !$this->entityTypeManager->hasDefinition('ai_assistant')) {
      return NULL;
    }

    $assistant = $this->entityTypeManager
      ->getStorage('ai_assistant')
      ->load($assistant_id);
    if (!$assistant || !method_exists($assistant, 'get')) {
      return NULL;
    }

    $roles = $assistant->get('roles');
    if (!is_array($roles)) {
      return NULL;
    }

    $selected = [];
    foreach ($roles as $role_id => $enabled) {
      if ($enabled) {
        $selected[(string) $role_id] = TRUE;
      }
    }

    return $selected;
  }

}
