<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\trotamundos_assistant\Access\AssistantAccessManager;
use Drupal\trotamundos_assistant\Config\TrotamundosAssistantDefaults;
use Drupal\trotamundos_assistant\Skill\SkillMatcher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Trotamundos Assistant block.
 */
#[Block(
  id: 'trotamundos_assistant',
  admin_label: new TranslatableMarkup('Trotamundos Assistant'),
  category: new TranslatableMarkup('Minsait')
)]
final class TrotamundosAssistantBlock extends BlockBase implements ContainerFactoryPluginInterface, TrustedCallbackInterface {

  /**
   * Constructs a TrotamundosAssistantBlock instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly RequestStack $requestStack,
    private readonly SkillMatcher $skillMatcher,
    private readonly AssistantAccessManager $accessManager,
    private readonly TrotamundosAssistantDefaults $defaults,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('trotamundos_assistant.skill_matcher'),
      $container->get('trotamundos_assistant.access_manager'),
      $container->get('trotamundos_assistant.defaults'),
    );
  }

  /**
   * Builds the search assistant outside the shared page cache.
   */
  public static function buildSearch(): array {
    return \Drupal::service('plugin.manager.block')
      ->createInstance('trotamundos_assistant', ['search_inline' => TRUE])
      ->build();
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['buildSearch'];
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $search_inline = !empty($this->configuration['search_inline']);
    $endpoint = Url::fromRoute('trotamundos_assistant.chat')->toString();
    $history_endpoint = Url::fromRoute('trotamundos_assistant.chat_history')->toString();
    $reset_endpoint = Url::fromRoute('trotamundos_assistant.chat_reset')->toString();
    $access_allowed = $this->isAssistantAccessAllowed();
    if (!$search_inline && $access_allowed && $this->currentUser->isAnonymous()) {
      $request = $this->requestStack->getCurrentRequest();
      if ($request && $request->hasSession()) {
        $request->getSession()->set('trotamundos_assistant_anonymous', TRUE);
      }
    }
    $access_denied_message = $this->getAccessDeniedMessage();
    $welcome_message = $this->getWelcomeMessage();
    $input_placeholder = $this->getInputPlaceholder();
    $privacy_notice = $this->getPrivacyNotice();
    $result_target_selector = $this->getResultTargetSelector();
    $header_sticky = $this->getHeaderSticky();
    $extra_shift_selectors = $this->getExtraShiftSelectors();
    $desktop_always_open = $this->desktopAlwaysOpen();
    $toggle_label = $this->getToggleLabel();
    $chat_logo_path = $this->getChatLogoPath();

    $libraries = [
      'trotamundos_assistant/assistant',
    ];
    array_push($libraries, ...$this->getEnabledSkillLibraries());
    if ($this->useMinsaitFont()) {
      $libraries[] = 'trotamundos_assistant/font';
    }
    $libraries = array_values(array_unique($libraries));

    return [
      '#theme' => $search_inline ? 'trotamundos_assistant__search' : 'trotamundos_assistant',
      '#search_inline' => $search_inline,
      '#welcome_message' => $welcome_message,
      '#input_placeholder' => $input_placeholder,
      '#privacy_notice' => $privacy_notice,
      '#model_id' => $this->getAssistantModelId(),
      '#result_target_selector' => $result_target_selector,
      '#desktop_always_open' => $desktop_always_open,
      '#toggle_label' => $toggle_label,
      '#chat_logo_path' => $chat_logo_path,
      '#configuration_error' => !$search_inline && $result_target_selector === ''
        ? $this->t('Falta configurar el selector del panel de resultado del asistente.')
        : '',
      'result' => [
        '#theme' => 'trotamundos_assistant_welcome',
        '#title' => $this->t('Tu asistente de viaje'),
        '#intro' => $access_allowed
          ? $this->t('Descubre lugares y planes de Colombia de la mano de comunidades locales.')
          : $access_denied_message,
        '#features' => $access_allowed ? [
          [
            'label' => $this->t('Descubrir'),
            'text' => $this->t('Encuentra alojamientos, experiencias y paquetes según tus intereses.'),
          ],
          [
            'label' => $this->t('Orientarte'),
            'text' => $this->t('Consulta destinos y guías publicados en el sitio.'),
          ],
          [
            'label' => $this->t('Preparar'),
            'text' => $this->t('Revisa cada ficha para confirmar precios, fechas y condiciones.'),
          ],
        ] : [],
        '#suggestions' => $access_allowed ? [
          $this->t('Recomiéndame experiencias de naturaleza en Antioquia'),
          $this->t('¿Qué alojamientos hay para viajar en pareja?'),
          $this->t('Cuéntame sobre los destinos de café y tradición'),
        ] : [],
      ],
      '#access_allowed' => $access_allowed,
      '#access_denied_message' => $access_denied_message,
      '#attached' => [
        'library' => $libraries,
        'drupalSettings' => [
          'trotamundosAssistant' => [
            'endpoint' => $endpoint,
            'historyEndpoint' => $history_endpoint,
            'resetEndpoint' => $reset_endpoint,
            'csrfTokenEndpoint' => Url::fromRoute('trotamundos_assistant.session_token')->toString(),
            'welcomeMessage' => $welcome_message,
            'accessAllowed' => $access_allowed,
            'resultTargetSelector' => $result_target_selector,
            'headerSticky' => $header_sticky,
            'extraShiftSelectors' => $extra_shift_selectors,
            'desktopAlwaysOpen' => $desktop_always_open,
            'toggleLabel' => $toggle_label,
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Gets the configured welcome message.
   */
  private function getWelcomeMessage(): string {
    $welcome_message = trim((string) $this->configFactory
      ->get('trotamundos_assistant.settings')
      ->get('welcome_message'));

    return $welcome_message ?: $this->defaults->getWelcomeMessage();
  }

  /**
   * Gets the configured message input placeholder.
   */
  private function getInputPlaceholder(): string {
    $input_placeholder = trim((string) $this->configFactory
      ->get('trotamundos_assistant.settings')
      ->get('input_placeholder'));

    return $input_placeholder ?: $this->defaults->getInputPlaceholder();
  }

  /**
   * Gets the configured privacy notice.
   */
  private function getPrivacyNotice(): string {
    $privacy_notice = trim((string) $this->configFactory
      ->get('trotamundos_assistant.settings')
      ->get('privacy_notice'));

    return $privacy_notice ?: $this->defaults->getPrivacyNotice();
  }

  /**
   * Gets the model configured for the active assistant.
   */
  private function getAssistantModelId(): string {
    $settings = $this->configFactory->get('trotamundos_assistant.settings');
    $assistant_id = trim((string) $settings->get('assistant_id'));
    if ($assistant_id === '') {
      $assistant_id = $this->defaults->getAssistantId();
    }

    return trim((string) $this->configFactory
      ->get('ai_assistant_api.ai_assistant.' . $assistant_id)
      ->get('llm_model'));
  }

  /**
   * Determines whether the Minsait typography should be loaded.
   */
  private function useMinsaitFont(): bool {
    return $this->configFactory
      ->get('trotamundos_assistant.settings')
      ->get('use_minsait_font') ?? $this->defaults->useMinsaitFont();
  }

  /**
   * Gets the configured external result target selector.
   */
  private function getResultTargetSelector(): string {
    $selector = $this->configFactory
      ->get('trotamundos_assistant.settings')
      ->get('result_target_selector');

    return $selector === NULL
      ? $this->defaults->getResultTargetSelector()
      : trim((string) $selector);
  }

  /**
   * Gets the configured sticky header selector.
   */
  private function getHeaderSticky(): string {
    $selector = $this->configFactory
      ->get('trotamundos_assistant.settings')
      ->get('header_sticky');

    return $selector === NULL
      ? $this->defaults->getHeaderSticky()
      : trim((string) $selector);
  }

  /**
   * Gets additional selectors shifted when the chat opens.
   */
  private function getExtraShiftSelectors(): string {
    $selectors = $this->configFactory
      ->get('trotamundos_assistant.settings')
      ->get('extra_shift_selectors');

    return $selectors === NULL
      ? $this->defaults->getExtraShiftSelectors()
      : trim((string) $selectors);
  }

  /**
   * Determines whether the chat should start open on desktop.
   */
  private function desktopAlwaysOpen(): bool {
    return (bool) ($this->configFactory
      ->get('trotamundos_assistant.settings')
      ->get('desktop_always_open') ?? $this->defaults->desktopAlwaysOpen());
  }

  /**
   * Gets the configured chat toggle label.
   */
  private function getToggleLabel(): string {
    $label = trim((string) $this->configFactory
      ->get('trotamundos_assistant.settings')
      ->get('toggle_label'));

    return $label ?: $this->defaults->getToggleLabel();
  }

  /**
   * Gets the configured chat logo path.
   */
  private function getChatLogoPath(): string {
    $path = trim((string) $this->configFactory
      ->get('trotamundos_assistant.settings')
      ->get('chat_logo_path'));

    return $path ?: $this->defaults->getChatLogoPath();
  }

  /**
   * Gets the libraries declared by enabled skills.
   *
   * @return string[]
   *   Library names.
   */
  private function getEnabledSkillLibraries(): array {
    $libraries = [];

    foreach ($this->skillMatcher->loadAll() as $skill) {
      $metadata = is_array($skill['metadata'] ?? NULL) ? $skill['metadata'] : [];
      $declared_libraries = is_array($metadata['libraries'] ?? NULL) ? $metadata['libraries'] : [];

      foreach ($declared_libraries as $library) {
        $library = trim((string) $library);
        if ($library !== '') {
          $libraries[] = $library;
        }
      }
    }

    return array_values(array_unique($libraries));
  }

  /**
   * Checks whether the current user can use the assistant.
   */
  private function isAssistantAccessAllowed(): bool {
    return $this->accessManager->isAllowed($this->currentUser);
  }

  /**
   * Gets the access denied message for the current user.
   */
  private function getAccessDeniedMessage(): string {
    return $this->accessManager->getAccessDeniedMessage($this->currentUser);
  }

}
