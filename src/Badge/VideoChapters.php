<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Badge;

/**
 * Turns a badge's YouTube timestamp links into chapter lists per video.
 *
 * `field_badge_video_timestamps` holds ordinary link items such as
 * `https://youtu.be/7C_wvhiM4J0?t=120` titled "Thread and tension discussion".
 * The lightbox on the tool-page badge embed needs those as seconds + label,
 * grouped under the video they belong to, so a member can jump straight to
 * the part they want to review.
 */
final class VideoChapters {

  /**
   * Extracts the YouTube video id from a URL, or NULL.
   *
   * Mirrors youtube_get_video_id() from the youtube module so this class stays
   * usable in unit tests without bootstrapping that module.
   */
  public static function videoId(string $url): ?string {
    if (preg_match("~^(?:https?://)?(?:www\.|m\.)?(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:(?:watch)?\?(?:.*&)?v(?:i)?=|(?:embed|v|vi|shorts|live)/))([A-Za-z0-9_-]{6,})~", trim($url), $m)) {
      return $m[1];
    }
    return NULL;
  }

  /**
   * Reads the start offset (in seconds) from a YouTube URL, or NULL.
   *
   * Accepts `t=90`, `t=90s`, `t=1m30s`, `t=1h2m3s`, and `start=90`.
   */
  public static function startSeconds(string $url): ?int {
    $query = (string) parse_url(trim($url), PHP_URL_QUERY);
    $fragment = (string) parse_url(trim($url), PHP_URL_FRAGMENT);
    parse_str($query, $params);
    if (!isset($params['t']) && !isset($params['start']) && $fragment !== '') {
      parse_str($fragment, $params);
    }
    $raw = $params['t'] ?? $params['start'] ?? NULL;
    if ($raw === NULL || $raw === '') {
      return NULL;
    }
    $raw = strtolower(trim((string) $raw));
    if (ctype_digit($raw)) {
      return (int) $raw;
    }
    if (!preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/', $raw, $m) || $m[0] === '') {
      return NULL;
    }
    return ((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + (int) ($m[3] ?? 0);
  }

  /**
   * Formats seconds as M:SS or H:MM:SS.
   */
  public static function label(int $seconds): string {
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0
      ? sprintf('%d:%02d:%02d', $h, $m, $s)
      : sprintf('%d:%02d', $m, $s);
  }

  /**
   * Groups timestamp links by video id.
   *
   * @param array<int, array{uri: string, title: string}> $links
   *   Link items (uri + title) in field order.
   * @param string[] $video_ids
   *   The badge's video ids in field order. Links whose video is not one of
   *   these (or that carry no recognisable id) fall back to the first video.
   *
   * @return array<string, array<int, array{seconds: int, label: string, title: string}>>
   *   Chapters keyed by video id, sorted by start time. Videos without
   *   chapters are present with an empty list.
   */
  public static function group(array $links, array $video_ids): array {
    $chapters = array_fill_keys($video_ids, []);
    if (!$video_ids) {
      return [];
    }
    $fallback = $video_ids[0];
    foreach ($links as $link) {
      $uri = (string) ($link['uri'] ?? '');
      $seconds = self::startSeconds($uri);
      if ($seconds === NULL) {
        continue;
      }
      $id = self::videoId($uri);
      $key = ($id !== NULL && array_key_exists($id, $chapters)) ? $id : $fallback;
      $title = trim((string) ($link['title'] ?? ''));
      $chapters[$key][] = [
        'seconds' => $seconds,
        'label' => self::label($seconds),
        'title' => $title !== '' ? $title : self::label($seconds),
      ];
    }
    foreach ($chapters as &$list) {
      usort($list, static fn(array $a, array $b): int => $a['seconds'] <=> $b['seconds']);
    }
    unset($list);
    return $chapters;
  }

}
