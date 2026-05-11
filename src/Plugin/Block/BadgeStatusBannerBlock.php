<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Plugin\Block;

use Drupal\appointment_facilitator\Service\BadgeUserStatusResolver;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Member-facing "next step" banner for the badge term page.
 *
 * Resolves the current user's status with respect to the badge term loaded
 * by the active route, then renders a sticky banner with an appropriate CTA
 * (take quiz, schedule checkout, submit documentation, earn prerequisites,
 * etc.). Anonymous visitors see a log-in prompt.
 *
 * @Block(
 *   id = "appointment_facilitator_badge_status_banner",
 *   admin_label = @Translation("Badge status banner (member next-step CTA)"),
 *   category = @Translation("MakeHaven")
 * )
 */
class BadgeStatusBannerBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the block.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly RouteMatchInterface $routeMatch,
    protected readonly AccountInterface $currentUser,
    protected readonly BadgeUserStatusResolver $statusResolver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('current_user'),
      $container->get('appointment_facilitator.badge_user_status'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $term = $this->resolveBadgeTerm();
    if (!$term) {
      return [];
    }

    $uid = (int) $this->currentUser->id();
    $resolved = $this->statusResolver->resolve($uid, $term);
    $state = $resolved['state'];

    [$tone, $heading, $body, $cta] = $this->renderForState($state, $term, $resolved);

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'mh-badge-status-banner',
          'mh-badge-status-banner--' . str_replace('_', '-', $state),
          'mh-badge-status-banner--tone-' . $tone,
        ],
        'data-state' => $state,
      ],
      '#attached' => [
        'library' => ['appointment_facilitator/badge_status_banner'],
      ],
      'content' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mh-badge-status-banner__inner']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['mh-badge-status-banner__heading']],
          '#value' => $heading,
        ],
        'body' => $this->renderBody($body),
        'cta' => $cta ?? [],
      ],
    ];
  }

  /**
   * Wraps the body in an <p> tag when it's a string, or returns the render
   * array as-is when the state contributes a complex element (e.g. the
   * prereqs-missing state injects an item_list of prerequisite links).
   */
  protected function renderBody(mixed $body): array {
    if ($body === NULL || $body === '') {
      return [];
    }
    if (is_array($body)) {
      return $body;
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['mh-badge-status-banner__body']],
      '#value' => $body,
    ];
  }

  /**
   * Computes [tone, heading, body, cta-render-array] for a state.
   */
  protected function renderForState(string $state, TermInterface $term, array $resolved): array {
    switch ($state) {
      case BadgeUserStatusResolver::STATE_ANONYMOUS:
        $login = Url::fromRoute('user.login', [], [
          'query' => ['destination' => $term->toUrl()->toString()],
        ])->toString();
        $cta = $this->ctaButton($this->t('Log in'), $login);
        return [
          'neutral',
          $this->t('Log in to see your progress on this badge.'),
          NULL,
          $cta,
        ];

      case BadgeUserStatusResolver::STATE_ACTIVE:
        return [
          'success',
          $this->t('✓ You have this badge.'),
          $this->t("You're authorized to use the associated tools listed below."),
          NULL,
        ];

      case BadgeUserStatusResolver::STATE_PENDING:
        return [
          'progress',
          $this->t('Next step: schedule a facilitator checkout.'),
          $this->t("You've passed the quiz. Pick an upcoming session below or contact a facilitator."),
          NULL,
        ];

      case BadgeUserStatusResolver::STATE_QUIZ_NEEDED:
        // No CTA: we deliberately do not link directly to the quiz from the
        // top of the page. Members should watch the walkthrough and review
        // requirements first; the quiz link lives further down with the rest
        // of the materials.
        return [
          'progress',
          $this->t('Start by watching the video and reviewing the requirements below.'),
          $this->t('After you have reviewed the materials, scroll down to take the quiz.'),
          NULL,
        ];

      case BadgeUserStatusResolver::STATE_PREREQS_MISSING:
        $links = $this->renderPrereqLinks($resolved['gate'] ?? []);
        return [
          'blocked',
          $this->t('You need to earn prerequisites first.'),
          $links,
          NULL,
        ];

      case BadgeUserStatusResolver::STATE_DOCS_REQUIRED:
        $form_url = $resolved['gate']['documentation_form_url'] ?? NULL;
        $cta = $form_url ? $this->ctaButton($this->t('Submit documentation'), $form_url) : NULL;
        $body = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['mh-badge-status-banner__body']],
          '#value' => $this->t(
            'You can earn this badge in two ways: attend one of the @link, or submit prior training documentation for staff review.',
            ['@link' => Markup::create('<a href="#earn-badge-at-event" class="mh-badge-status-banner__inline-link">upcoming classes listed below</a>')]
          ),
        ];
        return [
          'blocked',
          $this->t('Take a class or submit documentation.'),
          $body,
          $cta,
        ];

      case BadgeUserStatusResolver::STATE_SUSPENDED:
        return [
          'warning',
          $this->t('Your access to this badge is suspended.'),
          $this->t('Contact staff for next steps.'),
          NULL,
        ];

      case BadgeUserStatusResolver::STATE_EXPIRED:
        return [
          'warning',
          $this->t('This badge has expired.'),
          $this->t('Re-take the quiz or schedule a refresher to renew it.'),
          NULL,
        ];
    }

    return ['neutral', '', NULL, NULL];
  }

  /**
   * Builds a CTA button render element.
   */
  protected function ctaButton($label, string $href, array $extra_classes = []): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'a',
      '#value' => $label,
      '#attributes' => [
        'href' => $href,
        'class' => array_merge(['mh-badge-status-banner__cta', 'btn', 'btn-primary'], $extra_classes),
      ],
    ];
  }

  /**
   * Resolves the URL of the quiz referenced by the badge term.
   */
  protected function resolveQuizUrl(TermInterface $term): ?string {
    if (!$term->hasField('field_badge_quiz_reference') || $term->get('field_badge_quiz_reference')->isEmpty()) {
      return NULL;
    }
    $quiz = $term->get('field_badge_quiz_reference')->entity;
    if (!$quiz) {
      return NULL;
    }
    try {
      return $quiz->toUrl()->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Renders an item list of links to the user's missing prerequisite badges.
   */
  protected function renderPrereqLinks(array $gate): array {
    $tids = $gate['prerequisites_missing'] ?? [];
    $labels = $gate['prerequisites_missing_labels'] ?? [];
    if (!$tids) {
      return [];
    }

    $items = [];
    foreach ($tids as $i => $tid) {
      $label = $labels[$i] ?? ('Badge ' . $tid);
      $url = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid])->toString();
      $items[] = [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => $label,
        '#attributes' => [
          'href' => $url,
          'class' => ['mh-badge-status-banner__prereq-link'],
        ],
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['mh-badge-status-banner__prereqs']],
    ];
  }

  /**
   * Returns the badges term from the current route or NULL.
   */
  protected function resolveBadgeTerm(): ?TermInterface {
    $term = $this->routeMatch->getParameter('taxonomy_term');
    if (!$term instanceof TermInterface) {
      return NULL;
    }
    return $term->bundle() === 'badges' ? $term : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route', 'user']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    $tags = parent::getCacheTags();
    if ($term = $this->resolveBadgeTerm()) {
      $tags = Cache::mergeTags($tags, $term->getCacheTags());
      $tags[] = 'node_list:badge_request';
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

}
