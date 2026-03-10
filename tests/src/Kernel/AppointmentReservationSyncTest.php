<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;

/**
 * Verifies checkout appointments create and sync tool reservations.
 *
 * @group appointment_facilitator
 */
class AppointmentReservationSyncTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'node',
    'taxonomy',
    'datetime',
    'datetime_range',
    'appointment_facilitator',
    'makerspace_reservations',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node', 'appointment_facilitator']);

    foreach ([
      'appointment' => 'Appointment',
      'reservation' => 'Reservation',
      'item' => 'Item',
    ] as $type => $label) {
      if (!NodeType::load($type)) {
        NodeType::create([
          'type' => $type,
          'name' => $label,
        ])->save();
      }
    }

    if (!Vocabulary::load('badges')) {
      Vocabulary::create([
        'vid' => 'badges',
        'name' => 'Badges',
      ])->save();
    }

    $this->ensureEntityReferenceField('appointment', 'field_appointment_badges', 'taxonomy_term');
    $this->ensureListField('appointment', 'field_appointment_purpose', [
      'informational' => 'General Informational (no badge)',
      'checkout' => 'Badge Checkout',
    ]);
    $this->ensureListField('appointment', 'field_appointment_status', [
      'scheduled' => 'scheduled',
      'canceled' => 'canceled',
    ]);
    $this->ensureDateRangeField('appointment', 'field_appointment_timerange');
    $this->ensureEntityReferenceField('item', 'field_member_badges', 'taxonomy_term');

    $this->ensureEntityReferenceField('reservation', 'field_reservation_asset', 'node');
    $this->ensureEntityReferenceField('reservation', 'field_source_appointment', 'node');
    $this->ensureDateRangeField('reservation', 'field_reservation_time_range');
    $this->ensureStringField('reservation', 'field_reservation_status');
    $this->ensureStringField('reservation', 'field_reservation_purpose');
  }

  /**
   * Ensures a checkout appointment creates and updates a linked reservation.
   */
  public function testCheckoutAppointmentCreatesAndUpdatesReservation(): void {
    $member = User::create([
      'name' => 'badging_member',
      'mail' => 'badging-member@example.com',
      'status' => 1,
    ]);
    $member->save();

    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Panel Saw',
    ]);
    $badge->save();

    $tool = Node::create([
      'type' => 'item',
      'title' => 'Panel Saw',
      'status' => 1,
      'field_member_badges' => [['target_id' => $badge->id()]],
    ]);
    $tool->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'uid' => (int) $member->id(),
      'field_appointment_badges' => [['target_id' => $badge->id()]],
      'field_appointment_purpose' => 'checkout',
      'field_appointment_status' => 'scheduled',
      'field_appointment_timerange' => [[
        'value' => '2026-03-11T19:00:00',
        'end_value' => '2026-03-11T20:00:00',
      ]],
    ]);
    $appointment->save();
    _appointment_facilitator_sync_tool_reservations($appointment);

    $reservations = $this->loadLinkedReservations((int) $appointment->id());
    $this->assertCount(1, $reservations, 'A linked reservation was created.');

    /** @var \Drupal\node\Entity\Node $reservation */
    $reservation = reset($reservations);
    $this->assertSame((int) $tool->id(), (int) $reservation->get('field_reservation_asset')->target_id);
    $this->assertSame('confirmed', (string) $reservation->get('field_reservation_status')->value);
    $this->assertSame('education', (string) $reservation->get('field_reservation_purpose')->value);
    $this->assertSame((int) $member->id(), (int) $reservation->getOwnerId());
    $this->assertSame('2026-03-11T19:00:00', (string) $reservation->get('field_reservation_time_range')->value);
    $this->assertSame('2026-03-11T20:00:00', (string) $reservation->get('field_reservation_time_range')->end_value);

    $reservation_id = (int) $reservation->id();
    $appointment->set('field_appointment_timerange', [[
      'value' => '2026-03-11T19:30:00',
      'end_value' => '2026-03-11T20:30:00',
    ]]);
    $appointment->save();
    _appointment_facilitator_sync_tool_reservations($appointment);

    $reloaded = Node::load($reservation_id);
    $this->assertNotNull($reloaded, 'Linked reservation still exists after update.');
    $this->assertSame('2026-03-11T19:30:00', (string) $reloaded->get('field_reservation_time_range')->value);
    $this->assertSame('2026-03-11T20:30:00', (string) $reloaded->get('field_reservation_time_range')->end_value);
  }

  /**
   * Ensures cancelling the appointment cancels the linked reservation.
   */
  public function testCancelledAppointmentCancelsLinkedReservation(): void {
    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Drill Press',
    ]);
    $badge->save();

    $tool = Node::create([
      'type' => 'item',
      'title' => 'Drill Press',
      'status' => 1,
      'field_member_badges' => [['target_id' => $badge->id()]],
    ]);
    $tool->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'field_appointment_badges' => [['target_id' => $badge->id()]],
      'field_appointment_purpose' => 'checkout',
      'field_appointment_status' => 'scheduled',
      'field_appointment_timerange' => [[
        'value' => '2026-03-12T10:00:00',
        'end_value' => '2026-03-12T11:00:00',
      ]],
    ]);
    $appointment->save();
    _appointment_facilitator_sync_tool_reservations($appointment);

    $reservations = $this->loadLinkedReservations((int) $appointment->id());
    $this->assertCount(1, $reservations, 'Initial linked reservation was created.');

    /** @var \Drupal\node\Entity\Node $reservation */
    $reservation = reset($reservations);
    $appointment->set('field_appointment_status', 'canceled');
    $appointment->save();
    _appointment_facilitator_sync_tool_reservations($appointment);

    $reloaded = Node::load((int) $reservation->id());
    $this->assertSame('cancelled', (string) $reloaded->get('field_reservation_status')->value);
  }

  /**
   * Ensures ambiguous badge mappings do not reserve every matching tool.
   */
  public function testAmbiguousBadgeDoesNotCreateReservation(): void {
    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Laser Cutter',
    ]);
    $badge->save();

    foreach (['Laser 1', 'Laser 2'] as $label) {
      $tool = Node::create([
        'type' => 'item',
        'title' => $label,
        'status' => 1,
        'field_member_badges' => [['target_id' => $badge->id()]],
      ]);
      $tool->save();
    }

    $appointment = Node::create([
      'type' => 'appointment',
      'field_appointment_badges' => [['target_id' => $badge->id()]],
      'field_appointment_purpose' => 'checkout',
      'field_appointment_status' => 'scheduled',
      'field_appointment_timerange' => [[
        'value' => '2026-03-13T13:00:00',
        'end_value' => '2026-03-13T14:00:00',
      ]],
    ]);
    $appointment->save();
    _appointment_facilitator_sync_tool_reservations($appointment);

    $this->assertCount(0, $this->loadLinkedReservations((int) $appointment->id()), 'No reservation is created when a badge maps to multiple tools.');
  }

  /**
   * Loads reservations linked to an appointment.
   *
   * @return \Drupal\node\Entity\Node[]
   *   Reservation nodes keyed by nid.
   */
  protected function loadLinkedReservations(int $appointment_id): array {
    $ids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'reservation')
      ->condition('field_source_appointment.target_id', $appointment_id)
      ->execute();

    return Node::loadMultiple($ids);
  }

  /**
   * Ensures an entity reference field exists on the requested bundle.
   */
  protected function ensureEntityReferenceField(string $bundle, string $field_name, string $target_type): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => $target_type,
        ],
        'cardinality' => -1,
      ])->save();
    }

    if (!FieldConfig::loadByName('node', $bundle, $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => $field_name,
        'settings' => [
          'handler' => 'default',
        ],
      ])->save();
    }
  }

  /**
   * Ensures a list field exists on the requested bundle.
   */
  protected function ensureListField(string $bundle, string $field_name, array $allowed_values): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'list_string',
        'settings' => [
          'allowed_values' => $allowed_values,
        ],
      ])->save();
    }

    if (!FieldConfig::loadByName('node', $bundle, $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => $field_name,
      ])->save();
    }
  }

  /**
   * Ensures a daterange field exists on the requested bundle.
   */
  protected function ensureDateRangeField(string $bundle, string $field_name): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'daterange',
        'settings' => [
          'datetime_type' => 'datetime',
        ],
      ])->save();
    }

    if (!FieldConfig::loadByName('node', $bundle, $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => $field_name,
      ])->save();
    }
  }

  /**
   * Ensures a string field exists on the requested bundle.
   */
  protected function ensureStringField(string $bundle, string $field_name): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'string',
        'settings' => [
          'max_length' => 255,
        ],
      ])->save();
    }

    if (!FieldConfig::loadByName('node', $bundle, $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => $field_name,
      ])->save();
    }
  }

}
