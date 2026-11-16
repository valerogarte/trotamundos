<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Admin;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\key\KeyRepositoryInterface;
use Drupal\trotamundos_assistant\Plugin\AiFunctionCall\SkillAwareRagTool;

/**
 * Builds dependency status definitions for Trotamundos Assistant admin pages.
 */
final class DependencyStatusBuilder {

  use StringTranslationTrait;

  /**
   * Modules required by the RAG recipe used by the block.
   */
  private const REQUIRED_MODULES = [
    'key' => 'Key',
    'search_api' => 'Search API',
    'ai' => 'AI',
    'ai_provider_openai' => 'AI Provider OpenAI',
    'ai_vdb_provider_postgres' => 'AI VDB Provider Postgres',
    'ai_search' => 'AI Search',
    'ai_agents' => 'AI Agents',
    'ai_assistant_api' => 'AI Assistant API',
    'ai_chatbot' => 'AI Chatbot',
  ];

  /**
   * Constructs the dependency status builder.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyRepositoryInterface $keyRepository,
    private readonly Connection $database,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds dependency checks shown in the administration overview.
   *
   * @return array<int, array{section: string, section_label: \Stringable|string, label: \Stringable|string, message: \Stringable|string, purpose: \Stringable|string, status: bool, url?: \Drupal\Core\Url, help?: \Stringable|string, details?: array<int, array<string, mixed>>, wide?: bool}>
   *   Dependency check definitions.
   */
  public function buildChecks(): array {
    $checks = [];

    foreach (self::REQUIRED_MODULES as $module => $label) {
      $enabled = $this->moduleHandler->moduleExists($module);
      $checks[] = [
        'section' => 'modules',
        'section_label' => $this->t('Módulos'),
        'label' => $this->t('Módulo: @module', ['@module' => $label]),
        'status' => $enabled,
        'url' => Url::fromRoute('system.modules_list'),
        'purpose' => $this->buildModulePurpose($module),
        'message' => $enabled
          ? ''
          : $this->t('Falta este módulo. Instala las dependencias de la receta con Composer y activa la receta: ddev composer require -W drupal/ai drupal/ai_agents drupal/ai_provider_openai drupal/ai_vdb_provider_postgres drupal/key drupal/search_api && ddev drush recipe recipes/minsait_chatbot_pgvector_openai'),
      ];
    }

    foreach ($this->getRequiredConfig() as $config_name => $label) {
      $exists = !$this->configFactory->get($config_name)->isNew();
      $section = $this->buildConfigurationSection($config_name);
      $checks[] = [
        'section' => $section,
        'section_label' => $this->buildConfigurationSectionLabel($section),
        'label' => $label,
        'status' => $exists,
        ...$this->buildConfigurationCheckAccess($config_name),
        'purpose' => $this->buildConfigurationPurpose($config_name),
        'details' => $exists ? $this->buildConfigurationDetails($config_name) : [],
        'wide' => $exists && $this->isFeaturedConfiguration($config_name),
        'message' => $exists
          ? ''
          : $this->t('No existe esta configuración. Ejecuta la receta: ddev drush recipe recipes/minsait_chatbot_pgvector_openai && ddev drush cr'),
      ];
    }

    $checks[] = [
      'section' => 'credentials',
      'section_label' => $this->t('Credenciales'),
      'label' => $this->t('Variable de entorno OPENAI_API_KEY'),
      'status' => $this->keyHasValue('openai'),
      'url' => Url::fromRoute('entity.key.edit_form', ['key' => 'openai']),
      'purpose' => $this->t('Permite al proveedor OpenAI generar respuestas, embeddings y llamadas del asistente.'),
      'message' => $this->keyHasValue('openai')
        ? $this->t('Drupal puede leer la key de OpenAI.')
        : $this->t('No se puede leer la key openai. Define OPENAI_API_KEY en .ddev/config.local.yaml o en el entorno del servidor y reinicia DDEV/PHP.'),
    ];

    $checks[] = [
      'section' => 'credentials',
      'section_label' => $this->t('Credenciales'),
      'label' => $this->t('Variable de entorno PGVECTOR_PASSWORD'),
      'status' => $this->keyHasValue('pgvector_password'),
      'url' => Url::fromRoute('entity.key.edit_form', ['key' => 'pgvector_password']),
      'purpose' => $this->t('Permite conectar con el Postgres local que guarda y consulta los vectores del catálogo.'),
      'message' => $this->keyHasValue('pgvector_password')
        ? $this->t('Drupal puede leer el password de pgvector.')
        : $this->t('No se puede leer la key pgvector_password. Define PGVECTOR_PASSWORD en .ddev/config.local.yaml o en el entorno del servidor y reinicia DDEV/PHP.'),
    ];

    $checks[] = [
      'section' => 'database',
      'section_label' => $this->t('Base de datos'),
      'label' => $this->t('Tabla de coste trotamundos_assistant_usage'),
      'status' => $this->database->schema()->tableExists('trotamundos_assistant_usage'),
      'purpose' => $this->t('Guarda llamadas al proveedor, tokens, coste estimado, usuario, payload bruto y respuesta bruta para auditoría.'),
      'message' => $this->database->schema()->tableExists('trotamundos_assistant_usage')
        ? $this->t('La tabla trotamundos_assistant_usage está creada.')
        : $this->t('La tabla trotamundos_assistant_usage no existe.'),
    ];

    return $checks;
  }

  /**
   * Builds the section key for a configuration check.
   */
  private function buildConfigurationSection(string $config_name): string {
    return in_array($config_name, [
      'ai_search.index.minsait_nodes_ai',
      'search_api.index.minsait_nodes_ai',
      'search_api.server.minsait_nodes_ai',
    ], TRUE) ? 'catalog_index' : 'configuration';
  }

  /**
   * Builds the visible section label for a configuration check.
   */
  private function buildConfigurationSectionLabel(string $section): \Stringable|string {
    return $section === 'catalog_index'
      ? $this->t('Configuración de búsqueda')
      : $this->t('Configuración');
  }

  /**
   * Builds the module purpose text shown in the status page.
   */
  private function buildModulePurpose(string $module): \Stringable|string {
    return match ($module) {
      'key' => $this->t('Almacena y resuelve credenciales como OpenAI y pgvector sin dejarlas en código.'),
      'search_api' => $this->t('Define el índice del catálogo y coordina qué entidades y campos se indexan.'),
      'ai' => $this->t('Aporta la capa base de proveedores, modelos y operaciones de IA.'),
      'ai_provider_openai' => $this->t('Conecta Drupal con los modelos de OpenAI usados por el asistente.'),
      'ai_vdb_provider_postgres' => $this->t('Conecta AI Search con Postgres/pgvector como base de datos vectorial local.'),
      'ai_search' => $this->t('Genera embeddings y habilita búsquedas RAG sobre el índice Search API.'),
      'ai_agents' => $this->t('Define el agente que decide cómo usar herramientas y contexto.'),
      'ai_assistant_api' => $this->t('Ejecuta el assistant conversacional reutilizado por el bloque embebido.'),
      'ai_chatbot' => $this->t('Aporta piezas base del ecosistema chatbot del módulo AI.'),
      default => $this->t('Dependencia necesaria para el funcionamiento del módulo.'),
    };
  }

  /**
   * Builds the configuration purpose text shown in the status page.
   */
  private function buildConfigurationPurpose(string $config_name): \Stringable|string {
    $agent_config_name = 'ai_agents.ai_agent.' . $this->getAssistantId();
    $assistant_config_name = 'ai_assistant_api.ai_assistant.' . $this->getAssistantId();

    return match ($config_name) {
      $agent_config_name => $this->t('Guarda las instrucciones del agente, herramientas disponibles y reglas para devolver JSON compatible con las skills.'),
      $assistant_config_name => $this->t('Configura el assistant que recibe los mensajes del bloque y mantiene el flujo conversacional.'),
      'ai_search.index.minsait_nodes_ai' => $this->t('Indica qué campos son contenido principal, contexto o atributos para embeddings y metadata.'),
      'search_api.index.minsait_nodes_ai' => $this->t('Define entidades de catálogo, variaciones y campos que se preparan para pgvector.'),
      'search_api.server.minsait_nodes_ai' => $this->t('Configura el backend vectorial, colección y conexión usada para indexar y consultar.'),
      default => $this->t('Configuración requerida por la receta del módulo.'),
    };
  }

  /**
   * Builds useful configuration data for expanded status cards.
   *
   * @return array<int, array{label: \Stringable|string, value: \Stringable|string}>
   *   Detail items shown inside the dependency card.
   */
  private function buildConfigurationDetails(string $config_name): array {
    $assistant_id = $this->getAssistantId();
    $assistant_config_name = 'ai_assistant_api.ai_assistant.' . $assistant_id;
    $agent_config_name = 'ai_agents.ai_agent.' . $assistant_id;

    return match ($config_name) {
      $assistant_config_name => $this->buildAssistantConfigurationDetails($config_name),
      $agent_config_name => $this->buildAgentConfigurationDetails($config_name),
      'search_api.server.minsait_nodes_ai' => $this->buildSearchApiServerConfigurationDetails($config_name),
      default => [],
    };
  }

  /**
   * Builds detailed metadata for the configured AI Assistant.
   *
   * @return array<int, array{label: \Stringable|string, value: \Stringable|string}>
   *   Detail items shown inside the dependency card.
   */
  private function buildAssistantConfigurationDetails(string $config_name): array {
    $config = $this->configFactory->get($config_name);
    $llm_configuration = $config->get('llm_configuration') ?: [];

    return [
      [
        'label' => $this->t('ID'),
        'value' => (string) ($config->get('id') ?: $this->getAssistantId()),
      ],
      [
        'label' => $this->t('Etiqueta'),
        'value' => (string) ($config->get('label') ?: $this->t('Sin etiqueta')),
      ],
      [
        'label' => $this->t('Descripción'),
        'value' => (string) ($config->get('description') ?: $this->t('Sin descripción')),
      ],
      [
        'label' => $this->t('Modelo'),
        'value' => $this->t('@provider / @model', [
          '@provider' => $config->get('llm_provider') ?: $this->t('Sin proveedor'),
          '@model' => $config->get('llm_model') ?: $this->t('Sin modelo'),
        ]),
        'help' => $this->t('Proveedor y modelo que ejecutan la conversación. Ejemplo: openai / gpt-5.4-mini significa que Drupal llama al proveedor OpenAI usando ese modelo.'),
      ],
      $this->buildLlmConfigurationDetail($llm_configuration),
      [
        'label' => $this->t('Historial'),
        'value' => $this->t('@mode; @length mensajes de contexto', [
          '@mode' => $config->get('allow_history') ?: $this->t('Sin historial'),
          '@length' => $config->get('history_context_length') ?: '0',
        ]),
        'help' => $this->t('Define cómo se conserva la conversación y cuántos mensajes previos se pasan como contexto. Ejemplo: session; 8 mensajes permite continuar el hilo usando el thread_id del navegador.'),
      ],
      [
        'label' => $this->t('Agente enlazado'),
        'value' => (string) ($config->get('ai_agent') ?: $this->t('No configurado')),
        'help' => $this->t('Agente al que delega el Assistant durante process() cuando el módulo AI Agents está activo. En este flujo es quien decide usar RAG y herramientas.'),
      ],
      [
        'label' => $this->t('Actions directas'),
        'value' => $this->formatEnabledKeys($config->get('actions_enabled') ?: [], $this->t('Sin actions directas')),
        'help' => $this->t('Actions clásicas configuradas directamente en el Assistant. En este caso pueden estar vacías porque el RAG se gestiona desde el agente enlazado.'),
      ],
      [
        'label' => $this->t('Function calling'),
        'value' => $this->formatBoolean((bool) $config->get('use_function_calling')),
        'help' => $this->t('Indica si el Assistant usa function calling en el flujo clásico de actions. En el flujo actual el razonamiento con herramientas lo lleva el Agent.'),
      ],
      [
        'label' => $this->t('Prompts propios'),
        'value' => $this->formatPromptState([
          'system_prompt' => $config->get('system_prompt'),
          'pre_action_prompt' => $config->get('pre_action_prompt'),
          'instructions' => $config->get('instructions'),
        ]),
        'help' => $this->t('Resume qué campos de prompt tienen contenido personalizado y su tamaño. Sirve para detectar si el comportamiento viene de instrucciones propias del Assistant.'),
      ],
      [
        'group' => TRUE,
        'label' => $this->t('RAG Action'),
        'details' => $this->buildAssistantRagDetails((string) ($config->get('ai_agent') ?: '')),
      ],
      [
        'group' => TRUE,
        'label' => $this->t('Mensajes de error'),
        'details' => $this->buildAssistantErrorDetails($config_name),
      ],
    ];
  }

  /**
   * Builds RAG action metadata shown in the assistant card.
   *
   * The AI Assistant form stores these values on the linked AI Agent.
   *
   * @return array<int, array{label: \Stringable|string, value: \Stringable|string}>
   *   RAG detail items.
   */
  private function buildAssistantRagDetails(string $agent_id): array {
    if ($agent_id === '') {
      return [
      [
        'label' => $this->t('RAG habilitado'),
        'value' => $this->t('No, sin agente enlazado'),
        'help' => $this->t('Indica si el Assistant tiene acceso a la búsqueda RAG a través de su agente. Sin agente enlazado no puede activar esta herramienta desde aquí.'),
      ],
      ];
    }

    $agent_config = $this->configFactory->get('ai_agents.ai_agent.' . $agent_id);
    if ($agent_config->isNew()) {
      return [
      [
        'label' => $this->t('RAG habilitado'),
        'value' => $this->t('No, agente no encontrado'),
        'help' => $this->t('Indica si el Assistant tiene acceso a la búsqueda RAG. Si el agente no existe, no se puede resolver la herramienta configurada.'),
      ],
      ];
    }

    $tools = $agent_config->get('tools') ?: [];
    $tool_limits = $agent_config->get('tool_usage_limits') ?: [];
    $legacy_rag_tool_id = 'ai_search:rag_search';
    $rag_tool_id = !empty($tools[SkillAwareRagTool::PLUGIN_ID])
      ? SkillAwareRagTool::PLUGIN_ID
      : $legacy_rag_tool_id;
    $rag_limits = is_array($tool_limits[$rag_tool_id] ?? NULL)
      ? $tool_limits[$rag_tool_id]
      : [];
    $index_id = $this->getToolLimitValue($rag_limits, 'index');
    $max_results = $this->getToolLimitValue($rag_limits, 'amount');
    $threshold = $this->getToolLimitValue($rag_limits, 'min_score');

    return [
      [
        'label' => $this->t('RAG habilitado'),
        'value' => $this->formatBoolean(!empty($tools[$rag_tool_id])),
        'help' => $this->t('Activa la herramienta trotamundos_assistant:skill_rag_search para que el agente consulte el índice solo cuando la skill elegida requiere RAG.'),
      ],
      [
        'label' => $this->t('RAG database'),
        'value' => $index_id !== ''
          ? $this->t('@label (@id)', [
            '@label' => $this->getSearchIndexLabel($index_id),
            '@id' => $index_id,
          ])
          : $this->t('No configurada'),
        'help' => $this->t('Índice Search API que usa la herramienta RAG. En este proyecto apunta al índice de nodos/productos sincronizado con pgvector.'),
      ],
      [
        'label' => $this->t('RAG threshold'),
        'value' => $threshold !== '' ? $threshold : $this->t('No configurado'),
        'help' => $this->t('Puntuación mínima de similitud para aceptar resultados RAG. Ejemplo: 0.25 permite recuperar resultados relacionados sin ser demasiado estricto.'),
      ],
      [
        'label' => $this->t('Max results'),
        'value' => $max_results !== '' ? $max_results : $this->t('No configurado'),
        'help' => $this->t('Número máximo de resultados que devuelve la búsqueda RAG al agente. Ejemplo: 10 da suficiente contexto sin saturar el prompt.'),
      ],
    ];
  }

  /**
   * Builds assistant error message metadata.
   *
   * @return array<int, array{label: \Stringable|string, value: \Stringable|string}>
   *   Error message detail items.
   */
  private function buildAssistantErrorDetails(string $config_name): array {
    $config = $this->configFactory->get($config_name);
    $details = [
      [
        'label' => $this->t('Mensaje por defecto'),
        'value' => (string) ($config->get('error_message') ?: $this->t('Sin mensaje configurado')),
        'help' => $this->t('Mensaje que recibe el usuario si el Assistant no puede generar respuesta por un error no especializado.'),
      ],
    ];
    $specific_messages = is_array($config->get('specific_error_messages'))
      ? $config->get('specific_error_messages')
      : [];
    $specific_labels = [
      'AiBadRequestException' => $this->t('Bad Request'),
      'AiRateLimitException' => $this->t('Rate Limit'),
      'AiQuotaException' => $this->t('Quota'),
      'AiSetupFailureException' => $this->t('Setup Failure'),
      'AiRequestErrorException' => $this->t('Request Error'),
    ];

    foreach ($specific_labels as $exception => $label) {
      $message = trim((string) ($specific_messages[$exception] ?? ''));
      $details[] = [
        'label' => $label,
        'value' => $message !== '' ? $message : $this->t('Sin mensaje específico configurado'),
        'help' => $this->t('Mensaje específico para @exception. Si está vacío, el Assistant usa el mensaje por defecto.', [
          '@exception' => $exception,
        ]),
      ];
    }

    return $details;
  }

  /**
   * Reads the first forced value from a tool usage limit.
   */
  private function getToolLimitValue(array $tool_limits, string $key): string {
    $values = $tool_limits[$key]['values'] ?? [];
    if (!is_array($values)) {
      $values = [$values];
    }

    return isset($values[0]) ? (string) $values[0] : '';
  }

  /**
   * Gets a readable Search API index label from config.
   */
  private function getSearchIndexLabel(string $index_id): string {
    $config = $this->configFactory->get('search_api.index.' . $index_id);
    $label = trim((string) ($config->get('name') ?: ''));

    return $label !== '' ? $label : $index_id;
  }

  /**
   * Builds detailed metadata for the Search API vector server.
   *
   * @return array<int, array<string, mixed>>
   *   Detail items shown inside the dependency card.
   */
  private function buildSearchApiServerConfigurationDetails(string $config_name): array {
    $config = $this->configFactory->get($config_name);
    $backend_config = $config->get('backend_config') ?: [];
    $backend_config = is_array($backend_config) ? $backend_config : [];
    $database_settings = is_array($backend_config['database_settings'] ?? NULL)
      ? $backend_config['database_settings']
      : [];
    $embedding_configuration = is_array($backend_config['embeddings_engine_configuration'] ?? NULL)
      ? $backend_config['embeddings_engine_configuration']
      : [];
    $strategy_configuration = is_array($backend_config['embedding_strategy_configuration'] ?? NULL)
      ? $backend_config['embedding_strategy_configuration']
      : [];

    return [
      [
        'label' => $this->t('ID'),
        'value' => (string) ($config->get('id') ?: 'minsait_nodes_ai'),
      ],
      [
        'label' => $this->t('Nombre'),
        'value' => (string) ($config->get('name') ?: $this->t('Sin nombre')),
      ],
      [
        'label' => $this->t('Descripción'),
        'value' => (string) ($config->get('description') ?: $this->t('Sin descripción')),
      ],
      [
        'label' => $this->t('Backend'),
        'value' => (string) ($config->get('backend') ?: $this->t('Sin backend')),
        'help' => $this->t('Plugin Search API que conecta el índice Drupal con la búsqueda vectorial. Ejemplo: search_api_ai_search usa AI Search como backend del servidor.'),
      ],
      [
        'label' => $this->t('Modelo contextual'),
        'value' => $this->formatProviderModel((string) ($backend_config['chat_model'] ?? '')),
        'help' => $this->t('Modelo usado por el backend para generar contexto durante la estrategia de indexación. Ejemplo: openai / gpt-5.4-mini puede ayudar a preparar chunks contextuales.'),
      ],
      [
        'group' => TRUE,
        'label' => $this->t('Base vectorial'),
        'details' => [
          [
            'label' => $this->t('Base de datos'),
            'value' => (string) ($backend_config['database'] ?? $this->t('No configurada')),
            'help' => $this->t('Proveedor vectorial donde se guardan y consultan los embeddings. Ejemplo: postgres almacena los vectores del catálogo en una tabla pgvector para búsquedas RAG.'),
          ],
          [
            'label' => $this->t('Database name'),
            'value' => (string) ($database_settings['database_name'] ?? $this->t('No configurada')),
            'help' => $this->t('Nombre lógico del entorno o base vectorial en el proveedor. Ejemplo: ecommerce separa este catálogo de otros posibles índices.'),
          ],
          [
            'label' => $this->t('Colección'),
            'value' => (string) ($database_settings['collection'] ?? $this->t('No configurada')),
            'help' => $this->t('Colección o namespace donde se escriben los vectores. Ejemplo: minsait_products agrupa los vectores usados para responder sobre productos.'),
          ],
          [
            'label' => $this->t('Métrica'),
            'value' => (string) ($database_settings['metric'] ?? $this->t('No configurada')),
            'help' => $this->t('Criterio de similitud usado al comparar el vector de la pregunta con los vectores indexados. Ejemplo: cosine_similarity mide cercanía semántica por orientación.'),
          ],
          [
            'label' => $this->t('Estrategia de índice'),
            'value' => (string) ($database_settings['vector_index_strategy'] ?? $this->t('No configurada')),
            'help' => $this->t('Tipo de índice vectorial usado por pgvector. Ejemplo: none usa búsqueda exacta; hnsw mejora rendimiento en colecciones grandes.'),
          ],
        ],
      ],
      [
        'group' => TRUE,
        'label' => $this->t('Embeddings'),
        'details' => [
          [
            'label' => $this->t('Motor'),
            'value' => $this->formatProviderModel((string) ($backend_config['embeddings_engine'] ?? '')),
            'help' => $this->t('Modelo que convierte texto del catálogo en vectores numéricos. Ejemplo: openai / text-embedding-3-small genera embeddings que se guardan en pgvector.'),
          ],
          [
            'label' => $this->t('Dimensiones'),
            'value' => (string) ($embedding_configuration['dimensions'] ?? $this->t('No configuradas')),
            'help' => $this->t('Tamaño del vector generado por el modelo de embeddings. Ejemplo: 1536 dimensiones deben ser compatibles con la tabla vectorial de pgvector.'),
          ],
          [
            'label' => $this->t('Fijar dimensiones'),
            'value' => $this->formatBoolean((bool) ($embedding_configuration['set_dimensions'] ?? FALSE)),
            'help' => $this->t('Indica si Drupal fuerza una dimensión concreta al proveedor de embeddings. Ejemplo: No deja que el modelo use su dimensión por defecto.'),
          ],
          [
            'label' => $this->t('Guardar vector bruto'),
            'value' => $this->formatBoolean((bool) ($backend_config['include_raw_embedding_vector'] ?? FALSE)),
            'help' => $this->t('Controla si el vector numérico completo se conserva también como dato bruto. Ejemplo: No evita cargar metadata con arrays grandes innecesarios.'),
          ],
        ],
      ],
      [
        'group' => TRUE,
        'label' => $this->t('Estrategia de chunks'),
        'details' => [
          [
            'label' => $this->t('Estrategia'),
            'value' => (string) ($backend_config['embedding_strategy'] ?? $this->t('No configurada')),
            'help' => $this->t('Define cómo se divide y contextualiza el contenido antes de generar embeddings. Ejemplo: contextual_chunks crea fragmentos con información de contexto.'),
          ],
          [
            'label' => $this->t('Tamaño chunk'),
            'value' => (string) ($strategy_configuration['chunk_size'] ?? $this->t('No configurado')),
            'help' => $this->t('Longitud objetivo de cada fragmento de contenido indexado. Ejemplo: 500 intenta mantener chunks manejables para búsqueda semántica.'),
          ],
          [
            'label' => $this->t('Solape mínimo'),
            'value' => (string) ($strategy_configuration['chunk_min_overlap'] ?? $this->t('No configurado')),
            'help' => $this->t('Cantidad mínima de contenido que se repite entre chunks consecutivos. Ejemplo: 100 ayuda a no perder contexto en cortes de texto.'),
          ],
          [
            'label' => $this->t('Porcentaje contexto'),
            'value' => (string) ($strategy_configuration['contextual_content_max_percentage'] ?? $this->t('No configurado')),
            'help' => $this->t('Porcentaje máximo del chunk reservado a contenido contextual. Ejemplo: 30 permite añadir contexto sin desplazar el contenido principal.'),
          ],
          [
            'label' => $this->t('Saltar moderación'),
            'value' => $this->formatBoolean((bool) ($strategy_configuration['skip_moderation'] ?? FALSE)),
            'help' => $this->t('Indica si se omiten controles de moderación durante la preparación del contenido. Ejemplo: No mantiene el flujo estándar de seguridad del proveedor.'),
          ],
        ],
      ],
    ];
  }

  /**
   * Builds detailed metadata for the configured AI Agent.
   *
   * @return array<int, array{label: \Stringable|string, value: \Stringable|string}>
   *   Detail items shown inside the dependency card.
   */
  private function buildAgentConfigurationDetails(string $config_name): array {
    $config = $this->configFactory->get($config_name);
    $tools = $config->get('tools') ?: [];
    $tool_settings = $config->get('tool_settings') ?: [];
    $tool_limits = $config->get('tool_usage_limits') ?: [];

    return [
      [
        'label' => $this->t('ID'),
        'value' => (string) ($config->get('id') ?: $this->getAssistantId()),
      ],
      [
        'label' => $this->t('Etiqueta'),
        'value' => (string) ($config->get('label') ?: $this->t('Sin etiqueta')),
      ],
      [
        'label' => $this->t('Descripción'),
        'value' => (string) ($config->get('description') ?: $this->t('Sin descripción')),
      ],
      [
        'group' => TRUE,
        'label' => $this->t('Ejecución'),
        'details' => [
          [
            'label' => $this->t('Tipo'),
            'value' => $this->t('Orquestador: @orchestration; triage: @triage', [
              '@orchestration' => $this->formatBoolean((bool) $config->get('orchestration_agent')),
              '@triage' => $this->formatBoolean((bool) $config->get('triage_agent')),
            ]),
            'help' => $this->t('Define el papel del agente. Orquestador significa que puede razonar y usar herramientas; triage se usa para clasificar o delegar hacia otros agentes.'),
          ],
          [
            'label' => $this->t('Límite de vueltas'),
            'value' => (string) ($config->get('max_loops') ?: $this->t('No configurado')),
            'help' => $this->t('Número máximo de ciclos de razonamiento/herramientas antes de detenerse. Evita bucles infinitos si una herramienta no resuelve la petición.'),
          ],
        ],
      ],
      [
        'group' => TRUE,
        'label' => $this->t('Tools'),
        'details' => [
          [
            'label' => $this->t('Tools activas'),
            'value' => $this->formatEnabledKeys($tools, $this->t('Sin tools activas')),
            'help' => $this->t('Herramientas que el agente puede invocar durante su razonamiento. Ejemplo: trotamundos_assistant:skill_rag_search consulta el índice semántico y conserva la skill seleccionada.'),
          ],
          [
            'label' => $this->t('Ajustes de tools'),
            'value' => $this->formatToolSettings($tool_settings),
            'help' => $this->t('Opciones de ejecución de cada herramienta: si devuelve directamente, si su uso es obligatorio o si genera artefactos.'),
          ],
          [
            'label' => $this->t('Descripción de tools'),
            'value' => $this->formatToolDescriptions($tool_settings),
            'help' => $this->t('Descripción que ve el modelo para decidir cuándo usar la herramienta. Es importante porque guía el razonamiento del agente.'),
          ],
          [
            'label' => $this->t('Límites de tool'),
            'value' => $this->formatToolUsageLimits($tool_limits),
            'help' => $this->t('Restricciones forzadas sobre parámetros de la herramienta. Ejemplo: fijar index=minsait_nodes_ai impide que el agente consulte otro índice.'),
          ],
        ],
      ],
      [
        'group' => TRUE,
        'label' => $this->t('Prompt'),
        'details' => [
          [
            'label' => $this->t('Prompt del agente'),
            'value' => $this->formatPromptState([
              'system_prompt' => $config->get('system_prompt'),
              'secured_system_prompt' => $config->get('secured_system_prompt'),
            ]),
            'help' => $this->t('Instrucciones principales del agente. Determinan cuándo debe usar RAG, qué no puede inventar y cómo debe devolver datos.'),
          ],
        ],
      ],
    ];
  }

  /**
   * Checks whether a configuration card should span the full grid.
   */
  private function isFeaturedConfiguration(string $config_name): bool {
    $assistant_id = $this->getAssistantId();

    return in_array($config_name, [
      'ai_assistant_api.ai_assistant.' . $assistant_id,
      'ai_agents.ai_agent.' . $assistant_id,
      'search_api.server.minsait_nodes_ai',
    ], TRUE);
  }

  /**
   * Formats a boolean value for admin details.
   */
  private function formatBoolean(bool $value): \Stringable|string {
    return $value ? $this->t('Sí') : $this->t('No');
  }

  /**
   * Formats provider/model IDs stored as provider__model.
   */
  private function formatProviderModel(string $model): \Stringable|string {
    $model = trim($model);
    if ($model === '') {
      return $this->t('No configurado');
    }

    $parts = explode('__', $model, 2);
    if (count($parts) !== 2) {
      return $model;
    }

    return $this->t('@provider / @model', [
      '@provider' => $parts[0],
      '@model' => $parts[1],
    ]);
  }

  /**
   * Formats enabled map keys.
   */
  private function formatEnabledKeys(array $values, \Stringable|string $empty): \Stringable|string {
    $enabled = array_keys(array_filter($values, static fn(mixed $value): bool => (bool) $value));

    return $enabled !== [] ? implode(', ', $enabled) : $empty;
  }

  /**
   * Formats model parameters.
   */
  private function buildLlmConfigurationDetail(array $configuration): array {
    $items = $this->buildLlmConfigurationItems($configuration);

    return [
      'label' => $this->t('Parámetros LLM'),
      'value' => $items === [] ? $this->t('Sin parámetros específicos') : '',
      'items' => $items,
    ];
  }

  /**
   * Builds model parameter lines with contextual help.
   *
   * @return array<int, array{label: string, value: string, help: \Stringable|string}>
   *   Parameter lines.
   */
  private function buildLlmConfigurationItems(array $configuration): array {
    $definitions = [
      'max_tokens' => [
        'label' => 'max_tokens',
        'help' => $this->t('Límite máximo de tokens que puede generar el modelo en la respuesta. Ejemplo: 4096 permite respuestas largas con JSON y datos de interfaz.'),
      ],
      'temperature' => [
        'label' => 'temperature',
        'help' => $this->t('Controla la aleatoriedad: 0 es más determinista; valores altos generan respuestas más variadas. Ejemplo: 0.1 ayuda a elegir skills y JSON de forma estable.'),
      ],
      'top_p' => [
        'label' => 'top_p',
        'help' => $this->t('Limita la generación al conjunto de tokens con probabilidad acumulada indicada. Ejemplo: 1 usa todo el rango disponible y deja que temperature controle la variación.'),
      ],
      'frequency_penalty' => [
        'label' => 'frequency_penalty',
        'help' => $this->t('Reduce la repetición de términos ya usados. Ejemplo: 0 evita penalizar repeticiones necesarias como IDs, nombres de campos o claves JSON.'),
      ],
      'presence_penalty' => [
        'label' => 'presence_penalty',
        'help' => $this->t('Incentiva introducir temas nuevos frente a repetir los ya presentes. Ejemplo: 0 mantiene la respuesta centrada en el catálogo y en los datos recuperados.'),
      ],
    ];
    $items = [];

    foreach ($definitions as $key => $definition) {
      if (array_key_exists($key, $configuration)) {
        $items[] = [
          'label' => $definition['label'],
          'value' => (string) $configuration[$key],
          'help' => $definition['help'],
        ];
      }
    }

    return $items;
  }

  /**
   * Formats prompt fields without dumping large prompt bodies into the card.
   */
  private function formatPromptState(array $prompts): \Stringable|string {
    $configured = [];

    foreach ($prompts as $key => $prompt) {
      $text = trim((string) $prompt);
      if ($text !== '') {
        $configured[] = $key . ' (' . mb_strlen($text) . ' caracteres)';
      }
    }

    return $configured !== [] ? implode('; ', $configured) : $this->t('Sin prompts propios configurados');
  }

  /**
   * Formats tool settings.
   */
  private function formatToolSettings(array $settings): \Stringable|string {
    $parts = [];

    foreach ($settings as $tool => $tool_settings) {
      if (!is_array($tool_settings)) {
        continue;
      }
      $setting_parts = [];
      foreach (['return_directly', 'require_usage', 'use_artifacts'] as $key) {
        if (array_key_exists($key, $tool_settings)) {
          $setting_parts[] = $key . ': ' . (string) $this->formatBoolean((bool) $tool_settings[$key]);
        }
      }
      $parts[] = $tool . ($setting_parts !== [] ? ' (' . implode('; ', $setting_parts) . ')' : '');
    }

    return $parts !== [] ? implode('; ', $parts) : $this->t('Sin ajustes específicos');
  }

  /**
   * Formats tool description overrides.
   */
  private function formatToolDescriptions(array $settings): \Stringable|string {
    $parts = [];

    foreach ($settings as $tool => $tool_settings) {
      if (!is_array($tool_settings)) {
        continue;
      }
      $description = trim((string) ($tool_settings['description_override'] ?? ''));
      if ($description !== '') {
        $parts[] = $tool . ': ' . $description;
      }
    }

    return $parts !== [] ? implode('; ', $parts) : $this->t('Sin descripciones override');
  }

  /**
   * Formats forced tool usage limits.
   */
  private function formatToolUsageLimits(array $limits): \Stringable|string {
    $parts = [];

    foreach ($limits as $tool => $tool_limits) {
      if (!is_array($tool_limits)) {
        continue;
      }
      $limit_parts = [];
      foreach ($tool_limits as $field => $rule) {
        if (!is_array($rule)) {
          continue;
        }
        $values = $rule['values'] ?? [];
        $values = is_array($values) ? $values : [$values];
        $values = implode(', ', array_map('strval', $values));
        $action = (string) ($rule['action'] ?? '');
        $limit_parts[] = $field . ': ' . $values . ($action !== '' ? ' (' . $action . ')' : '');
      }
      if ($limit_parts !== []) {
        $parts[] = $tool . ' -> ' . implode('; ', $limit_parts);
      }
    }

    return $parts !== [] ? implode('; ', $parts) : $this->t('Sin límites forzados');
  }

  /**
   * Builds the administration access data for a configuration check.
   */
  private function buildConfigurationCheckAccess(string $config_name): array {
    $assistant_id = $this->getAssistantId();
    $agent_config_name = 'ai_agents.ai_agent.' . $assistant_id;
    $assistant_config_name = 'ai_assistant_api.ai_assistant.' . $assistant_id;

    return match ($config_name) {
      $agent_config_name => [
        'url' => Url::fromRoute('entity.ai_agent.edit_form', [
          'ai_agent' => $assistant_id,
        ]),
      ],
      $assistant_config_name => [
        'url' => Url::fromRoute('entity.ai_assistant.edit_form', [
          'ai_assistant' => $assistant_id,
        ]),
      ],
      'ai_search.index.minsait_nodes_ai' => [
        'url' => Url::fromRoute('entity.search_api_index.fields', [
          'search_api_index' => 'minsait_nodes_ai',
        ]),
      ],
      'search_api.index.minsait_nodes_ai' => [
        'url' => Url::fromRoute('entity.search_api_index.canonical', [
          'search_api_index' => 'minsait_nodes_ai',
        ]),
      ],
      'search_api.server.minsait_nodes_ai' => [
        'url' => Url::fromRoute('entity.search_api_server.edit_form', [
          'search_api_server' => 'minsait_nodes_ai',
        ]),
      ],
      default => [
        'help' => $this->t('Esta configuración se gestiona desde sincronización de configuración. Revisa el fichero @name.yml y aplica la receta o importa configuración si falta.', [
          '@name' => $config_name,
        ]),
      ],
    };
  }

  /**
   * Gets configuration entities required by the RAG recipe used by the block.
   *
   * @return array<string, \Stringable|string>
   *   Required config names keyed by config object name.
   */
  private function getRequiredConfig(): array {
    $assistant_id = $this->getAssistantId();

    return [
      'ai_assistant_api.ai_assistant.' . $assistant_id => $this->t('Assistant'),
      'ai_agents.ai_agent.' . $assistant_id => $this->t('Agente'),
      'search_api.server.minsait_nodes_ai' => $this->t('Servidor Search API'),
      'ai_search.index.minsait_nodes_ai' => $this->t('Índice AI Search'),
      'search_api.index.minsait_nodes_ai' => $this->t('Índice Search API'),
    ];
  }

  /**
   * Gets the configured assistant id.
   */
  private function getAssistantId(): string {
    return trim((string) $this->configFactory
      ->get('trotamundos_assistant.settings')
      ->get('assistant_id'));
  }

  /**
   * Checks whether a Key entity can resolve a secret value.
   */
  private function keyHasValue(string $key_id): bool {
    try {
      $key = $this->keyRepository->getKey($key_id);
      return $key && trim((string) $key->getKeyValue()) !== '';
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

}
