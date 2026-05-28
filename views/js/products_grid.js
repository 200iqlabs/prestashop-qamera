/* global window, document, fetch */
/**
 * Phase 4.4 (add-analysis-status-surfacing) — BO Products grid client
 * code. Vanilla ES5/ES6, no NPM, no build step (per CLAUDE.md
 * "no Node, no TypeScript, no React" constraint).
 *
 * Responsibilities:
 *   1. Bulk-select form: count + Generate-selected button enablement.
 *   2. JS poll: every 5s for in-flight rows
 *      ([data-analysis-status="pending"|"processing"|"null"]),
 *      rate-limited to ≤10 fetches per cycle, FIFO across the visible
 *      window. Infinite loop — stops only when no in-flight rows
 *      remain (no wall-clock cap; backend has no push channel).
 *   3. Per-row Refresh button: synchronous TTL-bypass pull.
 *
 * Activation: the template injects
 *   window.QameraAiProductsGrid = { statusUrlTemplate: '...' };
 * before loading this script. We read from there and bind on
 * DOMContentLoaded.
 */
(function () {
  'use strict';

  var POLL_INTERVAL_MS = 5000;
  var ROWS_PER_CYCLE = 10;
  var IN_FLIGHT_STATES = ['pending', 'processing', 'null'];
  // Cap consecutive transient failures per row before we drop it from
  // the poll queue. Without a cap, a permanently-broken endpoint would
  // be hammered indefinitely; without re-enqueueing on failure, a single
  // transient blip would silently strand an in-flight row until reload.
  var MAX_CONSECUTIVE_FAILURES = 5;

  function init() {
    var config = window.QameraAiProductsGrid || {};
    var statusUrlTemplate = config.statusUrlTemplate || '';

    initBulkSelect();
    initRefreshButtons(statusUrlTemplate);
    initPoll(statusUrlTemplate);
    initGenerateGuard();
  }

  /**
   * Generate is rendered as an `<a>` regardless of state (so the JS poll
   * can flip a disabled row to enabled without swapping element shape).
   * Intercept clicks on rows that are still gated so a stale-disabled
   * anchor cannot navigate.
   */
  function initGenerateGuard() {
    document.addEventListener('click', function (evt) {
      var anchor = evt.target.closest && evt.target.closest('.js-qameraai-generate');
      if (anchor && anchor.getAttribute('aria-disabled') === 'true') {
        evt.preventDefault();
      }
    });
  }

  /* --------------------------------------------------------------------
   * 1. Bulk-select form
   * ------------------------------------------------------------------ */

  function initBulkSelect() {
    var form = document.getElementById('qameraai-products-bulk-form');
    if (!form) { return; }

    var selectAll = document.getElementById('qameraai-select-all');
    var rowChecks = form.querySelectorAll('.qameraai-row-select');
    var bulkBtn = document.getElementById('qameraai-bulk-generate');
    var counter = document.getElementById('qameraai-bulk-count');

    function refresh() {
      var n = 0;
      rowChecks.forEach(function (cb) {
        if (cb.checked && !cb.disabled) { n++; }
      });
      if (counter) { counter.textContent = String(n); }
      if (bulkBtn) { bulkBtn.disabled = n === 0; }
    }

    if (selectAll) {
      selectAll.addEventListener('change', function () {
        rowChecks.forEach(function (cb) {
          if (!cb.disabled) { cb.checked = selectAll.checked; }
        });
        refresh();
      });
    }
    rowChecks.forEach(function (cb) {
      cb.addEventListener('change', refresh);
    });
    refresh();
  }

  /* --------------------------------------------------------------------
   * 2. Per-row Refresh button
   * ------------------------------------------------------------------ */

  function initRefreshButtons(statusUrlTemplate) {
    if (!statusUrlTemplate) { return; }

    document.querySelectorAll('.js-qameraai-refresh-analysis').forEach(function (btn) {
      btn.addEventListener('click', function (evt) {
        evt.preventDefault();
        var idLink = parseInt(btn.getAttribute('data-id-link'), 10);
        if (!idLink) { return; }
        runRefresh(idLink, statusUrlTemplate, true, btn);
      });
    });
  }

  /* --------------------------------------------------------------------
   * 3. JS poll (FIFO, ≤10 per tick, infinite while in-flight visible)
   * ------------------------------------------------------------------ */

  function initPoll(statusUrlTemplate) {
    if (!statusUrlTemplate) { return; }

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
      batch.forEach(function (idLink) {
        delete seen[idLink];
        runRefresh(idLink, statusUrlTemplate, false, null).then(function (payload) {
          if (!payload) {
            // Transient failure (network error, 4xx/5xx, bad envelope).
            // The row is still in-flight by assumption — re-queue with
            // a small retry budget so a one-off blip doesn't strand it,
            // but a permanently-broken endpoint stops eventually.
            failures[idLink] = (failures[idLink] || 0) + 1;
            if (failures[idLink] <= MAX_CONSECUTIVE_FAILURES && !seen[idLink]) {
              seen[idLink] = true;
              queue.push(idLink);
            }
            return;
          }
          failures[idLink] = 0;
          if (IN_FLIGHT_STATES.indexOf(stringifyStatus(payload.analysis_status)) !== -1) {
            // Still in-flight — push back to tail (FIFO across visible window).
            if (!seen[idLink]) {
              seen[idLink] = true;
              queue.push(idLink);
            }
          }
        });
      });
    }, POLL_INTERVAL_MS);
  }

  function collectInFlightRows() {
    var ids = [];
    IN_FLIGHT_STATES.forEach(function (state) {
      document
        .querySelectorAll('[data-analysis-status="' + state + '"]')
        .forEach(function (el) {
          var idLink = parseInt(el.getAttribute('data-id-link'), 10);
          if (idLink && ids.indexOf(idLink) === -1) {
            ids.push(idLink);
          }
        });
    });
    return ids;
  }

  /* --------------------------------------------------------------------
   * Shared refresh path (called from JS poll AND from Refresh button)
   * ------------------------------------------------------------------ */

  function runRefresh(idLink, statusUrlTemplate, force, button) {
    var url = statusUrlTemplate.replace('{idLink}', String(idLink));
    if (force) { url += (url.indexOf('?') === -1 ? '?' : '&') + 'force=1'; }

    if (button) {
      button.setAttribute('disabled', 'disabled');
      button.classList.add('qameraai-spinner');
    }

    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (res) {
        // Gate on res.ok so a 4xx/5xx JSON error envelope (e.g.
        // {"error":"not_found"} or {"error":"db_error",...}) does not
        // get fed to applyStatusToRow — that would blank the badge and
        // force-disable the row from a transient failure.
        if (!res.ok) {
          if (window.console) {
            window.console.warn('[QameraAi] status fetch failed for row ' + idLink + ': HTTP ' + res.status);
          }
          return null;
        }
        return res.json().catch(function () { return null; });
      })
      .then(function (payload) {
        // Defensive: even with res.ok, only apply payloads that look
        // like the success envelope (`id_link` is always populated).
        if (payload && typeof payload.id_link !== 'undefined') {
          applyStatusToRow(idLink, payload);
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

  /**
   * Mutates the DOM for one row given a status JSON response. Idempotent —
   * the JS poll calls this on every tick whether or not the status
   * changed.
   */
  function applyStatusToRow(idLink, payload) {
    var badge = document.querySelector('[data-id-link="' + idLink + '"][data-analysis-status]');
    if (badge) {
      badge.setAttribute('data-analysis-status', stringifyStatus(payload.analysis_status));
      badge.className = 'badge ' + (payload.badge_class || 'badge-secondary');
      var label = (payload.badge_icon || '') + ' ' + (payload.badge_label || '');
      // Mirror the Twig "(k of n)" suffix that the initial render appends
      // when the row has more than one upstream image — otherwise a poll
      // tick or Refresh click drops the count indicator on multi-image
      // partial/described rows.
      var total = payload.analysis_total_count;
      if (typeof total === 'number' && total > 1) {
        var described = typeof payload.analysis_described_count === 'number'
          ? payload.analysis_described_count
          : 0;
        label += ' (' + described + ' of ' + total + ')';
      }
      badge.textContent = label;
      if (payload.analysis_refreshed_at) {
        badge.setAttribute('title', 'Refreshed at ' + payload.analysis_refreshed_at);
      }
    }

    var generateBtn = document.querySelector(
      '.js-qameraai-generate[data-id-link="' + idLink + '"]'
    );
    if (generateBtn) {
      if (payload.generate_enabled) {
        generateBtn.removeAttribute('aria-disabled');
        generateBtn.removeAttribute('tabindex');
        generateBtn.removeAttribute('title');
        generateBtn.classList.remove('btn-secondary', 'disabled');
        generateBtn.classList.add('btn-primary');
      } else {
        generateBtn.setAttribute('aria-disabled', 'true');
        generateBtn.setAttribute('tabindex', '-1');
        generateBtn.classList.remove('btn-primary');
        generateBtn.classList.add('btn-secondary', 'disabled');
        if (payload.hint) {
          generateBtn.setAttribute('title', payload.hint);
        }
      }
    }

    var rowCheck = document.querySelector(
      '.qameraai-row-select[data-id-link="' + idLink + '"]'
    );
    if (rowCheck) {
      if (payload.generate_enabled) {
        rowCheck.removeAttribute('disabled');
      } else {
        rowCheck.setAttribute('disabled', 'disabled');
        rowCheck.checked = false;
      }
    }

    if (payload.refresh_error && window.console) {
      window.console.warn(
        '[QameraAi] analysis refresh warning for row ' + idLink + ': ' + payload.refresh_error
      );
    }
  }

  function stringifyStatus(s) {
    return s === null || typeof s === 'undefined' ? 'null' : String(s);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
