(function ($) {
  'use strict';

  function parseJsonResponse(r) {
    return r.text().then(function (text) {
      var data = null;
      try {
        data = text ? JSON.parse(text) : null;
      } catch (e) {
        var err = new Error(
          r.status === 504 || r.status === 502
            ? 'Server timed out (HTTP ' + r.status + '). Try a quick scan without browser crawl.'
            : 'Server returned non-JSON (HTTP ' + r.status + '). The site may be overloaded — wait and retry.'
        );
        err.status = r.status;
        err.body = text.slice(0, 200);
        throw err;
      }
      if (!r.ok) {
        var msg = (data && data.message) ? data.message : ('Request failed (HTTP ' + r.status + ')');
        var err2 = new Error(msg);
        err2.status = r.status;
        err2.data = data;
        throw err2;
      }
      return data;
    });
  }

  function restPost(path, body, signal) {
    var opts = {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': ucpfAdmin.nonce,
      },
      body: JSON.stringify(body || {}),
    };
    if (signal) {
      opts.signal = signal;
    }
    return fetch(ucpfAdmin.restUrl + path, opts).then(parseJsonResponse);
  }

  function restGet(path, signal) {
    var opts = {
      headers: { 'X-WP-Nonce': ucpfAdmin.nonce },
    };
    if (signal) {
      opts.signal = signal;
    }
    return fetch(ucpfAdmin.restUrl + path, opts).then(parseJsonResponse);
  }

  function setStatus(selector, message, isError) {
    var $el = $(selector);
    if (!$el.length) {
      return;
    }
    $el.prop('hidden', false).text(message);
    $el.toggleClass('is-error', !!isError);
  }

  function currentCookieNames() {
    return document.cookie
      ? document.cookie.split(';').map(function (part) {
          return part.split('=')[0].trim();
        }).filter(Boolean)
      : [];
  }

  function isAdminOnlyCookie(name) {
    return /^(wordpress_logged_in_|wordpress_sec_|wordpress_)/i.test(name)
      || /^wp-settings(-time)?-/i.test(name)
      || /^wp_lang$/i.test(name);
  }

  function filterGuestCookieNames(names) {
    return (names || []).filter(function (n) {
      return n && !isAdminOnlyCookie(n);
    });
  }

  function withDiscoverParam(url, token) {
    try {
      var u = new URL(url, window.location.origin);
      u.searchParams.set('ucpf_discover', token);
      return u.toString();
    } catch (e) {
      var sep = url.indexOf('?') >= 0 ? '&' : '?';
      return url + sep + 'ucpf_discover=' + encodeURIComponent(token);
    }
  }

  function setScanBusy(busy) {
    $('#ucpf-run-scan, #ucpf-wizard-run-scan, #ucpf-live-capture, #ucpf-wizard-live-capture, #ucpf-scan-add-url, #ucpf-deep-scan, #ucpf-import-scan-json')
      .prop('disabled', !!busy);
    $('#ucpf-stop-scan').prop('hidden', !busy).prop('disabled', !busy);
    if (!busy) {
      scanRuntime.cancelled = false;
      scanRuntime.abortController = null;
      scanRuntime.crawlAbort = null;
      scanRuntime.deepPollTimer = null;
    }
  }

  var scanRuntime = {
    cancelled: false,
    abortController: null,
    crawlAbort: null,
    deepPollTimer: null,
  };

  var scanPickerState = {
    selected: {},
    available: [],
    chips: [],
    maxCrawl: (ucpfAdmin && ucpfAdmin.maxCrawl) ? ucpfAdmin.maxCrawl : 40,
    maxServer: (ucpfAdmin && ucpfAdmin.maxServer) ? ucpfAdmin.maxServer : 40,
    homeUrl: (ucpfAdmin && ucpfAdmin.homeUrl) ? ucpfAdmin.homeUrl : '',
    depth: 'standard',
    ready: false,
  };

  function currentScanDepth() {
    var v = ($('#ucpf-scan-depth').val() || scanPickerState.depth || 'standard');
    if (v !== 'quick' && v !== 'standard' && v !== 'deep') {
      return 'standard';
    }
    return v;
  }

  function applyScanUrlPayload(payload) {
    scanPickerState.available = (payload && payload.available) ? payload.available : ((payload && payload.urls) || []);
    scanPickerState.chips = (payload && payload.chips) ? payload.chips : [];
    scanPickerState.maxCrawl = (payload && payload.max_crawl) ? payload.max_crawl : scanPickerState.maxCrawl;
    scanPickerState.maxServer = (payload && payload.max_server) ? payload.max_server : scanPickerState.maxServer;
    scanPickerState.depth = (payload && payload.depth) ? payload.depth : scanPickerState.depth;
    if (payload && payload.home_url) {
      scanPickerState.homeUrl = payload.home_url;
    }
    scanPickerState.selected = {};
    ((payload && payload.urls) || []).forEach(function (item) {
      var url = normalizeSiteUrl(item.url || item);
      if (url) {
        scanPickerState.selected[url] = item.label || url;
      }
    });
    if (!Object.keys(scanPickerState.selected).length && scanPickerState.homeUrl) {
      scanPickerState.selected[normalizeSiteUrl(scanPickerState.homeUrl)] = 'Homepage';
    }
    scanPickerState.ready = true;
    renderScanChips();
    renderScanPages();
    updateSelectionHint();
  }

  function loadScanUrls(depth) {
    depth = depth || currentScanDepth();
    scanPickerState.depth = depth;
    $('#ucpf-scanner-pages').html('<p class="description">Discovering pages…</p>');
    return restGet('scan/urls?depth=' + encodeURIComponent(depth)).then(function (payload) {
      applyScanUrlPayload(payload);
      return payload;
    });
  }

  function selectedCount() {
    return Object.keys(scanPickerState.selected).filter(function (url) {
      return !!scanPickerState.selected[url];
    }).length;
  }

  function updateSelectionHint() {
    var $hint = $('#ucpf-scan-selection-hint');
    if (!$hint.length) {
      return;
    }
    var n = selectedCount();
    var max = Math.min(scanPickerState.maxCrawl || 30, scanPickerState.maxServer || 30);
    var msg = n + ' page(s) selected (max ' + max + ' will be scanned).';
    if (n > max) {
      msg += ' Extra checks beyond ' + max + ' are ignored — uncheck some pages.';
      $hint.addClass('ucpf-hint--warn');
    } else {
      $hint.removeClass('ucpf-hint--warn');
    }
    $hint.text(msg).prop('hidden', false);
  }

  function normalizeSiteUrl(raw) {
    var value = (raw || '').trim();
    if (!value) {
      return '';
    }
    if (value.charAt(0) === '/') {
      value = (scanPickerState.homeUrl || window.location.origin) + value;
    }
    try {
      var u = new URL(value, scanPickerState.homeUrl || window.location.origin);
      var homeHost = '';
      try {
        homeHost = new URL(scanPickerState.homeUrl || window.location.origin).host;
      } catch (e2) {
        homeHost = window.location.host;
      }
      if (u.host !== homeHost) {
        return '';
      }
      var path = u.pathname.replace(/\/$/, '');
      return u.origin + path;
    } catch (e) {
      return '';
    }
  }

  function selectedUrlDefs() {
    var out = [];
    var max = Math.min(scanPickerState.maxCrawl || 30, scanPickerState.maxServer || 30);
    Object.keys(scanPickerState.selected).forEach(function (url) {
      if (!scanPickerState.selected[url]) {
        return;
      }
      out.push({
        url: url,
        context: 'guest',
        label: scanPickerState.selected[url],
      });
    });
    return out.slice(0, max);
  }

  function renderScanChips() {
    var $chips = $('#ucpf-scanner-chips');
    if (!$chips.length) {
      return;
    }
    $chips.empty();
    (scanPickerState.chips || []).forEach(function (chip) {
      var url = normalizeSiteUrl(chip.url);
      if (!url) {
        return;
      }
      var $btn = $('<button type="button" class="ucpf-scanner-chip"></button>')
        .text(chip.label || chip.id || url)
        .attr('data-url', url)
        .attr('aria-pressed', scanPickerState.selected[url] ? 'true' : 'false')
        .toggleClass('is-active', !!scanPickerState.selected[url]);
      $chips.append($btn);
    });
  }

  function renderScanPages() {
    var $pages = $('#ucpf-scanner-pages');
    if (!$pages.length) {
      return;
    }
    $pages.empty();
    var list = scanPickerState.available || [];
    if (!list.length) {
      $pages.append($('<p class="description"></p>').text('No published pages found.'));
      return;
    }
    list.forEach(function (item) {
      var url = normalizeSiteUrl(item.url);
      if (!url) {
        return;
      }
      var id = 'ucpf-scan-page-' + url.replace(/[^a-z0-9]+/gi, '-');
      var $label = $('<label></label>');
      var $cb = $('<input type="checkbox" class="ucpf-scan-page-cb" />')
        .attr('id', id)
        .attr('data-url', url)
        .attr('data-label', item.label || url)
        .prop('checked', !!scanPickerState.selected[url]);
      $label.append($cb).append(document.createTextNode(' ' + (item.label || url)));
      $pages.append($label);
    });
  }

  function toggleSelectedUrl(url, label, on) {
    url = normalizeSiteUrl(url);
    if (!url) {
      return;
    }
    if (on) {
      scanPickerState.selected[url] = label || url;
    } else {
      delete scanPickerState.selected[url];
    }
    renderScanChips();
    renderScanPages();
    updateSelectionHint();
  }

  function initScanPicker() {
    if (!$('#ucpf-scanner-picker').length) {
      return;
    }
    loadScanUrls(currentScanDepth()).catch(function () {
      $('#ucpf-scanner-pages').html('<p class="description">Could not load page list.</p>');
      $('#ucpf-scanner-chips').empty();
    });
  }

  $(document).on('change', '#ucpf-scan-depth', function () {
    loadScanUrls(currentScanDepth()).catch(function () {
      setStatus('#ucpf-scan-status', 'Could not rediscover pages for this depth.', true);
    });
  });

  $('#ucpf-scan-rediscover').on('click', function () {
    loadScanUrls(currentScanDepth()).then(function (payload) {
      var n = payload && payload.count ? payload.count : 0;
      setStatus('#ucpf-scan-status', 'Discovered ' + n + ' page(s) via sitemap + homepage links + WP content.');
    }).catch(function () {
      setStatus('#ucpf-scan-status', 'Page discovery failed.', true);
    });
  });

  $(document).on('click', '.ucpf-scanner-chip', function () {
    var url = $(this).attr('data-url');
    var label = $(this).text();
    toggleSelectedUrl(url, label, !scanPickerState.selected[url]);
  });

  $(document).on('change', '.ucpf-scan-page-cb', function () {
    var url = $(this).attr('data-url');
    var label = $(this).attr('data-label') || url;
    toggleSelectedUrl(url, label, $(this).is(':checked'));
  });

  $('#ucpf-scan-add-url').on('click', function () {
    var raw = $('#ucpf-scan-custom-url').val();
    var url = normalizeSiteUrl(raw);
    if (!url) {
      alert('Enter a same-site URL or path (e.g. /contact/).');
      return;
    }
    toggleSelectedUrl(url, url, true);
    $('#ucpf-scan-custom-url').val('');
  });

  /**
   * Guest front-end crawl: same-origin iframe + discover token.
   * No sandbox/credentialless — those block GA/Site Kit cookie writes.
   * Admin auth cookies are filtered from the harvest.
   */
  function deepBrowserCrawl(urls, token, onProgress) {
    return new Promise(function (resolve, reject) {
      var list = (urls || []).map(function (item) {
        return typeof item === 'string' ? item : item.url;
      }).filter(Boolean);

      var max = Math.min(scanPickerState.maxCrawl || 40, scanPickerState.maxServer || 40);
      // Deep discovery may select more pages than the default crawl cap — allow up to maxCrawl.
      if (scanPickerState.depth === 'deep') {
        max = Math.max(max, scanPickerState.maxCrawl || 40);
      }
      list = list.slice(0, max);
      var collected = {};
      var aborted = false;

      scanRuntime.crawlAbort = function () {
        aborted = true;
      };

      if (!list.length || !token) {
        scanRuntime.crawlAbort = null;
        resolve(Object.keys(collected));
        return;
      }

      var iframe = document.createElement('iframe');
      iframe.setAttribute('title', 'UCPF guest cookie scan');
      iframe.style.cssText = 'position:fixed;width:1px;height:1px;left:-9999px;top:0;opacity:0;pointer-events:none;border:0;';
      document.body.appendChild(iframe);

      var index = 0;
      var settleMs = 7500;
      var origin = window.location.origin;

      function onMessage(event) {
        if (aborted || event.origin !== origin) {
          return;
        }
        var data = event.data;
        if (!data || data.type !== 'ucpf-scan-cookies' || !Array.isArray(data.cookies)) {
          return;
        }
        filterGuestCookieNames(data.cookies).forEach(function (n) {
          collected[n] = true;
        });
      }

      window.addEventListener('message', onMessage);

      function cleanup() {
        window.removeEventListener('message', onMessage);
        scanRuntime.crawlAbort = null;
        try {
          iframe.src = 'about:blank';
          document.body.removeChild(iframe);
        } catch (e) {}
      }

      function finish() {
        cleanup();
        if (aborted || scanRuntime.cancelled) {
          reject(new Error('Scan stopped.'));
          return;
        }
        resolve(Object.keys(collected));
      }

      function harvestFromIframe() {
        if (aborted) {
          return;
        }
        try {
          if (iframe.contentDocument && iframe.contentDocument.cookie) {
            filterGuestCookieNames(
              iframe.contentDocument.cookie.split(';').map(function (part) {
                return part.split('=')[0].trim();
              }).filter(Boolean)
            ).forEach(function (n) {
              collected[n] = true;
            });
          }
        } catch (e) {}
      }

      function step() {
        if (aborted || scanRuntime.cancelled) {
          finish();
          return;
        }
        if (index >= list.length) {
          finish();
          return;
        }
        var url = list[index];
        if (onProgress) {
          onProgress(index + 1, list.length, url);
        }

        var done = false;
        var timer;
        var pulse;

        function advance() {
          if (done) return;
          done = true;
          clearTimeout(timer);
          clearInterval(pulse);
          harvestFromIframe();
          index += 1;
          window.setTimeout(step, 600);
        }

        timer = window.setTimeout(advance, settleMs + 2000);
        pulse = window.setInterval(harvestFromIframe, 1500);

        iframe.onload = function () {
          if (aborted || scanRuntime.cancelled) {
            advance();
            return;
          }
          window.setTimeout(function () {
            harvestFromIframe();
          }, 2000);
          window.setTimeout(advance, settleMs);
        };
        iframe.onerror = function () {
          advance();
        };

        try {
          iframe.src = withDiscoverParam(url, token);
        } catch (err) {
          advance();
        }
      }

      step();
    });
  }

  function clearDiscoverToken() {
    return fetch(ucpfAdmin.restUrl + 'scan/discover-token', {
      method: 'DELETE',
      headers: { 'X-WP-Nonce': ucpfAdmin.nonce },
    }).catch(function () {});
  }

  function hardStopScan($status) {
    scanRuntime.cancelled = true;
    if (scanRuntime.abortController) {
      try {
        scanRuntime.abortController.abort();
      } catch (e) {}
    }
    if (typeof scanRuntime.crawlAbort === 'function') {
      try {
        scanRuntime.crawlAbort();
      } catch (e2) {}
    }
    if (scanRuntime.deepPollTimer) {
      window.clearTimeout(scanRuntime.deepPollTimer);
      scanRuntime.deepPollTimer = null;
    }
    setStatus($status || '#ucpf-scan-status', 'Stopping scan…');
    return restPost('scan/cancel', {}).then(function (data) {
      setScanBusy(false);
      setStatus($status || '#ucpf-scan-status', (data && data.message) ? data.message : 'Scan stopped.');
    }).catch(function () {
      setScanBusy(false);
      setStatus($status || '#ucpf-scan-status', 'Scan stopped locally. Refresh if the page still looks busy.');
    });
  }

  function runScan($status) {
    var includeAuth = $('#ucpf-scan-auth').is(':checked');
    var browserCrawl = $('#ucpf-scan-browser').is(':checked');
    scanRuntime.cancelled = false;
    scanRuntime.abortController = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var signal = scanRuntime.abortController ? scanRuntime.abortController.signal : null;
    setScanBusy(true);

    var urlsPromise = scanPickerState.ready
      ? Promise.resolve(selectedUrlDefs())
      : restGet('scan/urls', signal).then(function (urlPayload) {
          return (urlPayload && urlPayload.urls) ? urlPayload.urls : [];
        });

    setStatus($status, 'Preparing guest scan…');

    return urlsPromise.then(function (urls) {
      if (scanRuntime.cancelled) {
        throw new Error('Scan stopped.');
      }
      if (!urls.length) {
        throw new Error('Select at least one front-end page to scan.');
      }
      setStatus($status, 'Scanning ' + urls.length + ' URL(s) as guest (scripts + Set-Cookie)…');
      return restPost('scan', {
        include_auth: includeAuth,
        urls: urls,
        limit: urls.length || scanPickerState.maxServer,
      }, signal).then(function (scanData) {
        return { scanData: scanData, urls: urls };
      });
    }).then(function (payload) {
      if (scanRuntime.cancelled) {
        throw new Error('Scan stopped.');
      }
      if (!browserCrawl) {
        return { scanData: payload.scanData, cookieNames: [] };
      }
      setStatus($status, 'Creating discover token…');
      return restPost('scan/discover-token', {}, signal).then(function (tok) {
        var token = tok && tok.token ? tok.token : '';
        if (!token) {
          throw new Error('Could not create discover token.');
        }
        setStatus($status, 'Guest browser crawl…');
        return deepBrowserCrawl(payload.urls, token, function (i, total, url) {
          setStatus($status, 'Guest crawl ' + i + '/' + total + ': ' + url);
        }).then(function (cookieNames) {
          return clearDiscoverToken().then(function () {
            return { scanData: payload.scanData, cookieNames: cookieNames };
          });
        }).catch(function (err) {
          return clearDiscoverToken().then(function () {
            throw err;
          });
        });
      });
    }).then(function (payload) {
      if (scanRuntime.cancelled) {
        throw new Error('Scan stopped.');
      }
      var cookieNames = filterGuestCookieNames(payload.cookieNames || []);
      if (!cookieNames.length) {
        setStatus($status, 'Saving scan (no JS cookies harvested)…');
        window.setTimeout(function () { window.location.reload(); }, 900);
        return payload.scanData;
      }
      setStatus($status, 'Saving ' + cookieNames.length + ' guest cookie name(s)…');
      return restPost('cookies/capture', { cookies: cookieNames, context: 'guest' }, signal);
    }).then(function (data) {
      if (!data || scanRuntime.cancelled) {
        return;
      }
      var msg = 'Scan complete. Cookies: ' +
        (data && data.cookies ? data.cookies.length : 0) +
        ', unknown: ' + (data && data.unknown_cookies ? data.unknown_cookies.length : 0) +
        ', services: ' + (data && data.detected_services ? data.detected_services.length : (data && data.results ? data.results.length : 0));
      if (data && data._pages_refreshed) {
        msg += ' · Cookie Policy page refreshed.';
      }
      setStatus($status, msg);
      window.setTimeout(function () { window.location.reload(); }, 900);
    }).catch(function (err) {
      console.error(err);
      setScanBusy(false);
      clearDiscoverToken();
      if (scanRuntime.cancelled || (err && err.name === 'AbortError') || (err && /stopped/i.test(err.message || ''))) {
        setStatus($status, 'Scan stopped.');
        return;
      }
      setStatus($status, (err && err.message) ? err.message : 'Scan failed. See console for details.', true);
    });
  }

  function liveCapture($status) {
    var names = currentCookieNames();
    setScanBusy(true);
    setStatus($status, 'Capturing ' + names.length + ' cookie name(s) from this admin tab (debug)…');
    return restPost('cookies/capture', { cookies: names, context: 'admin_tab' }).then(function () {
      setStatus($status, 'Admin-tab capture saved. Reloading…');
      window.setTimeout(function () { window.location.reload(); }, 600);
    }).catch(function (err) {
      setScanBusy(false);
      setStatus($status, (err && err.message) ? err.message : 'Live capture failed.', true);
    });
  }

  initScanPicker();

  function pollDeepScan(jobId, $status, attempt) {
    attempt = attempt || 0;
    if (scanRuntime.cancelled) {
      setScanBusy(false);
      setStatus($status, 'Scan stopped.');
      return;
    }
    // ~4s × 225 ≈ 15 min — deep Playwright scans often exceed the old ~6 min cap.
    if (attempt > 225) {
      setScanBusy(false);
      setStatus(
        $status,
        'Deep scan timed out in WordPress after ~15 minutes. The scanner may still finish on the server — wait for Chromium to exit, then run Deep scan again or import JSON. Job: ' +
          jobId,
        true
      );
      return;
    }
    var signal = scanRuntime.abortController ? scanRuntime.abortController.signal : null;
    restGet('scan/deep/' + encodeURIComponent(jobId) + '?import=1', signal).then(function (job) {
      if (scanRuntime.cancelled) {
        setScanBusy(false);
        setStatus($status, 'Scan stopped.');
        return;
      }
      if (!job) {
        throw new Error('Empty scanner response');
      }
      if (job.status === 'failed') {
        throw new Error(job.error || 'Deep scan failed');
      }
      if (job.status === 'completed') {
        var inv = job.inventory || {};
        var msg = 'Deep scan imported. Cookies: ' + (inv.cookies || 0) +
          ', unclassified: ' + (inv.unknown_cookies || 0) +
          ', signals: ' + (inv.results || 0);
        if (job.import_error) {
          msg += ' (import warning: ' + job.import_error + ')';
        }
        setStatus($status, msg);
        window.setTimeout(function () { window.location.reload(); }, 1000);
        return;
      }
      setStatus($status, 'Deep scan ' + (job.status || 'running') + '… (' + (attempt + 1) + ')');
      scanRuntime.deepPollTimer = window.setTimeout(function () {
        pollDeepScan(jobId, $status, attempt + 1);
      }, 4000);
    }).catch(function (err) {
      setScanBusy(false);
      if (scanRuntime.cancelled || (err && err.name === 'AbortError')) {
        setStatus($status, 'Scan stopped.');
        return;
      }
      setStatus($status, (err && err.message) ? err.message : 'Deep scan poll failed.', true);
    });
  }

  function runDeepScan($status) {
    scanRuntime.cancelled = false;
    scanRuntime.abortController = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var signal = scanRuntime.abortController ? scanRuntime.abortController.signal : null;
    setScanBusy(true);
    setStatus($status, 'Starting deep privacy scan (Playwright)…');

    var urlsPromise = scanPickerState.ready
      ? Promise.resolve(selectedUrlDefs())
      : restGet('scan/urls', signal).then(function (urlPayload) {
          return (urlPayload && urlPayload.urls) ? urlPayload.urls : [];
        });

    return urlsPromise.then(function (urls) {
      if (scanRuntime.cancelled) {
        throw new Error('Scan stopped.');
      }
      return restPost('scan/deep', {
        url: (ucpfAdmin && ucpfAdmin.homeUrl) ? ucpfAdmin.homeUrl : window.location.origin,
        urls: urls,
        options: {
          depth: currentScanDepth(),
        },
      }, signal);
    }).then(function (job) {
      if (!job || !job.id) {
        throw new Error('Scanner did not return a job id. Check Advanced → Scanner API URL/key.');
      }
      setStatus($status, 'Deep scan queued (' + job.id + '). Waiting for Playwright…');
      pollDeepScan(job.id, $status, 0);
    }).catch(function (err) {
      console.error(err);
      setScanBusy(false);
      if (scanRuntime.cancelled || (err && err.name === 'AbortError') || (err && /stopped/i.test(err.message || ''))) {
        setStatus($status, 'Scan stopped.');
        return;
      }
      setStatus($status, (err && err.message) ? err.message : 'Deep scan failed.', true);
    });
  }

  function importScanJson($status, report) {
    if (!report || typeof report !== 'object') {
      alert('Paste a Playwright report JSON first, or choose a report file.');
      return;
    }
    if (!report.schema || String(report.schema).indexOf('ucpf-playwright-scan/') !== 0) {
      alert('This JSON is not a Playwright privacy scan report (missing schema ucpf-playwright-scan/…).');
      return;
    }
    var cookieCount = (report.cookies && report.cookies.length) ? report.cookies.length : 0;
    setScanBusy(true);
    setStatus($status, 'Importing privacy scan report (' + cookieCount + ' cookies)…');
    restPost('scan/import', report).then(function (data) {
      var known = data && data.cookies ? data.cookies.length : 0;
      var unknown = data && data.unknown_cookies ? data.unknown_cookies.length : 0;
      var services = data && data.detected_services ? data.detected_services.length : 0;
      setStatus($status, 'Import complete. Cookies: ' + known +
        ', needs category: ' + unknown +
        ', services selected: ' + services +
        '. Reloading…');
      window.setTimeout(function () { window.location.reload(); }, 1100);
    }).catch(function (err) {
      setScanBusy(false);
      setStatus($status, (err && err.message) ? err.message : 'Import failed.', true);
    });
  }

  function parseAndImportScan($status, raw) {
    if (!raw || !String(raw).trim()) {
      alert('Paste a Playwright report JSON first, or choose a report file.');
      return;
    }
    var report;
    try {
      report = JSON.parse(raw);
    } catch (e) {
      alert('Invalid JSON');
      return;
    }
    importScanJson($status, report);
  }

  $('#ucpf-deep-scan').on('click', function () {
    runDeepScan('#ucpf-scan-status');
  });

  $('#ucpf-stop-scan').on('click', function () {
    hardStopScan('#ucpf-scan-status');
  });

  $('#ucpf-import-scan-json').on('click', function () {
    parseAndImportScan('#ucpf-scan-status', $('#ucpf-import-scan-json-text').val());
  });

  $('#ucpf-import-scan-file').on('change', function () {
    var file = this.files && this.files[0];
    if (!file) {
      return;
    }
    var reader = new FileReader();
    reader.onload = function () {
      $('#ucpf-import-scan-json-text').val(String(reader.result || ''));
      parseAndImportScan('#ucpf-scan-status', reader.result);
    };
    reader.onerror = function () {
      alert('Could not read that file.');
    };
    reader.readAsText(file);
  });

  $(document).on('click', '.ucpf-save-unknown-cookie', function () {
    var $row = $(this).closest('tr');
    var name = $row.data('cookie-name');
    var category = $row.find('.ucpf-unknown-category').val();
    var treatment = $row.find('.ucpf-unknown-treatment').val() || 'consent';
    var label = $row.find('.ucpf-unknown-label').val() || '';
    var purpose = $row.find('.ucpf-unknown-purpose').val() || '';
    var visibility = $row.find('.ucpf-unknown-visibility').val() || 'show';
    if (!category) {
      alert('Select a category first. Unclassified is not allowed.');
      $row.find('.ucpf-unknown-category').focus();
      return;
    }
    var $btn = $(this).prop('disabled', true);
    restPost('cookies/review', {
      name: name,
      category: category,
      treatment: treatment,
      label: label,
      purpose: purpose,
      visibility: visibility
    }).then(function () {
      $row.fadeOut(200, function () { $(this).remove(); });
      setStatus('#ucpf-scan-status', 'Saved ' + name + ' as ' + category + '.');
      setStatus('#ucpf-cookie-review-status', 'Saved ' + name + ' as ' + category + '. Policy pages refreshed.');
    }).catch(function (err) {
      $btn.prop('disabled', false);
      alert((err && err.message) ? err.message : 'Save failed');
    });
  });

  $(document).on('click', '#ucpf-save-cookie-review', function () {
    var $root = $('#ucpf-cookie-review');
    if (!$root.length) return;

    var unknownRows = [];
    var missing = null;
    $root.find('.ucpf-unknown-table tbody tr').each(function () {
      var $row = $(this);
      var name = $row.data('cookie-name');
      var category = $row.find('.ucpf-unknown-category').val();
      var treatment = $row.find('.ucpf-unknown-treatment').val() || 'consent';
      if (!name) return;
      if (!category) {
        missing = $row.find('.ucpf-unknown-category');
        return false;
      }
      unknownRows.push({
        name: name,
        category: category,
        treatment: treatment,
        label: $row.find('.ucpf-unknown-label').val() || '',
        purpose: $row.find('.ucpf-unknown-purpose').val() || '',
        visibility: $row.find('.ucpf-unknown-visibility').val() || 'show',
        $row: $row
      });
    });
    if (missing) {
      alert('Select a category for every unclassified cookie before saving.');
      missing.focus();
      return;
    }

    var cookieOverrides = {};
    $root.find('.ucpf-known-cookie-row').each(function () {
      var $row = $(this);
      var name = $row.data('cookie-name');
      if (!name) return;
      cookieOverrides[name] = {
        label: $row.find('.ucpf-known-label').val() || '',
        purpose: $row.find('.ucpf-known-purpose').val() || '',
        category: $row.find('.ucpf-known-category').val() || '',
        treatment: $row.find('.ucpf-known-treatment').val() || 'consent',
        visibility: $row.find('.ucpf-known-visibility').val() || 'show'
      };
    });

    var overrides = {};
    $root.find('.ucpf-cookie-review__services tbody tr').each(function () {
      var $row = $(this);
      var key = $row.data('service-key');
      if (!key) return;
      overrides[key] = {
        category: $row.find('.ucpf-service-override-category').val() || '',
        treatment: $row.find('.ucpf-service-override-treatment').val() || 'consent',
        default_blocking: true
      };
    });

    var $btn = $(this).prop('disabled', true);
    setStatus('#ucpf-cookie-review-status', 'Saving cookie review…');

    var chain = Promise.resolve();
    unknownRows.forEach(function (row) {
      chain = chain.then(function () {
        return restPost('cookies/review', {
          name: row.name,
          category: row.category,
          treatment: row.treatment,
          label: row.label,
          purpose: row.purpose,
          visibility: row.visibility
        }).then(function () {
          row.$row.fadeOut(150, function () { $(this).remove(); });
        });
      });
    });

    chain
      .then(function () {
        if (!Object.keys(cookieOverrides).length) {
          return { count: 0 };
        }
        return restPost('cookies/overrides', { overrides: cookieOverrides });
      })
      .then(function () {
        if (!Object.keys(overrides).length) {
          return { count: 0 };
        }
        return restPost('services/overrides', { overrides: overrides });
      })
      .then(function (ov) {
        var msg = 'Cookie review saved';
        if (unknownRows.length) {
          msg += ' (' + unknownRows.length + ' cookie' + (unknownRows.length === 1 ? '' : 's') + ')';
        }
        if (Object.keys(cookieOverrides).length) {
          msg += '; labels/visibility updated';
        }
        if (ov && ov.count) {
          msg += '; ' + ov.count + ' service treatment' + (ov.count === 1 ? '' : 's') + ' updated';
        }
        msg += '. Policy pages refreshed.';
        setStatus('#ucpf-cookie-review-status', msg);
        setStatus('#ucpf-scan-status', msg);
        $btn.prop('disabled', false);
      })
      .catch(function (err) {
        $btn.prop('disabled', false);
        setStatus('#ucpf-cookie-review-status', (err && err.message) ? err.message : 'Save failed.', true);
      });
  });

  $('#ucpf-run-scan, #ucpf-wizard-run-scan').on('click', function () {
    runScan('#ucpf-scan-status');
  });

  $('#ucpf-live-capture, #ucpf-wizard-live-capture').on('click', function () {
    liveCapture('#ucpf-scan-status');
  });

  $('#ucpf-generate-pages, #ucpf-wizard-generate-pages').on('click', function () {
    setStatus('#ucpf-pages-status', 'Generating…');
    restPost('pages/generate', { overwrite: false }).then(function () {
      setStatus('#ucpf-pages-status', 'Pages generated.');
      window.setTimeout(function () { window.location.reload(); }, 600);
    }).catch(function (err) {
      setStatus('#ucpf-pages-status', (err && err.message) ? err.message : 'Generation failed.', true);
    });
  });

  $('#ucpf-regenerate-pages').on('click', function () {
    if (!window.confirm('Overwrite all UCPF-generated pages with fresh template content?')) {
      return;
    }
    setStatus('#ucpf-pages-status', 'Regenerating…');
    restPost('pages/generate', { overwrite: true }).then(function () {
      setStatus('#ucpf-pages-status', 'Pages regenerated.');
      window.setTimeout(function () { window.location.reload(); }, 600);
    }).catch(function (err) {
      setStatus('#ucpf-pages-status', (err && err.message) ? err.message : 'Regeneration failed.', true);
    });
  });

  $(document).on('click', '#ucpf-run-scheduled-scan', function () {
    var $btn = $(this).prop('disabled', true);
    var statusSel = $('#ucpf-scheduled-scan-status').length ? '#ucpf-scheduled-scan-status' : '#ucpf-scan-status';
    setStatus(statusSel, 'Starting scheduled Deep scan…');
    restPost('scan/scheduled', {}).then(function (data) {
      var job = data && data.job_id ? data.job_id : '';
      setStatus(statusSel, 'Job started' + (job ? ' (' + job + ')' : '') + '. Polling in the background — refresh this page in a few minutes for results. You will get an email only if review is needed.');
      $btn.prop('disabled', false);
    }).catch(function (err) {
      $btn.prop('disabled', false);
      setStatus(statusSel, (err && err.message) ? err.message : 'Could not start scheduled scan.', true);
    });
  });

  $('#ucpf-refresh-cookie-policy').on('click', function () {
    setStatus('#ucpf-pages-status', 'Refreshing Cookie Policy…');
    restPost('pages/generate', { page: 'cookie_policy' }).then(function () {
      setStatus('#ucpf-pages-status', 'Cookie Policy refreshed from latest scan.');
      window.setTimeout(function () { window.location.reload(); }, 600);
    }).catch(function (err) {
      setStatus('#ucpf-pages-status', (err && err.message) ? err.message : 'Refresh failed.', true);
    });
  });

  $('#ucpf-export-registry').on('click', function () {
    restGet('registry/export').then(function (data) {
      var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'ucpf-registry-export.json';
      a.click();
    });
  });

  $('#ucpf-export-scan').on('click', function () {
    restGet('scan/export').then(function (data) {
      var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'ucpf-scan-export.json';
      a.click();
    }).catch(function (err) {
      alert((err && err.message) ? err.message : 'Export failed');
    });
  });

  $('#ucpf-import-registry').on('click', function () {
    var raw = $('#ucpf-import-json').val();
    try {
      var data = JSON.parse(raw);
      restPost('registry/import', data).then(function () {
        alert('Imported');
        window.location.reload();
      });
    } catch (e) {
      alert('Invalid JSON');
    }
  });

  function updateBannerPreview() {
    var banner = document.getElementById('ucpf-banner-preview-el');
    var root = document.querySelector('#ucpf-banner-preview #ucpf-root');
    if (!banner) {
      return;
    }

    var layout = $('#ucpf-banner-layout').val() || 'bar';
    var theme = $('#ucpf-banner-theme').val() || 'classic';
    var layouts = ['bar', 'modal', 'corner'];

    layouts.forEach(function (name) {
      banner.classList.remove('ucpf-banner--' + name);
    });
    banner.classList.add('ucpf-banner--' + layout);
    banner.classList.add('ucpf-banner--visible');
    banner.setAttribute('data-ucpf-layout', layout);

    var overlay = banner.querySelector('.ucpf-modal__overlay');
    if (overlay) {
      if (layout === 'modal') {
        overlay.hidden = false;
        overlay.removeAttribute('hidden');
      } else {
        overlay.hidden = true;
      }
    }

    if (root) {
      root.className = root.className.replace(/ucpf-theme-\S+/g, '').trim();
      root.classList.add('ucpf-theme-' + theme);
    }
  }

  $('#ucpf-banner-layout, #ucpf-banner-theme').on('change', updateBannerPreview);
  updateBannerPreview();

  // Wrap wide admin tables so overflow scrolls inside the table, not the page.
  function wrapWideTables(root) {
    var scope = root || document;
    var tables = scope.querySelectorAll
      ? scope.querySelectorAll('.ucpf-shell__main table.widefat, .ucpf-admin table.widefat, .ucpf-wizard__panel table.widefat')
      : [];
    Array.prototype.forEach.call(tables, function (table) {
      if (!table || table.closest('.ucpf-table-scroll')) {
        return;
      }
      var wrap = document.createElement('div');
      wrap.className = 'ucpf-table-scroll';
      if (table.parentNode) {
        table.parentNode.insertBefore(wrap, table);
        wrap.appendChild(table);
      }
    });
  }
  wrapWideTables();
  if (window.MutationObserver) {
    var mo = new MutationObserver(function (mutations) {
      var needs = false;
      mutations.forEach(function (m) {
        if (m.addedNodes && m.addedNodes.length) {
          needs = true;
        }
      });
      if (needs) {
        wrapWideTables();
      }
    });
    var main = document.querySelector('.ucpf-shell__main') || document.querySelector('.ucpf-admin');
    if (main) {
      mo.observe(main, { childList: true, subtree: true });
    }
  }
})(jQuery);
