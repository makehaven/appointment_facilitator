<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays badge completion options for the current member.
 *
 * For each badge that is pending on the member's profile, the page shows —
 * in priority order — ways to complete it:
 *
 *  1. Join an existing upcoming appointment that includes this badge and still
 *     has open capacity (most efficient: the facilitator is already scheduled).
 *  2. Attend an upcoming CiviCRM event tagged with this badge.
 *  3. Book a new one-on-one session with a listed facilitator.
 *  4. Request a private session (fallback link to /private-class-inquiry).
 */
class BadgeNextStepsController extends ControllerBase {

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
   * Builds the badge next-steps page.
   */
  public function build(): array {
    $uid = (int) $this->currentUser()->id();
    $pending_tids = _appointment_facilitator_load_pending_badge_term_ids($uid);

    if (empty($pending_tids)) {
      return [
        '#markup' => '<p>' . $this->t('You have no badges currently pending. Once a badge is marked as pending on your profile, your options to complete it will appear here.') . '</p>',
      ];
    }

    $terms = $this->entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadMultiple($pending_tids);

    // Load badge_request nodes to find when each badge became pending.
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $request_nids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('status', 1)
      ->condition('field_member_to_badge.target_id', $uid)
      ->condition('field_badge_status', 'pending')
      ->condition('field_badge_requested.target_id', array_values($pending_tids), 'IN')
      ->sort('created', 'ASC')
      ->execute();

    // Build tid → created timestamp map (earliest request per badge).
    $pending_since = [];
    if ($request_nids) {
      foreach ($node_storage->loadMultiple($request_nids) as $request) {
        $tid = (int) $request->get('field_badge_requested')->target_id;
        if (!isset($pending_since[$tid])) {
          $pending_since[$tid] = (int) $request->getCreatedTime();
        }
      }
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['badge-next-steps']],
      '#cache' => ['max-age' => 0],
    ];

    foreach ($terms as $tid => $term) {
      $build['badge_' . $tid] = $this->buildBadgeSection($term, $uid, $pending_since[$tid] ?? 0);
    }

    return $build;
  }

  /**
   * Builds the card for a single pending badge.
   */
  protected function buildBadgeSection(TermInterface $term, int $uid, int $pending_since = 0): array {
    $tid = (int) $term->id();
    $badge_url = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid]);

    // Build the "pending for X days" notice.
    $now = \Drupal::time()->getRequestTime();
    $days = $pending_since > 0 ? (int) floor(($now - $pending_since) / 86400) : 0;
    if ($days === 0) {
      $age_text = $this->t('marked pending today');
    }
    elseif ($days === 1) {
      $age_text = $this->t('pending for 1 day');
    }
    else {
      $age_text = $this->t('pending for @days days', ['@days' => $days]);
    }
    $notice_text = $this->t(
      'This badge has been @age — complete your checkout with a facilitator to activate it.',
      ['@age' => $age_text]
    );

    $section = [
      '#type' => 'container',
      '#attributes' => ['class' => ['badge-next-step-card']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#attributes' => ['class' => ['badge-card-title']],
        'link' => [
          '#type' => 'link',
          '#title' => $term->label(),
          '#url' => $badge_url,
        ],
      ],
      'notice' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $notice_text,
        '#attributes' => ['class' => ['badge-pending-notice']],
      ],
      'options' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['badge-options']],
      ],
    ];

    // --- Already scheduled? Show when and skip all booking options ---
    $existing = $this->findExistingAppointmentForBadge($tid, $uid);
    if ($existing) {
      $ts = (int) $existing->get('field_appointment_timerange')->value;
      $date_str = $this->dateFormatter->format($ts, 'custom', 'l, F j \a\t g:ia');
      $node_url = $existing->toUrl();
      $section['options']['scheduled'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['badge-option', 'badge-option--scheduled']],
        'message' => [
          '#markup' => '<p class="badge-scheduled-notice">'
            . $this->t('You are already scheduled for a session on @date.', ['@date' => $date_str])
            . ' </p>',
        ],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('View appointment'),
          '#url' => $node_url,
          '#attributes' => ['class' => ['button', 'button--small']],
        ],
      ];
      return $section;
    }

    $has_options = FALSE;

    // --- Priority 1: join an existing open session ---
    $open_appointments = $this->findOpenAppointments($tid, $uid);
    if ($open_appointments) {
      $has_options = TRUE;
      $rows = [];
      foreach ($open_appointments as $node) {
        $rows[] = $this->buildAppointmentRow($node, $uid);
      }
      $section['options']['join'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['badge-option', 'badge-option--join']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h4',
          '#value' => $this->t('Join an upcoming session'),
        ],
        'note' => ['#markup' => '<p class="badge-option-note">' . $this->t('A facilitator is already scheduled — most efficient option.') . '</p>'],
        'slots' => $rows,
      ];
    }

    // --- Priority 2: upcoming events ---
    $events = $this->findUpcomingEvents($tid);
    if ($events) {
      $has_options = TRUE;
      $items = [];
      foreach ($events as $event) {
        $item = $this->buildEventItem($event);
        if ($item) {
          $items[] = $item;
        }
      }
      if ($items) {
        $section['options']['events'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['badge-option', 'badge-option--event']],
          'heading' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('Attend an upcoming class'),
          ],
          'items' => $items,
        ];
      }
    }

    // --- Priority 3: book a new facilitator session ---
    $facilitators = $this->getFacilitatorsForBadge($term);
    if ($facilitators) {
      $has_options = TRUE;
      $items = [];
      foreach ($facilitators as $entry) {
        $items[] = $this->buildFacilitatorItem(
          $entry['user'],
          $tid,
          $entry['availability'],
          (int) ($entry['soonest_ts'] ?? 0),
        );
      }
      $grid = [
        '#type' => 'container',
        '#attributes' => ['class' => ['facilitator-cards-grid']],
      ];
      foreach ($items as $idx => $item) {
        $grid['f_' . $idx] = $item;
      }
      $section['options']['book'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['badge-option', 'badge-option--book']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h4',
          '#value' => $this->t('Schedule a session with a facilitator'),
        ],
        'grid' => $grid,
      ];
    }

    // --- No current options ---
    if (!$has_options) {
      $section['options']['none'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['badge-option', 'badge-option--none']],
        '#markup' => '<p>' . $this->t('No sessions or events are currently scheduled for this badge.') . '</p>',
      ];
    }

    // --- Always: private session fallback ---
    $section['options']['private'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['badge-option', 'badge-option--private']],
      'link' => [
        '#type' => 'link',
        '#title' => $this->t('Request a private session'),
        '#url' => Url::fromUri('internal:/private-class-inquiry'),
        '#attributes' => ['class' => ['badge-private-link']],
      ],
    ];

    return $section;
  }

  /**
   * Finds upcoming appointment nodes for a badge that have open capacity.
   *
   * Excludes sessions the current user has already joined — there is no point
   * offering to join something they are already part of. Only looks within the
   * current scheduling window (7 days).
   */
  protected function findOpenAppointments(int $badge_tid, int $uid): array {
    $now = \Drupal::time()->getRequestTime();
    $week_ahead = $now + (7 * 24 * 60 * 60);

    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('status', 1)
      ->condition('field_appointment_badges.target_id', $badge_tid)
      ->condition('field_appointment_timerange.value', $now, '>=')
      ->condition('field_appointment_timerange.value', $week_ahead, '<=')
      ->condition('field_appointment_status', 'canceled', '!=')
      ->sort('field_appointment_timerange.value', 'ASC')
      ->range(0, 10);

    $nids = $query->execute();
    if (!$nids) {
      return [];
    }

    $open = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $capacity = appointment_facilitator_effective_capacity($node);
      // Only surface group sessions (capacity > 1). Capacity-1 appointments
      // are 1-on-1s that must be freshly booked; the JoinAppointmentForm also
      // returns empty for them, so linking there would show a blank page.
      if ($capacity <= 1) {
        continue;
      }
      $attendee_values = $node->get('field_appointment_attendees')->getValue();
      // Skip sessions the user already joined — no point listing them.
      $already_joined = FALSE;
      foreach ($attendee_values as $item) {
        if ((int) ($item['target_id'] ?? 0) === $uid) {
          $already_joined = TRUE;
          break;
        }
      }
      if ($already_joined) {
        continue;
      }
      if (count($attendee_values) < $capacity) {
        $open[] = $node;
      }
    }
    return $open;
  }

  /**
   * Returns the earliest upcoming appointment for a badge that this user is
   * already attending (as host or attendee), if one exists.
   */
  protected function findExistingAppointmentForBadge(int $badge_tid, int $uid): ?NodeInterface {
    $now = \Drupal::time()->getRequestTime();
    $storage = $this->entityTypeManager()->getStorage('node');

    // Check appointments where the user is the host.
    foreach (['field_appointment_host', 'field_appointment_attendees'] as $field) {
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'appointment')
        ->condition('status', 1)
        ->condition('field_appointment_badges.target_id', $badge_tid)
        ->condition('field_appointment_timerange.value', $now, '>=')
        ->condition('field_appointment_status', 'canceled', '!=')
        ->condition($field . '.target_id', $uid)
        ->sort('field_appointment_timerange.value', 'ASC')
        ->range(0, 1);

      $nids = $query->execute();
      if ($nids) {
        $nodes = $storage->loadMultiple($nids);
        return reset($nodes) ?: NULL;
      }
    }

    return NULL;
  }

  /**
   * Finds upcoming CiviCRM events tagged with a badge.
   */
  protected function findUpcomingEvents(int $badge_tid): array {
    if (!$this->moduleHandler()->moduleExists('civicrm_entity')) {
      return [];
    }

    try {
      $query = $this->entityTypeManager()->getStorage('civicrm_event')->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_civi_event_badges.target_id', $badge_tid)
        ->condition('start_date', date('Y-m-d H:i:s'), '>=')
        ->condition('is_active', 1)
        ->sort('start_date', 'ASC')
        ->range(0, 5);

      $ids = $query->execute();
      if (!$ids) {
        return [];
      }
      return $this->entityTypeManager()->getStorage('civicrm_event')->loadMultiple($ids);
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Returns facilitators with upcoming availability for a badge.
   *
   * Resolves badge issuers (on-request first, then all issuers), loads each
   * facilitator's coordinator profile, and keeps only those who have at least
   * one field_coordinator_hours slot within the next week (matching the
   * /facilitator/schedules view: now-2h to now+7d). Excludes facilitators
   * whose field_coordinator_hours_display is set to 'hide'.
   *
   * Returns an array of ['user', 'availability'] entries.
   */
  protected function getFacilitatorsForBadge(TermInterface $term): array {
    $users = [];
    foreach (['field_badge_issuer_on_request', 'field_badge_issuer'] as $field) {
      if ($term->hasField($field) && !$term->get($field)->isEmpty()) {
        $users = $term->get($field)->referencedEntities();
        break;
      }
    }
    if (!$users) {
      return [];
    }

    $bundle = \Drupal::config('appointment_facilitator.settings')
      ->get('facilitator_profile_bundle') ?: 'coordinator';
    $profile_storage = $this->entityTypeManager()->getStorage('profile');

    $now = \Drupal::time()->getRequestTime();
    // Match /facilitator/schedules view window: 2 hours ago → 1 week ahead.
    $window_start = $now - (2 * 60 * 60);
    $window_end   = $now + (7 * 24 * 60 * 60);

    $available = [];
    foreach ($users as $user) {
      $profiles = $profile_storage->loadByUser($user, $bundle);
      $profile = is_array($profiles) ? reset($profiles) : $profiles;
      if (!$profile) {
        continue;
      }

      // Skip if explicitly hidden from schedules.
      if ($profile->hasField('field_coordinator_hours_display')
        && !$profile->get('field_coordinator_hours_display')->isEmpty()
        && $profile->get('field_coordinator_hours_display')->value === 'hide') {
        continue;
      }

      if (!$profile->hasField('field_coordinator_hours') || $profile->get('field_coordinator_hours')->isEmpty()) {
        continue;
      }

      // Find the soonest slot within the availability window.
      $soonest_ts = NULL;
      foreach ($profile->get('field_coordinator_hours') as $item) {
        $slot_ts = (int) ($item->value ?? 0);
        if ($slot_ts >= $window_start && $slot_ts <= $window_end) {
          if ($soonest_ts === NULL || $slot_ts < $soonest_ts) {
            $soonest_ts = $slot_ts;
          }
        }
      }
      if ($soonest_ts === NULL) {
        continue;
      }

      $available[] = [
        'user' => $user,
        'availability' => $this->formatCoordinatorHours($profile),
        'soonest_ts' => $soonest_ts,
      ];
    }

    // Sort by soonest upcoming slot so the earliest available appears first.
    usort($available, fn($a, $b) => $a['soonest_ts'] <=> $b['soonest_ts']);

    return $available;
  }

  /**
   * Formats coordinator hours into a human-readable availability string.
   *
   * Reads the stored field_coordinator_hours items, sorts by start timestamp
   * (soonest first), deduplicates by day-of-week (keeping the earliest slot
   * per day), and returns a compact string such as "Mon 6–8pm · Wed 6–8pm".
   */
  protected function formatCoordinatorHours($profile): string {
    $raw = [];
    foreach ($profile->get('field_coordinator_hours') as $item) {
      $start_ts = (int) ($item->value ?? 0);
      if ($start_ts <= 0) {
        continue;
      }
      $raw[] = [
        'ts'  => $start_ts,
        'end' => (int) ($item->end_value ?? 0),
      ];
    }

    // Sort soonest first so the first entry per day is always the earliest.
    usort($raw, fn($a, $b) => $a['ts'] <=> $b['ts']);

    $slots = [];
    foreach ($raw as $entry) {
      $start_ts = $entry['ts'];
      $end_ts   = $entry['end'];
      $day      = $this->dateFormatter->format($start_ts, 'custom', 'D');
      if (isset($slots[$day])) {
        continue; // keep only the earliest slot per day
      }
      $start = $this->dateFormatter->format($start_ts, 'custom', 'g:ia');
      $end   = $end_ts > $start_ts
        ? $this->dateFormatter->format($end_ts, 'custom', 'g:ia')
        : '';
      $slots[$day] = $end ? $day . ' ' . $start . '–' . $end : $day . ' ' . $start;
    }

    return implode(' · ', array_values($slots));
  }

  /**
   * Renders a row for an upcoming appointment with open capacity.
   */
  protected function buildAppointmentRow(NodeInterface $node, int $current_uid): array {
    $ts = (int) $node->get('field_appointment_timerange')->value;
    $date = $this->dateFormatter->format($ts, 'custom', 'D, M j');
    $time = $this->dateFormatter->format($ts, 'custom', 'g:ia');

    $capacity = appointment_facilitator_effective_capacity($node);
    $attendee_count = count($node->get('field_appointment_attendees')->getValue());
    $open_spots = $capacity - $attendee_count;

    $host_name = '';
    if (!$node->get('field_appointment_host')->isEmpty()) {
      $host = $node->get('field_appointment_host')->entity;
      if ($host) {
        $host_name = $host->getDisplayName();
      }
    }

    $already_joined = FALSE;
    foreach ($node->get('field_appointment_attendees')->getValue() as $item) {
      if ((int) ($item['target_id'] ?? 0) === $current_uid) {
        $already_joined = TRUE;
        break;
      }
    }

    $spots_label = $this->formatPlural($open_spots, '1 spot left', '@count spots left');
    $info = $date . ' at ' . $time
      . ($host_name ? ' with ' . htmlspecialchars($host_name, ENT_QUOTES, 'UTF-8') : '')
      . ' &mdash; ' . $spots_label;

    $row = [
      '#type' => 'container',
      '#attributes' => ['class' => ['appointment-slot-row']],
      'info' => ['#markup' => '<span class="slot-info">' . $info . '</span> '],
    ];

    if ($already_joined) {
      $row['action'] = ['#markup' => '<em>' . $this->t('Already joined') . '</em>'];
    }
    else {
      $row['action'] = [
        '#type' => 'link',
        '#title' => $this->t('Join this session'),
        '#url' => Url::fromRoute('appointment_facilitator.join', ['node' => $node->id()]),
        '#attributes' => ['class' => ['button', 'button--primary', 'button--small']],
      ];
    }

    return $row;
  }

  /**
   * Renders a row for a CiviCRM event.
   */
  protected function buildEventItem($event): ?array {
    try {
      $start = $event->get('start_date')->value ?? NULL;
      $date_str = $start
        ? $this->dateFormatter->format(strtotime($start), 'custom', 'D, M j g:ia')
        : '';

      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['event-item']],
        'content' => [
          '#markup' => '<span class="event-date">' . htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') . '</span> ',
        ],
        'link' => [
          '#type' => 'link',
          '#title' => $event->label(),
          '#url' => $event->toUrl(),
        ],
      ];
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Renders a card for a facilitator with availability and a booking link.
   */
  protected function buildFacilitatorItem($user, int $badge_tid, string $availability = '', int $soonest_ts = 0): array {
    $query = [
      // Keep both query keys for compatibility with existing defaults.
      'host-uid' => $user->id(),
      'host' => $user->id(),
      'badge' => $badge_tid,
      'purpose' => 'checkout',
      'from-badges-complete' => 1,
    ];

    if ($soonest_ts > 0) {
      $timezone_name = \Drupal::config('system.date')->get('timezone.default') ?: date_default_timezone_get();
      try {
        $slot = (new \DateTimeImmutable('@' . $soonest_ts))->setTimezone(new \DateTimeZone($timezone_name));
        $query['date'] = $slot->format('Y-m-d');
        // Mirrors existing scheduler links that send a start_time hint.
        $query['start_time'] = (string) $soonest_ts;
      }
      catch (\Exception $e) {
        // Fall back to host + badge preselection only.
      }
    }

    $schedule_url = Url::fromRoute('node.add', ['node_type' => 'appointment'], [
      'query' => $query,
    ]);

    $name = htmlspecialchars($user->getDisplayName(), ENT_QUOTES, 'UTF-8');
    $avail_html = $availability
      ? '<span class="facilitator-hours">' . htmlspecialchars($availability, ENT_QUOTES, 'UTF-8') . '</span>'
      : '';

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['facilitator-card']],
      'info' => [
        '#markup' => '<span class="facilitator-name">' . $name . '</span>' . $avail_html,
      ],
      'link' => [
        '#type' => 'link',
        '#title' => $this->t('Schedule'),
        '#url' => $schedule_url,
        '#attributes' => ['class' => ['facilitator-schedule-btn']],
      ],
    ];
  }

}
