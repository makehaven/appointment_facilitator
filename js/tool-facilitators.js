/**
 * @file
 * Opens the tool page's facilitator schedule in a native <dialog>.
 *
 * One dialog per tool page (rendered by the tool_facilitators theme hook).
 * Any element carrying data-mh-facilitators-open opens it — the card's own
 * button and the "See facilitator schedule" link inside each badge card.
 * The opener's data-mh-facilitators-purpose (checkout|project) is written
 * onto every appointment link in the dialog when it opens, so a badge card
 * books a checkout while the tool-level button books whatever the server
 * decided for this viewer. Without <dialog> support (or without a dialog on
 * the page) the opener is a normal link and simply navigates.
 */
(function (Drupal, once) {
  'use strict';

  function withPurpose(url, purpose) {
    url.searchParams.set('purpose', purpose);
    if (purpose !== 'checkout') {
      url.searchParams.delete('from-badges-complete');
    }
    return url.pathname + url.search + url.hash;
  }

  function setPurpose(dialog, purpose) {
    if (!purpose || !/^[a-z_]+$/.test(purpose)) {
      return;
    }
    dialog.querySelectorAll('a[href*="node/add/appointment"]').forEach(function (link) {
      try {
        var url = new URL(link.getAttribute('href'), window.location.origin);
        if (url.pathname.indexOf('/node/add/appointment') === 0) {
          link.setAttribute('href', withPurpose(url, purpose));
          return;
        }
        // Anonymous slots go through login and back to the booking form:
        // rewrite the destination instead.
        var destination = url.searchParams.get('destination');
        if (destination && destination.indexOf('/node/add/appointment') === 0) {
          url.searchParams.set('destination', withPurpose(new URL(destination, window.location.origin), purpose));
          link.setAttribute('href', url.pathname + url.search + url.hash);
        }
      }
      catch (e) {
        // Leave the link as rendered.
      }
    });
    dialog.setAttribute('data-mh-facilitators-active-purpose', purpose);
  }

  function setup(dialog) {
    var supported = typeof dialog.showModal === 'function';

    function open(purpose) {
      setPurpose(dialog, purpose);
      dialog.showModal();
      document.documentElement.classList.add('mh-tool-facilitators-open');
      var focus = dialog.querySelector('[data-mh-facilitators-close]');
      if (focus) {
        focus.focus();
      }
    }

    dialog.addEventListener('close', function () {
      document.documentElement.classList.remove('mh-tool-facilitators-open');
    });

    // Clicks on the backdrop land on the <dialog> itself; the panel fills the
    // dialog so clicks inside content never do.
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) {
        dialog.close();
      }
    });

    dialog.querySelectorAll('[data-mh-facilitators-close]').forEach(function (button) {
      button.addEventListener('click', function () {
        dialog.close();
      });
    });

    return function bind(opener) {
      if (!supported) {
        return;
      }
      opener.addEventListener('click', function (event) {
        event.preventDefault();
        open(opener.getAttribute('data-mh-facilitators-purpose') || dialog.parentNode.getAttribute('data-mh-facilitators-purpose') || '');
      });
    };
  }

  Drupal.behaviors.mhToolFacilitators = {
    attach: function (context) {
      var dialog = document.querySelector('dialog[data-mh-facilitators-dialog]');
      if (!dialog) {
        return;
      }
      once('mh-tool-facilitators-dialog', dialog).forEach(function (el) {
        el.mhToolFacilitatorsBind = setup(el);
      });
      if (!dialog.mhToolFacilitatorsBind) {
        return;
      }
      once('mh-tool-facilitators-open', '[data-mh-facilitators-open]', context).forEach(dialog.mhToolFacilitatorsBind);
    }
  };
})(Drupal, once);
