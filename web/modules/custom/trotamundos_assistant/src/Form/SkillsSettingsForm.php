<?php

declare(strict_types=1);

namespace Drupal\trotamundos_assistant\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\trotamundos_assistant\Admin\AdminPillsTrait;
use Drupal\trotamundos_assistant\Config\TrotamundosAssistantDefaults;
use Drupal\trotamundos_assistant\Skill\SkillMatcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Enables or disables filesystem skills.
 */
final class SkillsSettingsForm extends ConfigFormBase {

  use AdminPillsTrait;

  /**
   * Constructs the skills settings form.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly SkillMatcher $skillMatcher,
    private readonly TrotamundosAssistantDefaults $defaults,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('trotamundos_assistant.skill_matcher'),
      $container->get('trotamundos_assistant.defaults'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'trotamundos_assistant_skills_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['trotamundos_assistant.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'trotamundos-assistant-skills-form';
    $form['nav'] = $this->buildAdminPills('skills');

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'trotamundos-assistant-usage__panel',
          'trotamundos-assistant-skills__intro',
        ],
      ],
      'title' => [
        '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Skills del módulo') . '</h2>',
      ],
      'description' => [
        '#markup' => '<p>' . $this->t('Las skills se descubren desde la carpeta skills de cualquier módulo habilitado. Activa solo las que deban enviarse al proveedor de IA.') . '</p>',
      ],
    ];

    $form['skills'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['trotamundos-assistant-skills']],
      'title' => [
        '#markup' => '<h2 class="trotamundos-assistant-usage__panel-title">' . $this->t('Skills disponibles') . '</h2>',
      ],
    ];

    $skills = $this->skillMatcher->loadDefinitions();
    $enabled_count = count(array_filter($skills, static fn(array $skill): bool => !empty($skill['enabled'])));
    $form['skills']['summary'] = [
      '#markup' => '<p class="trotamundos-assistant-skills__summary">' . $this->t('@enabled activas de @total disponibles. Solo las activas se envían al proveedor IA.', [
        '@enabled' => $enabled_count,
        '@total' => count($skills),
      ]) . '</p>',
    ];

    if (!$skills) {
      $form['skills']['empty'] = [
        '#markup' => '<p>' . $this->t('No se han encontrado skills en las carpetas skills de los módulos habilitados.') . '</p>',
      ];
    }

    $groups = $this->buildSkillGroups($skills);
    if ($groups !== []) {
      $form['skills']['groups_tabs'] = [
        '#type' => 'vertical_tabs',
        '#attributes' => ['class' => ['trotamundos-assistant-skills__tabs']],
      ];
    }

    foreach ($groups as $group_id => $group) {
      $form['skills']['group_' . $group_id] = [
        '#type' => 'details',
        '#title' => $this->t('@label (@enabled/@total)', [
          '@label' => $group['label'],
          '@enabled' => $group['enabled_count'],
          '@total' => count($group['skills']),
        ]),
        '#group' => 'groups_tabs',
        '#open' => $group['enabled_count'] > 0,
        '#attributes' => [
          'class' => [
            'trotamundos-assistant-skills__group',
            'trotamundos-assistant-skills__group--' . Html::getClass($group_id),
          ],
        ],
        'summary' => [
          '#markup' => '<p class="trotamundos-assistant-skills__group-summary">' . $this->t('@enabled activas de @total skills en este grupo.', [
            '@enabled' => $group['enabled_count'],
            '@total' => count($group['skills']),
          ]) . '</p>',
        ],
        'items' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-skills__group-items']],
        ],
      ];
    }

    foreach ($skills as $skill_id => $skill) {
      $group_id = (string) ($skill['group']['id'] ?? 'general');
      $field_name = $this->buildFieldName($skill_id);
      $enabled = !empty($skill['enabled']);
      $dependencies_met = !empty($skill['dependencies_met']);
      $missing_modules = $skill['missing_dependencies']['modules'] ?? [];
      $required_modules = $skill['dependencies']['modules'] ?? [];

      $form['skills']['group_' . $group_id]['items'][$this->buildFieldName($skill_id)] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'trotamundos-assistant-skills__item',
            $enabled ? 'is-enabled' : 'is-disabled',
            $dependencies_met ? 'has-dependencies' : 'is-unavailable',
          ],
        ],
        'copy' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['trotamundos-assistant-skills__copy']],
          'title' => [
            '#markup' => '<h3 class="trotamundos-assistant-skills__title">' . $this->formatSkillTitle($skill) . '</h3>',
          ],
          'machine_name' => [
            '#markup' => '<p class="trotamundos-assistant-skills__machine"><code>' . Html::escape($skill_id) . '</code></p>',
          ],
          'provider_module' => [
            '#markup' => '<p class="trotamundos-assistant-skills__module">' . $this->t('Módulo: @module', [
              '@module' => (string) ($skill['provider_module_label'] ?? $skill['provider_module'] ?? ''),
            ]) . '</p>',
          ],
          'description' => [
            '#markup' => '<p class="trotamundos-assistant-skills__description">' . Html::escape((string) ($skill['description'] ?? '')) . '</p>',
          ],
          'dependencies' => [
            '#markup' => $this->buildDependencyMarkup($required_modules, $missing_modules),
          ],
        ],
        $field_name => [
          '#type' => 'checkbox',
          '#title' => $this->t('Activar'),
          '#title_display' => 'invisible',
          '#default_value' => $enabled,
          '#disabled' => !$dependencies_met,
          '#attributes' => ['class' => ['trotamundos-assistant-skills__checkbox']],
          '#wrapper_attributes' => ['class' => ['trotamundos-assistant-skills__toggle']],
        ],
      ];
    }

    $form['#attached']['library'][] = 'trotamundos_assistant/usage_admin';
    if (($this->config('trotamundos_assistant.settings')->get('use_minsait_font') ?? $this->defaults->useMinsaitFont()) !== FALSE) {
      $form['#attached']['library'][] = 'trotamundos_assistant/font';
    }
    $form['#prefix'] = '<div class="trotamundos-assistant-usage">';
    $form['#suffix'] = '</div>';

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    if ($this->getEnabledSkillsFromForm($form_state) === []) {
      $form_state->setErrorByName('skills', $this->t('Debe haber al menos una skill activa.'));
    }

    foreach ($this->skillMatcher->loadDefinitions() as $skill_id => $skill) {
      if (!$form_state->getValue($this->buildFieldName($skill_id)) || !empty($skill['dependencies_met'])) {
        continue;
      }

      $form_state->setErrorByName(
        $this->buildFieldName($skill_id),
        $this->t('La skill @skill no se puede activar porque faltan módulos requeridos.', [
          '@skill' => $skill_id,
        ]),
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('trotamundos_assistant.settings')
      ->set('enabled_skills', $this->getEnabledSkillsFromForm($form_state))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Formats a skill row label.
   *
   * @param array<string, mixed> $skill
   *   Skill definition.
   */
  private function formatSkillTitle(array $skill): string {
    return Html::escape((string) ($skill['title'] ?? $skill['machine_name']));
  }

  /**
   * Builds a safe form element name for a skill toggle.
   */
  private function buildFieldName(string $skill_id): string {
    return 'skill_enabled_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', $skill_id);
  }

  /**
   * Builds dependency status markup for a skill.
   *
   * @param array<int, string> $required_modules
   *   Required module machine names.
   * @param array<int, string> $missing_modules
   *   Missing module machine names.
   */
  private function buildDependencyMarkup(array $required_modules, array $missing_modules): string {
    if ($required_modules === []) {
      return '<p class="trotamundos-assistant-skills__dependencies">' . $this->t('Sin dependencias adicionales.') . '</p>';
    }

    $missing_lookup = array_fill_keys($missing_modules, TRUE);
    $module_items = [];
    foreach ($required_modules as $module) {
      $module_items[] = '<span class="trotamundos-assistant-skills__dependency-chip' . (isset($missing_lookup[$module]) ? ' is-missing' : ' is-ok') . '">' . Html::escape($module) . '</span>';
    }

    if ($missing_modules === []) {
      return '<div class="trotamundos-assistant-skills__dependencies is-ok"><p>' . $this->t('Dependencias activas') . '</p><div class="trotamundos-assistant-skills__dependency-list">' . implode('', $module_items) . '</div></div>';
    }

    return '<div class="trotamundos-assistant-skills__dependencies is-missing"><p>' . $this->t('No disponible. Faltan módulos') . '</p><div class="trotamundos-assistant-skills__dependency-list">' . implode('', $module_items) . '</div></div>';
  }

  /**
   * Groups skills by their declared metadata group.
   *
   * @param array<string, array<string, mixed>> $skills
   *   Skill definitions keyed by machine name.
   *
   * @return array<string, array{label: string, enabled_count: int, skills: array<string, array<string, mixed>>}>
   *   Group data keyed by normalized group id.
   */
  private function buildSkillGroups(array $skills): array {
    $groups = [];
    foreach ($skills as $skill_id => $skill) {
      $group = is_array($skill['group'] ?? NULL) ? $skill['group'] : [];
      $group_id = (string) ($group['id'] ?? 'general');
      if ($group_id === '') {
        $group_id = 'general';
      }

      if (!isset($groups[$group_id])) {
        $groups[$group_id] = [
          'label' => (string) ($group['label'] ?? ucfirst(str_replace('_', ' ', $group_id))),
          'enabled_count' => 0,
          'skills' => [],
        ];
      }

      $groups[$group_id]['skills'][$skill_id] = $skill;
      if (!empty($skill['enabled'])) {
        $groups[$group_id]['enabled_count']++;
      }
    }

    uasort($groups, static fn(array $left, array $right): int => $left['label'] <=> $right['label']);

    return $groups;
  }

  /**
   * Gets enabled skill IDs from submitted checkbox values.
   *
   * @return array<int, string>
   *   Enabled skill IDs.
   */
  private function getEnabledSkillsFromForm(FormStateInterface $form_state): array {
    $enabled = [];
    foreach ($this->skillMatcher->loadDefinitions() as $skill_id => $skill) {
      if (empty($skill['dependencies_met'])) {
        continue;
      }
      if ($form_state->getValue($this->buildFieldName($skill_id))) {
        $enabled[] = $skill_id;
      }
    }

    return $enabled;
  }

}
