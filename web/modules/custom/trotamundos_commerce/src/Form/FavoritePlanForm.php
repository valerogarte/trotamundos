<?php

declare(strict_types=1);

namespace Drupal\trotamundos_commerce\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AnnounceCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\trotamundos_commerce\Setup\TravelProfileModel;
use Drupal\trotamundos_experiences\Setup\ProductTypes;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Independent card and detail forms for saving an editorial plan.
 */
final class FavoritePlanForm extends FormBase implements TrustedCallbackInterface {

  public function __construct(
    private readonly int $nodeId,
    private readonly bool $compact = FALSE,
  ) {}

  public static function create(ContainerInterface $container): static {
    $node = $container->get('current_route_match')->getParameter('node');
    if (!$node instanceof NodeInterface) {
      throw new \LogicException('The favorite card route requires an editorial node.');
    }
    return new static((int) $node->id(), TRUE);
  }

  public function getFormId(): string {
    return 'trotamundos_favorite_plan_' . $this->nodeId . ($this->compact ? '_card' : '');
  }

  /**
   * Builds account-specific controls outside the shared catalog cache.
   */
  public static function buildCard(int $node_id): array {
    $node = Node::load($node_id);
    if (!$node || !$node->isPublished() || !in_array($node->bundle(), ProductTypes::BUNDLES, TRUE)) {
      return ['#access' => FALSE, '#cache' => ['tags' => ['node:' . $node_id]]];
    }
    $access = $node->access('view', NULL, TRUE);
    if (!$access->isAllowed()) {
      return ['#access' => $access];
    }
    $node = \Drupal::service('entity.repository')->getTranslationFromContext($node);
    $label = t('Inicia sesión para guardar @plan', ['@plan' => $node->label()]);
    $build = \Drupal::currentUser()->isAuthenticated()
      ? \Drupal::formBuilder()->getForm(new static($node_id, TRUE))
      : [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute('user.login', [], [
          'query' => \Drupal::service('redirect.destination')->getAsArray(),
        ]),
        '#attributes' => [
          'class' => ['favorite-control__target'],
          'aria-label' => $label,
          'title' => $label,
        ],
        '#theme_wrappers' => ['trotamundos_favorite_control'],
      ];
    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($node)
      ->addCacheableDependency($access)
      ->addCacheContexts(['user', 'url.path', 'url.query_args', 'languages:language_interface'])
      ->applyTo($build);
    return $build;
  }

  public static function trustedCallbacks(): array {
    return ['buildCard'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $user = User::load($this->currentUser()->id());
    if (!$this->currentUser()->isAuthenticated() || !$user) {
      throw new AccessDeniedHttpException();
    }
    $saved = array_map('intval', array_column($user->get(TravelProfileModel::SAVED)->getValue(), 'target_id'));
    $is_saved = in_array($this->nodeId, $saved, TRUE);
    $form['operation'] = [
      '#type' => 'hidden',
      '#default_value' => $is_saved ? 'remove' : 'save',
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $is_saved ? $this->t('Quitar de mis planes') : $this->t('Guardar en mis planes'),
      '#attributes' => ['class' => ['button', 'button--secondary']],
    ];
    $form['#attributes']['class'][] = 'favorite-plan-form';
    if ($this->compact) {
      $node = Node::load($this->nodeId);
      if (!$node) {
        throw new AccessDeniedHttpException();
      }
      $node = \Drupal::service('entity.repository')->getTranslationFromContext($node);
      $form['submit']['#attributes'] = [
        'class' => ['favorite-control__target'],
        'aria-label' => $this->t('Favorito: @plan', ['@plan' => $node->label()]),
        'aria-pressed' => $is_saved ? 'true' : 'false',
        'title' => $form['submit']['#value'],
      ];
      $form['submit']['#theme_wrappers'] = ['input__submit', 'trotamundos_favorite_control'];
      $form['#attributes']['class'][] = 'favorite-plan-form--compact';
      $form['#attributes']['data-favorite-node'] = (string) $this->nodeId;
      $wrapper_id = Html::getUniqueId('favorite-ajax-' . $this->nodeId);
      $form['#prefix'] = '<div id="' . $wrapper_id . '" class="favorite-card-ajax">';
      $form['#suffix'] = '</div>';
      $form['#action'] = Url::fromRoute('trotamundos_commerce.favorite_card', ['node' => $this->nodeId], [
        'query' => $this->getRedirectDestination()->getAsArray(),
      ])->toString();
      $query = [FormBuilderInterface::AJAX_FORM_REQUEST => 1];
      if ($this->getRouteMatch()->getRouteName() === 'trotamundos_commerce.saved_plans') {
        $query['favorite_profile'] = 1;
      }
      $form['submit']['#ajax'] = [
        'callback' => '::ajaxFavorite',
        'url' => Url::fromRoute('trotamundos_commerce.favorite_card', ['node' => $this->nodeId]),
        'options' => ['query' => $query],
        'wrapper' => $wrapper_id,
        'event' => 'click',
        'trigger_as' => ['name' => 'op'],
        'progress' => ['type' => 'none'],
      ];
    }
    $form['#cache'] = ['contexts' => ['user'], 'max-age' => 0];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $operation = $form_state->getValue('operation');
    if (!in_array($operation, ['save', 'remove'], TRUE)) {
      $form_state->setErrorByName('operation', $this->t('La acción de guardado no es válida.'));
    }
    elseif ($operation === 'save') {
      $node = Node::load($this->nodeId);
      if (!$node || !$node->isPublished() || !in_array($node->bundle(), ProductTypes::BUNDLES, TRUE) || !$node->access('view')) {
        $form_state->setErrorByName('operation', $this->t('Este plan ya no está disponible para guardar.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->isAuthenticated()) {
      throw new AccessDeniedHttpException();
    }

    $lock = \Drupal::lock();
    $lock_name = 'trotamundos_commerce.favorite.' . $this->currentUser()->id();
    if (!$lock->acquire($lock_name)) {
      $lock->wait($lock_name, 5);
      if (!$lock->acquire($lock_name)) {
        throw new ServiceUnavailableHttpException(5, (string) $this->t('No se pudo actualizar este favorito. Vuelve a intentarlo.'));
      }
    }

    try {
      $user = \Drupal::entityTypeManager()->getStorage('user')->loadUnchanged($this->currentUser()->id());
      if (!$user) {
        throw new AccessDeniedHttpException();
      }
      $saved = array_map('intval', array_column($user->get(TravelProfileModel::SAVED)->getValue(), 'target_id'));
      $save = $form_state->getValue('operation') === 'save';
      $saved = $save
        ? array_values(array_unique([...$saved, $this->nodeId]))
        : array_values(array_filter($saved, fn (int $id): bool => $id !== $this->nodeId));
      $user->set(TravelProfileModel::SAVED, $saved)->save();
    }
    finally {
      $lock->release($lock_name);
    }

    $form_state->set('favorite_saved', $save);
    if (!$this->compact || !$this->getRequest()?->query->has(FormBuilderInterface::AJAX_FORM_REQUEST)) {
      $this->messenger()->addStatus($save
        ? $this->t('Plan guardado en tu perfil.')
        : $this->t('Plan eliminado de tus guardados.'));
      $form_state->setRedirectUrl(Url::fromRoute($this->compact ? 'trotamundos_experiences.catalog' : '<current>'));
    }
  }

  /**
   * Synchronizes card controls only after the protected POST succeeds.
   */
  public function ajaxFavorite(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $saved = $form_state->get('favorite_saved');
    if ($form_state->hasAnyErrors() || !is_bool($saved)) {
      if (!$form_state->hasAnyErrors()) {
        $this->messenger()->addError($this->t('No se pudo actualizar este favorito. Vuelve a intentarlo.'));
      }
      $response->addCommand(new PrependCommand(NULL, ['#type' => 'status_messages']));
      return $response;
    }

    $selector = '.favorite-plan-form--compact[data-favorite-node="' . $this->nodeId . '"]';
    $action = $saved ? $this->t('Quitar de mis planes') : $this->t('Guardar en mis planes');
    $response->addCommand(new RemoveCommand('.favorite-card-ajax:has(' . $selector . ') > [data-drupal-messages]'));
    $response->addCommand(new InvokeCommand($selector . ' [name="operation"]', 'val', [$saved ? 'remove' : 'save']));
    $response->addCommand(new InvokeCommand($selector . ' .favorite-control__target', 'attr', [[
      'aria-pressed' => $saved ? 'true' : 'false',
      'title' => (string) $action,
    ]]));
    $response->addCommand(new InvokeCommand($selector . ' .favorite-control__target', 'val', [(string) $action]));

    if (!$saved) {
      if ($this->getRequest()?->query->get('favorite_profile') === '1') {
        $response->addCommand(new InvokeCommand('#saved-plans-title', 'focus'));
      }
      $response->addCommand(new RemoveCommand('#saved-plans .travel-saved-grid > li:has(' . $selector . ')'));
      $response->addCommand(new RemoveCommand('#saved-plans .travel-saved-grid:not(:has(> li))'));
      $response->addCommand(new InvokeCommand('#saved-plans:not(:has(.travel-saved-grid)) [data-saved-empty]', 'removeAttr', ['hidden']));
    }
    $response->addCommand(new AnnounceCommand($saved
      ? $this->t('Plan guardado en tu perfil.')
      : $this->t('Plan eliminado de tus guardados.')));
    return $response;
  }

}
