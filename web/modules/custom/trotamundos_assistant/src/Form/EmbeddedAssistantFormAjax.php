<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\RenderElementBase;
use Drupal\Core\Url;

/**
 * AJAX helpers for entity forms embedded in assistant skill templates.
 */
final class EmbeddedAssistantFormAjax {

  /**
   * Prepares a form to behave as an embedded assistant AJAX form.
   *
   * @param array<string, mixed> $form
   *   Form render array.
   * @param string $wrapper_id
   *   AJAX replacement wrapper ID.
   * @param string $form_class
   *   CSS class added to the form element.
   */
  public static function prepareForm(array &$form, string $wrapper_id, string $form_class = ''): void {
    if ($form_class !== '') {
      $form['#attributes']['class'][] = $form_class;
    }

    $form['#prefix'] = '<div id="' . $wrapper_id . '" class="trotamundos-assistant-embedded-form">';
    $form['#suffix'] = '</div>';
    $form['#trotamundos_assistant_ajax_wrapper'] = $wrapper_id;

    if (isset($form['actions']['submit']) && is_array($form['actions']['submit'])) {
      $action = (string) $form['#action'];
      $action_parts = UrlHelper::parse($action);
      $query = $action_parts['query'];
      if (str_starts_with($action, '/')) {
        $url = Url::fromUserInput($action);
      }
      elseif (UrlHelper::isExternal($action)) {
        $url = Url::fromUri($action);
      }
      else {
        $url = Url::fromRoute('<current>');
        $query = \Drupal::request()->query->all();
      }
      $form['actions']['submit']['#ajax'] = [
        'callback' => [self::class, 'submit'],
        'wrapper' => $wrapper_id,
        'url' => $url,
        'options' => ['query' => $query + ['ajax_form' => 1]],
      ];
      unset($form['actions']['submit']['#ajax_processed']);
      if (isset($form['actions']['submit']['#id'])) {
        $form['actions']['submit'] = RenderElementBase::preRenderAjaxForm($form['actions']['submit']);
      }
      if (isset($form['actions']['submit']['#submit'])) {
        $form['actions']['submit']['#submit'][] = [self::class, 'preventRedirect'];
      }
      else {
        $form['#submit'][] = [self::class, 'preventRedirect'];
      }
    }
  }

  /**
   * Prevents standard entity-form redirects after AJAX submissions.
   */
  public static function preventRedirect(array $form, FormStateInterface $form_state): void {
    $form_state->disableRedirect();
  }

  /**
   * Replaces the embedded form wrapper after submit.
   *
   * @return array<string, mixed>
   *   Replacement render array.
   */
  public static function submit(array $form, FormStateInterface $form_state): array {
    if ($form_state->hasAnyErrors()) {
      return [
        '#type' => 'container',
        '#attributes' => [
          'id' => (string) ($form['#trotamundos_assistant_ajax_wrapper'] ?? 'trotamundos-assistant-embedded-form'),
          'class' => ['trotamundos-assistant-embedded-form'],
        ],
        'messages' => [
          '#type' => 'status_messages',
        ],
        'form' => $form,
      ];
    }

    $wrapper_id = (string) ($form['#trotamundos_assistant_ajax_wrapper'] ?? 'trotamundos-assistant-embedded-form');
    $form = self::buildFreshEntityForm($form, $form_state);
    unset($form['#prefix'], $form['#suffix']);

    return [
      '#type' => 'container',
      '#attributes' => [
        'id' => $wrapper_id,
        'class' => ['trotamundos-assistant-embedded-form'],
      ],
      'messages' => [
        '#type' => 'status_messages',
      ],
      'message' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'trotamundos-assistant-embedded-form__message',
            'trotamundos-assistant-embedded-form__message--success',
          ],
        ],
        '#markup' => t('Actualización realizada correctamente.'),
      ],
      'form' => $form,
    ];
  }

  /**
   * Rebuilds entity forms from the freshly saved entity.
   *
   * @param array<string, mixed> $fallback_form
   *   The submitted form, used as fallback.
   *
   * @return array<string, mixed>
   *   A fresh entity form when possible.
   */
  private static function buildFreshEntityForm(array $fallback_form, FormStateInterface $form_state): array {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return $fallback_form;
    }

    $entity = $form_object->getEntity();
    if (!$entity instanceof EntityInterface || $entity->isNew()) {
      return $fallback_form;
    }

    try {
      $fresh_entity = \Drupal::entityTypeManager()
        ->getStorage($entity->getEntityTypeId())
        ->loadUnchanged($entity->id()) ?: $entity;

      $fresh_form = \Drupal::service('entity.form_builder')
        ->getForm($fresh_entity, $form_object->getOperation(), [
          'input' => [],
          'process_input' => FALSE,
        ]);

      return is_array($fresh_form) ? $fresh_form : $fallback_form;
    }
    catch (\Throwable) {
      return $fallback_form;
    }

    return $fallback_form;
  }

}
