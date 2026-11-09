<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Schedule;

/**
 * Groups facilitator shifts into the next seven days, one list per day.
 *
 * /facilitator/schedules shows one card per facilitator, so seeing who is in
 * on a given day meant reading every card. A facilitator asked for the whole
 * week at a glance back (#47010, feedback sid 21005).
 */
final class WeekAtAGlance {

  /**
   * Builds the days.
   *
   * @param array $facilitators
   *   Each: ['name' => string, 'focus' => string, 'off' => bool,
   *   'slots' => [['start' => int, 'end' => int], ...]].
   * @param int $now
   *   Current Unix time.
   * @param string $timezone
   *   Site timezone.
   * @param int $days
   *   How many days, starting today.
   *
   * @return array
   *   List of ['date' => 'Y-m-d', 'label' => 'Mon Nov 9', 'today' => bool,
   *   'shifts' => [['start','end','time','name','focus'], ...]] in date
   *   order; shifts sorted by start then name. Days with no one are kept so
   *   a quiet day reads as quiet rather than missing.
   */
  public static function build(array $facilitators, int $now, string $timezone, int $days = 7): array {
    $tz = new \DateTimeZone($timezone);
    $today = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
    $out = [];
    for ($i = 0; $i < $days; $i++) {
      $day = $today->modify("+$i days");
      $out[$day->format('Y-m-d')] = [
        'date' => $day->format('Y-m-d'),
        'label' => $day->format('D M j'),
        'today' => $i === 0,
        'shifts' => [],
      ];
    }

    foreach ($facilitators as $f) {
      if (!empty($f['off'])) {
        continue;
      }
      $seen = [];
      foreach ($f['slots'] ?? [] as $slot) {
        $start = (int) ($slot['start'] ?? 0);
        $end = (int) ($slot['end'] ?? 0);
        // Shifts already over drop off; one still running stays.
        if ($start <= 0 || ($end > 0 ? $end : $start) < $now) {
          continue;
        }
        $key = (new \DateTimeImmutable('@' . $start))->setTimezone($tz)->format('Y-m-d');
        if (!isset($out[$key]) || isset($seen[$start . '-' . $end])) {
          continue;
        }
        $seen[$start . '-' . $end] = TRUE;
        $out[$key]['shifts'][] = [
          'start' => $start,
          'end' => $end,
          'time' => self::timeRange($start, $end, $tz),
          'name' => (string) ($f['name'] ?? ''),
          'focus' => (string) ($f['focus'] ?? ''),
        ];
      }
    }

    foreach ($out as &$day) {
      usort($day['shifts'], fn(array $a, array $b) => [$a['start'], $a['name']] <=> [$b['start'], $b['name']]);
    }
    return array_values($out);
  }

  /**
   * Formats a shift as "3–6pm", "10am–1pm" or "7:30–10:30pm".
   */
  public static function timeRange(int $start, int $end, \DateTimeZone $tz): string {
    $s = (new \DateTimeImmutable('@' . $start))->setTimezone($tz);
    if ($end <= $start) {
      return self::clock($s, TRUE);
    }
    $e = (new \DateTimeImmutable('@' . $end))->setTimezone($tz);
    $same_half = $s->format('a') === $e->format('a');
    return self::clock($s, !$same_half) . '–' . self::clock($e, TRUE);
  }

  /**
   * Formats one end of a shift: "3pm", "7:30", "10am".
   */
  private static function clock(\DateTimeImmutable $t, bool $with_suffix): string {
    $text = $t->format('i') === '00' ? $t->format('g') : $t->format('g:i');
    return $with_suffix ? $text . $t->format('a') : $text;
  }

}
