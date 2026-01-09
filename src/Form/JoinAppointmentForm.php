<?php

namespace Drupal\appointment_facilitator\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a CSRF-protected submit form for joining appointments.
 */
class JoinAppointmentForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $configFactory,
  ) {
    // FormBase already provides $this->configFactory; assign it here instead of
    // redeclaring the property with readonly promotion (which PHP forbids).
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_facilitator_join_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return [];
    }

    if (!$node || $node->bundle() !== 'appointment') {
      $form['message'] = ['#markup' => $this->t('This appointment is unavailable.')];
      return $this->addCacheContexts($form);
    }

    if (!$node->hasField('field_appointment_attendees')) {
      return [];
    }

    $config = $this->configFactory->get('appointment_facilitator.settings');
    $show_always = (bool) $config->get('show_always_join_cta');

    $effective_capacity = appointment_facilitator_effective_capacity($node);
    if (!$show_always && $effective_capacity <= 1) {
      return [];
    }

    $attendee_values = $node->get('field_appointment_attendees')->getValue();
    $current_ids = [];
    foreach ($attendee_values as $value) {
      $current_ids[] = (int) ($value['target_id'] ?? 0);
    }

    $current_ids = array_filter($current_ids, static fn($value) => $value > 0);
    $current_count = count($current_ids);
    $remaining = max(0, $effective_capacity - $current_count);

    $form['#attributes']['class'][] = 'appointment-join-form';
    $form['remaining'] = [
      '#markup' => $this->t('<div><strong>Seats left:</strong> @count</div>', ['@count' => $remaining]),
    ];

    if (in_array((int) $account->id(), $current_ids, TRUE)) {
      $form['message'] = ['#markup' => $this->t('You are already on this appointment.')];
      return $this->addCacheContexts($form);
    }

    if ($remaining <= 0) {
      $form['message'] = ['#markup' => $this->t('This appointment is full.')];
      return $this->addCacheContexts($form);
    }

    $form['node_id'] = [
      '#type' => 'hidden',
      '#value' => $node->id(),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Join this appointment'),
      '#button_type' => 'primary',
    ];

    return $this->addCacheContexts($form);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      $this->messenger()->addError($this->t('You must be logged in to join this appointment.'));
      return;
    }

    $nid = (int) $form_state->getValue('node_id');
    if ($nid <= 0) {
      $this->messenger()->addError($this->t('Unable to determine which appointment to join.'));
      return;
    }

    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'appointment') {
      $this->messenger()->addError($this->t('This appointment is unavailable.'));
      return;
    }

    if (!$node->hasField('field_appointment_attendees')) {
      $this->messenger()->addError($this->t('This appointment does not accept attendees.'));
      return;
    }

    if (!$node->access('view', $this->currentUser(), TRUE)->isAllowed()) {
      $this->messenger()->addError($this->t('You do not have access to this appointment.'));
      return;
    }

    $attendee_field = $node->get('field_appointment_attendees');
    $attendee_values = $attendee_field->getValue();
    foreach ($attendee_values as $value) {
      if ((int) ($value['target_id'] ?? 0) === $uid) {
        $this->messenger()->addStatus($this->t('You are already on this appointment.'));
        $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
        return;
      }
    }

    $capacity = appointment_facilitator_effective_capacity($node);
    $current_count = count($attendee_values);
    if ($current_count >= $capacity) {
      $this->messenger()->addWarning($this->t('This appointment is full.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
      return;
    }

    $attendee_field->appendItem($uid);
    try {
      $node->save();
      $this->messenger()->addStatus($this->t('You have joined this appointment.'));
    }
    catch (\Exception $e) {
      $this->getLogger('appointment_facilitator')->error('Failed to add attendee to appointment @id: @error', [
        '@id' => $node->id(),
        '@error' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Unable to join the appointment. Please try again.'));
    }

    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Adds cache contexts so per-user content stays distinct.
   */
  protected function addCacheContexts(array $form): array {
    $contexts = $form['#cache']['contexts'] ?? [];
    $contexts[] = 'user';
    $contexts[] = 'url.path';
    $form['#cache']['contexts'] = array_values(array_unique($contexts));
    return $form;
  }

}
