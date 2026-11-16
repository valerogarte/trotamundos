(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.trotamundos4Guide = {
    attach(context) {
      once('tm4-guide', '[data-tm4-guide]', context).forEach((root) => {
        const status = root.querySelector('[data-g4-status]');
        root.querySelectorAll('[data-g4-copy]').forEach((button) => {
          button.addEventListener('click', async () => {
            const value = button.dataset.g4Copy;
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
            if (status) status.textContent = copied ? Drupal.t('Color @color copiado.', { '@color': value }) : Drupal.t('No se pudo copiar. Selecciona el código y cópialo manualmente.');
          });
        });
        root.querySelector('[data-g4-print]')?.addEventListener('click', () => window.print());
        const phrase = root.querySelector('[data-g4-phrase]');
        phrase?.addEventListener('input', () => {
          const text = phrase.value.trim() ? phrase.value.slice(0, 75) : 'Colombia, por descubrir.';
          root.querySelectorAll('[data-g4-specimen]').forEach((sample) => { sample.textContent = text; });
        });
      });
    },
  };
})(Drupal, once);
