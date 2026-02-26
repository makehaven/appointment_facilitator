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
use Drupal\views\ViewExecutable;

/**
 * Verifies /appointments join-link visibility rules.
 *
 * @group appointment_facilitator
 */
class AppointmentsJoinLinkVisibilityTest extends KernelTestBase {

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
    $this->installConfig(['node', 'taxonomy', 'appointment_facilitator']);

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

    $this->ensureEntityReferenceField('node', 'appointment', 'field_appointment_attendees', 'user');
    $this->ensureEntityReferenceField('node', 'appointment', 'field_appointment_badges', 'taxonomy_term');
    $this->ensureEntityReferenceField('node', 'appointment', 'field_appointment_host', 'user');
    $this->ensureIntegerField('taxonomy_term', 'badges', 'field_badge_capacity');
  }

  /**
   * Join link is hidden when effective capacity is host-only.
   */
  public function testJoinLinkHiddenWhenJoinCapacityIsZero(): void {
    $this->config('appointment_facilitator.settings')
      ->set('always_show_join', FALSE)
      ->save();

    $viewer = User::create([
      'name' => 'viewer_zero_cap',
      'mail' => 'viewer-zero-cap@example.com',
      'status' => 1,
    ]);
    $viewer->save();

    $host = User::create([
      'name' => 'host_zero_cap',
      'mail' => 'host-zero-cap@example.com',
      'status' => 1,
    ]);
    $host->save();

    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Zero Join Badge',
      'field_badge_capacity' => 0,
    ]);
    $badge->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Zero capacity appointment',
      'status' => 1,
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_appointment_badges' => [['target_id' => $badge->id()]],
      'field_appointment_attendees' => [],
    ]);
    $appointment->save();

    $this->container->get('current_user')->setAccount($viewer);

    $variables = $this->buildViewRowVariables($appointment);
    appointment_facilitator_preprocess_views_view_fields($variables);

    $this->assertStringNotContainsString('/appointment/' . $appointment->id() . '/join', $variables['fields']['nothing']->content);
  }

  /**
   * Join link appears when capacity is open.
   */
  public function testJoinLinkShownWhenCapacityOpen(): void {
    $this->config('appointment_facilitator.settings')
      ->set('always_show_join', FALSE)
      ->save();

    $viewer = User::create([
      'name' => 'viewer_open_cap',
      'mail' => 'viewer-open-cap@example.com',
      'status' => 1,
    ]);
    $viewer->save();

    $host = User::create([
      'name' => 'host_open_cap',
      'mail' => 'host-open-cap@example.com',
      'status' => 1,
    ]);
    $host->save();

    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Open Join Badge',
      'field_badge_capacity' => 3,
    ]);
    $badge->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Open capacity appointment',
      'status' => 1,
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_appointment_badges' => [['target_id' => $badge->id()]],
      'field_appointment_attendees' => [],
    ]);
    $appointment->save();

    $this->container->get('current_user')->setAccount($viewer);

    $variables = $this->buildViewRowVariables($appointment);
    appointment_facilitator_preprocess_views_view_fields($variables);

    $this->assertStringContainsString('/appointment/' . $appointment->id() . '/join', $variables['fields']['nothing']->content);
  }

  /**
   * Builds a minimal views row-variable array for preprocess testing.
   */
  protected function buildViewRowVariables(Node $appointment): array {
    $view = $this->getMockBuilder(ViewExecutable::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['id'])
      ->getMock();
    $view->method('id')->willReturn('appointments');
    $view->current_display = 'appointment_list';

    return [
      'view' => $view,
      'row' => (object) ['_entity' => $appointment],
      'fields' => [
        'nothing' => (object) [
          'content' => '<a href="/node/' . $appointment->id() . '" class="btn btn-outline-primary btn-sm">View</a>',
        ],
      ],
    ];
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

  /**
   * Ensures an integer field exists.
   */
  protected function ensureIntegerField(string $entity_type, string $bundle, string $field_name): void {
    if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => 'integer',
      ])->save();
    }

    if (!FieldConfig::loadByName($entity_type, $bundle, $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'bundle' => $bundle,
        'label' => $field_name,
      ])->save();
    }
  }

}

