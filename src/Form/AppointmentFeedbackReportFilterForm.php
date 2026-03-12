<?php

namespace Drupal\appointment_facilitator\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter form for the appointment feedback narrative report.
 */
class AppointmentFeedbackReportFilterForm extends FormBase {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManagerService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_facilitator_feedback_report_filter';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $filters = []): array {
    $form['#method'] = 'get';
    $form['#attributes']['class'][] = 'appointment-feedback-report-filter';
    $purpose_options = ['all' => $this->t('All purposes')] + ($filters['purpose_options'] ?? []);
    $result_options = ['all' => $this->t('All results')] + ($filters['result_options'] ?? []);

    $host_default = NULL;
    if (!empty($filters['host'])) {
      $host_default = $this->entityTypeManagerService->getStorage('user')->load((int) $filters['host']);
    }

    $form['host'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Facilitator'),
      '#target_type' => 'user',
      '#selection_settings' => [
        'include_anonymous' => FALSE,
      ],
      '#default_value' => $host_default,
      '#description' => $this->t('Filter to one facilitator. Leave blank to see all facilitators.'),
    ];

    $form['start'] = [
      '#type' => 'date',
      '#title' => $this->t('Start date'),
      '#default_value' => $filters['start'] ?? '',
    ];

    $form['end'] = [
      '#type' => 'date',
      '#title' => $this->t('End date'),
      '#default_value' => $filters['end'] ?? '',
    ];

    $form['purpose'] = [
      '#type' => 'select',
      '#title' => $this->t('Purpose'),
      '#options' => $purpose_options,
      '#default_value' => $filters['purpose'] ?? 'all',
    ];

    $form['result'] = [
      '#type' => 'select',
      '#title' => $this->t('Result'),
      '#options' => $result_options,
      '#default_value' => $filters['result'] ?? 'all',
    ];

    $form['keywords'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Feedback contains'),
      '#default_value' => $filters['keywords'] ?? '',
      '#size' => 32,
      '#description' => $this->t('Search within the feedback narrative.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
      '#button_type' => 'primary',
    ];
    $form['items_per_page'] = [
      '#type' => 'select',
      '#title' => $this->t('Rows per page'),
      '#options' => [
        50 => '50',
        100 => '100',
        250 => '250',
      ],
      '#default_value' => $filters['items_per_page'] ?? 100,
    ];
    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => \Drupal\Core\Url::fromRoute('appointment_facilitator.feedback_report'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $params = [];

    $host = $form_state->getValue('host');
    if (is_array($host) && !empty($host[0]['target_id'])) {
      $params['host'] = (int) $host[0]['target_id'];
    }
    elseif (is_numeric($host) && (int) $host > 0) {
      $params['host'] = (int) $host;
    }

    $start = trim((string) $form_state->getValue('start'));
    $end = trim((string) $form_state->getValue('end'));
    $purpose = (string) $form_state->getValue('purpose');
    $result = (string) $form_state->getValue('result');
    $keywords = trim((string) $form_state->getValue('keywords'));
    $items_per_page = (int) $form_state->getValue('items_per_page');

    if ($start !== '') {
      $params['start'] = $start;
    }
    if ($end !== '') {
      $params['end'] = $end;
    }
    if ($purpose !== '' && $purpose !== 'all') {
      $params['purpose'] = $purpose;
    }
    if ($result !== '' && $result !== 'all') {
      $params['result'] = $result;
    }
    if ($keywords !== '') {
      $params['keywords'] = $keywords;
    }
    if (in_array($items_per_page, [50, 100, 250], TRUE) && $items_per_page !== 100) {
      $params['items_per_page'] = $items_per_page;
    }

    $form_state->setRedirect('appointment_facilitator.feedback_report', [], ['query' => $params]);
  }

}
