/**
 * @file
 * Progressive interactions for the independent Trotamundos 4 theme.
 */

(function (Drupal, once) {
  'use strict';

  const desktopNavigation = window.matchMedia('(min-width: 960px)');
  const headerStates = new WeakMap();
  const experienceStates = new WeakMap();
  const atlasStates = new WeakMap();
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
      'tm4-menu-is-open',
      Boolean(document.querySelector('[data-tm4-header].menu-is-open')),
    );
  }

  function synchronizeDialogLock() {
    const openDialog = document.querySelector('dialog[data-tm4-dialog][open]');
    document.body.classList.toggle('tm4-dialog-is-open', Boolean(openDialog));
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
    const initialToggle = header.querySelector('[data-tm4-menu-toggle]');
    const openLabel = initialToggle && initialToggle.getAttribute('aria-label')
      ? initialToggle.getAttribute('aria-label')
      : Drupal.t('Abrir menú de navegación');

    const refresh = () => {
      const toggle = header.querySelector('[data-tm4-menu-toggle]');
      const menu = header.querySelector('[data-tm4-menu]');
      if (!toggle || !menu) {
        return;
      }
      if (!menu.id) {
        menu.id = 'tm4-navigation';
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
      const toggle = header.querySelector('[data-tm4-menu-toggle]');
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
      const toggle = event.target.closest('[data-tm4-menu-toggle]');
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
      .filter((element) => element.closest('[data-tm4-experiences]') === root);
    const selected = find('[data-tm4-filter][aria-pressed="true"]')[0];
    let category = selected && categories.includes(selected.dataset.tm4Filter)
      ? selected.dataset.tm4Filter
      : 'all';
    let query = find('[data-tm4-search]')[0]?.value || '';
    let announcementTimer = 0;
    const normalize = (value) => value.normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLocaleLowerCase('es')
      .replace(/\s+/g, ' ')
      .trim();

    const update = (announce = false) => {
      let count = 0;
      const terms = normalize(query).split(' ').filter(Boolean);
      find('[data-tm4-search]').forEach((input) => {
        if (input.value !== query) {
          input.value = query;
        }
      });
      find('[data-tm4-filter]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.tm4Filter === category));
      });
      find('[data-tm4-card]').forEach((card) => {
        const matchesCategory = category === 'all' || card.dataset.category === category;
        const content = normalize(card.textContent || '');
        const visible = matchesCategory && terms.every((term) => content.includes(term));
        card.hidden = !visible;
        if (visible) {
          count += 1;
        }
      });
      root.classList.toggle('has-filter', category !== 'all' || terms.length > 0);
      categories.forEach((value) => root.classList.toggle(`filter-is-${value}`, value === category));
      const countLabel = Drupal.formatPlural(count, '1 lugar', '@count lugares');
      find('[data-tm4-results]').forEach((element) => {
        element.textContent = countLabel;
      });
      find('[data-tm4-empty]').forEach((element) => {
        element.hidden = count > 0;
      });
      find('[data-tm4-status]').forEach((status) => {
        status.setAttribute('role', 'status');
        status.setAttribute('aria-atomic', 'true');
        if (announce) {
          const labels = {
            pueblos: Drupal.t('en pueblos'),
            bienestar: Drupal.t('de bienestar'),
            naturaleza: Drupal.t('de naturaleza'),
          };
          const searchLabel = terms.length ? ` ${Drupal.t('para')} «${query.trim()}»` : '';
          status.textContent = `${countLabel}${labels[category] ? ` ${labels[category]}` : ''}${searchLabel}.`;
        }
      });
    };

    root.addEventListener('input', (event) => {
      if (
        !(event.target instanceof Element) || !event.target.matches('[data-tm4-search]') ||
        event.target.closest('[data-tm4-experiences]') !== root
      ) {
        return;
      }
      query = event.target.value;
      update();
      window.clearTimeout(announcementTimer);
      announcementTimer = window.setTimeout(() => {
        announcementTimer = 0;
        update(true);
      }, 250);
    }, { signal: controller.signal });

    root.addEventListener('click', (event) => {
      if (!(event.target instanceof Element)) {
        return;
      }
      const button = event.target.closest('[data-tm4-filter], [data-tm4-clear]');
      if (
        !button || button.closest('[data-tm4-experiences]') !== root
      ) {
        return;
      }
      if (button.hasAttribute('data-tm4-clear')) {
        event.preventDefault();
        window.clearTimeout(announcementTimer);
        category = 'all';
        query = '';
        const searchInputs = find('[data-tm4-search]');
        searchInputs.forEach((input) => {
          input.value = '';
        });
        update(true);
        searchInputs[0]?.focus({ preventScroll: true });
        return;
      }
      if (!categories.includes(button.dataset.tm4Filter)) {
        return;
      }
      event.preventDefault();
      window.clearTimeout(announcementTimer);
      category = button.dataset.tm4Filter;
      update(true);
    }, { signal: controller.signal });

    experienceStates.set(root, {
      update,
      cleanup() {
        controller.abort();
        window.clearTimeout(announcementTimer);
      },
    });
  }

  /**
   * Synchronize the manual atlas without changing the visitor's focus.
   */
  function initializeAtlas(root) {
    const controller = new AbortController();
    const find = (selector) => Array.from(root.querySelectorAll(selector))
      .filter((element) => element.closest('[data-tm4-atlas]') === root);
    let activePlace = '';

    const selectPlace = (button, announce = false) => {
      if (!button) {
        return;
      }
      activePlace = button.dataset.tm4Place || '';
      const name = button.dataset.name || '';
      const region = button.dataset.region || '';
      find('[data-tm4-place]').forEach((point) => {
        const selected = point === button;
        point.setAttribute('aria-pressed', String(selected));
        point.classList.toggle('is-active', selected);
      });
      find('[data-tm4-atlas-image]').forEach((image) => {
        if (button.dataset.image) {
          image.setAttribute('src', button.dataset.image);
        }
        image.setAttribute('alt', button.dataset.alt || name);
      });
      find('[data-tm4-atlas-title]').forEach((title) => {
        title.textContent = name;
      });
      find('[data-tm4-atlas-region]').forEach((element) => {
        element.textContent = region;
      });
      find('[data-tm4-atlas-open]').forEach((openButton) => {
        openButton.dataset.tm4DialogOpen = button.dataset.dialog || '';
        openButton.disabled = !button.dataset.dialog;
      });
      find('[data-tm4-atlas-status]').forEach((status) => {
        status.setAttribute('role', 'status');
        status.setAttribute('aria-atomic', 'true');
        if (announce) {
          status.textContent = `${Drupal.t('Lugar seleccionado:')} ${name}${region ? ` · ${region}` : ''}.`;
        }
      });
    };

    const refresh = () => {
      const points = find('[data-tm4-place]');
      const selected = points.find((point) => activePlace && point.dataset.tm4Place === activePlace)
        || points.find((point) => point.getAttribute('aria-pressed') === 'true')
        || points[0];
      selectPlace(selected);
    };

    root.addEventListener('click', (event) => {
      if (!(event.target instanceof Element)) {
        return;
      }
      const point = event.target.closest('[data-tm4-place]');
      if (!point || point.closest('[data-tm4-atlas]') !== root) {
        return;
      }
      event.preventDefault();
      selectPlace(point, true);
    }, { signal: controller.signal });

    atlasStates.set(root, {
      refresh,
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
      if (event.target.closest('[data-tm4-dialog-close]')) {
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
      const dialog = document.getElementById(button.dataset.tm4DialogOpen);
      if (
        !dialog || !dialog.matches('dialog[data-tm4-dialog]') ||
        typeof dialog.showModal !== 'function' || !dialog.isConnected
      ) {
        return;
      }
      event.preventDefault();
      if (dialog.open) {
        return;
      }
      once('tm4-dialog', [dialog]).forEach(initializeDialog);
      dialogStates.get(dialog).opener = button;
      dialog.showModal();
      synchronizeDialogLock();
    }, { signal: controller.signal });
    triggerCleanup.set(button, () => controller.abort());
  }

  Drupal.behaviors.trotamundos4 = {
    attach(context) {
      document.documentElement.classList.add('tm4-js');

      const headers = components(context, '[data-tm4-header]', true);
      once('tm4-header', headers).forEach(initializeHeader);
      headers.forEach((header) => headerStates.get(header)?.refresh());

      const experienceRoots = components(context, '[data-tm4-experiences]', true);
      once('tm4-experiences', experienceRoots).forEach(initializeExperiences);
      experienceRoots.forEach((root) => experienceStates.get(root)?.update());

      const atlasRoots = components(context, '[data-tm4-atlas]', true);
      once('tm4-atlas', atlasRoots).forEach(initializeAtlas);
      atlasRoots.forEach((root) => atlasStates.get(root)?.refresh());

      once('tm4-dialog', components(context, 'dialog[data-tm4-dialog]')).forEach(initializeDialog);
      once('tm4-dialog-trigger', components(context, '[data-tm4-dialog-open], [data-tm4-atlas-open]')).forEach(initializeDialogTrigger);
    },

    detach(context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      [
        ['tm4-header', '[data-tm4-header]', headerStates],
        ['tm4-experiences', '[data-tm4-experiences]', experienceStates],
        ['tm4-atlas', '[data-tm4-atlas]', atlasStates],
        ['tm4-dialog', 'dialog[data-tm4-dialog]', dialogStates],
      ].forEach(([onceId, selector, states]) => {
        once.remove(onceId, components(context, selector)).forEach((element) => {
          states.get(element)?.cleanup();
          states.delete(element);
        });
      });
      once.remove('tm4-dialog-trigger', components(context, '[data-tm4-dialog-open], [data-tm4-atlas-open]')).forEach((button) => {
        triggerCleanup.get(button)?.();
        triggerCleanup.delete(button);
      });
    },
  };
})(Drupal, once);
