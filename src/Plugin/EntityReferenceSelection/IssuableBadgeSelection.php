<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\taxonomy\Plugin\EntityReferenceSelection\TermSelection;

/**
 * Badge checkout selection that honors BOTH issuer fields.
 *
 * The badge-checkout appointment widget must list every badge the chosen
 * facilitator can issue. The previous Views-based handler matched the
 * facilitator (the `host-uid` query parameter) against `field_badge_issuer`
 * ONLY, silently dropping facilitators who issue on request
 * (`field_badge_issuer_on_request`). That left members with an empty,
 * unselectable badge list and an impossible "select a badge" error whenever
 * they booked an on-request-only facilitator.
 *
 * The booking schedule (`getFacilitatorsForBadge()`) and the badge-request
 * approval check both already treat either issuer field as "can issue". This
 * handler brings the checkout widget in line, so the options shown — and the
 * save-time ValidReference validation, which re-runs this same query — match
 * the rest of the system.
 */
#[EntityReferenceSelection(
  id: "appointment_facilitator_issuable_badge:taxonomy_term",
  label: new TranslatableMarkup("Badges issuable by the chosen facilitator"),
  entity_types: ["taxonomy_term"],
  // The group MUST match the part of the id before ':'. Core's
  // field_config_presave (FieldHooks::fieldConfigCreate) resolves the handler
  // by treating that prefix as a selection group, so a mismatched group throws
  // on any non-sync save. A dedicated group (not "default") also keeps this
  // handler from ever shadowing the default selection for other term fields.
  group: "appointment_facilitator_issuable_badge",
  weight: 1,
)]
class IssuableBadgeSelection extends TermSelection {

  /**
   * {@inheritdoc}
   *
   * The options_buttons widget calls this with no match/limit, and
   * TermSelection answers it from loadTree() — bypassing buildEntityQuery().
   * Filter the tree result here so the checkbox list reflects the issuer
   * restriction, then rely on buildEntityQuery() (below) for the autocomplete
   * and save-time validation paths.
   */
  public function getReferenceableEntities($match = NULL, $match_operator = 'CONTAINS', $limit = 0) {
    $options = parent::getReferenceableEntities($match, $match_operator, $limit);

    $allowed = $this->resolveIssuableBadgeTids();
    if ($allowed === NULL) {
      return $options;
    }

    $allowed = array_flip($allowed);
    foreach ($options as $bundle => $terms) {
      foreach ($terms as $tid => $label) {
        if (!isset($allowed[(int) $tid])) {
          unset($options[$bundle][$tid]);
        }
      }
      if (empty($options[$bundle])) {
        unset($options[$bundle]);
      }
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $query = parent::buildEntityQuery($match, $match_operator);

    $allowed = $this->resolveIssuableBadgeTids();
    if ($allowed !== NULL) {
      // An empty set yields no rows (0 is never a valid term id).
      $query->condition('tid', $allowed ?: [0], 'IN');
    }

    return $query;
  }

  /**
   * Resolves the badge term ids the chosen facilitator may issue.
   *
   * @return int[]|null
   *   The issuable badge term ids (via EITHER issuer field), or NULL when no
   *   facilitator is selected yet — in which case no restriction is applied so
   *   the widget is never empty.
   */
  protected function resolveIssuableBadgeTids(): ?array {
    $host_uid = $this->resolveFacilitatorUid();
    if ($host_uid <= 0) {
      return NULL;
    }

    $host = $this->entityTypeManager->getStorage('user')->load($host_uid);
    if (!$host instanceof AccountInterface) {
      return NULL;
    }

    return _appointment_facilitator_get_badge_issuer_term_ids($host);
  }

  /**
   * Resolves the chosen facilitator user id from the current request.
   *
   * Mirrors the `host-uid` (with legacy `host`) query parameter the booking
   * links and the appointment form alter already use.
   */
  protected function resolveFacilitatorUid(): int {
    $request = \Drupal::request();
    return (int) $request->query->get('host-uid', $request->query->get('host', 0));
  }

}
