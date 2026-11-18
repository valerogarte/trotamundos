/**
 * @file
 * Accessible destination, date-range and companion panels for the shared search.
 */

(function (Drupal, once) {
  'use strict';

  const cleanup = new WeakMap();
  const controlsCleanup = new WeakMap();
  const assistantInputCleanup = new WeakMap();
  // Distance between the assistant panel and the top of the viewport.
  const ASSISTANT_TOP_GAP = 12;

  function resizeAssistantInput(input) {
    const disclosure = input.closest('[data-discovery-assistant]');
    if (!disclosure.open || !input.getClientRects().length) {
      return;
    }
    const chat = input.closest('.trotamundos-assistant__chat');
    const scrollTop = input.scrollTop;
    input.style.overflowY = 'hidden';
    input.style.height = 'auto';
    input.style.height = `${input.scrollHeight}px`;
    input.style.overflowY = '';
    input.scrollTop = scrollTop;
    if (document.activeElement === input) {
      const hiddenBottom = input.parentElement.getBoundingClientRect().bottom - chat.getBoundingClientRect().bottom;
      if (hiddenBottom > 0) {
        chat.scrollTop += hiddenBottom;
      }
    }
  }

  function initializeAssistantInput(input) {
    const controller = new AbortController();
    const { signal } = controller;
    const disclosure = input.closest('[data-discovery-assistant]');
    let width = -1;
    const resize = () => {
      if (!signal.aborted) {
        resizeAssistantInput(input);
      }
    };
    input.addEventListener('input', resize, { signal });
    input.form.addEventListener('submit', () => queueMicrotask(resize), { signal });
    input.form.addEventListener('reset', () => queueMicrotask(resize), { signal });
    window.addEventListener('resize', resize, { signal });
    disclosure.addEventListener('toggle', () => {
      if (disclosure.open) {
        resize();
      }
    }, { signal });

    let observer;
    if (typeof ResizeObserver !== 'undefined') {
      observer = new ResizeObserver((entries) => {
        const nextWidth = entries[0].contentRect.width;
        if (nextWidth !== width) {
          width = nextWidth;
          resize();
        }
      });
      observer.observe(input);
    }
    else {
      const header = input.closest('[data-site-header]');
      header.addEventListener('trotamundosHeaderLayoutChanged', resize, { signal });
      header.addEventListener('transitionend', resize, { signal });
    }
    resize();
    assistantInputCleanup.set(input, () => {
      controller.abort();
      observer?.disconnect();
      input.style.height = '';
    });
  }

  function initializeControls(controls) {
    const disclosures = Array.from(controls.querySelectorAll(':scope > details[data-discovery-control]'));
    const assistant = disclosures.find((disclosure) => disclosure.hasAttribute('data-discovery-assistant'));
    const header = controls.closest('[data-site-header]');
    const controller = new AbortController();
    const { signal } = controller;
    let headerFrame;
    let assistantMotion;
    let assistantContentMotions = [];
    let assistantClosing = false;
    let assistantRestoreFocus = false;
    const stopAssistantMotion = () => {
      if (assistantMotion) {
        assistantMotion.cancel();
        assistantMotion = null;
      }
      assistantContentMotions.forEach((motion) => motion.cancel());
      assistantContentMotions = [];
    };
    const finishAssistantClose = () => {
      assistantClosing = false;
      controls.classList.remove('is-assistant-closing');
      assistant.open = false;
      if (assistantRestoreFocus) {
        assistant.querySelector(':scope > summary').focus({ preventScroll: true });
      }
      assistantRestoreFocus = false;
    };
    const positionPanel = (disclosure) => {
      const panel = disclosure.querySelector(':scope > .discovery-control__panel');
      const offsetParent = panel.offsetParent;
      if (!offsetParent) {
        return;
      }
      const anchor = offsetParent.getBoundingClientRect();
      const summary = disclosure.querySelector(':scope > summary');
      const trigger = summary.getBoundingClientRect();
      if (disclosure === assistant) {
        // Pinned near the top of the viewport, over the navigation, so the
        // answer painted in the main content stays visible below it.
        const top = (Drupal.displace?.offsets?.top || 0) + ASSISTANT_TOP_GAP;
        panel.style.top = `${top - anchor.top}px`;
        panel.style.maxHeight = `${Math.max(0, window.innerHeight - top - 12)}px`;
        return;
      }
      const headerTop = Math.max(0, header.getBoundingClientRect().top);
      panel.style.maxHeight = '';
      const minimumHeight = Math.min(panel.scrollHeight, 280, window.innerHeight - headerTop - 24);
      const top = Math.max(headerTop + 8, Math.min(trigger.bottom + 8, window.innerHeight - minimumHeight - 12));
      panel.style.top = `${top - anchor.top}px`;
      panel.style.maxHeight = `${window.innerHeight - top - 12}px`;
    };
    const animateAssistant = (disclosure, closing = false, resuming = false) => {
      const panel = disclosure.querySelector(':scope > .discovery-control__panel');
      const summary = disclosure.querySelector(':scope > summary');
      const currentClip = window.getComputedStyle(panel).clipPath;
      const currentTransform = window.getComputedStyle(panel).transform;
      const currentTriggerTransform = window.getComputedStyle(summary).transform;
      const content = Array.from(panel.querySelectorAll([
        '.trotamundos-assistant__heading',
        '.trotamundos-assistant__configuration-error',
        '.trotamundos-assistant__messages',
        '.trotamundos-assistant__composer',
        '.trotamundos-assistant__privacy-notice',
      ].join(', '))).map((element) => {
        const style = window.getComputedStyle(element);
        return { element, opacity: style.opacity, transform: style.transform };
      });
      stopAssistantMotion();
      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !panel.animate) {
        return null;
      }
      const trigger = summary.getBoundingClientRect();
      const bounds = panel.getBoundingClientRect();
      if (!bounds.width || !bounds.height) {
        return null;
      }
      const shiftX = trigger.left < bounds.left ? trigger.left - bounds.left : Math.max(0, trigger.right - bounds.right);
      const shiftY = trigger.top < bounds.top ? trigger.top - bounds.top : Math.max(0, trigger.bottom - bounds.bottom);
      const top = Math.max(0, trigger.top - bounds.top - shiftY);
      const right = Math.max(0, bounds.right + shiftX - trigger.right);
      const bottom = Math.max(0, bounds.bottom + shiftY - trigger.bottom);
      const left = Math.max(0, trigger.left - bounds.left - shiftX);
      const radius = window.getComputedStyle(panel).borderTopRightRadius;
      const triggerClip = `inset(${top}px ${right}px ${bottom}px ${left}px round ${trigger.height / 2}px)`;
      const panelClip = `inset(0px round ${radius})`;
      const shadowClip = `inset(-64px -72px -96px -72px round ${radius})`;
      const startClip = closing || resuming ? (currentClip !== 'none' ? currentClip : shadowClip) : triggerClip;
      const finalTransform = 'translate(0px, 0px)';
      const triggerTransform = `translate(${shiftX}px, ${shiftY}px)`;
      const startTransform = closing || resuming ? (currentTransform !== 'none' ? currentTransform : finalTransform) : triggerTransform;
      const easing = 'cubic-bezier(0.4, 0, 0.2, 1)';
      // Closing mirrors the opening: the shadow tucks in first, then the panel
      // folds back into the trigger and settles softly.
      const closeEasing = 'cubic-bezier(0.45, 0, 0.15, 1)';
      const closeHold = 0.18;
      const closeDuration = 440;
      const frames = closing
        ? [
          { clipPath: startClip, transform: startTransform, offset: 0, easing: 'ease-in' },
          { clipPath: panelClip, transform: startTransform, offset: closeHold, easing: closeEasing },
          { clipPath: triggerClip, transform: triggerTransform, offset: 1 },
        ]
        : [
          { clipPath: startClip, transform: startTransform, offset: 0, easing },
          { clipPath: panelClip, transform: finalTransform, offset: 0.8, easing: 'ease-out' },
          { clipPath: shadowClip, transform: finalTransform, offset: 1 },
        ];
      const motion = panel.animate(frames, { duration: closing ? closeDuration : resuming ? 300 : 480 });
      assistantMotion = motion;
      assistantContentMotions = content.filter(({ element }) => element.animate).map(({ element, opacity, transform }) => {
        const style = window.getComputedStyle(element);
        const contentMotion = element.animate([
          {
            opacity: closing || resuming ? opacity : 0,
            transform: closing || resuming ? transform : 'translateY(6px)',
          },
          {
            opacity: closing ? 0 : style.opacity,
            transform: closing ? 'translateY(4px)' : style.transform,
          },
        ], {
          duration: closing ? 170 : 200,
          delay: closing ? 0 : resuming ? 190 : 300,
          easing: closing ? 'ease-in' : 'ease-out',
          fill: closing ? 'forwards' : 'backwards',
        });
        if (!closing) {
          contentMotion.addEventListener('finish', () => {
            assistantContentMotions = assistantContentMotions.filter((item) => item !== contentMotion);
          }, { once: true });
        }
        return contentMotion;
      });
      if ((shiftX || shiftY || currentTriggerTransform !== 'none') && summary.animate) {
        const triggerStart = closing || resuming ? currentTriggerTransform : finalTransform;
        const triggerEnd = closing ? finalTransform : `translate(${-shiftX}px, ${-shiftY}px)`;
        const triggerFrames = closing
          ? [
            { transform: triggerStart, offset: 0 },
            { transform: triggerStart, offset: closeHold, easing: closeEasing },
            { transform: triggerEnd, offset: 1 },
          ]
          : [
            { transform: triggerStart, offset: 0, easing },
            { transform: triggerEnd, offset: 1 },
          ];
        const triggerMotion = summary.animate(triggerFrames, { duration: closing ? closeDuration : resuming ? 240 : 384 });
        assistantContentMotions.push(triggerMotion);
        triggerMotion.addEventListener('finish', () => {
          assistantContentMotions = assistantContentMotions.filter((item) => item !== triggerMotion);
        }, { once: true });
      }
      motion.addEventListener('finish', () => {
        if (assistantMotion === motion) {
          assistantMotion = null;
          if (assistantClosing) {
            finishAssistantClose();
          }
        }
      }, { once: true });
      return motion;
    };
    const closeDisclosure = (disclosure, restoreFocus = false, immediate = false) => {
      if (!disclosure.open) {
        return;
      }
      const summary = disclosure.querySelector(':scope > summary');
      const returnFocus = restoreFocus || (disclosure.contains(document.activeElement) && document.activeElement !== summary);
      if (disclosure === assistant && immediate) {
        stopAssistantMotion();
        assistantClosing = false;
        assistantRestoreFocus = false;
        controls.classList.remove('is-assistant-closing');
      }
      if (disclosure === assistant && !immediate) {
        assistantRestoreFocus ||= returnFocus;
        if (assistantClosing) {
          return;
        }
        if (animateAssistant(disclosure, true)) {
          assistantClosing = true;
          controls.classList.add('is-assistant-closing');
          return;
        }
        assistantRestoreFocus = false;
      }
      disclosure.open = false;
      if (returnFocus) {
        summary.focus({ preventScroll: true });
      }
    };
    const closeControls = () => disclosures.forEach((disclosure) => closeDisclosure(disclosure));

    controls.addEventListener('toggle', (event) => {
      const disclosure = event.target;
      if (disclosure === assistant) {
        controls.classList.toggle('is-assistant-open', disclosure.open);
        if (!disclosure.open) {
          stopAssistantMotion();
          assistantClosing = false;
          assistantRestoreFocus = false;
          controls.classList.remove('is-assistant-closing');
        }
      }
      if (disclosures.includes(disclosure) && disclosure.open) {
        disclosures.filter((other) => other !== disclosure).forEach((other) => closeDisclosure(other, false, true));
        positionPanel(disclosure);
        if (disclosure === assistant) {
          const summary = disclosure.querySelector(':scope > summary');
          const input = disclosure.querySelector('.trotamundos-assistant__input');
          if (input) {
            resizeAssistantInput(input);
          }
          if (document.activeElement === summary || !disclosure.contains(document.activeElement)) {
            (input && !input.disabled ? input : disclosure.querySelector('.trotamundos-assistant__info'))?.focus({ preventScroll: true });
          }
          animateAssistant(disclosure);
        }
      }
    }, { capture: true, signal });
    controls.addEventListener('trotamundosAssistantClose', (event) => {
      if (event.target === assistant) {
        event.preventDefault();
        closeDisclosure(assistant, true);
      }
    }, { signal });
    controls.addEventListener('trotamundosAssistantOpen', (event) => {
      if (event.target === assistant && assistantClosing) {
        assistantClosing = false;
        assistantRestoreFocus = false;
        controls.classList.remove('is-assistant-closing');
        positionPanel(assistant);
        animateAssistant(assistant, false, true);
      }
    }, { signal });
    const closeOnEscape = (event) => {
      if (!event.defaultPrevented && event.key === 'Escape') {
        const disclosure = disclosures.find((item) => item.open);
        if (disclosure) {
          event.preventDefault();
          event.stopPropagation();
          closeDisclosure(disclosure, true);
        }
      }
    };
    controls.addEventListener('keydown', closeOnEscape, { signal });
    document.addEventListener('keydown', closeOnEscape, { signal });
    controls.addEventListener('click', (event) => {
      const close = event.target instanceof Element && event.target.closest('[data-discovery-control-close]');
      const disclosure = close && close.closest('details[data-discovery-control]');
      if (disclosures.includes(disclosure)) {
        closeDisclosure(disclosure, true);
      }
    }, { signal });
    document.addEventListener('click', (event) => {
      const prompt = event.target instanceof Element && event.target.closest('[data-trotamundos-assistant-prompt]');
      if (!controls.contains(event.target) && !prompt) {
        closeControls();
      }
    }, { signal });
    const updateOpenPanels = () => {
      disclosures.filter((disclosure) => disclosure.open).forEach((disclosure) => {
        if (disclosure === assistant) {
          stopAssistantMotion();
          if (assistantClosing) {
            finishAssistantClose();
          }
          else {
            positionPanel(disclosure);
          }
        }
        else {
          positionPanel(disclosure);
        }
      });
    };
    const updateAfterHeaderLayout = () => {
      updateOpenPanels();
      window.cancelAnimationFrame(headerFrame);
      // Compact controls can settle after the header's class change.
      headerFrame = window.requestAnimationFrame(() => {
        headerFrame = window.requestAnimationFrame(updateOpenPanels);
      });
    };
    window.addEventListener('scroll', updateOpenPanels, { passive: true, signal });
    window.addEventListener('resize', updateOpenPanels, { signal });
    header.addEventListener('trotamundosHeaderLayoutChanged', updateAfterHeaderLayout, { signal });

    controlsCleanup.set(controls, () => {
      controller.abort();
      stopAssistantMotion();
      disclosures.forEach((disclosure) => closeDisclosure(disclosure, false, true));
      controls.classList.remove('is-assistant-open', 'is-assistant-closing');
      window.cancelAnimationFrame(headerFrame);
    });
  }

  function initialize(discovery) {
    const form = discovery.querySelector('form');
    const header = discovery.closest('[data-site-header]');
    const destination = form.querySelector('#discovery-destino');
    const companion = form.querySelector('#discovery-acompanamiento');
    const start = form.querySelector('#discovery-desde');
    const end = form.querySelector('#discovery-hasta');
    const margin = form.querySelector('#discovery-margen');
    const legacyMonth = form.querySelector('#discovery-fecha');
    const destinationLabel = form.querySelector('[data-discovery-destination-value]');
    const companionLabel = form.querySelector('[data-discovery-companion-value]');
    const dateLabel = form.querySelector('[data-discovery-date-value]');
    const calendar = form.querySelector('[data-discovery-panel="dates"]');
    const months = calendar.querySelector('[data-calendar-months]');
    const status = calendar.querySelector('[data-calendar-status]');
    const apply = calendar.querySelector('[data-calendar-apply]');
    const choices = form.querySelector('.discovery-destinations__choices');
    const today = discovery.dataset.today;
    const locale = document.documentElement.lang === 'en' ? 'en-GB' : document.documentElement.lang;
    const shortDate = new Intl.DateTimeFormat(locale, { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' });
    const compactDate = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', timeZone: 'UTC' });
    const compactYearDate = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
    const fullDate = new Intl.DateTimeFormat(locale, { dateStyle: 'full', timeZone: 'UTC' });
    const monthName = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' });
    const weekday = new Intl.DateTimeFormat(locale, { weekday: 'short', timeZone: 'UTC' });
    const fullWeekday = new Intl.DateTimeFormat(locale, { weekday: 'long', timeZone: 'UTC' });
    const marginLabels = new Map(Array.from(margin.options, (option) => [option.value, option.textContent.trim()]));
    const parse = (value) => new Date(`${value}T00:00:00Z`);
    const iso = (date) => date.toISOString().slice(0, 10);
    const monthIndex = (value) => parse(value).getUTCFullYear() * 12 + parse(value).getUTCMonth();
    const monthDate = (index, day = 1) => new Date(Date.UTC(Math.floor(index / 12), index % 12, day));
    const currentMonth = monthIndex(today);
    const lastMonth = 9999 * 12 + 11;
    const monthCount = () => Math.min(discovery.clientWidth >= 520 ? 2 : 1, lastMonth - currentMonth + 1);
    const clampDay = (date) => new Date(Math.min(parse('9999-12-31').getTime(), Math.max(parse(today).getTime(), date.getTime())));
    const shiftDay = (value, days) => {
      const date = parse(value);
      date.setUTCDate(date.getUTCDate() + days);
      return iso(clampDay(date));
    };
    const shiftMonth = (value, offset) => {
      const index = monthIndex(value) + offset;
      const day = Math.min(parse(value).getUTCDate(), monthDate(index + 1, 0).getUTCDate());
      return iso(clampDay(monthDate(index, day)));
    };
    let activePanel = null;
    let activeTrigger = null;
    let panelFrame = 0;
    let viewMonth = currentMonth;
    let renderedMonthCount = 0;
    let draft = { start: '', end: '', margin: '' };

    const formatRange = (first, last, flexibility) => {
      let label = shortDate.format(parse(first));
      if (first !== last) {
        label += ` \u2013 ${shortDate.format(parse(last))}`;
      }
      return flexibility ? `${label} (${marginLabels.get(flexibility)})` : label;
    };
    const updateLabels = () => {
      destinationLabel.textContent = destination.selectedOptions[0].textContent.trim();
      companionLabel.textContent = companion.selectedOptions[0].textContent.trim();
      form.querySelectorAll('[data-companion-value]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.companionValue === companion.value));
      });
      const fullLabel = start.value && end.value
        ? formatRange(start.value, end.value, margin.value)
        : (legacyMonth.value ? monthName.format(parse(`${legacyMonth.value}-01`)) : Drupal.t('Cualquier fecha'));
      if (start.value && end.value) {
        const formatter = start.value.slice(0, 4) === today.slice(0, 4) && end.value.slice(0, 4) === today.slice(0, 4) ? compactDate : compactYearDate;
        dateLabel.textContent = formatter.formatRange(parse(start.value), parse(end.value)).replace(/\./g, '')
          + (margin.value ? ` (\u00b1${margin.value})` : '');
      }
      else {
        dateLabel.textContent = fullLabel;
      }
      dateLabel.title = fullLabel;
      form.querySelector('[data-discovery-open="dates"]').setAttribute('aria-label', `${Drupal.t('Fechas')}: ${fullLabel}`);
      form.querySelectorAll('[data-destination-value]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.destinationValue === destination.value));
      });
    };
    const closePanel = (restoreFocus = false) => {
      if (!activePanel) {
        return;
      }
      const panel = activePanel;
      const trigger = activeTrigger;
      activePanel = null;
      activeTrigger = null;
      if (restoreFocus || panel.contains(document.activeElement)) {
        const target = trigger.getClientRects().length ? trigger : discovery.querySelector('[data-discovery-toggle]');
        target.focus({ preventScroll: true });
      }
      panel.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      trigger.closest('.discovery-bar__field').classList.remove('is-open');
      form.classList.remove('is-panel-open');
    };
    const positionPanel = (panel) => {
      const bar = form.getBoundingClientRect();
      const isCompanion = panel.dataset.discoveryPanel === 'companions';
      // The compact list needs its full height, border and viewport gutter.
      const requiredSpace = isCompanion ? panel.scrollHeight + 14 : 420;
      const top = Math.min(bar.bottom + 12, Math.max(header.getBoundingClientRect().top + 8, window.innerHeight - requiredSpace));
      if (isCompanion) {
        const field = activeTrigger.closest('.discovery-bar__field').getBoundingClientRect();
        panel.style.insetInlineEnd = `${bar.right - field.right}px`;
      }
      panel.style.top = `${top - bar.top - form.clientTop}px`;
      panel.style.maxHeight = `${window.innerHeight - top - 12}px`;
    };
    const updateCalendarControls = () => {
      calendar.querySelectorAll('[data-calendar-margin]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.calendarMargin === draft.margin));
      });
      apply.disabled = Boolean(draft.start) !== Boolean(draft.end) || Boolean(draft.margin && !draft.start);
      calendar.querySelector('[data-calendar-nav="-1"]').disabled = viewMonth <= currentMonth;
      calendar.querySelector('[data-calendar-nav="1"]').disabled = viewMonth >= lastMonth - monthCount() + 1;
      if (draft.start && draft.end) {
        status.textContent = formatRange(draft.start, draft.end, draft.margin);
      }
      else {
        const visible = Array.from({ length: monthCount() }, (unused, index) => monthName.format(monthDate(viewMonth + index)));
        const prompt = draft.start ? Drupal.t('Ahora elige la fecha de fin') : Drupal.t('Elige el inicio de tu viaje');
        status.textContent = `${visible.join(' \u2013 ')}. ${prompt}`;
      }
    };
    const revealFocused = (panel, element) => {
      const panelBounds = panel.getBoundingClientRect();
      const headingBottom = panel.querySelector(':scope > .discovery-panel__heading').getBoundingClientRect().bottom;
      const visibleTop = Math.max(panelBounds.top + 12, headingBottom + 8);
      const bounds = element.getBoundingClientRect();
      if (bounds.top < visibleTop) {
        panel.scrollTop -= visibleTop - bounds.top;
      }
      else if (bounds.bottom > panelBounds.bottom - 12) {
        panel.scrollTop += bounds.bottom - panelBounds.bottom + 12;
      }
    };
    const focusDay = (value) => {
      const button = months.querySelector(`[data-calendar-day="${value}"]`);
      button.focus({ preventScroll: true });
      revealFocused(calendar, button);
    };
    const renderCalendar = (focusValue = '') => {
      renderedMonthCount = monthCount();
      let tabDay = focusValue || draft.start || today;
      if (monthIndex(tabDay) < viewMonth || monthIndex(tabDay) >= viewMonth + renderedMonthCount) {
        tabDay = iso(monthDate(viewMonth));
      }
      tabDay = tabDay < today ? today : tabDay;
      const fragment = document.createDocumentFragment();
      for (let index = 0; index < renderedMonthCount; index++) {
        const displayedMonth = viewMonth + index;
        const first = monthDate(displayedMonth);
        const count = monthDate(displayedMonth + 1, 0).getUTCDate();
        const leading = (first.getUTCDay() + 6) % 7;
        const section = document.createElement('section');
        section.className = 'discovery-calendar__month';
        const heading = document.createElement('h3');
        heading.id = `discovery-month-${index}`;
        heading.textContent = monthName.format(first);
        const table = document.createElement('table');
        table.setAttribute('role', 'grid');
        table.setAttribute('aria-labelledby', heading.id);
        table.setAttribute('aria-describedby', 'discovery-calendar-help');
        const head = document.createElement('thead');
        const weekdays = document.createElement('tr');
        for (let day = 0; day < 7; day++) {
          const date = new Date(Date.UTC(2024, 0, 1 + day));
          const cell = document.createElement('th');
          cell.scope = 'col';
          cell.abbr = fullWeekday.format(date);
          cell.textContent = weekday.format(date).replace(/\.$/, '');
          weekdays.append(cell);
        }
        head.append(weekdays);
        const body = document.createElement('tbody');
        const cells = Math.ceil((leading + count) / 7) * 7;
        let row;
        for (let position = 0; position < cells; position++) {
          if (position % 7 === 0) {
            row = document.createElement('tr');
            body.append(row);
          }
          const cell = document.createElement('td');
          cell.className = 'discovery-calendar__cell';
          cell.setAttribute('role', 'gridcell');
          row.append(cell);
          const day = position - leading + 1;
          if (day < 1 || day > count) {
            continue;
          }
          const date = monthDate(displayedMonth, day);
          const value = iso(date);
          const isStart = value === draft.start;
          const isEnd = value === draft.end;
          const isBetween = Boolean(draft.end && value > draft.start && value < draft.end);
          cell.dataset.calendarCell = value;
          cell.classList.toggle('is-start', isStart);
          cell.classList.toggle('is-end', isEnd);
          cell.classList.toggle('is-between', isBetween);
          cell.classList.toggle('is-pending', isStart && !draft.end);
          cell.setAttribute('aria-selected', String(isStart || isEnd || isBetween));
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'discovery-calendar__day';
          button.dataset.calendarDay = value;
          button.textContent = String(day);
          button.disabled = value < today;
          button.tabIndex = value === tabDay && !button.disabled ? 0 : -1;
          const label = [fullDate.format(date)];
          if (isStart) {
            label.push(Drupal.t('Inicio del viaje'));
          }
          if (isEnd) {
            label.push(Drupal.t('Fin del viaje'));
          }
          button.setAttribute('aria-label', label.join(', '));
          if (value === today) {
            button.setAttribute('aria-current', 'date');
          }
          cell.append(button);
        }
        table.append(head, body);
        section.append(heading, table);
        fragment.append(section);
      }
      months.replaceChildren(fragment);
      updateCalendarControls();
      if (focusValue) {
        focusDay(focusValue);
      }
    };
    const openPanel = (name) => {
      const panel = form.querySelector(`[data-discovery-panel="${name}"]`);
      if (activePanel === panel) {
        closePanel(true);
        return;
      }
      closePanel();
      activePanel = panel;
      activeTrigger = form.querySelector(`[data-discovery-open="${name}"]`);
      activeTrigger.setAttribute('aria-expanded', 'true');
      activeTrigger.closest('.discovery-bar__field').classList.add('is-open');
      form.classList.add('is-panel-open');
      panel.hidden = false;
      panel.scrollTop = 0;
      positionPanel(panel);
      if (name === 'dates') {
        draft = { start: start.value, end: end.value, margin: margin.value };
        const focus = draft.start || today;
        viewMonth = Math.max(currentMonth, Math.min(monthIndex(focus), lastMonth - monthCount() + 1));
        renderCalendar(focus);
      }
      else if (name === 'destination') {
        const selected = choices.querySelector('[aria-pressed="true"]');
        choices.scrollTop += selected.getBoundingClientRect().top - choices.getBoundingClientRect().top
          - (choices.clientHeight - selected.offsetHeight) / 2;
        selected.focus({ preventScroll: true });
        revealFocused(panel, selected);
      }
      else {
        const selected = panel.querySelector('[aria-pressed="true"]');
        selected.focus({ preventScroll: true });
        revealFocused(panel, selected);
      }
    };
    const onClick = (event) => {
      const button = event.target.closest('button');
      if (!button || !form.contains(button)) {
        return;
      }
      if (button.hasAttribute('data-discovery-open')) {
        openPanel(button.dataset.discoveryOpen);
      }
      else if (button.hasAttribute('data-discovery-close')) {
        closePanel(true);
      }
      else if (button.hasAttribute('data-destination-value')) {
        destination.value = button.dataset.destinationValue;
        destination.dispatchEvent(new Event('change', { bubbles: true }));
      }
      else if (button.hasAttribute('data-companion-value')) {
        companion.value = button.dataset.companionValue;
        companion.dispatchEvent(new Event('change', { bubbles: true }));
      }
      else if (button.hasAttribute('data-calendar-day')) {
        event.stopPropagation();
        const value = button.dataset.calendarDay;
        if (!draft.start || draft.end || value < draft.start) {
          draft.start = value;
          draft.end = '';
        }
        else {
          draft.end = value;
        }
        renderCalendar(value);
      }
      else if (button.hasAttribute('data-calendar-nav')) {
        viewMonth += Number(button.dataset.calendarNav);
        renderCalendar();
      }
      else if (button.hasAttribute('data-calendar-margin')) {
        draft.margin = button.dataset.calendarMargin;
        updateCalendarControls();
      }
      else if (button.hasAttribute('data-calendar-clear')) {
        draft = { start: '', end: '', margin: '' };
        viewMonth = currentMonth;
        renderCalendar();
      }
      else if (button.hasAttribute('data-calendar-apply')) {
        start.value = draft.start;
        end.value = draft.end;
        end.min = start.value || today;
        margin.value = draft.margin;
        legacyMonth.value = '';
        [start, end, margin, legacyMonth].forEach((control) => {
          control.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }
    };
    const onKeydown = (event) => {
      if (event.key === 'Escape' && activePanel) {
        event.preventDefault();
        event.stopPropagation();
        closePanel(true);
        return;
      }
      if (event.key === 'ArrowDown' && event.target.hasAttribute('data-discovery-open') && !activePanel) {
        event.preventDefault();
        openPanel(event.target.dataset.discoveryOpen);
        return;
      }
      if (event.target.hasAttribute('data-companion-value') && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
        event.preventDefault();
        const options = Array.from(activePanel.querySelectorAll('[data-companion-value]'));
        const index = options.indexOf(event.target);
        const next = event.key === 'Home' ? 0
          : event.key === 'End' ? options.length - 1
            : (index + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
        options[next].focus({ preventScroll: true });
        revealFocused(activePanel, options[next]);
        return;
      }
      const value = event.target.dataset.calendarDay;
      if (!value) {
        return;
      }
      const weekdayIndex = (parse(value).getUTCDay() + 6) % 7;
      const offsets = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7, Home: -weekdayIndex, End: 6 - weekdayIndex };
      let next;
      if (Object.hasOwn(offsets, event.key)) {
        next = shiftDay(value, offsets[event.key]);
      }
      else if (event.key === 'PageUp' || event.key === 'PageDown') {
        next = shiftMonth(value, (event.key === 'PageUp' ? -1 : 1) * (event.shiftKey ? 12 : 1));
      }
      else {
        return;
      }
      event.preventDefault();
      const nextMonth = monthIndex(next);
      if (nextMonth < viewMonth) {
        viewMonth = nextMonth;
      }
      else if (nextMonth >= viewMonth + monthCount()) {
        viewMonth = nextMonth - monthCount() + 1;
      }
      renderCalendar(next);
    };
    const clearPreview = () => {
      months.querySelectorAll('.is-preview').forEach((cell) => cell.classList.remove('is-preview'));
    };
    const onPointerOver = (event) => {
      const button = event.target.closest('[data-calendar-day]');
      if (!button || !draft.start || draft.end) {
        return;
      }
      const last = button.dataset.calendarDay;
      months.querySelectorAll('[data-calendar-cell]').forEach((cell) => {
        cell.classList.toggle('is-preview', cell.dataset.calendarCell >= draft.start && cell.dataset.calendarCell <= last);
      });
    };
    const onOutsideClick = (event) => {
      if (activePanel && !activePanel.contains(event.target) && !activeTrigger.contains(event.target)) {
        closePanel();
      }
    };
    const onDocumentKeydown = (event) => {
      if (!event.defaultPrevented && event.key === 'Escape' && activePanel) {
        event.preventDefault();
        closePanel(true);
      }
    };
    const onViewportChange = () => {
      if (!activePanel) {
        return;
      }
      positionPanel(activePanel);
      const count = monthCount();
      if (activePanel === calendar && count !== renderedMonthCount) {
        const focusedDay = calendar.contains(document.activeElement) ? document.activeElement.dataset.calendarDay || '' : '';
        if (focusedDay && monthIndex(focusedDay) >= viewMonth + count) {
          viewMonth = monthIndex(focusedDay) - count + 1;
        }
        viewMonth = Math.min(viewMonth, lastMonth - count + 1);
        renderCalendar(focusedDay);
      }
    };
    const onHeaderLayoutChange = () => {
      onViewportChange();
      window.cancelAnimationFrame(panelFrame);
      panelFrame = window.requestAnimationFrame(() => {
        panelFrame = window.requestAnimationFrame(onViewportChange);
      });
    };
    const onPageShow = () => {
      updateLabels();
      form.dispatchEvent(new Event('change', { bubbles: true }));
    };

    form.addEventListener('click', onClick);
    form.addEventListener('keydown', onKeydown);
    form.addEventListener('change', updateLabels, true);
    months.addEventListener('pointerover', onPointerOver);
    months.addEventListener('pointerleave', clearPreview);
    document.addEventListener('click', onOutsideClick);
    document.addEventListener('keydown', onDocumentKeydown);
    window.addEventListener('scroll', onViewportChange, { passive: true });
    window.addEventListener('resize', onViewportChange);
    header.addEventListener('trotamundosHeaderLayoutChanged', onHeaderLayoutChange);
    window.addEventListener('pageshow', onPageShow);
    start.readOnly = true;
    end.readOnly = true;
    updateLabels();
    discovery.classList.add('is-enhanced');

    cleanup.set(discovery, () => {
      closePanel();
      form.removeEventListener('click', onClick);
      form.removeEventListener('keydown', onKeydown);
      form.removeEventListener('change', updateLabels, true);
      months.removeEventListener('pointerover', onPointerOver);
      months.removeEventListener('pointerleave', clearPreview);
      document.removeEventListener('click', onOutsideClick);
      document.removeEventListener('keydown', onDocumentKeydown);
      window.removeEventListener('scroll', onViewportChange);
      window.removeEventListener('resize', onViewportChange);
      header.removeEventListener('trotamundosHeaderLayoutChanged', onHeaderLayoutChange);
      window.removeEventListener('pageshow', onPageShow);
      window.cancelAnimationFrame(panelFrame);
      start.readOnly = false;
      end.readOnly = false;
      discovery.classList.remove('is-enhanced');
    });
  }

  Drupal.behaviors.trotamundosDiscovery = {
    attach(context) {
      once('trotamundos-discovery', '[data-header-discovery]', context).forEach(initialize);
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once.remove('trotamundos-discovery', '[data-header-discovery]', context).forEach((discovery) => {
          const destroy = cleanup.get(discovery);
          if (destroy) {
            destroy();
            cleanup.delete(discovery);
          }
        });
      }
    },
  };

  Drupal.behaviors.trotamundosDiscoveryControls = {
    attach(context) {
      once('trotamundos-discovery-controls', '[data-discovery-controls]', context).forEach(initializeControls);
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once.remove('trotamundos-discovery-controls', '[data-discovery-controls]', context).forEach((controls) => {
          const destroy = controlsCleanup.get(controls);
          if (destroy) {
            destroy();
            controlsCleanup.delete(controls);
          }
        });
      }
    },
  };

  Drupal.behaviors.trotamundosDiscoveryAssistantInput = {
    attach(context) {
      once('trotamundos-discovery-assistant-input', '[data-discovery-assistant] .trotamundos-assistant__input', context).forEach(initializeAssistantInput);
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once.remove('trotamundos-discovery-assistant-input', '[data-discovery-assistant] .trotamundos-assistant__input', context).forEach((input) => {
          const destroy = assistantInputCleanup.get(input);
          if (destroy) {
            destroy();
            assistantInputCleanup.delete(input);
          }
        });
      }
    },
  };
})(Drupal, once);
