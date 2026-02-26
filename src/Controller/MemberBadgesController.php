<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays a member's badges organised by area of interest.
 *
 * Data chain:
 *   badge_request node  →  field_badge_requested  →  badges term
 *   item node  →  field_member_badges / field_additional_badges  →  badges term
 *   item node  →  field_item_area_interest  →  area_of_interest term
 *
 * A badge belongs to an area of interest if any tool in that area requires it.
 * Badges with no associated tool are grouped under "Other".
 */
class MemberBadgesController extends ControllerBase {

  /**
   * Status display config: label, CSS modifier.
   */
  const STATUS_CONFIG = [
    'active'    => ['label' => 'Active',    'class' => 'badge-status--active'],
    'pending'   => ['label' => 'Pending',   'class' => 'badge-status--pending'],
    'suspended' => ['label' => 'Suspended', 'class' => 'badge-status--suspended'],
    'expired'   => ['label' => 'Expired',   'class' => 'badge-status--expired'],
    'rejected'  => ['label' => 'Rejected',  'class' => 'badge-status--rejected'],
  ];

  /**
   * Statuses shown in priority order within each group.
   */
  const STATUS_ORDER = ['active', 'pending', 'suspended', 'expired', 'rejected'];

  public function __construct(
    protected readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the /badges/my page.
   */
  public function build(): array {
    $uid = (int) $this->currentUser()->id();

    // Load all badge_request nodes for this user (excluding duplicates/spam).
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $nids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('status', 1)
      ->condition('field_member_to_badge.target_id', $uid)
      ->condition('field_badge_status', ['duplicate', 'Rejected'], 'NOT IN')
      ->sort('created', 'DESC')
      ->execute();

    if (!$nids) {
      return [
        '#cache' => ['max-age' => 0],
        '#markup' => '<p>' . $this->t("You don't have any badges yet. Visit <a href=\"/badges/earn\">Badges to Earn</a> to find badges that match your interests.") . '</p>',
      ];
    }

    $requests = $node_storage->loadMultiple($nids);

    // Collect badge TIDs and build a map: badge_tid => [badge_request, ...].
    $badge_tids = [];
    $requests_by_badge = [];
    foreach ($requests as $request) {
      if ($request->get('field_badge_requested')->isEmpty()) {
        continue;
      }
      $tid = (int) $request->get('field_badge_requested')->target_id;
      $badge_tids[$tid] = $tid;
      $requests_by_badge[$tid][] = $request;
    }

    if (!$badge_tids) {
      return ['#markup' => '<p>' . $this->t('No badge data found.') . '</p>'];
    }

    // Load all badge terms in bulk.
    $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $badge_terms = $term_storage->loadMultiple($badge_tids);

    // Derive area of interest for each badge via tool associations.
    $areas_by_badge = $this->deriveAreasForBadges(array_values($badge_tids));

    // Collect all area TIDs and load them.
    $all_area_tids = [];
    foreach ($areas_by_badge as $area_tids) {
      foreach ($area_tids as $atid) {
        $all_area_tids[$atid] = $atid;
      }
    }
    $area_terms = $all_area_tids
      ? $term_storage->loadMultiple($all_area_tids)
      : [];

    // Group badge TIDs by area: area_tid => [badge_tids].
    // Badges with multiple areas appear in each. Badges with no area → 0 (Other).
    $badges_by_area = [];
    foreach ($badge_tids as $tid) {
      $areas = $areas_by_badge[$tid] ?? [];
      if ($areas) {
        foreach ($areas as $atid) {
          $badges_by_area[$atid][$tid] = $tid;
        }
      }
      else {
        $badges_by_area[0][$tid] = $tid;
      }
    }

    // Sort areas: named areas alphabetically, "Other" last.
    uksort($badges_by_area, function ($a, $b) use ($area_terms) {
      if ($a === 0) return 1;
      if ($b === 0) return -1;
      $la = isset($area_terms[$a]) ? $area_terms[$a]->label() : '';
      $lb = isset($area_terms[$b]) ? $area_terms[$b]->label() : '';
      return strcasecmp($la, $lb);
    });

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['member-badges-page']],
      '#cache' => ['max-age' => 0],
    ];

    foreach ($badges_by_area as $area_tid => $tids_in_area) {
      $area_label = ($area_tid && isset($area_terms[$area_tid]))
        ? $area_terms[$area_tid]->label()
        : $this->t('Other');

      $section = [
        '#type' => 'container',
        '#attributes' => ['class' => ['badge-area-section']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#attributes' => ['class' => ['badge-area-heading']],
          '#value' => htmlspecialchars((string) $area_label, ENT_QUOTES, 'UTF-8'),
        ],
        'cards' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['badge-cards']],
        ],
      ];

      // Within each area, sort badge requests by status priority then date.
      $sorted = $this->sortBadgeRequests($tids_in_area, $requests_by_badge);

      foreach ($sorted as $entry) {
        ['tid' => $tid, 'request' => $request] = $entry;
        $term = $badge_terms[$tid] ?? NULL;
        if (!$term) {
          continue;
        }
        $section['cards']['badge_' . $request->id()] = $this->buildBadgeCard($term, $request);
      }

      $build['area_' . $area_tid] = $section;
    }

    return $build;
  }

  /**
   * Derives area of interest term IDs for each badge via tool associations.
   *
   * Runs two bulk entity queries (one per badge field on item nodes) to avoid
   * N+1 lookups.
   *
   * @param int[] $badge_tids
   *   Badge taxonomy term IDs to map.
   *
   * @return array
   *   Keyed by badge TID, value is array of area_of_interest TIDs.
   */
  protected function deriveAreasForBadges(array $badge_tids): array {
    if (!$badge_tids) {
      return [];
    }

    $area_map = [];
    $node_storage = $this->entityTypeManager()->getStorage('node');

    foreach (['field_member_badges', 'field_additional_badges'] as $badge_field) {
      $nids = $node_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'item')
        ->condition('status', 1)
        ->condition($badge_field . '.target_id', $badge_tids, 'IN')
        ->execute();

      if (!$nids) {
        continue;
      }

      foreach ($node_storage->loadMultiple($nids) as $item) {
        // Collect area TIDs from this tool.
        if ($item->get('field_item_area_interest')->isEmpty()) {
          continue;
        }
        $item_areas = array_column(
          $item->get('field_item_area_interest')->getValue(),
          'target_id'
        );

        // Map each referenced badge to those areas.
        if ($item->hasField($badge_field)) {
          foreach ($item->get($badge_field)->getValue() as $val) {
            $tid = (int) ($val['target_id'] ?? 0);
            if (!$tid || !in_array($tid, $badge_tids, TRUE)) {
              continue;
            }
            foreach ($item_areas as $atid) {
              $area_map[$tid][(int) $atid] = (int) $atid;
            }
          }
        }
      }
    }

    return array_map('array_values', $area_map);
  }

  /**
   * Sorts badge TIDs by status priority, then by request date.
   *
   * Returns flat list of ['tid' => int, 'request' => NodeInterface].
   */
  protected function sortBadgeRequests(array $tids, array $requests_by_badge): array {
    $entries = [];
    $status_order = array_flip(self::STATUS_ORDER);

    foreach ($tids as $tid) {
      foreach ($requests_by_badge[$tid] ?? [] as $request) {
        $status = $request->get('field_badge_status')->value ?? 'active';
        $entries[] = [
          'tid' => $tid,
          'request' => $request,
          'status_rank' => $status_order[$status] ?? 99,
          'created' => (int) $request->getCreatedTime(),
        ];
      }
    }

    usort($entries, function ($a, $b) {
      if ($a['status_rank'] !== $b['status_rank']) {
        return $a['status_rank'] <=> $b['status_rank'];
      }
      return $b['created'] <=> $a['created'];
    });

    return $entries;
  }

  /**
   * Renders a single badge card.
   */
  protected function buildBadgeCard($term, $request): array {
    $status = $request->get('field_badge_status')->value ?? 'active';
    $config = self::STATUS_CONFIG[$status] ?? ['label' => ucfirst($status), 'class' => ''];
    $tid = (int) $term->id();

    $badge_url = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid]);

    $card = [
      '#type' => 'container',
      '#attributes' => ['class' => ['badge-card', 'badge-card--' . $status]],
    ];

    // Badge image.
    if (!$term->get('field_member_badge_image')->isEmpty()) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $term->get('field_member_badge_image')->entity;
      if ($file) {
        $card['image'] = [
          '#theme' => 'image_style',
          '#style_name' => 'thumbnail',
          '#uri' => $file->getFileUri(),
          '#alt' => $term->label(),
          '#attributes' => ['class' => ['badge-card__image']],
        ];
      }
    }

    // Badge name linked to term page.
    $card['name'] = [
      '#type' => 'link',
      '#title' => $term->label(),
      '#url' => $badge_url,
      '#attributes' => ['class' => ['badge-card__name']],
    ];

    // Status chip.
    $card['status'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $config['label'],
      '#attributes' => ['class' => ['badge-status', $config['class']]],
    ];

    // Date line.
    $date = $this->dateFormatter->format($request->getCreatedTime(), 'custom', 'M j, Y');
    $date_label = $status === 'active'
      ? $this->t('Earned @date', ['@date' => $date])
      : $this->t('Since @date', ['@date' => $date]);
    $card['date'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $date_label,
      '#attributes' => ['class' => ['badge-card__date']],
    ];

    // If pending: link to /badges/complete to take action.
    if ($status === 'pending') {
      $card['action'] = [
        '#type' => 'link',
        '#title' => $this->t('Complete this badge →'),
        '#url' => Url::fromRoute('appointment_facilitator.badge_next_steps'),
        '#attributes' => ['class' => ['badge-card__action']],
      ];
    }

    return $card;
  }

}
