<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "Get your first badge" helper for members who haven't earned one yet.
 *
 * Members frequently ask staff how to get started with badges. This block
 * answers that question inline on /resources for members who hold zero active
 * badges (excluding the access-control "Door" badge, which everyone gets and
 * is not what people mean by "earning a badge"). For members who already hold
 * any equipment badge, the block returns empty so it doesn't clutter the page.
 *
 * @Block(
 *   id = "appointment_facilitator_first_badge_next_step",
 *   admin_label = @Translation("First badge — getting started helper"),
 *   category = @Translation("MakeHaven")
 * )
 */
class FirstBadgeNextStepBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Door badge term ID — granted to all members, doesn't count as "a badge".
   */
  protected const DOOR_BADGE_TID = 1519;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
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
      $container->get('current_user'),
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf(
      $account->isAuthenticated() && in_array('member', $account->getRoles(), TRUE),
    )->addCacheContexts(['user.roles']);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return [];
    }

    if ($this->memberHasEquipmentBadge($uid)) {
      return [
        '#cache' => [
          'contexts' => ['user'],
          'tags' => ['node_list:badge_request', 'user:' . $uid],
        ],
      ];
    }

    return [
      '#theme' => 'first_badge_next_step',
      '#headline' => $this->t('Ready to earn your first badge?'),
      '#intro' => $this->t('Badges unlock the tools at MakeHaven. Here\'s the typical path:'),
      '#steps' => [
        $this->t('Pick a badge for something you want to use.'),
        $this->t('Watch the video and review requirements on the badge page.'),
        $this->t('Pass the short online quiz (100% to pass — you can retake).'),
        $this->t('Schedule a quick checkout with a facilitator (most badges).'),
      ],
      '#primary_cta' => [
        'title' => $this->t('Get a personalized recommendation'),
        'url' => Url::fromUserInput('/badges/complete')->toString(),
        'description' => $this->t('Picks foundational badges from your areas of interest.'),
      ],
      '#secondary_cta' => [
        'title' => $this->t('Browse all badges'),
        'url' => Url::fromUserInput('/badges')->toString(),
      ],
      '#help_link' => [
        'title' => $this->t('Ask a question'),
        'url' => Url::fromUserInput('/form/website-feedback')->toString(),
      ],
      '#attached' => [
        'library' => ['appointment_facilitator/first_badge_next_step'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list:badge_request', 'user:' . $uid],
      ],
    ];
  }

  /**
   * Checks whether the user has any active badge other than the Door badge.
   */
  protected function memberHasEquipmentBadge(int $uid): bool {
    $query = $this->database->select('node__field_member_to_badge', 'm');
    $query->innerJoin('node__field_badge_requested', 'b', 'b.entity_id = m.entity_id');
    $query->innerJoin('node__field_badge_status', 's', 's.entity_id = m.entity_id');
    $query->innerJoin('node_field_data', 'n', 'n.nid = m.entity_id');
    $query->condition('n.type', 'badge_request');
    $query->condition('n.status', 1);
    $query->condition('m.field_member_to_badge_target_id', $uid);
    $query->condition('s.field_badge_status_value', 'active');
    $query->condition('b.field_badge_requested_target_id', self::DOOR_BADGE_TID, '<>');
    $query->range(0, 1);
    $query->addExpression('1', 'present');
    return (bool) $query->execute()->fetchField();
  }

}
