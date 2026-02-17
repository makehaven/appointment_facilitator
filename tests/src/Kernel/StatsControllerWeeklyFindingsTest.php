<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Verifies weekly issue findings are shown on the admin stats report.
 *
 * @group appointment_facilitator
 */
class StatsControllerWeeklyFindingsTest extends KernelTestBase {

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
    $this->installConfig(['system']);

    if (!NodeType::load('appointment')) {
      NodeType::create([
        'type' => 'appointment',
        'name' => 'Appointment',
      ])->save();
    }

    $this->ensureEntityReferenceField('appointment', 'field_appointment_host', 'user');
    $this->ensureDateField('appointment', 'field_appointment_date');
    $this->ensureListField('appointment', 'field_appointment_status', [
      'scheduled' => 'Scheduled',
      'canceled' => 'Canceled',
    ]);
    $this->ensureListField('appointment', 'field_appointment_result', [
      'met_successful' => 'Success',
      'met_unsuccesful' => 'Problems',
      'volunteer_absent' => 'No Show',
      'member_absent' => 'Member absent',
    ]);
    $this->ensureListField('appointment', 'field_facilitator_arrival_status', [
      'on_time' => 'On time',
      'late_grace' => 'Late (grace)',
      'late' => 'Late',
      'missed' => 'Missed',
    ]);
  }

  /**
   * Tests weekly findings include configured issue labels for facilitators.
   */
  public function testWeeklyFindingsShowsFlaggedFacilitator(): void {
    $facilitator = User::create([
      'name' => 'weekly_findings_facilitator',
      'mail' => 'weekly-findings-facilitator@example.com',
      'status' => 1,
    ]);
    $facilitator->save();

    $today = (new \DateTimeImmutable('now'))->format('Y-m-d');

    Node::create([
      'type' => 'appointment',
      'title' => 'Cancelled slot',
      'uid' => $facilitator->id(),
      'status' => 1,
      'field_appointment_host' => ['target_id' => $facilitator->id()],
      'field_appointment_date' => $today,
      'field_appointment_status' => 'canceled',
      'field_appointment_result' => 'met_unsuccesful',
      'field_facilitator_arrival_status' => 'late',
    ])->save();

    Node::create([
      'type' => 'appointment',
      'title' => 'No show slot',
      'uid' => $facilitator->id(),
      'status' => 1,
      'field_appointment_host' => ['target_id' => $facilitator->id()],
      'field_appointment_date' => $today,
      'field_appointment_status' => 'scheduled',
      'field_appointment_result' => 'volunteer_absent',
      'field_facilitator_arrival_status' => 'late_grace',
    ])->save();

    $controller = \Drupal\appointment_facilitator\Controller\StatsController::create($this->container);
    $build = $controller->overview();

    $this->assertArrayHasKey('weekly_findings', $build);
    $this->assertNotEmpty($build['weekly_findings']['#items']);

    $items_text = implode(' ', array_map(static fn($item) => (string) $item, $build['weekly_findings']['#items']));
    $this->assertStringContainsString('weekly_findings_facilitator', $items_text);
    $this->assertStringContainsString('session removed from schedule', $items_text);
    $this->assertStringContainsString('no show', $items_text);
    $this->assertStringContainsString('late', $items_text);
    $this->assertStringContainsString('cancelled', $items_text);
    $this->assertStringContainsString('problems', $items_text);
    $this->assertStringContainsString('latest details', $items_text);
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

  /**
   * Ensures a date field exists for appointment nodes.
   */
  protected function ensureDateField(string $bundle, string $field_name): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'datetime',
        'settings' => [
          'datetime_type' => 'date',
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
