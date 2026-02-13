<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\appointment_facilitator\Form\StatsFilterForm;
use Drupal\appointment_facilitator\Service\AppointmentStats;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides administrative statistics for facilitator appointments.
 */
class StatsController extends ControllerBase {

  public function __construct(
    protected readonly FormBuilderInterface $statsFormBuilder,
    protected readonly EntityTypeManagerInterface $entityTypeManagerService,
    protected readonly EntityFieldManagerInterface $entityFieldManagerService,
    protected readonly DateFormatterInterface $dateFormatterService,
    protected readonly RequestStack $requestStack,
    protected readonly AppointmentStats $statsHelper,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('form_builder'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
      $container->get('appointment_facilitator.stats'),
    );
  }

  /**
   * Displays the stats dashboard.
   */
  public function overview(): array {
    $request = $this->requestStack->getCurrentRequest();
    $filters = $this->buildFilters($request);
    $use_facilitator_terms = empty($filters['start']) && empty($filters['end']);

    $summary = $this->statsHelper->summarize($filters['start'], $filters['end'], [
      'purpose' => $filters['purpose'] ?? NULL,
      'include_cancelled' => $filters['include_cancelled'] ?? FALSE,
      'use_facilitator_terms' => $use_facilitator_terms,
    ]);

    $badge_labels = $this->loadBadgeLabels(array_keys($summary['badge_ids']));
    $user_labels = $this->loadUserLabels(array_keys($summary['facilitators']));
    $purpose_labels = $this->getAllowedValues('field_appointment_purpose');
    $result_labels = $this->getAllowedValues('field_appointment_result');
    $status_labels = $this->getAllowedValues('field_appointment_status');
    $arrival_status_labels = $this->getAllowedValues('field_facilitator_arrival_status');

    [$sort_key, $sort_direction] = $this->resolveSort($request);
    $header = $this->buildTableHeader($request, $sort_key, $sort_direction);
    $facilitators = $this->sortFacilitators($summary['facilitators'], $user_labels, $sort_key, $sort_direction);
    $rows = $this->buildTableRows($facilitators, $user_labels, $badge_labels, $purpose_labels, $result_labels, $status_labels, $arrival_status_labels);

    return [
      '#type' => 'container',
      '#attached' => [
        'library' => [
          'appointment_facilitator/stats_report',
        ],
      ],
      'filter' => $this->statsFormBuilder->getForm(StatsFilterForm::class, $filters, $purpose_labels),
      'summary' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Summary'),
        '#items' => $this->buildSummaryItems($summary, $purpose_labels, $result_labels, $status_labels),
        '#attributes' => ['class' => ['appointment-facilitator-summary']],
      ],
      'definitions' => $this->buildDefinitions($use_facilitator_terms),
      'table_wrapper' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['appointment-facilitator-table-wrapper']],
        'table' => [
          '#type' => 'table',
          '#header' => $header,
          '#rows' => $rows,
          '#empty' => $this->t('No appointments found for the selected filters.'),
          '#attributes' => ['class' => ['appointment-facilitator-table']],
        ],
      ],
    ];
  }

  protected function buildDefinitions(bool $use_facilitator_terms): array {
    $items = [
      Markup::create($this->t('<strong>Sessions</strong>: Total appointments hosted in the date range.')),
      Markup::create($this->t('<strong>Evaluation Complete %</strong>: Percentage of appointments with feedback submitted.')),
      Markup::create($this->t('<strong>Evaluation Results</strong>: Distribution of appointment outcomes (successful, check-in, other, etc.) - percentages exclude appointments without results.')),
      Markup::create($this->t('<strong>Arrival %</strong>: Percentage of appointments where facilitator was detected via access logs (scans up to 4 hours before through end of session).')),
      Markup::create($this->t('<strong>Late %</strong>: Percentage of tracked appointments where arrival was after start time (includes grace period and late).')),
      Markup::create($this->t('<strong>Missed %</strong>: Percentage of tracked appointments with no access log scan found.')),
      Markup::create($this->t('<strong>Details</strong>: Compact view of attendees served, badge sessions, cancelled count, active days, and top badges issued.')),
      Markup::create($this->t('<strong>Arrival Window</strong>: System checks for access logs from 4 hours before appointment start through the end time. This accommodates facilitators who arrive early for setup.')),
    ];

    return [
      '#theme' => 'item_list',
      '#title' => $this->t('How to read this report'),
      '#items' => $items,
      '#attributes' => ['class' => ['appointment-facilitator-definitions']],
    ];
  }

  protected function buildFilters(Request $request): array {
    $filters = [];

    $start_input = $request->query->get('start');
    $end_input = $request->query->get('end');
    $purpose = $request->query->get('purpose');
    $include_cancelled = (bool) $request->query->get('include_cancelled');

    // Default to last 6 months if no start date provided.
    if (!$start_input) {
      $start_date = new DrupalDateTime('-6 months');
      $start_date->setTime(0, 0, 0);
    }
    else {
      $start_date = $this->createDate($start_input . ' 00:00:00');
    }

    $end_date = $end_input ? $this->createDate($end_input . ' 23:59:59') : NULL;

    if ($start_date && $end_date && $start_date > $end_date) {
      $end_date = NULL;
    }

    $filters['start'] = $start_date;
    $filters['end'] = $end_date;

    if (!empty($purpose) && $purpose !== 'all') {
      $filters['purpose'] = $purpose;
    }

    $filters['raw'] = [
      'start' => $start_date ? $start_date->format('Y-m-d') : '',
      'end' => $end_date ? $end_date->format('Y-m-d') : '',
      'purpose' => $purpose ?: 'all',
      'include_cancelled' => $include_cancelled ? 1 : 0,
    ];

    $filters['include_cancelled'] = $include_cancelled;

    return $filters;
  }

  protected function createDate(string $value): ?DrupalDateTime {
    try {
      return new DrupalDateTime($value);
    }
    catch (\Exception $e) {
      $this->getLogger('appointment_facilitator')->warning('Invalid date filter provided: @value', ['@value' => $value]);
      return NULL;
    }
  }

  protected function loadBadgeLabels(array $ids): array {
    $ids = array_filter(array_map('intval', $ids));
    if (!$ids) {
      return [];
    }
    $terms = $this->entityTypeManagerService->getStorage('taxonomy_term')->loadMultiple($ids);
    $labels = [];
    foreach ($terms as $term) {
      $labels[$term->id()] = $term->label();
    }
    return $labels;
  }

  protected function loadUserLabels(array $uids): array {
    $uids = array_filter(array_map('intval', $uids));
    if (!$uids) {
      return [];
    }
    $accounts = $this->entityTypeManagerService->getStorage('user')->loadMultiple($uids);
    $labels = [];
    foreach ($accounts as $account) {
      $labels[$account->id()] = $account->getDisplayName();
    }
    return $labels;
  }

  protected function getAllowedValues(string $field_name): array {
    $definitions = $this->entityFieldManagerService->getFieldDefinitions('node', 'appointment');
    if (!isset($definitions[$field_name])) {
      return [];
    }
    $settings = $definitions[$field_name]->getSetting('allowed_values') ?? [];
    $labels = [];
    foreach ($settings as $item) {
      if (is_array($item) && isset($item['value'])) {
        $labels[$item['value']] = $item['label'] ?? $item['value'];
      }
      elseif (is_string($item)) {
        $labels[$item] = $item;
      }
    }
    return $labels;
  }

  protected function resolveSort(Request $request): array {
    $sortable = [
      'name',
      'appointments',
      'badge_sessions',
      'badges',
      'attendees',
      'feedback_rate',
      'arrival_rate',
      'late_rate',
      'missed_rate',
      'arrival_days',
      'appointments_per_week',
      'appointments_per_month',
      'appointment_day_count',
      'cancelled',
      'latest',
    ];
    $sort = $request->query->get('sort');
    if (!in_array($sort, $sortable, TRUE)) {
      $sort = 'appointments';
    }

    $order = strtolower((string) $request->query->get('order', 'desc'));
    if (!in_array($order, ['asc', 'desc'], TRUE)) {
      $order = 'desc';
    }

    return [$sort, $order];
  }

  protected function buildTableHeader(Request $request, string $sort_key, string $sort_direction): array {
    $columns = [
      'name' => $this->t('Facilitator'),
      'appointments' => $this->t('Sessions'),
      'feedback_rate' => $this->t('Evaluation Complete %'),
      'result' => $this->t('Evaluation Results'),
      'arrival_rate' => $this->t('Arrival %'),
      'late_rate' => $this->t('Late %'),
      'missed_rate' => $this->t('Missed %'),
      'meta' => $this->t('Details'),
      'latest' => $this->t('Latest'),
    ];

    $sortable = [
      'name',
      'appointments',
      'feedback_rate',
      'arrival_rate',
      'late_rate',
      'missed_rate',
      'latest',
    ];

    $header = [];
    foreach ($columns as $key => $label) {
      if (in_array($key, $sortable, TRUE)) {
        $header[$key] = [
          'data' => $this->buildSortLink($label, $key, $sort_key, $sort_direction, $request),
        ];
      }
      else {
        $header[$key] = $label;
      }
    }

    return $header;
  }

  protected function buildSortLink($label, string $column, string $current_sort, string $current_order, Request $request): array {
    $query = $request->query->all();
    $new_order = ($current_sort === $column && $current_order === 'asc') ? 'desc' : 'asc';
    $query['sort'] = $column;
    $query['order'] = $new_order;

    $indicator = '';
    if ($current_sort === $column) {
      $indicator = $current_order === 'asc' ? ' ▲' : ' ▼';
    }

    $url = Url::fromRoute('<current>', [], ['query' => $query]);
    $link = Link::fromTextAndUrl($label . $indicator, $url);
    $render = $link->toRenderable();
    $render['#title'] = Markup::create($label . $indicator);
    return $render;
  }

  protected function sortFacilitators(array $facilitators, array $user_labels, string $sort_key, string $sort_direction): array {
    $records = array_values($facilitators);
    $multiplier = $sort_direction === 'asc' ? 1 : -1;

    usort($records, function (array $a, array $b) use ($sort_key, $multiplier, $user_labels) {
      switch ($sort_key) {
        case 'name':
          $name_a = $this->buildFacilitatorName($a['uid'], $user_labels);
          $name_b = $this->buildFacilitatorName($b['uid'], $user_labels);
          $comparison = strcasecmp($name_a, $name_b);
          break;

        case 'badge_sessions':
        case 'badges':
        case 'attendees':
        case 'feedback_rate':
        case 'arrival_rate':
        case 'arrival_days':
        case 'appointments_per_week':
        case 'appointments_per_month':
        case 'appointment_day_count':
        case 'cancelled':
        case 'appointments':
          $comparison = $a[$sort_key] <=> $b[$sort_key];
          break;

        case 'late_rate':
          $comparison = ($this->calculateLateRate($a) ?? -1) <=> ($this->calculateLateRate($b) ?? -1);
          break;

        case 'missed_rate':
          $comparison = ($this->calculateMissedRate($a) ?? -1) <=> ($this->calculateMissedRate($b) ?? -1);
          break;

        case 'latest':
          $time_a = $a['latest'] instanceof \Drupal\Core\Datetime\DrupalDateTime ? $a['latest']->getTimestamp() : 0;
          $time_b = $b['latest'] instanceof \Drupal\Core\Datetime\DrupalDateTime ? $b['latest']->getTimestamp() : 0;
          $comparison = $time_a <=> $time_b;
          break;

        default:
          $comparison = 0;
      }

      if ($comparison === 0 && $sort_key !== 'appointments') {
        $comparison = $a['appointments'] <=> $b['appointments'];
      }
      if ($comparison === 0) {
        $comparison = strcmp((string) $a['uid'], (string) $b['uid']);
      }

      return $multiplier * $comparison;
    });

    return $records;
  }

  protected function buildFacilitatorName(int $uid, array $user_labels): string {
    if ($uid === 0) {
      return (string) $this->t('Unassigned');
    }
    return $user_labels[$uid] ?? (string) $this->t('User @uid', ['@uid' => $uid]);
  }

  protected function buildTableRows(array $facilitators, array $user_labels, array $badge_labels, array $purpose_labels, array $result_labels, array $status_labels, array $arrival_status_labels): array {
    $rows = [];

    foreach ($facilitators as $data) {
      $name_render = $this->buildFacilitatorNameRenderable($data['uid'], $user_labels);

      // Build compact details cell.
      $details_items = [];
      $details_items[] = $this->t('Attendees: @count', ['@count' => $data['attendees']]);
      if ($data['badge_sessions'] > 0) {
        $details_items[] = $this->t('Badge sessions: @count', ['@count' => $data['badge_sessions']]);
      }
      if ($data['cancelled'] > 0) {
        $details_items[] = $this->t('Cancelled: @count', ['@count' => $data['cancelled']]);
      }
      $details_items[] = $this->t('Active days: @count', ['@count' => $data['appointment_day_count']]);

      if (!empty($data['badges_breakdown'])) {
        $top_badges = array_slice($data['badges_breakdown'], 0, 3, TRUE);
        $badge_names = [];
        foreach ($top_badges as $tid => $count) {
          $badge_names[] = ($badge_labels[$tid] ?? 'Badge ' . $tid) . ' (' . $count . ')';
        }
        if ($badge_names) {
          $details_items[] = $this->t('Top badges: @badges', ['@badges' => implode(', ', $badge_names)]);
        }
      }

      $rows[] = [
        'name' => ['data' => $name_render],
        'appointments' => ['data' => $data['appointments']],
        'feedback_rate' => ['data' => $this->formatPercent($data['feedback_rate'])],
        'result' => ['data' => $this->formatResultPercentages($data['result_counts'], $result_labels, TRUE)],
        'arrival_rate' => ['data' => $this->formatPercent($data['arrival_rate'])],
        'late_rate' => ['data' => $this->formatPercent($this->calculateLateRate($data))],
        'missed_rate' => ['data' => $this->formatPercent($this->calculateMissedRate($data))],
        'meta' => [
          'data' => [
            '#theme' => 'item_list',
            '#items' => $details_items,
            '#attributes' => ['class' => ['appointment-facilitator-meta-list']],
          ],
        ],
        'latest' => ['data' => $this->formatLatest($data['latest'])],
      ];
    }

    return $rows;
  }

  protected function formatLatest($latest): string {
    if ($latest instanceof \Drupal\Core\Datetime\DrupalDateTime) {
      return $this->dateFormatterService->format($latest->getTimestamp(), 'medium');
    }
    return (string) $this->t('—');
  }

  protected function buildFacilitatorNameRenderable(int $uid, array $user_labels): array {
    $name = $this->buildFacilitatorName($uid, $user_labels);

    if ($uid === 0) {
      return ['#markup' => $name];
    }

    $url = Url::fromRoute('entity.user.canonical', ['user' => $uid]);
    return [
      '#type' => 'link',
      '#title' => $name,
      '#url' => $url,
    ];
  }

  protected function buildSummaryItems(array $summary, array $purpose_labels, array $result_labels, array $status_labels): array {
    $items = [];
    $items[] = $this->t('Appointments: @count', ['@count' => $summary['total_appointments']]);
    $items[] = $this->t('Attendees served: @count', ['@count' => $summary['total_attendees']]);
    $items[] = $this->t('Badge sessions: @count', ['@count' => $summary['total_badge_appointments']]);
    $items[] = $this->t('Badges selected: @count', ['@count' => $summary['total_badges']]);
    $items[] = $this->t('Cancelled appointments: @count', ['@count' => $summary['cancelled_total']]);
    if (!empty($summary['arrival_available']) && $summary['total_appointment_days'] > 0) {
      $items[] = $this->t('Arrival days: @count of @total (@rate%)', [
        '@count' => $summary['total_arrival_days'],
        '@total' => $summary['total_appointment_days'],
        '@rate' => $summary['arrival_rate'],
      ]);
    }
    if ($summary['total_appointments'] > 0) {
      $items[] = $this->t('Evaluation completion: @rate% (@count)', [
        '@rate' => $summary['feedback_rate'],
        '@count' => $summary['total_feedback'],
      ]);
    }

    if ($summary['purpose_totals']) {
      $items[] = Markup::create($this->t('Purpose mix: @list', ['@list' => $this->formatDistribution($summary['purpose_totals'], $purpose_labels)]));
    }
    if ($summary['result_totals']) {
      $items[] = Markup::create($this->t('Result mix (set): @list', ['@list' => $this->formatResultPercentages($summary['result_totals'], $result_labels)]));
    }
    if ($summary['status_totals']) {
      $items[] = Markup::create($this->t('Status mix: @list', ['@list' => $this->formatDistribution($summary['status_totals'], $status_labels)]));
    }
    if (!empty($summary['arrival_status_totals'])) {
      $arrival_status_labels = $this->getAllowedValues('field_facilitator_arrival_status');
      $items[] = Markup::create($this->t('Arrival status mix: @list', ['@list' => $this->formatDistribution($summary['arrival_status_totals'], $arrival_status_labels)]));
    }

    return $items;
  }

  protected function formatRate($value): string {
    if ($value === NULL) {
      return (string) $this->t('—');
    }
    if ($value === 0 || $value === '0' || $value === 0.0) {
      return '0';
    }
    return (string) $value;
  }

  protected function formatPercent($value): string {
    if ($value === NULL) {
      return (string) $this->t('—');
    }
    $value = (float) $value;
    return $value . '%';
  }

  /**
   * Calculates late percentage from arrival status counts.
   */
  protected function calculateLateRate(array $facilitator): ?float {
    $counts = $facilitator['arrival_status_counts'] ?? [];
    if (!$counts || !is_array($counts)) {
      return NULL;
    }

    $evaluated = array_sum($counts);
    if ($evaluated <= 0) {
      return NULL;
    }

    $late_total = (int) ($counts['late'] ?? 0) + (int) ($counts['late_grace'] ?? 0) + (int) ($counts['missed'] ?? 0);
    return round(($late_total / $evaluated) * 100, 1);
  }

  /**
   * Calculates missed percentage from arrival status counts.
   */
  protected function calculateMissedRate(array $facilitator): ?float {
    $counts = $facilitator['arrival_status_counts'] ?? [];
    if (!$counts || !is_array($counts)) {
      return NULL;
    }

    $evaluated = array_sum($counts);
    if ($evaluated <= 0) {
      return NULL;
    }

    $missed_total = (int) ($counts['missed'] ?? 0);
    return round(($missed_total / $evaluated) * 100, 1);
  }

  protected function formatResultPercentages(array $counts, array $labels, bool $as_list = FALSE): string|array {
    $filtered = array_filter($counts, static fn($value, $key) => $value > 0 && $key !== '_none', ARRAY_FILTER_USE_BOTH);
    if (!$filtered) {
      return (string) $this->t('—');
    }

    $total = array_sum($filtered);
    if ($total === 0) {
      return (string) $this->t('—');
    }

    arsort($filtered);
    $items = [];
    foreach ($filtered as $key => $value) {
      $label = $labels[$key] ?? $this->humanizeMachineName($key);
      $percentage = round(($value / $total) * 100, 1);
      $percentage = (float) $percentage;
      $items[] = $this->t('@percent% (@count) @label', [
        '@label' => $label,
        '@percent' => $percentage,
        '@count' => $value,
      ]);
    }

    if ($as_list) {
      return $this->renderList($items);
    }

    return implode(', ', array_map(static function ($item) {
      if (is_array($item) && isset($item['#markup'])) {
        return (string) $item['#markup'];
      }
      return (string) $item;
    }, $items));
  }

  protected function formatDistribution(array $counts, array $labels): string {
    if (!$counts) {
      return (string) $this->t('—');
    }
    arsort($counts);
    $pieces = [];
    foreach ($counts as $key => $value) {
      $label = $labels[$key] ?? NULL;

      if ($key === '_none' || $key === NULL || $key === '') {
        $label = $this->t('Not set');
      }
      elseif ($label === NULL) {
        $label = $this->humanizeMachineName($key);
      }

      $pieces[] = $this->t('@count @label', ['@label' => $label, '@count' => $value]);
    }
    return implode(', ', $pieces);
  }

  protected function formatDistributionList(array $counts, array $labels, bool $include_value = FALSE): array {
    if (!$counts) {
      return ['#markup' => $this->t('—')];
    }
    arsort($counts);
    $items = [];
    foreach ($counts as $key => $value) {
      $label = $labels[$key] ?? NULL;

      if ($key === '_none' || $key === NULL || $key === '') {
        $label = $this->t('Not set');
      }
      elseif ($label === NULL) {
        $label = $this->humanizeMachineName($key);
      }

      $text = $include_value
        ? $this->t('@count @label', ['@label' => $label, '@count' => $value])
        : $this->t('@label (@count)', ['@label' => $label, '@count' => $value]);

      $items[] = ['#markup' => $text];
    }

    return $this->renderList($items);
  }

  protected function formatTopBadges(array $badge_counts, array $badge_labels): array {
    if (!$badge_counts) {
      return ['#markup' => $this->t('—')];
    }
    arsort($badge_counts);
    $top = array_slice($badge_counts, 0, 3, TRUE);
    $items = [];
    foreach ($top as $tid => $count) {
      $label = $badge_labels[$tid] ?? $this->t('Badge @id', ['@id' => $tid]);
      $items[] = ['#markup' => $this->t('@count @label', ['@label' => $label, '@count' => $count])];
    }
    return $this->renderList($items);
  }

  protected function humanizeMachineName(?string $value): string {
    if ($value === NULL || $value === '' || $value === '_none') {
      return (string) $this->t('Not set');
    }
    $value = str_replace(['_', '-'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', trim($value));
    return ucwords($value);
  }

  protected function renderList(array $items): array {
    if (!$items) {
      return ['#markup' => $this->t('—')];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => [
        'class' => ['appointment-facilitator-list', 'list-unstyled'],
      ],
      '#item_attributes' => ['class' => ['appointment-facilitator-list__item']],
    ];
  }

}
