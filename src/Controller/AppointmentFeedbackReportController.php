<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\appointment_facilitator\Form\AppointmentFeedbackReportFilterForm;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds an admin report for appointment feedback narratives.
 */
class AppointmentFeedbackReportController extends ControllerBase {

  public function __construct(
    protected readonly FormBuilderInterface $formBuilderService,
    protected readonly EntityTypeManagerInterface $entityTypeManagerService,
    protected readonly EntityFieldManagerInterface $entityFieldManagerService,
    protected readonly DateFormatterInterface $dateFormatterService,
    protected readonly RequestStack $requestStack,
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
    );
  }

  /**
   * Displays appointment feedback narratives with facilitator drill-down.
   */
  public function overview(): array {
    $request = $this->requestStack->getCurrentRequest();
    $filters = $this->buildFilters($request);

    $count_query = $this->buildBaseQuery($filters);
    $total = (int) $count_query->count()->execute();
    $stats = $this->buildStats($filters);

    $query = $this->buildBaseQuery($filters);
    $date_field = $this->resolveAppointmentDateField();
    if ($date_field) {
      $query->sort($date_field . '.value', 'DESC');
    }
    else {
      $query->sort('created', 'DESC');
    }
    $query->pager($filters['items_per_page']);

    $nids = $query->execute();
    $nodes = $nids ? $this->entityTypeManagerService->getStorage('node')->loadMultiple($nids) : [];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['appointment-feedback-report']],
      '#attached' => [
        'library' => [
          'appointment_facilitator/feedback_report',
        ],
      ],
      '#cache' => [
        'contexts' => [
          'url.query_args',
          'user.permissions',
        ],
        'tags' => [
          'node_list',
          'node_list:appointment',
          'user_list',
          'config:system.date',
        ],
        'max-age' => 300,
      ],
      'filters' => $this->formBuilderService->getForm(AppointmentFeedbackReportFilterForm::class, $filters['raw'] + [
        'purpose_options' => $this->getAllowedValues('field_appointment_purpose'),
        'result_options' => $this->getAllowedValues('field_appointment_result'),
      ]),
      'summary' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Report scope'),
        '#items' => $this->buildSummaryItems($filters, $total),
        '#attributes' => ['class' => ['appointment-feedback-report-summary']],
      ],
      'stats' => $this->buildStatsSummary($stats),
      'results' => $this->buildResultsTable($nodes),
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * Builds the query used for both count and paged result loading.
   */
  protected function buildBaseQuery(array $filters) {
    $query = $this->entityTypeManagerService->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('status', 1)
      ->exists('field_appointment_feedback.value')
      ->condition('field_appointment_feedback.value', '', '<>');

    if (!empty($filters['host'])) {
      $query->condition('field_appointment_host.target_id', (int) $filters['host']);
    }
    if (!empty($filters['purpose'])) {
      $query->condition('field_appointment_purpose.value', $filters['purpose']);
    }
    if (!empty($filters['result'])) {
      $query->condition('field_appointment_result.value', $filters['result']);
    }

    if (!empty($filters['keywords'])) {
      $query->condition('field_appointment_feedback.value', '%' . $filters['keywords'] . '%', 'LIKE');
    }

    $date_field = $this->resolveAppointmentDateField();
    if ($date_field && !empty($filters['start'])) {
      if ($date_field === 'field_appointment_timerange') {
        $query->condition($date_field . '.value', $filters['start']->getTimestamp(), '>=');
      }
      else {
        $query->condition($date_field . '.value', $filters['start']->format('Y-m-d'), '>=');
      }
    }

    if ($date_field && !empty($filters['end'])) {
      if ($date_field === 'field_appointment_timerange') {
        $query->condition($date_field . '.value', $filters['end']->getTimestamp(), '<=');
      }
      else {
        $query->condition($date_field . '.value', $filters['end']->format('Y-m-d'), '<=');
      }
    }

    return $query;
  }

  /**
   * Normalizes query string filters.
   */
  protected function buildFilters(Request $request): array {
    $host = $request->query->get('host');
    $start = $this->createDate($request->query->get('start'), FALSE);
    $end = $this->createDate($request->query->get('end'), TRUE);
    $purpose = trim((string) $request->query->get('purpose', ''));
    $result = trim((string) $request->query->get('result', ''));
    $keywords = trim((string) $request->query->get('keywords', ''));
    $items_per_page = (int) $request->query->get('items_per_page', 100);

    if ($start && $end && $start > $end) {
      $end = NULL;
    }

    $host_id = $this->parseHostFilterValue($host);
    $allowed_purpose = $this->getAllowedValues('field_appointment_purpose');
    $allowed_result = $this->getAllowedValues('field_appointment_result');
    $purpose = isset($allowed_purpose[$purpose]) ? $purpose : '';
    $result = isset($allowed_result[$result]) ? $result : '';
    $items_per_page = in_array($items_per_page, [50, 100, 250], TRUE) ? $items_per_page : 100;

    return [
      'host' => $host_id,
      'start' => $start,
      'end' => $end,
      'purpose' => $purpose,
      'result' => $result,
      'keywords' => $keywords,
      'items_per_page' => $items_per_page,
      'raw' => [
        'host' => $host_id,
        'start' => $start?->format('Y-m-d') ?? '',
        'end' => $end?->format('Y-m-d') ?? '',
        'purpose' => $purpose ?: 'all',
        'result' => $result ?: 'all',
        'keywords' => $keywords,
        'items_per_page' => $items_per_page,
      ],
    ];
  }

  /**
   * Parses facilitator filter values from query strings.
   */
  protected function parseHostFilterValue(mixed $value): ?int {
    if (is_numeric($value) && (int) $value > 0) {
      return (int) $value;
    }

    if (is_string($value) && preg_match('/\((\d+)\)\s*$/', $value, $matches)) {
      return (int) $matches[1];
    }

    return NULL;
  }

  /**
   * Safely creates a Drupal date object from date input.
   */
  protected function createDate(?string $value, bool $end_of_day): ?DrupalDateTime {
    $value = trim((string) $value);
    if ($value === '') {
      return NULL;
    }

    try {
      $date = new DrupalDateTime($value);
      $date->setTime($end_of_day ? 23 : 0, $end_of_day ? 59 : 0, $end_of_day ? 59 : 0);
      return $date;
    }
    catch (\Exception $exception) {
      $this->getLogger('appointment_facilitator')->warning('Invalid appointment feedback report date filter: @value', ['@value' => $value]);
      return NULL;
    }
  }

  /**
   * Resolves the appointment date field present on the site.
   */
  protected function resolveAppointmentDateField(): ?string {
    $definitions = $this->entityFieldManagerService->getFieldDefinitions('node', 'appointment');
    if (isset($definitions['field_appointment_timerange'])) {
      return 'field_appointment_timerange';
    }
    if (isset($definitions['field_appointment_date'])) {
      return 'field_appointment_date';
    }
    return NULL;
  }

  /**
   * Builds the filter summary.
   */
  protected function buildSummaryItems(array $filters, int $total): array {
    $items = [
      $this->t('Feedback narratives found: @count', ['@count' => $total]),
    ];

    if (!empty($filters['host'])) {
      $account = $this->entityTypeManagerService->getStorage('user')->load((int) $filters['host']);
      $items[] = $this->t('Facilitator: @name', ['@name' => $account?->getDisplayName() ?? $this->t('Unknown user')]);
      $items[] = Link::fromTextAndUrl(
        $this->t('Open overall facilitator stats'),
        Url::fromRoute('appointment_facilitator.stats')
      );
    }
    else {
      $items[] = $this->t('Facilitator: all');
    }

    if (!empty($filters['start']) || !empty($filters['end'])) {
      $items[] = $this->t('Date range: @start to @end', [
        '@start' => !empty($filters['start']) ? $filters['start']->format('Y-m-d') : $this->t('earliest'),
        '@end' => !empty($filters['end']) ? $filters['end']->format('Y-m-d') : $this->t('latest'),
      ]);
    }
    if (!empty($filters['purpose'])) {
      $items[] = $this->t('Purpose: @purpose', ['@purpose' => $this->extractFieldLabel($filters['purpose'], 'field_appointment_purpose')]);
    }
    if (!empty($filters['result'])) {
      $items[] = $this->t('Result: @result', ['@result' => $this->extractFieldLabel($filters['result'], 'field_appointment_result')]);
    }

    if (!empty($filters['keywords'])) {
      $items[] = $this->t('Narrative contains: @keywords', ['@keywords' => $filters['keywords']]);
    }

    $items[] = $this->t('Rows per page: @count', ['@count' => $filters['items_per_page']]);

    return $items;
  }

  /**
   * Builds aggregate stats for the filtered result set.
   */
  protected function buildStats(array $filters): array {
    $stats = [
      'records' => 0,
      'with_badges' => 0,
      'with_result' => 0,
      'with_purpose' => 0,
      'purpose_counts' => [],
      'result_counts' => [],
      'facilitator_counts' => [],
    ];

    $query = $this->buildBaseQuery($filters);
    $nids = $query->execute();
    if (!$nids) {
      return $stats;
    }

    $nodes = $this->entityTypeManagerService->getStorage('node')->loadMultiple($nids);
    foreach ($nodes as $node) {
      $stats['records']++;

      if ($node->hasField('field_appointment_badges') && !$node->get('field_appointment_badges')->isEmpty()) {
        $stats['with_badges']++;
      }

      $purpose = (string) ($node->get('field_appointment_purpose')->value ?? '');
      if ($purpose !== '') {
        $stats['with_purpose']++;
        $stats['purpose_counts'][$purpose] = ($stats['purpose_counts'][$purpose] ?? 0) + 1;
      }

      $result = (string) ($node->get('field_appointment_result')->value ?? '');
      if ($result !== '') {
        $stats['with_result']++;
        $stats['result_counts'][$result] = ($stats['result_counts'][$result] ?? 0) + 1;
      }

      $host_id = (int) ($node->get('field_appointment_host')->target_id ?? 0);
      $stats['facilitator_counts'][$host_id] = ($stats['facilitator_counts'][$host_id] ?? 0) + 1;
    }

    arsort($stats['purpose_counts']);
    arsort($stats['result_counts']);
    arsort($stats['facilitator_counts']);

    return $stats;
  }

  /**
   * Builds a stats section for the filtered feedback set.
   */
  protected function buildStatsSummary(array $stats): array {
    $purpose_labels = $this->getAllowedValues('field_appointment_purpose');
    $result_labels = $this->getAllowedValues('field_appointment_result');
    $facilitator_labels = $this->loadUserLabels(array_keys(array_filter($stats['facilitator_counts'])));

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['appointment-feedback-report-stats']],
      'totals' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Breakdown'),
        '#items' => [
          $this->t('Narratives in filtered set: @count', ['@count' => $stats['records']]),
          $this->t('Appointments with badges selected: @count', ['@count' => $stats['with_badges']]),
          $this->t('Appointments with purpose set: @count', ['@count' => $stats['with_purpose']]),
          $this->t('Appointments with result set: @count', ['@count' => $stats['with_result']]),
        ],
        '#attributes' => ['class' => ['appointment-feedback-report-summary']],
      ],
      'purpose' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Purpose mix'),
        '#items' => $this->buildDistributionItems($stats['purpose_counts'], $purpose_labels),
        '#attributes' => ['class' => ['appointment-feedback-report-summary']],
      ],
      'result' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Result mix'),
        '#items' => $this->buildDistributionItems($stats['result_counts'], $result_labels),
        '#attributes' => ['class' => ['appointment-feedback-report-summary']],
      ],
      'facilitators' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Top facilitators in filtered set'),
        '#items' => $this->buildFacilitatorCountItems($stats['facilitator_counts'], $facilitator_labels),
        '#attributes' => ['class' => ['appointment-feedback-report-summary']],
      ],
    ];
  }

  /**
   * Builds item list strings from keyed counts.
   */
  protected function buildDistributionItems(array $counts, array $labels): array {
    if (!$counts) {
      return [$this->t('None')];
    }

    $items = [];
    foreach (array_slice($counts, 0, 8, TRUE) as $key => $count) {
      $items[] = $this->t('@label: @count', [
        '@label' => $labels[$key] ?? $this->humanizeMachineName($key),
        '@count' => $count,
      ]);
    }
    return $items;
  }

  /**
   * Builds facilitator count items.
   */
  protected function buildFacilitatorCountItems(array $counts, array $labels): array {
    if (!$counts) {
      return [$this->t('None')];
    }

    $items = [];
    foreach (array_slice($counts, 0, 8, TRUE) as $uid => $count) {
      $name = $uid > 0 ? ($labels[$uid] ?? $this->t('User @uid', ['@uid' => $uid])) : $this->t('Unassigned');
      $items[] = $this->t('@name: @count', ['@name' => $name, '@count' => $count]);
    }
    return $items;
  }

  /**
   * Builds the report table.
   */
  protected function buildResultsTable(array $nodes): array {
    if (!$nodes) {
      return [
        '#markup' => '<p>' . $this->t('No appointment feedback matched the current filters.') . '</p>',
      ];
    }

    $rows = [];
    foreach ($nodes as $node) {
      $host = $node->hasField('field_appointment_host') && !$node->get('field_appointment_host')->isEmpty()
        ? $node->get('field_appointment_host')->entity
        : NULL;
      $author = $node->getOwner();
      $purpose = $this->extractFieldLabel($node->get('field_appointment_purpose')->value ?? NULL, 'field_appointment_purpose');
      $result = $this->extractFieldLabel($node->get('field_appointment_result')->value ?? NULL, 'field_appointment_result');
      $feedback = $node->get('field_appointment_feedback')->value ?? '';
      $feedback_markup = nl2br(Html::escape($feedback));
      $badges = $this->buildBadgeList($node);

      $rows[] = [
        'date' => [
          'data' => $this->buildAppointmentDateCell($node),
        ],
        'facilitator' => [
          'data' => $host
            ? Link::fromTextAndUrl($host->getDisplayName(), Url::fromRoute('appointment_facilitator.feedback_report', [], ['query' => ['host' => (int) $host->id()]]))->toRenderable()
            : ['#markup' => $this->t('Unassigned')],
        ],
        'member' => [
          'data' => $author
            ? Link::fromTextAndUrl($author->getDisplayName(), $author->toUrl())->toRenderable()
            : ['#markup' => $this->t('Unknown')],
        ],
        'purpose' => [
          'data' => ['#markup' => $purpose ?: (string) $this->t('Not set')],
        ],
        'result' => [
          'data' => ['#markup' => $result ?: (string) $this->t('Not set')],
        ],
        'badges' => [
          'data' => $badges,
        ],
        'feedback' => [
          'data' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['appointment-feedback-report__narrative']],
            'markup' => [
              '#markup' => Xss::filterAdmin($feedback_markup),
            ],
          ],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['appointment-feedback-report-table-wrapper']],
      'table' => [
        '#type' => 'table',
        '#header' => [
          'date' => $this->t('Appointment date'),
          'facilitator' => $this->t('Facilitator'),
          'member' => $this->t('Member'),
          'purpose' => $this->t('Purpose'),
          'result' => $this->t('Result'),
          'badges' => $this->t('Badges'),
          'feedback' => $this->t('Feedback narrative'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['appointment-feedback-report-table']],
      ],
    ];
  }

  /**
   * Builds a linked appointment date cell.
   */
  protected function buildAppointmentDateCell($node): array {
    $timestamp = NULL;

    if ($node->hasField('field_appointment_timerange') && !$node->get('field_appointment_timerange')->isEmpty()) {
      $timestamp = (int) $node->get('field_appointment_timerange')->value;
    }
    elseif ($node->hasField('field_appointment_date') && !$node->get('field_appointment_date')->isEmpty()) {
      $value = (string) $node->get('field_appointment_date')->value;
      $date = DrupalDateTime::createFromFormat('Y-m-d', $value);
      $timestamp = $date?->getTimestamp();
    }

    $title = $timestamp
      ? $this->dateFormatterService->format($timestamp, 'custom', 'M j, Y g:i a')
      : (string) $this->t('View appointment');

    return Link::fromTextAndUrl($title, $node->toUrl())->toRenderable();
  }

  /**
   * Returns a human-readable option label for an appointment field value.
   */
  protected function extractFieldLabel(?string $value, string $field_name): string {
    if ($value === NULL || $value === '') {
      return '';
    }

    $definitions = $this->entityFieldManagerService->getFieldDefinitions('node', 'appointment');
    $allowed = $definitions[$field_name]?->getSetting('allowed_values') ?? [];
    foreach ($allowed as $item) {
      if (is_array($item) && ($item['value'] ?? NULL) === $value) {
        return (string) ($item['label'] ?? $value);
      }
      if (is_string($item) && $item === $value) {
        return $item;
      }
    }

    return ucwords(str_replace('_', ' ', $value));
  }

  /**
   * Builds a compact badge list.
   */
  protected function buildBadgeList($node): array {
    if (!$node->hasField('field_appointment_badges') || $node->get('field_appointment_badges')->isEmpty()) {
      return ['#markup' => $this->t('—')];
    }

    $items = [];
    foreach ($node->get('field_appointment_badges')->referencedEntities() as $term) {
      $items[] = ['#markup' => Html::escape($term->label())];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['appointment-feedback-report__badges']],
    ];
  }

  /**
   * Loads user labels keyed by uid.
   */
  protected function loadUserLabels(array $uids): array {
    $uids = array_filter(array_map('intval', $uids));
    if (!$uids) {
      return [];
    }

    $accounts = $this->entityTypeManagerService->getStorage('user')->loadMultiple($uids);
    $labels = [];
    foreach ($accounts as $account) {
      $labels[(int) $account->id()] = $account->getDisplayName();
    }
    return $labels;
  }

  /**
   * Returns allowed values for an appointment field.
   */
  protected function getAllowedValues(string $field_name): array {
    $definitions = $this->entityFieldManagerService->getFieldDefinitions('node', 'appointment');
    if (!isset($definitions[$field_name])) {
      return [];
    }

    $allowed = $definitions[$field_name]->getSetting('allowed_values') ?? [];
    $labels = [];
    foreach ($allowed as $item) {
      if (is_array($item) && isset($item['value'])) {
        $labels[$item['value']] = $item['label'] ?? $item['value'];
      }
      elseif (is_string($item)) {
        $labels[$item] = $item;
      }
    }
    return $labels;
  }

  /**
   * Humanizes machine names when labels are unavailable.
   */
  protected function humanizeMachineName(?string $value): string {
    if ($value === NULL || $value === '') {
      return (string) $this->t('Not set');
    }

    return ucwords(str_replace(['_', '-'], ' ', $value));
  }

}
