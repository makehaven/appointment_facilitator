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
 * Verifies closed sessions reject direct join submissions.
 *
 * @group appointment_facilitator
 */
class JoinAppointmentClosedSessionTest extends KernelTestBase {

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
    $this->ensureEntityReferenceField('appointment', 'field_appointment_host', 'user');
    $this->ensureBooleanField('appointment', 'field_appointment_open_to_join');

    $this->config('appointment_facilitator.settings')
      ->set('system_wide_joiner_cap', 2)
      ->save();
  }

  /**
   * Ensures submitForm enforces the open-to-join flag.
   */
  public function testSubmitBlockedWhenAppointmentClosedToJoiners(): void {
    $joiner = User::create([
      'name' => 'closed_joiner',
      'mail' => 'closed-joiner@example.com',
      'status' => 1,
    ]);
    $joiner->save();

    $host = User::create([
      'name' => 'closed_host',
      'mail' => 'closed-host@example.com',
      'status' => 1,
    ]);
    $host->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Closed join session',
      'status' => 1,
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_appointment_open_to_join' => 0,
      'field_appointment_attendees' => [],
    ]);
    $appointment->save();

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
    $this->assertSame([], $reloaded->get('field_appointment_attendees')->getValue(), 'Joiner was not added to a closed session.');
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
        'settings' => ['target_type' => $target_type],
        'cardinality' => -1,
      ])->save();
    }

    if (!FieldConfig::loadByName('node', $bundle, $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => $field_name,
        'settings' => ['handler' => 'default:' . $target_type],
      ])->save();
    }
  }

  /**
   * Ensures a boolean field exists on appointment nodes.
   */
  protected function ensureBooleanField(string $bundle, string $field_name): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'boolean',
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
