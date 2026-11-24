<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Controller;

use Drupal\appointment_facilitator\Service\AppointmentOutcomes;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Records what happened at a badge appointment that is not being approved.
 *
 * From the dashboard sign-off card: No-show, Needs another session, Didn't
 * happen, or undo. The badge request is left pending so the member can book
 * again; the row leaves the facilitator's list.
 */
final class BadgeOutcomeController extends ControllerBase {

  public function __construct(private readonly AppointmentOutcomes $outcomes) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('appointment_facilitator.outcomes'));
  }

  /**
   * Records (or undoes) the outcome for one member at the listed appointments.
   */
  public function record(int $member, string $outcome, Request $request): RedirectResponse {
    $back = Url::fromRoute('appointment_facilitator.dashboard')->toString();
    $nids = $this->allowedAppointments((string) $request->query->get('nids', ''), $member);
    if (!$nids) {
      $this->messenger()->addError($this->t('Nothing recorded: those appointments are not yours, or that member was not on them.'));
      return new RedirectResponse($back);
    }
    $account = $this->entityTypeManager()->getStorage('user')->load($member);
    $name = $account ? $account->getDisplayName() : (string) $this->t('the member');

    if ($outcome === 'undo') {
      $this->outcomes->clear($nids, $member);
      $this->messenger()->addStatus($this->t('Undone. @name is back on your list.', ['@name' => $name]));
      return new RedirectResponse($back);
    }

    $this->outcomes->record($nids, $member, $outcome, (int) $this->currentUser()->id());
    $this->messenger()->addStatus($this->t('Recorded "@outcome" for @name. Their badge stays pending so they can book again. To undo, use "You marked recently" at the bottom of the sign-off card.', [
      '@outcome' => AppointmentOutcomes::labels()[$outcome],
      '@name' => $name,
    ]));
    $this->getLogger('appointment_facilitator')->notice('Badge appointment outcome @outcome for member @member at appointment(s) @nids, recorded by uid @uid.', [
      '@outcome' => $outcome,
      '@member' => $member,
      '@nids' => implode(',', $nids),
      '@uid' => $this->currentUser()->id(),
    ]);
    return new RedirectResponse($back);
  }

  /**
   * The requested appointments this user may record on, with the member on it.
   *
   * The host of the appointment, or staff with the facilitator reports
   * permission. The member must be its booker or a joiner.
   *
   * @return int[]
   *   Appointment node ids.
   */
  private function allowedAppointments(string $list, int $member): array {
    $ids = array_filter(array_map('intval', explode(',', $list)));
    if (!$ids) {
      return [];
    }
    $uid = (int) $this->currentUser()->id();
    $staff = $this->currentUser()->hasPermission('view appointment facilitator reports');
    $ok = [];
    foreach ($this->entityTypeManager()->getStorage('node')->loadMultiple(array_slice($ids, 0, 20)) as $node) {
      if (!$node instanceof NodeInterface || $node->bundle() !== 'appointment') {
        continue;
      }
      $host = $node->hasField('field_appointment_host') ? (int) $node->get('field_appointment_host')->target_id : 0;
      if ($host !== $uid && !$staff) {
        continue;
      }
      $on = (int) $node->getOwnerId() === $member;
      if (!$on && $node->hasField('field_appointment_attendees')) {
        foreach ($node->get('field_appointment_attendees') as $item) {
          $on = $on || (int) $item->target_id === $member;
        }
      }
      if ($on) {
        $ok[] = (int) $node->id();
      }
    }
    return $ok;
  }

}
