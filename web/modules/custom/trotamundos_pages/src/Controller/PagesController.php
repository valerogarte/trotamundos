<?php

declare(strict_types=1);

namespace Drupal\trotamundos_pages\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * "Nuestra esencia" and "Ayuda".
 */
final class PagesController extends ControllerBase {

  /**
   * Who we are, purpose, communities and allies.
   */
  public function esencia(): array {
    $number = preg_replace('/\D+/', '', (string) ($this->config('trotamundos.settings')->get('whatsapp_number') ?: '573114232421'));
    $message = (string) $this->t('Hola, tengo un emprendimiento turístico y me gustaría ser aliado de Trotamundos.');
    return [
      '#theme' => 'trotamundos_esencia',
      '#partner_whatsapp_url' => 'https://wa.me/' . $number . '?text=' . rawurlencode($message),
      '#attached' => ['library' => ['trotamundos/pages']],
      '#cache' => [
        'contexts' => ['languages:language_interface'],
        'tags' => ['config:trotamundos.settings'],
      ],
    ];
  }

  /**
   * Frequently asked questions, contact and traveller care.
   */
  public function ayuda(): array {
    return [
      '#theme' => 'trotamundos_ayuda',
      '#attached' => ['library' => ['trotamundos/pages']],
      '#cache' => [
        'contexts' => ['languages:language_interface'],
        'tags' => ['config:trotamundos.settings'],
      ],
    ];
  }

}
