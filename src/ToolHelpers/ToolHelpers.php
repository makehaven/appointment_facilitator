<?php

namespace Drupal\appointment_facilitator\ToolHelpers;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;

/**
 * Who can help with a tool, and asking them on Slack.
 *
 * Track: conductor/tracks/ask_the_right_people_20261109.
 *
 * "People to ask" (field_tool_helpers on item nodes) is the list staff keep
 * and members can join themselves. Facilitators who sign off the tool's badge
 * come next. Questions post publicly in the tool's channel (else its area's
 * channel) through the Member Sync bot, tagging up to three people chosen by
 * HelperRanker. Each question is logged in appointment_facilitator_tool_question
 * for rotation and the weekly count.
 */
class ToolHelpers {

  public const FIELD = 'field_tool_helpers';

  public const TABLE = 'appointment_facilitator_tool_question';

  /**
   * Roles treated as staff: tagged only when no volunteer can be.
   */
  public const STAFF_ROLES = ['manager', 'administrator'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected ?object $slackBot = NULL,
  ) {}

  /**
   * Whether the tool has the People to ask field.
   */
  public function applies(NodeInterface $tool): bool {
    return $tool->bundle() === 'item' && $tool->hasField(self::FIELD);
  }

  /**
   * Active people listed on the tool, in listed order.
   *
   * @return \Drupal\user\UserInterface[]
   *   Accounts keyed by uid.
   */
  public function listed(NodeInterface $tool): array {
    if (!$this->applies($tool)) {
      return [];
    }
    $people = [];
    foreach ($tool->get(self::FIELD)->referencedEntities() as $account) {
      if ($account instanceof UserInterface && $account->isActive()) {
        $people[(int) $account->id()] = $account;
      }
    }
    return $people;
  }

  /**
   * Badge terms the tool requires.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   Badge terms keyed by tid.
   */
  public function badges(NodeInterface $tool): array {
    if (!$tool->hasField('field_member_badges')) {
      return [];
    }
    $terms = [];
    foreach ($tool->get('field_member_badges')->referencedEntities() as $term) {
      if ($term instanceof TermInterface && $term->bundle() === 'badges') {
        $terms[(int) $term->id()] = $term;
      }
    }
    return $terms;
  }

  /**
   * Active people who sign off any of the tool's badges, issuers first.
   *
   * @return int[]
   *   User ids.
   */
  public function issuerUids(NodeInterface $tool): array {
    $uids = [];
    foreach (['field_badge_issuer', 'field_badge_issuer_on_request'] as $field) {
      foreach ($this->badges($tool) as $term) {
        if ($term->hasField($field)) {
          foreach ($term->get($field) as $item) {
            $uids[] = (int) $item->target_id;
          }
        }
      }
    }
    return $this->activeOnly(array_values(array_unique(array_filter($uids))));
  }

  /**
   * Slack channel name (no '#') for the tool: its own, else its area's.
   */
  public function channel(NodeInterface $tool): string {
    if ($tool->hasField('field_item_slack_channel') && !$tool->get('field_item_slack_channel')->isEmpty()) {
      $channel = ltrim(trim((string) $tool->get('field_item_slack_channel')->value), '#');
      if ($channel !== '') {
        return $channel;
      }
    }
    if ($tool->hasField('field_item_area_interest')) {
      foreach ($tool->get('field_item_area_interest')->referencedEntities() as $term) {
        if ($term->hasField('field_interest_slack_channel') && !$term->get('field_interest_slack_channel')->isEmpty()) {
          $channel = ltrim(trim((string) $term->get('field_interest_slack_channel')->value), '#');
          if ($channel !== '') {
            return $channel;
          }
        }
      }
    }
    return '';
  }

  /**
   * Browser link that opens a channel in Slack.
   */
  public function channelUrl(string $channel): string {
    return 'https://makehaven.slack.com/app_redirect?channel=' . rawurlencode($channel);
  }

  /**
   * Slack member IDs keyed by uid, for those who have one.
   *
   * @param int[] $uids
   *   User ids.
   *
   * @return array<int, string>
   *   Slack member IDs keyed by uid.
   */
  public function slackIds(array $uids): array {
    $uids = array_values(array_filter(array_map('intval', $uids)));
    if (!$uids || !$this->database->schema()->tableExists('profile__field_member_slack_id_number')) {
      return [];
    }
    $query = $this->database->select('profile', 'p');
    $query->innerJoin('profile__field_member_slack_id_number', 's', 's.entity_id = p.profile_id');
    $query->condition('p.type', 'main')
      ->condition('p.status', 1)
      ->condition('p.uid', $uids, 'IN');
    $query->addField('p', 'uid');
    $query->addField('s', 'field_member_slack_id_number_value', 'slack');
    $ids = [];
    foreach ($query->execute() as $row) {
      $slack = trim((string) $row->slack);
      if (preg_match('/^[UW][A-Z0-9]{6,}$/', $slack)) {
        $ids[(int) $row->uid] = $slack;
      }
    }
    return $ids;
  }

  /**
   * Whether the account is staff (tagged last).
   */
  public function isStaff(UserInterface $account): bool {
    return (bool) array_intersect(self::STAFF_ROLES, $account->getRoles());
  }

  /**
   * Uids among $uids who hold any of the tool's badges (active).
   *
   * @return int[]
   *   User ids.
   */
  public function badgeHolders(NodeInterface $tool, array $uids): array {
    $tids = array_keys($this->badges($tool));
    $uids = array_values(array_filter(array_map('intval', $uids)));
    if (!$tids || !$uids) {
      return [];
    }
    $query = $this->database->select('node__field_member_to_badge', 'm');
    $query->innerJoin('node__field_badge_requested', 'b', 'b.entity_id = m.entity_id');
    $query->innerJoin('node__field_badge_status', 's', 's.entity_id = m.entity_id');
    $query->innerJoin('node_field_data', 'n', 'n.nid = m.entity_id');
    $query->condition('n.type', 'badge_request')
      ->condition('n.status', 1)
      ->condition('b.field_badge_requested_target_id', $tids, 'IN')
      ->condition('s.field_badge_status_value', 'active')
      ->condition('m.field_member_to_badge_target_id', $uids, 'IN');
    $query->addField('m', 'field_member_to_badge_target_id', 'uid');
    $query->distinct();
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Whether a member may add themselves to People to ask.
   *
   * Members who hold one of the tool's badges, or any member when the tool
   * needs no badge. Willing is their call; able is the badge.
   */
  public function canVolunteer(NodeInterface $tool, UserInterface $account): bool {
    if (!$this->applies($tool) || !$account->hasRole('member')) {
      return FALSE;
    }
    if (!$this->badges($tool)) {
      return TRUE;
    }
    return (bool) $this->badgeHolders($tool, [(int) $account->id()]);
  }

  /**
   * People staff might ask to be listed, with the reasons.
   *
   * Signals, strongest first: signs off the badge; booked the tool often in
   * the last year (reservations); held the badge longest. Already-listed
   * people and blocked accounts are left out.
   *
   * @return array<int, array{uid: int, reasons: string[], score: int}>
   *   Suggestions keyed by uid, best first.
   */
  public function suggestions(NodeInterface $tool, int $limit = 12): array {
    $listed = array_keys($this->listed($tool));
    $out = [];
    $add = function (int $uid, string $reason, int $score) use (&$out, $listed): void {
      if ($uid <= 0 || in_array($uid, $listed, TRUE)) {
        return;
      }
      $out[$uid] ??= ['uid' => $uid, 'reasons' => [], 'score' => 0];
      $out[$uid]['reasons'][] = $reason;
      $out[$uid]['score'] += $score;
    };

    foreach ($this->issuerUids($tool) as $uid) {
      $add($uid, 'Signs off the badge', 100);
    }

    foreach ($this->facilitatorsByFocus($tool) as $uid => $focus) {
      $add($uid, sprintf('Facilitator; focus: %s', $focus), 60);
    }

    foreach ($this->frequentUsers($tool) as $uid => $count) {
      $add($uid, sprintf('Booked it %d times in the last year', $count), min(80, 10 + 2 * $count));
    }

    foreach ($this->longestHolders($tool) as $uid => $since) {
      $add($uid, 'Has the badge since ' . date('Y', $since), 15);
    }

    $active = array_flip($this->activeOnly(array_keys($out)));
    $out = array_intersect_key($out, $active);
    uasort($out, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['uid'] <=> $b['uid']);
    return array_slice($out, 0, $limit, TRUE);
  }

  /**
   * Uid => focus line, for facilitators whose focus names the tool's area.
   *
   * The focus is free text ("Precious Plastics & Repair • Media Room"), so
   * match the tool's title, its channel name ("media-room" → "media room")
   * and its area names (before any parenthesis).
   */
  protected function facilitatorsByFocus(NodeInterface $tool): array {
    if (!$this->database->schema()->tableExists('profile__field_coordinator_focus')) {
      return [];
    }
    $needles = [(string) $tool->label()];
    $channel = $this->channel($tool);
    if ($channel !== '') {
      $needles[] = str_replace(['-', '_'], ' ', $channel);
    }
    if ($tool->hasField('field_item_area_interest')) {
      foreach ($tool->get('field_item_area_interest')->referencedEntities() as $term) {
        $needles[] = trim(preg_replace('/\s*\(.*$/', '', (string) $term->label()));
      }
    }
    $needles = array_values(array_unique(array_filter($needles, static fn(string $n): bool => mb_strlen($n) >= 4)));
    if (!$needles) {
      return [];
    }
    $query = $this->database->select('profile', 'p');
    $query->innerJoin('profile__field_coordinator_focus', 'f', 'f.entity_id = p.profile_id');
    $query->innerJoin('user__roles', 'r', "r.entity_id = p.uid AND r.roles_target_id = 'facilitator'");
    $query->condition('p.type', 'coordinator')->condition('p.status', 1);
    $or = $query->orConditionGroup();
    foreach ($needles as $needle) {
      $or->condition('f.field_coordinator_focus_value', '%' . $this->database->escapeLike($needle) . '%', 'LIKE');
    }
    $query->condition($or);
    $query->addField('p', 'uid');
    $query->addField('f', 'field_coordinator_focus_value', 'focus');
    $query->range(0, 10);
    return $query->execute()->fetchAllKeyed();
  }

  /**
   * Uid => reservations of this tool created in the last year (3 or more).
   */
  protected function frequentUsers(NodeInterface $tool): array {
    if (!$this->database->schema()->tableExists('node__field_reservation_asset')) {
      return [];
    }
    $query = $this->database->select('node_field_data', 'n');
    $query->innerJoin('node__field_reservation_asset', 'r', 'r.entity_id = n.nid');
    $query->condition('n.type', 'reservation')
      ->condition('r.field_reservation_asset_target_id', (int) $tool->id())
      ->condition('n.created', $this->time->getRequestTime() - 365 * 86400, '>');
    $query->addField('n', 'uid');
    $query->addExpression('COUNT(*)', 'c');
    $query->groupBy('n.uid');
    $query->having('COUNT(*) >= 3');
    $query->orderBy('c', 'DESC');
    $query->range(0, 10);
    return array_map('intval', $query->execute()->fetchAllKeyed());
  }

  /**
   * Uid => first badge date, for the members who have held it longest.
   */
  protected function longestHolders(NodeInterface $tool): array {
    $tids = array_keys($this->badges($tool));
    if (!$tids) {
      return [];
    }
    $query = $this->database->select('node__field_member_to_badge', 'm');
    $query->innerJoin('node__field_badge_requested', 'b', 'b.entity_id = m.entity_id');
    $query->innerJoin('node__field_badge_status', 's', 's.entity_id = m.entity_id');
    $query->innerJoin('node_field_data', 'n', 'n.nid = m.entity_id');
    $query->innerJoin('user__roles', 'ro', "ro.entity_id = m.field_member_to_badge_target_id AND ro.roles_target_id = 'member'");
    $query->condition('n.type', 'badge_request')
      ->condition('n.status', 1)
      ->condition('b.field_badge_requested_target_id', $tids, 'IN')
      ->condition('s.field_badge_status_value', 'active');
    $query->addField('m', 'field_member_to_badge_target_id', 'uid');
    $query->addExpression('MIN(n.created)', 'since');
    $query->groupBy('m.field_member_to_badge_target_id');
    $query->orderBy('since', 'ASC');
    $query->range(0, 5);
    return array_map('intval', $query->execute()->fetchAllKeyed());
  }

  /**
   * Keeps only active (not blocked) accounts, preserving order.
   *
   * @param int[] $uids
   *   User ids.
   *
   * @return int[]
   *   User ids.
   */
  public function activeOnly(array $uids): array {
    if (!$uids) {
      return [];
    }
    $active = $this->database->select('users_field_data', 'u')
      ->fields('u', ['uid'])
      ->condition('uid', $uids, 'IN')
      ->condition('status', 1)
      ->execute()
      ->fetchCol();
    $active = array_flip(array_map('intval', $active));
    return array_values(array_filter($uids, static fn(int $uid): bool => isset($active[$uid])));
  }

  /**
   * Chooses who to tag for a question about the tool.
   *
   * @return array<int, string>
   *   Map of uid => Slack ID, in tag order.
   */
  public function whoToTag(NodeInterface $tool, int $askerUid): array {
    $listed = array_keys($this->listed($tool));
    $issuers = $this->issuerUids($tool);
    $slack = $this->slackIds(array_merge($listed, $issuers));
    $taggable = static fn(array $uids): array => array_values(array_filter($uids, static fn(int $uid): bool => isset($slack[$uid])));

    $accounts = $this->entityTypeManager->getStorage('user')->loadMultiple(array_keys($slack));
    $staff = [];
    foreach ($accounts as $account) {
      if ($account instanceof UserInterface && $this->isStaff($account)) {
        $staff[] = (int) $account->id();
      }
    }

    [$weekCounts, $lastTagged] = $this->tagHistory(array_keys($slack));
    $picked = HelperRanker::pick([$taggable($listed), $taggable($issuers)], $weekCounts, $lastTagged, $staff, [$askerUid]);
    $out = [];
    foreach ($picked as $uid) {
      $out[$uid] = $slack[$uid];
    }
    return $out;
  }

  /**
   * Tag counts in the last 7 days and the last tag time, per uid.
   *
   * @return array{0: array<int, int>, 1: array<int, int>}
   *   Tag counts and last tag times, each keyed by uid.
   */
  public function tagHistory(array $uids): array {
    $counts = [];
    $last = [];
    if (!$uids || !$this->database->schema()->tableExists(self::TABLE)) {
      return [$counts, $last];
    }
    $now = $this->time->getRequestTime();
    $rows = $this->database->select(self::TABLE, 'q')
      ->fields('q', ['tagged', 'created'])
      ->condition('created', $now - 90 * 86400, '>')
      ->execute();
    $wanted = array_flip($uids);
    foreach ($rows as $row) {
      foreach (array_filter(array_map('intval', explode(',', (string) $row->tagged))) as $uid) {
        if (!isset($wanted[$uid])) {
          continue;
        }
        $last[$uid] = max($last[$uid] ?? 0, (int) $row->created);
        if ((int) $row->created > $now - 7 * 86400) {
          $counts[$uid] = ($counts[$uid] ?? 0) + 1;
        }
      }
    }
    return [$counts, $last];
  }

  /**
   * Posts a member's question in the tool's channel and logs it.
   *
   * @return array{channel: string, ts: string, permalink: string, tagged: int[]}
   *   Where it was posted and who was tagged.
   *
   * @throws \RuntimeException
   *   When there is no channel, the asker has no Slack ID, or Slack refuses.
   */
  public function ask(NodeInterface $tool, UserInterface $asker, string $question): array {
    $channel = $this->channel($tool);
    if ($channel === '') {
      throw new \RuntimeException('no_channel');
    }
    $askerSlack = $this->slackIds([(int) $asker->id()])[(int) $asker->id()] ?? '';
    if ($askerSlack === '') {
      throw new \RuntimeException('asker_not_on_slack');
    }
    if (!$this->slackBot) {
      throw new \RuntimeException('no_slack_bot');
    }

    $tag = $this->whoToTag($tool, (int) $asker->id());
    $text = HelperRanker::message(
      (string) $tool->label(),
      $tool->toUrl('canonical', ['absolute' => TRUE])->toString(),
      $askerSlack,
      $question,
      array_values($tag)
    );
    $ts = $this->slackBot->post($channel, $text);
    $channelId = method_exists($this->slackBot, 'resolveChannelId') ? (string) $this->slackBot->resolveChannelId($channel) : $channel;
    $permalink = method_exists($this->slackBot, 'permalink') ? (string) $this->slackBot->permalink($channelId, $ts) : '';

    if ($this->database->schema()->tableExists(self::TABLE)) {
      $this->database->insert(self::TABLE)->fields([
        'nid' => (int) $tool->id(),
        'uid' => (int) $asker->id(),
        'channel' => $channel,
        'ts' => $ts,
        'tagged' => implode(',', array_keys($tag)),
        'question' => mb_substr($question, 0, 2000),
        'created' => $this->time->getRequestTime(),
      ])->execute();
    }
    $this->loggerFactory->get('appointment_facilitator')->info('Tool question on @tool posted to #@channel, tagged @tagged.', [
      '@tool' => $tool->id(),
      '@channel' => $channel,
      '@tagged' => implode(',', array_keys($tag)) ?: 'nobody',
    ]);
    return ['channel' => $channel, 'ts' => $ts, 'permalink' => $permalink, 'tagged' => array_keys($tag)];
  }

}
