<?php

declare(strict_types=1);

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\appointment_facilitator\Service\BadgePopularityStats;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Covers the new query methods on the popularity-stats service.
 *
 * Regression coverage for the bug where the visible card count differed
 * from the inline tool list: the union over `field_member_badges` and
 * `field_additional_badges` could double-count a tool that referenced
 * the badge in both fields, or stale node_list:item cache could return
 * a count that didn't match access-filtered renders.
 *
 * @coversDefaultClass \Drupal\appointment_facilitator\Service\BadgePopularityStats
 * @group appointment_facilitator
 */
class BadgePopularityStatsTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'options',
    'taxonomy',
    'datetime',
    'appointment_facilitator',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'taxonomy', 'appointment_facilitator']);

    // Roles — countActiveMakers() looks for the `member` role.
    if (!Role::load('member')) {
      Role::create(['id' => 'member', 'label' => 'Member'])->save();
    }

    // Content types referenced by the queries.
    if (!NodeType::load('item')) {
      NodeType::create(['type' => 'item', 'name' => 'Item'])->save();
    }
    if (!NodeType::load('badge_request')) {
      NodeType::create(['type' => 'badge_request', 'name' => 'Badge request'])->save();
    }

    // Vocabularies.
    if (!Vocabulary::load('badges')) {
      Vocabulary::create(['vid' => 'badges', 'name' => 'Badges'])->save();
    }

    // Fields the queries scan.
    $this->ensureEntityReferenceField('node', 'item', 'field_member_badges', 'taxonomy_term');
    $this->ensureEntityReferenceField('node', 'item', 'field_additional_badges', 'taxonomy_term');
    $this->ensureEntityReferenceField('taxonomy_term', 'badges', 'field_badge_prerequisite', 'taxonomy_term');
    $this->ensureEntityReferenceField('node', 'badge_request', 'field_badge_requested', 'taxonomy_term');
    $this->ensureEntityReferenceField('node', 'badge_request', 'field_member_to_badge', 'user');
    $this->ensureListStringField('node', 'badge_request', 'field_badge_status', ['active' => 'Active', 'pending' => 'Pending']);
  }

  /**
   * Tools that reference a badge in both fields should count ONCE.
   */
  public function testCountToolsForBadgeDeduplicatesAcrossFields(): void {
    $badge = $this->createBadge('Table Saw');

    // Tool A: badge in field_member_badges only — should count.
    $this->createTool('Saw A', [(int) $badge->id()], []);
    // Tool B: badge in field_additional_badges only — should count.
    $this->createTool('Saw B', [], [(int) $badge->id()]);
    // Tool C: badge in BOTH — should count once, not twice.
    $this->createTool('Saw C', [(int) $badge->id()], [(int) $badge->id()]);

    $stats = $this->service()->getStats($badge);
    $this->assertSame(3, $stats['tool_count'], 'Tool counted once even when referenced in both badge fields.');
  }

  /**
   * Unpublished tools should not count.
   */
  public function testCountToolsForBadgeIgnoresUnpublished(): void {
    $badge = $this->createBadge('Drill');
    $this->createTool('Live drill', [(int) $badge->id()], [], TRUE);
    $this->createTool('Archived drill', [(int) $badge->id()], [], FALSE);

    $stats = $this->service()->getStats($badge);
    $this->assertSame(1, $stats['tool_count']);
  }

  /**
   * Downstream demand: count badges that list THIS badge as a prerequisite.
   */
  public function testCountUnlocksForBadgeReportsDownstreamDemand(): void {
    $foundational = $this->createBadge('Wood Shop Orientation');
    $this->createBadge('Table Saw', [$foundational->id()]);
    $this->createBadge('Band Saw', [$foundational->id()]);
    $this->createBadge('Acrylic Bender'); // unrelated

    $stats = $this->service()->getStats($foundational);
    $this->assertSame(2, $stats['unlocks'], 'Returns number of downstream badges that require this one.');
  }

  /**
   * Holders + percent_of_active math.
   */
  public function testHolderCountAndPercent(): void {
    $badge = $this->createBadge('Test Badge');

    // Three active members; one of them holds the badge.
    [$u1, $u2, $u3] = [
      $this->createMember(),
      $this->createMember(),
      $this->createMember(),
    ];
    $this->createBadgeRequest((int) $badge->id(), (int) $u1->id(), 'active');
    // u2 has a pending request — shouldn't count as holder.
    $this->createBadgeRequest((int) $badge->id(), (int) $u2->id(), 'pending');

    $stats = $this->service()->getStats($badge);
    $this->assertSame(1, $stats['holders']);
    $this->assertSame(3, $stats['active_makers']);
    $this->assertSame(33, $stats['percent_of_active'], 'Rounded percent of active members holding the badge.');
  }

  /**
   * Recent activity: badge_request rows created inside the window count.
   */
  public function testEarnedLast30dWindow(): void {
    $badge = $this->createBadge('Recent Badge');
    $u1 = $this->createMember();
    $u2 = $this->createMember();
    $now = \Drupal::time()->getRequestTime();
    // Recent: 5 days ago.
    $this->createBadgeRequest((int) $badge->id(), (int) $u1->id(), 'active', $now - (5 * 86400));
    // Stale: 60 days ago — outside the 30d window.
    $this->createBadgeRequest((int) $badge->id(), (int) $u2->id(), 'active', $now - (60 * 86400));

    $stats = $this->service()->getStats($badge);
    $this->assertSame(1, $stats['earned_last_30d']);
    $this->assertSame(5, $stats['last_earned_days_ago']);
  }

  // ── Helpers ────────────────────────────────────────────────────────

  protected function service(): BadgePopularityStats {
    // The static cache on the service is per-request; tests share it,
    // which makes assertions about counts on the SAME badge multiply if
    // we don't bypass. Always read fresh by invalidating tags.
    \Drupal\Core\Cache\Cache::invalidateTags(['node_list:badge_request', 'node_list:item']);
    return $this->container->get('appointment_facilitator.badge_popularity');
  }

  protected function createBadge(string $name, array $prereq_tids = []): Term {
    $term = Term::create([
      'vid' => 'badges',
      'name' => $name,
      'field_badge_prerequisite' => $prereq_tids,
    ]);
    $term->save();
    return $term;
  }

  protected function createTool(string $title, array $member_badges, array $additional_badges, bool $published = TRUE): Node {
    $node = Node::create([
      'type' => 'item',
      'title' => $title,
      'status' => $published ? 1 : 0,
      'field_member_badges' => $member_badges,
      'field_additional_badges' => $additional_badges,
    ]);
    $node->save();
    return $node;
  }

  protected function createMember(): User {
    static $counter = 0;
    $counter++;
    $user = User::create([
      'name' => 'member_' . $counter,
      'mail' => 'member_' . $counter . '@example.test',
      'status' => 1,
      'roles' => ['member'],
    ]);
    $user->save();
    return $user;
  }

  protected function createBadgeRequest(int $badge_tid, int $uid, string $status, ?int $created = NULL): Node {
    $node = Node::create([
      'type' => 'badge_request',
      'title' => 'Request for ' . $badge_tid,
      'status' => 1,
      'created' => $created ?? \Drupal::time()->getRequestTime(),
      'field_badge_requested' => [$badge_tid],
      'field_member_to_badge' => [$uid],
      'field_badge_status' => $status,
    ]);
    $node->save();
    return $node;
  }

  protected function ensureEntityReferenceField(string $entity_type, string $bundle, string $field_name, string $target_type, int $cardinality = -1): void {
    if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => 'entity_reference',
        'settings' => ['target_type' => $target_type],
        'cardinality' => $cardinality,
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

  protected function ensureListStringField(string $entity_type, string $bundle, string $field_name, array $allowed_values): void {
    if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => 'list_string',
        'settings' => ['allowed_values' => $allowed_values],
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
