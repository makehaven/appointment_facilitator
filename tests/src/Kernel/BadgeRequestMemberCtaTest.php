<?php

declare(strict_types=1);

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
 * Tests the member next-step CTA on the badge_request canonical page.
 *
 * A member returning to a badge they're still earning lands on the
 * badge_request "instance" page, which otherwise only shows status. The CTA
 * keeps it from being a dead end by pointing the owner at the badge page to
 * schedule. Only the owner sees it; it is status-aware.
 *
 * @group appointment_facilitator
 */
class BadgeRequestMemberCtaTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
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
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'appointment_facilitator']);

    if (!NodeType::load('badge_request')) {
      NodeType::create(['type' => 'badge_request', 'name' => 'Badge Request'])->save();
    }
    if (!Vocabulary::load('badges')) {
      Vocabulary::create(['vid' => 'badges', 'name' => 'Badges'])->save();
    }

    $this->ensureNodeEntityReferenceField('badge_request', 'field_badge_requested', 'taxonomy_term');
    $this->ensureNodeEntityReferenceField('badge_request', 'field_member_to_badge', 'user');
    $this->ensureNodeListField('badge_request', 'field_badge_status', [
      'pending' => 'Pending',
      'active' => 'Active',
      'suspended' => 'Suspended',
    ]);
    $this->ensureNodeDateField('badge_request', 'field_class_completed_date');

    // uid 1 placeholder so created members aren't treated as the super-user.
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
  }

  /**
   * The owner of a pending request gets the in-progress certificate + schedule.
   */
  public function testPendingOwnerSeesScheduleCta(): void {
    [$member, $badge, $node] = $this->makeRequest('pending');

    $build = $this->buildFullViewAs($node, $member);
    $this->assertArrayHasKey('appointment_facilitator_member_cta', $build);
    $cta = $build['appointment_facilitator_member_cta'];
    $this->assertContains('mh-badge-cert--pending', $cta['#attributes']['class']);
    $this->assertSame($badge->label(), (string) $cta['title']['#value']);
    $this->assertStringContainsStringIgnoringCase('schedule', (string) $cta['actions']['badge']['#title']);
    $this->assertSame((int) $badge->id(), (int) $cta['actions']['badge']['#url']->getRouteParameters()['taxonomy_term']);
    $this->assertContains('user', $build['#cache']['contexts'] ?? []);
  }

  /**
   * A non-owner (e.g. staff) does not get the member CTA.
   */
  public function testNonOwnerDoesNotSeeCta(): void {
    [, , $node] = $this->makeRequest('pending');
    $other = User::create(['name' => 'someone_else', 'status' => 1]);
    $other->save();

    $build = $this->buildFullViewAs($node, $other);
    $this->assertArrayNotHasKey('appointment_facilitator_member_cta', $build);
  }

  /**
   * An active badge renders the "Earned" certificate seal + recipient.
   */
  public function testActiveOwnerSeesEarnedCertificate(): void {
    [$member, , $node] = $this->makeRequest('active');

    $build = $this->buildFullViewAs($node, $member);
    $this->assertArrayHasKey('appointment_facilitator_member_cta', $build);
    $cta = $build['appointment_facilitator_member_cta'];
    $this->assertContains('mh-badge-cert--earned', $cta['#attributes']['class']);
    $this->assertStringContainsStringIgnoringCase('earned', (string) $cta['seal']['#value']);
    $this->assertStringContainsString($member->getDisplayName(), (string) $cta['awarded_to']['#value']);
  }

  /**
   * Earned certificate shows the earned date and the awarding facilitator.
   */
  public function testActiveCertificateShowsDateAndAwarder(): void {
    $facilitator = User::create(['name' => 'fac_jane', 'status' => 1]);
    $facilitator->save();
    [$member, , $node] = $this->makeRequest('active');
    $node->set('field_class_completed_date', '2026-06-01T12:00:00');
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId((int) $facilitator->id());
    $node->save();

    $cta = $this->buildFullViewAs($node, $member)['appointment_facilitator_member_cta'];
    $this->assertArrayHasKey('meta', $cta);
    $meta = (string) $cta['meta']['#value'];
    $this->assertStringContainsString('Earned', $meta);
    $this->assertStringContainsString('June 1, 2026', $meta);
    $this->assertStringContainsString('Awarded by', $meta);
    $this->assertStringContainsString('fac_jane', $meta);
  }

  /**
   * Creates a badge term + member + badge_request node with the given status.
   *
   * @return array
   *   [member User, badge Term, badge_request Node].
   */
  protected function makeRequest(string $status): array {
    $member = User::create(['name' => 'member_' . $status, 'status' => 1]);
    $member->save();
    $badge = Term::create(['vid' => 'badges', 'name' => 'Router Table']);
    $badge->save();
    $node = Node::create([
      'type' => 'badge_request',
      'title' => 'Request',
      'field_badge_requested' => [['target_id' => $badge->id()]],
      'field_member_to_badge' => [['target_id' => $member->id()]],
      'field_badge_status' => $status,
    ]);
    $node->save();

    return [$member, $badge, $node];
  }

  /**
   * Runs the node-view hook worker as a given account and returns the build.
   */
  protected function buildFullViewAs(Node $node, User $account): array {
    $this->container->get('current_user')->setAccount($account);
    $build = [];
    _appointment_facilitator_build_node_view($build, $node, 'full');
    return $build;
  }

  /**
   * Creates a node entity-reference field.
   */
  protected function ensureNodeEntityReferenceField(string $bundle, string $field_name, string $target_type): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => $target_type],
        'cardinality' => 1,
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
   * Creates a node datetime field.
   */
  protected function ensureNodeDateField(string $bundle, string $field_name): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'datetime',
        'settings' => ['datetime_type' => 'datetime'],
        'cardinality' => 1,
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
   * Creates a node list_string field.
   */
  protected function ensureNodeListField(string $bundle, string $field_name, array $allowed_values): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'list_string',
        'settings' => ['allowed_values' => $allowed_values],
        'cardinality' => 1,
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
