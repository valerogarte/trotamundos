(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.trotamundos3Guide = {
    attach(context) {
      once('tm3-guide', '[data-tm3-guide]', context).forEach((root) => {
        const status = root.querySelector('[data-g3-status]');
        root.querySelectorAll('[data-g3-copy]').forEach((button) => {
          button.addEventListener('click', async () => {
            const value = button.dataset.g3Copy;
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
        root.querySelector('[data-g3-print]')?.addEventListener('click', () => window.print());
        const phrase = root.querySelector('[data-g3-phrase]');
        const style = root.querySelector('[data-g3-style]');
        const update = () => {
          const text = phrase.value.trim() ? phrase.value.slice(0, 80) : 'Hay viajes que se quedan contigo.';
          root.querySelectorAll('[data-g3-specimen]').forEach((sample) => {
            sample.textContent = text;
            if (sample.dataset.g3Specimen === 'display') {
              const italic = style.value === 'italic';
              sample.style.fontStyle = italic ? 'italic' : 'normal';
              sample.style.fontWeight = italic ? '400' : '500';
            }
          });
        };
        phrase?.addEventListener('input', update);
        style?.addEventListener('change', update);
      });
    },
  };
})(Drupal, once);
