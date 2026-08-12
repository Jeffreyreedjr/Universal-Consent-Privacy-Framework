(function () {
  'use strict';

  var loaded = {};
  var config = window.ucpfConfig || {};
  var mapsterForceDone = false;
  var embedRefireTimersScheduled = false;
  var scanPlaceholdersRunning = false;

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

  function canActivateUrl(url, category) {
    // Accessibility toolbar — always allow even if older HTML stamped functional/preferences.
    if (isUserWayUrl(url)) {
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

  function activateScript(node) {
    var src = node.getAttribute('data-src') || '';
    var category = node.getAttribute('data-ucpf-category');
    var service = node.getAttribute('data-ucpf-service');

    if (!canActivateUrl(src, category)) {
      dispatchBlocked(service || category);
      return;
    }

    var script = document.createElement('script');
    Array.prototype.slice.call(node.attributes || []).forEach(function (attr) {
      if (!attr || !attr.name) return;
      if (attr.name === 'type' || attr.name === 'data-src' || attr.name === 'src') return;
      if (attr.name === 'data-ucpf-category' || attr.name === 'data-ucpf-service' || attr.name === 'data-ucpf-gated') return;
      try {
        script.setAttribute(attr.name, attr.value);
      } catch (eAttr) { /* ignore invalid / empty names */ }
    });
    if (src) {
      script.src = src;
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
          refireMapDependents();
        });
        script.addEventListener('error', function () {
          // Still try dependents — some sites polyfill or load maps twice.
          window.setTimeout(refireMapDependents, 200);
        });
      }
    } else {
      script.text = node.textContent;
    }
    script.type = 'text/javascript';
    if (node.parentNode) {
      node.parentNode.replaceChild(script, node);
    }
    dispatchLoaded(service || category);
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
        // Never clone a clone (ucpf_r= cache-bust or marked refire).
        if (
          /[?&]ucpf_r=/.test(src) ||
          old.getAttribute('data-ucpf-refire-clone') === '1' ||
          (old.id && /-ucpf-refire$/i.test(old.id))
        ) {
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
    refireDependentScripts(
      /wpgmza|wp-google-maps|wpgmaps|mapster|google-maps-builder|agm_google|flexible-map|ultimate-maps|wp-map-block|gmapn/i,
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
    if (window.google && window.google.maps) {
      refireMapDependents();
    }
    if (typeof window.mapboxgl !== 'undefined' || typeof window.maplibregl !== 'undefined') {
      refireDependentScripts(/mapster|mapbox|maplibre|leaflet/i, { force: false });
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
      if (alreadyManaged(svc.key)) {
        return;
      }
      // Same-page Accept: each managed entry is one complete unit (src+code merged in PHP).
      var token = svc.key + '|' + (svc.src || '') + '|' + (svc.code ? 'c:' + String(svc.code).length : '');
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
        document.head.appendChild(s);
      }
      if (svc.code) {
        var inline = document.createElement('script');
        inline.type = 'text/javascript';
        inline.text = svc.code;
        document.head.appendChild(inline);
      }

      loaded[token] = true;
      window.ucpfManagedLoaded = window.ucpfManagedLoaded || [];
      if (window.ucpfManagedLoaded.indexOf(svc.key) === -1) {
        window.ucpfManagedLoaded.push(svc.key);
      }
      dispatchLoaded(svc.key);
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

  function scanPlaceholders() {
    if (scanPlaceholdersRunning) {
      return;
    }
    scanPlaceholdersRunning = true;
    try {
      // Activate map/player APIs before dependent plugin scripts (DOM order alone is unreliable).
      var parked = collectParkedScripts();
      var apis = [];
      var rest = [];
      parked.forEach(function (node) {
        var src = node.getAttribute('data-src') || '';
        if (
          isMapApiUrl(src) ||
          /player\.vimeo\.com\/api\/player\.js/i.test(src) ||
          /youtube\.com\/iframe_api/i.test(src)
        ) {
          apis.push(node);
        } else {
          rest.push(node);
        }
      });
      apis.forEach(activateScript);
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
      if (!embedRefireTimersScheduled) {
        embedRefireTimersScheduled = true;
        [300, 1000, 2500].forEach(function (ms) {
          window.setTimeout(refireEmbedDependents, ms);
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
        if (isUserWayUrl(src)) {
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
      document.querySelectorAll('iframe[src]').forEach(function (node) {
        var src = node.getAttribute('src') || '';
        if (!src || src === 'about:blank' || isUserWayUrl(src)) {
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
  };

  window.addEventListener('ucpf:consent:changed', function () {
    // Always neutralize denied assets first, then activate only granted placeholders.
    neutralizeDeniedAssets();
    scanPlaceholders();
  });
  document.addEventListener('DOMContentLoaded', scanPlaceholders);
})();
