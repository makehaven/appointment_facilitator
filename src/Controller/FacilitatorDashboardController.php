<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\appointment_facilitator\Service\BadgePrerequisiteGate;
use Drupal\appointment_facilitator\Service\AppointmentStats;
use Drupal\appointment_facilitator\Service\AppointmentSlackService;
use Drupal\asset_status\Service\AssetAvailability;
use Drupal\Component\Utility\Html;
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

  protected const FEEDBACK_DELAY_DAYS = 30;

  protected const FEEDBACK_MINIMUM_COUNT = 3;

  protected const FEEDBACK_DISPLAY_LIMIT = 6;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManagerService,
    protected readonly EntityFieldManagerInterface $entityFieldManagerService,
    protected readonly DateFormatterInterface $dateFormatterService,
    protected readonly AppointmentStats $statsHelper,
    protected readonly BadgePrerequisiteGate $badgeGate,
    protected readonly AppointmentSlackService $slackService,
    protected readonly AssetAvailability $assetAvailability,
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
      $container->get('asset_status.availability'),
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
      $appointment_url = $node->toUrl()->setAbsolute()->toString();
      $message = $this->t('Hi @name, your facilitator (@host) is running a few minutes late for your appointment. They are on their way! Appointment: @url', [
        '@name' => $attendee->getDisplayName(),
        '@host' => $host->getDisplayName(),
        '@url' => $appointment_url,
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
    $lifetime_summary = $this->statsHelper->summarize(NULL, NULL, [
      'host_id' => $uid,
      'include_cancelled' => TRUE,
      'use_facilitator_terms' => FALSE,
    ]);
    $facilitator = $summary['facilitators'][$uid] ?? $this->emptyFacilitatorStats($uid);
    $lifetime = $lifetime_summary['facilitators'][$uid] ?? $this->emptyFacilitatorStats($uid);
    $term = $this->statsHelper->getFacilitatorTermRange($uid);
    $term_note = $this->t('These counts only include appointments in your active facilitator term.');
    if ($term) {
      $term_note = $this->t('Current term: @start to @end.', [
        '@start' => $this->dateFormatterService->format($term['start']->getTimestamp(), 'custom', 'M j, Y'),
        '@end' => $this->dateFormatterService->format($term['end']->getTimestamp(), 'custom', 'M j, Y'),
      ]);
    }

    $upcoming_appointments = $this->loadUpcomingAppointments($uid, $now, 8);
    $qualified_badges = $this->loadQualifiedBadges($uid, 12);
    $feedback_items = $this->loadAnonymizedFeedbackItems($uid);
    $offline_tools = $this->loadOfflineToolsForIssuerBadges($uid, 8);
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
        '#attributes' => ['class' => ['afd-stat-section']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Current term snapshot'),
          '#attributes' => ['class' => ['afd-section-title']],
        ],
        'note' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $term_note,
          '#attributes' => ['class' => ['afd-section-note']],
        ],
        'cards' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afd-stat-row']],
          'term_appointments' => $this->buildStatCard($this->t('Appointments'), (string) $facilitator['appointments']),
          'term_attendees' => $this->buildStatCard($this->t('Attendees'), (string) $facilitator['attendees']),
          'term_eval' => $this->buildStatCard($this->t('Feedback'), $this->formatPercent($facilitator['feedback_rate'])),
          'term_arrival' => $this->buildStatCard($this->t('Arrival'), $this->formatPercent($facilitator['arrival_rate'])),
        ],
      ],
      'lifetime_stat_row' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['afd-stat-section', 'afd-stat-section-secondary']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Lifetime snapshot'),
          '#attributes' => ['class' => ['afd-section-title']],
        ],
        'note' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('These totals include your full appointment history as a facilitator.'),
          '#attributes' => ['class' => ['afd-section-note']],
        ],
        'cards' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afd-stat-row', 'afd-stat-row-secondary']],
          'lifetime_appointments' => $this->buildStatCard($this->t('Appointments'), (string) $lifetime['appointments'], TRUE),
          'lifetime_attendees' => $this->buildStatCard($this->t('Attendees'), (string) $lifetime['attendees'], TRUE),
          'lifetime_eval' => $this->buildStatCard($this->t('Feedback'), $this->formatPercent($lifetime['feedback_rate']), TRUE),
          'lifetime_arrival' => $this->buildStatCard($this->t('Arrival'), $this->formatPercent($lifetime['arrival_rate']), TRUE),
        ],
      ],
      'content_grid' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['afd-grid']],
        'upcoming' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afd-card', 'afd-col-full']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('Upcoming appointments'),
          ],
          'table' => $this->buildUpcomingTable($upcoming_appointments),
        ],
        'feedback' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afd-card', 'afd-col-wide']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('What members are saying'),
          ],
          'note' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this->t('Feedback is shown without member names or appointment dates, only after a @days-day delay, and in randomized order.', [
              '@days' => self::FEEDBACK_DELAY_DAYS,
            ]),
            '#attributes' => ['class' => ['afd-muted']],
          ],
          'list' => $this->buildFeedbackList($feedback_items),
        ],
        'issuer_badges' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afd-card', 'afd-col-narrow']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('Badges you can issue'),
          ],
          'list' => $this->buildBadgeList($qualified_badges),
          'earn_more' => [
            '#markup' => '<div class="afd-note-card"><strong>' . $this->t('Earn more') . '</strong><p>' . $this->t('Earn the badge, arrange with another issuer to watch you badge someone else, then badge under their supervision. If that goes well, they can add you as an issuer.') . '</p></div>',
          ],
        ],
        'offline_tools' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afd-card', 'afd-col-full']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('Tools currently offline'),
          ],
          'note' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this->t('These tools are connected to badges you can issue, so members may ask about them.'),
            '#attributes' => ['class' => ['afd-muted']],
          ],
          'list' => $this->buildOfflineToolList($offline_tools),
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
          Url::fromRoute('smart_date_recur.instances', ['rrule' => $rrule_id, 'modal' => 0])
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
      ];
      if ($member_id) {
        $actions['pending'] = [
          'title' => $this->t('Member pending'),
          'url' => Url::fromUri('internal:/badges/pending/user/' . $member_id),
          'attributes' => [
            'class' => ['afd-chip-link-secondary'],
          ],
        ];
        $actions['running_late'] = [
          'title' => $this->t('Running late'),
          'url' => Url::fromRoute('appointment_facilitator.running_late', ['node' => $appointment->id()]),
          'attributes' => [
            'class' => ['use-ajax', 'afd-chip-link-warning'],
            'title' => $this->t('Notify attendee via Slack that you are running late.'),
          ],
        ];
      }

      $rows[] = [
        'time' => [
          'data' => [
            '#type' => 'link',
            '#title' => $this->formatAppointmentDate($appointment),
            '#url' => $appointment->toUrl(),
            '#options' => ['attributes' => ['class' => ['afd-date-link']]],
          ],
        ],
        'member' => $member ? $member->getDisplayName() : $this->t('Unknown member'),
        'purpose' => $this->fieldLabelOrFallback($appointment, 'field_appointment_purpose'),
        'badges' => [
          'data' => $badge_links,
        ],
        'actions' => [
          'data' => $this->buildActionLinks($actions),
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
  protected function buildStatCard(string $label, string $value, bool $secondary = FALSE): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => array_filter(['afd-stat', $secondary ? 'afd-stat-secondary' : NULL])],
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
   * Renders action links inline instead of a dropbutton for mobile access.
   */
  protected function buildActionLinks(array $actions): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['afd-action-links']],
    ];

    foreach ($actions as $key => $action) {
      $attributes = ['class' => ['afd-chip-link']];
      if (!empty($action['attributes']) && is_array($action['attributes'])) {
        $attributes = array_replace_recursive($attributes, $action['attributes']);
      }

      $build[$key] = [
        '#type' => 'link',
        '#title' => $action['title'],
        '#url' => $action['url'],
        '#options' => [
          'attributes' => $attributes,
        ],
      ];
    }

    return $build;
  }

  /**
   * Renders anonymized delayed feedback.
   */
  protected function buildFeedbackList(array $items): array {
    if (count($items) === 1 && is_string(reset($items))) {
      return ['#markup' => (string) reset($items)];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['afd-feedback-list']],
    ];
  }

  /**
   * Loads anonymized delayed feedback items.
   */
  protected function loadAnonymizedFeedbackItems(int $uid): array {
    $storage = $this->entityTypeManagerService->getStorage('node');
    $cutoff = \Drupal::time()->getRequestTime() - (self::FEEDBACK_DELAY_DAYS * 86400);
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('status', 1)
      ->condition('field_appointment_host.target_id', $uid)
      ->exists('field_appointment_feedback.value')
      ->condition('field_appointment_feedback.value', '', '<>')
      ->sort('created', 'DESC');

    $nids = $query->execute();
    if (!$nids) {
      return [(string) $this->t('No delayed feedback is available yet.')];
    }

    $nodes = $storage->loadMultiple($nids);
    $items = [];
    foreach ($nodes as $node) {
      $feedback = trim((string) $node->get('field_appointment_feedback')->value);
      if ($feedback === '') {
        continue;
      }
      $appointment_ts = $this->extractAppointmentTimestamp($node);
      if ($appointment_ts !== NULL && $appointment_ts > $cutoff) {
        continue;
      }
      if ($appointment_ts === NULL && (int) $node->getCreatedTime() > $cutoff) {
        continue;
      }
      $items[] = [
        '#markup' => '<blockquote class="afd-feedback-quote">' . nl2br(Html::escape($feedback)) . '</blockquote>',
      ];
    }

    if (count($items) < self::FEEDBACK_MINIMUM_COUNT) {
      return [(string) $this->t('More delayed feedback is needed before anonymous comments can be shown.')];
    }

    $seed = ((int) floor(\Drupal::time()->getRequestTime() / 300)) + $uid;
    mt_srand($seed);
    shuffle($items);
    mt_srand();

    return array_slice($items, 0, self::FEEDBACK_DISPLAY_LIMIT);
  }

  /**
   * Builds an item list for offline tools.
   */
  protected function buildOfflineToolList(array $tools): array {
    if (!$tools) {
      return [
        '#markup' => $this->t('No offline tools are currently linked to badges you can issue.'),
      ];
    }

    $items = [];
    foreach ($tools as $tool) {
      $items[] = [
        '#markup' => '<div class="afd-offline-tool"><a class="afd-inline-link" href="' . Html::escape($tool['url']) . '">' . Html::escape($tool['label']) . '</a><div class="afd-offline-tool__meta">' . Html::escape($tool['status']) . ' · ' . Html::escape($tool['badges']) . '</div></div>',
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['afd-offline-list']],
    ];
  }

  /**
   * Loads offline tools tied to badges this facilitator can issue.
   */
  protected function loadOfflineToolsForIssuerBadges(int $uid, int $limit): array {
    if ($uid <= 0) {
      return [];
    }

    $term_storage = $this->entityTypeManagerService->getStorage('taxonomy_term');
    $badge_ids = $term_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'badges')
      ->condition('status', 1)
      ->condition('field_badge_issuer.target_id', $uid)
      ->exists('field_badge_prerequisite.target_id')
      ->execute();

    if (!$badge_ids) {
      return [];
    }

    $badges = $term_storage->loadMultiple($badge_ids);
    $tool_map = [];
    foreach ($badges as $badge) {
      if (!$badge->hasField('field_badge_prerequisite') || $badge->get('field_badge_prerequisite')->isEmpty()) {
        continue;
      }
      foreach ($badge->get('field_badge_prerequisite')->referencedEntities() as $item) {
        if (!$item instanceof NodeInterface || $item->bundle() !== 'item' || !$item->isPublished()) {
          continue;
        }
        if (!$item->hasField('field_item_status') || $item->get('field_item_status')->isEmpty()) {
          continue;
        }
        $status_term = $item->get('field_item_status')->entity;
        if (!$status_term || $this->assetAvailability->isUsable($status_term)) {
          continue;
        }

        $nid = (int) $item->id();
        if (!isset($tool_map[$nid])) {
          $tool_map[$nid] = [
            'label' => $item->label(),
            'status' => $status_term->label(),
            'url' => $item->toUrl()->toString(),
            'badges' => [],
          ];
        }
        $tool_map[$nid]['badges'][$badge->id()] = $badge->label();
      }
    }

    if (!$tool_map) {
      return [];
    }

    uasort($tool_map, static function (array $a, array $b): int {
      return strnatcasecmp($a['label'], $b['label']);
    });

    $items = [];
    foreach (array_slice($tool_map, 0, $limit, TRUE) as $tool) {
      $tool['badges'] = implode(', ', array_values($tool['badges']));
      $items[] = $tool;
    }

    return $items;
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
      '#type' => 'container',
      '#attributes' => ['class' => ['afd-table-wrap']],
      'table' => [
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
      ],
    ];
  }

  /**
   * Extracts the appointment timestamp from a node when possible.
   */
  protected function extractAppointmentTimestamp($node): ?int {
    if ($node->hasField('field_appointment_timerange') && !$node->get('field_appointment_timerange')->isEmpty()) {
      $value = $node->get('field_appointment_timerange')->value;
      return $value ? strtotime((string) $value) : NULL;
    }

    if ($node->hasField('field_appointment_date') && !$node->get('field_appointment_date')->isEmpty()) {
      $value = $node->get('field_appointment_date')->value;
      return $value ? strtotime((string) $value . ' 12:00:00') : NULL;
    }

    return NULL;
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
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'task')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range(0, $scanLimit);

    if ($this->taskFieldExists('field_task_priority')) {
      $query->sort('field_task_priority.value', 'ASC');
    }

    $nids = $query->execute();

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
   * Checks whether a node field exists on task bundle.
   */
  protected function taskFieldExists(string $field_name): bool {
    $definitions = $this->entityFieldManagerService->getFieldDefinitions('node', 'task');
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
