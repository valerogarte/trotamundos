/**
 * @file
 * Applies catalog filters as soon as they change. Without JavaScript the
 * form keeps its "Ver resultados" button.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.trotamundosCatalog = {
    attach(context) {
      once('trotamundos-catalog', 'form[data-catalog-form]', context).forEach((form) => {
        const submit = () => {
          form.classList.add('is-loading');
          if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
          }
          else {
            form.dispatchEvent(new Event('submit', { cancelable: true }));
            form.submit();
          }
        };

        form.addEventListener('change', (event) => {
          if (event.target.matches('select, input[type="radio"]')) {
            submit();
          }
        });

        // The sort select belongs to the form through its "form" attribute.
        document.querySelectorAll('[data-catalog-autosubmit]').forEach((select) => {
          if (select.form === form) {
            select.addEventListener('change', submit);
          }
        });

        // On wide screens the advanced filters are always visible.
        const more = form.querySelector('[data-catalog-more]');
        const wide = window.matchMedia('(min-width: 1024px)');
        const sync = () => {
          if (more && wide.matches) {
            more.open = true;
          }
        };
        wide.addEventListener('change', sync);
        sync();
      });
    },
  };
})(Drupal, once);
