<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Admin;

use Drupal\Core\Url;

/**
 * Provides the administration pills shared by Trotamundos Assistant pages.
 */
trait AdminPillsTrait {

  /**
   * Builds the administration pills used by the module pages.
   */
  private function buildAdminPills(string $active): array {
    $links = [
      'overview' => [
        'label' => $this->t('Estado'),
        'route' => 'trotamundos_assistant.overview',
      ],
      'usage' => [
        'label' => $this->t('Coste'),
        'route' => 'trotamundos_assistant.usage',
      ],
      'statistics' => [
        'label' => $this->t('Estadísticas'),
        'route' => 'trotamundos_assistant.statistics',
      ],
      'settings' => [
        'label' => $this->t('Configuración'),
        'route' => 'trotamundos_assistant.settings',
      ],
      'extensions' => [
        'label' => $this->t('Extensiones'),
        'route' => 'trotamundos_assistant.extensions',
      ],
      'skills' => [
        'label' => $this->t('Skills'),
        'route' => 'trotamundos_assistant.skills',
      ],
      'flow' => [
        'label' => $this->t('Flujo'),
        'route' => 'trotamundos_assistant.flow',
      ],
    ];

    $items = [];
    foreach ($links as $key => $link) {
      $items[$key] = [
        '#type' => 'link',
        '#title' => $link['label'],
        '#url' => Url::fromRoute($link['route']),
        '#attributes' => [
          'class' => [
            'trotamundos-assistant-usage__pill',
            $key === $active ? 'is-active' : '',
          ],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-usage__pills']],
      'items' => $items,
    ];
  }

}
