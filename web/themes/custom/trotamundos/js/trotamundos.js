/**
 * @file
 * Accessible interactions shared by the Trotamundos theme.
 */

(function (Drupal, once) {
  'use strict';

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const desktopNavigation = window.matchMedia('(min-width: 1024px)');
  const navigationCleanup = new WeakMap();
  const sliderCleanup = new WeakMap();
  let revealObserver;

  /**
   * Filters the home cards by travel style without moving keyboard focus.
   */
  function filterExperiences(scope, style, announce) {
    let count = 0;
    scope.querySelectorAll('[data-experience-filter]').forEach((button) => {
      button.setAttribute('aria-pressed', String(button.dataset.experienceFilter === style));
    });
    scope.querySelectorAll('[data-experience-card]').forEach((card) => {
      const styles = (card.dataset.styles || '').split(' ');
      const visible = style === 'all' || styles.includes(style);
      card.hidden = !visible;
      if (visible) {
        count += 1;
      }
    });

    if (announce) {
      const status = scope.querySelector('[data-results-status]');
      if (status) {
        status.textContent = Drupal.formatPlural(count, '1 experiencia', '@count experiencias');
      }
    }
  }

  /**
   * Reveal only after intersection, so unobserved content remains visible.
   */
  function prepareReveals(context) {
    const elements = once('trotamundos-reveal', '[data-reveal]', context);
    if (!elements.length) {
      return;
    }
    if (reducedMotion.matches || !('IntersectionObserver' in window)) {
      elements.forEach((element) => element.classList.add('is-visible'));
      return;
    }
    try {
      if (!revealObserver) {
        revealObserver = new IntersectionObserver((entries) => {
          entries.forEach((entry) => {
            if (!entry.isIntersecting) {
              return;
            }
            const element = entry.target;
            revealObserver.unobserve(element);
            element.classList.add('reveal-ready');
            window.requestAnimationFrame(() => {
              window.requestAnimationFrame(() => element.classList.add('is-visible'));
            });
          });
        }, { threshold: 0.08 });
      }
      elements.forEach((element) => {
        const rectangle = element.getBoundingClientRect();
        if (rectangle.top < window.innerHeight && rectangle.bottom > 0) {
          element.classList.add('is-visible');
        }
        else {
          revealObserver.observe(element);
        }
      });
    }
    catch (error) {
      elements.forEach((element) => {
        element.classList.remove('reveal-ready');
        element.classList.add('is-visible');
      });
    }
  }

  function initializeLoopingSlider(slider) {
    const viewport = slider.querySelector('[data-slider-viewport]');
    const track = slider.querySelector('[data-slider-track]');
    const controls = slider.querySelector('[data-slider-controls]');
    const previous = controls.querySelector('[data-slider-scroll="-1"]');
    const next = controls.querySelector('[data-slider-scroll="1"]');
    // The pause button is optional: without it the rotation still stops on
    // hover, focus, hidden tabs and reduced motion.
    const autoplay = controls.querySelector('[data-slider-autoplay]');
    const cards = Array.from(track.children).map((card) => {
      const link = card.querySelector('a');
      return {
        card,
        link,
        hidden: card.getAttribute('aria-hidden'),
        tabindex: link?.getAttribute('tabindex'),
      };
    });
    const controller = new AbortController();
    const { signal } = controller;
    let canMove = false;
    let paused = false;
    let pointerInside = slider.matches(':hover');
    let trackFocused = track.contains(document.activeElement);
    let timer = 0;
    let motion = null;

    const restoreAttribute = (element, name, value) => {
      if (value === null || value === undefined) {
        element.removeAttribute(name);
      }
      else {
        element.setAttribute(name, value);
      }
    };
    const syncVisibility = (revealFocus = false, focusTarget = document.activeElement) => {
      const bounds = viewport.getBoundingClientRect();
      const focusedCard = focusTarget instanceof Node
        ? cards.find(({ card }) => card.contains(focusTarget))?.card
        : null;
      if (revealFocus && canMove && focusedCard) {
        const focusedBounds = focusedCard.getBoundingClientRect();
        if (focusedBounds.left < bounds.left - 1 || focusedBounds.right > bounds.right + 1) {
          while (track.firstElementChild !== focusedCard) {
            track.append(track.firstElementChild);
          }
        }
      }
      cards.forEach(({ card, link, hidden, tabindex }) => {
        const cardBounds = card.getBoundingClientRect();
        const visible = !canMove || card === focusedCard
          || (cardBounds.left >= bounds.left - 1 && cardBounds.right <= bounds.right + 1)
          || (card === track.firstElementChild && cardBounds.left < bounds.right && cardBounds.right > bounds.left);
        if (visible) {
          restoreAttribute(card, 'aria-hidden', hidden);
          if (link) {
            restoreAttribute(link, 'tabindex', tabindex);
          }
        }
        else {
          card.setAttribute('aria-hidden', 'true');
          link?.setAttribute('tabindex', '-1');
        }
      });
    };
    const cancelMotion = () => {
      if (!motion) {
        return;
      }
      const current = motion;
      motion = null;
      current.animation.cancel();
      current.clone.remove();
      syncVisibility();
    };
    const finishMotion = () => {
      if (!motion) {
        return;
      }
      const current = motion;
      motion = null;
      if (current.direction > 0) {
        track.append(current.original);
      }
      else {
        track.prepend(current.original);
      }
      current.clone.remove();
      current.animation.cancel();
      syncVisibility();
    };
    const move = (direction) => {
      if (!canMove) {
        return;
      }
      finishMotion();
      const first = track.children[0];
      const second = track.children[1];
      const distance = second.getBoundingClientRect().left - first.getBoundingClientRect().left;
      const original = direction > 0 ? first : track.lastElementChild;
      if (reducedMotion.matches || typeof track.animate !== 'function') {
        if (direction > 0) {
          track.append(original);
        }
        else {
          track.prepend(original);
        }
        syncVisibility();
        return;
      }

      const clone = original.cloneNode(true);
      clone.setAttribute('aria-hidden', 'true');
      clone.setAttribute('inert', '');
      clone.removeAttribute('id');
      clone.querySelectorAll('[id]').forEach((element) => element.removeAttribute('id'));
      clone.querySelectorAll('a, button, input, select, textarea, [tabindex]').forEach((element) => element.setAttribute('tabindex', '-1'));
      if (direction > 0) {
        track.append(clone);
      }
      else {
        track.prepend(clone);
      }
      const animation = track.animate(
        direction > 0
          ? [{ transform: 'translateX(0)' }, { transform: `translateX(-${distance}px)` }]
          : [{ transform: `translateX(-${distance}px)` }, { transform: 'translateX(0)' }],
        { duration: 480, easing: 'cubic-bezier(0.22, 1, 0.36, 1)', fill: 'both' },
      );
      motion = { animation, clone, direction, original };
      animation.addEventListener('finish', finishMotion, { once: true });
    };
    const stopTimer = () => {
      if (timer) {
        window.clearInterval(timer);
        timer = 0;
      }
    };
    const updateAutoplay = () => {
      if (autoplay) {
        autoplay.hidden = !canMove || reducedMotion.matches;
        autoplay.classList.toggle('is-paused', paused);
        autoplay.setAttribute('aria-label', paused ? autoplay.dataset.resumeLabel : autoplay.dataset.pauseLabel);
      }
      if (!canMove || reducedMotion.matches || paused || pointerInside || trackFocused || document.hidden) {
        stopTimer();
      }
      else if (!timer) {
        timer = window.setInterval(() => move(1), 2500);
      }
    };
    const restartAutoplay = () => {
      stopTimer();
      updateAutoplay();
    };
    const sync = () => {
      finishMotion();
      canMove = cards.length > 1 && track.scrollWidth > viewport.clientWidth + 1;
      previous.disabled = !canMove;
      next.disabled = !canMove;
      syncVisibility(true);
      updateAutoplay();
    };

    controls.querySelectorAll('[data-slider-scroll]').forEach((button) => {
      button.addEventListener('click', () => {
        move(Number(button.dataset.sliderScroll));
        restartAutoplay();
      }, { signal });
    });
    autoplay?.addEventListener('click', () => {
      paused = !paused;
      updateAutoplay();
    }, { signal });
    track.addEventListener('keydown', (event) => {
      if (event.defaultPrevented || event.target !== track || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
        return;
      }
      if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        move(event.key === 'ArrowRight' ? 1 : -1);
        restartAutoplay();
      }
    }, { signal });
    track.addEventListener('focusin', (event) => {
      trackFocused = true;
      cancelMotion();
      syncVisibility(false, event.target);
      updateAutoplay();
    }, { signal });
    track.addEventListener('focusout', (event) => {
      syncVisibility(false, event.relatedTarget);
      if (!track.contains(event.relatedTarget)) {
        trackFocused = false;
        updateAutoplay();
      }
    }, { signal });
    slider.addEventListener('pointerenter', () => {
      pointerInside = true;
      updateAutoplay();
    }, { signal });
    slider.addEventListener('pointerleave', () => {
      pointerInside = false;
      updateAutoplay();
    }, { signal });
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        cancelMotion();
      }
      updateAutoplay();
    }, { signal });
    reducedMotion.addEventListener('change', () => {
      if (reducedMotion.matches) {
        cancelMotion();
      }
      updateAutoplay();
    }, { signal });
    window.addEventListener('resize', sync, { signal });

    viewport.scrollLeft = 0;
    slider.classList.add('is-looping');
    controls.hidden = false;
    const observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(sync) : null;
    observer?.observe(viewport);
    sync();
    sliderCleanup.set(slider, () => {
      controller.abort();
      observer?.disconnect();
      stopTimer();
      cancelMotion();
      cards.forEach(({ card, link, hidden, tabindex }) => {
        track.append(card);
        restoreAttribute(card, 'aria-hidden', hidden);
        if (link) {
          restoreAttribute(link, 'tabindex', tabindex);
        }
      });
      slider.classList.remove('is-looping');
      controls.hidden = true;
    });
  }

  function initializeSlider(slider) {
    if (slider.hasAttribute('data-slider-loop')) {
      initializeLoopingSlider(slider);
      return;
    }
    const track = slider.querySelector('[data-slider-track]');
    const controls = slider.querySelector('[data-slider-controls]');
    const previous = controls.querySelector('[data-slider-scroll="-1"]');
    const next = controls.querySelector('[data-slider-scroll="1"]');
    const controller = new AbortController();
    const { signal } = controller;
    const sync = () => {
      previous.disabled = track.scrollLeft <= 1;
      next.disabled = track.scrollLeft >= track.scrollWidth - track.clientWidth - 1;
    };
    const scroll = (direction) => {
      track.scrollBy({
        left: direction * track.clientWidth * 0.85,
        behavior: reducedMotion.matches ? 'auto' : 'smooth',
      });
    };
    controls.querySelectorAll('[data-slider-scroll]').forEach((button) => {
      button.addEventListener('click', () => {
        scroll(Number(button.dataset.sliderScroll));
      }, { signal });
    });
    track.addEventListener('keydown', (event) => {
      if (event.defaultPrevented || event.target !== track || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
        return;
      }
      if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        scroll(event.key === 'ArrowRight' ? 1 : -1);
      }
    }, { signal });
    track.addEventListener('scroll', sync, { passive: true, signal });
    const observer = new ResizeObserver(sync);
    observer.observe(track);
    controls.hidden = false;
    sync();
    sliderCleanup.set(slider, () => {
      controller.abort();
      observer.disconnect();
      controls.hidden = true;
    });
  }

  /**
   * Header disclosures, focus return and the compact search on scroll.
   */
  function initializeNavigation(header) {
    const toggle = header.querySelector('[data-menu-toggle]');
    const navigation = header.querySelector('#trotamundos-navigation');
    if (!toggle || !navigation) {
      return;
    }

    const languageSelector = header.querySelector('[data-language-selector]');
    const discovery = header.querySelector('[data-header-discovery]');
    const discoveryForm = header.querySelector('#discovery-form');
    const discoveryToggle = header.querySelector('[data-discovery-toggle]');
    const summaryDestination = header.querySelector('[data-discovery-destination]');
    const summaryDetails = header.querySelector('[data-discovery-details]');
    const defaultDestination = summaryDestination ? summaryDestination.textContent : '';
    const destination = header.querySelector('#discovery-destino');
    const date = header.querySelector('[data-discovery-date-value]');
    const companion = header.querySelector('#discovery-acompanamiento');
    const navigationLinks = header.querySelectorAll('[data-discovery-navigation]');
    const searchNames = ['destino', 'fecha', 'desde', 'hasta', 'margen', 'acompanamiento'];
    const mobileSearch = window.matchMedia('(max-width: 639px)');
    let frame = 0;
    let focusTimer = 0;
    let searchMotion = null;
    const notifyHeaderLayout = () => header.dispatchEvent(new Event('trotamundosHeaderLayoutChanged'));
    const discoveryObserver = discovery && typeof ResizeObserver !== 'undefined'
      ? new ResizeObserver(notifyHeaderLayout)
      : null;
    discoveryObserver?.observe(discovery);
    const stopSearchMotion = () => {
      if (searchMotion) {
        searchMotion.cancel();
        searchMotion = null;
        notifyHeaderLayout();
      }
    };
    // Keep a moving control under the pointer until its click is dispatched.
    const pauseSearchMotion = () => {
      if (searchMotion && searchMotion.playState === 'running') {
        searchMotion.pause();
      }
    };
    const resumeSearchMotion = () => {
      if (searchMotion && searchMotion.playState === 'paused') {
        searchMotion.play();
      }
    };
    const updateHeader = (animate = false) => {
      frame = 0;
      const compact = window.scrollY > (discoveryForm ? 72 : 24);
      if (header.classList.contains('header-is-scrolled') === compact) {
        return;
      }
      const previous = animate && discovery && !mobileSearch.matches && !reducedMotion.matches ? discovery.getBoundingClientRect() : null;
      stopSearchMotion();
      header.classList.toggle('header-is-scrolled', compact);
      if (previous && previous.width && previous.height) {
        const next = discovery.getBoundingClientRect();
        const x = previous.left + previous.width / 2 - next.left - next.width / 2;
        searchMotion = discovery.animate([
          { width: `${previous.width}px`, translate: `${x}px ${previous.top - next.top}px` },
          { width: `${next.width}px`, translate: '0px 0px' },
        ], { duration: 420, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' });
        const motion = searchMotion;
        motion.addEventListener('finish', () => {
          if (searchMotion === motion) {
            searchMotion = null;
            notifyHeaderLayout();
          }
        }, { once: true });
      }
      notifyHeaderLayout();
    };
    const onScroll = () => {
      resumeSearchMotion();
      closeLanguage();
      if (!frame) {
        frame = window.requestAnimationFrame(() => updateHeader(true));
      }
    };
    const closeLabel = Drupal.t('Cerrar menú');
    const label = toggle.querySelector('.menu-toggle__label');
    const openText = label ? label.textContent : '';

    const closeNavigation = (restoreFocus = false) => {
      header.classList.remove('menu-is-open');
      toggle.setAttribute('aria-expanded', 'false');
      if (label) {
        label.textContent = openText;
      }
      if (restoreFocus) {
        toggle.focus({ preventScroll: true });
      }
    };
    const closeLanguage = (restoreFocus = false) => {
      if (languageSelector && languageSelector.open) {
        languageSelector.open = false;
        if (restoreFocus) {
          languageSelector.querySelector('summary').focus({ preventScroll: true });
        }
      }
    };
    const setSearchOpen = (open, restoreFocus = false) => {
      if (!discoveryToggle) {
        return;
      }
      const changed = header.classList.contains('search-is-open') !== open;
      header.classList.toggle('search-is-open', open);
      discoveryToggle.setAttribute('aria-expanded', String(open));
      if (!open && mobileSearch.matches) {
        const filters = header.querySelector('[data-discovery-controls] > details:not([data-discovery-assistant])');
        if (filters) {
          filters.open = false;
        }
      }
      if (restoreFocus) {
        discoveryToggle.focus({ preventScroll: true });
      }
      if (changed) {
        notifyHeaderLayout();
      }
    };
    const updateDiscoverySummary = () => {
      if (!summaryDestination || !summaryDetails || !destination || !date || !companion) {
        return;
      }
      summaryDestination.textContent = destination.value ? destination.selectedOptions[0].textContent.trim() : defaultDestination;
      summaryDetails.textContent = `${date.textContent} \u00b7 ${companion.selectedOptions[0].textContent}`;
      navigationLinks.forEach((link) => {
        const url = new URL(link.href);
        searchNames.forEach((name) => {
          const field = discoveryForm.elements.namedItem(name);
          const value = field ? field.value : '';
          if (value) {
            url.searchParams.set(name, value);
          }
          else {
            url.searchParams.delete(name);
          }
        });
        link.href = url.href;
      });
    };
    const onSearchToggle = () => {
      const open = !header.classList.contains('search-is-open');
      closeLanguage();
      closeNavigation();
      setSearchOpen(open);
      if (open && destination) {
        const trigger = header.querySelector('[data-discovery-open="destination"]');
        (trigger && trigger.offsetParent ? trigger : destination).focus({ preventScroll: true });
      }
    };
    const onToggle = () => {
      closeLanguage();
      setSearchOpen(false);
      if (header.classList.contains('menu-is-open')) {
        closeNavigation();
        return;
      }
      header.classList.add('menu-is-open');
      toggle.setAttribute('aria-expanded', 'true');
      if (label) {
        label.textContent = closeLabel;
      }
    };
    const onLanguageToggle = () => {
      if (languageSelector.open) {
        closeNavigation();
        setSearchOpen(false);
      }
    };
    const onNavigationClick = (event) => {
      if (event.target.closest('a[href]')) {
        closeNavigation();
      }
    };
    const onKeydown = (event) => {
      if (event.key === 'Escape' && languageSelector && languageSelector.open) {
        event.preventDefault();
        closeLanguage(true);
      }
      else if (event.key === 'Escape' && header.classList.contains('menu-is-open')) {
        closeNavigation(true);
      }
      else if (event.key === 'Escape' && header.classList.contains('search-is-open')) {
        setSearchOpen(false, true);
      }
    };
    const onOutsideClick = (event) => {
      stopSearchMotion();
      if (languageSelector && !languageSelector.contains(event.target)) {
        closeLanguage();
      }
      if (!navigation.contains(event.target) && !toggle.contains(event.target)) {
        closeNavigation();
      }
      if (!header.contains(event.target)) {
        setSearchOpen(false);
      }
    };
    const onFocusOut = () => {
      window.clearTimeout(focusTimer);
      focusTimer = window.setTimeout(() => {
        if (languageSelector && !languageSelector.contains(document.activeElement)) {
          closeLanguage();
        }
        if (header.classList.contains('menu-is-open')
          && !navigation.contains(document.activeElement)
          && !toggle.contains(document.activeElement)) {
          closeNavigation();
        }
        if (!header.contains(document.activeElement)) {
          closeNavigation();
          const panelOpen = discovery && discovery.querySelector('[data-discovery-panel]:not([hidden]), details[data-discovery-control][open]');
          if (document.activeElement !== document.body && !panelOpen) {
            setSearchOpen(false);
          }
        }
      }, 0);
    };
    const onViewportChange = () => {
      stopSearchMotion();
      closeLanguage(Boolean(languageSelector && languageSelector.contains(document.activeElement)));
      closeNavigation(navigation.contains(document.activeElement));
      const panelOpen = discoveryForm && discoveryForm.querySelector('[data-discovery-panel]:not([hidden])');
      const filtersOpen = discovery && discovery.querySelector('details[data-discovery-control]:not([data-discovery-assistant])[open]');
      setSearchOpen(mobileSearch.matches && Boolean(discoveryForm && (discoveryForm.contains(document.activeElement) || panelOpen || filtersOpen)));
    };

    toggle.addEventListener('click', onToggle);
    if (languageSelector) {
      languageSelector.addEventListener('toggle', onLanguageToggle);
    }
    navigation.addEventListener('click', onNavigationClick);
    header.addEventListener('focusout', onFocusOut);
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('click', onOutsideClick);
    desktopNavigation.addEventListener('change', onViewportChange);
    mobileSearch.addEventListener('change', onViewportChange);
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', stopSearchMotion);
    window.addEventListener('blur', stopSearchMotion);
    window.addEventListener('pointerup', resumeSearchMotion);
    window.addEventListener('pointercancel', resumeSearchMotion);
    reducedMotion.addEventListener('change', stopSearchMotion);
    if (discovery) {
      discovery.addEventListener('pointerdown', pauseSearchMotion, true);
      discovery.addEventListener('click', stopSearchMotion, true);
      discovery.addEventListener('keydown', stopSearchMotion, true);
    }
    window.addEventListener('pageshow', updateDiscoverySummary);
    if (discoveryToggle) {
      discoveryToggle.addEventListener('click', onSearchToggle);
    }
    if (discoveryForm) {
      discoveryForm.addEventListener('change', updateDiscoverySummary);
    }
    updateDiscoverySummary();
    updateHeader();

    navigationCleanup.set(header, () => {
      discoveryObserver?.disconnect();
      stopSearchMotion();
      closeLanguage();
      closeNavigation();
      setSearchOpen(false);
      toggle.removeEventListener('click', onToggle);
      if (languageSelector) {
        languageSelector.removeEventListener('toggle', onLanguageToggle);
      }
      navigation.removeEventListener('click', onNavigationClick);
      header.removeEventListener('focusout', onFocusOut);
      document.removeEventListener('keydown', onKeydown);
      document.removeEventListener('click', onOutsideClick);
      desktopNavigation.removeEventListener('change', onViewportChange);
      mobileSearch.removeEventListener('change', onViewportChange);
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', stopSearchMotion);
      window.removeEventListener('blur', stopSearchMotion);
      window.removeEventListener('pointerup', resumeSearchMotion);
      window.removeEventListener('pointercancel', resumeSearchMotion);
      reducedMotion.removeEventListener('change', stopSearchMotion);
      if (discovery) {
        discovery.removeEventListener('pointerdown', pauseSearchMotion, true);
        discovery.removeEventListener('click', stopSearchMotion, true);
        discovery.removeEventListener('keydown', stopSearchMotion, true);
      }
      window.removeEventListener('pageshow', updateDiscoverySummary);
      window.cancelAnimationFrame(frame);
      window.clearTimeout(focusTimer);
      if (discoveryToggle) {
        discoveryToggle.removeEventListener('click', onSearchToggle);
      }
      if (discoveryForm) {
        discoveryForm.removeEventListener('change', updateDiscoverySummary);
      }
      header.classList.remove('header-is-scrolled');
    });
  }

  Drupal.behaviors.trotamundos = {
    attach(context) {
      document.documentElement.classList.add('trotamundos-js');

      once('trotamundos-navigation', '[data-site-header]', context).forEach(initializeNavigation);
      once('trotamundos-slider', '[data-trotamundos-slider]', context).forEach(initializeSlider);

      once('trotamundos-filter', '[data-experience-filter]', context).forEach((button) => {
        button.addEventListener('click', () => {
          const scope = button.closest('section, [data-brand-guide]') || document;
          filterExperiences(scope, button.dataset.experienceFilter, true);
        });
      });

      // GET forms submit only the filters that have a value.
      once('trotamundos-clean-query', 'form[data-clean-query]', context).forEach((form) => {
        form.addEventListener('submit', (event) => {
          if (event.defaultPrevented) {
            return;
          }
          // form.elements includes fields linked with the "form" attribute.
          Array.from(form.elements).forEach((field) => {
            if (field.name && field.value === '') {
              field.dataset.cleanName = field.name;
              field.removeAttribute('name');
            }
          });
          // Restore names if the page is shown again from the history cache.
          window.addEventListener('pageshow', () => {
            Array.from(form.elements).forEach((field) => {
              if (field.dataset.cleanName) {
                field.name = field.dataset.cleanName;
                delete field.dataset.cleanName;
              }
            });
          }, { once: true });
        });
      });

      prepareReveals(context);
    },

    detach(context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      once.remove('trotamundos-navigation', '[data-site-header]', context).forEach((header) => {
        const cleanup = navigationCleanup.get(header);
        if (cleanup) {
          cleanup();
          navigationCleanup.delete(header);
        }
      });
      once.remove('trotamundos-slider', '[data-trotamundos-slider]', context).forEach((slider) => {
        const cleanup = sliderCleanup.get(slider);
        if (cleanup) {
          cleanup();
          sliderCleanup.delete(slider);
        }
      });
      if (revealObserver) {
        context.querySelectorAll('[data-reveal]').forEach((element) => revealObserver.unobserve(element));
      }
    },
  };

  reducedMotion.addEventListener('change', (event) => {
    if (event.matches) {
      if (revealObserver) {
        revealObserver.disconnect();
      }
      document.querySelectorAll('[data-reveal]').forEach((element) => {
        element.classList.remove('reveal-ready');
        element.classList.add('is-visible');
      });
    }
  });
})(Drupal, once);
