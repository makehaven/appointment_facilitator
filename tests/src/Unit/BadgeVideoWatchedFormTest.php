<?php

declare(strict_types=1);

namespace Drupal\Tests\appointment_facilitator\Unit;

use Drupal\appointment_facilitator\Form\BadgeVideoWatchedForm;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserDataInterface;

/**
 * Covers the static watched-flag helper that the stepper reads from.
 *
 * @coversDefaultClass \Drupal\appointment_facilitator\Form\BadgeVideoWatchedForm
 * @group appointment_facilitator
 */
class BadgeVideoWatchedFormTest extends UnitTestCase {

  /**
   * @covers ::isWatched
   */
  public function testIsWatchedReturnsFalseForAnonymous(): void {
    $userData = $this->createMock(UserDataInterface::class);
    $userData->expects($this->never())->method('get');
    $this->assertFalse(BadgeVideoWatchedForm::isWatched($userData, 0, 123));
  }

  /**
   * @covers ::isWatched
   */
  public function testIsWatchedReturnsFalseForInvalidTid(): void {
    $userData = $this->createMock(UserDataInterface::class);
    $userData->expects($this->never())->method('get');
    $this->assertFalse(BadgeVideoWatchedForm::isWatched($userData, 5, 0));
  }

  /**
   * @covers ::isWatched
   */
  public function testIsWatchedReturnsTrueWhenTimestampStored(): void {
    $userData = $this->createMock(UserDataInterface::class);
    $userData->expects($this->once())
      ->method('get')
      ->with('appointment_facilitator', 5, 'badge_video_watched.123')
      ->willReturn(1700000000);
    $this->assertTrue(BadgeVideoWatchedForm::isWatched($userData, 5, 123));
  }

  /**
   * @covers ::isWatched
   *
   * NULL = the row was deleted via `clear()`; that path should read as
   * "not watched" so the stepper flips step 2 back to current.
   */
  public function testIsWatchedReturnsFalseWhenStorageReturnsNull(): void {
    $userData = $this->createMock(UserDataInterface::class);
    $userData->method('get')->willReturn(NULL);
    $this->assertFalse(BadgeVideoWatchedForm::isWatched($userData, 5, 123));
  }

  /**
   * @covers ::isWatched
   *
   * `user.data` returns 0 when the column exists but is zero. Treat as
   * not-watched — defensive guard against a malformed write.
   */
  public function testIsWatchedReturnsFalseWhenStorageReturnsZero(): void {
    $userData = $this->createMock(UserDataInterface::class);
    $userData->method('get')->willReturn(0);
    $this->assertFalse(BadgeVideoWatchedForm::isWatched($userData, 5, 123));
  }

}
