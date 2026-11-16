<?php

declare(strict_types=1);

namespace Drupal\Tests\appointment_facilitator\Unit;

use Drupal\appointment_facilitator\Service\PendingBadgeReminder;
use Drupal\Tests\UnitTestCase;

/**
 * Who is reminded about a badge still pending, and what the email says.
 *
 * @group appointment_facilitator
 */
final class PendingBadgeReminderTest extends UnitTestCase {

  private const ENDED = 1_800_000_000;

  private const NAMES = [7 => 'Fay Facilitator', 20 => 'Mo Member', 21 => 'Nia Member'];
  private const LABELS = [5 => 'Laser Cutter', 6 => 'Vinyl Cutter'];
  private const OK = [7 => TRUE, 8 => FALSE];

  private function row(array $o = []): array {
    return $o + ['nid' => 100, 'host_uid' => 7, 'member_uid' => 20, 'tid' => 5, 'ended' => self::ENDED];
  }

  public function testOneAppointmentTwoBadgesIsOneLine(): void {
    $due = PendingBadgeReminder::group([$this->row(), $this->row(['tid' => 6])], self::NAMES, self::LABELS, self::OK);
    $this->assertCount(1, $due[7]);
    $this->assertSame(['Laser Cutter', 'Vinyl Cutter'], $due[7][0]['badges']);
  }

  public function testTwoMembersOnOneAppointmentAreTwoLines(): void {
    $due = PendingBadgeReminder::group([$this->row(), $this->row(['member_uid' => 21])], self::NAMES, self::LABELS, self::OK);
    $this->assertCount(2, $due[7]);
  }

  public function testOldestAppointmentFirst(): void {
    $due = PendingBadgeReminder::group([
      $this->row(['nid' => 101, 'ended' => self::ENDED + 86400]),
      $this->row(['nid' => 100, 'member_uid' => 21]),
    ], self::NAMES, self::LABELS, self::OK);
    $this->assertSame([100, 101], array_column($due[7], 'nid'));
  }

  public function testFacilitatorWhoCannotBeEmailedIsSkipped(): void {
    $this->assertSame([], PendingBadgeReminder::group([$this->row(['host_uid' => 8])], self::NAMES, self::LABELS, self::OK));
  }

  public function testHostIsNeverRemindedAboutTheirOwnBadge(): void {
    $this->assertSame([], PendingBadgeReminder::group([$this->row(['member_uid' => 7])], self::NAMES, self::LABELS, self::OK));
  }

  public function testUnknownBadgeOrMemberIsSkipped(): void {
    $this->assertSame([], PendingBadgeReminder::group([$this->row(['tid' => 99]), $this->row(['member_uid' => 99])], self::NAMES, self::LABELS, self::OK));
  }

  public function testEmailForOneBadge(): void {
    $due = PendingBadgeReminder::group([$this->row()], self::NAMES, self::LABELS, self::OK);
    $mail = PendingBadgeReminder::compose('Fay Facilitator', $due[7], 'https://www.example.org');
    $this->assertSame('A badge is waiting for your sign-off: Mo Member', $mail['subject']);
    $this->assertStringContainsString('Hi Fay Facilitator,', $mail['body']);
    $this->assertStringContainsString('- Mo Member: Laser Cutter (' . date('D j M', self::ENDED) . ')', $mail['body']);
    $this->assertStringContainsString('https://www.example.org/badges/pending/user/20', $mail['body']);
    $this->assertStringContainsString('You will not be reminded about that appointment again.', $mail['body']);
  }

  public function testEmailForSeveralCountsThem(): void {
    $due = PendingBadgeReminder::group([$this->row(), $this->row(['nid' => 101, 'member_uid' => 21])], self::NAMES, self::LABELS, self::OK);
    $mail = PendingBadgeReminder::compose('Fay Facilitator', $due[7], 'https://www.example.org');
    $this->assertSame('2 badges are waiting for your sign-off', $mail['subject']);
    $this->assertStringContainsString('These members had a badge checkout with you', $mail['body']);
  }

}
