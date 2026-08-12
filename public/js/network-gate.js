/**
 * Catalog-driven consent gate: block analytics/marketing/functional/security
 * network + script/link injection until the matching category is granted.
 * Runs as early as possible in <head> so builders/themes that bypass wp_enqueue_* still get gated.
 */
(function () {
  'use strict';

  if (window.__ucpfNetworkGate) {
    return;
  }
  window.__ucpfNetworkGate = true;

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
    var defaults = window.__ucpfCategoryDefaults || null;
    if (defaults && Object.prototype.hasOwnProperty.call(defaults, category)) {
      return !!defaults[category];
    }
    // optout: allow until declined; optin: deny until accepted.
    if (consentType === 'optout') {
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
      u.indexOf('friendly-challenge') !== -1
    ) {
      return 'security';
    }

    // Functional: maps / embeds / widgets until Embeds consent (fonts allowlisted above).
    if (
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
      u.indexOf('ladesk.com') !== -1
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
      u.indexOf('insight.adsrvr.org') !== -1
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
      u.indexOf('vimeocdn.com') !== -1 ||
      u.indexOf('arclight.vimeo.com') !== -1
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
   * Third-party embeds/iframes can load Marketing trackers we cannot inspect.
   * Require Marketing + Embeds together (except payment processors).
   */
  function needsMarketingAndEmbeds(url) {
    if (isVideoEmbedUrl(url)) {
      return true;
    }
    if (isPaymentEmbedUrl(url)) {
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

  function shouldBlockUrl(url) {
    if (
      isAmeliaPluginUrl(url) ||
      isUserWayUrl(url) ||
      isLayoutFontUrl(url) ||
      isStylesheetUrl(url) ||
      isSiteLayoutAsset(url)
    ) {
      return false;
    }
    // Third-party embeds/iframes: Marketing + Embeds (cannot inspect frame contents).
    if (needsMarketingAndEmbeds(url)) {
      return !(categoryAllowed('marketing') && categoryAllowed('functional'));
    }
    var kind = classifyUrl(url);
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

  /** Category to stamp on parked unknown third-party assets. */
  function gateCategoryForUrl(url) {
    if (needsMarketingAndEmbeds(url)) {
      // Prefer functional stamp for dual embeds; loader/guard still require both.
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

  function blockScriptNode(node) {
    if (!node || node.tagName !== 'SCRIPT') {
      return false;
    }
    var src = node.getAttribute('src') || node.getAttribute('data-src') || node.src || '';
    if (!src || !shouldBlockUrl(src)) {
      return false;
    }
    // Always re-assert parking — Elementor HTML widgets may restore src/type after gate.
    if (!node.getAttribute('data-src')) {
      node.setAttribute('data-src', src);
    }
    node.setAttribute('data-ucpf-category', gateCategoryForUrl(src));
    node.setAttribute('data-ucpf-gated', '1');
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
    var src = node.getAttribute('src') || node.getAttribute('data-src') || node.src || '';
    if (!src || src === 'about:blank' || !shouldBlockUrl(src)) {
      return false;
    }
    var kind = gateCategoryForUrl(src);
    if (!node.getAttribute('data-src')) {
      node.setAttribute('data-src', src);
    }
    node.setAttribute('data-ucpf-category', kind);
    node.setAttribute('data-ucpf-gated', '1');
    node.removeAttribute('data-ucpf-map-restored');
    // Capture layout before removing src — empty iframes often collapse to 0.
    if (!node.getAttribute('data-ucpf-iframe-h')) {
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
              if (v && v !== 'about:blank' && shouldBlockUrl(v)) {
                this.setAttribute('data-src', v);
                this.setAttribute('data-ucpf-category', gateCategoryForUrl(v));
                this.setAttribute('data-ucpf-gated', '1');
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
          if (v && v !== 'about:blank' && shouldBlockUrl(v)) {
            this.setAttribute('data-src', v);
            this.setAttribute('data-ucpf-category', gateCategoryForUrl(v));
            this.setAttribute('data-ucpf-gated', '1');
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
        if (v && v !== 'about:blank' && shouldBlockUrl(v)) {
          nativeIframeSetAttr.call(this, 'data-src', v);
          nativeIframeSetAttr.call(this, 'data-ucpf-category', gateCategoryForUrl(v));
          nativeIframeSetAttr.call(this, 'data-ucpf-gated', '1');
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
            this.setAttribute('data-src', v);
            this.setAttribute('data-ucpf-category', gateCategoryForUrl(v));
            this.setAttribute('data-ucpf-gated', '1');
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
      Array.prototype.forEach.call(document.querySelectorAll('script[src]'), blockScriptNode);
      Array.prototype.forEach.call(document.querySelectorAll('iframe[src]'), blockIframeNode);
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

  window.addEventListener('ucpf:consent:changed', function () {
    // Accept/Reject is about to hard-reload — do not activate every parked asset first.
    if (window.__ucpfConsentReloadPending) {
      return;
    }
    // Re-defer any live gated tags before/while the loader runs (e.g. after Reject All).
    scanExisting();
    if (window.UCPFLoader && typeof window.UCPFLoader.applyConsent === 'function') {
      window.UCPFLoader.applyConsent();
    }
  });
})();
