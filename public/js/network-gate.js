/**
 * Catalog-driven consent gate: block analytics/marketing/functional/security
 * network + script/link injection until the matching category is granted.
 * Runs as early as possible in <head> so builders/themes that bypass wp_enqueue_* still get gated.
 */
(function () {
  'use strict';

  /**
   * ALWAYS run (even if network-gate already booted via SiteGround/Hummingbird duplicate).
   * GF PayPal Checkout must stay loaded, but PayPal SDK is soft-deferred until Embeds.
   * Without this gate, `new GFPPCP` throws / re-inits and stacks duplicate button hosts.
   * Do NOT patch jQuery.fn.trigger (that broke Accept/Reject).
   */
  (function installGfppcpPaypalReadyGate() {
    if (window.__ucpfGfppcpPaypalGateInstalled) {
      return;
    }
    window.__ucpfGfppcpPaypalGateInstalled = true;

    var pending = [];
    var started = false;
    var OrigCtor = null;

    function paypalReady() {
      return !!(
        window.paypal &&
        (window.paypal.Buttons || window.paypal.HostedFields || window.paypal.version)
      );
    }

    function buttonsLive() {
      try {
        return !!document.querySelector(
          '.gform_ppcp_smart_payment_buttons .paypal-buttons iframe, [id^="gform_ppcp_smart_payment_buttons"] .paypal-buttons iframe'
        );
      } catch (eL) {
        return false;
      }
    }


    function wrapCtor(Orig) {
      if (!Orig || typeof Orig !== 'function' || Orig.__ucpfPaypalGated) {
        return Orig;
      }
      function GatedGFPPCP(config) {
        if (!paypalReady()) {
          pending.push({ config: config });
          this.__ucpfWaitingPaypal = true;
          return;
        }
        // One construct only. Do not touch PayPal DOM (pruning broke live buttons).
        if (started || buttonsLive()) {
          this.__ucpfSkippedDup = true;
          return;
        }
        started = true;
        try {
          return Orig.apply(this, arguments);
        } catch (eCtor) {
          started = false;
          throw eCtor;
        }
      }
      GatedGFPPCP.prototype = Orig.prototype;
      GatedGFPPCP.__ucpfPaypalGated = true;
      GatedGFPPCP.__ucpfOrig = Orig;
      try {
        Object.keys(Orig).forEach(function (k) {
          try {
            GatedGFPPCP[k] = Orig[k];
          } catch (eCopy) { /* ignore */ }
        });
      } catch (eKeys) { /* ignore */ }
      return GatedGFPPCP;
    }

    function flushPending() {
      if (!paypalReady()) {
        return 0;
      }
      if (started || buttonsLive()) {
        pending.length = 0;
        return 0;
      }
      var batch = pending.splice(0, pending.length);
      if (!batch.length) {
        return 0;
      }
      var ctor = OrigCtor;
      if (!ctor && window.GFPPCP && window.GFPPCP.__ucpfOrig) {
        ctor = window.GFPPCP.__ucpfOrig;
      }
      if (!ctor && typeof window.GFPPCP === 'function' && !window.GFPPCP.__ucpfPaypalGated) {
        ctor = window.GFPPCP;
      }
      if (!ctor) {
        return 0;
      }
      try {
        started = true;
        var use = ctor.__ucpfOrig || ctor;
        // eslint-disable-next-line new-cap
        new use(batch[0].config);
        return 1;
      } catch (eFlush) {
        started = false;
        return 0;
      }
    }

    window.__ucpfFlushGfppcpAfterPaypal = flushPending;

    try {
      window.addEventListener('ucpf:consent:changed', function () {
        try {
          var cats =
            (window.UCPF && typeof window.UCPF.getConsent === 'function' && window.UCPF.getConsent().categories) ||
            {};
          if (!cats.functional) {
            started = false;
            pending.length = 0;
          }
        } catch (eReset) { /* ignore */ }
      });
    } catch (eListen) { /* ignore */ }

    try {
      var held = typeof window.GFPPCP === 'function' ? wrapCtor(window.GFPPCP) : undefined;
      if (typeof window.GFPPCP === 'function') {
        OrigCtor = window.GFPPCP.__ucpfOrig || window.GFPPCP;
      }
      Object.defineProperty(window, 'GFPPCP', {
        configurable: true,
        enumerable: true,
        get: function () {
          return held;
        },
        set: function (v) {
          if (typeof v === 'function') {
            OrigCtor = v.__ucpfOrig || v;
            held = wrapCtor(v);
          } else {
            held = v;
          }
        },
      });
    } catch (eDef) {
      var tries = 0;
      var poll = window.setInterval(function () {
        tries += 1;
        try {
          if (typeof window.GFPPCP === 'function' && !window.GFPPCP.__ucpfPaypalGated) {
            OrigCtor = window.GFPPCP;
            window.GFPPCP = wrapCtor(window.GFPPCP);
          }
        } catch (ePoll) { /* ignore */ }
        if ((window.GFPPCP && window.GFPPCP.__ucpfPaypalGated) || tries > 80) {
          window.clearInterval(poll);
        }
      }, 50);
    }
  })();

  if (window.__ucpfNetworkGate) {
    return;
  }
  window.__ucpfNetworkGate = true;

  // Nextend Smart Slider (and similar) call customElements.define on every script exec.
  // Consent activate / map-style refire / duplicate parked copies can load them twice;
  // re-define throws and aborts init (hero stays blank). Make define idempotent early.
  try {
    if (typeof customElements !== 'undefined' && customElements.define && !customElements.__ucpfDefinePatched) {
      var nativeCeDefine = customElements.define.bind(customElements);
      customElements.define = function (name, ctor, options) {
        try {
          if (name && typeof customElements.get === 'function' && customElements.get(name)) {
            return;
          }
        } catch (eGet) { /* fall through to native */ }
        return nativeCeDefine(name, ctor, options);
      };
      customElements.__ucpfDefinePatched = true;
    }
  } catch (eCePatch) { /* ignore */ }

  var COOKIE_NAME = 'ucpf_consent';

  function fromBase64Url(packed) {
    try {
      var s = String(packed || '').replace(/-/g, '+').replace(/_/g, '/');
      while (s.length % 4) {
        s += '=';
      }
      return decodeURIComponent(escape(window.atob(s)));
    } catch (eB64) {
      return '';
    }
  }

  /**
   * Brave Shields often drops cookies across reload. Consent.js navigates with
   * `#ucpf_c=` (and optionally `?_ucpf_c=`) so early gates can honor the choice
   * before the main consent script runs.
   */
  function readConsentHandoffEarly() {
    if (window.__ucpfConsentHandoff && window.__ucpfConsentHandoff.categories) {
      return window.__ucpfConsentHandoff;
    }
    try {
      var packed = '';
      if (window.location.hash && /^#ucpf_c=/i.test(window.location.hash)) {
        packed = window.location.hash.replace(/^#ucpf_c=/i, '');
      }
      if (!packed && window.location.search) {
        var m = window.location.search.match(/[?&]_ucpf_c=([^&]*)/);
        packed = m ? m[1] : '';
      }
      if (!packed) {
        return null;
      }
      var data = JSON.parse(fromBase64Url(decodeURIComponent(packed)));
      if (!data || typeof data !== 'object' || !data.categories) {
        return null;
      }
      window.__ucpfConsentHandoff = data;
      window.__ucpfConsentDone = true;
      return data;
    } catch (eHandoff) {
      return null;
    }
  }

  readConsentHandoffEarly();

  function parseCookie() {
    try {
      var match = document.cookie.match(
        new RegExp('(?:^|; )' + COOKIE_NAME.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)')
      );
      if (!match) {
        return null;
      }
      var raw = match[1];
      try {
        return JSON.parse(decodeURIComponent(raw));
      } catch (e1) {
        return JSON.parse(raw);
      }
    } catch (e) {
      return null;
    }
  }

  function categoryAllowed(category) {
    if (window.__ucpfDiscover) {
      return true;
    }
    // Hard privacy deny (GPC / Do Not Sell / opt-in pack) always wins.
    if (window.__ucpfPrivacy && window.__ucpfPrivacy[category] === false) {
      return false;
    }
    if (window.UCPF && typeof window.UCPF.hasConsent === 'function') {
      return !!window.UCPF.hasConsent(category);
    }
    var handoff = readConsentHandoffEarly();
    if (handoff && handoff.categories && Object.prototype.hasOwnProperty.call(handoff.categories, category)) {
      return !!handoff.categories[category];
    }
    var cookie = parseCookie();
    if (cookie && cookie.categories) {
      return !!cookie.categories[category];
    }
    // No consent cookie yet — use jurisdiction model, NOT Privacy_State "true".
    // Privacy_State marks functional/marketing true whenever GPC is absent; that
    // must not bypass opt-in (GDPR / US baseline) before the visitor chooses.
    var consentType = String(window.__ucpfConsentType || 'optin').toLowerCase();
    // Opt-in: pack category_defaults (often security:true) are for UI hints / opt-out
    // models — never a pre-consent free pass for captcha or other optional scripts.
    if (consentType === 'optin' || consentType === 'opt-in') {
      return category === 'necessary';
    }
    var defaults = window.__ucpfCategoryDefaults || null;
    if (defaults && Object.prototype.hasOwnProperty.call(defaults, category)) {
      return !!defaults[category];
    }
    // optout: allow until declined; optin handled above.
    if (consentType === 'optout' || consentType === 'opt-out') {
      return !(window.__ucpfPrivacy && window.__ucpfPrivacy[category] === false);
    }
    return false;
  }

  function matchExtra(url, list) {
    if (!list || !list.length) {
      return false;
    }
    for (var i = 0; i < list.length; i++) {
      var p = list[i];
      if (p && url.indexOf(p) !== -1) {
        return true;
      }
    }
    return false;
  }

  /**
   * Layout webfonts must never be consent-gated — blocking them until Embeds
   * leaves every theme looking broken (tiny text, missing icons).
   */
  function isLayoutFontUrl(url) {
    var u = String(url || '').toLowerCase();
    if (!u) {
      return false;
    }
    return (
      u.indexOf('use.typekit.net') !== -1 ||
      u.indexOf('p.typekit.net') !== -1 ||
      u.indexOf('fonts.googleapis.com') !== -1 ||
      u.indexOf('fonts.gstatic.com') !== -1 ||
      u.indexOf('kit.fontawesome.com') !== -1 ||
      u.indexOf('ka-f.fontawesome.com') !== -1 ||
      u.indexOf('ka-p.fontawesome.com') !== -1 ||
      u.indexOf('use.fontawesome.com') !== -1
    );
  }

  /**
   * Theme / Elementor / WP core layout assets — never consent-gate.
   * Builders must load exactly as enqueued so Cloudflare can cache them untouched.
   */
  function isSiteLayoutAsset(url) {
    if (!url || typeof url !== 'string') {
      return false;
    }
    var u = url.toLowerCase();
    var needles = [
      '/wp-includes/',
      '/wp-admin/',
      '/wp-content/themes/',
      '/wp-content/plugins/elementor/',
      '/wp-content/plugins/elementor-pro/',
      '/wp-content/plugins/hello-elementor',
      '/wp-content/plugins/pro-elements/',
      '/wp-content/plugins/the-plus-addons-for-elementor',
      '/wp-content/plugins/essential-addons-for-elementor',
      '/wp-content/plugins/elementskit',
      '/wp-content/plugins/header-footer-elementor',
      '/wp-content/uploads/elementor/',
      '/wp-content/plugins/smart-slider-3/',
      '/wp-content/plugins/smart-slider-3-pro/',
      'n2.min.js',
      'smartslider-frontend',
      'jquery.min.js',
      'jquery.js',
      'jquery-migrate',
    ];
    for (var i = 0; i < needles.length; i++) {
      if (needles[i] && u.indexOf(needles[i]) !== -1) {
        return true;
      }
    }
    return false;
  }

  /**
   * Site media CDNs / mirrored uploads — never consent-gate.
   * Smush (assetcdn.net) and Jetpack Photon host WP media off-origin; Image src/srcset
   * hooks must not fail-closed those swaps as unknown third-party Marketing trackers.
   */
  function isSiteMediaUrl(url) {
    if (!url || typeof url !== 'string') {
      return false;
    }
    var u = url.toLowerCase();
    if (u.indexOf('/wp-content/uploads/') !== -1) {
      return true;
    }
    if (u.indexOf('assetcdn.net') !== -1) {
      return true;
    }
    // Jetpack Photon: i0.wp.com, i1.wp.com, …
    if (/\/\/i\d+\.wp\.com(\/|$)/.test(u)) {
      return true;
    }
    return false;
  }

  /**
   * Any stylesheet URL — first- or third-party — must never be consent-gated.
   * Gating CSS unstyles the site and historically used href="" → MIME text/html.
   * Consent applies to scripts, iframes, and network beacons only.
   */
  function isStylesheetUrl(url) {
    if (!url || typeof url !== 'string') {
      return false;
    }
    var u = url.toLowerCase();
    if (u.indexOf('data:text/css') === 0) {
      return true;
    }
    return (
      /\.css(\?|#|$)/.test(u) ||
      u.indexOf('/elementor/css/') !== -1 ||
      u.indexOf('text/css') !== -1 ||
      u.indexOf('fonts.googleapis.com/css') !== -1 ||
      u.indexOf('fonts.gstatic.com') !== -1 ||
      u.indexOf('use.typekit.net') !== -1 ||
      u.indexOf('p.typekit.net') !== -1
    );
  }

  /** @deprecated alias — kept so any leftover calls stay safe */
  function isSameOriginStylesheet(url) {
    return isStylesheetUrl(url);
  }

  /** Never use href="" — browsers fetch the HTML document as CSS (MIME text/html). */
  function inertStylesheetHref() {
    return 'data:text/css,/*ucpf-deferred*/';
  }

  function classifyUrl(url) {
    if (!url || typeof url !== 'string') {
      return null;
    }
    var u = url.toLowerCase();
    // Accessibility toolbar — never classify / park (ADA / assistive tech).
    if (isUserWayUrl(u)) {
      return null;
    }
    if (isLayoutFontUrl(u)) {
      return null;
    }

    // Security / CAPTCHA (before broader google matches).
    if (
      u.indexOf('google.com/recaptcha') !== -1 ||
      u.indexOf('gstatic.com/recaptcha') !== -1 ||
      u.indexOf('hcaptcha.com') !== -1 ||
      u.indexOf('newassets.hcaptcha.com') !== -1 ||
      u.indexOf('challenges.cloudflare.com') !== -1 ||
      u.indexOf('friendlycaptcha.com') !== -1 ||
      u.indexOf('friendly-challenge') !== -1 ||
      // First-party captcha plugins (invisible reCAPTCHA for Woo/GF — no google.com URL).
      u.indexOf('recaptcha-woo') !== -1 ||
      u.indexOf('/rcfwc.js') !== -1 ||
      u.indexOf('recaptcha-for-woocommerce') !== -1 ||
      u.indexOf('woocommerce-recaptcha') !== -1
    ) {
      return 'security';
    }

    // Cloudflare Web Analytics (edge-injected type=module beacon) — analytics, not NS/CDN.
    // Keep challenges.cloudflare.com / __cf_bm / cdn-cgi/challenge as security/necessary elsewhere.
    if (
      u.indexOf('static.cloudflareinsights.com') !== -1 ||
      u.indexOf('cloudflareinsights.com') !== -1 ||
      u.indexOf('/cdn-cgi/rum') !== -1 ||
      (u.indexOf('cloudflare.com/cdn-cgi/') !== -1 && u.indexOf('rum') !== -1)
    ) {
      return 'analytics';
    }

    // First-party Zoom / VCZAPI (same-origin plugin assets) — Embeds.
    if (
      u.indexOf('video-conferencing-with-zoom-api') !== -1 ||
      u.indexOf('vczapi-pro') !== -1 ||
      u.indexOf('vczapi-woocommerce') !== -1 ||
      u.indexOf('/vczapi/') !== -1
    ) {
      return 'functional';
    }

    // Google Site Kit analytics bundles (keep consent-mode bridge ungated).
    if (u.indexOf('googlesitekit-consent-mode') !== -1 || (u.indexOf('google-site-kit/') !== -1 && u.indexOf('consent-mode') !== -1)) {
      return null;
    }
    if (
      (u.indexOf('google-site-kit/') !== -1 || u.indexOf('googlesitekit-') !== -1) &&
      u.indexOf('consent-mode') === -1
    ) {
      return 'analytics';
    }

    // Functional: maps / embeds / widgets until Embeds consent (fonts allowlisted above).
    // GSAP / Lottie are handled by isPresentationLibUrl (never gated) — do not classify here.
    if (
      // Lottie still listed for inventory classify when needed — but shouldBlockUrl allows via isPresentationLibUrl.
      // Prefer maps / embeds / widgets below.
      u.indexOf('player.vimeo.com') !== -1 ||
      u.indexOf('vimeo.com/api') !== -1 ||
      u.indexOf('vimeocdn.com') !== -1 ||
      u.indexOf('f.vimeocdn.com') !== -1 ||
      u.indexOf('i.vimeocdn.com') !== -1 ||
      u.indexOf('arclight.vimeo.com') !== -1 ||
      u.indexOf('gtm4wp-vimeo') !== -1 ||
      // First-party map plugins (same-origin) depend on maps.googleapis — park like gtm4wp-vimeo.
      u.indexOf('wpgmza') !== -1 ||
      u.indexOf('wp-google-maps') !== -1 ||
      u.indexOf('/wpgmaps/') !== -1 ||
      u.indexOf('mapster-wp-maps') !== -1 ||
      u.indexOf('google-maps-builder') !== -1 ||
      u.indexOf('flexible-map') !== -1 ||
      u.indexOf('maps.googleapis.com') !== -1 ||
      u.indexOf('maps.google.com') !== -1 ||
      u.indexOf('maps.gstatic.com') !== -1 ||
      u.indexOf('google.com/maps') !== -1 ||
      u.indexOf('api.mapbox.com') !== -1 ||
      u.indexOf('events.mapbox.com') !== -1 ||
      u.indexOf('tiles.mapbox.com') !== -1 ||
      u.indexOf('mapbox.com') !== -1 ||
      u.indexOf('mapbox.cn') !== -1 ||
      u.indexOf('maptiler.com') !== -1 ||
      u.indexOf('openstreetmap.org') !== -1 ||
      u.indexOf('tile.openstreetmap.org') !== -1 ||
      u.indexOf('nominatim.openstreetmap.org') !== -1 ||
      u.indexOf('demotiles.maplibre.org') !== -1 ||
      u.indexOf('unpkg.com/maplibre-gl') !== -1 ||
      u.indexOf('cdn.jsdelivr.net/npm/maplibre-gl') !== -1 ||
      u.indexOf('maplibre.org') !== -1 ||
      u.indexOf('stadiamaps.com') !== -1 ||
      u.indexOf('thunderforest.com') !== -1 ||
      u.indexOf('dev.virtualearth.net') !== -1 ||
      u.indexOf('hereapi.com') !== -1 ||
      u.indexOf('js.api.here.com') !== -1 ||
      u.indexOf('arcgis.com') !== -1 ||
      u.indexOf('arcgisonline.com') !== -1 ||
      u.indexOf('api.tomtom.com') !== -1 ||
      u.indexOf('cdn.tomtom.com') !== -1 ||
      u.indexOf('docs.google.com') !== -1 ||
      u.indexOf('drive.google.com') !== -1 ||
      u.indexOf('open.spotify.com') !== -1 ||
      u.indexOf('embed.spotify.com') !== -1 ||
      u.indexOf('w.soundcloud.com') !== -1 ||
      u.indexOf('wistia.com') !== -1 ||
      u.indexOf('fast.wistia') !== -1 ||
      u.indexOf('js.stripe.com') !== -1 ||
      u.indexOf('hooks.stripe.com') !== -1 ||
      u.indexOf('m.stripe.network') !== -1 ||
      u.indexOf('paypal.com/sdk') !== -1 ||
      u.indexOf('paypalobjects.com') !== -1 ||
      u.indexOf('www.paypal.com/sdk') !== -1 ||
      u.indexOf('c.paypal.com') !== -1 ||
      u.indexOf('c6.paypal.com') !== -1 ||
      u.indexOf('b.stats.paypal.com') !== -1 ||
      u.indexOf('slc.stats.paypal.com') !== -1 ||
      u.indexOf('lvs.stats.paypal.com') !== -1 ||
      u.indexOf('stats.paypal.com') !== -1 ||
      u.indexOf('paypal.com/smart/') !== -1 ||
      u.indexOf('paypal.com/graphql') !== -1 ||
      u.indexOf('paypal.com/xoplatform') !== -1 ||
      u.indexOf('paypal.com/credit-presentment') !== -1 ||
      u.indexOf('braintreegateway.com') !== -1 ||
      u.indexOf('js.braintreegateway.com') !== -1 ||
      u.indexOf('assets.braintreegateway.com') !== -1 ||
      u.indexOf('squareup.com') !== -1 ||
      u.indexOf('squarecdn.com') !== -1 ||
      u.indexOf('web.squarecdn.com') !== -1 ||
      u.indexOf('goshippo.com') !== -1 ||
      u.indexOf('api.goshippo.com') !== -1 ||
      u.indexOf('shippo.com') !== -1 ||
      u.indexOf('onlinetools.ups.com') !== -1 ||
      u.indexOf('wwwapps.ups.com') !== -1 ||
      u.indexOf('tools.usps.com') !== -1 ||
      u.indexOf('shippingapis.com') !== -1 ||
      u.indexOf('apis.fedex.com') !== -1 ||
      u.indexOf('api.dhl.com') !== -1 ||
      u.indexOf('checkout.dhl.com') !== -1 ||
      u.indexOf('api.easypost.com') !== -1 ||
      u.indexOf('easypost.com') !== -1 ||
      u.indexOf('shipstation.com') !== -1 ||
      u.indexOf('avalara.com') !== -1 ||
      u.indexOf('avatax.avalara.net') !== -1 ||
      u.indexOf('api.taxjar.com') !== -1 ||
      u.indexOf('taxjar.com') !== -1 ||
      u.indexOf('printful.com') !== -1 ||
      u.indexOf('assets.calendly.com') !== -1 ||
      u.indexOf('calendly.com') !== -1 ||
      // Field-service / booking form embeds (Jobber Client Hub).
      // NOTE: Amelia is a first-party WP plugin (like Gravity Forms) — do NOT park
      // /ameliabooking/ scripts here; captcha overlay handles Security separately.
      u.indexOf('getjobber.com') !== -1 ||
      u.indexOf('clienthub.getjobber.com') !== -1 ||
      u.indexOf('d3ey4dbjkt2f6s.cloudfront.net') !== -1 ||
      u.indexOf('work_request_embed') !== -1 ||
      // Chat / messaging widgets (majors — catalog covers long-tail).
      u.indexOf('embed.tawk.to') !== -1 ||
      u.indexOf('tawk.to') !== -1 ||
      u.indexOf('code.tidio.co') !== -1 ||
      u.indexOf('tidio.co') !== -1 ||
      u.indexOf('client.crisp.chat') !== -1 ||
      u.indexOf('crisp.chat') !== -1 ||
      u.indexOf('widget.intercom.io') !== -1 ||
      u.indexOf('js.intercomcdn.com') !== -1 ||
      u.indexOf('js.driftt.com') !== -1 ||
      u.indexOf('static.zdassets.com') !== -1 ||
      u.indexOf('ekr.zdassets.com') !== -1 ||
      u.indexOf('static.olark.com') !== -1 ||
      u.indexOf('app.chatwoot.com') !== -1 ||
      u.indexOf('wchat.freshchat.com') !== -1 ||
      u.indexOf('beacon-v2.helpscout.net') !== -1 ||
      u.indexOf('code.jivosite.com') !== -1 ||
      u.indexOf('smartsuppchat.com') !== -1 ||
      u.indexOf('ladesk.com') !== -1 ||
      u.indexOf('vialivechat.com') !== -1 ||
      u.indexOf('apexchat.com') !== -1 ||
      u.indexOf('blazeo.com') !== -1
    ) {
      return 'functional';
    }

    // Analytics / GTM / product analytics / session replay.
    if (
      u.indexOf('google-analytics.com') !== -1 ||
      u.indexOf('analytics.google.com') !== -1 ||
      u.indexOf('/g/collect') !== -1 ||
      u.indexOf('googletagmanager.com/gtag/js') !== -1 ||
      u.indexOf('googletagmanager.com/gtag') !== -1 ||
      u.indexOf('googletagmanager.com/gtm.js') !== -1 ||
      u.indexOf('googletagmanager.com/gtm') !== -1 ||
      u.indexOf('googletagmanager.com/a?') !== -1 ||
      u.indexOf('region1.google-analytics.com') !== -1 ||
      u.indexOf('stats.g.doubleclick.net') !== -1 ||
      u.indexOf('hotjar.com') !== -1 ||
      u.indexOf('static.hotjar.com') !== -1 ||
      u.indexOf('clarity.ms') !== -1 ||
      u.indexOf('www.clarity.ms') !== -1 ||
      u.indexOf('mixpanel.com') !== -1 ||
      u.indexOf('api-js.mixpanel.com') !== -1 ||
      u.indexOf('segment.io') !== -1 ||
      u.indexOf('segment.com') !== -1 ||
      u.indexOf('fullstory.com') !== -1 ||
      u.indexOf('heap-api.com') !== -1 ||
      u.indexOf('heapanalytics.com') !== -1 ||
      u.indexOf('mouseflow.com') !== -1 ||
      u.indexOf('crazyegg.com') !== -1 ||
      u.indexOf('luckyorange.com') !== -1 ||
      u.indexOf('contentsquare.net') !== -1 ||
      u.indexOf('inspectlet.com') !== -1 ||
      u.indexOf('smartlook.com') !== -1 ||
      u.indexOf('logrocket.io') !== -1 ||
      u.indexOf('amplitude.com') !== -1 ||
      u.indexOf('matomo.cloud') !== -1 ||
      u.indexOf('cdn.matomo.cloud') !== -1
    ) {
      return 'analytics';
    }

    // Ads / marketing pixels / ESP trackers.
    if (
      u.indexOf('youtube.com/iframe_api') !== -1 ||
      u.indexOf('www.youtube.com/iframe_api') !== -1 ||
      u.indexOf('youtube.com/s/player') !== -1 ||
      u.indexOf('youtube.com/embed') !== -1 ||
      u.indexOf('youtube-nocookie.com') !== -1 ||
      u.indexOf('gtm4wp-youtube') !== -1 ||
      u.indexOf('i.ytimg.com') !== -1 ||
      u.indexOf('yt3.ggpht.com') !== -1 ||
      u.indexOf('youtube-feed-pro') !== -1 ||
      u.indexOf('sb-youtube.js') !== -1 ||
      u.indexOf('cdninstagram.com') !== -1 ||
      u.indexOf('/plugins/social-wall/') !== -1 ||
      u.indexOf('sb-wall') !== -1 ||
      u.indexOf('googleadservices.com') !== -1 ||
      u.indexOf('googlesyndication.com') !== -1 ||
      u.indexOf('doubleclick.net') !== -1 ||
      u.indexOf('pagead2.googlesyndication.com') !== -1 ||
      u.indexOf('facebook.com/tr') !== -1 ||
      u.indexOf('facebook.net') !== -1 ||
      u.indexOf('connect.facebook.net') !== -1 ||
      (u.indexOf('fbcdn.net') !== -1 && u.indexOf('/tr') !== -1) ||
      u.indexOf('analytics.tiktok.com') !== -1 ||
      u.indexOf('ads.tiktok.com') !== -1 ||
      u.indexOf('snap.licdn.com') !== -1 ||
      u.indexOf('px.ads.linkedin.com') !== -1 ||
      u.indexOf('linkedin.com/px') !== -1 ||
      u.indexOf('sc-static.net') !== -1 ||
      u.indexOf('tr.snapchat.com') !== -1 ||
      u.indexOf('bat.bing.com') !== -1 ||
      u.indexOf('ads.yahoo.com') !== -1 ||
      u.indexOf('pinterest.com/ct') !== -1 ||
      u.indexOf('ct.pinterest.com') !== -1 ||
      u.indexOf('static.ads-twitter.com') !== -1 ||
      u.indexOf('analytics.twitter.com') !== -1 ||
      u.indexOf('t.co/i/adsct') !== -1 ||
      u.indexOf('adnxs.com') !== -1 ||
      u.indexOf('list-manage.com') !== -1 ||
      u.indexOf('chimpstatic.com') !== -1 ||
      u.indexOf('mailchimp-for-woocommerce') !== -1 ||
      u.indexOf('mailchimp-woocommerce') !== -1 ||
      u.indexOf('mcjs-connected') !== -1 ||
      u.indexOf('pixel-tracking') !== -1 ||
      u.indexOf('-pixel.js') !== -1 ||
      u.indexOf('tracking.js') !== -1 ||
      u.indexOf('-tracking.js') !== -1 ||
      u.indexOf('/tracker/') !== -1 ||
      u.indexOf('cdn.taboola.com') !== -1 ||
      u.indexOf('trc.taboola.com') !== -1 ||
      u.indexOf('widgets.outbrain.com') !== -1 ||
      u.indexOf('static.criteo.net') !== -1 ||
      u.indexOf('bidder.criteo.com') !== -1 ||
      u.indexOf('insight.adsrvr.org') !== -1 ||
      u.indexOf('adsrvr.org') !== -1 ||
      u.indexOf('bidr.io') !== -1 ||
      u.indexOf('casalemedia.com') !== -1 ||
      u.indexOf('indexexchange.com') !== -1 ||
      u.indexOf('bidswitch.net') !== -1 ||
      u.indexOf('pubmatic.com') !== -1 ||
      u.indexOf('xad.com') !== -1 ||
      u.indexOf('groundtruth.com') !== -1 ||
      u.indexOf('amazon-adsystem.com') !== -1 ||
      u.indexOf('semcasting.com') !== -1 ||
      u.indexOf('adtini.com') !== -1 ||
      (u.indexOf('googletagmanager.com/gtag') !== -1 && u.indexOf('id=AW-') !== -1)
    ) {
      return 'marketing';
    }

    var extra = window.__ucpfGateExtra || {};
    if (matchExtra(u, extra.suspicion)) {
      return 'marketing';
    }
    if (matchExtra(u, extra.security)) {
      return 'security';
    }
    if (matchExtra(u, extra.functional)) {
      return 'functional';
    }
    if (matchExtra(u, extra.marketing)) {
      return 'marketing';
    }
    if (matchExtra(u, extra.analytics)) {
      return 'analytics';
    }
    return null;
  }

  /** True for YouTube / Vimeo player embed URLs (iframes + player APIs). */
  function isVideoEmbedUrl(url) {
    var u = String(url || '').toLowerCase();
    if (!u) {
      return false;
    }
    return (
      u.indexOf('youtube.com/embed') !== -1 ||
      u.indexOf('youtube-nocookie.com') !== -1 ||
      u.indexOf('youtu.be/') !== -1 ||
      u.indexOf('youtube.com/iframe_api') !== -1 ||
      u.indexOf('youtube.com/s/player') !== -1 ||
      u.indexOf('player.vimeo.com') !== -1 ||
      u.indexOf('vimeo.com/video') !== -1 ||
      u.indexOf('vimeo.com/api') !== -1 ||
      u.indexOf('vimeocdn.com') !== -1 ||
      u.indexOf('arclight.vimeo.com') !== -1 ||
      // Cookie / API host used by the player (sets vuid).
      (u.indexOf('vimeo.com') !== -1 && u.indexOf('vimeo.com/') !== -1)
    );
  }

  /** Payment iframes stay Embeds-only so checkout is not blocked on Marketing. */
  function isPaymentEmbedUrl(url) {
    var u = String(url || '').toLowerCase();
    return (
      u.indexOf('js.stripe.com') !== -1 ||
      u.indexOf('hooks.stripe.com') !== -1 ||
      u.indexOf('m.stripe.network') !== -1 ||
      u.indexOf('paypal.com') !== -1 ||
      u.indexOf('paypalobjects.com') !== -1 ||
      u.indexOf('squareup.com') !== -1 ||
      u.indexOf('squarecdn.com') !== -1 ||
      u.indexOf('braintreegateway.com') !== -1 ||
      u.indexOf('adyen.com') !== -1
    );
  }

  /**
   * GSAP / Lottie / AOS / etc. — presentation motion libs used in Elementor HTML widgets.
   * Never consent-gate: parking breaks document-order + ScrollTrigger pin timelines.
   */
  function isPresentationLibUrl(url) {
    var u = String(url || '').toLowerCase();
    if (!u) {
      return false;
    }
    return (
      u.indexOf('cdn.jsdelivr.net/npm/gsap') !== -1 ||
      u.indexOf('cdnjs.cloudflare.com/ajax/libs/gsap') !== -1 ||
      u.indexOf('unpkg.com/gsap') !== -1 ||
      u.indexOf('greensock.com') !== -1 ||
      u.indexOf('gsap.com') !== -1 ||
      /\/gsap(@[\w.-]+)?\/dist\//i.test(u) ||
      /\/gsap(\.min)?\.js/i.test(u) ||
      u.indexOf('scrolltrigger') !== -1 ||
      u.indexOf('scrolltoplugin') !== -1 ||
      u.indexOf('draggable.min.js') !== -1 ||
      u.indexOf('draggable.js') !== -1 ||
      u.indexOf('splittext') !== -1 ||
      u.indexOf('motionpathplugin') !== -1 ||
      u.indexOf('flip.min.js') !== -1 ||
      u.indexOf('unpkg.com/@lottiefiles') !== -1 ||
      u.indexOf('cdn.jsdelivr.net/npm/@lottiefiles') !== -1 ||
      u.indexOf('cdn.jsdelivr.net/npm/lottie-web') !== -1 ||
      u.indexOf('unpkg.com/lottie-web') !== -1 ||
      u.indexOf('bodymovin') !== -1 ||
      u.indexOf('lottiefiles.com') !== -1 ||
      u.indexOf('dotlottie') !== -1 ||
      u.indexOf('lottie-player') !== -1 ||
      u.indexOf('dotlottie-wc') !== -1 ||
      u.indexOf('dotlottie-player') !== -1 ||
      u.indexOf('cdn.jsdelivr.net/npm/aos') !== -1 ||
      u.indexOf('unpkg.com/aos@') !== -1 ||
      u.indexOf('cdnjs.cloudflare.com/ajax/libs/aos') !== -1 ||
      u.indexOf('cdn.jsdelivr.net/npm/animejs') !== -1 ||
      u.indexOf('unpkg.com/animejs') !== -1 ||
      u.indexOf('cdn.jsdelivr.net/npm/@rive-app') !== -1 ||
      u.indexOf('unpkg.com/@rive-app') !== -1
    );
  }

  /**
   * Third-party embeds/iframes can load Marketing trackers we cannot inspect.
   * Require Marketing + Embeds together (except payment processors and presentation libs).
   */
  function needsMarketingAndEmbeds(url) {
    if (isVideoEmbedUrl(url)) {
      return true;
    }
    if (isPaymentEmbedUrl(url)) {
      return false;
    }
    if (isPresentationLibUrl(url)) {
      return false;
    }
    var kind = classifyUrl(url);
    if (kind === 'functional') {
      return true;
    }
    // Unknown third-party host — iframe/script may load either category.
    if (!kind && !isSameOriginOrLocalUrl(url)) {
      return true;
    }
    // Catalog marketing pixels that are also embed hosts (e.g. social iframes).
    if (kind === 'marketing' && /embed|iframe|player|widget|hub|forms?\./i.test(String(url || ''))) {
      return true;
    }
    return false;
  }

  /** Accessibility toolbar — never park (ADA / assistive tech). */
  function isUserWayUrl(url) {
    var u = String(url || '').toLowerCase();
    return (
      u.indexOf('cdn.userway.org') !== -1 ||
      u.indexOf('api.userway.org') !== -1 ||
      u.indexOf('userway.org') !== -1
    );
  }

  /** First-party Amelia Booking SPA — never park (Gravity Forms model). */
  function isAmeliaPluginUrl(url) {
    var u = String(url || '').toLowerCase();
    return (
      u.indexOf('/ameliabooking/') !== -1 ||
      u.indexOf('wpamelia') !== -1 ||
      u.indexOf('ameliabooking') !== -1
    );
  }

  /** Nextend Smart Slider 3 — never park (customElements cannot re-define). */
  function isSmartSliderUrl(url) {
    var u = String(url || '').toLowerCase();
    return (
      u.indexOf('/smart-slider-3/') !== -1 ||
      u.indexOf('/smart-slider-3-pro/') !== -1 ||
      u.indexOf('n2.min.js') !== -1 ||
      u.indexOf('smartslider-frontend') !== -1 ||
      u.indexOf('smartslider') !== -1
    );
  }

  function shouldBlockUrl(url) {
    if (
      isAmeliaPluginUrl(url) ||
      isUserWayUrl(url) ||
      isSmartSliderUrl(url) ||
      isLayoutFontUrl(url) ||
      isStylesheetUrl(url) ||
      isSiteLayoutAsset(url) ||
      isSiteMediaUrl(url) ||
      // GSAP / Lottie / AOS — Elementor HTML widgets depend on document order.
      // Parking these breaks ScrollTrigger pins and inline DOMContentLoaded inits.
      isPresentationLibUrl(url)
    ) {
      return false;
    }
    // Third-party embeds/iframes: Marketing + Embeds (cannot inspect frame contents).
    if (needsMarketingAndEmbeds(url)) {
      return !(categoryAllowed('marketing') && categoryAllowed('functional'));
    }
    var kind = classifyUrl(url);
    // Payment processors (PayPal / Braintree / Stripe / Square / …) are Embeds-only.
    // Never fall through to unknown→Marketing when isPaymentEmbedUrl matched.
    if (!kind && isPaymentEmbedUrl(url)) {
      kind = 'functional';
    }
    if (kind) {
      return !categoryAllowed(kind);
    }
    // Unknown URL: never gate same-origin / relative / data|blob.
    if (isSameOriginOrLocalUrl(url)) {
      return false;
    }
    // Opt-in packs: block unknown third-party hosts until Marketing consent.
    // Opt-out packs: honor pack defaults via categoryAllowed('marketing').
    return !categoryAllowed('marketing');
  }

  /**
   * Same-origin, relative, or non-network schemes — never fail-closed.
   * @param {string} url
   * @return {boolean}
   */
  function isSameOriginOrLocalUrl(url) {
    var u = String(url || '').trim();
    if (!u) {
      return true;
    }
    var lower = u.toLowerCase();
    if (
      lower.indexOf('data:') === 0 ||
      lower.indexOf('blob:') === 0 ||
      lower.indexOf('about:') === 0 ||
      lower.indexOf('javascript:') === 0
    ) {
      return true;
    }
    // Protocol-relative or absolute with host.
    if (lower.indexOf('//') === 0 || /^[a-z][a-z0-9+.-]*:/i.test(u)) {
      try {
        var resolved = new URL(u, window.location.href);
        var host = String(resolved.hostname || '')
          .toLowerCase()
          .replace(/^www\./, '');
        var site = String(window.location.hostname || '')
          .toLowerCase()
          .replace(/^www\./, '');
        if (!host) {
          return true;
        }
        return host === site;
      } catch (eUrl) {
        return false;
      }
    }
    // Relative path → same origin.
    return true;
  }

  /** Lazy-load placeholders — not real embed targets. */
  function isPlaceholderEmbedSrc(url) {
    var u = String(url || '').trim();
    if (!u || u === 'about:blank') {
      return true;
    }
    var lower = u.toLowerCase();
    if (
      lower.indexOf('data:') === 0 ||
      lower.indexOf('blob:') === 0 ||
      lower.indexOf('javascript:') === 0
    ) {
      return true;
    }
    return false;
  }

  /**
   * Resolve real embed URL from lazy iframes/scripts (data-src over placeholder src).
   *
   * @param {Element} node
   * @return {string}
   */
  function resolveDeferredEmbedUrl(node) {
    if (!node || node.nodeType !== 1) {
      return '';
    }
    var deferAttrs = ['data-src', 'data-lazy-src', 'data-original', 'data-iframe-src', 'data-ezsrc'];
    var deferred = '';
    var i;
    for (i = 0; i < deferAttrs.length; i++) {
      var dv = node.getAttribute(deferAttrs[i]) || '';
      if (dv && !isPlaceholderEmbedSrc(dv)) {
        deferred = dv;
        break;
      }
    }
    var live = node.getAttribute('src') || node.src || '';
    if (deferred && isPlaceholderEmbedSrc(live)) {
      return deferred;
    }
    if (live && !isPlaceholderEmbedSrc(live)) {
      return live;
    }
    return deferred || live || '';
  }

  /** Category to stamp on parked unknown third-party assets. */
  function gateCategoryForUrl(url) {
    if (needsMarketingAndEmbeds(url)) {
      // Prefer functional stamp for dual embeds; loader/guard still require both.
      return classifyUrl(url) || 'functional';
    }
    if (isPaymentEmbedUrl(url)) {
      return classifyUrl(url) || 'functional';
    }
    if (isVideoEmbedUrl(url)) {
      return 'marketing';
    }
    return classifyUrl(url) || 'marketing';
  }

  function isStylesheetLink(node) {
    if (!node || node.tagName !== 'LINK') {
      return false;
    }
    var rel = (node.getAttribute('rel') || '').toLowerCase();
    return rel.indexOf('stylesheet') !== -1 || rel.indexOf('preload') !== -1;
  }

  /**
   * Stash type=module / importmap before parking as text/plain so loader can restore.
   *
   * @param {Element} node
   * @return {void}
   */
  function rememberScriptOriginalType(node) {
    if (!node || node.nodeType !== 1) {
      return;
    }
    if (node.getAttribute('data-ucpf-original-type')) {
      return;
    }
    var t = '';
    try {
      t = String(node.getAttribute('type') || '').trim();
    } catch (eT) {
      t = '';
    }
    var lower = t.toLowerCase();
    if (lower === 'module' || lower === 'importmap') {
      try {
        node.setAttribute('data-ucpf-original-type', lower);
      } catch (eSet) { /* ignore */ }
    }
  }

  function blockScriptNode(node) {
    if (!node || node.tagName !== 'SCRIPT') {
      return false;
    }
    var src = resolveDeferredEmbedUrl(node);
    if (!src) {
      return blockInlineAnimationScriptNode(node);
    }
    if (!shouldBlockUrl(src)) {
      return false;
    }
    // Always re-assert parking — Elementor HTML widgets may restore src/type after gate.
    rememberScriptOriginalType(node);
    if (!node.getAttribute('data-src')) {
      node.setAttribute('data-src', src);
    }
    node.setAttribute('data-ucpf-category', gateCategoryForUrl(src));
    node.setAttribute('data-ucpf-gated', '1');
    // CF Web Analytics ships integrity= on type=module — stash then drop so parking sticks.
    try {
      var integ = node.getAttribute('integrity');
      if (integ && !node.getAttribute('data-ucpf-integrity')) {
        node.setAttribute('data-ucpf-integrity', integ);
        node.removeAttribute('integrity');
      }
    } catch (eInteg) { /* ignore */ }
    try {
      node.type = 'text/plain';
    } catch (eType) {}
    try {
      node.removeAttribute('src');
    } catch (e) {}
    try {
      node.src = '';
    } catch (e2) {}
    return true;
  }

  /**
   * Park consent-gated iframes (YouTube/Vimeo/maps/calendly/Jobber/…) before paint.
   * Server-rendered embeds otherwise load third-party cookies before the banner.
   */
  function blockIframeNode(node) {
    if (!node || node.tagName !== 'IFRAME') {
      return false;
    }
    var src = resolveDeferredEmbedUrl(node);
    if (!src || isPlaceholderEmbedSrc(src) || !shouldBlockUrl(src)) {
      return false;
    }
    var kind = gateCategoryForUrl(src);
    if (!node.getAttribute('data-src') || isPlaceholderEmbedSrc(node.getAttribute('data-src') || '')) {
      node.setAttribute('data-src', src);
    }
    node.setAttribute('data-ucpf-category', kind);
    node.setAttribute('data-ucpf-gated', '1');
    node.removeAttribute('data-ucpf-map-restored');
    // Smush / lazysizes re-copy data-src → src when lazyload classes remain.
    try {
      node.classList.remove('lazyload', 'lazyloaded', 'lazyloading');
      node.classList.add('no-lazyload', 'skip-lazy');
    } catch (eLazy) { /* ignore */ }
    try {
      node.setAttribute('data-no-lazyload', '1');
      node.setAttribute('data-skip-lazy-load', '1');
    } catch (eSkip) { /* ignore */ }
    // Capture layout before removing src — empty iframes often collapse to 0.
    // Skip forced height on Elementor open-inline video widgets (breaks Quick Tip grids).
    var skipKeepH = false;
    try {
      skipKeepH = !!(
        (node.classList && node.classList.contains('elementor-video-iframe')) ||
        (node.closest && node.closest('.elementor-wrapper.elementor-open-inline, .elementor-widget-video'))
      );
    } catch (eSkipH) {
      skipKeepH = false;
    }
    if (skipKeepH) {
      try {
        node.removeAttribute('data-ucpf-iframe-h');
        node.style.removeProperty('min-height');
        node.style.removeProperty('height');
      } catch (eClrH) { /* ignore */ }
    } else if (!node.getAttribute('data-ucpf-iframe-h')) {
      var keepH = 0;
      try {
        keepH = Math.round(node.getBoundingClientRect().height || 0);
      } catch (eH) {
        keepH = 0;
      }
      var attrH = parseInt(node.getAttribute('height'), 10) || 0;
      var styleH = 0;
      try {
        styleH = node.style && node.style.height ? parseInt(node.style.height, 10) || 0 : 0;
      } catch (eSt) {
        styleH = 0;
      }
      keepH = Math.max(keepH, attrH, styleH);
      if (keepH >= 40) {
        node.setAttribute('data-ucpf-iframe-h', String(keepH));
        try {
          node.style.setProperty('min-height', keepH + 'px', 'important');
          node.style.setProperty('height', keepH + 'px', 'important');
        } catch (eKeep) { /* ignore */ }
      }
    }
    try {
      node.removeAttribute('src');
    } catch (eRm) {}
    try {
      node.src = '';
    } catch (eSrc) {}
    return true;
  }

  function blockLinkNode(node) {
    // Stylesheets are never gated — see isStylesheetUrl / repairDeferredStylesheets.
    if (isStylesheetLink(node)) {
      return false;
    }
    return false;
  }

  /**
   * Do not park Elementor / theme inline GSAP init — CDN GSAP is never gated, so
   * parking inline breaks document order and double-fires ScrollTrigger pins.
   */
  function blockInlineAnimationScriptNode() {
    return false;
  }

  function blockNode(node) {
    if (!node || node.nodeType !== 1) {
      return;
    }
    if (node.tagName === 'SCRIPT') {
      blockScriptNode(node);
    } else if (node.tagName === 'IFRAME') {
      blockIframeNode(node);
    } else if (node.tagName === 'LINK') {
      blockLinkNode(node);
    } else if (node.querySelectorAll) {
      Array.prototype.forEach.call(node.querySelectorAll('script[src]'), blockScriptNode);
      Array.prototype.forEach.call(node.querySelectorAll('script:not([src])'), blockInlineAnimationScriptNode);
      Array.prototype.forEach.call(node.querySelectorAll('iframe[src]'), blockIframeNode);
      Array.prototype.forEach.call(
        node.querySelectorAll('link[href][rel*="stylesheet"], link[href][rel*="preload"]'),
        blockLinkNode
      );
    }
  }

  function blockedFetchResult(url) {
    // Prefer AbortError so MapLibre / fetch clients fail closed without trying to
    // decode an empty 204 body as a PNG/JPEG (console: "could not be decoded").
    var u = String(url || '').toLowerCase();
    var looksRaster =
      /\.(png|jpe?g|gif|webp)(\?|#|$)/.test(u) ||
      u.indexOf('/styles/') !== -1 && u.indexOf('/sprite') !== -1;

    if (looksRaster && typeof Uint8Array !== 'undefined') {
      // 1×1 transparent PNG — valid image decode, no third-party pixels.
      var b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
      try {
        var bin = atob(b64);
        var bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) {
          bytes[i] = bin.charCodeAt(i);
        }
        return Promise.resolve(
          new Response(bytes, {
            status: 200,
            statusText: 'UCPF Blocked',
            headers: {
              'Content-Type': 'image/png',
              'Cache-Control': 'no-store',
            },
          })
        );
      } catch (ePng) {}
    }

    if (typeof DOMException === 'function') {
      return Promise.reject(new DOMException('UCPF: blocked until consent', 'AbortError'));
    }
    return Promise.reject(new TypeError('UCPF: blocked until consent'));
  }

  // --- Network hooks ---
  var nativeFetch = window.fetch;
  if (typeof nativeFetch === 'function') {
    window.fetch = function (input, init) {
      var url = typeof input === 'string' ? input : input && input.url ? input.url : '';
      if (shouldBlockUrl(url)) {
        return blockedFetchResult(url);
      }
      return nativeFetch.apply(this, arguments);
    };
  }

  if (window.XMLHttpRequest) {
    var nativeOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url) {
      this.__ucpfUrl = url;
      if (shouldBlockUrl(url)) {
        this.__ucpfBlocked = true;
      }
      return nativeOpen.apply(this, arguments);
    };
    var nativeSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function () {
      if (this.__ucpfBlocked) {
        return;
      }
      return nativeSend.apply(this, arguments);
    };
  }

  if (navigator.sendBeacon) {
    var nativeBeacon = navigator.sendBeacon.bind(navigator);
    navigator.sendBeacon = function (url, data) {
      if (shouldBlockUrl(url)) {
        return false;
      }
      return nativeBeacon(url, data);
    };
  }

  // Tracking pixels via Image constructor + markup <img src>/<srcset>.
  try {
    var NativeImage = window.Image;
    if (NativeImage) {
      window.Image = function (w, h) {
        var img = new NativeImage(w, h);
        return img;
      };
      window.Image.prototype = NativeImage.prototype;
    }
  } catch (eImg) {}

  try {
    var imgSrcDesc = Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'src');
    if (imgSrcDesc && imgSrcDesc.set) {
      Object.defineProperty(HTMLImageElement.prototype, 'src', {
        configurable: true,
        enumerable: true,
        get: function () {
          return imgSrcDesc.get.call(this);
        },
        set: function (value) {
          if (shouldBlockUrl(String(value || ''))) {
            return;
          }
          imgSrcDesc.set.call(this, value);
        },
      });
    }
  } catch (eImgSrc) {}

  try {
    var imgSrcsetDesc = Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'srcset');
    if (imgSrcsetDesc && imgSrcsetDesc.set) {
      Object.defineProperty(HTMLImageElement.prototype, 'srcset', {
        configurable: true,
        enumerable: true,
        get: function () {
          return imgSrcsetDesc.get.call(this);
        },
        set: function (value) {
          var raw = String(value || '');
          // srcset can list multiple URLs — block if any gated candidate appears.
          var parts = raw.split(',');
          for (var si = 0; si < parts.length; si++) {
            var candidate = String(parts[si] || '')
              .trim()
              .split(/\s+/)[0];
            if (candidate && shouldBlockUrl(candidate)) {
              return;
            }
          }
          imgSrcsetDesc.set.call(this, value);
        },
      });
    }
  } catch (eImgSrcset) {}

  try {
    var nativeImgSetAttr = HTMLImageElement.prototype.setAttribute;
    HTMLImageElement.prototype.setAttribute = function (name, value) {
      var attr = String(name || '').toLowerCase();
      if (attr === 'src' && shouldBlockUrl(String(value || ''))) {
        return;
      }
      if (attr === 'srcset') {
        var raw = String(value || '');
        var parts = raw.split(',');
        for (var si = 0; si < parts.length; si++) {
          var candidate = String(parts[si] || '')
            .trim()
            .split(/\s+/)[0];
          if (candidate && shouldBlockUrl(candidate)) {
            return;
          }
        }
      }
      return nativeImgSetAttr.apply(this, arguments);
    };
  } catch (eImgAttr) {}

  // WebSocket / EventSource — block gated third-party realtime beacons.
  try {
    if (typeof window.WebSocket === 'function') {
      var NativeWebSocket = window.WebSocket;
      window.WebSocket = function (url, protocols) {
        if (shouldBlockUrl(String(url || ''))) {
          throw new DOMException('UCPF: blocked until consent', 'SecurityError');
        }
        if (protocols !== undefined) {
          return new NativeWebSocket(url, protocols);
        }
        return new NativeWebSocket(url);
      };
      window.WebSocket.prototype = NativeWebSocket.prototype;
      try {
        window.WebSocket.CONNECTING = NativeWebSocket.CONNECTING;
        window.WebSocket.OPEN = NativeWebSocket.OPEN;
        window.WebSocket.CLOSING = NativeWebSocket.CLOSING;
        window.WebSocket.CLOSED = NativeWebSocket.CLOSED;
      } catch (eWsConst) {}
    }
  } catch (eWs) {}

  try {
    if (typeof window.EventSource === 'function') {
      var NativeEventSource = window.EventSource;
      window.EventSource = function (url, config) {
        if (shouldBlockUrl(String(url || ''))) {
          throw new DOMException('UCPF: blocked until consent', 'SecurityError');
        }
        return config !== undefined ? new NativeEventSource(url, config) : new NativeEventSource(url);
      };
      window.EventSource.prototype = NativeEventSource.prototype;
    }
  } catch (eEs) {}

  // --- Dynamic script / link / iframe injection ---
  var nativeCreateElement = Document.prototype.createElement;
  Document.prototype.createElement = function (tagName, options) {
    var el = nativeCreateElement.call(this, tagName, options);
    var tag = String(tagName).toLowerCase();
    if (tag === 'script') {
      try {
        var desc = Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype, 'src');
        if (desc && desc.set) {
          Object.defineProperty(el, 'src', {
            configurable: true,
            enumerable: true,
            get: function () {
              return desc.get.call(this);
            },
            set: function (value) {
              if (shouldBlockUrl(String(value || ''))) {
                rememberScriptOriginalType(this);
                this.setAttribute('data-src', value);
                this.setAttribute('data-ucpf-category', gateCategoryForUrl(value));
                this.setAttribute('data-ucpf-gated', '1');
                this.type = 'text/plain';
                return;
              }
              desc.set.call(this, value);
            },
          });
        }
      } catch (eSrc) {}
    } else if (tag === 'iframe') {
      try {
        var iframeDesc = Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype, 'src');
        if (iframeDesc && iframeDesc.set) {
          Object.defineProperty(el, 'src', {
            configurable: true,
            enumerable: true,
            get: function () {
              return iframeDesc.get.call(this);
            },
            set: function (value) {
              var v = String(value || '');
              if (v && v !== 'about:blank' && !isPlaceholderEmbedSrc(v) && shouldBlockUrl(v)) {
                this.setAttribute('data-src', v);
                this.setAttribute('data-ucpf-category', gateCategoryForUrl(v));
                this.setAttribute('data-ucpf-gated', '1');
                try {
                  this.classList.remove('lazyload', 'lazyloaded', 'lazyloading');
                } catch (eLz3) { /* ignore */ }
                try {
                  iframeDesc.set.call(this, '');
                } catch (eClr3) {
                  try {
                    this.removeAttribute('src');
                  } catch (eRm3) { /* ignore */ }
                }
                return;
              }
              iframeDesc.set.call(this, value);
            },
          });
        }
      } catch (eIframe) {}
    } else if (tag === 'link') {
      try {
        var hrefDesc = Object.getOwnPropertyDescriptor(HTMLLinkElement.prototype, 'href');
        if (hrefDesc && hrefDesc.set) {
          Object.defineProperty(el, 'href', {
            configurable: true,
            enumerable: true,
            get: function () {
              return hrefDesc.get.call(this);
            },
            set: function (value) {
              // Never defer stylesheets via link.href setter.
              hrefDesc.set.call(this, value);
            },
          });
        }
      } catch (eHref) {}
    }
    return el;
  };

  function wrapInsert(proto, method) {
    var native = proto[method];
    if (!native) {
      return;
    }
    proto[method] = function (node) {
      blockNode(node);
      return native.apply(this, arguments);
    };
  }

  wrapInsert(Node.prototype, 'appendChild');
  wrapInsert(Node.prototype, 'insertBefore');

  // Catch parser-created + Elementor-set iframe/script src before the network request.
  try {
    var iframeSrcDesc = Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype, 'src');
    if (iframeSrcDesc && iframeSrcDesc.set) {
      Object.defineProperty(HTMLIFrameElement.prototype, 'src', {
        configurable: true,
        enumerable: true,
        get: function () {
          return iframeSrcDesc.get.call(this);
        },
        set: function (value) {
          var v = String(value || '');
          if (v && v !== 'about:blank' && !isPlaceholderEmbedSrc(v) && shouldBlockUrl(v)) {
            this.setAttribute('data-src', v);
            this.setAttribute('data-ucpf-category', gateCategoryForUrl(v));
            this.setAttribute('data-ucpf-gated', '1');
            try {
              this.classList.remove('lazyload', 'lazyloaded', 'lazyloading');
            } catch (eLz) { /* ignore */ }
            // Do not leave a prior live player URL on the node.
            try {
              iframeSrcDesc.set.call(this, '');
            } catch (eClr) {
              try {
                this.removeAttribute('src');
              } catch (eRm) { /* ignore */ }
            }
            return;
          }
          iframeSrcDesc.set.call(this, value);
        },
      });
    }
  } catch (eProtoIframe) {}

  try {
    var nativeIframeSetAttr = HTMLIFrameElement.prototype.setAttribute;
    HTMLIFrameElement.prototype.setAttribute = function (name, value) {
      if (String(name || '').toLowerCase() === 'src') {
        var v = String(value || '');
        if (v && v !== 'about:blank' && !isPlaceholderEmbedSrc(v) && shouldBlockUrl(v)) {
          nativeIframeSetAttr.call(this, 'data-src', v);
          nativeIframeSetAttr.call(this, 'data-ucpf-category', gateCategoryForUrl(v));
          nativeIframeSetAttr.call(this, 'data-ucpf-gated', '1');
          try {
            this.classList.remove('lazyload', 'lazyloaded', 'lazyloading');
          } catch (eLz2) { /* ignore */ }
          try {
            this.removeAttribute('src');
          } catch (eRm2) { /* ignore */ }
          return;
        }
      }
      return nativeIframeSetAttr.apply(this, arguments);
    };
  } catch (eSetAttr) {}

  try {
    var scriptSrcDesc = Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype, 'src');
    if (scriptSrcDesc && scriptSrcDesc.set) {
      Object.defineProperty(HTMLScriptElement.prototype, 'src', {
        configurable: true,
        enumerable: true,
        get: function () {
          return scriptSrcDesc.get.call(this);
        },
        set: function (value) {
          var v = String(value || '');
          if (v && shouldBlockUrl(v)) {
            rememberScriptOriginalType(this);
            this.setAttribute('data-src', v);
            this.setAttribute('data-ucpf-category', gateCategoryForUrl(v));
            this.setAttribute('data-ucpf-gated', '1');
            try {
              var integ = this.getAttribute('integrity');
              if (integ && !this.getAttribute('data-ucpf-integrity')) {
                this.setAttribute('data-ucpf-integrity', integ);
                this.removeAttribute('integrity');
              }
            } catch (eIn) { /* ignore */ }
            this.type = 'text/plain';
            return;
          }
          scriptSrcDesc.set.call(this, value);
        },
      });
    }
  } catch (eProtoScript) {}

  try {
    var nativeScriptSetAttr = HTMLScriptElement.prototype.setAttribute;
    HTMLScriptElement.prototype.setAttribute = function (name, value) {
      var attrName = String(name == null ? '' : name);
      if (!attrName) {
        return;
      }
      // Optimizers (Hummingbird combine/delay) sometimes call setAttribute(url).
      if (
        attrName.indexOf('://') !== -1 ||
        attrName.indexOf('/wp-content/') !== -1 ||
        /^\s*https?:/i.test(attrName) ||
        /\s/.test(attrName)
      ) {
        return;
      }
      try {
        if (attrName.toLowerCase() === 'src') {
          var v = String(value || '');
          if (v && shouldBlockUrl(v)) {
            rememberScriptOriginalType(this);
            nativeScriptSetAttr.call(this, 'data-src', v);
            nativeScriptSetAttr.call(this, 'data-ucpf-category', gateCategoryForUrl(v));
            nativeScriptSetAttr.call(this, 'data-ucpf-gated', '1');
            nativeScriptSetAttr.call(this, 'type', 'text/plain');
            return;
          }
        }
        return nativeScriptSetAttr.apply(this, arguments);
      } catch (eSet) {
        /* ignore invalid names from third-party optimizers */
      }
    };
  } catch (eScriptAttr) {}

  if (window.MutationObserver) {
    try {
      var mo = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          Array.prototype.forEach.call(m.addedNodes || [], blockNode);
        });
      });
      mo.observe(document.documentElement, { childList: true, subtree: true });
    } catch (eMo) {}
    try {
      var attrMo = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          var target = m.target;
          if (!target || target.nodeType !== 1 || target.tagName !== 'IFRAME') {
            return;
          }
          var name = String(m.attributeName || '').toLowerCase();
          if (name === 'src' || name === 'data-src' || name === 'data-lazy-src') {
            blockIframeNode(target);
          }
        });
      });
      attrMo.observe(document.documentElement, {
        subtree: true,
        attributes: true,
        attributeFilter: ['src', 'data-src', 'data-lazy-src'],
      });
    } catch (eAttrMo) {}
  }

  function repairDeferredStylesheets() {
    try {
      // Self-heal: older UCPF / cached HTML may still have deferred stylesheets.
      // Always restore real href — CSS is never consent-gated.
      document
        .querySelectorAll('link[data-href][data-ucpf-deferred], link[data-href][data-ucpf-gated], link[href=""][data-href], link[data-ucpf-deferred]')
        .forEach(function (node) {
          var real = node.getAttribute('data-href') || '';
          if (!real) {
            var attr = node.getAttribute('href');
            // href="" alone → browser loads this HTML document as CSS (MIME text/html).
            if (attr === null || attr === '') {
              try {
                node.setAttribute('href', inertStylesheetHref());
              } catch (eInert) {}
            }
            return;
          }
          try {
            node.setAttribute('href', real);
            node.removeAttribute('data-href');
            node.removeAttribute('data-ucpf-deferred');
            node.removeAttribute('data-ucpf-gated');
            node.removeAttribute('data-ucpf-category');
            node.removeAttribute('data-ucpf-service');
          } catch (eRestore) {}
        });
    } catch (eRepair) {}
  }

  function scanExisting() {
    repairDeferredStylesheets();
    try {
      Array.prototype.forEach.call(document.querySelectorAll('script[src], script[data-src]'), blockScriptNode);
      Array.prototype.forEach.call(document.querySelectorAll('script:not([src])'), blockInlineAnimationScriptNode);
      Array.prototype.forEach.call(
        document.querySelectorAll('iframe[src], iframe[data-src], iframe[data-lazy-src]'),
        blockIframeNode
      );
      Array.prototype.forEach.call(
        document.querySelectorAll('link[href][rel*="stylesheet"], link[href][rel*="preload"]'),
        blockLinkNode
      );
    } catch (e) {}
  }
  scanExisting();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scanExisting);
  }

  // Shared with loader for post-reject neutralization.
  window.__ucpfClassifyUrl = classifyUrl;
  window.__ucpfRescanGate = scanExisting;
  window.__ucpfShouldBlockUrl = shouldBlockUrl;
  window.__ucpfNeedsMarketingAndEmbeds = needsMarketingAndEmbeds;
  window.__ucpfIsPresentationLibUrl = isPresentationLibUrl;
  window.__ucpfResolveDeferredEmbedUrl = resolveDeferredEmbedUrl;
  window.__ucpfIsPlaceholderEmbedSrc = isPlaceholderEmbedSrc;

  /**
   * SiteGround / peers sometimes drop ucpf-loader from the page. Without it, every
   * text/plain parked script (PayPal SDK, captcha, GTM) stays dead after Accept.
   */
  function resolveLoaderUrl() {
    try {
      var link = document.querySelector(
        'link[href*="universal-consent-privacy-framework/public/css/"], link[href*="universal-consent-privacy-framework"]'
      );
      if (link && link.href) {
        return String(link.href).replace(/\/public\/css\/[^?#]*/i, '/public/js/loader.js').replace(/[?#].*$/, '');
      }
    } catch (eLink) { /* ignore */ }
    try {
      var s = document.querySelector('script[src*="universal-consent-privacy-framework/public/js/"]');
      if (s && s.src) {
        return String(s.src).replace(/\/public\/js\/[^/?#]+/i, '/public/js/loader.js').replace(/[?#].*$/, '');
      }
    } catch (eScript) { /* ignore */ }
    return '';
  }

  function emergencyActivateParkedScripts() {
    if (!window.UCPF || typeof window.UCPF.hasConsent !== 'function') {
      return 0;
    }
    var n = 0;
    var deferredDeps = [];
    var nodes = document.querySelectorAll(
      'script[type="text/plain"][data-src], script[data-ucpf-gated="1"][data-src]'
    );
    function activateOne(node, onLoad) {
      var src = node.getAttribute('data-src') || '';
      var cat = node.getAttribute('data-ucpf-category') || '';
      if (!src) {
        return false;
      }
      if (cat && !window.UCPF.hasConsent(cat)) {
        return false;
      }
      if (!cat && typeof window.__ucpfShouldBlockUrl === 'function' && window.__ucpfShouldBlockUrl(src)) {
        return false;
      }
      try {
        var script = document.createElement('script');
        Array.prototype.slice.call(node.attributes || []).forEach(function (attr) {
          if (!attr || !attr.name) return;
          if (
            attr.name === 'type' ||
            attr.name === 'data-src' ||
            attr.name === 'src' ||
            attr.name === 'data-ucpf-category' ||
            attr.name === 'data-ucpf-service' ||
            attr.name === 'data-ucpf-gated' ||
            attr.name === 'data-ucpf-original-type'
          ) {
            return;
          }
          try {
            script.setAttribute(attr.name, attr.value);
          } catch (eA) { /* ignore */ }
        });
        script.src = src;
        var origType = (node.getAttribute('data-ucpf-original-type') || '').trim();
        script.type = origType === 'module' || origType === 'importmap' ? origType : 'text/javascript';
        var integ = node.getAttribute('data-ucpf-integrity');
        if (integ) {
          try {
            script.setAttribute('integrity', integ);
          } catch (eIg) { /* ignore */ }
        }
        if (typeof onLoad === 'function') {
          script.addEventListener('load', onLoad);
          script.addEventListener('error', onLoad);
        }
        if (node.parentNode) {
          node.parentNode.replaceChild(script, node);
          return true;
        }
      } catch (eAct) { /* ignore */ }
      return false;
    }
    for (var i = 0; i < nodes.length; i++) {
      var node = nodes[i];
      var src = (node.getAttribute('data-src') || '').toLowerCase();
      // Hold GF PPCP frontend until PayPal SDK has loaded.
      if (src.indexOf('gravityformsppcp') !== -1 && src.indexOf('frontend') !== -1) {
        deferredDeps.push(node);
        continue;
      }
      var isPaypalSdk = src.indexOf('paypal.com/sdk') !== -1 || src.indexOf('paypalobjects.com') !== -1;
      if (activateOne(node, isPaypalSdk ? function () {
        deferredDeps.forEach(function (dep) {
          if (dep && dep.parentNode) {
            activateOne(dep);
          }
        });
        deferredDeps = [];
        try {
          if (typeof window.__ucpfFlushGfppcpAfterPaypal === 'function') {
            window.__ucpfFlushGfppcpAfterPaypal();
          }
        } catch (eFlushEm) { /* ignore */ }
      } : null)) {
        n += 1;
      }
    }
    if (window.paypal || deferredDeps.length) {
      // No SDK was parked (already live) — release deps now.
      if (window.paypal || !document.querySelector('script[src*="paypal.com/sdk"]')) {
        deferredDeps.forEach(function (dep) {
          if (dep && dep.parentNode && activateOne(dep)) {
            n += 1;
          }
        });
      }
    }
    return n;
  }

  function ensureLoaderThenApply() {
    if (window.UCPFLoader && typeof window.UCPFLoader.applyConsent === 'function') {
      window.UCPFLoader.applyConsent();
      return;
    }
    var url = resolveLoaderUrl();
    if (url && !window.__ucpfLoaderInjectAttempted) {
      window.__ucpfLoaderInjectAttempted = true;
      var s = document.createElement('script');
      s.src = url;
      s.setAttribute('data-cfasync', 'false');
      s.setAttribute('data-no-optimize', '1');
      s.onload = function () {
        if (window.UCPFLoader && typeof window.UCPFLoader.applyConsent === 'function') {
          window.UCPFLoader.applyConsent();
        } else {
          emergencyActivateParkedScripts();
        }
      };
      s.onerror = function () {
        emergencyActivateParkedScripts();
      };
      (document.head || document.documentElement).appendChild(s);
      return;
    }
    emergencyActivateParkedScripts();
  }

  window.addEventListener('ucpf:consent:changed', function () {
    // Accept/Reject is about to hard-reload — do not activate every parked asset first.
    if (window.__ucpfConsentReloadPending) {
      return;
    }
    // Re-defer any live gated tags before/while the loader runs (e.g. after Reject All).
    scanExisting();
    ensureLoaderThenApply();
  });

  // Returning visitors: consent already stored, but optimizer may have dropped loader.
  function bootEnsureLoader() {
    if (window.__ucpfConsentReloadPending) {
      return;
    }
    if (!window.UCPF || typeof window.UCPF.hasConsent !== 'function') {
      return;
    }
    var hasParked = !!document.querySelector(
      'script[type="text/plain"][data-src], script[data-ucpf-gated="1"][data-src]'
    );
    if (!hasParked) {
      return;
    }
    // Any granted category with parked assets needs activation.
    ensureLoaderThenApply();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootEnsureLoader);
  } else {
    bootEnsureLoader();
  }
  window.setTimeout(bootEnsureLoader, 0);

  /**
   * Heal missing Elementor Pro webpack runtime (older Pro builds only).
   * Elementor Pro 4.3+ ships self-contained frontend bundles — webpack-pro.runtime
   * may 404; do not inject a ghost URL (404 HTML → SyntaxError at :1).
   * When optimizers strip a real runtime but leave frontend.min.js, chunks land on a
   * plain array and elementorProFrontend never boots (mobile nav/popups).
   */
  function healElementorProWebpackRuntime() {
    if (window.__ucpfElementorProRuntimeHealDone) {
      return;
    }
    if (typeof window.elementorProFrontend !== 'undefined') {
      window.__ucpfElementorProRuntimeHealDone = true;
      return;
    }
    var hasProFrontend =
      document.getElementById('elementor-pro-frontend-js') ||
      document.querySelector('script[src*="elementor-pro/assets/js/frontend"]');
    if (!hasProFrontend) {
      return;
    }
    if (document.querySelector('script[data-ucpf-elementor-pro-runtime-heal]')) {
      return;
    }
    window.__ucpfElementorProRuntimeHealDone = true;
    var base =
      (window.ElementorProFrontendConfig &&
        window.ElementorProFrontendConfig.urls &&
        window.ElementorProFrontendConfig.urls.assets) ||
      '/wp-content/plugins/elementor-pro/assets/';
    var src = String(base).replace(/\/?$/, '/') + 'js/webpack-pro.runtime.min.js';
    fetch(src, { method: 'GET', cache: 'no-store', credentials: 'omit' })
      .then(function (res) {
        var ct = (res.headers && res.headers.get('content-type')) || '';
        var isJs = ct.indexOf('javascript') !== -1 || ct.indexOf('ecmascript') !== -1;
        if (!res.ok || !isJs) {
          return;
        }
        var s = document.createElement('script');
        s.src = src;
        s.setAttribute('data-ucpf-elementor-pro-runtime-heal', '1');
        s.setAttribute('data-cfasync', 'false');
        s.setAttribute('data-no-optimize', '1');
        s.onload = function () {
          try {
            if (window.jQuery) {
              window.jQuery(window).trigger('elementor/frontend/init');
            }
          } catch (eInit) {}
        };
        (document.head || document.documentElement).appendChild(s);
      })
      .catch(function () {
        /* missing file / network — leave Pro alone */
      });
  }
  function scheduleElementorProRuntimeHeal() {
    healElementorProWebpackRuntime();
    window.setTimeout(healElementorProWebpackRuntime, 0);
    window.setTimeout(healElementorProWebpackRuntime, 500);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleElementorProRuntimeHeal);
  } else {
    scheduleElementorProRuntimeHeal();
  }
  window.addEventListener('load', function () {
    window.setTimeout(healElementorProWebpackRuntime, 0);
  });

})();
