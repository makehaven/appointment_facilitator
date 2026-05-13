<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Plugin\Block;

use Drupal\appointment_facilitator\Service\BadgePopularityStats;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the badge popularity stats panel as a placeable block.
 *
 * Same data as `_appointment_facilitator_attach_badge_popularity_stats()`,
 * but exposed as a block so it can sit in the pre_content region (above the
 * page title) where members see it before they read anything else. The
 * inline render call is suppressed when this block is enabled to avoid
 * duplicating the stats further down the page.
 *
 * @Block(
 *   id = "appointment_facilitator_badge_popularity_stats",
 *   admin_label = @Translation("Badge popularity stats"),
 *   category = @Translation("MakeHaven")
 * )
 */
class BadgePopularityStatsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly RouteMatchInterface $routeMatch,
    protected readonly BadgePopularityStats $stats,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('appointment_facilitator.badge_popularity'),
    );
  }

  public function build(): array {
    $term = $this->resolveBadgeTerm();
    if (!$term) {
      return [];
    }
    $stats = $this->stats->getStats($term);
    $holders = (int) ($stats['holders'] ?? 0);
    if ($holders <= 0 && (int) ($stats['tool_count'] ?? 0) <= 0) {
      // Nothing meaningful to show on a brand-new badge nobody holds and
      // that doesn't gate anything.
      return [];
    }

    $cards = [];

    // Holders + percent (combined card: big number, % below). Clickable —
    // links to the members-with-this-badge listing.
    $pct = $stats['percent_of_active'] ?? NULL;
    $holders_url = NULL;
    if ($holders > 0) {
      $holders_url = Url::fromRoute('appointment_facilitator.badge_members', [
        'taxonomy_term' => $term->id(),
      ])->toString();
    }
    $cards[] = [
      'value' => $holders,
      'label' => $this->formatPlural($holders, 'member', 'members'),
      'sub' => $pct !== NULL ? $this->t('@pct% of active makers', ['@pct' => $pct]) : NULL,
      'tone' => 'primary',
      'url' => $holders_url,
    ];

    // Rank (only meaningful when ranked)
    $rank = (int) ($stats['rank'] ?? 0);
    $total = (int) ($stats['total_ranked_badges'] ?? 0);
    if ($rank > 0 && $total > 0) {
      $cards[] = [
        'value' => '#' . $rank,
        'label' => $this->t('of @t earned', ['@t' => $total]),
        'sub' => $this->rankFlavor($rank, $total),
        'tone' => 'neutral',
      ];
    }

    // Recent activity — "earned in the last 30 days" or "most recent" date
    $recent = (int) ($stats['earned_last_30d'] ?? 0);
    $days_ago = $stats['last_earned_days_ago'];
    if ($recent > 0) {
      $sub_text = NULL;
      if (is_int($days_ago)) {
        $sub_text = $days_ago === 0
          ? $this->t('Most recent: today')
          : $this->formatPlural($days_ago, 'Most recent: 1 day ago', 'Most recent: @count days ago');
      }
      $cards[] = [
        'value' => $recent,
        'label' => $this->t('earned in 30 days'),
        'sub' => $sub_text,
        'tone' => 'success',
      ];
    }

    // Tools unlocked by this badge — the "what does it let me use" stat
    $tools = (int) ($stats['tool_count'] ?? 0);
    if ($tools > 0) {
      $cards[] = [
        'value' => $tools,
        'label' => $this->formatPlural($tools, 'tool unlocked', 'tools unlocked'),
        'sub' => NULL,
        'tone' => 'info',
      ];
    }

    return [
      '#type' => 'inline_template',
      '#template' => <<<TWIG
<aside class="badge-popularity-stats badge-popularity-stats--cards" aria-label="{{ heading }}">
  <h3 class="badge-popularity-stats__heading">{{ heading }}</h3>
  <ul class="badge-popularity-stats__cards">
    {% for card in cards %}
      <li class="badge-popularity-stats__card badge-popularity-stats__card--{{ card.tone }}{% if card.url %} badge-popularity-stats__card--linked{% endif %}">
        {% if card.url %}<a class="badge-popularity-stats__card-link" href="{{ card.url }}">{% endif %}
        <span class="badge-popularity-stats__card-value">{{ card.value }}</span>
        <span class="badge-popularity-stats__card-label">{{ card.label }}</span>
        {% if card.sub %}<span class="badge-popularity-stats__card-sub">{{ card.sub }}</span>{% endif %}
        {% if card.url %}</a>{% endif %}
      </li>
    {% endfor %}
  </ul>
</aside>
TWIG,
      '#context' => [
        'heading' => $this->t('Badge at a glance'),
        'cards' => $cards,
      ],
      '#cache' => [
        // Vary by route so the same rendered HTML isn't reused across
        // different taxonomy term pages. Without this, the first badge
        // page to render set the stats for every subsequent badge page
        // until tags invalidated (e.g. "669 members" on /badges/80-freezer
        // when those were really the Wood Shop Orientation numbers).
        'contexts' => ['route'],
        'tags' => ['node_list:badge_request', 'node_list:item', 'taxonomy_term:' . $term->id()],
      ],
      '#attached' => [
        'library' => ['appointment_facilitator/badge_status_banner'],
      ],
    ];
  }

  /**
   * Returns a short flavor line for the rank pill ("Top 25%", etc.).
   */
  protected function rankFlavor(int $rank, int $total): string {
    if ($total <= 0) {
      return '';
    }
    $percentile = (int) round(($rank / $total) * 100);
    return match (TRUE) {
      $percentile <= 10 => (string) $this->t('Top 10% — popular'),
      $percentile <= 25 => (string) $this->t('Top 25%'),
      $percentile <= 50 => (string) $this->t('Top half'),
      default => (string) $this->t('Niche / specialty'),
    };
  }

  /**
   * Resolves the badge term from the active route, NULL on a non-term page.
   */
  protected function resolveBadgeTerm(): ?TermInterface {
    $term = $this->routeMatch->getParameter('taxonomy_term');
    if ($term instanceof TermInterface && $term->bundle() === 'badges') {
      return $term;
    }
    return NULL;
  }

}
