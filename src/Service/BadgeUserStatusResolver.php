<?php

namespace Drupal\appointment_facilitator\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Resolves a member's status with respect to a badge.
 *
 * Wraps BadgePrerequisiteGate plus a badge_request lookup to produce a single
 * state flag the badge page banner, the embedded compliance strip, and the
 * /badges card grid all consume.
 */
class BadgeUserStatusResolver {

  public const STATE_ANONYMOUS = 'anonymous';
  public const STATE_ACTIVE = 'active';
  public const STATE_PENDING = 'pending';
  public const STATE_QUIZ_NEEDED = 'quiz_needed';
  public const STATE_PREREQS_MISSING = 'prereqs_missing';
  public const STATE_DOCS_REQUIRED = 'docs_required';
  public const STATE_SUSPENDED = 'suspended';
  public const STATE_EXPIRED = 'expired';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected BadgePrerequisiteGate $badgeGate,
  ) {}

  /**
   * Resolves the user's state for the given badge.
   *
   * @return array
   *   Keys: state (string), badge_request (NodeInterface|null), gate (array
   *   from BadgePrerequisiteGate::evaluate), cache_tags (string[]).
   */
  public function resolve(int $uid, TermInterface $badge): array {
    $cache_tags = ['taxonomy_term:' . $badge->id()];

    if ($uid <= 0) {
      return [
        'state' => self::STATE_ANONYMOUS,
        'badge_request' => NULL,
        'gate' => [],
        'cache_tags' => $cache_tags,
      ];
    }

    $request = $this->loadLatestBadgeRequest($uid, (int) $badge->id());
    if ($request) {
      $cache_tags[] = 'node:' . $request->id();
    }
    // Always invalidate per-user when any badge_request changes.
    $cache_tags[] = 'node_list:badge_request';

    $status = '';
    if ($request && $request->hasField('field_badge_status') && !$request->get('field_badge_status')->isEmpty()) {
      $status = strtolower(trim((string) $request->get('field_badge_status')->value));
    }

    if ($status === 'active') {
      return [
        'state' => self::STATE_ACTIVE,
        'badge_request' => $request,
        'gate' => [],
        'cache_tags' => $cache_tags,
      ];
    }
    if ($status === 'suspended') {
      return [
        'state' => self::STATE_SUSPENDED,
        'badge_request' => $request,
        'gate' => [],
        'cache_tags' => $cache_tags,
      ];
    }
    if ($status === 'expired') {
      return [
        'state' => self::STATE_EXPIRED,
        'badge_request' => $request,
        'gate' => [],
        'cache_tags' => $cache_tags,
      ];
    }

    $gate = $this->badgeGate->evaluate($uid, $badge);

    if (!empty($gate['prerequisites_missing'])) {
      return [
        'state' => self::STATE_PREREQS_MISSING,
        'badge_request' => $request,
        'gate' => $gate,
        'cache_tags' => $cache_tags,
      ];
    }

    if (!empty($gate['requires_documentation']) && empty($gate['documentation_approved'])) {
      return [
        'state' => self::STATE_DOCS_REQUIRED,
        'badge_request' => $request,
        'gate' => $gate,
        'cache_tags' => $cache_tags,
      ];
    }

    if ($request && in_array($status, ['', 'pending'], TRUE)) {
      return [
        'state' => self::STATE_PENDING,
        'badge_request' => $request,
        'gate' => $gate,
        'cache_tags' => $cache_tags,
      ];
    }

    return [
      'state' => self::STATE_QUIZ_NEEDED,
      'badge_request' => $request,
      'gate' => $gate,
      'cache_tags' => $cache_tags,
    ];
  }

  /**
   * Loads the most recently changed badge_request for this user/badge pair.
   */
  protected function loadLatestBadgeRequest(int $uid, int $tid): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('field_member_to_badge.target_id', $uid)
      ->condition('field_badge_requested.target_id', $tid)
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();

    if (!$nids) {
      return NULL;
    }
    $nid = (int) reset($nids);
    $node = $storage->load($nid);
    return $node instanceof NodeInterface ? $node : NULL;
  }

}
