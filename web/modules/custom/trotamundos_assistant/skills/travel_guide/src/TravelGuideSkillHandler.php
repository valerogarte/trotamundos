<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\SkillLocal\TravelGuide;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Renders a grounded travel answer with validated source links.
 */
final class TravelGuideSkillHandler extends AbstractSkillHandler {

  public function buildVariables(SkillContext $context): array {
    $sources = $this->runtime->resolveTravelSources($context->result['sources'] ?? []);
    return [
      '#answer' => $sources ? trim((string) ($context->result['answer'] ?? '')) : '',
      '#sources' => $sources,
    ];
  }

  public function buildChatReply(SkillContext $context): string {
    if (!$this->runtime->resolveTravelSources($context->result['sources'] ?? [])) {
      return (string) $this->t('No he encontrado información publicada para confirmar la respuesta.');
    }
    return parent::buildChatReply($context);
  }

}
