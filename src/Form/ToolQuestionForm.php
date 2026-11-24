<?php

namespace Drupal\appointment_facilitator\Form;

use Drupal\appointment_facilitator\ToolHelpers\ToolHelpers;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use League\CommonMark\CommonMarkConverter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The question box on a tool page: check what is known, then ask on Slack.
 *
 * JR 11-24: members should not be sent straight to Slack without checking
 * what has already been said. The first button asks the tool page's assistant
 * (manual, status, approved answers harvested from the channel) and lists
 * earlier questions about this tool that look the same, with their Slack
 * threads. Members who turned AI off (field_hide_ai_chatbots) get only the
 * earlier questions. Posting in the channel is offered only after that.
 */
class ToolQuestionForm extends FormBase {

  /**
   * Questions one member may post per hour.
   */
  const HOURLY_LIMIT = 5;

  /**
   * Checks one member may run per hour (each is an AI call).
   */
  const HOURLY_CHECKS = 20;

  public function __construct(
    protected ToolHelpers $helpers,
    protected FloodInterface $flood,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?object $toolAnswers = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('appointment_facilitator.tool_helpers'),
      $container->get('flood'),
      $container->get('entity_type.manager'),
      $container->has('makerspace_ai_tool_context.tool_answer_fallback') ? $container->get('makerspace_ai_tool_context.tool_answer_fallback') : NULL,
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
    if ($tool) {
      $form_state->set('tool_id', (int) $tool->id());
    }
    $tool_id = (int) $form_state->get('tool_id');
    $tool = $tool ?: $this->entityTypeManager->getStorage('node')->load($tool_id);
    $channel = $tool instanceof NodeInterface ? $this->helpers->channel($tool) : '';
    $wrapper = 'mh-tool-question-' . $tool_id;
    $form['#prefix'] = '<div id="' . $wrapper . '">';
    $form['#suffix'] = '</div>';
    $form['#attributes']['class'][] = 'mh-tool-helpers__ask';
    $ajax = [
      'callback' => '::ajaxRefresh',
      'wrapper' => $wrapper,
      'progress' => [
        'type' => 'throbber',
        'message' => $this->t('Checking…'),
      ],
    ];

    if ($form_state->get('answered')) {
      $form['done'] = [
        '#markup' => '<p class="mh-tool-helpers__found-lead">' . $this->t('Glad that helped.') . '</p>',
      ];
      $form['again'] = [
        '#type' => 'submit',
        '#value' => $this->t('Ask something else'),
        '#submit' => ['::resetSubmit'],
        '#limit_validation_errors' => [],
        '#ajax' => $ajax,
        '#attributes' => ['class' => ['btn-link', 'mh-tool-helpers__linkbutton']],
      ];
      return $form;
    }

    $checked = (bool) $form_state->get('checked');
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

    if (!$checked) {
      $form['actions']['check'] = [
        '#type' => 'submit',
        '#value' => $this->t('Check for an answer'),
        '#button_type' => 'primary',
        '#submit' => ['::checkSubmit'],
        '#ajax' => $ajax,
      ];
      $form['note'] = [
        '#weight' => 100,
        '#markup' => '<p class="mh-tool-helpers__fine">' . ($this->aiAllowed()
          ? $this->t('We look in this tool’s manual and earlier answers first. If that does not answer it, you can post it in #@channel.', ['@channel' => $channel])
          : $this->t('We look at earlier questions about this tool first. If none answers it, you can post it in #@channel.', ['@channel' => $channel])) . '</p>',
      ];
      return $form;
    }

    $form['found'] = [
      '#weight' => -10,
      '#markup' => $this->foundMarkup((string) $form_state->get('answer'), (array) $form_state->get('earlier')),
    ];
    $found_something = $form_state->get('answer') !== '' || $form_state->get('earlier');
    if ($found_something) {
      $form['actions']['answered'] = [
        '#type' => 'submit',
        '#value' => $this->t('That answers it'),
        '#button_type' => 'primary',
        '#submit' => ['::answeredSubmit'],
        '#limit_validation_errors' => [],
        '#ajax' => $ajax,
      ];
    }
    $form['actions']['post'] = [
      '#type' => 'submit',
      '#value' => $found_something
        ? $this->t('Still need a person: post in #@channel', ['@channel' => $channel])
        : $this->t('Post in #@channel', ['@channel' => $channel]),
      '#button_type' => $found_something ? '' : 'primary',
      '#attributes' => ['class' => $found_something ? ['btn-outline-primary'] : []],
    ];
    $form['note'] = [
      '#weight' => 100,
      '#markup' => '<p class="mh-tool-helpers__fine">' . $this->t('Posted publicly in #@channel under your name, tagging up to three people who know this tool. Replies come to you in Slack.', ['@channel' => $channel]) . '</p>',
    ];
    return $form;
  }

  /**
   * Whether the viewer allows AI answers (has not turned AI off).
   */
  protected function aiAllowed(): bool {
    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser()->id());
    if (!$user instanceof UserInterface || !$user->hasField('field_hide_ai_chatbots')) {
      return TRUE;
    }
    return !$user->get('field_hide_ai_chatbots')->value;
  }

  /**
   * What the check found, as HTML.
   */
  protected function foundMarkup(string $answer, array $earlier): string {
    if ($answer === '' && !$earlier) {
      $none = $this->aiAllowed()
        ? $this->t('Nothing in the manual or earlier questions answers this. Post it below and someone who knows the tool can help.')
        : $this->t('Nobody has asked this here before. Post it below and someone who knows the tool can help.');
      return '<div class="mh-tool-helpers__found"><p class="mh-tool-helpers__found-lead">' . $none . '</p></div>';
    }
    $html = '<div class="mh-tool-helpers__found">';
    if ($answer !== '') {
      $converter = new CommonMarkConverter(['html_input' => 'escape', 'allow_unsafe_links' => FALSE]);
      $html .= '<p class="mh-tool-helpers__found-lead">' . $this->t('From this tool’s manual and earlier answers:') . '</p>';
      $allowed = ['a', 'p', 'ul', 'ol', 'li', 'strong', 'em', 'code', 'br'];
      $html .= '<div class="mh-tool-helpers__answer">' . Xss::filter((string) $converter->convert($answer), $allowed) . '</div>';
      $html .= '<p class="mh-tool-helpers__fine">' . $this->t('Written by the site assistant from the tool’s page. Check anything safety-related with a facilitator.') . '</p>';
    }
    if ($earlier) {
      $html .= '<p class="mh-tool-helpers__found-lead">' . $this->t('Asked here before:') . '</p><ul class="mh-tool-helpers__earlier">';
      foreach ($earlier as $row) {
        $text = Html::escape(mb_strimwidth((string) $row['question'], 0, 160, '…'));
        $when = \Drupal::service('date.formatter')->format((int) $row['created'], 'custom', 'M j, Y');
        $html .= '<li>' . ($row['url'] !== ''
          ? '<a href="' . Html::escape($row['url']) . '" target="_blank" rel="noopener">' . $text . '</a>'
          : $text) . ' <span class="mh-tool-helpers__fine">' . $when . '</span></li>';
      }
      $html .= '</ul>';
      $html .= '<p class="mh-tool-helpers__fine">' . $this->t('The links open the Slack thread with the replies.') . '</p>';
    }
    return $html . '</div>';
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $parents = $form_state->getTriggeringElement()['#parents'] ?? [];
    $button = (string) end($parents);
    if (!in_array($button, ['check', 'post'], TRUE)) {
      return;
    }
    if (mb_strlen(trim((string) $form_state->getValue('question'))) < 10) {
      $form_state->setErrorByName('question', $this->t('Please say a little more so people can help.'));
    }
    $uid = 'user:' . $this->currentUser()->id();
    if ($button === 'check') {
      if (!$this->flood->isAllowed('appointment_facilitator.tool_question_check', self::HOURLY_CHECKS, 3600, $uid)) {
        $form_state->setErrorByName('question', $this->t('You have checked a lot of questions in the last hour. Please wait a little, or post directly in Slack.'));
      }
      return;
    }
    if (!$this->flood->isAllowed('appointment_facilitator.tool_question', self::HOURLY_LIMIT, 3600, $uid)) {
      $form_state->setErrorByName('question', $this->t('You have asked several questions in the last hour. Please wait a little, or post directly in Slack.'));
    }
  }

  /**
   * Looks for an answer before anything is posted.
   */
  public function checkSubmit(array &$form, FormStateInterface $form_state): void {
    $tool = $this->entityTypeManager->getStorage('node')->load((int) $form_state->get('tool_id'));
    if (!$tool instanceof NodeInterface) {
      return;
    }
    $question = trim((string) $form_state->getValue('question'));
    $this->flood->register('appointment_facilitator.tool_question_check', 3600, 'user:' . $this->currentUser()->id());
    $answer = '';
    // Members who turned AI off (field_hide_ai_chatbots) get only the earlier
    // questions; their question is never sent to the assistant.
    if ($this->aiAllowed() && $this->toolAnswers && method_exists($this->toolAnswers, 'answerOnToolPage')) {
      $answer = (string) ($this->toolAnswers->answerOnToolPage($question, '/node/' . $tool->id()) ?? '');
    }
    $earlier = $this->helpers->earlierQuestions($tool, $question);
    $form_state->set('checked', TRUE);
    $form_state->set('answer', $answer);
    $form_state->set('earlier', $earlier);
    $this->logger('appointment_facilitator')->info('Tool question check on @nid: answer @answer, earlier questions @earlier.', [
      '@nid' => $tool->id(),
      '@answer' => $answer !== '' ? 'yes' : 'no',
      '@earlier' => count($earlier),
    ]);
    $form_state->setRebuild();
  }

  /**
   * The member says the check answered it: nothing is posted.
   */
  public function answeredSubmit(array &$form, FormStateInterface $form_state): void {
    $this->logger('appointment_facilitator')->info('Tool question on @nid answered without posting to Slack.', [
      '@nid' => (int) $form_state->get('tool_id'),
    ]);
    $form_state->set('answered', TRUE);
    $form_state->setRebuild();
  }

  /**
   * Back to an empty question box.
   */
  public function resetSubmit(array &$form, FormStateInterface $form_state): void {
    foreach (['answered', 'checked', 'answer', 'earlier'] as $key) {
      $form_state->set($key, NULL);
    }
    $form_state->setUserInput([]);
    $form_state->setRebuild();
  }

  /**
   * AJAX: the rebuilt form.
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state): array {
    return $form;
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
