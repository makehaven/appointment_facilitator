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
 * Verifies untitled appointments receive a generated fallback title on save.
 *
 * @group appointment_facilitator
 */
class AppointmentTitlePresaveTest extends KernelTestBase {

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
    $this->installConfig(['system', 'node', 'appointment_facilitator']);

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

    $this->ensureEntityReferenceField('appointment', 'field_appointment_host', 'user');
    $this->ensureEntityReferenceField('appointment', 'field_appointment_badges', 'taxonomy_term');
    $this->ensureListField('appointment', 'field_appointment_purpose', [
      'informational' => 'General Informational (no badge)',
      'checkout' => 'Badge Checkout',
      'project' => 'Advice on my project (no badge)',
      'other' => 'Other (specify in appointment note) (no badge)',
    ]);
    $this->ensureDateField('appointment', 'field_appointment_date');
  }

  /**
   * Ensures save succeeds when the appointment form leaves title blank.
   */
  public function testUntitledAppointmentGetsGeneratedTitle(): void {
    $host = User::create([
      'name' => 'facilitator_title_host',
      'mail' => 'facilitator-title-host@example.com',
      'status' => 1,
    ]);
    $host->save();

    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Table Saw',
    ]);
    $badge->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'uid' => 2,
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_appointment_badges' => [['target_id' => $badge->id()]],
      'field_appointment_purpose' => 'checkout',
      'field_appointment_date' => '2026-03-11',
    ]);

    $appointment->save();

    $this->assertNotSame('', trim((string) $appointment->label()), 'Appointment title was generated during presave.');
    $this->assertSame('Badge Checkout: Table Saw with facilitator_title_host on 2026-03-11', $appointment->label());
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
          'handler' => 'default',
        ],
      ])->save();
    }
  }

  /**
   * Ensures a list field exists on appointment nodes.
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
   * Ensures a date field exists on appointment nodes.
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
