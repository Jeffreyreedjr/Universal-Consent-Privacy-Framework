/**
 * Consent surface guard — captcha forms, maps, and gated embeds.
 * When the required category is denied, show a theme-matched blocking notice
 * so visitors do not interact with a broken surface (or lose form input).
 */
(function () {
  'use strict';

  if (window.__ucpfConsentSurfaceGuard) {
    return;
  }
  window.__ucpfConsentSurfaceGuard = true;
  // Legacy flag used by earlier builds.
  window.__ucpfCaptchaGuard = true;

  /**
   * When true: skip Elementor Motion FX / sticky layout recovery hacks only.
   * Consent overlays, captcha covers, and video parking ALWAYS run on every
   * builder (Elementor, Divi, Gutenberg, Bricks, etc.) — GDPR surfaces are not optional.
   */
  function leaveBuildersAlone() {
    var c = window.ucpfConfig || {};
    return c.leaveBuildersAlone !== false;
  }

  function isBuilderChrome(node) {
    if (!node || node.nodeType !== 1) {
      return false;
    }
    try {
      if (node.closest) {
        return !!(
          node.closest('.elementor') ||
          node.closest('.elementor-element') ||
          node.closest('.elementor-widget') ||
          node.closest('[data-elementor-type]') ||
          node.closest('.e-con') ||
          node.closest('.et_pb_section') ||
          node.closest('.et_pb_module') ||
          node.closest('.brxe-section') ||
          node.closest('.fl-builder-content') ||
          node.closest('.oxygen-body') ||
          node.closest('.breakdance')
        );
      }
    } catch (eB) {}
    return false;
  }

  var TOKEN_PROPS = [
    '--ucpf-black',
    '--ucpf-surface',
    '--ucpf-cream',
    '--ucpf-muted',
    '--ucpf-border',
    '--ucpf-accent',
    '--ucpf-accent-hover',
    '--ucpf-accent-active',
    '--ucpf-accent-2',
    '--ucpf-on-accent',
    '--ucpf-label',
    '--ucpf-focus',
    '--ucpf-focus-ring',
    '--ucpf-shadow-panel',
    '--ucpf-font-sans',
    '--ucpf-radius-soft',
  ];

  /**
   * Provider-first markers (any theme/plugin), then popular WP builder wrappers.
   * Broad [class*="…"] / [name*="…"] patterns are only used via form heuristic
   * (see formHasCaptchaSignal) to avoid guarding non-form page widgets.
   */
  var CAPTCHA_MARKERS = [
    // —— Cloudflare Turnstile ——
    '.cf-turnstile',
    '#cf-turnstile',
    '.cf-turnstile-wrapper',
    '[data-turnstile]',
    '[name="cf-turnstile-response"]',
    '[name*="cf-turnstile"]',
    'iframe[src*="challenges.cloudflare.com"]',
    'iframe[data-src*="challenges.cloudflare.com"]',
    'iframe[src*="turnstile"]',
    'iframe[data-src*="turnstile"]',
    // —— Google reCAPTCHA ——
    '.g-recaptcha',
    '.grecaptcha-badge',
    '.grecaptcha-logo',
    '#g-recaptcha-response',
    'textarea[name="g-recaptcha-response"]',
    '[name*="g-recaptcha"]',
    '[id*="g-recaptcha"]',
    'iframe[src*="recaptcha"]',
    'iframe[data-src*="recaptcha"]',
    'iframe[src*="google.com/recaptcha"]',
    'iframe[data-src*="google.com/recaptcha"]',
    'iframe[title*="reCAPTCHA"]',
    'iframe[title*="recaptcha"]',
    // Empty widget hosts (Forminator / Elementor / custom)
    'div[data-sitekey][data-size]',
    'div[data-sitekey][data-theme]',
    'div[data-sitekey][data-callback]',
    // —— hCaptcha ——
    '.h-captcha',
    '[name="h-captcha-response"]',
    '[name*="h-captcha"]',
    'iframe[src*="hcaptcha.com"]',
    'iframe[data-src*="hcaptcha.com"]',
    'iframe[src*="newassets.hcaptcha.com"]',
    'iframe[title*="hCaptcha"]',
    'iframe[title*="hcaptcha"]',
    // —— Friendly Captcha ——
    '.frc-captcha',
    '[class*="frc-captcha"]',
    '[name*="frc-"]',
    'iframe[src*="friendlycaptcha"]',
    'iframe[data-src*="friendlycaptcha"]',
    // —— Gravity Forms ——
    '.ginput_recaptcha',
    '.gform_recaptcha',
    '.gfield--type-captcha',
    '.ginput_container_captcha',
    '.gform_captcha',
    '.gform_captcha_button',
    '.gform_turnstile',
    '.gf-cloudflare-turnstile',
    '.cf-turnstile-response',
    '.gform_wrapper .gfield--type-captcha',
    'form[id^="gform_"] .gfield--type-captcha',
    'form[id^="gform_"] [data-sitekey]',
    // —— Forminator ——
    '.forminator-g-recaptcha',
    '.forminator-hcaptcha',
    '.forminator-captcha',
    '.forminator-field-captcha',
    '.forminator-captcha-left',
    '.forminator-captcha-right',
    '[class*="forminator"][class*="recaptcha"]',
    '[class*="forminator"][class*="hcaptcha"]',
    '[class*="forminator"][class*="turnstile"]',
    // —— WPForms / Fluent / CF7 / Ninja ——
    '.wpforms-recaptcha-container',
    '.wpforms-is-recaptcha',
    '.wpforms-captcha-invisible',
    '.ff-el-recaptcha',
    '.ff-el-hcaptcha',
    '.wpcf7-form-control.g-recaptcha',
    '.wpcf7-recaptcha',
    '.nf-field .g-recaptcha',
    // —— Elementor Form ——
    '.elementor-field-type-recaptcha',
    '.elementor-field-type-recaptcha_v3',
    '.elementor-g-recaptcha',
    // —— Formidable / Quform / HappyForms / JetFormBuilder ——
    '.frm-g-recaptcha',
    '[id*="frm_field"][class*="captcha"]',
    '.frm_form_field[class*="captcha"]',
    '.quform-captcha',
    '.quform-recaptcha',
    '.happyforms-part--recaptcha',
    '.happyforms-part--captcha',
    '[class*="jet-form"][class*="recaptcha"]',
    '[class*="jet-form"][class*="hcaptcha"]',
    '[class*="jet-form"][class*="turnstile"]',
    // —— Kadence / Spectra / Bricks / Divi ——
    '[class*="kb-recaptcha"]',
    '[class*="uagb"][class*="recaptcha"]',
    '[class*="brxe"][class*="recaptcha"]',
    '.et_pb_contact_captcha',
    // —— WooCommerce captcha plugins ——
    '[class*="woo"][class*="recaptcha"]',
    '[class*="woo"][class*="hcaptcha"]',
    '[class*="woo"][class*="turnstile"]',
    // —— Simple Cloudflare Turnstile / BestWebSoft Google Captcha ——
    '[class*="cf-turnstile"]',
    '.gglcptch',
    '#gglcptch_recaptcha',
    // —— Amelia Booking (captcha often injected on payment step) ——
    '#amelia-container .g-recaptcha',
    '#amelia-container .cf-turnstile',
    '#amelia-container .h-captcha',
    '.am-fs__wrapper .g-recaptcha',
    '.am-fs__wrapper .cf-turnstile',
    '.am-fs__wrapper .h-captcha',
    // —— UCPF-blocked security scripts ——
    'script[data-ucpf-category="security"][src*="recaptcha"]',
    'script[data-ucpf-category="security"][src*="hcaptcha"]',
    'script[data-ucpf-category="security"][src*="challenges.cloudflare.com"]',
    'script[data-ucpf-category="security"][src*="friendlycaptcha"]',
    'script[type="text/plain"][data-src*="recaptcha"]',
    'script[type="text/plain"][data-src*="hcaptcha"]',
    'script[type="text/plain"][data-src*="challenges.cloudflare.com"]',
    'script[type="text/plain"][data-src*="friendlycaptcha"]',
  ];

  /** Broad patterns only scanned inside <form> (heuristic pass). */
  var CAPTCHA_FORM_INNER_SELECTORS = [
    '[class*="g-recaptcha"]',
    '[class*="grecaptcha"]',
    '[class*="h-captcha"]',
    '[class*="hcaptcha"]',
    '[class*="turnstile"]',
    '[class*="recaptcha"]',
    '[class*="frc-captcha"]',
    '[data-sitekey]',
  ];

  var MAP_MARKERS = [
    '.wpgmza_map',
    '.wpgmza_map_container',
    '.gm-style',
    '.mapboxgl-map',
    '.maplibregl-map',
    '.leaflet-container',
    '.mapster-wp-maps-container',
    '.mapster-wp-maps',
    '.mapster-map',
    '[id^="mapster-wp-maps"]',
    '[data-mapbox-map]',
    '.elementor-widget-google_maps',
    '[data-widget_type="google_maps.default"]',
    '[data-widget_type*="google_maps"]',
    'gmp-map',
    'gmp-advanced-marker',
    'iframe[src*="google.com/maps"]',
    'iframe[data-src*="google.com/maps"]',
    'iframe[src*="maps.google"]',
    'iframe[data-src*="maps.google"]',
    'iframe[src*="openstreetmap.org"]',
    'iframe[data-src*="openstreetmap.org"]',
    'iframe[src*="mapbox.com"]',
    'iframe[data-src*="mapbox.com"]',
  ];

  /** Calendly scheduling embeds (inline widgets + iframes; Elementor popups inject late). */
  var CALENDLY_MARKERS = [
    '.calendly-inline-widget',
    '.calendly-badge-widget',
    '.calendly-overlay',
    '[data-url*="calendly.com"]',
    'iframe[src*="calendly.com"]',
    'iframe[data-src*="calendly.com"]',
    'iframe[data-lazy-src*="calendly.com"]',
  ];

  /** Third-party booking / work-request / form embeds (Jobber, Typeform, …). */
  var WIDGET_EMBED_MARKERS = [
    '.jobber-inline-work-request',
    'iframe.jobber-work-request',
    'iframe[src*="getjobber.com"]',
    'iframe[data-src*="getjobber.com"]',
    'iframe[src*="clienthub.getjobber.com"]',
    'iframe[data-src*="clienthub.getjobber.com"]',
    'iframe[src*="typeform.com"]',
    'iframe[data-src*="typeform.com"]',
    'iframe[src*="jotform.com"]',
    'iframe[data-src*="jotform.com"]',
    'iframe[src*="forms.hsforms.com"]',
    'iframe[data-src*="forms.hsforms.com"]',
    'iframe[src*="tally.so"]',
    'iframe[data-src*="tally.so"]',
  ];

  /** Amelia Booking — first-party WP booking UI (like Gravity Forms). Overlay only. */
  var AMELIA_FORM_MARKERS = [
    '#amelia-container',
    '.am-fs__wrapper',
  ];

  var VIDEO_MARKERS = [
    'iframe[src*="youtube.com"]',
    'iframe[data-src*="youtube.com"]',
    'iframe[data-lazy-src*="youtube"]',
    'iframe[src*="youtu.be"]',
    'iframe[data-src*="youtu.be"]',
    'iframe[src*="youtube-nocookie.com"]',
    'iframe[data-src*="youtube-nocookie.com"]',
    'iframe[src*="player.vimeo.com"]',
    'iframe[data-src*="player.vimeo.com"]',
    'iframe[data-lazy-src*="vimeo"]',
    'iframe[src*="vimeo.com"]',
    'iframe[data-src*="vimeo.com"]',
    // Lazy / CMP-style attrs — filtered by isVisualEmbedSurface (skips <script> / *.js APIs).
    '[data-src*="youtube.com"]',
    '[data-src*="youtu.be"]',
    '[data-src*="youtube-nocookie.com"]',
    '[data-src*="player.vimeo.com/video"]',
    '[data-src*="vimeo.com/video"]',
    '[data-lazy-src*="youtube"]',
    '[data-lazy-src*="vimeo"]',
    '[data-youtube]',
    '[data-youtube-url]',
    '[data-vimeo-url]',
    '[data-vimeo-id]',
  ];

  /** Builder / theme video shells that inject iframes later (often empty at paint). */
  var VIDEO_SHELL_SELECTORS = [
    // Elementor
    '.elementor-widget-video',
    '[data-widget_type="video.default"]',
    '[data-widget_type="video"]',
    '.elementor-video',
    '.elementor-wrapper.elementor-open-inline',
    '.elementor-background-video-container',
    '.elementor-background-video-embed',
    // Gutenberg
    '.wp-block-embed-youtube',
    '.wp-block-embed-vimeo',
    '.wp-block-embed.is-provider-youtube',
    '.wp-block-embed.is-provider-vimeo',
    '.wp-block-embed.is-type-video',
    '.wp-block-video',
    // Divi
    '.et_pb_video',
    '.et_pb_video_box',
    '.et_pb_slide_video',
    // WPBakery / Visual Composer
    '.wpb_video_widget',
    '.wpb_video_wrapper',
    '.vc_video-bg',
    // Bricks
    '.brxe-video',
    '[data-script-id][class*="brxe-video"]',
    // Beaver
    '.fl-module-video',
    '.fl-video',
    // Oxygen / Breakdance-ish
    '.oxy-video',
    '.breakdance-video',
    // Theme / generic oEmbed wrappers
    '.wp-block-embed__wrapper',
    '.fluidvids',
    '.rll-youtube-player',
    '.wp-video',
    'lite-youtube',
    'lite-vimeo',
  ];

  /**
   * WooCommerce / checkout surfaces that need Embeds & Widgets (functional)
   * for PayPal, Stripe, Square, and similar payment widgets.
   */
  var CHECKOUT_SELECTORS = [
    'form.woocommerce-checkout',
    'form.checkout',
    'form#order_review',
    '.woocommerce-checkout',
    '.woocommerce-order-pay',
    '#payment',
    '.wc-block-checkout',
    '.wp-block-woocommerce-checkout',
    '.wc-block-cart__submit-container',
  ];

  /** @type {WeakMap<Element, string>} */
  var guarded = new WeakMap();

  function t(key, fallback) {
    var i18n = (window.ucpfConfig && window.ucpfConfig.i18n) || {};
    return i18n[key] || fallback;
  }

  function categoryLabel(slug) {
    var cats = (window.ucpfConfig && window.ucpfConfig.categories) || {};
    if (cats[slug] && cats[slug].label) {
      return cats[slug].label;
    }
    return slug.charAt(0).toUpperCase() + slug.slice(1);
  }

  function hasCategoryConsent(slug) {
    if (window.__ucpfDiscover) {
      return true;
    }
    if (!slug || slug === 'necessary') {
      return true;
    }
    if (window.UCPF && typeof window.UCPF.hasConsent === 'function') {
      return !!window.UCPF.hasConsent(slug);
    }
    try {
      var handoff = window.__ucpfConsentHandoff;
      if (handoff && handoff.categories && handoff.categories[slug]) {
        return true;
      }
    } catch (eHandoff) { /* ignore */ }
    try {
      var match = document.cookie.match(/(?:^|; )ucpf_consent=([^;]*)/);
      if (match) {
        var data = JSON.parse(decodeURIComponent(match[1]));
        if (data && data.categories && data.categories[slug]) {
          return true;
        }
      }
    } catch (e) { /* ignore */ }
    // Safari may lose the cookie across reload; honor storage backup / bridge.
    try {
      var cfg = window.ucpfConfig || {};
      var suffix = (cfg.storageSuffix || '').toString().replace(/[^a-zA-Z0-9_-]/g, '');
      var keys = [];
      if (suffix) {
        keys.push('ucpf_consent_backup_' + suffix, 'ucpf_consent_bridge_' + suffix);
      }
      keys.push('ucpf_consent_backup', 'ucpf_consent_bridge');
      for (var i = 0; i < keys.length; i++) {
        var raw = null;
        if (window.localStorage) {
          raw = localStorage.getItem(keys[i]);
        }
        if (!raw && window.sessionStorage) {
          raw = sessionStorage.getItem(keys[i]);
        }
        if (!raw) {
          continue;
        }
        var parsed = JSON.parse(raw);
        if (parsed && parsed.categories && parsed.categories[slug]) {
          return true;
        }
      }
    } catch (e2) { /* ignore */ }
    return false;
  }

  function bannerTheme() {
    var cfg = window.ucpfConfig || {};
    var theme = cfg.bannerTheme || 'classic';
    return String(theme).replace(/[^a-z0-9_]/gi, '') || 'classic';
  }

  function applyTokenMap(el, map) {
    if (!el || !map) {
      return;
    }
    Object.keys(map).forEach(function (prop) {
      var v = map[prop];
      if (v === undefined || v === null || v === '') {
        return;
      }
      var name = prop.indexOf('--') === 0 ? prop : '--' + prop;
      el.style.setProperty(name, String(v));
    });
  }

  function syncThemeOnto(el) {
    if (!el) {
      return;
    }
    var theme = bannerTheme();
    Array.prototype.slice.call(el.classList).forEach(function (cls) {
      if (cls.indexOf('ucpf-theme-') === 0) {
        el.classList.remove(cls);
      }
    });
    el.classList.add('ucpf-theme-' + theme);

    // 1) Authoritative tokens from PHP (active preset + Banner & Branding customs).
    var cfg = window.ucpfConfig || {};
    if (cfg.themeTokens && typeof cfg.themeTokens === 'object') {
      applyTokenMap(el, cfg.themeTokens);
    }

    // 2) Live refine from #ucpf-root when present (keeps guard identical to banner).
    var root = document.getElementById('ucpf-root');
    if (root && window.getComputedStyle) {
      var cs = window.getComputedStyle(root);
      TOKEN_PROPS.forEach(function (prop) {
        var v = cs.getPropertyValue(prop);
        if (v && String(v).trim()) {
          el.style.setProperty(prop, String(v).trim());
        }
      });
      // Also copy any other --ucpf-* the theme may set.
      try {
        for (var i = 0; i < cs.length; i++) {
          var name = cs[i];
          if (name && name.indexOf('--ucpf-') === 0) {
            var val = cs.getPropertyValue(name);
            if (val && String(val).trim()) {
              el.style.setProperty(name, String(val).trim());
            }
          }
        }
      } catch (e) {
        /* older browsers */
      }
    }
  }

  function resyncAllGuards() {
    var nodes = document.querySelectorAll('.ucpf-consent-guard, .ucpf-captcha-guard');
    Array.prototype.forEach.call(nodes, syncThemeOnto);
  }

  function findFormForNode(node) {
    if (!node) {
      return null;
    }
    if (node.tagName === 'FORM') {
      return node;
    }
    if (node.closest) {
      var form =
        node.closest('form') ||
        node.closest('form.forminator-custom-form') ||
        node.closest('form.forminator-ui') ||
        node.closest('form.wpforms-form') ||
        node.closest('form.wpcf7-form') ||
        node.closest('form.frm-fluent-form') ||
        node.closest('form.nf-form-content') ||
        node.closest('form.elementor-form') ||
        node.closest('form.frm-show-form') ||
        node.closest('form.formidable') ||
        node.closest('form.quform-form') ||
        node.closest('form.happyforms-form') ||
        node.closest('form.jet-form-builder') ||
        node.closest('form.kb-form') ||
        node.closest('form.et_pb_contact_form');
      if (form) {
        return form;
      }
      // Captcha sometimes sits in a wrapper just outside / beside the form markup.
      var shell =
        node.closest('.gform_wrapper') ||
        node.closest('.gform_body') ||
        node.closest('.forminator-custom-form') ||
        node.closest('.elementor-shortcode') ||
        node.closest('.wpforms-container') ||
        node.closest('.wpcf7') ||
        node.closest('.elementor-widget-form') ||
        node.closest('.frm_forms') ||
        node.closest('.formidable') ||
        node.closest('.quform-form-wrap') ||
        node.closest('.happyforms-form') ||
        node.closest('.jet-form-builder') ||
        node.closest('.kb-form') ||
        node.closest('.et_pb_contact_form');
      if (shell) {
        var nested =
          shell.querySelector('form') ||
          (shell.tagName === 'FORM' ? shell : null) ||
          (shell.id && String(shell.id).indexOf('gform_') === 0 ? shell : null);
        if (nested) {
          return nested;
        }
        // GF: cover the wrapper when the <form> node is missing / replaced mid-AJAX.
        if (shell.classList && shell.classList.contains('gform_wrapper')) {
          return shell;
        }
      }
    }
    var parent = node.parentElement;
    for (var i = 0; parent && i < 8; i++) {
      if (parent.tagName === 'FORM') {
        return parent;
      }
      parent = parent.parentElement;
    }
    return null;
  }

  function queryAll(sels) {
    var out = [];
    var seen = [];
    sels.forEach(function (sel) {
      var nodes;
      try {
        nodes = document.querySelectorAll(sel);
      } catch (e) {
        return;
      }
      Array.prototype.forEach.call(nodes, function (node) {
        for (var i = 0; i < seen.length; i++) {
          if (seen[i] === node) {
            return;
          }
        }
        seen.push(node);
        out.push(node);
      });
    });
    return out;
  }

  function lockFields(form) {
    var controls = form.querySelectorAll('input, textarea, select, button');
    Array.prototype.forEach.call(controls, function (el) {
      if (el.getAttribute('data-ucpf-captcha-skip') === '1') {
        return;
      }
      if (!el.hasAttribute('data-ucpf-captcha-prev-disabled')) {
        el.setAttribute('data-ucpf-captcha-prev-disabled', el.disabled ? '1' : '0');
      }
      if (!el.hasAttribute('data-ucpf-captcha-prev-readonly') && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')) {
        el.setAttribute('data-ucpf-captcha-prev-readonly', el.readOnly ? '1' : '0');
      }
      if (el.tagName === 'BUTTON' || (el.tagName === 'INPUT' && /^(submit|button|image)$/i.test(el.type))) {
        el.disabled = true;
      } else if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
        el.readOnly = true;
        el.disabled = true;
      } else {
        el.disabled = true;
      }
    });
    form.setAttribute('aria-disabled', 'true');
  }

  function unlockFields(form) {
    var controls = form.querySelectorAll('[data-ucpf-captcha-prev-disabled], [data-ucpf-captcha-prev-readonly]');
    Array.prototype.forEach.call(controls, function (el) {
      var wasDisabled = el.getAttribute('data-ucpf-captcha-prev-disabled');
      var wasReadonly = el.getAttribute('data-ucpf-captcha-prev-readonly');
      if (wasDisabled !== null) {
        el.disabled = wasDisabled === '1';
        el.removeAttribute('data-ucpf-captcha-prev-disabled');
      }
      if (wasReadonly !== null) {
        el.readOnly = wasReadonly === '1';
        el.removeAttribute('data-ucpf-captcha-prev-readonly');
      }
    });
    form.removeAttribute('aria-disabled');
  }

  function copyForKind(kind, category, categories) {
    var label = categoryLabel(category);
    var cats = Array.isArray(categories) && categories.length ? categories : category ? [category] : [];
    if (kind === 'checkout') {
      var needsSec = cats.indexOf('security') !== -1;
      var needsFun = cats.indexOf('functional') !== -1;
      if (needsSec && needsFun) {
        return {
          title: t('checkoutGuardCombinedTitle', 'Checkout needs Security & Embeds'),
          body: t(
            'checkoutGuardCombinedBody',
            'Checkout uses anti-spam protection (CAPTCHA) and payment / shipping widgets. Enable Security and Embeds & Widgets together before entering details so nothing is lost.'
          ),
          enable: t('checkoutGuardCombinedEnable', 'Enable required cookies & continue'),
          categories: cats,
        };
      }
      return {
        title: t('checkoutGuardTitle', 'Checkout needs Embeds & Widgets'),
        body: t(
          'checkoutGuardBody',
          'Payment and shipping widgets (PayPal, Stripe, Square, Shippo, UPS, USPS, and similar) need Embeds & Widgets cookies before checkout can request rates, validate addresses, or load payment buttons. Enable them before entering details so nothing is lost.'
        ),
        enable: t('checkoutGuardEnable', 'Enable Embeds & Widgets & continue'),
        categories: cats.length ? cats : ['functional'],
      };
    }
    if (kind === 'captcha') {
      return {
        title: t('captchaGuardTitle', 'CAPTCHA required before you can use this form'),
        body: t(
          'captchaGuardBody',
          'This form uses anti-spam protection (CAPTCHA / Turnstile) that needs Security cookies. Enable Security before filling fields so your entries are not lost.'
        ),
        enable: t('captchaGuardEnable', 'Enable Security & continue'),
        categories: cats.length ? cats : ['security'],
      };
    }
    if (kind === 'map') {
      return {
        title: t('embedGuardMapTitle', 'Map blocked until you allow Marketing & Embeds'),
        body: t(
          'embedGuardMapBody',
          'This map loads third-party content we cannot fully control. Enable Marketing and Embeds & Widgets so it can load.'
        ),
        enable: t('embedGuardEnableVideo', 'Enable Marketing & Embeds & continue'),
        categories: ensureEmbedConsentCategories(cats),
      };
    }
    // YouTube / Vimeo: marketing pixels + embed players often need both.
    if (kind === 'youtube' || kind === 'vimeo') {
      var videoCats = ensureEmbedConsentCategories(cats);
      return {
        title: t('embedGuardVideoTitle', 'Video blocked until you allow Marketing & Embeds'),
        body: t(
          'embedGuardVideoBody',
          'This embedded video needs Marketing and Embeds & Widgets cookies. Enable both to load the player.'
        ),
        enable: t('embedGuardEnableVideo', 'Enable Marketing & Embeds & continue'),
        categories: videoCats,
      };
    }
    if (kind === 'embed' && category === 'marketing') {
      return {
        title: t('embedGuardMarketingTitle', 'Content blocked until you allow Marketing & Embeds'),
        body: t(
          'embedGuardMarketingBody',
          'This embedded content may load Marketing and Embeds & Widgets cookies. Enable both to continue.'
        ),
        enable: t('embedGuardEnableVideo', 'Enable Marketing & Embeds & continue'),
        categories: ensureEmbedConsentCategories(cats.length ? cats : ['marketing']),
      };
    }
    if (kind === 'calendly') {
      return {
        title: t('embedGuardCalendlyTitle', 'Scheduling blocked until you allow Marketing & Embeds'),
        body: t(
          'embedGuardCalendlyBody',
          'This scheduling embed (Calendly) loads third-party content we cannot fully control. Enable Marketing and Embeds & Widgets to continue.'
        ),
        enable: t('embedGuardEnableVideo', 'Enable Marketing & Embeds & continue'),
        categories: ensureEmbedConsentCategories(cats),
      };
    }
    if (kind === 'widget' || (kind === 'embed' && category === 'functional')) {
      return {
        title: t('embedGuardWidgetTitle', 'Form / widget blocked until you allow Marketing & Embeds'),
        body: t(
          'embedGuardWidgetBody',
          'This embedded form or widget loads third-party content we cannot fully control. Enable Marketing and Embeds & Widgets to continue.'
        ),
        enable: t('embedGuardEnableVideo', 'Enable Marketing & Embeds & continue'),
        categories: ensureEmbedConsentCategories(cats),
      };
    }
    return {
      title: t('embedGuardGenericTitle', 'Content blocked until you allow the required cookies').replace('%s', label),
      body: t(
        'embedGuardGenericBody',
        'This content needs additional cookies before it can load. Enable the required category to continue.'
      ),
      enable: (t('embedGuardEnableCategory', 'Enable %s & continue') || 'Enable %s & continue').replace('%s', label),
      categories: cats.length ? cats : [category],
    };
  }

  /**
   * Third-party iframes/embeds can load Marketing trackers we cannot inspect —
   * always unlock Marketing + Embeds together (same rule as video players).
   */
  function ensureEmbedConsentCategories(categories) {
    var out = [];
    var seen = {};
    function add(slug) {
      if (!slug || seen[slug]) {
        return;
      }
      seen[slug] = true;
      out.push(slug);
    }
    if (Array.isArray(categories)) {
      categories.forEach(add);
    }
    add('marketing');
    add('functional');
    return out;
  }

  /** @deprecated Use ensureEmbedConsentCategories — kept for older call sites. */
  function ensureVideoConsentCategories(categories) {
    return ensureEmbedConsentCategories(categories);
  }

  function onEnableCategories(categories, e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    var list = Array.isArray(categories) ? categories.filter(Boolean) : [categories];
    if (!list.length) {
      return;
    }
    // Pair Marketing ↔ Embeds for embed unlocks. Pure Security (CAPTCHA) stays alone.
    var securityOnly = list.indexOf('security') !== -1 && list.indexOf('marketing') === -1 && list.indexOf('analytics') === -1 && list.indexOf('functional') === -1;
    if (!securityOnly && (list.indexOf('marketing') !== -1 || list.indexOf('functional') !== -1)) {
      if (list.indexOf('marketing') === -1) {
        list = list.concat(['marketing']);
      }
      if (list.indexOf('functional') === -1) {
        list = list.concat(['functional']);
      }
    }
    if (!window.UCPF || typeof window.UCPF.getConsent !== 'function' || typeof window.UCPF.setConsent !== 'function') {
      if (window.UCPF && window.UCPF.openPreferences) {
        window.UCPF.openPreferences({ highlight: list[0] });
      }
      return;
    }
    var current = window.UCPF.getConsent() || {};
    var cats = Object.assign({}, current.categories || {});
    cats.necessary = true;
    list.forEach(function (slug) {
      cats[slug] = true;
    });
    window.UCPF.setConsent({
      state: 'custom',
      categories: cats,
      services: current.services || {},
      uuid: current.uuid || '',
    });
  }

  function onEnableCategory(category, e) {
    onEnableCategories([category], e);
  }

  function hasAllCategories(categories) {
    var list = Array.isArray(categories) ? categories : categories ? [categories] : [];
    if (!list.length) {
      return true;
    }
    for (var i = 0; i < list.length; i++) {
      if (!hasCategoryConsent(list[i])) {
        return false;
      }
    }
    return true;
  }

  function isCheckoutSurface(node) {
    if (!node) {
      return false;
    }
    if (node.matches && node.matches(CHECKOUT_SELECTORS.join(','))) {
      return true;
    }
    if (node.closest) {
      for (var i = 0; i < CHECKOUT_SELECTORS.length; i++) {
        try {
          if (node.closest(CHECKOUT_SELECTORS[i])) {
            return true;
          }
        } catch (eClose) {
          /* ignore invalid selector */
        }
      }
    }
    return false;
  }

  function onOpenPrefs(category, e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    if (window.UCPF && typeof window.UCPF.openPreferences === 'function') {
      window.UCPF.openPreferences({ highlight: category });
    }
  }

  function buildPanel(kind, category, categories) {
    var copy = copyForKind(kind, category, categories);
    var enableCats = copy.categories && copy.categories.length ? copy.categories : [category];
    var panel = document.createElement('div');
    panel.className = 'ucpf-consent-guard__panel';
    panel.setAttribute('role', 'alert');
    panel.setAttribute('aria-live', 'assertive');

    var title = document.createElement('p');
    title.className = 'ucpf-consent-guard__title';
    title.textContent = copy.title;

    var body = document.createElement('p');
    body.className = 'ucpf-consent-guard__body';
    body.textContent = copy.body;

    var actions = document.createElement('div');
    actions.className = 'ucpf-consent-guard__actions';

    var enableBtn = document.createElement('button');
    enableBtn.type = 'button';
    enableBtn.className = 'ucpf-consent-guard__btn ucpf-consent-guard__btn--primary';
    enableBtn.setAttribute('data-ucpf-captcha-skip', '1');
    enableBtn.textContent = copy.enable;
    enableBtn.addEventListener(
      'click',
      function (ev) {
        // Third-party iframes/embeds cannot be inspected — always grant Marketing + Embeds.
        var dualEmbed =
          kind === 'youtube' ||
          kind === 'vimeo' ||
          kind === 'widget' ||
          kind === 'calendly' ||
          kind === 'map' ||
          kind === 'embed';
        var catsToEnable = dualEmbed ? ensureEmbedConsentCategories(enableCats) : enableCats;
        onEnableCategories(catsToEnable, ev);
      },
      true
    );

    var prefsBtn = document.createElement('button');
    prefsBtn.type = 'button';
    prefsBtn.className = 'ucpf-consent-guard__btn ucpf-consent-guard__btn--ghost';
    prefsBtn.setAttribute('data-ucpf-captcha-skip', '1');
    prefsBtn.textContent = t('captchaGuardPrefs', 'Cookie Settings');
    prefsBtn.addEventListener('click', function (ev) {
      onOpenPrefs(enableCats[0] || category, ev);
    });

    actions.appendChild(enableBtn);
    actions.appendChild(prefsBtn);
    panel.appendChild(title);
    panel.appendChild(body);
    panel.appendChild(actions);
    return panel;
  }

  function isFormShell(node) {
    if (!node) {
      return false;
    }
    if (node.tagName === 'FORM') {
      return true;
    }
    if (!node.classList) {
      return false;
    }
    return (
      node.classList.contains('woocommerce-checkout') ||
      node.classList.contains('wc-block-checkout') ||
      node.classList.contains('wp-block-woocommerce-checkout') ||
      node.classList.contains('forminator-custom-form') ||
      node.classList.contains('forminator-ui') ||
      node.classList.contains('wpforms-form') ||
      node.classList.contains('wpcf7-form') ||
      node.classList.contains('elementor-form') ||
      node.classList.contains('frm-show-form') ||
      node.classList.contains('formidable') ||
      node.classList.contains('quform-form') ||
      node.classList.contains('happyforms-form') ||
      node.classList.contains('jet-form-builder') ||
      node.classList.contains('kb-form') ||
      node.classList.contains('et_pb_contact_form') ||
      node.id === 'customer_details' ||
      node.id === 'order_review'
    );
  }

  /**
   * Never decorate / wrap document chrome — wrapping <body> nukes Elementor/GF/sticky.
   * @param {Element|null} node
   * @return {boolean}
   */
  function isForbiddenGuardHost(node) {
    if (!node || node.nodeType !== 1) {
      return true;
    }
    var tag = String(node.tagName || '').toUpperCase();
    if (tag === 'HTML' || tag === 'HEAD' || tag === 'BODY') {
      return true;
    }
    if (node.id === 'ucpf-root') {
      return true;
    }
    return false;
  }

  /** Accessibility toolbar CDN / hosts — never park or cover. */
  function isUserWayUrl(url) {
    var u = String(url || '').toLowerCase();
    if (!u) {
      return false;
    }
    return (
      u.indexOf('cdn.userway.org') !== -1 ||
      u.indexOf('api.userway.org') !== -1 ||
      u.indexOf('userway.org') !== -1
    );
  }

  /**
   * UserWay DOM / scripts — fully hands-off (ADA toolbar).
   * @param {Element|null} node
   * @return {boolean}
   */
  function isUserWayNode(node) {
    if (!node || node.nodeType !== 1) {
      return false;
    }
    try {
      var id = String(node.id || '').toLowerCase();
      if (id.indexOf('userway') !== -1 || id === 'userwayaccessibilityicon') {
        return true;
      }
      var cls = String(node.className || '').toLowerCase();
      if (cls.indexOf('userway') !== -1 || cls.indexOf('uwy') !== -1) {
        return true;
      }
      var blob = (
        (node.getAttribute('data-src') || '') +
        ' ' +
        (node.getAttribute('src') || '') +
        ' ' +
        (node.getAttribute('data-account') || '') +
        ' ' +
        (node.getAttribute('href') || '')
      ).toLowerCase();
      if (isUserWayUrl(blob)) {
        return true;
      }
      if (node.closest) {
        if (
          node.closest('#userwayAccessibilityIcon') ||
          node.closest('[id*="userway" i]') ||
          node.closest('[class*="userway" i]') ||
          node.closest('.userway_buttons_wrapper') ||
          node.closest('.uwy')
        ) {
          return true;
        }
      }
    } catch (eUw) {
      /* :i selector unsupported — fall through */
      try {
        if (node.closest && (node.closest('.userway_buttons_wrapper') || node.closest('#userwayAccessibilityIcon'))) {
          return true;
        }
      } catch (e2) { /* ignore */ }
    }
    return false;
  }

  function isBackgroundVideoOwner(node) {
    if (!node || !node.getAttribute) {
      return false;
    }
    var settings = node.getAttribute('data-settings') || '';
    return settings.indexOf('background_video_link') !== -1;
  }

  function isBackgroundVideoShell(node) {
    if (!node || !node.classList) {
      return false;
    }
    if (isBackgroundVideoOwner(node)) {
      return true;
    }
    return (
      node.classList.contains('elementor-background-video-container') ||
      node.classList.contains('elementor-background-video-embed')
    );
  }

  /**
   * Prefer the Elementor e-con that owns background_video_link.
   * Decorating .elementor-background-video-container fights Elementor's absolute fill.
   *
   * @param {Element} node
   * @return {Element|null}
   */
  function resolveBackgroundVideoHost(node) {
    if (!node) {
      return null;
    }
    if (isBackgroundVideoOwner(node)) {
      return node;
    }
    if (node.closest) {
      var owner = node.closest('[data-settings*="background_video_link"]');
      if (owner) {
        return owner;
      }
    }
    if (node.classList.contains('elementor-background-video-embed')) {
      var box = node.closest ? node.closest('.elementor-background-video-container') : node.parentElement;
      return resolveBackgroundVideoHost(box || node);
    }
    if (node.classList.contains('elementor-background-video-container') && node.parentElement) {
      return node.parentElement;
    }
    return node;
  }

  function isBuilderShell(node) {
    if (!node || !node.classList) {
      return false;
    }
    if (node.getAttribute('data-widget_type') === 'video.default') {
      return true;
    }
    if (node.getAttribute('data-widget_type') === 'html.default') {
      return true;
    }
    if (isBackgroundVideoShell(node) || isBackgroundVideoOwner(node)) {
      return true;
    }
    return (
      node.classList.contains('elementor-widget-video') ||
      node.classList.contains('elementor-widget-html') ||
      node.classList.contains('elementor-widget-container') ||
      node.classList.contains('et_pb_video') ||
      node.classList.contains('wpb_video_widget') ||
      node.classList.contains('fl-module-video') ||
      node.classList.contains('brxe-video') ||
      node.classList.contains('wp-block-embed-youtube') ||
      node.classList.contains('wp-block-embed-vimeo') ||
      node.classList.contains('wp-block-video') ||
      node.classList.contains('wp-block-embed') ||
      node.classList.contains('jobber-inline-work-request')
    );
  }

  /**
   * Elementor (and similar) video widgets that gate before an iframe exists.
   * Empty .elementor-video shells measure ~0px — absolute overlays then vanish.
   *
   * @param {Element} host
   * @return {boolean}
   */
  function isCollapsedVideoShell(host) {
    if (!host || !host.classList) {
      return false;
    }
    if (
      host.classList.contains('elementor-widget-video') ||
      host.getAttribute('data-widget_type') === 'video.default' ||
      host.classList.contains('et_pb_video') ||
      host.classList.contains('wpb_video_widget') ||
      host.classList.contains('fl-module-video') ||
      host.classList.contains('brxe-video') ||
      host.classList.contains('wp-block-embed-youtube') ||
      host.classList.contains('wp-block-embed-vimeo')
    ) {
      return true;
    }
    var kind = host.getAttribute('data-ucpf-guard-kind') || '';
    return kind === 'youtube' || kind === 'vimeo';
  }

  /**
   * Lock the host to its pre-gate size so parked iframes don’t collapse the box
   * (and we don’t invent a taller 14–22rem shell that looks wrong).
   *
   * @param {Element} host
   * @return {void}
   */
  function preserveEmbedBoxSize(host) {
    if (!host || host.nodeType !== 1) {
      return;
    }
    if (host.getAttribute('data-ucpf-size-locked') === '1') {
      return;
    }
    // Background video e-cons: Elementor owns geometry — never invent min-height.
    if (isBackgroundVideoOwner(host) || (host.classList && host.classList.contains('ucpf-consent-guard--bg'))) {
      return;
    }

    var measured = 0;
    try {
      measured = Math.round(host.getBoundingClientRect().height || 0);
    } catch (eRect) {
      measured = 0;
    }

    var attrH = 0;
    var iframes = [];
    try {
      if (host.tagName === 'IFRAME') {
        iframes = [host];
      } else if (host.querySelectorAll) {
        iframes = Array.prototype.slice.call(host.querySelectorAll('iframe'));
      }
    } catch (eIf) {
      iframes = [];
    }
    for (var i = 0; i < iframes.length; i++) {
      var iframe = iframes[i];
      var hAttr = parseInt(iframe.getAttribute('height'), 10) || 0;
      var styleH = 0;
      try {
        var st = iframe.style && iframe.style.height ? parseInt(iframe.style.height, 10) : 0;
        styleH = st || 0;
      } catch (eSt) {
        styleH = 0;
      }
      var rectH = 0;
      try {
        rectH = Math.round(iframe.getBoundingClientRect().height || 0);
      } catch (eRh) {
        rectH = 0;
      }
      attrH = Math.max(attrH, hAttr, styleH, rectH);
      // Keep the iframe itself from collapsing when src is parked.
      if (Math.max(hAttr, styleH, rectH) >= 40 && !iframe.getAttribute('data-ucpf-iframe-h')) {
        var keep = Math.max(hAttr, styleH, rectH);
        iframe.setAttribute('data-ucpf-iframe-h', String(keep));
        try {
          iframe.style.setProperty('min-height', keep + 'px', 'important');
          iframe.style.setProperty('height', keep + 'px', 'important');
        } catch (eKeep) { /* ignore */ }
      }
    }

    var targetH = Math.max(measured, attrH);
    // Empty Elementor YouTube/Vimeo shells: invent a 16:9 box so the glass overlay is visible.
    // Do NOT do this for maps/forms/widgets — only collapsed video players.
    if (targetH < 80 && isCollapsedVideoShell(host)) {
      try {
        host.style.setProperty('aspect-ratio', '16 / 9', 'important');
        host.style.setProperty('width', '100%', 'important');
        host.style.setProperty('min-height', '12rem', 'important');
        host.setAttribute('data-ucpf-size-locked', '1');
        host.setAttribute('data-ucpf-guard-min-h', 'aspect-16-9');
        host.setAttribute('data-ucpf-aspect-fallback', '1');
        // Give the inner Elementor fill a height so layout isn’t empty under the panel.
        var inner =
          host.querySelector('.elementor-wrapper') ||
          host.querySelector('.elementor-video') ||
          host.querySelector('.elementor-widget-container');
        if (inner && !inner.getAttribute('data-ucpf-aspect-fallback')) {
          inner.style.setProperty('aspect-ratio', '16 / 9', 'important');
          inner.style.setProperty('min-height', '12rem', 'important');
          inner.style.setProperty('width', '100%', 'important');
          inner.setAttribute('data-ucpf-aspect-fallback', '1');
        }
      } catch (eAspect) { /* ignore */ }
      return;
    }
    // Aspect-ratio shells (Elementor fit) already size themselves — only lock if we have a real box.
    if (targetH < 80) {
      return;
    }
    // Cap runaway measurements (full-page wrappers).
    if (targetH > 1200) {
      targetH = Math.min(targetH, Math.max(measured, attrH, 480));
    }
    try {
      host.style.setProperty('min-height', targetH + 'px', 'important');
      host.setAttribute('data-ucpf-size-locked', '1');
      host.setAttribute('data-ucpf-guard-min-h', String(targetH));
    } catch (eLock) { /* ignore */ }
  }

  /**
   * Undo preserveEmbedBoxSize.
   *
   * @param {Element} host
   * @return {void}
   */
  function clearEmbedBoxSize(host) {
    if (!host || host.nodeType !== 1) {
      return;
    }
    if (host.getAttribute('data-ucpf-size-locked') === '1') {
      try {
        host.style.removeProperty('min-height');
        if (host.getAttribute('data-ucpf-aspect-fallback') === '1') {
          host.style.removeProperty('aspect-ratio');
          host.style.removeProperty('width');
        }
      } catch (eRm) { /* ignore */ }
      host.removeAttribute('data-ucpf-size-locked');
      host.removeAttribute('data-ucpf-guard-min-h');
      host.removeAttribute('data-ucpf-aspect-fallback');
    }
    try {
      var inners = host.querySelectorAll
        ? host.querySelectorAll('[data-ucpf-aspect-fallback="1"]')
        : [];
      Array.prototype.forEach.call(inners, function (inner) {
        if (inner === host) {
          return;
        }
        try {
          inner.style.removeProperty('aspect-ratio');
          inner.style.removeProperty('min-height');
          inner.style.removeProperty('width');
        } catch (eIn) { /* ignore */ }
        inner.removeAttribute('data-ucpf-aspect-fallback');
      });
    } catch (eInner) { /* ignore */ }
    try {
      var nodes = host.tagName === 'IFRAME' ? [host] : host.querySelectorAll ? host.querySelectorAll('iframe[data-ucpf-iframe-h]') : [];
      Array.prototype.forEach.call(nodes, function (iframe) {
        try {
          iframe.style.removeProperty('min-height');
          iframe.style.removeProperty('height');
        } catch (eI) { /* ignore */ }
        iframe.removeAttribute('data-ucpf-iframe-h');
      });
    } catch (eQ) { /* ignore */ }
  }

  function isEffectivelyHidden(el) {
    if (!el) {
      return true;
    }
    // Builder entrance animations (e.g. Elementor .elementor-invisible) use
    // visibility:hidden until Motion FX runs. Consent covers must still attach
    // before the embed loads — otherwise the section reveals with no overlay.
    var entranceAnim = false;
    try {
      entranceAnim = !!(el.classList && el.classList.contains('elementor-invisible'));
    } catch (eInv) { /* ignore */ }
    try {
      if (window.getComputedStyle) {
        var cs = window.getComputedStyle(el);
        if (cs.display === 'none') {
          return true;
        }
        if (cs.visibility === 'hidden' && !entranceAnim) {
          return true;
        }
      }
    } catch (e) {
      /* ignore */
    }
    return false;
  }

  function guardHost(target) {
    if (!target || !target.classList) {
      return null;
    }
    if (target.classList.contains('ucpf-consent-guard')) {
      return target;
    }
    var parent = target.parentElement;
    if (parent && parent.classList.contains('ucpf-consent-guard')) {
      return parent;
    }
    return null;
  }

  function ensureWrap(target, mode) {
    if (isForbiddenGuardHost(target)) {
      return target;
    }
    var existing = guardHost(target);
    if (existing) {
      if (isForbiddenGuardHost(existing)) {
        return target;
      }
      return existing;
    }

    // Elementor background-video e-con: --bg only (never --embed layout rules).
    if (mode === 'embed' && isBackgroundVideoOwner(target)) {
      target.classList.add('ucpf-consent-guard', 'ucpf-captcha-guard', 'ucpf-consent-guard--bg');
      target.setAttribute('data-ucpf-guard-shell', '1');
      return target;
    }

    // Decorate in place — wrapping forms/builder shells breaks theme layout.
    if (
      (mode === 'form' && isFormShell(target)) ||
      (mode === 'embed' && isBuilderShell(target))
    ) {
      target.classList.add(
        'ucpf-consent-guard',
        'ucpf-captcha-guard',
        mode === 'form' ? 'ucpf-consent-guard--form' : 'ucpf-consent-guard--embed'
      );
      target.setAttribute('data-ucpf-guard-shell', '1');
      return target;
    }

    // Never insert a wrapper around document chrome (would reparent <body>).
    if (!target.parentNode || isForbiddenGuardHost(target.parentNode)) {
      return target;
    }

    var wrap = document.createElement('div');
    wrap.className = 'ucpf-consent-guard ucpf-captcha-guard';
    wrap.classList.add(mode === 'form' ? 'ucpf-consent-guard--form' : 'ucpf-consent-guard--embed');
    wrap.setAttribute('data-ucpf-guard-shell', '0');
    if (target.parentNode) {
      target.parentNode.insertBefore(wrap, target);
      wrap.appendChild(target);
    }
    return wrap;
  }

  /** True when URL is a YouTube / Vimeo player embed. */
  function isVideoPlayerUrl(url) {
    var u = String(url || '').toLowerCase();
    if (!u) {
      return false;
    }
    return (
      u.indexOf('youtube.com') !== -1 ||
      u.indexOf('youtu.be') !== -1 ||
      u.indexOf('youtube-nocookie.com') !== -1 ||
      u.indexOf('player.vimeo.com') !== -1 ||
      u.indexOf('vimeo.com') !== -1
    );
  }

  /**
   * Stop players loading under the cover — park src onto data-src until consent.
   * Also claim iframes already emptied by network-gate so restore can find them.
   * @param {Element} host Guard target (widget / wrapper).
   */
  function parkVideoIframes(host) {
    if (!host || !host.querySelectorAll) {
      return;
    }
    var nodes = host.querySelectorAll('iframe');
    Array.prototype.forEach.call(nodes, function (iframe) {
      var src = iframe.getAttribute('src') || '';
      var parked = iframe.getAttribute('data-src') || '';
      var candidate = src && src !== 'about:blank' ? src : parked;
      if (!candidate || !isVideoPlayerUrl(candidate)) {
        return;
      }
      if (!iframe.getAttribute('data-src')) {
        iframe.setAttribute('data-src', candidate);
      }
      iframe.setAttribute('data-ucpf-parked', '1');
      if (!src || src === 'about:blank') {
        return;
      }
      try {
        iframe.removeAttribute('src');
      } catch (eRm) { /* ignore */ }
      try {
        iframe.src = '';
      } catch (eSrc) { /* ignore */ }
    });
  }

  /**
   * Put a video URL back on an iframe after consent.
   * Clears gate stamps, assigns src, and replaces the node if the gate re-blocks
   * (YouTube often stays blank on a previously emptied iframe).
   *
   * @param {HTMLIFrameElement} iframe
   * @param {string} src
   * @return {boolean}
   */
  function forceVideoIframeSrc(iframe, src) {
    if (!iframe || !src || !isVideoPlayerUrl(src)) {
      return false;
    }
    iframe.removeAttribute('data-ucpf-parked');
    iframe.removeAttribute('data-ucpf-gated');
    iframe.removeAttribute('data-ucpf-category');
    try {
      iframe.src = src;
    } catch (eProp) {
      try {
        iframe.setAttribute('src', src);
      } catch (eAttr) {
        return false;
      }
    }
    iframe.removeAttribute('data-src');
    var live = '';
    try {
      live = iframe.getAttribute('src') || iframe.src || '';
    } catch (eLive) {
      live = '';
    }
    if (live && live !== 'about:blank' && isVideoPlayerUrl(live)) {
      return true;
    }
    // Setter still blocked or player dead — swap in a fresh iframe.
    if (!iframe.parentNode) {
      return false;
    }
    var fresh = document.createElement('iframe');
    Array.prototype.slice.call(iframe.attributes || []).forEach(function (attr) {
      if (!attr || !attr.name) {
        return;
      }
      if (
        attr.name === 'src' ||
        attr.name === 'data-src' ||
        attr.name === 'data-ucpf-gated' ||
        attr.name === 'data-ucpf-parked' ||
        attr.name === 'data-ucpf-category'
      ) {
        return;
      }
      try {
        fresh.setAttribute(attr.name, attr.value);
      } catch (eCopy) { /* ignore */ }
    });
    if (!fresh.className && iframe.className) {
      fresh.className = iframe.className;
    }
    if (iframe.classList && iframe.classList.contains('elementor-video')) {
      fresh.classList.add('elementor-video');
    }
    fresh.setAttribute('src', src);
    try {
      iframe.parentNode.replaceChild(fresh, iframe);
    } catch (eRep) {
      return false;
    }
    return true;
  }

  /**
   * Restore parked / gated YouTube / Vimeo iframes after consent.
   * Covers both guard parking (data-ucpf-parked) and network-gate (data-ucpf-gated).
   * @param {Element} host Guard target (or document-wide root).
   */
  function restoreParkedVideoIframes(host) {
    if (!host || !host.querySelectorAll) {
      return;
    }
    var roots = [host];
    var wrap = guardHost(host);
    if (wrap && wrap !== host) {
      roots.push(wrap);
    }
    roots.forEach(function (root) {
      if (!root || !root.querySelectorAll) {
        return;
      }
      Array.prototype.forEach.call(
        root.querySelectorAll(
          'iframe[data-ucpf-parked="1"], iframe[data-ucpf-gated="1"][data-src], iframe.elementor-video[data-src], iframe[data-src*="youtube"], iframe[data-src*="youtu.be"], iframe[data-src*="youtube-nocookie"], iframe[data-src*="vimeo"]'
        ),
        function (iframe) {
          var live = iframe.getAttribute('src') || '';
          if (live && live !== 'about:blank' && isVideoPlayerUrl(live)) {
            iframe.removeAttribute('data-ucpf-parked');
            iframe.removeAttribute('data-ucpf-gated');
            return;
          }
          var real = iframe.getAttribute('data-src') || '';
          if (!real || !isVideoPlayerUrl(real)) {
            iframe.removeAttribute('data-ucpf-parked');
            return;
          }
          forceVideoIframeSrc(iframe, real);
        }
      );
    });
  }

  /** Document-wide video iframe restore (maps-style — not only under a guard host). */
  function restoreAllParkedVideoIframes() {
    restoreParkedVideoIframes(document);
  }

  function applyGuard(target, kind, category, mode, categories) {
    var cats = Array.isArray(categories) && categories.length ? categories : category ? [category] : [];
    if (!target || isForbiddenGuardHost(target) || isUserWayNode(target) || hasAllCategories(cats)) {
      return;
    }
    if (mode === 'embed' && isEffectivelyHidden(target)) {
      return;
    }
    var key = kind + ':' + cats.join('+');
    var isVideoKind = kind === 'youtube' || kind === 'vimeo';
    if (guarded.get(target) === key && target.getAttribute('data-ucpf-consent-guarded') === '1') {
      var existing = guardHost(target) || target;
      if (existing && existing.classList.contains('ucpf-consent-guard')) {
        syncThemeOnto(existing);
      }
      // Retry size lock: first pass often measured 0 before Elementor painted the shell.
      if (mode === 'embed' && isVideoKind && existing) {
        preserveEmbedBoxSize(existing);
        if (existing !== target) {
          preserveEmbedBoxSize(target);
        }
      }
      // Elementor may re-inject src after we parked — keep players unloaded.
      if (isVideoKind || (mode === 'embed' && isBackgroundVideoOwner(target))) {
        parkVideoIframes(target);
        if (existing && existing !== target) {
          parkVideoIframes(existing);
        }
      }
      return;
    }

    var isBg = mode === 'embed' && isBackgroundVideoOwner(target);

    // Capture Elementor position before any class adds.
    var preservePos = '';
    if (isBg) {
      try {
        preservePos = window.getComputedStyle(target).position || '';
      } catch (eRead) {
        preservePos = '';
      }
    }

    var wrap = ensureWrap(target, mode);
    wrap.setAttribute('data-ucpf-guard-kind', kind);
    wrap.setAttribute('data-ucpf-guard-category', cats.join(','));
    wrap.classList.add('ucpf-consent-guard--active');
    if (isBg || wrap.classList.contains('ucpf-consent-guard--bg')) {
      wrap.classList.add('ucpf-consent-guard--bg');
      // Never apply --embed (relative/min-height/width) to Elementor absolute e-cons.
      wrap.classList.remove('ucpf-consent-guard--embed', 'ucpf-consent-guard--form');
      // Only invent a positioning context if Elementor left the host static.
      if (!preservePos || preservePos === 'static') {
        wrap.style.setProperty('position', 'relative', 'important');
        wrap.setAttribute('data-ucpf-bg-pos', '1');
      } else if (wrap.getAttribute('data-ucpf-bg-pos') === '1') {
        // Drop forced absolute from older builds; Elementor owns coords.
        wrap.style.removeProperty('position');
        wrap.removeAttribute('data-ucpf-bg-pos');
      }
    } else if (mode === 'embed') {
      // Measure before parking iframes so the glass overlay keeps the real media box.
      preserveEmbedBoxSize(wrap);
      if (wrap !== target) {
        preserveEmbedBoxSize(target);
      }
    }
    syncThemeOnto(wrap);

    var panel = null;
    try {
      panel = wrap.querySelector(':scope > .ucpf-consent-guard__panel');
    } catch (eScope) {
      panel = null;
    }
    if (!panel) {
      var kids = wrap.children;
      for (var ci = 0; ci < kids.length; ci++) {
        if (kids[ci].classList && kids[ci].classList.contains('ucpf-consent-guard__panel')) {
          panel = kids[ci];
          break;
        }
      }
    }
    if (!panel) {
      panel = wrap.querySelector('.ucpf-consent-guard__panel');
    }
    var newPanel = buildPanel(kind, category, cats);
    try {
      if (panel && panel.parentNode === wrap) {
        wrap.replaceChild(newPanel, panel);
      } else {
        var anchor = wrap === target ? wrap.firstChild : target;
        if (anchor && anchor.parentNode === wrap) {
          wrap.insertBefore(newPanel, anchor);
        } else if (wrap.firstChild && wrap.firstChild.parentNode === wrap) {
          wrap.insertBefore(newPanel, wrap.firstChild);
        } else {
          wrap.appendChild(newPanel);
        }
      }
    } catch (eInsert) {
      try {
        wrap.appendChild(newPanel);
      } catch (eAppend) {
        /* ignore — Elementor may reparent mid-refresh */
      }
    }

    if (mode === 'form') {
      lockFields(target);
    }
    if (isVideoKind || isBg) {
      parkVideoIframes(target);
      parkVideoIframes(wrap);
    }
    target.setAttribute('data-ucpf-consent-guarded', '1');
    guarded.set(target, key);
  }

  function removeGuard(target) {
    if (!target || target.getAttribute('data-ucpf-consent-guarded') !== '1') {
      return;
    }
    if (target.tagName === 'FORM') {
      unlockFields(target);
    }
    restoreParkedVideoIframes(target);
    target.removeAttribute('data-ucpf-consent-guarded');

    var wrap = guardHost(target) || (target.classList.contains('ucpf-consent-guard') ? target : null);
    if (wrap && wrap.classList.contains('ucpf-consent-guard')) {
      restoreParkedVideoIframes(wrap);
      clearEmbedBoxSize(wrap);
      clearEmbedBoxSize(target);
      var panel = wrap.querySelector('.ucpf-consent-guard__panel');
      if (panel) {
        panel.remove();
      }
      wrap.classList.remove('ucpf-consent-guard--active');

      var inPlace = wrap.getAttribute('data-ucpf-guard-shell') === '1' || wrap === target;
      if (inPlace) {
        wrap.classList.remove(
          'ucpf-consent-guard',
          'ucpf-captcha-guard',
          'ucpf-consent-guard--embed',
          'ucpf-consent-guard--form',
          'ucpf-consent-guard--bg'
        );
        wrap.removeAttribute('data-ucpf-guard-shell');
        wrap.removeAttribute('data-ucpf-guard-kind');
        wrap.removeAttribute('data-ucpf-guard-category');
        if (wrap.getAttribute('data-ucpf-bg-pos') === '1') {
          wrap.style.removeProperty('position');
          wrap.removeAttribute('data-ucpf-bg-pos');
        }
      } else if (wrap.parentNode) {
        wrap.parentNode.insertBefore(target, wrap);
        wrap.remove();
      }
    } else {
      clearEmbedBoxSize(target);
    }
    guarded.set(target, 'off');

    // Overlay is gone — remount the player immediately if restore left a blank shell.
    var videoHost =
      (target.classList &&
        (target.classList.contains('elementor-widget-video') ||
          target.getAttribute('data-widget_type') === 'video.default' ||
          target.classList.contains('elementor-wrapper'))) ||
      (target.querySelector &&
        target.querySelector('.elementor-wrapper.elementor-open-inline, iframe.elementor-video, iframe[data-src*="youtube"], iframe[data-src*="vimeo"]'));
    if (videoHost && hasCategoryConsent('marketing') && hasCategoryConsent('functional')) {
      var remountRoot =
        (target.closest && (target.closest('.elementor-widget-video') || target.closest('[data-widget_type="video.default"]'))) ||
        target;
      var kick = function (allowCreate) {
        restoreParkedVideoIframes(remountRoot);
        injectElementorOpenInlineVideo(remountRoot, { allowCreate: !!allowCreate });
        dedupeElementorOpenInlineVideos(remountRoot);
      };
      // Restore Elementor's frame first; create only if still empty shortly after.
      kick(false);
      window.setTimeout(function () {
        kick(false);
      }, 200);
      window.setTimeout(function () {
        kick(true);
      }, 1000);
    }
  }

  function kindForPlaceholder(node) {
    var service = (node.getAttribute('data-ucpf-service') || '').toLowerCase();
    var src = (node.getAttribute('data-src') || node.getAttribute('src') || '').toLowerCase();
    if (service.indexOf('map') !== -1 || /maps\.google|google\.com\/maps|openstreetmap|mapbox|maplibre/.test(src)) {
      return 'map';
    }
    if (service.indexOf('youtube') !== -1 || src.indexOf('youtube') !== -1 || src.indexOf('youtu.be') !== -1) {
      return 'youtube';
    }
    if (service.indexOf('vimeo') !== -1 || src.indexOf('vimeo') !== -1) {
      return 'vimeo';
    }
    if (service.indexOf('calendly') !== -1 || src.indexOf('calendly.com') !== -1) {
      return 'calendly';
    }
    if (
      service.indexOf('jobber') !== -1 ||
      /getjobber\.com|clienthub\.getjobber|work_request|typeform\.com|jotform\.com|hsforms\.com|tally\.so/.test(src)
    ) {
      return 'widget';
    }
    return 'embed';
  }

  function parseDataSettings(el) {
    if (!el || !el.getAttribute) {
      return null;
    }
    var raw = el.getAttribute('data-settings') || el.getAttribute('data-elementor-settings') || '';
    if (!raw) {
      return null;
    }
    try {
      return JSON.parse(raw);
    } catch (e1) {
      try {
        return JSON.parse(raw.replace(/&quot;/g, '"').replace(/&#039;/g, "'"));
      } catch (e2) {
        return null;
      }
    }
  }

  /**
   * Detect YouTube / Vimeo from builder widgets even when the iframe is not painted yet.
   * @param {Element} el
   * @returns {{ kind: string, category: string }|null}
   */
  function hostMatchesPage(hostname) {
    var a = String(hostname || '')
      .replace(/^www\./i, '')
      .toLowerCase();
    var b = String(window.location.hostname || '')
      .replace(/^www\./i, '')
      .toLowerCase();
    return !!(a && b && a === b);
  }

  /** Same-origin (or relative / blob / data) media — first-party, never consent-gated. */
  function isSameOriginMediaUrl(url) {
    var raw = String(url || '').trim();
    if (!raw) {
      return false;
    }
    if (/^(blob:|data:)/i.test(raw)) {
      return true;
    }
    if (raw.charAt(0) === '/' && raw.charAt(1) !== '/') {
      return true;
    }
    try {
      var u = new URL(raw, window.location.href);
      return hostMatchesPage(u.hostname);
    } catch (e) {
      return false;
    }
  }

  /**
   * Elementor Self Hosted / HTML5 <video> / same-origin iframe — do not cover.
   * @param {Element} el
   * @return {boolean}
   */
  function isSelfHostedVideoSurface(el) {
    if (!el || el.nodeType !== 1) {
      return false;
    }
    var settings = parseDataSettings(el);
    if ((!settings || typeof settings !== 'object') && el.closest) {
      var host = el.closest('[data-settings]');
      settings = parseDataSettings(host);
    }
    if (settings && typeof settings === 'object') {
      var vtype = String(settings.video_type || '').toLowerCase();
      if (vtype === 'hosted' || vtype === 'self' || vtype === 'self_hosted' || vtype === 'file') {
        return true;
      }
      if (settings.hosted_url && isSameOriginMediaUrl(settings.hosted_url)) {
        return true;
      }
      var bg = String(settings.background_video_link || settings.background_background || '');
      if (bg && isSameOriginMediaUrl(bg) && /\.(mp4|webm|ogg|ogv|m4v)(\?|#|$)/i.test(bg)) {
        return true;
      }
    }
    if (el.tagName === 'VIDEO') {
      var vsrc = el.getAttribute('src') || el.getAttribute('data-src') || '';
      if (vsrc && isSameOriginMediaUrl(vsrc)) {
        return true;
      }
      var sources = el.querySelectorAll ? el.querySelectorAll('source[src], source[data-src]') : [];
      for (var si = 0; si < sources.length; si++) {
        var ss = sources[si].getAttribute('src') || sources[si].getAttribute('data-src') || '';
        if (ss && isSameOriginMediaUrl(ss)) {
          return true;
        }
      }
    }
    if (el.querySelector) {
      var video = el.querySelector('video[src], video source[src], video[data-src], video source[data-src]');
      if (video) {
        var nested =
          (video.tagName === 'SOURCE' ? video : video).getAttribute('src') ||
          (video.tagName === 'SOURCE' ? video : video).getAttribute('data-src') ||
          '';
        if (!nested && video.querySelector) {
          var nestedSrc = video.querySelector('source[src], source[data-src]');
          nested = nestedSrc
            ? nestedSrc.getAttribute('src') || nestedSrc.getAttribute('data-src') || ''
            : '';
        }
        if (nested && isSameOriginMediaUrl(nested)) {
          return true;
        }
        if (video.tagName === 'VIDEO' && isSelfHostedVideoSurface(video)) {
          return true;
        }
      }
    }
    if (el.tagName === 'IFRAME') {
      var iframeSrc = el.getAttribute('src') || el.getAttribute('data-src') || '';
      if (iframeSrc && isSameOriginMediaUrl(iframeSrc) && !isVideoPlayerUrl(iframeSrc)) {
        return true;
      }
    }
    return false;
  }

  function detectVideoProvider(el) {
    if (!el || el.nodeType !== 1) {
      return null;
    }
    if (isSelfHostedVideoSurface(el)) {
      return null;
    }

    var blob = '';
    var attrs = [
      'src',
      'data-src',
      'data-lazy-src',
      'data-youtube',
      'data-youtube-url',
      'data-vimeo-url',
      'data-vimeo-id',
      'href',
      'data-url',
    ];
    for (var a = 0; a < attrs.length; a++) {
      blob += ' ' + (el.getAttribute(attrs[a]) || '');
    }
    blob += ' ' + (el.className || '');
    blob += ' ' + (el.getAttribute('data-widget_type') || '');

    var settings = parseDataSettings(el);
    if (settings && typeof settings === 'object') {
      var vtypeEarly = String(settings.video_type || '').toLowerCase();
      if (vtypeEarly === 'hosted' || vtypeEarly === 'self' || vtypeEarly === 'self_hosted' || vtypeEarly === 'file') {
        return null;
      }
      blob +=
        ' ' +
        (settings.video_type || '') +
        ' ' +
        (settings.youtube_url || '') +
        ' ' +
        (settings.vimeo_url || '') +
        ' ' +
        (settings.dailymotion_url || '') +
        ' ' +
        (settings.hosted_url || '') +
        ' ' +
        (settings.background_video_link || '') +
        ' ' +
        (settings.background_background || '');
    }

    // Walk parents for Elementor widget / container data-settings (background videos live on e-con).
    if (el.parentElement) {
      var settingsHost = el.closest
        ? el.closest('[data-settings*="background_video"], [data-settings*="youtube_url"], [data-settings*="vimeo_url"], [data-settings]')
        : el.parentElement;
      var parentSettings = parseDataSettings(el.parentElement) || parseDataSettings(settingsHost);
      if (parentSettings && typeof parentSettings === 'object') {
        var pv = String(parentSettings.video_type || '').toLowerCase();
        if (pv === 'hosted' || pv === 'self' || pv === 'self_hosted' || pv === 'file') {
          return null;
        }
        blob +=
          ' ' +
          (parentSettings.video_type || '') +
          ' ' +
          (parentSettings.youtube_url || '') +
          ' ' +
          (parentSettings.vimeo_url || '') +
          ' ' +
          (parentSettings.background_video_link || '') +
          ' ' +
          (parentSettings.background_background || '');
      }
    }

    blob = blob.toLowerCase();
    // Hosted-only blob with no third-party player signal.
    if (/hosted/.test(blob) && !/youtube|youtu\.be|vimeo|dailymotion/.test(blob)) {
      return null;
    }
    if (/vimeo|player\.vimeo/.test(blob)) {
      return { kind: 'vimeo', category: 'functional' };
    }
    if (/youtube|youtu\.be|youtube-nocookie/.test(blob)) {
      return { kind: 'youtube', category: 'marketing' };
    }
    return null;
  }

  function calendlyHostContainer(node) {
    if (!node || node.nodeType !== 1) {
      return null;
    }
    if (node.classList && (node.classList.contains('calendly-inline-widget') || node.classList.contains('calendly-badge-widget'))) {
      return node.closest('.elementor-widget-container') || node.closest('.elementor-widget') || node;
    }
    if (node.tagName === 'IFRAME' && node.parentElement) {
      return (
        node.closest('.calendly-inline-widget') ||
        node.closest('.elementor-widget-container') ||
        node.parentElement
      );
    }
    var dataUrl = (node.getAttribute('data-url') || '').toLowerCase();
    if (dataUrl.indexOf('calendly.com') !== -1) {
      return node.closest('.elementor-widget-container') || node.closest('.elementor-widget') || node;
    }
    return node;
  }

  /**
   * Prefer a sized mount box for Jobber / Typeform / Elementor HTML embeds.
   *
   * @param {Element} node
   * @return {Element|null}
   */
  function widgetEmbedHostContainer(node) {
    if (!node || node.nodeType !== 1) {
      return null;
    }
    // UserWay accessibility toolbar — never promote a cover host.
    if (isUserWayNode(node) || isUserWayUrl((node.getAttribute('data-src') || '') + ' ' + (node.getAttribute('src') || ''))) {
      return null;
    }
    if (node.classList && node.classList.contains('jobber-inline-work-request')) {
      return node;
    }
    var blob = (
      (node.getAttribute('data-src') || '') +
      ' ' +
      (node.getAttribute('src') || '') +
      ' ' +
      (node.id || '')
    ).toLowerCase();
    // Calendly script often lives under <body> — cover the widget box, never the page.
    if (blob.indexOf('calendly') !== -1 && node.ownerDocument) {
      var cal =
        (node.closest && node.closest('.calendly-inline-widget, .calendly-badge-widget')) ||
        node.ownerDocument.querySelector('.calendly-inline-widget[data-url], .calendly-badge-widget, .calendly-inline-widget');
      if (cal && !isForbiddenGuardHost(cal)) {
        return cal;
      }
    }
    if (node.closest) {
      // Prefer the Elementor / builder layout box — not the parked iframe (collapses to 0).
      var box =
        node.closest('[data-widget_type="html.default"]') ||
        node.closest('.elementor-widget-html') ||
        node.closest('[data-widget_type="tp-meeting-scheduler.default"]') ||
        node.closest('.elementor-widget-tp-meeting-scheduler') ||
        node.closest('.jobber-inline-work-request') ||
        node.closest('[class*="jobber-inline"]') ||
        node.closest('.elementor-widget-container') ||
        node.closest('.elementor-widget');
      if (box && !isForbiddenGuardHost(box)) {
        return box;
      }
    }
    if (node.tagName === 'IFRAME' && node.parentElement && !isForbiddenGuardHost(node.parentElement)) {
      return node.parentElement;
    }
    // Scripts parked under <body>/<head> must not promote the document chrome as a host.
    if (node.tagName === 'SCRIPT') {
      return null;
    }
    if (isForbiddenGuardHost(node)) {
      return null;
    }
    return node;
  }

  /**
   * Whether a gated script is a form/widget embed (not analytics).
   *
   * @param {Element} script
   * @return {boolean}
   */
  function isEmbedLikeScript(script) {
    if (!script || script.tagName !== 'SCRIPT') {
      return false;
    }
    var blob = (
      (script.getAttribute('data-src') || '') +
      ' ' +
      (script.getAttribute('src') || '') +
      ' ' +
      (script.getAttribute('form_url') || '') +
      ' ' +
      (script.getAttribute('typehub_id') || '') +
      ' ' +
      (script.id || '') +
      ' ' +
      (script.className || '')
    ).toLowerCase();
    // Accessibility toolbar — never treat as a third-party form/widget cover.
    if (isUserWayUrl(blob) || blob.indexOf('userway') !== -1) {
      return false;
    }
    if (
      /(google-analytics|googletagmanager|gtag\/js|facebook\.net|fbevents|hotjar|clarity\.ms|doubleclick|googlesyndication)/.test(
        blob
      )
    ) {
      return false;
    }
    if (
      /(jobber|getjobber|work_request|typeform|jotform|hsforms|hubspot\.com\/.*form|calendly|wufoo|fillout\.com|tally\.so|forms\.office)/.test(
        blob
      )
    ) {
      return true;
    }
    if (script.getAttribute('form_url') || script.getAttribute('typehub_id') || script.getAttribute('data-form-id')) {
      return true;
    }
    // Elementor HTML widgets often host third-party form snippets.
    if (script.closest) {
      return !!(
        script.closest('.elementor-widget-html') ||
        script.closest('[data-widget_type="html.default"]') ||
        script.closest('.jobber-inline-work-request')
      );
    }
    return false;
  }

  /**
   * Calendly only scans the DOM once on script load. Elementor popups inject
   * .calendly-inline-widget later — re-run init after Functional consent.
   */
  function reinitCalendlyWidgets() {
    if (!hasCategoryConsent('functional')) {
      return;
    }
    if (window.UCPFLoader && typeof window.UCPFLoader.applyConsent === 'function') {
      try {
        window.UCPFLoader.applyConsent();
      } catch (eLoad) {
        /* ignore */
      }
    }
    var attempt = 0;
    function tryInit() {
      attempt += 1;
      var C = window.Calendly;
      var nodes = document.querySelectorAll('.calendly-inline-widget[data-url], .calendly-badge-widget[data-url]');
      if (!nodes.length) {
        return;
      }
      if (C && typeof C === 'object') {
        try {
          if (typeof C.initInlineWidgets === 'function') {
            C.initInlineWidgets();
            return;
          }
        } catch (eInitAll) {
          /* fall through per-node */
        }
        Array.prototype.forEach.call(nodes, function (el) {
          if (el.querySelector && el.querySelector('iframe')) {
            return;
          }
          var url = el.getAttribute('data-url');
          if (!url) {
            return;
          }
          try {
            if (typeof C.initInlineWidget === 'function') {
              C.initInlineWidget({ url: url, parentElement: el });
            }
          } catch (eOne) {
            /* ignore */
          }
        });
        return;
      }
      if (attempt < 20) {
        window.setTimeout(tryInit, 250);
      }
    }
    tryInit();
  }

  /**
   * Extract a YouTube video id from watch / embed / shorts / youtu.be URLs.
   * @param {string} raw
   * @return {string}
   */
  function youtubeIdFromUrl(raw) {
    var url = String(raw || '').trim();
    if (!url) {
      return '';
    }
    var m =
      url.match(/youtu\.be\/([A-Za-z0-9_-]{6,})/i) ||
      url.match(/youtube\.com\/shorts\/([A-Za-z0-9_-]{6,})/i) ||
      url.match(/youtube(?:-nocookie)?\.com\/embed\/([A-Za-z0-9_-]{6,})/i) ||
      url.match(/[?&]v=([A-Za-z0-9_-]{6,})/i) ||
      url.match(/youtube\.com\/live\/([A-Za-z0-9_-]{6,})/i);
    return m && m[1] ? m[1] : '';
  }

  /**
   * @param {string} raw
   * @param {object} [settings]
   * @return {string}
   */
  function youtubeEmbedSrc(raw, settings) {
    var id = youtubeIdFromUrl(raw);
    if (!id) {
      return '';
    }
    var params = ['feature=oembed', 'enablejsapi=1'];
    var rawStr = String(raw || '');
    var preserved = {};
    try {
      var q = rawStr.split('?')[1] || '';
      if (q) {
        q.replace(/^\?/, '')
          .split('&')
          .forEach(function (pair) {
            var parts = pair.split('=');
            var k = decodeURIComponent(parts[0] || '').toLowerCase();
            var v = decodeURIComponent((parts[1] || '').replace(/\+/g, ' '));
            if (k === 'list' || k === 'start' || k === 'si' || k === 'index') {
              preserved[k] = v;
            }
            if (k === 't' || k === 'time_continue') {
              var secs = v;
              if (/^\d+$/.test(v)) {
                preserved.start = v;
              } else {
                var tm = String(v).match(/(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?/i);
                if (tm) {
                  preserved.start = String(
                    (parseInt(tm[1] || '0', 10) || 0) * 3600 +
                      (parseInt(tm[2] || '0', 10) || 0) * 60 +
                      (parseInt(tm[3] || '0', 10) || 0)
                  );
                }
              }
            }
          });
      }
    } catch (eYtQ) { /* ignore */ }

    Object.keys(preserved).forEach(function (k) {
      if (preserved[k] !== '' && preserved[k] != null) {
        params.push(encodeURIComponent(k) + '=' + encodeURIComponent(preserved[k]));
      }
    });

    if (settings && String(settings.controls) === 'yes') {
      params.push('controls=1');
    }
    if (settings && (settings.autoplay === 'yes' || settings.autoplay === true)) {
      params.push('autoplay=1');
      if (params.indexOf('mute=1') === -1) {
        params.push('mute=1');
      }
    }
    if (settings && (settings.mute === 'yes' || settings.mute === true)) {
      if (params.indexOf('mute=1') === -1) {
        params.push('mute=1');
      }
    }
    if (settings && (settings.loop === 'yes' || settings.loop === true)) {
      params.push('loop=1');
      if (!preserved.list) {
        params.push('playlist=' + encodeURIComponent(id));
      }
    }
    return 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(id) + '?' + params.join('&');
  }

  /**
   * Build a Vimeo player URL without dropping unlisted privacy hashes.
   * Accepts vimeo.com/{id}/{hash}, ?h=, and player.vimeo.com/video/{id}?h=.
   *
   * @param {string} raw
   * @return {string}
   */
  function vimeoEmbedSrc(raw) {
    var url = String(raw || '').trim();
    if (!url) {
      return '';
    }

    function hashFromQuery(u) {
      var m = String(u).match(/[?&#]h=([a-zA-Z0-9]+)/i);
      return m && m[1] ? m[1] : '';
    }

    var playerM = url.match(/player\.vimeo\.com\/video\/(\d+)/i);
    if (playerM && playerM[1]) {
      var ph = hashFromQuery(url);
      return (
        'https://player.vimeo.com/video/' +
        encodeURIComponent(playerM[1]) +
        (ph ? '?h=' + encodeURIComponent(ph) : '')
      );
    }

    // vimeo.com/1027049072/366bb7c771 or vimeo.com/video/123
    var pathM = url.match(/vimeo\.com\/(?:video\/)?(\d+)(?:\/([a-zA-Z0-9]+))?/i);
    if (!pathM || !pathM[1]) {
      return '';
    }
    var id = pathM[1];
    var hash = pathM[2] || hashFromQuery(url);
    // Path segments that are not privacy hashes.
    if (hash && /^(channels|manage|album|groups|ondemand|showcase|event|review|staffpick)$/i.test(hash)) {
      hash = hashFromQuery(url);
    }
    return (
      'https://player.vimeo.com/video/' +
      encodeURIComponent(id) +
      (hash ? '?h=' + encodeURIComponent(hash) : '')
    );
  }

  /**
   * Prefer an exact existing iframe URL over rebuilding (keeps h=, list, etc.).
   *
   * @param {Element} root
   * @return {string}
   */
  function existingEmbedUrl(root) {
    if (!root || !root.querySelector) {
      return '';
    }
    var iframe = root.querySelector(
      'iframe[src*="youtube"], iframe[src*="youtu.be"], iframe[src*="youtube-nocookie"], iframe[src*="vimeo"],' +
        'iframe[data-src*="youtube"], iframe[data-src*="youtu.be"], iframe[data-src*="youtube-nocookie"], iframe[data-src*="vimeo"]'
    );
    if (!iframe) {
      return '';
    }
    return String(iframe.getAttribute('src') || iframe.getAttribute('data-src') || '').trim();
  }

  /**
   * Build a plain iframe for non-Elementor empty shells only.
   * Do not invent Elementor background cover CSS — that breaks native styling.
   *
   * @param {string} src
   * @param {string} title
   * @return {HTMLIFrameElement}
   */
  function createConsentVideoIframe(src, title) {
    var iframe = document.createElement('iframe');
    iframe.className = 'elementor-video-iframe';
    iframe.setAttribute('src', src);
    iframe.setAttribute('title', title || 'Video player');
    iframe.setAttribute('frameborder', '0');
    iframe.setAttribute(
      'allow',
      'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen'
    );
    iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    iframe.style.cssText = 'width:100%;height:100%;border:0;';
    return iframe;
  }

  /**
   * Elementor-style cover size (px width/height), matching disabled-plugin markup.
   *
   * @param {HTMLIFrameElement} iframe
   * @param {Element} container
   */
  function sizeElementorBackgroundIframe(iframe, container) {
    var cw = (container && (container.clientWidth || container.offsetWidth)) || 0;
    var ch = (container && (container.clientHeight || container.offsetHeight)) || 0;
    if (cw < 2 || ch < 2) {
      cw = window.innerWidth || 1920;
      ch = window.innerHeight || 1080;
    }
    var ratio = 16 / 9;
    var width;
    var height;
    if (cw / ch > ratio) {
      width = cw;
      height = cw / ratio;
    } else {
      height = ch;
      width = ch * ratio;
    }
    // Slight overscan like Elementor's Vimeo background sizing.
    width = Math.ceil(width * 1.11);
    height = Math.ceil(height * 1.11);
    iframe.setAttribute('width', '426');
    iframe.setAttribute('height', '240');
    iframe.style.width = width + 'px';
    iframe.style.height = height + 'px';
  }

  /**
   * Player URL matching Elementor's background Vimeo/YouTube iframe (keep privacy hash).
   *
   * @param {string} link
   * @return {{src:string,isVimeo:boolean}}
   */
  function elementorBackgroundPlayerUrl(link) {
    var isVimeo = /vimeo/i.test(String(link || ''));
    var src = '';
    if (isVimeo) {
      src = vimeoEmbedSrc(link);
      if (src) {
        var join = src.indexOf('?') === -1 ? '?' : '&';
        // Same query shape Elementor emits (plus app_id Elementor adds).
        if (src.indexOf('autoplay=') === -1) {
          src += join + 'muted=1&autoplay=1&loop=1&background=1&app_id=122963';
        } else if (src.indexOf('app_id=') === -1) {
          src += '&app_id=122963';
        }
      }
    } else {
      src = youtubeEmbedSrc(link, { autoplay: 'yes', mute: 'yes', loop: 'yes' });
    }
    return { src: src, isVimeo: isVimeo };
  }

  /**
   * Inject background iframe that mirrors Elementor-disabled markup:
   * keep presentation div; sibling iframe.elementor-background-video-embed with px size.
   *
   * @param {Element} owner
   * @return {boolean}
   */
  function injectElementorNativeBackground(owner) {
    if (!owner) {
      return false;
    }
    var box = owner.querySelector('.elementor-background-video-container');
    if (!box) {
      return false;
    }
    // Already has a native Elementor iframe (not our old custom hydrate).
    var existing = box.querySelector('iframe.elementor-background-video-embed:not(.elementor-video-iframe)');
    if (existing && existing.getAttribute('src') && box.getAttribute('data-vimeo-initialized') === 'true') {
      return false;
    }

    var settings = parseDataSettings(owner) || {};
    var link = String(settings.background_video_link || '');
    if (!link) {
      return false;
    }
    var isVimeo = /vimeo/i.test(link);
    if (isVimeo && !hasCategoryConsent('functional')) {
      return false;
    }
    if (!isVimeo && !(hasCategoryConsent('marketing') && hasCategoryConsent('functional'))) {
      return false;
    }

    // Remove prior UCPF hydrates only.
    box.querySelectorAll('iframe.elementor-video-iframe').forEach(function (iframe) {
      iframe.remove();
    });
    // If a broken/partial iframe remains without native class sizing, replace it.
    box.querySelectorAll('iframe').forEach(function (iframe) {
      if (iframe.classList.contains('elementor-video-iframe')) {
        iframe.remove();
      }
    });
    if (box.querySelector('iframe.elementor-background-video-embed')) {
      return false;
    }

    var placeholder = box.querySelector('div.elementor-background-video-embed');
    if (!placeholder) {
      placeholder = document.createElement('div');
      placeholder.className = 'elementor-background-video-embed';
      placeholder.setAttribute('role', 'presentation');
      box.insertBefore(placeholder, box.firstChild);
    } else if (!placeholder.getAttribute('role')) {
      placeholder.setAttribute('role', 'presentation');
    }

    var built = elementorBackgroundPlayerUrl(link);
    if (!built.src) {
      return false;
    }

    var iframe = document.createElement('iframe');
    iframe.className = 'elementor-background-video-embed';
    iframe.setAttribute('src', built.src);
    iframe.setAttribute('frameborder', '0');
    iframe.setAttribute(
      'allow',
      'autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media; web-share'
    );
    iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    iframe.setAttribute('title', 'Background video');
    iframe.setAttribute('data-ready', 'true');
    sizeElementorBackgroundIframe(iframe, box);
    box.appendChild(iframe);
    box.setAttribute('data-vimeo-initialized', 'true');
    box.setAttribute('data-ucpf-bg-native', '1');
    owner.setAttribute('data-ucpf-bg-native', '1');
    owner.removeAttribute('data-ucpf-video-hydrated');
    box.removeAttribute('data-ucpf-video-hydrated');
    return true;
  }

  /**
   * Undo UCPF-injected background iframes and restore Elementor's empty embed shell.
   *
   * @param {Element} owner Container with background_video_link settings.
   */
  function restoreElementorBackgroundShell(owner) {
    if (!owner) {
      return null;
    }
    var box = owner.querySelector('.elementor-background-video-container');
    if (!box) {
      return null;
    }
    try {
      box.querySelectorAll('iframe.elementor-video-iframe').forEach(function (iframe) {
        iframe.remove();
      });
      var placeholder = box.querySelector('.elementor-background-video-embed[role="presentation"]');
      if (!placeholder) {
        placeholder = box.querySelector('div.elementor-background-video-embed:not(iframe)');
      }
      if (!placeholder) {
        placeholder = document.createElement('div');
        placeholder.className = 'elementor-background-video-embed';
        placeholder.setAttribute('role', 'presentation');
        box.insertBefore(placeholder, box.firstChild);
      }
      box.removeAttribute('data-ucpf-video-hydrated');
      owner.removeAttribute('data-ucpf-video-hydrated');
    } catch (eRestore) { /* ignore */ }
    return box;
  }

  function whenPlayerApiReady(isVimeo, cb) {
    var ready = function () {
      if (isVimeo) {
        return typeof window.Vimeo !== 'undefined';
      }
      return typeof window.YT !== 'undefined' || true;
    };
    if (ready()) {
      cb();
      return;
    }
    var n = 0;
    var t = window.setInterval(function () {
      n += 1;
      if (ready() || n > 50) {
        window.clearInterval(t);
        cb();
      }
    }, 100);
  }

  /**
   * Live YouTube / Vimeo iframe already playing inside a root.
   *
   * @param {Element} root
   * @return {boolean}
   */
  function hasLiveVideoPlayer(root) {
    if (!root || !root.querySelector) {
      return false;
    }
    return !!root.querySelector(
      'iframe[src*="youtube.com"], iframe[src*="youtu.be"], iframe[src*="youtube-nocookie.com"], iframe[src*="player.vimeo.com"], iframe[src*="vimeo.com"]'
    );
  }

  /**
   * Prefer a single Elementor-native player. Our late inject must not stack a
   * second iframe.elementor-video next to Elementor's (hard-refresh showed both).
   *
   * @param {Element} [root] Widget or document.
   */
  function dedupeElementorOpenInlineVideos(root) {
    var scope = root && root.querySelectorAll ? root : document;
    var widgets = [];
    if (
      scope.classList &&
      (scope.classList.contains('elementor-widget-video') || scope.getAttribute('data-widget_type') === 'video.default')
    ) {
      widgets = [scope];
    } else {
      widgets = Array.prototype.slice.call(
        scope.querySelectorAll
          ? scope.querySelectorAll('.elementor-widget-video, [data-widget_type="video.default"]')
          : []
      );
    }
    widgets.forEach(function (widget) {
      var wrap = widget.querySelector('.elementor-wrapper.elementor-open-inline') || widget;
      if (!wrap || !wrap.querySelectorAll) {
        return;
      }
      var iframes = Array.prototype.slice.call(
        wrap.querySelectorAll('iframe.elementor-video, iframe[src*="youtube"], iframe[src*="youtu.be"], iframe[src*="youtube-nocookie"], iframe[src*="vimeo"]')
      );
      if (iframes.length <= 1) {
        return;
      }
      var keep = null;
      // 1) Live Elementor player (not our inject).
      iframes.forEach(function (iframe) {
        if (keep) {
          return;
        }
        var src = iframe.getAttribute('src') || '';
        if (
          iframe.getAttribute('data-ucpf-injected-video') !== '1' &&
          src &&
          src !== 'about:blank' &&
          isVideoPlayerUrl(src)
        ) {
          keep = iframe;
        }
      });
      // 2) Any live player.
      if (!keep) {
        iframes.forEach(function (iframe) {
          if (keep) {
            return;
          }
          var src = iframe.getAttribute('src') || '';
          if (src && src !== 'about:blank' && isVideoPlayerUrl(src)) {
            keep = iframe;
          }
        });
      }
      // 3) Non-injected shell (parked Elementor frame).
      if (!keep) {
        iframes.forEach(function (iframe) {
          if (keep) {
            return;
          }
          if (iframe.getAttribute('data-ucpf-injected-video') !== '1') {
            keep = iframe;
          }
        });
      }
      if (!keep) {
        keep = iframes[0];
      }
      iframes.forEach(function (iframe) {
        if (iframe !== keep) {
          try {
            iframe.remove();
          } catch (eRm) { /* ignore */ }
        }
      });
    });
  }

  /**
   * Additional method for Elementor Video widgets in open-inline mode.
   * Restores the existing iframe.elementor-video when possible; only creates a
   * new frame when the shell is empty (and marks it so we can dedupe later).
   *
   * @param {Element} widget
   * @param {{ allowCreate?: boolean }} [opts]
   * @return {boolean}
   */
  function injectElementorOpenInlineVideo(widget, opts) {
    opts = opts || {};
    var allowCreate = opts.allowCreate !== false;

    if (!widget || !widget.querySelector) {
      return false;
    }
    if (widget.querySelector('.elementor-background-video-container') || isBackgroundVideoOwner(widget)) {
      return false;
    }
    if (isSelfHostedVideoSurface(widget)) {
      return false;
    }

    var wrap = widget.querySelector('.elementor-wrapper.elementor-open-inline');
    if (!wrap) {
      return false;
    }

    // Already have a live player — only strip duplicates.
    if (hasLiveVideoPlayer(wrap) || hasLiveVideoPlayer(widget)) {
      dedupeElementorOpenInlineVideos(widget);
      return true;
    }

    var settings = parseDataSettings(widget) || {};
    var videoType = String(settings.video_type || '').toLowerCase();
    if (videoType === 'hosted') {
      return false;
    }

    var ytLink = String(settings.youtube_url || '');
    var vimLink = String(settings.vimeo_url || '');
    var isVimeo =
      videoType === 'vimeo' ||
      (!!vimLink && !ytLink) ||
      /vimeo/i.test(vimLink || existingEmbedUrl(wrap) || existingEmbedUrl(widget));

    if (isVimeo) {
      if (!hasCategoryConsent('functional')) {
        return false;
      }
    } else if (!(hasCategoryConsent('marketing') && hasCategoryConsent('functional'))) {
      return false;
    }

    // Prefer Elementor's exact parked URL (youtube.com/…) over rebuilding nocookie.
    var src = existingEmbedUrl(wrap) || existingEmbedUrl(widget);
    if (!src || !isVideoPlayerUrl(src)) {
      if (isVimeo) {
        src = vimeoEmbedSrc(vimLink || ytLink);
      } else {
        src = youtubeEmbedSrc(ytLink || vimLink, settings);
      }
    }
    if (!src) {
      return false;
    }

    var iframe =
      wrap.querySelector('iframe.elementor-video:not([data-ucpf-injected-video])') ||
      wrap.querySelector('iframe.elementor-video') ||
      wrap.querySelector('iframe[data-ucpf-parked="1"]') ||
      wrap.querySelector('iframe[data-src]') ||
      wrap.querySelector('iframe');

    if (iframe) {
      if (!iframe.classList.contains('elementor-video')) {
        iframe.classList.add('elementor-video');
      }
      if (!forceVideoIframeSrc(iframe, src)) {
        return false;
      }
      dedupeElementorOpenInlineVideos(widget);
      return true;
    }

    // Empty shell only — wait for Elementor unless allowCreate (late fallback).
    if (!allowCreate) {
      return false;
    }

    iframe = document.createElement('iframe');
    iframe.className = 'elementor-video';
    iframe.setAttribute('data-ucpf-injected-video', '1');
    iframe.setAttribute('frameborder', '0');
    iframe.setAttribute('allowfullscreen', '');
    iframe.setAttribute(
      'allow',
      'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share'
    );
    iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    iframe.setAttribute('title', 'Video player');
    iframe.setAttribute('width', '640');
    iframe.setAttribute('height', '360');
    wrap.appendChild(iframe);
    if (!forceVideoIframeSrc(iframe, src)) {
      try {
        iframe.remove();
      } catch (eRm) { /* ignore */ }
      return false;
    }

    widget.setAttribute('data-ucpf-open-inline', '1');
    wrap.setAttribute('data-ucpf-open-inline', '1');
    dedupeElementorOpenInlineVideos(widget);
    return true;
  }

  /**
   * After consent: try Elementor handlers, then native-matching iframe fallback.
   *
   * @return {boolean}
   */
  function reinitElementorVideos() {
    var hasEl =
      !!(
        window.elementorFrontend &&
        elementorFrontend.elementsHandler &&
        typeof elementorFrontend.elementsHandler.runReadyTrigger === 'function'
      );
    var run = function (el) {
      if (!hasEl) {
        return;
      }
      try {
        elementorFrontend.elementsHandler.runReadyTrigger(el);
      } catch (eRun) { /* ignore */ }
    };

    queryAll(['[data-settings*="background_video_link"]']).forEach(function (owner) {
      var box = owner.querySelector('.elementor-background-video-container');
      if (!box) {
        return;
      }
      // Native Elementor success — leave alone.
      if (
        owner.getAttribute('data-ucpf-bg-native') === '1' ||
        box.getAttribute('data-ucpf-bg-native') === '1'
      ) {
        var native = box.querySelector('iframe.elementor-background-video-embed:not(.elementor-video-iframe)');
        if (native && native.getAttribute('src')) {
          return;
        }
      }
      if (
        box.getAttribute('data-vimeo-initialized') === 'true' &&
        box.querySelector('iframe.elementor-background-video-embed:not(.elementor-video-iframe)') &&
        owner.getAttribute('data-ucpf-video-hydrated') !== '1'
      ) {
        return;
      }

      // Strip broken UCPF custom iframes; keep/restore presentation div.
      restoreElementorBackgroundShell(owner);
      box.removeAttribute('data-vimeo-initialized');
      run(owner);

      // Elementor often will not re-bind background video after a failed first pass.
      // Fall back to markup that matches plugin-disabled Elementor output.
      window.setTimeout(function () {
        if (box.querySelector('iframe.elementor-background-video-embed:not(.elementor-video-iframe)')) {
          return;
        }
        whenPlayerApiReady(/vimeo/i.test(String((parseDataSettings(owner) || {}).background_video_link || '')), function () {
          injectElementorNativeBackground(owner);
        });
      }, 350);
    });

    queryAll(['.elementor-widget-video', '[data-widget_type="video.default"]']).forEach(function (widget) {
      // Always restore parked Elementor frames first; never invent a sibling yet.
      restoreParkedVideoIframes(widget);
      dedupeElementorOpenInlineVideos(widget);

      if (widget.getAttribute('data-ucpf-video-hydrated') === '1') {
        try {
          widget.querySelectorAll('iframe.elementor-video-iframe').forEach(function (iframe) {
            iframe.remove();
          });
          widget.removeAttribute('data-ucpf-video-hydrated');
        } catch (eW) { /* ignore */ }
      }

      if (hasLiveVideoPlayer(widget)) {
        dedupeElementorOpenInlineVideos(widget);
        return;
      }

      run(widget);
      // Restore-only passes while Elementor remounts.
      window.setTimeout(function () {
        restoreParkedVideoIframes(widget);
        injectElementorOpenInlineVideo(widget, { allowCreate: false });
        dedupeElementorOpenInlineVideos(widget);
      }, 200);
      // Late create only if Elementor left the open-inline shell empty.
      window.setTimeout(function () {
        if (!hasLiveVideoPlayer(widget)) {
          injectElementorOpenInlineVideo(widget, { allowCreate: true });
        }
        dedupeElementorOpenInlineVideos(widget);
      }, 1200);
    });

    return hasEl;
  }

  /**
   * Ensure the mount has sizing so the absolute iframe is visible.
   * @param {Element} mount
   * @param {Element} widget
   */
  function ensureVideoMountBox(mount, widget) {
    if (!mount) {
      return;
    }
    try {
      var cs = window.getComputedStyle(mount);
      if (cs.position === 'static') {
        mount.style.position = 'relative';
      }
      var h = mount.getBoundingClientRect().height;
      if (h < 40) {
        var wrap = widget && widget.querySelector ? widget.querySelector('.elementor-wrapper') : null;
        if (wrap && wrap.classList.contains('elementor-fit-aspect-ratio')) {
          /* Elementor aspect-ratio CSS should size it */
        } else if (!mount.style.paddingBottom && !mount.style.aspectRatio) {
          mount.style.aspectRatio = '16 / 9';
          mount.style.width = '100%';
          mount.style.minHeight = '200px';
        }
      }
    } catch (eBox) {
      /* ignore */
    }
  }

  /**
   * Elementor / Divi / Gutenberg leave empty shells until their player JS runs.
   * After Marketing + Embeds consent, inject the iframe ourselves (Shorts-safe).
   */
  function hydrateBuilderVideos() {
    var allowYt = hasCategoryConsent('marketing') && hasCategoryConsent('functional');
    var allowVim = hasCategoryConsent('functional');
    if (!allowYt && !allowVim) {
      return 0;
    }

    var count = 0;

    function alreadyHasPlayer(root) {
      if (!root || !root.querySelector) {
        return true;
      }
      // Live or deferred (data-src) players — never clobber; loader restores data-src verbatim.
      return !!(
        root.querySelector(
          'iframe[src*="youtube"], iframe[src*="youtu.be"], iframe[src*="youtube-nocookie"], iframe[src*="vimeo"],' +
            'iframe[data-src*="youtube"], iframe[data-src*="youtu.be"], iframe[data-src*="youtube-nocookie"], iframe[data-src*="vimeo"]'
        ) || root.querySelector('video[src], video source[src]')
      );
    }

    function resolveEmbedSrc(root, mount, built) {
      var exact = existingEmbedUrl(root) || existingEmbedUrl(mount);
      if (exact && /youtube|youtu\.be|vimeo/i.test(exact)) {
        return exact;
      }
      return built || '';
    }

    function injectInto(mount, src, title, widget) {
      if (!mount || !src || mount.querySelector('iframe')) {
        return false;
      }
      ensureVideoMountBox(mount, widget);
      mount.appendChild(createConsentVideoIframe(src, title));
      mount.setAttribute('data-ucpf-video-hydrated', '1');
      return true;
    }

    // Elementor Video widgets — never invent markup; Elementor sizes/styles natively.
    // (Handled in reinitElementorVideos / runReadyTrigger below.)

    // Do NOT inject Elementor background videos here — that breaks Elementor's
    // cover sizing (width/height px). reinitElementorVideos restores the shell
    // and runs Elementor's own Vimeo/YouTube background handler.

    // Gutenberg / Divi / WPBakery / Bricks empty shells with URL in attrs/classes.
    queryAll([
      '.wp-block-embed-youtube',
      '.wp-block-embed.is-provider-youtube',
      '.wp-block-embed-vimeo',
      '.wp-block-embed.is-provider-vimeo',
      '.et_pb_video',
      '.wpb_video_widget',
      '.fl-module-video',
      '.brxe-video',
    ]).forEach(function (shell) {
      if (alreadyHasPlayer(shell) || shell.getAttribute('data-ucpf-video-hydrated') === '1') {
        return;
      }
      // Skip if this shell is inside Elementor — Elementor owns those.
      if (shell.closest && shell.closest('.elementor-element, .elementor')) {
        return;
      }
      var blob =
        (shell.getAttribute('data-src') || '') +
        ' ' +
        (shell.getAttribute('data-url') || '') +
        ' ' +
        (shell.getAttribute('data-video-url') || '') +
        ' ' +
        (shell.innerHTML || '');
      var mount =
        shell.querySelector('.wp-block-embed__wrapper') ||
        shell.querySelector('.et_pb_video_box') ||
        shell.querySelector('.fluid-width-video-wrapper') ||
        shell;
      var src = '';
      var cls = (shell.className || '').toLowerCase();
      var built = '';
      if (/vimeo/.test(cls + blob)) {
        if (!allowVim) {
          return;
        }
        var vm = blob.match(/https?:\/\/[^\s"'<>]*vimeo\.com\/[^\s"'<>]+/i);
        built = vimeoEmbedSrc(vm ? vm[0] : blob);
      } else {
        if (!allowYt) {
          return;
        }
        var ym = blob.match(/https?:\/\/[^\s"'<>]*(?:youtube\.com|youtu\.be)\/[^\s"'<>]+/i);
        built = youtubeEmbedSrc(ym ? ym[0] : blob, {});
      }
      src = resolveEmbedSrc(shell, mount, built);
      if (injectInto(mount, src, 'Embedded video', shell)) {
        shell.setAttribute('data-ucpf-video-hydrated', '1');
        count += 1;
      }
    });

    return count;
  }

  function ensureVideosIfNeeded() {
    // Always hydrate videos after consent — leaveBuildersAlone only skips layout/Motion FX
    // recovery, never GDPR unlock for YouTube/Vimeo (same rule as maps).
    if (!hasCategoryConsent('marketing') || !hasCategoryConsent('functional')) {
      return;
    }
    var runRestore = function () {
      restoreAllParkedVideoIframes();
      dedupeElementorOpenInlineVideos(document);
    };
    var runHydrate = function (allowCreate) {
      restoreAllParkedVideoIframes();
      reinitElementorVideos();
      hydrateBuilderVideos();
      queryAll(['.elementor-widget-video', '[data-widget_type="video.default"]']).forEach(function (widget) {
        injectElementorOpenInlineVideo(widget, { allowCreate: !!allowCreate });
        dedupeElementorOpenInlineVideos(widget);
      });
      dedupeElementorOpenInlineVideos(document);
    };
    // Immediate: put Elementor's parked URL back. Do not invent a second iframe yet.
    runRestore();
    whenPlayerApiReady(true, function () {
      runHydrate(false);
    });
    window.setTimeout(function () {
      runHydrate(false);
    }, 300);
    window.setTimeout(function () {
      runHydrate(false);
    }, 800);
    // Late fallback create only if Elementor never mounted a player.
    window.setTimeout(function () {
      runHydrate(true);
    }, 1400);
    window.setTimeout(function () {
      runHydrate(true);
    }, 2500);
  }

  function videoHostContainer(node) {
    if (!node) {
      return null;
    }
    // Prefer a sized shell so the overlay has layout (empty .elementor-video is fine).
    if (node.classList) {
      if (node.classList.contains('elementor-widget-video') || node.getAttribute('data-widget_type') === 'video.default') {
        return node;
      }
      if (
        isBackgroundVideoOwner(node) ||
        node.classList.contains('elementor-background-video-container') ||
        node.classList.contains('elementor-background-video-embed')
      ) {
        return resolveBackgroundVideoHost(node);
      }
      if (node.classList.contains('elementor-wrapper') || node.classList.contains('elementor-video')) {
        return node.closest('.elementor-widget-video') || node.closest('[data-widget_type="video.default"]') || node;
      }
      if (
        node.classList.contains('et_pb_video') ||
        node.classList.contains('wpb_video_widget') ||
        node.classList.contains('fl-module-video') ||
        node.classList.contains('brxe-video') ||
        node.classList.contains('wp-block-embed-youtube') ||
        node.classList.contains('wp-block-embed-vimeo') ||
        node.classList.contains('wp-block-video') ||
        node.classList.contains('wp-block-embed')
      ) {
        return node;
      }
    }
    if (node.tagName === 'IFRAME' && node.parentElement) {
      var parent = node.parentElement;
      if (parent.classList.contains('ucpf-consent-guard')) {
        return node;
      }
      // Prefer known media shells; otherwise guard the iframe itself (avoid wrapping huge parents).
      if (
        parent.classList.contains('elementor-wrapper') ||
        parent.classList.contains('elementor-video') ||
        parent.classList.contains('elementor-fit-aspect-ratio') ||
        parent.classList.contains('wp-block-embed__wrapper') ||
        parent.classList.contains('fluidvids') ||
        parent.classList.contains('et_pb_video_box') ||
        parent.classList.contains('fl-video')
      ) {
        return (
          parent.closest('.elementor-widget-video') ||
          parent.closest('[data-widget_type="video.default"]') ||
          parent.closest('.et_pb_video') ||
          parent.closest('.wp-block-embed') ||
          parent
        );
      }
      return node;
    }
    return node;
  }

  /**
   * True for blocked library scripts / JS APIs — not playable embeds.
   *
   * @param {string} src
   * @return {boolean}
   */
  function isNonVisualMediaUrl(src) {
    src = String(src || '').toLowerCase();
    if (!src) {
      return false;
    }
    if (/\/api\/player\.js/.test(src)) {
      return true;
    }
    // Script assets ending in .js — keep player.vimeo.com/video/... allowed.
    if (/\.js(\?|#|$)/.test(src)) {
      return true;
    }
    return false;
  }

  /**
   * Surface overlays only belong on visual embeds (iframe/map/shell), never on
   * blocked library scripts (e.g. GTM4WP player.vimeo.com/api/player.js).
   *
   * @param {Element} node
   * @return {boolean}
   */
  function isVisualEmbedSurface(node) {
    if (!node || !node.tagName) {
      return false;
    }
    if (isForbiddenGuardHost(node)) {
      return false;
    }
    var tag = node.tagName.toUpperCase();
    if (
      tag === 'SCRIPT' ||
      tag === 'LINK' ||
      tag === 'STYLE' ||
      tag === 'META' ||
      tag === 'NOSCRIPT' ||
      tag === 'TEMPLATE' ||
      tag === 'SOURCE' ||
      tag === 'BASE'
    ) {
      return false;
    }
    if (/gtm4wp/i.test(node.id || '') || /gtm4wp/i.test(node.className || '')) {
      return false;
    }
    var src =
      node.getAttribute('data-src') ||
      node.getAttribute('src') ||
      node.getAttribute('data-lazy-src') ||
      '';
    if (isNonVisualMediaUrl(src)) {
      return false;
    }
    return true;
  }

  /** Tear down guards wrongly applied to scripts / empty non-visual wrappers. */
  function teardownNonVisualGuards() {
    teardownDocumentChromeGuards();
    // Heal any cover accidentally placed on the accessibility toolbar.
    try {
      queryAll([
        '#userwayAccessibilityIcon.ucpf-consent-guard',
        '.userway_buttons_wrapper.ucpf-consent-guard',
        '[id*="userway"].ucpf-consent-guard',
        '[class*="userway"].ucpf-consent-guard',
        '.uwy.ucpf-consent-guard',
      ]).forEach(function (el) {
        removeGuard(el);
      });
    } catch (eUwHeal) { /* ignore */ }
    // Un-park UserWay scripts left gated by older builds (Preferences / Embeds).
    try {
      queryAll([
        'script[data-src*="userway"]',
        'script[data-src*="cdn.userway.org"]',
        'script[type="text/plain"][data-src*="userway"]',
      ]).forEach(function (script) {
        var src = script.getAttribute('data-src') || '';
        if (!isUserWayUrl(src)) {
          return;
        }
        if (script.getAttribute('src')) {
          script.removeAttribute('data-ucpf-gated');
          script.removeAttribute('data-ucpf-category');
          script.removeAttribute('data-ucpf-service');
          return;
        }
        script.type = 'text/javascript';
        script.src = src;
        script.removeAttribute('data-src');
        script.removeAttribute('data-ucpf-gated');
        script.removeAttribute('data-ucpf-category');
        script.removeAttribute('data-ucpf-service');
      });
    } catch (eUwPark) { /* ignore */ }
    queryAll(['script[data-ucpf-consent-guarded="1"]']).forEach(function (script) {
      removeGuard(script);
    });
    // Migrate legacy overlays that decorated the Elementor video fill instead of the e-con owner.
    queryAll(['.elementor-background-video-container.ucpf-consent-guard']).forEach(function (box) {
      if (box.getAttribute('data-ucpf-consent-guarded') === '1') {
        removeGuard(box);
      }
    });
    // Older builds forced position:absolute !important + --embed on bg e-cons (broke layout).
    queryAll(['.ucpf-consent-guard--bg']).forEach(function (host) {
      host.classList.remove('ucpf-consent-guard--embed', 'ucpf-consent-guard--form');
      if (host.getAttribute('data-ucpf-bg-pos') === '1') {
        host.style.removeProperty('position');
        host.removeAttribute('data-ucpf-bg-pos');
      }
    });
    queryAll(['.ucpf-consent-guard--embed']).forEach(function (wrap) {
      // In-place shells (Elementor widget etc.) keep their own markup children — never tear those down here.
      if (wrap.getAttribute('data-ucpf-guard-shell') === '1') {
        return;
      }
      // Heal wraps that somehow contain <body>.
      for (var bi = 0; bi < wrap.children.length; bi++) {
        if (wrap.children[bi] === document.body || String(wrap.children[bi].tagName || '').toUpperCase() === 'BODY') {
          teardownDocumentChromeGuards();
          return;
        }
      }
      var kids = [];
      for (var i = 0; i < wrap.children.length; i++) {
        var child = wrap.children[i];
        if (!child.classList.contains('ucpf-consent-guard__panel')) {
          kids.push(child);
        }
      }
      if (kids.length === 1 && !isVisualEmbedSurface(kids[0])) {
        removeGuard(kids[0]);
      }
    });
  }

  /**
   * Heal catastrophic wraps: consent guard that reparented <body> (breaks Elementor MO/sticky).
   */
  function teardownDocumentChromeGuards() {
    try {
      var body = document.body;
      if (body && body.parentElement && body.parentElement.classList && body.parentElement.classList.contains('ucpf-consent-guard')) {
        var wrap = body.parentElement;
        var grand = wrap.parentNode;
        if (grand) {
          while (wrap.firstChild) {
            var child = wrap.firstChild;
            if (child.classList && child.classList.contains('ucpf-consent-guard__panel')) {
              wrap.removeChild(child);
              continue;
            }
            grand.insertBefore(child, wrap);
          }
          if (wrap.parentNode === grand) {
            grand.removeChild(wrap);
          }
        }
      }
    } catch (eUnwrap) { /* ignore */ }

    [document.documentElement, document.head, document.body].forEach(function (el) {
      if (!el || !el.classList) {
        return;
      }
      try {
        el.classList.remove(
          'ucpf-consent-guard',
          'ucpf-captcha-guard',
          'ucpf-consent-guard--active',
          'ucpf-consent-guard--embed',
          'ucpf-consent-guard--form',
          'ucpf-consent-guard--bg'
        );
        el.removeAttribute('data-ucpf-consent-guarded');
        el.removeAttribute('data-ucpf-guard-shell');
        el.removeAttribute('data-ucpf-guard-kind');
        el.removeAttribute('data-ucpf-guard-category');
        el.removeAttribute('data-ucpf-size-locked');
        el.removeAttribute('data-ucpf-guard-min-h');
        el.style.removeProperty('min-height');
        var panels = [];
        try {
          panels = el.querySelectorAll(':scope > .ucpf-consent-guard__panel');
        } catch (eScope) {
          for (var pi = 0; pi < el.children.length; pi++) {
            if (el.children[pi].classList && el.children[pi].classList.contains('ucpf-consent-guard__panel')) {
              panels.push(el.children[pi]);
            }
          }
        }
        Array.prototype.forEach.call(panels, function (p) {
          if (p && p.parentNode === el) {
            p.parentNode.removeChild(p);
          }
        });
      } catch (eClean) { /* ignore */ }
    });
  }

  /**
   * True when a form contains any captcha / security widget signal
   * (covers custom themes that invent class names).
   *
   * @param {Element} form
   * @return {boolean}
   */
  function formHasCaptchaSignal(form) {
    if (!form || !form.querySelector) {
      return false;
    }
    var i;
    for (i = 0; i < CAPTCHA_MARKERS.length; i++) {
      try {
        if (form.querySelector(CAPTCHA_MARKERS[i])) {
          return true;
        }
      } catch (e1) {
        /* invalid selector */
      }
    }
    for (i = 0; i < CAPTCHA_FORM_INNER_SELECTORS.length; i++) {
      try {
        if (form.querySelector(CAPTCHA_FORM_INNER_SELECTORS[i])) {
          return true;
        }
      } catch (e2) {
        /* invalid selector */
      }
    }
    // Name / URL heuristics for unknown markup.
    var named = form.querySelectorAll('[name], iframe[src], iframe[data-src], script[src], script[data-src]');
    for (i = 0; i < named.length; i++) {
      var el = named[i];
      var blob = (
        (el.getAttribute('name') || '') +
        ' ' +
        (el.getAttribute('src') || '') +
        ' ' +
        (el.getAttribute('data-src') || '') +
        ' ' +
        (el.getAttribute('data-ucpf-category') || '')
      ).toLowerCase();
      if (/(recaptcha|h-captcha|hcaptcha|cf-turnstile|turnstile|frc-|friendlycaptcha)/.test(blob)) {
        return true;
      }
      if (
        el.getAttribute('data-ucpf-category') === 'security' &&
        /(recaptcha|hcaptcha|turnstile|friendlycaptcha|challenges\.cloudflare)/.test(blob)
      ) {
        return true;
      }
    }
    return false;
  }

  function collectTargets() {
    /** @type {{ target: Element, kind: string, category: string, categories: string[], mode: string }[]} */
    var items = [];
    var seen = [];

    function push(target, kind, category, mode, categories) {
      if (!target || isForbiddenGuardHost(target) || isUserWayNode(target)) {
        return;
      }
      if (mode === 'embed' && !isVisualEmbedSurface(target)) {
        return;
      }
      if (mode === 'embed' && isEffectivelyHidden(target)) {
        return;
      }
      for (var i = 0; i < seen.length; i++) {
        if (seen[i] === target) {
          return;
        }
      }
      seen.push(target);
      var cats = Array.isArray(categories) && categories.length ? categories : category ? [category] : [];
      items.push({
        target: target,
        kind: kind,
        category: category || cats[0] || '',
        categories: cats,
        mode: mode,
      });
    }

    // WooCommerce checkout first — one combined Security + Embeds panel (no stacked overlays).
    queryAll(CHECKOUT_SELECTORS).forEach(function (node) {
      var host = node;
      if (node.tagName !== 'FORM') {
        host = (node.querySelector && (node.querySelector('form.checkout') || node.querySelector('form.woocommerce-checkout'))) || node;
      }
      push(host, 'checkout', 'functional', 'form', ['security', 'functional']);
    });

    // CAPTCHA-backed forms → Security (skip checkout hosts — already covered above).
    // Always cover builder-hosted forms (Elementor etc.) — leaveBuildersAlone must
    // never suppress GDPR surface overlays.
    queryAll(CAPTCHA_MARKERS).forEach(function (node) {
      var form = findFormForNode(node);
      if (isCheckoutSurface(form) || isCheckoutSurface(node)) {
        return;
      }
      push(form, 'captcha', 'security', 'form', ['security']);
    });

    // Any <form> with captcha-ish descendants (custom themes / unknown plugins).
    Array.prototype.forEach.call(document.querySelectorAll('form'), function (form) {
      if (isCheckoutSurface(form)) {
        return;
      }
      if (formHasCaptchaSignal(form)) {
        push(form, 'captcha', 'security', 'form', ['security']);
      }
    });

    // Gravity Forms wrappers (AJAX / Elementor shortcode) — cover even if markers sit oddly.
    queryAll(['.gform_wrapper', 'form[id^="gform_"]']).forEach(function (node) {
      if (isCheckoutSurface(node)) {
        return;
      }
      var form =
        node.tagName === 'FORM'
          ? node
          : (node.querySelector && (node.querySelector('form[id^="gform_"]') || node.querySelector('form'))) || node;
      if (
        formHasCaptchaSignal(form) ||
        (form.querySelector &&
          form.querySelector(
            '.gfield--type-captcha, .ginput_recaptcha, .gform_recaptcha, .gform_captcha, [data-sitekey]'
          ))
      ) {
        push(form, 'captcha', 'security', 'form', ['security']);
      }
    });

    // Catalog / blocker placeholders + network-gate parked iframes.
    queryAll([
      '.ucpf-iframe-placeholder[data-ucpf-category]',
      'iframe[data-ucpf-category][data-src]',
      'iframe[data-ucpf-gated="1"]',
      'iframe[data-ucpf-category]',
    ]).forEach(function (node) {
      var category = node.getAttribute('data-ucpf-category') || 'marketing';
      var kind = kindForPlaceholder(node);
      if (kind === 'embed' && category === 'functional') {
        kind = 'widget';
      }
      var cats =
        kind === 'youtube' || kind === 'vimeo' || kind === 'widget' || kind === 'calendly' || kind === 'map' || kind === 'embed'
          ? ensureEmbedConsentCategories([category])
          : [category];
      var host = node;
      if (node.tagName === 'IFRAME' && node.parentElement) {
        host = widgetEmbedHostContainer(node) || node.parentElement;
      }
      push(host, kind, category, 'embed', cats);
    });

    // Live map widgets / iframes not yet replaced.
    queryAll(MAP_MARKERS).forEach(function (node) {
      var host = node;
      if (node.tagName === 'IFRAME' && node.parentElement) {
        host = node.parentElement.classList.contains('ucpf-consent-guard')
          ? node
          : node.parentElement;
      }
      push(host, 'map', 'functional', 'embed', ensureEmbedConsentCategories(['functional']));
    });

    // Calendly inline widgets / iframes (Elementor popups inject these late).
    queryAll(CALENDLY_MARKERS).forEach(function (node) {
      if (isEffectivelyHidden(node)) {
        return;
      }
      var host = calendlyHostContainer(node);
      if (host) {
        push(host, 'calendly', 'functional', 'embed', ensureEmbedConsentCategories(['functional']));
      }
    });

    // Amelia Booking — first-party SPA (Gravity Forms model): Security overlay only
    // when captcha is configured. Never treat as a third-party embed / don't park scripts.
    queryAll(AMELIA_FORM_MARKERS).forEach(function (node) {
      if (isEffectivelyHidden(node)) {
        return;
      }
      var host = node.id === 'amelia-container' ? node : (node.closest && node.closest('#amelia-container')) || node;
      // Always cover booking until Security — Amelia loads reCAPTCHA with the form (HAR).
      push(host, 'captcha', 'security', 'form', ['security']);
    });

    // Jobber / Typeform / similar booking & form embeds.
    queryAll(WIDGET_EMBED_MARKERS).forEach(function (node) {
      if (isEffectivelyHidden(node)) {
        return;
      }
      var host = widgetEmbedHostContainer(node);
      if (host) {
        push(host, 'widget', 'functional', 'embed', ensureEmbedConsentCategories(['functional']));
      }
    });

    // Gated embed scripts (Jobber CloudFront snippet, etc.) — cover the host box
    // even when the iframe has not been injected yet.
    queryAll(['script[data-ucpf-gated="1"]', 'script[data-ucpf-category][data-src]', 'script[type="text/plain"][data-src]']).forEach(
      function (script) {
        if (isUserWayNode(script) || isUserWayUrl(script.getAttribute('data-src') || script.getAttribute('src') || '')) {
          return;
        }
        if (!isEmbedLikeScript(script)) {
          return;
        }
        var host = widgetEmbedHostContainer(script);
        if (!host || isEffectivelyHidden(host) || isUserWayNode(host)) {
          return;
        }
        var category = script.getAttribute('data-ucpf-category') || 'functional';
        if (category !== 'functional' && category !== 'marketing' && category !== 'analytics') {
          category = 'functional';
        }
        push(host, 'widget', category, 'embed', ensureEmbedConsentCategories([category]));
      }
    );
    // YouTube / Vimeo always — including Elementor when leaveBuildersAlone is on.
    // Layout skips stay elsewhere; video covers must still run before consent.
    // Same-origin / Elementor Self Hosted media must never be covered.
    queryAll(VIDEO_MARKERS).forEach(function (node) {
      if (!isVisualEmbedSurface(node)) {
        return;
      }
      if (isSelfHostedVideoSurface(node)) {
        return;
      }
      var detected = detectVideoProvider(node);
      if (!detected) {
        var src = (node.getAttribute('src') || node.getAttribute('data-src') || '').toLowerCase();
        if (src && isSameOriginMediaUrl(src)) {
          return;
        }
        if (src.indexOf('vimeo') !== -1) {
          detected = { kind: 'vimeo', category: 'functional' };
        } else if (src && isVideoPlayerUrl(src)) {
          detected = { kind: 'youtube', category: 'marketing' };
        }
      }
      if (detected) {
        var host = videoHostContainer(node);
        if (host && isSelfHostedVideoSurface(host)) {
          return;
        }
        if (host && isVisualEmbedSurface(host)) {
          push(host, detected.kind, detected.category, 'embed', ensureVideoConsentCategories([detected.category]));
        }
      }
    });

    // Builder shells (Elementor/Divi/Gutenberg/etc.) — iframe often injected later.
    queryAll(VIDEO_SHELL_SELECTORS).forEach(function (node) {
      if (isEffectivelyHidden(node)) {
        return;
      }
      if (isSelfHostedVideoSurface(node)) {
        return;
      }
      var detected = detectVideoProvider(node);
      if (!detected) {
        var cls = (node.className || '').toLowerCase();
        if (cls.indexOf('vimeo') !== -1) {
          detected = { kind: 'vimeo', category: 'functional' };
        } else if (cls.indexOf('youtube') !== -1) {
          detected = { kind: 'youtube', category: 'marketing' };
        }
      }
      if (!detected && (node.classList.contains('elementor-widget-video') || node.getAttribute('data-widget_type') === 'video.default')) {
        // Never invent YouTube for Self Hosted / same-origin Elementor videos.
        if (isSelfHostedVideoSurface(node)) {
          return;
        }
        var s = parseDataSettings(node);
        var vt = s && String(s.video_type || '').toLowerCase();
        if (vt === 'hosted' || vt === 'self' || vt === 'self_hosted' || vt === 'file') {
          return;
        }
        if (vt === 'vimeo' || (s && s.vimeo_url)) {
          detected = { kind: 'vimeo', category: 'functional' };
        } else if (vt === 'youtube' || (s && (s.youtube_url || s.youtube_id))) {
          detected = { kind: 'youtube', category: 'marketing' };
        } else {
          detected = detectVideoProvider(node);
        }
      }
      if (!detected && isBackgroundVideoShell(node)) {
        detected = detectVideoProvider(node);
      }
      if (detected) {
        var host = videoHostContainer(node);
        if (isBackgroundVideoShell(node) || (host && isBackgroundVideoShell(host))) {
          host = resolveBackgroundVideoHost(node);
        }
        if (host && isSelfHostedVideoSurface(host)) {
          return;
        }
        push(host, detected.kind, detected.category, 'embed', ensureVideoConsentCategories([detected.category]));
      }
    });

    queryAll(['[data-settings*="background_video_link"]']).forEach(function (node) {
      if (isEffectivelyHidden(node)) {
        return;
      }
      if (isSelfHostedVideoSurface(node)) {
        return;
      }
      var box = node.querySelector('.elementor-background-video-container');
      if (box && isEffectivelyHidden(box)) {
        return;
      }
      var detected = detectVideoProvider(node) || (box ? detectVideoProvider(box) : null);
      if (detected) {
        push(
          resolveBackgroundVideoHost(node),
          detected.kind,
          detected.category,
          'embed',
          ensureVideoConsentCategories([detected.category])
        );
      }
    });

    return items;
  }

  function activateLoaderFallback() {
    if (window.UCPFLoader && typeof window.UCPFLoader.applyConsent === 'function' && window.UCPF) {
      try {
        window.UCPFLoader.applyConsent(window.UCPF.getConsent());
      } catch (e) {
        /* ignore */
      }
    }
  }

  var refreshBusy = false;
  function refresh() {
    if (window.__ucpfDiscover || refreshBusy) {
      return;
    }
    refreshBusy = true;
    try {
      teardownNonVisualGuards();
      var items = collectTargets();
      items.forEach(function (item) {
        var cats = item.categories && item.categories.length ? item.categories : [item.category];
        if (hasAllCategories(cats)) {
          removeGuard(item.target);
        } else {
          applyGuard(item.target, item.kind, item.category, item.mode, cats);
        }
      });
    } finally {
      refreshBusy = false;
    }
  }

  function ensureCalendlyIfNeeded() {
    if (!hasCategoryConsent('functional')) {
      return;
    }
    var nodes = document.querySelectorAll('.calendly-inline-widget[data-url], .calendly-badge-widget[data-url]');
    if (!nodes.length) {
      return;
    }
    var needs = false;
    Array.prototype.forEach.call(nodes, function (el) {
      if (!el.querySelector || !el.querySelector('iframe')) {
        needs = true;
      }
    });
    if (needs) {
      reinitCalendlyWidgets();
    }
  }

  /**
   * After Security consent: captcha APIs may load after GF already tried once.
   * Re-render empty widgets and nudge Gravity Forms (same idea as Calendly).
   */
  function captchaWidgetNeedsRender(el) {
    if (!el) {
      return false;
    }
    var hasLiveFrame = !!(
      el.querySelector &&
      el.querySelector(
        'iframe[src*="recaptcha"], iframe[src*="hcaptcha"], iframe[src*="turnstile"], iframe[title*="reCAPTCHA"], iframe[title*="hCaptcha"]'
      )
    );
    var hasResponseBox = !!(
      el.querySelector &&
      el.querySelector('textarea.g-recaptcha-response, textarea[name="g-recaptcha-response"], textarea[name="h-captcha-response"], [name="cf-turnstile-response"]')
    );
    // Gravity Forms: already bound (see .ginput_recaptcha[data-widget-id] + gform-initialized).
    if (el.getAttribute('data-widget-id') != null && String(el.getAttribute('data-widget-id')) !== '') {
      if (hasLiveFrame || hasResponseBox) {
        return false;
      }
      // Stale widget-id with no live frame — clear so GF can bind again (new tab / bfcache).
      try {
        el.removeAttribute('data-widget-id');
      } catch (eWid) { /* ignore */ }
      if (el.classList) {
        el.classList.remove('gform-initialized');
      }
      return true;
    }
    if (el.classList && el.classList.contains('gform-initialized') && (hasLiveFrame || hasResponseBox)) {
      return false;
    }
    if (hasLiveFrame) {
      return false;
    }
    if (el.querySelector && el.querySelector('iframe') && hasResponseBox) {
      return false;
    }
    // Invisible widgets may have no iframe yet — still need an API render pass.
    return true;
  }

  /** True when a captcha host on the page still looks unbound / empty. */
  function captchaSurfacesNeedHelp() {
    var nodes = document.querySelectorAll(
      '.g-recaptcha, .h-captcha, .cf-turnstile, .ginput_recaptcha, .gform_recaptcha'
    );
    if (!nodes.length) {
      return false;
    }
    for (var i = 0; i < nodes.length; i++) {
      if (captchaWidgetNeedsRender(nodes[i])) {
        return true;
      }
    }
    return false;
  }

  function nudgeGravityFormsCaptcha() {
    try {
      if (!window.jQuery) {
        return;
      }
      var $ = window.jQuery;
      // Clear stale GF init flags on empty captcha hosts so post_render rebinds.
      $('.ginput_recaptcha, .gform_recaptcha').each(function () {
        var el = this;
        if (captchaWidgetNeedsRender(el) && el.classList) {
          el.classList.remove('gform-initialized');
        }
      });
      $('.gform_wrapper').each(function () {
        var wrap = this;
        var formId = 0;
        try {
          var m = (wrap.id || '').match(/gform_wrapper_(\d+)/);
          if (m) {
            formId = parseInt(m[1], 10) || 0;
          }
        } catch (eId) {
          formId = 0;
        }
        try {
          $(document).trigger('gform_post_render', [formId, 1]);
        } catch (eTrig) {
          /* ignore */
        }
      });
    } catch (eGf) {
      /* ignore */
    }
  }

  var captchaReinitScheduled = false;

  function reinitCaptchaWidgets() {
    if (!hasCategoryConsent('security')) {
      return;
    }
    if (captchaReinitScheduled) {
      return;
    }
    captchaReinitScheduled = true;
    if (window.UCPFLoader && typeof window.UCPFLoader.applyConsent === 'function') {
      try {
        window.UCPFLoader.applyConsent();
      } catch (eLoad) {
        /* ignore */
      }
    }
    var attempt = 0;
    function tryInit() {
      attempt += 1;

      try {
        var gr = window.grecaptcha;
        if (gr && typeof gr.render === 'function') {
          var ready = typeof gr.ready === 'function' ? gr.ready.bind(gr) : function (fn) {
            fn();
          };
          ready(function () {
            // Generic .g-recaptcha only — GF .ginput_recaptcha is owned by gform_post_render.
            Array.prototype.forEach.call(document.querySelectorAll('.g-recaptcha'), function (el) {
              if (!captchaWidgetNeedsRender(el)) {
                return;
              }
              if (el.getAttribute('data-ucpf-captcha-rendered') === '1') {
                return;
              }
              try {
                gr.render(el);
                el.setAttribute('data-ucpf-captcha-rendered', '1');
              } catch (eRender) {
                /* already rendered or missing sitekey — leave for GF nudge */
              }
            });
          });
        }
      } catch (eGr) {
        /* ignore */
      }

      try {
        var hc = window.hcaptcha;
        if (hc && typeof hc.render === 'function') {
          Array.prototype.forEach.call(document.querySelectorAll('.h-captcha'), function (el) {
            if (!captchaWidgetNeedsRender(el)) {
              return;
            }
            if (el.getAttribute('data-ucpf-captcha-rendered') === '1') {
              return;
            }
            try {
              hc.render(el);
              el.setAttribute('data-ucpf-captcha-rendered', '1');
            } catch (eHc) {
              /* ignore */
            }
          });
        }
      } catch (eH) {
        /* ignore */
      }

      try {
        var ts = window.turnstile;
        if (ts && typeof ts.render === 'function') {
          Array.prototype.forEach.call(document.querySelectorAll('.cf-turnstile'), function (el) {
            if (!captchaWidgetNeedsRender(el)) {
              return;
            }
            if (el.getAttribute('data-ucpf-captcha-rendered') === '1') {
              return;
            }
            try {
              ts.render(el);
              el.setAttribute('data-ucpf-captcha-rendered', '1');
            } catch (eTs) {
              /* ignore */
            }
          });
        }
      } catch (eT) {
        /* ignore */
      }

      // Always nudge GF while surfaces still look empty (API present ≠ widget live).
      if (window.grecaptcha || window.hcaptcha || window.turnstile || attempt > 2) {
        nudgeGravityFormsCaptcha();
      }

      var stillNeed = captchaSurfacesNeedHelp();
      if (!stillNeed || attempt >= 24) {
        captchaReinitScheduled = false;
        return;
      }
      window.setTimeout(tryInit, 250);
    }
    tryInit();
  }

  function ensureCaptchasIfNeeded() {
    if (!hasCategoryConsent('security')) {
      return;
    }
    var nodes = document.querySelectorAll(
      '.g-recaptcha, .h-captcha, .cf-turnstile, .ginput_recaptcha, .gform_recaptcha, .gform_wrapper .gfield--type-captcha, .gfield--type-captcha, form[id^="gform_"] [data-sitekey]'
    );
    if (!nodes.length) {
      return;
    }
    if (!captchaSurfacesNeedHelp()) {
      return;
    }
    reinitCaptchaWidgets();
  }

  /**
   * After Marketing + Embeds: restore parked map iframes once and nudge Elementor.
   * Never cache-bust / reload live iframes in a loop — that spam-refreshes embeds.
   */
  var mapsHydrateStarted = false;
  var mapsHydrated = false;
  var mapsHydrateRetryScheduled = false;

  function restoreParkedMapIframe(iframe) {
    if (!iframe || iframe.tagName !== 'IFRAME') {
      return false;
    }
    if (iframe.getAttribute('data-ucpf-map-restored') === '1') {
      return false;
    }
    var parked = iframe.getAttribute('data-src') || '';
    var live = iframe.getAttribute('src') || '';
    var gated = iframe.getAttribute('data-ucpf-gated') === '1';
    var blank = !live || live === 'about:blank';
    // Only restore when gated/blank — do not touch a healthy live maps iframe.
    if (!gated && !blank) {
      if (isMapEmbedSrc(live)) {
        iframe.setAttribute('data-ucpf-map-restored', '1');
      }
      return false;
    }
    var src = parked || live;
    if (!isMapEmbedSrc(src)) {
      return false;
    }
    // Strip any leftover cache-bust from older builds.
    src = String(src).replace(/([?&])ucpf_r=\d+/g, '$1').replace(/[?&]$/, '');
    try {
      iframe.src = src;
    } catch (eSet) {
      return false;
    }
    iframe.setAttribute('data-ucpf-map-restored', '1');
    iframe.removeAttribute('data-src');
    iframe.removeAttribute('data-ucpf-gated');
    iframe.removeAttribute('data-ucpf-category');
    iframe.removeAttribute('data-ucpf-parked');
    return true;
  }

  function isMapEmbedSrc(src) {
    var u = String(src || '').toLowerCase();
    return !!(
      u &&
      u !== 'about:blank' &&
      (/maps\.google|google\.com\/maps|mapbox\.com|openstreetmap\.org|maplibre|bing\.com\/maps|virtualearth/i.test(u))
    );
  }

  function mapIframeIsLive(iframe) {
    if (!iframe || iframe.tagName !== 'IFRAME') {
      return false;
    }
    if (iframe.getAttribute('data-ucpf-gated') === '1') {
      return false;
    }
    var live = iframe.getAttribute('src') || '';
    return isMapEmbedSrc(live);
  }

  function reinitMapWidgets() {
    if (!hasCategoryConsent('marketing') || !hasCategoryConsent('functional')) {
      return;
    }
    if (mapsHydrated) {
      return;
    }
    if (window.UCPFLoader && typeof window.UCPFLoader.applyConsent === 'function') {
      try {
        window.UCPFLoader.applyConsent();
      } catch (eLoad) { /* ignore */ }
    }

    queryAll(MAP_MARKERS).forEach(function (node) {
      if (node.querySelectorAll) {
        node.querySelectorAll('iframe').forEach(restoreParkedMapIframe);
      }
      if (node.tagName === 'IFRAME') {
        restoreParkedMapIframe(node);
      }
    });
    document
      .querySelectorAll(
        'iframe[data-ucpf-gated="1"][data-src*="maps.google"], iframe[data-ucpf-gated="1"][data-src*="google.com/maps"], iframe[data-ucpf-gated="1"][data-src*="google.com/maps"]'
      )
      .forEach(restoreParkedMapIframe);

    var hasEl =
      !!(
        window.elementorFrontend &&
        elementorFrontend.elementsHandler &&
        typeof elementorFrontend.elementsHandler.runReadyTrigger === 'function'
      );
    if (hasEl) {
      queryAll([
        '.elementor-widget-google_maps',
        '[data-widget_type="google_maps.default"]',
        '[data-widget_type*="google_maps"]',
      ]).forEach(function (widget) {
        try {
          widget.querySelectorAll('iframe').forEach(restoreParkedMapIframe);
          // Only re-boot Elementor when the map iframe is still missing/gated.
          var live = false;
          widget.querySelectorAll('iframe').forEach(function (iframe) {
            if (mapIframeIsLive(iframe)) {
              live = true;
            }
          });
          if (!live) {
            elementorFrontend.elementsHandler.runReadyTrigger(widget);
          }
        } catch (eRun) { /* ignore */ }
      });
    }

    try {
      if (typeof window.initMap === 'function') {
        window.initMap();
      }
    } catch (eInit) { /* ignore */ }
    try {
      if (window.WPGMZA && typeof window.WPGMZA.maps !== 'undefined' && window.jQuery) {
        window.jQuery('.wpgmza_map').each(function () {
          try {
            window.jQuery(this).trigger('init');
          } catch (eOne) { /* ignore */ }
        });
      }
    } catch (eWpg) { /* ignore */ }
    try {
      // Mapster / MapLibre / Mapbox: force-refire plugin bootstrap at most once per page.
      if (
        !window.__ucpfMapsterForceDone &&
        window.UCPFLoader &&
        typeof window.UCPFLoader.refireMapDependents === 'function'
      ) {
        window.__ucpfMapsterForceDone = true;
        window.UCPFLoader.refireMapDependents({ forceMapster: true });
      }
    } catch (eMapster) { /* ignore */ }
    try {
      if (window.jQuery) {
        window.jQuery(document).trigger('google.maps.loaded');
        window.jQuery(window).trigger('resize');
      }
    } catch (eJq) { /* ignore */ }
    try {
      window.dispatchEvent(new CustomEvent('ucpf:maps:ready'));
    } catch (eEv) { /* ignore */ }

    var anyLive = !!document.querySelector(
      'iframe[src*="google.com/maps"]:not([data-ucpf-gated="1"]), iframe[src*="maps.google"]:not([data-ucpf-gated="1"])'
    );
    var mapsterLive = mapsterMapIsLive();
    if (anyLive || mapsterLive) {
      mapsHydrated = true;
    }
  }

  /** Mapster canvas / MapLibre map finished past the initial loader. */
  function mapsterMapIsLive() {
    try {
      if (
        document.querySelector(
          '.mapster-wp-maps .maplibregl-map, .mapster-wp-maps .mapboxgl-map, .mapster-wp-maps-container .maplibregl-map, .mapster-wp-maps-container .mapboxgl-map, .mapster-map .maplibregl-map, .mapster-map .mapboxgl-map'
        )
      ) {
        return true;
      }
      var hosts = document.querySelectorAll(
        '.mapster-wp-maps, [id^="mapster-wp-maps"], .mapster-map'
      );
      for (var i = 0; i < hosts.length; i++) {
        var host = hosts[i];
        if (!host || !host.children || !host.children.length) {
          continue;
        }
        var loader = host.querySelector('.mapster-map-loader-initial, .mapster-wp-maps-loader-container .mapster-map-loader-initial');
        // Sibling loader outside the map node — check container.
        var container = host.closest ? host.closest('.mapster-wp-maps-container') : null;
        var containerLoader = container
          ? container.querySelector('.mapster-map-loader-initial')
          : null;
        var stillLoading = !!(
          (loader && loader.offsetParent !== null) ||
          (containerLoader && containerLoader.offsetParent !== null)
        );
        if (!stillLoading && host.children.length > 0) {
          // Canvas / SVG / div map chrome appeared.
          if (host.querySelector('canvas, .maplibregl-canvas, .mapboxgl-canvas, svg')) {
            return true;
          }
        }
      }
    } catch (eLive) { /* ignore */ }
    return false;
  }

  function whenMapsApiReady(cb) {
    var n = 0;
    var t = window.setInterval(function () {
      n += 1;
      var ready =
        (window.google && window.google.maps) ||
        typeof window.mapboxgl !== 'undefined' ||
        typeof window.maplibregl !== 'undefined' ||
        document.querySelector(
          'iframe[src*="google.com/maps"]:not([data-ucpf-gated="1"]), iframe[src*="maps.google"]:not([data-ucpf-gated="1"]), iframe[data-ucpf-gated="1"][data-src*="maps.google"], iframe[data-ucpf-gated="1"][data-src*="google.com/maps"]'
        );
      if (ready || n > 40) {
        window.clearInterval(t);
        cb();
      }
    }, 100);
  }

  function ensureMapsIfNeeded() {
    if (!hasCategoryConsent('marketing') || !hasCategoryConsent('functional')) {
      mapsHydrateStarted = false;
      mapsHydrated = false;
      mapsHydrateRetryScheduled = false;
      return;
    }
    if (mapsHydrated) {
      return;
    }
    if (mapsHydrateStarted) {
      return;
    }
    var hasMapSurface = !!document.querySelector(
      MAP_MARKERS.join(',') +
        ',script[data-src*="maps.googleapis"],script[src*="maps.googleapis"],script[data-src*="mapbox"],script[data-src*="mapster"],script[src*="mapster"],script[data-src*="maplibre"],script[src*="maplibre"],iframe[data-src*="google.com/maps"],iframe[data-src*="maps.google"],iframe[data-ucpf-gated="1"][data-src*="maps.google"]'
    );
    if (!hasMapSurface) {
      return;
    }
    mapsHydrateStarted = true;
    // One restore pass + a single delayed retry (idempotent via data-ucpf-map-restored).
    whenMapsApiReady(reinitMapWidgets);
    window.setTimeout(reinitMapWidgets, 600);
    // Mapster/MapLibre: one extra retry if still on the initial loader after APIs activate.
    if (!mapsHydrateRetryScheduled) {
      mapsHydrateRetryScheduled = true;
      window.setTimeout(function () {
        if (mapsHydrated) {
          return;
        }
        if (mapsterMapIsLive()) {
          mapsHydrated = true;
          return;
        }
        // Allow one more hydrate pass for canvas maps that missed the first refire.
        mapsHydrateStarted = false;
        whenMapsApiReady(reinitMapWidgets);
        window.setTimeout(reinitMapWidgets, 400);
      }, 1800);
    }
  }

  /**
   * Elementor sticky / Motion FX mutate the DOM constantly (sticky spacers,
   * data-settings). Re-scanning guards mid-flight races prepareOptions and
   * surfaces as: Cannot read properties of undefined (reading 'translateY'),
   * leaving widgets stuck with .elementor-invisible (untouchable on mobile).
   */
  /**
   * Captcha / map / calendly / gated iframe injections must wake refresh even
   * when builders mutate the DOM constantly.
   * @param {MutationRecord|null} mutation
   * @param {Node|null} target
   */
  function isConsentSurfaceSignal(mutation, target) {
    function nodeIsSurface(node) {
      if (!node || node.nodeType !== 1) {
        return false;
      }
      var el = /** @type {Element} */ (node);
      var tag = String(el.tagName || '').toUpperCase();
      if (tag === 'IFRAME') {
        var src = (el.getAttribute('src') || el.getAttribute('data-src') || '').toLowerCase();
        if (
          isVideoPlayerUrl(src) ||
          el.getAttribute('data-ucpf-gated') === '1' ||
          el.getAttribute('data-ucpf-category') ||
          /recaptcha|hcaptcha|turnstile|challenges\.cloudflare|calendly|google\.com\/maps|maps\.google|mapbox|openstreetmap|getjobber|jobber|typeform|jotform|hsforms|tally\.so/.test(
            src
          )
        ) {
          return true;
        }
      }
      if (tag === 'SCRIPT') {
        if (el.getAttribute('data-ucpf-gated') === '1' || el.getAttribute('form_url') || el.getAttribute('typehub_id')) {
          return true;
        }
      }
      if (!el.classList) {
        return false;
      }
      return !!(
        el.classList.contains('g-recaptcha') ||
        el.classList.contains('cf-turnstile') ||
        el.classList.contains('h-captcha') ||
        el.classList.contains('grecaptcha-badge') ||
        el.classList.contains('calendly-inline-widget') ||
        el.classList.contains('jobber-inline-work-request') ||
        el.classList.contains('ucpf-iframe-placeholder') ||
        el.classList.contains('gm-style') ||
        el.classList.contains('mapboxgl-map') ||
        el.classList.contains('maplibregl-map') ||
        el.classList.contains('mapster-wp-maps') ||
        el.classList.contains('mapster-wp-maps-container') ||
        el.classList.contains('mapster-map')
      );
    }
    if (target && nodeIsSurface(target)) {
      return true;
    }
    if (!mutation || mutation.type !== 'childList') {
      return false;
    }
    var lists = [mutation.addedNodes, mutation.removedNodes];
    for (var li = 0; li < lists.length; li++) {
      var list = lists[li];
      if (!list || !list.length) {
        continue;
      }
      for (var ni = 0; ni < list.length; ni++) {
        if (nodeIsSurface(list[ni])) {
          return true;
        }
        try {
          var child = list[ni];
          if (child && child.querySelector && child.querySelector('iframe, .g-recaptcha, .cf-turnstile, .h-captcha, .calendly-inline-widget, .jobber-inline-work-request, script[data-ucpf-gated], script[form_url]')) {
            return true;
          }
        } catch (eQ) { /* ignore */ }
      }
    }
    return false;
  }

  /**
   * Elementor sticky / Motion FX mutate the DOM constantly (sticky spacers,
   * data-settings). Re-scanning guards mid-flight races prepareOptions and
   * surfaces as: Cannot read properties of undefined (reading 'translateY'),
   * leaving widgets stuck with .elementor-invisible (untouchable on mobile).
   */
  function isElementorBuilderNoise(mutation, target) {
    // Video / captcha / map signals must still wake the guard.
    if (isVideoIframeSignal(mutation, target)) {
      return false;
    }
    if (isConsentSurfaceSignal(mutation, target)) {
      return false;
    }
    if (!mutation) {
      return false;
    }
    if (mutation.type === 'attributes') {
      var attr = mutation.attributeName || '';
      if (attr === 'data-settings') {
        return true;
      }
      if ((attr === 'src' || attr === 'data-src') && target && target.tagName) {
        var tag = String(target.tagName).toUpperCase();
        if (tag === 'IFRAME' && isVideoPlayerUrl(target.getAttribute('src') || target.getAttribute('data-src') || '')) {
          return false;
        }
        if (tag === 'AUDIO' || tag === 'VIDEO' || tag === 'SOURCE') {
          return true;
        }
      }
      // Ignore transform/style thrash from Motion FX / sticky when leaveBuildersAlone.
      if (leaveBuildersAlone() && (attr === 'style' || attr === 'data-motion' || attr.indexOf('data-') === 0)) {
        return isBuilderChrome(target);
      }
      return false;
    }
    if (mutation.type !== 'childList') {
      return false;
    }
    function isStickySpacer(node) {
      return !!(
        node &&
        node.nodeType === 1 &&
        node.classList &&
        node.classList.contains('elementor-sticky__spacer')
      );
    }
    function isEmptyText(node) {
      return !!(node && node.nodeType === 3 && !String(node.textContent || '').trim());
    }
    var lists2 = [mutation.addedNodes, mutation.removedNodes];
    var saw = false;
    for (var lj = 0; lj < lists2.length; lj++) {
      var list2 = lists2[lj];
      if (!list2 || !list2.length) {
        continue;
      }
      for (var nj = 0; nj < list2.length; nj++) {
        var n = list2[nj];
        if (isEmptyText(n)) {
          continue;
        }
        saw = true;
        if (!isStickySpacer(n)) {
          return false;
        }
      }
    }
    return saw;
  }

  /**
   * True only for real video player nodes — never bare .elementor-wrapper
   * (Elementor uses that class site-wide; matching it re-ran refresh on every
   * Motion FX / sticky mutation and tore down captcha covers).
   * @param {Element|Node} node
   */
  function nodeLooksLikeVideoEmbed(node) {
    if (!node || node.nodeType !== 1) {
      return false;
    }
    var el = /** @type {Element} */ (node);
    if (String(el.tagName || '').toUpperCase() === 'IFRAME') {
      return isVideoPlayerUrl(el.getAttribute('src') || el.getAttribute('data-src') || '');
    }
    if (el.classList) {
      if (
        el.classList.contains('elementor-widget-video') ||
        el.classList.contains('elementor-video') ||
        el.classList.contains('elementor-background-video-container') ||
        el.classList.contains('elementor-background-video-embed')
      ) {
        return true;
      }
      // Inline video shell only — not every .elementor-wrapper on the page.
      if (el.classList.contains('elementor-wrapper') && el.classList.contains('elementor-open-inline')) {
        return true;
      }
    }
    if (el.getAttribute) {
      var wt = el.getAttribute('data-widget_type') || '';
      if (wt === 'video.default' || wt === 'video') {
        return true;
      }
    }
    return false;
  }

  /**
   * Wake the guard only when a YouTube/Vimeo iframe (or video widget) actually
   * appears or changes src — not when Elementor mutates a parent wrapper.
   * @param {MutationRecord|null} mutation
   * @param {Node|null} target
   */
  function isVideoIframeSignal(mutation, target) {
    if (!mutation) {
      return !!(target && String(target.tagName || '').toUpperCase() === 'IFRAME' && nodeLooksLikeVideoEmbed(target));
    }
    if (mutation.type === 'attributes') {
      var attr = mutation.attributeName || '';
      if ((attr === 'src' || attr === 'data-src') && target && String(target.tagName || '').toUpperCase() === 'IFRAME') {
        return isVideoPlayerUrl(
          /** @type {Element} */ (target).getAttribute('src') ||
            /** @type {Element} */ (target).getAttribute('data-src') ||
            ''
        );
      }
      return false;
    }
    if (mutation.type !== 'childList') {
      return false;
    }
    var lists = [mutation.addedNodes, mutation.removedNodes];
    for (var li = 0; li < lists.length; li++) {
      var list = lists[li];
      if (!list || !list.length) {
        continue;
      }
      for (var ni = 0; ni < list.length; ni++) {
        if (nodeLooksLikeVideoEmbed(list[ni])) {
          return true;
        }
      }
    }
    return false;
  }

  /**
   * When OS "Reduce motion" is on, Elementor Pro Motion FX may crash and never
   * clear .elementor-invisible (visibility:hidden → no taps). Reveal stuck nodes.
   * Also run a delayed pass without reduced-motion: gate/consent races can leave
   * the same stuck class after Elementor should have animated.
   */
  function unhideReducedMotionElementor() {
    try {
      var preferReduce =
        window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      var nodes = document.querySelectorAll('.elementor-invisible');
      var n = nodes.length;
      if (!n) {
        return 0;
      }
      // Immediate unhide only for reduced-motion (Motion FX crash path).
      if (!preferReduce) {
        return 0;
      }
      Array.prototype.forEach.call(nodes, function (el) {
        el.classList.remove('elementor-invisible');
      });
      return n;
    } catch (eUnhide) {
      return 0;
    }
  }

  /** Late safety net: reveal Elementor nodes still invisible long after frontend init. */
  function unhideStuckElementorInvisible() {
    try {
      var nodes = document.querySelectorAll('.elementor-invisible');
      if (!nodes.length) {
        return 0;
      }
      // If Elementor never booted (script error / gate race), unhide so the page is usable
      // even when the visitor declined marketing cookies (no Accept reload / CF bypass).
      var elReady = !!(window.elementorFrontend && elementorFrontend.elementsHandler);
      if (elReady && document.readyState !== 'complete') {
        return 0;
      }
      Array.prototype.forEach.call(nodes, function (el) {
        el.classList.remove('elementor-invisible');
      });
      return nodes.length;
    } catch (eStuck) {
      return 0;
    }
  }

  var consentChangeTimer = null;
  var consentChangeRunning = false;

  function onConsentChanged() {
    // Accept/Reject hard-reload: skip heavy hydrate — it freezes the tab (Mapster/Elementor).
    if (window.__ucpfConsentReloadPending) {
      try {
        refresh();
      } catch (eRefreshOnly) { /* ignore */ }
      return;
    }
    // document + window + UCPF.on all fire the same event — coalesce to one pass.
    if (consentChangeTimer) {
      window.clearTimeout(consentChangeTimer);
    }
    consentChangeTimer = window.setTimeout(runConsentChanged, 50);
  }

  function runConsentChanged() {
    consentChangeTimer = null;
    if (consentChangeRunning || window.__ucpfConsentReloadPending) {
      return;
    }
    consentChangeRunning = true;
    try {
      // refresh() applies/removes covers and restores parked YouTube/Vimeo iframe src.
      refresh();
      if (
        hasCategoryConsent('security') ||
        hasCategoryConsent('functional') ||
        hasCategoryConsent('marketing')
      ) {
        activateLoaderFallback();
      }
      ensureCalendlyIfNeeded();
      ensureCaptchasIfNeeded();
      // Always hydrate videos after consent — builders must not block GDPR unlock.
      ensureVideosIfNeeded();
      // Maps: same class of failure as Vimeo (API parked, widget already ran).
      ensureMapsIfNeeded();
      // Layout-only Elementor Motion FX recovery stays behind leaveBuildersAlone.
      if (!leaveBuildersAlone()) {
        unhideReducedMotionElementor();
        window.setTimeout(unhideStuckElementorInvisible, 400);
      }
      // Ensure GTM4WP Vimeo/YouTube helpers re-run after player APIs activate.
      // Skip when loader already ran from the cancelled-navigation fallback.
      if (window.UCPFLoader && typeof window.UCPFLoader.applyConsent === 'function') {
        try {
          window.UCPFLoader.applyConsent();
        } catch (eApply) { /* ignore */ }
      }
      // Force-clear any leftover checkout panels (Safari cancel-navigation path).
      if (hasAllCategories(['security', 'functional'])) {
        queryAll(CHECKOUT_SELECTORS).forEach(function (node) {
          var host = node;
          if (node.tagName !== 'FORM') {
            host = (node.querySelector && (node.querySelector('form.checkout') || node.querySelector('form.woocommerce-checkout'))) || node;
          }
          removeGuard(host);
          if (host && host !== node) {
            removeGuard(node);
          }
        });
        queryAll(['.ucpf-consent-guard--active[data-ucpf-guard-kind="checkout"]']).forEach(function (wrap) {
          wrap.classList.remove('ucpf-consent-guard--active');
          var panel = wrap.querySelector('.ucpf-consent-guard__panel');
          if (panel) {
            panel.remove();
          }
          if (wrap.tagName === 'FORM') {
            unlockFields(wrap);
          }
        });
      }
    } finally {
      consentChangeRunning = false;
    }
  }

  function boot() {
    refresh();
    resyncAllGuards();
    ensureVideosIfNeeded();
    ensureMapsIfNeeded();
    ensureCaptchasIfNeeded();
    if (!leaveBuildersAlone()) {
      unhideReducedMotionElementor();
    }
    // Banner root + late builder/lazy iframes — re-scan and re-copy tokens.
    [50, 250, 800, 2000, 4000].forEach(function (ms) {
      window.setTimeout(function () {
        refresh();
        resyncAllGuards();
        ensureVideosIfNeeded();
        ensureMapsIfNeeded();
        ensureCaptchasIfNeeded();
        if (!leaveBuildersAlone()) {
          unhideReducedMotionElementor();
          if (ms >= 2000) {
            unhideStuckElementorInvisible();
          }
        }
      }, ms);
    });
    window.addEventListener('load', function () {
      ensureVideosIfNeeded();
      ensureMapsIfNeeded();
      ensureCaptchasIfNeeded();
      window.setTimeout(ensureVideosIfNeeded, 1200);
      window.setTimeout(ensureMapsIfNeeded, 1200);
      window.setTimeout(ensureCaptchasIfNeeded, 1200);
      if (leaveBuildersAlone()) {
        return;
      }
      unhideReducedMotionElementor();
      window.setTimeout(unhideReducedMotionElementor, 1200);
      window.setTimeout(unhideStuckElementorInvisible, 2500);
      window.setTimeout(unhideStuckElementorInvisible, 5000);
    });
    // Elementor may finish Motion FX after our early passes.
    try {
      document.addEventListener('elementor/frontend/init', function () {
        window.setTimeout(function () {
          refresh();
          ensureVideosIfNeeded();
          ensureMapsIfNeeded();
          ensureCaptchasIfNeeded();
        }, 150);
        window.setTimeout(ensureVideosIfNeeded, 900);
        window.setTimeout(ensureMapsIfNeeded, 900);
        window.setTimeout(ensureCaptchasIfNeeded, 900);
        if (!leaveBuildersAlone()) {
          window.setTimeout(unhideReducedMotionElementor, 100);
          window.setTimeout(unhideReducedMotionElementor, 800);
          window.setTimeout(unhideStuckElementorInvisible, 2000);
        }
      });
    } catch (eEl) { /* ignore */ }
    document.addEventListener('ucpf:consent:changed', onConsentChanged);
    document.addEventListener('ucpf:consent:accepted_all', onConsentChanged);
    document.addEventListener('ucpf:consent:rejected_all', onConsentChanged);
    window.addEventListener('ucpf:consent:changed', onConsentChanged);
    window.addEventListener('ucpf:consent:accepted_all', onConsentChanged);
    window.addEventListener('ucpf:consent:rejected_all', onConsentChanged);
    var ucpfBound = false;
    function bindUcpf() {
      if (ucpfBound) {
        return true;
      }
      if (window.UCPF && typeof window.UCPF.on === 'function') {
        window.UCPF.on('ucpf:consent:changed', onConsentChanged);
        window.UCPF.on('ucpf:consent:accepted_all', onConsentChanged);
        window.UCPF.on('ucpf:consent:rejected_all', onConsentChanged);
        ucpfBound = true;
        return true;
      }
      return false;
    }
    if (!bindUcpf()) {
      [50, 200, 800, 2000].forEach(function (ms) {
        window.setTimeout(bindUcpf, ms);
      });
    }
    // Safari bfcache: re-evaluate guards when restoring a frozen checkout page.
    window.addEventListener('pageshow', function () {
      captchaReinitScheduled = false;
      refresh();
      resyncAllGuards();
      ensureVideosIfNeeded();
      ensureMapsIfNeeded();
      ensureCaptchasIfNeeded();
      if (!leaveBuildersAlone()) {
        unhideReducedMotionElementor();
      }
    });

    if (typeof MutationObserver === 'function') {
      var timer = null;
      var obs = new MutationObserver(function (mutations) {
        var relevant = false;
        for (var i = 0; i < mutations.length; i++) {
          var m = mutations[i];
          var t = m.target;
          if (!t || t.nodeType !== 1) {
            // childList may only have addedNodes
            if (m.type === 'childList' && (m.addedNodes.length || m.removedNodes.length)) {
              if (isElementorBuilderNoise(m, t)) {
                continue;
              }
              relevant = true;
              break;
            }
            continue;
          }
          // Never react to our own UI or Elementor animation class thrash.
          if (t.id === 'ucpf-root' || (t.closest && t.closest('#ucpf-root, .ucpf-consent-guard__panel'))) {
            continue;
          }
          if (m.type === 'attributes' && m.attributeName === 'class') {
            // Ignore pure Elementor motion / sticky / animation class toggles.
            continue;
          }
          if (isElementorBuilderNoise(m, t)) {
            continue;
          }
          relevant = true;
          break;
        }
        if (!relevant) {
          return;
        }
        if (timer) {
          window.clearTimeout(timer);
        }
        // Longer debounce so Elementor entrance animations can settle.
        timer = window.setTimeout(function () {
          refresh();
          resyncAllGuards();
          ensureCalendlyIfNeeded();
          unhideReducedMotionElementor();
        }, 400);
      });
      // Do NOT watch `class` — Elementor fade/sticky/motion toggle classes constantly;
      // that used to re-scan the whole page and break Motion Effects + Turnstile.
      // data-settings is Elementor Motion FX / sticky state — filtered as noise above.
      obs.observe(document.documentElement, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['data-src', 'src', 'data-settings', 'data-url'],
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
