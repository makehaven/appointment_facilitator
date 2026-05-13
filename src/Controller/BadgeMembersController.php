<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Controller;

use Drupal\appointment_facilitator\Service\BadgePopularityStats;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the members who hold a given badge as a card grid.
 *
 * Driven by the "X members" stat on the badge term page; clicking through
 * lands here. Layout mirrors the /members view's card style (photo + name)
 * but is purpose-built rather than reusing that 3k-line view config.
 */
class BadgeMembersController extends ControllerBase {

  protected const PAGE_SIZE = 48;

  public function __construct(
    protected readonly BadgePopularityStats $stats,
    protected readonly PagerManagerInterface $pagerManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('appointment_facilitator.badge_popularity'),
      $container->get('pager.manager'),
    );
  }

  /**
   * Page title — "Members with the X badge".
   */
  public function title(TermInterface $taxonomy_term): string {
    return (string) $this->t('Members with the @badge badge', [
      '@badge' => $taxonomy_term->label(),
    ]);
  }

  /**
   * Builds the card grid.
   */
  public function view(TermInterface $taxonomy_term): array {
    if ($taxonomy_term->bundle() !== 'badges') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $all_uids = $this->stats->getActiveHolderUids($taxonomy_term);
    $total = count($all_uids);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['badge-members-page']],
      '#cache' => [
        'tags' => [
          'node_list:badge_request',
          'user_list',
          'taxonomy_term:' . $taxonomy_term->id(),
        ],
        'contexts' => ['user.roles', 'url.query_args'],
      ],
    ];

    $build['back'] = [
      '#type' => 'link',
      '#title' => $this->t('← Back to badge'),
      '#url' => Url::fromRoute('entity.taxonomy_term.canonical', [
        'taxonomy_term' => $taxonomy_term->id(),
      ]),
      '#attributes' => ['class' => ['badge-members-page__back']],
    ];

    if (!$total) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('No active members hold this badge yet.') . '</p>',
      ];
      return $build;
    }

    $pager = $this->pagerManager->createPager($total, self::PAGE_SIZE);
    $page = $pager->getCurrentPage();
    $offset = $page * self::PAGE_SIZE;
    $page_uids = array_slice($all_uids, $offset, self::PAGE_SIZE);

    $build['count'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['badge-members-page__count']],
      '#value' => $this->formatPlural(
        $total,
        '1 active member',
        '@count active members',
      ),
    ];

    $users = $this->entityTypeManager()->getStorage('user')->loadMultiple($page_uids);
    // Preserve query order — loadMultiple does not guarantee it.
    $ordered = [];
    foreach ($page_uids as $uid) {
      if (isset($users[$uid])) {
        $ordered[$uid] = $users[$uid];
      }
    }

    $profiles_by_uid = $this->loadMainProfiles($ordered);

    $cards = [
      '#type' => 'container',
      '#attributes' => ['class' => ['badge-members-grid']],
    ];

    foreach ($ordered as $uid => $user) {
      $cards['member_' . $uid] = $this->buildCard($user, $profiles_by_uid[$uid] ?? NULL);
    }
    $build['cards'] = $cards;
    $build['pager'] = ['#type' => 'pager'];
    $build['#attached']['library'][] = 'appointment_facilitator/badge_members_page';

    return $build;
  }

  /**
   * Loads the main profile for each user in a single query.
   *
   * @param \Drupal\user\UserInterface[] $users
   *
   * @return array<int, \Drupal\profile\Entity\ProfileInterface>
   */
  protected function loadMainProfiles(array $users): array {
    if (!$users) {
      return [];
    }
    $storage = $this->entityTypeManager()->getStorage('profile');
    $pids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'main')
      ->condition('status', 1)
      ->condition('uid', array_keys($users), 'IN')
      ->execute();
    if (!$pids) {
      return [];
    }
    $by_uid = [];
    foreach ($storage->loadMultiple($pids) as $profile) {
      /** @var \Drupal\profile\Entity\ProfileInterface $profile */
      $by_uid[(int) $profile->getOwnerId()] = $profile;
    }
    return $by_uid;
  }

  /**
   * Builds a single member card matching the /members card style.
   *
   * Layout: `.card` → image (`.card-img-top`) → `.card-body` with
   * linked name, truncated bio, and areas-of-interest chips, plus a
   * "More" button. Uses Bootstrap card classes so the visuals line up
   * with the existing /members view.
   */
  protected function buildCard(UserInterface $user, ?ProfileInterface $profile): array {
    $uid = (int) $user->id();
    $name = $this->resolveName($user);

    $card = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card', 'badge-members-card']],
    ];

    $card['image'] = $this->buildPhoto($profile, $this->userUrl($uid), $name);

    $card['body'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card-body', 'badge-members-card__body']],
    ];

    $card['body']['name'] = [
      '#type' => 'link',
      '#title' => $name,
      '#url' => $this->userUrl($uid),
      '#attributes' => ['class' => ['card-title', 'badge-members-card__name']],
    ];

    if ($bio = $this->resolveBio($profile)) {
      $card['body']['bio'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['card-text', 'badge-members-card__bio']],
        '#value' => $bio,
      ];
    }

    if ($areas = $this->buildAreas($profile)) {
      $card['body']['areas'] = $areas;
    }

    $card['body']['more'] = [
      '#type' => 'link',
      '#title' => $this->t('More'),
      '#url' => $this->userUrl($uid),
      '#attributes' => ['class' => ['btn', 'btn-link', 'badge-members-card__more']],
    ];

    return $card;
  }

  /**
   * Returns a fresh Url for the user's canonical page.
   *
   * Each render-array link gets its own Url instance — Drupal's link
   * element merges #attributes into the Url's options at pre-render
   * time, so reusing one Url across multiple links causes their class
   * attributes to bleed together onto the last-rendered anchor.
   */
  protected function userUrl(int $uid): Url {
    return Url::fromRoute('entity.user.canonical', ['user' => $uid]);
  }

  /**
   * Renders the member photo (or a placeholder) wrapped in a link to /user/N.
   */
  protected function buildPhoto(?ProfileInterface $profile, Url $url, string $alt): array {
    $image = NULL;
    if ($profile && $profile->hasField('field_member_photo') && !$profile->get('field_member_photo')->isEmpty()) {
      $file = $profile->get('field_member_photo')->entity;
      if ($file) {
        $image = [
          '#theme' => 'image_style',
          '#style_name' => 'member_photo',
          '#uri' => $file->getFileUri(),
          '#alt' => $alt,
          '#attributes' => ['class' => ['badge-members-card__image']],
        ];
      }
    }
    if (!$image) {
      $image = [
        '#theme' => 'image',
        '#uri' => '/sites/default/files/business-logos%20/Placeholder_1.png',
        '#alt' => $this->t('No photo available'),
        '#attributes' => ['class' => ['badge-members-card__image']],
        '#width' => 400,
        '#height' => 300,
      ];
    }
    return [
      '#type' => 'link',
      '#title' => $image,
      '#url' => $url,
      '#options' => ['html' => TRUE],
      '#attributes' => ['class' => ['card-img-top', 'badge-members-card__image-link']],
    ];
  }

  /**
   * Returns the bio text truncated to ~150 chars, matching /members.
   */
  protected function resolveBio(?ProfileInterface $profile): ?string {
    if (!$profile || !$profile->hasField('field_member_bio') || $profile->get('field_member_bio')->isEmpty()) {
      return NULL;
    }
    $raw = trim((string) $profile->get('field_member_bio')->value);
    if ($raw === '') {
      return NULL;
    }
    $plain = strip_tags($raw);
    if (mb_strlen($plain) <= 150) {
      return $plain;
    }
    return rtrim(mb_substr($plain, 0, 150)) . '…';
  }

  /**
   * Renders areas of interest as inline links, comma-separated.
   */
  protected function buildAreas(?ProfileInterface $profile): ?array {
    if (!$profile || !$profile->hasField('field_member_areas_interest')) {
      return NULL;
    }
    $values = $profile->get('field_member_areas_interest')->referencedEntities();
    if (!$values) {
      return NULL;
    }
    $items = [];
    foreach ($values as $term) {
      $items[] = [
        '#type' => 'link',
        '#title' => $term->label(),
        '#url' => Url::fromRoute('entity.taxonomy_term.canonical', [
          'taxonomy_term' => $term->id(),
        ]),
        '#attributes' => ['class' => ['badge-members-card__area']],
      ];
      $items[] = ['#markup' => ', '];
    }
    array_pop($items);
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['badge-members-card__areas']],
      'links' => $items,
    ];
  }

  /**
   * Builds a display name, preferring "First Last" then realname then username.
   */
  protected function resolveName(UserInterface $user): string {
    $first = $user->hasField('field_first_name') ? (string) $user->get('field_first_name')->value : '';
    $last = $user->hasField('field_last_name') ? (string) $user->get('field_last_name')->value : '';
    $combined = trim($first . ' ' . $last);
    if ($combined !== '') {
      return $combined;
    }
    return (string) $user->getDisplayName();
  }

}
