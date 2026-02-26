<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Verifies attendees can leave a session without canceling it.
 *
 * @group appointment_facilitator
 */
class JoinAppointmentLeaveSessionTest extends KernelTestBase {

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
    'appointment_facilitator',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['node', 'appointment_facilitator']);

    if (!NodeType::load('appointment')) {
      NodeType::create([
        'type' => 'appointment',
        'name' => 'Appointment',
      ])->save();
    }

    $this->ensureEntityReferenceField('appointment', 'field_appointment_attendees', 'user');
  }

  /**
   * Ensures leaveSubmit removes only the current attendee.
   */
  public function testAttendeeCanLeaveSession(): void {
    $attendee = User::create([
      'name' => 'leave_attendee_user',
      'mail' => 'leave-attendee-user@example.com',
      'status' => 1,
    ]);
    $attendee->save();

    $other = User::create([
      'name' => 'leave_other_user',
      'mail' => 'leave-other-user@example.com',
      'status' => 1,
    ]);
    $other->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Leave session test',
      'status' => 1,
      'field_appointment_attendees' => [
        ['target_id' => $attendee->id()],
        ['target_id' => $other->id()],
      ],
    ]);
    $appointment->save();

    $this->container->get('current_user')->setAccount($attendee);

    /** @var \Drupal\appointment_facilitator\Form\JoinAppointmentForm $form */
    $form = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(\Drupal\appointment_facilitator\Form\JoinAppointmentForm::class);

    $form_state = new FormState();
    $form_state->setValue('node_id', $appointment->id());
    $form_array = [];

    $form->leaveSubmit($form_array, $form_state);

    $reloaded = Node::load($appointment->id());
    $this->assertNotNull($reloaded);
    $attendees = array_map(
      static fn(array $item): int => (int) ($item['target_id'] ?? 0),
      $reloaded->get('field_appointment_attendees')->getValue()
    );

    $this->assertNotContains((int) $attendee->id(), $attendees, 'Current attendee was removed.');
    $this->assertContains((int) $other->id(), $attendees, 'Other attendees remain.');
  }

  /**
   * Ensures an entity reference field exists on appointment nodes.
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
          'handler' => 'default:' . $target_type,
        ],
      ])->save();
    }
  }

}

