<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Setup;

use Drupal\ai_assistant_api\Entity\AiAssistant;
use Drupal\ai_agents\Entity\AiAgent;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\trotamundos_assistant\Plugin\AiFunctionCall\SkillAwareRagTool;
use Drupal\trotamundos_assistant\Plugin\search_api\processor\TravelDocument;

/**
 * Creates the site-specific assistant without replacing existing configuration.
 */
final class TravelAssistantSetup {

  public const INDEX_ID = 'trotamundos_travel';
  public const SERVER_ID = 'trotamundos_qdrant';
  public const ASSISTANT_ID = 'trotamundos_travel';
  public const AGENT_ID = 'trotamundos_travel';

  public static function ensure(): void {
    if (!Server::load(self::SERVER_ID)) {
      Server::create([
        'id' => self::SERVER_ID,
        'name' => 'Qdrant - Trotamundos',
        'description' => 'Índice semántico del contenido público de viajes.',
        'status' => TRUE,
        'backend' => 'search_api_ai_search',
        'backend_config' => [
          'database' => 'qdrant',
          'database_settings' => [
            'database_name' => 'default',
            'collection' => 'juantrotamundos_public_travel_v1',
            'metric' => 'cosine_similarity',
          ],
          'embeddings_engine' => 'openai__text-embedding-3-small',
          'embeddings_engine_configuration' => ['set_dimensions' => TRUE, 'dimensions' => 1536],
          'embedding_strategy' => 'trotamundos_travel',
          'embedding_strategy_configuration' => [
            'chunk_size' => '800',
            'chunk_min_overlap' => '80',
            'contextual_content_max_percentage' => '30',
            'embedding_collection_size' => 20,
            'skip_moderation' => TRUE,
            'dimensions' => 1536,
          ],
          'chat_model' => 'openai__gpt-4o',
          'include_raw_embedding_vector' => FALSE,
        ],
      ])->save();
    }

    $index_settings = \Drupal::configFactory()->getEditable('ai_search.index.' . self::INDEX_ID);
    if ($index_settings->isNew()) {
      $index_settings
        ->set('indexing_options', [
          'travel_context' => ['indexing_option' => 'contextual_content', 'max' => -1],
          'travel_document' => ['indexing_option' => 'main_content', 'max' => -1],
        ])
        ->set('control_field_max_length', FALSE)
        ->set('exclude_chunk_from_metadata', FALSE)
        ->set('exclude_title', FALSE)
        ->save();
    }

    if (!Index::load(self::INDEX_ID)) {
      Index::create([
        'id' => self::INDEX_ID,
        'name' => 'Trotamundos - contenido público',
        'description' => 'Planes, destinos y guías publicados accesibles de forma anónima.',
        'status' => TRUE,
        'read_only' => FALSE,
        'server' => self::SERVER_ID,
        'datasource_settings' => [
          'entity:node' => [
            'bundles' => ['default' => FALSE, 'selected' => TravelDocument::BUNDLES],
            'languages' => ['default' => TRUE, 'selected' => []],
          ],
        ],
        'field_settings' => [
          'travel_context' => [
            'label' => 'Identidad del contenido',
            'property_path' => 'travel_context',
            'type' => 'text',
          ],
          'travel_document' => [
            'label' => 'Contenido público de viaje',
            'property_path' => 'travel_document',
            'type' => 'text',
          ],
        ],
        'processor_settings' => [
          'entity_status' => [],
          'trotamundos_assistant_travel_document' => [],
        ],
        'tracker_settings' => ['default' => ['indexing_order' => 'fifo']],
        // Indexing is enabled explicitly after the external provider is ready.
        'options' => ['cron_limit' => 0, 'index_directly' => FALSE],
      ])->save();
    }

    if (!AiAgent::load(self::AGENT_ID)) {
      AiAgent::create([
        'id' => self::AGENT_ID,
        'label' => 'Asistente de viajes Trotamundos',
        'description' => 'Busca planes y contenidos públicos de Juan Trota Mundos.',
        'system_prompt' => 'Ayuda a descubrir Colombia con el contenido publicado de Juan Trota Mundos. Usa la herramienta de búsqueda para fundamentar los datos del catálogo. No inventes precios, disponibilidad ni condiciones; recuerda que las fichas demo son provisionales y remite a la ficha para confirmar cualquier reserva. Responde en el idioma del visitante.',
        'secured_system_prompt' => '[ai_agent:agent_instructions]',
        'tools' => [SkillAwareRagTool::PLUGIN_ID => TRUE],
        'tool_usage_limits' => [
          SkillAwareRagTool::PLUGIN_ID => [
            'index' => [
              'action' => 'force_value',
              'hide_property' => TRUE,
              'values' => [self::INDEX_ID],
            ],
          ],
        ],
        'tool_settings' => [],
        'orchestration_agent' => FALSE,
        'triage_agent' => FALSE,
        'max_loops' => 4,
        'masquerade_roles' => [],
        'exclude_users_role' => FALSE,
      ])->save();
    }

    if (!AiAssistant::load(self::ASSISTANT_ID)) {
      AiAssistant::create([
        'id' => self::ASSISTANT_ID,
        'label' => 'Juan Trota Mundos',
        'description' => 'Asistente para descubrir planes y destinos de Colombia.',
        'system_prompt' => 'Ayuda a los visitantes de Juan Trota Mundos con información del sitio.',
        'instructions' => '',
        'pre_action_prompt' => '',
        'allow_history' => 'private_tempstore',
        'chat_memory_settings' => ['expiry' => 604800, 'max_messages' => 20],
        'actions_enabled' => [],
        'error_message' => 'No he podido consultar el asistente en este momento. Inténtalo más tarde.',
        'specific_error_messages' => [],
        'llm_provider' => 'openai',
        'llm_model' => 'gpt-6-luna',
        'llm_configuration' => [],
        'roles' => [],
        'use_function_calling' => TRUE,
        'ai_agent' => self::AGENT_ID,
      ])->save();
    }

    $settings = \Drupal::configFactory()->getEditable('trotamundos_assistant.settings');
    $current_id = (string) $settings->get('assistant_id');
    if ($current_id === '' || ($current_id === 'minsait_buscador_nodes' && !AiAssistant::load($current_id))) {
      $settings->set('assistant_id', self::ASSISTANT_ID);
      $defaults = [
        'welcome_message' => ['Bienvenido a tu nueva manera de navegar. ¿Qué necesitas?', 'Hola, soy tu asistente de viaje. ¿Qué te gustaría descubrir en Colombia?'],
        'input_placeholder' => ['Escribe tu consulta...', 'Cuéntame qué viaje estás buscando...'],
        'toggle_label' => ['Trotamundos Assistant', 'Tu asistente de viaje'],
        'chat_logo_path' => ['/modules/custom/trotamundos_assistant/assets/images/minsait-chatbot-logo-head.png', '/themes/custom/trotamundos/assets/brand/wordmark-selva.svg'],
      ];
      foreach ($defaults as $key => [$old, $new]) {
        if ($settings->get($key) === $old) {
          $settings->set($key, $new);
        }
      }
      if ($settings->get('use_minsait_font') === TRUE) {
        $settings->set('use_minsait_font', FALSE);
      }
    }
    $skills = $settings->get('enabled_skills');
    $skills = is_array($skills) ? $skills : [];
    $settings->set('enabled_skills', array_values(array_unique(array_merge($skills, [
      'default',
      'travel_plans',
      'travel_guide',
    ]))))->save();
  }

}
