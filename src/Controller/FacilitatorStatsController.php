<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\appointment_facilitator\Service\AppointmentStats;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides self-serve stats for facilitators.
 */
class FacilitatorStatsController extends ControllerBase {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManagerService,
    protected readonly EntityFieldManagerInterface $entityFieldManagerService,
    protected readonly DateFormatterInterface $dateFormatterService,
    protected readonly AppointmentStats $statsHelper,
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
    );
  }

  /**
   * Displays facilitator self stats.
   */
  public function overview(): array {
    $account = $this->currentUser();
    $uid = (int) $account->id();
    $user = $this->entityTypeManagerService->getStorage('user')->load($uid);

    $summary = $this->statsHelper->summarize(NULL, NULL, [
      'host_id' => $uid,
      'include_cancelled' => TRUE,
      'use_facilitator_terms' => TRUE,
    ]);
    $overall = $this->statsHelper->summarize(NULL, NULL, [
      'include_cancelled' => TRUE,
      'use_facilitator_terms' => TRUE,
    ]);

    $facilitator = $summary['facilitators'][$uid] ?? $this->buildEmptyFacilitatorRow($uid);
    $term = $this->statsHelper->getFacilitatorTermRange($uid);

    $summary_rows = [
      [$this->t('Appointments hosted'), $facilitator['appointments']],
      [$this->t('Attendees served'), $facilitator['attendees']],
      [$this->t('Badge sessions'), $facilitator['badge_sessions']],
      [$this->t('Badges selected'), $facilitator['badges']],
      [$this->t('Cancelled appointments'), $facilitator['cancelled']],
      [
        $this->t('Arrival days'),
        $facilitator['arrival_days'] === NULL
          ? $this->t('—')
          : $this->t('@count of @total', [
            '@count' => $facilitator['arrival_days'],
            '@total' => $facilitator['appointment_day_count'],
          ]),
      ],
      [
        $this->t('Arrival coverage'),
        $this->formatPercent($facilitator['arrival_rate']),
      ],
      [
        $this->t('Arrival status mix'),
        $this->formatArrivalStatusCounts(
          $facilitator['arrival_status_counts'] ?? [],
          $this->getAllowedValues('field_facilitator_arrival_status'),
        ),
      ],
      [
        $this->t('Evaluation completion'),
        $this->t('@rate (@count)', [
          '@rate' => $this->formatPercent($facilitator['feedback_rate']),
          '@count' => $facilitator['feedback'],
        ]),
      ],
      [$this->t('Appointments per week'), $this->formatRate($facilitator['appointments_per_week'])],
      [$this->t('Appointments per month'), $this->formatRate($facilitator['appointments_per_month'])],
    ];

    $term_value = $this->t('Not set');
    if ($term) {
      $term_value = $this->t('@start to @end', [
        '@start' => $this->dateFormatterService->format($term['start']->getTimestamp(), 'custom', 'M j, Y'),
        '@end' => $this->dateFormatterService->format($term['end']->getTimestamp(), 'custom', 'M j, Y'),
      ]);
    }

    $comparison_rows = [
      [
        $this->t('Org average per week'),
        $this->formatRate($overall['facilitator_rate_averages']['appointments_per_week'] ?? NULL),
      ],
      [
        $this->t('Org average per month'),
        $this->formatRate($overall['facilitator_rate_averages']['appointments_per_month'] ?? NULL),
      ],
      [
        $this->t('Org average evaluation completion'),
        $this->formatPercent($overall['facilitator_rate_averages']['feedback_rate'] ?? NULL),
      ],
      [
        $this->t('Org average arrival coverage'),
        $this->formatPercent($overall['facilitator_rate_averages']['arrival_rate'] ?? NULL),
      ],
    ];

    $title = $user ? $this->t('Facilitator stats for @name', ['@name' => $user->getDisplayName()]) : $this->t('Facilitator stats');

    return [
      '#type' => 'container',
      '#title' => $title,
      '#attributes' => ['class' => ['appointment-facilitator-self-stats']],
      'summary' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Your term-to-date summary'),
      ],
      'summary_table' => [
        '#type' => 'table',
        '#header' => [$this->t('Metric'), $this->t('Value')],
        '#rows' => $summary_rows,
        '#attributes' => ['class' => ['appointment-facilitator-summary-table']],
      ],
      'term' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Term range'),
      ],
      'term_table' => [
        '#type' => 'table',
        '#header' => [$this->t('Metric'), $this->t('Value')],
        '#rows' => [
          [$this->t('Current or most recent term'), $term_value],
        ],
        '#attributes' => ['class' => ['appointment-facilitator-term-table']],
      ],
      'comparisons' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Org comparisons'),
      ],
      'comparisons_table' => [
        '#type' => 'table',
        '#header' => [$this->t('Metric'), $this->t('Value')],
        '#rows' => $comparison_rows,
        '#attributes' => ['class' => ['appointment-facilitator-comparison-table']],
      ],
    ];
  }

  protected function buildEmptyFacilitatorRow(int $uid): array {
    return [
      'uid' => $uid,
      'appointments' => 0,
      'badge_sessions' => 0,
      'badges' => 0,
      'attendees' => 0,
      'feedback' => 0,
      'feedback_rate' => 0,
      'purpose_counts' => [],
      'result_counts' => [],
      'status_counts' => [],
      'badges_breakdown' => [],
      'latest' => NULL,
      'cancelled' => 0,
      'appointment_day_count' => 0,
      'arrival_days' => NULL,
      'arrival_rate' => NULL,
      'arrival_status_counts' => [],
      'term_start' => NULL,
      'term_end' => NULL,
      'term_elapsed_weeks' => NULL,
      'term_elapsed_months' => NULL,
      'appointments_per_week' => NULL,
      'appointments_per_month' => NULL,
    ];
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

  protected function formatArrivalStatusCounts(array $counts, array $labels): string {
    if (!$counts) {
      return (string) $this->t('—');
    }
    arsort($counts);
    $parts = [];
    foreach ($counts as $key => $value) {
      $label = $labels[$key] ?? NULL;
      if ($key === '_none' || $key === NULL || $key === '') {
        $label = $this->t('Not set');
      }
      elseif ($label === NULL) {
        $label = $this->humanizeMachineName($key);
      }
      $parts[] = $this->t('@count @label', ['@label' => $label, '@count' => $value]);
    }
    return implode(', ', $parts);
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

  protected function humanizeMachineName(?string $value): string {
    if ($value === NULL || $value === '' || $value === '_none') {
      return (string) $this->t('Not set');
    }
    $value = str_replace(['_', '-'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', trim($value));
    return ucwords($value);
  }

}
