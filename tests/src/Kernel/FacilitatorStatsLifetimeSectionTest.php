<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Verifies facilitator stats page includes lifetime summary output.
 *
 * @group appointment_facilitator
 */
class FacilitatorStatsLifetimeSectionTest extends KernelTestBase {

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

    $this->ensureEntityReferenceField('appointment', 'field_appointment_host', 'user');
  }

  /**
   * Tests overview render array includes lifetime section and values.
   */
  public function testLifetimeSectionPresent(): void {
    $facilitator = User::create([
      'name' => 'facilitator_lifetime_test',
      'mail' => 'facilitator-lifetime-test@example.com',
      'status' => 1,
    ]);
    $facilitator->save();

    Node::create([
      'type' => 'appointment',
      'title' => 'Lifetime sample appointment',
      'uid' => $facilitator->id(),
      'status' => 1,
      'field_appointment_host' => ['target_id' => $facilitator->id()],
    ])->save();

    /** @var \Drupal\Core\Session\AccountProxyInterface $current_user */
    $current_user = $this->container->get('current_user');
    $this->assertInstanceOf(AccountProxyInterface::class, $current_user);
    $current_user->setAccount($facilitator);

    $controller = \Drupal\appointment_facilitator\Controller\FacilitatorStatsController::create($this->container);
    $build = $controller->overview();

    $this->assertArrayHasKey('grid', $build);
    $this->assertArrayHasKey('lifetime_card', $build['grid']);
    $this->assertSame(
      'Lifetime summary (all-time activity)',
      (string) $build['grid']['lifetime_card']['lifetime']['#value']
    );

    $lifetime_rows = $build['grid']['lifetime_card']['lifetime_table']['#rows'];
    $this->assertNotEmpty($lifetime_rows);
    $this->assertSame('Appointments hosted', (string) $lifetime_rows[0][0]);
    $this->assertSame(1, (int) $lifetime_rows[0][1]);
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
