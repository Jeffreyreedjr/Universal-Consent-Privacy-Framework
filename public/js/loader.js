(function () {
  'use strict';

  var loaded = {};
  var config = window.ucpfConfig || {};
  var mapsterForceDone = false;
  var embedRefireTimersScheduled = false;
  var scanPlaceholdersRunning = false;
  /** @type {Object.<string, boolean>} */
  var smartSliderSrcActivated = {};

  function alreadyManaged(key) {
    // Set by PHP when Integrations tags were already enqueued for this page (returning consent).
    var list = window.ucpfManagedLoaded || [];
    return list.indexOf(key) !== -1;
  }

  function hasConsentForCategory(category) {
    return window.UCPF && window.UCPF.hasConsent(category);
  }

  function isVideoEmbedUrl(url) {
    var u = String(url || '').toLowerCase();
    if (!u) {
      return false;
    }
    return (
      u.indexOf('youtube.com/embed') !== -1 ||
      u.indexOf('youtube-nocookie.com') !== -1 ||
      u.indexOf('youtu.be/') !== -1 ||
      u.indexOf('player.vimeo.com') !== -1 ||
      u.indexOf('vimeo.com/video') !== -1 ||
      u.indexOf('vimeocdn.com') !== -1 ||
      u.indexOf('arclight.vimeo.com') !== -1
    );
  }

  function resolveDeferredIframeUrl(node) {
    if (typeof window.__ucpfResolveDeferredEmbedUrl === 'function') {
      return window.__ucpfResolveDeferredEmbedUrl(node);
    }
    if (!node) {
      return '';
    }
    var parked = node.getAttribute('data-src') || node.getAttribute('data-lazy-src') || '';
    var live = node.getAttribute('src') || '';
    var isPlaceholder = function (u) {
      var s = String(u || '').trim().toLowerCase();
      return !s || s === 'about:blank' || s.indexOf('data:') === 0 || s.indexOf('blob:') === 0;
    };
    if (parked && !isPlaceholder(parked) && isPlaceholder(live)) {
      return parked;
    }
    if (live && !isPlaceholder(live)) {
      return live;
    }
    return parked || live || '';
  }

  /** Third-party map / tile / geocoder APIs that widgets depend on. */
  function isMapApiUrl(url) {
    var u = String(url || '').toLowerCase();
    if (!u) {
      return false;
    }
    return (
      /maps\.googleapis\.com\/maps\/api\/js/i.test(u) ||
      /maps\.googleapis\.com\/maps\/api\/js\?/i.test(u) ||
      u.indexOf('maps.googleapis.com/maps/api/js') !== -1 ||
      /api\.mapbox\.com\/mapbox-gl-js/i.test(u) ||
      /api\.mapbox\.com\/mapbox\.js/i.test(u) ||
      /unpkg\.com\/maplibre-gl/i.test(u) ||
      /cdn\.jsdelivr\.net\/npm\/maplibre-gl/i.test(u) ||
      /cdn\.jsdelivr\.net\/npm\/leaflet/i.test(u)
    );
  }

  /** GSAP plugins (ScrollTrigger, ScrollToPlugin, Draggable, etc.) — after core. */
  function isGsapPluginUrl(url) {
    var u = String(url || '').toLowerCase();
    if (!u) {
      return false;
    }
    return (
      u.indexOf('scrolltrigger') !== -1 ||
      u.indexOf('scrolltoplugin') !== -1 ||
      u.indexOf('draggable.min.js') !== -1 ||
      u.indexOf('draggable.js') !== -1 ||
      u.indexOf('splittext') !== -1 ||
      u.indexOf('motionpathplugin') !== -1 ||
      u.indexOf('flip.min.js') !== -1 ||
      /\/gsap(@[\w.-]+)?\/dist\/(?!gsap(\.min)?\.js)/i.test(u) ||
      /gsap\/.*plugin/i.test(u)
    );
  }

  /** GSAP core bundles only (not ScrollTrigger / ScrollToPlugin / etc.). */
  function isGsapCoreUrl(url) {
    var u = String(url || '').toLowerCase();
    if (!u) {
      return false;
    }
    if (isGsapPluginUrl(u)) {
      return false;
    }
    return (
      /\/gsap(@[\w.-]+)?\/dist\/gsap(\.min)?\.js/i.test(u) ||
      /\/gsap(\.min)?\.js(\?|#|$)/i.test(u) ||
      /cdn\.jsdelivr\.net\/npm\/gsap(@[\w.-]+)?\/dist\/gsap/i.test(u) ||
      /cdnjs\.cloudflare\.com\/ajax\/libs\/gsap\/[^"'?\s]+\/gsap/i.test(u) ||
      /unpkg\.com\/gsap(@[\w.-]+)?\/dist\/gsap/i.test(u)
    );
  }

  /** Lottie / DotLottie / lottie-web player scripts. */
  function isLottieApiUrl(url) {
    var u = String(url || '').toLowerCase();
    if (!u) {
      return false;
    }
    return (
      u.indexOf('unpkg.com/@lottiefiles') !== -1 ||
      u.indexOf('cdn.jsdelivr.net/npm/@lottiefiles') !== -1 ||
      u.indexOf('cdn.jsdelivr.net/npm/lottie-web') !== -1 ||
      u.indexOf('unpkg.com/lottie-web') !== -1 ||
      u.indexOf('bodymovin') !== -1 ||
      u.indexOf('lottiefiles.com') !== -1 ||
      u.indexOf('dotlottie') !== -1 ||
      u.indexOf('lottie-player') !== -1 ||
      u.indexOf('dotlottie-wc') !== -1 ||
      u.indexOf('dotlottie-player') !== -1
    );
  }

  /** Presentation animation CDNs — never consent-gate (Elementor document order). */
  function isPresentationLibUrl(url) {
    if (typeof window.__ucpfIsPresentationLibUrl === 'function') {
      return window.__ucpfIsPresentationLibUrl(url);
    }
    return isGsapCoreUrl(url) || isGsapPluginUrl(url) || isLottieApiUrl(url);
  }

  /** Theme / builder bundles that expect gsap / lottie globals. */
  function isAnimationDependentUrl(url) {
    var u = String(url || '').toLowerCase();
    if (!u) {
      return false;
    }
    return (
      /\/animations?\//i.test(u) ||
      /\/gsap\//i.test(u) ||
      /elementor.*animation/i.test(u) ||
      /theme.*animation/i.test(u)
    );
  }

  /** Nextend Smart Slider 3 frontend scripts (custom element registrars). */
  function isSmartSliderScript(url) {
    var u = String(url || '').toLowerCase();
    if (!u) {
      return false;
    }
    return (
      u.indexOf('/smart-slider-3/') !== -1 ||
      u.indexOf('/smart-slider-3-pro/') !== -1 ||
      u.indexOf('n2.min.js') !== -1 ||
      u.indexOf('smartslider-frontend') !== -1 ||
      /\/ss3[-.]/i.test(u)
    );
  }

  function smartSliderAlreadyDefined() {
    try {
      if (typeof customElements === 'undefined' || typeof customElements.get !== 'function') {
        return false;
      }
      return !!(
        customElements.get('ss3-force-full-width') ||
        customElements.get('ss3-fullpage') ||
        customElements.get('ss3-loader')
      );
    } catch (eCe) {
      return false;
    }
  }

  /** Normalize script URL for dedupe (strip query/hash). */
  function scriptSrcKey(url) {
    var u = String(url || '').split('#')[0].split('?')[0].toLowerCase();
    return u;
  }

  /** True when a live (non-parked) script with the same file is already in the document. */
  function hasLiveScriptSrc(url) {
    var key = scriptSrcKey(url);
    if (!key) {
      return false;
    }
    try {
      var nodes = document.querySelectorAll('script[src]');
      for (var i = 0; i < nodes.length; i++) {
        var node = nodes[i];
        var type = (node.getAttribute('type') || '').toLowerCase();
        if (type === 'text/plain' || node.getAttribute('data-ucpf-gated') === '1') {
          continue;
        }
        var src = node.getAttribute('src') || '';
        if (scriptSrcKey(src) === key) {
          return true;
        }
        // Same Nextend bundle under a different query string.
        if (isSmartSliderScript(url) && isSmartSliderScript(src) && scriptSrcKey(src).indexOf(key.slice(key.lastIndexOf('/') + 1)) !== -1) {
          return true;
        }
      }
    } catch (eLive) { /* ignore */ }
    return false;
  }

  function clearSmartSliderGateAttrs(node) {
    try {
      node.removeAttribute('data-ucpf-gated');
      node.removeAttribute('data-ucpf-category');
      node.removeAttribute('data-ucpf-service');
    } catch (eSkip) { /* ignore */ }
  }

  /** First-party / plugin scripts that expect google.maps / mapboxgl after API load. */
  function isMapDependentScriptUrl(url) {
    var u = String(url || '').toLowerCase();
    if (!u) {
      return false;
    }
    return (
      /wpgmza|wp-google-maps|wpgmaps|mapster|google-maps-builder|agm_google|flexible-map|ultimate-maps|wp-map-block|gmapn|osmapper|open.?street.?map/i.test(
        u
      ) ||
      /\/plugins\/[^"'?\s]*map[^"'?\s]*\.js/i.test(u)
    );
  }

  function isPayPalSdkUrl(url) {
    var u = String(url || '').toLowerCase();
    return u.indexOf('paypal.com/sdk') !== -1 || u.indexOf('paypalobjects.com') !== -1;
  }

  function canActivateUrl(url, category) {
    // Accessibility toolbar — always allow even if older HTML stamped functional/preferences.
    if (isUserWayUrl(url)) {
      return true;
    }
    // GSAP / Lottie / AOS — never gated (layout/motion for Elementor custom code).
    if (isPresentationLibUrl(url)) {
      return true;
    }
    if (typeof window.__ucpfNeedsMarketingAndEmbeds === 'function' && window.__ucpfNeedsMarketingAndEmbeds(url)) {
      return hasConsentForCategory('marketing') && hasConsentForCategory('functional');
    }
    if (isVideoEmbedUrl(url) || isMapApiUrl(url)) {
      return hasConsentForCategory('marketing') && hasConsentForCategory('functional');
    }
    if (category) {
      return hasConsentForCategory(category);
    }
    return true;
  }

  /** Accessibility toolbar CDN — never leave parked after Reject / category deny. */
  function isUserWayUrl(url) {
    var u = String(url || '').toLowerCase();
    return (
      u.indexOf('cdn.userway.org') !== -1 ||
      u.indexOf('api.userway.org') !== -1 ||
      u.indexOf('userway.org') !== -1
    );
  }

  function classifyUrl(url) {
    if (typeof window.__ucpfClassifyUrl === 'function') {
      return window.__ucpfClassifyUrl(url);
    }
    return null;
  }

  /** Invoke Google Maps API callback= param after script load. */
  function invokeGoogleMapsCallback(src) {
    try {
      var m = String(src || '').match(/[?&]callback=([^&]+)/i);
      if (!m || !m[1]) {
        return;
      }
      var cb = decodeURIComponent(m[1].replace(/\+/g, ' '));
      if (cb && typeof window[cb] === 'function') {
        window[cb]();
      }
    } catch (eCb) { /* ignore */ }
  }

  var gsapLoadPollScheduled = false;

  var inlineAnimationRe = /\bgsap\b|ScrollTrigger|lottie\.loadAnimation|dotlottie|bodymovin/i;

  /**
   * Re-run inline animation init after DOMContentLoaded has already fired.
   * Cloning <script> alone does not re-fire DOMContentLoaded / jQuery.ready callbacks.
   */
  function execInlineAnimationScript(text) {
    var code = String(text || '');
    if (!code.trim()) {
      return;
    }
    var domReady = document.readyState !== 'loading';
    var queued = [];
    var nativeDocAdd = document.addEventListener;
    var nativeWinAdd = window.addEventListener;
    var nativeJqReady = null;

    function hijackAddListener(nativeFn, defaultTarget) {
      return function (type, listener, options) {
        if (
          domReady &&
          listener &&
          typeof listener === 'function' &&
          (type === 'DOMContentLoaded' || type === 'load')
        ) {
          queued.push({ fn: listener, target: defaultTarget, type: type });
          return;
        }
        return nativeFn.call(this, type, listener, options);
      };
    }

    if (domReady) {
      document.addEventListener = hijackAddListener(nativeDocAdd, document);
      window.addEventListener = hijackAddListener(nativeWinAdd, window);
      if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.ready === 'function') {
        nativeJqReady = window.jQuery.fn.ready;
        window.jQuery.fn.ready = function (fn) {
          if (typeof fn === 'function') {
            queued.push({ fn: fn, target: document, type: 'DOMContentLoaded' });
            return this;
          }
          return nativeJqReady.apply(this, arguments);
        };
      }
    }
    try {
      var script = document.createElement('script');
      script.setAttribute('data-ucpf-animation-refire', '1');
      script.text = code;
      (document.head || document.documentElement).appendChild(script);
    } finally {
      if (domReady) {
        document.addEventListener = nativeDocAdd;
        window.addEventListener = nativeWinAdd;
        if (nativeJqReady) {
          window.jQuery.fn.ready = nativeJqReady;
        }
        queued.forEach(function (item) {
          try {
            item.fn.call(item.target, new Event(item.type));
          } catch (eRun) { /* ignore */ }
        });
      }
    }
  }

  /** Activate only previously parked inline animation scripts (never re-run live Elementor code). */
  function refireInlineAnimationScripts() {
    try {
      document
        .querySelectorAll('script[type="text/plain"][data-ucpf-inline-animation="1"]')
        .forEach(function (node) {
          var parked = node.textContent || '';
          if (!inlineAnimationRe.test(parked)) {
            return;
          }
          node.removeAttribute('data-ucpf-inline-animation');
          node.removeAttribute('data-ucpf-gated');
          try {
            node.type = 'text/javascript';
          } catch (eType) { /* ignore */ }
          execInlineAnimationScript(parked);
        });
    } catch (eParkedInline) { /* ignore */ }
  }

  /** Poll until GSAP / Lottie globals exist, then refire dependents. */
  function whenAnimationLibsReady(cb) {
    var n = 0;
    var timer = window.setInterval(function () {
      n += 1;
      var ready =
        typeof window.gsap !== 'undefined' ||
        typeof window.lottie !== 'undefined' ||
        typeof window.bodymovin !== 'undefined';
      if (ready || n > 60) {
        window.clearInterval(timer);
        cb();
      }
    }, 100);
  }

  function scheduleAnimationLibPollRefire() {
    if (gsapLoadPollScheduled) {
      return;
    }
    gsapLoadPollScheduled = true;
    whenAnimationLibsReady(function () {
      refireAnimationDependents();
    });
  }

  /** Activate parked GSAP / Lottie API tags before other placeholders (capture-phase boot). */
  function activateParkedAnimationApis() {
    try {
      collectParkedScripts().forEach(function (node) {
        var src = node.getAttribute('data-src') || '';
        if (isGsapCoreUrl(src) || isGsapPluginUrl(src) || isLottieApiUrl(src)) {
          activateScript(node);
        }
      });
    } catch (eEarly) { /* ignore */ }
  }

  /** Nudge live GSAP script tags to load if global is still missing after consent. */
  function promoteLiveGsapScripts() {
    if (typeof window.gsap !== 'undefined') {
      return;
    }
    try {
      var nodes = document.querySelectorAll(
        'script[src*="gsap"], script[data-src*="gsap"], script[src*="scrolltrigger"], script[data-src*="scrolltrigger"], script[src*="ScrollTrigger"], script[data-src*="ScrollTrigger"]'
      );
      nodes.forEach(function (old) {
        var src = old.getAttribute('data-src') || old.getAttribute('src') || '';
        if (!src) {
          return;
        }
        if (!isGsapCoreUrl(src) && !isGsapPluginUrl(src) && !/\/gsap(\.min)?\.js/i.test(src)) {
          return;
        }
        if (old.getAttribute('type') === 'text/plain' || old.getAttribute('data-ucpf-gated') === '1') {
          activateScript(old);
          return;
        }
        if (!old.getAttribute('data-ucpf-gsap-watched')) {
          old.setAttribute('data-ucpf-gsap-watched', '1');
          old.addEventListener('load', function () {
            refireAnimationDependents();
          });
          old.addEventListener('error', function () {
            window.setTimeout(refireAnimationDependents, 200);
          });
        }
        if (hasLiveScriptSrc(src)) {
          return;
        }
        var fresh = document.createElement('script');
        fresh.src = src;
        fresh.async = false;
        fresh.setAttribute('data-ucpf-gsap-promote', '1');
        fresh.addEventListener('load', function () {
          refireAnimationDependents();
        });
        fresh.addEventListener('error', function () {
          window.setTimeout(refireAnimationDependents, 200);
        });
        (document.head || document.documentElement).insertBefore(
          fresh,
          (document.head || document.documentElement).firstChild
        );
      });
    } catch (ePromote) { /* ignore */ }
  }

  /** Wake Lottie / DotLottie player elements after consent. */
  function playLottiePlayers() {
    try {
      document.querySelectorAll('dotlottie-player, lottie-player, dotlottie-wc').forEach(function (el) {
        try {
          if (typeof el.play === 'function') {
            el.play();
          }
        } catch (ePlay) { /* ignore */ }
      });
    } catch (eLottie) { /* ignore */ }
  }

  /** After GSAP / Lottie load: activate any leftover parked tags only — never re-clone live CDN scripts. */
  function refireAnimationDependents() {
    var parkedAnim = false;
    try {
      document
        .querySelectorAll(
          'script[type="text/plain"][data-src], script[data-ucpf-gated="1"][data-src], script[data-ucpf-category][data-src]'
        )
        .forEach(function (node) {
          var src = node.getAttribute('data-src') || '';
          if (isGsapCoreUrl(src) || isGsapPluginUrl(src) || isLottieApiUrl(src) || isAnimationDependentUrl(src)) {
            parkedAnim = true;
            activateScript(node);
          }
        });
    } catch (eParkedAnim) { /* ignore */ }

    var hasParkedInline = !!document.querySelector(
      'script[type="text/plain"][data-ucpf-inline-animation="1"]'
    );
    if (hasParkedInline) {
      refireInlineAnimationScripts();
    }

    var hasGsap = typeof window.gsap !== 'undefined';
    var hasLottie = typeof window.lottie !== 'undefined' || typeof window.bodymovin !== 'undefined';

    if (!hasGsap && !hasLottie && (parkedAnim || hasParkedInline)) {
      scheduleAnimationLibPollRefire();
      return;
    }

    // Do NOT refireDependentScripts(/gsap/) — cloning live jsDelivr tags breaks Elementor ScrollTrigger pins.
    if (hasGsap && (parkedAnim || hasParkedInline)) {
      try {
        if (window.ScrollTrigger && typeof window.ScrollTrigger.refresh === 'function') {
          window.ScrollTrigger.refresh();
        }
      } catch (eSt) { /* ignore */ }
    }
    playLottiePlayers();
    try {
      window.dispatchEvent(new CustomEvent('ucpf:animations:ready'));
    } catch (eEvAnim) { /* ignore */ }
  }

  function activateScript(node) {
    var src = node.getAttribute('data-src') || '';
    var category = node.getAttribute('data-ucpf-category');
    var service = node.getAttribute('data-ucpf-service');

    if (!canActivateUrl(src, category)) {
      dispatchBlocked(service || category);
      return;
    }

    // Smart Slider / Nextend: custom elements can only be defined once. Never re-exec
    // parked n2/ss3 scripts when CE already exists, a live copy is in the DOM, or we
    // already activated this src this page (duplicate parked nodes race-load otherwise).
    if (isSmartSliderScript(src)) {
      var srcKey = scriptSrcKey(src);
      if (
        smartSliderAlreadyDefined() ||
        hasLiveScriptSrc(src) ||
        (srcKey && smartSliderSrcActivated[srcKey])
      ) {
        clearSmartSliderGateAttrs(node);
        return;
      }
      if (srcKey) {
        smartSliderSrcActivated[srcKey] = true;
      }
    }

    var script = document.createElement('script');
    Array.prototype.slice.call(node.attributes || []).forEach(function (attr) {
      if (!attr || !attr.name) return;
      if (attr.name === 'type' || attr.name === 'data-src' || attr.name === 'src') return;
      if (
        attr.name === 'data-ucpf-category' ||
        attr.name === 'data-ucpf-service' ||
        attr.name === 'data-ucpf-gated' ||
        attr.name === 'data-ucpf-original-type'
      ) {
        return;
      }
      try {
        script.setAttribute(attr.name, attr.value);
      } catch (eAttr) { /* ignore invalid / empty names */ }
    });
    if (src) {
      script.src = src;
      // GSAP core must not async-load after inline DOMContentLoaded handlers on the same tick.
      if (isGsapCoreUrl(src) || isGsapPluginUrl(src)) {
        script.async = false;
        try {
          script.removeAttribute('defer');
        } catch (eDef) { /* ignore */ }
      }
      // First-party helpers may have run (or need to run) after the third-party API exists.
      if (/player\.vimeo\.com\/api\/player\.js/i.test(src)) {
        script.addEventListener('load', function () {
          refireDependentScripts(/gtm4wp-vimeo/i);
        });
      }
      if (/youtube\.com\/iframe_api/i.test(src) || /youtube\.com\/s\/player/i.test(src)) {
        script.addEventListener('load', function () {
          refireDependentScripts(/gtm4wp-youtube/i);
        });
      }
      if (isMapApiUrl(src)) {
        script.addEventListener('load', function () {
          invokeGoogleMapsCallback(src);
          refireMapDependents();
        });
        script.addEventListener('error', function () {
          // Still try dependents — some sites polyfill or load maps twice.
          window.setTimeout(refireMapDependents, 200);
        });
      }
      if (isPayPalSdkUrl(src)) {
        script.addEventListener('load', function () {
          try {
            window.dispatchEvent(new CustomEvent('ucpf:paypal-sdk:ready'));
          } catch (eEv) { /* ignore */ }
        });
      }
      if (isGsapCoreUrl(src)) {
        script.addEventListener('load', function () {
          refireAnimationDependents();
        });
        script.addEventListener('error', function () {
          window.setTimeout(refireAnimationDependents, 200);
        });
      }
      if (isGsapPluginUrl(src)) {
        script.addEventListener('load', function () {
          refireAnimationDependents();
        });
      }
      if (isLottieApiUrl(src)) {
        script.addEventListener('load', function () {
          refireAnimationDependents();
        });
      }
      // After GTM / gtag / Meta / CF beacon load, finish managed Integrations inject + notify embeds.
      if (
        /googletagmanager\.com\/(gtm\.js|gtag\/js)/i.test(src) ||
        /connect\.facebook\.net\/.*fbevents\.js/i.test(src) ||
        /static\.cloudflareinsights\.com/i.test(src) ||
        /cdn-cgi\/rum/i.test(src)
      ) {
        script.addEventListener('load', function () {
          try {
            window.dispatchEvent(
              new CustomEvent('ucpf:tracker:ready', { detail: { src: src, service: service || '', category: category || '' } })
            );
          } catch (eTrk) { /* ignore */ }
          if (!window.__ucpfConsentReloadPending) {
            try {
              injectManagedServices();
            } catch (eInj) { /* ignore */ }
          }
        });
      }
    } else {
      script.text = node.textContent;
    }
    // Restore ES modules / import maps — never force classic text/javascript over type=module.
    var origType = (node.getAttribute('data-ucpf-original-type') || '').trim();
    if (!origType) {
      var curType = (node.getAttribute('type') || '').trim();
      var curLower = curType.toLowerCase();
      if (
        curType &&
        curLower !== 'text/plain' &&
        curLower !== 'text/javascript' &&
        curLower !== 'application/javascript' &&
        curLower !== 'application/ecmascript'
      ) {
        origType = curType;
      }
    }
    if (origType) {
      script.type = origType;
    } else {
      script.type = 'text/javascript';
    }
    var integ = node.getAttribute('data-ucpf-integrity');
    if (integ) {
      try {
        script.setAttribute('integrity', integ);
      } catch (eIg) { /* ignore */ }
    }
    if (node.parentNode) {
      node.parentNode.replaceChild(script, node);
    }
    dispatchLoaded(service || category);
  }

  /**
   * Strip cache-bust query from a script URL for clone-dedupe compares.
   *
   * @param {string} src
   * @return {string}
   */
  function refireScriptBaseSrc(src) {
    return String(src || '')
      .replace(/([?&])ucpf_r=\d+/g, '$1')
      .replace(/[?&]$/, '')
      .replace(/\?&/, '?');
  }

  /**
   * True when a ucpf-refire clone for this script is already in the document.
   *
   * @param {HTMLScriptElement} old
   * @param {string} src
   * @return {boolean}
   */
  function refireCloneAlreadyExists(old, src) {
    if (old.id) {
      try {
        if (document.getElementById(old.id + '-ucpf-refire')) {
          return true;
        }
      } catch (eId) { /* ignore */ }
    }
    var base = refireScriptBaseSrc(src);
    if (!base) {
      return false;
    }
    try {
      var clones = document.querySelectorAll('script[data-ucpf-refire-clone="1"][src]');
      for (var i = 0; i < clones.length; i++) {
        if (refireScriptBaseSrc(clones[i].getAttribute('src') || '') === base) {
          return true;
        }
      }
    } catch (eClones) { /* ignore */ }
    return false;
  }

  /**
   * Clone+reinsert first-party scripts that already executed and threw because a
   * gated third-party global (Vimeo / YT / google.maps) was missing.
   *
   * Never clones our own refire clones — force:true used to clear flags and
   * re-clone every Mapster script, doubling until the tab froze.
   *
   * @param {RegExp} srcRe
   * @param {{ force?: boolean }} [opts]
   */
  function refireDependentScripts(srcRe, opts) {
    opts = opts || {};
    try {
      // Snapshot first — appending during forEach would re-visit new nodes.
      var nodes = Array.prototype.slice.call(document.querySelectorAll('script[src]'));
      nodes.forEach(function (old) {
        var src = old.getAttribute('src') || '';
        if (!srcRe.test(src)) {
          return;
        }
        // Never clone Smart Slider — customElements.define cannot run twice.
        if (isSmartSliderScript(src)) {
          return;
        }
        // Never clone a clone (ucpf_r= cache-bust or marked refire).
        if (
          /[?&]ucpf_r=/.test(src) ||
          old.getAttribute('data-ucpf-refire-clone') === '1' ||
          (old.id && /-ucpf-refire$/i.test(old.id))
        ) {
          return;
        }
        // Even force:true must not create a second clone for the same script.
        if (refireCloneAlreadyExists(old, src)) {
          old.setAttribute('data-ucpf-refired', '1');
          return;
        }
        if (!opts.force && old.getAttribute('data-ucpf-refired') === '1') {
          return;
        }
        old.setAttribute('data-ucpf-refired', '1');
        var fresh = document.createElement('script');
        fresh.src = src + (src.indexOf('?') === -1 ? '?ucpf_r=' : '&ucpf_r=') + String(Date.now());
        if (old.id) {
          fresh.id = old.id + '-ucpf-refire';
        }
        fresh.setAttribute('data-ucpf-refired', '1');
        fresh.setAttribute('data-ucpf-refire-clone', '1');
        (old.parentNode || document.head).appendChild(fresh);
      });
    } catch (eRefire) { /* ignore */ }
  }

  /**
   * After Maps / Mapbox / MapLibre API load: re-run plugin helpers and common init hooks.
   *
   * @param {{ forceMapster?: boolean }} [opts]
   */
  function refireMapDependents(opts) {
    opts = opts || {};
    // forceMapster is one-shot per page — calling it repeatedly cloned Mapster scripts
    // until Chrome showed "Page Unresponsive" after Accept All.
    var forceMapster = !!opts.forceMapster && !mapsterForceDone;
    if (opts.forceMapster) {
      mapsterForceDone = true;
    }
    // Soft-pass excludes mapster when forceMapster runs — otherwise soft clone + force
    // clone both fire in the same tick (duplicate *-ucpf-refire / nested MapLibre).
    refireDependentScripts(
      forceMapster
        ? /wpgmza|wp-google-maps|wpgmaps|google-maps-builder|agm_google|flexible-map|ultimate-maps|wp-map-block|gmapn|elementor.*google.?maps/i
        : /wpgmza|wp-google-maps|wpgmaps|mapster|google-maps-builder|agm_google|flexible-map|ultimate-maps|wp-map-block|gmapn|elementor.*google.?maps/i,
      { force: false }
    );
    if (forceMapster) {
      refireDependentScripts(/mapster/i, { force: true });
    }
    // Also activate any still-parked map helpers (gated until API existed).
    try {
      document
        .querySelectorAll(
          'script[type="text/plain"][data-src], script[data-ucpf-gated="1"][data-src], script[data-ucpf-category][data-src]'
        )
        .forEach(function (node) {
          var src = node.getAttribute('data-src') || '';
          if (isMapDependentScriptUrl(src) || isMapApiUrl(src)) {
            activateScript(node);
          }
        });
    } catch (eParked) { /* ignore */ }

    try {
      document.querySelectorAll('script[src*="maps.googleapis.com/maps/api/js"]').forEach(function (node) {
        invokeGoogleMapsCallback(node.getAttribute('src') || '');
      });
    } catch (eGmCb) { /* ignore */ }

    try {
      if (typeof window.initMap === 'function') {
        window.initMap();
      }
    } catch (eInit) { /* ignore */ }
    try {
      if (typeof window.initGoogleMaps === 'function') {
        window.initGoogleMaps();
      }
    } catch (eInit2) { /* ignore */ }
    try {
      if (window.jQuery) {
        window.jQuery(document).trigger('wpgmza_map_initialized');
        window.jQuery(document).trigger('google.maps.loaded');
        window.jQuery(window).trigger('resize');
      }
    } catch (eJq) { /* ignore */ }
    // Leaflet / OSM: invalidateSize on known map instances.
    try {
      if (window.L) {
        document.querySelectorAll('.leaflet-container').forEach(function (el) {
          try {
            if (el._leaflet_map && typeof el._leaflet_map.invalidateSize === 'function') {
              el._leaflet_map.invalidateSize();
            } else if (el._leaflet_id && window.L.Map && window.L.Map._instances && window.L.Map._instances[el._leaflet_id]) {
              window.L.Map._instances[el._leaflet_id].invalidateSize();
            }
          } catch (eLeaf) { /* ignore */ }
        });
      }
    } catch (eL) { /* ignore */ }
    try {
      window.dispatchEvent(new CustomEvent('ucpf:maps:ready'));
    } catch (eEv) { /* ignore */ }
  }

  function refireEmbedDependents() {
    if (typeof window.Vimeo !== 'undefined') {
      refireDependentScripts(/gtm4wp-vimeo/i);
    }
    if (typeof window.YT !== 'undefined') {
      refireDependentScripts(/gtm4wp-youtube/i);
    }
    // GSAP/Lottie: only if something is still parked (never re-clone live Elementor CDN tags).
    if (
      document.querySelector(
        'script[type="text/plain"][data-src*="gsap"], script[data-ucpf-gated="1"][data-src*="gsap"], script[type="text/plain"][data-src*="lottie"], script[type="text/plain"][data-ucpf-inline-animation="1"]'
      )
    ) {
      refireAnimationDependents();
    }
    if (window.google && window.google.maps) {
      refireMapDependents();
    }
    if (typeof window.mapboxgl !== 'undefined' || typeof window.maplibregl !== 'undefined') {
      refireDependentScripts(/mapster|mapbox|maplibre|leaflet/i, { force: false });
    } else if (window.L && typeof window.L.map === 'function') {
      refireMapDependents();
    } else if (
      !mapsterForceDone &&
      document.querySelector(
        'script[src*="mapster"], script[data-src*="mapster"], .mapster-wp-maps, .mapster-wp-maps-container'
      )
    ) {
      // Mapster present but MapLibre not loaded yet — activate parked APIs + force bootstrap once.
      refireMapDependents({ forceMapster: true });
    }
  }

  function activateStylesheet(node) {
    var href = node.getAttribute('data-href');
    var category = node.getAttribute('data-ucpf-category');
    var service = node.getAttribute('data-ucpf-service');
    if (!href) return;
    // CSS is never consent-gated — always restore (heals older deferred markup).
    node.setAttribute('href', href);
    node.removeAttribute('data-href');
    node.removeAttribute('data-ucpf-deferred');
    node.removeAttribute('data-ucpf-gated');
    node.removeAttribute('data-ucpf-category');
    node.removeAttribute('data-ucpf-service');
    dispatchLoaded(service || category || 'stylesheet');
  }

  function activateIframe(node) {
    // Restore the exact deferred URL — never re-parse through embed builders.
    var src = node.getAttribute('data-src');
    var category = node.getAttribute('data-ucpf-category');
    if (!src) return;
    if (!canActivateUrl(src, category)) return;
    var iframe = document.createElement('iframe');
    iframe.src = src;
    iframe.setAttribute('loading', 'lazy');
    Array.prototype.slice.call(node.attributes || []).forEach(function (attr) {
      if (!attr || !attr.name) return;
      if (attr.name === 'data-src' || attr.name === 'src' || attr.name === 'data-ucpf-category') return;
      try {
        iframe.setAttribute(attr.name, attr.value);
      } catch (eAttr) { /* ignore */ }
    });
    if (node.parentNode) {
      node.parentNode.replaceChild(iframe, node);
    }
  }

  function injectManagedServices() {
    var list = config.managedServices || [];
    list.forEach(function (svc) {
      if (!svc || !svc.key) return;
      // PHP already printed this service for returning visitors — do not inject again.
      // Multi-container GTM: PHP marks the key once after printing ALL containers.
      if (alreadyManaged(svc.key)) {
        return;
      }
      // Same-page Accept: each managed entry is one complete unit (src+code merged in PHP).
      // Use part_id so multiple GTM containers (same key) each inject.
      var token =
        (svc.part_id || svc.key) +
        '|' +
        (svc.src || '') +
        '|' +
        (svc.code ? 'c:' + String(svc.code).length + ':' + String(svc.code).slice(0, 48) : '');
      if (loaded[token]) {
        return;
      }
      if (svc.category && !hasConsentForCategory(svc.category)) {
        return;
      }

      if (svc.src) {
        var s = document.createElement('script');
        s.async = true;
        s.src = svc.src;
        if (isMapApiUrl(svc.src)) {
          s.addEventListener('load', function () {
            refireMapDependents();
          });
        }
        if (isGsapCoreUrl(svc.src) || isGsapPluginUrl(svc.src) || isLottieApiUrl(svc.src)) {
          s.addEventListener('load', function () {
            refireAnimationDependents();
          });
        }
        document.head.appendChild(s);
      }
      if (svc.code) {
        var inline = document.createElement('script');
        inline.type = 'text/javascript';
        inline.text = svc.code;
        document.head.appendChild(inline);
      }

      loaded[token] = true;

      // Mark the service key loaded only after every part for that key has injected
      // (so multi-container GTM is not stopped after the first row).
      var siblings = list.filter(function (other) {
        return other && other.key === svc.key;
      });
      var allPartsLoaded = siblings.every(function (other) {
        var otherToken =
          (other.part_id || other.key) +
          '|' +
          (other.src || '') +
          '|' +
          (other.code ? 'c:' + String(other.code).length + ':' + String(other.code).slice(0, 48) : '');
        return !!loaded[otherToken];
      });
      if (allPartsLoaded) {
        window.ucpfManagedLoaded = window.ucpfManagedLoaded || [];
        if (window.ucpfManagedLoaded.indexOf(svc.key) === -1) {
          window.ucpfManagedLoaded.push(svc.key);
        }
        dispatchLoaded(svc.key);
      }
    });
  }

  function collectParkedScripts() {
    var seen = [];
    var out = [];
    function add(node) {
      if (!node || seen.indexOf(node) !== -1) {
        return;
      }
      // Skip live scripts that already have src and are not parked.
      var dataSrc = node.getAttribute('data-src');
      if (!dataSrc && node.getAttribute('type') !== 'text/plain') {
        return;
      }
      seen.push(node);
      out.push(node);
    }
    document.querySelectorAll('script[type="text/plain"][data-ucpf-category]').forEach(add);
    document.querySelectorAll('script[data-ucpf-gated="1"][data-src]').forEach(add);
    document.querySelectorAll('script[data-ucpf-category][data-src]').forEach(add);
    return out;
  }

  /**
   * Elementor BG YouTube can keep elementor-loading + elementor-invisible after consent
   * when its YT onReady never fires. Clear early from the always-loaded loader.
   */
  function healStuckElementorBackgroundVideos() {
    if (!hasConsentForCategory('marketing') || !hasConsentForCategory('functional')) {
      return 0;
    }
    var n = 0;
    try {
      document.querySelectorAll('.elementor-background-video-container').forEach(function (box) {
        var live =
          box.querySelector('iframe.elementor-background-video-embed[src]') ||
          box.querySelector('iframe[src*="youtube"], iframe[src*="youtu.be"], iframe[src*="vimeo"]');
        if (!live) {
          return;
        }
        var src = '';
        try {
          src = live.getAttribute('src') || live.src || '';
        } catch (eSrc) {
          src = '';
        }
        if (!src || src === 'about:blank') {
          return;
        }
        try {
          box.classList.remove('elementor-loading', 'elementor-invisible');
          box.classList.add('ucpf-bg-video-ready');
          box.style.visibility = 'visible';
          box.style.opacity = '1';
          n += 1;
        } catch (eHeal) { /* ignore */ }
      });
    } catch (eAll) { /* ignore */ }
    return n;
  }

  function scanPlaceholders() {
    // Accept/Reject navigates away — do not re-activate parked scripts on this document
    // (Smart Slider customElements.define, Mapster clone storms, etc.).
    if (window.__ucpfConsentReloadPending) {
      return;
    }
    if (scanPlaceholdersRunning) {
      return;
    }
    scanPlaceholdersRunning = true;
    try {
      // Activate APIs before dependent plugin scripts (DOM order alone is unreliable).
      var parked = collectParkedScripts();
      var embedApis = [];
      var paypalSdk = [];
      var gsapCore = [];
      var gsapPlugins = [];
      var lottieApis = [];
      var rest = [];
      parked.forEach(function (node) {
        var src = node.getAttribute('data-src') || '';
        if (
          isMapApiUrl(src) ||
          /player\.vimeo\.com\/api\/player\.js/i.test(src) ||
          /youtube\.com\/iframe_api/i.test(src)
        ) {
          embedApis.push(node);
        } else if (isPayPalSdkUrl(src)) {
          paypalSdk.push(node);
        } else if (isGsapCoreUrl(src)) {
          gsapCore.push(node);
        } else if (isGsapPluginUrl(src)) {
          gsapPlugins.push(node);
        } else if (isLottieApiUrl(src)) {
          lottieApis.push(node);
        } else {
          rest.push(node);
        }
      });
      embedApis.forEach(activateScript);
      paypalSdk.forEach(activateScript);
      gsapCore.forEach(activateScript);
      gsapPlugins.forEach(activateScript);
      lottieApis.forEach(activateScript);
      rest.forEach(activateScript);

      document.querySelectorAll('link[data-ucpf-deferred][data-href], link[data-ucpf-category][data-href]').forEach(activateStylesheet);
      document.querySelectorAll('.ucpf-iframe-placeholder[data-ucpf-category]').forEach(activateIframe);
      document.querySelectorAll('iframe[data-ucpf-category][data-src], iframe[data-ucpf-gated="1"][data-src]').forEach(function (node) {
        var src = node.getAttribute('data-src') || '';
        var category = node.getAttribute('data-ucpf-category');
        if (!canActivateUrl(src, category)) {
          return;
        }
        node.removeAttribute('data-ucpf-gated');
        node.removeAttribute('data-ucpf-category');
        try {
          node.src = src;
        } catch (eSrc) {
          try {
            node.setAttribute('src', src);
          } catch (eAttr) { /* ignore */ }
        }
        node.removeAttribute('data-src');
        var live = '';
        try {
          live = node.getAttribute('src') || node.src || '';
        } catch (eLive) {
          live = '';
        }
        // YouTube often stays blank on a previously emptied iframe — swap a fresh node.
        if ((!live || live === 'about:blank') && node.parentNode && isVideoEmbedUrl(src)) {
          var fresh = document.createElement('iframe');
          Array.prototype.slice.call(node.attributes || []).forEach(function (attr) {
            if (!attr || !attr.name) {
              return;
            }
            if (attr.name === 'src' || attr.name === 'data-src' || attr.name.indexOf('data-ucpf-') === 0) {
              return;
            }
            try {
              fresh.setAttribute(attr.name, attr.value);
            } catch (eCopy) { /* ignore */ }
          });
          if (node.className) {
            fresh.className = node.className;
          }
          fresh.src = src;
          try {
            node.parentNode.replaceChild(fresh, node);
          } catch (eRep) { /* ignore */ }
        }
      });
      injectManagedServices();
      // APIs may already be present (returning consent / hard reload) — refire helpers.
      refireEmbedDependents();
      healStuckElementorBackgroundVideos();
      if (!embedRefireTimersScheduled) {
        embedRefireTimersScheduled = true;
        [300, 1000, 2500, 4000].forEach(function (ms) {
          window.setTimeout(function () {
            refireEmbedDependents();
            healStuckElementorBackgroundVideos();
          }, ms);
        });
      }
    } finally {
      scanPlaceholdersRunning = false;
    }
  }

  function dispatchLoaded(service) {
    window.dispatchEvent(new CustomEvent('ucpf:service:loaded', { detail: { service: service } }));
  }

  function dispatchBlocked(service) {
    window.dispatchEvent(new CustomEvent('ucpf:service:blocked', { detail: { service: service } }));
  }

  /**
   * Soft-defer live scripts/links for any denied gated category (gate classification).
   * Hard reset after Reject All is a page reload in consent.js.
   */
  function neutralizeDeniedAssets() {
    try {
      if (typeof window.__ucpfRescanGate === 'function') {
        window.__ucpfRescanGate();
      }
    } catch (eRescan) {}

    try {
      document.querySelectorAll('script[src]').forEach(function (node) {
        var src = node.getAttribute('src') || '';
        if (isUserWayUrl(src) || isPresentationLibUrl(src)) {
          return;
        }
        var kind = classifyUrl(src);
        var dual =
          (typeof window.__ucpfNeedsMarketingAndEmbeds === 'function' && window.__ucpfNeedsMarketingAndEmbeds(src)) ||
          isVideoEmbedUrl(src);
        if (!kind && !dual) {
          return;
        }
        if (dual) {
          if (hasConsentForCategory('marketing') && hasConsentForCategory('functional')) {
            return;
          }
        } else if (hasConsentForCategory(kind)) {
          return;
        }
        node.setAttribute('data-src', src);
        node.setAttribute('data-ucpf-category', dual ? kind || 'functional' : kind);
        node.setAttribute('data-ucpf-gated', '1');
        node.type = 'text/plain';
        try {
          node.removeAttribute('src');
        } catch (e) {}
      });
      document.querySelectorAll('iframe[src], iframe[data-src], iframe[data-lazy-src]').forEach(function (node) {
        var src = resolveDeferredIframeUrl(node);
        if (!src || isUserWayUrl(src)) {
          return;
        }
        if (typeof window.__ucpfIsPlaceholderEmbedSrc === 'function' && window.__ucpfIsPlaceholderEmbedSrc(src)) {
          return;
        }
        var kind = classifyUrl(src);
        var dual =
          (typeof window.__ucpfNeedsMarketingAndEmbeds === 'function' && window.__ucpfNeedsMarketingAndEmbeds(src)) ||
          isVideoEmbedUrl(src);
        if (!kind && !dual) {
          return;
        }
        if (dual) {
          if (hasConsentForCategory('marketing') && hasConsentForCategory('functional')) {
            return;
          }
        } else if (kind && hasConsentForCategory(kind)) {
          return;
        }
        if (!node.getAttribute('data-src')) {
          node.setAttribute('data-src', src);
        }
        node.setAttribute('data-ucpf-category', dual ? kind || 'functional' : kind || 'functional');
        node.setAttribute('data-ucpf-gated', '1');
        node.removeAttribute('data-ucpf-map-restored');
        try {
          node.removeAttribute('src');
        } catch (eI) {}
        try {
          node.src = '';
        } catch (eI2) {}
      });
    } catch (eScript) {}

    // Stylesheets are never neutralized — gating CSS unstyles the site.
    try {
      document
        .querySelectorAll('link[data-href][data-ucpf-deferred], link[data-href][data-ucpf-gated]')
        .forEach(function (node) {
          var real = node.getAttribute('data-href') || '';
          if (!real) {
            return;
          }
          try {
            node.setAttribute('href', real);
            node.removeAttribute('data-href');
            node.removeAttribute('data-ucpf-deferred');
            node.removeAttribute('data-ucpf-gated');
            node.removeAttribute('data-ucpf-category');
          } catch (eRestore) {}
        });
    } catch (eLink) {}

    if (typeof gtag === 'function') {
      try {
        gtag('consent', 'update', {
          ad_storage: hasConsentForCategory('marketing') ? 'granted' : 'denied',
          analytics_storage: hasConsentForCategory('analytics') ? 'granted' : 'denied',
          ad_user_data: hasConsentForCategory('marketing') ? 'granted' : 'denied',
          ad_personalization: hasConsentForCategory('marketing') ? 'granted' : 'denied',
          functionality_storage: hasConsentForCategory('functional') ? 'granted' : 'denied',
          personalization_storage: hasConsentForCategory('preferences') ? 'granted' : 'denied',
          security_storage: hasConsentForCategory('security') || hasConsentForCategory('necessary') ? 'granted' : 'denied',
        });
      } catch (e2) {}
    }
  }

  window.UCPFLoader = {
    applyConsent: function () {
      scanPlaceholders();
    },
    loadService: function () {
      scanPlaceholders();
    },
    unloadService: function () {
      neutralizeDeniedAssets();
    },
    refireMapDependents: refireMapDependents,
    refireAnimationDependents: refireAnimationDependents,
  };

  window.addEventListener('ucpf:consent:changed', function () {
    if (window.__ucpfConsentReloadPending) {
      return;
    }
    // Always neutralize denied assets first, then activate only granted placeholders.
    neutralizeDeniedAssets();
    scanPlaceholders();
  });

  /** Only heal leftover parked animation tags — do not touch live Elementor GSAP. */
  function bootAnimationEarly() {
    activateParkedAnimationApis();
    if (
      document.querySelector(
        'script[type="text/plain"][data-src*="gsap"], script[data-ucpf-gated="1"][data-src*="gsap"], script[type="text/plain"][data-ucpf-inline-animation="1"]'
      )
    ) {
      scheduleAnimationLibPollRefire();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootAnimationEarly, true);
    document.addEventListener('DOMContentLoaded', scanPlaceholders);
  } else {
    bootAnimationEarly();
    scanPlaceholders();
  }
})();
