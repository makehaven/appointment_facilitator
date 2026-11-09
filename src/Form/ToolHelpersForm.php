<?php

namespace Drupal\appointment_facilitator\Form;

use Drupal\appointment_facilitator\ToolHelpers\ToolHelpers;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Staff tab on a tool: who members should ask, with suggestions.
 */
class ToolHelpersForm extends FormBase {

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
    return 'appointment_facilitator_tool_helpers';
  }

  /**
   * Route access: tools only, for people who manage tool helpers.
   */
  public static function access(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    $applies = $node->bundle() === 'item' && $node->hasField(ToolHelpers::FIELD);
    return AccessResult::allowedIf($applies)
      ->andIf(AccessResult::allowedIfHasPermission($account, 'manage tool helpers'))
      ->addCacheableDependency($node);
  }

  /**
   * Page title.
   */
  public function title(NodeInterface $node): string {
    return (string) $this->t('People to ask about @tool', ['@tool' => $node->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $form_state->set('tool_id', (int) $node->id());
    $listed = $this->helpers->listed($node);
    $slack = $this->helpers->slackIds(array_keys($listed));

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('These people appear under “Who can help” on the tool page, and are tagged first when a member asks about it on Slack (up to three, rotating). Volunteers come first: staff are tagged only when no one else can be. Anyone with an account can be listed. Members show a Member label on the tool page.') . '</p>',
    ];

    if ($node->hasField('field_question_contact') && !$node->get('field_question_contact')->isEmpty()) {
      $form['old'] = [
        '#markup' => '<p class="messages messages--warning">' . $this->t('Old free-text question contact: “@name”. Add the matching person below, then clear it on the tool’s edit form.', ['@name' => $node->get('field_question_contact')->value]) . '</p>',
      ];
    }

    $form['people'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('People to ask'),
      '#description' => $this->t('Start typing a name, pick from the list, and separate people with commas.'),
      '#target_type' => 'user',
      '#tags' => TRUE,
      '#default_value' => array_values($listed),
      '#selection_settings' => ['include_anonymous' => FALSE],
      '#maxlength' => 2048,
    ];

    if ($listed) {
      $rows = [];
      foreach ($listed as $uid => $account) {
        $rows[] = [
          $account->getDisplayName(),
          $account->hasRole('member') ? $this->t('Member') : $this->t('Not a current member'),
          isset($slack[$uid]) ? $this->t('Yes') : $this->t('No: cannot be tagged'),
          $this->helpers->isStaff($account) ? $this->t('Staff: tagged last') : '',
        ];
      }
      $form['current'] = [
        '#type' => 'table',
        '#caption' => $this->t('Listed now'),
        '#header' => [$this->t('Name'), $this->t('Type'), $this->t('On Slack'), ''],
        '#rows' => $rows,
      ];
    }

    $suggestions = $this->helpers->suggestions($node);
    if ($suggestions) {
      $accounts = $this->entityTypeManager->getStorage('user')->loadMultiple(array_keys($suggestions));
      $suggestion_slack = $this->helpers->slackIds(array_keys($suggestions));
      $options = [];
      foreach ($suggestions as $uid => $suggestion) {
        $account = $accounts[$uid] ?? NULL;
        if (!$account instanceof UserInterface) {
          continue;
        }
        $options[$uid] = [
          'name' => $account->toLink($account->getDisplayName())->toString(),
          'why' => implode('; ', $suggestion['reasons']),
          'member' => $account->hasRole('member') ? $this->t('Member') : $this->t('Not a current member'),
          'slack' => isset($suggestion_slack[$uid]) ? $this->t('Yes') : $this->t('No'),
        ];
      }
      $form['suggested'] = [
        '#type' => 'tableselect',
        '#caption' => $this->t('Suggested: tick anyone to add. Ask them first if you can.'),
        '#header' => [
          'name' => $this->t('Name'),
          'why' => $this->t('Why'),
          'member' => $this->t('Type'),
          'slack' => $this->t('On Slack'),
        ],
        '#options' => $options,
        '#empty' => $this->t('No suggestions.'),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $this->entityTypeManager->getStorage('node')->load((int) $form_state->get('tool_id'));
    if (!$node instanceof NodeInterface) {
      return;
    }
    $uids = [];
    foreach ((array) $form_state->getValue('people') as $item) {
      $uid = (int) (is_array($item) ? ($item['target_id'] ?? 0) : $item);
      if ($uid > 0) {
        $uids[] = $uid;
      }
    }
    foreach (array_filter((array) $form_state->getValue('suggested')) as $uid) {
      $uids[] = (int) $uid;
    }
    $uids = array_values(array_unique($uids));
    $node->set(ToolHelpers::FIELD, $uids);
    if ($node->getEntityType()->isRevisionable()) {
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId((int) $this->currentUser()->id());
      $node->setRevisionLogMessage('Updated People to ask.');
    }
    $node->save();
    $this->messenger()->addStatus($this->formatPlural(count($uids), '1 person listed for @tool.', '@count people listed for @tool.', ['@tool' => $node->label()]));
    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()], ['fragment' => 'who-can-help']);
  }

}
