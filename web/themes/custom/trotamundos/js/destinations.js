/**
 * @file
 * Highlights the matching map marker and directory link.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.trotamundosDestinationAtlas = {
    attach(context) {
      once('trotamundos-destination-atlas', '[data-destination-atlas]', context).forEach((atlas) => {
        const links = Array.from(atlas.querySelectorAll('[data-atlas-place]'));
        let hovered = null;
        let focused = null;

        const placeOf = (target) => {
          const link = target instanceof Element ? target.closest('[data-atlas-place]') : null;
          return link && atlas.contains(link) ? link.dataset.atlasPlace : null;
        };
        const update = () => {
          const active = focused || hovered;
          links.forEach((link) => link.classList.toggle('is-active', active !== null && link.dataset.atlasPlace === active));
        };

        atlas.addEventListener('pointerover', (event) => {
          hovered = placeOf(event.target);
          update();
        });
        atlas.addEventListener('pointerout', (event) => {
          hovered = placeOf(event.relatedTarget);
          update();
        });
        atlas.addEventListener('focusin', (event) => {
          focused = placeOf(event.target);
          update();
        });
        atlas.addEventListener('focusout', (event) => {
          focused = placeOf(event.relatedTarget);
          update();
        });
      });
    },
  };
})(Drupal, once);
