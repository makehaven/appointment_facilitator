<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;

/**
 * What a facilitator says happened to a member at a badge appointment.
 *
 * Recorded when the badge is NOT approved, so a pending badge after an
 * appointment stops being ambiguous: the member did not come, needs another
 * session, or the session itself did not happen. Approval is still the badge
 * request turning Active. The badge request stays pending either way, so the
 * member can book again. One row per appointment and member.
 */
final class AppointmentOutcomes {

  public const TABLE = 'appointment_facilitator_badge_outcome';

  public const NO_SHOW = 'no_show';
  public const RETRY = 'retry';
  public const NOT_HELD = 'not_held';

  /**
   * Cache tag for anything that lists pending badges after appointments.
   */
  public const CACHE_TAG = 'appointment_facilitator_badge_outcome';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Button and history labels.
   *
   * @return array<string, string>
   *   Outcome => label.
   */
  public static function labels(): array {
    return [
      self::NO_SHOW => 'No-show',
      self::RETRY => 'Needs another session',
      self::NOT_HELD => "Didn't happen",
    ];
  }

  /**
   * Whether the table exists yet (before updb it does not).
   */
  public function available(): bool {
    return $this->database->schema()->tableExists(self::TABLE);
  }

  /**
   * Records one outcome for a member at each of these appointments.
   *
   * @param int[] $nids
   *   Appointment node ids.
   * @param int $memberUid
   *   The member the outcome is about.
   * @param string $outcome
   *   One of the keys of labels().
   * @param int $byUid
   *   Who is recording it.
   */
  public function record(array $nids, int $memberUid, string $outcome, int $byUid): void {
    if (!isset(self::labels()[$outcome]) || !$this->available()) {
      return;
    }
    foreach (array_unique(array_map('intval', $nids)) as $nid) {
      $this->database->merge(self::TABLE)
        ->keys(['nid' => $nid, 'member_uid' => $memberUid])
        ->fields(['outcome' => $outcome, 'uid' => $byUid, 'created' => $this->time->getRequestTime()])
        ->execute();
    }
    Cache::invalidateTags([self::CACHE_TAG]);
  }

  /**
   * Removes the outcome (undo).
   *
   * @param int[] $nids
   *   Appointment node ids.
   * @param int $memberUid
   *   The member.
   */
  public function clear(array $nids, int $memberUid): void {
    if (!$nids || !$this->available()) {
      return;
    }
    $this->database->delete(self::TABLE)
      ->condition('nid', array_map('intval', $nids), 'IN')
      ->condition('member_uid', $memberUid)
      ->execute();
    Cache::invalidateTags([self::CACHE_TAG]);
  }

  /**
   * Outcomes recorded for these appointments.
   *
   * @param int[] $nids
   *   Appointment node ids.
   *
   * @return array<string, array{outcome: string, uid: int, created: int}>
   *   Keyed "nid:member_uid".
   */
  public function forAppointments(array $nids): array {
    if (!$nids || !$this->available()) {
      return [];
    }
    $out = [];
    $rows = $this->database->select(self::TABLE, 'o')
      ->fields('o', ['nid', 'member_uid', 'outcome', 'uid', 'created'])
      ->condition('nid', array_unique(array_map('intval', $nids)), 'IN')
      ->execute();
    foreach ($rows as $row) {
      $out[$row->nid . ':' . $row->member_uid] = [
        'outcome' => (string) $row->outcome,
        'uid' => (int) $row->uid,
        'created' => (int) $row->created,
      ];
    }
    return $out;
  }

  /**
   * The booker's own report on each appointment (field_appointment_result).
   *
   * Only the person who booked can report, so joiners have none.
   *
   * @param int[] $nids
   *   Appointment node ids.
   *
   * @return array<int, array{member_uid: int, result: string}>
   *   Keyed by appointment nid.
   */
  public function memberReports(array $nids): array {
    if (!$nids || !$this->database->schema()->tableExists('node__field_appointment_result')) {
      return [];
    }
    $q = $this->database->select('node__field_appointment_result', 'r');
    $q->innerJoin('node_field_data', 'n', 'n.nid = r.entity_id');
    $q->addField('r', 'entity_id', 'nid');
    $q->addField('n', 'uid', 'member_uid');
    $q->addField('r', 'field_appointment_result_value', 'result');
    $q->condition('r.entity_id', array_unique(array_map('intval', $nids)), 'IN');
    $out = [];
    foreach ($q->execute() as $row) {
      $out[(int) $row->nid] = ['member_uid' => (int) $row->member_uid, 'result' => (string) $row->result];
    }
    return $out;
  }

  /**
   * Plain wording for a member's report. Pure.
   */
  public static function reportLabel(string $result): string {
    return match ($result) {
      'met_successful' => 'Member reported: met, session went well',
      'met_unsuccesful' => 'Member reported: met, but something went wrong',
      'member_absent' => 'Member reported: they could not attend',
      'volunteer_absent' => 'Member reported: the facilitator did not show',
      default => '',
    };
  }

  /**
   * Outcomes this person recorded since a time, newest first (for undo).
   *
   * @return array<int, array{nid: int, member_uid: int, outcome: string, created: int}>
   *   Rows.
   */
  public function recordedBy(int $uid, int $since): array {
    if (!$this->available()) {
      return [];
    }
    $rows = $this->database->select(self::TABLE, 'o')
      ->fields('o', ['nid', 'member_uid', 'outcome', 'created'])
      ->condition('uid', $uid)
      ->condition('created', $since, '>=')
      ->orderBy('created', 'DESC')
      ->range(0, 50)
      ->execute();
    $out = [];
    foreach ($rows as $row) {
      $out[] = [
        'nid' => (int) $row->nid,
        'member_uid' => (int) $row->member_uid,
        'outcome' => (string) $row->outcome,
        'created' => (int) $row->created,
      ];
    }
    return $out;
  }

}
