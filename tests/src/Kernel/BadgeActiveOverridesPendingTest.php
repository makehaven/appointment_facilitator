<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\appointment_facilitator\Service\BadgeUserStatusResolver;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;

/**
 * Active badge_request must win over a stale, more-recently-changed pending.
 *
 * Regression (cycle review 2026-06-15, waterjet badge): a member held both an
 * `active` and a leftover `pending` badge_request for the same badge. The
 * resolver loaded the most-recently-changed single request — the pending one —
 * so the badge page banner and tool-page next-steps reported "pending" next to
 * an earned badge.
 *
 * @group appointment_facilitator
 * @covers \Drupal\appointment_facilitator\Service\BadgeUserStatusResolver
 */
class BadgeActiveOverridesPendingTest extends KernelTestBase {

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
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'taxonomy', 'asset_status', 'appointment_facilitator']);

    if (!Vocabulary::load('badges')) {
      Vocabulary::create(['vid' => 'badges', 'name' => 'Badges'])->save();
    }
    if (!NodeType::load('badge_request')) {
      NodeType::create(['type' => 'badge_request', 'name' => 'Badge Request'])->save();
    }

    $this->ensureField('field_badge_status', 'string', ['max_length' => 64]);
    $this->ensureEntityReferenceField('field_member_to_badge', 'user');
    $this->ensureEntityReferenceField('field_badge_requested', 'taxonomy_term');
  }

  /**
   * Resolve() returns ACTIVE when an active record exists alongside pending.
   */
  public function testActiveWinsOverMoreRecentlyChangedPending(): void {
    $member = User::create([
      'name' => 'waterjet_member',
      'mail' => 'waterjet_member@example.com',
      'status' => 1,
    ]);
    $member->save();

    $badge = Term::create(['vid' => 'badges', 'name' => 'Waterjet']);
    $badge->save();

    $active = $this->createBadgeRequest($member->id(), $badge->id(), 'active');
    $pending = $this->createBadgeRequest($member->id(), $badge->id(), 'pending');

    // Force the pending record to be the most-recently-changed so the pre-fix
    // "latest changed" loader would have returned it.
    $this->forceChanged($active->id(), 1000);
    $this->forceChanged($pending->id(), 2000);
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();

    /** @var \Drupal\appointment_facilitator\Service\BadgeUserStatusResolver $resolver */
    $resolver = $this->container->get('appointment_facilitator.badge_user_status');
    $result = $resolver->resolve((int) $member->id(), $badge);

    $this->assertSame(
      BadgeUserStatusResolver::STATE_ACTIVE,
      $result['state'],
      'An active badge_request overrides a stale, newer pending duplicate.'
    );
    $this->assertSame((int) $active->id(), (int) $result['badge_request']->id());
  }

  /**
   * With only a pending record, resolve() still reports pending.
   */
  public function testPendingAloneStaysPending(): void {
    $member = User::create([
      'name' => 'pending_member',
      'mail' => 'pending_member@example.com',
      'status' => 1,
    ]);
    $member->save();

    $badge = Term::create(['vid' => 'badges', 'name' => 'Laser']);
    $badge->save();

    $this->createBadgeRequest($member->id(), $badge->id(), 'pending');

    $resolver = $this->container->get('appointment_facilitator.badge_user_status');
    $result = $resolver->resolve((int) $member->id(), $badge);

    $this->assertSame(BadgeUserStatusResolver::STATE_PENDING, $result['state']);
  }

  /**
   * Builds a badge_request node.
   */
  protected function createBadgeRequest(int $uid, int $tid, string $status): Node {
    $node = Node::create([
      'type' => 'badge_request',
      'title' => 'Badge request ' . $status,
      'status' => 1,
      'field_member_to_badge' => ['target_id' => $uid],
      'field_badge_requested' => ['target_id' => $tid],
      'field_badge_status' => ['value' => $status],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Forces the stored changed timestamp for deterministic ordering.
   */
  protected function forceChanged(int $nid, int $changed): void {
    \Drupal::database()->update('node_field_data')
      ->fields(['changed' => $changed])
      ->condition('nid', $nid)
      ->execute();
  }

  /**
   * Creates a node field storage + instance on badge_request if missing.
   */
  protected function ensureField(string $field_name, string $type, array $settings): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $settings,
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'badge_request', $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'badge_request',
        'label' => $field_name,
      ])->save();
    }
  }

  /**
   * Creates an entity_reference field on badge_request if missing.
   */
  protected function ensureEntityReferenceField(string $field_name, string $target_type): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => $target_type],
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'badge_request', $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'badge_request',
        'label' => $field_name,
      ])->save();
    }
  }

}
