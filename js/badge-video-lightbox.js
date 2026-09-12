/**
 * @file
 * Opens a badge's training video in a native <dialog> lightbox with chapters.
 *
 * Used by the tool-page badge embed (badges.embedded view mode). The YouTube
 * iframe is only given a src when the dialog opens, so the tool page does not
 * load a player for every badge, and it is cleared on close so audio stops.
 * Chapter buttons seek through the IFrame API (postMessage) once the player
 * has reported ready; before that they simply (re)load the player at the
 * requested offset.
 */
(function (Drupal, once) {
  'use strict';

  function command(iframe, func, args) {
    if (!iframe.contentWindow) {
      return;
    }
    try {
      iframe.contentWindow.postMessage(JSON.stringify({ event: 'command', func: func, args: args || [] }), '*');
    }
    catch (e) {
      // Player not reachable; nothing to do.
    }
  }

  function setup(root, dialog) {
    var id = dialog.getAttribute('data-mh-video-id');
    var iframe = dialog.querySelector('iframe');
    var base = iframe.getAttribute('data-src') || '';
    var supported = typeof dialog.showModal === 'function';
    var ready = false;

    window.addEventListener('message', function (event) {
      if (!iframe.contentWindow || event.source !== iframe.contentWindow) {
        return;
      }
      var data = event.data;
      if (typeof data === 'string') {
        try {
          data = JSON.parse(data);
        }
        catch (e) {
          return;
        }
      }
      if (data && (data.event === 'onReady' || data.event === 'infoDelivery')) {
        ready = true;
      }
    });

    iframe.addEventListener('load', function () {
      if (!iframe.getAttribute('src')) {
        return;
      }
      try {
        iframe.contentWindow.postMessage(JSON.stringify({ event: 'listening', id: id }), '*');
      }
      catch (e) {
        // Ignore.
      }
    });

    function load(start) {
      ready = false;
      var src = base + '&autoplay=1&origin=' + encodeURIComponent(window.location.origin);
      if (start > 0) {
        src += '&start=' + start;
      }
      iframe.setAttribute('src', src);
    }

    function markActive(button) {
      dialog.querySelectorAll('[data-mh-video-seek]').forEach(function (candidate) {
        candidate.classList.toggle('is-active', candidate === button);
        if (candidate === button) {
          candidate.setAttribute('aria-current', 'true');
        }
        else {
          candidate.removeAttribute('aria-current');
        }
      });
    }

    function open(start) {
      if (!supported) {
        window.open('https://www.youtube.com/watch?v=' + encodeURIComponent(id) + (start > 0 ? '&t=' + start + 's' : ''), '_blank', 'noopener');
        return;
      }
      dialog.showModal();
      document.documentElement.classList.add('mh-badge-video-open');
      markActive(null);
      load(start || 0);
    }

    dialog.addEventListener('close', function () {
      command(iframe, 'stopVideo');
      iframe.removeAttribute('src');
      ready = false;
      document.documentElement.classList.remove('mh-badge-video-open');
    });

    // Clicks on the backdrop land on the <dialog> itself; the panel fills the
    // dialog so clicks inside content never do.
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) {
        dialog.close();
      }
    });

    root.querySelectorAll('[data-mh-video-open="' + id + '"]').forEach(function (button) {
      button.addEventListener('click', function (event) {
        event.preventDefault();
        open(0);
      });
    });

    dialog.querySelectorAll('[data-mh-video-close]').forEach(function (button) {
      button.addEventListener('click', function () {
        dialog.close();
      });
    });

    dialog.querySelectorAll('[data-mh-video-seek]').forEach(function (button) {
      button.addEventListener('click', function () {
        var seconds = parseInt(button.getAttribute('data-mh-video-seek'), 10) || 0;
        markActive(button);
        if (ready) {
          command(iframe, 'seekTo', [seconds, true]);
          command(iframe, 'playVideo');
        }
        else {
          load(seconds);
        }
      });
    });
  }

  Drupal.behaviors.mhBadgeVideoLightbox = {
    attach: function (context) {
      once('mh-badge-video-lightbox', '[data-mh-badge-video]', context).forEach(function (root) {
        root.querySelectorAll('dialog[data-mh-video-id]').forEach(function (dialog) {
          setup(root, dialog);
        });
      });
    }
  };
})(Drupal, once);
