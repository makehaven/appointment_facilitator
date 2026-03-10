<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\asset_status\Service\AssetAvailability;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Verifies the badge offline gating logic.
 *
 * @group appointment_facilitator
 */
class BadgeOfflineGateTest extends KernelTestBase {

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
    'asset_status',
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
    $this->installConfig(['node', 'taxonomy', 'asset_status', 'appointment_facilitator']);

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

    // item_status vocabulary is created by asset_status module config.
    // But we need to ensure the fields on 'item' node type exist.
    $this->ensureEntityReferenceField('node', 'item', 'field_member_badges', 'taxonomy_term');
    $this->ensureEntityReferenceField('node', 'item', 'field_additional_badges', 'taxonomy_term');
    $this->ensureEntityReferenceField('node', 'item', 'field_item_status', 'taxonomy_term');
  }

  /**
   * Verifies that a badge with no tools is NOT offline.
   */
  public function testBadgeWithNoToolsIsNotOffline(): void {
    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'No Tool Badge',
    ]);
    $badge->save();

    /** @var \Drupal\appointment_facilitator\Service\BadgePrerequisiteGate $gate */
    $gate = $this->container->get('appointment_facilitator.badge_gate');

    $this->assertFalse($gate->isBadgeOffline($badge));
  }

  /**
   * Verifies that a badge with at least one usable tool is NOT offline.
   */
  public function testBadgeWithUsableToolIsNotOffline(): void {
    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Usable Tool Badge',
    ]);
    $badge->save();

    // 'Operational' is a usable status defined in AssetAvailability.
    $status_operational = Term::create([
      'vid' => 'item_status',
      'name' => 'Operational',
    ]);
    $status_operational->save();

    // Create a tool with Operational status.
    Node::create([
      'type' => 'item',
      'title' => 'Working Tool',
      'field_member_badges' => [['target_id' => $badge->id()]],
      'field_item_status' => [['target_id' => $status_operational->id()]],
      'status' => 1,
    ])->save();

    /** @var \Drupal\appointment_facilitator\Service\BadgePrerequisiteGate $gate */
    $gate = $this->container->get('appointment_facilitator.badge_gate');

    $this->assertFalse($gate->isBadgeOffline($badge));
  }

  /**
   * Verifies that a badge with only offline tools IS offline.
   */
  public function testBadgeWithOnlyOfflineToolsIsOffline(): void {
    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Broken Tool Badge',
    ]);
    $badge->save();

    // 'Offline for Maintenance' is NOT in USABLE_STATUSES in AssetAvailability.
    $status_broken = Term::create([
      'vid' => 'item_status',
      'name' => 'Offline for Maintenance',
    ]);
    $status_broken->save();

    // Create a tool with broken status.
    Node::create([
      'type' => 'item',
      'title' => 'Broken Tool',
      'field_member_badges' => [['target_id' => $badge->id()]],
      'field_item_status' => [['target_id' => $status_broken->id()]],
      'status' => 1,
    ])->save();

    /** @var \Drupal\appointment_facilitator\Service\BadgePrerequisiteGate $gate */
    $gate = $this->container->get('appointment_facilitator.badge_gate');

    $this->assertTrue($gate->isBadgeOffline($badge));
  }

  /**
   * Verifies mixed tool statuses.
   */
  public function testBadgeWithMixedToolStatusesIsNotOffline(): void {
    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Mixed Tool Badge',
    ]);
    $badge->save();

    $status_operational = Term::create(['vid' => 'item_status', 'name' => 'Operational']);
    $status_operational->save();
    $status_broken = Term::create(['vid' => 'item_status', 'name' => 'Offline for Maintenance']);
    $status_broken->save();

    // Broken tool.
    Node::create([
      'type' => 'item',
      'title' => 'Broken Tool',
      'field_member_badges' => [['target_id' => $badge->id()]],
      'field_item_status' => [['target_id' => $status_broken->id()]],
      'status' => 1,
    ])->save();

    // Working tool.
    Node::create([
      'type' => 'item',
      'title' => 'Working Tool',
      'field_member_badges' => [['target_id' => $badge->id()]],
      'field_item_status' => [['target_id' => $status_operational->id()]],
      'status' => 1,
    ])->save();

    /** @var \Drupal\appointment_facilitator\Service\BadgePrerequisiteGate $gate */
    $gate = $this->container->get('appointment_facilitator.badge_gate');

    $this->assertFalse($gate->isBadgeOffline($badge));
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

}
