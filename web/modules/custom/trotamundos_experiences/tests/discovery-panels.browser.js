/**
 * Browser regression checks for the shared search, run on a disposable /planes tab.
 */
function auroraAlignedToViewport(discovery) {
  const bounds = discovery.getBoundingClientRect();
  const before = getComputedStyle(discovery, '::before');
  const after = getComputedStyle(discovery, '::after');
  return Math.abs(bounds.left + parseFloat(before.left)) < 20
    && Math.abs(bounds.right - parseFloat(after.right) - innerWidth) < 20;
}

function assistantClipBounds(panel) {
  const match = getComputedStyle(panel).clipPath.match(/^inset\(([^)]+?) round/);
  if (!match) {
    return null;
  }
  const values = match[1].split(' ').map(parseFloat);
  const [top, right = top, bottom = top, left = right] = values;
  const bounds = panel.getBoundingClientRect();
  return {
    top: bounds.top + top,
    right: bounds.right - right,
    bottom: bounds.bottom - bottom,
    left: bounds.left + left,
  };
}

function assistantBoundsMatch(actual, expected) {
  return actual && ['top', 'right', 'bottom', 'left'].every((side) => Math.abs(actual[side] - expected[side]) < 1);
}

function finishAssistantAnimation(animation) {
  animation.finish();
  // Hidden browser tabs may not dispatch animation events until the next render.
  if (document.visibilityState === 'hidden') {
    animation.dispatchEvent(new Event('finish'));
  }
}

export async function runDiscoveryPanelTests() {
  const assert = (condition, message) => {
    if (!condition) {
      throw new Error(message);
    }
  };
  const pause = (milliseconds = 40) => new Promise((resolve) => setTimeout(resolve, milliseconds));
  const until = async (condition, message) => {
    for (let attempt = 0; attempt < 160; attempt++) {
      if (condition()) {
        return;
      }
      await pause(50);
    }
    throw new Error(message);
  };
  const header = document.querySelector('[data-site-header]');
  const form = document.querySelector('#discovery-form');
  const filters = document.querySelector('[data-catalog-filters]');
  const assistant = document.querySelector('[data-discovery-assistant]');
  assert(header && form && filters && assistant, 'Open a catalog page with the shared search before running this test.');
  const discovery = assistant.closest('[data-header-discovery]');

  const settleLayout = () => {
    const finish = () => header.getAnimations({ subtree: true })
      .filter((animation) => Number.isFinite(animation.effect?.getComputedTiming().endTime))
      .forEach((animation) => animation.finish());
    finish();
    header.dispatchEvent(new Event('trotamundosHeaderLayoutChanged'));
    finish();
  };
  const expandSearch = () => {
    if (matchMedia('(max-width: 639px)').matches && !header.classList.contains('search-is-open')) {
      header.querySelector('[data-discovery-toggle]').click();
    }
  };
  const panels = [
    { name: 'dates', panel: form.querySelector('#discovery-calendar'), trigger: form.querySelector('[data-discovery-open="dates"]'), close: '[data-discovery-close]' },
    { name: 'destination', panel: form.querySelector('#discovery-destinations'), trigger: form.querySelector('[data-discovery-open="destination"]'), close: '[data-discovery-close]' },
    { name: 'companions', panel: form.querySelector('#discovery-companions'), trigger: form.querySelector('[data-discovery-open="companions"]'), close: '[data-discovery-close]' },
    { name: 'filters', panel: filters, trigger: filters.closest('details').querySelector('summary'), close: '[data-discovery-control-close]', disclosure: filters.closest('details') },
    { name: 'assistant', panel: assistant.querySelector('.discovery-assistant__panel'), trigger: assistant.querySelector('summary'), close: '.trotamundos-assistant__close', disclosure: assistant },
  ];
  const isOpen = (item) => item.disclosure ? item.disclosure.open : !item.panel.hidden;
  const auroraStyles = () => ['::before', '::after'].map((side) => getComputedStyle(discovery, side));
  const checkAurora = async (open) => {
    await until(() => auroraStyles().every((style) => open ? Number(style.opacity) > 0.95 : Number(style.opacity) < 0.05),
      `The assistant's side gradients did not ${open ? 'appear' : 'disappear'}.`);
    if (open) {
      const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
      const bounds = discovery.getBoundingClientRect();
      const styles = auroraStyles();
      assert(styles.every((style) => {
        const transform = style.transform === 'none' ? new DOMMatrixReadOnly() : new DOMMatrixReadOnly(style.transform);
        const top = bounds.top + parseFloat(style.top) + transform.m42;
        return style.position === 'absolute' && style.pointerEvents === 'none'
          && top <= 0 && top + parseFloat(style.height) >= innerHeight;
      }), 'The assistant gradients must cover the header and page without blocking interaction.');
      assert(auroraAlignedToViewport(discovery), 'The assistant gradients must stay aligned to both viewport edges.');
      assert(styles.every((style) => Number(style.zIndex) < Number(getComputedStyle(assistant.querySelector('.discovery-assistant__panel')).zIndex)),
        'The assistant panel must stay above the gradients.');
      assert(auroraStyles().every((style) => reducedMotion ? style.animationName === 'none'
        : style.animationName !== 'none' && style.animationPlayState === 'running'),
        'The assistant side gradients must respect the motion preference.');
      if (!reducedMotion) {
        const transforms = auroraStyles().map((style) => style.transform);
        await pause(300);
        assert(auroraStyles().every((style, index) => style.transform !== transforms[index]),
          'Both assistant side gradients must move.');
      }
    }
  };
  const open = async (item) => {
    expandSearch();
    if (!isOpen(item)) {
      item.trigger.click();
      await until(() => isOpen(item), `${item.name} did not open.`);
      await pause();
      settleLayout();
    }
  };
  const closed = (item) => until(() => !isOpen(item), `${item.name} did not close.`);

  for (const item of panels) {
    await open(item);
    if (item.name === 'assistant') {
      const input = item.panel.querySelector('.trotamundos-assistant__input');
      await until(() => !input.disabled && document.activeElement === input, 'Opening the assistant must focus its input.');
      await checkAurora(true);
    }
    else if (item.name === 'filters') {
      await checkAurora(false);
    }
    const closeButton = item.panel.querySelector(item.close);
    assert(closeButton, `${item.name} has no close button.`);
    closeButton.focus({ preventScroll: true });
    assert(document.activeElement === closeButton, `${item.name} could not receive focus.`);
    closeButton.blur();
    item.panel.click();
    await pause();
    assert(isOpen(item), `${item.name} closed after a click inside its background.`);
    if (matchMedia('(max-width: 639px)').matches) {
      assert(header.classList.contains('search-is-open'), `${item.name} collapsed the mobile search after an inside click.`);
    }
    const scrollTarget = item.name === 'assistant' ? item.panel.querySelector('.trotamundos-assistant__chat') : item.panel;
    scrollTarget.scrollTop = Math.min(80, scrollTarget.scrollHeight - scrollTarget.clientHeight);
    const panelScroll = scrollTarget.scrollTop;
    window.dispatchEvent(new Event('scroll'));
    window.dispatchEvent(new Event('resize'));
    await pause();
    settleLayout();
    assert(isOpen(item), `${item.name} closed on scroll or resize.`);
    assert(scrollTarget.scrollTop === panelScroll, `${item.name} lost its scroll position.`);
    const panelBounds = item.panel.getBoundingClientRect();
    const closeBounds = closeButton.getBoundingClientRect();
    assert(closeBounds.top >= Math.max(0, panelBounds.top) - 1
      && closeBounds.bottom <= Math.min(innerHeight, panelBounds.bottom) + 1, `${item.name} hid its close button while scrolling.`);

    if (item.name === 'filters') {
      let resizeError = '';
      const onError = (event) => {
        resizeError = event.message;
        event.preventDefault();
      };
      const originalDisplay = item.disclosure.style.display;
      window.addEventListener('error', onError);
      try {
        item.disclosure.style.display = 'none';
        window.dispatchEvent(new Event('resize'));
      }
      finally {
        item.disclosure.style.display = originalDisplay;
        window.removeEventListener('error', onError);
      }
      assert(!resizeError && isOpen(item), `A temporarily hidden filter panel failed to resize: ${resizeError}`);
    }

    closeButton.click();
    await closed(item);
    if (item.name === 'assistant') {
      await checkAurora(false);
    }
    await open(item);
    document.body.click();
    await closed(item);
    if (item.name === 'assistant') {
      await checkAurora(false);
    }
    await open(item);
    item.panel.querySelector(item.close).focus({ preventScroll: true });
    const outsideLink = document.querySelector('footer a[href]');
    outsideLink.focus({ preventScroll: true });
    await pause();
    assert(isOpen(item), `${item.name} closed when keyboard focus moved outside.`);
    outsideLink.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    await closed(item);
    if (item.name === 'assistant') {
      await checkAurora(false);
    }
  }

  const [dates, destination, companions, filterPanel] = panels;
  if (matchMedia('(max-width: 639px) and (min-height: 760px)').matches && typeof ResizeObserver !== 'undefined') {
    await open(filterPanel);
    await pause(100);
    const originalMargin = form.style.marginBottom;
    const originalTriggerBottom = filterPanel.trigger.getBoundingClientRect().bottom;
    try {
      form.style.marginBottom = '48px';
      await until(() => Math.abs(filterPanel.trigger.getBoundingClientRect().bottom - originalTriggerBottom) > 20, 'The search layout did not move for the test.');
      await until(() => Math.abs(filterPanel.panel.getBoundingClientRect().top
        - filterPanel.trigger.getBoundingClientRect().bottom - 8) < 1, 'The open filter panel did not follow a search layout change.');
      assert(isOpen(filterPanel), 'Changing the search layout must not close the filter panel.');
    }
    finally {
      form.style.marginBottom = originalMargin;
    }
    filterPanel.panel.querySelector(filterPanel.close).click();
    await closed(filterPanel);
  }

  await open(destination);
  const destinationChoice = destination.panel.querySelector('[data-destination-value]:not([data-destination-value=""])');
  assert(destinationChoice, 'The destination test needs a published destination.');
  destinationChoice.click();
  assert(isOpen(destination) && form.querySelector('#discovery-destino').value === destinationChoice.dataset.destinationValue, 'Selecting a destination must update the search without closing its panel.');
  destination.panel.querySelector(destination.close).click();

  await open(companions);
  const companionChoice = companions.panel.querySelector('[data-companion-value="pareja"]');
  companionChoice.click();
  assert(isOpen(companions) && form.querySelector('#discovery-acompanamiento').value === 'pareja', 'Selecting a companion must update the search without closing its panel.');
  companions.panel.querySelector(companions.close).click();

  await open(dates);
  const availableDays = Array.from(dates.panel.querySelectorAll('[data-calendar-day]:not(:disabled)'));
  assert(availableDays.length >= 2, 'The calendar test needs two available days.');
  const [first, second] = availableDays.map((day) => day.dataset.calendarDay);
  availableDays[0].click();
  dates.panel.querySelector(`[data-calendar-day="${second}"]`).click();
  dates.panel.querySelector('[data-calendar-margin="3"]').click();
  dates.panel.querySelector('[data-calendar-apply]').click();
  assert(isOpen(dates)
    && form.querySelector('#discovery-desde').value === first
    && form.querySelector('#discovery-hasta').value === second
    && form.querySelector('#discovery-margen').value === '3', 'Applying dates must save the range without closing the calendar.');
  dates.panel.querySelector(dates.close).click();

  await open(filterPanel);
  const style = Array.from(filters.querySelectorAll('input[name="estilo"]')).find((input) => input.value && !input.checked);
  assert(style, 'The catalog test needs an unselected travel style.');
  const styleValue = style.value;
  style.focus({ preventScroll: true });
  style.click();
  await until(() => new URL(location.href).searchParams.get('estilo') === styleValue
    && !document.querySelector('[data-catalog]').classList.contains('is-loading'), 'The asynchronous style filter did not finish.');
  assert(isOpen(filterPanel) && filters.querySelector(`input[name="estilo"][value="${styleValue}"]`).checked, 'The asynchronous filter update must retain its selection and open panel.');

  const minimum = filters.querySelector('[data-price-input="min"]');
  minimum.value = '1000';
  minimum.dispatchEvent(new Event('input', { bubbles: true }));
  filters.querySelector('[data-catalog-submit]').click();
  await until(() => new URL(location.href).searchParams.get('precio_min') === '1000'
    && !document.querySelector('[data-catalog]').classList.contains('is-loading'), 'The asynchronous price filter did not finish.');
  assert(isOpen(filterPanel), 'Applying catalog filters must not close their panel.');
  filters.querySelector(filterPanel.close).click();

  const assistantPanel = panels[4];
  const chat = assistant.querySelector('.trotamundos-assistant--search');
  const input = chat.querySelector('textarea');
  const settings = window.drupalSettings.trotamundosAssistant;
  const originalFetch = window.fetch;
  const originalToken = settings.csrfToken;
  let answer;
  settings.csrfToken = 'panel-test-token';
  window.fetch = (resource, options) => {
    if (new URL(resource, location.href).href === new URL(settings.endpoint, location.href).href) {
      return new Promise((resolve) => {
        answer = () => resolve(new Response(JSON.stringify({ reply: 'Respuesta simulada', thread_id: '' }), {
          headers: { 'Content-Type': 'application/json' },
        }));
      });
    }
    return originalFetch.call(window, resource, options);
  };
  try {
    await open(assistantPanel);
    assert(!input.disabled, 'The assistant input is unavailable.');
    input.value = 'Una consulta de prueba';
    input.focus({ preventScroll: true });
    input.form.requestSubmit();
    await until(() => answer && chat.classList.contains('is-loading'), 'The simulated assistant request did not start.');
    input.blur();
    assistantPanel.panel.click();
    await pause();
    assert(isOpen(assistantPanel) && chat.classList.contains('is-loading'), 'The assistant closed during a click while loading.');
    answer();
    await until(() => !chat.classList.contains('is-loading')
      && chat.querySelector('.trotamundos-assistant__messages').textContent.includes('Respuesta simulada'), 'The simulated assistant response did not arrive.');
    assert(isOpen(assistantPanel), 'The assistant closed after its response.');
    assistantPanel.panel.querySelector(assistantPanel.close).click();
    await closed(assistantPanel);
  }
  finally {
    answer?.();
    window.fetch = originalFetch;
    if (originalToken === undefined) {
      delete settings.csrfToken;
    }
    else {
      settings.csrfToken = originalToken;
    }
  }

  return 'Search panel checks passed.';
}

/**
 * Browser regression checks for the home assistant at the current viewport.
 */
export async function runHomeAssistantTests() {
  const assert = (condition, message) => {
    if (!condition) {
      throw new Error(message);
    }
  };
  const until = async (condition, message) => {
    for (let attempt = 0; attempt < 160; attempt++) {
      if (condition()) {
        return;
      }
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
    throw new Error(message);
  };
  const near = (a, b) => Math.abs(a - b) < 1;
  const header = document.querySelector('[data-site-header]');
  const search = document.querySelector('.discovery-bar');
  const assistant = document.querySelector('[data-discovery-assistant]');
  assert(header?.classList.contains('site-header--overlay') && search && assistant,
    'Open the home page with the shared search before running this test.');
  assert(!header.classList.contains('header-is-scrolled') && !assistant.open, 'Start at the top of the home page with the assistant closed.');
  const hero = document.querySelector('.immersive-hero');
  assert(hero && Math.abs(hero.getBoundingClientRect().top - header.getBoundingClientRect().top) < 1,
    'The unscrolled home header must overlay the hero at every screen size.');
  const fadesToTransparent = (gradient) => /(?:rgba\([^)]*,\s*0\)|transparent)(?:\s+100%)?\s*\)$/.test(gradient);
  const background = getComputedStyle(header).backgroundImage;
  assert(background.startsWith('linear-gradient(') && fadesToTransparent(background),
    'The home header background must fade to transparent.');
  const supportsBlur = CSS.supports('backdrop-filter', 'blur(1px)') || CSS.supports('-webkit-backdrop-filter', 'blur(1px)');
  const supportsMask = CSS.supports('mask-image', 'linear-gradient(#000, transparent)')
    || CSS.supports('-webkit-mask-image', 'linear-gradient(#000, transparent)');
  if (supportsBlur && supportsMask) {
    const layer = getComputedStyle(header, '::after');
    const blurred = ['backdrop-filter', '-webkit-backdrop-filter'].some((property) => layer.getPropertyValue(property).includes('blur('));
    const masked = ['mask-image', '-webkit-mask-image'].some((property) => {
      const mask = layer.getPropertyValue(property);
      return mask.startsWith('linear-gradient(') && fadesToTransparent(mask);
    });
    assert(blurred && masked && layer.pointerEvents === 'none', 'The home header blur must fade without blocking controls.');
  }
  const trigger = assistant.querySelector('summary');
  const discovery = assistant.closest('[data-header-discovery]');
  const label = trigger.querySelector('span');
  const panel = assistant.querySelector('.discovery-assistant__panel');
  const input = panel.querySelector('.trotamundos-assistant__input');
  const composer = input.closest('.trotamundos-assistant__composer');
  const title = panel.querySelector('.trotamundos-assistant__search-title');
  const close = panel.querySelector('.trotamundos-assistant__close');
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const forcedColors = matchMedia('(forced-colors: active)').matches;
  const finishPanelAnimations = () => panel.getAnimations({ subtree: true })
    .filter((animation) => Number.isFinite(animation.effect?.getComputedTiming().endTime))
    .forEach(finishAssistantAnimation);
  await until(() => !input.disabled, 'The assistant input is unavailable.');
  const unfocusedBorder = getComputedStyle(composer).border;
  const unfocusedBackground = getComputedStyle(composer).backgroundColor;

  const searchBounds = search.getBoundingClientRect();
  const triggerBounds = trigger.getBoundingClientRect();
  const desktop = matchMedia('(min-width: 1024px)').matches;
  if (desktop) {
    const headerBounds = header.getBoundingClientRect();
    const side = parseFloat(getComputedStyle(header.querySelector('.site-header__inner')).paddingLeft);
    const originalLeft = headerBounds.left + (headerBounds.width - 850) / 2;
    const availableWidth = headerBounds.right - side - 16 - triggerBounds.width - originalLeft;
    assert(near(searchBounds.left, originalLeft), 'The home search must retain its original left edge.');
    assert(near(searchBounds.width, Math.min(850, availableWidth)), 'The home search must shrink only when the assistant needs room.');
    assert(near(triggerBounds.left - searchBounds.right, 16)
      && near(triggerBounds.top + triggerBounds.height / 2, searchBounds.top + searchBounds.height / 2),
    'The circular assistant must sit beside and vertically centered on the home search.');
    assert(near(triggerBounds.width, triggerBounds.height) && label.getBoundingClientRect().width <= 1,
      'The home assistant must be an icon-only circular control with its accessible label retained.');
    assert(triggerBounds.right <= headerBounds.right - side + 1, 'The assistant must remain inside the header.');
    for (const control of search.querySelectorAll('.discovery-trigger, .discovery-bar__submit')) {
      const bounds = control.getBoundingClientRect();
      const hit = document.elementFromPoint(bounds.left + bounds.width / 2, bounds.top + bounds.height / 2);
      assert(control.contains(hit), 'The assistant must not block a search control.');
    }
  }
  else if (matchMedia('(min-width: 640px)').matches) {
    assert(triggerBounds.top >= searchBounds.bottom && label.getBoundingClientRect().width > 1,
      'The tablet home assistant must retain its existing position and label.');
  }
  else {
    const summaryBounds = header.querySelector('.discovery-summary').getBoundingClientRect();
    assert(near(triggerBounds.width, 44) && near(triggerBounds.left - summaryBounds.right, 8)
      && near(triggerBounds.top + triggerBounds.height / 2, summaryBounds.top + summaryBounds.height / 2),
    'The mobile assistant must retain its existing position beside the search summary.');
  }

  trigger.click();
  assert(getComputedStyle(panel).visibility === 'hidden', 'The assistant panel must stay hidden until its opening shape is ready.');
  assert(getComputedStyle(trigger).visibility === 'visible' && Number(getComputedStyle(trigger).opacity) > 0.99,
    'The assistant button must remain visible until the opening shape is ready.');
  await until(() => assistant.open && document.activeElement === input, 'Opening the home assistant must focus its input.');
  assert(auroraAlignedToViewport(discovery), 'The home assistant gradients must stay aligned to both viewport edges.');
  const composerStyle = getComputedStyle(composer);
  assert(composerStyle.outlineStyle === 'none' && composerStyle.border === unfocusedBorder,
    'Focusing the assistant input must not add or change its border.');
  if (!forcedColors && document.hasFocus()) {
    assert(getComputedStyle(input).outlineStyle === 'none' && composerStyle.backgroundColor !== unfocusedBackground,
      'The input focus should be indicated without an outline.');
  }
  if (!reducedMotion) {
    const shapeMotion = panel.getAnimations().find((animation) => animation.effect?.target === panel);
    const titleMotion = title.getAnimations().find((animation) => animation.effect?.target === title);
    assert(shapeMotion && titleMotion, 'The assistant must animate its panel and content.');
    shapeMotion.pause();
    titleMotion.pause();
    const duration = shapeMotion.effect.getTiming().duration;
    shapeMotion.currentTime = 0;
    assert(assistantBoundsMatch(assistantClipBounds(panel), triggerBounds),
      'The home assistant must begin at the original button without changing size or position.');
    shapeMotion.currentTime = duration / 4;
    titleMotion.currentTime = duration / 4;
    assert(Number(getComputedStyle(title).opacity) < 0.05, 'Text should not appear clipped during the panel expansion.');
    shapeMotion.currentTime = duration * 0.85;
    titleMotion.currentTime = duration * 0.85;
    const clip = getComputedStyle(panel).clipPath.match(/^inset\(([^)]+?) round/);
    const values = clip && clip[1].split(' ').map(parseFloat);
    const insets = values && [
      values[0],
      values[1] ?? values[0],
      values[2] ?? values[0],
      values[3] ?? values[1] ?? values[0],
    ];
    const panelBounds = panel.getBoundingClientRect();
    const titleBounds = title.getBoundingClientRect();
    assert(insets?.length === 4 && Number(getComputedStyle(title).opacity) > 0.3
      && titleBounds.left >= panelBounds.left + insets[3] - 1
      && titleBounds.right <= panelBounds.right - insets[1] + 1,
    'The assistant title must be revealed only after it fits inside the opening panel.');
    finishPanelAnimations();
  }
  else {
    assert(!panel.getAnimations({ subtree: true }).some((animation) => animation.effect?.target === panel),
      'Reduced motion must open the assistant without a panel animation.');
  }
  if (desktop) {
    const panelBounds = panel.getBoundingClientRect();
    const closeBounds = close.getBoundingClientRect();
    assert(near(panelBounds.left, searchBounds.left) && near(panelBounds.top, searchBounds.top)
      && near(panelBounds.right, triggerBounds.right), 'The home assistant panel must cover the search and reach its trigger.');
    assert(near(closeBounds.left + closeBounds.width / 2, triggerBounds.left + triggerBounds.width / 2)
      && near(closeBounds.top + closeBounds.height / 2, triggerBounds.top + triggerBounds.height / 2),
    'The home assistant close button must align with its trigger.');
  }
  close.click();
  if (!reducedMotion) {
    const shapeMotion = panel.getAnimations().find((animation) => animation.effect?.target === panel);
    const titleMotion = title.getAnimations().find((animation) => animation.effect?.target === title);
    assert(shapeMotion && titleMotion, 'Closing the assistant must animate its panel and content.');
    shapeMotion.pause();
    titleMotion.pause();
    const halfway = shapeMotion.effect.getTiming().duration / 2;
    shapeMotion.currentTime = halfway;
    titleMotion.currentTime = halfway;
    assert(Number(getComputedStyle(title).opacity) < 0.05 && getComputedStyle(panel).clipPath !== 'none',
      'Content should fade before the panel folds away.');
    finishAssistantAnimation(shapeMotion);
  }
  await until(() => !assistant.open && document.activeElement === trigger, 'Closing the assistant must restore focus to its trigger.');

  input.disabled = true;
  try {
    trigger.click();
    await until(() => assistant.open && document.activeElement === close, 'The close button must receive focus when the input is unavailable.');
    close.click();
    finishPanelAnimations();
    await until(() => !assistant.open, 'The unavailable assistant must still close.');
  }
  finally {
    input.disabled = false;
  }

  if (!reducedMotion) {
    trigger.click();
    await until(() => assistant.open && document.activeElement === input, 'The assistant must reopen.');
    finishPanelAnimations();
    close.click();
    const closingShape = panel.getAnimations().find((animation) => animation.effect?.target === panel);
    const closingTitle = title.getAnimations().find((animation) => animation.effect?.target === title);
    assert(closingShape && closingTitle, 'The closing transition must be interruptible.');
    closingShape.pause();
    closingTitle.pause();
    closingShape.currentTime = closingShape.effect.getTiming().duration * 0.55;
    closingTitle.currentTime = closingShape.currentTime;
    const interruptedClip = getComputedStyle(panel).clipPath;
    assistant.dispatchEvent(new CustomEvent('trotamundosAssistantOpen', { bubbles: true }));
    input.focus({ preventScroll: true });
    const reopeningShape = panel.getAnimations().find((animation) => animation.effect?.target === panel);
    const reopeningTitle = title.getAnimations().find((animation) => animation.effect?.target === title);
    assert(reopeningShape && reopeningTitle && getComputedStyle(panel).clipPath === interruptedClip,
      'Reopening during the closing transition must continue from its current shape.');
    reopeningShape.pause();
    reopeningTitle.pause();
    reopeningShape.currentTime = reopeningShape.effect.getTiming().duration / 4;
    reopeningTitle.currentTime = reopeningShape.currentTime;
    assert(Number(getComputedStyle(title).opacity) < 0.05, 'Reopening must not reveal clipped text.');
    finishPanelAnimations();
    assert(assistant.open && !assistant.closest('.discovery-controls').classList.contains('is-assistant-closing'),
      'Reopening must leave the assistant usable.');
    close.click();
    finishPanelAnimations();
    await until(() => !assistant.open, 'The reopened assistant must still close.');

    trigger.click();
    await until(() => assistant.open && document.activeElement === input, 'The assistant must open before resizing.');
    window.dispatchEvent(new Event('resize'));
    assert(getComputedStyle(title).opacity === '1' && getComputedStyle(composer).opacity === '1'
      && document.activeElement === input, 'Resizing during the opening must reveal the content and retain focus.');
    close.click();
    finishPanelAnimations();
    await until(() => !assistant.open, 'The assistant must still close after resizing.');
  }

  return 'Home assistant checks passed.';
}

/**
 * Check the assistant's movement from the compact header to its panel.
 */
export async function runScrolledAssistantMotionTests() {
  const assert = (condition, message) => {
    if (!condition) {
      throw new Error(message);
    }
  };
  const until = async (condition, message) => {
    for (let attempt = 0; attempt < 160; attempt++) {
      if (condition()) {
        return;
      }
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
    throw new Error(message);
  };
  const header = document.querySelector('[data-site-header]');
  const assistant = document.querySelector('[data-discovery-assistant]');
  assert(header?.classList.contains('header-is-scrolled') && assistant && !assistant.open,
    'Start with the compact header and the assistant closed.');
  const trigger = assistant.querySelector('summary');
  const panel = assistant.querySelector('.discovery-assistant__panel');
  const input = panel.querySelector('.trotamundos-assistant__input');
  const close = panel.querySelector('.trotamundos-assistant__close');
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
  await until(() => !input.disabled, 'The assistant input is unavailable.');
  const triggerBounds = trigger.getBoundingClientRect();
  trigger.click();
  assert(getComputedStyle(panel).visibility === 'hidden', 'The assistant panel must not flash before its opening animation.');
  assert(getComputedStyle(trigger).visibility === 'visible' && Number(getComputedStyle(trigger).opacity) > 0.99,
    'The compact assistant button must remain visible until the opening shape is ready.');
  await until(() => assistant.open && document.activeElement === input, 'Opening the assistant must focus its input.');

  if (!reducedMotion) {
    const opening = panel.getAnimations().find((animation) => animation.effect?.target === panel);
    assert(opening, 'The compact assistant must animate open.');
    opening.pause();
    opening.currentTime = 0;
    assert(assistantBoundsMatch(assistantClipBounds(panel), triggerBounds),
      'The compact assistant must begin at the button, even when the panel ends below or beside it.');
    const initial = new DOMMatrixReadOnly(getComputedStyle(panel).transform);
    if (Math.abs(initial.m41) > 1 || Math.abs(initial.m42) > 1) {
      const buttonMotion = trigger.getAnimations().find((animation) => animation.effect?.getKeyframes().some((frame) => frame.transform));
      assert(buttonMotion, 'The assistant icon must travel with the opening shape.');
      buttonMotion.pause();
      buttonMotion.currentTime = buttonMotion.effect.getTiming().duration / 4;
      const button = new DOMMatrixReadOnly(getComputedStyle(trigger).transform);
      assert((Math.abs(initial.m41) <= 1 || button.m41 * initial.m41 < -1)
        && (Math.abs(initial.m42) <= 1 || button.m42 * initial.m42 < -1),
      'The assistant icon must move toward the panel.');
      opening.currentTime = opening.effect.getTiming().duration * 0.4;
      const middle = new DOMMatrixReadOnly(getComputedStyle(panel).transform);
      assert((Math.abs(initial.m41) <= 1 || Math.abs(middle.m41) < Math.abs(initial.m41) - 1)
        && (Math.abs(initial.m42) <= 1 || Math.abs(middle.m42) < Math.abs(initial.m42) - 1),
      'The assistant panel must move toward its final position while expanding.');
    }
    const interruptedClip = getComputedStyle(panel).clipPath;
    const interruptedTransform = getComputedStyle(panel).transform;
    close.click();
    const closing = panel.getAnimations().find((animation) => animation.effect?.target === panel);
    assert(closing, 'Closing must animate from the current opening shape.');
    closing.pause();
    closing.currentTime = 0;
    assert(getComputedStyle(panel).clipPath === interruptedClip && getComputedStyle(panel).transform === interruptedTransform,
      'Closing during opening must preserve the current shape and position.');
    closing.currentTime = closing.effect.getTiming().duration * 0.45;
    const closingClip = getComputedStyle(panel).clipPath;
    const closingTransform = getComputedStyle(panel).transform;
    assistant.dispatchEvent(new CustomEvent('trotamundosAssistantOpen', { bubbles: true }));
    const reopening = panel.getAnimations().find((animation) => animation.effect?.target === panel);
    assert(reopening, 'The compact assistant must support interrupting the close animation.');
    reopening.pause();
    reopening.currentTime = 0;
    assert(getComputedStyle(panel).clipPath === closingClip && getComputedStyle(panel).transform === closingTransform,
      'Reopening during closing must preserve the current shape and position.');
    finishAssistantAnimation(reopening);
  }
  else {
    assert(!panel.getAnimations().some((animation) => animation.effect?.target === panel),
      'Reduced motion must open the compact assistant without animation.');
  }
  close.click();
  panel.getAnimations({ subtree: true })
    .filter((animation) => Number.isFinite(animation.effect?.getComputedTiming().endTime))
    .forEach(finishAssistantAnimation);
  await until(() => !assistant.open && document.activeElement === trigger, 'Closing the assistant must return focus to its button.');
  return 'Scrolled assistant motion checks passed.';
}

/**
 * Browser regression checks for the home sliders at the current viewport.
 */
export async function runHomeSliderTests() {
  const assert = (condition, message) => {
    if (!condition) {
      throw new Error(message);
    }
  };
  const until = async (condition, message) => {
    for (let attempt = 0; attempt < 160; attempt++) {
      if (condition()) {
        return;
      }
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
    throw new Error(message);
  };
  const sliders = [
    { name: 'destinations', root: document.querySelector('.home-destinations[data-trotamundos-slider]') },
  ];

  for (const { name, root } of sliders) {
    assert(root, `The ${name} slider is missing.`);
    const track = root.querySelector('[data-slider-track]');
    const controls = root.querySelector('[data-slider-controls]');
    const previous = controls.querySelector('[data-slider-scroll="-1"]');
    const next = controls.querySelector('[data-slider-scroll="1"]');
    assert(track && !controls.hidden && previous && next, `The ${name} slider controls are unavailable.`);
    assert(previous.getAttribute('aria-controls') === track.id && next.getAttribute('aria-controls') === track.id,
      `The ${name} controls must identify their track.`);
    const originalPosition = track.scrollLeft;
    try {
      track.scrollTo({ left: 0, behavior: 'instant' });
      track.dispatchEvent(new Event('scroll'));
      await until(() => track.scrollLeft <= 1 && previous.disabled, `The ${name} slider did not reach its start.`);
      const max = track.scrollWidth - track.clientWidth;
      if (max > 1) {
        assert(!next.disabled, `The ${name} slider cannot advance.`);
        next.click();
        await until(() => track.scrollLeft > 1 && !previous.disabled, `The ${name} next control did not advance.`);
        track.scrollTo({ left: max, behavior: 'instant' });
        track.dispatchEvent(new Event('scroll'));
        await until(() => next.disabled, `The ${name} next control stayed enabled at the end.`);
        previous.click();
        await until(() => track.scrollLeft < max - 1 && !next.disabled, `The ${name} previous control did not go back.`);
        track.scrollTo({ left: 0, behavior: 'instant' });
        track.dispatchEvent(new Event('scroll'));
        await until(() => track.scrollLeft <= 1 && previous.disabled, `The ${name} slider did not return to its start.`);
        track.focus({ preventScroll: true });
        const arrow = new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true });
        track.dispatchEvent(arrow);
        assert(arrow.defaultPrevented, `The ${name} track must handle arrow-key navigation.`);
        await until(() => track.scrollLeft > 1, `The ${name} track did not advance with the keyboard.`);
        const modifiedArrow = new KeyboardEvent('keydown', { key: 'ArrowRight', ctrlKey: true, bubbles: true, cancelable: true });
        track.dispatchEvent(modifiedArrow);
        assert(!modifiedArrow.defaultPrevented, `The ${name} slider must not intercept modified keyboard shortcuts.`);
        const linkArrow = new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true });
        track.querySelector('a').dispatchEvent(linkArrow);
        assert(!linkArrow.defaultPrevented, `The ${name} slider must not intercept keys on its links.`);
      }
      else {
        assert(next.disabled, `The ${name} next control must be disabled without overflow.`);
      }
      if (name === 'destinations') {
        const cards = [...track.children];
        assert(cards.length > 0 && cards.every((card) => card.querySelector('a[href]')),
          'Destination cards must remain links.');
        assert(cards.every((card) => Math.abs(card.getBoundingClientRect().top - cards[0].getBoundingClientRect().top) < 1),
          'Destination cards must stay in one horizontal row.');
      }
    }
    finally {
      track.scrollTo({ left: originalPosition, behavior: 'instant' });
    }
  }

  const styles = document.querySelector('.travel-styles[data-slider-loop]');
  assert(styles, 'The looping travel-style slider is missing.');
  const styleTrack = styles.querySelector('[data-slider-track]');
  const viewport = styles.querySelector('[data-slider-viewport]');
  const controls = styles.querySelector('[data-slider-controls]');
  const previous = controls.querySelector('[data-slider-scroll="-1"]');
  const next = controls.querySelector('[data-slider-scroll="1"]');
  // The home has no pause button: hovering the slider holds the rotation.
  const autoplay = controls.querySelector('[data-slider-autoplay]');
  const title = styles.querySelector('#travel-styles-title');
  const section = styles.closest('#experiencias');
  const finishMotion = (track) => track.getAnimations().forEach((animation) => {
    animation.finish();
    animation.dispatchEvent(new Event('finish'));
  });
  const links = (track) => [...track.children].map((card) => card.querySelector('a').getAttribute('href'));
  const move = (button, track) => {
    button.click();
    finishMotion(track);
  };
  assert(section && section.getAttribute('aria-label'), 'The plans section needs an accessible name.');
  for (const removed of ['Planes en Colombia', 'Elige una forma de viajar y descubre planes con anfitriones locales.', 'Ver el catálogo completo']) {
    assert(!section.textContent.includes(removed), `The plans section must not display: ${removed}.`);
  }
  assert(title && title.parentElement === controls.parentElement && !controls.hidden,
    'The title and slider controls must share a row.');
  const titleBounds = title.getBoundingClientRect();
  const previousBounds = previous.getBoundingClientRect();
  const nextBounds = next.getBoundingClientRect();
  assert(titleBounds.left >= previousBounds.right - 1 && titleBounds.right <= nextBounds.left + 1,
    'The title must sit between the two arrows without overlapping them on narrow screens.');
  assert(Math.abs((titleBounds.left + titleBounds.right) / 2 - (previousBounds.right + nextBounds.left) / 2) < 2
    && getComputedStyle(title).textAlign === 'center',
  'The title must be centred between the arrows.');
  assert(previous.getAttribute('aria-controls') === styleTrack.id && next.getAttribute('aria-controls') === styleTrack.id,
    'The travel-style controls must identify their track.');
  assert(['clip', 'hidden'].includes(getComputedStyle(viewport).overflowX),
    'The enhanced travel-style slider must not expose manual scrolling.');
  if (getComputedStyle(viewport).overflowX === 'clip') {
    viewport.scrollLeft = 100;
    assert(viewport.scrollLeft === 0, 'The enhanced slider must not scroll manually.');
  }
  const cards = [...styleTrack.querySelectorAll('li > a')];
  assert(cards.length > 0 && cards.every((card) => new URL(card.href).searchParams.has('estilo')),
    'Travel-style cards must remain links to the filtered catalog.');
  assert(!autoplay, 'The travel-style slider must not render a pause button.');
  assert(cards.every((card) => {
    const rect = card.getBoundingClientRect();
    const image = card.querySelector('img.travel-style__image');
    return rect.height > rect.width * 1.4 && getComputedStyle(card).color === 'rgb(23, 61, 50)'
      && parseFloat(getComputedStyle(image).borderTopLeftRadius) > rect.width / 3;
  }), 'Travel-style cards must be vertical, with an arch photo and dark green text.');
  const images = cards.map((card) => card.querySelector('img.travel-style__image'));
  assert(images.every((image) => image && image.getAttribute('alt') === '' && image.loading === 'lazy'),
    'Every travel style must have a decorative, lazy-loaded image.');
  const imageResponses = await Promise.all(images.map((image) => fetch(image.src, { method: 'HEAD' })));
  assert(imageResponses.every((response) => response.ok && response.headers.get('content-type')?.startsWith('image/')),
    'Every travel-style image must be available.');

  finishMotion(styleTrack);
  const initialLinks = links(styleTrack);
  const holdAutoplay = () => styles.dispatchEvent(new Event('pointerenter'));
  const releaseAutoplay = () => styles.dispatchEvent(new Event('pointerleave'));
  holdAutoplay();
  try {
    if (!next.disabled) {
      move(next, styleTrack);
      assert(JSON.stringify(links(styleTrack)) === JSON.stringify([...initialLinks.slice(1), initialLinks[0]]),
        'The next control must advance exactly one travel style.');
      move(previous, styleTrack);
      move(previous, styleTrack);
      assert(JSON.stringify(links(styleTrack)) === JSON.stringify([initialLinks.at(-1), ...initialLinks.slice(0, -1)]),
        'The previous control must wrap to the last travel style.');
      move(next, styleTrack);
      for (let index = 0; index < initialLinks.length; index++) {
        move(next, styleTrack);
      }
      assert(JSON.stringify(links(styleTrack)) === JSON.stringify(initialLinks) && !previous.disabled && !next.disabled,
        'The slider must loop without disabling either direction.');
      next.click();
      if (!matchMedia('(prefers-reduced-motion: reduce)').matches && styleTrack.animate) {
        const clone = styleTrack.querySelector(':scope > [inert]');
        assert(clone && clone.getAttribute('aria-hidden') === 'true'
          && clone.querySelector('a').getAttribute('tabindex') === '-1',
        'The temporary loop card must be inaccessible to keyboards and screen readers.');
      }
      next.click();
      finishMotion(styleTrack);
      assert(JSON.stringify(links(styleTrack)) === JSON.stringify([...initialLinks.slice(2), ...initialLinks.slice(0, 2)]),
        'Rapid clicks must each advance one travel style.');
      move(previous, styleTrack);
      move(previous, styleTrack);
      styleTrack.focus({ preventScroll: true });
      const arrow = new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true });
      styleTrack.dispatchEvent(arrow);
      finishMotion(styleTrack);
      assert(arrow.defaultPrevented && links(styleTrack)[0] === initialLinks[1],
        'The focused travel-style track must advance with the arrow keys.');
      const modified = new KeyboardEvent('keydown', { key: 'ArrowRight', ctrlKey: true, bubbles: true, cancelable: true });
      styleTrack.dispatchEvent(modified);
      assert(!modified.defaultPrevented, 'The slider must not intercept modified keyboard shortcuts.');
      const linkArrow = new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true });
      styleTrack.querySelector('a').dispatchEvent(linkArrow);
      assert(!linkArrow.defaultPrevented, 'The slider must not intercept keys on its links.');
      styleTrack.blur();
    }
    else {
      assert(previous.disabled, 'A slider without overflow must not rotate.');
    }
  }
  finally {
    finishMotion(styleTrack);
    for (let index = 0; index < initialLinks.length && !next.disabled && links(styleTrack)[0] !== initialLinks[0]; index++) {
      move(previous, styleTrack);
    }
    releaseAutoplay();
  }

  holdAutoplay();
  const loopFixture = document.createElement('div');
  loopFixture.style.cssText = 'position:fixed;left:-10000px;top:0;width:300px;transition:none!important';
  loopFixture.innerHTML = '<div class="travel-styles" data-trotamundos-slider data-slider-loop><div data-slider-controls hidden><button data-slider-autoplay data-pause-label="Pause" data-resume-label="Resume"></button><button data-slider-scroll="-1"></button><button data-slider-scroll="1"></button></div><div class="travel-styles__viewport" data-slider-viewport><ul class="travel-styles__track" data-slider-track tabindex="0"><li><a href="#one">One</a></li><li><a href="#two">Two</a></li><li><a href="#three">Three</a></li></ul></div></div>';
  document.body.append(loopFixture);
  const loopRoot = loopFixture.querySelector('[data-slider-loop]');
  const loopTrack = loopFixture.querySelector('[data-slider-track]');
  const loopControls = loopFixture.querySelector('[data-slider-controls]');
  const loopAutoplay = loopFixture.querySelector('[data-slider-autoplay]');
  const originalSetInterval = window.setInterval;
  const originalClearInterval = window.clearInterval;
  const hiddenDescriptor = Object.getOwnPropertyDescriptor(document, 'hidden');
  const intervals = new Map();
  let intervalId = -1;
  let hidden = false;
  window.setInterval = (callback, delay, ...args) => {
    if (delay !== 2500) {
      return originalSetInterval(callback, delay, ...args);
    }
    const id = intervalId--;
    intervals.set(id, () => callback(...args));
    return id;
  };
  window.clearInterval = (id) => {
    if (id < 0) {
      intervals.delete(id);
    }
    else {
      originalClearInterval(id);
    }
  };
  Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
  try {
    Drupal.attachBehaviors(loopFixture);
    assert(!loopControls.hidden && !loopFixture.querySelector('[data-slider-scroll="1"]').disabled,
      'An overflowing loop must enable its controls.');
    assert(loopTrack.querySelector('li[aria-hidden="true"] a[tabindex="-1"]'),
      'Offscreen cards must not be in the keyboard or accessibility order.');
    const partialCard = loopTrack.children[1];
    const partialLink = partialCard.querySelector('a');
    const orderBeforeFocus = links(loopTrack);
    partialLink.focus({ preventScroll: true });
    if (!document.hasFocus()) {
      partialLink.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
    }
    assert(document.activeElement === partialLink && !partialCard.hasAttribute('aria-hidden')
      && JSON.stringify(links(loopTrack)) === JSON.stringify(orderBeforeFocus),
    'Focusing a partially visible card must expose it without moving it.');
    partialLink.blur();
    if (!document.hasFocus()) {
      partialLink.dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget: document.body }));
    }
    assert(partialCard.getAttribute('aria-hidden') === 'true', 'A card must be hidden again when focus leaves it.');
    if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
      assert(intervals.size === 1 && !loopAutoplay.hidden, 'Autoplay must use a 2.5-second interval.');
      [...intervals.values()][0]();
      finishMotion(loopTrack);
      assert(links(loopTrack)[0] === '#two', 'An autoplay tick must advance exactly one card.');
      loopAutoplay.click();
      assert(intervals.size === 0 && loopAutoplay.getAttribute('aria-label') === 'Resume',
        'The pause control must stop autoplay and offer to resume it.');
      loopAutoplay.click();
      assert(intervals.size === 1, 'The resume control must restart autoplay.');
      loopRoot.dispatchEvent(new Event('pointerenter'));
      assert(intervals.size === 0, 'Hovering over the slider must pause autoplay.');
      loopRoot.dispatchEvent(new Event('pointerleave'));
      assert(intervals.size === 1, 'Autoplay must resume after the pointer leaves.');
      loopTrack.focus({ preventScroll: true });
      if (!document.hasFocus()) {
        loopTrack.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
      }
      assert(intervals.size === 0, 'Focusing the track must pause autoplay.');
      loopTrack.blur();
      if (!document.hasFocus()) {
        loopTrack.dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget: document.body }));
      }
      assert(intervals.size === 1, 'Autoplay must resume when focus leaves the track.');
      hidden = true;
      document.dispatchEvent(new Event('visibilitychange'));
      assert(intervals.size === 0, 'Autoplay must stop in a hidden tab.');
      hidden = false;
      document.dispatchEvent(new Event('visibilitychange'));
      assert(intervals.size === 1, 'Autoplay must resume when the tab becomes visible.');
    }
    else {
      assert(loopAutoplay.hidden && intervals.size === 0, 'Reduced motion must disable autoplay.');
    }
    loopFixture.style.width = '1000px';
    window.dispatchEvent(new Event('resize'));
    assert([...loopControls.querySelectorAll('[data-slider-scroll]')].every((button) => button.disabled) && loopAutoplay.hidden,
      'All controls must stop rotating when the cards fit.');
    loopFixture.style.width = '300px';
    window.dispatchEvent(new Event('resize'));
    assert(!loopFixture.querySelector('[data-slider-scroll="1"]').disabled, 'Resizing must re-enable the loop.');
    loopFixture.style.width = '500px';
    window.dispatchEvent(new Event('resize'));
    const focusedLink = loopTrack.children[1].querySelector('a');
    focusedLink.focus({ preventScroll: true });
    loopFixture.style.width = '300px';
    window.dispatchEvent(new Event('resize'));
    assert(document.activeElement === focusedLink && loopTrack.firstElementChild.contains(focusedLink),
      'Resizing must keep a focused card visible without losing focus.');
    focusedLink.blur();
    loopFixture.querySelector('[data-slider-scroll="1"]').click();
    Drupal.detachBehaviors(loopFixture, drupalSettings, 'unload');
    assert(loopControls.hidden && !loopRoot.classList.contains('is-looping') && intervals.size === 0
      && !loopTrack.querySelector('[inert]') && loopTrack.getAnimations().length === 0,
    'Detaching the loop must stop its motion and autoplay and restore the native-scrolling fallback.');
    assert(JSON.stringify(links(loopTrack)) === JSON.stringify(['#one', '#two', '#three'])
      && [...loopTrack.querySelectorAll('a')].every((link) => !link.hasAttribute('tabindex')),
      'Detaching must restore the original order and keyboard access.');
    Drupal.attachBehaviors(loopFixture);
    assert(!loopControls.hidden, 'A detached loop must initialize correctly again.');
    Drupal.detachBehaviors(loopFixture, drupalSettings, 'unload');
    const fixtureCards = [...loopTrack.children];
    loopTrack.replaceChildren();
    Drupal.attachBehaviors(loopFixture);
    assert([...loopControls.querySelectorAll('[data-slider-scroll]')].every((button) => button.disabled) && loopAutoplay.hidden,
      'An empty loop must not rotate.');
    Drupal.detachBehaviors(loopFixture, drupalSettings, 'unload');
    loopTrack.append(fixtureCards[0]);
    Drupal.attachBehaviors(loopFixture);
    assert([...loopControls.querySelectorAll('[data-slider-scroll]')].every((button) => button.disabled) && loopAutoplay.hidden,
      'A loop with one card must not rotate.');
    Drupal.detachBehaviors(loopFixture, drupalSettings, 'unload');
    loopTrack.replaceChildren(...fixtureCards);
    const originalResizeObserver = window.ResizeObserver;
    try {
      window.ResizeObserver = undefined;
      Drupal.attachBehaviors(loopFixture);
      assert(!loopFixture.querySelector('[data-slider-scroll="1"]').disabled,
        'The loop must initialize without ResizeObserver.');
      loopFixture.style.width = '1000px';
      window.dispatchEvent(new Event('resize'));
      assert(loopFixture.querySelector('[data-slider-scroll="1"]').disabled,
        'Window resize must still update the loop without ResizeObserver.');
    }
    finally {
      Drupal.detachBehaviors(loopFixture, drupalSettings, 'unload');
      window.ResizeObserver = originalResizeObserver;
    }
  }
  finally {
    Drupal.detachBehaviors(loopFixture, drupalSettings, 'unload');
    loopFixture.remove();
    window.setInterval = originalSetInterval;
    window.clearInterval = originalClearInterval;
    if (hiddenDescriptor) {
      Object.defineProperty(document, 'hidden', hiddenDescriptor);
    }
    else {
      delete document.hidden;
    }
    releaseAutoplay();
  }

  const fixture = document.createElement('div');
  fixture.style.cssText = 'position:fixed;left:-10000px;top:0;width:300px';
  fixture.innerHTML = '<div data-trotamundos-slider><div data-slider-controls hidden><button data-slider-scroll="-1"></button><button data-slider-scroll="1"></button></div><div data-slider-track>One item</div></div>';
  document.body.append(fixture);
  try {
    Drupal.attachBehaviors(fixture);
    const controls = fixture.querySelector('[data-slider-controls]');
    assert(!controls.hidden && [...controls.querySelectorAll('button')].every((button) => button.disabled),
      'A slider without overflow must disable both controls.');
    Drupal.detachBehaviors(fixture, drupalSettings, 'unload');
    assert(controls.hidden, 'Detaching a slider must hide its controls.');
    Drupal.attachBehaviors(fixture);
    assert(!controls.hidden && [...controls.querySelectorAll('button')].every((button) => button.disabled),
      'A detached slider must initialize correctly again.');
  }
  finally {
    Drupal.detachBehaviors(fixture, drupalSettings, 'unload');
    fixture.remove();
  }
  return 'Home slider checks passed.';
}

/**
 * Browser regression checks for catalog cards at the current viewport.
 */
export async function runCatalogCardTests() {
  const assert = (condition, message) => {
    if (!condition) {
      throw new Error(message);
    }
  };
  const grid = document.querySelector('[data-catalog-grid]');
  assert(grid, 'Open a catalog with results before running this test.');
  const count = document.querySelector('#catalog-results-title');
  assert(count && count.classList.contains('visually-hidden') && count.textContent.trim(),
    'The result count must stay accessible without being displayed.');
  const cards = [...grid.querySelectorAll('.experience-card')];
  const columns = innerWidth >= 1200 ? 4 : innerWidth >= 900 ? 3 : innerWidth >= 640 ? 2 : 1;
  assert(cards.length > 0 && getComputedStyle(grid).gridTemplateColumns.split(' ').length === columns,
    `The catalog must have ${columns} columns at this width.`);
  assert(!grid.querySelector('.experience-card__type'), 'Catalog cards must not display type or DEMO badges.');

  for (const card of cards) {
    const media = card.querySelector('.experience-card__media');
    const summary = media.querySelector('.experience-card__summary');
    const accessibleSummary = card.querySelector('.experience-card__title + .visually-hidden');
    assert(!card.querySelector('.experience-card__body .experience-card__summary'),
      'The visual summary must not remain beneath the title.');
    assert(Boolean(summary) === Boolean(accessibleSummary), 'The visual and accessible summaries must agree.');
    if (summary) {
      assert(summary.getAttribute('aria-hidden') === 'true'
        && summary.textContent.trim() === accessibleSummary.textContent.trim(),
      'The complete summary must be available after the title to screen readers.');
      assert(getComputedStyle(summary).position === 'absolute' && summary.offsetHeight < media.offsetHeight,
        'The summary overlay must leave part of the image uncovered.');
    }
  }

  if (CSS.supports('grid-template-rows', 'subgrid')) {
    const rows = new Map();
    cards.forEach((card) => {
      const top = Math.round(card.getBoundingClientRect().top);
      const price = card.querySelector('.experience-card__price').getBoundingClientRect().top;
      if (rows.has(top)) assert(Math.abs(rows.get(top) - price) < 1, 'Prices must align within each row.');
      else rows.set(top, price);
    });
  }

  const card = cards.find((item) => item.querySelector('.experience-card__summary'));
  if (card) {
    const link = card.querySelector('.experience-card__title a');
    const summary = card.querySelector('.experience-card__summary');
    link.focus({ preventScroll: true });
    for (let attempt = 0; attempt < 30 && Number(getComputedStyle(summary).opacity) < 0.99; attempt++) {
      await new Promise((resolve) => setTimeout(resolve, 30));
    }
    assert(Number(getComputedStyle(summary).opacity) >= 0.99, 'Focusing a card must reveal its summary.');
    link.blur();

    const fixture = document.createElement('div');
    fixture.style.cssText = 'position:fixed;left:-10000px;top:0;width:256px';
    fixture.setAttribute('aria-hidden', 'true');
    const clone = card.cloneNode(true);
    clone.querySelector('.experience-card__favorite')?.remove();
    const media = clone.querySelector('.experience-card__media');
    media.querySelector('img')?.remove();
    media.classList.add('experience-card__media--empty');
    const longSummary = 'Un plan para descubrir Colombia con calma. '.repeat(20);
    clone.querySelector('.experience-card__summary span').textContent = longSummary;
    clone.querySelector('.experience-card__title + .visually-hidden').textContent = longSummary;
    fixture.append(clone);
    document.body.append(fixture);
    try {
      assert(media.offsetHeight > 0, 'A card without an image must retain its media area.');
      const preview = media.querySelector('.experience-card__summary');
      assert(preview.offsetHeight < media.offsetHeight, 'A long summary must not cover the whole media area.');
      assert(preview.querySelector('span').scrollHeight > preview.querySelector('span').clientHeight,
        'A long visual summary must be truncated.');
      assert(clone.querySelector('.experience-card__title + .visually-hidden').textContent === longSummary,
        'The complete long summary must remain accessible.');
    }
    finally {
      fixture.remove();
    }
  }
  return 'Catalog card checks passed.';
}
