<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Verifies the appointments dashboard works without the Profile module.
 *
 * @group appointment_facilitator
 */
class AppointmentDashboardProfileOptionalTest extends KernelTestBase {

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
    'datetime_range',
    'appointment_facilitator',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node', 'appointment_facilitator']);

    if (!NodeType::load('appointment')) {
      NodeType::create([
        'type' => 'appointment',
        'name' => 'Appointment',
      ])->save();
    }

    $this->ensureEntityReferenceField('appointment', 'field_appointment_host', 'user');
    $this->ensureDateRangeField('appointment', 'field_appointment_timerange');
  }

  /**
   * Ensures dashboard cards render even when profile entity type is absent.
   */
  public function testDashboardDoesNotRequireProfileModule(): void {
    $member = User::create([
      'name' => 'dashboard_member',
      'mail' => 'dashboard-member@example.com',
      'status' => 1,
    ]);
    $member->save();

    $host = User::create([
      'name' => 'dashboard_host',
      'mail' => 'dashboard-host@example.com',
      'status' => 1,
    ]);
    $host->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Profile optional dashboard test',
      'status' => 1,
      'uid' => (int) $member->id(),
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_appointment_timerange' => [[
        'value' => '2030-03-12T19:00:00',
        'end_value' => '2030-03-12T20:00:00',
      ]],
    ]);
    $appointment->save();

    $this->container->get('current_user')->setAccount($member);

    $controller = \Drupal\appointment_facilitator\Controller\AppointmentDashboardController::create($this->container);
    $build = $controller->dashboard();

    $this->assertSame('appointment_dashboard', $build['#theme']);
    $this->assertCount(1, $build['#upcoming']);
    $this->assertSame('', $build['#upcoming'][0]['#host_area'], 'Host area stays empty without the Profile module.');
    $this->assertSame('/node/' . $appointment->id(), $build['#upcoming'][0]['#url']);
  }

  /**
   * Ensures a date range field exists on appointment nodes.
   */
  protected function ensureDateRangeField(string $bundle, string $field_name): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'daterange',
        'settings' => ['datetime_type' => 'datetime'],
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

}
