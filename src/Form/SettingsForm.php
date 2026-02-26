<?php

namespace Drupal\appointment_facilitator\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class SettingsForm extends ConfigFormBase {
  protected function getEditableConfigNames() {
    return ['appointment_facilitator.settings'];
  }

  public function getFormId() {
    return 'appointment_facilitator_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $conf = $this->config('appointment_facilitator.settings');

    $form['system_wide_joiner_cap'] = [
      '#type' => 'number',
      '#title' => $this->t('System-wide Joiner Cap'),
      '#description' => $this->t('The maximum number of additional members allowed to join an appointment (beyond the person who scheduled it). Set to 0 to disable joining for all sessions, even if the badge or facilitator allows more. Default: <code>0</code>.'),
      '#default_value' => (int) ($conf->get('system_wide_joiner_cap') ?? 0),
      '#min' => 0,
      '#required' => TRUE,
    ];

    $form['show_always_join_cta'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Always show Join button'),
      '#description' => $this->t('When unchecked, the Join button only appears if effective capacity > 1.'),
      '#default_value' => (bool) $conf->get('show_always_join_cta'),
    ];

    $form['badges_vocab_machine_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Badges vocabulary machine name'),
      '#default_value' => $conf->get('badges_vocab_machine_name') ?: 'badges',
      '#description' => $this->t('Machine name of the vocabulary where badges live. Default: <code>badges</code>.'),
      '#required' => TRUE,
    ];

    $form['facilitator_profile_bundle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Facilitator profile bundle machine name'),
      '#default_value' => $conf->get('facilitator_profile_bundle') ?: 'coordinator',
      '#description' => $this->t('If you use the Profile module, enter the bundle used for facilitators. Default: <code>coordinator</code>.'),
    ];

    $form['arrival_tracking'] = [
      '#type' => 'details',
      '#title' => $this->t('Arrival tracking'),
      '#open' => TRUE,
    ];

    $form['arrival_tracking']['arrival_grace_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Grace minutes'),
      '#default_value' => (int) ($conf->get('arrival_grace_minutes') ?? 5),
      '#min' => 0,
      '#description' => $this->t('Minutes after the scheduled start that count as a lesser late.'),
    ];

    $form['arrival_tracking']['arrival_pre_window_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Pre-start scan window (minutes)'),
      '#default_value' => (int) ($conf->get('arrival_pre_window_minutes') ?? 30),
      '#min' => 0,
      '#description' => $this->t('How many minutes before the scheduled start to look for access logs.'),
    ];

    $form['arrival_tracking']['arrival_backfill_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Backfill days on cron'),
      '#default_value' => (int) ($conf->get('arrival_backfill_days') ?? 7),
      '#min' => 1,
      '#description' => $this->t('How many past days to scan for appointments when cron updates arrival status.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('appointment_facilitator.settings')
      ->set('system_wide_joiner_cap', (int) $form_state->getValue('system_wide_joiner_cap'))
      ->set('show_always_join_cta', (bool) $form_state->getValue('show_always_join_cta'))
      ->set('badges_vocab_machine_name', (string) $form_state->getValue('badges_vocab_machine_name'))
      ->set('facilitator_profile_bundle', (string) $form_state->getValue('facilitator_profile_bundle'))
      ->set('arrival_grace_minutes', (int) $form_state->getValue('arrival_grace_minutes'))
      ->set('arrival_pre_window_minutes', (int) $form_state->getValue('arrival_pre_window_minutes'))
      ->set('arrival_backfill_days', (int) $form_state->getValue('arrival_backfill_days'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
