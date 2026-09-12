<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Render;

use Drupal\Core\Render\Markup;
use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Post-render callback that pre-sets the appointment purpose on schedule links.
 *
 * The facilitator_schedules view hard-codes its "Request Appointment" links
 * (node/add/appointment/?host-uid=…&date=…) in a Views rewrite shared by every
 * display, so the appointment form defaults to "Badge Checkout". On a tool page
 * where the member already holds the badge the same grid is offered for
 * project advice, and the purpose should default accordingly. Rewriting the
 * rendered markup keeps the view config untouched and scopes the change to the
 * embed that asks for it. appointment_facilitator_form_node_form_alter() reads
 * the resulting `?purpose=` query parameter.
 */
final class ScheduleLinkPurpose implements TrustedCallbackInterface {

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['postRender'];
  }

  /**
   * Render API #post_render callback.
   *
   * @param \Drupal\Component\Render\MarkupInterface|string $markup
   *   The rendered children.
   * @param array $element
   *   The render element; reads `#mh_schedule_purpose` (default "project").
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The rewritten markup.
   */
  public static function postRender($markup, array $element) {
    $purpose = (string) ($element['#mh_schedule_purpose'] ?? 'project');
    return Markup::create(self::rewrite((string) $markup, $purpose));
  }

  /**
   * Adds `purpose=<value>` to every appointment-add link in the HTML.
   *
   * Links that already carry a purpose are left alone.
   *
   * @param string $html
   *   Rendered HTML.
   * @param string $purpose
   *   Machine name of a field_appointment_purpose option.
   *
   * @return string
   *   The rewritten HTML.
   */
  public static function rewrite(string $html, string $purpose): string {
    if ($purpose === '' || !preg_match('/^[a-z_]+$/', $purpose)) {
      return $html;
    }
    $result = preg_replace(
      '~(node/add/appointment/?\?)(?![^"\'\s>]*\bpurpose=)~',
      '${1}purpose=' . $purpose . '&amp;',
      $html
    );
    return $result ?? $html;
  }

}
