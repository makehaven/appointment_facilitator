<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;

/**
 * Verifies update_9017 provisions arrival tracking fields.
 *
 * @group appointment_facilitator
 */
class AppointmentFacilitatorUpdate9017Test extends KernelTestBase {

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
    'views',
    'comment',
    'smart_date',
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
  }

  /**
   * Tests that update_9017 creates arrival fields on appointment nodes.
   */
  public function testUpdate9017CreatesArrivalFields(): void {
    require_once DRUPAL_ROOT . '/modules/custom/appointment_facilitator/appointment_facilitator.install';

    appointment_facilitator_update_9017();

    $arrival_status_storage = FieldStorageConfig::loadByName('node', 'field_facilitator_arrival_status');
    $this->assertNotNull($arrival_status_storage);
    $this->assertSame('list_string', $arrival_status_storage->getType());

    $arrival_time_storage = FieldStorageConfig::loadByName('node', 'field_facilitator_arrival_time');
    $this->assertNotNull($arrival_time_storage);
    $this->assertSame('datetime', $arrival_time_storage->getType());

    $arrival_status_field = FieldConfig::loadByName('node', 'appointment', 'field_facilitator_arrival_status');
    $this->assertNotNull($arrival_status_field);

    $arrival_time_field = FieldConfig::loadByName('node', 'appointment', 'field_facilitator_arrival_time');
    $this->assertNotNull($arrival_time_field);
  }

}
