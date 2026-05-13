<?php

namespace Drupal\appointment_facilitator\Service;

use Drupal\asset_status\Service\AssetAvailability;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Evaluates badge prerequisite gates for a member.
 */
class BadgePrerequisiteGate {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly ?AssetAvailability $assetAvailability = NULL,
  ) {
    $this->logger = $loggerFactory->get('appointment_facilitator');
  }

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Checks if the tool(s) required for this badge are currently offline.
   *
   * A badge is considered "offline" if it has associated items and ALL of those
   * items have an "offline" status (i.e., not Operational or Degraded). If no
   * items are linked to the badge, it is assumed to be "online".
   */
  public function isBadgeOffline(TermInterface $term): bool {
    if (!$this->assetAvailability) {
      return FALSE;
    }

    $tid = (int) $term->id();
    $storage = $this->entityTypeManager->getStorage('node');

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'item')
      ->condition('status', 1);

    $group = $query->orConditionGroup()
      ->condition('field_member_badges', $tid)
      ->condition('field_additional_badges', $tid);

    $nids = $query->condition($group)->execute();

    if (empty($nids)) {
      return FALSE;
    }

    $items = $storage->loadMultiple($nids);
    $any_usable = FALSE;

    foreach ($items as $item) {
      if (!$item->hasField('field_item_status') || $item->get('field_item_status')->isEmpty()) {
        // If no status is explicitly set, assume it's usable.
        $any_usable = TRUE;
        break;
      }

      $status_term = $item->get('field_item_status')->entity;
      if ($status_term instanceof TermInterface && $this->assetAvailability->isUsable($status_term)) {
        $any_usable = TRUE;
        break;
      }
    }

    return !$any_usable;
  }

  /**
   * Evaluates whether a member can progress on a badge.
   *
   * @return array
   *   Keys:
   *   - allowed: bool
   *   - requires_documentation: bool
   *   - documentation_submitted: bool — at least one non-draft submission
   *     exists for this user against the badge's documentation webform,
   *     regardless of approval status.
   *   - documentation_submitted_at: int|null — UNIX timestamp of the most
   *     recent non-draft submission, or NULL if none.
   *   - documentation_approved: bool
   *   - documentation_webform_id: string|null
   *   - documentation_form_url: string|null
   *   - prerequisites_required: int[]
   *   - prerequisites_missing: int[]
   *   - prerequisites_missing_labels: string[]
   *   - reasons: string[]
   */
  public function evaluate(int $memberUid, TermInterface $badge): array {
    $result = [
      'allowed' => TRUE,
      'requires_documentation' => FALSE,
      'documentation_submitted' => FALSE,
      'documentation_submitted_at' => NULL,
      'documentation_approved' => TRUE,
      'documentation_webform_id' => NULL,
      'documentation_form_url' => NULL,
      'prerequisites_required' => [],
      'prerequisites_missing' => [],
      'prerequisites_missing_labels' => [],
      'reasons' => [],
    ];

    if ($memberUid <= 0) {
      $result['allowed'] = FALSE;
      $result['reasons'][] = 'Invalid member account.';
      return $result;
    }

    $webform_id = $this->resolveTrainingDocumentationWebformId($badge);
    if ($webform_id !== NULL) {
      $result['requires_documentation'] = TRUE;
      $result['documentation_webform_id'] = $webform_id;
      $result['documentation_form_url'] = $this->resolveTrainingDocumentationFormUrl($badge);
      $latest_submission_ts = $this->latestDocumentationSubmissionTimestamp($memberUid, $webform_id);
      $result['documentation_submitted'] = $latest_submission_ts !== NULL;
      $result['documentation_submitted_at'] = $latest_submission_ts;
      $result['documentation_approved'] = $this->hasApprovedDocumentationSubmission($memberUid, $webform_id);
      if (!$result['documentation_approved']) {
        $result['allowed'] = FALSE;
        $result['reasons'][] = 'Documentation approval is required before this badge can be requested.';
      }
    }

    $prerequisites = $this->extractPrerequisiteBadgeIds($badge);
    $result['prerequisites_required'] = $prerequisites;
    foreach ($prerequisites as $prereq_tid) {
      if (!$this->memberHasActiveOrBlankBadge($memberUid, $prereq_tid)) {
        $result['allowed'] = FALSE;
        $result['prerequisites_missing'][] = $prereq_tid;
      }
    }

    if ($result['prerequisites_missing']) {
      $labels = $this->loadBadgeLabels($result['prerequisites_missing']);
      $result['prerequisites_missing_labels'] = $labels;
      $result['reasons'][] = 'Missing active prerequisite badge(s): ' . implode(', ', $labels) . '.';
    }

    return $result;
  }

  /**
   * Returns the timestamp of the user's most recent non-draft submission to
   * the documentation webform, or NULL when none exists.
   *
   * Unlike hasApprovedDocumentationSubmission(), this ignores the submission's
   * own `status` field — it's the "did they submit at all?" signal used to
   * distinguish "not submitted" from "submitted, awaiting staff review" on
   * the badge page.
   */
  public function latestDocumentationSubmissionTimestamp(int $memberUid, string $webformId): ?int {
    if ($memberUid <= 0 || $webformId === '') {
      return NULL;
    }
    if (!$this->entityTypeManager->hasDefinition('webform_submission')) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('webform_submission');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('webform_id', $webformId)
      ->condition('uid', $memberUid)
      ->sort('created', 'DESC')
      ->range(0, 1);

    if ($storage->getEntityType()->hasKey('in_draft')) {
      $query->condition('in_draft', 0);
    }

    $sids = $query->execute();
    if (!$sids) {
      return NULL;
    }
    $submission = $storage->load((int) reset($sids));
    if (!$submission || !method_exists($submission, 'getCreatedTime')) {
      return NULL;
    }
    $ts = (int) $submission->getCreatedTime();
    return $ts > 0 ? $ts : NULL;
  }

  /**
   * Returns TRUE when the user has an approved submission for the webform.
   */
  public function hasApprovedDocumentationSubmission(int $memberUid, string $webformId): bool {
    if ($memberUid <= 0 || $webformId === '') {
      return FALSE;
    }
    if (!$this->entityTypeManager->hasDefinition('webform_submission')) {
      return FALSE;
    }

    $query = $this->entityTypeManager->getStorage('webform_submission')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('webform_id', $webformId)
      ->condition('uid', $memberUid)
      ->sort('changed', 'DESC');

    if ($this->entityTypeManager->getStorage('webform_submission')->getEntityType()->hasKey('in_draft')) {
      $query->condition('in_draft', 0);
    }

    $sids = $query->execute();
    if (!$sids) {
      return FALSE;
    }

    $submissions = $this->entityTypeManager->getStorage('webform_submission')->loadMultiple($sids);
    foreach ($submissions as $submission) {
      if (!method_exists($submission, 'getData')) {
        continue;
      }
      $data = $submission->getData();
      $status = '';
      if (isset($data['status']) && is_scalar($data['status'])) {
        $status = strtolower(trim((string) $data['status']));
      }
      if ($status === 'approved') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns TRUE when member has badge_request with active or blank status.
   */
  public function memberHasActiveOrBlankBadge(int $memberUid, int $badgeTid): bool {
    if ($memberUid <= 0 || $badgeTid <= 0) {
      return FALSE;
    }

    $nids = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('status', 1)
      ->condition('field_member_to_badge.target_id', $memberUid)
      ->condition('field_badge_requested.target_id', $badgeTid)
      ->execute();

    if (!$nids) {
      return FALSE;
    }

    $requests = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    foreach ($requests as $request) {
      $status = '';
      if ($request->hasField('field_badge_status') && !$request->get('field_badge_status')->isEmpty()) {
        $status = strtolower(trim((string) $request->get('field_badge_status')->value));
      }
      if ($status === '' || $status === 'active') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Resolves webform machine ID from the badge's training doc field.
   */
  protected function resolveTrainingDocumentationWebformId(TermInterface $badge): ?string {
    if (!$badge->hasField('field_training_documentation') || $badge->get('field_training_documentation')->isEmpty()) {
      return NULL;
    }

    $doc_node = $badge->get('field_training_documentation')->entity;
    if (!$doc_node || !$doc_node->hasField('webform') || $doc_node->get('webform')->isEmpty()) {
      return NULL;
    }

    $webform_id = (string) $doc_node->get('webform')->target_id;
    return $webform_id !== '' ? $webform_id : NULL;
  }

  /**
   * Resolves documentation form URL from training doc webform wrapper node.
   */
  protected function resolveTrainingDocumentationFormUrl(TermInterface $badge): ?string {
    if (!$badge->hasField('field_training_documentation') || $badge->get('field_training_documentation')->isEmpty()) {
      return NULL;
    }
    $doc_node = $badge->get('field_training_documentation')->entity;
    if (!$doc_node) {
      return NULL;
    }
    return $doc_node->toUrl()->toString();
  }

  /**
   * Extracts prerequisite badge term IDs from taxonomy field.
   */
  protected function extractPrerequisiteBadgeIds(TermInterface $badge): array {
    if (!$badge->hasField('field_badge_prerequisite') || $badge->get('field_badge_prerequisite')->isEmpty()) {
      return [];
    }

    $ids = [];
    foreach ($badge->get('field_badge_prerequisite')->getValue() as $item) {
      $tid = isset($item['target_id']) ? (int) $item['target_id'] : 0;
      if ($tid > 0 && $tid !== (int) $badge->id()) {
        $ids[] = $tid;
      }
    }

    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
  }

  /**
   * Loads badge labels for user-facing reason strings.
   */
  protected function loadBadgeLabels(array $tids): array {
    if (!$tids) {
      return [];
    }
    $labels = [];
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);
    foreach ($tids as $tid) {
      $labels[] = isset($terms[$tid]) ? $terms[$tid]->label() : ('Badge ' . $tid);
    }
    return $labels;
  }

}
