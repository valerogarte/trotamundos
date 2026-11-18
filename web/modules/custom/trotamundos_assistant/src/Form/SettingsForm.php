<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\trotamundos_assistant\Admin\AdminPillsTrait;
use Drupal\trotamundos_assistant\Config\TrotamundosAssistantDefaults;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the Trotamundos Assistant settings form.
 */
final class SettingsForm extends ConfigFormBase {

  use AdminPillsTrait;

  /**
   * Constructs the settings form.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly TrotamundosAssistantDefaults $defaults,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
      $container->get('trotamundos_assistant.defaults'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'trotamundos_assistant_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [
      'trotamundos_assistant.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $settings = $this->config('trotamundos_assistant.settings');

    $form['#attached']['library'][] = 'trotamundos_assistant/usage_admin';
    $form['nav'] = $this->buildAdminPills('settings');
    $form['nav']['#weight'] = -100;

    $form['conversation'] = [
      '#type' => 'details',
      '#title' => $this->t('Asistente y conversación'),
      '#open' => TRUE,
    ];

    $form['conversation']['model'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Modelo'),
    ];

    $form['conversation']['model']['assistant_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Assistant ID'),
      '#description' => $this->t('AI Assistant que procesará las conversaciones del módulo.'),
      '#options' => $this->getAssistantOptions((string) ($settings->get('assistant_id') ?: $this->defaults->getAssistantId())),
      '#default_value' => $settings->get('assistant_id') ?: $this->defaults->getAssistantId(),
      '#required' => TRUE,
    ];

    $form['conversation']['copy'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Mensajes'),
    ];

    $form['conversation']['copy']['welcome_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Mensaje de bienvenida'),
      '#description' => $this->t('Mensaje mostrado como primera respuesta del asistente y al iniciar una nueva conversación.'),
      '#default_value' => $settings->get('welcome_message') ?: $this->defaults->getWelcomeMessage(),
      '#required' => TRUE,
      '#rows' => 3,
    ];

    $form['conversation']['copy']['input_placeholder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Placeholder del campo de consulta'),
      '#description' => $this->t('Texto mostrado dentro del campo donde el usuario escribe su consulta.'),
      '#default_value' => $settings->get('input_placeholder') ?: $this->defaults->getInputPlaceholder(),
      '#required' => TRUE,
      '#maxlength' => 120,
    ];

    $form['conversation']['copy']['privacy_notice'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Aviso legal y privacidad'),
      '#description' => $this->t('Texto mostrado junto al campo de consulta en la presentación estándar. En el buscador del tema Trotamundos se muestra un enlace a la política de privacidad si la página está disponible; si no, se conserva este aviso. Úsalo para indicar tratamiento de datos, límites del asistente y recomendaciones de no enviar información sensible.'),
      '#default_value' => $settings->get('privacy_notice') ?: $this->defaults->getPrivacyNotice(),
      '#required' => TRUE,
      '#rows' => 3,
    ];

    $form['access'] = [
      '#type' => 'details',
      '#title' => $this->t('Acceso y permisos'),
      '#open' => FALSE,
    ];

    $form['access']['roles'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Roles'),
    ];

    $form['access']['roles']['assistant_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles permitidos'),
      '#description' => $this->t('Estos son los roles configurados en el AI Assistant seleccionado. Si no se selecciona ninguno, el AI Assistant permite el acceso a todos los usuarios. El usuario 1 siempre está permitido.'),
      '#options' => $this->getRoleOptions(),
      '#default_value' => $this->getAssistantRoles((string) ($settings->get('assistant_id') ?: $this->defaults->getAssistantId())),
      '#multiple' => TRUE,
    ];

    $form['access']['messages'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Mensajes de acceso denegado'),
    ];

    $form['access']['messages']['login_required_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Mensaje para anónimos'),
      '#description' => $this->t('Texto mostrado en el panel "Asistente de catálogo" y devuelto por el chat cuando el rol anónimo no puede usar el asistente.'),
      '#default_value' => $settings->get('login_required_message') ?: $this->defaults->getLoginRequiredMessage(),
      '#required' => TRUE,
      '#rows' => 2,
    ];

    $form['access']['messages']['role_denied_chat_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Respuesta del chat sin rol permitido'),
      '#description' => $this->t('Texto devuelto cuando un usuario autenticado no tiene ninguno de los roles permitidos.'),
      '#default_value' => $settings->get('role_denied_chat_message') ?: $this->defaults->getRoleDeniedChatMessage(),
      '#required' => TRUE,
      '#rows' => 2,
    ];

    $form['appearance'] = [
      '#type' => 'details',
      '#title' => $this->t('Interfaz y layout'),
      '#open' => FALSE,
    ];

    $form['appearance']['layout'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Layout y desplazamiento'),
      '#description' => $this->t('Controla dónde se pintan los resultados y qué contenido se aparta cuando el chat se abre en escritorio.'),
    ];

    $form['appearance']['layout']['result_target_selector'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Selector del panel de resultado'),
      '#description' => $this->t('Selector CSS del contenedor externo que representa el contenido principal de la página y donde el asistente pintará los resultados de las skills tras una acción explícita. Debe existir en las páginas donde se muestre el bloque. Ejemplo recomendado: #trotamundos-assistant-result-panel.'),
      '#default_value' => $settings->get('result_target_selector') ?: $this->defaults->getResultTargetSelector(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['appearance']['layout']['header_sticky'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Selector del header sticky'),
      '#description' => $this->t('Selector CSS opcional del header fijo o sticky que debe quedar por encima del chat. El frontend medirá automáticamente su borde inferior y colocará el chat debajo. Por defecto: header. Déjalo vacío para no aplicar desplazamiento superior.'),
      '#default_value' => $settings->get('header_sticky') ?? $this->defaults->getHeaderSticky(),
      '#maxlength' => 255,
    ];

    $form['appearance']['layout']['extra_shift_selectors'] = [
      '#type' => 'textfield',
      '#title' => $this->t('¿Quieres desplazar algún otro contenido al abrir el chat?'),
      '#description' => $this->t('Selectores CSS de elementos adicionales que deben desplazarse al abrir el chat en escritorio. Sepáralos con ;. Por defecto: footer.'),
      '#default_value' => $settings->get('extra_shift_selectors') ?? $this->defaults->getExtraShiftSelectors(),
      '#maxlength' => 512,
    ];

    $form['appearance']['layout']['desktop_always_open'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mostrar siempre abierto (en desktop)'),
      '#description' => $this->t('Hace que el chat se cargue abierto en pantallas de escritorio. En móvil esta opción no se aplica: el chat siempre arranca cerrado como drawer superpuesto.'),
      '#default_value' => $settings->get('desktop_always_open') ?? $this->defaults->desktopAlwaysOpen(),
    ];

    $form['appearance']['chat_identity'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Identidad del chat'),
    ];

    $form['appearance']['chat_identity']['toggle_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Texto de la pestaña del chat'),
      '#description' => $this->t('Texto mostrado en la pestaña lateral visible cuando el chat está cerrado. Debe ser corto porque se muestra en vertical. Por defecto: Trotamundos Assistant.'),
      '#default_value' => $settings->get('toggle_label') ?: $this->defaults->getToggleLabel(),
      '#required' => TRUE,
      '#maxlength' => 40,
    ];

    $form['appearance']['chat_identity']['chat_logo_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ruta del logo del chat'),
      '#description' => $this->t('Ruta web del logo mostrado en la cabecera del chat. Puede ser una ruta interna que empiece por / o una URL http(s). Por defecto: /modules/custom/trotamundos_assistant/assets/images/minsait-chatbot-logo-head.png.'),
      '#default_value' => $settings->get('chat_logo_path') ?: $this->defaults->getChatLogoPath(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['appearance']['typography'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Tipografía'),
    ];

    $form['appearance']['typography']['use_minsait_font'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Usar tipografía Minsait'),
      '#description' => $this->t('Carga la tipografía corporativa ForFuture Sans en el chat, las pantallas de administración del módulo y los resultados renderizados por las skills. Desactívalo si el theme ya gestiona toda la tipografía o si quieres heredar la fuente global del sitio.'),
      '#default_value' => $settings->get('use_minsait_font') ?? $this->defaults->useMinsaitFont(),
    ];

    $form['limits'] = [
      '#type' => 'details',
      '#title' => $this->t('Límites de uso'),
      '#open' => FALSE,
    ];

    $form['limits']['max_message_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Longitud máxima del mensaje'),
      '#description' => $this->t('Número máximo de caracteres permitidos en cada consulta.'),
      '#default_value' => $settings->get('max_message_length') ?? $this->defaults->getMaxMessageLength(),
      '#min' => 1,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['limits']['rate_limit_requests'] = [
      '#type' => 'number',
      '#title' => $this->t('Consultas máximas'),
      '#description' => $this->t('Número máximo de consultas permitidas dentro de la ventana temporal. Usa 0 para desactivar este límite.'),
      '#default_value' => $settings->get('rate_limit_requests') ?? $this->defaults->getRateLimitRequests(),
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['limits']['rate_limit_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Ventana temporal del rate limit'),
      '#description' => $this->t('Duración de la ventana temporal en segundos. Por ejemplo, 3600 equivale a una hora.'),
      '#default_value' => $settings->get('rate_limit_window') ?? $this->defaults->getRateLimitWindow(),
      '#min' => 1,
      '#step' => 1,
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="rate_limit_requests"]' => ['!value' => '0'],
        ],
      ],
    ];

    $form['pricing'] = [
      '#type' => 'details',
      '#title' => $this->t('Costes y moneda'),
      '#open' => FALSE,
    ];

    $form['pricing']['display'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Visualización'),
    ];

    $form['pricing']['display']['admin_currency'] = [
      '#type' => 'select',
      '#title' => $this->t('Moneda administración'),
      '#description' => $this->t('Solo afecta a la visualización de importes en la página de Coste. Los datos se mantienen guardados en su moneda original.'),
      '#options' => [
        'USD' => $this->t('USD'),
        'EUR' => $this->t('€'),
      ],
      '#default_value' => $settings->get('admin_currency') ?: $this->defaults->getAdminCurrency(),
      '#required' => TRUE,
    ];

    $form['pricing']['models'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Precios de modelos'),
    ];

    $form['pricing']['models']['pricing_last_saved'] = [
      '#type' => 'item',
      '#title' => $this->t('Última revisión'),
      '#markup' => $this->t('La última vez que revisaste los precios fue @date.', [
        '@date' => $this->formatSpanishDate((int) ($settings->get('pricing_last_saved') ?: $this->defaults->getPricingLastSaved())),
      ]),
    ];

    $form['pricing']['models']['model_pricing_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Precios por modelo'),
      '#description' => $this->t('Un modelo por línea con el formato modelo|input|cached|output. Los precios son USD por millón de tokens y usan coma decimal.'),
      '#default_value' => $this->formatModelPricingText($settings->get('model_pricing') ?: $this->defaults->getModelPricing()),
      '#rows' => 8,
      '#required' => TRUE,
    ];

    if (($settings->get('use_minsait_font') ?? $this->defaults->useMinsaitFont()) !== FALSE) {
      $form['#attached']['library'][] = 'trotamundos_assistant/font';
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $pricing = $this->parseModelPricingText((string) $form_state->getValue('model_pricing_text'));
    if ($pricing === []) {
      $form_state->setErrorByName('model_pricing_text', $this->t('Debes configurar al menos un modelo con precios válidos.'));
      return;
    }

    $result_target_selector = trim((string) $form_state->getValue('result_target_selector'));
    if ($result_target_selector === '') {
      $form_state->setErrorByName('result_target_selector', $this->t('Debes indicar el selector CSS del panel de resultado.'));
    }
    elseif (!$this->isValidSimpleSelector($result_target_selector)) {
      $form_state->setErrorByName('result_target_selector', $this->t('Indica un selector CSS sencillo, por ejemplo #trotamundos-assistant-result-panel.'));
    }
    $form_state->setValue('result_target_selector', $result_target_selector);

    $header_sticky = trim((string) $form_state->getValue('header_sticky'));
    if ($header_sticky !== '' && !$this->isValidCssSelector($header_sticky)) {
      $form_state->setErrorByName('header_sticky', $this->t('Indica un selector CSS válido, por ejemplo header, .site-header o #header.'));
    }
    $form_state->setValue('header_sticky', $header_sticky);

    $extra_shift_selectors = $this->normalizeCssSelectorList((string) $form_state->getValue('extra_shift_selectors'));
    if ($extra_shift_selectors !== '' && !$this->isValidCssSelectorList($extra_shift_selectors)) {
      $form_state->setErrorByName('extra_shift_selectors', $this->t('Indica selectores CSS válidos separados por ;, por ejemplo footer; .site-footer.'));
    }
    $form_state->setValue('extra_shift_selectors', $extra_shift_selectors);

    $toggle_label = trim((string) $form_state->getValue('toggle_label'));
    if ($toggle_label === '') {
      $form_state->setErrorByName('toggle_label', $this->t('Debes indicar el texto de la pestaña del chat.'));
    }
    $form_state->setValue('toggle_label', $toggle_label);

    $input_placeholder = trim((string) $form_state->getValue('input_placeholder'));
    if ($input_placeholder === '') {
      $form_state->setErrorByName('input_placeholder', $this->t('Debes indicar el placeholder del campo de consulta.'));
    }
    $form_state->setValue('input_placeholder', $input_placeholder);

    $chat_logo_path = trim((string) $form_state->getValue('chat_logo_path'));
    if ($chat_logo_path === '') {
      $form_state->setErrorByName('chat_logo_path', $this->t('Debes indicar la ruta del logo del chat.'));
    }
    elseif (!$this->isValidLogoPath($chat_logo_path)) {
      $form_state->setErrorByName('chat_logo_path', $this->t('Indica una ruta web válida que empiece por / o por http(s)://.'));
    }
    $form_state->setValue('chat_logo_path', $chat_logo_path);

    $form_state->setValue('model_pricing', $pricing);

    $assistant_id = trim((string) $form_state->getValue('assistant_id'));
    if ($assistant_id === '' || !$this->loadAssistant($assistant_id)) {
      $form_state->setErrorByName('assistant_id', $this->t('Selecciona un AI Assistant válido para configurar sus roles.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $settings = $this->config('trotamundos_assistant.settings');
    $model_pricing = $form_state->getValue('model_pricing');
    $pricing_last_saved = (int) ($settings->get('pricing_last_saved') ?: $this->defaults->getPricingLastSaved());
    if ($this->normalizeModelPricing($settings->get('model_pricing') ?: []) !== $this->normalizeModelPricing($model_pricing)) {
      $pricing_last_saved = $this->time->getRequestTime();
    }

    $assistant_id = trim((string) $form_state->getValue('assistant_id'));
    $this->saveAssistantRoles($assistant_id, $this->cleanSelectedRoles($form_state->getValue('assistant_roles')));

    $this->config('trotamundos_assistant.settings')
      ->set('welcome_message', trim((string) $form_state->getValue('welcome_message')))
      ->set('input_placeholder', trim((string) $form_state->getValue('input_placeholder')))
      ->set('privacy_notice', trim((string) $form_state->getValue('privacy_notice')))
      ->set('assistant_id', trim((string) $form_state->getValue('assistant_id')))
      ->clear('allowed_roles')
      ->set('login_required_message', trim((string) $form_state->getValue('login_required_message')))
      ->set('role_denied_chat_message', trim((string) $form_state->getValue('role_denied_chat_message')))
      ->set('use_minsait_font', (bool) $form_state->getValue('use_minsait_font'))
      ->set('result_target_selector', trim((string) $form_state->getValue('result_target_selector')))
      ->set('header_sticky', trim((string) $form_state->getValue('header_sticky')))
      ->set('extra_shift_selectors', (string) $form_state->getValue('extra_shift_selectors'))
      ->set('desktop_always_open', (bool) $form_state->getValue('desktop_always_open'))
      ->set('toggle_label', trim((string) $form_state->getValue('toggle_label')))
      ->set('chat_logo_path', trim((string) $form_state->getValue('chat_logo_path')))
      ->set('admin_currency', (string) $form_state->getValue('admin_currency'))
      ->set('max_message_length', (int) $form_state->getValue('max_message_length'))
      ->set('rate_limit_requests', (int) $form_state->getValue('rate_limit_requests'))
      ->set('rate_limit_window', (int) $form_state->getValue('rate_limit_window'))
      ->set('pricing_last_saved', $pricing_last_saved)
      ->set('model_pricing', $model_pricing)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Formats model pricing config as editable text.
   *
   * @param array<int, array<string, mixed>> $pricing
   *   Model pricing rows.
   */
  private function formatModelPricingText(array $pricing): string {
    $lines = [];
    foreach ($pricing as $row) {
      $model_id = trim((string) ($row['model_id'] ?? ''));
      if ($model_id === '') {
        continue;
      }

      $lines[] = implode('|', [
        $model_id,
        $this->formatModelPriceNumber((float) ($row['input'] ?? 0)),
        $this->formatModelPriceNumber((float) ($row['cached'] ?? 0)),
        $this->formatModelPriceNumber((float) ($row['output'] ?? 0)),
      ]);
    }

    return implode("\n", $lines);
  }

  /**
   * Parses editable model pricing text.
   *
   * @return array<int, array<string, mixed>>
   *   Parsed model pricing rows.
   */
  private function parseModelPricingText(string $value): array {
    $pricing = [];
    $seen = [];
    foreach (preg_split('/\R/', $value) ?: [] as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }

      $parts = array_map('trim', explode('|', $line));
      if (count($parts) !== 4 || $parts[0] === '') {
        continue;
      }

      [$model_id, $input, $cached, $output] = $parts;
      $input = $this->parseModelPriceNumber($input);
      $cached = $this->parseModelPriceNumber($cached);
      $output = $this->parseModelPriceNumber($output);
      if ($input === NULL || $cached === NULL || $output === NULL) {
        continue;
      }

      $seen[$model_id] = [
        'model_id' => $model_id,
        'input' => $input,
        'cached' => $cached,
        'output' => $output,
      ];
    }

    foreach ($seen as $row) {
      $pricing[] = $row;
    }

    return $pricing;
  }

  /**
   * Formats model prices for the editable pricing field.
   */
  private function formatModelPriceNumber(float $value): string {
    return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
  }

  /**
   * Parses model prices allowing comma decimal notation.
   */
  private function parseModelPriceNumber(string $value): ?float {
    $normalized = str_replace('.', '', trim($value));
    $normalized = str_replace(',', '.', $normalized);

    return is_numeric($normalized) ? (float) $normalized : NULL;
  }

  /**
   * Gets available AI Assistant options.
   *
   * @return array<string, string>
   *   Select options keyed by assistant id.
   */
  private function getAssistantOptions(string $configured_assistant_id): array {
    $options = [];
    try {
      $assistants = $this->entityTypeManager
        ->getStorage('ai_assistant')
        ->loadMultiple();
    }
    catch (\Throwable) {
      $assistants = [];
    }

    foreach ($assistants as $assistant_id => $assistant) {
      $label = method_exists($assistant, 'label') ? (string) $assistant->label() : (string) $assistant_id;
      $options[(string) $assistant_id] = $label . ' (' . $assistant_id . ')';
    }

    if ($configured_assistant_id !== '' && !isset($options[$configured_assistant_id])) {
      $options[$configured_assistant_id] = $this->t('@assistant_id (configurado, no encontrado)', [
        '@assistant_id' => $configured_assistant_id,
      ]);
    }

    asort($options);

    return $options;
  }

  /**
   * Gets available user role options.
   *
   * @return array<string, string>
   *   Select options keyed by role id.
   */
  private function getRoleOptions(): array {
    $options = [];
    try {
      $roles = $this->entityTypeManager
        ->getStorage('user_role')
        ->loadMultiple();
    }
    catch (\Throwable) {
      $roles = [];
    }

    foreach ($roles as $role_id => $role) {
      $options[(string) $role_id] = method_exists($role, 'label')
        ? (string) $role->label()
        : (string) $role_id;
    }

    return $options;
  }

  /**
   * Gets the selected roles from an AI Assistant.
   *
   * @return string[]
   *   Selected role IDs.
   */
  private function getAssistantRoles(string $assistant_id): array {
    $assistant = $this->loadAssistant($assistant_id);
    if (!$assistant || !method_exists($assistant, 'get')) {
      return [];
    }

    $roles = $assistant->get('roles');
    if (!is_array($roles)) {
      return [];
    }

    $selected = [];
    foreach ($roles as $role_id => $enabled) {
      if ($enabled) {
        $selected[] = (string) $role_id;
      }
    }

    return $selected;
  }

  /**
   * Loads an AI Assistant entity.
   */
  private function loadAssistant(string $assistant_id): mixed {
    if ($assistant_id === '' || !$this->entityTypeManager->hasDefinition('ai_assistant')) {
      return NULL;
    }

    try {
      return $this->entityTypeManager->getStorage('ai_assistant')->load($assistant_id);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Saves the selected roles on the AI Assistant entity.
   */
  private function saveAssistantRoles(string $assistant_id, array $roles): void {
    $assistant = $this->loadAssistant($assistant_id);
    if (!$assistant || !method_exists($assistant, 'set') || !method_exists($assistant, 'save')) {
      return;
    }

    $assistant->set('roles', array_fill_keys($roles, 1));
    $assistant->save();
  }

  /**
   * Normalizes selected role checkboxes.
   *
   * @param mixed $roles
   *   Submitted role values.
   *
   * @return string[]
   *   Role IDs.
   */
  private function cleanSelectedRoles(mixed $roles): array {
    if (!is_array($roles)) {
      return [];
    }

    return array_values(array_filter(array_map('strval', $roles), static fn(string $role): bool => $role !== '' && $role !== '0'));
  }

  /**
   * Checks whether the configured selector is safe enough to save.
   */
  private function isValidSimpleSelector(string $selector): bool {
    if (str_contains($selector, "\n") || str_contains($selector, "\r")) {
      return FALSE;
    }

    return (bool) preg_match('/^(#[A-Za-z][A-Za-z0-9_-]*|\.[A-Za-z][A-Za-z0-9_-]*|[A-Za-z][A-Za-z0-9_-]*|\[[^\]]+\])$/', $selector);
  }

  /**
   * Checks whether the configured logo path is safe enough to render.
   */
  private function isValidLogoPath(string $path): bool {
    if (str_contains($path, "\n") || str_contains($path, "\r")) {
      return FALSE;
    }

    return str_starts_with($path, '/')
      || (bool) preg_match('/^https?:\/\/[^\s]+$/', $path);
  }

  /**
   * Checks whether a configurable CSS selector is safe enough to pass to JS.
   */
  private function isValidCssSelector(string $selector): bool {
    if (str_contains($selector, "\n") || str_contains($selector, "\r")) {
      return FALSE;
    }

    return !preg_match('/[{};]/', $selector);
  }

  /**
   * Normalizes a semicolon-separated CSS selector list.
   */
  private function normalizeCssSelectorList(string $selectors): string {
    $parts = array_filter(array_map('trim', explode(';', $selectors)));

    return implode('; ', $parts);
  }

  /**
   * Checks whether each selector in a semicolon-separated list is valid.
   */
  private function isValidCssSelectorList(string $selectors): bool {
    foreach (explode(';', $selectors) as $selector) {
      $selector = trim($selector);
      if ($selector !== '' && !$this->isValidCssSelector($selector)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Normalizes model pricing rows before comparing config changes.
   *
   * @param array<int, array<string, mixed>> $pricing
   *   Model pricing rows.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized rows.
   */
  private function normalizeModelPricing(array $pricing): array {
    $normalized = [];
    foreach ($pricing as $row) {
      $model_id = trim((string) ($row['model_id'] ?? ''));
      if ($model_id === '') {
        continue;
      }

      $normalized[$model_id] = [
        'model_id' => $model_id,
        'input' => round((float) ($row['input'] ?? 0), 6),
        'cached' => round((float) ($row['cached'] ?? 0), 6),
        'output' => round((float) ($row['output'] ?? 0), 6),
      ];
    }

    ksort($normalized);

    return array_values($normalized);
  }

  /**
   * Formats a timestamp as a Spanish long date and time.
   */
  private function formatSpanishDate(int $timestamp): string {
    $months = [
      1 => 'enero',
      2 => 'febrero',
      3 => 'marzo',
      4 => 'abril',
      5 => 'mayo',
      6 => 'junio',
      7 => 'julio',
      8 => 'agosto',
      9 => 'septiembre',
      10 => 'octubre',
      11 => 'noviembre',
      12 => 'diciembre',
    ];

    $month = (int) date('n', $timestamp);

    return date('j', $timestamp) . ' de ' . ($months[$month] ?? '') . ' de ' . date('Y', $timestamp) . ' a las ' . date('H:i:s', $timestamp);
  }

}
