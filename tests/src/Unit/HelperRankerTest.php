<?php

namespace Drupal\Tests\appointment_facilitator\Unit;

use Drupal\appointment_facilitator\ToolHelpers\HelperRanker;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\appointment_facilitator\ToolHelpers\HelperRanker
 * @group appointment_facilitator
 */
class HelperRankerTest extends TestCase {

  /**
   * Listed people come before badge sign-off people, three at most.
   */
  public function testTiersInOrderCappedAtThree(): void {
    $this->assertSame([1, 2, 3], HelperRanker::pick([[1, 2], [3, 4]], [], [], []));
  }

  /**
   * Within a tier, whoever was tagged longest ago goes first.
   */
  public function testRotationWithinTier(): void {
    $last = [1 => 300, 2 => 100];
    $this->assertSame([3, 2, 1], HelperRanker::pick([[1, 2, 3]], [], $last, []));
  }

  /**
   * People at the weekly cap are skipped while others are free.
   */
  public function testWeeklyCapSkips(): void {
    $this->assertSame([2], HelperRanker::pick([[1, 2]], [1 => 3], [], []));
  }

  /**
   * When every volunteer is capped, one is still tagged.
   */
  public function testAllCappedStillTagsOne(): void {
    $this->assertSame([1], HelperRanker::pick([[1, 2]], [1 => 3, 2 => 5], [], []));
  }

  /**
   * Staff are tagged only when no volunteer can be.
   */
  public function testStaffLast(): void {
    $this->assertSame([2], HelperRanker::pick([[9, 2]], [], [], [9]));
    $this->assertSame([9], HelperRanker::pick([[9]], [], [], [9]));
  }

  /**
   * The asker is never tagged, and duplicates across tiers count once.
   */
  public function testExcludesAskerAndDedupes(): void {
    $this->assertSame([2, 3], HelperRanker::pick([[1, 2], [2, 3]], [], [], [], [1]));
    $this->assertSame([], HelperRanker::pick([[1]], [], [], [], [1]));
  }

  /**
   * The Slack text quotes the question, escapes it, and tags helpers.
   */
  public function testMessage(): void {
    $text = HelperRanker::message('Mic <A&B>', 'https://x/node/1', 'UASK', "Line one\nLine <two>", ['UH1', 'UH2']);
    $this->assertStringStartsWith('<@UASK> asked about <https://x/node/1|Mic &lt;A&amp;B&gt;>:', $text);
    $this->assertStringContainsString(">Line one\n>Line &lt;two&gt;", $text);
    $this->assertStringContainsString('<@UH1> <@UH2> you are listed', $text);
    $this->assertStringNotContainsString('you are listed', HelperRanker::message('T', 'u', 'U1', 'q', []));
  }

}
