<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;

/**
 * Verifies join flow respects computed badge capacity limits.
 *
 * @group appointment_facilitator
 */
class JoinAppointmentCapacityGuardTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'options',
    'datetime',
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
    $this->installConfig(['node', 'taxonomy', 'appointment_facilitator']);

    if (!NodeType::load('appointment')) {
      NodeType::create([
        'type' => 'appointment',
        'name' => 'Appointment',
      ])->save();
    }

    if (!Vocabulary::load('badges')) {
      Vocabulary::create([
        'vid' => 'badges',
        'name' => 'Badges',
      ])->save();
    }

    $this->ensureEntityReferenceField('node', 'appointment', 'field_appointment_attendees', 'user');
    $this->ensureEntityReferenceField('node', 'appointment', 'field_appointment_badges', 'taxonomy_term');
    $this->ensureEntityReferenceField('node', 'appointment', 'field_appointment_host', 'user');
    $this->ensureIntegerField('taxonomy_term', 'badges', 'field_badge_capacity');
  }

  /**
   * Ensures third attendee cannot join when effective capacity is two.
   */
  public function testJoinBlockedWhenBadgeCapacityReached(): void {
    // First created user gets UID 1 (bypass) to avoid access noise in test.
    $joiner = User::create([
      'name' => 'capacity_joiner',
      'mail' => 'capacity-joiner@example.com',
      'status' => 1,
    ]);
    $joiner->save();

    $host = User::create([
      'name' => 'capacity_host',
      'mail' => 'capacity-host@example.com',
      'status' => 1,
    ]);
    $host->save();

    $attendee_a = User::create([
      'name' => 'capacity_attendee_a',
      'mail' => 'capacity-attendee-a@example.com',
      'status' => 1,
    ]);
    $attendee_a->save();

    $attendee_b = User::create([
      'name' => 'capacity_attendee_b',
      'mail' => 'capacity-attendee-b@example.com',
      'status' => 1,
    ]);
    $attendee_b->save();

    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Capacity Two Badge',
      'field_badge_capacity' => 2,
    ]);
    $badge->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Capacity guard test',
      'status' => 1,
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_appointment_badges' => [['target_id' => $badge->id()]],
      'field_appointment_attendees' => [
        ['target_id' => $attendee_a->id()],
        ['target_id' => $attendee_b->id()],
      ],
    ]);
    $appointment->save();

    $this->assertSame(2, appointment_facilitator_effective_capacity($appointment), 'Effective capacity is derived from badge cap.');

    $this->container->get('current_user')->setAccount($joiner);

    /** @var \Drupal\appointment_facilitator\Form\JoinAppointmentForm $form */
    $form = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(\Drupal\appointment_facilitator\Form\JoinAppointmentForm::class);

    $form_state = new FormState();
    $form_state->setValue('node_id', $appointment->id());
    $form_array = [];
    $form->submitForm($form_array, $form_state);

    $reloaded = Node::load($appointment->id());
    $this->assertNotNull($reloaded);
    $attendee_ids = array_map(
      static fn(array $item): int => (int) ($item['target_id'] ?? 0),
      $reloaded->get('field_appointment_attendees')->getValue()
    );

    $this->assertCount(2, $attendee_ids, 'Attendee count remains at capacity.');
    $this->assertNotContains((int) $joiner->id(), $attendee_ids, 'Joiner was not added past capacity.');
  }

  /**
   * Ensures a reference field exists.
   */
  protected function ensureEntityReferenceField(string $entity_type, string $bundle, string $field_name, string $target_type): void {
    if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => 'entity_reference',
        'settings' => ['target_type' => $target_type],
        'cardinality' => -1,
      ])->save();
    }

    if (!FieldConfig::loadByName($entity_type, $bundle, $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'bundle' => $bundle,
        'label' => $field_name,
        'settings' => ['handler' => 'default:' . $target_type],
      ])->save();
    }
  }

  /**
   * Ensures an integer field exists.
   */
  protected function ensureIntegerField(string $entity_type, string $bundle, string $field_name): void {
    if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => 'integer',
      ])->save();
    }

    if (!FieldConfig::loadByName($entity_type, $bundle, $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'bundle' => $bundle,
        'label' => $field_name,
      ])->save();
    }
  }

}

