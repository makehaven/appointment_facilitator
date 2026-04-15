<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

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
 * Tests the non-issuer warning on badge_request presave.
 *
 * @group appointment_facilitator
 */
class BadgeIssuerWarningPresaveTest extends KernelTestBase {

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

    // Create an uninstantiated user 1 so subsequent users aren't treated as
    // the super-user (which bypasses permission checks).
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
  }

  /**
   * Regular member auto-awarded a badge (via quiz) should NOT see the warning.
   */
  public function testMemberSelfAssignmentProducesNoWarning(): void {
    $member = $this->createUser('member@example.com');
    $issuer = $this->createUser('issuer@example.com');

    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Drill Press',
      'field_badge_issuer' => [['target_id' => $issuer->id()]],
    ]);
    $badge->save();

    // Act as the member (mimicking quiz auto-assignment flow).
    $this->container->get('current_user')->setAccount($member);

    $node = Node::create([
      'type' => 'badge_request',
      'title' => 'Auto-awarded',
      'uid' => (int) $member->id(),
      'field_badge_requested' => ['target_id' => $badge->id()],
      'field_member_to_badge' => ['target_id' => $member->id()],
      'field_badge_status' => 'active',
    ]);
    $node->save();

    $this->assertNoIssuerWarning();
  }

  /**
   * A regular non-member, non-issuer user should NOT see the warning either.
   */
  public function testRegularUserWithoutEditPermissionProducesNoWarning(): void {
    $member = $this->createUser('target@example.com');
    $random = $this->createUser('random@example.com');
    $issuer = $this->createUser('issuer2@example.com');

    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Lathe',
      'field_badge_issuer' => [['target_id' => $issuer->id()]],
    ]);
    $badge->save();

    $this->container->get('current_user')->setAccount($random);

    Node::create([
      'type' => 'badge_request',
      'title' => 'No permission',
      'uid' => (int) $random->id(),
      'field_badge_requested' => ['target_id' => $badge->id()],
      'field_member_to_badge' => ['target_id' => $member->id()],
      'field_badge_status' => 'active',
    ])->save();

    $this->assertNoIssuerWarning();
  }

  /**
   * A non-issuer who CAN edit badge requests (facilitator) SHOULD see it.
   */
  public function testNonIssuerFacilitatorSeesWarning(): void {
    $member = $this->createUser('member2@example.com');
    $issuer = $this->createUser('issuer3@example.com');

    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'CNC',
      'field_badge_issuer' => [['target_id' => $issuer->id()]],
    ]);
    $badge->save();

    $role = Role::create([
      'id' => 'facilitator',
      'label' => 'Facilitator',
    ]);
    $role->grantPermission('edit any badge_request content');
    $role->save();

    $facilitator = $this->createUser('fac@example.com', ['facilitator']);

    $this->container->get('current_user')->setAccount($facilitator);

    Node::create([
      'type' => 'badge_request',
      'title' => 'Facilitator approving',
      'uid' => (int) $facilitator->id(),
      'field_badge_requested' => ['target_id' => $badge->id()],
      'field_member_to_badge' => ['target_id' => $member->id()],
      'field_badge_status' => 'active',
    ])->save();

    $messages = \Drupal::messenger()->messagesByType('warning');
    $joined = implode(' ', array_map('strval', $messages));
    $this->assertStringContainsString('not currently listed as an issuer', $joined);
  }

  /**
   * An actual issuer should NOT see the warning.
   */
  public function testIssuerSeesNoWarning(): void {
    $member = $this->createUser('member3@example.com');
    $issuer = $this->createUser('issuer4@example.com');

    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Welder',
      'field_badge_issuer' => [['target_id' => $issuer->id()]],
    ]);
    $badge->save();

    $this->container->get('current_user')->setAccount($issuer);

    Node::create([
      'type' => 'badge_request',
      'title' => 'Issuer approving',
      'uid' => (int) $issuer->id(),
      'field_badge_requested' => ['target_id' => $badge->id()],
      'field_member_to_badge' => ['target_id' => $member->id()],
      'field_badge_status' => 'active',
    ])->save();

    $this->assertNoIssuerWarning();
  }

  /**
   * Helper: assert no issuer warning was set.
   */
  protected function assertNoIssuerWarning(): void {
    $messages = \Drupal::messenger()->messagesByType('warning') ?: [];
    $joined = implode(' ', array_map('strval', $messages));
    $this->assertStringNotContainsString('not currently listed as an issuer', $joined);
    \Drupal::messenger()->deleteAll();
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
