/**
 * @file
 * Show catalog placeholders before the first paint, with a failure fallback.
 */

document.documentElement.classList.add('catalog-initial-loading');
window.setTimeout(() => document.documentElement.classList.remove('catalog-initial-loading'), 6000);
