<?php

namespace Drupal\appointment_facilitator\ToolHelpers;

/**
 * Picks who to tag when a member asks about a tool on Slack.
 *
 * Pure: no Drupal services, so the rules are unit-tested on their own.
 *
 * Rules (JR, 2026-11-09):
 * - Volunteers first. Tiers are tried in order: people listed on the tool,
 *   then facilitators who sign off its badge.
 * - Rotate: within a tier, whoever was tagged longest ago (or never) goes
 *   first, so one volunteer is not pinged every time.
 * - Weekly cap: someone tagged $weeklyCap times in the last 7 days is skipped
 *   while anyone else is available.
 * - Staff answer only when volunteers cannot: a staff member is tagged only
 *   when no volunteer can be.
 */
final class HelperRanker {

  /**
   * Returns up to $max uids to tag, best first.
   *
   * @param int[][] $tiers
   *   Lists of uids, most preferred tier first. Callers pass only people who
   *   can be tagged (they have a Slack ID).
   * @param array<int, int> $weekCounts
   *   Map of uid => times tagged in the last 7 days.
   * @param array<int, int> $lastTagged
   *   Map of uid => unix time of the last tag.
   * @param int[] $staff
   *   The uids of staff.
   * @param int[] $exclude
   *   The uids never to tag (the person asking).
   * @param int $max
   *   Most people to tag.
   * @param int $weeklyCap
   *   Tags per person per 7 days before they are skipped.
   *
   * @return int[]
   *   The uids to tag, best first.
   */
  public static function pick(array $tiers, array $weekCounts, array $lastTagged, array $staff, array $exclude = [], int $max = 3, int $weeklyCap = 3): array {
    $seen = array_fill_keys(array_map('intval', $exclude), TRUE);
    $staff = array_fill_keys(array_map('intval', $staff), TRUE);

    $ordered = [];
    foreach ($tiers as $tier) {
      $tier = array_values(array_unique(array_map('intval', $tier)));
      $position = array_flip($tier);
      usort($tier, static function (int $a, int $b) use ($lastTagged, $position): int {
        $cmp = ($lastTagged[$a] ?? 0) <=> ($lastTagged[$b] ?? 0);
        return $cmp !== 0 ? $cmp : $position[$a] <=> $position[$b];
      });
      foreach ($tier as $uid) {
        if ($uid > 0 && !isset($seen[$uid])) {
          $seen[$uid] = TRUE;
          $ordered[] = $uid;
        }
      }
    }

    $volunteers = array_values(array_filter($ordered, static fn(int $uid): bool => !isset($staff[$uid])));
    $fresh = array_values(array_filter($volunteers, static fn(int $uid): bool => ($weekCounts[$uid] ?? 0) < $weeklyCap));
    $capped = array_values(array_diff($volunteers, $fresh));

    $picked = array_slice($fresh, 0, $max);
    if (!$picked) {
      // Every volunteer hit the cap: still better to ask one than nobody.
      $picked = array_slice($capped, 0, 1);
    }
    if (!$picked) {
      $picked = array_slice(array_values(array_filter($ordered, static fn(int $uid): bool => isset($staff[$uid]))), 0, 1);
    }
    return $picked;
  }

  /**
   * Builds the Slack text for a question.
   *
   * @param string $toolLabel
   *   Tool name.
   * @param string $toolUrl
   *   Absolute tool page URL.
   * @param string $askerSlackId
   *   The member asking.
   * @param string $question
   *   Their question, as typed.
   * @param string[] $helperSlackIds
   *   Slack IDs to tag.
   */
  public static function message(string $toolLabel, string $toolUrl, string $askerSlackId, string $question, array $helperSlackIds): string {
    $escape = static fn(string $s): string => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    $quoted = implode("\n", array_map(
      static fn(string $line): string => '>' . $line,
      preg_split('/\R/', trim($escape($question))) ?: []
    ));
    $text = sprintf("<@%s> asked about <%s|%s>:\n%s", $askerSlackId, $toolUrl, $escape($toolLabel), $quoted);
    if ($helperSlackIds) {
      $tags = implode(' ', array_map(static fn(string $id): string => '<@' . $id . '>', $helperSlackIds));
      $text .= "\n\n" . $tags . ' you are listed as people who know this tool. Could one of you answer in this thread?';
    }
    return $text;
  }

}
