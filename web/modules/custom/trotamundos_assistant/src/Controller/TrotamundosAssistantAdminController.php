<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Controller;

use Drupal\Component\Utility\Html;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Drupal\trotamundos_assistant\Admin\AdminPillsTrait;
use Drupal\trotamundos_assistant\Admin\DependencyStatusBuilder;
use Drupal\trotamundos_assistant\Config\TrotamundosAssistantDefaults;
use Drupal\trotamundos_assistant\Skill\SkillMatcher;
use Drupal\trotamundos_assistant\Usage\UsageChartBuilder;
use Drupal\trotamundos_assistant\Usage\UsageReportBuilder;
use Drupal\trotamundos_assistant\Usage\UsageRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds administration pages for Trotamundos Assistant.
 */
final class TrotamundosAssistantAdminController extends ControllerBase {

  use AdminPillsTrait;

  /**
   * Number of conversations shown in the usage table.
   */
  private const USAGE_CONVERSATIONS_PAGE_SIZE = 25;

  /**
   * Visual conversion rate used when administrators choose EUR.
   */
  private const USD_TO_EUR_RATE = 0.92;

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManagerService,
    private readonly SkillMatcher $skillMatcher,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ConfigFactoryInterface $configFactoryService,
    private readonly PagerManagerInterface $pagerManager,
    private readonly UsageChartBuilder $usageChartBuilder,
    private readonly UsageRepository $usageRepository,
    private readonly UsageReportBuilder $usageReportBuilder,
    private readonly DependencyStatusBuilder $dependencyStatusBuilder,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly ModuleHandlerInterface $moduleHandlerService,
    private readonly RouteProviderInterface $routeProvider,
    private readonly TrotamundosAssistantDefaults $defaults,
    private readonly FunctionCallPluginManager $functionCallPluginManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('trotamundos_assistant.skill_matcher'),
      $container->get('date.formatter'),
      $container->get('config.factory'),
      $container->get('pager.manager'),
      $container->get('trotamundos_assistant.usage_chart_builder'),
      $container->get('trotamundos_assistant.usage_repository'),
      $container->get('trotamundos_assistant.usage_report_builder'),
      $container->get('trotamundos_assistant.dependency_status_builder'),
      $container->get('extension.list.module'),
      $container->get('module_handler'),
      $container->get('router.route_provider'),
      $container->get('trotamundos_assistant.defaults'),
      $container->get('plugin.manager.ai.function_calls'),
    );
  }

  /**
   * Builds the visual administration flow page.
   */
  public function flow(): array {
    $assistant_id = $this->getAssistantId();
    $steps = [
      [
        'icon' => '1',
        'title' => $this->t('1. El usuario envía'),
        'text' => $this->t('El usuario escribe en /home-ai y pulsa Enviar o Enter (Ctrl+Enter para salto de línea). También puede pulsar Nuevo chat para pedir un hilo limpio antes del siguiente mensaje.'),
      ],
      [
        'icon' => '2',
        'title' => $this->t('2. El frontend prepara la petición'),
        'text' => $this->t('El JS valida que haya texto, añade el mensaje al chat, muestra el loader, recupera el thread_id de sessionStorage y adjunta el token CSRF.'),
      ],
      [
        'icon' => '3',
        'title' => $this->t('3. AJAX llama al endpoint'),
        'text' => $this->t('El navegador envía POST a /trotamundos-assistant/chat con message y, si existe, thread_id. Si el hilo guardado no es válido, lo borra y reintenta una vez sin thread_id.'),
      ],
      [
        'icon' => '4',
        'title' => $this->t('4. Drupal valida entrada'),
        'text' => $this->t('El controller valida acceso al asistente, CSRF, mensaje, longitud máxima, rate limit y que el assistant configurado @assistant esté disponible.', [
          '@assistant' => $assistant_id,
        ]),
      ],
      [
        'icon' => '5',
        'title' => $this->t('5. Se prepara el hilo'),
        'text' => $this->t('Si llega thread_id válido, se reutiliza el historial conversacional; si no, se genera uno nuevo. Antes de procesar se limpia el estado temporal del agente para evitar continuar una ejecución anterior.'),
      ],
      [
        'icon' => '6',
        'title' => $this->t('6. Drupal carga las skills'),
        'text' => $this->t('SkillDiscovery descubre skills en los módulos habilitados, valida skill.yml, example.json, Twig y dependencias, y SkillMatcher filtra las activas por configuración.'),
      ],
      [
        'icon' => '7',
        'title' => $this->t('7. Se crea el prompt técnico'),
        'text' => $this->t('SkillTemplateRenderer combina el mensaje con el catálogo de skills y ejemplos. Para content_create añade creation_targets con entidades y bundles creables por el usuario actual.'),
      ],
      [
        'icon' => '8',
        'title' => $this->t('8. Empieza la medición'),
        'text' => $this->t('UsageLogger abre una interacción con call_id, usuario, thread_id, assistant_id, iteration_number y texto original enviado por el usuario.'),
      ],
      [
        'icon' => '9',
        'title' => $this->t('9. Se ejecuta el assistant'),
        'text' => $this->t('AiAssistantApiRunner guarda el mensaje técnico en el hilo y ejecuta process(). Si el assistant tiene ai_agent, delega en AgentRunner; si no, usa el flujo clásico de actions y respuesta final.'),
      ],
      [
        'icon' => '10',
        'title' => $this->t('10. El agente decide herramientas'),
        'text' => $this->t('El agente decide si puede responder directamente o si necesita RAG/Pinecone u otras herramientas. Cada búsqueda vectorial puede generar llamadas de embeddings y una interacción puede tener cero, una o varias llamadas internas.'),
      ],
      [
        'icon' => '11',
        'title' => $this->t('11. Se registra el uso interno'),
        'text' => $this->t('El subscriber registra cada llamada chat y embeddings con tokens, coste estimado, payload y raw resumido; fuera de beginCall no guarda nada.'),
      ],
      [
        'icon' => '12',
        'title' => $this->t('12. Se normaliza la respuesta'),
        'text' => $this->t('La IA debe devolver JSON con skill, chat_reply y result. Si el proveedor devuelve vacío, el controller crea un fallback local sin hacer otra llamada al modelo.'),
      ],
      [
        'icon' => '13',
        'title' => $this->t('13. Drupal valida la skill'),
        'text' => $this->t('SkillMatcher no decide la intención: solo comprueba que la skill devuelta por la IA existe y está activada en la configuración.'),
      ],
      [
        'icon' => '14',
        'title' => $this->t('14. Se preparan datos visuales'),
        'text' => $this->t('SkillHandlerResolver elige el handler y SkillRuntime prepara datos Drupal: productos, carrito, perfil o formularios embebidos de creación/edición con permisos.'),
      ],
      [
        'icon' => '15',
        'title' => $this->t('15. Se renderiza la template'),
        'text' => $this->t('Se renderiza la Twig de la skill en aislamiento y se procesan los attachments para devolver también comandos AJAX de librerías CSS/JS.'),
      ],
      [
        'icon' => '16',
        'title' => $this->t('16. Se guarda la conversación'),
        'text' => $this->t('El hilo guarda el mensaje visible del usuario, la respuesta breve del chat y el JSON completo para reconstruir el panel.'),
      ],
      [
        'icon' => '17',
        'title' => $this->t('17. Drupal responde al navegador'),
        'text' => $this->t('El endpoint devuelve reply, thread_id, call_id, usage, skill, machine_name, result_html y result_ajax_commands para el panel derecho.'),
      ],
      [
        'icon' => '18',
        'title' => $this->t('18. El frontend repinta y restaura'),
        'text' => $this->t('El JS guarda thread_id en sessionStorage, ejecuta result_ajax_commands, reemplaza el loader y pinta result_html. Si se refresca, /chat/history recupera mensajes y último resultado.'),
      ],
    ];

    $step_items = [];
    foreach ($steps as $index => $step) {
      $step_items['step_' . $index] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-flow__step']],
        'marker' => [
          '#markup' => '<span class="trotamundos-assistant-flow__step-icon" aria-hidden="true">' . $step['icon'] . '</span>',
        ],
        'content' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-flow__step-content']],
          'title' => [
            '#markup' => '<h3 class="trotamundos-assistant-flow__step-title">' . $step['title'] . '</h3>',
          ],
          'text' => [
            '#markup' => '<p class="trotamundos-assistant-flow__step-text">' . $step['text'] . '</p>',
          ],
        ],
      ];
    }

    return [
      '#attached' => [
        'library' => $this->getAdminLibraries(),
      ],
      '#prefix' => '<div class="trotamundos-assistant-usage trotamundos-assistant-flow">',
      '#suffix' => '</div>',
      'nav' => $this->buildAdminPills('flow'),
      'concepts' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-flow__concepts']],
        'title' => [
          '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Skill vs Tools') . '</h2>',
        ],
        'intro' => [
          '#markup' => '<p class="trotamundos-assistant-flow__concepts-intro">' . $this->t('Ambas exponen capacidades al asistente, pero viven en momentos distintos del flujo: la tool actúa o recupera contexto durante el razonamiento; la skill convierte la respuesta final en interfaz navegable.') . '</p>',
        ],
        'grid' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-flow__concept-grid']],
          'skill' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['trotamundos-assistant-flow__concept', 'trotamundos-assistant-flow__concept--skill']],
            'badge' => [
              '#markup' => '<p class="trotamundos-assistant-flow__concept-badge">' . $this->t('Skill') . '</p>',
            ],
            'title' => [
              '#markup' => '<h3 class="trotamundos-assistant-flow__concept-title">' . $this->t('Pinta el panel derecho') . '</h3>',
            ],
            'text' => [
              '#markup' => '<p class="trotamundos-assistant-flow__concept-text">' . $this->t('Es el contrato visual propio de Trotamundos Assistant. La IA devuelve JSON con skill, chat_reply y result; Drupal valida la skill, prepara variables y renderiza Twig con attachments.') . '</p>',
            ],
            'examples' => [
              '#markup' => '<p class="trotamundos-assistant-flow__concept-examples"><strong>' . $this->t('Ejemplos:') . '</strong> <code>content_create</code>, <code>content_edit</code>, <code>product_recommendations</code>, <code>user_profile</code>.</p>',
            ],
          ],
          'tool' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['trotamundos-assistant-flow__concept', 'trotamundos-assistant-flow__concept--tool']],
            'badge' => [
              '#markup' => '<p class="trotamundos-assistant-flow__concept-badge">' . $this->t('Tool / action') . '</p>',
            ],
            'title' => [
              '#markup' => '<h3 class="trotamundos-assistant-flow__concept-title">' . $this->t('Ejecuta o consulta durante el proceso') . '</h3>',
            ],
            'text' => [
              '#markup' => '<p class="trotamundos-assistant-flow__concept-text">' . $this->t('Es una capacidad del módulo AI/AI Agents. El modelo puede pedir usar una tool, Drupal la ejecuta, añade el resultado al historial y el agente puede hacer otra vuelta antes de responder.') . '</p>',
            ],
            'examples' => [
              '#markup' => '<p class="trotamundos-assistant-flow__concept-examples"><strong>' . $this->t('Ejemplos:') . '</strong> <code>trotamundos_assistant:skill_rag_search</code>, <code>action_plugin:entity:publish_action:node</code>, <code>action_plugin:action_send_email_action</code>.</p>',
            ],
          ],
        ],
        'bridge' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-flow__concept-bridge']],
          'label' => [
            '#markup' => '<span class="trotamundos-assistant-flow__concept-bridge-label">' . $this->t('Integración recomendada') . '</span>',
          ],
          'flow' => [
            '#markup' => '<span class="trotamundos-assistant-flow__concept-bridge-flow">' . $this->t('Tool ejecuta o recupera datos') . ' <span aria-hidden="true">→</span> ' . $this->t('IA compone JSON final') . ' <span aria-hidden="true">→</span> ' . $this->t('Skill renderiza el panel navegable') . '</span>',
          ],
        ],
      ],
      'hero' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-flow__hero']],
        'copy' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-flow__hero-copy']],
          'eyebrow' => [
            '#markup' => '<p class="trotamundos-assistant-flow__eyebrow">' . $this->t('Arquitectura funcional') . '</p>',
          ],
          'title' => [
            '#markup' => '<h2 class="trotamundos-assistant-flow__title">' . $this->t('¿Cómo viaja una conversación hasta convertirse en interfaz?') . '</h2>',
          ],
          'description' => [
            '#markup' => '<p class="trotamundos-assistant-flow__description">' . $this->t('El módulo separa conversación, decisión de skill, contrato de datos, render Twig y medición de coste para que el chatbot pueda cambiar la interfaz sin generar HTML libremente.') . '</p>',
          ],
        ],
      ],
      'diagram' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-flow__diagram']],
        'title' => [
          '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Flujo principal') . '</h2>',
        ],
        'steps' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-flow__steps']],
          'items' => $step_items,
        ],
      ],
      'contract' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-flow__contract']],
        'title' => [
          '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Contrato de respuesta') . '</h2>',
        ],
        'grid' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-flow__contract-grid']],
          'provider' => $this->buildFlowSnippet($this->t('La IA devuelve'), '{' . "\n" . '  "skill": "product_recommendations",' . "\n" . '  "chat_reply": "Te dejo recomendaciones.",' . "\n" . '  "result": { "items": [] }' . "\n" . '}'),
          'drupal' => $this->buildFlowSnippet($this->t('Drupal renderiza'), 'skills/{machine_name}' . "\n" . '+ example.json' . "\n" . '+ Twig template' . "\n" . '= panel derecho'),
        ],
      ],
    ];
  }

  /**
   * Builds the module administration overview page.
   */
  public function overview(): array {
    $checks = $this->dependencyStatusBuilder->buildChecks();
    $all_ok = !in_array(FALSE, array_column($checks, 'status'), TRUE);
    $failed_checks = array_values(array_filter($checks, static fn(array $check): bool => empty($check['status'])));
    $section_items = [];
    $catalog_index_items = [];

    foreach ($checks as $index => $check) {
      $section_key = (string) $check['section'];
      $check_item = $this->buildDependencyCheckItem($check, $index);

      if ($section_key === 'catalog_index') {
        $catalog_index_items['check_' . $index] = $check_item;
        continue;
      }

      $section_items[$section_key] ??= [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__dependency-section']],
        'title' => [
          '#markup' => '<h2 class="trotamundos-assistant-usage__dependency-section-title">' . $check['section_label'] . '</h2>',
        ],
        'items' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-usage__dependency-section-items']],
        ],
      ];

      $section_items[$section_key]['items']['check_' . $index] = $check_item;
    }

    return [
      '#attached' => [
        'library' => $this->getAdminLibraries(),
      ],
      '#prefix' => '<div class="trotamundos-assistant-usage">',
      '#suffix' => '</div>',
      'nav' => $this->buildAdminPills('overview'),
      'status_overview' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-status__overview']],
        'summary' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => [
              'trotamundos-assistant-usage__panel',
              'trotamundos-assistant-status__summary',
              $all_ok ? 'is-ok' : 'is-error',
            ],
          ],
          'icon' => [
            '#markup' => '<span class="trotamundos-assistant-status__summary-icon" aria-hidden="true">' . ($all_ok ? '&#10003;' : '!') . '</span>',
          ],
          'title' => [
            '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . ($all_ok ? $this->t('Todo listo') : $this->t('Diagnóstico pendiente')) . '</h2>',
          ],
          'description' => $all_ok ? [] : [
            '#markup' => '<p>' . $this->formatStatusDiagnosisMessage(count($failed_checks)) . '</p>',
          ],
          'diagnosis' => $all_ok ? [] : $this->buildStatusDiagnosisList($failed_checks),
        ],
        'cards' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-flow__cards']],
          'items' => $this->buildAssistantInfoCards(),
        ],
      ],
      'dependencies' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__dependencies']],
        'items' => $section_items,
      ],
      'index_status' => $this->buildIndexStatusPanel($catalog_index_items),
    ];
  }

  /**
   * Lists Trotamundos Assistant extension modules.
   */
  public function extensions(): array {
    $extensions = [];
    foreach ($this->moduleExtensionList->getList() as $machine_name => $extension) {
      if (!str_starts_with((string) $machine_name, 'trotamundos_assistant_')) {
        continue;
      }

      $info = is_array($extension->info ?? NULL) ? $extension->info : [];
      $extensions[$machine_name] = [
        'title' => (string) ($info['name'] ?? $machine_name),
        'description' => (string) ($info['description'] ?? $this->t('Sin descripción.')),
        'active' => $this->moduleHandlerService->moduleExists((string) $machine_name),
        'requirements' => $this->getExtensionRequirements((string) $machine_name),
        'settings_route' => $this->getExtensionSettingsRoute((string) $machine_name),
      ];
    }

    uasort($extensions, static fn(array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));

    $rows = [];
    foreach ($extensions as $machine_name => $extension) {
      $rows[] = [
        'title' => [
          'data' => [
            '#markup' => '<strong>' . Html::escape($extension['title']) . '</strong>',
          ],
        ],
        'description' => $extension['description'],
        'requirements' => [
          'data' => $this->buildExtensionRequirementsCell($extension['requirements']),
        ],
        'settings' => !$extension['active']
          ? $this->t('Desactivado')
          : ($extension['settings_route'] !== ''
            ? [
              'data' => [
                '#type' => 'link',
                '#title' => $this->t('Configurar'),
                '#url' => Url::fromRoute($extension['settings_route']),
              ],
            ]
            : $this->t('Sin configuración')),
      ];
    }

    $active_count = count(array_filter($extensions, static fn(array $extension): bool => $extension['active']));
    $total_count = count($extensions);

    return [
      '#attached' => [
        'library' => $this->getAdminLibraries(),
      ],
      '#prefix' => '<div class="trotamundos-assistant-usage">',
      '#suffix' => '</div>',
      'nav' => $this->buildAdminPills('extensions'),
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__panel']],
        'title' => [
          '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Extensiones disponibles') . '</h2>',
        ],
        'description' => [
          '#markup' => '<p class="trotamundos-assistant-usage__panel-description">' . $this->t('@active de @total extensiones están activas. Esta pantalla lista submódulos de Trotamundos Assistant detectados por Drupal.', [
            '@active' => $this->formatUsageNumber($active_count),
            '@total' => $this->formatUsageNumber($total_count),
          ]) . '</p>',
        ],
      ],
      'extensions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__panel']],
        'title' => [
          '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Listado') . '</h2>',
        ],
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Extensión'),
            $this->t('Descripción'),
            $this->t('Requisitos'),
            $this->t('Configuración'),
          ],
          '#rows' => $rows,
          '#empty' => $this->t('No hay extensiones disponibles. Si acabas de añadir una, limpia caché para que Drupal la descubra.'),
          '#attributes' => ['class' => ['trotamundos-assistant-usage__table']],
        ],
      ],
    ];
  }

  /**
   * Returns runtime requirements exposed by an active extension module.
   *
   * @return array<string, array<string, mixed>>
   *   The requirements returned by hook_requirements('runtime').
   */
  private function getExtensionRequirements(string $machine_name): array {
    if (!$this->moduleHandlerService->moduleExists($machine_name)) {
      return [];
    }

    $requirements = $this->moduleHandlerService->invoke($machine_name, 'requirements', ['runtime']);
    return is_array($requirements) ? $requirements : [];
  }

  /**
   * Builds a table cell for extension requirements.
   *
   * @param array<string, array<string, mixed>> $requirements
   *   Runtime requirements keyed by requirement machine name.
   */
  private function buildExtensionRequirementsCell(array $requirements): array {
    if ($requirements === []) {
      return [
        '#markup' => Html::escape((string) $this->t('Sin requisitos')),
      ];
    }

    $items = [];
    foreach ($requirements as $key => $requirement) {
      $title = (string) ($requirement['title'] ?? $key);
      $value = (string) ($requirement['value'] ?? '');
      $description = (string) ($requirement['description'] ?? '');
      $severity = $this->formatRequirementSeverity($requirement['severity'] ?? NULL);

      $markup = '<strong>' . Html::escape($title) . '</strong>';
      if ($value !== '') {
        $markup .= '<br><span>' . Html::escape($severity) . ': ' . Html::escape($value) . '</span>';
      }
      if ($description !== '') {
        $markup .= '<br><small>' . Html::escape($description) . '</small>';
      }

      $items[] = ['#markup' => $markup];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['trotamundos-assistant-extensions__requirements']],
    ];
  }

  /**
   * Formats a requirement severity for display.
   */
  private function formatRequirementSeverity(mixed $severity): string {
    return match ($severity) {
      RequirementSeverity::OK => (string) $this->t('OK'),
      RequirementSeverity::Warning => (string) $this->t('Aviso'),
      RequirementSeverity::Error => (string) $this->t('Error'),
      RequirementSeverity::Info => (string) $this->t('Info'),
      default => (string) $this->t('Info'),
    };
  }

  /**
   * Returns a settings route for an extension when available.
   */
  private function getExtensionSettingsRoute(string $machine_name): string {
    $route_name = $machine_name . '.settings';
    try {
      $this->routeProvider->getRouteByName($route_name);
      return $route_name;
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Builds the Search API index status panel.
   *
   * @return array<string, mixed>
   *   A render array with datasource and field information.
   */
  private function buildIndexStatusPanel(array $configuration_items = []): array {
    $index = $this->entityTypeManagerService
      ->getStorage('search_api_index')
      ->load('minsait_nodes_ai');

    if (!$index) {
      return [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'trotamundos-assistant-usage__panel',
            'is-error',
          ],
        ],
        'title' => [
          '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Configuración de búsqueda') . '</h2>',
        ],
        'description' => [
          '#markup' => '<p>' . $this->t('No se ha encontrado el índice Search API minsait_nodes_ai.') . '</p>',
        ],
        'configuration' => $this->buildCatalogIndexConfigurationSection($configuration_items),
      ];
    }

    $datasource_rows = [];
    foreach ($index->getDatasources() as $datasource_id => $datasource) {
      $entity_type = str_starts_with((string) $datasource_id, 'entity:')
        ? substr((string) $datasource_id, 7)
        : $this->t('No aplica');

      $datasource_rows[] = [
        'label' => (string) $datasource->label(),
        'id' => $datasource_id,
        'entity_type' => $entity_type,
      ];
    }

    $ai_search_options = $this->configFactoryService
      ->get('ai_search.index.minsait_nodes_ai')
      ->get('indexing_options') ?? [];
    $field_rows = [];
    foreach ($index->getFields() as $field_id => $field) {
      $field_rows[] = [
        'label' => (string) ($field->getLabel() ?: $field_id),
        'id' => $field_id,
        'datasource' => (string) ($field->getDatasourceId() ?: $this->t('Global')),
        'property' => (string) $field->getPropertyPath(),
        'type' => (string) $field->getType(),
        'ai_usage' => (string) ($ai_search_options[$field_id]['indexing_option'] ?? $this->t('Sin rol AI Search')),
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage__panel']],
      'title' => [
        '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Configuración de búsqueda') . '</h2>',
      ],
      'configuration' => $this->buildCatalogIndexConfigurationSection($configuration_items),
      'datasources_title' => [
        '#markup' => '<h3 class="trotamundos-assistant-usage__subsection-title">' . $this->t('Entidades dadas de alta') . '</h3>',
      ],
      'datasources' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Entidad'),
          $this->t('Datasource'),
          $this->t('Tipo de entidad'),
        ],
        '#rows' => $datasource_rows,
        '#empty' => $this->t('No hay entidades configuradas en el índice.'),
        '#attributes' => ['class' => ['trotamundos-assistant-usage__table']],
      ],
      'fields_title' => [
        '#markup' => '<h3 class="trotamundos-assistant-usage__subsection-title">' . $this->t('Campos indexados') . '</h3>',
      ],
      'fields' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Campo'),
          $this->t('ID'),
          $this->t('Datasource'),
          $this->t('Propiedad'),
          $this->t('Tipo'),
          $this->t('Uso AI Search'),
        ],
        '#rows' => $field_rows,
        '#empty' => $this->t('No hay campos configurados en el índice.'),
        '#attributes' => ['class' => ['trotamundos-assistant-usage__table']],
      ],
    ];
  }

  /**
   * Builds the usage administration page.
   */
  public function usage(Request $request): array {
    $selected_uid = $this->getSelectedUsageUid($request);
    $user_options = $this->getUsageUserOptions();
    $stats = $this->usageReportBuilder->buildOverviewStats(
      $this->usageRepository->fetchSummary($selected_uid),
    );
    $input_tokens = (int) $stats['input_tokens'];
    $output_tokens = (int) $stats['output_tokens'];
    $billable_tokens = max(1, $input_tokens + $output_tokens);
    $input_ratio = round(($input_tokens / $billable_tokens) * 100, 1);
    $output_ratio = round(($output_tokens / $billable_tokens) * 100, 1);
    $conversation_sort = $this->getConversationSort($request);
    $daily_rows = $this->usageRepository->fetchDailyRows($selected_uid);
    $admin_currency = $this->getAdminCurrency();
    $display_daily_rows = $this->convertDailyRowsForAdminCurrency($daily_rows);
    $model_rows = $this->usageRepository->fetchModelRows($selected_uid);
    $conversation_count = $this->usageRepository->countConversations($selected_uid);
    $this->pagerManager->createPager($conversation_count, self::USAGE_CONVERSATIONS_PAGE_SIZE);
    $current_page = $this->pagerManager->findPage();
    $conversation_items = $this->usageRepository->fetchConversationSummaries(
      $selected_uid,
      self::USAGE_CONVERSATIONS_PAGE_SIZE,
      $current_page * self::USAGE_CONVERSATIONS_PAGE_SIZE,
      $conversation_sort['field'],
      $conversation_sort['direction'],
    );

    $conversation_rows = [];
    foreach ($conversation_items as $conversation) {
      $conversation_rows[] = [
        $this->dateFormatter->format((int) $conversation['created'], 'short'),
        $this->dateFormatter->format((int) $conversation['last_iteration_created'], 'short'),
        $user_options[(int) $conversation['uid']] ?? $this->t('Usuario @uid', ['@uid' => $conversation['uid']]),
        $conversation['models'] ?: $this->t('No disponible'),
        $this->formatUsageNumber((int) $conversation['iteration_count']),
        $this->formatUsageNumber((int) $conversation['provider_calls']),
        $this->formatUsageNumber((int) $conversation['total_tokens']),
        $this->formatUsageMoney((string) $conversation['currency'], (float) $conversation['total_cost_usd']),
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Ver detalle'),
            '#url' => Url::fromRoute('trotamundos_assistant.usage_detail', ['conversation_id' => $conversation['conversation_id']]),
            '#attributes' => ['class' => ['button', 'button--small', 'trotamundos-assistant-usage__detail-button']],
          ],
        ],
      ];
    }

    $model_table_rows = [];
    foreach ($model_rows as $row) {
      $model_table_rows[] = [
        $row['provider_id'],
        $row['model_id'],
        $this->formatUsageNumber((int) $row['provider_calls']),
        $this->formatUsageNumber((int) $row['input_tokens']),
        $this->formatUsageNumber((int) $row['output_tokens']),
        $this->formatUsageNumber((int) $row['total_tokens']),
        $this->formatUsageMoney('USD', (float) $row['total_cost']),
      ];
    }

    return [
      '#attached' => [
        'library' => $this->getAdminLibraries(),
      ],
      '#prefix' => '<div class="trotamundos-assistant-usage">',
      '#suffix' => '</div>',
      'nav' => $this->buildAdminPills('usage'),
      'filters' => $this->buildUsageFilters($selected_uid, $user_options),
      'cards' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__cards']],
        'total_cost' => $this->buildUsageCard($this->t('Coste estimado total'), $this->formatUsageMoney('USD', (float) $stats['total_cost'])),
        'total_conversations' => $this->buildUsageCard($this->t('Conversaciones totales'), $this->formatUsageNumber($conversation_count)),
        'total_iterations' => $this->buildUsageCard($this->t('Iteraciones totales'), $this->formatUsageNumber((int) $stats['total_iterations'])),
        'provider_calls' => $this->buildUsageCard($this->t('Llamadas al proveedor'), $this->formatUsageNumber((int) $stats['provider_calls'])),
        'total_tokens' => $this->buildUsageCard($this->t('Tokens totales'), $this->formatUsageNumber((int) $stats['total_tokens'])),
        'average_cost' => $this->buildUsageCard($this->t('Coste medio por llamada'), $this->formatUsageMoney('USD', (float) $stats['average_cost'])),
      ],
      'chart_panel' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__panel']],
        'title' => [
          '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Coste diario') . '</h2>',
        ],
        'chart' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-usage__chart']],
          'graph' => $daily_rows ? [
            '#type' => 'inline_template',
            '#template' => '{{ chart|raw }}',
            '#context' => [
              'chart' => $this->usageChartBuilder->buildDailyUsageChart($display_daily_rows, $admin_currency),
            ],
          ] : [
            '#markup' => '<p>' . $this->t('Todavía no se ha registrado uso.') . '</p>',
          ],
        ],
      ],
      'latest_panel' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__panel']],
        'title' => [
          '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Últimas Conversaciones') . '</h2>',
        ],
        'table' => [
          '#type' => 'table',
          '#header' => $this->buildConversationTableHeader($conversation_sort, $selected_uid),
          '#rows' => $conversation_rows,
          '#empty' => $this->t('Todavía no se ha registrado uso.'),
        ],
        'pager' => [
          '#type' => 'pager',
        ],
      ],
      'model_panel' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__panel']],
        'title' => [
          '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Coste por modelo') . '</h2>',
        ],
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Proveedor'),
            $this->t('Modelo'),
            $this->t('Llamadas'),
            $this->t('Entrada'),
            $this->t('Salida'),
            $this->t('Total'),
            $this->t('Coste'),
          ],
          '#rows' => $model_table_rows,
          '#empty' => $this->t('Todavía no se ha registrado uso.'),
        ],
      ],
      'tokens_panel' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__panel', 'trotamundos-assistant-usage-tokens']],
        'title' => [
          '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Distribución total de tokens') . '</h2>',
        ],
        'summary' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-usage-tokens__summary']],
          'input' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['trotamundos-assistant-usage-tokens__metric', 'is-input']],
            'label' => [
              '#markup' => '<span class="trotamundos-assistant-usage-tokens__label">' . $this->t('Entrada') . '</span>',
            ],
            'value' => [
              '#markup' => '<span class="trotamundos-assistant-usage-tokens__value">' . $this->formatUsageNumber($input_tokens) . '</span>',
            ],
            'ratio' => [
              '#markup' => '<span class="trotamundos-assistant-usage-tokens__ratio">' . $this->t('@ratio%', ['@ratio' => $this->formatUsageNumber($input_ratio, 1)]) . '</span>',
            ],
          ],
          'output' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['trotamundos-assistant-usage-tokens__metric', 'is-output']],
            'label' => [
              '#markup' => '<span class="trotamundos-assistant-usage-tokens__label">' . $this->t('Salida') . '</span>',
            ],
            'value' => [
              '#markup' => '<span class="trotamundos-assistant-usage-tokens__value">' . $this->formatUsageNumber($output_tokens) . '</span>',
            ],
            'ratio' => [
              '#markup' => '<span class="trotamundos-assistant-usage-tokens__ratio">' . $this->t('@ratio%', ['@ratio' => $this->formatUsageNumber($output_ratio, 1)]) . '</span>',
            ],
          ],
          'total' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['trotamundos-assistant-usage-tokens__metric', 'is-total']],
            'label' => [
              '#markup' => '<span class="trotamundos-assistant-usage-tokens__label">' . $this->t('Total') . '</span>',
            ],
            'value' => [
              '#markup' => '<span class="trotamundos-assistant-usage-tokens__value">' . $this->formatUsageNumber((int) $stats['total_tokens']) . '</span>',
            ],
          ],
        ],
        'bar' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-usage-tokens__bar']],
          'input' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#attributes' => [
              'class' => ['trotamundos-assistant-usage-tokens__bar-input'],
              'style' => 'width: ' . $input_ratio . '%',
            ],
          ],
          'output' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#attributes' => [
              'class' => ['trotamundos-assistant-usage-tokens__bar-output'],
              'style' => 'width: ' . $output_ratio . '%',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Builds the statistics administration page.
   */
  public function statistics(Request $request): array {
    $selected_uid = $this->getSelectedUsageUid($request);
    $user_options = $this->getUsageUserOptions();
    $skill_definitions = $this->skillMatcher->loadDefinitions();
    $valid_skill_ids = array_unique(array_merge(
      array_keys($skill_definitions),
      array_map(static fn(array $skill): string => (string) ($skill['machine_name'] ?? ''), $skill_definitions),
    ));
    $skill_rows = $this->usageRepository->fetchSkillUsageRows($selected_uid, $valid_skill_ids);
    $total_uses = array_sum(array_map(static fn(array $row): int => (int) $row['usage_count'], $skill_rows));
    $top_skill = $skill_rows[0] ?? NULL;
    $chart_rows = [];
    $table_rows = [];

    foreach ($skill_rows as $row) {
      $machine_name = (string) $row['machine_name'];
      $skill = $this->skillMatcher->getByReference($machine_name);
      $label = (string) ($skill['title'] ?? $machine_name);
      $usage_count = (int) $row['usage_count'];
      $percentage = $total_uses > 0 ? ($usage_count / $total_uses) * 100 : 0;

      $chart_rows[] = [
        'machine_name' => $machine_name,
        'label' => $label,
        'value' => $usage_count,
      ];
      $table_rows[] = [
        $label,
        $machine_name,
        $this->formatUsageNumber($usage_count),
        $this->formatUsageNumber($percentage, 1) . '%',
      ];
    }

    $top_skill_label = $top_skill
      ? (string) ($this->skillMatcher->getByReference((string) $top_skill['machine_name'])['title'] ?? $top_skill['machine_name'])
      : $this->t('No disponible');

    return [
      '#attached' => [
        'library' => $this->getAdminLibraries(),
      ],
      '#prefix' => '<div class="trotamundos-assistant-usage trotamundos-assistant-statistics">',
      '#suffix' => '</div>',
      'nav' => $this->buildAdminPills('statistics'),
      'filters' => $this->buildUsageFilters($selected_uid, $user_options, 'trotamundos_assistant.statistics'),
      'cards' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__cards']],
        'total_uses' => $this->buildUsageCard($this->t('Usos de skills'), $this->formatUsageNumber($total_uses)),
        'distinct_skills' => $this->buildUsageCard($this->t('Skills utilizadas'), $this->formatUsageNumber(count($skill_rows))),
        'top_skill' => $this->buildUsageCard($this->t('Skill más utilizada'), (string) $top_skill_label),
      ],
      'chart_panel' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__panel']],
        'title' => [
          '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Skills más utilizadas') . '</h2>',
        ],
        'description' => [
          '#markup' => '<p class="trotamundos-assistant-usage__panel-description">' . $this->t('Cada eje representa una skill seleccionada por la IA. El área indica el volumen relativo de uso.') . '</p>',
        ],
        'chart' => $chart_rows ? [
          '#type' => 'inline_template',
          '#template' => '{{ chart|raw }}',
          '#context' => [
            'chart' => $this->usageChartBuilder->buildSkillUsageRadarChart($chart_rows),
          ],
        ] : [
          '#markup' => '<p>' . $this->t('Todavía no se han registrado skills utilizadas.') . '</p>',
        ],
      ],
      'table_panel' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__panel']],
        'title' => [
          '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Ranking de skills') . '</h2>',
        ],
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Skill'),
            $this->t('Machine name'),
            $this->t('Usos'),
            $this->t('Peso'),
          ],
          '#rows' => $table_rows,
          '#empty' => $this->t('Todavía no se han registrado skills utilizadas.'),
          '#attributes' => ['class' => ['trotamundos-assistant-usage__table']],
        ],
      ],
    ];
  }

  /**
   * Builds the usage detail page for a full assistant conversation.
   */
  public function usageDetail(string $conversation_id): array {
    $is_call_detail = str_starts_with($conversation_id, 'call:');
    $is_provider_call_detail = str_starts_with($conversation_id, 'usage:');
    $lookup_id = $is_call_detail ? substr($conversation_id, 5) : $conversation_id;
    $lookup_id = $is_provider_call_detail ? substr($conversation_id, 6) : $lookup_id;

    $rows = $this->usageRepository->fetchDetailRows($lookup_id, $is_call_detail, $is_provider_call_detail);
    if ($rows === []) {
      throw new NotFoundHttpException();
    }

    $first = $rows[0];
    $user_options = $this->getUsageUserOptions();
    if ($is_provider_call_detail) {
      // Enrich a single provider row with the phases and reconstructed tool
      // calls of its parent interaction. This makes legacy streamed requests
      // explicit instead of presenting the interaction's final answer as the
      // raw response of every internal request.
      $call_rows = $this->usageRepository->fetchDetailRows((string) $first['call_id'], TRUE, FALSE);
      $call_detail = $this->usageReportBuilder->buildConversationDetail($call_rows);
      foreach ($call_detail['turns'] as $turn) {
        foreach ($turn['provider_rows'] as $provider_row) {
          if ((int) ($provider_row['id'] ?? 0) === (int) ($first['id'] ?? 0)) {
            $first = $provider_row;
            break 2;
          }
        }
      }

      return $this->buildProviderCallDetailPage($first, $user_options);
    }

    $detail = $this->usageReportBuilder->buildConversationDetail($rows);
    $summary = $detail['summary'];
    $provider_ids = $detail['provider_ids'];
    $model_ids = $detail['model_ids'];
    $call_ids = $detail['call_ids'];

    $turn_build = [];
    foreach ($detail['turns'] as $index => $turn) {
      $turn_meta = $this->t('@date · @calls peticiones internas · @tokens tokens · @cost', [
        '@date' => $this->dateFormatter->format((int) $turn['created'], 'short'),
        '@calls' => $this->formatUsageNumber((int) $turn['provider_calls']),
        '@tokens' => $this->formatUsageNumber((int) $turn['total_tokens']),
        '@cost' => $this->formatUsageMoney((string) $turn['currency'], (float) $turn['total_cost_usd']),
      ]);

      $turn_build['turn_' . $index] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__turn']],
        'title' => [
          '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Interacción @number', ['@number' => $turn['iteration_number']]) . '</h2>',
        ],
        'meta' => [
          '#markup' => '<p class="trotamundos-assistant-usage-detail__turn-meta">' . $turn_meta . '</p>',
        ],
        'conversation' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__conversation']],
          'request' => $this->buildUsageCodeBlock($this->t('Consulta del usuario'), (string) $turn['request_text']),
          'response' => $this->buildUsageCodeBlock(
            $this->t('Respuesta estructurada'),
            $this->formatUsageJson((string) $turn['response_text'])
          ),
        ],
        'timeline' => $this->buildUsageExecutionTimeline($turn['provider_rows']),
      ];
    }

    $summary_cards = [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage__cards']],
      'interactions' => $this->buildUsageCard($this->t('Interacciones'), $this->formatUsageNumber(count($detail['turns']))),
      'provider_calls' => $this->buildUsageCard($this->t('Llamadas al proveedor'), $this->formatUsageNumber((int) $summary['provider_calls'])),
      'total_tokens' => $this->buildUsageCard($this->t('Tokens totales'), $this->formatUsageNumber((int) $summary['total_tokens'])),
      'total_cost' => $this->buildUsageCard($this->t('Coste estimado'), $this->formatUsageMoney('USD', (float) $summary['total_cost_usd'])),
    ];
    if ((int) $summary['cached_tokens'] > 0) {
      $summary_cards['cached_tokens'] = $this->buildUsageCard($this->t('Tokens cacheados'), $this->formatUsageNumber((int) $summary['cached_tokens']));
    }

    return [
      '#attached' => [
        'library' => $this->getAdminLibraries(),
      ],
      '#prefix' => '<div class="trotamundos-assistant-usage trotamundos-assistant-usage-detail">',
      '#suffix' => '</div>',
      'nav' => $this->buildAdminPills('usage'),
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Volver a Últimas Conversaciones'),
        '#url' => Url::fromRoute('trotamundos_assistant.usage'),
        '#attributes' => ['class' => ['button']],
      ],
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__panel', 'trotamundos-assistant-usage-detail__hero']],
        'copy' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__hero-copy']],
          'eyebrow' => [
            '#markup' => '<p class="trotamundos-assistant-usage-detail__eyebrow">' . $this->t('Conversación') . '</p>',
          ],
          'title' => [
            '#markup' => '<h2 class="trotamundos-assistant-usage-detail__title">' . ($is_call_detail ? $this->t('Detalle de llamada') : $this->t('Detalle de conversación')) . '</h2>',
          ],
          'description' => [
            '#markup' => '<p class="trotamundos-assistant-usage-detail__description">' . $this->t('Vista completa del hilo, sus interacciones y las llamadas internas realizadas al proveedor.') . '</p>',
          ],
        ],
        'meta' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__meta-grid']],
          'identifier' => $this->buildUsageMetaItem(
            $is_call_detail ? $this->t('Call ID') : $this->t('Thread ID'),
            $lookup_id,
            TRUE,
          ),
          'date' => $this->buildUsageMetaItem($this->t('Fecha'), $this->dateFormatter->format((int) $first['created'], 'short')),
          'user' => $this->buildUsageMetaItem($this->t('Usuario'), $user_options[(int) $first['uid']] ?? $this->t('Usuario @uid', ['@uid' => $first['uid']])),
          'assistant' => $this->buildUsageMetaItem($this->t('Assistant'), $first['assistant_id'] ?: $this->t('No disponible')),
          'providers' => $this->buildUsageMetaItem($this->t('Proveedores'), implode(', ', $provider_ids) ?: $this->t('No disponible')),
          'models' => $this->buildUsageMetaItem($this->t('Modelos'), implode(', ', $model_ids) ?: $this->t('No disponible')),
        ],
        'call_ids' => [
          '#type' => 'inline_template',
          '#template' => '<details class="trotamundos-assistant-usage-detail__technical"><summary>{{ title }}</summary><pre>{{ call_ids }}</pre></details>',
          '#context' => [
            'title' => $this->t('@count Call IDs técnicos', [
              '@count' => $this->formatUsageNumber(count($call_ids)),
            ]),
            'call_ids' => implode("\n", $call_ids),
          ],
        ],
      ],
      'cards' => $summary_cards,
      'turns' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__turns']],
        'items' => $turn_build,
      ],
    ];
  }

  /**
   * Builds the detail page for one internal provider request.
   *
   * @param array<string, mixed> $row
   *   Usage row.
   * @param array<int, string|\Stringable> $user_options
   *   User options keyed by uid.
   */
  private function buildProviderCallDetailPage(array $row, array $user_options): array {
    $conversation_id = trim((string) ($row['thread_id'] ?? ''));
    $back_route_id = $conversation_id !== '' ? $conversation_id : 'call:' . (string) ($row['call_id'] ?? '');
    $iteration_number = (int) ($row['iteration_number'] ?? 0);
    $phase = $this->getUsageExecutionPhasePresentation($row);

    return [
      '#attached' => [
        'library' => $this->getAdminLibraries(),
      ],
      '#prefix' => '<div class="trotamundos-assistant-usage trotamundos-assistant-usage-detail">',
      '#suffix' => '</div>',
      'nav' => $this->buildAdminPills('usage'),
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Volver a la conversación'),
        '#url' => Url::fromRoute('trotamundos_assistant.usage_detail', ['conversation_id' => $back_route_id]),
        '#attributes' => ['class' => ['button']],
      ],
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__panel', 'trotamundos-assistant-usage-detail__hero']],
        'copy' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__hero-copy']],
          'eyebrow' => [
            '#markup' => '<p class="trotamundos-assistant-usage-detail__eyebrow">' . $phase['label'] . '</p>',
          ],
          'title' => [
            '#markup' => '<h2 class="trotamundos-assistant-usage-detail__title">' . $phase['title'] . '</h2>',
          ],
          'description' => [
            '#markup' => '<p class="trotamundos-assistant-usage-detail__description">' . $phase['description'] . '</p>',
          ],
        ],
        'meta' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__meta-grid']],
          'id' => $this->buildUsageMetaItem($this->t('ID'), (string) $row['id'], TRUE),
          'request_id' => $this->buildUsageMetaItem($this->t('Request ID'), $row['provider_request_id'] ?: $this->t('No disponible'), TRUE),
          'iteration' => $this->buildUsageMetaItem($this->t('Iteración'), $iteration_number > 0 ? (string) $iteration_number : $this->t('No disponible')),
          'date' => $this->buildUsageMetaItem($this->t('Fecha'), $this->dateFormatter->format((int) $row['created'], 'short')),
          'user' => $this->buildUsageMetaItem($this->t('Usuario'), $user_options[(int) $row['uid']] ?? $this->t('Usuario @uid', ['@uid' => $row['uid']])),
          'call_id' => $this->buildUsageMetaItem($this->t('Call ID'), $row['call_id'] ?: $this->t('No disponible'), TRUE),
          'provider' => $this->buildUsageMetaItem($this->t('Proveedor'), $row['provider_id'] ?: $this->t('No disponible')),
          'model' => $this->buildUsageMetaItem($this->t('Modelo'), $row['model_id'] ?: $this->t('No disponible')),
          'operation' => $this->buildUsageMetaItem($this->t('Operación'), $row['operation_type'] ?: $this->t('No disponible')),
          'phase' => $this->buildUsageMetaItem($this->t('Fase'), $phase['label']),
        ],
      ],
      'cards' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__cards']],
        'input_tokens' => $this->buildUsageCard($this->t('Tokens entrada'), $this->formatUsageNumber((int) ($row['input_tokens'] ?? 0))),
        'output_tokens' => $this->buildUsageCard($this->t('Tokens salida'), $this->formatUsageNumber((int) ($row['output_tokens'] ?? 0))),
        'total_tokens' => $this->buildUsageCard($this->t('Tokens totales'), $this->formatUsageNumber((int) ($row['total_tokens'] ?? 0))),
        'total_cost' => $this->buildUsageCard($this->t('Coste estimado'), $this->formatUsageMoney((string) ($row['currency'] ?: $this->defaults->getPricingCurrency()), (float) ($row['total_cost_usd'] ?? 0))),
      ],
      'payloads' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__conversation']],
        'system_prompt' => $this->buildUsageCodeBlock(
          $this->t('Prompt de sistema enviado (skills incluidas)'),
          (string) ($row['provider_system_prompt'] ?? ''),
        ),
        'request' => $this->buildUsageCodeBlock($this->t('Envío bruto'), (string) ($row['provider_request_raw'] ?? '')),
        'response' => $this->buildProviderResponseAudit($row),
      ],
    ];
  }

  /**
   * Builds a compact metadata item for the conversation detail header.
   */
  private function buildUsageMetaItem(string|\Stringable $label, string|\Stringable $value, bool $monospace = FALSE): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__meta-item']],
      'label' => [
        '#markup' => '<span class="trotamundos-assistant-usage-detail__meta-label">' . $label . '</span>',
      ],
      'value' => [
        '#markup' => '<span class="trotamundos-assistant-usage-detail__meta-value' . ($monospace ? ' is-code' : '') . '">' . Html::escape((string) $value) . '</span>',
      ],
    ];
  }

  /**
   * Builds a human-readable execution timeline for one assistant turn.
   *
   * @param array<int, array<string, mixed>> $provider_rows
   *   Provider calls from one interaction, in chronological order.
   */
  private function buildUsageExecutionTimeline(array $provider_rows): array {
    if ($provider_rows === []) {
      return [];
    }

    $items = [];
    foreach ($provider_rows as $index => $row) {
      $phase = $this->getUsageExecutionPhasePresentation($row);
      $meta = $this->t('@provider · @model · @tokens tokens · @cost', [
        '@provider' => $row['provider_id'] ?: $this->t('Proveedor no disponible'),
        '@model' => $row['model_id'] ?: $this->t('Modelo no disponible'),
        '@tokens' => $this->formatUsageNumber((int) ($row['total_tokens'] ?? 0)),
        '@cost' => $this->formatUsageMoney((string) ($row['currency'] ?: $this->defaults->getPricingCurrency()), (float) ($row['total_cost_usd'] ?? 0)),
      ]);

      $item = [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__timeline-item', 'is-' . $phase['class']]],
        'marker' => [
          '#markup' => '<span class="trotamundos-assistant-usage-detail__timeline-marker">' . ($index + 1) . '</span>',
        ],
        'content' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__timeline-content']],
          'phase' => [
            '#markup' => '<span class="trotamundos-assistant-usage-detail__phase-badge is-' . $phase['class'] . '">' . Html::escape((string) $phase['label']) . '</span>',
          ],
          'title' => [
            '#markup' => '<h3 class="trotamundos-assistant-usage-detail__timeline-title">' . Html::escape((string) $phase['title']) . '</h3>',
          ],
          'description' => [
            '#markup' => '<p class="trotamundos-assistant-usage-detail__timeline-description">' . Html::escape((string) $phase['description']) . '</p>',
          ],
          'meta' => [
            '#markup' => '<p class="trotamundos-assistant-usage-detail__timeline-meta">' . Html::escape((string) $meta) . '</p>',
          ],
          'detail' => [
            '#type' => 'link',
            '#title' => $this->t('Ver datos técnicos'),
            '#url' => Url::fromRoute('trotamundos_assistant.usage_detail', ['conversation_id' => 'usage:' . $row['id']]),
            '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__timeline-link']],
          ],
        ],
      ];
      if ($phase['evidence'] !== '') {
        $item['content']['evidence'] = [
          '#markup' => '<p class="trotamundos-assistant-usage-detail__timeline-evidence">' . Html::escape((string) $phase['evidence']) . '</p>',
        ];
      }

      $items['step_' . $index] = $item;
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__timeline']],
      'title' => [
        '#markup' => '<h3 class="trotamundos-assistant-usage-detail__timeline-heading">' . $this->t('Recorrido de la interacción') . '</h3>',
      ],
      'intro' => [
        '#markup' => '<p class="trotamundos-assistant-usage-detail__timeline-intro">' . $this->t('Esta secuencia distingue la selección de herramientas, la recuperación de contexto y la respuesta visible para el usuario.') . '</p>',
      ],
      'items' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__timeline-items']],
        'steps' => $items,
      ],
    ];
  }

  /**
   * Gets the display copy for a provider execution phase.
   *
   * @param array<string, mixed> $row
   *   Enriched provider usage row.
   *
   * @return array{class: string, label: string|\Stringable, title: string|\Stringable, description: string|\Stringable, evidence: string|\Stringable}
   *   Presentation metadata.
   */
  private function getUsageExecutionPhasePresentation(array $row): array {
    $phase = (string) ($row['execution_phase'] ?? 'provider_operation');
    $tool_summary = $this->getUsageToolCallSummary($row);
    $tool_calls = is_array($row['execution_tool_calls'] ?? NULL) ? $row['execution_tool_calls'] : [];
    $evidence = '';
    if (($row['execution_evidence'] ?? '') === 'reconstructed') {
      $evidence = $this->t('La herramienta se ha reconstruido del siguiente mensaje del agente; no es un raw nativo del stream histórico.');
    }
    elseif (($row['execution_evidence'] ?? '') === 'captured') {
      $evidence = $this->t('Herramienta capturada en la salida de esta petición.');
    }

    return match ($phase) {
      'tool_selection' => [
        'class' => 'tool-selection',
        'label' => $this->containsUsageRagTool($tool_calls) ? $this->t('Decisión RAG') : $this->t('Selección de herramienta'),
        'title' => $this->containsUsageRagTool($tool_calls) ? $this->t('El agente solicita una búsqueda RAG') : $this->t('El agente solicita una herramienta'),
        'description' => $tool_summary !== ''
          ? $this->t('Herramienta solicitada: @tool.', ['@tool' => $tool_summary])
          : $this->t('El modelo solicita una herramienta antes de redactar la respuesta.'),
        'evidence' => $evidence,
      ],
      'retrieval_decision' => [
        'class' => 'retrieval-decision',
        'label' => $this->t('Decisión de recuperación'),
        'title' => $this->t('El agente pasa a recuperar contexto'),
        'description' => $this->t('Una llamada posterior de embeddings indica que el modelo necesitó consultar conocimiento antes de responder.'),
        'evidence' => $evidence,
      ],
      'embedding' => [
        'class' => 'embedding',
        'label' => $this->t('Recuperación semántica'),
        'title' => $this->t('Se prepara la consulta vectorial'),
        'description' => $this->t('Se genera el embedding de la consulta para recuperar contexto relevante del índice.'),
        'evidence' => '',
      ],
      'final_answer' => [
        'class' => 'final-answer',
        'label' => $this->t('Respuesta final'),
        'title' => $this->t('El modelo compone la respuesta final'),
        'description' => $this->t('Con el contexto disponible, el modelo genera la respuesta estructurada que recibe el usuario.'),
        'evidence' => '',
      ],
      'model_step' => [
        'class' => 'model-step',
        'label' => $this->t('Paso del modelo'),
        'title' => $this->t('El modelo continúa el razonamiento'),
        'description' => $this->t('Esta llamada forma parte de la ejecución interna previa a la respuesta final.'),
        'evidence' => '',
      ],
      default => [
        'class' => 'provider-operation',
        'label' => $this->t('Operación interna'),
        'title' => $this->t('Se ejecuta una operación del proveedor'),
        'description' => $this->t('Esta llamada complementa la ejecución del asistente.'),
        'evidence' => '',
      ],
    };
  }

  /**
   * Builds a concise, user-readable list of requested tools and their query.
   *
   * @param array<string, mixed> $row
   *   Enriched provider usage row.
   */
  private function getUsageToolCallSummary(array $row): string {
    $tool_calls = is_array($row['execution_tool_calls'] ?? NULL) ? $row['execution_tool_calls'] : [];
    $summaries = [];
    foreach ($tool_calls as $tool_call) {
      if (!is_array($tool_call) || empty($tool_call['name'])) {
        continue;
      }

      $summary = (string) $tool_call['name'];
      $excerpt = $this->getUsageToolArgumentExcerpt($tool_call['arguments'] ?? NULL);
      if ($excerpt !== '') {
        $summary .= ' — “' . $excerpt . '”';
      }
      $summaries[] = $summary;
    }

    return implode(' · ', $summaries);
  }

  /**
   * Extracts the most useful search text from one tool-call argument payload.
   */
  private function getUsageToolArgumentExcerpt(mixed $arguments): string {
    if (is_string($arguments)) {
      $decoded = json_decode($arguments, TRUE);
      if (json_last_error() === JSON_ERROR_NONE) {
        $arguments = $decoded;
      }
    }

    if (!is_array($arguments)) {
      return is_scalar($arguments) ? $this->truncateUsageText((string) $arguments) : '';
    }

    foreach (['search_string', 'query', 'q', 'input', 'prompt'] as $key) {
      if (isset($arguments[$key]) && is_scalar($arguments[$key])) {
        return $this->truncateUsageText((string) $arguments[$key]);
      }
    }

    return '';
  }

  /**
   * Limits a technical value so a timeline card stays readable.
   */
  private function truncateUsageText(string $text, int $limit = 140): string {
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : $text;
  }

  /**
   * Determines whether a set of tools is related to retrieval/RAG.
   *
   * @param array<int, mixed> $tool_calls
   *   Tool call data.
   */
  private function containsUsageRagTool(array $tool_calls): bool {
    foreach ($tool_calls as $tool_call) {
      if (!is_array($tool_call)) {
        continue;
      }
      $name = mb_strtolower((string) ($tool_call['name'] ?? ''));
      if (str_contains($name, 'rag') || str_contains($name, 'search') || str_contains($name, 'retriev')) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Builds the correct response panel for a provider call audit.
   *
   * Older rows stored the interaction-level final response as a fallback for
   * every stream wrapper. Do not render that value as a false provider raw.
   *
   * @param array<string, mixed> $row
   *   Enriched provider usage row.
   */
  private function buildProviderResponseAudit(array $row): array {
    $raw_response = (string) ($row['provider_response_raw'] ?? '');
    $source = $this->getUsageAuditSource($raw_response);
    if ($source === 'assistant_response_text_fallback') {
      $phase = $this->getUsageExecutionPhasePresentation($row);
      $build = [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__audit']],
        'notice' => $this->buildUsageAuditNotice(
          $this->t('Salida original del stream no disponible'),
          $this->t('Este registro histórico guardó la respuesta final de la interacción como fallback. No representa la salida de esta fase.'),
        ),
      ];
      if (($row['execution_phase'] ?? '') === 'final_answer' && !empty($row['response_text'])) {
        $build['response'] = $this->buildUsageCodeBlock(
          $this->t('Respuesta final normalizada'),
          $this->formatUsageJson((string) $row['response_text']),
        );
      }
      else {
        $build['phase'] = [
          '#markup' => '<p class="trotamundos-assistant-usage-detail__audit-phase">' . Html::escape((string) $this->t('La fase identificada para esta petición es: @phase.', ['@phase' => $phase['label']])) . '</p>',
        ];
      }

      return $build;
    }

    $title = $source === 'streamed_provider_response'
      ? $this->t('Salida auditada del streaming')
      : $this->t('Respuesta bruta del proveedor');

    return $this->buildUsageCodeBlock(
      $title,
      $this->formatUsageJson($raw_response),
    );
  }

  /**
   * Builds a visible warning when a provider output cannot be shown honestly.
   */
  private function buildUsageAuditNotice(string|\Stringable $title, string|\Stringable $text): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage-detail__audit-notice']],
      'title' => [
        '#markup' => '<h3 class="trotamundos-assistant-usage-detail__audit-title">' . Html::escape((string) $title) . '</h3>',
      ],
      'text' => [
        '#markup' => '<p class="trotamundos-assistant-usage-detail__audit-text">' . Html::escape((string) $text) . '</p>',
      ],
    ];
  }

  /**
   * Gets the source marker from a JSON audit payload.
   */
  private function getUsageAuditSource(string $payload): string {
    $decoded = json_decode(trim($payload), TRUE);
    if (is_string($decoded)) {
      $decoded = json_decode(trim($decoded), TRUE);
    }

    return is_array($decoded) ? (string) ($decoded['_source'] ?? '') : '';
  }

  /**
   * Gets the administration libraries for module pages.
   *
   * @return array<int, string>
   *   Library names.
   */
  private function getAdminLibraries(): array {
    $libraries = ['trotamundos_assistant/usage_admin'];
    if ($this->configFactoryService
      ->get('trotamundos_assistant.settings')
      ->get('use_minsait_font') !== FALSE) {
      $libraries[] = 'trotamundos_assistant/font';
    }

    return $libraries;
  }

  /**
   * Builds a usage summary card.
   */
  private function buildUsageCard(string|\Stringable $label, string $value): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage__card']],
      'label' => [
        '#markup' => '<p class="trotamundos-assistant-usage__card-label">' . $label . '</p>',
      ],
      'value' => [
        '#markup' => '<p class="trotamundos-assistant-usage__card-value">' . $value . '</p>',
      ],
    ];
  }

  /**
   * Formats the status diagnosis intro message.
   */
  private function formatStatusDiagnosisMessage(int $failed_count): \Stringable|string {
    return $this->formatPlural(
      $failed_count,
      'Hay 1 punto pendiente. Revisa el diagnóstico y la tarjeta marcada en rojo.',
      'Hay @count puntos pendientes. Revisa el diagnóstico y las tarjetas marcadas en rojo.',
    );
  }

  /**
   * Builds a short diagnosis list from failed dependency checks.
   *
   * @param array<int, array{label: \Stringable|string, message: \Stringable|string}> $failed_checks
   *   Failed dependency checks.
   *
   * @return array
   *   Render array.
   */
  private function buildStatusDiagnosisList(array $failed_checks): array {
    $items = [];

    foreach ($failed_checks as $check) {
      $label = (string) ($check['label'] ?? $this->t('Revisión pendiente'));
      $message = trim((string) ($check['message'] ?? ''));
      $items[] = [
        '#markup' => '<span class="trotamundos-assistant-status__diagnosis-label">' . Html::escape($label) . '</span>'
          . ($message !== '' ? '<span class="trotamundos-assistant-status__diagnosis-message">' . Html::escape($message) . '</span>' : ''),
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['trotamundos-assistant-status__diagnosis']],
    ];
  }

  /**
   * Builds assistant setup cards for the status page.
   *
   * @return array<string, array<string, mixed>>
   *   Render arrays keyed by card ID.
   */
  private function buildAssistantInfoCards(): array {
    $skill_definitions = $this->skillMatcher->loadDefinitions();
    $enabled_skill_count = count(array_filter($skill_definitions, static fn(array $skill): bool => !empty($skill['enabled'])));
    $total_skill_count = count($skill_definitions);
    $tool_counts = $this->getAssistantToolCounts();

    $data_cards = [
      'skills' => [
        'label' => $this->t('Skills'),
        'value' => $total_skill_count === 1
          ? $this->t('@enabled/@total skill disponible', [
            '@enabled' => $this->formatUsageNumber($enabled_skill_count),
            '@total' => $this->formatUsageNumber($total_skill_count),
          ])
          : $this->t('@enabled/@total skills disponibles', [
            '@enabled' => $this->formatUsageNumber($enabled_skill_count),
            '@total' => $this->formatUsageNumber($total_skill_count),
          ]),
        'description' => $this->t('Para generar una skill se define una carpeta con skill.yml, example.json, plantilla Twig y, si hace falta, un handler que prepare los datos del panel.'),
      ],
      'tools' => [
        'label' => $this->t('Herramientas'),
        'value' => $this->t('@enabled/@total tools disponibles', [
          '@enabled' => $this->formatUsageNumber($tool_counts['enabled']),
          '@total' => $this->formatUsageNumber($tool_counts['total']),
        ]),
        'description' => $this->t('Para generar una tool se crea un plugin de function call que el agente pueda invocar para consultar datos o ejecutar acciones durante el razonamiento.'),
      ],
    ];

    $cards = [];
    foreach ($data_cards as $key => $card) {
      $cards[$key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-flow__card']],
        'label' => [
          '#markup' => '<p class="trotamundos-assistant-flow__card-label">' . $card['label'] . '</p>',
        ],
        'value' => [
          '#markup' => '<p class="trotamundos-assistant-flow__card-value">' . $card['value'] . '</p>',
        ],
        'description' => [
          '#markup' => '<p class="trotamundos-assistant-flow__card-description">' . $card['description'] . '</p>',
        ],
      ];
    }

    return $cards;
  }

  /**
   * Counts tools enabled in the agent and existing in the system.
   *
   * @return array{enabled: int, total: int}
   *   Enabled and total tool counts.
   */
  private function getAssistantToolCounts(): array {
    $existing_tool_ids = array_keys($this->functionCallPluginManager->getDefinitions());
    $agent_config = $this->configFactoryService->get('ai_agents.ai_agent.' . $this->getAssistantId());
    if ($agent_config->isNew()) {
      return [
        'enabled' => 0,
        'total' => count($existing_tool_ids),
      ];
    }

    $tools = $agent_config->get('tools') ?: [];
    $tools = is_array($tools) ? $tools : [];
    $enabled_tools = array_intersect(
      array_keys(array_filter($tools, static fn(mixed $enabled): bool => (bool) $enabled)),
      $existing_tool_ids,
    );

    return [
      'enabled' => count($enabled_tools),
      'total' => count($existing_tool_ids),
    ];
  }

  /**
   * Builds sortable headers for the conversations table.
   *
   * @param array{field: string, direction: string} $current_sort
   *   Current sort configuration.
   * @param int|null $selected_uid
   *   Selected user ID filter, if any.
   *
   * @return array<int, mixed>
   *   Table header definitions.
   */
  private function buildConversationTableHeader(array $current_sort, ?int $selected_uid): array {
    $headers = [
      ['label' => $this->t('Fecha creación'), 'field' => 'created'],
      ['label' => $this->t('Última iteración'), 'field' => 'last_iteration_created'],
      ['label' => $this->t('Usuario'), 'field' => 'uid'],
      ['label' => $this->t('Modelos'), 'field' => 'models'],
      ['label' => 'Nº Iteraciones', 'field' => 'iteration_count'],
      ['label' => $this->t('Llamadas'), 'field' => 'provider_calls'],
      ['label' => 'Tokens', 'field' => 'total_tokens'],
      ['label' => $this->t('Coste'), 'field' => 'total_cost_usd'],
    ];

    $build = [];
    foreach ($headers as $header) {
      $field = $header['field'];
      $next_direction = $current_sort['field'] === $field && $current_sort['direction'] === 'ASC' ? 'DESC' : 'ASC';
      $classes = ['trotamundos-assistant-usage__sortable-header'];
      if ($current_sort['field'] === $field) {
        $classes[] = 'is-active';
        $classes[] = $current_sort['direction'] === 'ASC' ? 'is-asc' : 'is-desc';
      }

      $build[] = [
        'data' => [
          '#type' => 'link',
          '#title' => $header['label'],
          '#url' => Url::fromRoute('trotamundos_assistant.usage', [], [
            'query' => $this->buildConversationSortQuery($field, $next_direction, $selected_uid),
          ]),
          '#attributes' => [
            'class' => $classes,
          ],
        ],
      ];
    }

    $build[] = $this->t('Detalle');

    return $build;
  }

  /**
   * Formats a number for the module UI.
   */
  private function formatUsageNumber(float|int $value, int $decimals = 0): string {
    return number_format($value, $decimals, ',', '.');
  }

  /**
   * Formats a money value for the module UI.
   */
  private function formatUsageMoney(string $currency, float $value, int $decimals = 10): string {
    $admin_currency = $this->getAdminCurrency();
    $converted_value = $this->convertUsageMoney($currency, $value, $admin_currency);
    return $this->formatAdminCurrencyLabel($admin_currency) . ' ' . $this->formatUsageNumber($converted_value, $decimals);
  }

  /**
   * Returns the administration currency selected for usage pages.
   */
  private function getAdminCurrency(): string {
    $currency = strtoupper((string) ($this->configFactoryService
      ->get('trotamundos_assistant.settings')
      ->get('admin_currency') ?: $this->defaults->getAdminCurrency()));

    return in_array($currency, ['USD', 'EUR'], TRUE) ? $currency : $this->defaults->getAdminCurrency();
  }

  /**
   * Converts stored usage cost for visual administration display.
   */
  private function convertUsageMoney(string $source_currency, float $value, string $target_currency): float {
    $source_currency = strtoupper(trim($source_currency) ?: $this->defaults->getPricingCurrency());
    $target_currency = strtoupper($target_currency);

    if ($source_currency === $target_currency) {
      return $value;
    }
    if ($source_currency === 'USD' && $target_currency === 'EUR') {
      return $value * self::USD_TO_EUR_RATE;
    }
    if ($source_currency === 'EUR' && $target_currency === 'USD') {
      return $value / self::USD_TO_EUR_RATE;
    }

    return $value;
  }

  /**
   * Converts daily usage rows to the selected administration currency.
   *
   * @param array<int, array<string, mixed>> $daily_rows
   *   Daily usage rows in stored currency.
   *
   * @return array<int, array<string, mixed>>
   *   Daily rows converted for display.
   */
  private function convertDailyRowsForAdminCurrency(array $daily_rows): array {
    $admin_currency = $this->getAdminCurrency();
    foreach ($daily_rows as &$row) {
      if (is_array($row)) {
        $row['total_cost'] = $this->convertUsageMoney('USD', (float) ($row['total_cost'] ?? 0), $admin_currency);
      }
    }
    unset($row);

    return $daily_rows;
  }

  /**
   * Formats the configured administration currency.
   */
  private function formatAdminCurrencyLabel(string $currency): string {
    return strtoupper($currency) === 'EUR' ? '€' : 'USD';
  }

  /**
   * Gets the selected conversations table sort.
   *
   * @return array{field: string, direction: string}
   *   Sort field and direction.
   */
  private function getConversationSort(Request $request): array {
    $allowed_fields = [
      'created',
      'last_iteration_created',
      'uid',
      'models',
      'iteration_count',
      'provider_calls',
      'total_tokens',
      'total_cost_usd',
    ];
    $field = (string) $request->query->get('sort', 'last_iteration_created');
    $direction = strtoupper((string) $request->query->get('direction', 'DESC'));

    return [
      'field' => in_array($field, $allowed_fields, TRUE) ? $field : 'last_iteration_created',
      'direction' => $direction === 'ASC' ? 'ASC' : 'DESC',
    ];
  }

  /**
   * Builds query parameters for a conversation table sort link.
   *
   * @param string $field
   *   Field used for sorting.
   * @param string $direction
   *   Sort direction.
   * @param int|null $selected_uid
   *   Selected user ID filter, if any.
   *
   * @return array<string, mixed>
   *   Query parameters.
   */
  private function buildConversationSortQuery(string $field, string $direction, ?int $selected_uid): array {
    $query = [
      'sort' => $field,
      'direction' => $direction,
    ];
    if ($selected_uid !== NULL) {
      $query['uid'] = $selected_uid;
    }

    return $query;
  }

  /**
   * Builds a compact code-like snippet for the flow page.
   */
  private function buildFlowSnippet(string|\Stringable $title, string $content): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-flow__snippet']],
      'title' => [
        '#markup' => '<h3 class="trotamundos-assistant-flow__snippet-title">' . $title . '</h3>',
      ],
      'content' => [
        '#markup' => '<pre class="trotamundos-assistant-flow__snippet-pre">' . Html::escape($content) . '</pre>',
      ],
    ];
  }

  /**
   * Builds an inline code panel for conversation-level usage text.
   */
  private function buildUsageCodeBlock(string|\Stringable $title, string $text): array {
    $text = trim($text);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage__panel']],
      'title' => [
        '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $title . '</h2>',
      ],
      'content' => [
        '#markup' => $text === ''
          ? '<p>' . $this->t('No disponible') . '</p>'
          : '<pre class="trotamundos-assistant-usage-detail__pre">' . Html::escape($text) . '</pre>',
      ],
    ];
  }

  /**
   * Formats a JSON payload for the usage detail page.
   *
   * Provider implementations may return a JSON document as a string. The
   * request audit is already serialized as pretty JSON, so normalize the
   * response here before rendering it in the same code block.
   */
  private function formatUsageJson(string $text): string {
    $text = trim($text);
    if ($text === '') {
      return '';
    }

    $decoded = json_decode($text, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return $text;
    }

    // Handle providers that wrap a JSON document in a JSON string.
    if (is_string($decoded)) {
      $nested = json_decode(trim($decoded), TRUE);
      if (json_last_error() === JSON_ERROR_NONE) {
        $decoded = $nested;
      }
    }

    // Stream fallbacks keep the assistant response in a JSON-encoded
    // response_text property. Decode that property so it renders as nested
    // JSON instead of a long escaped string.
    if (is_array($decoded) && isset($decoded['response_text']) && is_string($decoded['response_text'])) {
      $nested_response = json_decode(trim($decoded['response_text']), TRUE);
      if (json_last_error() === JSON_ERROR_NONE) {
        $decoded['response_text'] = $nested_response;
      }
    }

    $formatted = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($formatted) ? $formatted : $text;
  }

  /**
   * Builds the usage filter form.
   *
   * @param int|null $selected_uid
   *   Currently selected user id, or NULL for all users.
   * @param array<int, string|\Stringable> $user_options
   *   Available user options keyed by uid.
   * @param string $route_name
   *   Route used by the filter form and reset link.
   */
  private function buildUsageFilters(?int $selected_uid, array $user_options, string $route_name = 'trotamundos_assistant.usage'): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage__panel']],
      'title' => [
        '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Filtros') . '</h2>',
      ],
      'form' => [
        '#type' => 'form',
        '#id' => 'trotamundos-assistant-usage-filter-form',
        '#method' => 'get',
        '#action' => Url::fromRoute($route_name)->toString(),
        '#attributes' => [
          'class' => ['trotamundos-assistant-usage__filter-form'],
        ],
        'user' => [
          '#type' => 'select',
          '#title' => $this->t('Usuario'),
          '#options' => ['' => $this->t('Todos los usuarios')] + $user_options,
          '#default_value' => $selected_uid ?? '',
          '#value' => $selected_uid ?? '',
          '#name' => 'uid',
          '#attributes' => [
            'id' => 'trotamundos-assistant-usage-user',
          ],
        ],
        'submit' => [
          '#type' => 'submit',
          '#value' => $this->t('Filtrar'),
          '#attributes' => [
            'class' => ['button', 'button--primary', 'trotamundos-assistant-usage__filter-button'],
          ],
        ],
        'reset' => [
          '#type' => 'link',
          '#title' => $this->t('Limpiar'),
          '#url' => Url::fromRoute($route_name),
          '#attributes' => [
            'class' => ['button', 'trotamundos-assistant-usage__filter-button'],
          ],
        ],
      ],
    ];
  }

  /**
   * Gets the selected usage uid from the request.
   */
  private function getSelectedUsageUid(Request $request): ?int {
    $uid = $request->query->get('uid');
    if ($uid === NULL || $uid === '') {
      return NULL;
    }

    return is_numeric($uid) && (int) $uid >= 0 ? (int) $uid : NULL;
  }

  /**
   * Gets users with recorded usage.
   *
   * @return array<int, string|\Stringable>
   *   User labels keyed by uid.
   */
  private function getUsageUserOptions(): array {
    $options = [];
    $account_uids = [];
    foreach ($this->usageRepository->fetchUsageUserIds() as $uid) {
      $uid = (int) $uid;
      if ($uid === 0) {
        $options[$uid] = $this->t('Anónimo');
        continue;
      }
      $account_uids[] = $uid;
    }

    if ($account_uids) {
      $accounts = $this->entityTypeManagerService
        ->getStorage('user')
        ->loadMultiple($account_uids);

      foreach ($account_uids as $uid) {
        $account = $accounts[$uid] ?? NULL;
        $options[$uid] = $account
          ? $this->t('@name (UID @uid)', [
            '@name' => $account->label(),
            '@uid' => $uid,
          ])
          : $this->t('Usuario @uid', ['@uid' => $uid]);
      }
    }

    ksort($options);

    return $options;
  }

  /**
   * Builds the clickable title for a dependency check.
   *
   * @param array{label: \Stringable|string, url?: \Drupal\Core\Url, help?: \Stringable|string} $check
   *   The dependency check definition.
   * @param int $index
   *   The dependency check list index.
   *
   * @return array<string, mixed>
   *   A render array for the title area.
   */
  private function buildDependencyCheckTitle(array $check, int $index): array {
    if (isset($check['url']) && $check['url'] instanceof Url) {
      return [
        '#type' => 'link',
        '#title' => $check['label'],
        '#url' => $check['url'],
        '#attributes' => [
          'class' => [
            'trotamundos-assistant-usage__dependency-title',
            'trotamundos-assistant-usage__dependency-link',
          ],
          'target' => '_blank',
          'rel' => 'noopener noreferrer',
          'title' => $this->t('Abrir configuración en una pestaña nueva'),
        ],
      ];
    }

    if (empty($check['help'])) {
      return [
        '#markup' => '<p class="trotamundos-assistant-usage__dependency-title">' . Html::escape((string) $check['label']) . '</p>',
      ];
    }

    $payload_id = Html::getUniqueId('trotamundos-assistant-dependency-help-' . $index);

    return [
      'button' => [
        '#type' => 'button',
        '#value' => $check['label'],
        '#attributes' => [
          'class' => [
            'trotamundos-assistant-usage__dependency-title',
            'trotamundos-assistant-usage__dependency-link',
          ],
          'data-trotamundos-assistant-usage-modal' => $payload_id,
          'data-trotamundos-assistant-modal-title' => $check['label'],
        ],
      ],
      'payload' => [
        '#markup' => '<textarea id="' . Html::escape($payload_id) . '" hidden>' . Html::escape((string) $check['help']) . '</textarea>',
      ],
    ];
  }

  /**
   * Builds a dependency check card.
   *
   * @param array{label: \Stringable|string, message: \Stringable|string, purpose: \Stringable|string, status: bool, url?: \Drupal\Core\Url, help?: \Stringable|string, details?: array<int, array<string, mixed>>, wide?: bool} $check
   *   The dependency check definition.
   * @param int $index
   *   The dependency check list index.
   *
   * @return array
   *   Render array.
   */
  private function buildDependencyCheckItem(array $check, int $index): array {
    $dependency_classes = [
      'trotamundos-assistant-usage__dependency',
      $check['status'] ? 'is-ok' : 'is-error',
    ];
    if (!empty($check['wide'])) {
      $dependency_classes[] = 'is-wide';
    }
    if (!empty($check['details'])) {
      $dependency_classes[] = 'has-details';
    }

    $content = [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage__dependency-content']],
      'title' => $this->buildDependencyCheckTitle($check, $index),
    ];

    if (trim((string) $check['message']) !== '') {
      $content['description'] = [
        '#markup' => '<p class="trotamundos-assistant-usage__dependency-description">' . $check['message'] . '</p>',
      ];
    }

    $content['purpose'] = [
      '#markup' => '<p class="trotamundos-assistant-usage__dependency-purpose">' . $check['purpose'] . '</p>',
    ];
    $content['details'] = !empty($check['details']) ? $this->buildDependencyCheckDetails($check['details']) : [];

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => $dependency_classes,
      ],
      'icon' => [
        '#markup' => '<span class="trotamundos-assistant-usage__dependency-icon" aria-hidden="true">' . ($check['status'] ? '&#10003;' : '!') . '</span>',
      ],
      'content' => $content,
    ];
  }

  /**
   * Builds the configuration cards shown inside the catalog index panel.
   *
   * @param array<string, array> $configuration_items
   *   Dependency check cards keyed by render item name.
   *
   * @return array
   *   Render array.
   */
  private function buildCatalogIndexConfigurationSection(array $configuration_items): array {
    if ($configuration_items === []) {
      return [];
    }

    $items = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'trotamundos-assistant-usage__dependency-section-items',
          'trotamundos-assistant-usage__index-configuration-items',
        ],
      ],
    ];

    foreach ($configuration_items as $key => $configuration_item) {
      $items[$key] = $configuration_item;
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage__index-configuration']],
      'title' => [
        '#markup' => '<h3 class="trotamundos-assistant-usage__subsection-title">' . $this->t('Configuración conectada') . '</h3>',
      ],
      'items' => $items,
    ];
  }

  /**
   * Builds the detailed metadata grid for a dependency check.
   *
   * @param array<int, array<string, mixed>> $details
   *   Detail items.
   *
   * @return array
   *   Render array.
   */
  private function buildDependencyCheckDetails(array $details): array {
    $items = [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage__dependency-details']],
    ];

    foreach ($details as $index => $detail) {
      if (!empty($detail['group']) && !empty($detail['details']) && is_array($detail['details'])) {
        $items['detail_' . $index] = $this->buildDependencyCheckDetailGroup($detail);
        continue;
      }

      $value = !empty($detail['items']) && is_array($detail['items'])
        ? $this->buildDependencyCheckDetailLines($detail['items'])
        : $this->buildDependencyCheckDetailValue($detail);

      $items['detail_' . $index] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__dependency-detail']],
        'label' => [
          '#markup' => '<span class="trotamundos-assistant-usage__dependency-detail-label">' . Html::escape((string) $detail['label']) . '</span>',
        ],
        'value' => $value,
      ];
    }

    return $items;
  }

  /**
   * Builds an internal detail group inside a dependency card.
   *
   * @param array{label: \Stringable|string, details: array<int, array<string, mixed>>} $group
   *   Group definition.
   *
   * @return array
   *   Render array.
   */
  private function buildDependencyCheckDetailGroup(array $group): array {
    $items = [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage__dependency-detail-group-items']],
    ];

    foreach ($group['details'] as $index => $detail) {
      $value = !empty($detail['items']) && is_array($detail['items'])
        ? $this->buildDependencyCheckDetailLines($detail['items'])
        : $this->buildDependencyCheckDetailValue($detail);

      $items['detail_' . $index] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__dependency-detail']],
        'label' => [
          '#markup' => '<span class="trotamundos-assistant-usage__dependency-detail-label">' . Html::escape((string) ($detail['label'] ?? '')) . '</span>',
        ],
        'value' => $value,
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage__dependency-detail-group']],
      'title' => [
        '#markup' => '<h4 class="trotamundos-assistant-usage__dependency-detail-group-title">' . Html::escape((string) $group['label']) . '</h4>',
      ],
      'items' => $items,
    ];
  }

  /**
   * Builds a detail value, optionally with contextual help.
   *
   * @param array<string, mixed> $detail
   *   Detail definition.
   *
   * @return array
   *   Render array.
   */
  private function buildDependencyCheckDetailValue(array $detail): array {
    $value = (string) ($detail['value'] ?? '');
    $help = trim((string) ($detail['help'] ?? ''));

    if ($help === '') {
      return [
        '#markup' => '<span class="trotamundos-assistant-usage__dependency-detail-value">' . Html::escape($value) . '</span>',
      ];
    }

    $label = (string) ($detail['label'] ?? '');
    $aria_label = $this->t('Qué significa @label', ['@label' => $label]);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage__dependency-detail-lines']],
      'line' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__dependency-detail-line']],
        'info' => [
          '#markup' => $this->buildDependencyInfoTooltipMarkup((string) $aria_label, $help),
        ],
        'value' => [
          '#markup' => '<span class="trotamundos-assistant-usage__dependency-detail-line-value">' . Html::escape($value) . '</span>',
        ],
      ],
    ];
  }

  /**
   * Builds structured detail lines with hover help.
   *
   * @param array<int, array{label: string, value: string, help: \Stringable|string}> $lines
   *   Detail lines.
   *
   * @return array
   *   Render array.
   */
  private function buildDependencyCheckDetailLines(array $lines): array {
    $items = [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage__dependency-detail-lines']],
    ];

    foreach ($lines as $index => $line) {
      $label = (string) ($line['label'] ?? '');
      $help = (string) ($line['help'] ?? '');
      $value = (string) ($line['value'] ?? '');
      $aria_label = $this->t('Qué hace @parameter', ['@parameter' => $label]);

      $items['line_' . $index] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['trotamundos-assistant-usage__dependency-detail-line']],
        'info' => [
          '#markup' => $this->buildDependencyInfoTooltipMarkup((string) $aria_label, $help),
        ],
        'value' => [
          '#markup' => '<span class="trotamundos-assistant-usage__dependency-detail-line-value"><span class="trotamundos-assistant-usage__dependency-detail-line-key">' . Html::escape($label) . ':</span> ' . Html::escape($value) . '</span>',
        ],
      ];
    }

    return $items;
  }

  /**
   * Builds the info icon tooltip markup.
   */
  private function buildDependencyInfoTooltipMarkup(string $aria_label, string $help): string {
    return '<span class="trotamundos-assistant-usage__dependency-info" role="button" tabindex="0" aria-label="' . Html::escape($aria_label) . '">'
      . '<span class="trotamundos-assistant-usage__dependency-info-icon" aria-hidden="true">i</span>'
      . '<span class="trotamundos-assistant-usage__dependency-tooltip" role="tooltip">' . $this->buildDependencyTooltipContentMarkup($help) . '</span>'
      . '</span>';
  }

  /**
   * Builds tooltip content and separates examples into a visual paragraph.
   */
  private function buildDependencyTooltipContentMarkup(string $help): string {
    $help = trim($help);
    $example_marker = 'Ejemplo:';
    $example_position = mb_strpos($help, $example_marker);

    if ($example_position === FALSE) {
      return '<span class="trotamundos-assistant-usage__dependency-tooltip-text">' . Html::escape($help) . '</span>';
    }

    $text = trim(mb_substr($help, 0, $example_position));
    $example = trim(mb_substr($help, $example_position));

    return '<span class="trotamundos-assistant-usage__dependency-tooltip-text">' . Html::escape($text) . '</span>'
      . '<span class="trotamundos-assistant-usage__dependency-tooltip-example">' . Html::escape($example) . '</span>';
  }

  /**
   * Gets the configured assistant id.
   */
  private function getAssistantId(): string {
    $assistant_id = trim((string) $this->configFactoryService
      ->get('trotamundos_assistant.settings')
      ->get('assistant_id'));

    return $assistant_id ?: $this->defaults->getAssistantId();
  }

}
