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

  /**
   * Keywords drop common words and plurals.
   */
  public function testKeywords(): void {
    $this->assertSame(['mic', 'record', 'podcast', 'two', 'people'], HelperRanker::keywords('Which mics should I use to record a podcast with two people?'));
  }

  /**
   * An earlier question about the same thing is found; an unrelated one is not.
   */
  public function testSimilarQuestions(): void {
    $earlier = [
      ['question' => 'How do I book the podcast room?', 'created' => 1],
      ['question' => 'What mic is best for recording a podcast?', 'created' => 2],
      ['question' => 'Is the green screen working?', 'created' => 3],
    ];
    $found = HelperRanker::similarQuestions('Which mic should I use to record a podcast with two people?', $earlier);
    $this->assertSame([2], array_column($found, 'created'));
  }

  /**
   * A short question matches on one shared keyword; nothing matches nothing.
   */
  public function testSimilarQuestionsShort(): void {
    $earlier = [['question' => 'Where are the SD cards kept?', 'created' => 1]];
    $this->assertCount(1, HelperRanker::similarQuestions('SD card?', $earlier));
    $this->assertSame([], HelperRanker::similarQuestions('?', $earlier));
  }

}
