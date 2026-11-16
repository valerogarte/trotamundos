(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.trotamundosAssistantDocumentsSettingsForm = {
    attach(context) {
      once('trotamundos-assistant-documents-confirm', 'form#trotamundos-assistant-documents-settings', context)
        .forEach((form) => {
          form.addEventListener('submit', (event) => {
            const submitter = event.submitter;
            if (submitter && submitter.matches('[data-drupal-selector^="edit-media-"]')) {
              return;
            }

            const field = form.querySelector('[data-trotamundos-assistant-documents-destination-field]')?.textContent.trim() || '';
            const selected = Array.from(form.querySelectorAll('[data-trotamundos-assistant-documents-rule] input[type="checkbox"]:checked'))
              .map((checkbox) => {
                const row = checkbox.closest('tr');
                const source = row?.querySelector('[data-trotamundos-assistant-documents-source]')?.textContent.trim();
                const media = row?.getAttribute('data-trotamundos-assistant-documents-media') || '';
                return source ? { media, source } : null;
              })
              .filter(Boolean);

            if (!field || selected.length === 0) {
              return;
            }

            const message = [
              `Se va a crear un campo nuevo llamado ${field}.`,
              '',
              'Se añadirá para los tipos de Media y productos seleccionados:',
              formatSelectedRules(selected),
              '',
              '¿Quieres continuar?',
            ].join('\n');

            if (!window.confirm(message)) {
              event.preventDefault();
            }
          });
      });
    },
  };

  function formatSelectedRules(selected) {
    const grouped = selected.reduce((groups, item) => {
      const media = item.media || 'Media sin tipo';
      groups[media] = groups[media] || [];
      groups[media].push(item.source);
      return groups;
    }, {});

    return Object.entries(grouped)
      .map(([media, sources]) => [
        `Tipo de Media: ${media}`,
        ...sources.map((source) => `- ${source}`),
      ].join('\n'))
      .join('\n\n');
  }
})(Drupal, once);
