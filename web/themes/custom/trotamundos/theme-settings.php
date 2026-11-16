<?php

/**
 * @file
 * Contact channels editable from Appearance › Settings › Trotamundos.
 */

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_system_theme_settings_alter().
 */
function trotamundos_form_system_theme_settings_alter(array &$form, FormStateInterface $form_state): void {
  $form['trotamundos_contact'] = [
    '#type' => 'details',
    '#title' => t('Canales de contacto'),
    '#open' => TRUE,
    '#weight' => -20,
  ];
  $form['trotamundos_contact']['whatsapp_number'] = [
    '#type' => 'tel',
    '#title' => t('WhatsApp'),
    '#description' => t('Número con indicativo de país, sin espacios ni signos. Ejemplo: 573114232421. Se usa en la cabecera, las fichas y la página de ayuda.'),
    '#default_value' => theme_get_setting('whatsapp_number', 'trotamundos') ?: '573114232421',
    '#required' => TRUE,
    '#pattern' => '[0-9]{8,15}',
  ];
  $form['trotamundos_contact']['contact_email'] = [
    '#type' => 'email',
    '#title' => t('Correo de contacto'),
    '#default_value' => theme_get_setting('contact_email', 'trotamundos') ?: 'juantrotamundoscol@gmail.com',
  ];
  $form['trotamundos_contact']['instagram'] = [
    '#type' => 'textfield',
    '#title' => t('Usuario de Instagram'),
    '#field_prefix' => '@',
    '#default_value' => theme_get_setting('instagram', 'trotamundos') ?: 'juantrotamundos',
  ];
}
