<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Verifies appointment and reservation pages link to each other.
 *
 * @group appointment_facilitator
 */
class AppointmentReservationViewLinksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'options',
    'datetime',
    'datetime_range',
    'taxonomy',
    'appointment_facilitator',
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

    foreach (['appointment' => 'Appointment', 'reservation' => 'Reservation'] as $type => $label) {
      if (!NodeType::load($type)) {
        NodeType::create([
          'type' => $type,
          'name' => $label,
        ])->save();
      }
    }

    if (!NodeType::load('item')) {
      NodeType::create([
        'type' => 'item',
        'name' => 'Item',
      ])->save();
    }

    if (!Vocabulary::load('badges')) {
      Vocabulary::create([
        'vid' => 'badges',
        'name' => 'Badges',
      ])->save();
    }

    $this->ensureEntityReferenceField('reservation', 'field_source_appointment', 'node');
    $this->ensureEntityReferenceField('reservation', 'field_reservation_asset', 'node');
    $this->ensureEntityReferenceField('appointment', 'field_appointment_badges', 'taxonomy_term');
    $this->ensureEntityReferenceField('item', 'field_member_badges', 'taxonomy_term');
    $this->ensureListField('appointment', 'field_appointment_purpose', [
      'informational' => 'General Informational',
      'checkout' => 'Badge Checkout',
    ]);
    $this->ensureListField('appointment', 'field_appointment_status', [
      'scheduled' => 'scheduled',
      'canceled' => 'canceled',
    ]);
  }

  /**
   * Ensures appointment full view shows links to linked reservations.
   */
  public function testAppointmentViewShowsReservationLinks(): void {
    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Panel Saw Checkout',
    ]);
    $appointment->save();

    $reservation = Node::create([
      'type' => 'reservation',
      'title' => 'Panel Saw Hold',
      'field_source_appointment' => ['target_id' => $appointment->id()],
    ]);
    $reservation->save();

    $build = [];
    appointment_facilitator_entity_view($build, $appointment, 'full', 'en');

    $items = $build['appointment_related_reservations']['links']['#items'] ?? [];
    $this->assertCount(1, $items, 'Appointment page shows one linked reservation.');
    $this->assertSame('Panel Saw Hold', (string) $items[0]['#title']);
  }

  /**
   * Ensures reservation full view shows a link back to the appointment.
   */
  public function testReservationViewShowsAppointmentLink(): void {
    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Drill Press Checkout',
    ]);
    $appointment->save();

    $reservation = Node::create([
      'type' => 'reservation',
      'title' => 'Drill Press Hold',
      'field_source_appointment' => ['target_id' => $appointment->id()],
    ]);
    $reservation->save();

    $build = [];
    appointment_facilitator_entity_view($build, $reservation, 'full', 'en');

    $items = $build['reservation_source_appointment']['links']['#items'] ?? [];
    $this->assertCount(1, $items, 'Reservation page shows one source appointment link.');
    $this->assertSame('Drill Press Checkout', (string) $items[0]['#title']);
  }

  /**
   * Ensures appointment full view warns when no automatic reservation was made.
   */
  public function testAppointmentViewWarnsForAmbiguousReservationMapping(): void {
    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Laser Cutter',
    ]);
    $badge->save();

    foreach (['Laser 1', 'Laser 2'] as $tool_label) {
      $tool = Node::create([
        'type' => 'item',
        'title' => $tool_label,
        'field_member_badges' => [['target_id' => $badge->id()]],
      ]);
      $tool->save();
    }

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Laser Checkout',
      'field_appointment_badges' => [['target_id' => $badge->id()]],
      'field_appointment_purpose' => 'checkout',
      'field_appointment_status' => 'scheduled',
    ]);
    $appointment->save();

    $build = [];
    appointment_facilitator_entity_view($build, $appointment, 'full', 'en');

    $markup = (string) ($build['appointment_reservation_warning']['message']['#markup'] ?? '');
    $this->assertStringContainsString('No automatic tool reservation was made', $markup);
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

}
