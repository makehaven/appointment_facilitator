<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\appointment_facilitator\Form\JoinAppointmentForm;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Tag-alongs are closed by default on appointments that are not checkouts.
 *
 * Ledger #42473: two unbadged members joined a badged member's informational
 * session hoping to get badged. Checkouts keep today's behaviour (open to
 * joiners with the badge pending); every other purpose is closed unless the
 * booker opted in.
 *
 * @group appointment_facilitator
 */
class JoinAppointmentPurposeDefaultTest extends KernelTestBase {

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
    'appointment_facilitator',
  ];

  /**
   * The booker (node owner).
   */
  protected User $booker;

  /**
   * The member trying to tag along.
   */
  protected User $joiner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'appointment_facilitator']);

    if (!NodeType::load('appointment')) {
      NodeType::create(['type' => 'appointment', 'name' => 'Appointment'])->save();
    }

    foreach (['field_appointment_attendees', 'field_appointment_host'] as $name) {
      FieldStorageConfig::loadByName('node', $name) ?? FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'user'],
        'cardinality' => -1,
      ])->save();
      FieldConfig::loadByName('node', 'appointment', $name) ?? FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'appointment',
        'label' => $name,
        'settings' => ['handler' => 'default:user'],
      ])->save();
    }

    FieldStorageConfig::loadByName('node', 'field_appointment_purpose') ?? FieldStorageConfig::create([
      'field_name' => 'field_appointment_purpose',
      'entity_type' => 'node',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          'checkout' => 'Badge Checkout',
          'informational' => 'General Informational / Advice (no badge)',
          'project' => 'Project specific advice or feedback (no badge)',
          'other' => 'Other',
        ],
      ],
    ])->save();
    FieldConfig::loadByName('node', 'appointment', 'field_appointment_purpose') ?? FieldConfig::create([
      'field_name' => 'field_appointment_purpose',
      'entity_type' => 'node',
      'bundle' => 'appointment',
      'label' => 'Purpose',
    ])->save();

    // Mirrors the site repo's field config
    // (field.field.node.appointment.field_appointment_open_to_join):
    // boolean, no default value.
    FieldStorageConfig::loadByName('node', 'field_appointment_open_to_join') ?? FieldStorageConfig::create([
      'field_name' => 'field_appointment_open_to_join',
      'entity_type' => 'node',
      'type' => 'boolean',
    ])->save();
    FieldConfig::loadByName('node', 'appointment', 'field_appointment_open_to_join') ?? FieldConfig::create([
      'field_name' => 'field_appointment_open_to_join',
      'entity_type' => 'node',
      'bundle' => 'appointment',
      'label' => 'Open to other members joining',
    ])->save();

    $this->config('appointment_facilitator.settings')
      ->set('system_wide_joiner_cap', 2)
      ->save();

    // User 1 is special; burn it so the booker/joiner are ordinary accounts.
    User::create(['name' => 'admin', 'status' => 1])->save();
    $this->booker = User::create(['name' => 'booker', 'mail' => 'booker@example.com', 'status' => 1]);
    $this->booker->save();
    Role::create(['id' => 'member', 'label' => 'Member'])
      ->grantPermission('access content')
      ->save();
    $this->joiner = User::create([
      'name' => 'joiner',
      'mail' => 'joiner@example.com',
      'status' => 1,
      'roles' => ['member'],
    ]);
    $this->joiner->save();
  }

  /**
   * Purpose decides the default; an explicit booker choice wins.
   */
  public function testOpenToJoinResolution(): void {
    $this->assertTrue(appointment_facilitator_is_open_to_join($this->appointment('checkout')), 'Checkout with no choice stays open.');
    $this->assertFalse(appointment_facilitator_is_open_to_join($this->appointment('checkout', 0)), 'Checkout kept one-on-one is closed.');
    foreach (['informational', 'project', 'other', NULL] as $purpose) {
      $label = $purpose ?? '(none)';
      $this->assertFalse(appointment_facilitator_is_open_to_join($this->appointment($purpose)), "$label with no choice is closed.");
      $this->assertTrue(appointment_facilitator_is_open_to_join($this->appointment($purpose, 1)), "$label opted in is open.");
    }
  }

  /**
   * A closed informational session shows no join form and refuses a join.
   */
  public function testInformationalSessionRefusesTagAlong(): void {
    $appointment = $this->appointment('informational');
    $this->container->get('current_user')->setAccount($this->joiner);
    $form = $this->container->get('class_resolver')->getInstanceFromDefinition(JoinAppointmentForm::class);

    $built = $form->buildForm([], new FormState(), $appointment);
    $this->assertArrayNotHasKey('actions', $built, 'No Join button on a closed informational session.');

    $form_state = new FormState();
    $form_state->setValue('node_id', $appointment->id());
    $form_state->setValue('experience_level', 'beginner');
    $form_array = [];
    $form->submitForm($form_array, $form_state);
    $this->assertSame([], Node::load($appointment->id())->get('field_appointment_attendees')->getValue(), 'Direct POST did not add the joiner.');
  }

  /**
   * An informational session the booker opened accepts a joiner.
   */
  public function testOptedInInformationalSessionAcceptsJoiner(): void {
    $appointment = $this->appointment('informational', 1);
    $this->container->get('current_user')->setAccount($this->joiner);
    $form = $this->container->get('class_resolver')->getInstanceFromDefinition(JoinAppointmentForm::class);

    $built = $form->buildForm([], new FormState(), $appointment);
    $this->assertArrayHasKey('actions', $built, 'Join button shows on an opted-in session.');

    $form_state = new FormState();
    $form_state->setValue('node_id', $appointment->id());
    $form_state->setValue('experience_level', 'beginner');
    $form_array = [];
    $form->submitForm($form_array, $form_state);
    $ids = array_column(Node::load($appointment->id())->get('field_appointment_attendees')->getValue(), 'target_id');
    $messages = $this->container->get('messenger')->all();
    $this->assertContains((string) $this->joiner->id(), array_map('strval', $ids), 'Joiner added. Messages: ' . json_encode(array_map(fn ($l) => array_map('strval', $l), $messages)));
  }

  /**
   * The node-form entity builder stores only choices that depart from default.
   */
  public function testEntityBuilderStoresOnlyDepartures(): void {
    $cases = [
      // purpose, one-on-one box, opt-in box, expected stored value.
      ['checkout', 0, 0, NULL],
      ['checkout', 1, 0, '0'],
      // The hidden opt-in box is ignored for checkouts (JS-off safety).
      ['checkout', 0, 1, NULL],
      ['informational', 0, 0, NULL],
      ['informational', 0, 1, '1'],
      // The hidden one-on-one box is ignored for other purposes.
      ['informational', 1, 0, NULL],
      ['project', 0, 1, '1'],
    ];
    foreach ($cases as [$purpose, $one_on_one, $opt_in, $expected]) {
      $node = Node::create([
        'type' => 'appointment',
        'title' => 'builder',
        'uid' => $this->booker->id(),
        'field_appointment_purpose' => $purpose,
      ]);
      $form_state = new FormState();
      $form_state->setValue('field_appointment_open_to_join_widget', $one_on_one);
      $form_state->setValue('field_appointment_open_to_join_opt_in', $opt_in);
      $form = [];
      _appointment_facilitator_build_open_to_join_field('node', $node, $form, $form_state);
      $stored = $node->get('field_appointment_open_to_join')->isEmpty() ? NULL : (string) $node->get('field_appointment_open_to_join')->value;
      $this->assertSame($expected, $stored, "purpose=$purpose one_on_one=$one_on_one opt_in=$opt_in");
    }
  }

  /**
   * Creates and saves an appointment owned by the booker.
   */
  protected function appointment(?string $purpose, ?int $open = NULL): NodeInterface {
    $values = [
      'type' => 'appointment',
      'title' => 'Session ' . ($purpose ?? 'none'),
      'status' => 1,
      'uid' => $this->booker->id(),
      'field_appointment_attendees' => [],
    ];
    if ($purpose !== NULL) {
      $values['field_appointment_purpose'] = $purpose;
    }
    if ($open !== NULL) {
      $values['field_appointment_open_to_join'] = $open;
    }
    $node = Node::create($values);
    $node->save();
    return $node;
  }

}
