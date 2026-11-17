<?php

declare(strict_types=1);

/**
 * @file
 * Run with drush php:script. Fixture accounts and orders are rolled back.
 */

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_store\Entity\Store;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\Entity\Node;
use Drupal\trotamundos_commerce\Controller\TravelProfileController;
use Drupal\trotamundos_commerce\Form\FavoritePlanForm;
use Drupal\trotamundos_commerce\Form\TravelPreferencesForm;
use Drupal\trotamundos_commerce\Setup\TravelProfileModel;
use Drupal\trotamundos_experiences\Setup\ProductTypes;
use Drupal\user\Entity\User;

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
  $build = $controller->view($owner);
  $assert(count($build['#orders']) === 1 && str_contains((string) $build['#orders'][0]['label'], 'transactional-profile-own'), 'The profile leaked another customer order or exposed a draft.');
  $assert(count($build['#saved']) === 1 && $build['#saved'][0]['card']['id'] === $node_id, 'Saved plans were not rendered.');
  $assert($build['#cache']['max-age'] === 0 && in_array('user', $build['#cache']['contexts'], TRUE), 'The private profile is not isolated by account.');
  $assert(($controller->view($other)['#theme'] ?? NULL) !== 'trotamundos_travel_profile', 'Another account received the private dashboard.');

  $missing = $node_id + 100000;
  while (Node::load($missing)) {
    $missing++;
  }
  $owner->set(TravelProfileModel::SAVED, [$node_id, $missing])->save();
  $build = $controller->view($owner);
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
print "Private travel profile checks passed: ownership, preferences, idempotent favorites, unavailable plans and customer-scoped orders.\n";
