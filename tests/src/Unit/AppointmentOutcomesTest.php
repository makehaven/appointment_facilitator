<?php

declare(strict_types=1);

namespace Drupal\Tests\appointment_facilitator\Unit;

use Drupal\appointment_facilitator\Service\AppointmentOutcomes;
use Drupal\Tests\UnitTestCase;

/**
 * Wording for facilitator outcomes and members' own appointment reports.
 *
 * @group appointment_facilitator
 */
final class AppointmentOutcomesTest extends UnitTestCase {

  /**
   * The three "not approving" choices, in button order.
   */
  public function testThreeOutcomes(): void {
    $this->assertSame(['no_show', 'retry', 'not_held'], array_keys(AppointmentOutcomes::labels()));
  }

  /**
   * Each member report option reads plainly; unknown values say nothing.
   */
  public function testMemberReportWording(): void {
    $this->assertSame('Member reported: met, session went well', AppointmentOutcomes::reportLabel('met_successful'));
    $this->assertSame('Member reported: they could not attend', AppointmentOutcomes::reportLabel('member_absent'));
    $this->assertSame('Member reported: the facilitator did not show', AppointmentOutcomes::reportLabel('volunteer_absent'));
    $this->assertSame('', AppointmentOutcomes::reportLabel(''));
  }

}
