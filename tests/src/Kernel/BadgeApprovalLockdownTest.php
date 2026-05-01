<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\Core\Access\AccessResultForbidden;
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
 * Tests that badge approval is gated by field_badge_issuer membership.
 *
 * @group appointment_facilitator
 */
class BadgeApprovalLockdownTest extends KernelTestBase {

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
      NodeType::create([
        'type' => 'badge_request',
        'name' => 'Badge Request',
      ])->save();
    }
    if (!Vocabulary::load('badges')) {
      Vocabulary::create(['vid' => 'badges', 'name' => 'Badges'])->save();
    }

    $this->ensureTermUserReferenceField('badges', 'field_badge_issuer');
    $this->ensureNodeEntityReferenceField('badge_request', 'field_badge_requested', 'taxonomy_term');
    $this->ensureNodeEntityReferenceField('badge_request', 'field_member_to_badge', 'user');
    $this->ensureNodeListField('badge_request', 'field_badge_status', [
      'pending' => 'Pending',
      'active' => 'Active',
      'rejected' => 'Rejected',
    ]);

    // Reserve uid=1 so other accounts aren't treated as super-user.
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
  }

  /**
   * Facilitator with approve perm but NOT on the issuer list cannot approve.
   */
  public function testFacilitatorNotOnIssuerListIsBlocked(): void {
    $issuer = $this->createUser('issuer@example.com');
    $member = $this->createUser('member@example.com');

    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Drill Press',
      'field_badge_issuer' => [['target_id' => $issuer->id()]],
    ]);
    $badge->save();

    $role = Role::create(['id' => 'facilitator', 'label' => 'Facilitator']);
    $role->grantPermission('approve badge requests');
    $role->grantPermission('edit field_badge_status');
    $role->save();
    $facilitator = $this->createUser('fac@example.com', ['facilitator']);

    $node = $this->createPendingBadgeRequest($badge, $member, $facilitator);

    $this->assertFalse(
      _appointment_facilitator_can_approve_badge_request($node, $facilitator),
      'Facilitator not on issuer list cannot approve.'
    );

    $access = $node->get('field_badge_status')->access('edit', $facilitator, TRUE);
    $this->assertInstanceOf(
      AccessResultForbidden::class,
      $access,
      'Field-level edit on field_badge_status is forbidden for non-issuer.'
    );
  }

  /**
   * Facilitator on the issuer list CAN approve.
   */
  public function testFacilitatorOnIssuerListCanApprove(): void {
    $member = $this->createUser('member2@example.com');

    $role = Role::create(['id' => 'facilitator', 'label' => 'Facilitator']);
    $role->grantPermission('approve badge requests');
    $role->grantPermission('edit field_badge_status');
    $role->save();
    $facilitator = $this->createUser('fac2@example.com', ['facilitator']);

    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Lathe',
      'field_badge_issuer' => [['target_id' => $facilitator->id()]],
    ]);
    $badge->save();

    $node = $this->createPendingBadgeRequest($badge, $member, $facilitator);

    $this->assertTrue(
      _appointment_facilitator_can_approve_badge_request($node, $facilitator),
      'Facilitator listed as issuer can approve.'
    );

    $access = $node->get('field_badge_status')->access('edit', $facilitator, TRUE);
    $this->assertFalse(
      $access instanceof AccessResultForbidden,
      'Field-level edit is not forbidden when user is on issuer list.'
    );
  }

  /**
   * Manager (edit any badge_request content) can approve regardless of list.
   */
  public function testManagerOverrideCanApprove(): void {
    $issuer = $this->createUser('issuer3@example.com');
    $member = $this->createUser('member3@example.com');

    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'CNC',
      'field_badge_issuer' => [['target_id' => $issuer->id()]],
    ]);
    $badge->save();

    $role = Role::create(['id' => 'manager', 'label' => 'Manager']);
    $role->grantPermission('edit any badge_request content');
    $role->grantPermission('edit field_badge_status');
    $role->save();
    $manager = $this->createUser('mgr@example.com', ['manager']);

    $node = $this->createPendingBadgeRequest($badge, $member, $manager);

    $this->assertTrue(
      _appointment_facilitator_can_approve_badge_request($node, $manager),
      'Manager override approves regardless of issuer list.'
    );

    $access = $node->get('field_badge_status')->access('edit', $manager, TRUE);
    $this->assertFalse(
      $access instanceof AccessResultForbidden,
      'Manager is not blocked at field-access on field_badge_status.'
    );
  }

  /**
   * User without `approve badge requests` cannot approve at all.
   */
  public function testUserWithoutApprovePermCannotApprove(): void {
    $member = $this->createUser('member4@example.com');
    $random = $this->createUser('random@example.com');

    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Soldering',
      'field_badge_issuer' => [['target_id' => $random->id()]],
    ]);
    $badge->save();

    $node = $this->createPendingBadgeRequest($badge, $member, $random);

    // User is on issuer list but lacks the role permission — still blocked.
    $this->assertFalse(
      _appointment_facilitator_can_approve_badge_request($node, $random),
      'Issuer list alone is not enough without approve perm.'
    );
  }

  /**
   * Helper: create a pending badge_request.
   */
  protected function createPendingBadgeRequest(Term $badge, User $member, User $author): Node {
    $node = Node::create([
      'type' => 'badge_request',
      'title' => 'Pending request',
      'uid' => (int) $author->id(),
      'field_badge_requested' => ['target_id' => $badge->id()],
      'field_member_to_badge' => ['target_id' => $member->id()],
      'field_badge_status' => 'pending',
    ]);
    $node->save();
    return $node;
  }

  /**
   * Helper: creates a user account.
   */
  protected function createUser(string $mail, array $roles = []): User {
    $user = User::create([
      'name' => $mail,
      'mail' => $mail,
      'status' => 1,
      'roles' => $roles,
    ]);
    $user->save();
    return $user;
  }

  protected function ensureTermUserReferenceField(string $bundle, string $field_name): void {
    if (!FieldStorageConfig::loadByName('taxonomy_term', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'taxonomy_term',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'user'],
        'cardinality' => -1,
      ])->save();
    }
    if (!FieldConfig::loadByName('taxonomy_term', $bundle, $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'taxonomy_term',
        'bundle' => $bundle,
        'label' => $field_name,
        'settings' => ['handler' => 'default:user'],
      ])->save();
    }
  }

  protected function ensureNodeEntityReferenceField(string $bundle, string $field_name, string $target_type): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => $target_type],
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

  protected function ensureNodeListField(string $bundle, string $field_name, array $allowed_values): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'list_string',
        'settings' => ['allowed_values' => $allowed_values],
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
