<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant_documents\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\trotamundos_assistant_documents\Service\DocumentConfigurationDiscovery;
use Drupal\trotamundos_assistant_documents\Service\DocumentTextExtractorRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Builds the Documents settings page.
 */
final class DocumentsSettingsController extends ControllerBase {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactoryService,
    private readonly EntityTypeManagerInterface $entityTypeManagerService,
    private readonly DocumentConfigurationDiscovery $discovery,
    private readonly DocumentTextExtractorRegistry $textExtractorRegistry,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('trotamundos_assistant_documents.discovery'),
      $container->get('trotamundos_assistant_documents.text_extractor_registry'),
    );
  }

  /**
   * Builds the configured rules page.
   */
  public function page(): array {
    return [
      'add_rule' => [
        '#type' => 'link',
        '#title' => $this->t('Añadir Regla de Indexación'),
        '#url' => Url::fromRoute('trotamundos_assistant_documents.rule_add'),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
          'style' => 'margin-bottom: 1.5rem;',
        ],
      ],
      'configured_rules' => $this->buildConfiguredRulesList(),
    ];
  }

  /**
   * Builds the configured rules listing.
   */
  private function buildConfiguredRulesList(): array {
    $rules = $this->configFactoryService
      ->get('trotamundos_assistant_documents.settings')
      ->get('rules') ?: [];

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['trotamundos-assistant-documents-configured-rules'],
        'style' => 'margin-bottom: 2rem;',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Tipo de Media'),
          $this->t('Entidad / bundle'),
          $this->t('Campo Media origen'),
          $this->t('Campo destino'),
          $this->t('Proceso de Extensión'),
          $this->t('Longitud máxima'),
          $this->t('Search API'),
          $this->t('Acciones'),
        ],
        '#empty' => $this->t('No hay reglas configuradas todavía.'),
      ],
    ];

    if (!is_array($rules)) {
      return $build;
    }

    foreach ($rules as $rule_id => $rule) {
      if (!is_array($rule) || empty($rule['enabled'])) {
        continue;
      }

      $media_type = (string) ($rule['media_type'] ?? '');
      $source_entity_type = (string) ($rule['source_entity_type'] ?? '');
      $source_bundle = (string) ($rule['source_bundle'] ?? '');
      $source_media_field = (string) ($rule['source_media_field'] ?? '');
      $media_file_field = (string) ($rule['media_file_field'] ?? '');
      $destination_field = (string) ($rule['destination_field'] ?? $this->buildDestinationFieldName($media_file_field));

      $build['table'][$rule_id]['media'] = [
        '#plain_text' => $this->discovery->getMediaTypeLabel($media_type),
      ];
      $build['table'][$rule_id]['source'] = [
        '#plain_text' => $this->discovery->getSourceLabel($source_entity_type, $source_bundle),
      ];
      $build['table'][$rule_id]['source_media_field'] = [
        '#plain_text' => $this->discovery->getFieldLabel($source_entity_type, $source_bundle, $source_media_field),
      ];
      $build['table'][$rule_id]['destination_field'] = $this->buildDestinationFieldLink($media_type, $destination_field);
      $build['table'][$rule_id]['extension_process'] = [
        '#plain_text' => $this->formatExtensionProcess((string) ($rule['extension_process'] ?? 'pdf')),
      ];
      $build['table'][$rule_id]['max_text_length'] = [
        '#plain_text' => (string) ((int) ($rule['max_text_length'] ?? 50000)),
      ];
      $build['table'][$rule_id]['search_api'] = $this->buildSearchApiStatus($destination_field);
      $build['table'][$rule_id]['actions'] = [
        '#type' => 'link',
        '#title' => $this->t('Borrar'),
        '#url' => Url::fromRoute('trotamundos_assistant_documents.rule_delete', [
          'rule_id' => $rule_id,
        ]),
        '#attributes' => [
          'class' => ['button', 'button--small'],
        ],
      ];
    }

    return $build;
  }

  /**
   * Formats an extension process for display.
   */
  private function formatExtensionProcess(string $extension_process): string {
    return $this->textExtractorRegistry->getLabel($extension_process);
  }

  /**
   * Builds the destination field link to the Media field configuration.
   */
  private function buildDestinationFieldLink(string $media_type, string $destination_field): array {
    if ($media_type !== '' && $destination_field !== '' && $this->routeExists('entity.media.field_ui_fields')) {
      return [
        '#type' => 'link',
        '#title' => $destination_field,
        '#url' => Url::fromRoute('entity.media.field_ui_fields', [
          'media_type' => $media_type,
        ]),
      ];
    }

    return [
      '#plain_text' => $destination_field,
    ];
  }

  /**
   * Builds the Search API status for one destination field.
   */
  private function buildSearchApiStatus(string $destination_field): array {
    $indexes = $this->getSearchApiIndexesUsingField($destination_field);
    if ($indexes !== []) {
      $build = ['#type' => 'container'];

      $last_index_id = array_key_last($indexes);
      foreach ($indexes as $index_id => $index_label) {
        $build['index_' . $index_id] = $this->buildSearchApiIndexLink($index_id, $index_label);
        if ($index_id !== $last_index_id) {
          $build['separator_' . $index_id] = [
            '#plain_text' => ', ',
          ];
        }
      }

      return $build;
    }

    if ($this->routeExists('search_api.overview')) {
      return [
        '#type' => 'link',
        '#title' => $this->t('Pte Configurar'),
        '#url' => Url::fromRoute('search_api.overview'),
      ];
    }

    return [
      '#plain_text' => $this->t('No. Search API no está disponible'),
    ];
  }

  /**
   * Returns Search API indexes where the destination field is already present.
   *
   * @return array<string, string>
   *   Index labels keyed by index ID.
   */
  private function getSearchApiIndexesUsingField(string $destination_field): array {
    if ($destination_field === '' || !$this->entityTypeManagerService->hasDefinition('search_api_index')) {
      return [];
    }

    $index_labels = [];
    $indexes = $this->entityTypeManagerService->getStorage('search_api_index')->loadMultiple();
    foreach ($indexes as $index) {
      foreach ($index->getFields() as $field_id => $field) {
        $property_path = (string) $field->getPropertyPath();
        if ((string) $field_id === $destination_field || str_contains($property_path, $destination_field)) {
          $index_labels[(string) $index->id()] = (string) $index->label();
          break;
        }
      }
    }

    return $index_labels;
  }

  /**
   * Builds a link to a Search API index detail page when available.
   */
  private function buildSearchApiIndexLink(string $index_id, string $index_label): array {
    if ($this->routeExists('entity.search_api_index.canonical')) {
      return [
        '#type' => 'link',
        '#title' => $index_label,
        '#url' => Url::fromRoute('entity.search_api_index.canonical', [
          'search_api_index' => $index_id,
        ]),
      ];
    }

    return [
      '#plain_text' => $index_label,
    ];
  }

  /**
   * Checks whether a route exists.
   */
  private function routeExists(string $route_name): bool {
    try {
      \Drupal::service('router.route_provider')->getRouteByName($route_name);
      return TRUE;
    }
    catch (RouteNotFoundException) {
      return FALSE;
    }
  }

  /**
   * Builds the destination field name from a Media file field.
   */
  private function buildDestinationFieldName(string $media_file_field): string {
    return $media_file_field === '' ? '' : $media_file_field . '_index_text';
  }

}
