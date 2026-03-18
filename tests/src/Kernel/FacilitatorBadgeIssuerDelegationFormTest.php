<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;

/**
 * Tests delegating badge issuer access from the facilitator dashboard.
 *
 * @group appointment_facilitator
 */
class FacilitatorBadgeIssuerDelegationFormTest extends KernelTestBase {

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
      Vocabulary::create([
        'vid' => 'badges',
        'name' => 'Badges',
      ])->save();
    }

    $this->ensureTermUserReferenceField('badges', 'field_badge_issuer');
    $this->ensureNodeEntityReferenceField('badge_request', 'field_badge_requested', 'taxonomy_term');
    $this->ensureNodeEntityReferenceField('badge_request', 'field_member_to_badge', 'user');
    $this->ensureNodeListField('badge_request', 'field_badge_status', [
      'pending' => 'Pending',
      'active' => 'Active',
      'rejected' => 'Rejected',
    ]);
  }

  /**
   * Ensures a direct issuer can delegate to someone who holds the same badge.
   */
  public function testDelegationRequiresSameBadgeAndAddsIssuer(): void {
    $issuer = User::create([
      'name' => 'issuer_user',
      'mail' => 'issuer@example.com',
      'status' => 1,
    ]);
    $issuer->save();

    $target = User::create([
      'name' => 'target_user',
      'mail' => 'target@example.com',
      'status' => 1,
    ]);
    $target->save();

    $other = User::create([
      'name' => 'other_user',
      'mail' => 'other@example.com',
      'status' => 1,
    ]);
    $other->save();

    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Laser Cutter',
      'field_badge_issuer' => [['target_id' => $issuer->id()]],
    ]);
    $badge->save();

    Node::create([
      'type' => 'badge_request',
      'title' => 'Active badge request',
      'status' => 1,
      'uid' => (int) $target->id(),
      'field_badge_requested' => ['target_id' => $badge->id()],
      'field_member_to_badge' => ['target_id' => $target->id()],
      'field_badge_status' => 'active',
    ])->save();

    $this->container->get('current_user')->setAccount($issuer);
    $form = \Drupal\appointment_facilitator\Form\FacilitatorBadgeIssuerDelegationForm::create($this->container);

    $valid_state = new FormState();
    $valid_state->setValues([
      'badge_tid' => (int) $badge->id(),
      'target_user' => (string) $target->id(),
    ]);
    $form_array = $form->buildForm([], $valid_state);
    $this->assertArrayHasKey('badge_tid', $form_array);
    $this->assertArrayHasKey('target_user', $form_array);

    $form->validateForm($form_array, $valid_state);
    $this->assertEmpty($valid_state->getErrors(), 'Users with the badge can receive issuer access.');

    $form->submitForm($form_array, $valid_state);
    $reloaded_badge = Term::load($badge->id());
    $issuer_ids = array_map(static fn(array $item): int => (int) ($item['target_id'] ?? 0), $reloaded_badge->get('field_badge_issuer')->getValue());
    sort($issuer_ids);

    $this->assertSame([(int) $issuer->id(), (int) $target->id()], $issuer_ids, 'Delegation adds the target user as a direct issuer for the same badge only.');

    $invalid_state = new FormState();
    $invalid_state->setValues([
      'badge_tid' => (int) $badge->id(),
      'target_user' => (string) $other->id(),
    ]);
    $form->validateForm($form_array, $invalid_state);
    $this->assertArrayHasKey('target_user', $invalid_state->getErrors(), 'Users without the badge cannot receive issuer access.');
  }

  /**
   * Ensures a user reference field exists on badge terms.
   */
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

  /**
   * Ensures a badge_request entity reference field exists.
   */
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

  /**
   * Ensures a list field exists on badge requests.
   */
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
