/**
 * @file
 * Accessible interactions shared by the Trotamundos theme.
 */

(function (Drupal, once) {
  'use strict';

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const desktopNavigation = window.matchMedia('(min-width: 1024px)');
  const navigationCleanup = new WeakMap();
  const styleSliderCleanup = new WeakMap();
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

  function initializeStyleSlider(slider) {
    const track = slider.querySelector('[data-style-track]');
    const controls = slider.querySelector('[data-style-controls]');
    const previous = controls.querySelector('[data-style-scroll="-1"]');
    const next = controls.querySelector('[data-style-scroll="1"]');
    const controller = new AbortController();
    const { signal } = controller;
    const sync = () => {
      previous.disabled = track.scrollLeft <= 1;
      next.disabled = track.scrollLeft >= track.scrollWidth - track.clientWidth - 1;
    };
    controls.querySelectorAll('[data-style-scroll]').forEach((button) => {
      button.addEventListener('click', () => {
        track.scrollBy({
          left: Number(button.dataset.styleScroll) * track.clientWidth * 0.85,
          behavior: reducedMotion.matches ? 'auto' : 'smooth',
        });
      }, { signal });
    });
    track.addEventListener('scroll', sync, { passive: true, signal });
    const observer = new ResizeObserver(sync);
    observer.observe(track);
    controls.hidden = false;
    sync();
    styleSliderCleanup.set(slider, () => {
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
    const mobileSearch = window.matchMedia('(max-width: 639px)');
    let frame = 0;
    let focusTimer = 0;
    let searchMotion = null;
    const stopSearchMotion = () => {
      if (searchMotion) {
        searchMotion.cancel();
        searchMotion = null;
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
      const previous = animate && discovery && !reducedMotion.matches ? discovery.getBoundingClientRect() : null;
      stopSearchMotion();
      header.classList.toggle('header-is-scrolled', compact);
      if (previous && previous.width && previous.height) {
        const next = discovery.getBoundingClientRect();
        const x = previous.left + previous.width / 2 - next.left - next.width / 2;
        searchMotion = discovery.animate([
          { width: `${previous.width}px`, translate: `${x}px ${previous.top - next.top}px` },
          { width: `${next.width}px`, translate: '0px 0px' },
        ], { duration: 420, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' });
      }
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
      header.classList.toggle('search-is-open', open);
      discoveryToggle.setAttribute('aria-expanded', String(open));
      if (restoreFocus) {
        discoveryToggle.focus({ preventScroll: true });
      }
    };
    const updateDiscoverySummary = () => {
      if (!summaryDestination || !summaryDetails || !destination || !date || !companion) {
        return;
      }
      summaryDestination.textContent = destination.value ? destination.selectedOptions[0].textContent.trim() : defaultDestination;
      summaryDetails.textContent = `${date.textContent} \u00b7 ${companion.selectedOptions[0].textContent}`;
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
        if (!header.contains(document.activeElement)) {
          closeNavigation();
          setSearchOpen(false);
        }
      }, 0);
    };
    const onViewportChange = () => {
      stopSearchMotion();
      closeLanguage(Boolean(languageSelector && languageSelector.contains(document.activeElement)));
      closeNavigation(navigation.contains(document.activeElement));
      setSearchOpen(mobileSearch.matches && Boolean(discoveryForm && discoveryForm.contains(document.activeElement)));
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
      once('trotamundos-style-slider', '[data-style-slider]', context).forEach(initializeStyleSlider);

      once('trotamundos-filter', '[data-experience-filter]', context).forEach((button) => {
        button.addEventListener('click', () => {
          const scope = button.closest('section, [data-brand-guide]') || document;
          filterExperiences(scope, button.dataset.experienceFilter, true);
        });
      });

      // GET forms submit only the filters that have a value.
      once('trotamundos-clean-query', 'form[data-clean-query]', context).forEach((form) => {
        form.addEventListener('submit', () => {
          // form.elements includes fields linked with the "form" attribute.
          Array.from(form.elements).forEach((field) => {
            if (field.name && field.value === '' && field.type !== 'radio') {
              field.dataset.cleanName = field.name;
              field.removeAttribute('name');
            }
            // An empty radio choice ("Todas") is dropped the same way.
            if (field.type === 'radio' && field.checked && field.value === '') {
              form.querySelectorAll(`input[type="radio"][name="${field.name}"]`).forEach((radio) => {
                radio.dataset.cleanName = radio.name;
                radio.removeAttribute('name');
              });
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
      once.remove('trotamundos-style-slider', '[data-style-slider]', context).forEach((slider) => {
        const cleanup = styleSliderCleanup.get(slider);
        if (cleanup) {
          cleanup();
          styleSliderCleanup.delete(slider);
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
