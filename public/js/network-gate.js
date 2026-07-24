/**
 * Hard-gate Google Analytics / GTM network + dynamic script loads until analytics (or marketing) consent.
 * Runs as early as possible in <head>. Consent Mode alone still allows cookieless /g/collect — this stops it.
 */
(function () {
  'use strict';

  if (window.__ucpfNetworkGate) {
    return;
  }
  window.__ucpfNetworkGate = true;

  var COOKIE_NAME = 'ucpf_consent';

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
    if (window.__ucpfPrivacy && window.__ucpfPrivacy[category] === false) {
      return false;
    }
    if (window.UCPF && typeof window.UCPF.hasConsent === 'function') {
      return !!window.UCPF.hasConsent(category);
    }
    var cookie = parseCookie();
    if (!cookie || !cookie.categories) {
      return false;
    }
    return !!cookie.categories[category];
  }

  function analyticsAllowed() {
    return categoryAllowed('analytics');
  }

  function marketingAllowed() {
    return categoryAllowed('marketing');
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

  function classifyUrl(url) {
    if (!url || typeof url !== 'string') {
      return null;
    }
    var u = url.toLowerCase();
    // Analytics / GTM / product analytics.
    if (
      u.indexOf('google-analytics.com') !== -1 ||
      u.indexOf('analytics.google.com') !== -1 ||
      u.indexOf('/g/collect') !== -1 ||
      u.indexOf('googletagmanager.com/gtag') !== -1 ||
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
      u.indexOf('heapanalytics.com') !== -1
    ) {
      return 'analytics';
    }
    // Ads / marketing pixels.
    if (
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
      u.indexOf('t.co/i/adsct') !== -1
    ) {
      return 'marketing';
    }
    var extra = window.__ucpfGateExtra || {};
    if (matchExtra(u, extra.marketing)) {
      return 'marketing';
    }
    if (matchExtra(u, extra.analytics)) {
      return 'analytics';
    }
    return null;
  }

  function shouldBlockUrl(url) {
    var kind = classifyUrl(url);
    if (!kind) {
      return false;
    }
    if (kind === 'analytics') {
      return !analyticsAllowed();
    }
    if (kind === 'marketing') {
      return !marketingAllowed();
    }
    return false;
  }

  function blockScriptNode(node) {
    if (!node || node.tagName !== 'SCRIPT') {
      return false;
    }
    var src = node.getAttribute('src') || node.src || '';
    if (!src || !shouldBlockUrl(src)) {
      return false;
    }
    if (node.getAttribute('data-ucpf-gated') === '1') {
      return true;
    }
    node.setAttribute('data-src', src);
    node.setAttribute('data-ucpf-category', classifyUrl(src) || 'analytics');
    node.setAttribute('data-ucpf-gated', '1');
    node.type = 'text/plain';
    try {
      node.removeAttribute('src');
    } catch (e) {}
    try {
      node.src = '';
    } catch (e2) {}
    return true;
  }

  // --- Network hooks ---
  var nativeFetch = window.fetch;
  if (typeof nativeFetch === 'function') {
    window.fetch = function (input, init) {
      var url = typeof input === 'string' ? input : input && input.url ? input.url : '';
      if (shouldBlockUrl(url)) {
        return Promise.resolve(
          new Response('', { status: 204, statusText: 'UCPF Blocked' })
        );
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

  // Tracking pixels via Image.
  try {
    var NativeImage = window.Image;
    if (NativeImage) {
      window.Image = function (w, h) {
        var img = new NativeImage(w, h);
        var desc = Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'src') ||
          Object.getOwnPropertyDescriptor(Image.prototype, 'src');
        if (desc && desc.set) {
          Object.defineProperty(img, 'src', {
            configurable: true,
            enumerable: true,
            get: function () {
              return desc.get.call(this);
            },
            set: function (value) {
              if (shouldBlockUrl(String(value || ''))) {
                return;
              }
              desc.set.call(this, value);
            },
          });
        }
        return img;
      };
      window.Image.prototype = NativeImage.prototype;
    }
  } catch (eImg) {}

  // --- Dynamic script injection ---
  var nativeCreateElement = Document.prototype.createElement;
  Document.prototype.createElement = function (tagName, options) {
    var el = nativeCreateElement.call(this, tagName, options);
    if (String(tagName).toLowerCase() === 'script') {
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
                this.setAttribute('data-ucpf-category', classifyUrl(value) || 'analytics');
                this.setAttribute('data-ucpf-gated', '1');
                this.type = 'text/plain';
                return;
              }
              desc.set.call(this, value);
            },
          });
        }
      } catch (eSrc) {}
    }
    return el;
  };

  function wrapInsert(proto, method) {
    var native = proto[method];
    if (!native) {
      return;
    }
    proto[method] = function (node) {
      if (node && node.tagName === 'SCRIPT') {
        blockScriptNode(node);
      }
      return native.apply(this, arguments);
    };
  }

  wrapInsert(Node.prototype, 'appendChild');
  wrapInsert(Node.prototype, 'insertBefore');

  if (window.MutationObserver) {
    try {
      var mo = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          Array.prototype.forEach.call(m.addedNodes || [], function (node) {
            if (node.nodeType !== 1) {
              return;
            }
            if (node.tagName === 'SCRIPT') {
              blockScriptNode(node);
            } else if (node.querySelectorAll) {
              Array.prototype.forEach.call(node.querySelectorAll('script[src]'), blockScriptNode);
            }
          });
        });
      });
      mo.observe(document.documentElement, { childList: true, subtree: true });
    } catch (eMo) {}
  }

  // Neutralize already-present blocked scripts (e.g. printed before this gate).
  function scanExisting() {
    try {
      Array.prototype.forEach.call(document.querySelectorAll('script[src]'), blockScriptNode);
    } catch (e) {}
  }
  scanExisting();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scanExisting);
  }

  window.addEventListener('ucpf:consent:changed', function () {
    // Allow loader to promote deferred scripts after Accept All.
    if (window.UCPFLoader && typeof window.UCPFLoader.applyConsent === 'function') {
      window.UCPFLoader.applyConsent();
    }
  });
})();
