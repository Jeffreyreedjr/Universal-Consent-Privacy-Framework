/**
 * Consent banner motion (GSAP). Loads after consent.js + gsap vendor.
 * Does not own Accept/Reject — inline boot + consent.js remain authoritative.
 * Skips all tweens when prefers-reduced-motion: reduce.
 */
(function () {
  'use strict';
  if (typeof gsap === 'undefined') return;

  var reduce =
    window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce) return;

  function animatePanel(root, y) {
    if (!root || root.hasAttribute('hidden')) return;
    var p =
      root.querySelector('.ucpf-banner__panel') ||
      root.querySelector('.ucpf-prefs__dialog') ||
      root;
    gsap.fromTo(
      p,
      { opacity: 0, y: y || 18 },
      { opacity: 1, y: 0, duration: 0.4, ease: 'power2.out', overwrite: true }
    );
  }

  function staggerActions(banner) {
    var actions = banner.querySelectorAll('.ucpf-banner__actions .ucpf-btn');
    if (!actions.length) return;
    gsap.fromTo(
      actions,
      { opacity: 0.55, y: 8 },
      { opacity: 1, y: 0, duration: 0.35, stagger: 0.05, ease: 'power2.out', overwrite: true }
    );
  }

  function watch() {
    var banner = document.getElementById('ucpf-banner');
    var prefs = document.getElementById('ucpf-prefs');
    if (banner) {
      var wasVisible = banner.classList.contains('ucpf-banner--visible');
      new MutationObserver(function () {
        var now = banner.classList.contains('ucpf-banner--visible');
        if (now && !wasVisible) {
          animatePanel(banner, 18);
          staggerActions(banner);
        }
        wasVisible = now;
      }).observe(banner, { attributes: true, attributeFilter: ['class', 'hidden'] });
    }
    if (prefs) {
      var wasHidden = prefs.hasAttribute('hidden');
      new MutationObserver(function () {
        var nowHidden = prefs.hasAttribute('hidden');
        if (wasHidden && !nowHidden) {
          animatePanel(prefs, 28);
        }
        wasHidden = nowHidden;
      }).observe(prefs, { attributes: true, attributeFilter: ['hidden', 'class'] });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', watch);
  } else {
    watch();
  }
})();
