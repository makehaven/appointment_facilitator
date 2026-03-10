(function (Drupal) {
  'use strict';

  function toMinutesMap(settings) {
    if (!settings || typeof settings !== 'object') {
      return {};
    }
    return settings.badgeCheckoutMinutes && typeof settings.badgeCheckoutMinutes === 'object'
      ? settings.badgeCheckoutMinutes
      : {};
  }

  function getSelectedBadgeIds(form) {
    return Array.from(form.querySelectorAll('input[name^="field_appointment_badges"]:checked'))
      .map(function (input) {
        return String(input.value || '').trim();
      })
      .filter(Boolean);
  }

  function getRequiredSlotCount(form, settings) {
    var purpose = form.querySelector('input[name="field_appointment_purpose"]:checked');
    if (!purpose || purpose.value !== 'checkout') {
      return 0;
    }

    var badgeIds = getSelectedBadgeIds(form);
    if (!badgeIds.length) {
      return 0;
    }

    var minutesMap = toMinutesMap(settings);
    var totalMinutes = badgeIds.reduce(function (sum, badgeId) {
      var raw = minutesMap[badgeId];
      var minutes = parseInt(raw, 10);
      return sum + (Number.isFinite(minutes) && minutes > 0 ? minutes : 0);
    }, 0);

    return Math.max(1, Math.ceil(totalMinutes / 30));
  }

  function getEnabledSlotInputs(form) {
    return Array.from(form.querySelectorAll('#edit-field-appointment-slot input[type="checkbox"]'))
      .filter(function (input) {
        return !input.disabled;
      });
  }

  function countSelectedSlots(form) {
    return form.querySelectorAll('#edit-field-appointment-slot input[type="checkbox"]:checked').length;
  }

  function setChecked(input, checked) {
    if (!!input.checked === !!checked) {
      return;
    }
    input.checked = !!checked;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function autoSelectSlots(form, settings) {
    if (form.dataset.appointmentSlotPrefillTouched === 'true') {
      return;
    }

    var requiredSlots = getRequiredSlotCount(form, settings);
    if (requiredSlots <= 0) {
      return;
    }

    var slots = getEnabledSlotInputs(form);
    if (slots.length < requiredSlots) {
      return;
    }

    var checkedCount = countSelectedSlots(form);
    if (checkedCount >= requiredSlots) {
      return;
    }

    var startIndex = -1;
    for (var i = 0; i <= slots.length - requiredSlots; i++) {
      var contiguous = true;
      for (var j = 0; j < requiredSlots; j++) {
        if (!slots[i + j] || slots[i + j].disabled) {
          contiguous = false;
          break;
        }
      }
      if (contiguous) {
        startIndex = i;
        break;
      }
    }

    if (startIndex < 0) {
      return;
    }

    form.dataset.appointmentSlotPrefillApplying = 'true';
    try {
      slots.forEach(function (input, index) {
        var shouldCheck = index >= startIndex && index < startIndex + requiredSlots;
        setChecked(input, shouldCheck);
      });
    }
    finally {
      form.dataset.appointmentSlotPrefillApplying = 'false';
    }
  }

  function scheduleAutoSelect(form, settings) {
    var pendingTimer = parseInt(form.dataset.appointmentSlotPrefillTimer || '', 10);
    if (Number.isFinite(pendingTimer) && pendingTimer > 0) {
      window.clearTimeout(pendingTimer);
    }

    var timer = window.setTimeout(function () {
      form.dataset.appointmentSlotPrefillTimer = '';
      autoSelectSlots(form, settings);
    }, 0);

    form.dataset.appointmentSlotPrefillTimer = String(timer);
  }

  Drupal.behaviors.appointmentFacilitatorSlotPrefill = {
    attach: function (context, drupalSettings) {
      var settings = (drupalSettings && drupalSettings.appointmentFacilitator) || {};
      var forms = context.querySelectorAll
        ? context.querySelectorAll('form.node-appointment-form, form#node-appointment-form')
        : [];

      Array.prototype.forEach.call(forms, function (form) {
        if (form.dataset.appointmentSlotPrefillBound !== 'true') {
          form.dataset.appointmentSlotPrefillBound = 'true';

          form.addEventListener('change', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLInputElement)) {
              return;
            }

            if (form.dataset.appointmentSlotPrefillApplying === 'true') {
              return;
            }

            if (target.name === 'field_appointment_purpose' || target.name.indexOf('field_appointment_badges') === 0) {
              scheduleAutoSelect(form, settings);
              return;
            }

            if (target.name.indexOf('field_appointment_slot') === 0) {
              form.dataset.appointmentSlotPrefillTouched = 'true';
            }
          });
        }

        scheduleAutoSelect(form, settings);
      });
    }
  };
})(Drupal);
