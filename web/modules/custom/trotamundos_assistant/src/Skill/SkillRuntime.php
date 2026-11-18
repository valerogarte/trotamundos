<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Skill;

use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\trotamundos_assistant\Form\EmbeddedAssistantFormAjax;
use Drupal\trotamundos_assistant\Plugin\search_api\processor\TravelDocument;
use Drupal\trotamundos_experiences\ExperienceRepository;
use Drupal\trotamundos_experiences\Setup\ProductTypes;

/**
 * Shared runtime services available to skill handlers.
 */
final class SkillRuntime {

  use StringTranslationTrait;

  private const EXCLUDED_PRODUCT_BUNDLE = 'deposit';

  /**
   * Product card data cache, keyed by product and variation IDs.
   *
   * @var array<string, array<string, mixed>>
   */
  private array $productCardCache = [];

  /**
   * Current user's favorite product IDs.
   *
   * @var array<int, int>|null
   */
  private ?array $favoriteProductIds = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly AccountProxyInterface $currentUser,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityFormBuilderInterface $entityFormBuilder,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly mixed $cartProvider = NULL,
    private readonly mixed $orderTotalSummary = NULL,
    private readonly ?EntityRepositoryInterface $entityRepository = NULL,
    private readonly ?ExperienceRepository $experienceRepository = NULL,
  ) {
  }

  /**
   * Builds cards from actual public editorial plans, never model-supplied data.
   *
   * @return array<int, array{card: array, reason: string}>
   *   Cards and optional explanations in the provider's order.
   */
  public function resolveTravelPlans(mixed $items): array {
    if ($this->experienceRepository === NULL) {
      throw new \LogicException('The travel catalog repository is unavailable.');
    }
    $nodes = $this->loadPublicTravelNodes($items, ProductTypes::BUNDLES);
    $plans = [];
    foreach ($nodes as [$node, $item]) {
      $plans[] = [
        'card' => $this->experienceRepository->card($node),
        'reason' => mb_substr(trim((string) ($item['reason'] ?? '')), 0, 400),
      ];
    }
    return $plans;
  }

  /**
   * Resolves cited node IDs to public, currently accessible source links.
   *
   * @return array<int, array{title: string, url: string, type: string}>
   *   Source links in the provider's order.
   */
  public function resolveTravelSources(mixed $items): array {
    $sources = [];
    foreach ($this->loadPublicTravelNodes($items, TravelDocument::BUNDLES) as [$node]) {
      $type = $node->get('type')->entity;
      $sources[] = [
        'title' => (string) $node->label(),
        'url' => $node->toUrl()->toString(),
        'type' => $type ? (string) $type->label() : $node->bundle(),
      ];
    }
    return $sources;
  }

  /**
   * Retains items retrieved for the public translation currently displayed.
   *
   * @param array<int, array<string, bool>> $retrieved_nodes
   *   Node IDs and language codes obtained from the search tool.
   * @param string[] $bundles
   *   Permitted content bundles.
   *
   * @return array<int, array<string, mixed>>
   *   Verified input items in their original order.
   */
  public function filterRetrievedTravelItems(mixed $items, array $retrieved_nodes, array $bundles): array {
    $verified = [];
    foreach ($this->loadPublicTravelNodes($items, $bundles) as [$node, $item]) {
      if (!empty($retrieved_nodes[(int) $node->id()][$node->language()->getId()])) {
        $verified[] = $item;
      }
    }
    return $verified;
  }

  /**
   * Loads at most six distinct nodes and checks access in the active language.
   *
   * @param mixed $items
   *   Provider items containing a node_id.
   * @param string[] $bundles
   *   Permitted content bundles.
   *
   * @return array<int, array{0: \Drupal\node\NodeInterface, 1: array}>
   *   Accessible nodes paired with their input.
   */
  private function loadPublicTravelNodes(mixed $items, array $bundles): array {
    if (!is_array($items)) {
      return [];
    }
    if ($this->entityRepository === NULL) {
      throw new \LogicException('The entity translation repository is unavailable.');
    }

    $requested = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $id = filter_var($item['node_id'] ?? NULL, FILTER_VALIDATE_INT);
      if ($id !== FALSE && $id > 0 && !isset($requested[$id])) {
        $requested[$id] = $item;
      }
      if (count($requested) === 6) {
        break;
      }
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $loaded = $storage->loadMultiple(array_keys($requested));
    $nodes = [];
    foreach ($requested as $id => $item) {
      if (!isset($loaded[$id])) {
        continue;
      }
      $node = $this->entityRepository->getTranslationFromContext($loaded[$id]);
      if ($node instanceof NodeInterface && in_array($node->bundle(), $bundles, TRUE)
        && TravelDocument::isPublic($node) && $node->access('view', $this->currentUser)) {
        $nodes[] = [$node, $item];
      }
    }
    return $nodes;
  }

  /**
   * Normalizes card/list items expected by product templates.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized items.
   */
  public function normalizeItems(mixed $items, bool $require_catalog_product = FALSE): array {
    if (!is_array($items)) {
      return [];
    }

    $normalized = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $product_id = (int) ($item['product_id'] ?? 0);
      $variation_id = (int) ($item['variation_id'] ?? 0);
      $body = trim((string) ($item['body'] ?? $item['description'] ?? $item['summary'] ?? ''));
      $title = trim((string) ($item['title'] ?? $item['name'] ?? ''));
      $card = $this->resolveProductCardDataFromIds(
        $product_id,
        $variation_id > 0 ? $variation_id : NULL,
        $title,
      );
      if ($require_catalog_product && empty($card['is_catalog_product'])) {
        continue;
      }

      $normalized[] = [
        'title' => $title,
        'body' => $body,
        'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
      ] + $card;
    }

    return $normalized;
  }

  /**
   * Normalizes rows expected by comparison templates.
   *
   * @return array<int, array{label: string, value: string}>
   *   Normalized rows.
   */
  public function normalizeRows(mixed $rows): array {
    if (!is_array($rows)) {
      return [];
    }

    $normalized = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }

      $normalized[] = [
        'label' => trim((string) ($row['label'] ?? $row['title'] ?? '')),
        'value' => trim((string) ($row['value'] ?? $row['body'] ?? $row['description'] ?? '')),
      ];
    }

    return $normalized;
  }

  /**
   * Converts paragraphs to generic items.
   *
   * @return array<int, array<string, mixed>>
   *   Generic text items.
   */
  public function paragraphsAsItems(string $text): array {
    $paragraphs = preg_split('/\n{2,}/', $this->normalizeResponseText($text)) ?: [];
    $items = [];

    foreach ($paragraphs as $paragraph) {
      $paragraph = $this->cleanInlineText($paragraph);
      if ($paragraph === '') {
        continue;
      }

      $items[] = [
        'title' => '',
        'body' => $paragraph,
      ];
    }

    return $items;
  }

  /**
   * Builds the current user edit form for profile-oriented skills.
   */
  public function buildUserProfileForm(): array {
    if (!$this->currentUser->isAuthenticated()) {
      return [];
    }

    $account = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    if (!$account) {
      return [];
    }

    $form = $this->entityFormBuilder->getForm($account, 'default');
    $form = $this->prepareEmbeddedAssistantForm(
      $form,
      'trotamundos-assistant-profile-form',
      $this->getProfileEditUrl(),
    );

    return $form;
  }

  /**
   * Returns the profile edit URL for the current user or login URL.
   */
  public function getProfileEditUrl(): string {
    return $this->currentUser->isAuthenticated()
      ? Url::fromRoute('entity.user.edit_form', ['user' => $this->currentUser->id()])->toString()
      : Url::fromRoute('user.login')->toString();
  }

  /**
   * Checks whether the current user is authenticated.
   */
  public function isAuthenticated(): bool {
    return $this->currentUser->isAuthenticated();
  }

  /**
   * Prepares an entity form to be submitted inside the assistant panel.
   *
   * @param array<string, mixed> $form
   *   Entity form render array.
   * @param string $form_class
   *   CSS class added to the embedded form.
   * @param string $action
   *   Real entity form action URL.
   *
   * @return array<string, mixed>
   *   Prepared form render array.
   */
  private function prepareEmbeddedAssistantForm(array $form, string $form_class, string $action): array {
    $wrapper_id = Html::getUniqueId('trotamundos-assistant-embedded-form');
    if ($action !== '') {
      $form['#action'] = $this->getEmbeddedAssistantFormAction($action, $wrapper_id, $form_class);
    }

    EmbeddedAssistantFormAjax::prepareForm($form, $wrapper_id, $form_class);

    return $form;
  }

  /**
   * Adds assistant form metadata to the real entity edit action URL.
   */
  private function getEmbeddedAssistantFormAction(string $action, string $wrapper_id, string $form_class): string {
    $parts = UrlHelper::parse($action);
    $parts['query'] = array_merge($parts['query'] ?? [], [
      'trotamundos_assistant_embedded' => '1',
      'trotamundos_assistant_wrapper' => $wrapper_id,
      'trotamundos_assistant_form_class' => $form_class,
    ]);

    $query = UrlHelper::buildQuery($parts['query']);
    $fragment = $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

    return $parts['path'] . ($query !== '' ? '?' . $query : '') . $fragment;
  }

  /**
   * Gets an entity label by ID.
   */
  public function getEntityLabel(string $entity_type_id, int $entity_id): string {
    $entity = $this->loadEntity($entity_type_id, $entity_id);

    return $entity instanceof EntityInterface ? (string) $entity->label() : '';
  }

  /**
   * Gets an entity canonical URL by ID when available.
   */
  public function getEntityUrl(string $entity_type_id, int $entity_id): string {
    $entity = $this->loadEntity($entity_type_id, $entity_id);
    if (!$entity instanceof EntityInterface) {
      return '';
    }

    try {
      return $entity->toUrl()->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Gets an absolute file URL by file ID.
   */
  public function getFileUrl(int $file_id): string {
    if ($file_id <= 0 || !$this->entityTypeManager->hasDefinition('file')) {
      return '';
    }

    try {
      $file = $this->entityTypeManager->getStorage('file')->load($file_id);
      if (!is_object($file) || !method_exists($file, 'getFileUri')) {
        return '';
      }

      return $this->fileUrlGenerator->generateAbsoluteString((string) $file->getFileUri());
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Builds the edit form for a content entity resolved by title or ID.
   *
   * @return array<string, mixed>
   *   Content edit display data.
   */
  public function buildContentEditForm(string $content_title, int $entity_id = 0, string $entity_type_id = ''): array {
    $result = [
      'found' => FALSE,
      'access_denied' => FALSE,
      'ambiguous' => FALSE,
      'content_title' => $content_title,
      'content_type' => '',
      'edit_url' => '',
      'content_form' => [],
      'matches' => [],
    ];

    $entities = $entity_id > 0 && $entity_type_id !== ''
      ? $this->loadContentEntitiesByIds($entity_type_id, [$entity_id])
      : $this->findContentEntitiesByTitle($entity_type_id, $content_title);

    if ($entities === []) {
      return $result;
    }

    if (count($entities) > 1 && $entity_id <= 0) {
      $result['ambiguous'] = TRUE;
      $result['matches'] = $this->normalizeContentMatches($entities);
      return $result;
    }

    $entity = reset($entities);
    if (!$entity instanceof ContentEntityInterface) {
      return $result;
    }

    $result['found'] = TRUE;
    $result['content_title'] = (string) $entity->label();
    $result['content_type'] = $this->getContentBundleLabel($entity);
    $result['edit_url'] = $this->getEntityEditUrl($entity);

    if (!$entity->access('update', $this->currentUser)) {
      $result['access_denied'] = TRUE;
      return $result;
    }

    try {
      $route = \Drupal::service('router.route_provider')->getRouteByName($entity->toUrl('edit-form')->getRouteName());
      $entity_form = (string) $route->getDefault('_entity_form');
      $operation = explode('.', $entity_form, 2)[1] ?? 'default';
      $form = $this->entityFormBuilder->getForm($entity, $operation);
      $form = $this->prepareEmbeddedAssistantForm(
        $form,
        'trotamundos-assistant-content-edit-form',
        $result['edit_url'],
      );
      $result['content_form'] = $form;
    }
    catch (\Throwable) {
      $result['access_denied'] = TRUE;
    }

    return $result;
  }

  /**
   * Builds an add form for a content entity type and bundle.
   *
   * @return array<string, mixed>
   *   Content creation display data.
   */
  public function buildContentCreateForm(string $entity_type_id = '', string $bundle = '', string $content_title = ''): array {
    $entity_type_id = $this->normalizeContentCreateEntityType($entity_type_id, $bundle);
    $entity_type_options = $this->getCreatableContentEntityTypeOptions();
    $result = [
      'available' => FALSE,
      'access_denied' => FALSE,
      'ambiguous' => FALSE,
      'entity_type' => $entity_type_id,
      'entity_type_label' => '',
      'bundle' => $bundle,
      'bundle_label' => '',
      'add_url' => '',
      'content_form' => [],
      'bundle_options' => [],
      'entity_type_options' => $entity_type_options,
    ];

    if ($entity_type_id === '' || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      $result['available'] = $entity_type_options !== [];
      $result['ambiguous'] = $entity_type_options !== [];
      return $result;
    }

    $definition = $this->entityTypeManager->getDefinition($entity_type_id);
    if (!$definition instanceof ContentEntityTypeInterface || !$definition->hasLinkTemplate('add-form')) {
      return $result;
    }

    $result['available'] = TRUE;
    $result['entity_type_label'] = (string) $definition->getLabel();
    $bundle_options = $this->getCreatableContentBundleOptions($entity_type_id);
    $result['bundle_options'] = $bundle_options;
    if ($bundle_options === []) {
      $result['access_denied'] = TRUE;
      return $result;
    }

    $bundle_id = $this->resolveContentBundleId($bundle, $bundle_options);
    if ($bundle_id === '' && count($bundle_options) === 1) {
      $bundle_id = (string) $bundle_options[0]['id'];
    }
    if ($bundle_id === '') {
      $result['ambiguous'] = TRUE;
      return $result;
    }

    $bundle_label = $this->getContentBundleOptionLabel($bundle_id, $bundle_options);
    $add_url = $this->getContentAddUrl($definition, $bundle_id);
    $result['bundle'] = $bundle_id;
    $result['bundle_label'] = $bundle_label;
    $result['add_url'] = $add_url;

    $values = [];
    $bundle_key = (string) $definition->getKey('bundle');
    if ($bundle_key !== '') {
      $values[$bundle_key] = $bundle_id;
    }

    $label_key = (string) $definition->getKey('label');
    if ($label_key !== '' && trim($content_title) !== '') {
      $values[$label_key] = trim($content_title);
    }

    try {
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->create($values);
      if (!$entity instanceof ContentEntityInterface) {
        return $result;
      }

      if (!$entity->access('create', $this->currentUser)) {
        $result['access_denied'] = TRUE;
        return $result;
      }

      $form = $this->entityFormBuilder->getForm($entity, 'default');
      $form = $this->prepareEmbeddedAssistantForm(
        $form,
        'trotamundos-assistant-content-edit-form',
        $add_url,
      );
      $result['content_form'] = $form;
    }
    catch (\Throwable) {
      $result['access_denied'] = TRUE;
    }

    return $result;
  }

  /**
   * Builds provider-facing entity and bundle options for content creation.
   *
   * @return array<int, array{entity_type: string, entity_type_label: string, bundles: array<int, array{bundle: string, bundle_label: string}>}>
   *   Creatable entity/bundle targets for the current user.
   */
  public function buildContentCreateProviderCatalog(): array {
    $catalog = [];
    foreach ($this->getCreatableContentEntityTypeOptions() as $entity_type_option) {
      $bundles = [];
      foreach ($this->getCreatableContentBundleOptions((string) $entity_type_option['id']) as $bundle_option) {
        $bundles[] = [
          'bundle' => (string) $bundle_option['id'],
          'bundle_label' => (string) $bundle_option['label'],
        ];
      }

      if ($bundles === []) {
        continue;
      }

      $catalog[] = [
        'entity_type' => (string) $entity_type_option['id'],
        'entity_type_label' => (string) $entity_type_option['label'],
        'bundles' => $bundles,
      ];
    }

    return $catalog;
  }

  /**
   * Resolves cited source titles to linkable content matches.
   *
   * @param array<int, string> $titles
   *   Content titles the assistant used as sources.
   * @param int $limit
   *   Maximum number of links to return.
   *
   * @return array<int, array{title: string, url: string, type: string, snippet: string}>
   *   Linkable matches for each resolved title.
   */
  public function resolveContentSources(array $titles, int $limit = 6): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $matches = [];
    $seen = [];
    foreach ($titles as $title) {
      $title = trim((string) $title);
      if ($title === '' || count($matches) >= $limit) {
        continue;
      }
      // Exact title first, then a loose match so minor LLM paraphrasing still
      // resolves to the real node.
      $ids = $storage->getQuery()->accessCheck(TRUE)->condition('status', 1)
        ->condition('title', $title)->range(0, 1)->execute()
        ?: $storage->getQuery()->accessCheck(TRUE)->condition('status', 1)
          ->condition('title', $title, 'CONTAINS')->range(0, 1)->execute();
      $node = $ids ? $storage->load(reset($ids)) : NULL;
      if ($node === NULL || isset($seen[$node->id()])) {
        continue;
      }
      $seen[$node->id()] = TRUE;
      $type = $node->get('type')->entity;
      $matches[] = [
        'title' => (string) $node->label(),
        'url' => $node->toUrl()->toString(),
        'type' => $type ? (string) $type->label() : $node->bundle(),
        'snippet' => '',
      ];
    }

    return $matches;
  }

  /**
   * Searches WYSIWYG (formatted long text) content and returns plain snippets.
   *
   * @param string $query
   *   Terms to look for inside the formatted text fields.
   * @param int $limit
   *   Maximum number of matches to return.
   *
   * @return array{query: string, found: bool, matches: array<int, array{title: string, url: string, type: string, snippet: string}>}
   *   Search result with grounded excerpts for the assistant to answer from.
   */
  public function searchWysiwygContent(string $query, int $limit = 5): array {
    $query = trim($query);
    if ($query === '') {
      return ['query' => '', 'found' => FALSE, 'matches' => []];
    }

    $fields = $this->getSearchableNodeFields();
    $storage = $this->entityTypeManager->getStorage('node');
    $entity_query = $storage->getQuery()->accessCheck(TRUE)->condition('status', 1);
    $group = $entity_query->orConditionGroup()->condition('title', $query, 'CONTAINS');
    foreach ($fields as $field => $property) {
      $group->condition($field . '.' . $property, $query, 'CONTAINS');
    }
    $ids = $entity_query->condition($group)->sort('changed', 'DESC')->range(0, $limit)->execute();

    $matches = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $text = '';
      foreach ($fields as $field => $property) {
        if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
          foreach ($node->get($field) as $item) {
            $text .= ' ' . (string) ($item->{$property} ?? '');
          }
        }
      }
      $type = $node->get('type')->entity;
      $matches[] = [
        'title' => (string) $node->label(),
        'url' => $node->toUrl()->toString(),
        'type' => $type ? (string) $type->label() : $node->bundle(),
        'snippet' => $this->buildWysiwygSnippet($text, $query),
      ];
    }

    return ['query' => $query, 'found' => $matches !== [], 'matches' => $matches];
  }

  /**
   * Lists every text/string node field to search, keyed by main property.
   *
   * @return array<string, string>
   *   Field machine name => main property name (usually 'value').
   */
  private function getSearchableNodeFields(): array {
    // ponytail: text-ish types only; add 'email','telephone','list_string' here
    // if those fields should also be searchable.
    $allowed = ['string', 'string_long', 'text', 'text_long', 'text_with_summary'];
    $fields = [];
    foreach ($this->entityFieldManager->getFieldStorageDefinitions('node') as $name => $storage) {
      if (in_array($storage->getType(), $allowed, TRUE)) {
        $fields[$name] = $storage->getMainPropertyName() ?: 'value';
      }
    }
    // Title is already queried as a base field.
    unset($fields['title']);

    return $fields;
  }

  /**
   * Strips HTML from WYSIWYG text and returns an excerpt around the query.
   */
  private function buildWysiwygSnippet(string $html, string $query): string {
    $text = trim((string) preg_replace('/\s+/', ' ', Html::decodeEntities(strip_tags($html))));
    if ($text === '') {
      return '';
    }
    $pos = mb_stripos($text, $query);
    if ($pos === FALSE) {
      return mb_substr($text, 0, 240) . (mb_strlen($text) > 240 ? '…' : '');
    }
    $start = max(0, $pos - 80);
    return ($start > 0 ? '…' : '') . trim(mb_substr($text, $start, 240)) . '…';
  }

  /**
   * Finds content entities by title.
   *
   * @return array<int|string, \Drupal\Core\Entity\ContentEntityInterface>
   *   Matching content entities.
   */
  private function findContentEntitiesByTitle(string $entity_type_id, string $content_title): array {
    $content_title = trim($content_title);
    if ($content_title === '') {
      return [];
    }

    $entity_type_ids = $entity_type_id !== ''
      ? [$entity_type_id]
      : $this->getEditableContentEntityTypeIds();

    $exact_matches = [];
    foreach ($entity_type_ids as $candidate_entity_type_id) {
      $exact_matches += $this->loadContentEntitiesByIds(
        $candidate_entity_type_id,
        $this->queryContentEntityIdsByTitle($candidate_entity_type_id, $content_title, '='),
      );
    }
    if ($exact_matches !== []) {
      return $exact_matches;
    }

    $partial_matches = [];
    foreach ($entity_type_ids as $candidate_entity_type_id) {
      $partial_matches += $this->loadContentEntitiesByIds(
        $candidate_entity_type_id,
        $this->queryContentEntityIdsByTitle($candidate_entity_type_id, $content_title, 'CONTAINS'),
      );
    }

    return $partial_matches;
  }

  /**
   * Queries content entity IDs by title.
   *
   * @return array<int|string>
   *   Entity IDs.
   */
  private function queryContentEntityIdsByTitle(string $entity_type_id, string $content_title, string $operator): array {
    $label_key = $this->getContentEntityLabelKey($entity_type_id);
    if ($label_key === '') {
      return [];
    }

    try {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $query = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition($label_key, $content_title, $operator)
        ->range(0, 6);
      if ($this->getContentEntityChangedKey($entity_type_id) !== '') {
        $query->sort($this->getContentEntityChangedKey($entity_type_id), 'DESC');
      }

      return array_values($query->execute());
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Loads content entities by IDs.
   *
   * @param array<int|string> $ids
   *   Entity IDs.
   *
   * @return array<int|string, \Drupal\Core\Entity\ContentEntityInterface>
   *   Content entities.
   */
  private function loadContentEntitiesByIds(string $entity_type_id, array $ids): array {
    if ($ids === [] || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return [];
    }

    try {
      $entities = $this->entityTypeManager->getStorage($entity_type_id)->loadMultiple($ids);
    }
    catch (\Throwable) {
      return [];
    }

    $typed_entities = [];
    foreach ($entities as $entity) {
      if ($entity instanceof ContentEntityInterface) {
        $typed_entities[$entity->getEntityTypeId() . ':' . $entity->id()] = $entity;
      }
    }

    return $typed_entities;
  }

  /**
   * Gets content entity types that can reasonably expose an edit form.
   *
   * @return string[]
   *   Entity type IDs.
   */
  private function getEditableContentEntityTypeIds(): array {
    $entity_type_ids = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $definition) {
      if (!$definition instanceof ContentEntityTypeInterface) {
        continue;
      }
      if (!$definition->hasLinkTemplate('edit-form') || $definition->getKey('label') === '') {
        continue;
      }

      $entity_type_ids[] = (string) $entity_type_id;
    }

    return $entity_type_ids;
  }

  /**
   * Normalizes a content entity type requested for creation.
   */
  private function normalizeContentCreateEntityType(string $entity_type_id, string $bundle): string {
    $entity_type_id = trim($entity_type_id);
    $lookup = $this->normalizeLookupToken($entity_type_id);

    foreach ($this->getCreatableContentEntityTypeOptions() as $entity_type_option) {
      if ((string) $entity_type_option['id'] === $entity_type_id) {
        return (string) $entity_type_option['id'];
      }
      if ($lookup !== '' && $this->normalizeLookupToken((string) $entity_type_option['label']) === $lookup) {
        return (string) $entity_type_option['id'];
      }
    }

    $bundle_lookup = $this->normalizeLookupToken($bundle !== '' ? $bundle : $entity_type_id);
    if ($bundle_lookup !== '') {
      $matches = [];
      foreach ($this->getCreatableContentEntityTypeOptions() as $entity_type_option) {
        $candidate_entity_type_id = (string) $entity_type_option['id'];
        foreach ($this->getCreatableContentBundleOptions($candidate_entity_type_id) as $option) {
          if ($this->normalizeLookupToken((string) $option['id']) === $bundle_lookup || $this->normalizeLookupToken((string) $option['label']) === $bundle_lookup) {
            $matches[$candidate_entity_type_id] = TRUE;
          }
        }
      }

      if (count($matches) === 1) {
        return (string) array_key_first($matches);
      }
    }

    return '';
  }

  /**
   * Gets content entity types that expose an add form and at least one bundle.
   *
   * @return array<int, array{id: string, label: string}>
   *   Display-ready entity type options.
   */
  private function getCreatableContentEntityTypeOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $definition) {
      if (!$definition instanceof ContentEntityTypeInterface || !$definition->hasLinkTemplate('add-form')) {
        continue;
      }
      if ($this->getCreatableContentBundleOptions((string) $entity_type_id) === []) {
        continue;
      }

      $options[] = [
        'id' => (string) $entity_type_id,
        'label' => (string) $definition->getLabel(),
      ];
    }

    usort($options, static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

    return $options;
  }

  /**
   * Gets bundles that the current user can create for an entity type.
   *
   * @return array<int, array{id: string, label: string, add_url: string}>
   *   Display-ready bundle options.
   */
  private function getCreatableContentBundleOptions(string $entity_type_id): array {
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return [];
    }

    $definition = $this->entityTypeManager->getDefinition($entity_type_id);
    if (!$definition instanceof ContentEntityTypeInterface || !$definition->hasLinkTemplate('add-form')) {
      return [];
    }

    $bundle_entity_type = $definition->getBundleEntityType();
    $bundle_key = (string) $definition->getKey('bundle');
    if (!$bundle_entity_type || $bundle_key === '' || !$this->entityTypeManager->hasDefinition($bundle_entity_type)) {
      return [];
    }

    try {
      $bundles = $this->entityTypeManager->getStorage($bundle_entity_type)->loadMultiple();
    }
    catch (\Throwable) {
      return [];
    }

    $options = [];
    foreach ($bundles as $bundle) {
      if (!$bundle instanceof EntityInterface) {
        continue;
      }

      $bundle_id = (string) $bundle->id();
      try {
        $entity = $this->entityTypeManager->getStorage($entity_type_id)->create([
          $bundle_key => $bundle_id,
        ]);
      }
      catch (\Throwable) {
        continue;
      }

      if (!$entity instanceof ContentEntityInterface || !$entity->access('create', $this->currentUser)) {
        continue;
      }

      $options[] = [
        'id' => $bundle_id,
        'label' => (string) $bundle->label(),
        'add_url' => $this->getContentAddUrl($definition, $bundle_id),
      ];
    }

    usort($options, static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

    return $options;
  }

  /**
   * Resolves a requested bundle ID or label from available bundle options.
   *
   * @param array<int, array{id: string, label: string, add_url: string}> $bundle_options
   *   Available bundle options.
   */
  private function resolveContentBundleId(string $bundle, array $bundle_options): string {
    $bundle = trim($bundle);
    if ($bundle === '') {
      return '';
    }

    $lookup = $this->normalizeLookupToken($bundle);
    foreach ($bundle_options as $option) {
      if ((string) $option['id'] === $bundle) {
        return (string) $option['id'];
      }
      if ($this->normalizeLookupToken((string) $option['id']) === $lookup || $this->normalizeLookupToken((string) $option['label']) === $lookup) {
        return (string) $option['id'];
      }
    }

    return '';
  }

  /**
   * Gets a bundle option label by ID.
   *
   * @param array<int, array{id: string, label: string, add_url: string}> $bundle_options
   *   Available bundle options.
   */
  private function getContentBundleOptionLabel(string $bundle_id, array $bundle_options): string {
    foreach ($bundle_options as $option) {
      if ((string) $option['id'] === $bundle_id) {
        return (string) $option['label'];
      }
    }

    return $bundle_id;
  }

  /**
   * Builds the add form URL for a bundle.
   */
  private function getContentAddUrl(ContentEntityTypeInterface $definition, string $bundle_id): string {
    try {
      $parameters = [];
      $bundle_entity_type = $definition->getBundleEntityType();
      if ($bundle_entity_type && $bundle_id !== '') {
        $parameters[$bundle_entity_type] = $bundle_id;
      }

      return Url::fromRoute('entity.' . $definition->id() . '.add_form', $parameters)->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Normalizes IDs and labels for loose matching.
   */
  private function normalizeLookupToken(string $value): string {
    $value = trim(mb_strtolower($value));
    $value = strtr($value, [
      'á' => 'a',
      'é' => 'e',
      'í' => 'i',
      'ó' => 'o',
      'ú' => 'u',
      'ü' => 'u',
      'ñ' => 'n',
    ]);

    return trim(preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '', '_');
  }

  /**
   * Gets the label key for a content entity type.
   */
  private function getContentEntityLabelKey(string $entity_type_id): string {
    if ($entity_type_id === '' || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return '';
    }

    $definition = $this->entityTypeManager->getDefinition($entity_type_id);
    return $definition instanceof ContentEntityTypeInterface ? (string) $definition->getKey('label') : '';
  }

  /**
   * Gets the changed timestamp key for a content entity type.
   */
  private function getContentEntityChangedKey(string $entity_type_id): string {
    if ($entity_type_id === '' || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return '';
    }

    $definition = $this->entityTypeManager->getDefinition($entity_type_id);
    return $definition instanceof ContentEntityTypeInterface ? (string) $definition->getKey('changed') : '';
  }

  /**
   * Normalizes content matches for display.
   *
   * @param array<int|string, \Drupal\Core\Entity\ContentEntityInterface> $entities
   *   Matching entities.
   *
   * @return array<int, array<string, string>>
   *   Display-ready matches.
   */
  private function normalizeContentMatches(array $entities): array {
    $matches = [];
    foreach ($entities as $entity) {
      $matches[] = [
        'label' => (string) $entity->label(),
        'type' => $this->getContentBundleLabel($entity),
        'edit_url' => $this->getEntityEditUrl($entity),
      ];
    }

    return $matches;
  }

  /**
   * Gets an entity edit URL when available.
   */
  private function getEntityEditUrl(EntityInterface $entity): string {
    try {
      return $entity->toUrl('edit-form')->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Gets a readable bundle label for a content entity.
   */
  private function getContentBundleLabel(ContentEntityInterface $entity): string {
    $bundle_entity_type = $entity->getEntityType()->getBundleEntityType();
    if (!$bundle_entity_type) {
      return $entity->bundle();
    }

    try {
      $bundle = $this->entityTypeManager->getStorage($bundle_entity_type)->load($entity->bundle());
    }
    catch (\Throwable) {
      return $entity->bundle();
    }

    return $bundle instanceof EntityInterface ? (string) $bundle->label() : $entity->bundle();
  }

  /**
   * Builds display data for the active Commerce cart.
   *
   * @return array{items: array<int, array<string, string>>, groups: array<int, array<string, mixed>>, total: string}
   *   Current cart data.
   */
  public function buildCurrentCartSummary(): array {
    if (!$this->moduleHandler->moduleExists('commerce_cart') || !is_object($this->cartProvider)) {
      return [
        'items' => [],
        'groups' => [],
        'total' => '',
      ];
    }

    $items = [];
    $groups = [];
    try {
      foreach ($this->cartProvider->getCarts($this->currentUser) as $cart) {
        $cart_items = [];
        foreach ($cart->getItems() as $order_item) {
          $quantity = (float) $order_item->getQuantity();
          $purchased_entity = $order_item->getPurchasedEntity();
          $cart_items[] = [
            'order_item_id' => is_object($order_item) && method_exists($order_item, 'id') ? (int) $order_item->id() : 0,
            'title' => $purchased_entity instanceof PurchasableEntityInterface
              ? $purchased_entity->getOrderItemTitle()
              : (string) $order_item->label(),
            'sku' => is_object($purchased_entity) && method_exists($purchased_entity, 'getSku') ? (string) $purchased_entity->getSku() : '',
            'image_url' => $this->getPurchasedEntityImageUrl($purchased_entity),
            'image_alt' => $purchased_entity instanceof PurchasableEntityInterface
              ? $purchased_entity->getOrderItemTitle()
              : (string) $order_item->label(),
            'seller_label' => $this->getPurchasedEntitySellerLabel($purchased_entity),
            'stock_label' => (string) $this->t('En Stock'),
            'quantity' => $this->formatQuantity($quantity),
            'quantity_value' => $this->formatQuantityValue($quantity),
            'unit_price' => $this->formatCommercePrice($order_item->getUnitPrice()),
            'total_price' => $this->formatCommercePrice($order_item->getTotalPrice()),
            'url' => $this->getPurchasedEntityUrl($purchased_entity),
          ];
        }

        if ($cart_items === []) {
          continue;
        }

        $items = array_merge($items, $cart_items);
        $groups[] = [
          'id' => is_object($cart) && method_exists($cart, 'id') ? (int) $cart->id() : NULL,
          'store_name' => $this->getCartStoreLabel($cart),
          'items' => $cart_items,
          'summary' => $this->buildCartTotals($cart),
          'total' => $this->formatCommercePrice($cart->getTotalPrice()),
        ];
      }
    }
    catch (\Throwable) {
      return [
        'items' => [],
        'groups' => [],
        'total' => '',
      ];
    }

    return [
      'items' => $items,
      'groups' => $groups,
      'total' => count($groups) === 1 ? (string) ($groups[0]['total'] ?? '') : '',
    ];
  }

  /**
   * Builds subtotal, adjustments, and total display data for a cart order.
   *
   * @return array{subtotal: string, adjustments: array<int, array{label: string, amount: string, type: string}>, total: string}
   *   Cart total summary data.
   */
  private function buildCartTotals(mixed $cart): array {
    if (!$cart instanceof OrderInterface || !is_object($this->orderTotalSummary)) {
      return [
        'subtotal' => '',
        'adjustments' => [],
        'total' => '',
      ];
    }

    $totals = $this->orderTotalSummary->buildTotals($cart);
    $adjustments = [];
    foreach (($totals['adjustments'] ?? []) as $adjustment) {
      if (!is_array($adjustment)) {
        continue;
      }

      $amount = $adjustment['total'] ?? $adjustment['amount'] ?? NULL;
      $adjustments[] = [
        'label' => (string) ($adjustment['label'] ?? $this->t('Ajuste')),
        'amount' => $this->formatCommercePrice($amount),
        'type' => (string) ($adjustment['type'] ?? ''),
      ];
    }

    return [
      'subtotal' => $this->formatCommercePrice($totals['subtotal'] ?? NULL),
      'adjustments' => $adjustments,
      'total' => $this->formatCommercePrice($totals['total'] ?? NULL),
    ];
  }

  private function loadEntity(string $entity_type_id, int $entity_id): ?EntityInterface {
    if ($entity_type_id === '' || $entity_id <= 0 || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return NULL;
    }

    try {
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
    }
    catch (\Throwable) {
      return NULL;
    }

    return $entity instanceof EntityInterface ? $entity : NULL;
  }

  /**
   * Formats a Commerce price-like object.
   */
  public function formatCommercePrice(mixed $price): string {
    if (!is_object($price) || !method_exists($price, 'getNumber')) {
      return '';
    }

    $currency = method_exists($price, 'getCurrencyCode') ? (string) $price->getCurrencyCode() : 'EUR';
    $symbol = $currency === 'EUR' ? '€' : $currency;

    return number_format((float) $price->getNumber(), 2, ',', '.') . ' ' . $symbol;
  }

  /**
   * Formats a quantity without noisy decimals.
   */
  public function formatQuantity(float $quantity): string {
    if (floor($quantity) === $quantity) {
      return (string) (int) $quantity;
    }

    return rtrim(rtrim(number_format($quantity, 2, ',', '.'), '0'), ',');
  }

  /**
   * Formats a quantity for HTML number inputs and JSON payloads.
   */
  private function formatQuantityValue(float $quantity): string {
    if (floor($quantity) === $quantity) {
      return (string) (int) $quantity;
    }

    return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
  }

  /**
   * Gets the product URL for a purchased entity when possible.
   */
  public function getPurchasedEntityUrl(mixed $purchased_entity): string {
    if (is_object($purchased_entity) && method_exists($purchased_entity, 'getProduct')) {
      $product = $purchased_entity->getProduct();
      return $product && method_exists($product, 'toUrl') ? $product->toUrl()->toString() : '';
    }

    return is_object($purchased_entity) && method_exists($purchased_entity, 'toUrl')
      ? $purchased_entity->toUrl()->toString()
      : '';
  }

  /**
   * Gets the product image for a purchased entity when possible.
   */
  private function getPurchasedEntityImageUrl(mixed $purchased_entity): string {
    if ($purchased_entity instanceof ProductVariationInterface) {
      $product = $purchased_entity->getProduct();
      if ($product instanceof ProductInterface) {
        return $this->getProductImageUrl($product, $purchased_entity);
      }
    }

    if ($purchased_entity instanceof ProductInterface) {
      return $this->getProductImageUrl($purchased_entity, NULL);
    }

    return '';
  }

  /**
   * Gets the seller label for a purchased entity when possible.
   */
  private function getPurchasedEntitySellerLabel(mixed $purchased_entity): string {
    if ($purchased_entity instanceof ProductVariationInterface) {
      $product = $purchased_entity->getProduct();
      if ($product instanceof ProductInterface) {
        return $this->getReferencedEntityLabel($product, 'field_store') ?: $product->getTitle();
      }
    }

    if ($purchased_entity instanceof ProductInterface) {
      return $this->getReferencedEntityLabel($purchased_entity, 'field_store') ?: $purchased_entity->getTitle();
    }

    return '';
  }

  /**
   * Normalizes assistant output before parsing.
   */
  public function normalizeResponseText(string $text): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/\s+(\d+[\.\)]\s+(?:\*\*)?)/u', "\n$1", $text) ?? $text;
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';

    return trim($text);
  }

  /**
   * Cleans lightweight markdown from inline text.
   */
  public function cleanInlineText(string $text): string {
    $text = $this->normalizeResponseText($text);
    $text = preg_replace('/\*\*(.*?)\*\*/s', '$1', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;

    return trim($text, " \n\t.:;-");
  }

  /**
   * Resolves Commerce product data used by catalog-like cards from IDs.
   *
   * @return array<string, mixed>
   *   Product card data. Values are empty when no valid product is matched.
   */
  private function resolveProductCardDataFromIds(int $product_id, ?int $variation_id = NULL, string $fallback_title = ''): array {
    $empty = [
      'product_id' => NULL,
      'product_url' => '#',
      'image_url' => '',
      'image_alt' => $fallback_title,
      'store_name' => '',
      'store_url' => '#',
      'rating' => 0.0,
      'price' => '',
      'price_amount' => 0.0,
      'favorite_toggle_url' => '',
      'favorite_login_url' => '/user/login',
      'is_favorite' => FALSE,
      'variation_id' => NULL,
      'add_to_cart_url' => '',
      'add_to_cart_form' => [],
      'is_catalog_product' => FALSE,
    ];

    if ($product_id < 1 || !$this->entityTypeManager->hasDefinition('commerce_product')) {
      return $empty;
    }

    $cache_key = $product_id . ':' . ($variation_id ?? 0);
    if (isset($this->productCardCache[$cache_key])) {
      return $this->productCardCache[$cache_key];
    }

    $product = $this->entityTypeManager->getStorage('commerce_product')->load($product_id);
    if (!$product instanceof ProductInterface || !$product->isPublished() || $this->isExcludedProduct($product)) {
      $this->productCardCache[$cache_key] = $empty;
      return $empty;
    }

    $variation = $this->resolveProductVariation($product, $variation_id);

    $card = [
      'product_id' => (int) $product->id(),
      'product_url' => $product->toUrl()->toString(),
      'image_url' => $this->getProductImageUrl($product, $variation),
      'image_alt' => $product->getTitle(),
      'store_name' => $this->getReferencedEntityLabel($product, 'field_store'),
      'store_url' => $this->getReferencedEntityUrl($product, 'field_store'),
      'rating' => $product->hasField('field_avg_rating') && !$product->get('field_avg_rating')->isEmpty()
        ? (float) $product->get('field_avg_rating')->value
        : 0.0,
      'price' => $variation ? $this->getProductPrice($variation) : '',
      'price_amount' => $variation && $variation->getPrice()
        ? (float) $variation->getPrice()->getNumber()
        : 0.0,
      'favorite_toggle_url' => $this->getFavoriteToggleUrl((int) $product->id()),
      'favorite_login_url' => '/user/login',
      'is_favorite' => isset($this->getFavoriteProductIds()[(int) $product->id()]),
      'variation_id' => $variation ? (int) $variation->id() : NULL,
      'add_to_cart_url' => $variation ? $this->getAddToCartUrl() : '',
      'add_to_cart_form' => [],
      'is_catalog_product' => TRUE,
    ];

    $this->productCardCache[$cache_key] = $card;
    return $card;
  }

  /**
   * Gets the display label for the store attached to a cart.
   */
  private function getCartStoreLabel(mixed $cart): string {
    if (is_object($cart) && method_exists($cart, 'getStore')) {
      $store = $cart->getStore();
      if (is_object($store)) {
        return method_exists($store, 'label') ? (string) $store->label() : (string) $this->t('Vendedor');
      }
    }

    return (string) $this->t('Vendedor');
  }

  /**
   * Gets the AJAX add-to-cart endpoint used by skill cards.
   */
  private function getAddToCartUrl(): string {
    if (!$this->moduleHandler->moduleExists('commerce_cart')) {
      return '';
    }

    return Url::fromRoute('trotamundos_assistant.cart_add')->toString();
  }

  /**
   * Resolves a product variation and validates ownership.
   */
  private function resolveProductVariation(ProductInterface $product, ?int $variation_id): ?ProductVariationInterface {
    if ($variation_id) {
      $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($variation_id);
      if (
        $variation instanceof ProductVariationInterface
        && (int) $variation->getProductId() === (int) $product->id()
        && $variation->isPublished()
      ) {
        return $variation;
      }
    }

    $variation = $product->getDefaultVariation();
    if ($variation instanceof ProductVariationInterface && $variation->isPublished()) {
      return $variation;
    }

    foreach ($product->getVariations() as $candidate) {
      if ($candidate instanceof ProductVariationInterface && $candidate->isPublished()) {
        return $candidate;
      }
    }

    return NULL;
  }

  /**
   * Checks whether a product should be hidden from assistant product actions.
   */
  private function isExcludedProduct(ProductInterface $product): bool {
    return $product->bundle() === self::EXCLUDED_PRODUCT_BUNDLE;
  }

  /**
   * Builds the favorites toggle URL for the current product.
   */
  private function getFavoriteToggleUrl(int $product_id): string {
    if ($product_id < 1 || !$this->moduleHandler->moduleExists('minsait_product_favorites') || !$this->currentUser->isAuthenticated()) {
      return '';
    }

    $seed = 'minsait_marketplace_favorite_toggle:' . $product_id;
    return '/ajax/favorites/toggle/' . $product_id . '?token=' . $this->csrfToken->get($seed);
  }

  /**
   * Gets current user favorite product IDs.
   *
   * @return array<int, int>
   *   Favorite product IDs keyed by product ID.
   */
  private function getFavoriteProductIds(): array {
    if ($this->favoriteProductIds !== NULL) {
      return $this->favoriteProductIds;
    }

    $this->favoriteProductIds = [];
    if (!$this->currentUser->isAuthenticated()) {
      return $this->favoriteProductIds;
    }

    $account = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    if (!$account || !$account->hasField('field_favorite_products')) {
      return $this->favoriteProductIds;
    }

    foreach ($account->get('field_favorite_products')->getValue() as $item) {
      $product_id = (int) ($item['target_id'] ?? 0);
      if ($product_id > 0) {
        $this->favoriteProductIds[$product_id] = $product_id;
      }
    }

    return $this->favoriteProductIds;
  }

  /**
   * Gets the image URL from a product variation or its parent product.
   */
  private function getProductImageUrl(ProductInterface $product, ?ProductVariationInterface $variation): string {
    if ($variation) {
      $image_url = $this->getEntityImageUrl($variation);
      if ($image_url !== '') {
        return $image_url;
      }
    }

    return $this->getEntityImageUrl($product);
  }

  /**
   * Gets the image URL from an entity with a field_image media reference.
   */
  private function getEntityImageUrl(object $entity): string {
    if (!$entity->hasField('field_image') || $entity->get('field_image')->isEmpty()) {
      return '';
    }

    $media = $entity->get('field_image')->entity;
    if (!$media) {
      return '';
    }

    $source_field = 'field_media_image';
    if ($media->hasField($source_field) && !$media->get($source_field)->isEmpty()) {
      $file = $media->get($source_field)->entity;
      if ($file && file_exists($file->getFileUri())) {
        return $this->fileUrlGenerator->generateString($file->getFileUri());
      }
    }

    return '';
  }

  /**
   * Gets the display price from a product variation.
   */
  private function getProductPrice(ProductVariationInterface $variation): string {
    if (!$variation->getPrice()) {
      return '';
    }

    return number_format((float) $variation->getPrice()->getNumber(), 2, ',', '.') . ' €';
  }

  /**
   * Gets the label of a referenced entity field.
   */
  private function getReferencedEntityLabel(object $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    return (string) ($entity->get($field_name)->entity?->label() ?? '');
  }

  /**
   * Gets the URL of a referenced entity field.
   */
  private function getReferencedEntityUrl(object $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '#';
    }

    $referenced = $entity->get($field_name)->entity;
    return $referenced && method_exists($referenced, 'toUrl') ? $referenced->toUrl()->toString() : '#';
  }

}
