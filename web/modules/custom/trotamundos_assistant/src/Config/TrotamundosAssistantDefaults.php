<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Config;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Centralizes fallback values used by the Trotamundos Assistant module.
 */
final class TrotamundosAssistantDefaults {

  public const WELCOME_MESSAGE = 'Bienvenido a tu nueva manera de navegar. ¿Qué necesitas?';
  public const INPUT_PLACEHOLDER = 'Escribe tu consulta...';
  public const PRIVACY_NOTICE = 'Al enviar una consulta aceptas que se procese para generar la respuesta del asistente. No incluyas datos personales, confidenciales o de pago.';
  public const ASSISTANT_ID = 'minsait_buscador_nodes';
  public const LOGIN_REQUIRED_MESSAGE = 'Para usar el asistente de catálogo debes estar logado.';
  public const ROLE_DENIED_CHAT_MESSAGE = 'No tienes permisos para usar este asistente.';
  public const USE_MINSAIT_FONT = TRUE;
  public const RESULT_TARGET_SELECTOR = '#trotamundos-assistant-result-panel';
  public const HEADER_STICKY = 'header';
  public const EXTRA_SHIFT_SELECTORS = 'footer';
  public const DESKTOP_ALWAYS_OPEN = FALSE;
  public const TOGGLE_LABEL = 'Trotamundos Assistant';
  public const CHAT_LOGO_PATH = '/modules/custom/trotamundos_assistant/assets/images/minsait-chatbot-logo-head.png';
  public const ADMIN_CURRENCY = 'USD';
  public const PRICING_CURRENCY = 'USD';
  public const ENABLED_SKILLS = ['default'];
  public const MAX_MESSAGE_LENGTH = 800;
  public const RATE_LIMIT_REQUESTS = 20;
  public const RATE_LIMIT_WINDOW = 3600;
  public const PRICING_LAST_SAVED = 1779789600;

  /**
   * Decoded install settings cache.
   *
   * @var array<string, mixed>|null
   */
  private ?array $installSettings = NULL;

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {
  }

  public function getWelcomeMessage(): string {
    return self::WELCOME_MESSAGE;
  }

  public function getInputPlaceholder(): string {
    return self::INPUT_PLACEHOLDER;
  }

  public function getPrivacyNotice(): string {
    return self::PRIVACY_NOTICE;
  }

  public function getAssistantId(): string {
    return self::ASSISTANT_ID;
  }

  public function getLoginRequiredMessage(): string {
    return self::LOGIN_REQUIRED_MESSAGE;
  }

  public function getRoleDeniedChatMessage(): string {
    return self::ROLE_DENIED_CHAT_MESSAGE;
  }

  public function useMinsaitFont(): bool {
    return self::USE_MINSAIT_FONT;
  }

  public function getResultTargetSelector(): string {
    return self::RESULT_TARGET_SELECTOR;
  }

  public function getHeaderSticky(): string {
    return self::HEADER_STICKY;
  }

  public function getExtraShiftSelectors(): string {
    return self::EXTRA_SHIFT_SELECTORS;
  }

  public function desktopAlwaysOpen(): bool {
    return self::DESKTOP_ALWAYS_OPEN;
  }

  public function getToggleLabel(): string {
    return self::TOGGLE_LABEL;
  }

  public function getChatLogoPath(): string {
    return self::CHAT_LOGO_PATH;
  }

  public function getAdminCurrency(): string {
    return self::ADMIN_CURRENCY;
  }

  public function getPricingCurrency(): string {
    return self::PRICING_CURRENCY;
  }

  /**
   * @return string[]
   *   Default enabled skill machine names.
   */
  public function getEnabledSkills(): array {
    return self::ENABLED_SKILLS;
  }

  public function getMaxMessageLength(): int {
    return self::MAX_MESSAGE_LENGTH;
  }

  public function getRateLimitRequests(): int {
    return self::RATE_LIMIT_REQUESTS;
  }

  public function getRateLimitWindow(): int {
    return self::RATE_LIMIT_WINDOW;
  }

  public function getPricingLastSaved(): int {
    $timestamp = $this->getInstallSettings()['pricing_last_saved'] ?? self::PRICING_LAST_SAVED;
    return (int) $timestamp;
  }

  /**
   * Gets the default model pricing rows.
   *
   * The install config is the canonical source for the editable pricing table.
   *
   * @return array<int, array<string, mixed>>
   *   Default model pricing rows.
   */
  public function getModelPricing(): array {
    $pricing = $this->getInstallSettings()['model_pricing'] ?? [];
    return is_array($pricing) ? $pricing : [];
  }

  public function getInstallSettingsPath(): string {
    return DRUPAL_ROOT . '/' . $this->moduleExtensionList->getPath('trotamundos_assistant') . '/config/install/trotamundos_assistant.settings.yml';
  }

  /**
   * @return array<string, mixed>
   *   Decoded install settings.
   */
  private function getInstallSettings(): array {
    if ($this->installSettings !== NULL) {
      return $this->installSettings;
    }

    $path = $this->getInstallSettingsPath();
    if (!is_readable($path)) {
      $this->installSettings = [];
      return $this->installSettings;
    }

    $settings = Yaml::decode((string) file_get_contents($path));
    $this->installSettings = is_array($settings) ? $settings : [];

    return $this->installSettings;
  }

}
