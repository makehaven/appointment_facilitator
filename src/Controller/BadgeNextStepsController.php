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

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['badge-next-steps']],
      '#cache' => ['max-age' => 0],
    ];

    foreach ($terms as $tid => $term) {
      $build['badge_' . $tid] = $this->buildBadgeSection($term, $uid);
    }

    return $build;
  }

  /**
   * Builds the card for a single pending badge.
   */
  protected function buildBadgeSection(TermInterface $term, int $uid): array {
    $tid = (int) $term->id();
    $badge_url = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid]);

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
      'options' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['badge-options']],
      ],
    ];

    $has_options = FALSE;

    // --- Priority 1: join an existing open session ---
    $open_appointments = $this->findOpenAppointments($tid);
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
      foreach ($facilitators as $user) {
        $items[] = $this->buildFacilitatorItem($user);
      }
      $section['options']['book'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['badge-option', 'badge-option--book']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h4',
          '#value' => $this->t('Book a one-on-one session'),
        ],
        'items' => $items,
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
   * Only looks within the current scheduling window (7 days).
   */
  protected function findOpenAppointments(int $badge_tid): array {
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
      $attendee_count = count($node->get('field_appointment_attendees')->getValue());
      if ($attendee_count < $capacity) {
        $open[] = $node;
      }
    }
    return $open;
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
   * Returns facilitators from field_badge_issuer_on_request on the badge term.
   */
  protected function getFacilitatorsForBadge(TermInterface $term): array {
    if (!$term->hasField('field_badge_issuer_on_request') || $term->get('field_badge_issuer_on_request')->isEmpty()) {
      return [];
    }
    return $term->get('field_badge_issuer_on_request')->referencedEntities();
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
   * Renders a row for a facilitator with a booking link.
   *
   * The booking link passes ?host={uid} so the appointment form can
   * pre-select the facilitator via hook_form_node_form_alter().
   */
  protected function buildFacilitatorItem($user): array {
    $schedule_url = Url::fromRoute('node.add', ['node_type' => 'appointment'], [
      'query' => ['host' => $user->id()],
    ]);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['facilitator-item']],
      'name' => [
        '#markup' => '<span class="facilitator-name">' . htmlspecialchars($user->getDisplayName(), ENT_QUOTES, 'UTF-8') . '</span> ',
      ],
      'link' => [
        '#type' => 'link',
        '#title' => $this->t('Schedule a session'),
        '#url' => $schedule_url,
        '#attributes' => ['class' => ['button', 'button--small']],
      ],
    ];
  }

}
