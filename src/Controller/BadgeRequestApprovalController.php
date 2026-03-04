<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Handles one-click approval for pending badge requests.
 */
class BadgeRequestApprovalController extends ControllerBase {

  /**
   * Route access callback.
   */
  public function access(NodeInterface $node, AccountInterface $account): AccessResult {
    if ($node->bundle() !== 'badge_request' || !$node->hasField('field_badge_status')) {
      return AccessResult::forbidden();
    }

    $allowed = $account->hasPermission('administer nodes')
      || $account->hasPermission('approve badge requests')
      || $account->hasPermission('edit any badge_request content');

    return $allowed
      ? AccessResult::allowed()->cachePerPermissions()
      : AccessResult::forbidden()->cachePerPermissions();
  }

  /**
   * Marks a pending badge request as active and returns to destination.
   */
  public function approve(NodeInterface $node): RedirectResponse {
    if ($node->bundle() !== 'badge_request' || !$node->hasField('field_badge_status')) {
      $this->messenger()->addError($this->t('This item is not a badge request.'));
      return $this->redirectToNode($node);
    }

    $status = (string) $node->get('field_badge_status')->value;
    if ($status === 'active') {
      $this->messenger()->addStatus($this->t('Badge is already active.'));
      return $this->redirectToNode($node);
    }

    $node->set('field_badge_status', 'active');
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId((int) $this->currentUser()->id());
    $node->setRevisionLogMessage($this->t('Approved via quick action.'));
    $node->save();

    $this->messenger()->addStatus($this->t('Badge approved and set to Active.'));
    return $this->redirectToNode($node);
  }

  /**
   * Redirects to destination query parameter or node canonical page.
   */
  protected function redirectToNode(NodeInterface $node): RedirectResponse {
    $destination = (string) $this->getRequest()->query->get('destination', '');
    if ($destination !== '') {
      return new RedirectResponse(Url::fromUserInput('/' . ltrim($destination, '/'))->toString());
    }

    return new RedirectResponse($node->toUrl()->toString());
  }

}

