<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Entity\EntityStorageException;
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
 * Tests preparation versus award gates using actual persisted badge records.
 *
 * @group appointment_facilitator
 */
class BadgePrerequisiteProgressionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'options', 'taxonomy',
    'appointment_facilitator',
  ];

  /**
   * The member earning both badges.
   */
  protected User $member;
  /**
   * The prerequisite badge.
   */
  protected Term $basic;
  /**
   * The badge requiring basic sewing.
   */
  protected Term $advanced;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'taxonomy_term'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    NodeType::create(['type' => 'badge_request', 'name' => 'Badge request'])->save();
    NodeType::create(['type' => 'appointment', 'name' => 'Appointment'])->save();
    Vocabulary::create(['vid' => 'badges', 'name' => 'Badges'])->save();
    foreach ([
      ['node', 'appointment', 'field_appointment_badges', 'taxonomy_term'],
      ['node', 'badge_request', 'field_badge_requested', 'taxonomy_term'],
      ['node', 'badge_request', 'field_member_to_badge', 'user'],
      ['taxonomy_term', 'badges', 'field_badge_prerequisite', 'taxonomy_term'],
    ] as [$type, $bundle, $name, $target]) {
      FieldStorageConfig::create([
        'entity_type' => $type,
        'field_name' => $name,
        'type' => 'entity_reference',
        'cardinality' => -1,
        'settings' => ['target_type' => $target],
      ])->save();
      FieldConfig::create(['entity_type' => $type, 'bundle' => $bundle, 'field_name' => $name])->save();
    }
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_badge_status', 'type' => 'string'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'badge_request', 'field_name' => 'field_badge_status'])->save();
    if (!FieldStorageConfig::loadByName('node', 'field_appointment_purpose')) {
      FieldStorageConfig::create([
        'entity_type' => 'node',
        'field_name' => 'field_appointment_purpose',
        'type' => 'string',
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'appointment', 'field_appointment_purpose')) {
      FieldConfig::create([
        'entity_type' => 'node',
        'bundle' => 'appointment',
        'field_name' => 'field_appointment_purpose',
      ])->save();
    }
    $this->member = User::create(['name' => 'Member', 'status' => 1]);
    $this->member->save();
    $this->container->get('current_user')->setAccount($this->member);
    $this->basic = Term::create(['vid' => 'badges', 'name' => 'Basic sewing']);
    $this->basic->save();
    $this->advanced = Term::create([
      'vid' => 'badges',
      'name' => 'Advanced sewing',
      'field_badge_prerequisite' => $this->basic->id(),
    ]);
    $this->advanced->save();
  }

  /**
   * Verifies request.
   */
  protected function request(Term $badge, string $status = 'pending', bool $published = TRUE): Node {
    $node = Node::create([
      'type' => 'badge_request',
      'title' => $badge->label(),
      'status' => $published,
      'field_badge_requested' => $badge->id(),
      'field_member_to_badge' => $this->member->id(),
      'field_badge_status' => $status,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Verifies both pending then approve in same visit.
   */
  public function testBothPendingThenApproveInSameVisit(): void {
    $gate = $this->container->get('appointment_facilitator.badge_gate');
    $this->assertFalse($gate->evaluate((int) $this->member->id(), $this->advanced)['allowed']);
    $basic = $this->request($this->basic);
    $advanced = $this->request($this->advanced);
    $preparation = $gate->evaluate((int) $this->member->id(), $this->advanced);
    $this->assertTrue($preparation['allowed']);
    $this->assertSame([(int) $this->basic->id()], $preparation['prerequisites_pending']);
    $this->assertContains((int) $this->advanced->id(), _appointment_facilitator_load_pending_badge_term_ids((int) $this->member->id()));
    $advanced->set('field_badge_status', 'active');
    $this->assertStringContainsString('First approve', $gate->badgeRequestViolation($advanced));
    $basic->set('field_badge_status', 'active')->save();
    $this->assertNull($gate->badgeRequestViolation($advanced));
    $advanced->save();
    $this->assertSame('active', Node::load($advanced->id())->get('field_badge_status')->value);
  }

  /**
   * Verifies direct award cannot bypass pending prerequisite.
   */
  public function testDirectAwardCannotBypassPendingPrerequisite(): void {
    $this->request($this->basic);
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('First approve these prerequisite badges: Basic sewing');
    $this->request($this->advanced, 'active');
  }

  /**
   * Verifies direct pending request requires prerequisite.
   */
  public function testDirectPendingRequestRequiresPrerequisite(): void {
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('pending or earned');
    $this->request($this->advanced);
  }

  /**
   * Verifies inline approval gives field error.
   */
  public function testInlineApprovalGivesFieldError(): void {
    $this->request($this->basic);
    $advanced = $this->request($this->advanced);
    $state = new FormState();
    $state->setFormObject(new class($advanced) extends FormBase {

      public function __construct(private Node $node) {}

      /**
       * Verifies get entity.
       */
      public function getEntity() {
        return $this->node;
      }

      /**
       * {@inheritdoc}
       */
      public function getFormId() {
        return 'badge_test';
      }

      /**
       * {@inheritdoc}
       */
      public function buildForm(array $form, FormStateInterface $form_state) {
        return $form;
      }

      /**
       * {@inheritdoc}
       */
      public function submitForm(array &$form, FormStateInterface $form_state) {}

    });
    $state->setValue('field_badge_status', [['value' => 'active']]);
    $form = [];
    appointment_facilitator_validate_badge_prerequisites($form, $state);
    $this->assertArrayHasKey('field_badge_status', $state->getErrors());
    $this->assertSame('pending', Node::load($advanced->id())->get('field_badge_status')->value);
  }

  /**
   * Verifies disqualified prerequisites and other member do not count.
   */
  public function testDisqualifiedPrerequisitesAndOtherMemberDoNotCount(): void {
    $gate = $this->container->get('appointment_facilitator.badge_gate');
    foreach (['expired', 'suspended', 'rejected', 'duplicate'] as $status) {
      $this->request($this->basic, $status);
    }
    $this->request($this->basic, 'pending', FALSE);
    $this->request($this->basic, 'active', FALSE);
    $other = User::create(['name' => 'Other', 'status' => 1]);
    $other->save();
    $record = $this->request($this->basic, 'active');
    $record->set('field_member_to_badge', $other->id())->save();
    $this->assertFalse($gate->evaluate((int) $this->member->id(), $this->advanced)['allowed']);
  }

  /**
   * Verifies legacy earned prerequisite still counts.
   */
  public function testLegacyEarnedPrerequisiteStillCounts(): void {
    $this->request($this->basic, '');
    $award = $this->request($this->advanced, 'active');
    $this->assertSame('active', $award->get('field_badge_status')->value);
  }

  /**
   * Verifies new blank status cannot bypass award gate.
   */
  public function testNewBlankStatusCannotBypassAwardGate(): void {
    $this->request($this->basic);
    $this->expectException(EntityStorageException::class);
    $this->request($this->advanced, '');
  }

  /**
   * Verifies existing awards are not retroactively blocked.
   */
  public function testExistingAwardsAreNotRetroactivelyBlocked(): void {
    $this->advanced->set('field_badge_prerequisite', [])->save();
    $award = $this->request($this->advanced, 'active');
    $legacy = $this->request($this->advanced, '');
    $this->advanced->set('field_badge_prerequisite', $this->basic->id())->save();
    $award->setTitle('An ordinary edit')->save();
    $legacy->setTitle('Legacy edit')->save();
    $this->assertSame('active', $award->get('field_badge_status')->value);
    $this->assertSame('', (string) $legacy->get('field_badge_status')->value);
    $award->set('field_badge_status', 'expired')->save();
    $award->set('field_badge_status', 'active');
    $this->expectException(EntityStorageException::class);
    $award->save();
  }

  /**
   * Verifies changing award recipient is new grant.
   */
  public function testChangingAwardRecipientIsNewGrant(): void {
    $this->request($this->basic, 'active');
    $award = $this->request($this->advanced, 'active');
    $other = User::create(['name' => 'Other', 'status' => 1]);
    $other->save();
    $award->set('field_member_to_badge', $other->id());
    $this->expectException(EntityStorageException::class);
    $award->save();
  }

  /**
   * Verifies all prerequisites required and stale pending cannot book.
   */
  public function testAllPrerequisitesRequiredAndStalePendingCannotBook(): void {
    $basic = $this->request($this->basic);
    $advanced = $this->request($this->advanced);
    $basic->set('field_badge_status', 'suspended')->save();
    $this->assertNotContains((int) $this->advanced->id(), _appointment_facilitator_load_pending_badge_term_ids((int) $this->member->id()));
    $advanced->setTitle('Keep the old pending request')->save();
    $basic->set('field_badge_status', 'active')->save();
    $second = Term::create(['vid' => 'badges', 'name' => 'Another prerequisite']);
    $second->save();
    $this->advanced->get('field_badge_prerequisite')->appendItem($second->id());
    $this->advanced->save();
    $gate = $this->container->get('appointment_facilitator.badge_gate');
    $this->assertFalse($gate->evaluate((int) $this->member->id(), $this->advanced)['allowed']);
    $this->request($second);
    $this->assertTrue($gate->evaluate((int) $this->member->id(), $this->advanced)['allowed']);
    $this->assertFalse($gate->evaluatePrerequisites((int) $this->member->id(), $this->advanced)['allowed']);
  }

  /**
   * Checks direct bookings and on-request candidates through the shared guard.
   */
  public function testNewCheckoutBookingsRequirePreparation(): void {
    $gate = $this->container->get('appointment_facilitator.badge_gate');
    $booking = Node::create([
      'type' => 'appointment',
      'title' => 'Same visit',
      'uid' => $this->member->id(),
      'field_appointment_purpose' => 'checkout',
      'field_appointment_badges' => $this->advanced->id(),
    ]);
    $this->assertNotNull($gate->appointmentPrerequisiteViolation($booking));
    $basic = $this->request($this->basic);
    $this->assertNull($gate->appointmentPrerequisiteViolation($booking));
    $booking->save();
    $basic->set('field_badge_status', 'suspended')->save();
    $booking->setTitle('Preserve existing appointment')->save();
    $new = $booking->createDuplicate();
    $new->set('field_appointment_purpose', 'training');
    $this->assertNull($gate->appointmentPrerequisiteViolation($new));
    $new->set('field_appointment_purpose', 'checkout');
    $this->expectException(EntityStorageException::class);
    $new->save();
  }

}
