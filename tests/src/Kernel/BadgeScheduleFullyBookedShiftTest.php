<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\appointment_facilitator\Controller\BadgeNextStepsController;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * The badge schedule grid hides shifts that can no longer take the booking.
 *
 * Regression guard for 2026-09-11: a member clicked a facilitator's "6:00pm"
 * button while the 6-8pm slots were already booked; the appointment form
 * silently pre-selected 8:00pm and the member saved without noticing. The
 * grid button is read as the booking time, so a shift without a free run of
 * slots long enough for the badge's checkout must not be offered.
 *
 * @group appointment_facilitator
 */
class BadgeScheduleFullyBookedShiftTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'options',
    'datetime',
    'smart_date',
    'profile',
    'taxonomy',
    'appointment_facilitator',
  ];

  /**
   * Start of the shift under test (three hours long).
   */
  protected int $shiftStart;

  /**
   * The facilitator hosting the shift.
   */
  protected User $host;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('profile');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('system', ['sequences']);
    $this->installConfig(['node', 'appointment_facilitator']);

    $this->config('system.date')->set('timezone.default', 'UTC')->save();

    Role::create(['id' => 'facilitator', 'label' => 'Facilitator'])->save();
    ProfileType::create(['id' => 'coordinator', 'label' => 'Coordinator'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_coordinator_hours',
      'entity_type' => 'profile',
      'type' => 'smartdate',
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_coordinator_hours',
      'entity_type' => 'profile',
      'bundle' => 'coordinator',
      'label' => 'Coordinator Hours',
    ])->save();

    Vocabulary::create(['vid' => 'badges', 'name' => 'Badges'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_badge_issuer',
      'entity_type' => 'taxonomy_term',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'user'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_badge_issuer',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'badges',
      'label' => 'Badge Issuer',
      'settings' => ['handler' => 'default'],
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_badge_checkout_minutes',
      'entity_type' => 'taxonomy_term',
      'type' => 'integer',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_badge_checkout_minutes',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'badges',
      'label' => 'Checkout minutes',
    ])->save();

    if (!NodeType::load('appointment')) {
      NodeType::create(['type' => 'appointment', 'name' => 'Appointment'])->save();
    }
    $this->ensureNodeField('field_appointment_timerange', 'smartdate', []);
    $this->ensureNodeField('field_appointment_status', 'list_string', [
      'allowed_values' => ['scheduled' => 'scheduled', 'canceled' => 'canceled'],
    ]);
    $this->ensureNodeField('field_appointment_host', 'entity_reference', ['target_type' => 'user']);

    // A three-hour shift two days out, so it sits inside the grid's window.
    $this->shiftStart = (new \DateTimeImmutable('@' . \Drupal::time()->getRequestTime()))
      ->setTimezone(new \DateTimeZone('UTC'))
      ->modify('+2 days')
      ->setTime(18, 0)
      ->getTimestamp();

    $this->host = User::create([
      'name' => 'shift_host',
      'mail' => 'shift-host@example.com',
      'status' => 1,
    ]);
    $this->host->addRole('facilitator');
    $this->host->save();

    Profile::create([
      'type' => 'coordinator',
      'uid' => $this->host->id(),
      'status' => 1,
      'field_coordinator_hours' => [
        ['value' => $this->shiftStart, 'end_value' => $this->shiftStart + 3 * 3600, 'duration' => 180],
      ],
    ])->save();
  }

  /**
   * With the first two hours booked, a 60-minute checkout still fits.
   */
  public function testShiftWithRoomIsOffered(): void {
    $term = $this->makeBadge(60);
    $this->book(0, 60);
    $this->book(60, 120);

    $this->assertSame([$this->shiftStart], $this->offeredShiftStarts($term));
  }

  /**
   * Once every slot is taken the shift disappears from the grid.
   */
  public function testFullyBookedShiftIsHidden(): void {
    $term = $this->makeBadge(60);
    $this->book(0, 60);
    $this->book(60, 120);
    $this->book(120, 180);

    $this->assertSame([], $this->offeredShiftStarts($term));
  }

  /**
   * A single free half hour is not enough for a 60-minute checkout.
   */
  public function testShiftIsHiddenWhenFreeRunIsTooShort(): void {
    $term = $this->makeBadge(60);
    $this->book(0, 150);

    $this->assertSame([], $this->offeredShiftStarts($term), 'One free slot cannot hold a two-slot checkout.');

    $short = $this->makeBadge(30);
    $this->assertSame([$this->shiftStart], $this->offeredShiftStarts($short), 'A one-slot checkout still fits.');
  }

  /**
   * Free slots need not be at the start; a gap in the middle is enough.
   */
  public function testGapInTheMiddleKeepsTheShift(): void {
    $term = $this->makeBadge(60);
    $this->book(0, 60);
    $this->book(120, 180);

    $this->assertSame([$this->shiftStart], $this->offeredShiftStarts($term));
  }

  /**
   * Cancelled appointments do not occupy slots.
   */
  public function testCancelledBookingsFreeTheShift(): void {
    $term = $this->makeBadge(60);
    $this->book(0, 60);
    $this->book(60, 120);
    $this->book(120, 180, 'canceled');

    $this->assertSame([$this->shiftStart], $this->offeredShiftStarts($term));
  }

  /**
   * Another host's bookings never count against this shift.
   */
  public function testOtherHostsBookingsAreIgnored(): void {
    $term = $this->makeBadge(60);
    $other = User::create(['name' => 'other_host', 'mail' => 'other-host@example.com', 'status' => 1]);
    $other->save();
    foreach ([[0, 60], [60, 120], [120, 180]] as [$from, $to]) {
      $this->book($from, $to, 'scheduled', (int) $other->id());
    }

    $this->assertSame([$this->shiftStart], $this->offeredShiftStarts($term));
  }

  /**
   * Runs the grid's facilitator collection; returns the offered shift starts.
   */
  protected function offeredShiftStarts(Term $term): array {
    $controller = BadgeNextStepsController::create($this->container);
    $method = new \ReflectionMethod($controller, 'getFacilitatorsForBadge');
    $method->setAccessible(TRUE);
    $starts = [];
    foreach ($method->invoke($controller, $term) as $entry) {
      foreach ($entry['slots'] as $slot) {
        $starts[] = (int) $slot['start'];
      }
    }
    sort($starts);
    return $starts;
  }

  /**
   * Creates a badge issued by the host that needs the given checkout minutes.
   */
  protected function makeBadge(int $minutes): Term {
    $term = Term::create([
      'vid' => 'badges',
      'name' => 'Badge ' . $minutes,
      'field_badge_issuer' => [$this->host->id()],
      'field_badge_checkout_minutes' => $minutes,
    ]);
    $term->save();
    return $term;
  }

  /**
   * Books minutes [$from, $to) of the shift for the host.
   */
  protected function book(int $from, int $to, string $status = 'scheduled', ?int $host_uid = NULL): Node {
    $node = Node::create([
      'type' => 'appointment',
      'title' => 'Booking ' . $from . '-' . $to,
      'status' => 1,
      'field_appointment_host' => [['target_id' => $host_uid ?? (int) $this->host->id()]],
      'field_appointment_status' => $status,
      'field_appointment_timerange' => [[
        'value' => $this->shiftStart + $from * 60,
        'end_value' => $this->shiftStart + $to * 60,
        'duration' => $to - $from,
      ],
      ],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Ensures a field exists on the appointment bundle.
   */
  protected function ensureNodeField(string $field_name, string $type, array $settings): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $settings,
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
