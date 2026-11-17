/**
 * @file
 * Experience page: photo gallery, booking calculator and WhatsApp request.
 *
 * Online payment is not connected yet: "Reservar" reviews the request and
 * sends it by WhatsApp, where availability is confirmed and a secure payment
 * link is shared. Replace openReview() when a payment gateway is chosen.
 */

(function (Drupal, once) {
  'use strict';

  const openers = new WeakMap();

  function openDialog(dialog, opener) {
    if (!dialog || dialog.open || typeof dialog.showModal !== 'function') {
      return false;
    }
    openers.set(dialog, opener || document.activeElement);
    dialog.showModal();
    document.body.classList.add('dialog-is-open');
    return true;
  }

  function prepareDialog(dialog) {
    dialog.addEventListener('click', (event) => {
      if (event.target.closest('[data-dialog-close]')) {
        dialog.close();
        return;
      }
      if (event.target === dialog) {
        const box = dialog.getBoundingClientRect();
        const outside = event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom;
        if (outside) {
          dialog.close();
        }
      }
    });
    dialog.addEventListener('close', () => {
      document.body.classList.toggle('dialog-is-open', Boolean(document.querySelector('dialog[open]')));
      const opener = openers.get(dialog);
      if (opener && opener.isConnected && typeof opener.focus === 'function') {
        opener.focus({ preventScroll: true });
      }
      openers.delete(dialog);
    });
  }

  function parseDate(value) {
    const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
    return parts ? new Date(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3])) : null;
  }

  function initializeGallery(root) {
    const dialog = root.querySelector('[data-gallery-dialog]');
    if (!dialog) {
      return;
    }
    prepareDialog(dialog);
    root.querySelectorAll('[data-gallery-open]').forEach((button) => {
      button.addEventListener('click', () => {
        if (!openDialog(dialog, button)) {
          return;
        }
        const index = button.dataset.galleryOpen;
        const target = dialog.querySelector(`[data-gallery-index="${index}"]`);
        window.requestAnimationFrame(() => {
          if (target && index !== '0') {
            target.scrollIntoView({ block: 'start' });
          }
          else {
            dialog.scrollTop = 0;
          }
          const close = dialog.querySelector('[data-dialog-close]');
          if (close) {
            close.focus({ preventScroll: true });
          }
        });
      });
    });
  }

  function initializeBooking(root) {
    const card = root.querySelector('[data-booking]');
    const form = root.querySelector('[data-booking-form]');
    const dialog = root.querySelector('[data-booking-dialog]');
    if (!card || !form) {
      return;
    }
    if (dialog) {
      prepareDialog(dialog);
    }

    const lang = card.dataset.lang || document.documentElement.lang || 'es';
    const locale = lang === 'en' ? 'en-US' : 'es-CO';
    const price = Number(card.dataset.price) || 0;
    const capacity = Math.max(1, Number(card.dataset.capacity) || 12);
    let ranges = [];
    try {
      ranges = JSON.parse(card.dataset.ranges || '[]');
    }
    catch (error) {
      ranges = [];
    }

    const dateInput = form.querySelector('#booking-date');
    const dateMessage = form.querySelector('[data-booking-date-message]');
    const defaultMessage = dateMessage ? dateMessage.textContent : '';
    const travelersInput = form.querySelector('#booking-travelers');
    const minus = form.querySelector('[data-step="-1"]');
    const plus = form.querySelector('[data-step="1"]');
    const line = form.querySelector('[data-summary-line]');
    const subtotal = form.querySelector('[data-summary-subtotal]');
    const total = form.querySelector('[data-summary-total]');

    const money = (amount) => '$' + new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(amount);
    const longDate = (date) => new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', year: 'numeric' }).format(date);
    const travelersLabel = (count) => Drupal.formatPlural(count, '1 viajero', '@count viajeros');

    const travelers = () => {
      const value = Math.round(Number(travelersInput.value) || 1);
      return Math.min(capacity, Math.max(1, value));
    };

    const updateSummary = () => {
      const count = travelers();
      travelersInput.value = String(count);
      minus.disabled = count <= 1;
      plus.disabled = count >= capacity;
      const amount = price * count;
      line.textContent = `${money(price)} × ${travelersLabel(count)}`;
      subtotal.textContent = money(amount);
      total.textContent = `${money(amount)} COP`;
    };

    const setDateState = (state, message) => {
      dateMessage.classList.toggle('is-error', state === 'error');
      dateMessage.classList.toggle('is-valid', state === 'valid');
      dateMessage.textContent = message;
      if (state === 'error') {
        dateInput.setAttribute('aria-invalid', 'true');
      }
      else {
        dateInput.removeAttribute('aria-invalid');
      }
    };

    const validateDate = () => {
      const value = dateInput.value;
      if (!value) {
        setDateState('error', Drupal.t('Elige la fecha de llegada.'));
        return false;
      }
      const available = ranges.some((range) => value >= range.start && value <= range.end);
      if (!available) {
        const first = ranges[0];
        const last = ranges[ranges.length - 1];
        setDateState('error', first
          ? Drupal.t('Esa fecha no está disponible. Elige una entre el @start y el @end, o escríbenos.', {
            '@start': longDate(parseDate(first.start)),
            '@end': longDate(parseDate(last.end)),
          })
          : Drupal.t('Esa fecha no está disponible.'));
        return false;
      }
      setDateState('valid', Drupal.t('Fecha disponible para solicitar.'));
      return true;
    };

    const bookingMessage = () => {
      const count = travelers();
      return Drupal.t('Hola, quiero reservar «@title» (@location) para el @date, @travelers. Total estimado: @total COP.', {
        '@title': card.dataset.title,
        '@location': card.dataset.location,
        '@date': longDate(parseDate(dateInput.value)),
        '@travelers': travelersLabel(count),
        '@total': money(price * count),
      });
    };

    const openReview = () => {
      if (!dialog) {
        window.open(`${card.dataset.whatsapp}?text=${encodeURIComponent(bookingMessage())}`, '_blank', 'noopener');
        return;
      }
      const count = travelers();
      dialog.querySelector('[data-review-date]').textContent = longDate(parseDate(dateInput.value));
      dialog.querySelector('[data-review-travelers]').textContent = travelersLabel(count);
      dialog.querySelector('[data-review-total]').textContent = `${money(price * count)} COP`;
      dialog.querySelector('[data-review-whatsapp]').href = `${card.dataset.whatsapp}?text=${encodeURIComponent(bookingMessage())}`;
      openDialog(dialog, form.querySelector('[type="submit"]'));
    };

    form.addEventListener('click', (event) => {
      const step = event.target.closest('[data-step]');
      if (step) {
        travelersInput.value = String(travelers() + Number(step.dataset.step));
        updateSummary();
      }
    });
    travelersInput.addEventListener('change', updateSummary);
    dateInput.addEventListener('change', validateDate);
    dateInput.addEventListener('input', () => {
      if (!dateInput.value) {
        setDateState('', defaultMessage);
      }
    });
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!validateDate()) {
        dateInput.focus();
        return;
      }
      openReview();
    });

    updateSummary();

    // The bottom bar takes the visitor to the booking card and its date.
    const bar = root.querySelector('[data-booking-bar]');
    root.querySelectorAll('[data-booking-jump]').forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        card.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
        const target = card.querySelector('.commerce-purchase input[name="tm_trip_date"]') || dateInput;
        window.setTimeout(() => target.focus({ preventScroll: true }), 400);
      });
    });
    if (bar && 'IntersectionObserver' in window) {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => bar.classList.toggle('is-hidden', entry.isIntersecting));
      }, { threshold: 0.2 });
      observer.observe(card);
    }
  }

  Drupal.behaviors.trotamundosExperience = {
    attach(context) {
      once('trotamundos-experience', '[data-experience]', context).forEach((root) => {
        initializeGallery(root);
        initializeBooking(root);
      });
    },
  };
})(Drupal, once);
