<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_documents\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\trotamundos_assistant_documents\Service\DocumentConfigurationDiscovery;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms deletion of one configured document extraction rule.
 */
final class DeleteRuleConfirmForm extends ConfirmFormBase {

  private string $ruleId = '';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactoryService,
    private readonly DocumentConfigurationDiscovery $discovery,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('trotamundos_assistant_documents.discovery'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'trotamundos_assistant_documents_delete_rule_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return (string) $this->t('¿Quieres borrar esta regla?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    $rule = $this->getRule();
    if ($rule === []) {
      return (string) $this->t('La regla indicada no existe o ya ha sido borrada.');
    }

    return (string) $this->t('Se borrará solo la configuración para @media en @source. No se eliminará ningún campo ni contenido existente.', [
      '@media' => $this->discovery->getMediaTypeLabel((string) ($rule['media_type'] ?? '')),
      '@source' => $this->discovery->getSourceLabel(
        (string) ($rule['source_entity_type'] ?? ''),
        (string) ($rule['source_bundle'] ?? ''),
      ),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('trotamundos_assistant_documents.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return (string) $this->t('Borrar');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $rule_id = ''): array {
    $this->ruleId = $rule_id;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactoryService->getEditable('trotamundos_assistant_documents.settings');
    $rules = $config->get('rules') ?: [];

    if (is_array($rules) && isset($rules[$this->ruleId])) {
      unset($rules[$this->ruleId]);
      $config->set('rules', $rules)->save();
      $this->messenger()->addStatus($this->t('La regla se ha borrado correctamente.'));
    }
    else {
      $this->messenger()->addWarning($this->t('La regla indicada no existe o ya había sido borrada.'));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Returns the current rule from configuration.
   *
   * @return array<string, mixed>
   *   The configured rule, or an empty array.
   */
  private function getRule(): array {
    $rules = $this->configFactoryService
      ->get('trotamundos_assistant_documents.settings')
      ->get('rules') ?: [];

    if (!is_array($rules) || !isset($rules[$this->ruleId]) || !is_array($rules[$this->ruleId])) {
      return [];
    }

    return $rules[$this->ruleId];
  }

}
