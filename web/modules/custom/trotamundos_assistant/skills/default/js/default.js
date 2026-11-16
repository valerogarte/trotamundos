(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.trotamundosAssistantDefaultPrompts = {
    attach(context) {
      once('trotamundos-assistant-default-prompt', '[data-minsait-default-prompt]', context).forEach((button) => {
        button.addEventListener('click', () => {
          const assistant = button.closest('.trotamundos-assistant') || document.querySelector('.trotamundos-assistant');
          const input = assistant?.querySelector('.trotamundos-assistant__input');
          if (!input) {
            return;
          }

          input.value = button.dataset.minsaitDefaultPrompt || button.textContent.trim();
          input.focus();
        });
      });
    },
  };
})(Drupal, once);
