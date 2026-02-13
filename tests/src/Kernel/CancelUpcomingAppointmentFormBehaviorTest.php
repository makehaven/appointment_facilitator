<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Verifies cancellation behavior for facilitator session cancellation form.
 *
 * @group appointment_facilitator
 */
class CancelUpcomingAppointmentFormBehaviorTest extends KernelTestBase {

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
    'taxonomy',
    'views',
    'comment',
    'datetime',
    'smart_date',
    'smart_date_recur',
    'profile',
    'appointment_facilitator',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('profile');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'appointment_facilitator']);

    if (!NodeType::load('appointment')) {
      NodeType::create([
        'type' => 'appointment',
        'name' => 'Appointment',
      ])->save();
    }

    $this->ensureListField('appointment', 'field_appointment_status', [
      'scheduled' => 'Scheduled',
      'canceled' => 'Canceled',
    ]);
    $this->ensureListField('appointment', 'field_reservation_cancellation', [
      'Cancel' => 'Cancel this reservation',
    ]);
    $this->ensureEntityReferenceField('appointment', 'field_appointment_attendees', 'user');
  }

  /**
   * Ensures cancelAppointment marks cancellation field and keeps visibility/data.
   */
  public function testCancelAppointmentSetsCancellationFieldWithoutUnpublishing(): void {
    $host = User::create([
      'name' => 'facilitator_cancel_behavior',
      'mail' => 'facilitator-cancel-behavior@example.com',
      'status' => 1,
    ]);
    $host->save();

    $attendee = User::create([
      'name' => 'attendee_cancel_behavior',
      'mail' => 'attendee-cancel-behavior@example.com',
      'status' => 1,
    ]);
    $attendee->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Cancellation behavior test',
      'uid' => $host->id(),
      'status' => 1,
      'field_appointment_status' => 'scheduled',
      'field_appointment_attendees' => [['target_id' => $attendee->id()]],
    ]);
    $appointment->save();

    $form = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(\Drupal\appointment_facilitator\Form\CancelUpcomingAppointmentForm::class);

    $method = new \ReflectionMethod($form, 'cancelAppointment');
    $method->setAccessible(TRUE);
    $result = $method->invoke($form, $appointment);

    $this->assertTrue($result);

    $reloaded = Node::load($appointment->id());
    $this->assertNotNull($reloaded);
    $this->assertTrue($reloaded->isPublished(), 'Appointment remains published to allow cancellation automation.');
    $this->assertSame('canceled', (string) $reloaded->get('field_appointment_status')->value);
    $this->assertSame('Cancel', (string) $reloaded->get('field_reservation_cancellation')->value);
    $this->assertCount(1, $reloaded->get('field_appointment_attendees')->getValue(), 'Attendees are not cleared.');
  }

  /**
   * Ensures a list field exists for appointment nodes.
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
   * Ensures an entity reference field exists for appointment nodes.
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

