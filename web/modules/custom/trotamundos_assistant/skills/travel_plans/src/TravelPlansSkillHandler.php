<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\SkillLocal\TravelPlans;

use Drupal\trotamundos_assistant\Skill\Handler\AbstractSkillHandler;
use Drupal\trotamundos_assistant\Skill\SkillContext;

/**
 * Renders verified catalog cards for travel recommendations and comparisons.
 */
final class TravelPlansSkillHandler extends AbstractSkillHandler {

  public function buildVariables(SkillContext $context): array {
    $plans = $this->runtime->resolveTravelPlans($context->result['items'] ?? []);
    return [
      '#intro' => $plans ? trim((string) ($context->result['intro'] ?? '')) : '',
      '#plans' => $plans,
      '#outro' => $plans ? trim((string) ($context->result['outro'] ?? '')) : '',
    ];
  }

  public function buildChatReply(SkillContext $context): string {
    if (!$this->runtime->resolveTravelPlans($context->result['items'] ?? [])) {
      return (string) $this->t('No he encontrado planes publicados que pueda mostrarte.');
    }
    return parent::buildChatReply($context);
  }

}
