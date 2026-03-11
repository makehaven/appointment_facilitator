/**
 * @file
 * Upcoming/Past toggle and card click-through for the appointment dashboard.
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.appointmentDashboardFilter = {
    attach: function (context) {
      var dashboard = context.querySelector
        ? context.querySelector('.appt-dashboard')
        : null;
      if (!dashboard || dashboard.dataset.filterBound) {
        return;
      }
      dashboard.dataset.filterBound = 'true';

      // ── Filter bar toggle ──────────────────────────────────────────────
      var buttons  = dashboard.querySelectorAll('.appt-filter-btn');
      var sections = dashboard.querySelectorAll('.appt-section');

      buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
          var target = btn.dataset.target;

          buttons.forEach(function (b) {
            b.classList.remove('active');
            b.setAttribute('aria-selected', 'false');
          });
          btn.classList.add('active');
          btn.setAttribute('aria-selected', 'true');

          sections.forEach(function (section) {
            var isTarget = section.id === 'appt-' + target;
            section.hidden = !isTarget;
          });
        });
      });

      // ── Clickable cards ────────────────────────────────────────────────
      dashboard.querySelectorAll('.appt-card[data-href]').forEach(function (card) {
        card.addEventListener('click', function (e) {
          // Don't intercept clicks on links or buttons inside the card.
          if (e.target.closest('a, button')) {
            return;
          }
          window.location.href = card.dataset.href;
        });
      });
    }
  };

})(Drupal);
