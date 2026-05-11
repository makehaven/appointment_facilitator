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
   *   - active_makers: int — distinct users with at least one active badge
   *     anywhere in the system (the denominator we use for "what % of
   *     people who badge for anything have this one").
   *   - percent_of_active: int|null — null when no active makers exist
   *   - rank: int — 1-based position among badges sorted by holder count
   *   - total_ranked_badges: int — how many badges have at least one holder
   */
  public function getStats(TermInterface $badge): array {
    $cid = 'appointment_facilitator:badge_popularity:' . $badge->id();
    $cached = $this->cache->get($cid);
    if ($cached && is_array($cached->data)) {
      return $cached->data;
    }

    $holders = $this->countHoldersForBadge((int) $badge->id());
    $active_makers = $this->countActiveMakers();
    $percent_of_active = $active_makers > 0
      ? (int) round(($holders / $active_makers) * 100)
      : NULL;

    [$rank, $total_ranked] = $this->rankForBadge((int) $badge->id());

    $stats = [
      'holders' => $holders,
      'active_makers' => $active_makers,
      'percent_of_active' => $percent_of_active,
      'rank' => $rank,
      'total_ranked_badges' => $total_ranked,
    ];

    $this->cache->set(
      $cid,
      $stats,
      time() + self::CACHE_TTL,
      ['node_list:badge_request', 'user_list', 'taxonomy_term:' . $badge->id()]
    );

    return $stats;
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
   */
  protected function rankForBadge(int $tid): array {
    $query = $this->database->select('node__field_badge_requested', 'b');
    $query->innerJoin('node__field_badge_status', 's', 's.entity_id = b.entity_id');
    $query->innerJoin('node_field_data', 'n', 'n.nid = b.entity_id');
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
