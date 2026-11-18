<?php

declare(strict_types=1);

namespace Drupal\trotamundos_pages\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Editorial and legal pages.
 */
final class PagesController extends ControllerBase {

  /**
   * Who we are, purpose, communities and allies.
   */
  public function esencia(): array {
    return [
      '#theme' => 'trotamundos_esencia',
      '#partner_whatsapp_url' => $this->partnerWhatsappUrl(),
      '#attached' => ['library' => ['trotamundos/pages']],
      '#cache' => [
        'contexts' => ['languages:language_interface'],
        'tags' => ['config:trotamundos.settings'],
      ],
    ];
  }

  /**
   * Commitment to local communities and entrepreneurs.
   */
  public function compromiso(): array {
    return [
      '#theme' => 'trotamundos_compromiso',
      '#partner_whatsapp_url' => $this->partnerWhatsappUrl(),
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

  /**
   * Provisional legal information.
   */
  public function legal(string $document): array {
    $heading = match ($document) {
      'privacy' => $this->t('Política de privacidad'),
      'notice' => $this->t('Aviso legal'),
      'cookies' => $this->t('Política de cookies'),
    };

    return [
      '#theme' => 'trotamundos_legal',
      '#document' => $document,
      '#heading' => $heading,
      '#attached' => ['library' => ['trotamundos/pages']],
      '#cache' => ['contexts' => ['languages:language_interface']],
    ];
  }

  /**
   * Returns the contact URL for prospective local partners.
   */
  private function partnerWhatsappUrl(): string {
    $number = preg_replace('/\D+/', '', (string) ($this->config('trotamundos.settings')->get('whatsapp_number') ?: '573114232421'));
    $message = (string) $this->t('Hola, tengo un emprendimiento turístico y me gustaría ser aliado de Trotamundos.');
    return 'https://wa.me/' . $number . '?text=' . rawurlencode($message);
  }

}
