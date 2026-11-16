/**
 * @file
 * Silent, responsive nature film for the hero.
 */

(function (Drupal, once) {
  'use strict';

  const cleanupByRoot = new WeakMap();

  function heroRoots(context) {
    const roots = Array.from(context.querySelectorAll('[data-immersive-hero]'));
    if (context.matches && context.matches('[data-immersive-hero]')) {
      roots.unshift(context);
    }
    return roots;
  }

  function initializeHero(root) {
    const video = root.querySelector('[data-hero-video]');
    if (!video) {
      return;
    }

    const controller = new AbortController();
    const signal = controller.signal;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const mobileViewport = window.matchMedia('(max-width: 639px)');
    let requestedPlayback = !reducedMotion.matches;

    const updateVideoSource = () => {
      const source = mobileViewport.matches ? video.dataset.videoMobile : video.dataset.videoDesktop;
      const poster = mobileViewport.matches ? video.dataset.posterMobile : video.dataset.posterDesktop;
      const currentSource = video.getAttribute('src');
      if (poster) {
        video.poster = poster;
      }
      if (!source || currentSource === source) {
        return;
      }

      const time = Number.isFinite(video.currentTime) ? video.currentTime : 0;
      video.pause();
      video.setAttribute('src', source);
      video.load();
      video.addEventListener('loadedmetadata', () => {
        if (time > 0 && Number.isFinite(video.duration)) {
          video.currentTime = Math.min(time, Math.max(0, video.duration - 0.1));
        }
        if (requestedPlayback && !reducedMotion.matches) {
          video.play().catch(() => {});
        }
      }, { once: true, signal });
    };

    const playVideo = () => {
      video.muted = true;
      video.defaultMuted = true;
      video.volume = 0;
      video.play().catch(() => {});
    };

    mobileViewport.addEventListener('change', updateVideoSource, { signal });
    reducedMotion.addEventListener('change', (event) => {
      if (event.matches) {
        requestedPlayback = false;
        video.pause();
      }
      else {
        requestedPlayback = true;
        playVideo();
      }
    }, { signal });

    video.muted = true;
    video.defaultMuted = true;
    video.volume = 0;
    updateVideoSource();
    if (requestedPlayback) {
      playVideo();
    }

    cleanupByRoot.set(root, () => {
      controller.abort();
      video.pause();
    });
  }

  Drupal.behaviors.trotamundosImmersiveHero = {
    attach(context) {
      once('trotamundos-immersive-hero', heroRoots(context)).forEach(initializeHero);
    },

    detach(context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      once.remove('trotamundos-immersive-hero', heroRoots(context)).forEach((root) => {
        const cleanup = cleanupByRoot.get(root);
        if (cleanup) {
          cleanup();
          cleanupByRoot.delete(root);
        }
      });
    },
  };
})(Drupal, once);
