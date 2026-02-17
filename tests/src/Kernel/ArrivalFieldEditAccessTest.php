<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Verifies facilitator arrival tracking fields are not user-editable by default.
 *
 * @group appointment_facilitator
 */
class ArrivalFieldEditAccessTest extends KernelTestBase {

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

    $this->ensureListField('appointment', 'field_facilitator_arrival_status', [
      'on_time' => 'On time',
      'late_grace' => 'Late (grace)',
      'late' => 'Late',
      'missed' => 'Missed',
    ]);
    $this->ensureDatetimeField('appointment', 'field_facilitator_arrival_time');
  }

  /**
   * Tests regular users cannot edit arrival fields while privileged users can.
   */
  public function testArrivalFieldsRequirePrivilegedPermission(): void {
    // Consume UID 1 (superuser) so permission checks for test users are real.
    User::create([
      'name' => 'kernel_superuser_seed',
      'mail' => 'kernel-superuser-seed@example.com',
      'status' => 1,
    ])->save();

    Role::create([
      'id' => 'arrival_manager',
      'label' => 'Arrival Manager',
      'permissions' => ['manage facilitator arrival fields'],
    ])->save();

    $regular_user = User::create([
      'name' => 'regular_arrival_access_user',
      'mail' => 'regular-arrival-access@example.com',
      'status' => 1,
    ]);
    $regular_user->save();

    $arrival_manager = User::create([
      'name' => 'arrival_manager_user',
      'mail' => 'arrival-manager-access@example.com',
      'status' => 1,
      'roles' => ['arrival_manager'],
    ]);
    $arrival_manager->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Arrival access control test',
      'status' => 1,
      'field_facilitator_arrival_status' => 'late',
      'field_facilitator_arrival_time' => '2026-02-17T10:00:00',
    ]);
    $appointment->save();

    $status_field = $appointment->get('field_facilitator_arrival_status');
    $time_field = $appointment->get('field_facilitator_arrival_time');

    $this->assertFalse($status_field->access('edit', $regular_user), 'Regular user cannot edit arrival status.');
    $this->assertFalse($time_field->access('edit', $regular_user), 'Regular user cannot edit arrival time.');

    $this->assertTrue($status_field->access('edit', $arrival_manager), 'Arrival manager can edit arrival status.');
    $this->assertTrue($time_field->access('edit', $arrival_manager), 'Arrival manager can edit arrival time.');
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
   * Ensures a datetime field exists for appointment nodes.
   */
  protected function ensureDatetimeField(string $bundle, string $field_name): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'datetime',
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

}
