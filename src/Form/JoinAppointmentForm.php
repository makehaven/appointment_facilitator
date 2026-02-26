<?php

namespace Drupal\appointment_facilitator\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a CSRF-protected submit form for joining appointments.
 */
class JoinAppointmentForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly DateFormatterInterface $dateFormatter,
    ConfigFactoryInterface $configFactory,
  ) {
    // FormBase already provides $this->configFactory; assign it here instead of
    // redeclaring the property with readonly promotion (which PHP forbids).
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_facilitator_join_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return [];
    }

    if (!$node || $node->bundle() !== 'appointment') {
      $form['message'] = ['#markup' => $this->t('This appointment is unavailable.')];
      return $this->addCacheContexts($form);
    }

    if (!$node->hasField('field_appointment_attendees')) {
      return [];
    }

    $config = $this->configFactory->get('appointment_facilitator.settings');
    $show_always = (bool) $config->get('show_always_join_cta');

    $effective_capacity = appointment_facilitator_effective_capacity($node);
    if (!$show_always && $effective_capacity <= 1) {
      return [];
    }

    $attendee_values = $node->get('field_appointment_attendees')->getValue();
    $current_ids = [];
    foreach ($attendee_values as $value) {
      $current_ids[] = (int) ($value['target_id'] ?? 0);
    }

    $current_ids = array_filter($current_ids, static fn($value) => $value > 0);
    $current_count = count($current_ids);
    $remaining = max(0, $effective_capacity - $current_count);

    $form['#attributes']['class'][] = 'appointment-join-form';

    // --- Session details panel ---
    $uid = (int) $account->id();

    // Date/time.
    $ts = 0;
    if ($node->hasField('field_appointment_timerange') && !$node->get('field_appointment_timerange')->isEmpty()) {
      $ts = (int) $node->get('field_appointment_timerange')->value;
    }

    // Host name.
    $host_name = '';
    if ($node->hasField('field_appointment_host') && !$node->get('field_appointment_host')->isEmpty()) {
      $host = $node->get('field_appointment_host')->entity;
      if ($host) {
        $host_name = $host->getDisplayName();
      }
    }

    $meta_parts = [];
    if ($ts) {
      $meta_parts[] = $this->dateFormatter->format($ts, 'custom', 'l, F j \a\t g:ia');
    }
    if ($host_name) {
      $meta_parts[] = $this->t('with @name', ['@name' => $host_name]);
    }
    $meta_parts[] = $this->formatPlural($remaining, '1 seat left', '@count seats left');

    $form['session_meta'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => implode(' &mdash; ', array_map('strval', $meta_parts)),
      '#attributes' => ['class' => ['join-session-meta']],
    ];

    // --- Badges covered in this session ---
    if ($node->hasField('field_appointment_badges') && !$node->get('field_appointment_badges')->isEmpty()) {
      $badge_terms = $node->get('field_appointment_badges')->referencedEntities();
      $pending_tids = array_flip(_appointment_facilitator_load_pending_badge_term_ids($uid));

      $badge_items = [];
      foreach ($badge_terms as $term) {
        $tid = (int) $term->id();
        $badge_url = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid]);
        $is_pending = isset($pending_tids[$tid]);

        if ($is_pending) {
          $badge_items[] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['join-badge-item', 'join-badge-item--pending']],
            'label' => [
              '#type' => 'link',
              '#title' => $term->label(),
              '#url' => $badge_url,
            ],
            'status' => [
              '#markup' => ' <span class="join-badge-status join-badge-status--pending">' . $this->t('pending — ready to check out') . '</span>',
            ],
          ];
        }
        else {
          $badge_items[] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['join-badge-item', 'join-badge-item--not-pending']],
            'label' => [
              '#type' => 'link',
              '#title' => $term->label(),
              '#url' => $badge_url,
            ],
            'status' => [
              '#markup' => ' <span class="join-badge-status join-badge-status--not-pending">' . $this->t('not yet pending') . '</span>',
            ],
          ];
        }
      }

      if ($badge_items) {
        $form['badges_section'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['join-section', 'join-badges']],
          'heading' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('Badges covered in this session'),
            '#attributes' => ['class' => ['join-section-heading']],
          ],
          'items' => $badge_items,
          'hint' => [
            '#markup' => '<p class="join-badge-hint">' . $this->t('Badges marked <em>not yet pending</em> can still be part of your session — ask a staff member to mark them pending on your profile first if you want them counted.') . '</p>',
          ],
        ];
      }
    }

    // --- Current attendees ---
    $other_ids = array_filter($current_ids, static fn($id) => $id !== $uid && $id > 0);
    if ($other_ids) {
      $attendee_users = $this->entityTypeManager->getStorage('user')->loadMultiple($other_ids);
      $names = array_map(static fn($u) => htmlspecialchars($u->getDisplayName(), ENT_QUOTES, 'UTF-8'), $attendee_users);
      $form['attendees_section'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['join-section', 'join-attendees']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h4',
          '#value' => $this->t('Also attending'),
          '#attributes' => ['class' => ['join-section-heading']],
        ],
        'list' => [
          '#markup' => '<p class="join-attendee-names">' . implode(', ', $names) . '</p>',
        ],
      ];
    }

    if ($this->requiresPendingBadge($node) && !$this->userHasPendingBadgeForAppointment($node, $uid)) {
      $form['message'] = [
        '#markup' => '<p>' . $this->t('This session checks out badge(s). Ask staff to mark one of these badges as pending on your profile before joining.') . '</p>',
      ];
      return $this->addCacheContexts($form);
    }

    // --- Already joined / Full / Join button ---
    if (in_array($uid, $current_ids, TRUE)) {
      $form['message'] = ['#markup' => '<p>' . $this->t('You are already on this appointment.') . '</p>'];
      $form['node_id'] = [
        '#type' => 'hidden',
        '#value' => $node->id(),
      ];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['leave'] = [
        '#type' => 'submit',
        '#value' => $this->t('Leave this appointment'),
        '#button_type' => 'danger',
        '#submit' => ['::leaveSubmit'],
      ];
      return $this->addCacheContexts($form);
    }

    if ($remaining <= 0) {
      $form['message'] = ['#markup' => '<p>' . $this->t('This appointment is full.') . '</p>'];
      return $this->addCacheContexts($form);
    }

    $form['node_id'] = [
      '#type' => 'hidden',
      '#value' => $node->id(),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Join this appointment'),
      '#button_type' => 'primary',
    ];

    return $this->addCacheContexts($form);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      $this->messenger()->addError($this->t('You must be logged in to join this appointment.'));
      return;
    }

    $nid = (int) $form_state->getValue('node_id');
    if ($nid <= 0) {
      $this->messenger()->addError($this->t('Unable to determine which appointment to join.'));
      return;
    }

    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'appointment') {
      $this->messenger()->addError($this->t('This appointment is unavailable.'));
      return;
    }

    if (!$node->hasField('field_appointment_attendees')) {
      $this->messenger()->addError($this->t('This appointment does not accept attendees.'));
      return;
    }

    if (!$node->access('view', $this->currentUser(), TRUE)->isAllowed()) {
      $this->messenger()->addError($this->t('You do not have access to this appointment.'));
      return;
    }

    $attendee_field = $node->get('field_appointment_attendees');
    $attendee_values = $attendee_field->getValue();
    foreach ($attendee_values as $value) {
      if ((int) ($value['target_id'] ?? 0) === $uid) {
        $this->messenger()->addStatus($this->t('You are already on this appointment.'));
        $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
        return;
      }
    }

    $capacity = appointment_facilitator_effective_capacity($node);
    $current_count = count($attendee_values);
    if ($current_count >= $capacity) {
      $this->messenger()->addWarning($this->t('This appointment is full.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
      return;
    }

    if ($this->requiresPendingBadge($node) && !$this->userHasPendingBadgeForAppointment($node, $uid)) {
      $this->messenger()->addError($this->t('You can only join checkout sessions for badges currently pending on your profile.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
      return;
    }

    $attendee_field->appendItem($uid);
    try {
      $node->save();
      $this->messenger()->addStatus($this->t('You have joined this appointment.'));
    }
    catch (\Exception $e) {
      $this->getLogger('appointment_facilitator')->error('Failed to add attendee to appointment @id: @error', [
        '@id' => $node->id(),
        '@error' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Unable to join the appointment. Please try again.'));
    }

    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Removes the current user from appointment attendees.
   */
  public function leaveSubmit(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      $this->messenger()->addError($this->t('You must be logged in to leave this appointment.'));
      return;
    }

    $nid = (int) $form_state->getValue('node_id');
    if ($nid <= 0) {
      $this->messenger()->addError($this->t('Unable to determine which appointment to leave.'));
      return;
    }

    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'appointment') {
      $this->messenger()->addError($this->t('This appointment is unavailable.'));
      return;
    }

    if (!$node->hasField('field_appointment_attendees')) {
      $this->messenger()->addError($this->t('This appointment does not track attendees.'));
      return;
    }

    if (!$node->access('view', $this->currentUser(), TRUE)->isAllowed()) {
      $this->messenger()->addError($this->t('You do not have access to this appointment.'));
      return;
    }

    $attendee_values = $node->get('field_appointment_attendees')->getValue();
    $remaining = [];
    $removed = FALSE;
    foreach ($attendee_values as $value) {
      $target_id = (int) ($value['target_id'] ?? 0);
      if ($target_id === $uid) {
        $removed = TRUE;
        continue;
      }
      if ($target_id > 0) {
        $remaining[] = ['target_id' => $target_id];
      }
    }

    if (!$removed) {
      $this->messenger()->addStatus($this->t('You are not currently on this appointment.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
      return;
    }

    $node->set('field_appointment_attendees', $remaining);
    try {
      $node->save();
      $this->messenger()->addStatus($this->t('You have left this appointment.'));
    }
    catch (\Exception $e) {
      $this->getLogger('appointment_facilitator')->error('Failed to remove attendee from appointment @id: @error', [
        '@id' => $node->id(),
        '@error' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Unable to leave the appointment. Please try again.'));
    }

    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Adds cache contexts so per-user content stays distinct.
   */
  protected function addCacheContexts(array $form): array {
    $contexts = $form['#cache']['contexts'] ?? [];
    $contexts[] = 'user';
    $contexts[] = 'url.path';
    $form['#cache']['contexts'] = array_values(array_unique($contexts));
    return $form;
  }

  /**
   * Returns TRUE when this appointment is a badge checkout session.
   */
  protected function requiresPendingBadge(NodeInterface $node): bool {
    if (!$node->hasField('field_appointment_purpose') || $node->get('field_appointment_purpose')->isEmpty()) {
      return FALSE;
    }
    return (string) $node->get('field_appointment_purpose')->value === 'checkout';
  }

  /**
   * Returns TRUE if user has at least one pending badge on this appointment.
   */
  protected function userHasPendingBadgeForAppointment(NodeInterface $node, int $uid): bool {
    if ($uid <= 0) {
      return FALSE;
    }
    if (!$node->hasField('field_appointment_badges') || $node->get('field_appointment_badges')->isEmpty()) {
      // Checkout session without badge terms configured should not hard-block.
      return TRUE;
    }

    $pending = array_flip(_appointment_facilitator_load_pending_badge_term_ids($uid));
    if (!$pending) {
      return FALSE;
    }

    foreach ($node->get('field_appointment_badges')->getValue() as $item) {
      $tid = (int) ($item['target_id'] ?? 0);
      if ($tid > 0 && isset($pending[$tid])) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
