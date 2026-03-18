<?php

namespace Drupal\appointment_facilitator\Form;

use Drupal\appointment_facilitator\Service\BadgePrerequisiteGate;
use Drupal\Component\Utility\Tags;
use Drupal\Core\Entity\EntityAutocomplete;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets facilitators delegate issuer access for badges they already issue.
 */
class FacilitatorBadgeIssuerDelegationForm extends FormBase {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManagerService,
    protected readonly BadgePrerequisiteGate $badgeGate,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('appointment_facilitator.badge_gate'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_facilitator_badge_issuer_delegation';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $badge_options = $this->loadDelegableBadgeOptions((int) $this->currentUser()->id());

    $form['#attributes']['class'][] = 'afd-delegation-form';

    if (!$badge_options) {
      $form['empty'] = [
        '#markup' => (string) $this->t('You are not currently listed as a direct issuer on any badges.'),
      ];
      return $form;
    }

    $form['badge_tid'] = [
      '#type' => 'select',
      '#title' => $this->t('Badge'),
      '#options' => $badge_options,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select a badge -'),
    ];

    $form['target_user'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Grant issuer access to'),
      '#target_type' => 'user',
      '#selection_settings' => [
        'include_anonymous' => FALSE,
      ],
      '#required' => TRUE,
      '#description' => $this->t('Pick an existing user who already holds the selected badge. Facilitators, instructors, and staff are all allowed.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Grant issuer access'),
      '#button_type' => 'primary',
    ];
    $form['actions']['help'] = [
      '#type' => 'link',
      '#title' => $this->t('Review my badges'),
      '#url' => Url::fromRoute('appointment_facilitator.dashboard'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $current_uid = (int) $this->currentUser()->id();
    $badge_options = $this->loadDelegableBadgeOptions($current_uid);
    $badge_tid = (int) $form_state->getValue('badge_tid');

    if (!isset($badge_options[$badge_tid])) {
      $form_state->setErrorByName('badge_tid', $this->t('You can only delegate issuer access for badges you personally issue.'));
      return;
    }

    $target_uid = $this->extractTargetUserId($form_state->getValue('target_user'));
    if ($target_uid <= 0) {
      $form_state->setErrorByName('target_user', $this->t('Select a valid user account.'));
      return;
    }

    if ($target_uid === $current_uid) {
      $form_state->setErrorByName('target_user', $this->t('You already control your own issuer access.'));
      return;
    }

    $target = $this->entityTypeManagerService->getStorage('user')->load($target_uid);
    if (!$target instanceof UserInterface || !$target->isActive()) {
      $form_state->setErrorByName('target_user', $this->t('The selected user account is not active.'));
      return;
    }

    if (!$this->badgeGate->memberHasActiveOrBlankBadge($target_uid, $badge_tid)) {
      $form_state->setErrorByName('target_user', $this->t('@name does not currently hold that badge, so issuer access cannot be delegated yet.', [
        '@name' => $target->getDisplayName(),
      ]));
      return;
    }

    $term = $this->entityTypeManagerService->getStorage('taxonomy_term')->load($badge_tid);
    if (!$term || !$term->hasField('field_badge_issuer')) {
      $form_state->setErrorByName('badge_tid', $this->t('This badge cannot accept issuer assignments right now.'));
      return;
    }

    foreach ($term->get('field_badge_issuer')->getValue() as $item) {
      if ((int) ($item['target_id'] ?? 0) === $target_uid) {
        $form_state->setErrorByName('target_user', $this->t('@name is already a direct issuer for this badge.', [
          '@name' => $target->getDisplayName(),
        ]));
        return;
      }
    }

    $form_state->set('delegation_badge_tid', $badge_tid);
    $form_state->set('delegation_target_uid', $target_uid);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $badge_tid = (int) $form_state->get('delegation_badge_tid');
    $target_uid = (int) $form_state->get('delegation_target_uid');

    $term = $this->entityTypeManagerService->getStorage('taxonomy_term')->load($badge_tid);
    $target = $this->entityTypeManagerService->getStorage('user')->load($target_uid);
    if (!$term || !$target instanceof UserInterface || !$term->hasField('field_badge_issuer')) {
      $this->messenger()->addError($this->t('Issuer access could not be updated.'));
      $form_state->setRedirect('appointment_facilitator.dashboard');
      return;
    }

    $values = $term->get('field_badge_issuer')->getValue();
    $values[] = ['target_id' => $target_uid];
    $term->set('field_badge_issuer', $values);
    $term->save();

    $this->messenger()->addStatus($this->t('Granted @name issuer access for @badge.', [
      '@name' => $target->getDisplayName(),
      '@badge' => $term->label(),
    ]));

    $form_state->setRedirect('appointment_facilitator.dashboard');
  }

  /**
   * Loads badges the current facilitator can delegate.
   */
  protected function loadDelegableBadgeOptions(int $uid): array {
    if ($uid <= 0) {
      return [];
    }

    $storage = $this->entityTypeManagerService->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'badges')
      ->condition('field_badge_issuer.target_id', $uid)
      ->sort('name', 'ASC')
      ->execute();

    if (!$tids) {
      return [];
    }

    $options = [];
    foreach ($storage->loadMultiple($tids) as $term) {
      if ($term->hasField('field_badge_inactive') && !$term->get('field_badge_inactive')->isEmpty() && (bool) $term->get('field_badge_inactive')->value) {
        continue;
      }
      $options[(int) $term->id()] = $term->label();
    }

    return $options;
  }

  /**
   * Extracts a user id from an entity autocomplete value.
   */
  protected function extractTargetUserId(mixed $value): int {
    if (is_numeric($value)) {
      return (int) $value;
    }

    if (!is_string($value)) {
      return 0;
    }

    $value = trim($value);
    if ($value === '') {
      return 0;
    }

    $matches = EntityAutocomplete::extractEntityIdFromAutocompleteInput($value);
    if ($matches !== NULL) {
      return (int) $matches;
    }

    $labels = Tags::explode($value);
    if (count($labels) === 1) {
      $users = $this->entityTypeManagerService->getStorage('user')->loadByProperties(['name' => $labels[0]]);
      $user = $users ? reset($users) : NULL;
      return $user instanceof UserInterface ? (int) $user->id() : 0;
    }

    return 0;
  }

}
