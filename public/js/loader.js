(function () {
  'use strict';

  var loaded = {};
  var config = window.ucpfConfig || {};

  function alreadyManaged(key) {
    // Set by PHP when Integrations tags were already enqueued for this page (returning consent).
    var list = window.ucpfManagedLoaded || [];
    return list.indexOf(key) !== -1;
  }

  function hasConsentForCategory(category) {
    return window.UCPF && window.UCPF.hasConsent(category);
  }

  function activateScript(node) {
    var src = node.getAttribute('data-src');
    var category = node.getAttribute('data-ucpf-category');
    var service = node.getAttribute('data-ucpf-service');

    if (category && !hasConsentForCategory(category)) {
      dispatchBlocked(service || category);
      return;
    }

    var script = document.createElement('script');
    Array.prototype.slice.call(node.attributes).forEach(function (attr) {
      if (attr.name === 'type' || attr.name === 'data-src') return;
      if (attr.name === 'data-ucpf-category' || attr.name === 'data-ucpf-service') return;
      script.setAttribute(attr.name, attr.value);
    });
    if (src) {
      script.src = src;
    } else {
      script.text = node.textContent;
    }
    script.type = 'text/javascript';
    if (node.parentNode) {
      node.parentNode.replaceChild(script, node);
    }
    dispatchLoaded(service || category);
  }

  function activateStylesheet(node) {
    var href = node.getAttribute('data-href');
    var category = node.getAttribute('data-ucpf-category');
    var service = node.getAttribute('data-ucpf-service');
    if (!href) return;
    if (category && !hasConsentForCategory(category)) {
      dispatchBlocked(service || category);
      return;
    }
    node.setAttribute('href', href);
    node.removeAttribute('data-href');
    node.removeAttribute('data-ucpf-deferred');
    dispatchLoaded(service || category);
  }

  function activateIframe(node) {
    var src = node.getAttribute('data-src');
    var category = node.getAttribute('data-ucpf-category');
    if (!src) return;
    if (category && !hasConsentForCategory(category)) return;
    var iframe = document.createElement('iframe');
    iframe.src = src;
    iframe.setAttribute('loading', 'lazy');
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

  function scanPlaceholders() {
    document.querySelectorAll('script[type="text/plain"][data-ucpf-category]').forEach(activateScript);
    document.querySelectorAll('link[data-ucpf-deferred][data-href], link[data-ucpf-category][data-href]').forEach(activateStylesheet);
    document.querySelectorAll('.ucpf-iframe-placeholder[data-ucpf-category]').forEach(activateIframe);
    document.querySelectorAll('iframe[data-ucpf-category][data-src]').forEach(function (node) {
      if (hasConsentForCategory(node.getAttribute('data-ucpf-category'))) {
        node.src = node.getAttribute('data-src');
        node.removeAttribute('data-src');
      }
    });
    injectManagedServices();
  }

  function dispatchLoaded(service) {
    window.dispatchEvent(new CustomEvent('ucpf:service:loaded', { detail: { service: service } }));
  }

  function dispatchBlocked(service) {
    window.dispatchEvent(new CustomEvent('ucpf:service:blocked', { detail: { service: service } }));
  }

  window.UCPFLoader = {
    applyConsent: function () {
      scanPlaceholders();
    },
    loadService: function () {
      scanPlaceholders();
    },
    unloadService: function () {
      neutralizeTracking();
    },
  };

  function neutralizeTracking() {
    if (hasConsentForCategory('analytics')) {
      return;
    }
    try {
      document.querySelectorAll('script[src]').forEach(function (node) {
        var src = node.getAttribute('src') || '';
        var low = src.toLowerCase();
        if (
          low.indexOf('googletagmanager.com/gtag') !== -1 ||
          low.indexOf('googletagmanager.com/gtm') !== -1 ||
          low.indexOf('google-analytics.com') !== -1 ||
          low.indexOf('analytics.google.com') !== -1
        ) {
          node.setAttribute('data-src', src);
          node.setAttribute('data-ucpf-category', 'analytics');
          node.type = 'text/plain';
          node.removeAttribute('src');
        }
      });
    } catch (e) {}
    if (typeof gtag === 'function') {
      try {
        gtag('consent', 'update', {
          ad_storage: hasConsentForCategory('marketing') ? 'granted' : 'denied',
          analytics_storage: 'denied',
          ad_user_data: hasConsentForCategory('marketing') ? 'granted' : 'denied',
          ad_personalization: hasConsentForCategory('marketing') ? 'granted' : 'denied',
        });
      } catch (e2) {}
    }
  }

  window.addEventListener('ucpf:consent:changed', function () {
    scanPlaceholders();
    neutralizeTracking();
  });
  document.addEventListener('DOMContentLoaded', scanPlaceholders);
})();
