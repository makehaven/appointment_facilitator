<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Verifies the appointment author never sees the join form.
 *
 * Regression: after booking an appointment the confirmation screen was
 * showing the "Join this appointment" button and pace warning to the person
 * who had just created the appointment, which was confusing.
 *
 * @group appointment_facilitator
 */
class JoinAppointmentAuthorExclusionTest extends KernelTestBase {

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

    $this->ensureEntityReferenceField('node', 'appointment', 'field_appointment_attendees', 'user');
    $this->ensureEntityReferenceField('node', 'appointment', 'field_appointment_host', 'user');
  }

  /**
   * The node author (primary booker) gets an empty form, not the join UI.
   */
  public function testAuthorSeesEmptyForm(): void {
    $this->config('appointment_facilitator.settings')
      ->set('system_wide_joiner_cap', 2)
      ->save();

    $author = User::create([
      'name' => 'primary_booker',
      'mail' => 'primary-booker@example.com',
      'status' => 1,
    ]);
    $author->save();

    $host = User::create([
      'name' => 'join_test_host',
      'mail' => 'join-test-host@example.com',
      'status' => 1,
    ]);
    $host->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Author exclusion test',
      'status' => 1,
      'uid' => $author->id(),
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_appointment_attendees' => [],
    ]);
    $appointment->save();

    $this->container->get('current_user')->setAccount($author);

    /** @var \Drupal\appointment_facilitator\Form\JoinAppointmentForm $form_obj */
    $form_obj = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(\Drupal\appointment_facilitator\Form\JoinAppointmentForm::class);

    $form_state = new FormState();
    $built = $form_obj->buildForm([], $form_state, $appointment);

    // The author should receive an empty form array — no join UI elements.
    $this->assertArrayNotHasKey('actions', $built, 'Primary booker must not see join actions.');
    $this->assertArrayNotHasKey('pace_warning', $built, 'Primary booker must not see the pace warning.');
    $this->assertArrayNotHasKey('experience_level', $built, 'Primary booker must not see experience level selector.');
  }

  /**
   * A different authenticated user (potential joiner) does see the join form.
   */
  public function testNonAuthorSeesJoinForm(): void {
    $this->config('appointment_facilitator.settings')
      ->set('system_wide_joiner_cap', 2)
      ->save();

    $author = User::create([
      'name' => 'primary_booker_2',
      'mail' => 'primary-booker-2@example.com',
      'status' => 1,
    ]);
    $author->save();

    $joiner = User::create([
      'name' => 'potential_joiner',
      'mail' => 'potential-joiner@example.com',
      'status' => 1,
    ]);
    $joiner->save();

    $host = User::create([
      'name' => 'join_test_host_2',
      'mail' => 'join-test-host-2@example.com',
      'status' => 1,
    ]);
    $host->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Non-author join form test',
      'status' => 1,
      'uid' => $author->id(),
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_appointment_attendees' => [],
    ]);
    $appointment->save();

    $this->container->get('current_user')->setAccount($joiner);

    /** @var \Drupal\appointment_facilitator\Form\JoinAppointmentForm $form_obj */
    $form_obj = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(\Drupal\appointment_facilitator\Form\JoinAppointmentForm::class);

    $form_state = new FormState();
    $built = $form_obj->buildForm([], $form_state, $appointment);

    $this->assertArrayHasKey('actions', $built, 'Non-author joiner should see the join actions.');
    $this->assertArrayHasKey('pace_warning', $built, 'Non-author joiner should see the pace warning.');
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

}
