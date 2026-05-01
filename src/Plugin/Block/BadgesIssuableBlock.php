<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @Block(
 *   id = "appointment_facilitator_badges_issuable",
 *   admin_label = @Translation("Facilitator: Badges this person can issue"),
 *   category = @Translation("MakeHaven")
 * )
 */
class BadgesIssuableBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly RouteMatchInterface $routeMatch,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
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

    [$regular_ids, $on_request_ids] = $this->loadIssuableTids($uid);
    $all_ids = array_unique(array_merge($regular_ids, $on_request_ids));
    if (!$all_ids) {
      return [];
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $term_storage->loadMultiple($all_ids);
    uasort($terms, static fn (TermInterface $a, TermInterface $b) => strcasecmp((string) $a->label(), (string) $b->label()));

    $on_request_set = array_flip($on_request_ids);
    $regular_set = array_flip($regular_ids);

    $items = [];
    foreach ($terms as $term) {
      $tid = (int) $term->id();
      $is_on_request_only = isset($on_request_set[$tid]) && !isset($regular_set[$tid]);

      $items[] = [
        '#type' => 'inline_template',
        '#template' => '<a href="{{ url }}" class="facilitator-badge-pill">{{ name }}</a>{% if on_request %} <span class="facilitator-badge-pill__tag">by request</span>{% endif %}',
        '#context' => [
          'name' => $term->label(),
          'url' => Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid])->toString(),
          'on_request' => $is_on_request_only,
        ],
      ];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['facilitator-badges-issuable']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Badges this facilitator can issue'),
      ],
      'list' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['facilitator-badges-issuable__list']],
      ],
      '#attached' => [
        'library' => ['appointment_facilitator/facilitator_profile'],
      ],
    ];

    return $build;
  }

  protected function loadIssuableTids(int $uid): array {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $regular = $term_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', 'badges')
      ->condition('field_badge_issuer.target_id', $uid)
      ->execute();

    $on_request = [];
    $field_storage = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->load('taxonomy_term.field_badge_issuer_on_request');
    if ($field_storage) {
      $on_request = $term_storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('vid', 'badges')
        ->condition('field_badge_issuer_on_request.target_id', $uid)
        ->execute();
    }

    return [array_values($regular), array_values($on_request)];
  }

  protected function resolveProfile(): ?ProfileInterface {
    $profile = $this->routeMatch->getParameter('profile');
    return $profile instanceof ProfileInterface ? $profile : NULL;
  }

  public function getCacheTags(): array {
    $tags = parent::getCacheTags();
    $tags[] = 'taxonomy_term_list:badges';
    if ($profile = $this->resolveProfile()) {
      $tags = Cache::mergeTags($tags, $profile->getCacheTags());
    }
    return $tags;
  }

  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

}
