<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Honor-system "I've reviewed the materials" toggle on a badge term page.
 *
 * AJAX-driven: clicking flips storage via user.data and returns the
 * rebuilt form so the button morphs done ↔ undo without a page reload.
 * The stepper above the form picks up the new state on the next page
 * load (cache tags get invalidated on submit so that's a fresh render).
 *
 * Storage is per-user via the user.data API:
 *   module:  appointment_facilitator
 *   name:    badge_video_watched.{tid}
 *   value:   unix timestamp of acknowledgement (NULL when cleared)
 */
class BadgeVideoWatchedForm extends FormBase {

  /**
   * DOM id wrapping the form — Drupal's default AJAX replaces this on
   * submit. Keep stable across rebuilds so the second click works.
   */
  private const FORM_WRAPPER = 'badge-video-watched-form-wrapper';

  public function __construct(
    protected readonly UserDataInterface $userData,
    protected readonly TimeInterface $time,
    protected readonly AccountInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('user.data'),
      $container->get('datetime.time'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'appointment_facilitator_badge_video_watched';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?TermInterface $term = NULL): array {
    // The term arg comes through the first request via formBuilder->getForm();
    // on a rebuild from AJAX submission, recover it from form_state.
    if (!$term && ($stashed = $form_state->get('badge_term')) instanceof TermInterface) {
      $term = $stashed;
    }
    if (!$term || $term->bundle() !== 'badges') {
      return [];
    }
    $form_state->set('badge_term', $term);
    $uid = (int) $this->currentUser->id();
    $tid = (int) $term->id();

    $form['#prefix'] = '<div id="' . self::FORM_WRAPPER . '">';
    $form['#suffix'] = '</div>';
    $form['#attributes']['class'][] = 'badge-video-watched-form';
    $form['tid'] = ['#type' => 'hidden', '#value' => $tid];

    if ($uid <= 0) {
      $form['login_prompt'] = [
        '#type' => 'inline_template',
        '#template' => '<p class="badge-video-watched-form__login"><a href="{{ url }}">{{ msg }}</a></p>',
        '#context' => [
          'url' => Url::fromRoute('user.login', [], [
            'query' => ['destination' => $term->toUrl()->toString()],
          ])->toString(),
          'msg' => $this->t('Log in to track your progress on this badge.'),
        ],
      ];
      return $form;
    }

    $watched = self::isWatched($this->userData, $uid, $tid);
    $ajax = [
      'wrapper' => self::FORM_WRAPPER,
      'effect' => 'none',
      'progress' => ['type' => 'throbber'],
    ];

    if ($watched) {
      $form['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('✓ Reviewed — undo'),
        '#attributes' => [
          'class' => ['btn', 'btn-success', 'btn-sm', 'badge-video-watched-form__btn', 'badge-video-watched-form__btn--done'],
        ],
        '#submit' => ['::clear'],
        '#ajax' => $ajax,
      ];
      $form['note'] = [
        '#markup' => '<p class="badge-video-watched-form__note">' . $this->t("Honor system — we don't auto-check, but staff can see who marked themselves done.") . '</p>',
      ];
    }
    else {
      $form['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t("I've reviewed the materials"),
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'btn-sm', 'badge-video-watched-form__btn'],
        ],
        '#ajax' => $ajax,
      ];
      $form['note'] = [
        '#markup' => '<p class="badge-video-watched-form__note">' . $this->t('Confirms you watched the video, read the manual, or otherwise prepared. Required before the quiz makes sense.') . '</p>',
      ];
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser->id();
    $tid = (int) $form_state->getValue('tid');
    if ($uid <= 0 || $tid <= 0) {
      return;
    }
    $this->userData->set(
      'appointment_facilitator',
      $uid,
      'badge_video_watched.' . $tid,
      $this->time->getRequestTime()
    );
    // Banner block caches with `user` context + PERMANENT max-age — drop
    // the relevant render-cache entry so the next page render reflects
    // the new state instead of returning the pre-toggle copy.
    Cache::invalidateTags(['user:' . $uid, 'taxonomy_term:' . $tid]);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler for the "undo" path.
   */
  public function clear(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser->id();
    $tid = (int) $form_state->getValue('tid');
    if ($uid <= 0 || $tid <= 0) {
      return;
    }
    $this->userData->delete('appointment_facilitator', $uid, 'badge_video_watched.' . $tid);
    Cache::invalidateTags(['user:' . $uid, 'taxonomy_term:' . $tid]);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Returns TRUE when the user has self-attested to watching this video.
   */
  public static function isWatched(UserDataInterface $userData, int $uid, int $tid): bool {
    if ($uid <= 0 || $tid <= 0) {
      return FALSE;
    }
    $value = $userData->get('appointment_facilitator', $uid, 'badge_video_watched.' . $tid);
    return !empty($value);
  }

}
