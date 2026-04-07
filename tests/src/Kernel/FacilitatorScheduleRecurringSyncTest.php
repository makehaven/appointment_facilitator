<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Drupal\smart_date_recur\Entity\SmartDateOverride;
use Drupal\smart_date_recur\Entity\SmartDateRule;
use Drupal\user\Entity\User;

/**
 * Verifies recurring facilitator schedule rows stay aligned with overrides.
 *
 * @group appointment_facilitator
 */
class FacilitatorScheduleRecurringSyncTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'datetime',
    'smart_date',
    'smart_date_recur',
    'profile',
    'appointment_facilitator',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('profile');
    $this->installEntitySchema('smart_date_rule');
    $this->installEntitySchema('smart_date_override');
    $this->installSchema('system', ['sequences']);

    ProfileType::create([
      'id' => 'coordinator',
      'label' => 'Coordinator',
    ])->save();

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
      'third_party_settings' => [
        'smart_date_recur' => [
          'allow_recurring' => TRUE,
          'month_limit' => 12,
        ],
      ],
    ])->save();
  }

  /**
   * Ensures cancelled Smart Date instances are not re-saved onto the profile.
   */
  public function testCancelledRecurringInstanceIsRemovedOnProfileSave(): void {
    $user = User::create([
      'name' => 'facilitator_recurring_sync',
      'mail' => 'facilitator-recurring-sync@example.com',
      'status' => 1,
    ]);
    $user->save();

    $profile = Profile::create([
      'type' => 'coordinator',
      'uid' => $user->id(),
      'status' => 1,
    ]);
    $profile->save();

    $start = strtotime('2026-04-06 18:00:00 UTC');
    $end = $start + 3600;

    $rule = SmartDateRule::create([
      'freq' => 'WEEKLY',
      'limit' => 'COUNT=3',
      'entity_type' => 'profile',
      'bundle' => 'coordinator',
      'field_name' => 'field_coordinator_hours',
      'start' => $start,
      'end' => $end,
      'instances' => ['data' => []],
    ]);
    $rule->save();

    $instances = $rule->getRuleInstances();
    $this->assertCount(3, $instances, 'The test recurrence produced three schedule instances.');

    $profile->set('field_coordinator_hours', $this->buildRowsFromInstances($instances, (int) $rule->id()));
    $profile->save();

    SmartDateOverride::create([
      'rrule' => (int) $rule->id(),
      'rrule_index' => 1,
    ])->save();

    $profile = Profile::load($profile->id());
    $after_cancel = $profile->get('field_coordinator_hours')->getValue();
    $this->assertCount(2, $after_cancel, 'Cancelled instances are removed from the profile field.');
    $this->assertSame([0, 2], array_map(static fn(array $row): int => (int) $row['rrule_index'], $after_cancel));

    // Simulate a stale writer putting all generated rows back on the profile.
    $profile->set('field_coordinator_hours', $this->buildRowsFromInstances($instances, (int) $rule->id()));
    $profile->save();

    $profile = Profile::load($profile->id());
    $final_rows = $profile->get('field_coordinator_hours')->getValue();
    $this->assertCount(2, $final_rows, 'Profile presave normalization strips cancelled recurring instances.');
    $this->assertSame([0, 2], array_map(static fn(array $row): int => (int) $row['rrule_index'], $final_rows));
  }

  /**
   * Ensures rule updates keep cancelled instances absent while adding new ones.
   */
  public function testRuleUpdateKeepsCancelledInstancesRemoved(): void {
    [$profile, $rule] = $this->createRecurringCoordinatorProfile('facilitator_rule_sync');

    SmartDateOverride::create([
      'rrule' => (int) $rule->id(),
      'rrule_index' => 1,
    ])->save();

    $profile = Profile::load($profile->id());
    $after_cancel = $profile->get('field_coordinator_hours')->getValue();
    $this->assertCount(2, $after_cancel, 'Cancelled instances are removed before rule update.');
    $this->assertSame([0, 2], array_map(static fn(array $row): int => (int) $row['rrule_index'], $after_cancel));

    $rule->set('limit', 'COUNT=4');
    $rule->save();

    $profile = Profile::load($profile->id());
    $final_rows = $profile->get('field_coordinator_hours')->getValue();
    $this->assertCount(3, $final_rows, 'Rule updates add new future instances without restoring cancelled ones.');
    $this->assertSame([0, 2, 3], array_map(static fn(array $row): int => (int) $row['rrule_index'], $final_rows));
  }

  /**
   * Creates a coordinator profile with a weekly recurring Smart Date rule.
   */
  protected function createRecurringCoordinatorProfile(string $username): array {
    $user = User::create([
      'name' => $username,
      'mail' => $username . '@example.com',
      'status' => 1,
    ]);
    $user->save();

    $profile = Profile::create([
      'type' => 'coordinator',
      'uid' => $user->id(),
      'status' => 1,
    ]);
    $profile->save();

    $start = strtotime('2026-04-06 18:00:00 UTC');
    $end = $start + 3600;

    $rule = SmartDateRule::create([
      'freq' => 'WEEKLY',
      'limit' => 'COUNT=3',
      'entity_type' => 'profile',
      'bundle' => 'coordinator',
      'field_name' => 'field_coordinator_hours',
      'start' => $start,
      'end' => $end,
      'instances' => ['data' => []],
    ]);
    $rule->save();

    $instances = $rule->getRuleInstances();
    $this->assertCount(3, $instances, 'The test recurrence produced three schedule instances.');

    $profile->set('field_coordinator_hours', $this->buildRowsFromInstances($instances, (int) $rule->id()));
    $profile->save();

    return [$profile, $rule];
  }

  /**
   * Builds Smart Date field rows from generated rule instances.
   */
  protected function buildRowsFromInstances(array $instances, int $rid): array {
    $rows = [];
    foreach ($instances as $rrule_index => $instance) {
      $rows[] = [
        'value' => $instance['value'],
        'end_value' => $instance['end_value'],
        'duration' => ((int) $instance['end_value'] - (int) $instance['value']) / 60,
        'rrule' => $rid,
        'rrule_index' => $rrule_index,
      ];
    }

    return $rows;
  }

}
