/* global window, document, fetch */
/**
 * add-jobs-history-refresh — Jobs history client poll. Vanilla JS, no build
 * step (CLAUDE.md "no Node, no TypeScript, no React"). Mirrors the products
 * grid poll semantics (5s, FIFO <=10/cycle, infinite-while-in-flight, drop a
 * row after 5 consecutive failures) without sharing code — see design D4.
 *
 * The webhook is the primary updater; this poll is a fallback so in-flight
 * rows (pending / in_progress) flip to completed/failed + render the output
 * thumbnail without a manual page reload. Per-row Refresh forces a pull
 * (TTL-bypass via ?force=1).
 *
 * Activation: the template injects
 *   window.QameraAiJobsHistory = { statusUrlTemplate: '...' };
 */
(function () {
  'use strict';

  var POLL_INTERVAL_MS = 5000;
  var ROWS_PER_CYCLE = 10;
  var IN_FLIGHT_STATES = ['pending', 'in_progress'];
  var MAX_CONSECUTIVE_FAILURES = 5;

  // Module-scoped config so the delegated import handler and the poll's
  // applyJobToRow can build "Download to shop" affordances on rows the server
  // rendered before they were importable.
  var moduleConfig = {};

  function init() {
    var config = window.QameraAiJobsHistory || {};
    var statusUrlTemplate = config.statusUrlTemplate || '';
    if (!statusUrlTemplate) { return; }
    moduleConfig = config;

    initRefreshButtons(statusUrlTemplate);
    initPoll(statusUrlTemplate);
    initImportDelegation(config);
  }

  // add-packshot-output-downloader — "Download to shop". A single DELEGATED
  // click handler (not per-button binding) so import buttons the poll injects
  // into a row that completed after page load fire too — fixes "the button
  // only appears/works after a full page reload" (the poll now renders the
  // affordance in place via applyJobToRow; this makes those buttons live).
  function initImportDelegation(config) {
    if (!config.importUrlTemplate) { return; }

    document.addEventListener('click', function (evt) {
      var target = evt.target;
      if (!target || typeof target.closest !== 'function') { return; }
      var btn = target.closest('.js-qameraai-job-import');
      if (!btn || btn.hasAttribute('disabled')) { return; }
      evt.preventDefault();
      var jobId = btn.getAttribute('data-job-id');
      if (!jobId) { return; }
      runImport(jobId, config.importUrlTemplate, config.importCsrfToken || '', config.i18n || {}, btn);
    });
  }

  function runImport(jobId, importUrlTemplate, csrfToken, i18n, button) {
    var url = importUrlTemplate.replace('{jobId}', encodeURIComponent(jobId));
    button.setAttribute('disabled', 'disabled');
    button.classList.add('qameraai-spinner');

    var body = new URLSearchParams();
    body.set('_token', csrfToken);

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
      .then(function (res) {
        return res.json().catch(function () { return null; });
      })
      .then(function (payload) {
        // Terminal "imported" states: a fresh import, a partial set with at
        // least one image written, or an already-imported row (e.g. a second
        // tab/click after a prior import). All three mean the output is in the
        // gallery, so the cell flips to the "imported" badge.
        var didImport = payload && (payload.imported && payload.imported.length);
        if (payload && (payload.state === 'imported' || payload.state === 'already_imported' || didImport)) {
          markImported(jobId, i18n);
          if (payload.state === 'partial' && window.console) {
            window.console.warn('[QameraAi] ' + (i18n.importPartial || 'Some images failed to import'));
          }
          return;
        }
        // Nothing placed / aborted — re-enable and surface the reason.
        button.removeAttribute('disabled');
        button.classList.remove('qameraai-spinner');
        var reason = payload && payload.reason ? payload.reason : (i18n.importFailed || 'Import failed');
        if (window.console) {
          window.console.warn('[QameraAi] import for ' + jobId + ' did not complete: ' + reason);
        }
        button.setAttribute('title', String(reason));
      })
      .catch(function () {
        button.removeAttribute('disabled');
        button.classList.remove('qameraai-spinner');
      });
  }

  function markImported(jobId, i18n) {
    var row = document.querySelector('tr[data-job-id="' + cssEscape(jobId) + '"]');
    if (!row) { return; }
    var cell = row.querySelector('.js-qameraai-import-cell');
    if (!cell) { return; }
    while (cell.firstChild) { cell.removeChild(cell.firstChild); }
    var badge = document.createElement('span');
    badge.className = 'badge badge-success js-qameraai-import-done';
    badge.textContent = i18n.imported || 'Imported';
    cell.appendChild(badge);
  }

  function initRefreshButtons(statusUrlTemplate) {
    document.querySelectorAll('.js-qameraai-job-refresh').forEach(function (btn) {
      btn.addEventListener('click', function (evt) {
        evt.preventDefault();
        var jobId = btn.getAttribute('data-job-id');
        if (!jobId) { return; }
        runRefresh(jobId, statusUrlTemplate, true, btn);
      });
    });
  }

  function initPoll(statusUrlTemplate) {
    var queue = collectInFlightRows();
    if (queue.length === 0) { return; }

    var seen = {};
    var failures = {};
    queue.forEach(function (id) { seen[id] = true; });

    var timer = window.setInterval(function () {
      if (queue.length === 0) {
        window.clearInterval(timer);
        return;
      }
      var batch = queue.splice(0, ROWS_PER_CYCLE);
      batch.forEach(function (jobId) {
        delete seen[jobId];
        runRefresh(jobId, statusUrlTemplate, false, null).then(function (payload) {
          if (!payload) {
            failures[jobId] = (failures[jobId] || 0) + 1;
            if (failures[jobId] <= MAX_CONSECUTIVE_FAILURES && !seen[jobId]) {
              seen[jobId] = true;
              queue.push(jobId);
            }
            return;
          }
          failures[jobId] = 0;
          if (payload.in_flight && !seen[jobId]) {
            seen[jobId] = true;
            queue.push(jobId);
          }
        });
      });
    }, POLL_INTERVAL_MS);
  }

  function collectInFlightRows() {
    var ids = [];
    IN_FLIGHT_STATES.forEach(function (state) {
      document
        .querySelectorAll('.js-qameraai-job-badge[data-job-status="' + state + '"]')
        .forEach(function (badge) {
          var row = badge.closest('tr[data-job-id]');
          var jobId = row ? row.getAttribute('data-job-id') : null;
          if (jobId && ids.indexOf(jobId) === -1) { ids.push(jobId); }
        });
    });
    return ids;
  }

  function runRefresh(jobId, statusUrlTemplate, force, button) {
    var url = statusUrlTemplate.replace('{jobId}', encodeURIComponent(jobId));
    if (force) { url += (url.indexOf('?') === -1 ? '?' : '&') + 'force=1'; }

    if (button) {
      button.setAttribute('disabled', 'disabled');
      button.classList.add('qameraai-spinner');
    }

    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (res) {
        if (!res.ok) {
          if (window.console) {
            window.console.warn('[QameraAi] job status fetch failed for ' + jobId + ': HTTP ' + res.status);
          }
          return null;
        }
        return res.json().catch(function () { return null; });
      })
      .then(function (payload) {
        if (payload && typeof payload.qamera_job_id !== 'undefined') {
          applyJobToRow(jobId, payload);
          return payload;
        }
        return null;
      })
      .catch(function () { return null; })
      .then(function (result) {
        if (button) {
          button.removeAttribute('disabled');
          button.classList.remove('qameraai-spinner');
        }
        return result;
      });
  }

  function applyJobToRow(jobId, payload) {
    var row = document.querySelector('tr[data-job-id="' + cssEscape(jobId) + '"]');
    if (!row) { return; }

    var badge = row.querySelector('.js-qameraai-job-badge');
    if (badge) {
      badge.className = (payload.badge_class || 'qameraai-badge') + ' js-qameraai-job-badge';
      badge.setAttribute('data-job-status', payload.status || '');
      badge.textContent = payload.badge_label || payload.status || '';
    }

    var err = row.querySelector('.js-qameraai-job-error');
    if (err) {
      err.textContent = payload.last_error_message ? String(payload.last_error_message).slice(0, 120) : '';
    }

    var outputCell = row.querySelector('.js-qameraai-job-output');
    if (outputCell) {
      renderOutput(outputCell, payload.output_url);
    }

    // add-packshot-output-downloader — surface/update the "Download to shop"
    // affordance for a row that became importable (e.g. flipped to completed)
    // since the page was rendered, without a full reload.
    if (payload.import_state) {
      renderImportCell(row, payload.import_state);
    }

    if (payload.refresh_error && window.console) {
      window.console.warn('[QameraAi] job refresh warning for ' + jobId + ': ' + payload.refresh_error);
    }
  }

  // Rebuild the import cell to match the server-rendered markup for the given
  // gate state (imported | active | disabled | absent). Mirrors the Twig in
  // jobs_history.html.twig so a poll-rendered button is indistinguishable from
  // a page-load one (the delegated handler then drives it).
  function renderImportCell(row, importState) {
    var cell = row.querySelector('.js-qameraai-import-cell');
    if (!cell) { return; }
    var state = (importState && importState.state) ? importState.state : 'absent';
    // Never downgrade a cell already showing the "imported" badge — e.g. a poll
    // cycle that raced ahead of the ledger write right after an in-page import.
    if (state !== 'imported' && cell.querySelector('.js-qameraai-import-done')) { return; }

    var i18n = moduleConfig.i18n || {};
    while (cell.firstChild) { cell.removeChild(cell.firstChild); }

    if (state === 'imported') {
      cell.appendChild(buildImportedBadge(i18n));
    } else if (state === 'active') {
      cell.appendChild(buildImportButton(row.getAttribute('data-job-id'), i18n, false));
    } else if (state === 'disabled') {
      cell.appendChild(buildImportButton(null, i18n, true));
    } else {
      var dash = document.createElement('span');
      dash.className = 'text-muted';
      dash.textContent = '—';
      cell.appendChild(dash);
    }
  }

  function buildMaterialIcon(name) {
    var icon = document.createElement('i');
    icon.className = 'material-icons';
    icon.textContent = name;
    return icon;
  }

  function buildImportedBadge(i18n) {
    var badge = document.createElement('span');
    badge.className = 'badge badge-success js-qameraai-import-done';
    var icon = buildMaterialIcon('check');
    icon.style.fontSize = '14px';
    icon.style.verticalAlign = 'middle';
    badge.appendChild(icon);
    badge.appendChild(document.createTextNode(' ' + i18n.imported));
    return badge;
  }

  function buildImportButton(jobId, i18n, disabled) {
    var btn = document.createElement('button');
    btn.setAttribute('type', 'button');
    if (disabled) {
      btn.className = 'btn btn-outline-secondary btn-sm';
      btn.setAttribute('disabled', 'disabled');
      btn.setAttribute('title', i18n.acceptFirstTitle);
    } else {
      btn.className = 'btn btn-outline-primary btn-sm js-qameraai-job-import';
      btn.setAttribute('data-job-id', jobId || '');
      btn.setAttribute('title', i18n.downloadTitle);
    }
    btn.appendChild(buildMaterialIcon('cloud_download'));
    btn.appendChild(document.createTextNode(' ' + i18n.downloadToShop));
    return btn;
  }

  // Only http(s) URLs are allowed for the thumbnail link/src — blocks a
  // javascript:/data: scheme from executing on click (building DOM nodes
  // already prevents attribute breakout; this closes the scheme hole too).
  function isSafeHttpUrl(value) {
    return /^https?:\/\//i.test(String(value));
  }

  // Build the thumbnail via DOM nodes (NOT innerHTML string concat) so a
  // hostile/odd output URL cannot break out of an attribute (XSS-safe).
  function renderOutput(cell, outputUrl) {
    while (cell.firstChild) { cell.removeChild(cell.firstChild); }

    if (!outputUrl || !isSafeHttpUrl(outputUrl)) {
      var dash = document.createElement('span');
      dash.className = 'text-muted';
      dash.textContent = '—';
      cell.appendChild(dash);
      return;
    }

    var a = document.createElement('a');
    a.setAttribute('href', outputUrl);
    a.setAttribute('target', '_blank');
    a.setAttribute('rel', 'noopener');
    var img = document.createElement('img');
    img.setAttribute('src', outputUrl);
    img.setAttribute('alt', '');
    img.style.maxWidth = '64px';
    img.style.maxHeight = '64px';
    a.appendChild(img);
    cell.appendChild(a);
  }

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(value);
    }
    return String(value).replace(/["\\\]]/g, '\\$&');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
