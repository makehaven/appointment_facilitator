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
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies appointment form query prepopulation behavior.
 *
 * @group appointment_facilitator
 */
class AppointmentFormPrepopulateQueryTest extends KernelTestBase {

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

    if (!Vocabulary::load('badges')) {
      Vocabulary::create([
        'vid' => 'badges',
        'name' => 'Badges',
      ])->save();
    }

    $this->ensureEntityReferenceField('appointment', 'field_appointment_host', 'user');
    $this->ensureEntityReferenceField('appointment', 'field_appointment_badges', 'taxonomy_term');
    $this->ensureListField('appointment', 'field_appointment_purpose', [
      'informational' => 'General Informational (no badge)',
      'checkout' => 'Badge Checkout',
      'project' => 'Advice on my project (no badge)',
      'other' => 'Other (specify in appointment note) (no badge)',
    ]);
  }

  /**
   * Ensures host-uid and badge query params prefill the add form.
   */
  public function testPrepopulatesHostAndBadgeFromQuery(): void {
    $host = User::create([
      'name' => 'facilitator_query_host',
      'mail' => 'facilitator-query-host@example.com',
      'status' => 1,
    ]);
    $host->save();

    $badge = Term::create([
      'vid' => 'badges',
      'name' => 'Laser Cutter',
    ]);
    $badge->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Prefill test',
      'uid' => 0,
    ]);

    $form = [
      'field_appointment_host' => [
        'widget' => [
          0 => [
            'target_id' => [],
          ],
        ],
      ],
      'field_appointment_badges' => [
        'widget' => [
          (int) $badge->id() => [],
        ],
      ],
      'field_appointment_purpose' => [
        'widget' => [],
      ],
    ];

    $form_state = new FormState();
    $form_state->setFormObject(new AppointmentFormObjectStub($appointment));

    $request = Request::create('/node/add/appointment', 'GET', [
      'host-uid' => (string) $host->id(),
      'badge' => (string) $badge->id(),
      'purpose' => 'checkout',
      'from-badges-complete' => '1',
    ]);

    $request_stack = \Drupal::service('request_stack');
    $request_stack->push($request);
    try {
      appointment_facilitator_form_node_form_alter($form, $form_state, 'node_appointment_form');
    }
    finally {
      $request_stack->pop();
    }

    $selected_host = $form['field_appointment_host']['widget'][0]['target_id']['#default_value'] ?? NULL;
    $this->assertNotNull($selected_host, 'Host widget default was set.');
    $this->assertSame((int) $host->id(), (int) $selected_host->id(), 'Host is preselected from host-uid.');

    $selected_badges = $appointment->get('field_appointment_badges')->getValue();
    $this->assertCount(1, $selected_badges, 'Badge entity field was prefilled.');
    $this->assertSame((int) $badge->id(), (int) $selected_badges[0]['target_id'], 'Badge was preselected from query.');
    $this->assertSame('checkout', (string) $appointment->get('field_appointment_purpose')->value, 'Purpose was preselected as checkout.');
  }

  /**
   * Ensures legacy host query param still preselects the facilitator.
   */
  public function testPrepopulatesHostFromLegacyHostQueryParam(): void {
    $host = User::create([
      'name' => 'facilitator_legacy_host',
      'mail' => 'facilitator-legacy-host@example.com',
      'status' => 1,
    ]);
    $host->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Legacy host prefill test',
      'uid' => 0,
    ]);

    $form = [
      'field_appointment_host' => [
        'widget' => [
          0 => [
            'target_id' => [],
          ],
        ],
      ],
      'field_appointment_badges' => [
        'widget' => [],
      ],
      'field_appointment_purpose' => [
        'widget' => [],
      ],
    ];

    $form_state = new FormState();
    $form_state->setFormObject(new AppointmentFormObjectStub($appointment));

    $request = Request::create('/node/add/appointment', 'GET', [
      'host' => (string) $host->id(),
    ]);

    $request_stack = \Drupal::service('request_stack');
    $request_stack->push($request);
    try {
      appointment_facilitator_form_node_form_alter($form, $form_state, 'node_appointment_form');
    }
    finally {
      $request_stack->pop();
    }

    $selected_host = $form['field_appointment_host']['widget'][0]['target_id']['#default_value'] ?? NULL;
    $this->assertNotNull($selected_host, 'Host widget default was set from legacy host param.');
    $this->assertSame((int) $host->id(), (int) $selected_host->id(), 'Legacy host query param remains supported.');
  }

  /**
   * Ensures an entity reference field exists on appointment nodes.
   */
  protected function ensureEntityReferenceField(string $bundle, string $field_name, string $target_type): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => $target_type,
        ],
        'cardinality' => -1,
      ])->save();
    }

    if (!FieldConfig::loadByName('node', $bundle, $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => $field_name,
        'settings' => [
          'handler' => 'default:' . $target_type,
        ],
      ])->save();
    }
  }

  /**
   * Ensures a list field exists on appointment nodes.
   */
  protected function ensureListField(string $bundle, string $field_name, array $allowed_values): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'list_string',
        'settings' => [
          'allowed_values' => $allowed_values,
        ],
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

/**
 * Minimal form object stub for hook_form_node_form_alter() tests.
 */
class AppointmentFormObjectStub implements FormInterface {

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
