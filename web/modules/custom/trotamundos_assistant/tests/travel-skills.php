<?php

declare(strict_types=1);

/**
 * @file
 * Run with drush php:script against a site with published travel content.
 */

use Drupal\search_api\SearchApiException;
use Drupal\trotamundos_assistant\EventSubscriber\SkillPromptSubscriber;
use Drupal\trotamundos_assistant\Plugin\AiFunctionCall\SkillAwareRagTool;
use Drupal\trotamundos_assistant\Plugin\search_api\processor\TravelDocument;
use Drupal\trotamundos_assistant\Setup\TravelAssistantSetup;
use Drupal\trotamundos_experiences\Setup\ProductTypes;
use Symfony\Component\HttpFoundation\Request;

if (PHP_SAPI !== 'cli' || !class_exists('Drupal')) {
  throw new RuntimeException('Run this test with drush php:script.');
}

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$manager = \Drupal::entityTypeManager();
$storage = $manager->getStorage('node');
$ids = $storage->getQuery()
  ->condition('type', ProductTypes::BUNDLES, 'IN')
  ->condition('status', 1)
  ->accessCheck(FALSE)
  ->range(0, 100)
  ->execute();
$node = NULL;
foreach ($storage->loadMultiple($ids) as $candidate) {
  $candidate = \Drupal::service('entity.repository')->getTranslationFromContext($candidate);
  if (TravelDocument::isPublic($candidate)) {
    $node = $candidate;
    break;
  }
}
$assert($node !== NULL, 'A published, publicly accessible plan is required.');
$id = (int) $node->id();
$langcode = $node->language()->getId();
$evidence = [$id => [$langcode => TRUE]];

$index = $manager->getStorage('search_api_index')->load(TravelAssistantSetup::INDEX_ID);
$assert($index !== NULL && $index->status(), 'The travel index is not enabled.');
$item = \Drupal::service('search_api.fields_helper')->createItemFromObject(
  $index,
  $node->getTypedData(),
  NULL,
  $index->getDatasource('entity:node'),
);
$processor = $index->getProcessor('trotamundos_assistant_travel_document');
$fields = $item->getFields();
$context = implode(' ', $fields['travel_context']->getValues());
$assert(str_contains($context, 'ID de contenido: ' . $id), 'The indexed context lacks the node ID.');
$assert(str_contains($context, (string) $node->label()), 'The indexed context lacks the public title.');
$draft = clone $node;
$draft->setUnpublished();
$assert(!TravelDocument::isPublic($draft), 'An unpublished node was accepted as public.');
$draft_item = \Drupal::service('search_api.fields_helper')->createItemFromObject(
  $index,
  $draft->getTypedData(),
  NULL,
  $index->getDatasource('entity:node'),
);
$items = [$draft_item->getId() => $draft_item];
$processor->alterIndexedItems($items);
$assert($items === [], 'An unpublished node was not removed from indexing.');

$runtime = \Drupal::service('trotamundos_assistant.skill_runtime');
$renderer = \Drupal::service('trotamundos_assistant.skill_template_renderer');
$matcher = \Drupal::service('trotamundos_assistant.skill_matcher');
$plans_skill = $matcher->getByReference('travel_plans', TRUE);
$guide_skill = $matcher->getByReference('travel_guide', TRUE);
$assert($plans_skill !== NULL && $guide_skill !== NULL, 'Both travel skills must be enabled.');
$assert($renderer->skillRequiresRag($plans_skill) && $renderer->skillRequiresRag($guide_skill), 'Travel skills must require retrieval.');

$plans_reply = json_encode([
  'skill' => 'travel_plans',
  'chat_reply' => 'Unverified claim',
  'result' => [
    'intro' => 'Unverified introduction',
    'items' => [
      ['node_id' => $id, 'title' => 'Fake title', 'reason' => 'A published plan.'],
      ['node_id' => $id],
      ['node_id' => 999999999],
      ['node_id' => -1],
    ],
  ],
], JSON_THROW_ON_ERROR);
$restricted = $renderer->restrictTravelReferences($plans_skill, $plans_reply, $evidence);
$restricted_data = json_decode($restricted, TRUE, 512, JSON_THROW_ON_ERROR);
$assert(count($restricted_data['result']['items']) === 1, 'Invalid or duplicate plan IDs were retained.');
$assert($restricted_data['chat_reply'] === '' && $restricted_data['result']['intro'] === '', 'Unverified summary text was retained.');
$cards = $runtime->resolveTravelPlans($restricted_data['result']['items']);
$assert(count($cards) === 1 && (int) $cards[0]['card']['id'] === $id, 'The verified plan was not resolved.');
$assert($cards[0]['card']['title'] !== 'Fake title', 'A model-supplied title was used instead of the published title.');
$html = $renderer->render($plans_skill, $restricted);
$assert(str_contains($html, (string) $node->label()), 'The verified plan card was not rendered.');
$assert(!str_contains($html, 'Fake title'), 'An unverified title appeared in the rendered card.');

$no_evidence = json_decode($renderer->restrictTravelReferences($plans_skill, $plans_reply, []), TRUE, 512, JSON_THROW_ON_ERROR);
$assert($no_evidence['result']['items'] === [], 'A plan without search evidence was accepted.');
$wrong_language = json_decode($renderer->restrictTravelReferences($plans_skill, $plans_reply, [$id => ['not-' . $langcode => TRUE]]), TRUE, 512, JSON_THROW_ON_ERROR);
$assert($wrong_language['result']['items'] === [], 'A plan retrieved only in another language was accepted.');

$guide_reply = json_encode([
  'skill' => 'travel_guide',
  'chat_reply' => 'A source is available.',
  'result' => ['answer' => '<script>unsafe</script>', 'sources' => [['node_id' => $id]]],
], JSON_THROW_ON_ERROR);
$verified_guide = $renderer->restrictTravelReferences($guide_skill, $guide_reply, $evidence);
$guide_html = $renderer->render($guide_skill, $verified_guide);
$assert(str_contains($guide_html, (string) $node->label()), 'The verified source was not rendered.');
$assert(!str_contains($guide_html, '<script>unsafe</script>'), 'The guide answer was not escaped.');
$guide_data = json_decode($guide_reply, TRUE, 512, JSON_THROW_ON_ERROR);
$guide_data['result']['sources'][] = ['node_id' => 999999999];
$mixed_guide = $renderer->restrictTravelReferences($guide_skill, json_encode($guide_data, JSON_THROW_ON_ERROR), $evidence);
$assert(json_decode($mixed_guide, TRUE, 512, JSON_THROW_ON_ERROR)['result']['sources'] === [], 'A guide with an unverified source was accepted.');
$assert(!str_contains($renderer->render($guide_skill, $mixed_guide), 'unsafe'), 'An ungrounded guide answer was displayed.');

$request = Request::create('/');
$request->attributes->set(SkillPromptSubscriber::REQUEST_ATTRIBUTE, ['agent_id' => TravelAssistantSetup::AGENT_ID]);
$stack = \Drupal::service('request_stack');
$stack->push($request);
try {
  $tool = new class($id, $langcode) {
    public function __construct(private int $id, private string $langcode) {}
    public function getFunctionName(): string { return SkillAwareRagTool::FUNCTION_NAME; }
    public function getContextValue(string $name): string { return 'travel_plans'; }
    public function getStructuredOutput(): array {
      return [
        'index' => TravelAssistantSetup::INDEX_ID,
        'results' => [
          ['entity_type' => 'node', 'entity_id' => (string) $this->id, 'langcode' => $this->langcode],
          ['entity_type' => 'user', 'entity_id' => '1', 'langcode' => $this->langcode],
        ],
      ];
    }
  };
  $event = new class($tool) {
    public function __construct(private object $tool) {}
    public function getAgentId(): string { return TravelAssistantSetup::AGENT_ID; }
    public function getTool(): object { return $this->tool; }
  };
  \Drupal::service('trotamundos_assistant.skill_prompt_subscriber')->onAgentToolFinished($event);
  $context = $request->attributes->get(SkillPromptSubscriber::REQUEST_ATTRIBUTE);
  $assert(!empty($context['retrieved_nodes'][$id][$langcode]), 'Retrieved node evidence was not recorded.');
  $assert(count($context['retrieved_nodes']) === 1, 'A non-node search result was accepted.');
}
finally {
  $stack->pop();
}

$plugin = \Drupal::service('plugin.manager.ai.function_calls')->createInstance(SkillAwareRagTool::PLUGIN_ID);
$plugin->setContextValue('skill', 'travel_plans');
$plugin->setContextValue('index', 'nonexistent_travel_index');
$plugin->setContextValue('search_string', 'viaje');
try {
  $plugin->execute();
  throw new RuntimeException('A missing index was reported as a successful search.');
}
catch (SearchApiException $exception) {
  $assert(str_contains($exception->getMessage(), 'index'), 'The index failure was not explicit.');
}
$plugin->setContextValue('index', TravelAssistantSetup::INDEX_ID);
$plugin->setContextValue('search_string', '');
try {
  $plugin->execute();
  throw new RuntimeException('An empty search query was accepted.');
}
catch (SearchApiException $exception) {
  $assert(str_contains($exception->getMessage(), 'query'), 'The empty-query failure was not explicit.');
}

echo "Travel skill, publication, source and language checks passed.\n";
