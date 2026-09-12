<?php

declare(strict_types=1);

namespace Drupal\Tests\appointment_facilitator\Unit;

use Drupal\appointment_facilitator\Badge\VideoChapters;
use Drupal\Tests\UnitTestCase;

/**
 * Tests chapter extraction from badge video timestamp links.
 *
 * @group appointment_facilitator
 * @coversDefaultClass \Drupal\appointment_facilitator\Badge\VideoChapters
 */
class VideoChaptersTest extends UnitTestCase {

  /**
   * @covers ::videoId
   */
  public function testVideoIdForms(): void {
    $this->assertSame('7C_wvhiM4J0', VideoChapters::videoId('https://youtu.be/7C_wvhiM4J0?t=4'));
    $this->assertSame('7C_wvhiM4J0', VideoChapters::videoId('https://youtu.be/7C_wvhiM4J0?si=JlJkCYY1PU0BaO7D'));
    $this->assertSame('u7sRrC2Jpp4', VideoChapters::videoId('https://www.youtube.com/watch?v=u7sRrC2Jpp4'));
    $this->assertSame('u7sRrC2Jpp4', VideoChapters::videoId('https://www.youtube.com/watch?feature=share&v=u7sRrC2Jpp4&t=90'));
    $this->assertSame('u7sRrC2Jpp4', VideoChapters::videoId('https://www.youtube-nocookie.com/embed/u7sRrC2Jpp4'));
    $this->assertNull(VideoChapters::videoId('https://vimeo.com/12345'));
    $this->assertNull(VideoChapters::videoId(''));
  }

  /**
   * @covers ::startSeconds
   * @dataProvider startSecondsProvider
   */
  public function testStartSeconds(string $url, ?int $expected): void {
    $this->assertSame($expected, VideoChapters::startSeconds($url));
  }

  /**
   * Data provider for testStartSeconds().
   */
  public static function startSecondsProvider(): array {
    return [
      'plain seconds' => ['https://youtu.be/abc123def?t=120', 120],
      'seconds suffix' => ['https://youtu.be/abc123def?t=120s', 120],
      'minutes and seconds' => ['https://www.youtube.com/watch?v=abc123def&t=1m30s', 90],
      'hours' => ['https://www.youtube.com/watch?v=abc123def&t=1h2m3s', 3723],
      'start param' => ['https://www.youtube.com/embed/abc123def?start=45', 45],
      'fragment' => ['https://www.youtube.com/watch?v=abc123def#t=15', 15],
      'zero' => ['https://youtu.be/abc123def?t=0', 0],
      'no offset' => ['https://youtu.be/abc123def', NULL],
      'garbage' => ['https://youtu.be/abc123def?t=later', NULL],
    ];
  }

  /**
   * @covers ::label
   */
  public function testLabel(): void {
    $this->assertSame('0:04', VideoChapters::label(4));
    $this->assertSame('2:00', VideoChapters::label(120));
    $this->assertSame('16:13', VideoChapters::label(973));
    $this->assertSame('1:02:03', VideoChapters::label(3723));
    $this->assertSame('0:00', VideoChapters::label(-5));
  }

  /**
   * @covers ::group
   */
  public function testGroupSortsAndMatchesVideos(): void {
    $links = [
      ['uri' => 'https://youtu.be/AAAAAAAAAAA?t=120', 'title' => 'Thread and tension'],
      ['uri' => 'https://youtu.be/AAAAAAAAAAA?t=4', 'title' => 'Parts overview'],
      ['uri' => 'https://youtu.be/BBBBBBBBBBB?t=30', 'title' => 'Second video intro'],
      // Unknown video id falls back to the first video.
      ['uri' => 'https://youtu.be/ZZZZZZZZZZZ?t=60', 'title' => 'Orphan'],
      // No offset: ignored.
      ['uri' => 'https://youtu.be/AAAAAAAAAAA', 'title' => 'Whole video'],
      // Empty title falls back to the time label.
      ['uri' => 'https://youtu.be/AAAAAAAAAAA?t=200', 'title' => ''],
    ];
    $grouped = VideoChapters::group($links, ['AAAAAAAAAAA', 'BBBBBBBBBBB']);

    $this->assertSame(['AAAAAAAAAAA', 'BBBBBBBBBBB'], array_keys($grouped));
    $this->assertSame([4, 60, 120, 200], array_column($grouped['AAAAAAAAAAA'], 'seconds'));
    $this->assertSame(['Parts overview', 'Orphan', 'Thread and tension', '3:20'], array_column($grouped['AAAAAAAAAAA'], 'title'));
    $this->assertSame('0:04', $grouped['AAAAAAAAAAA'][0]['label']);
    $this->assertSame([['seconds' => 30, 'label' => '0:30', 'title' => 'Second video intro']], $grouped['BBBBBBBBBBB']);
  }

  /**
   * @covers ::group
   */
  public function testGroupWithoutVideosOrLinks(): void {
    $this->assertSame([], VideoChapters::group([['uri' => 'https://youtu.be/AAAAAAAAAAA?t=1', 'title' => 'x']], []));
    $this->assertSame(['AAAAAAAAAAA' => []], VideoChapters::group([], ['AAAAAAAAAAA']));
  }

}
