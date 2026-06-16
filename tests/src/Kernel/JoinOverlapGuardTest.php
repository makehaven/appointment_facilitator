<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\appointment_facilitator\Form\JoinAppointmentForm;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Same-member overlapping-appointment guard for the join (tagalong) path.
 *
 * Regression (cycle review 2026-06-15): a member was tagged onto two
 * appointments at the same time. JoinAppointmentForm now refuses a join when
 * the member is already the owner or an attendee of another non-cancelled
 * appointment whose time overlaps.
 *
 * @group appointment_facilitator
 * @covers \Drupal\appointment_facilitator\Form\JoinAppointmentForm
 */
class JoinOverlapGuardTest extends KernelTestBase {

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
    'smart_date',
    'appointment_facilitator',
  ];

  /**
   * The appointment timerange day, midnight, for building slots.
   *
   * @var int
   */
  protected int $base;

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

    $this->ensureField('field_appointment_timerange', 'smartdate', []);
    $this->ensureField('field_appointment_status', 'list_string', [
      'allowed_values' => ['scheduled' => 'scheduled', 'canceled' => 'canceled'],
    ]);
    $this->ensureEntityReferenceField('field_appointment_attendees', 'user', -1);

    // A fixed daytime base (2026-06-15 09:00:00 UTC) for slot math.
    $this->base = gmmktime(9, 0, 0, 6, 15, 2026);
  }

  /**
   * Owner of an overlapping appointment is blocked from joining another.
   */
  public function testOwnerOverlapIsDetected(): void {
    $member = $this->makeUser();
    // Existing booking 09:00–10:00.
    $this->makeAppointment($member->id(), [], $this->base, $this->base + 3600);
    // Target 09:30–10:30 overlaps.
    $target = $this->makeAppointment(0, [], $this->base + 1800, $this->base + 5400);

    $this->assertTrue($this->callGuard($target, (int) $member->id()));
  }

  /**
   * Attendee (tagalong) of an overlapping appointment is also blocked.
   */
  public function testAttendeeOverlapIsDetected(): void {
    $member = $this->makeUser();
    $this->makeAppointment(0, [$member->id()], $this->base, $this->base + 3600);
    $target = $this->makeAppointment(0, [], $this->base + 1800, $this->base + 5400);

    $this->assertTrue($this->callGuard($target, (int) $member->id()));
  }

  /**
   * Adjacent (non-overlapping) appointments do not trigger the guard.
   */
  public function testAdjacentDoesNotOverlap(): void {
    $member = $this->makeUser();
    // Existing 09:00–10:00, target 10:00–11:00 — touch but don't overlap.
    $this->makeAppointment($member->id(), [], $this->base, $this->base + 3600);
    $target = $this->makeAppointment(0, [], $this->base + 3600, $this->base + 7200);

    $this->assertFalse($this->callGuard($target, (int) $member->id()));
  }

  /**
   * A cancelled overlapping appointment is ignored.
   */
  public function testCancelledOverlapIsIgnored(): void {
    $member = $this->makeUser();
    $this->makeAppointment($member->id(), [], $this->base, $this->base + 3600, 'canceled');
    $target = $this->makeAppointment(0, [], $this->base + 1800, $this->base + 5400);

    $this->assertFalse($this->callGuard($target, (int) $member->id()));
  }

  /**
   * No other appointments — nothing to conflict with.
   */
  public function testNoOtherAppointments(): void {
    $member = $this->makeUser();
    $target = $this->makeAppointment(0, [], $this->base, $this->base + 3600);

    $this->assertFalse($this->callGuard($target, (int) $member->id()));
  }

  /**
   * Invokes the protected guard via reflection.
   */
  protected function callGuard(Node $target, int $uid): bool {
    $form = JoinAppointmentForm::create($this->container);
    $method = new \ReflectionMethod($form, 'memberHasOverlappingAppointment');
    $method->setAccessible(TRUE);
    return (bool) $method->invoke($form, $target, $uid);
  }

  /**
   * Creates a member user.
   */
  protected function makeUser(): User {
    $user = User::create([
      'name' => 'm_' . bin2hex(random_bytes(4)),
      'mail' => bin2hex(random_bytes(4)) . '@example.com',
      'status' => 1,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Creates an appointment node.
   */
  protected function makeAppointment(int $owner_uid, array $attendees, int $start, int $end, string $status = 'scheduled'): Node {
    $values = [
      'type' => 'appointment',
      'title' => 'Appt ' . $start,
      'status' => 1,
      'field_appointment_timerange' => [[
        'value' => $start,
        'end_value' => $end,
        'duration' => (int) (($end - $start) / 60),
        'timezone' => 'UTC',
      ],
      ],
      'field_appointment_status' => $status,
    ];
    if ($owner_uid > 0) {
      $values['uid'] = $owner_uid;
    }
    if ($attendees) {
      $values['field_appointment_attendees'] = array_map(fn($id) => ['target_id' => $id], $attendees);
    }
    $node = Node::create($values);
    $node->save();
    return $node;
  }

  /**
   * Creates a node field storage + appointment instance if missing.
   */
  protected function ensureField(string $field_name, string $type, array $settings, int $cardinality = 1): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $settings,
        'cardinality' => $cardinality,
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'appointment', $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'appointment',
        'label' => $field_name,
      ])->save();
    }
  }

  /**
   * Creates an entity_reference field on appointment if missing.
   */
  protected function ensureEntityReferenceField(string $field_name, string $target_type, int $cardinality = 1): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => $target_type],
        'cardinality' => $cardinality,
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'appointment', $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'appointment',
        'label' => $field_name,
      ])->save();
    }
  }

}
