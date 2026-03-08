<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\appointment_facilitator\Service\BadgePrerequisiteGate;
use Drupal\appointment_facilitator\Service\AppointmentStats;
use Drupal\appointment_facilitator\Service\AppointmentSlackService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
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
    protected readonly AppointmentSlackService $slackService,
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
      $container->get('appointment_facilitator.slack'),
    );
  }

  /**
   * Sends a "running late" notification to the attendee.
   */
  public function runningLate(NodeInterface $node): RedirectResponse {
    if ($node->bundle() !== 'appointment') {
      return $this->redirect('appointment_facilitator.dashboard');
    }

    $attendee = $node->getOwner();
    $host = $this->currentUser();

    if ($attendee) {
      $message = $this->t('Hi @name, your facilitator (@host) is running a few minutes late for your appointment. They are on their way!', [
        '@name' => $attendee->getDisplayName(),
        '@host' => $host->getDisplayName(),
      ]);

      $success = $this->slackService->sendMessageToUser($attendee, (string) $message);
      if ($success) {
        $this->messenger()->addStatus($this->t('Notification sent to @name via Slack.', ['@name' => $attendee->getDisplayName()]));
      }
      else {
        $this->messenger()->addWarning($this->t('Failed to send Slack notification. They may not have a Slack ID linked or Slack is misconfigured.'));
      }
    }

    return $this->redirect('appointment_facilitator.dashboard');
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
    $task_buckets = $this->loadOpenTaskBuckets($uid, 120);

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
        'tasks' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afd-card', 'afd-col-full']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('Task opportunities'),
          ],
          'subtitle' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#attributes' => ['class' => ['afd-muted']],
            '#value' => $this->t('Tasks can be generic or asset-linked. Maintenance tasks are highlighted when your badges qualify you.'),
          ],
          'my_tasks_title' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('Assigned to me'),
          ],
          'my_tasks' => $this->buildTaskTable($task_buckets['assigned'], $this->t('No open tasks are currently assigned to you.')),
          'qualified_tasks_title' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('Maintenance tasks I am qualified for'),
          ],
          'qualified_tasks' => $this->buildTaskTable($task_buckets['maintenance_qualified'], $this->t('No open maintenance tasks currently match your badges.')),
          'general_tasks_title' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('General open tasks'),
          ],
          'general_tasks' => $this->buildTaskTable($task_buckets['general'], $this->t('No open general tasks right now.')),
          'locked_tasks_title' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('Maintenance tasks requiring additional badges'),
          ],
          'locked_tasks' => $this->buildTaskTable($task_buckets['maintenance_locked'], $this->t('No maintenance tasks are currently blocked by badge requirements.')),
          'footer_links' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['afd-task-links']],
            'open_tasks' => [
              '#type' => 'link',
              '#title' => $this->t('Open full task board'),
              '#url' => Url::fromUri('internal:/tasks'),
              '#options' => ['attributes' => ['class' => ['afd-inline-link']]],
            ],
          ],
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
        $actions['running_late'] = [
          'title' => $this->t('Running late'),
          'url' => Url::fromRoute('appointment_facilitator.running_late', ['node' => $appointment->id()]),
          'attributes' => [
            'class' => ['use-ajax', 'btn', 'btn-warning', 'btn-xs'],
            'title' => $this->t('Notify attendee via Slack that you are running late.'),
          ],
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
   * Builds open task buckets for facilitator operations.
   */
  protected function loadOpenTaskBuckets(int $uid, int $scanLimit): array {
    $buckets = [
      'assigned' => [],
      'maintenance_qualified' => [],
      'maintenance_locked' => [],
      'general' => [],
    ];
    $seen = [];

    $storage = $this->entityTypeManagerService->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'task')
      ->condition('status', 1)
      ->sort('field_task_priority_value', 'ASC')
      ->sort('created', 'DESC')
      ->range(0, $scanLimit)
      ->execute();

    if (!$nids) {
      return $buckets;
    }

    $completed = $this->loadCompletedTaskIds($nids);
    $tasks = $storage->loadMultiple($nids);

    foreach ($tasks as $task) {
      $nid = (int) $task->id();
      if (isset($completed[$nid])) {
        continue;
      }

      $required_badge = NULL;
      if ($task->hasField('field_task_required_badge') && !$task->get('field_task_required_badge')->isEmpty()) {
        $required_badge = $task->get('field_task_required_badge')->entity;
      }

      $is_assigned = $this->isTaskAssignedToFacilitator($task, $uid);
      $is_maintenance = (bool) $required_badge;
      $is_qualified = FALSE;
      if ($is_maintenance && $required_badge) {
        $is_qualified = $this->badgeGate->memberHasActiveOrBlankBadge($uid, (int) $required_badge->id());
      }

      $row = $this->buildTaskRow($task, $required_badge, $is_maintenance, $is_qualified);

      if ($is_assigned && !isset($seen['assigned'][$nid])) {
        $buckets['assigned'][] = $row;
        $seen['assigned'][$nid] = TRUE;
      }

      if ($is_maintenance) {
        $bucket = $is_qualified ? 'maintenance_qualified' : 'maintenance_locked';
        if (!isset($seen[$bucket][$nid])) {
          $buckets[$bucket][] = $row;
          $seen[$bucket][$nid] = TRUE;
        }
      }
      else {
        if (!isset($seen['general'][$nid])) {
          $buckets['general'][] = $row;
          $seen['general'][$nid] = TRUE;
        }
      }
    }

    foreach (['assigned', 'maintenance_qualified', 'maintenance_locked', 'general'] as $bucket) {
      $buckets[$bucket] = array_slice($buckets[$bucket], 0, 8);
    }

    return $buckets;
  }

  /**
   * Returns TRUE when a task is assigned to facilitator directly or by group.
   */
  protected function isTaskAssignedToFacilitator($task, int $uid): bool {
    if ($uid <= 0) {
      return FALSE;
    }

    if ($task->hasField('field_task_lead') && !$task->get('field_task_lead')->isEmpty()) {
      foreach ($task->get('field_task_lead')->getValue() as $lead) {
        if ((int) ($lead['target_id'] ?? 0) === $uid) {
          return TRUE;
        }
      }
    }

    if ($task->hasField('field_task_group') && !$task->get('field_task_group')->isEmpty()) {
      foreach ($task->get('field_task_group')->referencedEntities() as $group_term) {
        if (!$group_term->hasField('field_group_lead') || $group_term->get('field_group_lead')->isEmpty()) {
          continue;
        }
        foreach ($group_term->get('field_group_lead')->getValue() as $lead) {
          if ((int) ($lead['target_id'] ?? 0) === $uid) {
            return TRUE;
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * Loads a map of completed task ids.
   */
  protected function loadCompletedTaskIds(array $taskNids): array {
    if (!$taskNids) {
      return [];
    }

    $results = \Drupal::database()->select('flagging', 'f')
      ->fields('f', ['entity_id'])
      ->condition('f.flag_id', 'task_completed')
      ->condition('f.entity_id', $taskNids, 'IN')
      ->distinct()
      ->execute()
      ->fetchCol();

    $map = [];
    foreach ($results as $entity_id) {
      $map[(int) $entity_id] = TRUE;
    }
    return $map;
  }

  /**
   * Builds a dashboard row for a task.
   */
  protected function buildTaskRow($task, $required_badge, bool $isMaintenance, bool $isQualified): array {
    $asset = (string) $this->t('General');
    if ($task->hasField('field_task_equipment') && !$task->get('field_task_equipment')->isEmpty()) {
      $item = $task->get('field_task_equipment')->entity;
      if ($item) {
        $asset = $item->label();
      }
    }

    $priority = (string) $this->fieldLabelOrFallback($task, 'field_task_priority');
    $requirement = (string) $this->t('None');
    if ($required_badge) {
      $requirement = $required_badge->label() . ($isQualified ? ' (' . (string) $this->t('qualified') . ')' : ' (' . (string) $this->t('missing') . ')');
    }
    elseif ($isMaintenance) {
      $requirement = (string) $this->t('Maintenance badge required');
    }

    return [
      'task' => [
        'data' => [
          '#type' => 'link',
          '#title' => $task->label(),
          '#url' => $task->toUrl(),
          '#options' => ['attributes' => ['class' => ['afd-inline-link']]],
        ],
      ],
      'asset' => $asset,
      'requirement' => $requirement,
      'priority' => $priority,
      'actions' => [
        'data' => [
          '#type' => 'link',
          '#title' => $this->t('Open'),
          '#url' => $task->toUrl(),
          '#options' => ['attributes' => ['class' => ['afd-inline-link']]],
        ],
      ],
    ];
  }

  /**
   * Renders a task table with common schema.
   */
  protected function buildTaskTable(array $rows, $emptyMessage): array {
    if (!$rows) {
      return ['#markup' => $emptyMessage];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Task'),
        $this->t('Asset/Area'),
        $this->t('Badge requirement'),
        $this->t('Priority'),
        $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#attributes' => ['class' => ['afd-table', 'afd-task-table']],
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
