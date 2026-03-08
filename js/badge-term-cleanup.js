(function (Drupal) {
  'use strict';

  Drupal.behaviors.appointmentFacilitatorBadgeTermCleanup = {
    attach: function (context) {
      if (!context || context !== document) {
        return;
      }

      // Only run when the new schedule-first block is present.
      if (!document.querySelector('.badge-next-step-card--term-page')) {
        return;
      }

      var killHeadings = [
        'Facilitator Checkout',
        'Earn Badge at Upcoming Event'
      ];

      var candidates = document.querySelectorAll(
        '.field-group-fieldset, .field--name-facilitator-schedules-facilitator-schedule-tool-eva, .field--name-event-upcoming-badging-badge-events'
      );

      candidates.forEach(function (el) {
        var text = (el.textContent || '').trim();
        for (var i = 0; i < killHeadings.length; i++) {
          if (text.indexOf(killHeadings[i]) !== -1) {
            el.remove();
            break;
          }
        }
      });

      // Remove legacy facilitator issuer cards (old flow) by CTA text.
      var requestCtas = document.querySelectorAll('a, button');
      requestCtas.forEach(function (el) {
        var label = (el.textContent || '').trim();
        if (label.indexOf('Request Appointment') === -1) {
          return;
        }

        var wrapper = el.closest('.views-row, .field__item, .card, li, tr');
        if (wrapper) {
          wrapper.remove();
        }
      });

      // Remove legacy facilitator schedule view wrappers directly.
      var legacyViews = document.querySelectorAll(
        '.view-id-facilitator_schedules, .view-display-id-facilitator_schedule_tool_eva, .view-display-id-facilitator_profile_badger_list'
      );
      legacyViews.forEach(function (el) {
        var wrapper = el.closest('.field-group-fieldset, .field__item, .view');
        if (wrapper) {
          wrapper.remove();
          return;
        }
        el.remove();
      });
    }
  };
})(Drupal);
