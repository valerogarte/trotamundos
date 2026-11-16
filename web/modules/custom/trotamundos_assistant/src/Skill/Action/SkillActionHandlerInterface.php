<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Skill\Action;

/**
 * Marker interface for handlers that own AJAX actions for a skill.
 *
 * Action methods declared in skill.yml receive the current request as their
 * first argument and the current account as their second argument.
 */
interface SkillActionHandlerInterface {

}
