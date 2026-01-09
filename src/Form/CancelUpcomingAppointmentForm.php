<?php

namespace Drupal\appointment_facilitator\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Time\TimeInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a quick workflow for facilitators to cancel their next session.
 */
class CancelUpcomingAppointmentForm extends FormBase {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_facilitator_cancel_upcoming_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $account = $this->currentUser();
    $appointment = $this->loadNextAppointment($account);

    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $form['description'] = [
      '#markup' => $this->t('This tool cancels your next facilitator appointment, removes it from public schedules, and clears any members who joined. Use the regular appointment editor for changes beyond the next session.'),
      '#weight' => -10,
    ];

    if (!$appointment) {
      $form['message'] = [
        '#markup' => $this->t('You do not have any upcoming facilitator appointments to cancel.'),
      ];
      return $form;
    }

    $start = $this->extractDate($appointment);
    $start_output = $start ? $this->dateFormatter->format($start->getTimestamp(), 'custom', 'l, F j, Y - g:ia', $start->getTimezone()->getName()) : $this->t('Unknown');

    $list_items = [
      $this->t('<strong>When:</strong> @date', ['@date' => $start_output]),
    ];

    if ($appointment->hasField('field_appointment_purpose') && !$appointment->get('field_appointment_purpose')->isEmpty()) {
      $list_items[] = $this->t('<strong>Purpose:</strong> @purpose', ['@purpose' => $appointment->get('field_appointment_purpose')->value]);
    }

    if ($appointment->hasField('field_appointment_badges') && !$appointment->get('field_appointment_badges')->isEmpty()) {
      $badges = [];
      foreach ($appointment->get('field_appointment_badges')->referencedEntities() as $term) {
        $badges[] = $term->label();
      }
      if ($badges) {
        $list_items[] = $this->t('<strong>Badges:</strong> @list', ['@list' => implode(', ', $badges)]);
      }
    }

    $attendees = $this->loadAttendeeNames($appointment);
    $list_items[] = $this->t('<strong>Attendees:</strong> @count', ['@count' => count($attendees)]);

    $form['details'] = [
      '#theme' => 'item_list',
      '#items' => array_map(static fn($item) => ['#markup' => $item], $list_items),
      '#weight' => -5,
    ];

    if ($attendees) {
      $form['attendee_warning'] = [
        '#type' => 'details',
        '#title' => $this->t('Scheduled attendees (@count)', ['@count' => count($attendees)]),
        '#open' => TRUE,
        'list' => [
          '#theme' => 'item_list',
          '#items' => array_map(static fn($name) => ['#markup' => $name], $attendees),
        ],
      ];
    }

    $form['warning'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      'text' => ['#markup' => $this->t('Cancelling this session will mark it as <em>canceled</em>, unpublish it so it disappears from the schedule, and remove all attendees. Be sure to notify members if you have not already done so.')],
    ];

    $form['confirmation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand this cannot be undone.'),
      '#required' => TRUE,
    ];

    $form['node_id'] = [
      '#type' => 'hidden',
      '#value' => $appointment->id(),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel this appointment'),
      '#button_type' => 'danger',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nid = (int) $form_state->getValue('node_id');
    if ($nid <= 0) {
      $this->messenger()->addError($this->t('Unable to find the appointment to cancel.'));
      return;
    }

    /** @var \Drupal\node\NodeInterface|null $appointment */
    $appointment = $this->entityTypeManager->getStorage('node')->load($nid);
    $uid = (int) $this->currentUser()->id();
    if (!$appointment instanceof NodeInterface || $appointment->bundle() !== 'appointment') {
      $this->messenger()->addError($this->t('This appointment is no longer available.'));
      return;
    }

    if (!$this->isHostedBy($appointment, $uid) && !$this->currentUser()->hasPermission('administer nodes')) {
      $this->messenger()->addError($this->t('You cannot cancel this appointment.'));
      return;
    }

    // Clone the original entity so downstream hooks can detect status changes.
    $appointment->original = clone $appointment;

    if ($appointment->hasField('field_appointment_status')) {
      $status_value = $appointment->get('field_appointment_status')->value;
      if ($status_value === 'canceled') {
        $this->messenger()->addWarning($this->t('This appointment is already canceled.'));
        return;
      }
      $appointment->set('field_appointment_status', 'canceled');
    }

    // Unpublish so the session disappears from schedule views.
    if ($appointment->isPublished()) {
      $appointment->setUnpublished();
    }

    if ($appointment->hasField('field_appointment_attendees')) {
      $appointment->set('field_appointment_attendees', []);
    }

    try {
      $appointment->save();
      $this->messenger()->addStatus($this->t('The appointment has been canceled.'));
    }
    catch (\Exception $e) {
      $this->getLogger('appointment_facilitator')->error('Unable to cancel appointment @id: @error', [
        '@id' => $appointment->id(),
        '@error' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Unable to cancel the appointment. Please try again.'));
      return;
    }

    $form_state->setRedirect('entity.node.canonical', ['node' => $appointment->id()]);
  }

  /**
   * Loads the next appointment for the facilitator.
   */
  protected function loadNextAppointment(AccountInterface $account): ?NodeInterface {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return NULL;
    }

    $date_field = $this->resolveDateField();
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('status', 1)
      ->condition('field_appointment_host.target_id', $uid)
      ->range(0, 1);

    if ($this->fieldExists('field_appointment_status')) {
      $query->condition('field_appointment_status.value', 'canceled', '<>');
    }

    if ($date_field === 'field_appointment_timerange') {
      $query->condition('field_appointment_timerange.value', $this->time->getRequestTime(), '>=');
      $query->sort('field_appointment_timerange.value', 'ASC');
    }
    elseif ($date_field === 'field_appointment_date') {
      $today = (new DrupalDateTime('now'))->format('Y-m-d');
      $query->condition('field_appointment_date.value', $today, '>=');
      $query->sort('field_appointment_date.value', 'ASC');
    }
    else {
      $query->sort('created', 'ASC');
    }

    $ids = $query->execute();
    if (!$ids) {
      return NULL;
    }
    $node = $this->entityTypeManager->getStorage('node')->load(reset($ids));

    return $node instanceof NodeInterface ? $node : NULL;
  }

  protected function extractDate(NodeInterface $node): ?DrupalDateTime {
    if ($node->hasField('field_appointment_timerange') && !$node->get('field_appointment_timerange')->isEmpty()) {
      $item = $node->get('field_appointment_timerange')->first();
      $value = $item->value;
      if ($value !== NULL && $value !== '') {
        $timezone = $item->timezone ?: date_default_timezone_get();
        try {
          return DrupalDateTime::createFromTimestamp((int) $value, new \DateTimeZone($timezone));
        }
        catch (\Exception $e) {
          // Fall through to legacy field.
        }
      }
    }
    if ($node->hasField('field_appointment_date') && !$node->get('field_appointment_date')->isEmpty()) {
      $value = $node->get('field_appointment_date')->value;
      if ($value) {
        return DrupalDateTime::createFromFormat('Y-m-d', $value) ?: NULL;
      }
    }
    return NULL;
  }

  protected function loadAttendeeNames(NodeInterface $node): array {
    if (!$node->hasField('field_appointment_attendees') || $node->get('field_appointment_attendees')->isEmpty()) {
      return [];
    }
    $names = [];
    foreach ($node->get('field_appointment_attendees')->referencedEntities() as $user) {
      $names[] = $user->getDisplayName();
    }
    return $names;
  }

  protected function resolveDateField(): ?string {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'appointment');
    if (isset($definitions['field_appointment_timerange'])) {
      return 'field_appointment_timerange';
    }
    if (isset($definitions['field_appointment_date'])) {
      return 'field_appointment_date';
    }
    return NULL;
  }

  protected function fieldExists(string $field_name): bool {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'appointment');
    return isset($definitions[$field_name]);
  }

  protected function isHostedBy(NodeInterface $node, int $uid): bool {
    if (!$node->hasField('field_appointment_host') || $node->get('field_appointment_host')->isEmpty()) {
      return FALSE;
    }
    $target = $node->get('field_appointment_host')->target_id;
    return $target !== NULL ? ((int) $target === $uid) : FALSE;
  }

}
