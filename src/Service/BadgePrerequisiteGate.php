<?php

namespace Drupal\appointment_facilitator\Service;

use Drupal\asset_status\Service\AssetAvailability;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\node\NodeInterface;

/**
 * Evaluates badge prerequisite gates for a member.
 */
class BadgePrerequisiteGate {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly ?AssetAvailability $assetAvailability = NULL,
    protected readonly ?Connection $database = NULL,
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
   * Evaluates whether a member can request a badge and book a checkout.
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
   *   - class_registration_satisfies_docs: bool — TRUE when the member has a
   *     non-cancelled CiviCRM registration for an event tied to this badge,
   *     which bypasses the otherwise-blocking docs gate. The badge itself
   *     still flows through the normal pending → checkout sequence; this only
   *     unlocks quiz access so the typical "quiz before class" path works.
   *   - prerequisites_required: int[]
   *   - prerequisites_missing: int[]
   *   - prerequisites_missing_labels: string[]
   *   - prerequisites_pending: int[] — allowed for preparation, not activation
   *   - prerequisites_pending_labels: string[]
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
      'class_registration_satisfies_docs' => FALSE,
      'prerequisites_required' => [],
      'prerequisites_missing' => [],
      'prerequisites_missing_labels' => [],
      'prerequisites_pending' => [],
      'prerequisites_pending_labels' => [],
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
        // Class-registration bypass: the documented MakeHaven flow is for
        // members to take the quiz BEFORE the class, so a non-cancelled
        // CiviCRM registration for any event whose `field_civi_event_badges`
        // includes this badge satisfies the docs gate for quiz access. The
        // badge itself still goes through pending → facilitator checkout —
        // this only opens the quiz door, it doesn't grant the badge.
        if ($this->hasActiveClassRegistrationForBadge($memberUid, (int) $badge->id())) {
          $result['class_registration_satisfies_docs'] = TRUE;
        }
        else {
          $result['allowed'] = FALSE;
          $result['reasons'][] = 'Documentation approval is required before this badge can be requested.';
        }
      }
    }

    $prerequisites = $this->evaluatePrerequisites($memberUid, $badge, TRUE);
    $result['allowed'] = $result['allowed'] && $prerequisites['allowed'];
    $result['reasons'] = array_merge($result['reasons'], $prerequisites['reasons']);
    foreach ($prerequisites as $key => $value) {
      if (str_starts_with($key, 'prerequisites_')) {
        $result[$key] = $value;
      }
    }

    return $result;
  }

  /**
   * Checks badge prerequisites independently of training documentation.
   *
   * Pending prerequisites allow requests and appointments. Only earned
   * prerequisites (including legacy blank statuses) allow activation.
   */
  public function evaluatePrerequisites(int $memberUid, TermInterface $badge, bool $allowPending = FALSE): array {
    $required = $this->extractPrerequisiteBadgeIds($badge);
    $missing = [];
    $pending = [];
    foreach ($required as $tid) {
      if ($this->memberHasActiveOrBlankBadge($memberUid, $tid)) {
        continue;
      }
      if ($this->memberHasBadgeStatus($memberUid, $tid, ['pending'])) {
        $pending[] = $tid;
        if ($allowPending) {
          continue;
        }
      }
      $missing[] = $tid;
    }
    $labels = $this->loadBadgeLabels($missing);
    return [
      'allowed' => !$missing,
      'prerequisites_required' => $required,
      'prerequisites_missing' => $missing,
      'prerequisites_missing_labels' => $labels,
      'prerequisites_pending' => $pending,
      'prerequisites_pending_labels' => $this->loadBadgeLabels($pending),
      'reasons' => $missing ? [($allowPending
        ? 'First get these prerequisite badges pending or earned: '
        : 'First approve these prerequisite badges: ') . implode(', ', $labels) . '.',
      ] : [],
    ];
  }

  /**
   * Checks new checkout bookings while preserving existing sessions.
   */
  public function appointmentPrerequisiteViolation(NodeInterface $node): ?string {
    if ($node->bundle() !== 'appointment' || !$node->hasField('field_appointment_purpose')
      || $node->get('field_appointment_purpose')->value !== 'checkout'
      || !$node->hasField('field_appointment_badges')) {
      return NULL;
    }
    $original = $node->isNew() ? NULL : $this->entityTypeManager->getStorage('node')->loadUnchanged($node->id());
    if ($original instanceof NodeInterface
      && (int) $original->getOwnerId() === (int) $node->getOwnerId()
      && $original->get('field_appointment_purpose')->value === 'checkout'
      && $original->get('field_appointment_badges')->getValue() === $node->get('field_appointment_badges')->getValue()) {
      return NULL;
    }
    $uid = (int) $node->getOwnerId();
    foreach ($node->get('field_appointment_badges')->getValue() as $item) {
      $tid = (int) ($item['target_id'] ?? 0);
      if ($this->memberHasActiveOrBlankBadge($uid, $tid)) {
        continue;
      }
      $badge = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
      if ($badge instanceof TermInterface) {
        $gate = $this->evaluatePrerequisites($uid, $badge, TRUE);
        if (!$gate['allowed']) {
          return $badge->label() . ': ' . implode(' ', $gate['reasons']);
        }
      }
    }
    return NULL;
  }

  /**
   * Returns an error for a new request/award, without auditing old awards.
   *
   * Both form validation and presave use this, including API/automated saves.
   * Changing the member, badge, or publication state is a new grant, even if
   * the status string is unchanged. Ordinary edits to earned badges are exempt.
   */
  public function badgeRequestViolation(NodeInterface $node): ?string {
    if ($node->bundle() !== 'badge_request' || !$node->hasField('field_badge_status')
      || !$node->isPublished()) {
      return NULL;
    }
    $status = strtolower(trim((string) $node->get('field_badge_status')->value));
    if (!in_array($status, ['', 'active', 'pending'], TRUE)) {
      return NULL;
    }
    $original = $node->isNew() ? NULL : $this->entityTypeManager->getStorage('node')->loadUnchanged($node->id());
    if ($original instanceof NodeInterface && $original->isPublished()
      && $original->get('field_badge_requested')->getValue() === $node->get('field_badge_requested')->getValue()
      && $original->get('field_member_to_badge')->getValue() === $node->get('field_member_to_badge')->getValue()) {
      $old = strtolower(trim((string) $original->get('field_badge_status')->value));
      if (($status === 'pending' && $old === 'pending')
        || (in_array($status, ['', 'active'], TRUE) && in_array($old, ['', 'active'], TRUE))) {
        return NULL;
      }
    }
    $badge = $this->entityTypeManager->getStorage('taxonomy_term')->load((int) $node->get('field_badge_requested')->target_id);
    if (!$badge instanceof TermInterface) {
      return NULL;
    }
    $uid = (int) $node->get('field_member_to_badge')->target_id;
    $gate = $this->evaluatePrerequisites($uid, $badge, $status === 'pending');
    return $gate['allowed'] ? NULL : $badge->label() . ': ' . implode(' ', $gate['reasons']);
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
   * Returns TRUE when the member has a non-cancelled CiviCRM registration for
   * any event whose `field_civi_event_badges` includes this badge.
   *
   * "Non-cancelled" mirrors the filter used by instructor_companion's
   * AttendanceManager: status names in {Cancelled, Rejected, Transferred,
   * Expired} are treated as out. Everything else — Registered, On waitlist,
   * Attended, Pending from waitlist, etc. — counts as an active link to a
   * class that earns this badge, and is enough to unlock quiz access.
   *
   * All failure modes (no database, no civicrm_event entity, missing field,
   * no contact_id mapping) return FALSE so the docs gate falls back to its
   * normal behavior — this method only ever flips a member from "blocked"
   * to "allowed", never the other direction.
   */
  public function hasActiveClassRegistrationForBadge(int $memberUid, int $badgeTid): bool {
    if ($memberUid <= 0 || $badgeTid <= 0) {
      return FALSE;
    }
    if (!$this->database) {
      return FALSE;
    }
    if (!$this->entityTypeManager->hasDefinition('civicrm_event')) {
      return FALSE;
    }

    // Read the field table directly. An entity query on civicrm_event with a
    // condition on a Drupal-attached field silently returns nothing (the
    // civicrm_entity storage only translates base-field conditions), which
    // is why this bypass never fired in practice before 2026-09.
    try {
      $event_ids = $this->database->select('civicrm_event__field_civi_event_badges', 'eb')
        ->fields('eb', ['entity_id'])
        ->condition('eb.field_civi_event_badges_target_id', $badgeTid)
        ->condition('eb.deleted', 0)
        ->execute()
        ->fetchCol();
    }
    catch (\Throwable $e) {
      // field_civi_event_badges may not exist yet in some environments.
      return FALSE;
    }
    if (!$event_ids) {
      return FALSE;
    }
    $event_ids = array_map('intval', $event_ids);

    try {
      $contact_id = (int) $this->database->select('civicrm_uf_match', 'm')
        ->fields('m', ['contact_id'])
        ->condition('m.uf_id', $memberUid)
        ->execute()
        ->fetchField();
    }
    catch (\Throwable $e) {
      return FALSE;
    }
    if ($contact_id <= 0) {
      return FALSE;
    }

    try {
      $query = $this->database->select('civicrm_participant', 'p');
      $query->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
      $query->fields('p', ['id']);
      $query->condition('p.contact_id', $contact_id);
      $query->condition('p.event_id', array_values($event_ids), 'IN');
      $query->condition('pst.name', ['Cancelled', 'Rejected', 'Transferred', 'Expired'], 'NOT IN');
      $query->range(0, 1);
      return $query->execute()->fetchField() !== FALSE;
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

  /**
   * Returns TRUE when member has badge_request with active or blank status.
   */
  public function memberHasActiveOrBlankBadge(int $memberUid, int $badgeTid): bool {
    return $this->memberHasBadgeStatus($memberUid, $badgeTid, ['', 'active']);
  }

  /**
   * Checks published badge records using the supplied qualifying statuses.
   */
  protected function memberHasBadgeStatus(int $memberUid, int $badgeTid, array $statuses): bool {
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
      if (in_array($status, $statuses, TRUE)) {
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
