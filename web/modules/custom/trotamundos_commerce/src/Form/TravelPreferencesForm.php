<?php

declare(strict_types=1);

namespace Drupal\trotamundos_commerce\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\trotamundos_commerce\Setup\TravelProfileModel;
use Drupal\trotamundos_experiences\ExperienceRepository;
use Drupal\user\Entity\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Edits only the current account's optional travel preferences.
 */
final class TravelPreferencesForm extends FormBase {

  public function getFormId(): string {
    return 'trotamundos_travel_preferences';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $user = User::load($this->currentUser()->id());
    if (!$this->currentUser()->isAuthenticated() || !$user) {
      throw new AccessDeniedHttpException();
    }
    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $styles = [];
    foreach (\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree('estilo_viaje', 0, NULL, TRUE) as $term) {
      if ($term->isPublished() && $term->access('view')) {
        $styles[$term->id()] = ($term->hasTranslation($langcode) ? $term->getTranslation($langcode) : $term)->label();
      }
    }
    $form['styles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Estilos que te inspiran'),
      '#options' => $styles,
      '#default_value' => array_column($user->get(TravelProfileModel::STYLES)->getValue(), 'target_id'),
    ];
    $form['companions'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Con quién te gusta viajar'),
      '#options' => ExperienceRepository::accompanimentLabels(),
      '#default_value' => array_column($user->get(TravelProfileModel::COMPANIONS)->getValue(), 'value'),
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Guardar preferencias'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];
    $form['#cache'] = ['contexts' => ['user'], 'max-age' => 0];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $user = User::load($this->currentUser()->id());
    foreach ($this->values($form_state) as $name => $values) {
      $user->set($name, $values);
      foreach ($user->get($name)->validate() as $violation) {
        $form_state->setErrorByName($name === TravelProfileModel::STYLES ? 'styles' : 'companions', $violation->getMessage());
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $user = \Drupal::entityTypeManager()->getStorage('user')->loadUnchanged($this->currentUser()->id());
    if (!$this->currentUser()->isAuthenticated() || !$user) {
      throw new AccessDeniedHttpException();
    }
    foreach ($this->values($form_state) as $name => $values) {
      $user->set($name, $values);
    }
    $user->save();
    $this->messenger()->addStatus($this->t('Tus preferencias de viaje se han guardado.'));
    $form_state->setRedirectUrl(Url::fromRoute('entity.user.canonical', ['user' => $user->id()], ['fragment' => 'travel-preferences']));
  }

  private function values(FormStateInterface $form_state): array {
    return [
      TravelProfileModel::STYLES => array_values(array_filter($form_state->getValue('styles', []))),
      TravelProfileModel::COMPANIONS => array_values(array_filter($form_state->getValue('companions', []))),
    ];
  }

}
