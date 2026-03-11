<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Member-facing appointment dashboard at /appointments.
 *
 * Shows the current user's hosting and attending appointments, color-coded
 * by role, with an Upcoming/Past JS toggle. No inline JS in view config.
 */
class AppointmentDashboardController extends ControllerBase {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManagerService,
    protected readonly DateFormatterInterface $dateFormatterService,
    protected readonly EntityFieldManagerInterface $entityFieldManagerService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * Renders the appointments dashboard page.
   */
  public function dashboard(): array {
    $uid = (int) $this->currentUser()->id();
    $now = \Drupal::time()->getRequestTime();

    $upcoming = $this->loadMyAppointments($uid, $now, TRUE);
    $past     = $this->loadMyAppointments($uid, $now, FALSE);

    return [
      '#theme'    => 'appointment_dashboard',
      '#upcoming' => $upcoming,
      '#past'     => $past,
      '#attached' => ['library' => ['appointment_facilitator/appointments']],
      '#cache'    => [
        'contexts' => ['user', 'user.permissions'],
        'tags'     => ['node_list:appointment'],
        'max-age'  => 0,
      ],
    ];
  }

  /**
   * Loads the current user's appointments (hosting + attending), merged.
   *
   * @param int  $uid      Current user ID.
   * @param int  $now      Request timestamp.
   * @param bool $upcoming TRUE for upcoming, FALSE for past.
   *
   * @return array  Array of #theme => appointment_card render arrays.
   */
  protected function loadMyAppointments(int $uid, int $now, bool $upcoming): array {
    $storage  = $this->entityTypeManagerService->getStorage('node');
    $op       = $upcoming ? '>=' : '<';
    $sort_dir = $upcoming ? 'ASC' : 'DESC';

    $base = static function () use ($storage, $uid, $now, $op, $sort_dir): object {
      return $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'appointment')
        ->condition('status', 1)
        ->condition('field_appointment_timerange.value', $now, $op)
        ->sort('field_appointment_timerange.value', $sort_dir);
    };

    // Hosting: field_appointment_host = current user.
    $hosting_q = $base();
    $hosting_q->condition('field_appointment_host.target_id', $uid);
    if ($this->fieldExists('field_appointment_status')) {
      $hosting_q->condition('field_appointment_status.value', 'canceled', '<>');
    }
    $hosting_nids = array_values($hosting_q->execute());

    // Attending: node author = current user.
    $attending_q = $base();
    $attending_q->condition('uid', $uid);
    if ($this->fieldExists('field_appointment_status')) {
      $attending_q->condition('field_appointment_status.value', 'canceled', '<>');
    }
    $attending_nids = array_values($attending_q->execute());

    // Merge, deduplicate, load.
    $all_nids = array_unique(array_merge($hosting_nids, $attending_nids));
    if (!$all_nids) {
      return [];
    }

    $nodes = $storage->loadMultiple($all_nids);
    $cards = [];

    foreach ($nodes as $node) {
      $nid     = (int) $node->id();
      $is_host = in_array($nid, $hosting_nids, TRUE);
      $role    = $is_host
        ? ($upcoming ? 'hosting' : 'hosted')
        : ($upcoming ? 'attending' : 'attended');
      $cards[] = $this->buildCard($node, $role, !$upcoming);
    }

    // Sort merged list by start timestamp.
    usort($cards, static function (array $a, array $b) use ($upcoming): int {
      return $upcoming
        ? $a['#sort_value'] <=> $b['#sort_value']
        : $b['#sort_value'] <=> $a['#sort_value'];
    });

    return $cards;
  }

  /**
   * Builds a single appointment card render array.
   */
  protected function buildCard(NodeInterface $node, string $role, bool $is_past): array {
    [$date_str, $time_str, $sort_val] = $this->extractDateTime($node);

    $host_name   = '';
    $member_name = '';

    if ($node->hasField('field_appointment_host') && !$node->get('field_appointment_host')->isEmpty()) {
      $host_entity = $node->get('field_appointment_host')->entity;
      $host_name   = $host_entity ? $host_entity->getDisplayName() : '';
    }

    $owner = $node->getOwner();
    $member_name = $owner ? $owner->getDisplayName() : '';

    $purpose = '';
    if ($node->hasField('field_appointment_purpose') && !$node->get('field_appointment_purpose')->isEmpty()) {
      $purpose = (string) $node->get('field_appointment_purpose')->first()?->getString();
    }

    $badges = [];
    if ($node->hasField('field_appointment_badges') && !$node->get('field_appointment_badges')->isEmpty()) {
      foreach ($node->get('field_appointment_badges')->referencedEntities() as $term) {
        $badges[] = $term->label();
      }
    }

    $note = '';
    if ($node->hasField('field_appointment_note') && !$node->get('field_appointment_note')->isEmpty()) {
      $note = (string) $node->get('field_appointment_note')->value;
    }

    $role_labels = [
      'hosting'  => 'Hosting',
      'hosted'   => 'Hosted',
      'attending' => 'Attending',
      'attended'  => 'Attended',
    ];

    return [
      '#theme'        => 'appointment_card',
      '#role'         => $role,
      '#role_label'   => $role_labels[$role] ?? $role,
      '#date'         => $date_str,
      '#time'         => $time_str,
      '#url'          => $node->toUrl()->toString(),
      '#host'         => $host_name,
      '#member'       => $member_name,
      '#purpose'      => $purpose,
      '#badges'       => $badges,
      '#note'         => $note,
      '#is_past'      => $is_past,
      '#feedback_url' => $is_past
        ? Url::fromRoute('appointment_facilitator.feedback_form', ['node' => $node->id()])->toString()
        : '',
      '#sort_value'   => $sort_val,
    ];
  }

  /**
   * Extracts date string, time range string, and sort timestamp from a node.
   *
   * @return array [date_str, time_str, sort_timestamp]
   */
  protected function extractDateTime(NodeInterface $node): array {
    if ($node->hasField('field_appointment_timerange') && !$node->get('field_appointment_timerange')->isEmpty()) {
      $item  = $node->get('field_appointment_timerange')->first();
      $start = (int) $item->value;
      $end   = (int) $item->end_value;

      $date_str  = $this->dateFormatterService->format($start, 'custom', 'D, M j');
      $start_str = $this->dateFormatterService->format($start, 'custom', 'g:i');
      $end_str   = $this->dateFormatterService->format($end,   'custom', 'g:i a');
      $time_str  = $start_str . '–' . $end_str;

      return [$date_str, $time_str, $start];
    }

    if ($node->hasField('field_appointment_date') && !$node->get('field_appointment_date')->isEmpty()) {
      $val  = (string) $node->get('field_appointment_date')->value;
      $ts   = strtotime($val);
      $date = $this->dateFormatterService->format($ts, 'custom', 'D, M j');
      return [$date, '', $ts];
    }

    return ['', '', 0];
  }

  /**
   * Checks whether a field exists on the appointment bundle.
   */
  protected function fieldExists(string $field_name): bool {
    $definitions = $this->entityFieldManagerService->getFieldDefinitions('node', 'appointment');
    return isset($definitions[$field_name]);
  }

}
