<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_documents\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\trotamundos_assistant_documents\Service\DocumentConfigurationDiscovery;
use Drupal\trotamundos_assistant_documents\Service\DocumentTextExtractorRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures document text extraction rules.
 */
final class DocumentsSettingsForm extends ConfigFormBase {

  private const DESTINATION_FIELD_SUFFIX = '_index_text';

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly DocumentTextExtractorRegistry $textExtractorRegistry,
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
      $container->get('trotamundos_assistant_documents.text_extractor_registry'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'trotamundos_assistant_documents_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['trotamundos_assistant_documents.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('trotamundos_assistant_documents.settings');
    $discovery = $this->getDiscovery();
    $media_type_options = $discovery->getMediaTypeOptions();
    $extension_process_options = $this->textExtractorRegistry->getOptions();

    $form['#attached']['library'][] = 'trotamundos_assistant_documents/settings_form';

    $selected_media_type = (string) ($this->getSubmittedValue($form_state, 'media_type') ?: array_key_first($media_type_options) ?: '');
    $media_file_field_options = $selected_media_type !== ''
      ? $discovery->getMediaFileFieldOptions($selected_media_type)
      : [];
    $selected_media_file_field = (string) ($this->getSubmittedValue($form_state, 'media_file_field') ?: array_key_first($media_file_field_options) ?: '');
    if ($selected_media_file_field !== '' && !isset($media_file_field_options[$selected_media_file_field])) {
      $selected_media_file_field = (string) (array_key_first($media_file_field_options) ?: '');
    }
    $destination_field = $this->buildDestinationFieldName($selected_media_file_field);
    $existing_rules = $config->get('rules') ?: [];
    $existing_rules = is_array($existing_rules) ? $existing_rules : [];
    $max_text_length = (int) ($form_state->getValue('max_text_length') ?: $this->getConfiguredMaxTextLength($existing_rules, $selected_media_type, $selected_media_file_field));
    $extension_process = (string) ($form_state->getValue('extension_process') ?: $this->getConfiguredExtensionProcess($existing_rules, $selected_media_type, $selected_media_file_field));
    if ($extension_process === '' || !isset($extension_process_options[$extension_process])) {
      $extension_process = (string) (array_key_first($extension_process_options) ?: '');
    }

    $form['media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Tipo de Media'),
      '#options' => $media_type_options,
      '#default_value' => $selected_media_type,
      '#empty_option' => $this->t('- Selecciona -'),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::refreshDynamicArea',
        'wrapper' => 'trotamundos-assistant-documents-dynamic',
      ],
      '#limit_validation_errors' => [],
    ];

    $form['dynamic'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'trotamundos-assistant-documents-dynamic'],
    ];

    $form['dynamic']['media_file_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Campo file del Media'),
      '#options' => $media_file_field_options,
      '#default_value' => $selected_media_file_field,
      '#empty_option' => $this->t('- Selecciona -'),
      '#parents' => ['media_file_field'],
      '#required' => TRUE,
      '#validated' => TRUE,
      '#ajax' => [
        'callback' => '::refreshDynamicArea',
        'wrapper' => 'trotamundos-assistant-documents-dynamic',
      ],
      '#limit_validation_errors' => [],
      '#description' => $this->t('El módulo extraerá texto solo de PDFs almacenados en este campo.'),
    ];

    $form['dynamic']['destination_field'] = [
      '#type' => 'item',
      '#title' => $this->t('Campo destino para el texto extraído'),
      '#markup' => $destination_field === ''
        ? '<em>' . $this->t('Selecciona un campo file del Media.') . '</em>'
        : '<code data-trotamundos-assistant-documents-destination-field>' . htmlspecialchars($destination_field, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>',
      '#description' => $this->t('Se calcula automáticamente a partir del campo file seleccionado. Al guardar se creará como campo técnico de texto largo en el tipo de Media seleccionado.'),
    ];

    $form['extension_process'] = [
      '#type' => 'select',
      '#title' => $this->t('Proceso de Extensión'),
      '#options' => $extension_process_options,
      '#default_value' => $extension_process,
      '#required' => TRUE,
    ];

    $form['max_text_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Longitud máxima del texto extraído'),
      '#default_value' => $max_text_length,
      '#min' => 1000,
      '#step' => 1000,
      '#description' => $this->t('Se truncará el texto para evitar campos demasiado grandes antes de indexar.'),
    ];

    $form['dynamic']['rules_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'trotamundos-assistant-documents-rules'],
    ];

    $form['dynamic']['rules_wrapper']['rules'] = [
      '#type' => 'table',
      '#parents' => ['rules'],
      '#header' => [
        $this->t('Activar'),
        $this->t('Entidad / bundle'),
        $this->t('Campo Media origen'),
        $this->t('Campo destino'),
        $this->t('Estado'),
      ],
      '#empty' => $this->t('No hay campos de entidades que referencien este tipo de Media.'),
    ];

    $candidates = $selected_media_type !== '' && $selected_media_file_field !== ''
      ? $discovery->getRuleCandidates($selected_media_type, $selected_media_file_field)
      : [];

    foreach ($candidates as $rule_id => $candidate) {
      $existing_rule = is_array($existing_rules[$rule_id] ?? NULL) ? $existing_rules[$rule_id] : [];
      $rule_destination_field = $this->buildDestinationFieldName($candidate['media_file_field']);
      $destination_options = $discovery->getDestinationFieldOptions('media', $candidate['media_type']);
      $destination_exists = isset($destination_options[$rule_destination_field]);

      $form['dynamic']['rules_wrapper']['rules'][$rule_id]['enabled'] = [
        '#type' => 'checkbox',
        '#default_value' => !empty($existing_rule['enabled']),
      ];
      $form['dynamic']['rules_wrapper']['rules'][$rule_id]['source'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('@entity: @bundle', [
          '@entity' => $candidate['source_entity_type_label'],
          '@bundle' => $candidate['source_bundle_label'],
        ]),
        '#attributes' => ['data-trotamundos-assistant-documents-source' => ''],
      ];
      $form['dynamic']['rules_wrapper']['rules'][$rule_id]['source_media_field'] = [
        '#plain_text' => $candidate['source_media_field_label'],
      ];
      $form['dynamic']['rules_wrapper']['rules'][$rule_id]['destination_field'] = [
        '#markup' => $rule_destination_field,
      ];
      $form['dynamic']['rules_wrapper']['rules'][$rule_id]['status'] = [
        '#markup' => $destination_exists
          ? $this->t('Preparado')
          : $this->t('Falta crear el campo destino en este bundle'),
      ];
      foreach (['media_type', 'media_file_field', 'source_entity_type', 'source_bundle', 'source_media_field'] as $key) {
        $form['dynamic']['rules_wrapper']['rules'][$rule_id]['metadata_' . $key] = [
          '#type' => 'hidden',
          '#parents' => ['rules', $rule_id, $key],
          '#value' => $candidate[$key],
        ];
      }
      $form['dynamic']['rules_wrapper']['rules'][$rule_id]['#attributes']['data-trotamundos-assistant-documents-rule'] = '';
      $form['dynamic']['rules_wrapper']['rules'][$rule_id]['#attributes']['data-trotamundos-assistant-documents-media'] = $discovery->getMediaTypeLabel($candidate['media_type']);
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * Ajax callback for Media field and rule list refreshes.
   */
  public function refreshDynamicArea(array &$form, FormStateInterface $form_state): array {
    $form_state->setRebuild(TRUE);
    return $form['dynamic'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $media_type = (string) $form_state->getValue('media_type');
    $media_file_field = (string) $form_state->getValue('media_file_field');
    $visible_rules = $form_state->getValue('rules') ?: [];
    $existing_rules = $this->config('trotamundos_assistant_documents.settings')->get('rules') ?: [];
    $rules_after_submit = is_array($existing_rules) ? $existing_rules : [];
    $max_text_length = (int) $form_state->getValue('max_text_length');
    $extension_process = (string) $form_state->getValue('extension_process');
    $discovery = $this->getDiscovery();

    if ($media_type === '') {
      $form_state->setErrorByName('media_type', $this->t('Selecciona un tipo de Media.'));
    }
    if ($media_file_field === '') {
      $form_state->setErrorByName('media_file_field', $this->t('Selecciona el campo file del Media.'));
    }
    elseif (!$this->textExtractorRegistry->get($extension_process)) {
      $form_state->setErrorByName('extension_process', $this->t('El proceso de extensión seleccionado no tiene extractor configurado.'));
    }
    elseif ($media_type !== '' && !$this->isExtensionProcessAllowed($media_type, $media_file_field, $extension_process)) {
      $allowed_extensions = $discovery->getMediaFileFieldExtensions($media_type, $media_file_field);
      $allowed_extensions_label = $allowed_extensions === []
        ? (string) $this->t('ninguna extensión')
        : implode(', ', $allowed_extensions);
      $form_state->setErrorByName('extension_process', $this->t('El campo @field solo tiene habilitado las extensiones @extensions y no tiene habilitado @extension.', [
        '@field' => $discovery->getFieldLabel('media', $media_type, $media_file_field),
        '@extensions' => $allowed_extensions_label,
        '@extension' => $this->formatExtensionProcess($extension_process),
      ]));
    }

    foreach ($visible_rules as $rule_id => $rule_values) {
      if (!is_array($rule_values) || empty($rule_values['enabled'])) {
        unset($rules_after_submit[$rule_id]);
        continue;
      }

      $rule_values['max_text_length'] = $max_text_length;
      $rule_values['extension_process'] = $extension_process;
      $rules_after_submit[$rule_id] = $this->normalizeSubmittedRule($rule_values);
    }
    if (!$this->hasEnabledRule($rules_after_submit)) {
      $form_state->setErrorByName('rules', $this->t('Activa al menos una entidad o bundle donde aplicar la extracción.'));
    }

    $destination_field = $this->buildDestinationFieldName($media_file_field);
    if (!preg_match('/^field_[a-z0-9_]+$/', $destination_field)) {
      $form_state->setErrorByName('media_file_field', $this->t('El campo destino calculado debe ser un machine name de campo configurable.'));
    }
    if (strlen($destination_field) > 32) {
      $form_state->setErrorByName('media_file_field', $this->t('El campo destino calculado "@field" supera los 32 caracteres permitidos por Drupal.', [
        '@field' => $destination_field,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable('trotamundos_assistant_documents.settings');
    $visible_rules = $form_state->getValue('rules') ?: [];
    $existing_rules = $config->get('rules') ?: [];
    $rules = is_array($existing_rules) ? $existing_rules : [];
    $max_text_length = (int) $form_state->getValue('max_text_length');
    $extension_process = (string) $form_state->getValue('extension_process');

    foreach ($visible_rules as $rule_id => $rule_values) {
      if (empty($rule_values['enabled'])) {
        unset($rules[$rule_id]);
        continue;
      }

      $rule_values['max_text_length'] = $max_text_length;
      $rule_values['extension_process'] = $extension_process;
      $rules[$rule_id] = $this->normalizeSubmittedRule($rule_values);
    }

    foreach ($rules as $rule) {
      if (is_array($rule) && !empty($rule['enabled'])) {
        $this->ensureDestinationField($rule);
      }
    }

    $config
      ->set('rules', $rules)
      ->clear('destination_field')
      ->clear('max_text_length')
      ->save();

    parent::submitForm($form, $form_state);
    $form_state->setRedirect('trotamundos_assistant_documents.settings');
  }

  /**
   * Builds the destination field name from the selected Media file field.
   */
  private function buildDestinationFieldName(string $media_file_field): string {
    return $media_file_field === '' ? '' : $media_file_field . self::DESTINATION_FIELD_SUFFIX;
  }

  /**
   * Normalizes one submitted rule row for config storage.
   *
   * @param array<string, mixed> $rule_values
   *   Submitted rule values.
   *
   * @return array<string, mixed>
   *   Normalized rule values.
   */
  private function normalizeSubmittedRule(array $rule_values): array {
    $media_file_field = (string) ($rule_values['media_file_field'] ?? '');
    return [
      'enabled' => TRUE,
      'media_type' => (string) ($rule_values['media_type'] ?? ''),
      'media_file_field' => $media_file_field,
      'extension_process' => (string) ($rule_values['extension_process'] ?? 'pdf'),
      'source_entity_type' => (string) ($rule_values['source_entity_type'] ?? ''),
      'source_bundle' => (string) ($rule_values['source_bundle'] ?? ''),
      'source_media_field' => (string) ($rule_values['source_media_field'] ?? ''),
      'destination_field' => (string) ($rule_values['destination_field'] ?? $this->buildDestinationFieldName($media_file_field)),
      'max_text_length' => (int) ($rule_values['max_text_length'] ?? 50000),
    ];
  }

  /**
   * Ensures the destination field exists for one configured rule.
   *
   * @param array<string, mixed> $rule
   *   Normalized rule values.
   */
  private function ensureDestinationField(array $rule): void {
    $entity_type_id = 'media';
    $bundle = (string) ($rule['media_type'] ?? '');
    $field_name = (string) ($rule['destination_field'] ?? '');
    if ($entity_type_id === '' || $bundle === '' || $field_name === '') {
      return;
    }

    $field_id = $entity_type_id . '.' . $bundle . '.' . $field_name;
    if (FieldConfig::load($field_id)) {
      $this->hideDestinationFieldInDisplays($entity_type_id, $bundle, $field_name);
      return;
    }

    if (!FieldStorageConfig::loadByName($entity_type_id, $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type_id,
        'type' => 'text_long',
        'cardinality' => 1,
        'translatable' => TRUE,
      ])->save();
    }

    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'label' => $this->t('Texto extraído para indexar'),
      'description' => $this->t('Campo técnico generado por Trotamundos Assistant Documents. Contiene texto extraído de documentos Media para su posterior indexación.'),
      'required' => FALSE,
      'translatable' => FALSE,
    ])->save();

    $this->hideDestinationFieldInDisplays($entity_type_id, $bundle, $field_name);
  }

  private function hideDestinationFieldInDisplays(string $entity_type_id, string $bundle, string $field_name): void {
    $form_display = EntityFormDisplay::load($entity_type_id . '.' . $bundle . '.default');
    if ($form_display instanceof EntityFormDisplay) {
      $form_display->removeComponent($field_name)->save();
    }

    $view_display = EntityViewDisplay::load($entity_type_id . '.' . $bundle . '.default');
    if ($view_display instanceof EntityViewDisplay) {
      $view_display->removeComponent($field_name)->save();
    }
  }

  /**
   * Gets a submitted value, including values from AJAX user input.
   */
  private function getSubmittedValue(FormStateInterface $form_state, string $key): mixed {
    $input = $form_state->getUserInput();
    return $input[$key] ?? $form_state->getValue($key);
  }

  /**
   * Returns the configured max text length for the selected Media field.
   *
   * @param array<string, mixed> $rules
   *   Configured rules.
   */
  private function getConfiguredMaxTextLength(array $rules, string $media_type, string $media_file_field): int {
    foreach ($rules as $rule) {
      if (is_array($rule)
        && ($rule['media_type'] ?? '') === $media_type
        && ($rule['media_file_field'] ?? '') === $media_file_field
        && !empty($rule['max_text_length'])) {
        return (int) $rule['max_text_length'];
      }
    }

    return 50000;
  }

  /**
   * Returns the configured extension process for the selected Media field.
   *
   * @param array<string, mixed> $rules
   *   Configured rules.
   */
  private function getConfiguredExtensionProcess(array $rules, string $media_type, string $media_file_field): string {
    foreach ($rules as $rule) {
      if (is_array($rule)
        && ($rule['media_type'] ?? '') === $media_type
        && ($rule['media_file_field'] ?? '') === $media_file_field
        && !empty($rule['extension_process'])) {
        return (string) $rule['extension_process'];
      }
    }

    return 'pdf';
  }

  /**
   * Checks whether the selected file field supports the extension process.
   */
  private function isExtensionProcessAllowed(string $media_type, string $media_file_field, string $extension_process): bool {
    $required_extension = $this->getRequiredExtension($extension_process);
    if ($required_extension === '') {
      return TRUE;
    }

    $allowed_extensions = $this->getDiscovery()->getMediaFileFieldExtensions($media_type, $media_file_field);
    return in_array($required_extension, $allowed_extensions, TRUE);
  }

  /**
   * Maps an extension process to the required file extension.
   */
  private function getRequiredExtension(string $extension_process): string {
    return $this->textExtractorRegistry->getRequiredExtension($extension_process);
  }

  /**
   * Formats an extension process for display.
   */
  private function formatExtensionProcess(string $extension_process): string {
    return $this->textExtractorRegistry->getLabel($extension_process);
  }

  /**
   * Gets the discovery service.
   */
  private function getDiscovery(): DocumentConfigurationDiscovery {
    return \Drupal::service('trotamundos_assistant_documents.discovery');
  }

  /**
   * Checks whether at least one visible rule is enabled.
   *
   * @param mixed $rules
   *   Submitted table rows.
   */
  private function hasEnabledRule(mixed $rules): bool {
    if (!is_array($rules)) {
      return FALSE;
    }

    foreach ($rules as $rule) {
      if (is_array($rule) && !empty($rule['enabled'])) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
