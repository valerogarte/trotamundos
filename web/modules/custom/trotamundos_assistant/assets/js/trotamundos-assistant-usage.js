(function (Drupal, once) {
  'use strict';

  let activeTrigger = null;

  function createElement(tag, className, text) {
    const element = document.createElement(tag);

    if (className) {
      element.className = className;
    }

    if (typeof text === 'string') {
      element.textContent = text;
    }

    return element;
  }

  function getTypeLabel(value) {
    if (Array.isArray(value)) {
      return Drupal.formatPlural(value.length, 'array · 1 item', 'array · @count items');
    }

    if (value && typeof value === 'object') {
      return Drupal.formatPlural(Object.keys(value).length, 'object · 1 key', 'object · @count keys');
    }

    if (value === null) {
      return 'null';
    }

    return typeof value;
  }

  function formatPrimitive(value) {
    if (typeof value === 'string') {
      return `"${value}"`;
    }

    if (value === null) {
      return 'null';
    }

    return String(value);
  }

  function renderJsonNode(value, key = '', depth = 0) {
    const row = createElement('div', 'trotamundos-assistant-json__node');
    const keyLabel = key === '' ? '' : `${key}: `;

    if (!value || typeof value !== 'object') {
      const primitive = createElement('div', 'trotamundos-assistant-json__primitive');
      const keyElement = createElement('span', 'trotamundos-assistant-json__key', keyLabel);
      const valueElement = createElement(
        'span',
        `trotamundos-assistant-json__value trotamundos-assistant-json__value--${value === null ? 'null' : typeof value}`,
        formatPrimitive(value),
      );

      primitive.appendChild(keyElement);
      primitive.appendChild(valueElement);
      row.appendChild(primitive);

      return row;
    }

    const details = createElement('details', 'trotamundos-assistant-json__details');
    details.open = depth < 2;

    const summary = createElement('summary', 'trotamundos-assistant-json__summary');
    summary.appendChild(createElement('span', 'trotamundos-assistant-json__key', keyLabel || Drupal.t('JSON')));
    summary.appendChild(createElement('span', 'trotamundos-assistant-json__meta', getTypeLabel(value)));
    details.appendChild(summary);

    const children = createElement('div', 'trotamundos-assistant-json__children');
    Object.entries(value).forEach(([childKey, childValue]) => {
      children.appendChild(renderJsonNode(childValue, childKey, depth + 1));
    });

    details.appendChild(children);
    row.appendChild(details);

    return row;
  }

  function buildRawPre(text) {
    const pre = createElement('pre', 'trotamundos-assistant-usage__modal-pre');
    pre.textContent = text;

    return pre;
  }

  function buildModal() {
    const modal = createElement('div', 'trotamundos-assistant-usage__modal');
    modal.hidden = true;
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'trotamundos-assistant-usage-modal-title');

    const backdrop = createElement('div', 'trotamundos-assistant-usage__modal-backdrop');
    backdrop.setAttribute('data-trotamundos-assistant-usage-close', '');

    const dialog = createElement('div', 'trotamundos-assistant-usage__modal-dialog');
    const header = createElement('div', 'trotamundos-assistant-usage__modal-header');
    const title = createElement('h2', 'trotamundos-assistant-usage__modal-title', Drupal.t('JSON bruto'));
    title.id = 'trotamundos-assistant-usage-modal-title';

    const close = createElement('button', 'trotamundos-assistant-usage__modal-close', Drupal.t('Cerrar'));
    close.type = 'button';
    close.setAttribute('data-trotamundos-assistant-usage-close', '');

    const body = createElement('div', 'trotamundos-assistant-usage__modal-body');

    header.appendChild(title);
    header.appendChild(close);
    dialog.appendChild(header);
    dialog.appendChild(body);
    modal.appendChild(backdrop);
    modal.appendChild(dialog);
    document.body.appendChild(modal);

    modal.addEventListener('click', (event) => {
      if (event.target instanceof Element && event.target.hasAttribute('data-trotamundos-assistant-usage-close')) {
        closeModal(modal);
      }
    });

    return modal;
  }

  function getModal() {
    return document.querySelector('.trotamundos-assistant-usage__modal') || buildModal();
  }

  function openModal(trigger, rawText) {
    const modal = getModal();
    const title = modal.querySelector('.trotamundos-assistant-usage__modal-title');
    const body = modal.querySelector('.trotamundos-assistant-usage__modal-body');
    activeTrigger = trigger;

    if (!body) {
      return;
    }

    if (title) {
      title.textContent = trigger.getAttribute('data-trotamundos-assistant-modal-title') || Drupal.t('JSON bruto');
    }

    body.innerHTML = '';

    try {
      const parsed = JSON.parse(rawText);
      const tree = createElement('div', 'trotamundos-assistant-json');
      tree.appendChild(renderJsonNode(parsed));
      body.appendChild(tree);
    }
    catch (error) {
      body.appendChild(buildRawPre(rawText));
    }

    modal.hidden = false;
    document.documentElement.classList.add('trotamundos-assistant-usage-modal-open');

    const close = modal.querySelector('.trotamundos-assistant-usage__modal-close');
    if (close) {
      close.focus();
    }
  }

  function closeModal(modal = getModal()) {
    modal.hidden = true;
    document.documentElement.classList.remove('trotamundos-assistant-usage-modal-open');

    if (activeTrigger) {
      activeTrigger.focus();
      activeTrigger = null;
    }
  }

  function getChartTooltip() {
    let tooltip = document.querySelector('.trotamundos-assistant-usage-chart__tooltip');

    if (!tooltip) {
      tooltip = createElement('div', 'trotamundos-assistant-usage-chart__tooltip');
      tooltip.hidden = true;
      document.body.appendChild(tooltip);
    }

    return tooltip;
  }

  function positionChartTooltip(tooltip, x, y) {
    const margin = 14;
    const rect = tooltip.getBoundingClientRect();
    const left = Math.min(x + margin, window.innerWidth - rect.width - margin);
    const top = Math.max(margin, y - rect.height - margin);

    tooltip.style.left = `${Math.max(margin, left)}px`;
    tooltip.style.top = `${top}px`;
  }

  function showChartTooltip(trigger, event = null) {
    const tooltip = getChartTooltip();
    const text = trigger.getAttribute('data-trotamundos-assistant-chart-tooltip');

    if (!text) {
      return;
    }

    tooltip.textContent = text;
    tooltip.hidden = false;

    if (event) {
      positionChartTooltip(tooltip, event.clientX, event.clientY);
      return;
    }

    const rect = trigger.getBoundingClientRect();
    positionChartTooltip(tooltip, rect.left + (rect.width / 2), rect.top);
  }

  function hideChartTooltip() {
    const tooltip = document.querySelector('.trotamundos-assistant-usage-chart__tooltip');

    if (tooltip) {
      tooltip.hidden = true;
    }
  }

  Drupal.behaviors.trotamundosAssistantUsageModal = {
    attach(context) {
      once('trotamundos-assistant-usage-modal', '[data-trotamundos-assistant-usage-modal]', context).forEach((trigger) => {
        trigger.addEventListener('click', () => {
          const payload = document.getElementById(trigger.getAttribute('data-trotamundos-assistant-usage-modal'));
          if (!payload) {
            return;
          }

          openModal(trigger, payload.value || payload.textContent || '');
        });
      });

      once('trotamundos-assistant-usage-modal-escape', 'html', context).forEach(() => {
        document.addEventListener('keydown', (event) => {
          const modal = document.querySelector('.trotamundos-assistant-usage__modal');
          if (event.key === 'Escape' && modal && !modal.hidden) {
            closeModal(modal);
          }
        });
      });
    },
  };

  Drupal.behaviors.trotamundosAssistantUsageChartTooltip = {
    attach(context) {
      once('trotamundos-assistant-usage-chart-tooltip', '[data-trotamundos-assistant-chart-tooltip]', context).forEach((trigger) => {
        trigger.addEventListener('mouseenter', (event) => showChartTooltip(trigger, event));
        trigger.addEventListener('mousemove', (event) => showChartTooltip(trigger, event));
        trigger.addEventListener('mouseleave', hideChartTooltip);
        trigger.addEventListener('focus', () => showChartTooltip(trigger));
        trigger.addEventListener('blur', hideChartTooltip);
      });
    },
  };
})(Drupal, once);
