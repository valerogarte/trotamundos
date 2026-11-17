/**
 * @file
 * Accessible destination, date-range and companion panels for the shared search.
 */

(function (Drupal, once) {
  'use strict';

  const cleanup = new WeakMap();

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
    let focusTimer = 0;
    let viewMonth = currentMonth;
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
      panel.scrollTop = 0;
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
    const focusDay = (value) => {
      const button = months.querySelector(`[data-calendar-day="${value}"]`);
      button.focus({ preventScroll: true });
      const panelBounds = calendar.getBoundingClientRect();
      const dayBounds = button.getBoundingClientRect();
      if (dayBounds.top < panelBounds.top + 12) {
        calendar.scrollTop -= panelBounds.top + 12 - dayBounds.top;
      }
      else if (dayBounds.bottom > panelBounds.bottom - 12) {
        calendar.scrollTop += dayBounds.bottom - panelBounds.bottom + 12;
      }
    };
    const renderCalendar = (focusValue = '') => {
      let tabDay = focusValue || draft.start || today;
      if (monthIndex(tabDay) < viewMonth || monthIndex(tabDay) >= viewMonth + monthCount()) {
        tabDay = iso(monthDate(viewMonth));
      }
      tabDay = tabDay < today ? today : tabDay;
      const fragment = document.createDocumentFragment();
      for (let index = 0; index < monthCount(); index++) {
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
      }
      else {
        panel.querySelector('[aria-pressed="true"]').focus({ preventScroll: true });
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
        closePanel(true);
      }
      else if (button.hasAttribute('data-companion-value')) {
        companion.value = button.dataset.companionValue;
        companion.dispatchEvent(new Event('change', { bubbles: true }));
        closePanel(true);
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
        closePanel(true);
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
    const onFocusOut = () => {
      window.clearTimeout(focusTimer);
      // Native focus transitions finish after the focusout handlers return.
      focusTimer = window.setTimeout(() => {
        if (activePanel && !activePanel.contains(document.activeElement) && !activeTrigger.contains(document.activeElement)) {
          closePanel();
        }
      }, 0);
    };
    const onViewportChange = () => closePanel();
    const onPageShow = () => {
      updateLabels();
      form.dispatchEvent(new Event('change', { bubbles: true }));
    };

    form.addEventListener('click', onClick);
    form.addEventListener('keydown', onKeydown);
    form.addEventListener('change', updateLabels, true);
    form.addEventListener('focusout', onFocusOut);
    months.addEventListener('pointerover', onPointerOver);
    months.addEventListener('pointerleave', clearPreview);
    document.addEventListener('click', onOutsideClick);
    window.addEventListener('scroll', onViewportChange, { passive: true });
    window.addEventListener('resize', onViewportChange);
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
      form.removeEventListener('focusout', onFocusOut);
      months.removeEventListener('pointerover', onPointerOver);
      months.removeEventListener('pointerleave', clearPreview);
      document.removeEventListener('click', onOutsideClick);
      window.removeEventListener('scroll', onViewportChange);
      window.removeEventListener('resize', onViewportChange);
      window.removeEventListener('pageshow', onPageShow);
      window.clearTimeout(focusTimer);
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
})(Drupal, once);
