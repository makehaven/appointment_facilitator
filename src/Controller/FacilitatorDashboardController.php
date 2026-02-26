<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\appointment_facilitator\Service\BadgePrerequisiteGate;
use Drupal\appointment_facilitator\Service\AppointmentStats;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Action-oriented dashboard for facilitators.
 */
class FacilitatorDashboardController extends ControllerBase {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManagerService,
    protected readonly EntityFieldManagerInterface $entityFieldManagerService,
    protected readonly DateFormatterInterface $dateFormatterService,
    protected readonly AppointmentStats $statsHelper,
    protected readonly BadgePrerequisiteGate $badgeGate,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('date.formatter'),
      $container->get('appointment_facilitator.stats'),
      $container->get('appointment_facilitator.badge_gate'),
    );
  }

  /**
   * Builds the facilitator dashboard page.
   */
  public function overview(): array {
    $account = $this->currentUser();
    $uid = (int) $account->id();
    $now = \Drupal::time()->getRequestTime();

    $summary = $this->statsHelper->summarize(NULL, NULL, [
      'host_id' => $uid,
      'include_cancelled' => TRUE,
      'use_facilitator_terms' => TRUE,
    ]);
    $facilitator = $summary['facilitators'][$uid] ?? $this->emptyFacilitatorStats($uid);

    $upcoming_appointments = $this->loadUpcomingAppointments($uid, $now, 8);
    $pending_followups = $this->loadPendingFollowUps($uid, $now, 10);
    $qualified_badges = $this->loadQualifiedBadges($uid, 24);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['appointment-facilitator-dashboard']],
      '#attached' => [
        'library' => [
          'appointment_facilitator/facilitator_dashboard',
        ],
      ],
      '#cache' => [
        'contexts' => ['user', 'user.permissions'],
        'tags' => [
          'node_list',
          'node_list:appointment',
          'taxonomy_term_list',
          'config:appointment_facilitator.settings',
          'access_control_log_list',
        ],
        'max-age' => 300,
      ],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['afd-header']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('Facilitator dashboard'),
        ],
        'subtitle' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Action-first view for your scheduling, appointments, and badge follow-up work.'),
        ],
      ],
      'quick_actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['afd-card', 'afd-actions']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Quick actions'),
        ],
        'items' => [
          '#theme' => 'item_list',
          '#items' => $this->buildQuickActions(),
          '#attributes' => ['class' => ['afd-action-list']],
        ],
      ],
      'stat_row' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['afd-stat-row']],
        'appointments' => $this->buildStatCard($this->t('Appointments hosted'), (string) $facilitator['appointments']),
        'attendees' => $this->buildStatCard($this->t('Attendees served'), (string) $facilitator['attendees']),
        'eval' => $this->buildStatCard($this->t('Evaluation completion'), $this->formatPercent($facilitator['feedback_rate'])),
        'arrival' => $this->buildStatCard($this->t('Arrival coverage'), $this->formatPercent($facilitator['arrival_rate'])),
      ],
      'content_grid' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['afd-grid']],
        'upcoming' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afd-card', 'afd-col-wide']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('Upcoming appointments'),
          ],
          'table' => $this->buildUpcomingTable($upcoming_appointments),
        ],
        'badges' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afd-card', 'afd-col-narrow']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('Badges you can issue'),
          ],
          'list' => $this->buildBadgeList($qualified_badges),
        ],
        'followups' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afd-card', 'afd-col-full']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('Pending follow-ups for your appointments'),
          ],
          'table' => $this->buildPendingTable($pending_followups),
        ],
      ],
    ];
  }

  /**
   * Redirects legacy self-stats URL to the new dashboard landing page.
   */
  public function redirectFromLegacyStats(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('appointment_facilitator.dashboard')->toString(), 301);
  }

  /**
   * Builds action links shown at the top of dashboard.
   */
  protected function buildQuickActions(): array {
    $actions = [];
    $uid = (int) $this->currentUser()->id();
    $actions[] = $this->linkItem($this->t('Edit my coordinator hours'), Url::fromUri('internal:/user/' . $uid . '/coordinator'));
    if ($this->currentUser()->hasPermission('reschedule smart date recur instances')) {
      $rrule_id = $this->resolveActiveCoordinatorRuleId($uid);
      if ($rrule_id) {
        $actions[] = $this->linkItem(
          $this->t('Manage recurring schedule instances'),
          Url::fromRoute('smart_date_recur.instances', ['rrule' => $rrule_id, 'modal' => 'FALSE'])
        );
      }
      else {
        $actions[] = $this->linkItem($this->t('Manage recurring schedule instances'), Url::fromUri('internal:/user/' . $uid . '/coordinator'));
      }
    }
    $actions[] = $this->linkItem($this->t('Cancel my next session'), Url::fromRoute('appointment_facilitator.cancel_next'));
    $actions[] = $this->linkItem($this->t('Open my facilitator stats'), Url::fromRoute('appointment_facilitator.self_stats_details'));

    if ($this->currentUser()->hasPermission('view appointment facilitator reports')) {
      $actions[] = $this->linkItem($this->t('Open facilitator reports'), Url::fromRoute('appointment_facilitator.stats'));
    }

    return $actions;
  }

  /**
   * Loads upcoming appointments hosted by this facilitator.
   */
  protected function loadUpcomingAppointments(int $uid, int $now, int $limit): array {
    $storage = $this->entityTypeManagerService->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('status', 1)
      ->condition('field_appointment_host.target_id', $uid)
      ->range(0, $limit);

    if ($this->appointmentFieldExists('field_appointment_status')) {
      $query->condition('field_appointment_status.value', 'canceled', '<>');
    }

    if ($this->appointmentFieldExists('field_appointment_timerange')) {
      $query
        ->condition('field_appointment_timerange.value', $now, '>=')
        ->sort('field_appointment_timerange.value', 'ASC');
    }
    elseif ($this->appointmentFieldExists('field_appointment_date')) {
      $today = $this->dateFormatterService->format($now, 'custom', 'Y-m-d');
      $query
        ->condition('field_appointment_date.value', $today, '>=')
        ->sort('field_appointment_date.value', 'ASC');
    }
    else {
      $query->sort('created', 'DESC');
    }

    $nids = $query->execute();
    if (!$nids) {
      return [];
    }

    $appointments = $storage->loadMultiple($nids);
    $rows = [];
    foreach ($appointments as $appointment) {
      $member = $appointment->getOwner();
      $member_id = $member ? (int) $member->id() : NULL;
      $badge_links = $this->buildBadgeIssueLinksForAppointment($appointment, $member?->id());
      $actions = [
        'view' => [
          'title' => $this->t('View'),
          'url' => $appointment->toUrl(),
        ],
      ];
      if ($member_id) {
        $actions['pending'] = [
          'title' => $this->t('Member pending'),
          'url' => Url::fromUri('internal:/badges/pending/user/' . $member_id),
        ];
      }

      $rows[] = [
        'time' => $this->formatAppointmentDate($appointment),
        'member' => $member ? $member->getDisplayName() : $this->t('Unknown member'),
        'purpose' => $this->fieldLabelOrFallback($appointment, 'field_appointment_purpose'),
        'badges' => [
          'data' => $badge_links,
        ],
        'actions' => [
          'data' => [
            '#type' => 'operations',
            '#links' => $actions,
          ],
        ],
      ];
    }

    return $rows;
  }

  /**
   * Loads appointments that likely still need post-session follow-up.
   */
  protected function loadPendingFollowUps(int $uid, int $now, int $limit): array {
    $storage = $this->entityTypeManagerService->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('status', 1)
      ->condition('field_appointment_host.target_id', $uid)
      ->range(0, 50);

    if ($this->appointmentFieldExists('field_appointment_status')) {
      $query->condition('field_appointment_status.value', 'canceled', '<>');
    }

    if ($this->appointmentFieldExists('field_appointment_timerange')) {
      $query
        ->condition('field_appointment_timerange.value', $now, '<=')
        ->sort('field_appointment_timerange.value', 'DESC');
    }
    elseif ($this->appointmentFieldExists('field_appointment_date')) {
      $today = $this->dateFormatterService->format($now, 'custom', 'Y-m-d');
      $query
        ->condition('field_appointment_date.value', $today, '<=')
        ->sort('field_appointment_date.value', 'DESC');
    }
    else {
      $query->sort('created', 'DESC');
    }

    $nids = $query->execute();
    if (!$nids) {
      return [];
    }

    $appointments = $storage->loadMultiple($nids);
    $rows = [];
    foreach ($appointments as $appointment) {
      if (!$this->needsFollowUp($appointment)) {
        continue;
      }

      $member = $appointment->getOwner();
      $member_id = $member ? (int) $member->id() : NULL;
      $actions = [
        'view' => [
          'title' => $this->t('Review appointment'),
          'url' => $appointment->toUrl(),
        ],
      ];
      if ($member_id) {
        $actions['pending'] = [
          'title' => $this->t('Pending badges'),
          'url' => Url::fromUri('internal:/badges/pending/user/' . $member_id),
        ];
      }
      $rows[] = [
        'time' => $this->formatAppointmentDate($appointment),
        'member' => $member ? $member->getDisplayName() : $this->t('Unknown member'),
        'issue' => $this->describeFollowUpIssue($appointment),
        'actions' => [
          'data' => [
            '#type' => 'operations',
            '#links' => $actions,
          ],
        ],
      ];

      if (count($rows) >= $limit) {
        break;
      }
    }

    return $rows;
  }

  /**
   * Loads badges this facilitator is marked as issuer for.
   */
  protected function loadQualifiedBadges(int $uid, int $limit): array {
    $storage = $this->entityTypeManagerService->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'badges')
      ->condition('field_badge_issuer.target_id', $uid)
      ->sort('name', 'ASC')
      ->range(0, $limit);

    $tids = $query->execute();
    if (!$tids) {
      return [];
    }

    $terms = $storage->loadMultiple($tids);
    $items = [];
    foreach ($terms as $term) {
      if ($term->hasField('field_badge_inactive') && !$term->get('field_badge_inactive')->isEmpty() && (bool) $term->get('field_badge_inactive')->value) {
        continue;
      }
      $items[] = [
        'label' => $term->label(),
        'url' => $term->toUrl(),
      ];
    }
    return $items;
  }

  /**
   * Renders a stat tile.
   */
  protected function buildStatCard(string $label, string $value): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['afd-stat']],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $label,
        '#attributes' => ['class' => ['afd-stat-label']],
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $value,
        '#attributes' => ['class' => ['afd-stat-value']],
      ],
    ];
  }

  /**
   * Renders upcoming appointments table.
   */
  protected function buildUpcomingTable(array $rows): array {
    if (!$rows) {
      return [
        '#markup' => $this->t('No upcoming appointments found.'),
      ];
    }
    return [
      '#type' => 'table',
      '#header' => [
        $this->t('When'),
        $this->t('Member'),
        $this->t('Purpose'),
        $this->t('Badges'),
        $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#attributes' => ['class' => ['afd-table']],
    ];
  }

  /**
   * Renders pending follow-ups table.
   */
  protected function buildPendingTable(array $rows): array {
    if (!$rows) {
      return [
        '#markup' => $this->t('No pending follow-ups right now.'),
      ];
    }
    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Appointment'),
        $this->t('Member'),
        $this->t('Needs attention'),
        $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#attributes' => ['class' => ['afd-table']],
    ];
  }

  /**
   * Renders qualified badge list.
   */
  protected function buildBadgeList(array $badges): array {
    if (!$badges) {
      return [
        '#markup' => $this->t('No badge issuer assignments found on your profile yet.'),
      ];
    }

    $items = [];
    foreach ($badges as $badge) {
      $items[] = $this->linkItem($badge['label'], $badge['url']);
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['afd-badge-list']],
    ];
  }

  /**
   * Returns link render array usable in item lists.
   */
  protected function linkItem(string $label, Url $url): array {
    return [
      '#type' => 'link',
      '#title' => $label,
      '#url' => $url,
    ];
  }

  /**
   * Returns badge issue links for an appointment/member.
   */
  protected function buildBadgeIssueLinksForAppointment($appointment, ?int $member_uid): array|string {
    if (!$member_uid || !$appointment->hasField('field_appointment_badges') || $appointment->get('field_appointment_badges')->isEmpty()) {
      return (string) $this->t('—');
    }

    $links = [];
    foreach ($appointment->get('field_appointment_badges')->referencedEntities() as $term) {
      $gate = $this->badgeGate->evaluate($member_uid, $term);
      $status_text = $gate['allowed']
        ? (string) $this->t('approved')
        : (string) $this->t('blocked');
      $status_detail = $this->buildFacilitatorGateDetail($gate);
      $url = Url::fromUri('internal:/badge/user/' . $member_uid . '?badgeid=' . $term->id());
      $links[] = [
        '#type' => 'link',
        '#title' => $term->label() . ' (' . $status_text . ($status_detail ? ': ' . $status_detail : '') . ')',
        '#url' => $url,
        '#options' => [
          'attributes' => ['class' => ['afd-inline-link']],
          'title' => $gate['allowed']
            ? (string) $this->t('Member can proceed with this badge.')
            : (!empty($gate['reasons']) ? implode(' ', $gate['reasons']) : (string) $this->t('Member is blocked by prerequisites or documentation approval.')),
        ],
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $links,
      '#attributes' => ['class' => ['afd-inline-list']],
    ];
  }

  /**
   * Builds an inline detail string for facilitator badge gate status.
   */
  protected function buildFacilitatorGateDetail(array $gate): string {
    if (!empty($gate['allowed'])) {
      return '';
    }

    $parts = [];
    if (!empty($gate['requires_documentation']) && empty($gate['documentation_approved'])) {
      $parts[] = (string) $this->t('documentation approval required');
    }
    if (!empty($gate['prerequisites_missing_labels'])) {
      $parts[] = (string) $this->t('missing prerequisites: @badges', [
        '@badges' => implode(', ', $gate['prerequisites_missing_labels']),
      ]);
    }

    return implode('; ', $parts);
  }

  /**
   * True when appointment appears to need follow-up.
   */
  protected function needsFollowUp($appointment): bool {
    $missing_result = $appointment->hasField('field_appointment_result')
      && trim((string) $appointment->get('field_appointment_result')->value) === '';
    $missing_feedback = $appointment->hasField('field_appointment_feedback')
      && trim((string) $appointment->get('field_appointment_feedback')->value) === '';
    return $missing_result || $missing_feedback;
  }

  /**
   * Human summary of follow-up issue.
   */
  protected function describeFollowUpIssue($appointment): string {
    $issues = [];
    if ($appointment->hasField('field_appointment_result') && trim((string) $appointment->get('field_appointment_result')->value) === '') {
      $issues[] = (string) $this->t('Missing result');
    }
    if ($appointment->hasField('field_appointment_feedback') && trim((string) $appointment->get('field_appointment_feedback')->value) === '') {
      $issues[] = (string) $this->t('Missing feedback');
    }
    return $issues ? implode(', ', $issues) : (string) $this->t('—');
  }

  /**
   * Returns best-effort label from list field.
   */
  protected function fieldLabelOrFallback($entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return (string) $this->t('—');
    }
    return (string) ($entity->get($field_name)->first()?->getString() ?: $this->t('—'));
  }

  /**
   * Formats appointment date from timerange or date field.
   */
  protected function formatAppointmentDate($appointment): string {
    if ($appointment->hasField('field_appointment_timerange') && !$appointment->get('field_appointment_timerange')->isEmpty()) {
      $item = $appointment->get('field_appointment_timerange')->first();
      if ($item && $item->value) {
        return $this->dateFormatterService->format((int) $item->value, 'custom', 'M j, Y g:i a');
      }
    }
    if ($appointment->hasField('field_appointment_date') && !$appointment->get('field_appointment_date')->isEmpty()) {
      $date = (string) $appointment->get('field_appointment_date')->value;
      return $this->dateFormatterService->format(strtotime($date), 'custom', 'M j, Y');
    }
    return (string) $this->t('Unknown date');
  }

  /**
   * Checks whether a node field exists on appointment bundle.
   */
  protected function appointmentFieldExists(string $field_name): bool {
    $definitions = $this->entityFieldManagerService->getFieldDefinitions('node', 'appointment');
    return isset($definitions[$field_name]);
  }

  /**
   * Basic empty stats row fallback.
   */
  protected function emptyFacilitatorStats(int $uid): array {
    return [
      'uid' => $uid,
      'appointments' => 0,
      'attendees' => 0,
      'feedback_rate' => 0,
      'arrival_rate' => NULL,
    ];
  }

  /**
   * Formats percent text.
   */
  protected function formatPercent($value): string {
    if ($value === NULL) {
      return (string) $this->t('—');
    }
    return (float) $value . '%';
  }

  /**
   * Finds an active (or nearest upcoming) recurring rule id for coordinator.
   */
  protected function resolveActiveCoordinatorRuleId(int $uid): ?int {
    if ($uid <= 0 || !$this->entityTypeManagerService->hasDefinition('profile')) {
      return NULL;
    }

    $bundle = \Drupal::config('appointment_facilitator.settings')->get('facilitator_profile_bundle') ?: 'coordinator';
    $account = $this->entityTypeManagerService->getStorage('user')->load($uid);
    if (!$account) {
      return NULL;
    }

    $profiles = $this->entityTypeManagerService->getStorage('profile')->loadByUser($account, $bundle);
    if (!$profiles) {
      return NULL;
    }

    $profile = NULL;
    if (is_object($profiles)) {
      $profile = $profiles;
    }
    elseif (is_array($profiles)) {
      $first_key = array_key_first($profiles);
      $profile = $first_key !== NULL ? $profiles[$first_key] : NULL;
    }
    elseif ($profiles instanceof \Traversable) {
      foreach ($profiles as $item) {
        $profile = $item;
        break;
      }
    }

    if (!$profile || !$profile->hasField('field_coordinator_hours') || $profile->get('field_coordinator_hours')->isEmpty()) {
      return NULL;
    }

    $now = \Drupal::time()->getRequestTime();
    $current = [];
    $future = [];
    $past = [];
    foreach ($profile->get('field_coordinator_hours')->getValue() as $item) {
      $rrule = isset($item['rrule']) ? (int) $item['rrule'] : 0;
      if ($rrule <= 0) {
        continue;
      }
      $start = isset($item['value']) ? (int) $item['value'] : 0;
      $end = isset($item['end_value']) ? (int) $item['end_value'] : 0;
      if ($start <= 0 && $end <= 0) {
        $future[] = ['rrule' => $rrule, 'start' => PHP_INT_MAX, 'end' => PHP_INT_MAX];
        continue;
      }

      if ($start <= $now && $end >= $now) {
        $current[] = ['rrule' => $rrule, 'start' => $start, 'end' => $end];
      }
      elseif ($start > $now) {
        $future[] = ['rrule' => $rrule, 'start' => $start, 'end' => $end];
      }
      else {
        $past[] = ['rrule' => $rrule, 'start' => $start, 'end' => $end];
      }
    }

    if ($current) {
      usort($current, static fn($a, $b) => $a['start'] <=> $b['start']);
      return $current[0]['rrule'];
    }
    if ($future) {
      usort($future, static fn($a, $b) => $a['start'] <=> $b['start']);
      return $future[0]['rrule'];
    }
    if ($past) {
      usort($past, static fn($a, $b) => $b['end'] <=> $a['end']);
      return $past[0]['rrule'];
    }

    return NULL;
  }

}
