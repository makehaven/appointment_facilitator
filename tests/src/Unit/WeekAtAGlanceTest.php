<?php

declare(strict_types=1);

namespace Drupal\Tests\appointment_facilitator\Unit;

use Drupal\appointment_facilitator\Schedule\WeekAtAGlance;
use Drupal\Tests\UnitTestCase;

/**
 * The week view on /facilitator/schedules (#47010).
 *
 * @coversDefaultClass \Drupal\appointment_facilitator\Schedule\WeekAtAGlance
 * @group appointment_facilitator
 */
class WeekAtAGlanceTest extends UnitTestCase {

  private const TZ = 'America/New_York';

  /**
   * Local New Haven time to a Unix timestamp.
   */
  private static function t(string $local): int {
    return (new \DateTimeImmutable($local, new \DateTimeZone(self::TZ)))->getTimestamp();
  }

  /**
   * @covers ::build
   */
  public function testGroupsByLocalDaySkipsOffAndPast(): void {
    $now = self::t('2026-11-09 16:00');
    $facilitators = [
      [
        'name' => 'Mike',
        'focus' => 'Woodworking',
        'slots' => [
        // Running now: kept.
        ['start' => self::t('2026-11-09 15:00'), 'end' => self::t('2026-11-09 18:00')],
        // Next week: outside seven days.
        ['start' => self::t('2026-11-16 15:00'), 'end' => self::t('2026-11-16 18:00')],
        ],
      ],
      [
        'name' => 'Ann',
        'focus' => 'Sewing',
        'slots' => [
        // Ended this morning: dropped.
        ['start' => self::t('2026-11-09 09:00'), 'end' => self::t('2026-11-09 12:00')],
        // Late evening, still the 12th locally though the 13th in UTC.
        ['start' => self::t('2026-11-12 20:00'), 'end' => self::t('2026-11-12 23:00')],
        // Literal duplicate collapses.
        ['start' => self::t('2026-11-12 20:00'), 'end' => self::t('2026-11-12 23:00')],
        ],
      ],
      [
        'name' => 'Off',
        'focus' => 'Metal',
        'off' => TRUE,
        'slots' => [
        ['start' => self::t('2026-11-10 15:00'), 'end' => self::t('2026-11-10 18:00')],
        ],
      ],
    ];
    $days = WeekAtAGlance::build($facilitators, $now, self::TZ);

    $this->assertCount(7, $days);
    $this->assertSame('2026-11-09', $days[0]['date']);
    $this->assertTrue($days[0]['today']);
    $this->assertSame('2026-11-15', $days[6]['date']);
    $this->assertSame(['Mike'], array_column($days[0]['shifts'], 'name'));
    $this->assertSame('3–6pm', $days[0]['shifts'][0]['time']);
    $this->assertSame([], $days[1]['shifts'], 'A facilitator marked off this week is left out.');
    $this->assertSame(['Ann'], array_column($days[3]['shifts'], 'name'));
    $this->assertSame('8–11pm', $days[3]['shifts'][0]['time']);
  }

  /**
   * @covers ::timeRange
   */
  public function testTimeRange(): void {
    $tz = new \DateTimeZone(self::TZ);
    $this->assertSame('10am–1pm', WeekAtAGlance::timeRange(self::t('2026-11-10 10:00'), self::t('2026-11-10 13:00'), $tz));
    $this->assertSame('7:30–10:30pm', WeekAtAGlance::timeRange(self::t('2026-11-10 19:30'), self::t('2026-11-10 22:30'), $tz));
  }

}
