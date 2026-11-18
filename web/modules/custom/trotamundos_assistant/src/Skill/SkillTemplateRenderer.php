<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Skill;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Render\AttachmentsResponseProcessorInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\trotamundos_assistant\Plugin\search_api\processor\TravelDocument;
use Drupal\trotamundos_experiences\Setup\ProductTypes;

/**
 * Orchestrates skill rendering without owning skill business logic.
 */
final class SkillTemplateRenderer {

  use StringTranslationTrait;

  /**
   * Constructs the skill template renderer.
   */
  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly RendererInterface $renderer,
    private readonly SkillHandlerResolver $skillHandlerResolver,
    private readonly AttachmentsResponseProcessorInterface $ajaxAttachmentsProcessor,
    private readonly SkillRuntime $skillRuntime,
  ) {
  }

  /**
   * Maximum number of active selection items exposed to the provider.
   */
  private const ACTIVE_SELECTION_LIMIT = 40;

  /**
   * Returns the unmodified user message for the provider conversation.
   *
   * Skill instructions deliberately do not belong to a user-role message:
   * they are attached to the agent system prompt by SkillPromptSubscriber.
   *
   * @param array<string, array<string, mixed>> $skills
   *   Available skills.
   * @param string $message
   *   User message.
   * @param string $last_result_reply
   *   Full structured answer stored for the previous turn of the thread.
   */
  public function buildProviderMessage(array $skills, string $message, string $last_result_reply = ''): string {
    return $message;
  }

  /**
   * Builds the system-level instructions for the active skills.
   *
   * @param array<string, array<string, mixed>> $skills
   *   Available skills.
   * @param string $last_result_reply
   *   Full structured answer stored for the previous turn of the thread.
   */
  public function buildProviderInstructions(array $skills, string $last_result_reply = ''): string {
    return $this->joinInstructionBlocks([
      $this->getGlobalProviderInstructions(),
      $this->buildActiveSelectionBlock($last_result_reply),
      $this->getSkillCatalogInstructions($skills),
    ]);
  }

  /**
   * Builds the compact first-iteration prompt used to route the request.
   *
   * Detailed response contracts are included only for skills that can answer
   * without retrieval. RAG-backed skills disclose their full contract after
   * the selected skill is carried by the tool call into the next iteration.
   *
   * @param array<string, array<string, mixed>> $skills
   *   Available skills.
   * @param string $last_result_reply
   *   Full structured answer stored for the previous turn of the thread.
   * @param bool $force_rag
   *   Whether this is a guarded retry that must execute the RAG tool.
   */
  public function buildProviderRoutingInstructions(array $skills, string $last_result_reply = '', bool $force_rag = FALSE): string {
    return $this->joinInstructionBlocks([
      $this->getGlobalProviderInstructions(),
      $this->buildActiveSelectionBlock($last_result_reply),
      $this->getSkillRoutingInstructions($skills, $force_rag),
    ]);
  }

  /**
   * Builds a later-iteration prompt for one explicitly selected skill.
   *
   * @param array<string, mixed> $skill
   *   Selected enabled skill.
   * @param string $last_result_reply
   *   Full structured answer stored for the previous turn of the thread.
   * @param string $skill_reference
   *   Exact reference carried by the tool call.
   */
  public function buildProviderSelectedSkillInstructions(array $skill, string $last_result_reply = '', string $skill_reference = ''): string {
    return $this->joinInstructionBlocks([
      $this->getGlobalProviderInstructions(),
      $this->buildActiveSelectionBlock($last_result_reply),
      $this->getSelectedSkillInstructions($skill, $skill_reference),
    ]);
  }

  /**
   * Whether a skill must have retrieval evidence before it can answer.
   *
   * Unknown definitions remain conservative for backwards compatibility.
   *
   * @param array<string, mixed>|null $skill
   *   Skill definition.
   */
  public function skillRequiresRag(?array $skill): bool {
    return $skill === NULL
      || !array_key_exists('requires_rag', $skill)
      || (bool) $skill['requires_rag'];
  }

  /**
   * Preserves a product collection across intervening document lookups.
   *
   * @param array $results
   *   Sanitized full replies from the current thread only.
   * @param string $last_result_reply
   *   Current visual reply, retained when no collection can be resumed.
   *
   * @return string
   *   Context reply for provider instructions, without changing the panel.
   */
  public function getProviderContextReply(array $results, string $last_result_reply): string {
    $structured = $this->parseStructuredReply($last_result_reply);
    $reference = (string) ($structured['skill'] ?? $structured['machine_name'] ?? '');
    if ($reference !== 'document_lookup' && !str_ends_with($reference, ':document_lookup')) {
      return $last_result_reply;
    }

    foreach (array_reverse($results) as $entry) {
      $reply = is_array($entry) ? (string) ($entry['reply'] ?? '') : '';
      $structured = $this->parseStructuredReply($reply);
      $reference = (string) ($structured['skill'] ?? $structured['machine_name'] ?? '');
      if ($reference === 'document_lookup' || str_ends_with($reference, ':document_lookup')) {
        continue;
      }

      $result = is_array($structured['result'] ?? NULL) ? $structured['result'] : [];
      return self::collectSelectionItems($result) !== [] ? $reply : $last_result_reply;
    }

    return $last_result_reply;
  }

  /**
   * Builds the active product collection context for follow-up requests.
   *
   * Thread history only keeps the concise chat reply, so without this block the
   * provider cannot know which products it already offered and restarts the
   * selection on every turn.
   *
   * @param string $last_result_reply
   *   Full structured answer stored for the previous turn of the thread.
   */
  private function buildActiveSelectionBlock(string $last_result_reply): string {
    if (trim($last_result_reply) === '') {
      return '';
    }

    $structured = $this->parseStructuredReply($last_result_reply);
    $result = is_array($structured['result'] ?? NULL) ? $structured['result'] : [];
    $items = self::collectSelectionItems($result);
    if ($items === []) {
      return '';
    }

    $selection = json_encode([
      'skill' => (string) ($structured['skill'] ?? $structured['machine_name'] ?? ''),
      'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return "COLECCIÓN ACTIVA (productos o planes ya ofrecidos en este hilo):\n"
      . $selection . "\n"
      . "Reglas de la colección activa:\n"
      . "- Si el usuario pide añadir, quitar, sustituir o ajustar algo, conserva los `product_id` o `node_id` válidos y devuelve siempre la colección COMPLETA resultante.\n"
      . "- No reinicies la selección ni devuelvas solo los productos nuevos.\n"
      . "- Mantén la misma `skill` salvo que la intención del usuario cambie con claridad.\n"
      . "- Para lo que se añada, lanza una consulta RAG por cada categoría nueva y fusiona los resultados con esta colección.\n"
      . "- Ignora esta colección si el usuario abre un tema sin relación con ella.";
  }

  /**
   * Flattens the product items of a structured result.
   *
   * Handles both the flat `items` contract and the `categories[].items`
   * contract used by event oriented skills.
   *
   * @param array<string, mixed> $result
   *   Structured `result` payload of an assistant answer.
   *
   * @return array<int, array<string, mixed>>
   *   Items reduced to the fields the provider needs to rebuild the selection.
   */
  public static function collectSelectionItems(array $result): array {
    $groups = [['', $result['items'] ?? []]];
    foreach (is_array($result['categories'] ?? NULL) ? $result['categories'] : [] as $category) {
      if (is_array($category)) {
        $groups[] = [
          trim((string) ($category['name'] ?? $category['title'] ?? '')),
          $category['items'] ?? $category['products'] ?? [],
        ];
      }
    }

    $items = [];
    foreach ($groups as [$category_name, $group_items]) {
      foreach (is_array($group_items) ? $group_items : [] as $item) {
        $product_id = is_array($item) ? (int) ($item['product_id'] ?? 0) : 0;
        $node_id = is_array($item) ? (int) ($item['node_id'] ?? 0) : 0;
        if (($product_id <= 0 && $node_id <= 0) || count($items) >= self::ACTIVE_SELECTION_LIMIT) {
          continue;
        }

        $selection_item = ($product_id > 0 ? ['product_id' => $product_id] : ['node_id' => $node_id]) + [
          'title' => trim((string) ($item['title'] ?? $item['name'] ?? '')),
          'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
        ];
        if ((int) ($item['variation_id'] ?? 0) > 0) {
          $selection_item['variation_id'] = (int) $item['variation_id'];
        }
        if ($category_name !== '') {
          $selection_item['category'] = $category_name;
        }

        $items[] = $selection_item;
      }
    }

    return $items;
  }

  /**
   * Checks whether the provider flagged the question as out of assistant scope.
   *
   * Out-of-scope answers are chat-only: the visual result area keeps whatever
   * the previous turn painted.
   */
  public function isOutOfScope(string $assistant_reply): bool {
    $structured = $this->parseStructuredReply($assistant_reply);

    return !empty($structured['out_of_scope'])
      && trim((string) ($structured['chat_reply'] ?? '')) !== '';
  }

  /**
   * Checks whether a structured answer carries anything worth painting.
   *
   * Providers drop the `out_of_scope` flag often enough that an empty `result`
   * is the second signal that the answer belongs in the chat only.
   */
  public function hasRenderableResult(string $assistant_reply): bool {
    $structured = $this->parseStructuredReply($assistant_reply);
    if ($structured === []) {
      // Plain text answers still render through the default template.
      return TRUE;
    }

    return self::hasContent(is_array($structured['result'] ?? NULL) ? $structured['result'] : []);
  }

  /**
   * Checks recursively whether a value tree holds any non-empty scalar.
   *
   * @param array<mixed> $values
   *   Value tree to inspect.
   */
  private static function hasContent(array $values): bool {
    foreach ($values as $value) {
      if (is_array($value) ? self::hasContent($value) : trim((string) $value) !== '') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Extracts the concise chat reply from a structured assistant answer.
   */
  public function extractChatReply(string $assistant_reply): string {
    $structured = $this->parseStructuredReply($assistant_reply);

    return trim((string) ($structured['chat_reply'] ?? ''));
  }

  /**
   * Restricts travel cards and citations to retrieved public translations.
   *
   * @param array<int, array<string, bool>> $retrieved_nodes
   *   Search evidence for this conversation.
   */
  public function restrictTravelReferences(?array $skill, string $assistant_reply, array $retrieved_nodes): string {
    $machine_name = $skill['machine_name'] ?? '';
    if (!in_array($machine_name, ['travel_plans', 'travel_guide'], TRUE)) {
      return $assistant_reply;
    }

    $structured = $this->parseStructuredReply($assistant_reply);
    if ($structured === [] || !is_array($structured['result'] ?? NULL)) {
      return $assistant_reply;
    }

    $field = $machine_name === 'travel_plans' ? 'items' : 'sources';
    $bundles = $machine_name === 'travel_plans' ? ProductTypes::BUNDLES : TravelDocument::BUNDLES;
    $requested = is_array($structured['result'][$field] ?? NULL) ? $structured['result'][$field] : [];
    $verified = $this->skillRuntime->filterRetrievedTravelItems($requested, $retrieved_nodes, $bundles);
    $allowed_ids = [];
    foreach ($verified as $item) {
      $allowed_ids[(int) $item['node_id']] = TRUE;
    }
    $rejected = FALSE;
    foreach ($requested as $item) {
      $id = is_array($item) ? filter_var($item['node_id'] ?? NULL, FILTER_VALIDATE_INT) : FALSE;
      if ($id === FALSE || $id <= 0 || !isset($allowed_ids[$id])) {
        $rejected = TRUE;
        break;
      }
    }

    if ($rejected) {
      $structured['chat_reply'] = '';
      if ($machine_name === 'travel_guide') {
        $verified = [];
      }
      else {
        $structured['result']['intro'] = '';
        $structured['result']['outro'] = '';
      }
    }
    $structured['result'][$field] = $verified;
    return json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

  /**
   * Renders the result area for the matched skill.
   *
   * @param array<string, mixed>|null $skill
   *   Matched filesystem skill definition.
   * @param string $assistant_reply
   *   Raw assistant response to render.
   */
  public function render(?array $skill, string $assistant_reply): string {
    return $this->renderWithAttachments($skill, $assistant_reply)['html'];
  }

  /**
   * Renders the result area and exposes AJAX commands for bubbled assets.
   *
   * @param array<string, mixed>|null $skill
   *   Matched filesystem skill definition.
   * @param string $assistant_reply
   *   Raw assistant response to render.
   *
   * @return array{html: string, ajax_commands: array<int, array<string, mixed>>}
   *   Rendered HTML and Drupal AJAX commands needed by attached assets.
   */
  public function renderWithAttachments(?array $skill, string $assistant_reply): array {
    $template = $this->resolveTemplate($skill);
    $context = $this->buildContext($skill, $template, $assistant_reply);
    $handler = $this->skillHandlerResolver->resolve($skill, $template);
    $build = [
      '#theme' => 'trotamundos_assistant_template_' . $template,
      '#title' => $skill['title'] ?? NULL,
      '#content' => NULL,
    ] + $handler->buildVariables($context);

    foreach ($handler->getLibraries($context) as $library) {
      $build['#attached']['library'][] = $library;
    }

    $html = (string) $this->renderer->renderInIsolation($build);
    $ajax_response = new AjaxResponse();
    $ajax_response->setAttachments($build['#attached'] ?? []);
    $ajax_response = $this->ajaxAttachmentsProcessor->processAttachments($ajax_response);

    return [
      'html' => $html,
      'ajax_commands' => $ajax_response instanceof AjaxResponse ? $ajax_response->getCommands() : [],
    ];
  }

  /**
   * Builds the concise chat reply delegated to the selected skill.
   *
   * @param array<string, mixed>|null $skill
   *   Matched filesystem skill definition.
   * @param string $assistant_reply
   *   Raw assistant response used to build the chat reply.
   */
  public function buildChatReply(?array $skill, string $assistant_reply): string {
    $template = $this->resolveTemplate($skill);
    $context = $this->buildContext($skill, $template, $assistant_reply);
    $handler = $this->skillHandlerResolver->resolve($skill, $template);

    return $handler->buildChatReply($context);
  }

  /**
   * Builds context data passed to skill handlers.
   *
   * @param array<string, mixed>|null $skill
   *   Matched filesystem skill definition.
   * @param string $template
   *   Template machine name resolved for the skill.
   * @param string $assistant_reply
   *   Raw assistant response used to build the context.
   */
  private function buildContext(?array $skill, string $template, string $assistant_reply): SkillContext {
    $structured = $this->parseStructuredReply($assistant_reply);
    $result = is_array($structured['result'] ?? NULL) ? $structured['result'] : [];
    $parsed = $structured === [] ? $this->parseAssistantReply($assistant_reply) : $this->emptyParsedFallback();

    return new SkillContext($skill, $template, $assistant_reply, $structured, $result, $parsed);
  }

  /**
   * Builds an empty fallback parse for already-structured responses.
   *
   * @return array{intro: string, items: array<int, array<string, mixed>>, outro: string}
   *   Empty parsed response.
   */
  private function emptyParsedFallback(): array {
    return [
      'intro' => '',
      'items' => [],
      'outro' => '',
    ];
  }

  /**
   * Parses the preferred structured response contract.
   *
   * @return array<string, mixed>
   *   Decoded response or an empty array.
   */
  private function parseStructuredReply(string $assistant_reply): array {
    $text = trim($assistant_reply);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text) ?? $text;
    $decoded = json_decode($text, TRUE);

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Parses a plain assistant answer into intro, items, and outro.
   *
   * @return array{intro: string, items: array<int, array<string, mixed>>, outro: string}
   *   Parsed response.
   */
  private function parseAssistantReply(string $assistant_reply): array {
    $text = $this->normalizeResponseText($assistant_reply);
    $matches = [];
    preg_match_all(
      '/(?:^|\n)\s*(?:\d+[\.\)]|-)\s*(?:\*\*)?([^:\n*]+?)(?:\*\*)?\s*:\s*(.*?)(?=\n\s*(?:\d+[\.\)]|-)\s*(?:\*\*)?[^:\n*]+(?:\*\*)?\s*:|\z)/su',
      $text,
      $matches,
      PREG_SET_ORDER,
    );

    $items = [];
    foreach ($matches as $match) {
      $items[] = [
        'title' => $this->cleanInlineText($match[1]),
        'body' => $this->cleanInlineText($match[2]),
      ];
    }

    if ($items === []) {
      return [
        'intro' => '',
        'items' => $this->paragraphsAsItems($text),
        'outro' => '',
      ];
    }

    $first_item_position = strpos($text, $matches[0][0]);
    $last_match = end($matches);
    $last_item_position = strrpos($text, $last_match[0]);
    $intro = $first_item_position === FALSE ? '' : substr($text, 0, $first_item_position);
    $outro_position = $last_item_position === FALSE ? FALSE : $last_item_position + strlen($last_match[0]);
    $outro = $outro_position === FALSE ? '' : substr($text, $outro_position);

    return [
      'intro' => $this->cleanInlineText($intro),
      'items' => $items,
      'outro' => $this->cleanInlineText($outro),
    ];
  }

  /**
   * Converts paragraphs to generic items.
   *
   * @return array<int, array<string, mixed>>
   *   Generic text items.
   */
  private function paragraphsAsItems(string $text): array {
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
   * Normalizes assistant output before parsing.
   */
  private function normalizeResponseText(string $text): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/\s+(\d+[\.\)]\s+(?:\*\*)?)/u', "\n$1", $text) ?? $text;
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';

    return trim($text);
  }

  /**
   * Cleans lightweight markdown from inline text.
   */
  private function cleanInlineText(string $text): string {
    $text = $this->normalizeResponseText($text);
    $text = preg_replace('/\*\*(.*?)\*\*/s', '$1', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;

    return trim($text, " \n\t.:;-");
  }

  /**
   * Resolves the template machine name for a skill.
   *
   * @param array<string, mixed>|null $skill
   *   Matched filesystem skill definition.
   */
  private function resolveTemplate(?array $skill): string {
    if (!$skill) {
      return 'default';
    }

    $template = $this->sanitizeTemplateName((string) ($skill['template_hook'] ?? $skill['machine_name'] ?? ''));
    $template_file = (string) ($skill['template'] ?? '');

    return is_file($template_file) ? $template : 'default';
  }

  /**
   * Converts a configured template value into a safe theme-hook suffix.
   */
  private function sanitizeTemplateName(string $template): string {
    $template = mb_strtolower(trim($template));
    $template = preg_replace('/[^a-z0-9_]+/', '_', $template) ?? '';
    $template = trim($template, '_');

    return $template ?: 'default';
  }

  /**
   * Returns exact provider instructions for all available skills.
   *
   * @param array<string, array<string, mixed>> $skills
   *   Available skills.
   */
  private function getSkillCatalogInstructions(array $skills): string {
    $catalog = [];
    $machine_name_counts = $this->countSkillMachineNames($skills);
    foreach ($skills as $skill) {
      if (!is_array($skill) || empty($skill['machine_name'])) {
        continue;
      }

      $skill_reference = $this->getProviderSkillReference($skill, $machine_name_counts);
      $catalog_item = $this->buildDetailedCatalogItem($skill, $skill_reference);
      if ($catalog_item !== NULL) {
        $catalog[] = $catalog_item;
      }
    }

    if ($catalog === []) {
      $default_item = $this->buildDefaultCatalogItem();
      if ($default_item === NULL) {
        return '';
      }
      $catalog[] = $default_item;
    }

    return "CATÁLOGO DETALLADO DE SKILLS:\n"
      . "Escoge exactamente una skill y respeta su `response_example`.\n"
      . $this->encodeProviderData($catalog);
  }

  /**
   * Returns the compact skill router used in the first agent iteration.
   *
   * @param array<string, array<string, mixed>> $skills
   *   Available skills.
   * @param bool $force_rag
   *   Whether a guarded retry must execute RAG.
   */
  private function getSkillRoutingInstructions(array $skills, bool $force_rag): string {
    $catalog = [];
    $machine_name_counts = $this->countSkillMachineNames($skills);
    foreach ($skills as $skill) {
      if (!is_array($skill) || empty($skill['machine_name'])) {
        continue;
      }

      $machine_name = (string) $skill['machine_name'];
      $skill_reference = $this->getProviderSkillReference($skill, $machine_name_counts);
      $requires_rag = $this->skillRequiresRag($skill);
      $routing_hints = array_values(array_filter(array_map(
        'strval',
        is_array($skill['when_to_use'] ?? NULL) ? array_slice($skill['when_to_use'], 0, 2) : [],
      )));
      $item = [
        'skill' => $skill_reference,
        'title' => (string) ($skill['title'] ?? $machine_name),
        'intent' => (string) ($skill['provider_intent'] ?? ''),
        'routing_hints' => $routing_hints,
        'requires_rag' => $requires_rag,
      ];

      // Direct skills must be able to complete the request in this first and
      // only provider call, so retain just their compact response contract.
      if (!$requires_rag) {
        $example = $this->getSkillExample($skill);
        if ($example !== '') {
          $item['response_example'] = ['skill' => $skill_reference] + (json_decode($example, TRUE) ?: []);
        }
        if ($machine_name === 'content_create') {
          $item['creation_targets'] = $this->skillRuntime->buildContentCreateProviderCatalog();
        }
      }

      $catalog[] = $item;
    }

    if ($catalog === []) {
      $default_item = $this->buildDefaultCatalogItem();
      if ($default_item === NULL) {
        return '';
      }
      $default_item['requires_rag'] = FALSE;
      $catalog[] = $default_item;
    }

    $retry_rule = $force_rag
      ? "Este es un reintento protegido: debes llamar a `trotamundos_assistant_skill_rag_search` antes de responder."
      : "Si `requires_rag` es `true`, llama primero a `trotamundos_assistant_skill_rag_search`; si es `false`, responde directamente y no uses RAG.";

    return "ENRUTADO DE SKILLS:\n"
      . "Escoge exactamente una skill según la intención del usuario.\n"
      . $retry_rule . "\n"
      . "Al llamar a RAG, copia el valor exacto de `skill` en el argumento `skill`. Para varios tipos de producto, puedes lanzar varias búsquedas con la misma skill.\n"
      . "Las skills con RAG publicarán su contrato detallado después de recuperar resultados. Las skills directas deben responder ahora respetando `response_example`.\n"
      . "Catálogo compacto:\n"
      . $this->encodeProviderData($catalog);
  }

  /**
   * Returns the full response contract for one selected skill.
   *
   * @param array<string, mixed> $skill
   *   Selected skill.
   * @param string $skill_reference
   *   Exact skill reference carried by the RAG tool.
   */
  private function getSelectedSkillInstructions(array $skill, string $skill_reference): string {
    $skill_reference = trim($skill_reference);
    if ($skill_reference === '') {
      $skill_reference = (string) ($skill['id'] ?? $skill['machine_name'] ?? '');
    }

    $item = $this->buildDetailedCatalogItem($skill, $skill_reference);
    if ($item === NULL) {
      return $this->getSkillCatalogInstructions([$skill_reference => $skill]);
    }

    return "SKILL SELECCIONADA PARA ESTA ITERACIÓN:\n"
      . "Usa exclusivamente la skill indicada y respeta exactamente su `response_example`.\n"
      . "Si necesitas más resultados RAG, conserva el mismo argumento `skill` en todas las búsquedas.\n"
      . $this->encodeProviderData($item);
  }

  /**
   * Builds one detailed catalogue item.
   *
   * @param array<string, mixed> $skill
   *   Skill definition.
   * @param string $skill_reference
   *   Exact provider-facing skill reference.
   *
   * @return array<string, mixed>|null
   *   Provider catalogue item, or NULL when its example is unavailable.
   */
  private function buildDetailedCatalogItem(array $skill, string $skill_reference): ?array {
    $machine_name = (string) ($skill['machine_name'] ?? '');
    $example = $this->getSkillExample($skill);
    if ($machine_name === '' || $example === '') {
      return NULL;
    }

    $item = [
      'skill' => $skill_reference,
      'title' => (string) ($skill['title'] ?? $machine_name),
      'intent' => (string) ($skill['provider_intent'] ?? ''),
      'when_to_use' => array_values(array_filter(array_map('strval', is_array($skill['when_to_use'] ?? NULL) ? $skill['when_to_use'] : []))),
      'requires_rag' => $this->skillRequiresRag($skill),
      'response_example' => ['skill' => $skill_reference] + (json_decode($example, TRUE) ?: []),
    ];
    if ($machine_name === 'content_create') {
      $item['creation_targets'] = $this->skillRuntime->buildContentCreateProviderCatalog();
    }

    return $item;
  }

  /**
   * Builds the filesystem fallback when discovery returns no skills.
   *
   * @return array<string, mixed>|null
   *   Default catalogue item.
   */
  private function buildDefaultCatalogItem(): ?array {
    $default_example = $this->getTemplateExample('default');
    if ($default_example === '') {
      return NULL;
    }

    $metadata = $this->getTemplateMetadata('default');

    return [
      'skill' => 'default',
      'title' => (string) ($metadata['title'] ?? 'default'),
      'intent' => (string) ($metadata['provider']['intent'] ?? ''),
      'when_to_use' => array_values(array_filter(array_map('strval', is_array($metadata['provider']['when_to_use'] ?? NULL) ? $metadata['provider']['when_to_use'] : []))),
      'requires_rag' => FALSE,
      'response_example' => ['skill' => 'default'] + (json_decode($default_example, TRUE) ?: []),
    ];
  }

  /**
   * Returns rules shared by routing and response-composition iterations.
   */
  private function getGlobalProviderInstructions(): string {
    return "INSTRUCCIONES DE SKILLS PARA DRUPAL:\n"
      . "ÁMBITO (compruébalo ANTES de mirar el catálogo): la consulta debe tratar de este sitio (catálogo, productos, pedidos, carrito, contenido editorial y ayuda del sitio).\n"
      . "Si NO trata de eso, el catálogo de skills no aplica. Devuelve exactamente {\"skill\":\"default\",\"out_of_scope\":true,\"chat_reply\":\"<respuesta breve y educada reconduciendo al sitio>\",\"result\":{}}.\n"
      . "En una respuesta fuera de ámbito, `result` debe ir VACÍO: el panel conserva lo que el usuario ya tenía en pantalla y la respuesta se muestra solo en el chat. Nunca rellenes `result.title` ni `result.description` para explicar de qué puedes hablar.\n"
      . "La skill `default` sin `out_of_scope` es solo para consultas DEL SITIO que ninguna otra skill cubre. Fuera de ámbito, `out_of_scope` es obligatorio. No lo marques si la consulta encaja en alguna skill del catálogo.\n"
      . "Devuelve exclusivamente JSON válido, sin markdown ni texto fuera del JSON, salvo cuando estés llamando a una herramienta.\n"
      . "La respuesta final debe incluir siempre `skill`, `chat_reply` y `result`; mantén `chat_reply` muy conciso.\n"
      . "El valor de `skill` debe ser uno de los valores `skill` del catálogo; Drupal resolverá internamente el template configurado.\n"
      . "No inventes datos. En `result`, respeta la estructura del ejemplo de la skill y usa información real recuperada por RAG.\n"
      . "Para tarjetas de Commerce, cada item debe incluir `product_id` presente en RAG o en la COLECCIÓN ACTIVA; para planes editoriales, usa el `node_id` recuperado. Si la referencia es a una variación concreta, incluye también `variation_id`. No inventes esos IDs: Drupal resuelve los datos reales.\n"
      . "Si existe una COLECCIÓN ACTIVA, conserva sus elementos válidos y devuelve la colección completa, no solo los cambios.\n"
      . "Cuando la petición implique varios tipos de producto, lanza una consulta RAG por cada tipo antes de componer la respuesta, en vez de una sola consulta genérica.\n"
      . "Para `content_create`, elige `result.entity_type` y `result.bundle` exclusivamente desde `creation_targets`, usando sus IDs exactos.";
  }

  /**
   * Joins non-empty system-prompt blocks.
   *
   * @param string[] $blocks
   *   Prompt blocks.
   */
  private function joinInstructionBlocks(array $blocks): string {
    return implode("\n\n", array_values(array_filter(array_map('trim', $blocks))));
  }

  /**
   * Encodes provider context without token-expensive presentation whitespace.
   */
  private function encodeProviderData(array $data): string {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
  }

  /**
   * Counts available skills by machine name.
   *
   * @param array<string, array<string, mixed>> $skills
   *   Available skills.
   *
   * @return array<string, int>
   *   Number of occurrences by skill machine name.
   */
  private function countSkillMachineNames(array $skills): array {
    $counts = [];
    foreach ($skills as $skill) {
      if (!is_array($skill)) {
        continue;
      }
      $machine_name = (string) ($skill['machine_name'] ?? '');
      if ($machine_name !== '') {
        $counts[$machine_name] = ($counts[$machine_name] ?? 0) + 1;
      }
    }

    return $counts;
  }

  /**
   * Gets the safest skill reference to expose to the provider.
   *
   * @param array<string, mixed> $skill
   *   Skill definition.
   * @param array<string, int> $machine_name_counts
   *   Number of occurrences by skill machine name.
   */
  private function getProviderSkillReference(array $skill, array $machine_name_counts): string {
    $machine_name = (string) ($skill['machine_name'] ?? '');
    if (($machine_name_counts[$machine_name] ?? 0) <= 1) {
      return $machine_name;
    }

    return (string) ($skill['id'] ?? $machine_name);
  }

  /**
   * Returns the JSON example associated with a template.
   */
  private function getTemplateExample(string $template): string {
    $module_path = $this->moduleExtensionList->getPath('trotamundos_assistant');
    $example_file = $module_path . '/skills/' . $template . '/example.json';
    if (!is_file($example_file)) {
      return '';
    }

    $example = trim((string) file_get_contents($example_file));
    if ($example === '') {
      return '';
    }

    return $example;
  }

  /**
   * Returns the JSON example associated with a discovered skill.
   *
   * @param array<string, mixed> $skill
   *   Skill definition.
   */
  private function getSkillExample(array $skill): string {
    $example_file = (string) ($skill['example'] ?? '');
    if (!is_file($example_file)) {
      return '';
    }

    return trim((string) file_get_contents($example_file));
  }

  /**
   * Returns metadata declared by a skill template.
   *
   * @return array<string, mixed>
   *   Metadata from skill.yml.
   */
  private function getTemplateMetadata(string $template): array {
    $module_path = $this->moduleExtensionList->getPath('trotamundos_assistant');
    $metadata_file = $module_path . '/skills/' . $template . '/skill.yml';
    if (!is_file($metadata_file)) {
      return [];
    }

    try {
      $metadata = Yaml::decode((string) file_get_contents($metadata_file));
    }
    catch (\Throwable) {
      return [];
    }

    return is_array($metadata) ? $metadata : [];
  }

}
