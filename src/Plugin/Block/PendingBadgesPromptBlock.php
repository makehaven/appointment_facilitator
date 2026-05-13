<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Nudges members with pending badges toward /badges/complete.
 *
 * Shown only when the current user is viewing their OWN /user/{uid}/badges
 * page and has one or more pending badge_request nodes. Facilitators viewing
 * another member's badges page don't see this — the prompt is a
 * self-directed "you've got something in progress, finish it" reminder.
 *
 * @Block(
 *   id = "appointment_facilitator_pending_badges_prompt",
 *   admin_label = @Translation("Pending badges — finish prompt"),
 *   category = @Translation("MakeHaven")
 * )
 */
class PendingBadgesPromptBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly RouteMatchInterface $routeMatch,
    protected readonly AccountInterface $currentUser,
    protected readonly Connection $database,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('current_user'),
      $container->get('database'),
    );
  }

  protected function blockAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($account->isAuthenticated())
      ->addCacheContexts(['user.roles', 'url.path']);
  }

  public function build(): array {
    if (!$this->isViewingOwnBadgesPage()) {
      return [];
    }
    $uid = (int) $this->currentUser->id();
    $pending = $this->countPendingBadges($uid);

    $cache = [
      'contexts' => ['user', 'url.path'],
      'tags' => ['node_list:badge_request', 'user:' . $uid],
    ];

    if ($pending < 1) {
      return ['#cache' => $cache];
    }

    $headline = $this->formatPlural(
      $pending,
      'You have 1 badge in progress.',
      'You have @count badges in progress.',
    );
    $body = $this->t("Don't lose momentum — see what's between you and finishing each one.");

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mh-pending-badges-prompt']],
      '#attached' => ['library' => ['appointment_facilitator/pending_badges_prompt']],
      '#cache' => $cache,
      'headline' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#attributes' => ['class' => ['mh-pending-badges-prompt__headline']],
        '#value' => $headline,
      ],
      'body' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['mh-pending-badges-prompt__body']],
        '#value' => $body,
      ],
      'cta' => [
        '#type' => 'link',
        '#title' => $this->t('Plan my next step'),
        '#url' => Url::fromUserInput('/badges/complete'),
        '#attributes' => ['class' => ['mh-pending-badges-prompt__cta', 'btn', 'btn-primary']],
      ],
    ];
  }

  /**
   * True when the current request is /user/{currentUser}/badges.
   */
  private function isViewingOwnBadgesPage(): bool {
    if ($this->routeMatch->getRouteName() !== 'view.badges_earned_block.page_1') {
      return FALSE;
    }
    $arg = $this->routeMatch->getParameter('arg_0');
    return is_numeric($arg) && (int) $arg === (int) $this->currentUser->id();
  }

  /**
   * Counts pending badge_request nodes for a member.
   */
  private function countPendingBadges(int $uid): int {
    $q = $this->database->select('node__field_member_to_badge', 'm');
    $q->innerJoin('node__field_badge_status', 's', 's.entity_id = m.entity_id');
    $q->innerJoin('node_field_data', 'n', 'n.nid = m.entity_id AND n.type = :t', [':t' => 'badge_request']);
    $q->condition('n.status', 1);
    $q->condition('m.field_member_to_badge_target_id', $uid);
    $q->condition('s.field_badge_status_value', 'pending');
    return (int) $q->countQuery()->execute()->fetchField();
  }

}
