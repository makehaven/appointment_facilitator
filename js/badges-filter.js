(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.appointmentFacilitatorBadgesFilter = {
    attach: function (context) {
      once('badges-filter', '[data-badges-filter]', context).forEach(function (bar) {
        var view = bar.closest('.view-badges') || document.querySelector('.view-badges');
        if (!view) {
          return;
        }
        var rows = view.querySelectorAll('.views-row.badge-card-row');
        var count = bar.querySelector('[data-filter-count]');

        function matches(row) {
          var area = bar.querySelector('[data-filter="area"]').value;
          var checkout = bar.querySelector('[data-filter="checkout"]').value;
          var state = bar.querySelector('[data-filter="state"]').value;

          if (area) {
            var areas = (row.getAttribute('data-areas') || '').split(',');
            if (areas.indexOf(area) === -1) {
              return false;
            }
          }

          if (checkout) {
            var rowCheckout = (row.getAttribute('data-checkout') || '').toLowerCase();
            if (rowCheckout !== checkout) {
              return false;
            }
          }

          if (state) {
            var rowState = (row.getAttribute('data-state') || '').toLowerCase();
            if (state === 'mine' && rowState !== 'active') {
              return false;
            }
            if (state === 'pending' && rowState !== 'pending') {
              return false;
            }
            if (state === 'available' && rowState !== 'quiz_needed') {
              return false;
            }
            if (state === 'blocked'
              && rowState !== 'prereqs_missing'
              && rowState !== 'docs_required'
              && rowState !== 'suspended'
              && rowState !== 'expired') {
              return false;
            }
          }

          return true;
        }

        function apply() {
          var visible = 0;
          rows.forEach(function (row) {
            if (matches(row)) {
              row.style.display = '';
              visible += 1;
            }
            else {
              row.style.display = 'none';
            }
          });
          if (count) {
            count.textContent = visible + ' of ' + rows.length + ' badges';
          }
        }

        bar.querySelectorAll('select').forEach(function (sel) {
          sel.addEventListener('change', apply);
        });
        var reset = bar.querySelector('[data-filter-reset]');
        if (reset) {
          reset.addEventListener('click', function () {
            bar.querySelectorAll('select').forEach(function (sel) {
              sel.value = '';
            });
            apply();
          });
        }

        apply();
      });
    }
  };
})(Drupal, once);
