/**
 * @file
 * Applies catalog filters without navigation and shows loading placeholders.
 * Without JavaScript the form keeps its "Ver resultados" button.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  const cleanup = new WeakMap();

  function initializePriceRange(range, signal) {
    const minimum = range.querySelector('[data-price-input="min"]');
    const maximum = range.querySelector('[data-price-input="max"]');
    const minimumSlider = range.querySelector('[data-price-slider="min"]');
    const maximumSlider = range.querySelector('[data-price-slider="max"]');
    const sliders = range.querySelector('.catalog-price__sliders');
    const error = range.querySelector('[data-price-error]');
    const bars = Array.from(range.querySelectorAll('[data-price-count]'));
    const baseCeiling = Number(range.dataset.priceCeiling);
    const baseStep = Number(range.dataset.priceStep);
    const limit = Number(range.dataset.priceLimit);
    const formatter = new Intl.NumberFormat(document.documentElement.lang === 'en' ? 'en-GB' : 'es-CO', {
      style: 'currency',
      currency: 'COP',
      maximumFractionDigits: 0,
    });
    const valueOf = (input) => /^\d{1,15}$/.test(input.value) ? Number(input.value) : null;
    let ceiling = baseCeiling;
    let step = baseStep;

    const sync = () => {
      const minValue = valueOf(minimum);
      const maxValue = valueOf(maximum);
      const inverted = minValue !== null && maxValue !== null && minValue > maxValue;
      maximum.setCustomValidity(inverted ? error.textContent.trim() : '');
      error.hidden = !inverted;

      const extent = Math.max(baseCeiling, minValue ?? 0, maxValue ?? 0);
      step = Math.max(baseStep, 10 ** Math.max(0, Math.floor(Math.log10(extent)) - 2));
      ceiling = Math.min(limit, Math.ceil(extent / (step * 10)) * step * 10);
      [minimumSlider, maximumSlider].forEach((slider) => {
        slider.max = String(ceiling);
        slider.step = String(step);
      });
      minimumSlider.value = String(minValue ?? 0);
      maximumSlider.value = String(maxValue ?? ceiling);
      minimumSlider.setAttribute('aria-valuetext', minValue === null ? minimum.placeholder : formatter.format(minValue));
      maximumSlider.setAttribute('aria-valuetext', maxValue === null ? maximum.placeholder : formatter.format(maxValue));
      range.style.setProperty('--price-start', `${(minValue ?? 0) / ceiling * 100}%`);
      range.style.setProperty('--price-end', `${(maxValue ?? ceiling) / ceiling * 100}%`);
      range.style.setProperty('--histogram-width', `${baseCeiling / ceiling * 100}%`);
      bars.forEach((bar, index) => {
        const from = index * baseCeiling / bars.length;
        const to = (index + 1) * baseCeiling / bars.length;
        bar.classList.toggle('is-outside', (minValue !== null && to <= minValue) || (maxValue !== null && from > maxValue));
      });
    };

    range.addEventListener('input', (event) => {
      if (event.target === minimumSlider) {
        const next = Math.min(Number(minimumSlider.value), valueOf(maximum) ?? ceiling);
        minimum.value = next === 0 ? '' : String(next);
      }
      else if (event.target === maximumSlider) {
        const next = Math.max(Number(maximumSlider.value), valueOf(minimum) ?? 0);
        maximum.value = next >= ceiling ? '' : String(next);
      }
      sync();
    }, { signal });
    sliders.addEventListener('pointerdown', (event) => {
      if (event.button !== 0 || event.target instanceof HTMLInputElement) {
        return;
      }
      const bounds = sliders.getBoundingClientRect();
      const fraction = Math.max(0, Math.min(1, (event.clientX - bounds.left - 11) / Math.max(1, bounds.width - 22)));
      const next = Math.min(ceiling, Math.round(fraction * ceiling / step) * step);
      const slider = Math.abs(next - Number(minimumSlider.value)) <= Math.abs(next - Number(maximumSlider.value))
        ? minimumSlider : maximumSlider;
      event.preventDefault();
      slider.value = String(next);
      slider.dispatchEvent(new Event('input', { bubbles: true }));
      slider.focus({ preventScroll: true });
    }, { signal });
    range.classList.add('is-enhanced');
    sync();
    return {
      sync,
      dispose() {
        range.classList.remove('is-enhanced');
        maximum.setCustomValidity('');
        error.hidden = true;
      },
    };
  }

  Drupal.behaviors.trotamundosCatalog = {
    attach(context) {
      once('trotamundos-catalog', '[data-catalog]', context).forEach((catalog) => {
        const panel = document.querySelector('[data-catalog-filters]');
        const form = document.getElementById('discovery-form');
        const results = catalog.querySelector('[data-catalog-results]');
        const status = catalog.querySelector('[data-catalog-status]');
        const controller = new AbortController();
        const { signal } = controller;
        const searchNames = ['destino', 'fecha', 'desde', 'hasta', 'margen', 'acompanamiento'];
        let priceController = new AbortController();
        let priceRange = panel.querySelector('[data-price-range]');
        let priceRangeState = priceRange ? initializePriceRange(priceRange, priceController.signal) : null;
        let activeRequest;
        let initialTimer;
        let navigationTimer;
        const setBusy = (busy) => {
          results.setAttribute('aria-busy', String(busy));
          const grid = results.querySelector('[data-catalog-grid]');
          const empty = results.querySelector('.empty-state');
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
        const beginLoading = (navigation = false) => {
          finishInitial();
          catalog.classList.add('is-loading');
          panel.classList.add('is-loading');
          setBusy(true);
          window.clearTimeout(navigationTimer);
          if (navigation) {
            navigationTimer = window.setTimeout(() => resetLoading(true), 20000);
          }
        };

        if (document.documentElement.classList.contains('catalog-initial-loading')) {
          setBusy(true);
          const pending = new Set([...results.querySelectorAll('[data-catalog-grid] img')].filter((image) => {
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

        const snapshotControls = (root) => new Map(
          Array.from(root.querySelectorAll('input[id], select[id], textarea[id]'), (field) => [
            field.id,
            { value: field.value, checked: field.checked },
          ]),
        );
        const searchValues = () => Object.fromEntries(searchNames.map((name) => [
          name,
          form.querySelector(`[name="${name}"]`)?.value || '',
        ]));
        const formUrl = () => {
          const url = new URL(form.action, window.location.href);
          url.search = '';
          new FormData(form).forEach((value, name) => {
            if (typeof value === 'string' && value !== '') {
              url.searchParams.append(name, value);
            }
          });
          return url;
        };
        const updateCatalog = async (url, pushHistory, scrollToResults = false) => {
          activeRequest?.abort();
          const request = new AbortController();
          activeRequest = request;
          const initialSearch = searchValues();
          const initialControls = snapshotControls(panel);
          let timedOut = false;
          const timeout = window.setTimeout(() => {
            timedOut = true;
            request.abort();
          }, 20000);
          beginLoading();

          try {
            const fragmentUrl = new URL(url);
            fragmentUrl.searchParams.set('_trotamundos_catalog_fragment', '1');
            const response = await fetch(fragmentUrl, {
              credentials: 'same-origin',
              headers: { Accept: 'text/html' },
              signal: request.signal,
            });
            if (!response.ok) {
              throw new Error(`Catalog request failed with status ${response.status}.`);
            }
            if (new URL(response.url).pathname !== url.pathname) {
              window.location.assign(url.href);
              return;
            }
            const document = new DOMParser().parseFromString(await response.text(), 'text/html');
            const incomingResults = document.querySelector('[data-catalog-results]');
            const incomingPanel = document.querySelector('[data-catalog-filters]');
            const incomingForm = document.getElementById('discovery-form');
            const settingsElement = document.querySelector('script[data-drupal-selector="drupal-settings-json"]');
            if (!incomingResults || !incomingPanel || !incomingForm || !settingsElement
              || incomingResults.querySelector('[data-big-pipe-placeholder-id], drupal-render-placeholder')) {
              throw new Error('The catalog response is incomplete.');
            }
            const incomingSettings = JSON.parse(settingsElement.textContent);
            if (activeRequest !== request) {
              return;
            }

            const focused = panel.contains(window.document.activeElement) ? window.document.activeElement : null;
            let focusSelector = focused?.id ? `#${CSS.escape(focused.id)}` : '';
            if (!focusSelector && focused?.hasAttribute('data-price-slider')) {
              focusSelector = `[data-price-slider="${focused.dataset.priceSlider}"]`;
            }
            if (!focusSelector && focused?.hasAttribute('data-discovery-control-close')) {
              focusSelector = '[data-discovery-control-close]';
            }
            const selection = focused instanceof HTMLInputElement && focused.type === 'text'
              ? [focused.selectionStart, focused.selectionEnd] : null;
            const panelScroll = panel.scrollTop;
            const resultsHadFocus = results.contains(window.document.activeElement);
            snapshotControls(panel).forEach((value, id) => {
              const previous = initialControls.get(id);
              const next = incomingPanel.querySelector(`#${CSS.escape(id)}`);
              if (previous && next && (value.value !== previous.value || value.checked !== previous.checked)) {
                next.value = value.value;
                if ('checked' in next) {
                  next.checked = value.checked;
                }
              }
            });

            Drupal.detachBehaviors(panel, drupalSettings, 'unload');
            Drupal.detachBehaviors(results, drupalSettings, 'unload');
            priceController.abort();
            priceRangeState?.dispose();
            panel.replaceChildren(...incomingPanel.childNodes);
            results.replaceChildren(...incomingResults.childNodes);
            Object.assign(drupalSettings.ajax ||= {}, incomingSettings.ajax || {});
            Object.assign(drupalSettings.ajaxTrustedUrl ||= {}, incomingSettings.ajaxTrustedUrl || {});
            priceController = new AbortController();
            priceRange = panel.querySelector('[data-price-range]');
            priceRangeState = priceRange ? initializePriceRange(priceRange, priceController.signal) : null;
            Drupal.attachBehaviors(panel, drupalSettings);
            Drupal.attachBehaviors(results, drupalSettings);

            searchNames.forEach((name) => {
              const field = form.querySelector(`[name="${name}"]`);
              const incoming = incomingForm.querySelector(`[name="${name}"]`);
              if (field && incoming && field.value === initialSearch[name]) {
                field.value = incoming.value;
              }
            });
            const start = form.querySelector('#discovery-desde');
            const end = form.querySelector('#discovery-hasta');
            if (start && end) {
              end.min = start.value || start.min;
            }
            form.dispatchEvent(new Event('change', { bubbles: true }));
            const languageLinks = new Map(
              Array.from(document.querySelectorAll('.language-switch a[hreflang][href]'), (link) => [
                link.hreflang,
                link.getAttribute('href'),
              ]),
            );
            window.document.querySelectorAll('.language-switch a[hreflang][href]').forEach((link) => {
              const href = languageLinks.get(link.hreflang);
              if (href) {
                link.setAttribute('href', href);
              }
            });
            if (pushHistory && url.href !== window.location.href) {
              window.history.pushState(null, '', url.href);
            }
            panel.scrollTop = panelScroll;
            const nextFocus = focusSelector && panel.querySelector(focusSelector);
            if (nextFocus) {
              nextFocus.focus({ preventScroll: true });
              if (selection && nextFocus instanceof HTMLInputElement && nextFocus.type === 'text') {
                nextFocus.setSelectionRange(...selection);
              }
            }
            const heading = results.querySelector('#catalog-results-title');
            if (resultsHadFocus && heading) {
              heading.setAttribute('tabindex', '-1');
              heading.focus({ preventScroll: true });
            }
            resetLoading();
            status.textContent = heading?.textContent.trim() || '';
            if (scrollToResults) {
              results.scrollIntoView({ block: 'start' });
            }
          }
          catch (error) {
            if (request.signal.aborted && !timedOut) {
              return;
            }
            resetLoading();
            status.classList.remove('visually-hidden');
            status.classList.add('catalog-status--timeout');
            status.textContent = timedOut ? status.dataset.timeoutMessage : status.dataset.errorMessage;
            console.error('Catalog update failed.', error);
            if (!pushHistory) {
              window.location.assign(url.href);
            }
          }
          finally {
            window.clearTimeout(timeout);
            if (activeRequest === request) {
              activeRequest = null;
            }
          }
        };

        panel.addEventListener('change', (event) => {
          if (event.target.matches('select, input[type="radio"]')) {
            submit();
          }
        }, { signal });
        form.addEventListener('submit', (event) => {
          if (event.defaultPrevented) {
            return;
          }
          const url = formUrl();
          if (url.origin !== window.location.origin || url.pathname !== window.location.pathname) {
            return;
          }
          event.preventDefault();
          updateCatalog(url, true);
        }, { capture: true, signal });

        document.addEventListener('click', (event) => {
          if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || !(event.target instanceof Element)) {
            return;
          }
          const link = event.target.closest('.explore-navigation__link, .primary-navigation__explore a, .active-filters a, .pager a, [data-catalog-reset]');
          if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self') || link.origin !== window.location.origin) {
            return;
          }
          const url = new URL(link.href);
          if (url.pathname === window.location.pathname
            && link.matches('.active-filters a, .pager a, [data-catalog-reset]')) {
            event.preventDefault();
            updateCatalog(url, true, Boolean(link.closest('.pager')));
            return;
          }
          beginLoading(true);
          queueMicrotask(() => {
            if (event.defaultPrevented) {
              resetLoading();
            }
          });
        }, { signal });
        window.addEventListener('pageshow', (event) => {
          if (event.persisted) {
            resetLoading();
            priceRangeState?.sync();
          }
        }, { signal });
        window.addEventListener('popstate', () => {
          updateCatalog(new URL(window.location.href), false);
        }, { signal });

        cleanup.set(catalog, () => {
          controller.abort();
          activeRequest?.abort();
          priceController.abort();
          priceRangeState?.dispose();
          window.clearTimeout(initialTimer);
          window.clearTimeout(navigationTimer);
          document.documentElement.classList.remove('catalog-initial-loading');
        });
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once.remove('trotamundos-catalog', '[data-catalog]', context).forEach((catalog) => {
          const dispose = cleanup.get(catalog);
          if (dispose) {
            dispose();
            cleanup.delete(catalog);
          }
        });
      }
    },
  };
})(Drupal, drupalSettings, once);
