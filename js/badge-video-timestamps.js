(function (Drupal) {
  'use strict';

  // Reads the `?t=…s` parameter on YouTube timestamp links and inserts a
  // human-readable "(MM:SS)" or "(HH:MM:SS)" span before each link, so the
  // "Jump to Topics in Video" list reads as a real chapter index instead
  // of a wall of titles. Mirrors what the legacy
  // asset_injector.js.time_stamps_on_youtube_video_human_readable script
  // did for tool pages, but lives in a code-owned library so it loads
  // reliably on badge term pages under the Gin admin theme. Handles both
  // youtube.com and youtu.be URL forms — the asset-injector regex only
  // covered youtube.com, which silently dropped the youtu.be links badge
  // editors actually paste.
  Drupal.behaviors.appointmentFacilitatorBadgeVideoTimestamps = {
    attach: function (context) {
      var root = context && context.querySelectorAll ? context : document;
      var links = root.querySelectorAll(
        '.field__item a[href*="youtube.com"]:not([data-mh-ts-processed]), ' +
        '.field__item a[href*="youtu.be"]:not([data-mh-ts-processed])'
      );

      links.forEach(function (link) {
        link.setAttribute('data-mh-ts-processed', '1');
        try {
          var url = new URL(link.href, window.location.origin);
          var tParam = url.searchParams.get('t');
          if (!tParam) {
            return;
          }
          var seconds = parseInt(String(tParam).replace('s', ''), 10);
          if (isNaN(seconds) || seconds < 0) {
            return;
          }
          var h = Math.floor(seconds / 3600);
          var m = Math.floor((seconds % 3600) / 60);
          var s = seconds % 60;
          var formatted =
            (h > 0 ? String(h).padStart(2, '0') + ':' : '') +
            String(m).padStart(2, '0') + ':' +
            String(s).padStart(2, '0');

          var span = document.createElement('span');
          span.className = 'badge-video-timestamp';
          span.textContent = '(' + formatted + ') ';
          link.parentNode.insertBefore(span, link);
        }
        catch (e) {
          // Bad URL — leave the link unchanged.
        }
      });
    }
  };
})(Drupal);
