<?php

declare(strict_types=1);

namespace Drupal\Tests\appointment_facilitator\Unit;

use Drupal\appointment_facilitator\Service\AppointmentDoorEvidence;
use Drupal\Tests\UnitTestCase;

/**
 * How a member's door scans are read against a badge appointment.
 *
 * @group appointment_facilitator
 */
final class AppointmentDoorEvidenceTest extends UnitTestCase {

  private const START = 1_800_000_000;
  private const END = self::START + 3600;

  public function testScanDuringTheAppointmentCounts(): void {
    $this->assertSame(['status' => 'in_window', 'time' => self::START + 60], AppointmentDoorEvidence::classify(self::START, self::END, [self::START + 60]));
  }

  public function testScanUpToAnHourBeforeCounts(): void {
    $this->assertSame('in_window', AppointmentDoorEvidence::classify(self::START, self::END, [self::START - 3600])['status']);
  }

  public function testEarliestScanInTheWindowIsReported(): void {
    $this->assertSame(self::START - 600, AppointmentDoorEvidence::classify(self::START, self::END, [self::START + 900, self::START - 600])['time']);
  }

  public function testScanOnlyLaterThatDayIsSameDay(): void {
    $door = AppointmentDoorEvidence::classify(self::START, self::END, [self::END + 7200, self::START - 5 * 3600]);
    $this->assertSame('same_day', $door['status']);
    $this->assertSame(self::END + 7200, $door['time'], 'The scan closest to the start is the one shown.');
  }

  public function testNoScanIsNone(): void {
    $this->assertSame(['status' => 'none', 'time' => NULL], AppointmentDoorEvidence::classify(self::START, self::END, []));
  }

  public function testWordingAsksToCheckWhenTheLogDisagrees(): void {
    $time = static fn (int $t): string => 'T';
    $this->assertStringContainsString('during the appointment', AppointmentDoorEvidence::describe(['status' => 'in_window', 'time' => 1], 'Mo', $time));
    $this->assertStringContainsString('Check before approving', AppointmentDoorEvidence::describe(['status' => 'same_day', 'time' => 1], 'Mo', $time));
    $this->assertStringContainsString('no scan for Mo that day', AppointmentDoorEvidence::describe(['status' => 'none', 'time' => NULL], 'Mo', $time));
  }

}
