<?php

namespace Drupal\appointment_facilitator\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Computes how many members hold a given badge and ranks it against others.
 *
 * The queries scan the badge_request node table. Results are cached for a
 * day under the `node_list:badge_request` tag so any change to badge state
 * invalidates the stats.
 */
class BadgePopularityStats {

  protected const CACHE_TTL = 86400;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CacheBackendInterface $cache,
    protected Connection $database,
  ) {}

  /**
   * Returns stats for a single badge.
   *
   * @return array
   *   Keys:
   *   - holders: int — distinct active holders of this badge
   *   - active_makers: int — total active members (denominator)
   *   - percent_of_active: int|null — % of active members holding this badge
   *   - rank: int — 1-based position among badges sorted by holder count
   *   - total_ranked_badges: int — how many badges have at least one holder
   *   - earned_last_30d: int — badge_request nodes for this badge created
   *     in the past 30 days. Captures whether the badge is actively being
   *     earned today vs. legacy stock.
   *   - last_earned_days_ago: int|null — days since the most recent
   *     badge_request creation for this badge. NULL if none.
   *   - tool_count: int — distinct `item` nodes that reference this badge
   *     in field_member_badges or field_additional_badges. The "what does
   *     this unlock?" number members care about.
   */
  public function getStats(TermInterface $badge): array {
    $cid = 'appointment_facilitator:badge_popularity:' . $badge->id();
    $cached = $this->cache->get($cid);
    if ($cached && is_array($cached->data)) {
      return $cached->data;
    }

    $tid = (int) $badge->id();
    $holders = $this->countHoldersForBadge($tid);
    $active_makers = $this->countActiveMakers();
    $percent_of_active = $active_makers > 0
      ? (int) round(($holders / $active_makers) * 100)
      : NULL;

    [$rank, $total_ranked] = $this->rankForBadge($tid);
    $earned_last_30d = $this->countEarnedRecentlyForBadge($tid, 30);
    $last_earned_days_ago = $this->daysSinceLastEarnedForBadge($tid);
    $tool_count = $this->countToolsForBadge($tid);

    $stats = [
      'holders' => $holders,
      'active_makers' => $active_makers,
      'percent_of_active' => $percent_of_active,
      'rank' => $rank,
      'total_ranked_badges' => $total_ranked,
      'earned_last_30d' => $earned_last_30d,
      'last_earned_days_ago' => $last_earned_days_ago,
      'tool_count' => $tool_count,
    ];

    $this->cache->set(
      $cid,
      $stats,
      time() + self::CACHE_TTL,
      ['node_list:badge_request', 'node_list:item', 'user_list', 'taxonomy_term:' . $badge->id()]
    );

    return $stats;
  }

  /**
   * Counts badge_request nodes for this badge created in the last N days.
   *
   * Uses the request's creation time rather than active-state timestamp so
   * we capture the "is this badge being earned lately?" question with a
   * single-table scan. Inactive/pending requests count: they're still a
   * signal that members are pursuing the badge.
   */
  protected function countEarnedRecentlyForBadge(int $tid, int $days): int {
    $cutoff = time() - ($days * 86400);
    $query = $this->database->select('node__field_badge_requested', 'b');
    $query->innerJoin('node_field_data', 'n', 'n.nid = b.entity_id');
    $query->condition('n.type', 'badge_request');
    $query->condition('n.status', 1);
    $query->condition('b.field_badge_requested_target_id', $tid);
    $query->condition('n.created', $cutoff, '>=');
    $query->addExpression('COUNT(DISTINCT b.entity_id)', 'c');
    return (int) $query->execute()->fetchField();
  }

  /**
   * Returns days since the most recent badge_request for this badge.
   *
   * NULL when no request exists. Useful for "Last earned 3 days ago" copy.
   */
  protected function daysSinceLastEarnedForBadge(int $tid): ?int {
    $query = $this->database->select('node__field_badge_requested', 'b');
    $query->innerJoin('node_field_data', 'n', 'n.nid = b.entity_id');
    $query->condition('n.type', 'badge_request');
    $query->condition('n.status', 1);
    $query->condition('b.field_badge_requested_target_id', $tid);
    $query->addExpression('MAX(n.created)', 'most_recent');
    $ts = $query->execute()->fetchField();
    if (!$ts) {
      return NULL;
    }
    return max(0, (int) floor((time() - (int) $ts) / 86400));
  }

  /**
   * Counts item nodes that reference this badge as a required or extra badge.
   *
   * Deduplicates across `field_member_badges` and `field_additional_badges` —
   * a tool that lists the same badge in both fields should be counted once.
   * (The pre-fix version summed COUNT(DISTINCT) from each table separately,
   * which inflated the badge-page "tools unlocked" stat.)
   */
  protected function countToolsForBadge(int $tid): int {
    try {
      $a = $this->database->select('node__field_member_badges', 'b');
      $a->innerJoin('node_field_data', 'n', 'n.nid = b.entity_id');
      $a->condition('n.type', 'item');
      $a->condition('n.status', 1);
      $a->condition('b.field_member_badges_target_id', $tid);
      $a->addField('b', 'entity_id', 'nid');

      $b = $this->database->select('node__field_additional_badges', 'b');
      $b->innerJoin('node_field_data', 'n', 'n.nid = b.entity_id');
      $b->condition('n.type', 'item');
      $b->condition('n.status', 1);
      $b->condition('b.field_additional_badges_target_id', $tid);
      $b->addField('b', 'entity_id', 'nid');

      $a->union($b, 'UNION');
      $outer = $this->database->select($a, 'u');
      $outer->addExpression('COUNT(DISTINCT u.nid)', 'c');
      return (int) $outer->execute()->fetchField();
    }
    catch (\Throwable) {
      // One of the tables/columns may not exist on this site (legacy
      // migrations); return zero so the stats card just hides.
      return 0;
    }
  }

  /**
   * Counts distinct users holding the given badge in active state.
   */
  protected function countHoldersForBadge(int $tid): int {
    $query = $this->database->select('node__field_member_to_badge', 'm');
    $query->innerJoin('node__field_badge_requested', 'b', 'b.entity_id = m.entity_id');
    $query->innerJoin('node__field_badge_status', 's', 's.entity_id = m.entity_id');
    $query->innerJoin('node_field_data', 'n', 'n.nid = m.entity_id');
    $query->condition('n.type', 'badge_request');
    $query->condition('n.status', 1);
    $query->condition('b.field_badge_requested_target_id', $tid);
    $query->condition('s.field_badge_status_value', 'active');
    $query->addExpression('COUNT(DISTINCT m.field_member_to_badge_target_id)', 'c');
    return (int) $query->execute()->fetchField();
  }

  /**
   * Returns UIDs of active members who hold this badge in `active` state.
   *
   * Restricted to enabled user accounts so the listing matches the same
   * "active makers" denominator used by the percentage stat. Order is by
   * most recent badge_request first, then UID for stability.
   *
   * @return int[]
   */
  public function getActiveHolderUids(TermInterface $badge): array {
    $tid = (int) $badge->id();
    $query = $this->database->select('node__field_member_to_badge', 'm');
    $query->innerJoin('node__field_badge_requested', 'b', 'b.entity_id = m.entity_id');
    $query->innerJoin('node__field_badge_status', 's', 's.entity_id = m.entity_id');
    $query->innerJoin('node_field_data', 'n', 'n.nid = m.entity_id');
    $query->innerJoin('users_field_data', 'u', 'u.uid = m.field_member_to_badge_target_id');
    $query->condition('n.type', 'badge_request');
    $query->condition('n.status', 1);
    $query->condition('b.field_badge_requested_target_id', $tid);
    $query->condition('s.field_badge_status_value', 'active');
    $query->condition('u.status', 1);
    $query->addField('m', 'field_member_to_badge_target_id', 'uid');
    $query->addExpression('MAX(n.created)', 'last_earned');
    $query->groupBy('m.field_member_to_badge_target_id');
    $query->orderBy('last_earned', 'DESC');
    $query->orderBy('uid', 'ASC');
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Counts distinct active users with the `member` role.
   *
   * This is the denominator for "% of active makers who hold this badge".
   * We use the role rather than "has at least one active badge" because plenty
   * of members never request a badge, and using the badge population as the
   * denominator made the percentages inflate by a factor of ~10.
   */
  protected function countActiveMakers(): int {
    $query = $this->database->select('user__roles', 'r');
    $query->innerJoin('users_field_data', 'u', 'u.uid = r.entity_id');
    $query->condition('r.roles_target_id', 'member');
    $query->condition('u.status', 1);
    $query->addExpression('COUNT(DISTINCT u.uid)', 'c');
    return (int) $query->execute()->fetchField();
  }

  /**
   * Returns [rank, total] for the badge among all badges with ≥1 holder.
   *
   * Filters out badge IDs that don't appear on the public /badges listing:
   *   - dangling references (the badge term was deleted but badge_request
   *     rows still point at it)
   *   - unpublished terms
   *   - terms marked `field_badge_inactive=1`
   *
   * Without this filter the denominator drifted ahead of the listing
   * (e.g. "#127 of 145" vs the listing's "135 of 135 badges"). Matching the
   * listing view's filter set keeps the rank story coherent for members.
   */
  protected function rankForBadge(int $tid): array {
    $query = $this->database->select('node__field_badge_requested', 'b');
    $query->innerJoin('node__field_badge_status', 's', 's.entity_id = b.entity_id');
    $query->innerJoin('node_field_data', 'n', 'n.nid = b.entity_id');
    // INNER JOIN drops dangling tids (deleted badges) and unpublished terms.
    $query->innerJoin('taxonomy_term_field_data', 't', 't.tid = b.field_badge_requested_target_id AND t.vid = :vid AND t.status = 1', [':vid' => 'badges']);
    // LEFT JOIN inactive flag; treat missing rows as "active". Excludes only
    // the badges explicitly retired via field_badge_inactive=1.
    $query->leftJoin('taxonomy_term__field_badge_inactive', 'i', 'i.entity_id = t.tid');
    $orInactive = $query->orConditionGroup()
      ->isNull('i.field_badge_inactive_value')
      ->condition('i.field_badge_inactive_value', 1, '!=');
    $query->condition($orInactive);
    $query->condition('n.type', 'badge_request');
    $query->condition('n.status', 1);
    $query->condition('s.field_badge_status_value', 'active');
    $query->addField('b', 'field_badge_requested_target_id', 'tid');
    $query->addExpression('COUNT(DISTINCT b.entity_id)', 'holders');
    $query->groupBy('b.field_badge_requested_target_id');
    $query->orderBy('holders', 'DESC');

    $results = $query->execute()->fetchAll();
    $rank = 0;
    $total = count($results);
    foreach ($results as $i => $row) {
      if ((int) $row->tid === $tid) {
        $rank = $i + 1;
        break;
      }
    }
    return [$rank, $total];
  }

}
