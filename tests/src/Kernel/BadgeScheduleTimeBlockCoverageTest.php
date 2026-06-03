<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\appointment_facilitator\Controller\BadgeNextStepsController;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Verifies the badge schedule grid renders slots across the full day.
 *
 * Regression guard: the grid previously used three sparse time blocks
 * (9-11 AM, 11 AM-2 PM, 5-8 PM) and silently dropped any availability set
 * for 2-5 PM or after 8 PM, hiding fully-eligible facilitators (e.g. a
 * standing Tue 9 PM slot) from the badge schedule. The blocks must now
 * cover hours 0-23 with no gaps.
 *
 * @group appointment_facilitator
 */
class BadgeScheduleTimeBlockCoverageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'datetime',
    'smart_date',
    'profile',
    'taxonomy',
    'text',
    'filter',
    'appointment_facilitator',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('profile');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('system', ['sequences']);

    // Deterministic timezone so slot hours map to blocks predictably.
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
  }

  /**
   * A facilitator with afternoon (2 PM) and late (9 PM) slots must render.
   */
  public function testFullDaySlotsRenderInScheduleGrid(): void {
    $now = \Drupal::time()->getRequestTime();
    // Two days out keeps both slots safely inside the now..+7d window.
    $base = (new \DateTimeImmutable('@' . $now))
      ->setTimezone(new \DateTimeZone('UTC'))
      ->modify('+2 days');
    // 2 PM (old "dead zone" between midday and evening) and 9 PM (old
    // "dead zone" after the evening block) — both dropped before the fix.
    $afternoon_ts = $base->setTime(14, 0)->getTimestamp();
    $evening_ts = $base->setTime(21, 0)->getTimestamp();

    $user = User::create([
      'name' => 'late_facilitator',
      'mail' => 'late-facilitator@example.com',
      'status' => 1,
    ]);
    $user->addRole('facilitator');
    $user->save();

    Profile::create([
      'type' => 'coordinator',
      'uid' => $user->id(),
      'status' => 1,
      'field_coordinator_hours' => [
        [
          'value' => $afternoon_ts,
          'end_value' => $afternoon_ts + 3600,
          'duration' => 60,
        ],
        [
          'value' => $evening_ts,
          'end_value' => $evening_ts + 3600,
          'duration' => 60,
        ],
      ],
    ])->save();

    $term = Term::create([
      'vid' => 'badges',
      'name' => 'Large Format Printer Badge',
      'field_badge_issuer' => [$user->id()],
    ]);
    $term->save();

    $controller = BadgeNextStepsController::create($this->container);
    $ref = new \ReflectionObject($controller);

    $get = $ref->getMethod('getFacilitatorsForBadge');
    $get->setAccessible(TRUE);
    $facilitators = $get->invoke($controller, $term);
    $this->assertCount(1, $facilitators, 'Eligible facilitator resolved for the badge.');

    $build_table = $ref->getMethod('buildFacilitatorScheduleTable');
    $build_table->setAccessible(TRUE);
    // Gated mode renders inert spans (no node.add route lookup needed) while
    // exercising the same block-mapping logic that the fix touched.
    $build = $build_table->invoke($controller, $facilitators, (int) $term->id(), TRUE);

    $html = (string) \Drupal::service('renderer')->renderRoot($build);

    // Both previously-dropped slots now render.
    $this->assertStringContainsString('2:00pm', $html, 'Afternoon (2 PM) slot is rendered.');
    $this->assertStringContainsString('9:00pm', $html, 'Late evening (9 PM) slot is rendered.');
    // And their columns are present.
    $this->assertStringContainsString('Afternoon', $html);
    $this->assertStringContainsString('Evening', $html);
  }

}
