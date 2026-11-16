/**
 * @file
 * Progressive interactions for the independent Trotamundos 2 theme.
 */

(function (Drupal, once) {
  'use strict';

  const desktopNavigation = window.matchMedia('(min-width: 960px)');
  const headerStates = new WeakMap();
  const experienceStates = new WeakMap();
  const dialogStates = new WeakMap();
  const triggerCleanup = new WeakMap();
  const categories = ['all', 'pueblos', 'bienestar', 'naturaleza'];

  /**
   * Include an Ajax context itself and, optionally, its containing component.
   */
  function components(context, selector, includeParent = false) {
    const elements = Array.from(context.querySelectorAll(selector));
    if (context.matches && context.matches(selector)) {
      elements.unshift(context);
    }
    else if (includeParent && context.closest) {
      const parent = context.closest(selector);
      if (parent) {
        elements.unshift(parent);
      }
    }
    return elements;
  }

  function synchronizeMenuLock() {
    document.body.classList.toggle(
      'tm2-menu-is-open',
      Boolean(document.querySelector('[data-tm2-header].menu-is-open')),
    );
  }

  function synchronizeDialogLock() {
    const openDialog = document.querySelector('dialog[data-tm2-dialog][open]');
    document.body.classList.toggle('tm2-dialog-is-open', Boolean(openDialog));
    return openDialog;
  }

  /**
   * Preserve native fragment scrolling, then place focus in the new section.
   */
  function focusAnchorDestination(anchor, event) {
    if (
      event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey ||
      (anchor.target && anchor.target !== '_self')
    ) {
      return;
    }
    let destination;
    try {
      const url = new URL(anchor.href, window.location.href);
      if (
        url.origin !== window.location.origin ||
        url.pathname !== window.location.pathname ||
        url.search !== window.location.search ||
        !url.hash
      ) {
        return;
      }
      destination = document.getElementById(decodeURIComponent(url.hash.slice(1)));
    }
    catch (error) {
      return;
    }
    if (!destination) {
      return;
    }
    const headingSelector = 'h1, h2, h3, h4, h5, h6';
    const target = destination.matches(headingSelector)
      ? destination
      : destination.querySelector(headingSelector) || destination;
    window.requestAnimationFrame(() => {
      if (!target.isConnected) {
        return;
      }
      if (!target.hasAttribute('tabindex')) {
        target.setAttribute('tabindex', '-1');
        target.addEventListener('blur', () => target.removeAttribute('tabindex'), { once: true });
      }
      target.focus({ preventScroll: true });
    });
  }

  function initializeHeader(header) {
    const controller = new AbortController();
    let scrollFrame = 0;
    const initialToggle = header.querySelector('[data-tm2-menu-toggle]');
    const openLabel = initialToggle && initialToggle.getAttribute('aria-label')
      ? initialToggle.getAttribute('aria-label')
      : Drupal.t('Abrir menú de navegación');

    const refresh = () => {
      const toggle = header.querySelector('[data-tm2-menu-toggle]');
      const menu = header.querySelector('[data-tm2-menu]');
      if (!toggle || !menu) {
        return;
      }
      if (!menu.id) {
        menu.id = 'tm2-navigation';
      }
      const isOpen = header.classList.contains('menu-is-open');
      toggle.setAttribute('aria-controls', menu.id);
      toggle.setAttribute('aria-expanded', String(isOpen));
      toggle.setAttribute('aria-label', isOpen ? Drupal.t('Cerrar menú de navegación') : openLabel);
    };
    const closeMenu = (returnFocus = false) => {
      header.classList.remove('menu-is-open');
      synchronizeMenuLock();
      refresh();
      const toggle = header.querySelector('[data-tm2-menu-toggle]');
      if (returnFocus && toggle) {
        toggle.focus({ preventScroll: true });
      }
    };
    const updateScrolled = () => {
      scrollFrame = 0;
      header.classList.toggle('is-scrolled', window.scrollY > 20);
    };

    header.addEventListener('click', (event) => {
      if (!(event.target instanceof Element)) {
        return;
      }
      const toggle = event.target.closest('[data-tm2-menu-toggle]');
      if (toggle && header.contains(toggle)) {
        event.preventDefault();
        if (header.classList.contains('menu-is-open')) {
          closeMenu();
        }
        else {
          header.classList.add('menu-is-open');
          synchronizeMenuLock();
          refresh();
        }
        return;
      }
      const anchor = event.target.closest('a[href]');
      if (anchor && header.contains(anchor) && header.classList.contains('menu-is-open')) {
        closeMenu();
        focusAnchorDestination(anchor, event);
      }
    }, { signal: controller.signal });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && header.classList.contains('menu-is-open')) {
        closeMenu(true);
      }
    }, { signal: controller.signal });
    desktopNavigation.addEventListener('change', (event) => {
      if (event.matches) {
        closeMenu();
      }
    }, { signal: controller.signal });
    window.addEventListener('scroll', () => {
      if (!scrollFrame) {
        scrollFrame = window.requestAnimationFrame(updateScrolled);
      }
    }, { passive: true, signal: controller.signal });

    if (desktopNavigation.matches) {
      closeMenu();
    }
    refresh();
    updateScrolled();
    headerStates.set(header, {
      refresh,
      cleanup() {
        controller.abort();
        if (scrollFrame) {
          window.cancelAnimationFrame(scrollFrame);
        }
        closeMenu();
      },
    });
  }

  function initializeExperiences(root) {
    const controller = new AbortController();
    const find = (selector) => Array.from(root.querySelectorAll(selector))
      .filter((element) => element.closest('[data-tm2-experiences]') === root);
    const selected = find('[data-tm2-filter][aria-pressed="true"]')[0];
    let category = selected && categories.includes(selected.dataset.tm2Filter)
      ? selected.dataset.tm2Filter
      : 'all';

    const update = (announce = false) => {
      let count = 0;
      find('[data-tm2-filter]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.tm2Filter === category));
      });
      find('[data-tm2-card]').forEach((card) => {
        const visible = category === 'all' || card.dataset.category === category;
        card.hidden = !visible;
        if (visible) {
          count += 1;
        }
      });
      root.classList.toggle('has-filter', category !== 'all');
      categories.forEach((value) => root.classList.toggle(`filter-is-${value}`, value === category));
      const countLabel = Drupal.formatPlural(count, '1 idea de viaje', '@count ideas de viaje');
      find('[data-tm2-results]').forEach((element) => {
        element.textContent = countLabel;
      });
      find('[data-tm2-status]').forEach((status) => {
        status.setAttribute('role', 'status');
        status.setAttribute('aria-atomic', 'true');
        if (announce) {
          const labels = {
            pueblos: Drupal.t('en pueblos'),
            bienestar: Drupal.t('de bienestar'),
            naturaleza: Drupal.t('de naturaleza'),
          };
          status.textContent = `${countLabel}${labels[category] ? ` ${labels[category]}` : ''}.`;
        }
      });
    };

    root.addEventListener('click', (event) => {
      if (!(event.target instanceof Element)) {
        return;
      }
      const button = event.target.closest('[data-tm2-filter]');
      if (
        !button || button.closest('[data-tm2-experiences]') !== root ||
        !categories.includes(button.dataset.tm2Filter)
      ) {
        return;
      }
      event.preventDefault();
      category = button.dataset.tm2Filter;
      update(true);
    }, { signal: controller.signal });

    experienceStates.set(root, {
      update,
      cleanup() {
        controller.abort();
      },
    });
  }

  function initializeDialog(dialog) {
    const controller = new AbortController();
    const state = {
      opener: null,
      cleanup() {
        if (dialog.open) {
          dialog.close();
        }
        controller.abort();
        synchronizeDialogLock();
      },
    };

    dialog.addEventListener('click', (event) => {
      if (!(event.target instanceof Element)) {
        return;
      }
      if (event.target.closest('[data-tm2-dialog-close]')) {
        event.preventDefault();
        dialog.close();
        return;
      }
      if (event.target === dialog) {
        const bounds = dialog.getBoundingClientRect();
        if (
          event.clientX < bounds.left || event.clientX > bounds.right ||
          event.clientY < bounds.top || event.clientY > bounds.bottom
        ) {
          dialog.close();
        }
      }
    }, { signal: controller.signal });
    dialog.addEventListener('close', () => {
      const anotherDialog = synchronizeDialogLock();
      if (
        !anotherDialog && state.opener && state.opener.isConnected &&
        !state.opener.closest('[hidden], [inert]')
      ) {
        state.opener.focus({ preventScroll: true });
      }
      state.opener = null;
    }, { signal: controller.signal });

    dialogStates.set(dialog, state);
  }

  function initializeDialogTrigger(button) {
    const controller = new AbortController();
    button.addEventListener('click', (event) => {
      const dialog = document.getElementById(button.dataset.tm2DialogOpen);
      if (
        !dialog || !dialog.matches('dialog[data-tm2-dialog]') ||
        typeof dialog.showModal !== 'function' || !dialog.isConnected
      ) {
        return;
      }
      event.preventDefault();
      if (dialog.open) {
        return;
      }
      once('tm2-dialog', [dialog]).forEach(initializeDialog);
      dialogStates.get(dialog).opener = button;
      dialog.showModal();
      synchronizeDialogLock();
    }, { signal: controller.signal });
    triggerCleanup.set(button, () => controller.abort());
  }

  Drupal.behaviors.trotamundos2 = {
    attach(context) {
      document.documentElement.classList.add('tm2-js');

      const headers = components(context, '[data-tm2-header]', true);
      once('tm2-header', headers).forEach(initializeHeader);
      headers.forEach((header) => headerStates.get(header)?.refresh());

      const experienceRoots = components(context, '[data-tm2-experiences]', true);
      once('tm2-experiences', experienceRoots).forEach(initializeExperiences);
      experienceRoots.forEach((root) => experienceStates.get(root)?.update());

      once('tm2-dialog', components(context, 'dialog[data-tm2-dialog]')).forEach(initializeDialog);
      once('tm2-dialog-trigger', components(context, '[data-tm2-dialog-open]')).forEach(initializeDialogTrigger);
    },

    detach(context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      [
        ['tm2-header', '[data-tm2-header]', headerStates],
        ['tm2-experiences', '[data-tm2-experiences]', experienceStates],
        ['tm2-dialog', 'dialog[data-tm2-dialog]', dialogStates],
      ].forEach(([onceId, selector, states]) => {
        once.remove(onceId, components(context, selector)).forEach((element) => {
          states.get(element)?.cleanup();
          states.delete(element);
        });
      });
      once.remove('tm2-dialog-trigger', components(context, '[data-tm2-dialog-open]')).forEach((button) => {
        triggerCleanup.get(button)?.();
        triggerCleanup.delete(button);
      });
    },
  };
})(Drupal, once);
