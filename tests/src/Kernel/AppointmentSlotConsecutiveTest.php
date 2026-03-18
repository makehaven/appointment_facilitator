<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;

/**
 * Tests the consecutive slot validation for appointments.
 *
 * @group appointment_facilitator
 */
class AppointmentSlotConsecutiveTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'node',
    'taxonomy',
    'datetime',
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
    $this->installConfig(['system', 'node', 'appointment_facilitator']);

    if (!NodeType::load('appointment')) {
      NodeType::create([
        'type' => 'appointment',
        'name' => 'Appointment',
      ])->save();
    }

    $this->ensureListField('appointment', 'field_appointment_purpose', [
      'informational' => 'General Informational (no badge)',
      'checkout' => 'Badge Checkout',
    ]);
    
    $this->ensureListField('appointment', 'field_appointment_slot', [
      '1' => '1st slot',
      '1-5' => '2nd slot',
      '2' => '3rd slot',
      '2-5' => '4th slot',
      '3' => '5th slot',
      '3-5' => '6th slot',
    ]);

    $this->ensureEntityReferenceField('appointment', 'field_appointment_badges', 'taxonomy_term');
  }

  /**
   * Tests _appointment_facilitator_are_slots_consecutive helper.
   */
  public function testAreSlotsConsecutive(): void {
    $this->assertTrue(_appointment_facilitator_are_slots_consecutive(['1', '1-5']), 'Adjacent slots are consecutive.');
    $this->assertTrue(_appointment_facilitator_are_slots_consecutive(['1', '1-5', '2']), 'Three adjacent slots are consecutive.');
    $this->assertFalse(_appointment_facilitator_are_slots_consecutive(['1', '2']), 'Skipped slot is not consecutive.');
    $this->assertFalse(_appointment_facilitator_are_slots_consecutive(['1', '3-5']), 'Large gap is not consecutive.');
    $this->assertTrue(_appointment_facilitator_are_slots_consecutive(['1']), 'Single slot is consecutive.');
    $this->assertTrue(_appointment_facilitator_are_slots_consecutive([]), 'Empty slots are consecutive.');
  }

  /**
   * Tests the validation handler.
   */
  public function testSlotCoverageValidation(): void {
    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Validation test',
      'uid' => 0,
    ]);

    $form = ['#form_id' => 'node_appointment_form'];
    $form_state = new FormState();
    $form_state->setFormObject(new AppointmentSlotConsecutiveFormObjectStub($appointment));

    if (!Vocabulary::load('badges')) {
        Vocabulary::create(['vid' => 'badges', 'name' => 'Badges'])->save();
    }
    $this->ensureField('taxonomy_term', 'badges', 'field_badge_checkout_minutes', 'string');
    
    $badge = \Drupal\taxonomy\Entity\Term::create([
        'vid' => 'badges',
        'name' => 'Test Badge',
        'field_badge_checkout_minutes' => '60',
    ]);
    $badge->save();

    // Case 1: Non-consecutive slots.
    $form_state->setValues([
      'field_appointment_purpose' => [['value' => 'checkout']],
      'field_appointment_badges' => [['target_id' => $badge->id()]],
      'field_appointment_slot' => ['1', '2'], // 1st and 3rd slot (60 mins but not consecutive)
    ]);

    appointment_facilitator_validate_slot_coverage($form, $form_state);
    
    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('field_appointment_slot', $errors);
    $this->assertEquals('Please select consecutive time slots. Non-consecutive slots are not allowed for a single appointment.', $errors['field_appointment_slot']);
    
    // Case 2: Consecutive slots.
    $form_state = new FormState();
    $form_state->setFormObject(new AppointmentSlotConsecutiveFormObjectStub($appointment));
    $form_state->setValues([
      'field_appointment_purpose' => [['value' => 'checkout']],
      'field_appointment_badges' => [['target_id' => $badge->id()]],
      'field_appointment_slot' => ['1', '1-5'], // 1st and 2nd slot
    ]);

    appointment_facilitator_validate_slot_coverage($form, $form_state);
    $this->assertEmpty($form_state->getErrors(), 'Consecutive slots are valid.');

    // Case 2b: Flat widget values still require at least one badge.
    $form_state = new FormState();
    $form_state->setFormObject(new AppointmentSlotConsecutiveFormObjectStub($appointment));
    $form_state->setValues([
      'field_appointment_purpose' => 'checkout',
      'field_appointment_badges' => [],
      'field_appointment_slot' => ['1', '1-5'],
    ]);

    appointment_facilitator_validate_slot_coverage($form, $form_state);
    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('field_appointment_badges', $errors, 'Checkout appointments require at least one badge even with flat widget values.');
    $this->assertSame('Select at least one badge for a badge checkout appointment.', (string) $errors['field_appointment_badges']);

    // Case 3: Edit form.
    $form['#form_id'] = 'node_appointment_edit_form';
    $form_state = new FormState();
    $form_state->setFormObject(new AppointmentSlotConsecutiveFormObjectStub($appointment));
    $form_state->setValues([
      'field_appointment_purpose' => [['value' => 'checkout']],
      'field_appointment_badges' => [['target_id' => $badge->id()]],
      'field_appointment_slot' => ['1', '2'], // 1st and 3rd slot (60 mins but not consecutive)
    ]);

    appointment_facilitator_validate_slot_coverage($form, $form_state);
    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('field_appointment_slot', $errors, 'Edit form also validates consecutive slots.');

    // Case 4: Admin user (should also be blocked).
    Role::create(['id' => 'admin', 'label' => 'Admin'])->save();
    $admin = User::create(['name' => 'admin', 'mail' => 'admin@example.com', 'status' => 1]);
    $admin->addRole('admin');
    $admin->save();
    Role::load('admin')->grantPermission('administer nodes')->save();
    $this->container->get('current_user')->setAccount($admin);

    $form_state = new FormState();
    $form_state->setFormObject(new AppointmentSlotConsecutiveFormObjectStub($appointment));
    $form_state->setValues([
      'field_appointment_purpose' => [['value' => 'checkout']],
      'field_appointment_badges' => [['target_id' => $badge->id()]],
      'field_appointment_slot' => ['1', '2'],
    ]);

    appointment_facilitator_validate_slot_coverage($form, $form_state);
    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('field_appointment_slot', $errors, 'Admin users are also blocked from non-consecutive slots.');

    // Case 5: Different purpose (Informational).
    // Now blocked for ALL purposes.
    $form_state = new FormState();
    $form_state->setFormObject(new AppointmentSlotConsecutiveFormObjectStub($appointment));
    $form_state->setValues([
      'field_appointment_purpose' => [['value' => 'informational']],
      'field_appointment_badges' => [['target_id' => $badge->id()]],
      'field_appointment_slot' => ['1', '2'],
    ]);

    appointment_facilitator_validate_slot_coverage($form, $form_state);
    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('field_appointment_slot', $errors, 'Non-consecutive slots are now blocked for all purposes.');
  }

  /**
   * Ensures an entity reference field exists.
   */
  protected function ensureEntityReferenceField(string $bundle, string $field_name, string $target_type): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => $target_type],
        'cardinality' => -1,
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
   * Ensures a list field exists.
   */
  protected function ensureListField(string $bundle, string $field_name, array $allowed_values): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'list_string',
        'settings' => ['allowed_values' => $allowed_values],
        'cardinality' => -1,
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
   * Ensures a simple field exists.
   */
  protected function ensureField(string $entity_type, string $bundle, string $field_name, string $type): void {
    if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => $type,
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

/**
 * Minimal form object stub for hook_form_node_form_alter() tests.
 */
class AppointmentSlotConsecutiveFormObjectStub implements FormInterface {

  /**
   * Creates a new stub.
   */
  public function __construct(protected Node $entity) {}

  /**
   * Returns the appointment entity used by the hook.
   */
  public function getEntity(): Node {
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'node_appointment_form';
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
  public function validateForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {}

}
