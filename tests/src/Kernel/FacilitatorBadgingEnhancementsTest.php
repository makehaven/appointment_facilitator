<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;

/**
 * Tests the enhancements for facilitator experience and badging UX.
 *
 * @group appointment_facilitator
 */
class FacilitatorBadgingEnhancementsTest extends KernelTestBase {

  use UserCreationTrait;
  use NodeCreationTrait;

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
    'profile',
    'smart_date',
    'smart_date_recur',
    'appointment_facilitator',
    'makerspace_reservations', // Mock if not present, but here we assume it exists
  ];

  /**
   * @var \Drupal\user\UserInterface
   */
  protected $member;

  /**
   * @var \Drupal\user\UserInterface
   */
  protected $admin;

  /**
   * @var \Drupal\taxonomy\TermInterface
   */
  protected $badge;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('profile');
    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);

    // Create types.
    NodeType::create(['type' => 'appointment', 'name' => 'Appointment'])->save();
    NodeType::create(['type' => 'badge_request', 'name' => 'Badge Request'])->save();
    NodeType::create(['type' => 'item', 'name' => 'Item'])->save();
    
    Vocabulary::create(['vid' => 'badges', 'name' => 'Badges'])->save();
    ProfileType::create(['id' => 'coordinator', 'label' => 'Coordinator'])->save();

    // Setup fields needed for tests.
    $this->setupTestFields();

    $this->member = $this->createUser([], 'test_member');
    $this->admin = $this->createUser(['administer nodes'], 'test_admin');
    
    $this->badge = Term::create([
      'vid' => 'badges',
      'name' => 'Woodshop Safety',
    ]);
    $this->badge->save();
  }

  /**
   * Helper to setup minimal fields for validation testing.
   */
  protected function setupTestFields() {
    // Appointment purpose.
    FieldStorageConfig::create([
      'field_name' => 'field_appointment_purpose',
      'entity_type' => 'node',
      'type' => 'list_string',
      'settings' => ['allowed_values' => ['checkout' => 'Checkout', 'info' => 'Info']],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_appointment_purpose',
      'entity_type' => 'node',
      'bundle' => 'appointment',
      'label' => 'Purpose',
    ])->save();

    // Appointment badges.
    FieldStorageConfig::create([
      'field_name' => 'field_appointment_badges',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_appointment_badges',
      'entity_type' => 'node',
      'bundle' => 'appointment',
      'label' => 'Badges',
    ])->save();

    // Badge Request Status & Badge reference.
    FieldStorageConfig::create([
      'field_name' => 'field_badge_status',
      'entity_type' => 'node',
      'type' => 'list_string',
      'settings' => ['allowed_values' => ['pending' => 'Pending', 'active' => 'Active']],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_badge_status',
      'entity_type' => 'node',
      'bundle' => 'badge_request',
      'label' => 'Status',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_badge_requested',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_badge_requested',
      'entity_type' => 'node',
      'bundle' => 'badge_request',
      'label' => 'Badge',
    ])->save();

    // Facilitator Intro.
    FieldStorageConfig::create([
      'field_name' => 'field_facilitator_intro',
      'entity_type' => 'profile',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_facilitator_intro',
      'entity_type' => 'profile',
      'bundle' => 'coordinator',
      'label' => 'Intro',
    ])->save();
  }

  /**
   * Tests duplicate badge validation.
   */
  public function testDuplicateBadgeValidation() {
    $this->setCurrentUser($this->member);

    // 1. Create an existing active badge request.
    $req = Node::create([
      'type' => 'badge_request',
      'title' => 'Request 1',
      'uid' => $this->member->id(),
      'field_badge_requested' => $this->badge->id(),
      'field_badge_status' => 'active',
    ]);
    $req->save();

    // 2. Mock form state for a NEW appointment request for the same badge.
    $form_state = new FormState();
    $form_state->setValues([
      'field_appointment_purpose' => [['value' => 'checkout']],
      'field_appointment_badges' => [['target_id' => $this->badge->id()]],
    ]);
    
    // We need to set the form object for the helper to work.
    $node = Node::create(['type' => 'appointment']);
    $form_object = $this->container->get('entity_type.manager')->getFormObject('node', 'default');
    $form_object->setEntity($node);
    $form_state->setFormObject($form_object);

    $form = ['#form_id' => 'node_appointment_form'];
    
    // Call the validation function.
    _appointment_facilitator_validate_no_duplicates($form, $form_state);

    $errors = $form_state->getErrors();
    $this->assertNotEmpty($errors, 'Validation should fail for duplicate active badge.');
    $this->assertStringContainsString('You already have the Woodshop Safety badge', (string) reset($errors));

    // 3. Test that Admin can bypass.
    $this->setCurrentUser($this->admin);
    $form_state_admin = new FormState();
    $form_state_admin->setValues([
      'field_appointment_purpose' => [['value' => 'checkout']],
      'field_appointment_badges' => [['target_id' => $this->badge->id()]],
    ]);
    $form_state_admin->setFormObject($form_object);
    
    _appointment_facilitator_validate_no_duplicates($form, $form_state_admin);
    $this->assertEmpty($form_state_admin->getErrors(), 'Admin should bypass duplicate badge validation.');
  }

  /**
   * Tests facilitator intro loading.
   */
  public function testFacilitatorIntroLoading() {
    $facilitator = $this->createUser([], 'facil');
    $intro_text = "I am a woodshop expert. Please bring your own safety glasses.";
    
    Profile::create([
      'type' => 'coordinator',
      'uid' => $facilitator->id(),
      'field_facilitator_intro' => $intro_text,
      'status' => 1,
    ])->save();

    $loaded_intro = _appointment_facilitator_get_user_intro($facilitator);
    $this->assertEquals($intro_text, $loaded_intro, 'Facilitator intro should be correctly loaded from profile.');
  }

}
