/**
 * @file
 * Applies filters and shows placeholders while catalog navigation is pending.
 * Without JavaScript the form keeps its "Ver resultados" button.
 */

(function (Drupal, once) {
  'use strict';

  const cleanup = new WeakMap();

  Drupal.behaviors.trotamundosCatalog = {
    attach(context) {
      once('trotamundos-catalog', '[data-catalog-filters]', context).forEach((panel) => {
        const form = panel.querySelector('select').form;
        const catalog = panel.closest('[data-catalog]');
        const results = catalog.querySelector('[data-catalog-results]');
        const status = catalog.querySelector('[data-catalog-status]');
        const grid = catalog.querySelector('[data-catalog-grid]');
        const empty = catalog.querySelector('.empty-state');
        const controller = new AbortController();
        const { signal } = controller;
        let initialTimer;
        let navigationTimer;
        const setBusy = (busy) => {
          results.setAttribute('aria-busy', String(busy));
          if (grid) {
            grid.inert = busy;
          }
          if (empty) {
            empty.inert = busy;
          }
          status.classList.add('visually-hidden');
          status.classList.remove('catalog-status--timeout');
          status.textContent = busy ? status.dataset.loadingMessage : '';
        };
        const finishInitial = () => {
          window.clearTimeout(initialTimer);
          document.documentElement.classList.remove('catalog-initial-loading');
          if (!catalog.classList.contains('is-loading')) {
            setBusy(false);
          }
        };
        const resetLoading = (timedOut = false) => {
          window.clearTimeout(navigationTimer);
          finishInitial();
          catalog.classList.remove('is-loading');
          panel.classList.remove('is-loading');
          setBusy(false);
          if (timedOut) {
            status.classList.remove('visually-hidden');
            status.classList.add('catalog-status--timeout');
            status.textContent = status.dataset.timeoutMessage;
          }
        };
        const beginLoading = () => {
          finishInitial();
          catalog.classList.add('is-loading');
          panel.classList.add('is-loading');
          setBusy(true);
          window.clearTimeout(navigationTimer);
          navigationTimer = window.setTimeout(() => resetLoading(true), 20000);
        };

        if (document.documentElement.classList.contains('catalog-initial-loading')) {
          setBusy(true);
          const pending = new Set([...catalog.querySelectorAll('[data-catalog-grid] img')].filter((image) => {
            const rect = image.getBoundingClientRect();
            return !image.complete && rect.top < window.innerHeight + 200 && rect.bottom > 0;
          }));
          const settle = (event) => {
            pending.delete(event.currentTarget);
            if (!pending.size) {
              finishInitial();
            }
          };
          pending.forEach((image) => {
            image.addEventListener('load', settle, { once: true, signal });
            image.addEventListener('error', settle, { once: true, signal });
            if (image.complete) {
              pending.delete(image);
            }
          });
          if (pending.size) {
            initialTimer = window.setTimeout(finishInitial, 4500);
          }
          else {
            finishInitial();
          }
        }

        const submit = () => {
          if (!form.reportValidity()) {
            return;
          }
          if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
          }
          else {
            if (form.dispatchEvent(new Event('submit', { cancelable: true }))) {
              form.submit();
            }
          }
        };

        panel.addEventListener('change', (event) => {
          if (event.target.matches('select')) {
            submit();
          }
        }, { signal });
        form.addEventListener('submit', (event) => {
          if (event.defaultPrevented) {
            return;
          }
          beginLoading();
          queueMicrotask(() => {
            if (event.defaultPrevented) {
              resetLoading();
            }
          });
        }, { signal });

        document.addEventListener('click', (event) => {
          if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || !(event.target instanceof Element)) {
            return;
          }
          const link = event.target.closest('.explore-navigation__link, .primary-navigation__explore a, .active-filters a, .pager a, [data-catalog-reset]');
          if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self') || link.origin !== window.location.origin) {
            return;
          }
          beginLoading();
          queueMicrotask(() => {
            if (event.defaultPrevented) {
              resetLoading();
            }
          });
        }, { signal });
        window.addEventListener('pageshow', (event) => {
          if (event.persisted) {
            resetLoading();
          }
        }, { signal });

        document.querySelectorAll('[data-catalog-autosubmit]').forEach((select) => {
          if (select.form === form) {
            select.addEventListener('change', submit, { signal });
          }
        });
        cleanup.set(panel, () => {
          controller.abort();
          window.clearTimeout(initialTimer);
          window.clearTimeout(navigationTimer);
          document.documentElement.classList.remove('catalog-initial-loading');
        });
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once.remove('trotamundos-catalog', '[data-catalog-filters]', context).forEach((panel) => {
          const dispose = cleanup.get(panel);
          if (dispose) {
            dispose();
            cleanup.delete(panel);
          }
        });
      }
    },
  };
})(Drupal, once);
