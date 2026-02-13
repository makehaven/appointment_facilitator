<?php

namespace Drupal\appointment_facilitator\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;
use Drupal\smart_date_recur\Entity\SmartDateOverride;
use Drupal\smart_date_recur\Entity\SmartDateRule;
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
    $next_availability = $this->findNextAvailabilityInstance((int) $account->id());
    $session_appointments = $appointment ? $this->loadSessionAppointments($appointment, (int) $account->id()) : [];

    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $form['description'] = [
      '#markup' => $this->t('This tool cancels your next session. By default, it marks all appointments in that upcoming session window as canceled using the reservation cancellation field.'),
      '#weight' => -10,
    ];

    if (!$appointment) {
      if (!$next_availability) {
        $form['message'] = [
          '#markup' => $this->t('You do not have any upcoming facilitator appointments or schedule instances to cancel.'),
        ];
        return $form;
      }

      $when = $this->dateFormatter->format((int) $next_availability['value'], 'custom', 'l, F j, Y - g:ia');
      $form['message'] = [
        '#markup' => $this->t('You do not have any upcoming facilitator appointments to cancel.'),
      ];
      $form['details'] = [
        '#theme' => 'item_list',
        '#items' => [
          ['#markup' => $this->t('<strong>Next availability instance:</strong> @date', ['@date' => $when])],
        ],
      ];
      $form['warning'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'text' => ['#markup' => $this->t('This will remove your next upcoming availability instance from your coordinator schedule.')],
      ];
      $form['confirmation'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('I understand this cannot be undone.'),
        '#required' => TRUE,
      ];
      $form['fallback_rrule'] = [
        '#type' => 'hidden',
        '#value' => (string) $next_availability['rrule'],
      ];
      $form['fallback_index'] = [
        '#type' => 'hidden',
        '#value' => (string) $next_availability['index'],
      ];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove my next availability instance'),
        '#button_type' => 'danger',
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
    $session_total = count($session_appointments);
    $session_additional = max(0, $session_total - 1);
    $list_items[] = $this->t('<strong>Appointments in this session:</strong> @count', ['@count' => $session_total]);
    $list_items[] = $this->t('<strong>Additional appointments in this session:</strong> @count', ['@count' => $session_additional]);

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

    if ($session_appointments) {
      $form['session_appointments'] = [
        '#type' => 'details',
        '#title' => $this->t('Appointments that will be canceled for "full session" (@count)', ['@count' => $session_total]),
        '#open' => FALSE,
        'list' => [
          '#theme' => 'item_list',
          '#items' => array_map(static fn($item) => ['#markup' => $item], $this->buildSessionAppointmentLabels($session_appointments)),
        ],
      ];
    }

    $form['warning'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      'text' => ['#markup' => $this->t('Cancelling marks affected appointments as <em>Cancel this reservation</em> via the reservation cancellation field. The standard cancellation automation can then send notifications.')],
    ];

    $form['cancel_scope'] = [
      '#type' => 'radios',
      '#title' => $this->t('What should be canceled?'),
      '#options' => [
        'session' => $this->t('Cancel my next full session (recommended)'),
        'single' => $this->t('Cancel only this one appointment'),
      ],
      '#default_value' => 'session',
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
      '#value' => $this->t('Cancel selected appointments'),
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
      $rrule_id = (int) $form_state->getValue('fallback_rrule');
      $index = (int) $form_state->getValue('fallback_index');
      if ($rrule_id > 0 && $index >= 0) {
        try {
          if ($this->removeAvailabilityInstance($rrule_id, $index)) {
            $this->messenger()->addStatus($this->t('Your next availability instance has been removed.'));
          }
          else {
            $this->messenger()->addWarning($this->t('No matching availability instance was found to remove.'));
          }
        }
        catch (\Exception $e) {
          $this->getLogger('appointment_facilitator')->error('Unable to remove schedule instance (rule @rule, index @index): @error', [
            '@rule' => $rrule_id,
            '@index' => $index,
            '@error' => $e->getMessage(),
          ]);
          $this->messenger()->addError($this->t('Unable to remove the availability instance. Please try again.'));
          return;
        }
        $form_state->setRedirect('appointment_facilitator.dashboard');
        return;
      }
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

    if (!$this->isHostedBy($appointment, $uid)) {
      $this->messenger()->addError($this->t('You cannot cancel this appointment.'));
      return;
    }

    $scope = (string) $form_state->getValue('cancel_scope', 'session');
    $targets = [$appointment];

    if ($scope === 'session') {
      $targets = $this->loadSessionAppointments($appointment, $uid);
    }

    if (!$targets) {
      $this->messenger()->addWarning($this->t('No matching appointments were found to cancel.'));
      return;
    }

    $cancelled_count = 0;
    try {
      foreach ($targets as $target) {
        if ($this->cancelAppointment($target)) {
          $cancelled_count++;
        }
      }
    }
    catch (\Exception $e) {
      $this->getLogger('appointment_facilitator')->error('Unable to cancel appointment(s) starting from @id: @error', [
        '@id' => $appointment->id(),
        '@error' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Unable to cancel appointments. Please try again.'));
      return;
    }

    if ($cancelled_count === 0) {
      $this->messenger()->addWarning($this->t('No appointments needed cancellation.'));
    }
    elseif ($scope === 'session') {
      $this->messenger()->addStatus($this->t('Canceled @count appointment(s) in your next session.', ['@count' => $cancelled_count]));
    }
    else {
      $this->messenger()->addStatus($this->t('The appointment has been canceled.'));
    }

    $form_state->setRedirect('appointment_facilitator.dashboard');
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
    if ($this->fieldExists('field_reservation_cancellation')) {
      $query->condition('field_reservation_cancellation.value', 'Cancel', '<>');
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

  /**
   * Loads all appointment nodes in the same session window as next appointment.
   */
  protected function loadSessionAppointments(NodeInterface $seed, int $uid): array {
    if ($uid <= 0) {
      return [$seed];
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('status', 1)
      ->condition('field_appointment_host.target_id', $uid);

    if ($this->fieldExists('field_appointment_status')) {
      $query->condition('field_appointment_status.value', 'canceled', '<>');
    }
    if ($this->fieldExists('field_reservation_cancellation')) {
      $query->condition('field_reservation_cancellation.value', 'Cancel', '<>');
    }

    $used_session_key = FALSE;
    if ($seed->hasField('field_appointment_timerange') && !$seed->get('field_appointment_timerange')->isEmpty()) {
      $seed_value = (int) $seed->get('field_appointment_timerange')->value;
      if ($seed_value > 0) {
        $query->condition('field_appointment_timerange.value', $seed_value);
        $used_session_key = TRUE;
      }
    }

    if ($seed->hasField('field_appointment_date') && !$seed->get('field_appointment_date')->isEmpty()) {
      $query->condition('field_appointment_date.value', (string) $seed->get('field_appointment_date')->value);
      if ($seed->hasField('field_host_start_time') && !$seed->get('field_host_start_time')->isEmpty()) {
        $query->condition('field_host_start_time.value', (string) $seed->get('field_host_start_time')->value);
        $used_session_key = TRUE;
      }
    }

    if (!$used_session_key) {
      $date = $this->extractDate($seed);
      if ($date instanceof DrupalDateTime) {
        $start_of_day = clone $date;
        $start_of_day->setTime(0, 0, 0);
        $end_of_day = clone $date;
        $end_of_day->setTime(23, 59, 59);

        $date_field = $this->resolveDateField();
        if ($date_field === 'field_appointment_timerange') {
          $query->condition('field_appointment_timerange.value', $start_of_day->getTimestamp(), '>=');
          $query->condition('field_appointment_timerange.value', $end_of_day->getTimestamp(), '<=');
        }
        elseif ($date_field === 'field_appointment_date') {
          $query->condition('field_appointment_date.value', $start_of_day->format('Y-m-d'));
        }
      }
      else {
        return [$seed];
      }
    }

    $query->sort('created', 'ASC');
    $ids = $query->execute();
    if (!$ids) {
      return [$seed];
    }

    $nodes = $storage->loadMultiple($ids);
    $items = [];
    foreach ($nodes as $node) {
      if ($node instanceof NodeInterface) {
        $items[] = $node;
      }
    }
    return $items ?: [$seed];
  }

  /**
   * Builds readable labels for appointments included in full-session cancellation.
   */
  protected function buildSessionAppointmentLabels(array $appointments): array {
    $items = [];
    foreach ($appointments as $appointment) {
      if (!$appointment instanceof NodeInterface) {
        continue;
      }

      $date = $this->extractDate($appointment);
      $when = $date
        ? $this->dateFormatter->format($date->getTimestamp(), 'custom', 'Y-m-d g:ia', $date->getTimezone()->getName())
        : (string) $this->t('Unknown time');
      $attendee_count = count($this->loadAttendeeNames($appointment));
      $items[] = (string) $this->t(
        '#@id: @when (@attendees attendee(s))',
        [
          '@id' => $appointment->id(),
          '@when' => $when,
          '@attendees' => $attendee_count,
        ]
      );
    }

    return $items;
  }

  /**
   * Applies cancellation changes to one appointment.
   */
  protected function cancelAppointment(NodeInterface $appointment): bool {
    // Clone original so downstream hooks can inspect status transition.
    $appointment->original = clone $appointment;

    $marked_cancelled = FALSE;
    if ($appointment->hasField('field_reservation_cancellation')) {
      $existing = array_column($appointment->get('field_reservation_cancellation')->getValue(), 'value');
      if (!in_array('Cancel', $existing, TRUE)) {
        $appointment->set('field_reservation_cancellation', [['value' => 'Cancel']]);
        $marked_cancelled = TRUE;
      }
    }

    if ($appointment->hasField('field_appointment_status')) {
      $status_value = (string) $appointment->get('field_appointment_status')->value;
      if ($status_value === 'canceled' && !$marked_cancelled) {
        return FALSE;
      }
      if ($status_value !== 'canceled') {
        $appointment->set('field_appointment_status', 'canceled');
        $marked_cancelled = TRUE;
      }
    }

    if (!$marked_cancelled) {
      return FALSE;
    }

    $appointment->save();
    return TRUE;
  }

  /**
   * Finds the next availability instance from coordinator recurring schedule.
   */
  protected function findNextAvailabilityInstance(int $uid): ?array {
    $rrule = $this->resolveActiveCoordinatorRule($uid);
    if (!$rrule) {
      return NULL;
    }

    $instances = $rrule->getRuleInstances();
    if (!is_array($instances) || !$instances) {
      return NULL;
    }

    $now = $this->time->getRequestTime();
    foreach ($instances as $index => $instance) {
      $value = isset($instance['value']) ? (int) $instance['value'] : 0;
      $end_value = isset($instance['end_value']) ? (int) $instance['end_value'] : 0;
      if ($value <= 0 || $end_value <= 0) {
        continue;
      }
      if ($end_value > $now) {
        return [
          'rrule' => (int) $rrule->id(),
          'index' => (int) $index,
          'value' => $value,
          'end_value' => $end_value,
        ];
      }
    }

    return NULL;
  }

  /**
   * Removes one availability instance by creating a cancellation override.
   */
  protected function removeAvailabilityInstance(int $rrule_id, int $index): bool {
    if ($rrule_id <= 0 || $index < 0) {
      return FALSE;
    }
    $rrule = SmartDateRule::load($rrule_id);
    if (!$rrule) {
      return FALSE;
    }

    $query = $this->entityTypeManager->getStorage('smart_date_override')->getQuery()
      ->accessCheck(FALSE)
      ->condition('rrule', $rrule_id)
      ->condition('rrule_index', $index);
    $existing_ids = $query->execute();
    if ($existing_ids) {
      $existing = SmartDateOverride::loadMultiple($existing_ids);
      foreach ($existing as $item) {
        $item->delete();
      }
    }

    $override = SmartDateOverride::create([
      'rrule' => $rrule_id,
      'rrule_index' => $index,
    ]);
    $override->save();

    return $this->updateParentFieldFromRule($rrule);
  }

  /**
   * Applies recurring rule overrides back onto parent profile field values.
   */
  protected function updateParentFieldFromRule(SmartDateRule $rrule): bool {
    $entity = $rrule->getParentEntity();
    if (!$entity) {
      return FALSE;
    }

    $rid = (int) $rrule->id();
    $field_name = $rrule->field_name->getString();
    if (!$field_name || !$entity->hasField($field_name)) {
      return FALSE;
    }

    $values = $entity->get($field_name)->getValue();
    $first_instance = FALSE;
    foreach ($values as $delta => $value) {
      if (isset($value['rrule']) && (int) $value['rrule'] === $rid) {
        if (!$first_instance) {
          $first_instance = $value;
        }
        unset($values[$delta]);
      }
    }
    if (!$first_instance) {
      return FALSE;
    }

    $instances = $rrule->getRuleInstances();
    foreach ($instances as $rrule_index => $instance) {
      $first_instance['value'] = $instance['value'];
      $first_instance['end_value'] = $instance['end_value'];
      $first_instance['duration'] = ((int) $instance['end_value'] - (int) $instance['value']) / 60;
      $first_instance['rrule_index'] = $rrule_index;
      $values[] = $first_instance;
    }

    $entity->set($field_name, $values);
    $entity->save();
    return TRUE;
  }

  /**
   * Resolves active or nearest recurring coordinator rule for a facilitator.
   */
  protected function resolveActiveCoordinatorRule(int $uid): ?SmartDateRule {
    if ($uid <= 0 || !$this->entityTypeManager->hasDefinition('profile')) {
      return NULL;
    }
    $bundle = \Drupal::config('appointment_facilitator.settings')->get('facilitator_profile_bundle') ?: 'coordinator';
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account) {
      return NULL;
    }
    $profiles = $this->entityTypeManager->getStorage('profile')->loadByUser($account, $bundle);
    if (!$profiles) {
      return NULL;
    }

    $profile = NULL;
    if (is_object($profiles)) {
      $profile = $profiles;
    }
    elseif (is_array($profiles)) {
      $first_key = array_key_first($profiles);
      $profile = $first_key !== NULL ? $profiles[$first_key] : NULL;
    }
    elseif ($profiles instanceof \Traversable) {
      foreach ($profiles as $item) {
        $profile = $item;
        break;
      }
    }

    if (!$profile || !$profile->hasField('field_coordinator_hours') || $profile->get('field_coordinator_hours')->isEmpty()) {
      return NULL;
    }

    $now = $this->time->getRequestTime();
    $current = [];
    $future = [];
    $past = [];
    foreach ($profile->get('field_coordinator_hours')->getValue() as $item) {
      $rid = isset($item['rrule']) ? (int) $item['rrule'] : 0;
      if ($rid <= 0) {
        continue;
      }
      $start = isset($item['value']) ? (int) $item['value'] : 0;
      $end = isset($item['end_value']) ? (int) $item['end_value'] : 0;
      if ($start <= $now && $end >= $now) {
        $current[] = ['rid' => $rid, 'start' => $start, 'end' => $end];
      }
      elseif ($start > $now) {
        $future[] = ['rid' => $rid, 'start' => $start, 'end' => $end];
      }
      else {
        $past[] = ['rid' => $rid, 'start' => $start, 'end' => $end];
      }
    }

    $selected_rid = NULL;
    if ($current) {
      usort($current, static fn($a, $b) => $a['start'] <=> $b['start']);
      $selected_rid = $current[0]['rid'];
    }
    elseif ($future) {
      usort($future, static fn($a, $b) => $a['start'] <=> $b['start']);
      $selected_rid = $future[0]['rid'];
    }
    elseif ($past) {
      usort($past, static fn($a, $b) => $b['end'] <=> $a['end']);
      $selected_rid = $past[0]['rid'];
    }

    if (!$selected_rid) {
      return NULL;
    }

    $rule = SmartDateRule::load((int) $selected_rid);
    return $rule instanceof SmartDateRule ? $rule : NULL;
  }

}
