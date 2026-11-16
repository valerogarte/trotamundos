(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.trotamundos2Guide = {
    attach(context) {
      once('tm2-guide', '[data-tm2-guide]', context).forEach((root) => {
        const status = root.querySelector('[data-tm2-status]');
        const announce = (message) => { if (status) status.textContent = message; };
        root.querySelectorAll('[data-tm2-copy]').forEach((button) => {
          button.addEventListener('click', async () => {
            const value = button.dataset.tm2Copy;
            let copied = false;
            if (!/^#[0-9a-f]{6}$/i.test(value)) return;
            if (window.isSecureContext && navigator.clipboard) {
              try { await navigator.clipboard.writeText(value); copied = true; } catch (error) { /* Try the selection fallback. */ }
            }
            if (!copied && document.execCommand) {
              const input = document.createElement('textarea');
              input.value = value;
              input.setAttribute('readonly', '');
              input.setAttribute('tabindex', '-1');
              input.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0';
              document.body.append(input);
              try { input.select(); copied = document.execCommand('copy') === true; } catch (error) { copied = false; }
              finally { input.remove(); button.focus({ preventScroll: true }); }
            }
            announce(copied ? Drupal.t('Color @color copiado.', { '@color': value }) : Drupal.t('No se pudo copiar. Selecciona el código y cópialo manualmente.'));
          });
        });
        root.querySelector('[data-tm2-print]')?.addEventListener('click', () => window.print());
        const text = root.querySelector('[data-tm2-type]');
        const weight = root.querySelector('[data-tm2-weight]');
        const update = () => {
          const phrase = text.value.trim() ? text.value.slice(0, 70) : 'Que el viaje te mueva.';
          root.querySelectorAll('[data-tm2-specimen]').forEach((sample) => {
            sample.textContent = phrase;
            if (sample.dataset.tm2Specimen === 'display') sample.style.fontWeight = ['700', '800'].includes(weight.value) ? weight.value : '800';
          });
        };
        text?.addEventListener('input', update);
        weight?.addEventListener('change', update);
      });
    },
  };
})(Drupal, once);
