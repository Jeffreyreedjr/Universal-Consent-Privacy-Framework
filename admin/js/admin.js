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
            ? 'Server timed out or gateway error (HTTP ' + r.status + '). The scanner may be down or overloaded.'
            : 'Server returned non-JSON (HTTP ' + r.status + '). The site may be overloaded — wait and retry.'
        );
        err.status = r.status;
        err.body = text.slice(0, 200);
        throw err;
      }
      if (!r.ok) {
        var msg = (data && (data.message || data.error)) ? (data.message || data.error) : ('Request failed (HTTP ' + r.status + ')');
        if (data && data.hint) {
          msg += ' — ' + data.hint;
        }
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

  /**
   * Combine user cancel + a hard timeout so polls cannot hang forever.
   */
  function withTimeoutSignal(parentSignal, ms) {
    if (typeof AbortController === 'undefined') {
      return { signal: parentSignal || null, clear: function () {} };
    }
    var ctrl = new AbortController();
    var timer = window.setTimeout(function () {
      try {
        ctrl.abort();
      } catch (e) { /* ignore */ }
    }, ms);
    function onParentAbort() {
      try {
        ctrl.abort();
      } catch (e2) { /* ignore */ }
    }
    if (parentSignal) {
      if (parentSignal.aborted) {
        onParentAbort();
      } else {
        parentSignal.addEventListener('abort', onParentAbort, { once: true });
      }
    }
    return {
      signal: ctrl.signal,
      clear: function () {
        window.clearTimeout(timer);
        if (parentSignal) {
          parentSignal.removeEventListener('abort', onParentAbort);
        }
      },
    };
  }

  function isTransientScanError(err) {
    if (!err) {
      return false;
    }
    var s = err.status || 0;
    if (s === 502 || s === 503 || s === 504 || s === 429) {
      return true;
    }
    var msg = String(err.message || '');
    return /timed out|gateway|overloaded|did not respond|AbortError/i.test(msg) || err.name === 'AbortError';
  }

  function setStatus(selector, message, isError) {
    var $el = $(selector);
    if (!$el.length) {
      return;
    }
    $el.prop('hidden', false).text(message);
    $el.toggleClass('is-error', !!isError);
  }

  function setStatusHtml(selector, html, isError) {
    var $el = $(selector);
    if (!$el.length) {
      return;
    }
    $el.prop('hidden', false).html(html);
    $el.toggleClass('is-error', !!isError);
  }

  function showReverifyPrompt(selector, message) {
    var $wrap = $('<span></span>').text(message + ' ');
    if (ucpfAdmin && ucpfAdmin.scannerConfigured) {
      $wrap.append(
        $('<button type="button" class="button button-primary" id="ucpf-reverify-playwright"></button>')
          .text('Re-verify (fast Playwright)')
      );
    } else {
      var adv = (ucpfAdmin && ucpfAdmin.advancedSettingsUrl) ? ucpfAdmin.advancedSettingsUrl : '';
      $wrap.append(document.createTextNode('Configure the Scanner API under Advanced Settings to re-verify, or import a Playwright report. '));
      if (adv) {
        $wrap.append($('<a></a>').attr('href', adv).text('Open Advanced Settings'));
      }
    }
    setStatusHtml(selector, $wrap, false);
  }

  function enableServiceBlocking(keys) {
    var list = (keys || []).filter(Boolean);
    if (!list.length) {
      return Promise.resolve({ count: 0 });
    }
    var overrides = {};
    list.forEach(function (key) {
      var $row = $('#ucpf-service-' + key);
      var category = $row.find('.ucpf-service-override-category').val() || '';
      overrides[key] = {
        category: category,
        treatment: 'consent',
        default_blocking: true,
      };
      if ($row.length) {
        $row.find('.ucpf-service-override-treatment').val('consent');
      }
    });
    return restPost('services/overrides', { overrides: overrides });
  }

  function ensureScanProgressBox($status) {
    var $box = $('#ucpf-scan-progress');
    if ($box.length) {
      return $box;
    }
    var $anchor = $status && $status.length ? $status : $('#ucpf-scan-status');
    if (!$anchor.length) {
      return $();
    }
    $anchor.after(
      '<div id="ucpf-scan-progress" class="ucpf-scan-progress" hidden>' +
        '<div class="ucpf-scan-progress__meta">' +
          '<span id="ucpf-scan-progress-pct">0%</span>' +
          '<span id="ucpf-scan-progress-step"></span>' +
        '</div>' +
        '<div class="ucpf-scan-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="ucpf-scan-progress-bar">' +
          '<span class="ucpf-scan-progress__fill" style="width:0%"></span>' +
        '</div>' +
        '<p id="ucpf-scan-progress-msg" class="ucpf-scan-progress__msg"></p>' +
        '<pre id="ucpf-scan-progress-log" class="ucpf-scan-progress__log" hidden></pre>' +
      '</div>'
    );
    return $('#ucpf-scan-progress');
  }

  function hideScanProgress() {
    var $box = $('#ucpf-scan-progress');
    if ($box.length) {
      $box.prop('hidden', true);
    }
  }

  function showScanProgress(progress, attempt, $status) {
    var $box = ensureScanProgressBox($status);
    if (!$box.length) {
      return;
    }
    var p = progress && typeof progress === 'object' ? progress : {};
    var pct = typeof p.percent === 'number' ? Math.max(0, Math.min(100, Math.round(p.percent))) : 0;
    var stepLabel = '';
    if (p.step != null && p.total) {
      stepLabel = 'Step ' + p.step + '/' + p.total;
      if (p.session_index && p.sessions_total) {
        stepLabel += ' · session ' + p.session_index + '/' + p.sessions_total;
      }
      if (p.page_index && p.pages_total) {
        stepLabel += ' · page ' + p.page_index + '/' + p.pages_total;
      }
    } else if (p.sessions_total && p.pages_total) {
      stepLabel = p.sessions_total + ' sessions × ' + p.pages_total + ' pages (per session)';
    }
    if (attempt != null) {
      stepLabel = (stepLabel ? stepLabel + ' · ' : '') + 'poll ' + (attempt + 1);
    }

    $box.prop('hidden', false);
    $('#ucpf-scan-progress-pct').text(pct + '%');
    $('#ucpf-scan-progress-step').text(stepLabel);
    $('#ucpf-scan-progress-msg').text(p.message || 'Working…');
    var $bar = $('#ucpf-scan-progress-bar');
    $bar.attr('aria-valuenow', String(pct));
    $bar.find('.ucpf-scan-progress__fill').css('width', pct + '%');

    var $log = $('#ucpf-scan-progress-log');
    if (p.log && p.log.length) {
      $log.prop('hidden', false).text(p.log.slice(-12).join('\n'));
      if ($log[0]) {
        $log[0].scrollTop = $log[0].scrollHeight;
      }
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

  var scanRuntime = {
    cancelled: false,
    abortController: null,
    crawlAbort: null,
    deepPollTimer: null,
    deepFailStreak: 0,
    deepJobId: null,
    /** Pages WordPress sent for the current Playwright job (fail if scanner walks fewer). */
    pathsSent: 0,
    /** true when this tab owns REST polling for the deep scan */
    deepPollLeader: false,
    deepTabId: 't' + String(Date.now()) + '-' + Math.random().toString(36).slice(2, 8),
  };

  var deepScanChannel = null;
  try {
    if (typeof BroadcastChannel !== 'undefined') {
      deepScanChannel = new BroadcastChannel('ucpf-deep-scan');
    }
  } catch (eChan) {
    deepScanChannel = null;
  }

  function scannerRedeployError(accepted, sent) {
    if (sent > 1 && accepted > 0 && accepted < sent) {
      return 'Scanner kept ' + accepted + ' of ' + sent +
        ' page(s). GET /health can show a new version from package.json while an old Node process is still running. Copy tools/ucpf-scanner, restart the service (systemctl restart ucpf-scanner), then confirm /health includes features.exactPaths and version 1.5.3+. Updating the WordPress plugin zip does not update this service.';
    }
    return 'Multi-page Playwright scans need Scanner API 1.5.3+ with features.exactPaths (GET /health). Copy tools/ucpf-scanner and restart the Node process — updating the WordPress plugin zip is not enough (see docs/SCANNER-SERVER.md).';
  }

  function remoteAcceptedPages(job) {
    if (!job) {
      return 0;
    }
    if (job.paths_count != null && job.paths_count !== '') {
      var n = Number(job.paths_count);
      if (isFinite(n) && n >= 0) {
        return n;
      }
    }
    if (job.paths && job.paths.length) {
      return job.paths.length;
    }
    return 0;
  }

  function scannerJobUnderScanned(job, pathsSent) {
    if (pathsSent < 2 || !job) {
      return false;
    }
    var accepted = remoteAcceptedPages(job);
    // Scanner echoed the curated list — do not second-guess via queued progress.
    if (accepted >= pathsSent) {
      return false;
    }
    if (accepted > 0 && accepted < pathsSent) {
      return true;
    }
    var pagesTotal = job.progress && job.progress.pages_total;
    if (Number(pagesTotal) === 1) {
      return true;
    }
    var logs = (job.progress && job.progress.log) || [];
    var i;
    for (i = 0; i < logs.length; i++) {
      if (/session\(s\)\s*·\s*1 page\(s\)/.test(String(logs[i] || ''))) {
        return true;
      }
    }
    return false;
  }

  function deepScanBroadcast(msg) {
    if (!deepScanChannel || !msg) {
      return;
    }
    try {
      msg.tabId = scanRuntime.deepTabId;
      deepScanChannel.postMessage(msg);
    } catch (ePost) { /* ignore */ }
  }

  function claimDeepPollLeadership(jobId) {
    scanRuntime.deepPollLeader = true;
    scanRuntime.deepJobId = jobId || scanRuntime.deepJobId;
    deepScanBroadcast({ type: 'leader', jobId: scanRuntime.deepJobId });
  }

  function releaseDeepPollLeadership() {
    if (scanRuntime.deepPollLeader) {
      deepScanBroadcast({ type: 'leader-gone', jobId: scanRuntime.deepJobId });
    }
    scanRuntime.deepPollLeader = false;
  }

  if (deepScanChannel) {
    deepScanChannel.onmessage = function (ev) {
      var data = ev && ev.data ? ev.data : null;
      if (!data || data.tabId === scanRuntime.deepTabId) {
        return;
      }
      if (data.type === 'leader' && data.jobId) {
        // Another tab claimed leadership — stop our poller and mirror.
        if (scanRuntime.deepPollLeader && scanRuntime.deepJobId === data.jobId) {
          scanRuntime.deepPollLeader = false;
          if (scanRuntime.deepPollTimer) {
            window.clearTimeout(scanRuntime.deepPollTimer);
            scanRuntime.deepPollTimer = null;
          }
        }
        scanRuntime.deepJobId = data.jobId;
        setScanBusy(true);
        $('#ucpf-stop-scan').prop('hidden', false);
      } else if (data.type === 'progress') {
        scanRuntime.deepJobId = data.jobId || scanRuntime.deepJobId;
        if (data.progress) {
          showScanProgress(data.progress, data.attempt || 0, $('#ucpf-scan-status'));
        }
        if (data.statusText) {
          setStatus('#ucpf-scan-status', data.statusText, !!data.isError);
        }
        setScanBusy(true);
        $('#ucpf-stop-scan').prop('hidden', false);
      } else if (data.type === 'done') {
        scanRuntime.deepJobId = null;
        setScanBusy(false);
        if (data.statusText) {
          setStatus('#ucpf-scan-status', data.statusText, !!data.isError);
        }
        if (data.reload) {
          window.setTimeout(function () { window.location.reload(); }, 1000);
        } else {
          hideScanProgress();
        }
      } else if (data.type === 'stop') {
        scanRuntime.cancelled = true;
        if (scanRuntime.deepPollTimer) {
          window.clearTimeout(scanRuntime.deepPollTimer);
          scanRuntime.deepPollTimer = null;
        }
        setStatus('#ucpf-scan-status', 'Stopping scan — closing Chromium on scanner…');
      } else if (data.type === 'leader-gone' && data.jobId && !scanRuntime.deepPollLeader) {
        // Previous leader left — take over if we still have the Scanner UI.
        if ($('#ucpf-scan-progress').length || $('#ucpf-deep-scan').length) {
          claimDeepPollLeadership(data.jobId);
          pollDeepScan(data.jobId, '#ucpf-scan-status', 0);
        }
      }
    };
  }

  var scanPickerState = {
    selected: {},
    available: [],
    chips: [],
    groups: {},
    filter: '',
    maxCrawl: (ucpfAdmin && ucpfAdmin.maxCrawl) ? ucpfAdmin.maxCrawl : 80,
    maxServer: (ucpfAdmin && ucpfAdmin.maxServer) ? ucpfAdmin.maxServer : 40,
    homeUrl: (ucpfAdmin && ucpfAdmin.homeUrl) ? ucpfAdmin.homeUrl : '',
    depth: 'standard',
    presets: { quick: 10, standard: 40, deep: 80 },
    ready: false,
    remembered: false,
    rememberedUpdated: '',
    saveTimer: null,
  };

  var SCAN_GROUP_ORDER = [
    'home',
    'woocommerce',
    'products',
    'product_categories',
    'pages',
    'posts',
    'categories',
    'other',
  ];

  function currentScanDepth() {
    var v = ($('#ucpf-scan-depth').val() || scanPickerState.depth || 'standard');
    if (v !== 'quick' && v !== 'standard' && v !== 'deep') {
      return 'standard';
    }
    return v;
  }

  function defaultScanSelection(available) {
    var selected = {};
    (available || []).forEach(function (item) {
      if (!item || !item.url) {
        return;
      }
      var url = normalizeSiteUrl(item.url);
      if (!url) {
        return;
      }
      var group = item.group || '';
      var chip = item.chip || '';
      var source = item.source || '';
      if (group === 'home' || chip === 'home' || source === 'home') {
        selected[url] = item.label || 'Homepage';
      }
      if (group === 'woocommerce' || source === 'woocommerce') {
        selected[url] = item.label || url;
      }
    });
    return selected;
  }

  function applyScanUrlPayload(payload, opts) {
    opts = opts || {};
    var prevSelected = scanPickerState.selected || {};
    var hadSelection = Object.keys(prevSelected).length > 0;
    var saved = (payload && payload.saved_selection) ? payload.saved_selection : null;
    var savedUrls = (saved && saved.urls && typeof saved.urls === 'object') ? saved.urls : {};
    var savedCount = Object.keys(savedUrls).length;

    scanPickerState.available = (payload && payload.available) ? payload.available : ((payload && payload.urls) || []);
    // Client-side dedupe by normalized URL (query variants of / used to flood the list).
    (function () {
      var seen = {};
      var uniq = [];
      (scanPickerState.available || []).forEach(function (item) {
        var url = normalizeSiteUrl(item && item.url ? item.url : item);
        if (!url || seen[url]) {
          return;
        }
        seen[url] = true;
        var copy = item && typeof item === 'object' ? Object.assign({}, item) : { url: url };
        copy.url = url;
        if (!copy.group) {
          copy.group = 'other';
        }
        try {
          copy.path = new URL(url).pathname || '/';
          if (!copy.path) {
            copy.path = '/';
          }
        } catch (e) {
          copy.path = '/';
        }
        uniq.push(copy);
      });
      scanPickerState.available = uniq;
    })();
    scanPickerState.chips = (payload && payload.chips) ? payload.chips : [];
    scanPickerState.groups = (payload && payload.groups) ? payload.groups : scanPickerState.groups;
    scanPickerState.maxCrawl = (payload && payload.max_crawl) ? payload.max_crawl : scanPickerState.maxCrawl;
    scanPickerState.maxServer = (payload && payload.max_server) ? payload.max_server : scanPickerState.maxServer;
    if (payload && payload.presets) {
      scanPickerState.presets = payload.presets;
    }
    if (payload && payload.home_url) {
      scanPickerState.homeUrl = payload.home_url;
    }

    // Prefer remembered coverage on first load; otherwise honor the select / request.
    var depthFromPayload = (payload && payload.depth) ? payload.depth : '';
    var depthFromSaved = (saved && saved.depth) ? saved.depth : '';
    var depth = depthFromPayload || depthFromSaved || scanPickerState.depth || 'standard';
    if (opts.preferSaved && depthFromSaved) {
      depth = depthFromSaved;
    }
    if (depth !== 'quick' && depth !== 'standard' && depth !== 'deep') {
      depth = 'standard';
    }
    scanPickerState.depth = depth;
    if ($('#ucpf-scan-depth').length && $('#ucpf-scan-depth').val() !== depth) {
      $('#ucpf-scan-depth').val(depth);
    }

    if (saved && typeof saved.browser_crawl === 'boolean' && $('#ucpf-scan-browser').length) {
      $('#ucpf-scan-browser').prop('checked', !!saved.browser_crawl);
    }
    if (saved && typeof saved.include_auth === 'boolean' && $('#ucpf-scan-auth').length) {
      $('#ucpf-scan-auth').prop('checked', !!saved.include_auth);
    }
    if ($('#ucpf-playwright-merge-auth').length) {
      if (saved && typeof saved.merge_logged_in === 'boolean') {
        $('#ucpf-playwright-merge-auth').prop('checked', !!saved.merge_logged_in);
      } else if (saved && typeof saved.include_auth === 'boolean' && saved.include_auth) {
        // wp_login profile / helper auth remembered — default merge on for inventory completeness.
        $('#ucpf-playwright-merge-auth').prop('checked', true);
      }
    }

    if (opts.resetSelection) {
      scanPickerState.selected = defaultScanSelection(scanPickerState.available);
      scanPickerState.remembered = false;
      scanPickerState.rememberedUpdated = '';
    } else if (opts.preferSaved && savedCount) {
      var restored = {};
      Object.keys(savedUrls).forEach(function (rawUrl) {
        var url = normalizeSiteUrl(rawUrl);
        if (url) {
          restored[url] = savedUrls[rawUrl] || url;
        }
      });
      scanPickerState.selected = Object.keys(restored).length
        ? restored
        : defaultScanSelection(scanPickerState.available);
      scanPickerState.remembered = Object.keys(restored).length > 0;
      scanPickerState.rememberedUpdated = saved.updated || '';
    } else if (hadSelection) {
      // Keep prior picks that still exist; do not wipe when rediscovering.
      var next = {};
      (scanPickerState.available || []).forEach(function (item) {
        if (item && item.url && prevSelected[item.url]) {
          next[item.url] = prevSelected[item.url] || item.label || item.url;
        }
      });
      Object.keys(prevSelected).forEach(function (url) {
        if (prevSelected[url] && !next[url]) {
          next[url] = prevSelected[url];
        }
      });
      scanPickerState.selected = Object.keys(next).length ? next : defaultScanSelection(scanPickerState.available);
    } else if (savedCount) {
      var fromSaved = {};
      Object.keys(savedUrls).forEach(function (rawUrl) {
        var url = normalizeSiteUrl(rawUrl);
        if (url) {
          fromSaved[url] = savedUrls[rawUrl] || url;
        }
      });
      scanPickerState.selected = Object.keys(fromSaved).length
        ? fromSaved
        : defaultScanSelection(scanPickerState.available);
      scanPickerState.remembered = Object.keys(fromSaved).length > 0;
      scanPickerState.rememberedUpdated = saved.updated || '';
    } else {
      scanPickerState.selected = defaultScanSelection(scanPickerState.available);
      scanPickerState.remembered = false;
      scanPickerState.rememberedUpdated = '';
    }

    if (!Object.keys(scanPickerState.selected).length && scanPickerState.homeUrl) {
      scanPickerState.selected[normalizeSiteUrl(scanPickerState.homeUrl)] = 'Homepage';
    }
    scanPickerState.ready = true;
    renderScanChips();
    renderScanPages();
    updateSelectionHint();
    updateRememberedHint();
  }

  function updateRememberedHint() {
    var $el = $('#ucpf-scan-remembered');
    if (!$el.length) {
      return;
    }
    var n = selectedCount();
    if (scanPickerState.remembered && n) {
      var msg = 'Remembered selection restored (' + n + ' page' + (n === 1 ? '' : 's') + '). Changes save automatically.';
      if (scanPickerState.rememberedUpdated) {
        msg += ' Last saved: ' + String(scanPickerState.rememberedUpdated).replace('T', ' ').replace(/\.\d+Z$/, ' UTC').replace(/Z$/, ' UTC');
      }
      $el.text(msg).prop('hidden', false);
    } else if (n) {
      $el.text('Page picks are saved on this site for the next scan.').prop('hidden', false);
    } else {
      $el.prop('hidden', true).text('');
    }
  }

  function selectionPayload() {
    return {
      urls: scanPickerState.selected || {},
      depth: currentScanDepth(),
      browser_crawl: $('#ucpf-scan-browser').is(':checked'),
      include_auth: $('#ucpf-scan-auth').is(':checked'),
      merge_logged_in: $('#ucpf-playwright-merge-auth').is(':checked'),
    };
  }

  function persistScanSelection(immediate) {
    if (!$('#ucpf-scanner-picker').length || !scanPickerState.ready) {
      return Promise.resolve(null);
    }
    if (scanPickerState.saveTimer) {
      window.clearTimeout(scanPickerState.saveTimer);
      scanPickerState.saveTimer = null;
    }
    var run = function () {
      return restPost('scan/selection', selectionPayload()).then(function (data) {
        if (data && data.selection) {
          scanPickerState.remembered = Object.keys(scanPickerState.selected || {}).length > 0;
          scanPickerState.rememberedUpdated = data.selection.updated || '';
          updateRememberedHint();
        }
        return data;
      }).catch(function () {
        return null;
      });
    };
    if (immediate) {
      return run();
    }
    return new Promise(function (resolve) {
      scanPickerState.saveTimer = window.setTimeout(function () {
        scanPickerState.saveTimer = null;
        run().then(resolve);
      }, 450);
    });
  }

  function loadScanUrls(depth, opts) {
    depth = depth || currentScanDepth();
    scanPickerState.depth = depth;
    $('#ucpf-scanner-pages').html('<p class="description">Discovering pages…</p>');
    // Depth is sent only for crawl-cap metadata; catalog is always full on the server.
    return restGet('scan/urls?depth=' + encodeURIComponent(depth)).then(function (payload) {
      applyScanUrlPayload(payload, opts || {});
      return payload;
    });
  }

  function applyDepthCapsOnly() {
    var depth = currentScanDepth();
    scanPickerState.depth = depth;
    var presets = scanPickerState.presets || {};
    var suggested = presets[depth] || 40;
    // Intensity does not reload the page list — only updates the hint.
    updateSelectionHint(suggested);
  }

  function selectedCount() {
    return Object.keys(scanPickerState.selected).filter(function (url) {
      return !!scanPickerState.selected[url];
    }).length;
  }

  /**
   * Reconcile scanPickerState.selected with live checkbox DOM so a stale
   * in-memory map cannot send a 1-page list while many boxes are checked.
   */
  function syncSelectionFromDom() {
    if (!$('#ucpf-scanner-pages').length) {
      return;
    }
    var next = {};
    $('#ucpf-scanner-pages .ucpf-scan-page-cb:checked').each(function () {
      var url = normalizeSiteUrl($(this).attr('data-url') || '');
      var label = $(this).attr('data-label') || url;
      if (url) {
        next[url] = label;
      }
    });
    // Keep custom URLs that may not be in the checklist yet.
    Object.keys(scanPickerState.selected || {}).forEach(function (url) {
      if (scanPickerState.selected[url] && !next[url]) {
        var stillChecked = $('#ucpf-scanner-pages .ucpf-scan-page-cb').filter(function () {
          return normalizeSiteUrl($(this).attr('data-url') || '') === url;
        });
        if (!stillChecked.length) {
          next[url] = scanPickerState.selected[url];
        } else if (stillChecked.is(':checked')) {
          next[url] = scanPickerState.selected[url];
        }
      }
    });
    if (Object.keys(next).length) {
      scanPickerState.selected = next;
    }
  }

  function updateSelectionHint(suggested) {
    var $hint = $('#ucpf-scan-selection-hint');
    if (!$hint.length) {
      return;
    }
    var n = selectedCount();
    var max = scanPickerState.maxCrawl || 80;
    var depth = scanPickerState.depth || 'standard';
    var sessions = depth === 'quick' ? 2 : (depth === 'deep' ? 10 : 6);
    var coverage = depth === 'quick' ? 'Light' : (depth === 'deep' ? 'Thorough' : 'Standard');
    var msg = n + ' page(s) selected · ' + coverage + ' coverage · ' + sessions + ' consent sessions × those pages · up to ' + max + ' per session';
    if (suggested) {
      msg += ' · typical pick ~' + suggested;
    }
    if (n > max) {
      msg += ' — extras beyond ' + max + ' are ignored.';
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
        homeHost = new URL(scanPickerState.homeUrl || window.location.origin).hostname;
      } catch (e2) {
        homeHost = window.location.hostname;
      }
      // Compare hostname only (ignore www. mismatch when one side has it).
      var a = String(u.hostname || '').replace(/^www\./i, '').toLowerCase();
      var b = String(homeHost || '').replace(/^www\./i, '').toLowerCase();
      if (a && b && a !== b) {
        return '';
      }
      var path = u.pathname || '/';
      if (path !== '/' && path.slice(-1) === '/') {
        path = path.slice(0, -1);
      }
      // Keep a trailing slash only for homepage so path parsing stays unambiguous.
      if (path === '/' || path === '') {
        return u.origin + '/';
      }
      return u.origin + path;
    } catch (e) {
      return '';
    }
  }

  function pathFromSiteUrl(url) {
    try {
      var path = new URL(url, scanPickerState.homeUrl || window.location.origin).pathname || '/';
      if (path !== '/' && path.slice(-1) === '/') {
        path = path.slice(0, -1);
      }
      return path || '/';
    } catch (e) {
      return '/';
    }
  }

  /**
   * @param {number} [limit] Cap — Playwright uses picker max (200); browser crawl uses maxCrawl.
   */
  function selectedUrlDefs(limit) {
    var out = [];
    var max = typeof limit === 'number' && limit > 0
      ? limit
      : (scanPickerState.maxCrawl || 80);
    Object.keys(scanPickerState.selected).forEach(function (url) {
      if (!scanPickerState.selected[url]) {
        return;
      }
      out.push({
        url: url,
        path: pathFromSiteUrl(url),
        context: 'guest',
        label: scanPickerState.selected[url],
      });
    });
    return out.slice(0, max);
  }

  function reverifyHint() {
    return (ucpfAdmin && ucpfAdmin.reverifyHint && typeof ucpfAdmin.reverifyHint === 'object')
      ? ucpfAdmin.reverifyHint
      : {};
  }

  /** Light (quick) unless last FAILs need GPC/DNS sessions. */
  function reverifyDepth() {
    var hint = reverifyHint();
    var codes = hint.fail_codes || [];
    var i;
    for (i = 0; i < codes.length; i++) {
      if (codes[i] === 'still_loaded_after_gpc' || codes[i] === 'still_loaded_after_dns') {
        return 'standard';
      }
    }
    if (hint.depth === 'standard') {
      return 'standard';
    }
    return 'quick';
  }

  /**
   * Pages for re-verify: last Playwright scanned URLs (capped), else current selection.
   * Does not mutate the page picker checkboxes.
   */
  function reverifyUrlDefs() {
    var depth = reverifyDepth();
    var cap = depth === 'standard' ? 10 : 8;
    var hint = reverifyHint();
    var out = [];
    var seen = {};
    var home = normalizeSiteUrl((ucpfAdmin && ucpfAdmin.homeUrl) ? ucpfAdmin.homeUrl + '/' : window.location.origin + '/');

    function pushUrl(raw, label) {
      var url = normalizeSiteUrl(raw);
      if (!url || seen[url] || out.length >= cap) {
        return;
      }
      seen[url] = true;
      out.push({
        url: url,
        path: pathFromSiteUrl(url),
        context: 'guest',
        label: label || url,
      });
    }

    if (home) {
      pushUrl(home, 'Homepage');
    }
    (hint.page_urls || []).forEach(function (u) {
      pushUrl(u);
    });
    if (out.length < 2) {
      selectedUrlDefs().forEach(function (item) {
        if (item && item.url) {
          pushUrl(item.url, item.label);
        }
      });
    }
    if (!out.length && home) {
      pushUrl(home, 'Homepage');
    }
    return out.slice(0, cap);
  }

  function coverageLabelForDepth(depth) {
    if (depth === 'quick') {
      return 'Light';
    }
    if (depth === 'deep') {
      return 'Thorough';
    }
    return 'Standard';
  }

  function sessionsHintForDepth(depth) {
    if (depth === 'quick') {
      return 2;
    }
    if (depth === 'deep') {
      return 10;
    }
    return 6;
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

  function pageMatchesFilter(item, label, path) {
    var q = (scanPickerState.filter || '').trim().toLowerCase();
    if (!q) {
      return true;
    }
    return (
      String(label || '').toLowerCase().indexOf(q) !== -1 ||
      String(path || '').toLowerCase().indexOf(q) !== -1 ||
      String(item.url || '').toLowerCase().indexOf(q) !== -1
    );
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

    var groupLabels = scanPickerState.groups || {};
    var buckets = {};
    SCAN_GROUP_ORDER.forEach(function (g) {
      buckets[g] = [];
    });

    var seen = {};
    list.forEach(function (item) {
      var url = normalizeSiteUrl(item.url);
      if (!url || seen[url]) {
        return;
      }
      seen[url] = true;
      var label = item.label || url;
      var path = item.path || '/';
      try {
        path = new URL(url).pathname || path;
        if (!label || label === '/' || label === url) {
          label = (path === '/' || path === '') ? 'Homepage' : path;
        }
      } catch (e) { /* keep label */ }
      if (!pageMatchesFilter(item, label, path)) {
        return;
      }
      var group = item.group || 'other';
      if (!buckets[group]) {
        buckets[group] = [];
      }
      buckets[group].push({ url: url, label: label, path: path, item: item });
    });

    var any = false;
    SCAN_GROUP_ORDER.forEach(function (groupId) {
      var rows = buckets[groupId] || [];
      if (!rows.length) {
        return;
      }
      any = true;
      var title = groupLabels[groupId] || groupId;
      var selectedInGroup = 0;
      rows.forEach(function (row) {
        if (scanPickerState.selected[row.url]) {
          selectedInGroup += 1;
        }
      });
      var allSelected = selectedInGroup === rows.length && rows.length > 0;
      var $group = $('<div class="ucpf-scanner-group"></div>').attr('data-group', groupId);
      var $head = $('<div class="ucpf-scanner-group__head"></div>');
      $head.append($('<strong class="ucpf-scanner-group__title"></strong>').text(title + ' (' + selectedInGroup + ' of ' + rows.length + ' selected)'));
      var $sel = $('<button type="button" class="button-link ucpf-scanner-group__select"></button>')
        .text(allSelected ? 'Clear group' : 'Select group')
        .attr('data-group', groupId)
        .attr('data-action', allSelected ? 'clear' : 'select');
      $head.append($sel);
      $group.append($head);

      rows.forEach(function (row) {
        var id = 'ucpf-scan-page-' + row.url.replace(/[^a-z0-9]+/gi, '-');
        var $label = $('<label class="ucpf-scanner-page"></label>');
        var $cb = $('<input type="checkbox" class="ucpf-scan-page-cb" />')
          .attr('id', id)
          .attr('data-url', row.url)
          .attr('data-label', row.label)
          .attr('data-group', groupId)
          .prop('checked', !!scanPickerState.selected[row.url]);
        var meta = row.path && row.path !== row.label ? ' — ' + row.path : '';
        $label.append($cb).append(document.createTextNode(' ' + row.label + meta));
        $group.append($label);
      });
      $pages.append($group);
    });

    // Custom / leftover selected URLs not in catalog.
    var extras = [];
    Object.keys(scanPickerState.selected).forEach(function (url) {
      if (!seen[url] && scanPickerState.selected[url]) {
        extras.push(url);
      }
    });
    if (extras.length) {
      any = true;
      var $custom = $('<div class="ucpf-scanner-group"></div>').attr('data-group', 'custom');
      $custom.append($('<div class="ucpf-scanner-group__head"></div>').append(
        $('<strong class="ucpf-scanner-group__title"></strong>').text('Custom URLs (' + extras.length + ')')
      ));
      extras.forEach(function (url) {
        var label = scanPickerState.selected[url] || url;
        if (!pageMatchesFilter({ url: url }, label, pathFromSiteUrl(url))) {
          return;
        }
        var id = 'ucpf-scan-page-' + url.replace(/[^a-z0-9]+/gi, '-');
        var $label = $('<label class="ucpf-scanner-page"></label>');
        var $cb = $('<input type="checkbox" class="ucpf-scan-page-cb" />')
          .attr('id', id)
          .attr('data-url', url)
          .attr('data-label', label)
          .prop('checked', true);
        $label.append($cb).append(document.createTextNode(' ' + label));
        $custom.append($label);
      });
      $pages.append($custom);
    }

    if (!any) {
      $pages.append($('<p class="description"></p>').text('No pages match this filter.'));
    }
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
    updateRememberedHint();
    persistScanSelection(false);
  }

  function initScanPicker() {
    if (!$('#ucpf-scanner-picker').length) {
      return;
    }
    loadScanUrls(currentScanDepth(), { preferSaved: true }).catch(function () {
      $('#ucpf-scanner-pages').html('<p class="description">Could not load page list.</p>');
      $('#ucpf-scanner-chips').empty();
    });
  }

  $(document).on('change', '#ucpf-scan-depth', function () {
    applyDepthCapsOnly();
    persistScanSelection(false);
  });

  $(document).on('change', '#ucpf-scan-browser, #ucpf-scan-auth, #ucpf-playwright-merge-auth', function () {
    persistScanSelection(false);
  });

  $('#ucpf-scan-rediscover').on('click', function () {
    loadScanUrls(currentScanDepth(), { resetSelection: false }).then(function (payload) {
      var n = payload && payload.count ? payload.count : 0;
      setStatus('#ucpf-scan-status', 'Discovered ' + n + ' page(s) (full catalog). Selection preserved where possible.');
      persistScanSelection(true);
    }).catch(function () {
      setStatus('#ucpf-scan-status', 'Page discovery failed.', true);
    });
  });

  $(document).on('input', '#ucpf-scan-page-filter', function () {
    scanPickerState.filter = $(this).val() || '';
    renderScanPages();
  });

  $('#ucpf-scan-select-visible').on('click', function () {
    $('#ucpf-scanner-pages .ucpf-scan-page-cb').each(function () {
      var url = $(this).attr('data-url');
      var label = $(this).attr('data-label') || url;
      if (url) {
        scanPickerState.selected[normalizeSiteUrl(url)] = label;
      }
    });
    renderScanChips();
    renderScanPages();
    updateSelectionHint();
    updateRememberedHint();
    persistScanSelection(false);
  });

  $('#ucpf-scan-select-woo').on('click', function () {
    var chips = { shop: true, cart: true, checkout: true, myaccount: true };
    (scanPickerState.available || []).forEach(function (item) {
      if (!item) {
        return;
      }
      var group = item.group || '';
      var source = item.source || '';
      var chip = item.chip || '';
      var isCoreWoo = group === 'woocommerce' || source === 'woocommerce';
      if (!isCoreWoo) {
        return;
      }
      // Prefer core shop/cart/checkout/my-account chips; still allow unlabeled woo group rows.
      if (chip && !chips[chip]) {
        return;
      }
      var url = normalizeSiteUrl(item.url);
      if (url) {
        scanPickerState.selected[url] = item.label || url;
      }
    });
    renderScanChips();
    renderScanPages();
    updateSelectionHint();
    updateRememberedHint();
    persistScanSelection(false);
    setStatus('#ucpf-scan-status', 'WooCommerce shop/cart/checkout/My Account selected.');
  });

  $('#ucpf-scan-clear').on('click', function () {
    scanPickerState.selected = {};
    var home = normalizeSiteUrl(scanPickerState.homeUrl);
    if (home) {
      scanPickerState.selected[home] = 'Homepage';
    }
    renderScanChips();
    renderScanPages();
    updateSelectionHint();
    updateRememberedHint();
    persistScanSelection(false);
  });

  $(document).on('click', '.ucpf-scanner-group__select', function () {
    var group = $(this).attr('data-group');
    var action = $(this).attr('data-action') || 'select';
    (scanPickerState.available || []).forEach(function (item) {
      if (!item || item.group !== group) {
        return;
      }
      var url = normalizeSiteUrl(item.url);
      if (!url) {
        return;
      }
      if (action === 'clear') {
        delete scanPickerState.selected[url];
      } else {
        scanPickerState.selected[url] = item.label || url;
      }
    });
    renderScanChips();
    renderScanPages();
    updateSelectionHint();
    updateRememberedHint();
    persistScanSelection(false);
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

      var max = scanPickerState.maxCrawl || 80;
      // Allow selected pages up to the browser crawl cap (intensity no longer shrinks the list).
      if (scanPickerState.depth === 'deep') {
        max = Math.max(max, scanPickerState.maxCrawl || 80);
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
    deepScanBroadcast({ type: 'stop', jobId: scanRuntime.deepJobId });
    releaseDeepPollLeadership();
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
    setStatus($status || '#ucpf-scan-status', 'Stopping scan — closing Chromium on scanner…');
    showScanProgress({
      percent: 0,
      phase: 'cancelling',
      message: 'Stop requested — cancelling remote Playwright job…',
      log: [],
    }, 0, $($status || '#ucpf-scan-status'));

    var body = {};
    if (scanRuntime.deepJobId) {
      body.job_id = scanRuntime.deepJobId;
    }
    // Server resolves persisted job_id when the tab lost deepJobId.

    return restPost('scan/cancel', body).then(function (data) {
      setScanBusy(false);
      var msg = (data && data.message) ? data.message : 'Scan stopped.';
      if (data && data.job_id) {
        scanRuntime.deepJobId = data.job_id;
      }
      if (data && data.imported && data.inventory) {
        msg += ' Cookies: ' + (data.inventory.cookies || 0) +
          ', unclassified: ' + (data.inventory.unknown_cookies || 0) +
          ', signals: ' + (data.inventory.results || 0) + '. Reloading…';
        setStatus($status || '#ucpf-scan-status', msg);
        deepScanBroadcast({ type: 'done', statusText: msg, reload: true });
        window.setTimeout(function () { window.location.reload(); }, 1100);
        return;
      }
      if (data && data.remote_error) {
        msg += ' (' + data.remote_error + ')';
      }
      if (!body.job_id && !(data && data.job_id) && !(data && data.remote)) {
        msg = 'Scan stopped locally. No active Playwright job was stored on this site — other sites’ Chromium jobs were not cancelled.';
      }
      hideScanProgress();
      setStatus($status || '#ucpf-scan-status', msg, !!(data && data.remote_error));
      deepScanBroadcast({ type: 'done', statusText: msg, isError: !!(data && data.remote_error) });
      scanRuntime.deepJobId = null;
    }).catch(function () {
      setScanBusy(false);
      hideScanProgress();
      setStatus($status || '#ucpf-scan-status', 'Scan stopped locally. If this site’s Chromium is still running, use Stop again, or Advanced → Emergency reset (admin only — affects all sites on that scanner).', true);
      deepScanBroadcast({
        type: 'done',
        statusText: 'Scan stopped locally.',
        isError: true,
      });
      scanRuntime.deepJobId = null;
    });
  }

  function cancelRemoteAndImport($status, jobId, reason) {
    jobId = jobId || scanRuntime.deepJobId;
    if (!jobId) {
      return Promise.resolve();
    }
    setStatus($status, (reason || 'Stopping remote scan') + ' — requesting Chromium cancel…');
    return restPost('scan/cancel', { job_id: jobId }).then(function (data) {
      if (data && data.imported && data.inventory) {
        var msg = (data.message || 'Partial results imported.') +
          ' Cookies: ' + (data.inventory.cookies || 0) +
          ', unclassified: ' + (data.inventory.unknown_cookies || 0) +
          ', signals: ' + (data.inventory.results || 0) + '. Reloading…';
        setStatus($status, msg);
        window.setTimeout(function () { window.location.reload(); }, 1100);
        return data;
      }
      setStatus($status, (data && data.message) ? data.message : (reason || 'Remote scan cancelled.'), true);
      return data;
    }).catch(function (err) {
      setStatus($status, (err && err.message) ? err.message : 'Could not cancel remote scan.', true);
    });
  }

  function runScan($status) {
    var includeAuth = $('#ucpf-scan-auth').is(':checked');
    var browserCrawl = $('#ucpf-scan-browser').is(':checked');
    scanRuntime.cancelled = false;
    scanRuntime.abortController = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var signal = scanRuntime.abortController ? scanRuntime.abortController.signal : null;
    setScanBusy(true);
    persistScanSelection(true);

    var urlsPromise = scanPickerState.ready
      ? Promise.resolve(selectedUrlDefs())
      : restGet('scan/urls', signal).then(function (urlPayload) {
          return (urlPayload && urlPayload.urls) ? urlPayload.urls : [];
        });

    setStatus($status, 'Preparing WordPress helper scan…');

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

  /**
   * Reconnect to a Playwright job that kept running after leaving the Scanner page.
   * Progress/logs are persisted server-side; WP-Cron imports when the job finishes.
   */
  function resumeActiveDeepScan() {
    if (!$('#ucpf-scanner-picker').length && !$('#ucpf-deep-scan').length && !$('#ucpf-scan-progress').length) {
      return;
    }
    restGet('scan/active').then(function (payload) {
      var job = payload && payload.job ? payload.job : null;
      var active = !!(payload && payload.active && job && job.job_id);
      if (!active) {
        if (job && job.imported && job.state && (job.state === 'completed' || job.state === 'cancelled' || job.state === 'failed')) {
          // Recently finished while away — leave status hint; inventory already on page from last load.
          setStatus(
            '#ucpf-scan-status',
            (job.message || 'Last Playwright scan finished while you were away.') +
              (job.inventory
                ? ' Cookies: ' + (job.inventory.cookies || 0) +
                  ', unclassified: ' + (job.inventory.unknown_cookies || 0) + '.'
                : '')
          );
        }
        return;
      }
      scanRuntime.cancelled = false;
      scanRuntime.deepFailStreak = 0;
      scanRuntime.deepJobId = job.job_id;
      scanRuntime.pathsSent = Number(job.paths_sent || 0) || 0;
      scanRuntime.abortController = (typeof AbortController !== 'undefined') ? new AbortController() : null;
      setScanBusy(true);
      $('#ucpf-stop-scan').prop('hidden', false);
      var progress = job.progress && typeof job.progress === 'object' ? job.progress : {};
      if (!progress.message && job.message) {
        progress.message = job.message;
      }
      if (!progress.phase) {
        progress.phase = job.state || 'running';
      }
      setStatus(
        '#ucpf-scan-status',
        'Reconnected to Playwright job ' + job.job_id +
          ' (saved on this site — safe to leave again). Polling…'
      );
      showScanProgress(progress, job.poll_count || 0, $('#ucpf-scan-status'));
      claimDeepPollLeadership(job.job_id);
      pollDeepScan(job.job_id, '#ucpf-scan-status', 0);
    }).catch(function (err) {
      var msg = (err && err.message) ? err.message : 'Could not check active Playwright scan status.';
      setStatus('#ucpf-scan-status', msg + ' Open Cookie Scanner again or check Advanced → Scanner API.', true);
      $('#ucpf-scan-status').prop('hidden', false);
    });
  }

  resumeActiveDeepScan();

  $(document).on('click', '#ucpf-active-scan-notice-stop', function (e) {
    e.preventDefault();
    hardStopScan('#ucpf-scan-status');
  });

  function pollDeepScan(jobId, $status, attempt) {
    attempt = attempt || 0;
    if (!scanRuntime.deepPollLeader && attempt > 0) {
      return;
    }
    if (scanRuntime.cancelled) {
      setScanBusy(false);
      hideScanProgress();
      setStatus($status, 'Scan stopped.');
      releaseDeepPollLeadership();
      return;
    }
    // ~4s × 900 ≈ 60 min — covers Deep + queue wait on shared agency scanners.
    if (attempt > 900) {
      setScanBusy(false);
      setStatus(
        $status,
        'WordPress poll timed out after ~60 minutes. Cancelling this site’s remote job and importing whatever finished…',
        true
      );
      cancelRemoteAndImport($status, jobId, 'Poll timed out').finally(function () {
        scanRuntime.deepJobId = null;
        releaseDeepPollLeadership();
      });
      return;
    }
    var parentSignal = scanRuntime.abortController ? scanRuntime.abortController.signal : null;
    // Hard ceiling per poll so a stuck PHP/proxy cannot spin the UI forever.
    var timed = withTimeoutSignal(parentSignal, 18000);
    restGet('scan/deep/' + encodeURIComponent(jobId) + '?import=1', timed.signal).then(function (job) {
      timed.clear();
      scanRuntime.deepFailStreak = 0;
      if (scanRuntime.cancelled) {
        setScanBusy(false);
        hideScanProgress();
        setStatus($status, 'Scan stopped.');
        releaseDeepPollLeadership();
        return;
      }
      if (!job) {
        throw new Error('Empty scanner response');
      }
      var sent = Number(job.paths_sent || scanRuntime.pathsSent || 0) || 0;
      if (sent > 1) {
        scanRuntime.pathsSent = sent;
      }
      if (scannerJobUnderScanned(job, sent)) {
        restPost('scan/cancel', { job_id: jobId }).catch(function () { /* ignore */ });
        throw new Error(scannerRedeployError(remoteAcceptedPages(job), sent));
      }
      if (job.progress) {
        showScanProgress(job.progress, attempt, $status);
      }
      if (job.status === 'failed') {
        throw new Error(job.error || 'Deep scan failed');
      }
      if (job.status === 'completed' || job.status === 'cancelled') {
        var inv = job.inventory || {};
        var msg = (job.status === 'cancelled' || job.partial)
          ? 'Partial scan imported (stopped/cancelled).'
          : 'Deep scan imported.';
        if (job.logged_in_merged) {
          msg += ' Logged-in helper cookies merged.';
        }
        msg += ' Cookies: ' + (inv.cookies || 0) +
          ', unclassified: ' + (inv.unknown_cookies || 0) +
          ', signals: ' + (inv.results || 0);
        if (job.import_error) {
          msg += ' (import warning: ' + job.import_error + ')';
        }
        showScanProgress(
          Object.assign({}, job.progress || {}, {
            percent: 100,
            phase: 'done',
            message: job.status === 'cancelled' ? 'Cancelled — importing partial results…' : 'Importing results into WordPress…',
          }),
          attempt,
          $status
        );
        setScanBusy(false);
        scanRuntime.deepJobId = null;
        setStatus($status, msg);
        deepScanBroadcast({ type: 'done', statusText: msg, reload: true });
        releaseDeepPollLeadership();
        window.setTimeout(function () { window.location.reload(); }, 1000);
        return;
      }
      if (job.status === 'cancelling') {
        setStatus($status, 'Remote scan cancelling — waiting for Chromium to close…');
        showScanProgress(job.progress || { phase: 'cancelling', message: 'Cancelling…' }, attempt, $status);
        scanRuntime.deepPollTimer = window.setTimeout(function () {
          pollDeepScan(jobId, $status, attempt + 1);
        }, 2000);
        return;
      }
      if (job.status === 'queued') {
        var qPos = job.position || (job.progress && job.progress.queue_position) || 0;
        var qLen = job.queue_length || (job.progress && job.progress.queue_length) || 0;
        var qHint = job.estimated_wait_hint || '';
        var qMsg = 'Queued on shared scanner';
        if (qPos) {
          qMsg += ' — position ' + qPos + (qLen ? ' of ' + qLen : '');
        }
        if (qHint) {
          qMsg += ' (' + qHint + ')';
        }
        if (job.progress && job.progress.message) {
          qMsg += '\n' + job.progress.message;
        }
        setStatus($status, qMsg);
        showScanProgress(
          Object.assign({}, job.progress || {}, {
            phase: 'queued',
            message: qMsg,
            percent: typeof (job.progress && job.progress.percent) === 'number' ? job.progress.percent : 0,
          }),
          attempt,
          $status
        );
        deepScanBroadcast({
          type: 'progress',
          jobId: jobId,
          message: qMsg,
          percent: typeof (job.progress && job.progress.percent) === 'number' ? job.progress.percent : 0,
        });
        scanRuntime.deepPollTimer = window.setTimeout(function () {
          pollDeepScan(jobId, $status, attempt + 1);
        }, 4000);
        return;
      }
      var head = 'Deep scan ' + (job.status || 'running');
      if (job.progress && typeof job.progress.percent === 'number') {
        head += ' — ' + Math.round(job.progress.percent) + '%';
      }
      if (job.progress && job.progress.step != null && job.progress.total) {
        head += ' · ' + job.progress.step + '/' + job.progress.total;
      }
      if (job.progress && job.progress.message) {
        head += '\n' + job.progress.message;
      }
      setStatus($status, head);
      deepScanBroadcast({
        type: 'progress',
        jobId: jobId,
        message: head,
        percent: job.progress && typeof job.progress.percent === 'number' ? job.progress.percent : undefined,
      });
      scanRuntime.deepPollTimer = window.setTimeout(function () {
        pollDeepScan(jobId, $status, attempt + 1);
      }, 4000);
    }).catch(function (err) {
      timed.clear();
      if (scanRuntime.cancelled || (err && err.name === 'AbortError' && parentSignal && parentSignal.aborted)) {
        setScanBusy(false);
        hideScanProgress();
        setStatus($status, 'Scan stopped.');
        return;
      }
      // Timeout or gateway flake: retry a few times, then stop with a clear error.
      if (isTransientScanError(err) && !(parentSignal && parentSignal.aborted)) {
        scanRuntime.deepFailStreak = (scanRuntime.deepFailStreak || 0) + 1;
        if (scanRuntime.deepFailStreak <= 3) {
          setStatus(
            $status,
            'Scanner briefly unreachable (HTTP ' + (err.status || 'timeout') + '). Retrying… (' +
              scanRuntime.deepFailStreak +
              '/3) · poll ' +
              (attempt + 1) +
              ')',
            true
          );
          scanRuntime.deepPollTimer = window.setTimeout(function () {
            pollDeepScan(jobId, $status, attempt + 1);
          }, 5000);
          return;
        }
        setScanBusy(false);
        setStatus(
          $status,
          'Scan stopped: scanner kept failing (502/timeout). Check your Scanner API host health and that the scanner process is running. Job: ' +
            jobId,
          true
        );
        releaseDeepPollLeadership();
        return;
      }
      setScanBusy(false);
      setStatus($status, (err && err.message) ? err.message : 'Deep scan poll failed.', true);
      releaseDeepPollLeadership();
    });
  }

  function runDeepScan($status, opts) {
    opts = opts || {};
    var isReverify = !!opts.reverify;
    if (!ucpfAdmin || !ucpfAdmin.scannerConfigured) {
      var adv = (ucpfAdmin && ucpfAdmin.advancedSettingsUrl) ? ucpfAdmin.advancedSettingsUrl : '';
      setStatus(
        $status,
        'Playwright scan needs a Scanner API URL (Setup Wizard → Scanner API, or Advanced Settings)' +
          (adv ? ' (' + adv + ')' : '') +
          '. Or import a local CLI report JSON.',
        true
      );
      return;
    }
    scanRuntime.cancelled = false;
    scanRuntime.deepFailStreak = 0;
    scanRuntime.deepJobId = null;
    scanRuntime.pathsSent = 0;
    scanRuntime.abortController = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var signal = scanRuntime.abortController ? scanRuntime.abortController.signal : null;
    setScanBusy(true);
    var depth = isReverify ? reverifyDepth() : currentScanDepth();
    var sessionsHint = sessionsHintForDepth(depth);
    var coverageLabel = coverageLabelForDepth(depth);
    var persistPromise = (!isReverify && scanPickerState.ready)
      ? persistScanSelection(true)
      : Promise.resolve(null);

    var urlsPromise = persistPromise.then(function () {
      if (isReverify) {
        return reverifyUrlDefs();
      }
      if (scanPickerState.ready) {
        syncSelectionFromDom();
        // Playwright honors the full picker (up to 200), not the browser-crawl cap.
        return selectedUrlDefs(200);
      }
      return restGet('scan/urls?depth=' + encodeURIComponent(depth), signal).then(function (urlPayload) {
        return (urlPayload && urlPayload.urls) ? urlPayload.urls : [];
      });
    });

    return urlsPromise.then(function (urls) {
      if (scanRuntime.cancelled) {
        throw new Error('Scan stopped.');
      }
      if (!urls || !urls.length) {
        throw new Error('No pages selected. Check at least one page in the list, then start again.');
      }
      var pathPreview = [];
      var pathSeen = {};
      urls.forEach(function (u) {
        var p = (u && u.path) ? u.path : pathFromSiteUrl((u && u.url) ? u.url : u);
        if (!p) {
          return;
        }
        if (p !== '/' && p.slice(-1) === '/') {
          p = p.slice(0, -1);
        }
        if (pathSeen[p]) {
          return;
        }
        pathSeen[p] = true;
        pathPreview.push(p);
      });
      if (urls.length > 1 && pathPreview.length < 2) {
        throw new Error(
          'Selected pages collapsed to a single path (' + (pathPreview[0] || '/') +
            '). Check that each checkbox is a distinct same-site URL, then try again.'
        );
      }
      var profileHint = isReverify
        ? ('Re-verify (' + coverageLabel + '): ' + pathPreview.length + ' pages × ' + sessionsHint + ' sessions')
        : (coverageLabel + ' coverage · ' + pathPreview.length + ' page(s) · ' + sessionsHint + ' sessions · ' + pathPreview.slice(0, 8).join(', ') + (pathPreview.length > 8 ? '…' : ''));
      setStatus($status, 'Starting Playwright scan (' + profileHint + ')…');
      showScanProgress({
        percent: 0,
        step: 0,
        total: 0,
        phase: 'starting',
        message: isReverify
          ? ('Starting ' + profileHint + '. Full inventory stays on Run Playwright scan.')
          : ('Starting Playwright scan (' + profileHint + '). Each session re-walks the URL list; unfinished pages mean time budget.'),
        log: [],
        sessions_total: sessionsHint,
        pages_total: pathPreview.length,
      }, 0, $status);
      return startDeepScanJob(urls, depth, signal, 0).then(function (job) {
        return { job: job, profileHint: profileHint, pathsSent: pathPreview.length };
      });
    }).then(function (pack) {
      var job = pack && pack.job;
      var profileHint = (pack && pack.profileHint) || '';
      var pathsSent = (pack && pack.pathsSent) || 0;
      scanRuntime.pathsSent = pathsSent;
      if (!job || !job.id) {
        throw new Error('Scanner did not return a job id. Check Advanced → Scanner API URL/key.');
      }
      var pathsCount = remoteAcceptedPages(job);
      if (pathsSent > 1 && (!pathsCount || pathsCount < pathsSent)) {
        restPost('scan/cancel', { job_id: job.id }).catch(function () { /* ignore */ });
        throw new Error(scannerRedeployError(pathsCount, pathsSent));
      }
      if (pathsCount > 0) {
        profileHint = profileHint.replace(/(\d+)\s*page\(s\)/, pathsCount + ' page(s)');
      }
      scanRuntime.deepJobId = job.id;
      setStatus(
        $status,
        'Scan queued (' + job.id + ', ' + profileHint +
          (pathsSent || pathsCount
            ? (', sent ' + pathsSent + ' · scanner accepted ' + (pathsCount || pathsSent) + ' path(s)')
            : '') +
          '). Progress is saved on this WordPress site — you can leave this page; Stop still works when you return. Status also appears on other admin screens.'
      );
      showScanProgress(
        Object.assign({}, job.progress || {}, {
          message: (job.progress && job.progress.message) || ('Queued · job ' + job.id),
        }),
        0,
        $status
      );
      claimDeepPollLeadership(job.id);
      pollDeepScan(job.id, $status, 0);
    }).catch(function (err) {
      console.error(err);
      setScanBusy(false);
      if (scanRuntime.cancelled || (err && err.name === 'AbortError') || (err && /stopped/i.test(err.message || ''))) {
        hideScanProgress();
        setStatus($status, 'Scan stopped.');
        return;
      }
      // 409 — another interactive job already running: offer reconnect.
      if (err && (err.status === 409 || (err.data && err.data.code === 'ucpf_scan_already_active'))) {
        var existingId =
          (err.data && err.data.data && err.data.data.job_id) ||
          (err.data && err.data.job_id) ||
          (err.data && err.data.data && err.data.data.active && err.data.data.active.job_id);
        if (existingId) {
          scanRuntime.deepJobId = existingId;
          setScanBusy(true);
          setStatus($status, 'A Playwright scan is already running (' + existingId + '). Reconnecting…');
          resumeActiveDeepScan();
          return;
        }
      }
      setStatus($status, (err && err.message) ? err.message : 'Deep scan failed.', true);
    });
  }

  function runReverifyScan($status) {
    return runDeepScan($status, { reverify: true });
  }

  function startDeepScanJob(urls, depth, signal, retry) {
    var pathList = [];
    var pathSeen = {};
    (urls || []).forEach(function (item) {
      var p = '';
      if (item && typeof item === 'object' && item.path) {
        p = String(item.path);
      } else if (typeof item === 'string') {
        p = pathFromSiteUrl(item);
      } else if (item && item.url) {
        p = pathFromSiteUrl(item.url);
      }
      if (!p) {
        return;
      }
      if (p !== '/' && p.slice(-1) === '/') {
        p = p.slice(0, -1);
      }
      if (pathSeen[p]) {
        return;
      }
      pathSeen[p] = true;
      pathList.push(p);
    });
    if (!pathList.length) {
      pathList = ['/'];
    }
    return restPost('scan/deep', {
      url: (ucpfAdmin && ucpfAdmin.homeUrl) ? ucpfAdmin.homeUrl : window.location.origin,
      urls: urls,
      paths: pathList,
      merge_logged_in: $('#ucpf-playwright-merge-auth').is(':checked'),
      options: {
        depth: depth,
        maxPages: Math.max(pathList.length, 1),
        exactPaths: true,
        merge_logged_in: $('#ucpf-playwright-merge-auth').is(':checked'),
      },
    }, signal).catch(function (err) {
      var msg = (err && err.message) ? String(err.message) : '';
      var status = err && err.status;
      // Queue full / busy — backoff and retry once. Never cancel_all (kills other sites).
      if (retry < 1 && (status === 429 || status === 503 || /queue is full|per-key|concurrent|rate limit|busy/i.test(msg))) {
        var waitMs = status === 503 ? 8000 : 4000;
        setStatus(
          '#ucpf-scan-status',
          'Scanner busy or queue full — waiting ' + Math.round(waitMs / 1000) + 's then retrying (other sites’ jobs are left alone)…',
          true
        );
        return new Promise(function (resolve) {
          window.setTimeout(resolve, waitMs);
        }).then(function () {
          return startDeepScanJob(urls, depth, signal, retry + 1);
        });
      }
      if (status === 503 || /queue is full/i.test(msg)) {
        throw new Error(
          'Scanner queue is full. Wait a few minutes and try again, or ask your host to raise UCPF_SCANNER_MAX_QUEUE / add another scanner node. Do not use cancel-all on a shared scanner.'
        );
      }
      if (status === 429 || /concurrent|per-key|busy/i.test(msg)) {
        throw new Error(
          'Scanner is busy (your key may already have a job running/queued). Wait for it to finish, press Stop on your own job if stuck, then try again.'
        );
      }
      if (/rate limit/i.test(msg)) {
        throw new Error('Scanner rate limit hit. Wait ~30s and try again (polls no longer count against the limit after you update the scanner).');
      }
      throw err;
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
        showReverifyPrompt('#ucpf-cookie-review-status', msg);
        showReverifyPrompt('#ucpf-scan-status', msg);
        $btn.prop('disabled', false);
      })
      .catch(function (err) {
        $btn.prop('disabled', false);
        setStatus('#ucpf-cookie-review-status', (err && err.message) ? err.message : 'Save failed.', true);
      });
  });

  $(document).on('click', '#ucpf-reverify-playwright', function (e) {
    e.preventDefault();
    var $run = $('#ucpf-scanner-run');
    if ($run.length) {
      $('html, body').animate({ scrollTop: Math.max(0, $run.offset().top - 48) }, 200);
    }
    runReverifyScan('#ucpf-scan-status');
  });

  $(document).on('click', '#ucpf-scroll-import', function (e) {
    e.preventDefault();
    var $box = $('.ucpf-import-box').first();
    if ($box.length) {
      $('html, body').animate({ scrollTop: Math.max(0, $box.offset().top - 48) }, 200);
      $('#ucpf-import-scan-json-text').trigger('focus');
    }
  });

  $(document).on('click', '.ucpf-enable-blocking', function () {
    var key = $(this).attr('data-service');
    var $btn = $(this).prop('disabled', true);
    enableServiceBlocking([key]).then(function (data) {
      var n = data && data.count ? data.count : 0;
      showReverifyPrompt(
        '#ucpf-scan-status',
        n ? ('Blocking enabled for ' + key + '.') : ('Could not enable blocking for ' + key + '.')
      );
      $btn.prop('disabled', false).text('Enabled');
    }).catch(function (err) {
      $btn.prop('disabled', false);
      setStatus('#ucpf-scan-status', (err && err.message) ? err.message : 'Enable blocking failed.', true);
    });
  });

  $(document).on('click', '#ucpf-enable-leak-blocking', function () {
    var raw = $(this).attr('data-services') || '[]';
    var keys = [];
    try {
      keys = JSON.parse(raw);
    } catch (e) {
      keys = [];
    }
    if (!Array.isArray(keys) || !keys.length) {
      return;
    }
    var $btn = $(this).prop('disabled', true);
    enableServiceBlocking(keys).then(function (data) {
      var n = data && data.count ? data.count : 0;
      showReverifyPrompt(
        '#ucpf-scan-status',
        'Enabled blocking for ' + n + ' service(s). Re-verify with Playwright to confirm leaks clear.'
      );
      $btn.prop('disabled', false);
    }).catch(function (err) {
      $btn.prop('disabled', false);
      setStatus('#ucpf-scan-status', (err && err.message) ? err.message : 'Bulk enable failed.', true);
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

  function downloadJson(filename, data) {
    var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    a.click();
  }

  function exportKnowledgePack() {
    restGet('knowledge/export').then(function (data) {
      var n = (data && data.knowledge_count) ? data.knowledge_count : ((data && data.cookies) ? data.cookies.length : 0);
      downloadJson('ucpf-knowledge-export.json', data);
      if (window.console && console.info) {
        console.info('UCPF knowledge export:', n, 'cookie(s)');
      }
    }).catch(function (err) {
      alert((err && err.message) ? err.message : 'Knowledge export failed');
    });
  }

  $('#ucpf-knowledge-export, #ucpf-knowledge-export-toolbar').on('click', exportKnowledgePack);

  function syncContributeButtons() {
    var on = $('#ucpf-contribute-consent').is(':checked');
    $('#ucpf-contribute-download, #ucpf-contribute-github').prop('disabled', !on);
  }

  $('#ucpf-contribute-consent').on('change', syncContributeButtons);

  $('#ucpf-contribute-download').on('click', function () {
    if (!$('#ucpf-contribute-consent').is(':checked')) {
      return;
    }
    var status = $('#ucpf-contribute-status');
    status.text('Preparing pack…');
    restGet('knowledge/contribute').then(function (data) {
      var n = (data && data.cookie_count) ? data.cookie_count : 0;
      downloadJson('ucpf-knowledge-contribution.json', data);
      status.text(
        n
          ? ('Downloaded ' + n + ' cookie(s). Next: Open GitHub issue and attach the file.')
          : 'Pack downloaded (empty or fully scrubbed). Add reviews first, or open an issue describing the cookies.'
      );
    }).catch(function (err) {
      status.text((err && err.message) ? err.message : 'Contribution pack failed');
    });
  });

  $('#ucpf-contribute-github').on('click', function () {
    if (!$('#ucpf-contribute-consent').is(':checked')) {
      return;
    }
    var url = (ucpfAdmin && ucpfAdmin.contributeIssueUrl) ? ucpfAdmin.contributeIssueUrl : '';
    if (!url) {
      $('#ucpf-contribute-status').text('GitHub issue URL is not configured.');
      return;
    }
    window.open(url, '_blank', 'noopener,noreferrer');
    $('#ucpf-contribute-status').text('GitHub opened — attach ucpf-knowledge-contribution.json to the issue.');
  });

  $('#ucpf-knowledge-import').on('click', function () {
    $('#ucpf-knowledge-import-file').trigger('click');
  });

  $('#ucpf-knowledge-import-file').on('change', function () {
    var file = this.files && this.files[0];
    if (!file) {
      return;
    }
    var reader = new FileReader();
    reader.onload = function () {
      try {
        var pack = JSON.parse(String(reader.result || ''));
        restPost('knowledge/import', pack).then(function (res) {
          alert((res && res.message) ? res.message : 'Imported knowledge pack.');
          window.location.reload();
        }).catch(function (err) {
          alert((err && err.message) ? err.message : 'Import failed');
        });
      } catch (e) {
        alert('Invalid JSON');
      }
    };
    reader.readAsText(file);
    this.value = '';
  });

  function sourceLabel(src) {
    if (src === 'ucpf') {
      return 'Vendor catalog';
    }
    if (src === 'knowledge') {
      return 'Site knowledge';
    }
    if (src === 'open_cookie_database') {
      return 'Open Cookie Database';
    }
    return src || '—';
  }

  function runCookieLookup() {
    var q = String($('#ucpf-cookie-lookup-q').val() || '').trim();
    var status = $('#ucpf-cookie-lookup-status');
    var table = $('#ucpf-cookie-lookup-table');
    var tbody = table.find('tbody');
    if (q.length < 2) {
      status.text('Enter at least 2 characters.');
      table.attr('hidden', 'hidden');
      return;
    }
    status.text('Searching…');
    tbody.empty();
    restGet('cookies/lookup?q=' + encodeURIComponent(q) + '&limit=25').then(function (data) {
      var rows = (data && data.results) ? data.results : [];
      if (!rows.length) {
        status.text('No local matches. Add via Cookie Review, then export knowledge for your hub.');
        table.attr('hidden', 'hidden');
        return;
      }
      status.text((data && data.note) ? data.note : (rows.length + ' result(s).'));
      rows.forEach(function (row) {
        var name = row.name || '';
        var cat = row.category || '';
        var purpose = row.purpose || '';
        var tr = $('<tr/>');
        tr.append($('<td/>').text(name));
        tr.append($('<td/>').text(sourceLabel(row.source)));
        tr.append($('<td/>').text(row.provider || row.service_name || '—'));
        tr.append($('<td/>').text(cat || '—'));
        tr.append($('<td/>').text(purpose ? purpose.slice(0, 160) : '—'));
        var actions = $('<td/>');
        if (cat) {
          var btn = $('<button type="button" class="button button-small"/>').text('Save to knowledge');
          btn.on('click', function () {
            var map = {};
            map[name] = {
              purpose: purpose,
              category: cat,
              treatment: row.treatment || 'consent',
              visibility: 'show',
              label: row.provider || row.service_name || name
            };
            restPost('cookies/overrides', { overrides: map }).then(function () {
              status.text('Saved “' + name + '” to site knowledge.');
            }).catch(function (err) {
              alert((err && err.message) ? err.message : 'Could not save');
            });
          });
          actions.append(btn);
        } else {
          actions.append($('<span class="description"/>').text('Assign category in Cookie Review'));
        }
        tr.append(actions);
        tbody.append(tr);
      });
      table.removeAttr('hidden');
    }).catch(function (err) {
      status.text((err && err.message) ? err.message : 'Lookup failed');
      table.attr('hidden', 'hidden');
    });
  }

  $('#ucpf-cookie-lookup-go').on('click', runCookieLookup);
  $('#ucpf-cookie-lookup-q').on('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      runCookieLookup();
    }
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

  var lastThemePack = null;

  function themePackStatus(msg, isError) {
    setStatus('#ucpf-theme-pack-status', msg, isError);
  }

  function fillThemePackTextarea(pack) {
    lastThemePack = pack;
    $('#ucpf-theme-pack-json').val(JSON.stringify(pack, null, 2));
    $('#ucpf-theme-copy, #ucpf-theme-download').prop('hidden', false);
  }

  $('#ucpf-theme-export').on('click', function () {
    var name = ($('#ucpf-theme-pack-name').val() || '').trim();
    var path = 'theme/export' + (name ? ('?name=' + encodeURIComponent(name)) : '');
    themePackStatus('Exporting theme…');
    restGet(path).then(function (data) {
      fillThemePackTextarea(data);
      themePackStatus('Theme pack ready — copy or download below.');
    }).catch(function (err) {
      themePackStatus((err && err.message) ? err.message : 'Export failed.', true);
    });
  });

  $('#ucpf-theme-copy').on('click', function () {
    var text = $('#ucpf-theme-pack-json').val() || '';
    if (!text) {
      themePackStatus('Nothing to copy — export first.', true);
      return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        themePackStatus('Copied to clipboard.');
      }).catch(function () {
        themePackStatus('Could not copy — select the JSON and copy manually.', true);
      });
    } else {
      themePackStatus('Clipboard unavailable — select the JSON and copy manually.', true);
    }
  });

  $('#ucpf-theme-download').on('click', function () {
    var pack = lastThemePack;
    if (!pack) {
      try {
        pack = JSON.parse($('#ucpf-theme-pack-json').val() || '{}');
      } catch (e) {
        themePackStatus('Invalid JSON in the textarea.', true);
        return;
      }
    }
    var slug = (pack && pack.name) ? String(pack.name).replace(/[^\w\-]+/g, '-').replace(/^-|-$/g, '').toLowerCase() : 'theme';
    if (!slug) {
      slug = 'theme';
    }
    var blob = new Blob([JSON.stringify(pack, null, 2)], { type: 'application/json' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'ucpf-theme-' + slug + '.json';
    a.click();
    themePackStatus('Download started.');
  });

  $('#ucpf-theme-pack-file').on('change', function () {
    var file = this.files && this.files[0];
    if (!file) {
      return;
    }
    var reader = new FileReader();
    reader.onload = function () {
      try {
        var pack = JSON.parse(String(reader.result || ''));
        fillThemePackTextarea(pack);
        themePackStatus('Loaded ' + (file.name || 'file') + '. Click Import theme to apply.');
      } catch (e) {
        themePackStatus('That file is not valid JSON.', true);
      }
    };
    reader.onerror = function () {
      themePackStatus('Could not read that file.', true);
    };
    reader.readAsText(file);
  });

  $('#ucpf-theme-import').on('click', function () {
    var raw = $('#ucpf-theme-pack-json').val();
    var pack;
    try {
      pack = JSON.parse(raw);
    } catch (e) {
      themePackStatus('Invalid JSON — paste a UCPF theme pack or choose a .json file.', true);
      return;
    }
    if (!pack || typeof pack !== 'object') {
      themePackStatus('Theme pack must be a JSON object.', true);
      return;
    }
    themePackStatus('Importing theme…');
    restPost('theme/import', pack).then(function (res) {
      var msg = (res && res.message) ? res.message : 'Theme imported.';
      themePackStatus(msg);
      window.setTimeout(function () { window.location.reload(); }, 700);
    }).catch(function (err) {
      themePackStatus((err && err.message) ? err.message : 'Import failed.', true);
    });
  });

  function updateBannerPreview() {
    var banner = document.getElementById('ucpf-banner-preview-el');
    var root = document.querySelector('#ucpf-banner-preview #ucpf-root');
    if (!banner) {
      return;
    }

    var layout = $('#ucpf-banner-layout').val() || 'bar';
    var position = $('#ucpf-banner-position').val() || 'left';
    var theme = $('#ucpf-banner-theme').val() || 'classic';
    var layouts = ['bar', 'modal', 'corner'];
    var positions = ['left', 'center', 'right'];

    layouts.forEach(function (name) {
      banner.classList.remove('ucpf-banner--' + name);
    });
    positions.forEach(function (name) {
      banner.classList.remove('ucpf-banner--pos-' + name);
    });
    banner.classList.add('ucpf-banner--' + layout);
    banner.classList.add('ucpf-banner--pos-' + position);
    banner.classList.add('ucpf-banner--visible');
    banner.setAttribute('data-ucpf-layout', layout);
    banner.setAttribute('data-ucpf-position', position);

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

  $('#ucpf-banner-layout, #ucpf-banner-position, #ucpf-banner-theme').on('change', updateBannerPreview);
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
      wrap.setAttribute('role', 'region');
      wrap.setAttribute('tabindex', '0');
      wrap.setAttribute('aria-label', 'Scrollable data table');
      if (table.parentNode) {
        table.parentNode.insertBefore(wrap, table);
        wrap.appendChild(table);
      }
    });
    // Ensure existing wrappers are keyboard-reachable.
    Array.prototype.forEach.call(
      (scope.querySelectorAll ? scope.querySelectorAll('.ucpf-table-scroll') : []),
      function (wrap) {
        if (!wrap.getAttribute('role')) {
          wrap.setAttribute('role', 'region');
        }
        if (!wrap.hasAttribute('tabindex')) {
          wrap.setAttribute('tabindex', '0');
        }
        if (!wrap.getAttribute('aria-label')) {
          wrap.setAttribute('aria-label', 'Scrollable data table');
        }
      }
    );
  }
  $(document).on('click', '#ucpf-registry-refresh', function () {
    var $st = $('#ucpf-registry-sync-status');
    $st.text('Refreshing registry…');
    restPost('registry/refresh', {}).then(function (data) {
      var msg = (data && data.message) ? data.message : 'Done.';
      if (data && data.status && data.status.at) {
        msg += ' (' + data.status.at + ')';
      }
      $st.text(msg);
    }).catch(function (err) {
      $st.text((err && err.message) ? err.message : 'Refresh failed.');
    });
  });

  $(document).on('click', '#ucpf-scanner-reset-all', function () {
    var ok = window.confirm(
      'Cancel ALL jobs on the shared scanner and reset slots?\n\nThis affects every WordPress site using this scanner host. Prefer Stop scan (your job only) unless Chromium is stuck.'
    );
    if (!ok) {
      return;
    }
    var $st = $('#ucpf-scanner-reset-status');
    $st.text('Requesting cancel-all…');
    restPost('scan/cancel', { cancel_all: true, confirm_cancel_all: true }).then(function (data) {
      $st.text((data && data.message) ? data.message : 'Reset requested.');
    }).catch(function (err) {
      $st.text((err && err.message) ? err.message : 'Reset failed.');
    });
  });

  $(document).on('input', '#ucpf-registry-search', function () {
    var q = String($(this).val() || '')
      .toLowerCase()
      .trim();
    var $rows = $('#ucpf-registry-table tbody tr');
    var shown = 0;
    $rows.each(function () {
      var hay = String($(this).attr('data-ucpf-filter') || '').toLowerCase();
      var match = !q || hay.indexOf(q) !== -1;
      $(this).toggle(match);
      if (match) {
        shown += 1;
      }
    });
    $('#ucpf-registry-empty').prop('hidden', shown > 0);
  });

  $(document).on('change', '.ucpf-integration-card__enable input[type="checkbox"]', function () {
    $(this).closest('.ucpf-integration-card').toggleClass('is-enabled', this.checked);
  });

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
