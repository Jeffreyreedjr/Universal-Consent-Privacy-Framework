/**
 * Consent banner motion (CSS). Loads after consent.js.
 * Does not own Accept/Reject — inline boot + consent.js remain authoritative.
 * Skips class toggles when prefers-reduced-motion: reduce.
 */
(function () {
  'use strict';

  var reduce =
    window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce) return;

  function animatePanel(root) {
    if (!root || root.hasAttribute('hidden')) return;
    var p =
      root.querySelector('.ucpf-banner__panel') ||
      root.querySelector('.ucpf-prefs__dialog') ||
      root;
    p.classList.remove('ucpf-motion-enter');
    // Force reflow so re-adding the class restarts the animation.
    void p.offsetWidth;
    p.classList.add('ucpf-motion-enter');
  }

  function staggerActions(banner) {
    var actions = banner.querySelectorAll('.ucpf-banner__actions .ucpf-btn');
    if (!actions.length) return;
    actions.forEach(function (btn, i) {
      btn.style.setProperty('--ucpf-motion-delay', 0.05 * i + 's');
      btn.classList.remove('ucpf-motion-stagger');
      void btn.offsetWidth;
      btn.classList.add('ucpf-motion-stagger');
    });
  }

  function watch() {
    var banner = document.getElementById('ucpf-banner');
    var prefs = document.getElementById('ucpf-prefs');
    if (banner) {
      var wasVisible = banner.classList.contains('ucpf-banner--visible');
      new MutationObserver(function () {
        var now = banner.classList.contains('ucpf-banner--visible');
        if (now && !wasVisible) {
          animatePanel(banner);
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
          animatePanel(prefs);
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
