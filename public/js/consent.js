(function () {
  'use strict';

  var config = window.ucpfConfig || {};
  var COOKIE_NAME = 'ucpf_consent';
  var listeners = {};
  var reshowTimers = [];
  var state = {
    uuid: '',
    state: 'unknown',
    categories: {},
    services: {},
  };

  function markConsentDone() {
    window.__ucpfConsentDone = true;
  }

  function clearReshowTimers() {
    while (reshowTimers.length) {
      window.clearTimeout(reshowTimers.pop());
    }
  }

  function cookieDomainAttr() {
    var d = (config.cookieDomain || '').toString().trim();
    if (!d || d === 'localhost' || /^\d+\.\d+\.\d+\.\d+$/.test(d)) {
      return '';
    }
    if (d.charAt(0) !== '.') {
      d = '.' + d.replace(/^\./, '');
    }
    // Only set Domain when it matches the current host (avoid invalid Domain=).
    var host = (location.hostname || '').toLowerCase();
    var bare = d.replace(/^\./, '').toLowerCase();
    if (host !== bare && host.slice(-(bare.length + 1)) !== '.' + bare) {
      return '';
    }
    return '; Domain=' + d;
  }

  function cookiePath() {
    var p = (config.cookiePath || '/').toString().trim();
    if (!p || p.charAt(0) !== '/') {
      p = '/' + (p || '');
    }
    if (!p) {
      p = '/';
    }
    return p;
  }

  function backupStorageKey() {
    var suffix = (config.storageSuffix || '').toString().replace(/[^a-zA-Z0-9_-]/g, '');
    return suffix ? 'ucpf_consent_backup_' + suffix : 'ucpf_consent_backup';
  }

  function bridgeStorageKey() {
    var suffix = (config.storageSuffix || '').toString().replace(/[^a-zA-Z0-9_-]/g, '');
    return suffix ? 'ucpf_consent_bridge_' + suffix : 'ucpf_consent_bridge';
  }

  function toBase64Url(str) {
    var b64 = window.btoa(unescape(encodeURIComponent(str)));
    return b64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
  }

  function fromBase64Url(packed) {
    var s = String(packed || '').replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) {
      s += '=';
    }
    return decodeURIComponent(escape(window.atob(s)));
  }

  function packConsentHandoff(data) {
    var compact = {
      uuid: data.uuid || '',
      state: data.state || 'custom',
      categories: data.categories || {},
      services: data.services || {},
      version: data.version || config.consentVersion || '',
      policy_version: data.policy_version || config.policyVersion || '',
      timestamp: data.timestamp || Math.floor(Date.now() / 1000),
      expires: data.expires || 0,
    };
    return toBase64Url(JSON.stringify(compact));
  }

  function stripConsentQueryParams() {
    try {
      var url = new URL(window.location.href);
      var changed = false;
      if (url.searchParams.has('_ucpf') || url.searchParams.has('_ucpf_c')) {
        url.searchParams.delete('_ucpf');
        url.searchParams.delete('_ucpf_c');
        changed = true;
      }
      if (url.hash && /^#ucpf_c=/i.test(url.hash)) {
        url.hash = '';
        changed = true;
      }
      if (!changed) {
        return;
      }
      var q = url.searchParams.toString();
      var clean = url.pathname + (q ? '?' + q : '') + (url.hash || '');
      window.history.replaceState(null, '', clean);
    } catch (eStrip) { /* ignore */ }
  }

  /**
   * Brave Shields (and some privacy extensions) drop document.cookie across reload.
   * One-shot handoff in the URL hash survives WooCommerce checkout redirects that
   * strip unknown query args; query `_ucpf` is only a cache-buster.
   */
  function readConsentHandoff() {
    try {
      var cached = window.__ucpfConsentHandoff;
      if (cached && typeof cached === 'object' && cached.state && cached.categories) {
        stripConsentQueryParams();
        try {
          delete window.__ucpfConsentHandoff;
        } catch (eDel) {
          window.__ucpfConsentHandoff = null;
        }
        return cached;
      }
      var packed = '';
      if (window.location.hash && /^#ucpf_c=/i.test(window.location.hash)) {
        packed = window.location.hash.replace(/^#ucpf_c=/i, '');
      }
      if (!packed) {
        var params = new URLSearchParams(window.location.search || '');
        packed = params.get('_ucpf_c') || '';
      }
      stripConsentQueryParams();
      if (!packed) {
        return null;
      }
      var data = JSON.parse(fromBase64Url(decodeURIComponent(packed)));
      if (!data || typeof data !== 'object' || !data.state) {
        return null;
      }
      return data;
    } catch (eHandoff) {
      stripConsentQueryParams();
      return null;
    }
  }

  function writeConsentBridge(data) {
    try {
      if (window.sessionStorage) {
        sessionStorage.setItem(bridgeStorageKey(), JSON.stringify(data));
      }
    } catch (e) { /* private mode */ }
  }

  function readConsentBridge() {
    try {
      if (!window.sessionStorage) return null;
      var key = bridgeStorageKey();
      var raw = sessionStorage.getItem(key);
      if (!raw && key !== 'ucpf_consent_bridge') {
        raw = sessionStorage.getItem('ucpf_consent_bridge');
      }
      if (!raw) return null;
      sessionStorage.removeItem(key);
      if (key !== 'ucpf_consent_bridge') {
        sessionStorage.removeItem('ucpf_consent_bridge');
      }
      return JSON.parse(raw);
    } catch (e) {
      return null;
    }
  }

  function cookieBase(maxAge) {
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    return '; Path=' + cookiePath() + cookieDomainAttr() + '; Max-Age=' + maxAge + '; SameSite=Lax' + secure;
  }

  function expireCookieAtPath(path) {
    try {
      document.cookie = COOKIE_NAME + '=; Path=' + path + cookieDomainAttr() + '; Max-Age=0; SameSite=Lax';
    } catch (eExpire) { /* ignore */ }
  }

  function clearConsentCookie() {
    expireCookieAtPath(cookiePath());
    if (cookiePath() !== '/') {
      expireCookieAtPath('/');
    }
  }

  function readStoredConsent() {
    // URL handoff first (Brave Shields often drops cookies/storage across reload).
    var handoff = readConsentHandoff();
    if (handoff && !shouldReprompt(handoff)) {
      writeLocalCookie(handoff);
      writeConsentBackup(handoff);
      return handoff;
    }
    var cookie = parseCookie();
    if (cookie && !shouldReprompt(cookie)) {
      return cookie;
    }
    var backup = readConsentBackup();
    if (backup && !shouldReprompt(backup)) {
      return backup;
    }
    var bridge = readConsentBridge();
    if (bridge && !shouldReprompt(bridge)) {
      writeLocalCookie(bridge);
      return bridge;
    }
    return null;
  }

  function parseCookie() {
    var match = document.cookie.match(new RegExp('(?:^|; )' + COOKIE_NAME.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
    if (!match) return null;
    var raw = match[1];
    try {
      return JSON.parse(decodeURIComponent(raw));
    } catch (e1) {
      try {
        return JSON.parse(raw);
      } catch (e2) {
        // Corrupt / truncated cookie — clear so backup can rehydrate.
        try {
          clearConsentCookie();
        } catch (e3) { /* ignore */ }
        return null;
      }
    }
  }

  function readConsentBackup() {
    try {
      var key = backupStorageKey();
      var raw = window.localStorage && localStorage.getItem(key);
      if (!raw) {
        raw = window.sessionStorage && sessionStorage.getItem(key);
      }
      // Migrate legacy unsuffixed backup once (single-site or first MS load).
      if (!raw && key !== 'ucpf_consent_backup') {
        raw = window.localStorage && localStorage.getItem('ucpf_consent_backup');
        if (!raw) {
          raw = window.sessionStorage && sessionStorage.getItem('ucpf_consent_backup');
        }
      }
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) {
      return null;
    }
  }

  function writeConsentBackup(data) {
    try {
      var raw = JSON.stringify(data);
      var key = backupStorageKey();
      if (window.localStorage) localStorage.setItem(key, raw);
      if (window.sessionStorage) sessionStorage.setItem(key, raw);
    } catch (e) { /* private mode */ }
  }

  function clearConsentBackup() {
    try {
      var key = backupStorageKey();
      if (window.localStorage) localStorage.removeItem(key);
      if (window.sessionStorage) sessionStorage.removeItem(key);
      if (key !== 'ucpf_consent_backup') {
        if (window.localStorage) localStorage.removeItem('ucpf_consent_backup');
        if (window.sessionStorage) sessionStorage.removeItem('ucpf_consent_backup');
      }
    } catch (e) { /* ignore */ }
  }

  function defaultRejected() {
    var cats = {};
    Object.keys(config.categories || {}).forEach(function (slug) {
      cats[slug] = slug === 'necessary';
    });
    return cats;
  }

  function defaultAccepted() {
    var cats = {};
    var keys = Object.keys(config.categories || {});
    if (!keys.length) {
      // Fallback if config not hydrated yet — match banner shim.
      return {
        necessary: true,
        preferences: true,
        analytics: true,
        marketing: true,
        functional: true,
        security: true,
      };
    }
    keys.forEach(function (slug) {
      cats[slug] = true;
    });
    return cats;
  }

  function shouldReprompt(cookie) {
    if (!cookie) return true;
    if (!cookie.state || cookie.state === 'unknown') return true;
    var exp = Number(cookie.expires || 0);
    if (exp && exp < Math.floor(Date.now() / 1000)) return true;
    if (cookie.policy_version && config.policyVersion && String(cookie.policy_version) !== String(config.policyVersion)) return true;
    if (cookie.version && config.consentVersion && String(cookie.version) !== String(config.consentVersion)) return true;
    return false;
  }

  function loadState() {
    if (config.discoverMode) {
      state.state = 'discover';
      state.categories = defaultAccepted();
      state.services = {};
      state.uuid = '';
      return;
    }
    var cookie = readStoredConsent();
    if (!cookie) {
      state.state = 'unknown';
      state.categories = defaultRejected();
      state.services = {};
      state.uuid = '';
      return;
    }
    // Cookie missing/corrupt but backup valid — rehydrate so Path=COOKIEPATH persists again.
    if (!parseCookie()) {
      writeLocalCookie(cookie);
    }
    state.uuid = cookie.uuid || '';
    state.state = cookie.state || 'custom';
    state.categories = cookie.categories || defaultRejected();
    state.services = cookie.services || {};
    markConsentDone();
  }

  function collectCookieNames() {
    return document.cookie
      ? document.cookie.split(';').map(function (part) {
          return part.split('=')[0].trim();
        }).filter(Boolean)
      : [];
  }

  function reportDiscoverCookies() {
    if (!config.discoverMode || window.parent === window) {
      return;
    }
    try {
      window.parent.postMessage(
        {
          type: 'ucpf-scan-cookies',
          cookies: collectCookieNames(),
          href: window.location.href,
        },
        window.location.origin
      );
    } catch (e) {}
  }

  function activateDiscoverTracking() {
    syncWpConsent(state.categories, state.services);
    if (window.UCPFLoader) {
      window.UCPFLoader.applyConsent(state);
    }
    if (typeof gtag === 'function') {
      gtag('consent', 'update', {
        ad_storage: 'granted',
        analytics_storage: 'granted',
        ad_user_data: 'granted',
        ad_personalization: 'granted',
        functionality_storage: 'granted',
        personalization_storage: 'granted',
        security_storage: 'granted',
      });
    }
    // Wake common deferred placeholders other CMPs leave behind.
    try {
      document.querySelectorAll('script[type="text/plain"][data-src], script[type="text/plain"][data-ucpf-category]').forEach(function (node) {
        if (window.UCPFLoader) return;
        var src = node.getAttribute('data-src');
        var s = document.createElement('script');
        if (src) s.src = src;
        else s.text = node.textContent || '';
        s.type = 'text/javascript';
        if (node.parentNode) node.parentNode.replaceChild(s, node);
      });
    } catch (e) {}
  }

  function dispatch(name, detail) {
    if (listeners[name]) {
      listeners[name].forEach(function (cb) {
        try {
          cb(detail);
        } catch (eCb) { /* ignore listener errors */ }
      });
    }
    var evt;
    try {
      evt = new CustomEvent(name, { detail: detail, bubbles: true });
    } catch (eIE) {
      evt = document.createEvent('CustomEvent');
      evt.initCustomEvent(name, true, true, detail);
    }
    // Fire on document AND window — guards listen on document; some hosts only see window.
    try {
      document.dispatchEvent(evt);
    } catch (eDoc) { /* ignore */ }
    try {
      window.dispatchEvent(new CustomEvent(name, { detail: detail, bubbles: true }));
    } catch (eWin) { /* ignore */ }
  }

  function clearNavigationBlockers() {
    try {
      window.onbeforeunload = null;
    } catch (e0) { /* ignore */ }
  }

  /**
   * Hard navigation after consent. Same-URL location.replace() is a no-op in
   * Chrome/Brave; reload() can be cancelled by beforeunload on dirty checkout forms.
   * Cache-bust with `?_ucpf=` and pass consent in `#ucpf_c=` for Brave Shields
   * (hash survives Woo redirects that drop unknown query args).
   */
  function navigateAfterConsent(local) {
    clearNavigationBlockers();
    var dest = String(window.location.href || '').split('#')[0];
    try {
      var u = new URL(dest, window.location.origin);
      u.searchParams.delete('_ucpf');
      u.searchParams.delete('_ucpf_c');
      var q0 = u.searchParams.toString();
      dest = u.pathname + (q0 ? '?' + q0 : '');
    } catch (eUrl) { /* keep dest */ }

    var packed = '';
    try {
      packed = packConsentHandoff(local || state);
    } catch (ePack) { /* storage bridge still set */ }

    var qs = '_ucpf=' + String(Date.now());
    var joiner = dest.indexOf('?') === -1 ? '?' : '&';
    // Hash handoff survives server redirects that drop unknown query params (Woo checkout).
    var hash = packed && packed.length < 2500 ? '#ucpf_c=' + encodeURIComponent(packed) : '';
    var next = dest + joiner + qs + hash;
    try {
      window.location.assign(next);
      return;
    } catch (eAssign) { /* fall through */ }
    try {
      window.location.href = next;
      return;
    } catch (eHref) { /* fall through */ }
    try {
      window.location.reload();
    } catch (eReload) { /* ignore */ }
  }

  function scheduleConsentReload(local) {
    writeConsentBridge(local);
    writeConsentBackup(local);
    clearNavigationBlockers();
    // Give cookie/storage writes time to flush (Brave Shields / Mac Chrome).
    var reloadDelay = 220;
    window.setTimeout(function () {
      writeLocalCookie(local);
      writeConsentBridge(local);
      writeConsentBackup(local);
      navigateAfterConsent(local);
    }, reloadDelay);
    // If navigation is cancelled (dirty Woo form / extension), unlock so Enable/Accept still work.
    window.setTimeout(function () {
      if (!consentInFlight) {
        return;
      }
      consentInFlight = false;
      setConsentControlsLocked(false);
      if (window.UCPFLoader && typeof window.UCPFLoader.applyConsent === 'function') {
        try {
          window.UCPFLoader.applyConsent(state);
        } catch (eApply) { /* ignore */ }
      }
      dispatch('ucpf:consent:changed', state);
      if (state.state === 'accepted_all') {
        dispatch('ucpf:consent:accepted_all', state);
      }
    }, 1000);
  }

  function apiRequest(path, body) {
    return fetch(config.restUrl + path, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce,
      },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    }).then(function (res) {
      return res.json();
    });
  }

  function syncGtagConsent(categories) {
    categories = categories || state.categories || {};
    if (typeof gtag !== 'function') {
      return;
    }
    gtag('consent', 'update', {
      ad_storage: categories.marketing ? 'granted' : 'denied',
      analytics_storage: categories.analytics ? 'granted' : 'denied',
      ad_user_data: categories.marketing ? 'granted' : 'denied',
      ad_personalization: categories.marketing ? 'granted' : 'denied',
    });
  }

  function writeWpConsentCookie(category, value) {
    var maxAge = parseInt(config.cookieLifetime, 10);
    if (!maxAge || maxAge < 86400) {
      maxAge = 180 * 86400;
    }
    document.cookie = 'wp_consent_' + category + '=' + (value ? 'allow' : 'deny') + cookieBase(maxAge);
  }

  function softStubWooOrderAttribution() {
    // WooCommerce wp-consent-api-integration may call setOrderTracking on pages
    // where order-attribution.js never loaded (e.g. Contact). Prevent console throws.
    try {
      var oa = window.wc_order_attribution;
      if (oa && typeof oa.setOrderTracking !== 'function') {
        oa.setOrderTracking = function () {};
      }
    } catch (eStub) {}
  }

  function syncWpConsent(categories, services) {
    categories = categories || {};
    softStubWooOrderAttribution();

    // Client-side WP Consent API cookies so Site Kit / other listeners wake without reload.
    writeWpConsentCookie('functional', true);
    writeWpConsentCookie('preferences', !!categories.preferences);
    writeWpConsentCookie('statistics', !!categories.analytics);
    writeWpConsentCookie('marketing', !!categories.marketing);
    if (Object.prototype.hasOwnProperty.call(categories, 'security')) {
      writeWpConsentCookie('security', !!categories.security);
    }

    try {
      if (typeof window.wp_set_consent === 'function') {
        window.wp_set_consent('functional', 'allow');
        var map = {
          preferences: categories.preferences,
          statistics: categories.analytics,
          marketing: categories.marketing,
          security: categories.security,
        };
        Object.keys(map).forEach(function (key) {
          if (map[key] === undefined) return;
          try {
            window.wp_set_consent(key, map[key] ? 'allow' : 'deny');
          } catch (eSet) {}
        });
      }
      if (typeof window.wp_set_service_consent === 'function' && services) {
        Object.keys(services).forEach(function (service) {
          try {
            window.wp_set_service_consent(service, !!services[service]);
          } catch (eSvc) {}
        });
      }
      if (window.wp_consent_type === undefined && config) {
        window.wp_consent_type = (config.consentType === 'optout') ? 'optout' : 'optin';
        softStubWooOrderAttribution();
        document.dispatchEvent(new CustomEvent('wp_consent_type_defined'));
      }
      softStubWooOrderAttribution();
      document.dispatchEvent(new CustomEvent('wp_listen_for_consent_change', {
        detail: {
          functional: 'allow',
          preferences: categories.preferences ? 'allow' : 'deny',
          statistics: categories.analytics ? 'allow' : 'deny',
          marketing: categories.marketing ? 'allow' : 'deny',
        },
      }));
    } catch (e) {}
  }

  function writeLocalCookie(payload) {
    var maxAge = parseInt(config.cookieLifetime, 10);
    if (!maxAge || maxAge < 86400) {
      maxAge = 180 * 86400;
    }
    var expires = Math.floor(Date.now() / 1000) + maxAge;
    var data = {
      uuid: payload.uuid || state.uuid || (window.crypto && crypto.randomUUID ? crypto.randomUUID() : String(Date.now())),
      version: config.consentVersion || '1.0.0',
      policy_version: config.policyVersion || '',
      state: payload.state || 'custom',
      categories: payload.categories || defaultRejected(),
      services: payload.services || {},
      timestamp: Math.floor(Date.now() / 1000),
      expires: expires,
    };
    // Keep under typical browser/proxy cookie limits (~4KB) — oversized writes are dropped silently.
    var encoded = encodeURIComponent(JSON.stringify(data));
    if (encoded.length > 3500) {
      data.services = {};
      encoded = encodeURIComponent(JSON.stringify(data));
    }
    document.cookie = COOKIE_NAME + '=' + encoded + cookieBase(maxAge);
    if (cookiePath() !== '/') {
      expireCookieAtPath('/');
    }
    state.uuid = data.uuid;
    state.state = data.state;
    state.categories = data.categories;
    state.services = data.services || {};
    writeConsentBackup(data);
    // Verify browser accepted the cookie; retry once if needed.
    var readBack = parseCookie();
    if (!readBack || readBack.state !== data.state) {
      document.cookie = COOKIE_NAME + '=' + encoded + cookieBase(maxAge);
    }
    markConsentDone();
    return data;
  }

  /** Blocks duplicate Accept / Reject / Save while a consent write is in flight. */
  var consentInFlight = false;

  function setConsentControlsLocked(locked) {
    var root = document.getElementById('ucpf-root');
    if (!root) return;
    var nodes = root.querySelectorAll(
      '[data-ucpf-action="accept_all"], [data-ucpf-action="reject_all"], [data-ucpf-action="save_preferences"]'
    );
    for (var i = 0; i < nodes.length; i++) {
      if (locked) {
        nodes[i].setAttribute('disabled', 'disabled');
        nodes[i].setAttribute('aria-busy', 'true');
      } else {
        nodes[i].removeAttribute('disabled');
        nodes[i].removeAttribute('aria-busy');
      }
    }
  }

  function mapsEqualBool(a, b) {
    a = a || {};
    b = b || {};
    var keys = {};
    Object.keys(a).forEach(function (k) {
      keys[k] = true;
    });
    Object.keys(b).forEach(function (k) {
      keys[k] = true;
    });
    return Object.keys(keys).every(function (k) {
      return !!a[k] === !!b[k];
    });
  }

  /** True when Save Preferences leaves choices identical to when the panel opened. */
  function savePreferencesUnchanged(payload) {
    if (!prefsBaseline) {
      return false;
    }
    return (
      mapsEqualBool(payload && payload.categories, prefsBaseline.categories) &&
      mapsEqualBool(payload && payload.services, prefsBaseline.services)
    );
  }

  function finishConsentRequest(local, payload, action) {
    return apiRequest('consent', Object.assign({ action: action, uuid: local.uuid }, payload))
      .then(function (response) {
        if (
          response &&
          response.consent &&
          response.consent.categories &&
          response.consent.state &&
          response.consent.state !== 'unknown'
        ) {
          writeLocalCookie(response.consent);
          syncWpConsent(state.categories, state.services);
        }
        consentInFlight = false;
        setConsentControlsLocked(false);
        return response;
      })
      .catch(function () {
        consentInFlight = false;
        setConsentControlsLocked(false);
        return { success: true, consent: local, offline: true };
      });
  }

  function applyConsent(payload, action) {
    if (consentInFlight) {
      return Promise.resolve({ success: false, skipped: true });
    }
    consentInFlight = true;
    setConsentControlsLocked(true);

    var skipReload = action === 'save_preferences' && savePreferencesUnchanged(payload);

    // Persist immediately so Accept All / Save always stick even if REST fails.
    clearReshowTimers();
    var local = writeLocalCookie(payload);
    syncWpConsent(local.categories, local.services);
    syncGtagConsent(local.categories);
    dispatch('ucpf:consent:changed', state);
    if (action === 'accept_all') dispatch('ucpf:consent:accepted_all', state);
    if (action === 'reject_all') dispatch('ucpf:consent:rejected_all', state);
    if (action === 'reject_all') {
      if (window.UCPFLoader && typeof window.UCPFLoader.unloadService === 'function') {
        try {
          window.UCPFLoader.unloadService();
        } catch (eUnload0) { /* ignore */ }
      }
    }
    prefsDirty = false;
    prefsBaseline = null;
    hideBanner();
    hidePrefs();
    showFab();

    // Accept / Reject / Save Preferences: reload so PHP re-renders enqueued tags
    // (GT &ver=, fonts, captchas) for the exact consent mix. Same-page inject alone
    // misses WordPress-enqueued scripts that were deferred as text/plain.
    // Exception: Save with no toggle changes — close UI only (no refresh).
    if (
      action === 'accept_all' ||
      action === 'reject_all' ||
      (action === 'save_preferences' && !skipReload)
    ) {
      // Promote deferred tags immediately (navigation may be cancelled on checkout).
      if (action !== 'reject_all' && window.UCPFLoader && typeof window.UCPFLoader.applyConsent === 'function') {
        try {
          window.UCPFLoader.applyConsent(state);
        } catch (eLoad) { /* ignore */ }
      }
      var hardReq = apiRequest('consent', Object.assign({ action: action, uuid: local.uuid }, payload)).catch(function () {
        return { success: true, consent: local, offline: true };
      });
      scheduleConsentReload(local);
      return hardReq;
    }

    if (window.UCPFLoader) window.UCPFLoader.applyConsent(state);

    return finishConsentRequest(local, payload, action);
  }

  var bannerEl, prefsEl, fabEl, prefsReturnFocus;
  /** True when prefs toggles changed but Save Preferences has not been pressed. */
  var prefsDirty = false;
  /** Category/service snapshot when preferences panel opened (for unchanged-save). */
  var prefsBaseline = null;

  function qs(sel, ctx) {
    return (ctx || document).querySelector(sel);
  }

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function getFocusable(container) {
    if (!container) return [];
    return Array.prototype.slice.call(
      container.querySelectorAll(
        'button:not([disabled]):not([tabindex="-1"]), a[href]:not([tabindex="-1"]), [role="switch"]:not([tabindex="-1"]), [tabindex]:not([tabindex="-1"])'
      )
    ).filter(function (el) {
      return !el.hasAttribute('hidden') && el.getAttribute('aria-hidden') !== 'true';
    });
  }

  function syncLayoutChrome() {
    if (!bannerEl) return;
    var layout = String(config.bannerLayout || bannerEl.getAttribute('data-ucpf-layout') || 'bar');
    if (['bar', 'modal', 'corner'].indexOf(layout) === -1) {
      layout = 'bar';
    }
    var position = String(config.bannerPosition || bannerEl.getAttribute('data-ucpf-position') || 'left');
    if (['left', 'center', 'right'].indexOf(position) === -1) {
      position = 'left';
    }

    // Force layout class from settings (theme CSS alone is not enough if markup is stale/cached).
    ['bar', 'modal', 'corner'].forEach(function (name) {
      bannerEl.classList.remove('ucpf-banner--' + name);
    });
    ['left', 'center', 'right'].forEach(function (name) {
      bannerEl.classList.remove('ucpf-banner--pos-' + name);
    });
    bannerEl.classList.add('ucpf-banner--' + layout);
    bannerEl.classList.add('ucpf-banner--pos-' + position);
    bannerEl.setAttribute('data-ucpf-layout', layout);
    bannerEl.setAttribute('data-ucpf-position', position);

    var rootEl = bannerEl.parentElement && bannerEl.parentElement.id === 'ucpf-root'
      ? bannerEl.parentElement
      : document.getElementById('ucpf-root');
    if (rootEl) {
      rootEl.setAttribute('data-ucpf-layout', layout);
      rootEl.setAttribute('data-ucpf-position', position);

      // Sync theme class from live config (page caches often keep a stale ucpf-theme-* class).
      var theme = String(config.bannerTheme || '').replace(/[^a-z0-9_]/gi, '');
      if (!theme) {
        var m = (rootEl.className || '').match(/ucpf-theme-([a-z0-9_]+)/i);
        theme = m ? m[1] : 'classic';
      }
      rootEl.className = String(rootEl.className || '')
        .replace(/ucpf-theme-\S+/g, '')
        .replace(/\s+/g, ' ')
        .trim();
      rootEl.classList.add('ucpf-theme-' + theme);
    }

    // Floating prefs button follows the same Banner position setting.
    var fab = document.getElementById('ucpf-fab') || document.querySelector('#ucpf-root .ucpf-fab');
    if (fab) {
      ['left', 'center', 'right'].forEach(function (name) {
        fab.classList.remove('ucpf-fab--pos-' + name);
      });
      fab.classList.add('ucpf-fab--pos-' + position);
      fab.setAttribute('data-ucpf-position', position);
    }

    var overlay = bannerEl.querySelector('.ucpf-modal__overlay');
    if (overlay) {
      if (layout === 'modal') {
        overlay.hidden = false;
        overlay.removeAttribute('hidden');
      } else {
        overlay.hidden = true;
        overlay.setAttribute('hidden', 'hidden');
      }
    }
  }

  function showBanner() {
    if (!bannerEl) return;
    syncLayoutChrome();
    bannerEl.hidden = false;
    bannerEl.classList.remove('ucpf-banner--hidden');
    requestAnimationFrame(function () {
      bannerEl.classList.add('ucpf-banner--visible');
    });
    var focusable = bannerEl.querySelector('button, a[href]');
    if (focusable) focusable.focus();
  }

  function hideBanner() {
    if (!bannerEl) return;
    bannerEl.classList.remove('ucpf-banner--visible');
    bannerEl.classList.add('ucpf-banner--hidden');
    bannerEl.hidden = true;
  }

  function showPrefs() {
    if (!prefsEl) return;
    prefsReturnFocus = document.activeElement;
    prefsDirty = false;
    prefsBaseline = {
      categories: Object.assign({}, state.categories || {}),
      services: Object.assign({}, state.services || {}),
    };
    renderPrefs();
    prefsEl.hidden = false;
    prefsEl.removeAttribute('hidden');
    setPrefsHint('');
    var dialog = prefsEl.querySelector('.ucpf-prefs__dialog');
    var focusable = getFocusable(dialog);
    window.setTimeout(function () {
      if (focusable.length) {
        focusable[0].focus();
      } else if (dialog) {
        dialog.focus();
      }
    }, 0);
  }

  function hidePrefs() {
    if (!prefsEl) return;
    prefsEl.hidden = true;
    prefsEl.setAttribute('hidden', 'hidden');
    setPrefsHint('');
    prefsDirty = false;
    // Keep prefsBaseline until Save so an unchanged Save can skip reload.
    if (prefsReturnFocus && typeof prefsReturnFocus.focus === 'function') {
      window.setTimeout(function () {
        try {
          prefsReturnFocus.focus();
        } catch (err) { /* ignore */ }
        prefsReturnFocus = null;
      }, 0);
    }
  }

  function setPrefsHint(message) {
    if (!prefsEl) return;
    var hint = prefsEl.querySelector('.ucpf-prefs__hint');
    if (!hint) {
      var dialog = prefsEl.querySelector('.ucpf-prefs__dialog');
      if (!dialog) return;
      hint = document.createElement('p');
      hint.className = 'ucpf-prefs__hint';
      hint.setAttribute('role', 'status');
      hint.setAttribute('aria-live', 'polite');
      var footer = dialog.querySelector('.ucpf-prefs__footer');
      if (footer) {
        dialog.insertBefore(hint, footer);
      } else {
        dialog.appendChild(hint);
      }
    }
    if (message) {
      hint.textContent = message;
      hint.hidden = false;
      hint.removeAttribute('hidden');
    } else {
      hint.textContent = '';
      hint.hidden = true;
      hint.setAttribute('hidden', 'hidden');
    }
  }

  function showFab() {
    fabEl = document.getElementById('ucpf-fab') || document.querySelector('.ucpf-fab');
    if (fabEl && state.state !== 'unknown') {
      fabEl.hidden = false;
      fabEl.removeAttribute('hidden');
    }
  }

  function renderPrefs() {
    var container = document.getElementById('ucpf-prefs-categories');
    if (!container) return;
    container.innerHTML = '';
    Object.keys(config.categories || {}).forEach(function (slug) {
      var cat = config.categories[slug];
      var row = document.createElement('div');
      row.className = 'ucpf-prefs__category';
      row.setAttribute('data-ucpf-pref-slug', slug);
      var checked = !!state.categories[slug];
      var locked = !!cat.required;
      var labelId = 'ucpf-cat-label-' + slug;
      var descId = 'ucpf-cat-desc-' + slug;
      var switchLabel = (cat.label || slug) + (locked ? ' (always on)' : '');
      row.innerHTML =
        '<div>' +
          '<p class="ucpf-prefs__category-name" id="' + labelId + '">' + escapeHtml(cat.label || slug) + '</p>' +
          '<p class="ucpf-prefs__category-desc" id="' + descId + '">' + escapeHtml(cat.description || '') + '</p>' +
        '</div>' +
        '<button type="button" class="ucpf-toggle' + (locked ? ' ucpf-toggle--locked' : '') + '"' +
          ' role="switch"' +
          ' aria-checked="' + (checked ? 'true' : 'false') + '"' +
          ' aria-labelledby="' + labelId + '"' +
          ' aria-describedby="' + descId + '"' +
          (locked ? ' aria-disabled="true" tabindex="-1"' : '') +
          ' data-category="' + escapeHtml(slug) + '"' +
          ' title="' + escapeHtml(switchLabel) + '">' +
          '<span class="ucpf-toggle__thumb" aria-hidden="true"></span>' +
          '<span class="screen-reader-text">' + escapeHtml(switchLabel) + '</span>' +
        '</button>';
      container.appendChild(row);
    });

    container.querySelectorAll('.ucpf-toggle:not(.ucpf-toggle--locked)').forEach(function (toggle) {
      function flip() {
        var slug = toggle.getAttribute('data-category');
        var next = toggle.getAttribute('aria-checked') !== 'true';
        toggle.setAttribute('aria-checked', next ? 'true' : 'false');
        state.categories[slug] = next;
        prefsDirty = true;
        setPrefsHint('Changes not saved yet. Press Save Preferences to apply.');
      }
      toggle.addEventListener('click', flip);
      toggle.addEventListener('keydown', function (e) {
        if (e.key === ' ' || e.key === 'Enter') {
          e.preventDefault();
          flip();
        }
      });
    });
  }

  function trapFocus(container, event) {
    if (event.key !== 'Tab' || !container) return;
    var items = getFocusable(container);
    if (!items.length) return;
    var first = items[0];
    var last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function bindUI() {
    bannerEl = document.getElementById('ucpf-banner');
    prefsEl = document.getElementById('ucpf-prefs');
    fabEl = document.getElementById('ucpf-fab') || document.querySelector('.ucpf-fab');

    document.addEventListener('click', function (e) {
      var action = e.target.closest('[data-ucpf-action]');
      if (action) {
        var type = action.getAttribute('data-ucpf-action');
        if (
          consentInFlight &&
          (type === 'accept_all' || type === 'reject_all' || type === 'save_preferences')
        ) {
          e.preventDefault();
          return;
        }
        if (type === 'accept_all') {
          // Always persist cookie + close (banner shim may also call acceptAll).
          UCPF.acceptAll();
        } else if (type === 'reject_all') {
          UCPF.rejectAll();
        } else if (type === 'customize') {
          UCPF.openPreferences();
        } else if (type === 'save_preferences') {
          UCPF.setConsent({ state: 'custom', categories: state.categories, services: state.services, uuid: state.uuid });
        }
      }

      if (e.target.closest('[data-ucpf-open-preferences]')) {
        UCPF.openPreferences();
      }

      // Overlay must not discard unsaved prefs — require Save / Reject / ESC.
      if (e.target.closest('[data-ucpf-close-overlay]')) {
        if (consentInFlight) {
          e.preventDefault();
          return;
        }
        if (prefsEl && !prefsEl.hidden) {
          e.preventDefault();
          setPrefsHint(prefsDirty
            ? 'Press Save Preferences to apply your choices, or Reject All / Escape for essential only.'
            : 'Press Save Preferences to close, or Reject All / Escape for essential only.');
          var saveBtn = prefsEl.querySelector('[data-ucpf-action="save_preferences"]');
          if (saveBtn) saveBtn.focus();
          return;
        }
        UCPF.rejectAll();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        if (consentInFlight) {
          e.preventDefault();
          return;
        }
        if (prefsEl && !prefsEl.hidden) {
          // ESC = essential only (reject) — explicit dismiss that does persist.
          UCPF.rejectAll();
        } else if (bannerEl && !bannerEl.hidden) {
          UCPF.rejectAll();
        }
      }
      if (prefsEl && !prefsEl.hidden) trapFocus(prefsEl.querySelector('.ucpf-prefs__dialog'), e);
      if (bannerEl && !bannerEl.hidden && config.bannerLayout === 'modal') trapFocus(bannerEl.querySelector('.ucpf-banner__panel'), e);
    });
  }

  window.UCPF = {
    getConsent: function () {
      return Object.assign({}, state);
    },
    hasConsent: function (categoryOrService) {
      var privacy = (config && config.privacy) || {};
      var cat = categoryOrService;
      var services = (config && config.services) || {};
      if (services[categoryOrService] && services[categoryOrService].category) {
        cat = services[categoryOrService].category;
      }
      // Privacy enforcement (GPC / Do Not Sell) overrides Accept All for optional categories.
      // functional stays available under GPC-only (checkout embeds); see Privacy_State.
      if (privacy && cat && privacy[cat] === false && cat !== 'necessary' && cat !== 'security') {
        return false;
      }
      if (cat === 'marketing' && privacy.marketing === false) {
        return false;
      }
      if (state.services && Object.prototype.hasOwnProperty.call(state.services, categoryOrService)) {
        return !!state.services[categoryOrService];
      }
      if (state.categories && Object.prototype.hasOwnProperty.call(state.categories, categoryOrService)) {
        return !!state.categories[categoryOrService];
      }
      // Resolve service key → category (matches PHP Consent_Manager).
      if (services[categoryOrService] && services[categoryOrService].category) {
        return !!state.categories[services[categoryOrService].category];
      }
      return false;
    },
    setConsent: function (payload) {
      return applyConsent(payload, 'save_preferences');
    },
    acceptAll: function () {
      // Categories alone drive consent; do not embed the full service catalog in the cookie.
      return applyConsent({
        state: 'accepted_all',
        categories: defaultAccepted(),
        services: {},
        uuid: state.uuid,
      }, 'accept_all');
    },
    rejectAll: function () {
      return applyConsent({
        state: 'rejected_all',
        categories: defaultRejected(),
        services: {},
        uuid: state.uuid,
      }, 'reject_all');
    },
    withdraw: function () {
      if (consentInFlight) {
        return Promise.resolve({ success: false, skipped: true });
      }
      consentInFlight = true;
      setConsentControlsLocked(true);
      clearReshowTimers();
      var local = writeLocalCookie({
        state: 'withdrawn',
        categories: defaultRejected(),
        services: {},
        uuid: state.uuid,
      });
      syncWpConsent(local.categories, local.services);
      syncGtagConsent(local.categories);
      if (window.UCPFLoader && typeof window.UCPFLoader.unloadService === 'function') {
        window.UCPFLoader.unloadService();
      }
      hideBanner();
      hidePrefs();
      showFab();
      dispatch('ucpf:consent:withdrawn', state);
      writeConsentBridge(local);
      writeConsentBackup(local);
      var withdrawReq = fetch(config.restUrl + 'withdraw', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
        credentials: 'same-origin',
        body: JSON.stringify({ uuid: local.uuid || state.uuid || '' }),
      }).catch(function () {
        return null;
      });
      scheduleConsentReload(local);
      return withdrawReq;
    },
    openPreferences: function (options) {
      showPrefs();
      var highlight = options && options.highlight ? String(options.highlight) : '';
      if (!highlight || !prefsEl) {
        return;
      }
      window.setTimeout(function () {
        var rows = prefsEl.querySelectorAll('.ucpf-prefs__category');
        Array.prototype.forEach.call(rows, function (row) {
          row.classList.toggle(
            'ucpf-prefs__category--highlight',
            row.getAttribute('data-ucpf-pref-slug') === highlight
          );
        });
        var target = prefsEl.querySelector('.ucpf-prefs__category[data-ucpf-pref-slug="' + highlight + '"]');
        if (target && typeof target.scrollIntoView === 'function') {
          target.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
      }, 50);
    },
    closePreferences: function () {
      hidePrefs();
    },
    on: function (eventName, callback) {
      if (!listeners[eventName]) listeners[eventName] = [];
      listeners[eventName].push(callback);
    },
    off: function (eventName, callback) {
      if (!listeners[eventName]) return;
      listeners[eventName] = listeners[eventName].filter(function (cb) { return cb !== callback; });
    },
    registerService: function () {
      return false;
    },
    loadService: function (key) {
      if (window.UCPFLoader) window.UCPFLoader.loadService(key);
    },
    unloadService: function (key) {
      if (window.UCPFLoader) window.UCPFLoader.unloadService(key);
    },
  };

  function init() {
    loadState();
    if (state.state !== 'unknown') {
      markConsentDone();
      clearReshowTimers();
      // Handoff / bridge restore: tell guards + network gate before first paint settle.
      dispatch('ucpf:consent:changed', state);
      if (state.state === 'accepted_all') {
        dispatch('ucpf:consent:accepted_all', state);
      }
    }
    if (config.discoverMode) {
      // Discover crawl: allow tags without writing a visitor consent cookie.
      activateDiscoverTracking();
      dispatch('ucpf:ready', state);
      [800, 1600, 3200, 5000, 7000, 9000].forEach(function (ms) {
        window.setTimeout(function () {
          activateDiscoverTracking();
          reportDiscoverCookies();
        }, ms);
      });
      return;
    }
    bindUI();
    function revealUi() {
      bannerEl = document.getElementById('ucpf-banner');
      prefsEl = document.getElementById('ucpf-prefs');
      fabEl = document.getElementById('ucpf-fab') || document.querySelector('.ucpf-fab');
      if (window.__ucpfConsentDone || state.state !== 'unknown') {
        clearReshowTimers();
        hideBanner();
        showFab();
        syncWpConsent(state.categories, state.services);
        syncGtagConsent(state.categories);
        if (window.UCPFLoader) window.UCPFLoader.applyConsent(state);
        return;
      }
      showBanner();
    }
    revealUi();
    // Guests: banner markup may arrive after head scripts (body_open/footer).
    // Only reshow when consent is still unknown — never after accept/reject.
    [100, 500, 1500, 3000].forEach(function (ms) {
      reshowTimers.push(window.setTimeout(function () {
        if (window.__ucpfConsentDone || state.state !== 'unknown') {
          clearReshowTimers();
          return;
        }
        bannerEl = document.getElementById('ucpf-banner');
        if (bannerEl && (bannerEl.hidden || bannerEl.classList.contains('ucpf-banner--hidden'))) {
          showBanner();
        }
      }, ms));
    });
    dispatch('ucpf:ready', state);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
