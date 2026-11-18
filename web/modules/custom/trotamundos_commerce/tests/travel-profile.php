<?php

declare(strict_types=1);

/**
 * @file
 * Run with drush php:script. Fixture accounts and orders are rolled back.
 */

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_store\Entity\Store;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\Entity\Node;
use Drupal\trotamundos_commerce\Controller\TravelProfileController;
use Drupal\trotamundos_commerce\Form\FavoritePlanForm;
use Drupal\trotamundos_commerce\Form\TravelPreferencesForm;
use Drupal\trotamundos_commerce\Setup\TravelProfileModel;
use Drupal\trotamundos_experiences\Setup\ProductTypes;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

if (PHP_SAPI !== 'cli' || !class_exists('Drupal')) {
  throw new RuntimeException('Run this test with drush php:script.');
}

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$manager = \Drupal::entityTypeManager();
$users = $manager->getStorage('user');
$transaction = \Drupal::database()->startTransaction();
$switcher = \Drupal::service('account_switcher');
$switched = FALSE;

try {
  $owner = User::create(['name' => 'transactional-traveler-a', 'mail' => 'traveler-a@example.invalid', 'status' => 1]);
  $owner->save();
  $other = User::create(['name' => 'transactional-traveler-b', 'mail' => 'traveler-b@example.invalid', 'status' => 1]);
  $other->save();
  $switcher->switchTo($owner);
  $switched = TRUE;
  foreach (TravelProfileModel::FIELDS as $field) {
    $assert($owner->get($field)->access('view', $owner), 'An owner cannot access their travel field.');
    $assert(!$owner->get($field)->access('view', $other), 'A travel field leaked to another customer.');
    $assert(!$owner->get($field)->access('edit', $other), 'Another customer can edit a private travel field.');
    $assert(!$owner->get($field)->access('view', new AnonymousUserSession()), 'A travel field leaked to an anonymous visitor.');
  }

  $node_ids = $manager->getStorage('node')->getQuery()->accessCheck(TRUE)
    ->condition('type', ProductTypes::BUNDLES, 'IN')->condition('status', 1)->range(0, 1)->execute();
  $assert(count($node_ids) === 1, 'A published editorial product is required for this integration test.');
  $node_id = (int) reset($node_ids);
  $favorite = new FavoritePlanForm($node_id);
  $form = [];
  $save = (new FormState())->setValues(['operation' => 'save']);
  $favorite->validateForm($form, $save);
  $assert(!$save->hasAnyErrors(), 'A published plan was rejected.');
  $favorite->submitForm($form, $save);
  $favorite->submitForm($form, $save);
  $owner = $users->loadUnchanged($owner->id());
  $assert(array_map('intval', array_column($owner->get(TravelProfileModel::SAVED)->getValue(), 'target_id')) === [$node_id], 'Saving twice duplicated or removed the favorite.');
  $assert($users->loadUnchanged($other->id())->get(TravelProfileModel::SAVED)->isEmpty(), 'Saving changed another account.');

  $saved_card = FavoritePlanForm::buildCard($node_id);
  $assert($saved_card['submit']['#attributes']['aria-pressed'] === 'true'
    && $saved_card['operation']['#value'] === 'remove'
    && isset($saved_card['form_token']), 'The saved card has no protected remove control.');
  $assert($saved_card['#cache']['max-age'] === 0
    && in_array('user', $saved_card['#cache']['contexts'], TRUE), 'A saved card can leak through the shared cache.');
  $ajax_settings = $saved_card['submit']['#ajax'];
  $assert($ajax_settings['url']->getRouteName() === 'trotamundos_commerce.favorite_card'
    && $ajax_settings['options']['query']['ajax_form'] === 1
    && !empty($ajax_settings['wrapper'])
    && $ajax_settings['trigger_as']['name'] === 'op', 'The favorite does not submit through the protected AJAX form route.');
  $compact = new FavoritePlanForm($node_id, TRUE);
  $ajax_state = (new FormState())->set('favorite_saved', TRUE);
  $commands = $compact->ajaxFavorite($form, $ajax_state)->getCommands();
  $assert($commands[1]['method'] === 'val' && $commands[1]['args'] === ['remove']
    && $commands[2]['args'][0]['aria-pressed'] === 'true'
    && end($commands)['command'] === 'announce', 'A successful AJAX save did not update and announce the favorite state.');
  $ajax_state = (new FormState())->set('favorite_saved', FALSE);
  $commands = $compact->ajaxFavorite($form, $ajax_state)->getCommands();
  $assert($commands[1]['args'] === ['save']
    && $commands[2]['args'][0]['aria-pressed'] === 'false'
    && in_array('remove', array_column($commands, 'command'), TRUE), 'A successful AJAX removal did not update the heart and saved list.');
  $ajax_error = new FormState();
  $ajax_error->setErrorByName('operation', 'Favorite validation failed.');
  \Drupal::messenger()->addError('Favorite validation failed.');
  $commands = $compact->ajaxFavorite($form, $ajax_error)->getCommands();
  $assert(count($commands) === 1 && $commands[0]['command'] === 'insert'
    && $commands[0]['method'] === 'prepend'
    && str_contains((string) $commands[0]['data'], 'Favorite validation failed.'), 'An AJAX validation error was hidden or changed the favorite state.');
  $ajax_error->clearErrors();
  $switcher->switchTo($other);
  try {
    $other_card = FavoritePlanForm::buildCard($node_id);
    $assert($other_card['submit']['#attributes']['aria-pressed'] === 'false'
      && $other_card['operation']['#value'] === 'save', 'The favorite state leaked between customers.');
  }
  finally {
    $switcher->switchBack();
  }
  $switcher->switchTo(new AnonymousUserSession());
  try {
    $anonymous_card = FavoritePlanForm::buildCard($node_id);
    $assert($anonymous_card['#type'] === 'link'
      && $anonymous_card['#url']->getRouteName() === 'user.login'
      && isset($anonymous_card['#url']->getOption('query')['destination']), 'An anonymous heart can save without signing in or loses its return destination.');
  }
  finally {
    $switcher->switchBack();
  }

  $term_ids = $manager->getStorage('taxonomy_term')->getQuery()->accessCheck(TRUE)
    ->condition('vid', 'estilo_viaje')->condition('status', 1)->range(0, 1)->execute();
  $term_id = (int) reset($term_ids);
  $preferences = new TravelPreferencesForm();
  $state = (new FormState())->setValues(['styles' => [$term_id => $term_id], 'companions' => ['pareja' => 'pareja', 'solo' => 0]]);
  $preferences->validateForm($form, $state);
  $assert(!$state->hasAnyErrors(), 'Valid preferences were rejected.');
  $preferences->submitForm($form, $state);
  $redirect = $state->getRedirect();
  $assert($redirect && $redirect->getRouteName() === 'trotamundos_commerce.travel_preferences'
    && (int) $redirect->getRouteParameters()['user'] === (int) $owner->id(), 'Saving preferences did not return to their page.');
  TravelProfileModel::ensure();
  $owner = $users->loadUnchanged($owner->id());
  $assert((int) $owner->get(TravelProfileModel::STYLES)->target_id === $term_id && $owner->get(TravelProfileModel::COMPANIONS)->value === 'pareja', 'Preferences did not survive saving and repeated setup.');
  $assert($users->loadUnchanged($other->id())->get(TravelProfileModel::STYLES)->isEmpty(), 'Preferences changed another account.');

  $store = Store::create([
    'type' => 'online',
    'name' => 'Transactional profile fixture',
    'mail' => 'store@example.invalid',
    'default_currency' => 'COP',
    'address' => ['country_code' => 'CO'],
    'is_default' => FALSE,
  ]);
  $store->save();
  // Direct fixture states do not dispatch placement or receipt transitions.
  foreach (['own' => [$owner, 'completed'], 'other' => [$other, 'completed'], 'draft' => [$owner, 'draft']] as $name => [$customer, $order_state]) {
    Order::create([
      'type' => 'default',
      'store_id' => $store->id(),
      'uid' => $customer->id(),
      'mail' => $customer->getEmail(),
      'order_number' => 'transactional-profile-' . $name,
      'state' => $order_state,
    ])->save();
  }
  $controller = TravelProfileController::create(\Drupal::getContainer());
  $pages = [
    'orders' => $controller->view($owner),
    'saved' => $controller->view($owner, 'saved'),
    'preferences' => $controller->view($owner, 'preferences'),
  ];
  $assert(count($pages['orders']['#orders']) === 1 && str_contains((string) $pages['orders']['#orders'][0]['label'], 'transactional-profile-own'), 'The profile leaked another customer order or exposed a draft.');
  $assert(!str_starts_with((string) $pages['orders']['#orders'][0]['label'], 'Order ')
    && (string) $pages['orders']['#orders'][0]['state'] !== ''
    && (string) $pages['orders']['#orders'][0]['state'] !== 'Completed', 'The completed order was not localized in Spanish.');
  $assert(count($pages['saved']['#saved']) === 1 && $pages['saved']['#saved'][0]['card']['id'] === $node_id, 'Saved plans were not rendered.');
  $assert(!isset($pages['orders']['#saved']) && !isset($pages['orders']['#preferences'])
    && !isset($pages['saved']['#orders']) && !isset($pages['saved']['#preferences'])
    && !isset($pages['preferences']['#orders']) && !isset($pages['preferences']['#saved']), 'A profile page loaded another section.');
  $ids = ['orders' => 'travel-orders', 'saved' => 'saved-plans', 'preferences' => 'travel-preferences'];
  $url_keys = ['orders' => '#orders_url', 'saved' => '#saved_url', 'preferences' => '#preferences_url'];
  $assert(count(array_unique(array_map(static fn ($key) => $pages['orders'][$key], $url_keys))) === 3, 'Profile navigation does not lead to three distinct URLs.');
  $settings_url = \Drupal\Core\Url::fromRoute('trotamundos_commerce.account_settings', ['user' => $owner->id()])->toString();
  $assert($pages['orders']['#edit_url'] === $settings_url, 'Account settings does not link to the front-end form.');
  foreach ($pages as $section => $page) {
    $assert($page['#cache']['max-age'] === 0 && in_array('user', $page['#cache']['contexts'], TRUE), 'A private profile page is not isolated by account.');
    $markup = (string) \Drupal::service('renderer')->renderRoot($page);
    foreach ($ids as $name => $id) {
      $assert(str_contains($markup, 'id="' . $id . '"') === ($section === $name), 'A profile page rendered the wrong sections.');
    }
    $assert(substr_count($markup, 'aria-current="page"') === 1
      && str_contains($markup, 'href="' . $page[$url_keys[$section]] . '" aria-current="page"'), 'Profile navigation did not mark the current page.');
  }
  $access = \Drupal::service('access_manager');
  foreach (['trotamundos_commerce.saved_plans', 'trotamundos_commerce.travel_preferences'] as $route) {
    $assert($access->checkNamedRoute($route, ['user' => $owner->id()], $owner)
      && !$access->checkNamedRoute($route, ['user' => $owner->id()], $other)
      && !$access->checkNamedRoute($route, ['user' => $owner->id()], new AnonymousUserSession()), 'A private profile route is not restricted to its owner.');
  }
  $settings_route = 'trotamundos_commerce.account_settings';
  $assert($access->checkNamedRoute($settings_route, ['user' => $owner->id()], $owner)
    && !$access->checkNamedRoute($settings_route, ['user' => $owner->id()], $other)
    && !$access->checkNamedRoute($settings_route, ['user' => $owner->id()], $users->load(1))
    && !$access->checkNamedRoute($settings_route, ['user' => $owner->id()], new AnonymousUserSession()), 'Front-end account settings is not restricted to its owner.');
  $routes = \Drupal::service('router.route_provider');
  $front_route = $routes->getRouteByName($settings_route);
  $native_route = $routes->getRouteByName('entity.user.edit_form');
  $assert($front_route->getDefault('_entity_form') === 'user.default'
    && !$front_route->getOption('_admin_route')
    && $native_route->getOption('_admin_route'), 'Account settings changed or bypassed the native administrative form.');
  drupal_static_reset('_trotamundos_site_data');
  $response = \Drupal::service('http_kernel')->handle(Request::create($settings_url), HttpKernelInterface::SUB_REQUEST);
  $assert($response->getStatusCode() === 200 && $response instanceof HtmlResponse
    && in_array('trotamundos/travel-profile', $response->getAttachments()['library'] ?? [], TRUE), 'The account settings URL did not load its front-end styles.');
  $document = new DOMDocument();
  $assert($document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), 'Account settings did not render HTML.');
  $xpath = new DOMXPath($document);
  $assert($xpath->query('//main[contains(concat(" ", normalize-space(@class), " "), " content-page ")]//form[contains(concat(" ", normalize-space(@class), " "), " account-settings-form ")]')->length === 1
    && $xpath->query('//form[contains(@class, "account-settings-form")]//input[@name="mail"]')->length === 1
    && $xpath->query('//form[contains(@class, "account-settings-form")]//input[@name="current_pass"]')->length === 1
    && $xpath->query('//form[contains(@class, "account-settings-form")]//input[@name="pass[pass1]"]')->length === 1
    && $xpath->query('//form[contains(@class, "account-settings-form")]//input[@name="pass[pass2]"]')->length === 1
    && $xpath->query('//form[contains(@class, "account-settings-form")]//input[@name="form_token"]')->length === 1
    && $xpath->query('//form[contains(@class, "account-settings-form")]//a[contains(@class, "account-settings__back") and @href="' . $pages['orders']['#orders_url'] . '"]')->length === 1, 'The front-end form is missing secure native account controls or profile navigation.');
  foreach ($pages as $section => $page) {
    drupal_static_reset('_trotamundos_site_data');
    $request = Request::create($page[$url_keys[$section]]);
    $response = \Drupal::service('http_kernel')->handle($request, HttpKernelInterface::SUB_REQUEST);
    $assert($response->getStatusCode() === 200, 'A profile section URL did not respond successfully.');
    $document = new DOMDocument();
    $assert($document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), 'A profile section URL did not render HTML.');
    $xpath = new DOMXPath($document);
    foreach ($ids as $name => $id) {
      $assert(($xpath->query('//section[@id="' . $id . '"]')->length === 1) === ($section === $name), 'A profile section URL displayed the wrong content.');
    }
    if ($section === 'saved') {
      $settings_node = $xpath->query('//script[@data-drupal-selector="drupal-settings-json"]')->item(0);
      $button = $xpath->query('//*[@id="saved-plans"]//form[contains(@class, "favorite-plan-form--compact")]//input[@type="submit"]')->item(0);
      $assert($settings_node && $button, 'The saved page lost its protected favorite control.');
      $settings = json_decode($settings_node->textContent, TRUE, 512, JSON_THROW_ON_ERROR);
      $assert(str_contains($settings['ajax'][$button->getAttribute('id')]['url'] ?? '', 'favorite_profile=1'), 'The saved-page favorite cannot restore focus after AJAX removal.');
    }
  }
  $assert(($controller->view($other)['#theme'] ?? NULL) !== 'trotamundos_travel_profile', 'Another account received the private dashboard.');
  try {
    $controller->view($other, 'saved');
    throw new RuntimeException('Another account received a private profile section.');
  }
  catch (AccessDeniedHttpException) {
  }
  $missing = $node_id + 100000;
  while (Node::load($missing)) {
    $missing++;
  }
  $owner->set(TravelProfileModel::SAVED, [$node_id, $missing])->save();
  $build = $controller->view($owner, 'saved');
  $assert(count($build['#saved']) === 2 && $build['#saved'][1]['card'] === NULL, 'An unavailable saved plan cannot be managed.');
  $remove = (new FormState())->setValues(['operation' => 'remove']);
  (new FavoritePlanForm($missing))->submitForm($form, $remove);
  $favorite->submitForm($form, $remove);
  $favorite->submitForm($form, $remove);
  $assert($users->loadUnchanged($owner->id())->get(TravelProfileModel::SAVED)->isEmpty(), 'Removing saved plans is not idempotent.');

  $invalid = (new FormState())->setValues(['operation' => 'save']);
  (new FavoritePlanForm($missing))->validateForm($form, $invalid);
  $assert($invalid->hasAnyErrors(), 'An unavailable plan was accepted for saving.');
  $invalid->clearErrors();
}
finally {
  $transaction->rollBack();
  if ($switched) {
    $switcher->switchBack();
  }
  foreach (['user', 'node', 'commerce_order', 'commerce_store'] as $entity_type) {
    $manager->getStorage($entity_type)->resetCache();
  }
}
print "Private travel profile checks passed: separate pages, front-end account settings, ownership, preferences, idempotent favorites, unavailable plans and customer-scoped orders.\n";
