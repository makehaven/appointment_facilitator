<?php

namespace Drupal\appointment_facilitator\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Derives area-of-interest term IDs for badges via the items that require them.
 *
 * Badges have no direct field_badge_area_of_interest. The mapping comes from
 * item nodes (item.field_member_badges + item.field_additional_badges link
 * to badges; item.field_item_area_interest links to area terms). This service
 * caches the full badge→areas map for an hour so directory pages don't
 * re-query on every render.
 */
class BadgeAreaMapper {

  protected const CACHE_TTL = 3600;
  protected const CACHE_CID = 'appointment_facilitator:badge_area_map';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * Returns map: badge_tid => [area_tid, area_tid, …].
   */
  public function getAreaMap(): array {
    $cached = $this->cache->get(self::CACHE_CID);
    if ($cached && is_array($cached->data)) {
      return $cached->data;
    }

    $map = [];
    $storage = $this->entityTypeManager->getStorage('node');

    foreach (['field_member_badges', 'field_additional_badges'] as $badge_field) {
      $nids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'item')
        ->condition('status', 1)
        ->exists($badge_field)
        ->execute();

      if (!$nids) {
        continue;
      }

      foreach ($storage->loadMultiple($nids) as $item) {
        if (!$item->hasField('field_item_area_interest') || $item->get('field_item_area_interest')->isEmpty()) {
          continue;
        }
        $area_tids = array_map(
          static fn ($v) => (int) ($v['target_id'] ?? 0),
          $item->get('field_item_area_interest')->getValue()
        );
        $area_tids = array_values(array_filter($area_tids));
        if (!$area_tids) {
          continue;
        }

        if (!$item->hasField($badge_field)) {
          continue;
        }
        foreach ($item->get($badge_field)->getValue() as $val) {
          $tid = (int) ($val['target_id'] ?? 0);
          if ($tid <= 0) {
            continue;
          }
          foreach ($area_tids as $atid) {
            $map[$tid][$atid] = $atid;
          }
        }
      }
    }

    // Normalise inner lists to plain integer arrays.
    foreach ($map as $tid => $atids) {
      $map[$tid] = array_values($atids);
    }

    $this->cache->set(
      self::CACHE_CID,
      $map,
      time() + self::CACHE_TTL,
      ['node_list:item', 'taxonomy_term_list:badges', 'taxonomy_term_list:area_of_interest']
    );

    return $map;
  }

}
