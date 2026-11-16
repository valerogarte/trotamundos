(function (Drupal, once) {
  'use strict';

  const RESULT_TRANSITION_MS = 220;

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

  function setResultHtml(result, html) {
    if (!result) {
      return;
    }

    result.innerHTML = html || '';
    Drupal.attachBehaviors(result);
    showResult(result);
  }

  function getAssistantResult(element) {
    return Drupal.trotamundosAssistant?.getResultElement(element) || null;
  }

  async function submitCartItemAction(element, url, payload) {
    const product = Drupal.trotamundosAssistantProduct;
    const result = getAssistantResult(element);
    if (!product || !url || !result) {
      return;
    }

    const csrfToken = await product.getCsrfToken();
    const resultExit = hideResult(result);
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-Token': csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(payload),
    });
    const data = await product.parseJsonResponse(response);

    if (!response.ok) {
      throw new Error(data.error || Drupal.t('No se ha podido actualizar el carrito.'));
    }

    await resultExit;
    setResultHtml(result, data.result_html || '');
    product.updateCartCount(Number(data.cart_count));
  }

  function attachCartPanel(context) {
    once('trotamundos-assistant-cart-panel', '[data-trotamundos-assistant-cart-panel]', context).forEach((link) => {
      link.addEventListener('click', async (event) => {
        const product = Drupal.trotamundosAssistantProduct;
        const panelUrl = link.dataset.cartPanelUrl || '';
        const result = getAssistantResult(link);

        if (!product || !panelUrl || !result) {
          return;
        }

        event.preventDefault();

        if (link.dataset.isSubmitting === 'true') {
          return;
        }

        const originalText = link.textContent;
        link.dataset.isSubmitting = 'true';
        link.setAttribute('aria-busy', 'true');
        link.classList.add('is-loading');
        link.textContent = Drupal.t('Cargando...');

        try {
          const resultExit = hideResult(result);
          const response = await fetch(panelUrl, {
            credentials: 'same-origin',
            headers: {
              Accept: 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
          });
          const data = await product.parseJsonResponse(response);

          if (!response.ok) {
            throw new Error(data.error || Drupal.t('No se ha podido abrir el carrito.'));
          }

          await resultExit;
          setResultHtml(result, data.result_html || '');
          product.updateCartCount(Number(data.cart_count));
        }
        catch (error) {
          window.location.href = link.getAttribute('href') || '/cart';
        }
        finally {
          link.dataset.isSubmitting = 'false';
          link.removeAttribute('aria-busy');
          link.classList.remove('is-loading');
          link.textContent = originalText;
        }
      });
    });
  }

  function attachCartItemActions(context) {
    once('trotamundos-assistant-cart-update-item', '[data-minsait-cart-update-item]', context).forEach((form) => {
      form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (form.dataset.isSubmitting === 'true') {
          return;
        }

        const button = event.submitter || form.querySelector('button[type="submit"]');
        const input = form.querySelector('input[name="quantity"]');
        const parsedQuantity = Number(input?.value || 1);
        const currentQuantity = Number.isFinite(parsedQuantity) ? Math.max(1, parsedQuantity) : 1;
        const delta = Number(button?.value || 0);
        const quantity = Number.isFinite(delta) && delta !== 0
          ? Math.max(1, currentQuantity + delta)
          : currentQuantity;
        const orderItemId = parseInt(form.dataset.orderItemId || '0', 10);
        const updateUrl = form.dataset.updateItemUrl || '';
        if (!orderItemId || !updateUrl || quantity === currentQuantity) {
          return;
        }

        if (input) {
          input.value = String(quantity);
        }

        const originalText = button ? button.textContent : '';
        form.dataset.isSubmitting = 'true';
        form.setAttribute('aria-busy', 'true');
        form.classList.add('is-loading');
        if (button) {
          button.disabled = true;
          button.textContent = '…';
        }

        try {
          await submitCartItemAction(form, updateUrl, {
            order_item_id: orderItemId,
            quantity,
          });
        }
        catch (error) {
          if (button) {
            button.textContent = '!';
          }
          window.setTimeout(() => {
            if (button) {
              button.textContent = originalText;
            }
          }, 1200);
        }
        finally {
          form.dataset.isSubmitting = 'false';
          form.removeAttribute('aria-busy');
          form.classList.remove('is-loading');
          if (button) {
            button.disabled = false;
          }
        }
      });
    });

    once('trotamundos-assistant-cart-remove-item', '[data-minsait-cart-remove-item]', context).forEach((button) => {
      button.addEventListener('click', async (event) => {
        event.preventDefault();

        if (button.disabled || button.dataset.isSubmitting === 'true') {
          return;
        }

        const orderItemId = parseInt(button.dataset.orderItemId || '0', 10);
        const removeUrl = button.dataset.removeItemUrl || '';
        if (!orderItemId || !removeUrl) {
          return;
        }

        const originalText = button.textContent;
        button.disabled = true;
        button.dataset.isSubmitting = 'true';
        button.setAttribute('aria-busy', 'true');
        button.classList.add('is-loading');
        button.textContent = '…';

        try {
          await submitCartItemAction(button, removeUrl, {
            order_item_id: orderItemId,
          });
        }
        catch (error) {
          button.textContent = '!';
          window.setTimeout(() => {
            button.textContent = originalText;
          }, 1200);
        }
        finally {
          button.disabled = false;
          button.dataset.isSubmitting = 'false';
          button.removeAttribute('aria-busy');
          button.classList.remove('is-loading');
        }
      });
    });
  }

  Drupal.behaviors.trotamundosAssistantShoppingCart = {
    attach(context) {
      Drupal.trotamundosAssistantProduct?.attachProductActions(context);
      attachCartPanel(context);
      attachCartItemActions(context);
    },
  };
})(Drupal, once);
