<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Plugin\Block;

use Drupal\appointment_facilitator\Service\AppointmentStats;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\profile\Entity\ProfileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @Block(
 *   id = "appointment_facilitator_contributions",
 *   admin_label = @Translation("Facilitator: Lifetime contributions"),
 *   category = @Translation("MakeHaven")
 * )
 */
class FacilitatorContributionsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly AppointmentStats $stats,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly RouteMatchInterface $routeMatch,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('appointment_facilitator.stats'),
      $container->get('date.formatter'),
      $container->get('current_route_match'),
    );
  }

  public function build(): array {
    $profile = $this->resolveProfile();
    if (!$profile || $profile->bundle() !== 'coordinator') {
      return [];
    }

    $uid = (int) $profile->getOwnerId();
    if ($uid <= 0) {
      return [];
    }

    $summary = $this->stats->summarize(NULL, NULL, ['host_id' => $uid]);
    $row = $summary['facilitators'][$uid] ?? NULL;
    if (!$row || (int) ($row['appointments'] ?? 0) === 0) {
      return [];
    }

    $tiles = [
      ['label' => $this->t('Appointments hosted'), 'value' => (int) $row['appointments']],
      ['label' => $this->t('Members served'), 'value' => (int) ($row['attendees'] ?? 0)],
      ['label' => $this->t('Badge sessions'), 'value' => (int) ($row['badge_sessions'] ?? 0)],
      ['label' => $this->t('Badges granted'), 'value' => (int) ($row['badges'] ?? 0)],
    ];

    $latest = $row['latest'] ?? NULL;
    if ($latest) {
      $tiles[] = [
        'label' => $this->t('Most recent appointment'),
        'value' => $this->dateFormatter->format($latest->getTimestamp(), 'custom', 'M j, Y'),
      ];
    }

    $items = [];
    foreach ($tiles as $tile) {
      $items[] = [
        '#type' => 'inline_template',
        '#template' => '<div class="facilitator-stat-tile"><div class="facilitator-stat-tile__value">{{ value }}</div><div class="facilitator-stat-tile__label">{{ label }}</div></div>',
        '#context' => [
          'value' => $tile['value'],
          'label' => $tile['label'],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['facilitator-contributions']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('By the numbers'),
      ],
      'tiles' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['facilitator-contributions__tiles']],
        'items' => $items,
      ],
      '#attached' => [
        'library' => ['appointment_facilitator/facilitator_profile'],
      ],
    ];
  }

  protected function resolveProfile(): ?ProfileInterface {
    $profile = $this->routeMatch->getParameter('profile');
    return $profile instanceof ProfileInterface ? $profile : NULL;
  }

  public function getCacheTags(): array {
    $tags = parent::getCacheTags();
    $tags[] = 'node_list:appointment';
    if ($profile = $this->resolveProfile()) {
      $tags = Cache::mergeTags($tags, $profile->getCacheTags());
    }
    return $tags;
  }

  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

  public function getCacheMaxAge(): int {
    return 3600;
  }

}
