<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * What the door log says about a member at a badge appointment.
 *
 * A scan during the appointment (from an hour before it to its end) is good
 * evidence the member came. A scan only at another time that day, or none,
 * means the facilitator should check before approving. It is evidence, never
 * proof: a scan does not mean the checkout happened or was passed, and a
 * member can walk in behind someone else without scanning.
 */
final class AppointmentDoorEvidence {

  public const IN_WINDOW = 'in_window';
  public const SAME_DAY = 'same_day';
  public const NONE = 'none';
  public const UNKNOWN = 'unknown';

  /**
   * Seconds before the start that still count as arriving for it.
   */
  public const EARLY = 3600;

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Classifies scan times against an appointment. Pure, so it is tested.
   *
   * @param int $start
   *   Appointment start.
   * @param int $end
   *   Appointment end.
   * @param int[] $scans
   *   The member's scan times on the appointment's day.
   *
   * @return array{status: string, time: int|null}
   *   Status constant and the scan that decided it (first in the window, or
   *   the closest one that day).
   */
  public static function classify(int $start, int $end, array $scans): array {
    sort($scans);
    foreach ($scans as $t) {
      if ($t >= $start - self::EARLY && $t <= $end) {
        return ['status' => self::IN_WINDOW, 'time' => (int) $t];
      }
    }
    if (!$scans) {
      return ['status' => self::NONE, 'time' => NULL];
    }
    usort($scans, static fn (int $a, int $b): int => abs($a - $start) <=> abs($b - $start));
    return ['status' => self::SAME_DAY, 'time' => (int) $scans[0]];
  }

  /**
   * Plain-language line for the email and the approval form. Pure.
   *
   * @param array{status: string, time: int|null} $door
   *   From classify().
   * @param string $name
   *   Member's first name or display name.
   * @param callable $time
   *   Formats a timestamp as a time of day, e.g. "6:52pm".
   */
  public static function describe(array $door, string $name, callable $time): string {
    return match ($door['status']) {
      self::IN_WINDOW => sprintf('Door log: %s scanned in at %s, during the appointment.', $name, $time((int) $door['time'])),
      self::SAME_DAY => sprintf('Door log: %s scanned in at %s that day, but not around the appointment time. Check before approving.', $name, $time((int) $door['time'])),
      self::NONE => sprintf('Door log: no scan for %s that day. They may not have come. Check before approving.', $name),
      default => 'Door log: not available.',
    };
  }

  /**
   * Door evidence for one member at one appointment.
   *
   * @return array{status: string, time: int|null, start: int, end: int}
   *   Status is UNKNOWN when the appointment has no time or there is no log.
   */
  public function forAppointment(int $nid, int $memberUid): array {
    $times = $this->appointmentTimes($nid);
    if (!$times) {
      return ['status' => self::UNKNOWN, 'time' => NULL, 'start' => 0, 'end' => 0];
    }
    [$start, $end] = $times;
    $scans = $this->scansOnDay($memberUid, $start);
    if ($scans === NULL) {
      return ['status' => self::UNKNOWN, 'time' => NULL, 'start' => $start, 'end' => $end];
    }
    return self::classify($start, $end, $scans) + ['start' => $start, 'end' => $end];
  }

  /**
   * The member's latest past appointment for a badge, any host.
   *
   * Booked by the member or joined by them, not cancelled, already ended.
   *
   * @return array{nid: int, host_uid: int, start: int, end: int}|null
   *   NULL when there is none.
   */
  public function latestAppointment(int $memberUid, int $badgeTid, int $now): ?array {
    $schema = $this->database->schema();
    foreach (['node__field_appointment_badges', 'node__field_appointment_timerange', 'node__field_appointment_host'] as $table) {
      if (!$schema->tableExists($table)) {
        return NULL;
      }
    }
    $best = NULL;
    foreach ([FALSE, TRUE] as $viaAttendees) {
      if ($viaAttendees && !$schema->tableExists('node__field_appointment_attendees')) {
        continue;
      }
      $q = $this->database->select('node__field_appointment_badges', 'ab');
      $q->innerJoin('node_field_data', 'n', 'n.nid = ab.entity_id AND n.status = 1 AND n.type = :appt', [':appt' => 'appointment']);
      $q->innerJoin('node__field_appointment_timerange', 't', 't.entity_id = ab.entity_id');
      $q->innerJoin('node__field_appointment_host', 'h', 'h.entity_id = ab.entity_id');
      if ($viaAttendees) {
        $q->innerJoin('node__field_appointment_attendees', 'att', 'att.entity_id = ab.entity_id AND att.field_appointment_attendees_target_id = :uid', [':uid' => $memberUid]);
      }
      else {
        $q->condition('n.uid', $memberUid);
      }
      if ($schema->tableExists('node__field_appointment_status')) {
        $q->leftJoin('node__field_appointment_status', 'st', 'st.entity_id = ab.entity_id');
        $q->condition($q->orConditionGroup()->isNull('st.field_appointment_status_value')->condition('st.field_appointment_status_value', 'canceled', '<>'));
      }
      $q->condition('ab.field_appointment_badges_target_id', $badgeTid);
      $q->condition('h.field_appointment_host_target_id', $memberUid, '<>');
      $q->condition('t.field_appointment_timerange_end_value', $now, '<=');
      $q->addField('ab', 'entity_id', 'nid');
      $q->addField('h', 'field_appointment_host_target_id', 'host_uid');
      $q->addField('t', 'field_appointment_timerange_value', 'start');
      $q->addField('t', 'field_appointment_timerange_end_value', 'end');
      $q->orderBy('t.field_appointment_timerange_end_value', 'DESC');
      $q->range(0, 1);
      $row = $q->execute()->fetchAssoc();
      if ($row && (!$best || (int) $row['end'] > $best['end'])) {
        $best = [
          'nid' => (int) $row['nid'],
          'host_uid' => (int) $row['host_uid'],
          'start' => (int) $row['start'],
          'end' => (int) $row['end'],
        ];
      }
    }
    return $best;
  }

  /**
   * Start and end of an appointment (first occurrence).
   *
   * @return int[]|null
   *   [start, end].
   */
  private function appointmentTimes(int $nid): ?array {
    if (!$this->database->schema()->tableExists('node__field_appointment_timerange')) {
      return NULL;
    }
    $row = $this->database->query(
      'SELECT MIN(field_appointment_timerange_value) AS s, MAX(field_appointment_timerange_end_value) AS e FROM {node__field_appointment_timerange} WHERE entity_id = :nid',
      [':nid' => $nid]
    )->fetchAssoc();
    return ($row && $row['s']) ? [(int) $row['s'], (int) $row['e']] : NULL;
  }

  /**
   * The member's scan times on the site-local day of $at.
   *
   * @return int[]|null
   *   NULL when there is no door log on this site.
   */
  private function scansOnDay(int $memberUid, int $at): ?array {
    $schema = $this->database->schema();
    if (!$schema->tableExists('access_control_log_field_data') || !$schema->tableExists('access_control_log__field_access_request_user')) {
      return NULL;
    }
    $tz = new \DateTimeZone((string) ($this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC'));
    $day = (new \DateTimeImmutable('@' . $at))->setTimezone($tz)->setTime(0, 0);
    $q = $this->database->select('access_control_log_field_data', 'l');
    $q->innerJoin('access_control_log__field_access_request_user', 'u', 'u.entity_id = l.id');
    $q->condition('u.field_access_request_user_target_id', $memberUid);
    $q->condition('l.created', [$day->getTimestamp(), $day->modify('+1 day')->getTimestamp() - 1], 'BETWEEN');
    $q->addField('l', 'created');
    return array_map('intval', $q->execute()->fetchCol());
  }

}
