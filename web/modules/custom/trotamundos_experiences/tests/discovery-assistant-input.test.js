'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../../../themes/custom/trotamundos/js/discovery.js'), 'utf8');

function setup({ visible = true, observe = true, open = visible } = {}) {
  const window = new EventTarget();
  const disclosure = new EventTarget();
  disclosure.open = open;
  const header = new EventTarget();
  const form = new EventTarget();
  const composer = {
    bottom: 0,
    getBoundingClientRect() { return { bottom: this.bottom }; },
  };
  const chat = {
    bottom: 0,
    scrollTop: 0,
    getBoundingClientRect() { return { bottom: this.bottom }; },
  };
  const input = Object.assign(new EventTarget(), {
    form,
    parentElement: composer,
    scrollHeight: 60,
    scrollTop: 0,
    style: { height: '', overflowY: '' },
    value: '',
    visible,
    getClientRects() { return this.visible ? [1] : []; },
    closest(selector) {
      return {
        '[data-discovery-assistant]': disclosure,
        '.trotamundos-assistant__chat': chat,
        '[data-site-header]': header,
      }[selector];
    },
  });
  const document = { activeElement: null };
  const Drupal = { behaviors: {} };
  let attached = false;
  const once = () => {
    if (attached) {
      return [];
    }
    attached = true;
    return [input];
  };
  once.remove = () => {
    if (!attached) {
      return [];
    }
    attached = false;
    return [input];
  };
  let observer;
  class TestResizeObserver {
    constructor(callback) {
      this.callback = callback;
      observer = this;
    }

    observe(target) { this.target = target; }
    disconnect() { this.disconnected = true; }
    resize(width) { this.callback([{ contentRect: { width } }]); }
  }
  vm.runInNewContext(source, {
    Drupal,
    once,
    window,
    document,
    AbortController,
    queueMicrotask,
    ResizeObserver: observe ? TestResizeObserver : undefined,
  });
  const behavior = Drupal.behaviors.trotamundosDiscoveryAssistantInput;
  behavior.attach({});
  return { behavior, input, form, disclosure, header, chat, composer, document, window, observer };
}

test('resizes on opening, typing and deleting without losing the input scroll position', () => {
  const { input, disclosure } = setup({ visible: false });
  assert.equal(input.style.height, '');

  input.visible = true;
  disclosure.open = true;
  disclosure.dispatchEvent(new Event('toggle'));
  assert.equal(input.style.height, '60px');

  input.scrollTop = 18;
  input.scrollHeight = 160;
  input.dispatchEvent(new Event('input'));
  assert.equal(input.style.height, '160px');
  assert.equal(input.scrollTop, 18);

  input.scrollHeight = 60;
  input.dispatchEvent(new Event('input'));
  assert.equal(input.style.height, '60px');
});

test('does not measure the textarea while its disclosure is closed', () => {
  const { input, disclosure, observer } = setup({ open: false });
  input.scrollHeight = 828;
  observer.resize(40);
  assert.equal(input.style.height, '');

  input.scrollHeight = 60;
  disclosure.open = true;
  disclosure.dispatchEvent(new Event('toggle'));
  assert.equal(input.style.height, '60px');
});

test('recalculates after submission and when the available width changes', async () => {
  const { input, form, observer, window } = setup();
  input.value = 'A longer message';
  input.scrollHeight = 160;
  input.dispatchEvent(new Event('input'));
  form.addEventListener('submit', () => {
    input.value = '';
    input.scrollHeight = 60;
  });
  form.dispatchEvent(new Event('submit', { cancelable: true }));
  await Promise.resolve();
  assert.equal(input.style.height, '60px');

  input.scrollHeight = 90;
  observer.resize(300);
  assert.equal(input.style.height, '90px');
  input.scrollHeight = 120;
  observer.resize(300);
  assert.equal(input.style.height, '90px');
  observer.resize(200);
  assert.equal(input.style.height, '120px');

  input.scrollHeight = 75;
  window.dispatchEvent(new Event('resize'));
  assert.equal(input.style.height, '75px');
});

test('keeps the composer visible in a short panel and cleans up its listeners', () => {
  const { behavior, input, chat, composer, document, observer } = setup();
  composer.bottom = 250;
  chat.bottom = 200;
  document.activeElement = input;
  input.dispatchEvent(new Event('input'));
  assert.equal(chat.scrollTop, 50);

  document.activeElement = null;
  input.dispatchEvent(new Event('input'));
  assert.equal(chat.scrollTop, 50);

  behavior.detach({}, {}, 'unload');
  assert.equal(observer.disconnected, true);
  assert.equal(input.style.height, '');
  input.scrollHeight = 100;
  input.dispatchEvent(new Event('input'));
  assert.equal(input.style.height, '');
});

test('recalculates after header transitions without ResizeObserver', () => {
  const { input, header } = setup({ observe: false });
  input.scrollHeight = 110;
  header.dispatchEvent(new Event('transitionend'));
  assert.equal(input.style.height, '110px');
});
