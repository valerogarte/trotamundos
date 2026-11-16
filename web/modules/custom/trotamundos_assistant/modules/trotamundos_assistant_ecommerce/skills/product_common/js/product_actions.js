(function (Drupal, drupalSettings, once) {
  'use strict';

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

  // Badge inline style mirrors commerce-cart-block.html.twig so a badge created
  // client-side (0 -> N) matches one rendered server-side.
  // ponytail: duplicated style string; move to a CSS class if the badge is
  // ever restyled in more than one place.
  const CART_BADGE_STYLE = 'position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px; padding: 0 4px; color: #fff; font-size: 10px; font-weight: 700; line-height: 18px; text-align: center; border-radius: 9999px;';

  // Inject the server-rendered Drupal status messages so the AJAX confirmation
  // is identical to the native one (same theme template, markup and CSS). The
  // server already drained the messenger queue, so it won't repeat on reload.
  function showServerMessage(html) {
    if (!html) {
      return;
    }

    const tmp = document.createElement('div');
    tmp.innerHTML = html.trim();
    const incoming = tmp.querySelector('[data-drupal-messages]');
    if (!incoming) {
      return;
    }

    const existing = document.querySelector('[data-drupal-messages]');
    if (existing) {
      incoming.querySelectorAll('[data-message-item]').forEach((item) => existing.appendChild(item));
      Drupal.attachBehaviors(existing);
    }
    else {
      document.body.appendChild(incoming);
      Drupal.attachBehaviors(incoming);
    }
  }

  // Swap the header cart block with the server-rendered markup so the dropdown
  // shows the new line item, not just an updated counter.
  function replaceCartBlock(html) {
    const current = document.querySelector('.js-commerce-cart-block');
    if (!current) {
      return;
    }

    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    const fresh = tmp.querySelector('.js-commerce-cart-block') || tmp.firstElementChild;
    if (!fresh) {
      return;
    }

    current.replaceWith(fresh);
    Drupal.attachBehaviors(fresh);
  }

  function updateCartCount(count) {
    if (!Number.isFinite(count)) {
      return;
    }

    document.querySelectorAll('.cart-block--link__expand').forEach((link) => {
      let badge = link.querySelector('.cart-block--summary__count');

      if (count > 0) {
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'cart-block--summary__count bg-primary';
          badge.setAttribute('style', CART_BADGE_STYLE);
          badge.setAttribute('aria-hidden', 'true');
          link.appendChild(badge);
        }
        badge.textContent = count > 99 ? '99+' : String(count);
      }
      else if (badge) {
        badge.remove();
      }

      link.setAttribute('aria-label', count > 0
        ? Drupal.t('Carrito (@count)', { '@count': count })
        : Drupal.t('Carrito'));
    });
  }

  function attachProductActions(context) {
    once('trotamundos-assistant-favorite', '[data-minsait-skill-favorite-toggle]', context).forEach((button) => {
      const setFavoriteState = (isFavorite) => {
        button.dataset.isFavorite = isFavorite ? '1' : '0';
        button.setAttribute('aria-pressed', isFavorite ? 'true' : 'false');
        button.setAttribute('aria-label', isFavorite ? Drupal.t('Quitar de favoritos') : Drupal.t('Añadir a favoritos'));
        button.classList.toggle('is-favorite', isFavorite);

        const icon = button.querySelector('span');
        if (icon) {
          icon.textContent = isFavorite ? '♥' : '♡';
        }
      };

      setFavoriteState(button.dataset.isFavorite === '1');

      button.addEventListener('click', async (event) => {
        event.preventDefault();

        if (button.disabled || button.dataset.isSubmitting === 'true') {
          return;
        }

        const favoriteUrl = button.dataset.favoriteUrl || '';
        if (!favoriteUrl) {
          const loginUrl = button.dataset.favoriteLoginUrl || '/user/login';
          const separator = loginUrl.includes('?') ? '&' : '?';
          window.location.href = `${loginUrl}${separator}destination=${encodeURIComponent(window.location.pathname + window.location.search)}`;
          return;
        }

        const previousState = button.dataset.isFavorite === '1';
        button.disabled = true;
        button.dataset.isSubmitting = 'true';
        button.setAttribute('aria-busy', 'true');
        button.classList.add('is-loading');

        try {
          const response = await fetch(favoriteUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              Accept: 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
          });

          if (!response.ok) {
            throw new Error(Drupal.t('No se ha podido actualizar favoritos.'));
          }

          const payload = await response.json();
          setFavoriteState(!!payload.is_favorite);
        }
        catch (error) {
          setFavoriteState(previousState);
        }
        finally {
          button.disabled = false;
          button.dataset.isSubmitting = 'false';
          button.removeAttribute('aria-busy');
          button.classList.remove('is-loading');
        }
      });
    });

    once('trotamundos-assistant-add-to-cart', '[data-minsait-skill-add-to-cart]', context).forEach((button) => {
      button.addEventListener('click', async (event) => {
        event.preventDefault();

        if (button.disabled || button.dataset.isSubmitting === 'true') {
          return;
        }

        const addToCartUrl = button.dataset.addToCartUrl || '';
        const variationId = parseInt(button.dataset.variationId || '0', 10);
        const quantity = Math.max(1, parseInt(button.dataset.quantity || '1', 10) || 1);
        if (!addToCartUrl || !variationId) {
          return;
        }

        const originalText = button.textContent;
        button.disabled = true;
        button.dataset.isSubmitting = 'true';
        button.setAttribute('aria-busy', 'true');
        button.classList.add('is-loading');
        button.textContent = '…';

        try {
          const csrfToken = await getCsrfToken();
          const response = await fetch(addToCartUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              Accept: 'application/json',
              'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify({
              variation_id: variationId,
              quantity,
            }),
          });
          const data = await parseJsonResponse(response);

          if (!response.ok) {
            throw new Error(data.error || Drupal.t('No se ha podido añadir el producto.'));
          }

          button.textContent = '✓';

          if (data.cart_block) {
            replaceCartBlock(data.cart_block);
          }
          else {
            updateCartCount(Number(data.cart_count));
          }

          showServerMessage(data.message_html);
        }
        catch (error) {
          button.textContent = '!';
          window.alert(error.message || Drupal.t('No se ha podido añadir el producto.'));
        }

        window.setTimeout(() => {
          button.disabled = false;
          button.dataset.isSubmitting = 'false';
          button.removeAttribute('aria-busy');
          button.classList.remove('is-loading');
          button.textContent = originalText || '+';
        }, 1200);
      });
    });

    once('trotamundos-assistant-event-add-all', '[data-event-selection-add-all]', context).forEach((button) => {
      const selection = button.closest('.trotamundos-assistant-template--event-product-selection');
      if (!selection) {
        return;
      }

      button.addEventListener('click', async (event) => {
        event.preventDefault();

        if (button.disabled || button.dataset.isSubmitting === 'true') {
          return;
        }

        const productButtons = Array.from(selection.querySelectorAll('[data-minsait-skill-add-to-cart][data-variation-id]'))
          .filter((productButton) => productButton.dataset.addToCartUrl && productButton.dataset.variationId);
        const status = selection.querySelector('[data-event-selection-status]');
        if (!productButtons.length) {
          if (status) {
            status.textContent = Drupal.t('No hay productos disponibles para añadir.');
          }
          return;
        }

        const originalText = button.querySelector('span')?.textContent || button.textContent;
        const arrow = button.querySelector('span:last-child');
        button.disabled = true;
        button.dataset.isSubmitting = 'true';
        button.setAttribute('aria-busy', 'true');
        button.classList.add('is-loading');
        if (button.querySelector('span')) {
          button.querySelector('span').textContent = Drupal.t('Añadiendo…');
        }
        if (arrow) {
          arrow.textContent = '…';
        }
        if (status) {
          status.textContent = Drupal.t('Añadiendo productos…');
        }

        let added = 0;
        let failed = null;
        try {
          const csrfToken = await getCsrfToken();
          for (const productButton of productButtons) {
            const response = await fetch(productButton.dataset.addToCartUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-Token': csrfToken,
              },
              body: JSON.stringify({
                variation_id: parseInt(productButton.dataset.variationId || '0', 10),
                quantity: Math.max(1, parseInt(productButton.dataset.quantity || '1', 10) || 1),
              }),
            });
            const data = await parseJsonResponse(response);
            if (!response.ok) {
              throw new Error(data.error || Drupal.t('No se ha podido añadir un producto.'));
            }

            added++;
            productButton.textContent = '✓';
            productButton.classList.add('is-added');
            if (data.cart_block) {
              replaceCartBlock(data.cart_block);
            }
            else {
              updateCartCount(Number(data.cart_count));
            }
            showServerMessage(data.message_html);
          }
        }
        catch (error) {
          failed = error;
        }

        if (status) {
          status.textContent = failed
            ? Drupal.t('Se han añadido @added de @total productos. @message', {
              '@added': added,
              '@total': productButtons.length,
              '@message': failed.message || Drupal.t('Revisa los productos restantes.'),
            })
            : Drupal.t('Se han añadido @count productos al carrito.', { '@count': added });
        }

        if (button.querySelector('span')) {
          button.querySelector('span').textContent = failed ? Drupal.t('Revisar selección') : Drupal.t('Todo añadido');
        }
        if (arrow) {
          arrow.textContent = failed ? '!' : '✓';
        }
        window.setTimeout(() => {
          button.disabled = false;
          button.dataset.isSubmitting = 'false';
          button.removeAttribute('aria-busy');
          button.classList.remove('is-loading');
          if (button.querySelector('span')) {
            button.querySelector('span').textContent = originalText;
          }
          if (arrow) {
            arrow.textContent = '›';
          }
        }, 1800);
      });
    });

    once('trotamundos-assistant-event-review', '[data-event-selection-review]', context).forEach((button) => {
      button.addEventListener('click', () => {
        const selection = button.closest('.trotamundos-assistant-template--event-product-selection');
        const products = selection?.querySelector('[data-event-selection-products]');
        products?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
  }

  Drupal.trotamundosAssistantProduct = {
    attachProductActions,
    getCsrfToken,
    parseJsonResponse,
    updateCartCount,
  };

  Drupal.behaviors.trotamundosAssistantProductActions = {
    attach: attachProductActions,
  };
})(Drupal, drupalSettings, once);
