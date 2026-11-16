/**
 * @file
 * Clipboard, typography specimens and navigation for the brand guide.
 */

(function (Drupal, once) {
  'use strict';

  const guideCleanup = new WeakMap();
  const defaultText = 'Viajar bonito. Sentir de verdad.';
  const allowedWeights = ['400', '500', '600'];

  /**
   * Include an Ajax context that is itself the guide root.
   */
  function guideRoots(context) {
    const roots = Array.from(context.querySelectorAll('[data-brand-guide]'));
    if (context.matches && context.matches('[data-brand-guide]')) {
      roots.unshift(context);
    }
    return roots;
  }

  /**
   * Attempt the legacy clipboard path, checking its actual return value.
   */
  function copyWithSelection(value) {
    if (typeof document.execCommand !== 'function') {
      return false;
    }

    const previousFocus = document.activeElement;
    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.setAttribute('aria-hidden', 'true');
    textarea.setAttribute('tabindex', '-1');
    Object.assign(textarea.style, {
      position: 'fixed',
      top: '0',
      left: '0',
      width: '1px',
      height: '1px',
      padding: '0',
      border: '0',
      opacity: '0',
      pointerEvents: 'none',
    });

    document.body.append(textarea);
    try {
      textarea.focus({ preventScroll: true });
      textarea.select();
      textarea.setSelectionRange(0, value.length);
      return document.execCommand('copy') === true;
    }
    catch (error) {
      return false;
    }
    finally {
      textarea.remove();
      if (previousFocus && previousFocus.isConnected && typeof previousFocus.focus === 'function') {
        previousFocus.focus({ preventScroll: true });
      }
    }
  }

  /**
   * The Clipboard API needs a secure context; HTTP uses the selection fallback.
   */
  async function copyColor(value, root) {
    if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
      try {
        await navigator.clipboard.writeText(value);
        return true;
      }
      catch (error) {
        // Permissions can deny the modern API while selection copying works.
      }
    }
    return root.isConnected && copyWithSelection(value);
  }

  function initializeGuide(root) {
    const controller = new AbortController();
    const copiedTimers = new Map();
    let copyRequest = 0;
    let observer;

    const announce = (message) => {
      root.querySelectorAll('[data-brand-status]').forEach((status) => {
        status.setAttribute('role', 'status');
        status.textContent = message;
      });
    };

    root.querySelectorAll('[data-brand-status]').forEach((status) => {
      status.setAttribute('role', 'status');
    });

    const updateSpecimens = () => {
      const textInput = root.querySelector('#brand-specimen-text');
      const weightInput = root.querySelector('#brand-specimen-weight');
      const sizeInput = root.querySelector('#brand-specimen-size');
      let text = defaultText;
      if (textInput) {
        textInput.maxLength = 90;
        const boundedText = textInput.value.slice(0, 90);
        if (textInput.value !== boundedText) {
          textInput.value = boundedText;
        }
        text = boundedText.trim() ? boundedText : defaultText;
      }

      const weight = weightInput && allowedWeights.includes(weightInput.value)
        ? weightInput.value
        : '400';
      const requestedSize = sizeInput ? Number(sizeInput.value) : 48;
      const size = Number.isFinite(requestedSize)
        ? Math.max(24, Math.min(72, requestedSize))
        : 48;

      root.style.setProperty('--specimen-size', `${size}px`);
      root.querySelectorAll('[data-font-specimen]').forEach((specimen) => {
        specimen.textContent = text;
        specimen.style.fontWeight = weight;
        specimen.style.setProperty('--specimen-size', `${size}px`);
      });
      root.querySelectorAll('[data-specimen-size]').forEach((output) => {
        output.textContent = `${size} px`;
      });
    };

    updateSpecimens();

    const sectionForLink = (link) => {
      try {
        const url = new URL(link.href, window.location.href);
        if (
          url.origin !== window.location.origin ||
          url.pathname !== window.location.pathname ||
          !url.hash
        ) {
          return null;
        }
        const section = document.getElementById(decodeURIComponent(url.hash.slice(1)));
        return section && root.contains(section) && section.matches('[data-brand-section]')
          ? section
          : null;
      }
      catch (error) {
        return null;
      }
    };

    const navigationLinks = Array.from(root.querySelectorAll('[data-brand-nav] a[href], a[data-brand-nav][href]'));
    const sections = Array.from(root.querySelectorAll('[data-brand-section][id]'));
    const setCurrentSection = (section) => {
      navigationLinks.forEach((link) => {
        const isCurrent = sectionForLink(link) === section;
        link.classList.toggle('is-current', isCurrent);
        if (isCurrent) {
          link.setAttribute('aria-current', 'location');
        }
        else {
          link.removeAttribute('aria-current');
        }
      });
    };

    if (sections.length) {
      let initialSection;
      try {
        initialSection = document.getElementById(decodeURIComponent(window.location.hash.slice(1)));
      }
      catch (error) {
        initialSection = null;
      }
      setCurrentSection(sections.includes(initialSection) ? initialSection : sections[0]);
    }

    if ('IntersectionObserver' in window && navigationLinks.length && sections.length) {
      const visibleSections = new Set();
      const observeSections = () => {
        if (observer) {
          observer.disconnect();
        }
        visibleSections.clear();
        const topMargin = Math.round(window.innerHeight * 0.15);
        const bottomMargin = Math.round(window.innerHeight * 0.6);
        observer = new IntersectionObserver((entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              visibleSections.add(entry.target);
            }
            else {
              visibleSections.delete(entry.target);
            }
          });
          if (visibleSections.size) {
            const readingLine = window.innerHeight * 0.15;
            const currentSection = Array.from(visibleSections).sort((first, second) => (
              Math.abs(first.getBoundingClientRect().top - readingLine) -
              Math.abs(second.getBoundingClientRect().top - readingLine)
            ))[0];
            setCurrentSection(currentSection);
          }
        }, { rootMargin: `-${topMargin}px 0px -${bottomMargin}px 0px`, threshold: 0 });
        sections.forEach((section) => observer.observe(section));
      };
      observeSections();
      window.addEventListener('resize', observeSections, { signal: controller.signal });
    }

    const onSpecimenChange = (event) => {
      if (event.target.matches('#brand-specimen-text, #brand-specimen-weight, #brand-specimen-size')) {
        updateSpecimens();
      }
    };
    root.addEventListener('input', onSpecimenChange, { signal: controller.signal });
    root.addEventListener('change', onSpecimenChange, { signal: controller.signal });

    root.addEventListener('click', async (event) => {
      const target = event.target.closest('[data-copy-color], [data-specimen-reset], [data-brand-print], [data-brand-nav] a[href], a[data-brand-nav][href]');
      if (!target || !root.contains(target)) {
        return;
      }

      if (target.hasAttribute('data-copy-color')) {
        event.preventDefault();
        const request = ++copyRequest;
        const color = target.dataset.copyColor.trim();
        if (!/^#[0-9a-f]{6}$/i.test(color)) {
          announce(Drupal.t('No se pudo copiar el color.'));
          return;
        }
        const copied = await copyColor(color, root);
        if (!root.isConnected || request !== copyRequest) {
          return;
        }
        if (copied) {
          target.dataset.copied = 'true';
          if (copiedTimers.has(target)) {
            window.clearTimeout(copiedTimers.get(target));
          }
          copiedTimers.set(target, window.setTimeout(() => {
            target.removeAttribute('data-copied');
            copiedTimers.delete(target);
          }, 2000));
          announce(Drupal.t('Color @color copiado.', { '@color': color }));
        }
        else {
          target.removeAttribute('data-copied');
          announce(Drupal.t('No se pudo copiar @color. Selecciona el código y cópialo manualmente.', { '@color': color }));
        }
      }
      else if (target.hasAttribute('data-specimen-reset')) {
        event.preventDefault();
        const textInput = root.querySelector('#brand-specimen-text');
        const weightInput = root.querySelector('#brand-specimen-weight');
        const sizeInput = root.querySelector('#brand-specimen-size');
        if (textInput) {
          textInput.value = defaultText;
        }
        if (weightInput) {
          weightInput.value = '400';
        }
        if (sizeInput) {
          sizeInput.value = '48';
        }
        updateSpecimens();
        announce(Drupal.t('Muestra de tipografía restablecida.'));
      }
      else if (target.hasAttribute('data-brand-print')) {
        event.preventDefault();
        window.print();
      }
      else {
        const section = sectionForLink(target);
        if (section) {
          setCurrentSection(section);
        }
      }
    }, { signal: controller.signal });

    guideCleanup.set(root, () => {
      copyRequest += 1;
      controller.abort();
      if (observer) {
        observer.disconnect();
      }
      copiedTimers.forEach((timer, button) => {
        window.clearTimeout(timer);
        button.removeAttribute('data-copied');
      });
      copiedTimers.clear();
    });
  }

  Drupal.behaviors.trotamundosBrandGuide = {
    attach(context) {
      once('trotamundos-brand-guide', guideRoots(context)).forEach(initializeGuide);
    },

    detach(context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      once.remove('trotamundos-brand-guide', guideRoots(context)).forEach((root) => {
        const cleanup = guideCleanup.get(root);
        if (cleanup) {
          cleanup();
          guideCleanup.delete(root);
        }
      });
    },
  };
})(Drupal, once);
