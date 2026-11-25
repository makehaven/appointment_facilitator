/**
 * @file
 * "Ask the tool assistant" on a tool page's Who can help card.
 *
 * Opens the tool chat already on the page (Ask Red panel) and puts the cursor
 * in it. Without that chat (blocked, not placed, no JS) the link goes to the
 * tool chat page.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.mhToolHelpersAskChat = {
    attach: function (context) {
      once('mh-open-tool-chat', '[data-mh-open-tool-chat]', context).forEach(function (link) {
        link.addEventListener('click', function (event) {
          var container = document.querySelector('[data-mh-askred-assistant="tool_page_chat"]');
          var header = container && container.querySelector('.ai-deepchat--header');
          if (!header) {
            return;
          }
          event.preventDefault();
          if (!container.classList.contains('chat-open')) {
            header.click();
          }
          window.setTimeout(function () {
            var chat = container.querySelector('deep-chat');
            var input = chat && chat.shadowRoot && chat.shadowRoot.querySelector('#text-input, [contenteditable="true"], textarea');
            if (input) {
              input.focus();
            }
          }, 150);
        });
      });
    }
  };

})(Drupal, once);
