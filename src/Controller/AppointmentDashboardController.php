<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Member-facing appointment dashboard at /appointments.
 *
 * Shows the current user's hosting and attending appointments, color-coded
 * by role, with an Upcoming/Past JS toggle. No inline JS in view config.
 */
class AppointmentDashboardController extends ControllerBase {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManagerService,
    protected readonly DateFormatterInterface $dateFormatterService,
    protected readonly EntityFieldManagerInterface $entityFieldManagerService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * Renders the appointments dashboard page.
   */
  public function dashboard(): array {
    $account = $this->currentUser();
    $uid     = (int) $account->id();
    $now     = \Drupal::time()->getRequestTime();

    $upcoming = $this->loadMyAppointments($uid, $now, TRUE);
    $past     = $this->loadMyAppointments($uid, $now, FALSE);

    // Admins/managers with no personal appointments see everyone's upcoming
    // so they can verify the page works and review the schedule.
    $is_admin = $account->hasPermission('view appointment facilitator reports')
      || in_array('administrator', $account->getRoles(), TRUE)
      || in_array('manager', $account->getRoles(), TRUE);

    if ($is_admin && !$upcoming) {
      $upcoming = $this->loadAllUpcoming($now);
    }

    [$today, $later] = $this->splitToday($upcoming, $now);

    return [
      '#theme'    => 'appointment_dashboard',
      '#today'    => $today,
      '#upcoming' => $later,
      '#past'     => $past,
      '#context'  => 'mine',
      '#attached' => ['library' => ['appointment_facilitator/appointments']],
      '#cache'    => [
        'contexts' => ['user', 'user.permissions', 'user.roles'],
        'tags'     => ['node_list:appointment'],
        'max-age'  => 0,
      ],
    ];
  }

  /**
   * Renders all appointments (everyone, upcoming + past) at /appointments/all.
   */
  public function allAppointments(): array {
    $now      = \Drupal::time()->getRequestTime();
    $upcoming = $this->loadAllByTime($now, TRUE);
    $past     = $this->loadAllByTime($now, FALSE);

    [$today, $later] = $this->splitToday($upcoming, $now);

    return [
      '#theme'    => 'appointment_dashboard',
      '#today'    => $today,
      '#upcoming' => $later,
      '#past'     => $past,
      '#context'  => 'all',
      '#attached' => ['library' => ['appointment_facilitator/appointments']],
      '#cache'    => [
        'contexts' => ['user.permissions'],
        'tags'     => ['node_list:appointment'],
        'max-age'  => 0,
      ],
    ];
  }

  /**
   * Loads all appointments site-wide for a given time window.
   */
  protected function loadAllByTime(int $now, bool $upcoming): array {
    $storage  = $this->entityTypeManagerService->getStorage('node');
    $op       = $upcoming ? '>=' : '<';
    $sort_dir = $upcoming ? 'ASC' : 'DESC';

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('status', 1)
      ->condition('field_appointment_timerange.value', $now, $op)
      ->sort('field_appointment_timerange.value', $sort_dir)
      ->range(0, 200);

    if ($this->fieldExists('field_appointment_status')) {
      $orGroup = $query->orConditionGroup()
        ->condition('field_appointment_status.value', 'canceled', '<>')
        ->notExists('field_appointment_status');
      $query->condition($orGroup);
    }

    $nids = $query->execute();
    if (!$nids) {
      return [];
    }

    $is_past = !$upcoming;
    $cards   = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $cards[] = $this->buildCard($node, 'all', $is_past);
    }
    return $cards;
  }

  /**
   * Loads all upcoming appointments (admin fallback when user has none).
   */
  protected function loadAllUpcoming(int $now): array {
    $storage = $this->entityTypeManagerService->getStorage('node');
    $query   = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('status', 1)
      ->condition('field_appointment_timerange.value', $now, '>=')
      ->sort('field_appointment_timerange.value', 'ASC')
      ->range(0, 50);

    if ($this->fieldExists('field_appointment_status')) {
      $orGroup = $query->orConditionGroup()
        ->condition('field_appointment_status.value', 'canceled', '<>')
        ->notExists('field_appointment_status');
      $query->condition($orGroup);
    }

    $nids = $query->execute();
    if (!$nids) {
      return [];
    }

    $cards = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $cards[] = $this->buildCard($node, 'all', FALSE);
    }
    return $cards;
  }

  /**
   * Loads the current user's appointments (hosting + attending), merged.
   *
   * @param int  $uid      Current user ID.
   * @param int  $now      Request timestamp.
   * @param bool $upcoming TRUE for upcoming, FALSE for past.
   *
   * @return array  Array of #theme => appointment_card render arrays.
   */
  protected function loadMyAppointments(int $uid, int $now, bool $upcoming): array {
    $storage  = $this->entityTypeManagerService->getStorage('node');
    $op       = $upcoming ? '>=' : '<';
    $sort_dir = $upcoming ? 'ASC' : 'DESC';

    $base = static function () use ($storage, $uid, $now, $op, $sort_dir): object {
      return $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'appointment')
        ->condition('status', 1)
        ->condition('field_appointment_timerange.value', $now, $op)
        ->sort('field_appointment_timerange.value', $sort_dir);
    };

    // Hosting: field_appointment_host = current user.
    $hosting_q = $base();
    $hosting_q->condition('field_appointment_host.target_id', $uid);
    if ($this->fieldExists('field_appointment_status')) {
      $or = $hosting_q->orConditionGroup()
        ->condition('field_appointment_status.value', 'canceled', '<>')
        ->notExists('field_appointment_status');
      $hosting_q->condition($or);
    }
    // Cast to int — entity query returns string IDs; strict in_array requires type match.
    $hosting_nids = array_map('intval', array_values($hosting_q->execute()));

    // Attending: node author = current user AND not the facilitator.
    // Excludes appointments created by admins/coordinators on behalf of others.
    $attending_q = $base();
    $attending_q->condition('uid', $uid);
    $not_self_host = $attending_q->orConditionGroup()
      ->condition('field_appointment_host.target_id', $uid, '<>')
      ->notExists('field_appointment_host');
    $attending_q->condition($not_self_host);
    if ($this->fieldExists('field_appointment_status')) {
      $or2 = $attending_q->orConditionGroup()
        ->condition('field_appointment_status.value', 'canceled', '<>')
        ->notExists('field_appointment_status');
      $attending_q->condition($or2);
    }
    // Cast to int — entity query returns string IDs; strict in_array requires type match.
    $attending_nids = array_map('intval', array_values($attending_q->execute()));

    // Joined: user is in field_appointment_attendees (joined via Join form),
    // not the node author, and not the host.
    $joined_nids = [];
    if ($this->fieldExists('field_appointment_attendees')) {
      $joined_q = $base();
      $joined_q->condition('field_appointment_attendees.target_id', $uid);
      $joined_q->condition('uid', $uid, '<>');
      $joined_q->condition('field_appointment_host.target_id', $uid, '<>');
      if ($this->fieldExists('field_appointment_status')) {
        $or3 = $joined_q->orConditionGroup()
          ->condition('field_appointment_status.value', 'canceled', '<>')
          ->notExists('field_appointment_status');
        $joined_q->condition($or3);
      }
      $joined_nids = array_map('intval', array_values($joined_q->execute()));
    }

    // Merge, deduplicate, load. Hosting takes priority over attending/joined.
    $all_nids = array_unique(array_merge($hosting_nids, $attending_nids, $joined_nids));
    if (!$all_nids) {
      return [];
    }

    $nodes = $storage->loadMultiple($all_nids);
    $cards = [];

    foreach ($nodes as $node) {
      // All arrays are cast to int; strict comparison is safe.
      $nid     = (int) $node->id();
      $is_host = in_array($nid, $hosting_nids, TRUE);
      $role    = $is_host
        ? ($upcoming ? 'hosting' : 'hosted')
        : ($upcoming ? 'attending' : 'attended');
      $cards[] = $this->buildCard($node, $role, !$upcoming);
    }

    // Sort merged list by start timestamp.
    usort($cards, static function (array $a, array $b) use ($upcoming): int {
      return $upcoming
        ? $a['#sort_value'] <=> $b['#sort_value']
        : $b['#sort_value'] <=> $a['#sort_value'];
    });

    return $cards;
  }

  /**
   * Builds a single appointment card render array.
   */
  protected function buildCard(NodeInterface $node, string $role, bool $is_past): array {
    [$date_str, $time_str, $sort_val] = $this->extractDateTime($node);

    $host_name       = '';
    $host_entity_uid = 0;
    $host_area       = '';

    if ($node->hasField('field_appointment_host') && !$node->get('field_appointment_host')->isEmpty()) {
      $host_entity     = $node->get('field_appointment_host')->entity;
      $host_name       = $host_entity ? $host_entity->getDisplayName() : '';
      $host_entity_uid = $host_entity ? (int) $host_entity->id() : 0;

      if ($host_entity_uid && $this->entityTypeManagerService->hasDefinition('profile')) {
        $profiles = $this->entityTypeManagerService->getStorage('profile')
          ->loadByProperties(['uid' => $host_entity_uid, 'type' => 'coordinator', 'status' => 1]);
        $profile = reset($profiles);
        if ($profile && $profile->hasField('field_coordinator_focus') && !$profile->get('field_coordinator_focus')->isEmpty()) {
          $host_area = (string) $profile->get('field_coordinator_focus')->value;
        }
      }
    }

    // Build member list: node author (if not the host) + anyone who joined via
    // field_appointment_attendees (if different from author and host).
    $owner     = $node->getOwner();
    $owner_uid = $owner ? (int) $owner->id() : 0;

    $member_uid   = 0;
    $member_name  = '';
    $members      = [];  // all participants for display

    if ($owner_uid && $owner_uid !== $host_entity_uid) {
      $member_uid  = $owner_uid;
      $member_name = $owner->getDisplayName();
      $members[]   = $member_name;
    }

    // Add anyone who joined via the Join form (field_appointment_attendees),
    // skipping duplicates of the node author or host.
    if ($node->hasField('field_appointment_attendees') && !$node->get('field_appointment_attendees')->isEmpty()) {
      foreach ($node->get('field_appointment_attendees')->referencedEntities() as $attendee) {
        $attendee_uid = (int) $attendee->id();
        if ($attendee_uid !== $owner_uid && $attendee_uid !== $host_entity_uid) {
          $members[] = $attendee->getDisplayName();
          // Use first joiner as primary member_uid for pending badge link.
          if (!$member_uid) {
            $member_uid  = $attendee_uid;
            $member_name = $attendee->getDisplayName();
          }
        }
      }
    }

    $purpose = '';
    if ($node->hasField('field_appointment_purpose') && !$node->get('field_appointment_purpose')->isEmpty()) {
      $purpose = (string) $node->get('field_appointment_purpose')->first()?->getString();
    }

    $badges = [];
    if ($node->hasField('field_appointment_badges') && !$node->get('field_appointment_badges')->isEmpty()) {
      foreach ($node->get('field_appointment_badges')->referencedEntities() as $term) {
        $badges[] = $term->label();
      }
    }

    $note = '';
    if ($node->hasField('field_appointment_note') && !$node->get('field_appointment_note')->isEmpty()) {
      $note = (string) $node->get('field_appointment_note')->value;
    }

    $role_labels = [
      'hosting'   => 'Hosting',
      'hosted'    => 'Hosted',
      'attending' => 'Attending',
      'attended'  => 'Attended',
      'all'       => 'Scheduled',
    ];

    // Pending badges link: facilitators only (view own facilitator stats
    // permission), and only when the appointment has badge terms.
    $show_pending = $this->currentUser()->hasPermission('view own facilitator stats')
      && in_array($role, ['hosting', 'all'], TRUE)
      && !empty($badges);

    // Join link: show when the appointment has available joiner slots and the
    // current user is not already in it.
    $join_url = '';
    if (!$is_past) {
      $joiner_cap  = (int) (\Drupal::config('appointment_facilitator.settings')->get('system_wide_joiner_cap') ?? 0);
      $open_to_join = !$node->hasField('field_appointment_open_to_join')
        || $node->get('field_appointment_open_to_join')->isEmpty()
        || (bool) $node->get('field_appointment_open_to_join')->value;
      if ($joiner_cap > 0 && $open_to_join) {
        $current_uid = (int) $this->currentUser()->id();
        $is_already_in = ($current_uid === $host_entity_uid)
          || ($current_uid === $owner_uid);

        if (!$is_already_in && $node->hasField('field_appointment_attendees')) {
          foreach ($node->get('field_appointment_attendees')->getValue() as $item) {
            if ((int) ($item['target_id'] ?? 0) === $current_uid) {
              $is_already_in = TRUE;
              break;
            }
          }
        }

        if (!$is_already_in) {
          // Count existing extra joiners (not the primary member, not the host).
          $joiner_count = 0;
          if ($node->hasField('field_appointment_attendees')) {
            foreach ($node->get('field_appointment_attendees')->getValue() as $item) {
              $id = (int) ($item['target_id'] ?? 0);
              if ($id && $id !== $owner_uid && $id !== $host_entity_uid) {
                $joiner_count++;
              }
            }
          }
          if ($joiner_count < $joiner_cap) {
            $join_url = Url::fromRoute('appointment_facilitator.join', ['node' => $node->id()])->toString();
          }
        }
      }
    }

    return [
      '#theme'        => 'appointment_card',
      '#role'         => $role,
      '#role_label'   => $role_labels[$role] ?? $role,
      '#date'         => $date_str,
      '#time'         => $time_str,
      '#url'          => $node->toUrl()->toString(),
      '#host'         => $host_name,
      '#host_area'    => $host_area,
      '#member'       => $member_name,
      '#members'      => $members,
      '#member_uid'   => $member_uid,
      '#pending_url'  => ($show_pending && $member_uid)
        ? '/badges/pending/user/' . $member_uid
        : '',
      '#join_url'     => $join_url,
      '#purpose'      => $purpose,
      '#badges'       => $badges,
      '#note'         => $note,
      '#is_past'      => $is_past,
      '#feedback_url' => $is_past
        ? Url::fromRoute('appointment_facilitator.feedback_form', ['node' => $node->id()])->toString()
        : '',
      '#sort_value'   => $sort_val,
    ];
  }

  /**
   * Extracts date string, time range string, and sort timestamp from a node.
   *
   * @return array [date_str, time_str, sort_timestamp]
   */
  protected function extractDateTime(NodeInterface $node): array {
    if ($node->hasField('field_appointment_timerange') && !$node->get('field_appointment_timerange')->isEmpty()) {
      $item  = $node->get('field_appointment_timerange')->first();
      $start = (int) $item->value;
      $end   = (int) $item->end_value;

      $date_str  = $this->dateFormatterService->format($start, 'custom', 'D, M j');
      $start_str = $this->dateFormatterService->format($start, 'custom', 'g:i');
      $end_str   = $this->dateFormatterService->format($end,   'custom', 'g:i a');
      $time_str  = $start_str . '–' . $end_str;

      return [$date_str, $time_str, $start];
    }

    if ($node->hasField('field_appointment_date') && !$node->get('field_appointment_date')->isEmpty()) {
      $val  = (string) $node->get('field_appointment_date')->value;
      $ts   = strtotime($val);
      $date = $this->dateFormatterService->format($ts, 'custom', 'D, M j');
      return [$date, '', $ts];
    }

    return ['', '', 0];
  }

  /**
   * Splits an upcoming card array into [today, later] based on sort_value.
   */
  protected function splitToday(array $cards, int $now): array {
    $day_start = mktime(0, 0, 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));
    $day_end   = $day_start + 86400;
    $today = $later = [];
    foreach ($cards as $card) {
      $ts = $card['#sort_value'] ?? 0;
      if ($ts >= $day_start && $ts < $day_end) {
        $today[] = $card;
      }
      else {
        $later[] = $card;
      }
    }
    return [$today, $later];
  }

  /**
   * Checks whether a field exists on the appointment bundle.
   */
  protected function fieldExists(string $field_name): bool {
    $definitions = $this->entityFieldManagerService->getFieldDefinitions('node', 'appointment');
    return isset($definitions[$field_name]);
  }

}
