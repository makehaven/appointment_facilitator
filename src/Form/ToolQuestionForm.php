<?php

namespace Drupal\appointment_facilitator\Form;

use Drupal\appointment_facilitator\ToolHelpers\ToolHelpers;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The "Ask on Slack" form on a tool page: posts the question in the tool's channel.
 */
class ToolQuestionForm extends FormBase {

  /**
   * Questions one member may post per hour.
   */
  const HOURLY_LIMIT = 5;

  public function __construct(
    protected ToolHelpers $helpers,
    protected FloodInterface $flood,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('appointment_facilitator.tool_helpers'),
      $container->get('flood'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_facilitator_tool_question';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $tool = NULL): array {
    $form_state->set('tool_id', $tool ? (int) $tool->id() : 0);
    $channel = $tool ? $this->helpers->channel($tool) : '';
    $form['#attributes']['class'][] = 'mh-tool-helpers__ask';
    $form['question'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Your question'),
      '#title_display' => 'invisible',
      '#placeholder' => $this->t('e.g. Which mic should I use to record a podcast with two people?'),
      '#rows' => 3,
      '#maxlength' => 2000,
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Ask in #@channel', ['@channel' => $channel]),
      '#button_type' => 'primary',
    ];
    $form['note'] = [
      '#weight' => 100,
      '#markup' => '<p class="mh-tool-helpers__fine">' . $this->t('Posted publicly in #@channel under your name, tagging the people above. Replies come to you in Slack.', ['@channel' => $channel]) . '</p>',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (mb_strlen(trim((string) $form_state->getValue('question'))) < 10) {
      $form_state->setErrorByName('question', $this->t('Please say a little more so people can help.'));
    }
    if (!$this->flood->isAllowed('appointment_facilitator.tool_question', self::HOURLY_LIMIT, 3600, 'user:' . $this->currentUser()->id())) {
      $form_state->setErrorByName('question', $this->t('You have asked several questions in the last hour. Please wait a little, or post directly in Slack.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $tool = $this->entityTypeManager->getStorage('node')->load((int) $form_state->get('tool_id'));
    $asker = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser()->id());
    if (!$tool instanceof NodeInterface || !$asker instanceof UserInterface) {
      return;
    }
    $channel = $this->helpers->channel($tool);
    try {
      $result = $this->helpers->ask($tool, $asker, trim((string) $form_state->getValue('question')));
      $this->flood->register('appointment_facilitator.tool_question', 3600, 'user:' . $asker->id());
      $message = $result['tagged']
        ? $this->t('Posted in #@channel and tagged @count people who know this tool. Replies will come to you in Slack.', [
          '@channel' => $channel,
          '@count' => count($result['tagged']),
        ])
        : $this->t('Posted in #@channel. Replies will come to you in Slack.', [
          '@channel' => $channel,
        ]);
      if ($result['permalink'] !== '') {
        $message = $this->t('@message <a href=":url">See it in Slack</a>.', [
          '@message' => $message,
          ':url' => $result['permalink'],
        ]);
      }
      $this->messenger()->addStatus($message);
    }
    catch (\Throwable $e) {
      $this->logger('appointment_facilitator')->error('Tool question on @nid failed: @error', [
        '@nid' => $tool->id(),
        '@error' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('We could not post your question. Please ask in <a href=":url">#@channel</a> directly.', [
        ':url' => $this->helpers->channelUrl($channel),
        '@channel' => $channel,
      ]));
    }
    $form_state->setRedirectUrl(Url::fromRoute('entity.node.canonical', ['node' => $tool->id()], ['fragment' => 'who-can-help']));
  }

}
