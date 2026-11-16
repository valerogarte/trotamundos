<?php

/**
 * @file
 * Post-update hooks for Trotamundos Assistant.
 */

/**
 * Migrates data after the module machine name changed.
 *
 * This is needed when the old module schema entry is unavailable because the
 * site already used minsait_assistant before the machine-name change.
 */
function trotamundos_assistant_post_update_migrate_usage_table(): void {
  $schema = \Drupal::database()->schema();

  if (!$schema->tableExists('trotamundos_assistant_usage') && $schema->tableExists('minsait_assistant_usage')) {
    $schema->renameTable('minsait_assistant_usage', 'trotamundos_assistant_usage');
  }

  \Drupal::keyValue('system.schema')->deleteMultiple([
    'minsait_assistant',
    'minsait_assistant_documents',
    'minsait_assistant_ecommerce',
  ]);
}
