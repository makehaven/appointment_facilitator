<?php

declare(strict_types=1);

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\appointment_facilitator\Controller\FacilitatorDashboardController;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Regression test: extractAppointmentTimestamp() returns the correct Unix
 * timestamp for both Smart Date timerange and date-only fallback paths.
 *
 * Pre-fix bug:
 *   - The timerange branch ran strtotime() on a numeric Smart Date value
 *     (returns false, can't parse a Unix-timestamp string), so it silently
 *     fell through to the date-only branch.
 *   - The date-only branch ran strtotime("$date 12:00:00") with no explicit
 *     timezone, parsing as UTC on Pantheon — producing a 4-hour drift.
 *
 * @group appointment_facilitator
 */
class FacilitatorDashboardTimestampExtractorTest extends KernelTestBase {

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
    'smart_date',
    'profile',
    'appointment_facilitator',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node']);

    \Drupal::configFactory()->getEditable('system.date')
      ->set('timezone.default', 'America/New_York')
      ->save();

    if (!NodeType::load('appointment')) {
      NodeType::create(['type' => 'appointment', 'name' => 'Appointment'])->save();
    }

    if (!FieldStorageConfig::loadByName('node', 'field_appointment_timerange')) {
      FieldStorageConfig::create([
        'field_name' => 'field_appointment_timerange',
        'entity_type' => 'node',
        'type' => 'smartdate',
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'appointment', 'field_appointment_timerange')) {
      FieldConfig::create([
        'field_name' => 'field_appointment_timerange',
        'entity_type' => 'node',
        'bundle' => 'appointment',
        'label' => 'Timerange',
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('node', 'field_appointment_date')) {
      FieldStorageConfig::create([
        'field_name' => 'field_appointment_date',
        'entity_type' => 'node',
        'type' => 'datetime',
        'settings' => ['datetime_type' => 'date'],
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'appointment', 'field_appointment_date')) {
      FieldConfig::create([
        'field_name' => 'field_appointment_date',
        'entity_type' => 'node',
        'bundle' => 'appointment',
        'label' => 'Date',
      ])->save();
    }
  }

  /**
   * Smart Date timerange path returns the stored Unix timestamp exactly.
   */
  public function testTimerangePathReturnsExactTimestamp(): void {
    $tz = new \DateTimeZone('America/New_York');
    $start = new \DateTimeImmutable('2026-06-15 20:00:00', $tz);
    $node = $this->createNode([
      'field_appointment_timerange' => [[
        'value' => $start->getTimestamp(),
        'end_value' => $start->modify('+30 minutes')->getTimestamp(),
        'duration' => 30,
        'timezone' => 'America/New_York',
      ]],
    ]);

    $this->assertSame($start->getTimestamp(), $this->extract($node));
  }

  /**
   * Date-only fallback anchors at noon in the site timezone (not UTC).
   */
  public function testDateOnlyFallbackUsesSiteTimezone(): void {
    $node = $this->createNode([
      'field_appointment_date' => ['value' => '2026-06-15'],
    ]);

    $expected = (new \DateTimeImmutable('2026-06-15 12:00:00', new \DateTimeZone('America/New_York')))->getTimestamp();
    $this->assertSame($expected, $this->extract($node));
  }

  /**
   * Date-only fallback during EST winter — still anchored to site timezone.
   */
  public function testDateOnlyFallbackEstWinter(): void {
    $node = $this->createNode([
      'field_appointment_date' => ['value' => '2026-12-15'],
    ]);

    $expected = (new \DateTimeImmutable('2026-12-15 12:00:00', new \DateTimeZone('America/New_York')))->getTimestamp();
    $this->assertSame($expected, $this->extract($node));
  }

  /**
   * No timerange and no date → NULL.
   */
  public function testNoFieldsReturnsNull(): void {
    $node = $this->createNode([]);
    $this->assertNull($this->extract($node));
  }

  /**
   * Builds a saved appointment node with the supplied field values.
   */
  protected function createNode(array $fields): Node {
    $member = User::create([
      'name' => 'm_' . uniqid(),
      'mail' => uniqid() . '@example.com',
      'status' => 1,
    ]);
    $member->save();

    $node = Node::create([
      'type' => 'appointment',
      'title' => 'TS extractor regression',
      'uid' => $member->id(),
      'status' => 1,
    ] + $fields);
    $node->save();
    return $node;
  }

  /**
   * Reflectively invokes the protected extractor.
   *
   * The controller's full create() factory pulls in services unrelated to
   * this method (AssetAvailability etc.). The extractor only uses
   * $this->config(), so we instantiate without a constructor and rely on
   * ControllerBase's container-driven config helper.
   *
   * Sets PHP's default timezone to UTC immediately before invocation to
   * simulate Pantheon (UTC-based PHP-FPM with America/New_York site
   * config). Drupal aligns PHP TZ to site TZ during bootstrap, which would
   * otherwise mask the date-only-fallback bug locally.
   */
  protected function extract(Node $node): ?int {
    $reflector = new \ReflectionClass(FacilitatorDashboardController::class);
    $controller = $reflector->newInstanceWithoutConstructor();
    $method = $reflector->getMethod('extractAppointmentTimestamp');
    $method->setAccessible(TRUE);

    $previous = date_default_timezone_get();
    date_default_timezone_set('UTC');
    try {
      return $method->invoke($controller, $node);
    }
    finally {
      date_default_timezone_set($previous);
    }
  }

}
