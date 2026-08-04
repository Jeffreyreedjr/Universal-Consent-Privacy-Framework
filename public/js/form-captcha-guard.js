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
    '.gform_turnstile',
    '.gf-cloudflare-turnstile',
    '.cf-turnstile-response',
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
    '[data-mapbox-map]',
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
        var nested = shell.querySelector('form');
        if (nested) {
          return nested;
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
        title: t('embedGuardMapTitle', 'Map blocked until you allow Embeds & Widgets'),
        body: t(
          'embedGuardMapBody',
          'This map needs Embeds & Widgets cookies to load tiles and scripts. Enable Embeds & Widgets to continue — nothing loads until then.'
        ),
        enable: t('embedGuardEnableFunctional', 'Enable Embeds & Widgets & continue'),
        categories: cats.length ? cats : ['functional'],
      };
    }
    if (kind === 'youtube' || (kind === 'embed' && category === 'marketing')) {
      return {
        title: t('embedGuardVideoTitle', 'Video blocked until you allow Marketing'),
        body: t(
          'embedGuardVideoBody',
          'This embedded video needs Marketing cookies. Enable Marketing to load the player.'
        ),
        enable: t('embedGuardEnableMarketing', 'Enable Marketing & continue'),
        categories: cats.length ? cats : ['marketing'],
      };
    }
    if (kind === 'vimeo') {
      return {
        title: t('embedGuardVimeoTitle', 'Video blocked until you allow Embeds & Widgets'),
        body: t(
          'embedGuardVimeoBody',
          'This embedded video needs Embeds & Widgets cookies. Enable Embeds & Widgets to load the player.'
        ),
        enable: t('embedGuardEnableFunctional', 'Enable Embeds & Widgets & continue'),
        categories: cats.length ? cats : ['functional'],
      };
    }
    if (kind === 'calendly') {
      return {
        title: t('embedGuardCalendlyTitle', 'Scheduling blocked until you allow Embeds & Widgets'),
        body: t(
          'embedGuardCalendlyBody',
          'This scheduling embed (Calendly) needs Embeds & Widgets cookies before it can load. Enable Embeds & Widgets to continue.'
        ),
        enable: t('embedGuardEnableFunctional', 'Enable Embeds & Widgets & continue'),
        categories: cats.length ? cats : ['functional'],
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

  function onEnableCategories(categories, e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    var list = Array.isArray(categories) ? categories.filter(Boolean) : [categories];
    if (!list.length) {
      return;
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
        onEnableCategories(enableCats, ev);
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
    if (isBackgroundVideoShell(node) || isBackgroundVideoOwner(node)) {
      return true;
    }
    return (
      node.classList.contains('elementor-widget-video') ||
      node.classList.contains('et_pb_video') ||
      node.classList.contains('wpb_video_widget') ||
      node.classList.contains('fl-module-video') ||
      node.classList.contains('brxe-video') ||
      node.classList.contains('wp-block-embed-youtube') ||
      node.classList.contains('wp-block-embed-vimeo') ||
      node.classList.contains('wp-block-video') ||
      node.classList.contains('wp-block-embed')
    );
  }

  function isEffectivelyHidden(el) {
    if (!el) {
      return true;
    }
    // Elementor entrance animations start as .elementor-invisible — do not treat as guard targets mid-fade.
    try {
      if (el.classList && el.classList.contains('elementor-invisible')) {
        return true;
      }
    } catch (eInv) { /* ignore */ }
    try {
      if (window.getComputedStyle) {
        var cs = window.getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden') {
          return true;
        }
      }
    } catch (e) {
      /* ignore */
    }
    return false;
  }

  function guardHost(target) {
    if (!target) {
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
    var existing = guardHost(target);
    if (existing) {
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

  function applyGuard(target, kind, category, mode, categories) {
    var cats = Array.isArray(categories) && categories.length ? categories : category ? [category] : [];
    if (!target || hasAllCategories(cats)) {
      return;
    }
    if (mode === 'embed' && isEffectivelyHidden(target)) {
      return;
    }
    var key = kind + ':' + cats.join('+');
    if (guarded.get(target) === key && target.getAttribute('data-ucpf-consent-guarded') === '1') {
      var existing = guardHost(target) || target;
      if (existing && existing.classList.contains('ucpf-consent-guard')) {
        syncThemeOnto(existing);
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
    target.removeAttribute('data-ucpf-consent-guarded');

    var wrap = guardHost(target) || (target.classList.contains('ucpf-consent-guard') ? target : null);
    if (wrap && wrap.classList.contains('ucpf-consent-guard')) {
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
    }
    guarded.set(target, 'off');
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
  function detectVideoProvider(el) {
    if (!el || el.nodeType !== 1) {
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
      if (!target) {
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

    // Catalog / blocker placeholders (maps, video, etc.).
    queryAll(['.ucpf-iframe-placeholder[data-ucpf-category]', 'iframe[data-ucpf-category][data-src]']).forEach(function (node) {
      var category = node.getAttribute('data-ucpf-category') || 'marketing';
      push(node, kindForPlaceholder(node), category, 'embed', [category]);
    });

    // Live map widgets / iframes not yet replaced.
    queryAll(MAP_MARKERS).forEach(function (node) {
      var host = node;
      if (node.tagName === 'IFRAME' && node.parentElement) {
        host = node.parentElement.classList.contains('ucpf-consent-guard')
          ? node
          : node.parentElement;
      }
      push(host, 'map', 'functional', 'embed', ['functional']);
    });

    // Calendly inline widgets / iframes (Elementor popups inject these late).
    queryAll(CALENDLY_MARKERS).forEach(function (node) {
      if (isEffectivelyHidden(node)) {
        return;
      }
      var host = calendlyHostContainer(node);
      if (host) {
        push(host, 'calendly', 'functional', 'embed', ['functional']);
      }
    });

    // YouTube / Vimeo iframes + lazy attrs still in the DOM.
    queryAll(VIDEO_MARKERS).forEach(function (node) {
      if (!isVisualEmbedSurface(node)) {
        return;
      }
      var detected = detectVideoProvider(node);
      if (!detected) {
        var src = (node.getAttribute('src') || node.getAttribute('data-src') || '').toLowerCase();
        if (src.indexOf('vimeo') !== -1) {
          detected = { kind: 'vimeo', category: 'functional' };
        } else if (src) {
          detected = { kind: 'youtube', category: 'marketing' };
        }
      }
      if (detected) {
        var host = videoHostContainer(node);
        if (host && isVisualEmbedSurface(host)) {
          push(host, detected.kind, detected.category, 'embed', [detected.category]);
        }
      }
    });

    // Builder shells (Elementor/Divi/Gutenberg/etc.) — iframe often injected later.
    queryAll(VIDEO_SHELL_SELECTORS).forEach(function (node) {
      if (isEffectivelyHidden(node)) {
        return;
      }
      var detected = detectVideoProvider(node);
      if (!detected) {
        // Gutenberg provider class without URL blob.
        var cls = (node.className || '').toLowerCase();
        if (cls.indexOf('vimeo') !== -1) {
          detected = { kind: 'vimeo', category: 'functional' };
        } else if (cls.indexOf('youtube') !== -1) {
          detected = { kind: 'youtube', category: 'marketing' };
        }
      }
      // Elementor video widget with settings is enough even if detect needs parent.
      if (!detected && (node.classList.contains('elementor-widget-video') || node.getAttribute('data-widget_type') === 'video.default')) {
        detected = detectVideoProvider(node) || { kind: 'youtube', category: 'marketing' };
        var s = parseDataSettings(node);
        if (s && String(s.video_type || '').toLowerCase() === 'vimeo') {
          detected = { kind: 'vimeo', category: 'functional' };
        }
      }
      // Container background video (empty .elementor-background-video-embed until API injects iframe).
      if (!detected && isBackgroundVideoShell(node)) {
        detected = detectVideoProvider(node);
      }
      if (detected) {
        var host = videoHostContainer(node);
        if (isBackgroundVideoShell(node) || (host && isBackgroundVideoShell(host))) {
          host = resolveBackgroundVideoHost(node);
        }
        push(host, detected.kind, detected.category, 'embed', [detected.category]);
      }
    });

    // Elementor containers that declare background_video_link — guard the e-con, not the inner video fill.
    queryAll(['[data-settings*="background_video_link"]']).forEach(function (node) {
      if (isEffectivelyHidden(node)) {
        return;
      }
      var box = node.querySelector('.elementor-background-video-container');
      if (box && isEffectivelyHidden(box)) {
        return;
      }
      var detected = detectVideoProvider(node) || (box ? detectVideoProvider(box) : null);
      if (detected) {
        push(resolveBackgroundVideoHost(node), detected.kind, detected.category, 'embed', [detected.category]);
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
   * Elementor sticky / Motion FX mutate the DOM constantly (sticky spacers,
   * data-settings). Re-scanning guards mid-flight races prepareOptions and
   * surfaces as: Cannot read properties of undefined (reading 'translateY'),
   * leaving widgets stuck with .elementor-invisible (untouchable on mobile).
   */
  function isElementorBuilderNoise(mutation, target) {
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
        if (tag === 'AUDIO' || tag === 'VIDEO' || tag === 'SOURCE') {
          return true;
        }
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
    var lists = [mutation.addedNodes, mutation.removedNodes];
    var saw = false;
    for (var li = 0; li < lists.length; li++) {
      var list = lists[li];
      if (!list || !list.length) {
        continue;
      }
      for (var ni = 0; ni < list.length; ni++) {
        var n = list[ni];
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
   * When OS "Reduce motion" is on, Elementor Pro Motion FX may crash and never
   * clear .elementor-invisible (visibility:hidden → no taps). Reveal stuck nodes.
   */
  function unhideReducedMotionElementor() {
    try {
      if (!window.matchMedia || !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return 0;
      }
      var nodes = document.querySelectorAll('.elementor-invisible');
      var n = nodes.length;
      if (!n) {
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

  function onConsentChanged() {
    refresh();
    if (
      hasCategoryConsent('security') ||
      hasCategoryConsent('functional') ||
      hasCategoryConsent('marketing')
    ) {
      activateLoaderFallback();
    }
    ensureCalendlyIfNeeded();
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
  }

  function boot() {
    refresh();
    resyncAllGuards();
    unhideReducedMotionElementor();
    // Banner root + late builder/lazy iframes — re-scan and re-copy tokens.
    [50, 250, 800, 2000, 4000].forEach(function (ms) {
      window.setTimeout(function () {
        refresh();
        resyncAllGuards();
        unhideReducedMotionElementor();
      }, ms);
    });
    window.addEventListener('load', function () {
      unhideReducedMotionElementor();
      window.setTimeout(unhideReducedMotionElementor, 1200);
    });
    // Elementor may finish Motion FX after our early passes.
    try {
      document.addEventListener('elementor/frontend/init', function () {
        window.setTimeout(unhideReducedMotionElementor, 100);
        window.setTimeout(unhideReducedMotionElementor, 800);
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
      refresh();
      resyncAllGuards();
      unhideReducedMotionElementor();
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
