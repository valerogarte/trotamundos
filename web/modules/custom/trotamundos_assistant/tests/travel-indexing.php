<?php

declare(strict_types=1);

/**
 * @file
 * Run with drush php:script. The OpenAI fixture listens only on loopback.
 */

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\search_api\SearchApiException;
use Drupal\trotamundos_assistant\Controller\TrotamundosAssistantController;
use Drupal\trotamundos_assistant\EventSubscriber\SkillPromptSubscriber;
use Drupal\trotamundos_assistant\Plugin\AiFunctionCall\SkillAwareRagTool;
use Drupal\trotamundos_assistant\Plugin\search_api\processor\TravelDocument;
use Drupal\trotamundos_assistant\Setup\TravelAssistantSetup;
use Drupal\trotamundos_experiences\Setup\ProductTypes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

if (PHP_SAPI === 'cli-server') {
  header('Content-Type: application/json');
  $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  if ($path === '/health') {
    echo '{"status":"ready"}';
    return;
  }
  if ($path === '/fail/v1/embeddings') {
    http_response_code(503);
    echo '{"error":{"message":"Local embedding fixture failure","type":"server_error"}}';
    return;
  }
  if ($path === '/v1/chat/completions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $request = json_decode((string) file_get_contents('php://input'), TRUE);
    if (($request['model'] ?? '') === 'gpt-6-luna'
      && !empty($request['tools'])
      && ($request['reasoning_effort'] ?? '') !== 'none') {
      http_response_code(400);
      echo '{"error":{"message":"Luna tool calls require reasoning_effort none","type":"invalid_request_error"}}';
      return;
    }
    $tool_content = '';
    $last_user = '';
    $system_content = '';
    foreach ($request['messages'] ?? [] as $message) {
      $content = $message['content'] ?? '';
      $text = is_array($content) ? implode(' ', array_column($content, 'text')) : (string) $content;
      if (($message['role'] ?? '') === 'tool') {
        $tool_content = $text;
      }
      if (($message['role'] ?? '') === 'user') {
        $last_user = $text;
      }
      if (($message['role'] ?? '') === 'system') {
        $system_content .= $text;
      }
    }
    $skill = str_contains($last_user, 'guide-fixture') ? 'travel_guide' : 'travel_plans';
    if ($tool_content === '') {
      $name = '';
      foreach ($request['tools'] ?? [] as $tool) {
        if (str_contains($tool['function']['name'] ?? '', 'skill_rag_search')) {
          $name = $tool['function']['name'];
          break;
        }
      }
      if ($name === '') {
        http_response_code(400);
        echo '{"error":{"message":"The search tool was not supplied","type":"invalid_request_error"}}';
        return;
      }
      $message = [
        'role' => 'assistant',
        'content' => NULL,
        'tool_calls' => [[
          'id' => 'call_local_fixture',
          'type' => 'function',
          'function' => [
            'name' => $name,
            'arguments' => json_encode([
              'skill' => $skill,
              'search_string' => str_contains($last_user, 'followup-fixture') ? 'consulta sin resultados' : 'viaje de prueba',
              'amount' => 10,
              'min_score' => 0.5,
            ]),
          ],
        ]],
      ];
      $finish = 'tool_calls';
    }
    else {
      preg_match('/ID de contenido:\s*(\d+)/', $tool_content, $match);
      $node_id = (int) ($match[1] ?? 0);
      if (!$node_id && str_contains($last_user, 'followup-fixture')
        && preg_match('/"node_id":\s*(\d+)/', $system_content, $previous)) {
        $node_id = (int) $previous[1];
      }
      $items = $node_id ? [['node_id' => $node_id]] : [];
      if (preg_match('/unretrieved:(\d+)/', $last_user, $unretrieved)) {
        $items[] = ['node_id' => (int) $unretrieved[1]];
      }
      $result = $skill === 'travel_guide'
        ? ['answer' => 'Información de prueba.', 'sources' => $items]
        : ['items' => $items];
      $message = [
        'role' => 'assistant',
        'content' => json_encode(['skill' => $skill, 'chat_reply' => 'Resultado de prueba.', 'result' => $result], JSON_UNESCAPED_UNICODE),
      ];
      $finish = 'stop';
    }
    echo json_encode([
      'id' => 'chatcmpl-local-fixture',
      'object' => 'chat.completion',
      'created' => time(),
      'model' => 'gpt-6-luna',
      'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]],
      'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ], JSON_THROW_ON_ERROR);
    return;
  }
  if ($path !== '/v1/embeddings' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    echo '{"error":{"message":"Unknown fixture endpoint","type":"invalid_request_error"}}';
    return;
  }
  $request = json_decode((string) file_get_contents('php://input'), TRUE);
  $inputs = is_array($request['input'] ?? NULL) ? $request['input'] : [$request['input'] ?? ''];
  $data = [];
  foreach ($inputs as $index => $input) {
    $vector = array_fill(0, 1536, 0.0);
    $vector[is_string($input) && str_contains($input, 'consulta sin resultados') ? 1 : 0] = 1.0;
    $data[] = ['object' => 'embedding', 'index' => $index, 'embedding' => $vector];
  }
  echo json_encode([
    'object' => 'list',
    'data' => $data,
    'model' => 'text-embedding-3-small',
    'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
  ], JSON_THROW_ON_ERROR);
  return;
}

if (PHP_SAPI !== 'cli' || !class_exists('Drupal')) {
  throw new RuntimeException('Run this test with drush php:script.');
}

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === FALSE) {
  throw new RuntimeException('Cannot reserve a loopback port for the OpenAI fixture: ' . $error);
}
$address = stream_socket_get_name($socket, FALSE);
fclose($socket);
$process = proc_open([PHP_BINARY, '-S', $address, __FILE__], [
  0 => ['file', '/dev/null', 'r'],
  1 => ['file', '/dev/null', 'w'],
  2 => ['file', '/dev/null', 'w'],
], $pipes);
if (!is_resource($process)) {
  throw new RuntimeException('Cannot start the local embedding fixture.');
}

$collection = 'trotamundos_test_' . bin2hex(random_bytes(8));
$created = FALSE;
$provider = NULL;
try {
  $ready = FALSE;
  for ($attempt = 0; $attempt < 50; $attempt++) {
    if (!proc_get_status($process)['running']) {
      break;
    }
    if (@file_get_contents('http://' . $address . '/health') === '{"status":"ready"}') {
      $ready = TRUE;
      break;
    }
    usleep(100000);
  }
  $assert($ready, 'The local embedding fixture did not start.');

  $override = new class($address, $collection) implements ConfigFactoryOverrideInterface {
    public string $host;
    public function __construct(string $address, public string $collection) {
      $this->host = 'http://' . $address . '/v1';
    }
    public function loadOverrides($names) {
      $overrides = [];
      if (in_array('ai_provider_openai.settings', $names, TRUE)) {
        $overrides['ai_provider_openai.settings'] = ['host' => $this->host, 'moderation' => FALSE];
      }
      if (in_array('search_api.server.' . TravelAssistantSetup::SERVER_ID, $names, TRUE)) {
        $overrides['search_api.server.' . TravelAssistantSetup::SERVER_ID] = [
          'backend_config' => ['database_settings' => ['collection' => $this->collection]],
        ];
      }
      if (in_array('trotamundos_assistant.settings', $names, TRUE)) {
        $overrides['trotamundos_assistant.settings'] = ['rate_limit_requests' => 0];
      }
      return $overrides;
    }
    public function getCacheSuffix() { return 'travel_indexing_fixture'; }
    public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) { return NULL; }
    public function getCacheableMetadata($name) { return new CacheableMetadata(); }
  };
  putenv('OPENAI_API_KEY=local-fixture-only');
  $factory = \Drupal::configFactory();
  $factory->addOverride($override);
  $factory->reset('ai_provider_openai.settings');
  $factory->reset('search_api.server.' . TravelAssistantSetup::SERVER_ID);
  $factory->reset('trotamundos_assistant.settings');
  $assert($factory->get('ai_provider_openai.settings')->get('host') === $override->host, 'The fixture provider is not isolated on loopback.');
  $manager = \Drupal::entityTypeManager();
  $manager->getStorage('search_api_server')->resetCache([TravelAssistantSetup::SERVER_ID]);
  $manager->getStorage('search_api_index')->resetCache([TravelAssistantSetup::INDEX_ID]);

  $index = $manager->getStorage('search_api_index')->load(TravelAssistantSetup::INDEX_ID);
  $server = $index->getServerInstance();
  $config = $server->getBackendConfig();
  $assert($config['database_settings']['collection'] === $collection, 'The fixture was not isolated from the real collection.');
  $before = $index->getTrackerInstance()->getIndexedItemsCount();
  $storage = $manager->getStorage('node');
  $ids = $storage->getQuery()->condition('type', ProductTypes::BUNDLES, 'IN')
    ->condition('status', 1)->accessCheck(FALSE)->range(0, 100)->execute();
  $node = NULL;
  foreach ($storage->loadMultiple($ids) as $candidate) {
    $candidate = \Drupal::service('entity.repository')->getTranslationFromContext($candidate);
    if (TravelDocument::isPublic($candidate)) {
      $node = $candidate;
      break;
    }
  }
  $assert($node !== NULL, 'A published, publicly accessible plan is required.');
  $other_node = NULL;
  foreach ($storage->loadMultiple($ids) as $candidate) {
    $candidate = \Drupal::service('entity.repository')->getTranslationFromContext($candidate);
    if ($candidate->id() !== $node->id() && TravelDocument::isPublic($candidate)) {
      $other_node = $candidate;
      break;
    }
  }
  $assert($other_node !== NULL, 'A second public plan is required to test ungrounded IDs.');
  $item = \Drupal::service('search_api.fields_helper')->createItemFromObject(
    $index, $node->getTypedData(), NULL, $index->getDatasource('entity:node')
  );
  $fields = $item->getFields();
  $strategy = \Drupal::service('ai_search.embedding_strategy')->createInstance('trotamundos_travel');
  $provider = \Drupal::service('ai.vdb_provider')->createInstance('qdrant');
  $assert(!in_array($collection, $provider->getCollections(), TRUE), 'The temporary Qdrant collection already exists.');
  $provider->createCollection($collection, 1536);
  $created = TRUE;
  $assert(in_array($collection, $provider->getCollections(), TRUE), 'The temporary Qdrant collection could not be created.');

  $indexed = $provider->indexItems($config, $index, [$item], $strategy);
  $assert($indexed === [$item->getId()], 'The published travel item was not indexed.');
  $tool = \Drupal::service('plugin.manager.ai.function_calls')->createInstance(SkillAwareRagTool::PLUGIN_ID);
  $tool->setContextValue('skill', 'travel_plans');
  $tool->setContextValue('index', TravelAssistantSetup::INDEX_ID);
  $tool->setContextValue('search_string', 'viaje de prueba');
  $tool->setContextValue('amount', 10);
  $tool->setContextValue('min_score', 0.5);
  $results = [];
  for ($attempt = 0; $attempt < 30; $attempt++) {
    $tool->execute();
    $results = $tool->getStructuredOutput()['results'];
    if ($results !== []) {
      break;
    }
    usleep(100000);
  }
  $assert($results !== [], 'The indexed travel item could not be retrieved.');
  $assert(($results[0]['entity_type'] ?? '') === 'node' && (int) ($results[0]['entity_id'] ?? 0) === (int) $node->id(), 'The retrieved node ID is incorrect.');
  $assert(($results[0]['langcode'] ?? '') === $node->language()->getId(), 'The retrieved node language is incorrect.');
  $assert(str_contains($results[0]['content'], 'ID de contenido: ' . $node->id()), 'The retrieved chunk lacks its source ID.');
  $assert($index->getTrackerInstance()->getIndexedItemsCount() === $before, 'The test modified the real indexing tracker.');

  $transaction = \Drupal::database()->startTransaction();
  $stack = \Drupal::service('request_stack');
  $session = new Session(new MockArraySessionStorage());
  $session->start();
  $controller = TrotamundosAssistantController::create(\Drupal::getContainer());
  $token_request = Request::create('/trotamundos-assistant/session-token', 'GET');
  $token_request->setSession($session);
  $account_switcher = \Drupal::service('account_switcher');
  $account_switcher->switchTo(new AnonymousUserSession());
  $stack->push($token_request);
  try {
    $token_response = $controller->sessionToken($token_request);
  }
  finally {
    $stack->pop();
    $account_switcher->switchBack();
  }
  $assert($token_response->getStatusCode() === 200, 'The session token could not be created.');
  $assert($session->get('trotamundos_assistant_anonymous') === TRUE, 'The anonymous session was not retained for the CSRF token.');
  $assert($token_response->headers->hasCacheControlDirective('no-store'), 'The session token response can be cached.');
  $assert(\Drupal::service('csrf_token')->validate((string) $token_response->getContent(), CsrfRequestHeaderAccessCheck::TOKEN_KEY), 'The session token is invalid.');
  $send = static function (string $message, string $thread_id) use ($controller, $session, $stack): array {
    $request = Request::create('/trotamundos-assistant/chat', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], json_encode([
      'message' => $message,
      'thread_id' => $thread_id,
    ], JSON_THROW_ON_ERROR));
    $request->setSession($session);
    $stack->push($request);
    try {
      $request->headers->set('X-CSRF-Token', \Drupal::service('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
      return [$controller->chat($request), $request];
    }
    finally {
      $stack->pop();
    }
  };
  try {
    $thread_id = 'trota_0_' . bin2hex(random_bytes(16));
    $openai_key = \Drupal::service('key.repository')->getKey($factory->get('ai_provider_openai.settings')->get('api_key'));
    $assert($openai_key !== NULL, 'The fixture requires a configured OpenAI Key reference.');
    putenv('OPENAI_API_KEY');
    $openai_key->getKeyValue(TRUE);
    [$missing_key] = $send('Prueba local sin credencial', $thread_id);
    $assert($missing_key->getStatusCode() === 503, 'A request without an OpenAI key was not stopped: HTTP ' . $missing_key->getStatusCode());
    putenv('OPENAI_API_KEY=local-fixture-only');
    $openai_key->getKeyValue(TRUE);

    [$chat, $chat_request] = $send('Prueba local de un plan', $thread_id);
    $chat_data = json_decode((string) $chat->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $assert($chat->getStatusCode() === 200, 'The simulated travel chat failed: ' . ($chat_data['error'] ?? 'unknown error'));
    $assert(($chat_data['machine_name'] ?? '') === 'travel_plans', 'The chat did not select the travel plans skill.');
    $assert(str_contains($chat_data['result_html'] ?? '', (string) $node->label()), 'The chat did not render the retrieved plan.');
    $chat_context = $chat_request->attributes->get(SkillPromptSubscriber::REQUEST_ATTRIBUTE);
    $assert(!empty($chat_context['retrieved_nodes'][(int) $node->id()][$node->language()->getId()]), 'The chat did not retain retrieval evidence.');

    $history = Request::create('/trotamundos-assistant/chat/history', 'GET', ['thread_id' => $thread_id]);
    $history->setSession($session);
    $stack->push($history);
    try {
      $history_response = $controller->history($history);
    }
    finally {
      $stack->pop();
    }
    $history_data = json_decode((string) $history_response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $assert($history_response->getStatusCode() === 200 && str_contains($history_data['result_html'] ?? '', (string) $node->label()), 'The verified plan was not restored from the conversation.');
    $last_message = end($history_data['messages']);
    $assert(($last_message['message'] ?? '') === $chat_data['reply'], 'The history did not retain the concise assistant reply.');

    [$followup] = $send('followup-fixture: conserva el plan anterior', $thread_id);
    $followup_data = json_decode((string) $followup->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $assert($followup->getStatusCode() === 200 && str_contains($followup_data['result_html'] ?? '', (string) $node->label()), 'Previously retrieved evidence was not retained for a follow-up.');

    $unretrieved_thread = 'trota_0_' . bin2hex(random_bytes(16));
    [$unretrieved] = $send('Prueba local unretrieved:' . $other_node->id(), $unretrieved_thread);
    $unretrieved_data = json_decode((string) $unretrieved->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $assert($unretrieved->getStatusCode() === 200 && str_contains($unretrieved_data['result_html'] ?? '', (string) $node->label()), 'A verified plan was lost when filtering an ungrounded ID.');
    $assert(!str_contains($unretrieved_data['result_html'] ?? '', $other_node->toUrl()->toString()), 'A public plan not returned by search was displayed.');

    $guide_thread = 'trota_0_' . bin2hex(random_bytes(16));
    [$guide] = $send('guide-fixture: consulta una guía', $guide_thread);
    $guide_data = json_decode((string) $guide->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $assert($guide->getStatusCode() === 200 && ($guide_data['machine_name'] ?? '') === 'travel_guide', 'The guide skill did not complete the simulated chat.');
    $assert(str_contains($guide_data['result_html'] ?? '', (string) $node->label()), 'The guide did not cite the retrieved source.');
    $unverified_guide_thread = 'trota_0_' . bin2hex(random_bytes(16));
    [$unverified_guide] = $send('guide-fixture unretrieved:' . $other_node->id(), $unverified_guide_thread);
    $unverified_guide_data = json_decode((string) $unverified_guide->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $assert($unverified_guide->getStatusCode() === 200 && !str_contains($unverified_guide_data['result_html'] ?? '', 'Información de prueba.'), 'A guide with an ungrounded source was displayed.');

    $reset = Request::create('/trotamundos-assistant/chat/reset', 'POST', [], [], [], [], json_encode(['thread_id' => $thread_id], JSON_THROW_ON_ERROR));
    $reset->setSession($session);
    $stack->push($reset);
    try {
      $reset->headers->set('X-CSRF-Token', \Drupal::service('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
      $reset_response = $controller->reset($reset);
    }
    finally {
      $stack->pop();
    }
    $assert($reset_response->getStatusCode() === 200, 'The conversation could not be reset.');
    $stack->push($history);
    try {
      $cleared_history = $controller->history($history);
    }
    finally {
      $stack->pop();
    }
    $cleared_data = json_decode((string) $cleared_history->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $assert(($cleared_data['messages'] ?? NULL) === [] && ($cleared_data['result_html'] ?? NULL) === '', 'Reset left the old conversation accessible.');
  }
  finally {
    $transaction->rollBack();
  }

  $override->host = 'http://' . $address . '/fail/v1';
  $factory->reset('ai_provider_openai.settings');
  try {
    $strategy->getEmbedding($config['embeddings_engine'], $config['chat_model'], $config['embedding_strategy_configuration'], $fields, $item, $index);
    throw new RuntimeException('A failed embedding request was reported as successful.');
  }
  catch (SearchApiException $exception) {
    $assert(str_contains($exception->getMessage(), 'embedding'), 'The embedding failure was not explicit.');
  }
  $search_failed = FALSE;
  try {
    $tool->execute();
  }
  catch (Throwable $exception) {
    $search_failed = TRUE;
  }
  $assert($search_failed, 'A failed search was reported as an empty result.');

  echo "Temporary Qdrant indexing, simulated travel chat and provider failure checks passed.\n";
}
finally {
  try {
    if ($created && $provider !== NULL) {
      $provider->dropCollection($collection);
      if (in_array($collection, $provider->getCollections(), TRUE)) {
        throw new RuntimeException('The temporary Qdrant collection could not be removed: ' . $collection);
      }
    }
  }
  finally {
    proc_terminate($process);
    proc_close($process);
  }
}
