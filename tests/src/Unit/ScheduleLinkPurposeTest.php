<?php

declare(strict_types=1);

namespace Drupal\Tests\appointment_facilitator\Unit;

use Drupal\appointment_facilitator\Render\ScheduleLinkPurpose;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the purpose rewrite applied to facilitator schedule links.
 *
 * @group appointment_facilitator
 * @coversDefaultClass \Drupal\appointment_facilitator\Render\ScheduleLinkPurpose
 */
class ScheduleLinkPurposeTest extends UnitTestCase {

  /**
   * @covers ::rewrite
   */
  public function testRewriteAddsPurposeToViewLinks(): void {
    // The exact shape the facilitator_schedules view rewrite produces.
    $html = '<a href="/node/add/appointment/?host-uid=2520&amp;host-name=Darcy&amp;date=2026-09-14&amp;start_time=21:00" target="_blank">Request Appointment</a>'
      . '<a href="/node/add/appointment?date=2026-09-15">Bare</a>'
      . '<a href="/node/add/appointment/?purpose=checkout&amp;host-uid=1">Already set</a>'
      . '<a href="/appointments/all">Unrelated</a>';

    $out = ScheduleLinkPurpose::rewrite($html, 'project');

    $this->assertStringContainsString('href="/node/add/appointment/?purpose=project&amp;host-uid=2520&amp;host-name=Darcy', $out);
    $this->assertStringContainsString('href="/node/add/appointment?purpose=project&amp;date=2026-09-15"', $out);
    $this->assertStringContainsString('href="/node/add/appointment/?purpose=checkout&amp;host-uid=1"', $out);
    $this->assertStringContainsString('href="/appointments/all"', $out);
    $this->assertSame(1, substr_count($out, 'purpose=checkout'));
    $this->assertSame(2, substr_count($out, 'purpose=project'));
  }

  /**
   * @covers ::rewrite
   */
  public function testRewriteRejectsUnsafePurpose(): void {
    $html = '<a href="/node/add/appointment/?host-uid=1">x</a>';
    $this->assertSame($html, ScheduleLinkPurpose::rewrite($html, ''));
    $this->assertSame($html, ScheduleLinkPurpose::rewrite($html, 'pro ject"'));
  }

  /**
   * @covers ::postRender
   */
  public function testPostRenderReadsElementPurpose(): void {
    $element = ['#mh_schedule_purpose' => 'informational'];
    $out = (string) ScheduleLinkPurpose::postRender('<a href="/node/add/appointment/?host-uid=1">x</a>', $element);
    $this->assertStringContainsString('?purpose=informational&amp;host-uid=1', $out);

    $out = (string) ScheduleLinkPurpose::postRender('<a href="/node/add/appointment/?host-uid=1">x</a>', []);
    $this->assertStringContainsString('?purpose=project&amp;host-uid=1', $out);
  }

}
