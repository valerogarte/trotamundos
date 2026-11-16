/**
 * @file
 * Accessible interactions shared by the Trotamundos theme.
 */

(function (Drupal, once) {
  'use strict';

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const desktopNavigation = window.matchMedia('(min-width: 1024px)');
  const navigationCleanup = new WeakMap();
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

  /**
   * Mobile navigation with Escape, focus return and sticky header shadow.
   */
  function initializeNavigation(header) {
    const toggle = header.querySelector('[data-menu-toggle]');
    const navigation = header.querySelector('#trotamundos-navigation');
    if (!toggle || !navigation) {
      return;
    }

    let frame = 0;
    const updateHeader = () => {
      frame = 0;
      header.classList.toggle('header-is-scrolled', window.scrollY > 24);
    };
    const onScroll = () => {
      if (!frame) {
        frame = window.requestAnimationFrame(updateHeader);
      }
    };
    const closeLabel = Drupal.t('Cerrar menú');
    const label = toggle.querySelector('.menu-toggle__label');
    const openText = label ? label.textContent : '';

    const closeNavigation = (restoreFocus = false) => {
      header.classList.remove('menu-is-open');
      document.body.classList.remove('navigation-is-open');
      toggle.setAttribute('aria-expanded', 'false');
      if (label) {
        label.textContent = openText;
      }
      if (restoreFocus) {
        toggle.focus({ preventScroll: true });
      }
    };
    const onToggle = () => {
      if (header.classList.contains('menu-is-open')) {
        closeNavigation();
        return;
      }
      header.classList.add('menu-is-open');
      document.body.classList.add('navigation-is-open');
      toggle.setAttribute('aria-expanded', 'true');
      if (label) {
        label.textContent = closeLabel;
      }
    };
    const onNavigationClick = (event) => {
      if (event.target.closest('a[href]')) {
        closeNavigation();
      }
    };
    const onKeydown = (event) => {
      if (event.key === 'Escape' && header.classList.contains('menu-is-open')) {
        closeNavigation(true);
      }
    };
    const onViewportChange = (event) => {
      if (event.matches) {
        closeNavigation();
      }
    };

    toggle.addEventListener('click', onToggle);
    navigation.addEventListener('click', onNavigationClick);
    document.addEventListener('keydown', onKeydown);
    desktopNavigation.addEventListener('change', onViewportChange);
    window.addEventListener('scroll', onScroll, { passive: true });
    updateHeader();

    navigationCleanup.set(header, () => {
      closeNavigation();
      toggle.removeEventListener('click', onToggle);
      navigation.removeEventListener('click', onNavigationClick);
      document.removeEventListener('keydown', onKeydown);
      desktopNavigation.removeEventListener('change', onViewportChange);
      window.removeEventListener('scroll', onScroll);
      window.cancelAnimationFrame(frame);
      header.classList.remove('header-is-scrolled');
    });
  }

  Drupal.behaviors.trotamundos = {
    attach(context) {
      document.documentElement.classList.add('trotamundos-js');

      once('trotamundos-navigation', '[data-site-header]', context).forEach(initializeNavigation);

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
