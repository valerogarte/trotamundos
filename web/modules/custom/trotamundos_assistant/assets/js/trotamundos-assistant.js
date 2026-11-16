(function (Drupal, drupalSettings, once) {
  'use strict';

  const MIN_HEIGHT = 360;
  const THREAD_STORAGE_KEY = 'trotamundosAssistant.threadId';
  const RESULT_TRANSITION_MS = 220;
  const DESKTOP_MEDIA_QUERY = '(min-width: 901px)';
  const CONTENT_SHIFT_GUTTER = 24;
  const scheduledPositionRefreshes = new WeakSet();
  const shiftedExtraElements = new Set();

  Drupal.trotamundosAssistant = Drupal.trotamundosAssistant || {};

  function setStickyHeaderOffset(element) {
    const header = getStickyHeaderElement();
    const offsetTop = header ? getVisibleHeaderBottom(header) : 0;

    element.style.setProperty(
      '--trotamundos-assistant-page-offset-top',
      `${offsetTop}px`,
    );
  }

  function getStickyHeaderElement() {
    const selector = (drupalSettings.trotamundosAssistant?.headerSticky || '').trim();
    if (!selector) {
      return null;
    }

    try {
      return document.querySelector(selector);
    }
    catch (error) {
      return null;
    }
  }

  function getVisibleHeaderBottom(header) {
    const styles = window.getComputedStyle(header);
    if (styles.display === 'none' || styles.visibility === 'hidden') {
      return 0;
    }

    const rect = header.getBoundingClientRect();
    if (rect.width < 1 || rect.height < 1 || rect.bottom <= 0) {
      return 0;
    }

    return Math.max(0, Math.ceil(rect.bottom));
  }

  function setAvailableHeight(element) {
    const top = element.getBoundingClientRect().top;
    const availableHeight = Math.max(MIN_HEIGHT, Math.floor(window.innerHeight - top));

    element.style.setProperty(
      '--trotamundos-assistant-available-height',
      `${availableHeight}px`,
    );

    const target = getResultTarget(element);
    if (target) {
      target.style.setProperty(
        '--trotamundos-assistant-available-height',
        `${availableHeight}px`,
      );
    }
  }

  function refreshLayout(element, initial = false) {
    setStickyHeaderOffset(element);
    setAvailableHeight(element);
    applyChatState(element, initial);
    updateContentShift(element);
  }

  function refreshPosition(element) {
    setStickyHeaderOffset(element);
    setAvailableHeight(element);
    updateContentShift(element);
  }

  function schedulePositionRefresh(element) {
    if (scheduledPositionRefreshes.has(element)) {
      return;
    }

    scheduledPositionRefreshes.add(element);
    window.requestAnimationFrame(() => {
      scheduledPositionRefreshes.delete(element);
      refreshPosition(element);
    });
  }

  function observeStickyHeader(element) {
    const header = getStickyHeaderElement();
    if (!header) {
      return;
    }

    if (typeof ResizeObserver !== 'undefined') {
      const resizeObserver = new ResizeObserver(() => {
        schedulePositionRefresh(element);
      });
      resizeObserver.observe(header);
    }

    if (typeof MutationObserver !== 'undefined') {
      const mutationObserver = new MutationObserver(() => {
        schedulePositionRefresh(element);
      });
      mutationObserver.observe(header, {
        attributes: true,
        childList: true,
        subtree: true,
      });
    }
  }

  function getStoredThreadId() {
    clearLegacyStoredThreadId();

    try {
      return window.sessionStorage.getItem(THREAD_STORAGE_KEY) || '';
    }
    catch (error) {
      return '';
    }
  }

  function setStoredThreadId(threadId) {
    try {
      if (threadId) {
        window.sessionStorage.setItem(THREAD_STORAGE_KEY, threadId);
      }
      else {
        window.sessionStorage.removeItem(THREAD_STORAGE_KEY);
      }
    }
    catch (error) {
      // Storage can be unavailable in private browsing or hardened browsers.
    }

    clearLegacyStoredThreadId();
  }

  function clearLegacyStoredThreadId() {
    try {
      window.localStorage.removeItem(THREAD_STORAGE_KEY);
    }
    catch (error) {
      // localStorage can be unavailable in private browsing or hardened browsers.
    }
  }

  function clearMessages(messages) {
    messages.innerHTML = '';
  }

  function appendMessage(messages, text, role) {
    const message = document.createElement('div');
    message.className = `trotamundos-assistant__message trotamundos-assistant__message--${role}`;
    message.textContent = text;
    messages.appendChild(message);
    messages.scrollTop = messages.scrollHeight;

    return message;
  }

  function appendResponsiveResultButton(element, message, title) {
    const label = String(title || '').trim();
    if (!element || !message || !label) {
      return;
    }

    message.querySelector('.trotamundos-assistant__message-result-toggle')?.remove();

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'trotamundos-assistant__message-result-toggle';
    button.textContent = label;
    button.addEventListener('click', () => {
      setChatOpen(element, false);
    });

    message.appendChild(button);
  }

  function appendLoaderMessage(messages) {
    const message = document.createElement('div');
    message.className = 'trotamundos-assistant__message trotamundos-assistant__message--assistant trotamundos-assistant__message--loading';
    message.setAttribute('role', 'status');
    message.setAttribute('aria-live', 'polite');

    const loader = document.createElement('span');
    loader.className = 'trotamundos-assistant__loader';
    loader.setAttribute('aria-hidden', 'true');

    for (let index = 0; index < 3; index++) {
      const dot = document.createElement('span');
      dot.className = 'trotamundos-assistant__loader-dot';
      loader.appendChild(dot);
    }

    const label = document.createElement('span');
    label.className = 'visually-hidden';
    label.textContent = Drupal.t('Pensando...');

    message.appendChild(loader);
    message.appendChild(label);
    messages.appendChild(message);
    messages.scrollTop = messages.scrollHeight;

    return message;
  }

  function appendInitialMessage(element) {
    const messages = element.querySelector('.trotamundos-assistant__messages');
    if (messages) {
      appendMessage(messages, getWelcomeMessage(), 'assistant');
    }
  }

  function getWelcomeMessage() {
    return drupalSettings.trotamundosAssistant?.welcomeMessage
      || Drupal.t('Bienvenido a tu nueva manera de navegar. ¿Qué necesitas?');
  }

  function isAccessAllowed() {
    return drupalSettings.trotamundosAssistant?.accessAllowed !== false;
  }

  function getResultTargetSelector(element) {
    return (element?.dataset.resultTargetSelector || drupalSettings.trotamundosAssistant?.resultTargetSelector || '').trim();
  }

  function getResultTarget(element) {
    const selector = getResultTargetSelector(element);
    if (!selector) {
      return null;
    }

    try {
      return document.querySelector(selector);
    }
    catch (error) {
      return null;
    }
  }

  function prepareResultTarget(element) {
    const target = getResultTarget(element);
    const assistant = element?.closest?.('.trotamundos-assistant');
    if (!target || (assistant && (target === assistant || target.contains(assistant)))) {
      return null;
    }

    target.classList.add('trotamundos-assistant-target');
    if (element) {
      target.trotamundosAssistantElement = element;
    }
    if (element && typeof element.trotamundosAssistantDefaultResultHtml === 'undefined') {
      element.trotamundosAssistantDefaultResultHtml = target.innerHTML;
    }

    return target;
  }

  function getResultElement(element, create = true) {
    const target = prepareResultTarget(element);
    if (!target) {
      return null;
    }

    let result = target.querySelector(':scope > .trotamundos-assistant__result-inner');
    if (!result && create) {
      target.innerHTML = '';
      result = document.createElement('div');
      result.className = 'trotamundos-assistant__result-inner';
      target.appendChild(result);
    }

    target.classList.toggle('has-trotamundos-assistant-result', Boolean(result));

    return result;
  }

  function setConfigurationError(element, message = '') {
    element.classList.toggle('has-configuration-error', Boolean(message));
    const error = element.querySelector('.trotamundos-assistant__configuration-error');
    if (!error) {
      return;
    }

    error.hidden = !message;
    error.textContent = message;
  }

  function setControlsDisabled(element, disabled) {
    element.classList.toggle('is-disabled', disabled);
    element.querySelectorAll('.trotamundos-assistant__input, .trotamundos-assistant__submit, .trotamundos-assistant__reset')
      .forEach((control) => {
        control.disabled = disabled;
      });
  }

  function validateResultTarget(element) {
    const selector = getResultTargetSelector(element);
    if (!selector) {
      setConfigurationError(element, Drupal.t('Falta configurar el selector del panel de resultado del asistente.'));
      setControlsDisabled(element, true);
      return null;
    }

    const target = prepareResultTarget(element);
    if (!target) {
      setConfigurationError(element, Drupal.t('No se ha encontrado el panel de resultado configurado: @selector', {
        '@selector': selector,
      }));
      setControlsDisabled(element, true);
      return null;
    }

    setConfigurationError(element, '');
    setControlsDisabled(element, false);
    return target;
  }

  function isDesktop() {
    return window.matchMedia(DESKTOP_MEDIA_QUERY).matches;
  }

  function setChatOpen(element, open) {
    const target = getResultTarget(element);
    const shouldOpen = Boolean(open);

    element.classList.toggle('is-chat-open', shouldOpen);
    element.querySelector('.trotamundos-assistant__toggle')?.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    element.querySelector('.trotamundos-assistant__close')?.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');

    if (target) {
      updateContentShift(element, target, shouldOpen);
    }
  }

  function updateContentShift(element, target = getResultTarget(element), shouldOpen = element.classList.contains('is-chat-open')) {
    if (!target || !shouldOpen || !isDesktop()) {
      target?.style.setProperty('--trotamundos-assistant-shift-width', '0px');
      target?.classList.remove('is-trotamundos-assistant-shifted');
      clearExtraContentShift();
      return;
    }

    const chat = element.querySelector('.trotamundos-assistant__chat');
    if (!chat) {
      target.style.setProperty('--trotamundos-assistant-shift-width', '0px');
      target.classList.remove('is-trotamundos-assistant-shifted');
      clearExtraContentShift();
      return;
    }

    const chatRight = Math.ceil(element.getBoundingClientRect().left + chat.offsetWidth);
    const rawReferenceLeft = Math.floor(getShiftReferenceLeft(target));
    const referenceLeft = rawReferenceLeft - getCurrentShiftOffset(target);
    const shift = Math.max(0, chatRight + CONTENT_SHIFT_GUTTER - referenceLeft);

    target.style.setProperty('--trotamundos-assistant-shift-width', `${shift}px`);
    target.classList.add('is-trotamundos-assistant-shifted');
    updateExtraContentShift(target, shift);
  }

  function getCurrentShiftOffset(target) {
    if (!target.classList.contains('is-trotamundos-assistant-shifted')) {
      return 0;
    }

    const marginLeft = Number.parseFloat(window.getComputedStyle(target).marginLeft);

    return Number.isFinite(marginLeft) ? marginLeft : 0;
  }

  function getShiftReferenceLeft(target) {
    if (target.classList.contains('has-trotamundos-assistant-result')) {
      return target.getBoundingClientRect().left;
    }

    return getShiftReferenceElement(target).getBoundingClientRect().left;
  }

  function getShiftReferenceElement(target) {
    return target.querySelector(':scope > *')
      || target;
  }

  function updateExtraContentShift(target, shift) {
    const currentElements = new Set(getExtraShiftElements(target));

    shiftedExtraElements.forEach((element) => {
      if (!currentElements.has(element)) {
        clearExtraShiftElement(element);
        shiftedExtraElements.delete(element);
      }
    });

    currentElements.forEach((element) => {
      element.style.setProperty('--trotamundos-assistant-shift-width', `${shift}px`);
      element.classList.add('is-trotamundos-assistant-extra-shiftable');
      element.classList.add('is-trotamundos-assistant-extra-shifted');
      shiftedExtraElements.add(element);
    });
  }

  function clearExtraContentShift() {
    shiftedExtraElements.forEach((element) => {
      clearExtraShiftElement(element);
    });
    shiftedExtraElements.clear();
  }

  function clearExtraShiftElement(element) {
    element.style.setProperty('--trotamundos-assistant-shift-width', '0px');
    element.classList.remove('is-trotamundos-assistant-extra-shifted');
  }

  function getExtraShiftElements(target) {
    const selectors = getExtraShiftSelectors();
    if (selectors.length === 0) {
      return [];
    }

    const elements = [];
    selectors.forEach((selector) => {
      try {
        document.querySelectorAll(selector).forEach((element) => {
          // Descendants of the target already move with its own shift.
          if (element !== target && !target.contains(element) && !element.closest('.trotamundos-assistant')) {
            elements.push(element);
          }
        });
      }
      catch (error) {
        // Invalid selectors are ignored; validation happens in Drupal config.
      }
    });

    return [...new Set(elements)];
  }

  function getExtraShiftSelectors() {
    return String(drupalSettings.trotamundosAssistant?.extraShiftSelectors || '')
      .split(';')
      .map((selector) => selector.trim())
      .filter(Boolean);
  }

  function applyChatState(element, initial = false) {
    const alwaysOpen = element.dataset.desktopAlwaysOpen === 'true'
      || drupalSettings.trotamundosAssistant?.desktopAlwaysOpen === true;
    if (!isDesktop()) {
      setChatOpen(element, false);
      return;
    }

    if (initial || alwaysOpen) {
      setChatOpen(element, alwaysOpen);
    }
  }

  function setLoading(element, loading) {
    element.classList.toggle('is-loading', loading);
    const submit = element.querySelector('.trotamundos-assistant__submit');
    const input = element.querySelector('.trotamundos-assistant__input');
    const reset = element.querySelector('.trotamundos-assistant__reset');

    if (submit) {
      submit.disabled = loading || element.classList.contains('is-disabled');
    }

    if (input) {
      input.disabled = loading || element.classList.contains('is-disabled');
    }

    if (reset) {
      reset.disabled = loading || element.classList.contains('is-disabled');
    }
  }

  async function getCsrfToken() {
    if (drupalSettings.trotamundosAssistant?.csrfToken) {
      return drupalSettings.trotamundosAssistant.csrfToken;
    }

    const response = await fetch(`${drupalSettings.path.baseUrl}session/token`, {
      credentials: 'same-origin',
    });

    if (!response.ok) {
      throw new Error(Drupal.t('The security token could not be loaded.'));
    }

    drupalSettings.trotamundosAssistant.csrfToken = await response.text();

    return drupalSettings.trotamundosAssistant.csrfToken;
  }

  async function parseJsonResponse(response) {
    const rawResponse = await response.text();

    try {
      return rawResponse ? JSON.parse(rawResponse) : {};
    }
    catch (error) {
      return {};
    }
  }

  function shouldAnimateResult() {
    return !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function wait(milliseconds) {
    return new Promise((resolve) => {
      window.setTimeout(resolve, milliseconds);
    });
  }

  async function hideResult(result) {
    if (!result || !shouldAnimateResult() || result.innerHTML.trim() === '') {
      return;
    }

    result.classList.remove('is-entering');
    result.classList.add('is-exiting');
    await wait(RESULT_TRANSITION_MS);
  }

  function showResult(result) {
    if (!result || !shouldAnimateResult()) {
      return;
    }

    result.classList.remove('is-exiting');
    result.classList.add('is-entering');

    window.requestAnimationFrame(() => {
      result.classList.remove('is-entering');
    });
  }

  function setResultHtml(result, html, animate = true) {
    if (!result) {
      return;
    }

    result.closest('.trotamundos-assistant-target')?.classList.add('has-trotamundos-assistant-result');
    result.innerHTML = html || '';
    Drupal.attachBehaviors(result);
    refreshResultShift(result);
    result.dispatchEvent(new CustomEvent('trotamundosAssistantResultUpdated', {
      bubbles: true,
    }));

    if (animate) {
      showResult(result);
    }
    else {
      result.classList.remove('is-entering', 'is-exiting');
    }
  }

  function refreshResultShift(result) {
    const target = result?.closest?.('.trotamundos-assistant-target');
    const element = target?.trotamundosAssistantElement;
    if (!target || !element) {
      return;
    }

    updateContentShift(element, target);

    window.requestAnimationFrame(() => {
      updateContentShift(element, target);
    });
  }

  async function executeAjaxCommands(commands, result) {
    if (!Array.isArray(commands) || commands.length === 0 || !Drupal.AjaxCommands) {
      return;
    }

    const ajax = {
      commands: new Drupal.AjaxCommands(),
      getEffect() {
        return {
          showEffect: 'show',
          showSpeed: 0,
        };
      },
      method: 'html',
      settings: drupalSettings,
      wrapper: result,
    };

    for (const command of commands) {
      if (!command || typeof ajax.commands[command.command] !== 'function') {
        continue;
      }

      await Promise.resolve(ajax.commands[command.command](ajax, command, 200));
    }
  }

  async function setResultPayload(element, result, data, animate = true) {
    await executeAjaxCommands(data.result_ajax_commands, result);
    result = getResultElement(element);
    setResultHtml(result, data.result_html || '', animate);
  }

  function patchEmbeddedFormAjaxCommands() {
    if (!Drupal.AjaxCommands || Drupal.AjaxCommands.prototype.trotamundosAssistantEmbeddedFormPatched) {
      return;
    }

    const originalInsert = Drupal.AjaxCommands.prototype.insert;

    Drupal.AjaxCommands.prototype.insert = function (ajax, response, status) {
      const wrapper = ajax?.element?.closest?.('.trotamundos-assistant-embedded-form');

      if (wrapper && !response.selector) {
        response = {
          ...response,
          method: 'html',
          selector: `#${wrapper.id}`,
        };
      }

      return originalInsert.call(this, ajax, response, status);
    };

    Drupal.AjaxCommands.prototype.trotamundosAssistantEmbeddedFormPatched = true;
  }

  async function loadHistory(element) {
    const historyEndpoint = drupalSettings.trotamundosAssistant?.historyEndpoint;
    const messages = element.querySelector('.trotamundos-assistant__messages');
    const threadId = getStoredThreadId();

    if (!isAccessAllowed() || !historyEndpoint || !messages || !threadId) {
      return;
    }

    element.dataset.threadId = threadId;

    const url = new URL(historyEndpoint, window.location.origin);
    url.searchParams.set('thread_id', threadId);

    try {
      const response = await fetch(url.toString(), {
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
        },
      });
      const data = await parseJsonResponse(response);

      if (!response.ok || !Array.isArray(data.messages) || data.messages.length === 0) {
        setStoredThreadId('');
        element.dataset.threadId = '';
        return;
      }

      clearMessages(messages);
      data.messages.forEach((message) => {
        appendMessage(messages, message.message || '', message.role || 'assistant');
      });

      // Keep the page content intact on initial load. Results are only painted
      // after a new explicit assistant action in the current page view.
    }
    catch (error) {
      setStoredThreadId('');
      element.dataset.threadId = '';
    }
  }

  async function resetConversation(element) {
    if (!isAccessAllowed()) {
      setControlsDisabled(element, true);
      return;
    }

    const resetEndpoint = drupalSettings.trotamundosAssistant?.resetEndpoint;
    const messages = element.querySelector('.trotamundos-assistant__messages');
    const target = prepareResultTarget(element);

    setLoading(element, true);
    let newThreadId = '';

    try {
      if (resetEndpoint) {
        const csrfToken = await getCsrfToken();
        const response = await fetch(resetEndpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-Token': csrfToken,
          },
          body: JSON.stringify({
            thread_id: element.dataset.threadId || '',
          }),
        });
        const data = await parseJsonResponse(response);
        newThreadId = data.thread_id || '';
      }
    }
    finally {
      element.dataset.threadId = newThreadId;
      setStoredThreadId(newThreadId);

      if (messages) {
        clearMessages(messages);
      }

      if (target) {
        target.innerHTML = element.trotamundosAssistantDefaultResultHtml || '';
        target.classList.remove('has-trotamundos-assistant-result');
        Drupal.attachBehaviors(target);
        updateContentShift(element, target);
      }

      appendInitialMessage(element);
      setLoading(element, false);
    }
  }

  async function submitMessage(element, message) {
    const endpoint = drupalSettings.trotamundosAssistant?.endpoint;
    const messages = element.querySelector('.trotamundos-assistant__messages');
    const target = validateResultTarget(element);

    if (!endpoint || !messages || !target) {
      throw new Error(Drupal.t('The assistant is not configured.'));
    }

    appendMessage(messages, message, 'user');
    const pending = appendLoaderMessage(messages);
    setLoading(element, true);
    let result = getResultElement(element, false);
    const resultExit = result ? hideResult(result) : Promise.resolve();

    try {
      const csrfToken = await getCsrfToken();
      const requestPayload = {
        message,
        thread_id: element.dataset.threadId || null,
      };
      let data = {};
      let response = null;

      for (let attempt = 0; attempt < 2; attempt++) {
        response = await fetch(endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-Token': csrfToken,
          },
          body: JSON.stringify(requestPayload),
        });
        data = await parseJsonResponse(response);

        if (response.ok) {
          break;
        }

        if (attempt === 0 && data.error === 'Invalid thread ID.' && requestPayload.thread_id) {
          requestPayload.thread_id = null;
          element.dataset.threadId = '';
          setStoredThreadId('');
          continue;
        }

        const requestError = new Error(data.reply || data.error || Drupal.t('The assistant could not process the request.'));
        requestError.accessDenied = response.status === 403 && Boolean(data.reply);
        throw requestError;
      }

      element.dataset.threadId = data.thread_id || '';
      setStoredThreadId(element.dataset.threadId);
      pending.classList.remove('trotamundos-assistant__message--loading', 'trotamundos-assistant__message--error');
      pending.textContent = data.reply || Drupal.t('No se ha recibido respuesta.');
      appendResponsiveResultButton(element, pending, data.btn_responsive_title);
      await resultExit;

      if (data.keep_result) {
        // Off-topic answer: the result panel keeps the previous turn intact.
        showResult(getResultElement(element, false));
      }
      else {
        result = getResultElement(element);
        await setResultPayload(element, result, data);
      }
    }
    catch (error) {
      if (result) {
        result.classList.remove('is-entering', 'is-exiting');
      }
      pending.classList.remove('trotamundos-assistant__message--loading');
      pending.textContent = error.message;
      if (!error.accessDenied) {
        pending.classList.add('trotamundos-assistant__message--error');
      }
    }
    finally {
      setLoading(element, false);
    }
  }

  Drupal.behaviors.trotamundosAssistantLayout = {
    attach(context) {
      const elements = once('trotamundos-assistant-layout', '.trotamundos-assistant', context);

      elements.forEach((element) => {
        refreshLayout(element, true);
        observeStickyHeader(element);

        window.addEventListener('resize', () => {
          window.requestAnimationFrame(() => {
            refreshLayout(element);
          });
        });

        window.addEventListener('scroll', () => {
          schedulePositionRefresh(element);
        }, {passive: true});

        document.addEventListener('drupalViewportOffsetChange', () => {
          schedulePositionRefresh(element);
        });
      });
    },
  };

  function attachWelcomePrompts(element, context) {
    const form = element.querySelector('.trotamundos-assistant__form');
    const input = element.querySelector('.trotamundos-assistant__input');
    if (!form || !input) {
      return;
    }

    once('trotamundos-assistant-welcome-prompt', '[data-trotamundos-assistant-prompt]', context).forEach((button) => {
      button.addEventListener('click', () => {
        const prompt = button.dataset.trotamundosAssistantPrompt || '';
        if (!prompt || input.disabled) {
          return;
        }

        input.value = prompt;
        if (form.requestSubmit) {
          form.requestSubmit();
          return;
        }

        form.dispatchEvent(new Event('submit', {
          bubbles: true,
          cancelable: true,
        }));
      });
    });
  }

  Drupal.trotamundosAssistant.getResultElement = getResultElement;
  Drupal.trotamundosAssistant.setResultHtml = setResultHtml;

  Drupal.behaviors.trotamundosAssistantChat = {
    attach(context) {
      patchEmbeddedFormAjaxCommands();

      const elements = once('trotamundos-assistant-chat', '.trotamundos-assistant', context);

      elements.forEach((element) => {
        const form = element.querySelector('.trotamundos-assistant__form');
        const input = element.querySelector('.trotamundos-assistant__input');
        const reset = element.querySelector('.trotamundos-assistant__reset');
        const toggle = element.querySelector('.trotamundos-assistant__toggle');
        const close = element.querySelector('.trotamundos-assistant__close');

        if (!form || !input) {
          return;
        }

        const target = validateResultTarget(element);
        if (target && typeof element.trotamundosAssistantDefaultResultHtml === 'undefined') {
          element.trotamundosAssistantDefaultResultHtml = target.innerHTML;
        }

        loadHistory(element);

        if (toggle) {
          toggle.addEventListener('click', () => {
            setChatOpen(element, !element.classList.contains('is-chat-open'));
          });
        }

        if (close) {
          close.addEventListener('click', () => {
            setChatOpen(element, false);
          });
        }

        document.addEventListener('trotamundosAssistantResultUpdated', (event) => {
          attachWelcomePrompts(element, event.target);
        });

        form.addEventListener('submit', (event) => {
          event.preventDefault();

          if (!validateResultTarget(element)) {
            return;
          }

          const message = input.value.trim();
          if (!message) {
            return;
          }

          input.value = '';
          submitMessage(element, message);
        });

        input.addEventListener('keydown', (event) => {
          if (event.key !== 'Enter' || event.isComposing) {
            return;
          }

          // Ctrl/Cmd/Shift + Enter inserts a newline instead of sending.
          if (event.ctrlKey || event.metaKey || event.shiftKey) {
            event.preventDefault();
            const start = input.selectionStart;
            const end = input.selectionEnd;
            input.value = `${input.value.slice(0, start)}\n${input.value.slice(end)}`;
            input.selectionStart = start + 1;
            input.selectionEnd = start + 1;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            return;
          }

          event.preventDefault();

          if (form.requestSubmit) {
            form.requestSubmit();
            return;
          }

          form.dispatchEvent(new Event('submit', {
            bubbles: true,
            cancelable: true,
          }));
        });

        if (reset) {
          reset.addEventListener('click', () => {
            resetConversation(element);
          });
        }

        attachWelcomePrompts(element, document);
      });
    },
  };

})(Drupal, drupalSettings, once);
