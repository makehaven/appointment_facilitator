<?php

namespace Drupal\appointment_facilitator\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an appointment feedback form.
 */
class AppointmentFeedbackForm extends FormBase {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new AppointmentFeedbackForm object.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(RouteMatchInterface $route_match) {
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'appointment_feedback_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL) {
    if (!$node || $node->bundle() !== 'appointment') {
      $this->messenger()->addError($this->t('The provided node is not an appointment.'));
      return [];
    }

    $current_uid = (int) $this->currentUser()->id();
    $owner_uid = (int) $node->getOwnerId();
    $host_uid = 0;
    if ($node->hasField('field_appointment_host') && !$node->get('field_appointment_host')->isEmpty()) {
      $host_uid = (int) $node->get('field_appointment_host')->target_id;
    }

    $can_access = $this->currentUser()->hasPermission('administer nodes')
      || ($current_uid > 0 && $current_uid === $owner_uid)
      || ($current_uid > 0 && $current_uid === $host_uid);

    if (!$can_access) {
      $this->messenger()->addError($this->t('You do not have access to submit feedback for this appointment.'));
      return [];
    }

    $form_state->set('node', $node);
    $form['#attached']['library'][] = 'appointment_facilitator/appointment_feedback_form';
    $form['appointment_details'] = $this->buildAppointmentDetails($node);

    $result_options = $this->getAppointmentResultOptions($node);
    if ($node->hasField('field_appointment_result') && !empty($result_options)) {
      $form['appointment_result'] = [
        '#type' => 'radios',
        '#title' => $this->t('Appointment Result'),
        '#required' => TRUE,
        '#options' => $result_options,
        '#default_value' => $node->get('field_appointment_result')->value ?? NULL,
      ];
    }

    $form['feedback'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Please provide your feedback for the appointment.'),
      '#required' => TRUE,
      '#description' => $this->t('Your feedback helps us improve our program. Please note that the narrative feedback you provide will be shared with the facilitator to help them improve. To protect your privacy, this data is shared without your name and with a delay, making it less likely that you can be identified.'),
      '#default_value' => $node->get('field_appointment_feedback')->value ?? '',
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit Feedback'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Builds a quick appointment summary for the feedback page.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The appointment node.
   *
   * @return array
   *   A render array.
   */
  protected function buildAppointmentDetails(NodeInterface $node): array {
    $host_name = $this->getHostSummary($node);
    $host_photo_url = $this->getHostPhotoUrl($node);

    $host_photo = [
      '#type' => 'container',
      '#attributes' => ['class' => ['appointment-feedback-summary__photo-wrap']],
      'placeholder' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['appointment-feedback-summary__photo-placeholder']],
        '#value' => strtoupper(substr($host_name, 0, 1)),
      ],
    ];

    if ($host_photo_url !== '') {
      $host_photo = [
        '#type' => 'html_tag',
        '#tag' => 'img',
        '#attributes' => [
          'class' => ['appointment-feedback-summary__photo'],
          'src' => $host_photo_url,
          'alt' => $this->t('Photo of @name', ['@name' => $host_name]),
          'loading' => 'lazy',
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['appointment-feedback-summary']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Appointment details'),
        '#attributes' => ['class' => ['appointment-feedback-summary__title']],
      ],
      'content' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['appointment-feedback-summary__content']],
        'photo' => $host_photo,
        'fields' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['appointment-feedback-summary__fields']],
          'requested_badges' => $this->buildDetailRow(
            (string) $this->t('Requested badges'),
            $this->getRequestedBadgesSummary($node)
          ),
          'subject' => $this->buildDetailRow(
            (string) $this->t('Subject'),
            $this->getSubjectSummary($node)
          ),
          'date' => $this->buildDetailRow(
            (string) $this->t('Date'),
            $this->getAppointmentDateSummary($node)
          ),
          'host' => $this->buildDetailRow(
            (string) $this->t('Host'),
            $host_name
          ),
          'notes' => $this->buildDetailRow(
            (string) $this->t('Notes'),
            $this->getNotesSummary($node)
          ),
        ],
      ],
    ];
  }

  /**
   * Builds one label/value row for appointment detail rendering.
   */
  protected function buildDetailRow(string $label, string $value): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['appointment-feedback-summary__row']],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $label,
        '#attributes' => ['class' => ['appointment-feedback-summary__label']],
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $value,
        '#attributes' => ['class' => ['appointment-feedback-summary__value']],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $form_state->get('node');
    $feedback = $form_state->getValue('feedback');

    if ($node->hasField('field_appointment_feedback')) {
      $node->set('field_appointment_feedback', $feedback);
      if ($node->hasField('field_appointment_result')) {
        $node->set('field_appointment_result', $form_state->getValue('appointment_result'));
      }
      $node->save();
      $this->messenger()->addStatus($this->t('Thank you for your feedback!'));
    }
    else {
      $this->messenger()->addError($this->t('The appointment node does not have the feedback field.'));
    }

    $form_state->setRedirect('<front>');
  }

  /**
   * Returns configured appointment result options for the result field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The appointment node.
   *
   * @return array<string, string>
   *   Key-value options for a radios/select element.
   */
  protected function getAppointmentResultOptions(NodeInterface $node): array {
    if (!$node->hasField('field_appointment_result')) {
      return [];
    }

    $raw_options = $node->getFieldDefinition('field_appointment_result')->getSetting('allowed_values') ?? [];
    $options = [];
    foreach ($raw_options as $key => $value) {
      if (is_array($value) && isset($value['value'], $value['label'])) {
        $options[(string) $value['value']] = strip_tags((string) $value['label']);
        continue;
      }
      $options[(string) $key] = strip_tags((string) $value);
    }

    return $options;
  }

  /**
   * Builds a summary string for requested badges.
   */
  protected function getRequestedBadgesSummary(NodeInterface $node): string {
    if (!$node->hasField('field_appointment_badges') || $node->get('field_appointment_badges')->isEmpty()) {
      return (string) $this->t('None selected');
    }

    $labels = [];
    foreach ($node->get('field_appointment_badges')->referencedEntities() as $badge) {
      $labels[] = $badge->label();
    }

    return !empty($labels) ? implode(', ', $labels) : (string) $this->t('None selected');
  }

  /**
   * Builds a summary string for subject/purpose.
   */
  protected function getSubjectSummary(NodeInterface $node): string {
    if ($node->hasField('field_appointment_purpose') && !$node->get('field_appointment_purpose')->isEmpty()) {
      $purpose_value = (string) $node->get('field_appointment_purpose')->value;
      $allowed_values = $node->getFieldDefinition('field_appointment_purpose')->getSetting('allowed_values') ?? [];

      if (isset($allowed_values[$purpose_value])) {
        $label = $allowed_values[$purpose_value];
        if (is_array($label) && isset($label['label'])) {
          return strip_tags((string) $label['label']);
        }
        return strip_tags((string) $label);
      }

      if ($purpose_value !== '') {
        return $purpose_value;
      }
    }

    $title = trim((string) $node->label());
    return $title !== '' ? $title : (string) $this->t('Not specified');
  }

  /**
   * Builds a summary string for host.
   */
  protected function getHostSummary(NodeInterface $node): string {
    if ($node->hasField('field_appointment_host') && !$node->get('field_appointment_host')->isEmpty()) {
      $host = $node->get('field_appointment_host')->entity;
      if ($host) {
        return $host->label();
      }
    }

    return (string) $this->t('Not assigned');
  }

  /**
   * Returns the host photo URL from the host's main profile, when available.
   */
  protected function getHostPhotoUrl(NodeInterface $node): string {
    if (!$node->hasField('field_appointment_host') || $node->get('field_appointment_host')->isEmpty()) {
      return '';
    }

    $host = $node->get('field_appointment_host')->entity;
    if (!$host) {
      return '';
    }

    $profile_storage = \Drupal::entityTypeManager()->getStorage('profile');
    $profiles = $profile_storage->loadByProperties([
      'uid' => $host->id(),
      'type' => 'main',
      'is_default' => 1,
      'status' => 1,
    ]);
    if (empty($profiles)) {
      $profiles = $profile_storage->loadByProperties([
        'uid' => $host->id(),
        'type' => 'main',
      ]);
    }
    if (empty($profiles)) {
      return '';
    }

    $profile = reset($profiles);
    if (!$profile->hasField('field_member_photo') || $profile->get('field_member_photo')->isEmpty()) {
      return '';
    }

    $file = $profile->get('field_member_photo')->entity;
    if (!$file) {
      return '';
    }

    if (\Drupal::moduleHandler()->moduleExists('image')) {
      if (ImageStyle::load('member_photo')) {
        return ImageStyle::load('member_photo')->buildUrl($file->getFileUri());
      }
      if (ImageStyle::load('thumbnail')) {
        return ImageStyle::load('thumbnail')->buildUrl($file->getFileUri());
      }
    }

    return \Drupal::service('file_url_generator')->generateString($file->getFileUri());
  }

  /**
   * Builds a summary string for appointment day/date.
   */
  protected function getAppointmentDateSummary(NodeInterface $node): string {
    if ($node->hasField('field_appointment_timerange') && !$node->get('field_appointment_timerange')->isEmpty()) {
      $timestamp = (int) $node->get('field_appointment_timerange')->value;
      if ($timestamp > 0) {
        return \Drupal::service('date.formatter')->format($timestamp, 'custom', 'l, F j, Y');
      }
    }

    if ($node->hasField('field_appointment_date') && !$node->get('field_appointment_date')->isEmpty()) {
      $date_value = (string) $node->get('field_appointment_date')->value;
      $date = DrupalDateTime::createFromFormat('Y-m-d', $date_value);
      if ($date instanceof DrupalDateTime) {
        return $date->format('l, F j, Y');
      }
    }

    return (string) $this->t('Not specified');
  }

  /**
   * Builds a summary string for appointment notes.
   */
  protected function getNotesSummary(NodeInterface $node): string {
    if ($node->hasField('field_appointment_note') && !$node->get('field_appointment_note')->isEmpty()) {
      $note = trim((string) $node->get('field_appointment_note')->value);
      if ($note !== '') {
        return $note;
      }
    }

    return (string) $this->t('None');
  }

}
