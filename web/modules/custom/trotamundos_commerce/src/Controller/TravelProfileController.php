<?php

declare(strict_types=1);

namespace Drupal\trotamundos_commerce\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\trotamundos_commerce\Form\FavoritePlanForm;
use Drupal\trotamundos_commerce\Form\TravelPreferencesForm;
use Drupal\trotamundos_commerce\Setup\TravelProfileModel;
use Drupal\trotamundos_experiences\ExperienceRepository;
use Drupal\trotamundos_experiences\Setup\ProductTypes;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A private travel dashboard on the existing account page.
 */
final class TravelProfileController extends ControllerBase {

  public function __construct(
    private readonly ExperienceRepository $experiences,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('trotamundos_experiences.repository'));
  }

  public function title(UserInterface $user): array|TranslatableMarkup {
    return $this->isOwner($user)
      ? $this->t('Mi perfil viajero')
      : ['#theme' => 'username', '#account' => $user];
  }

  public function view(UserInterface $user): array {
    if (!$this->isOwner($user)) {
      $build = $this->entityTypeManager()->getViewBuilder('user')->view($user, 'full');
      $build['#cache']['contexts'][] = 'user';
      return $build;
    }

    $saved_ids = array_map('intval', array_column($user->get(TravelProfileModel::SAVED)->getValue(), 'target_id'));
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($saved_ids);
    $saved = [];
    foreach ($saved_ids as $id) {
      $node = $nodes[$id] ?? NULL;
      $available = $node && $node->isPublished()
        && in_array($node->bundle(), ProductTypes::BUNDLES, TRUE)
        && $node->access('view');
      $saved[] = [
        'card' => $available ? $this->experiences->card($node) : NULL,
        'form' => $available ? NULL : $this->formBuilder()->getForm(new FavoritePlanForm($id)),
      ];
    }

    $orders = [];
    $storage = $this->entityTypeManager()->getStorage('commerce_order');
    $order_ids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('uid', $user->id())
      ->condition('state', 'draft', '<>')
      ->sort('placed', 'DESC')
      ->sort('order_id', 'DESC')
      ->pager(5)
      ->execute();
    foreach ($storage->loadMultiple($order_ids) as $order) {
      $orders[] = [
        'label' => $order->label(),
        'state' => $order->getState()->getLabel(),
        'date' => \Drupal::service('date.formatter')->format($order->getPlacedTime() ?? $order->getCreatedTime(), 'short'),
        'total' => $order->get('total_price')->view(['label' => 'hidden', 'type' => 'commerce_price_default']),
        'url' => Url::fromRoute('entity.commerce_order.user_view', [
          'user' => $user->id(),
          'commerce_order' => $order->id(),
        ])->toString(),
      ];
    }

    return [
      '#theme' => 'trotamundos_travel_profile',
      '#saved' => $saved,
      '#orders' => $orders,
      '#pager' => ['#type' => 'pager'],
      '#preferences' => $this->formBuilder()->getForm(TravelPreferencesForm::class),
      '#edit_url' => Url::fromRoute('entity.user.edit_form', ['user' => $user->id()])->toString(),
      '#help_url' => Url::fromRoute('trotamundos_pages.ayuda')->toString(),
      '#catalog_url' => Url::fromRoute('trotamundos_experiences.catalog')->toString(),
      '#attached' => ['library' => ['trotamundos/travel-profile']],
      '#cache' => [
        'contexts' => ['user', 'user.permissions', 'url.query_args:page', 'languages:language_interface', 'languages:language_content'],
        'tags' => array_merge($user->getCacheTags(), ['commerce_order_list'], ProductTypes::CACHE_TAGS),
        'max-age' => 0,
      ],
    ];
  }

  private function isOwner(UserInterface $user): bool {
    return $this->currentUser()->isAuthenticated() && (int) $this->currentUser()->id() === (int) $user->id();
  }

}
