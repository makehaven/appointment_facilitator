<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Reminds a facilitator about badges still pending after their appointment.
 *
 * A member books a checkout for a badge; afterwards the facilitator approves
 * the member's pending badge request. When that step is forgotten the member
 * cannot use the tool and nobody is told. Once a day, from the send hour,
 * each facilitator gets one email listing the appointments they hosted that
 * ended at least a day ago where the member still has a pending request for
 * a badge the appointment was for.
 *
 * One reminder per appointment, ever: a badge left pending on purpose (the
 * member did not come, or needs another session) is not nagged about.
 * Off unless `pending_badge_reminder_enabled` is set.
 */
final class PendingBadgeReminder {

  /**
   * Key-value collection of appointment ids already reminded about.
   */
  public const REMINDED = 'appointment_facilitator.pending_badge_reminded';

  /**
   * State key holding the Y-m-d of the last run.
   */
  private const STATE_LAST_RUN = 'appointment_facilitator.pending_badge_reminder_last_run';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MailManagerInterface $mailManager,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether reminders are switched on.
   */
  public function enabled(): bool {
    return (bool) $this->configFactory->get('appointment_facilitator.settings')->get('pending_badge_reminder_enabled');
  }

  /**
   * Cron entry point: once a day from the send hour, when switched on.
   *
   * @return int
   *   Emails sent.
   */
  public function processDaily(): int {
    if (!$this->enabled()) {
      return 0;
    }
    $now = $this->time->getCurrentTime();
    $today = date('Y-m-d', $now);
    $hour = (int) ($this->configFactory->get('appointment_facilitator.settings')->get('pending_badge_reminder_hour') ?? 9);
    if ((int) date('G', $now) < $hour || $this->state->get(self::STATE_LAST_RUN) === $today) {
      return 0;
    }
    // Marked first: a failure part-way must not turn into a resend loop.
    $this->state->set(self::STATE_LAST_RUN, $today);
    return $this->send($this->due($now));
  }

  /**
   * What is due now, grouped by facilitator.
   *
   * Public so the drush command can show a dry run.
   *
   * @return array<int, array<int, array{nid: int, member_uid: int, member: string, badges: string[], ended: int}>>
   *   Facilitator uid => appointments.
   */
  public function due(?int $now = NULL): array {
    $now ??= $this->time->getCurrentTime();
    $settings = $this->configFactory->get('appointment_facilitator.settings');
    $after = max(1, (int) ($settings->get('pending_badge_reminder_after_hours') ?? 24)) * 3600;
    $window = max(1, (int) ($settings->get('pending_badge_reminder_window_days') ?? 7)) * 86400;

    $rows = $this->pendingRows($now - $window, $now - $after);
    $reminded = $this->keyValue->get(self::REMINDED);
    $rows = array_values(array_filter($rows, static fn (array $r): bool => !$reminded->has((string) $r['nid'])));
    if (!$rows) {
      return [];
    }

    $uids = array_unique(array_merge(array_column($rows, 'host_uid'), array_column($rows, 'member_uid')));
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple(array_unique(array_column($rows, 'tid')));

    $names = [];
    $active = [];
    foreach ($users as $user) {
      $names[(int) $user->id()] = (string) $user->getDisplayName();
      $active[(int) $user->id()] = $user->isActive() && (string) $user->getEmail() !== '';
    }
    $labels = [];
    foreach ($terms as $term) {
      $labels[(int) $term->id()] = (string) $term->label();
    }
    return self::group($rows, $names, $labels, $active);
  }

  /**
   * Badges still pending after one facilitator's appointments (dashboard).
   *
   * Same match as the email, but for any appointment that ended in the last
   * `pending_badge_dashboard_days` (90) and including ones already emailed:
   * the email is a nudge, the dashboard is the full list until it is cleared.
   *
   * @return array<int, array{nid: int, member_uid: int, member: string, badges: string[], ended: int, reminded: int|null}>
   *   Newest appointment first.
   */
  public function forHost(int $hostUid, ?int $now = NULL): array {
    $now ??= $this->time->getCurrentTime();
    $days = max(1, (int) ($this->configFactory->get('appointment_facilitator.settings')->get('pending_badge_dashboard_days') ?? 90));
    $rows = $this->pendingRows($now - $days * 86400, $now, $hostUid);
    if (!$rows) {
      return [];
    }
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple(array_unique(array_column($rows, 'member_uid')));
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple(array_unique(array_column($rows, 'tid')));
    $names = [];
    foreach ($users as $user) {
      $names[(int) $user->id()] = (string) $user->getDisplayName();
    }
    $labels = [];
    foreach ($terms as $term) {
      $labels[(int) $term->id()] = (string) $term->label();
    }
    $items = self::group($rows, $names, $labels, [$hostUid => TRUE])[$hostUid] ?? [];
    $reminded = $this->keyValue->get(self::REMINDED)->getMultiple(array_map(static fn (array $i): string => (string) $i['nid'], $items));
    foreach ($items as &$item) {
      $item['reminded'] = isset($reminded[(string) $item['nid']]) ? (int) $reminded[(string) $item['nid']] : NULL;
    }
    unset($item);
    return array_reverse($items);
  }

  /**
   * Groups raw rows into one list per facilitator. Pure, so it is tested.
   *
   * @param array<int, array{nid: int|string, host_uid: int|string, member_uid: int|string, tid: int|string, ended: int|string}> $rows
   *   One row per appointment, member and pending badge.
   * @param array<int, string> $names
   *   Display name by uid.
   * @param array<int, string> $labels
   *   Badge name by term id.
   * @param array<int, bool> $emailable
   *   Whether each uid can be emailed (active, has an address).
   *
   * @return array<int, array<int, array{nid: int, member_uid: int, member: string, badges: string[], ended: int}>>
   *   Facilitator uid => appointments, oldest first.
   */
  public static function group(array $rows, array $names, array $labels, array $emailable): array {
    $out = [];
    foreach ($rows as $row) {
      $host = (int) $row['host_uid'];
      $member = (int) $row['member_uid'];
      $tid = (int) $row['tid'];
      // Nobody signs off their own badge, and a blocked facilitator or an
      // unnamed member or badge gives nothing to act on.
      if ($host === $member || empty($emailable[$host]) || !isset($names[$member], $labels[$tid])) {
        continue;
      }
      $key = (int) $row['nid'] . ':' . $member;
      $out[$host][$key] ??= [
        'nid' => (int) $row['nid'],
        'member_uid' => $member,
        'member' => $names[$member],
        'badges' => [],
        'ended' => (int) $row['ended'],
      ];
      if (!in_array($labels[$tid], $out[$host][$key]['badges'], TRUE)) {
        $out[$host][$key]['badges'][] = $labels[$tid];
      }
    }
    foreach ($out as $host => $items) {
      usort($items, static fn (array $a, array $b): int => $a['ended'] <=> $b['ended']);
      $out[$host] = $items;
    }
    return $out;
  }

  /**
   * The email for one facilitator. Pure, so the wording is tested.
   *
   * @param string $name
   *   The facilitator's display name.
   * @param array<int, array{member_uid: int, member: string, badges: string[], ended: int}> $items
   *   Their appointments with a badge still pending.
   * @param string $base
   *   Site address, no trailing slash.
   *
   * @return array{subject: string, body: string}
   *   Subject and plain-text body.
   */
  public static function compose(string $name, array $items, string $base): array {
    $count = count($items);
    $body = [
      'Hi ' . $name . ',',
      '',
      $count === 1
        ? 'This member had a badge checkout with you and the badge is still marked pending:'
        : 'These members had a badge checkout with you and their badges are still marked pending:',
      '',
    ];
    foreach ($items as $item) {
      $body[] = sprintf('- %s: %s (%s)', $item['member'], implode(', ', $item['badges']), date('D j M', (int) $item['ended']));
      $body[] = '  ' . $base . '/badges/pending/user/' . (int) $item['member_uid'];
    }
    $body[] = '';
    $body[] = 'If the checkout went well, approve the badge at the link so they can use the tool.';
    $body[] = 'If they did not come or need another session, leave it as it is. You will not be reminded about that appointment again.';
    $body[] = '';
    $body[] = 'Everything still waiting on you is at the top of your facilitator dashboard:';
    $body[] = $base . '/facilitator/dashboard';
    $body[] = '';
    $body[] = 'Thank you for facilitating.';
    return [
      'subject' => $count === 1
        ? sprintf('A badge is waiting for your sign-off: %s', $items[0]['member'])
        : sprintf('%d badges are waiting for your sign-off', $count),
      'body' => implode("\n", $body),
    ];
  }

  /**
   * Sends one email per facilitator and records what was reminded.
   *
   * @return int
   *   Emails the mail system accepted.
   */
  public function send(array $due): int {
    if (!$due) {
      return 0;
    }
    $base = rtrim((string) ($this->configFactory->get('appointment_facilitator.settings')->get('pending_badge_reminder_base_url') ?: $GLOBALS['base_url'] ?? ''), '/');
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple(array_keys($due));
    $reminded = $this->keyValue->get(self::REMINDED);
    $sent = 0;
    foreach ($due as $uid => $items) {
      $user = $users[$uid] ?? NULL;
      if (!$user) {
        continue;
      }
      $mail = self::compose((string) $user->getDisplayName(), $items, $base);
      $result = $this->mailManager->mail('appointment_facilitator', 'pending_badges_reminder', (string) $user->getEmail(), $user->getPreferredLangcode(), $mail);
      if (empty($result['result'])) {
        $this->logger->warning('Pending-badge reminder to facilitator @uid was not accepted by the mail system.', ['@uid' => $uid]);
        continue;
      }
      $sent++;
      foreach ($items as $item) {
        $reminded->set((string) $item['nid'], $this->time->getCurrentTime());
      }
    }
    if ($sent) {
      $this->logger->info('Sent @n pending-badge reminder(s) to facilitators.', ['@n' => $sent]);
    }
    return $sent;
  }

  /**
   * Appointments that ended in the window with a matching pending request.
   *
   * A match is a published, pending badge_request for a badge the
   * appointment lists, for the person who booked it or joined it.
   *
   * @return array<int, array{nid: string, host_uid: string, member_uid: string, tid: string, ended: string}>
   *   One row per appointment, member and badge.
   */
  private function pendingRows(int $endedFrom, int $endedTo, ?int $hostUid = NULL): array {
    $schema = $this->database->schema();
    foreach (['node__field_appointment_badges', 'node__field_appointment_host', 'node__field_appointment_timerange', 'node__field_member_to_badge', 'node__field_badge_status', 'node__field_badge_requested'] as $table) {
      if (!$schema->tableExists($table)) {
        return [];
      }
    }
    $hasAttendees = $schema->tableExists('node__field_appointment_attendees');
    $hasStatus = $schema->tableExists('node__field_appointment_status');

    $build = function (bool $viaAttendees) use ($endedFrom, $endedTo, $hasStatus, $hostUid) {
      $q = $this->database->select('node__field_appointment_badges', 'ab');
      $q->innerJoin('node_field_data', 'n', 'n.nid = ab.entity_id AND n.status = 1 AND n.type = :appt', [':appt' => 'appointment']);
      $q->innerJoin('node__field_appointment_host', 'h', 'h.entity_id = ab.entity_id');
      $q->innerJoin('node__field_appointment_timerange', 't', 't.entity_id = ab.entity_id');
      if ($viaAttendees) {
        $q->innerJoin('node__field_appointment_attendees', 'att', 'att.entity_id = ab.entity_id');
        $member = 'att.field_appointment_attendees_target_id';
      }
      else {
        $member = 'n.uid';
      }
      $q->innerJoin('node__field_badge_requested', 'rb', 'rb.field_badge_requested_target_id = ab.field_appointment_badges_target_id');
      $q->innerJoin('node__field_member_to_badge', 'rm', 'rm.entity_id = rb.entity_id AND rm.field_member_to_badge_target_id = ' . $member);
      $q->innerJoin('node__field_badge_status', 'rs', 'rs.entity_id = rb.entity_id AND rs.field_badge_status_value = :pending', [':pending' => 'pending']);
      $q->innerJoin('node_field_data', 'rn', 'rn.nid = rb.entity_id AND rn.status = 1 AND rn.type = :req', [':req' => 'badge_request']);
      if ($hasStatus) {
        $q->leftJoin('node__field_appointment_status', 'st', 'st.entity_id = ab.entity_id');
        $or = $q->orConditionGroup()->isNull('st.field_appointment_status_value')->condition('st.field_appointment_status_value', 'canceled', '<>');
        $q->condition($or);
      }
      $q->condition('t.field_appointment_timerange_end_value', [$endedFrom, $endedTo], 'BETWEEN');
      if ($hostUid !== NULL) {
        $q->condition('h.field_appointment_host_target_id', $hostUid);
      }
      $q->addField('ab', 'entity_id', 'nid');
      $q->addField('h', 'field_appointment_host_target_id', 'host_uid');
      $q->addExpression($member, 'member_uid');
      $q->addField('ab', 'field_appointment_badges_target_id', 'tid');
      $q->addExpression('MAX(t.field_appointment_timerange_end_value)', 'ended');
      $q->groupBy('ab.entity_id')->groupBy('h.field_appointment_host_target_id')->groupBy($member)->groupBy('ab.field_appointment_badges_target_id');
      return $q->execute()->fetchAll(\PDO::FETCH_ASSOC);
    };

    $rows = $build(FALSE);
    if ($hasAttendees) {
      $rows = array_merge($rows, $build(TRUE));
    }
    // The booker can also be listed as an attendee: keep one of each.
    $seen = [];
    $out = [];
    foreach ($rows as $row) {
      $key = $row['nid'] . ':' . $row['member_uid'] . ':' . $row['tid'];
      if (!isset($seen[$key])) {
        $seen[$key] = TRUE;
        $out[] = $row;
      }
    }
    return $out;
  }

  /**
   * Uids of members with any pending badge request (for the card button).
   *
   * @return array<int, true>
   *   Keyed by uid.
   */
  public function membersWithPendingRequests(): array {
    $schema = $this->database->schema();
    if (!$schema->tableExists('node__field_member_to_badge') || !$schema->tableExists('node__field_badge_status')) {
      return [];
    }
    $q = $this->database->select('node__field_member_to_badge', 'm');
    $q->innerJoin('node__field_badge_status', 's', 's.entity_id = m.entity_id AND s.field_badge_status_value = :pending', [':pending' => 'pending']);
    $q->innerJoin('node_field_data', 'n', 'n.nid = m.entity_id AND n.status = 1 AND n.type = :req', [':req' => 'badge_request']);
    $q->addField('m', 'field_member_to_badge_target_id', 'uid');
    $q->distinct();
    return array_fill_keys(array_map('intval', $q->execute()->fetchCol()), TRUE);
  }

}
