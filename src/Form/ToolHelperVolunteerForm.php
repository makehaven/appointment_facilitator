<?php

namespace Drupal\appointment_facilitator\Form;

use Drupal\appointment_facilitator\ToolHelpers\ToolHelpers;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The "I can answer questions about this tool" toggle on a tool page.
 *
 * Members who hold the tool's badge (or any member, when it needs none) can
 * add themselves to People to ask. Staff can remove anyone on the staff tab.
 */
class ToolHelperVolunteerForm extends FormBase {

  public function __construct(
    protected ToolHelpers $helpers,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('appointment_facilitator.tool_helpers'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_facilitator_tool_helper_volunteer';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $tool = NULL, bool $listed = FALSE): array {
    $form_state->set('tool_id', $tool ? (int) $tool->id() : 0);
    $form['#attributes']['class'][] = 'mh-tool-helpers__volunteer';
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $listed ? $this->t('Take me off this list') : $this->t('I can answer questions about this tool'),
      '#attributes' => ['class' => [$listed ? 'btn-link' : 'btn-outline-secondary', 'btn', 'btn-sm']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $tool = $this->entityTypeManager->getStorage('node')->load((int) $form_state->get('tool_id'));
    $account = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser()->id());
    if (!$tool instanceof NodeInterface || !$account instanceof UserInterface || !$this->helpers->applies($tool)) {
      return;
    }
    $uid = (int) $account->id();
    $current = array_map(static fn(array $v): int => (int) $v['target_id'], $tool->get(ToolHelpers::FIELD)->getValue());
    if (in_array($uid, $current, TRUE)) {
      $tool->set(ToolHelpers::FIELD, array_values(array_diff($current, [$uid])));
      $note = 'Removed themselves from People to ask';
      $this->messenger()->addStatus($this->t('You are no longer listed for @tool.', ['@tool' => $tool->label()]));
    }
    elseif ($this->helpers->canVolunteer($tool, $account)) {
      $current[] = $uid;
      $tool->set(ToolHelpers::FIELD, $current);
      $note = 'Added themselves to People to ask';
      $this->messenger()->addStatus($this->t('Thanks! You are now listed for @tool. Members may tag you in Slack with questions about it.', ['@tool' => $tool->label()]));
    }
    else {
      $this->messenger()->addWarning($this->t('Only members with this tool’s badge can add themselves.'));
      return;
    }
    if ($tool->getEntityType()->isRevisionable()) {
      $tool->setNewRevision(TRUE);
      $tool->setRevisionUserId($uid);
      $tool->setRevisionLogMessage($note . ' (' . $account->getDisplayName() . ').');
    }
    $tool->save();
    Cache::invalidateTags($tool->getCacheTags());
    $form_state->setRedirect('entity.node.canonical', ['node' => $tool->id()], ['fragment' => 'who-can-help']);
  }

}
