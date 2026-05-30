/* global window, document, fetch, FormData */
/**
 * add-gallery-image-ingest — product-detail "Qamera" tab client. Vanilla JS,
 * no build step (CLAUDE.md "no Node, no TypeScript, no React"). Drives the
 * ingest picker (push) and the browse accordion (pull) against the
 * GalleryIngest/GalleryBrowse controllers.
 *
 * Config arrives as a JSON blob in #qameraai-gallery-config (urls, tokens,
 * write-scope flag, i18n). Bytes never touch the browser — ingest is one
 * server-side call per image; the picker only sends id_image + mode.
 */
(function () {
  'use strict';

  function init() {
    var root = document.getElementById('qameraai-gallery-tab');
    var configEl = document.getElementById('qameraai-gallery-config');
    if (!root || !configEl) { return; }

    var config;
    try { config = JSON.parse(configEl.textContent || '{}'); } catch (e) { return; }
    var ctx = { root: root, config: config, i18n: config.i18n || {} };

    bindSourceToggle(ctx);
    bindIngestActions(ctx);
    bindBrowse(ctx);
    loadBrowse(ctx);
  }

  function t(ctx, key, fallback) {
    return (ctx.i18n && ctx.i18n[key]) || fallback || key;
  }

  /* ---------------- Ingest picker ---------------- */

  function bindSourceToggle(ctx) {
    var buttons = ctx.root.querySelectorAll('[data-source]');
    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (btn.disabled) { return; }
        buttons.forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
      });
    });
  }

  function bindIngestActions(ctx) {
    var actions = ctx.root.querySelectorAll('[data-action="ingest"]');
    actions.forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (btn.disabled) { return; }
        var mode = btn.getAttribute('data-mode');
        var selected = selectedCells(ctx);
        if (selected.length === 0) {
          window.alert(t(ctx, 'select_one', 'Select at least one image.'));
          return;
        }
        ingestSequentially(ctx, selected, mode, 0);
      });
    });
  }

  function selectedCells(ctx) {
    var out = [];
    ctx.root.querySelectorAll('.qameraai-picker-check:checked').forEach(function (cb) {
      out.push(cb.closest('.qameraai-picker-cell'));
    });
    return out;
  }

  function ingestSequentially(ctx, cells, mode, i) {
    if (i >= cells.length) {
      loadBrowse(ctx);
      return;
    }
    var cell = cells[i];
    var idImage = cell.getAttribute('data-id-image');
    var statusEl = cell.querySelector('[data-role="status"]');
    setStatus(statusEl, 'working', t(ctx, 'uploading', 'Uploading…'));

    var body = new FormData();
    body.append('_token', ctx.config.token.ingest);
    body.append('id_image', idImage);
    body.append('mode', mode);

    fetch(ctx.config.urls.ingest, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          var label = data.status === 'existing'
            ? t(ctx, 'already', 'Already in Qamera')
            : t(ctx, 'ready', 'Ready');
          setStatus(statusEl, 'ok', label);
        } else {
          setStatus(statusEl, 'error', data.error_message || data.error_code || t(ctx, 'failed', 'Failed'));
        }
      })
      .catch(function () {
        setStatus(statusEl, 'error', t(ctx, 'failed', 'Failed'));
      })
      .then(function () {
        ingestSequentially(ctx, cells, mode, i + 1);
      });
  }

  function setStatus(el, kind, text) {
    if (!el) { return; }
    el.textContent = text;
    el.className = 'qameraai-picker-status qameraai-status-' + kind;
  }

  /* ---------------- Browse accordion ---------------- */

  function bindBrowse(ctx) {
    var refresh = ctx.root.querySelector('[data-action="refresh-browse"]');
    if (refresh) {
      refresh.addEventListener('click', function () { loadBrowse(ctx); });
    }
  }

  function loadBrowse(ctx) {
    var container = document.getElementById('qameraai-browse');
    var notices = document.getElementById('qameraai-browse-notices');
    if (!container) { return; }
    container.innerHTML = '';
    container.appendChild(textDiv('text-muted', t(ctx, 'loading', 'Loading…')));
    if (notices) { notices.innerHTML = ''; }

    fetch(ctx.config.urls.browse, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) { renderBrowse(ctx, data); })
      .catch(function () {
        container.innerHTML = '';
        container.appendChild(alertDiv('alert-danger', t(ctx, 'browse_failed', 'Could not load Qamera data.')));
      });
  }

  function renderBrowse(ctx, data) {
    var container = document.getElementById('qameraai-browse');
    var notices = document.getElementById('qameraai-browse-notices');
    container.innerHTML = '';
    notices.innerHTML = '';

    if (!data.ok) {
      container.appendChild(alertDiv('alert-danger', t(ctx, 'browse_failed', 'Could not load Qamera data.')));
      return;
    }
    if (!data.found) {
      container.appendChild(alertDiv('alert-info', t(ctx, 'empty', 'This product is not in Qamera yet. Push a gallery image above to get started.')));
      return;
    }
    if (data.images_truncated || data.packshots_truncated) {
      notices.appendChild(alertDiv('alert-warning', t(ctx, 'truncated', 'Some images or packshots are not shown (truncated by the server).')));
    }

    (data.images || []).forEach(function (image) {
      container.appendChild(buildRow(ctx, image));
    });

    if ((data.orphan_packshots || []).length > 0) {
      container.appendChild(buildOrphans(ctx, data.orphan_packshots));
    }

    maybePollStatus(ctx, data.images || []);
  }

  // D4: poll GET /products embedded analysis_status while any image is still
  // pending/processing, flipping the badge to described without a manual
  // Refresh. Bounded to ~1 min so a stuck analysis does not poll forever.
  function maybePollStatus(ctx, images) {
    var pending = images.filter(function (i) {
      return i.analysis_status === 'pending' || i.analysis_status === 'processing';
    });
    if (pending.length === 0 || !ctx.config.urls.status) { return; }

    var attempts = 0;
    var timer = window.setInterval(function () {
      attempts += 1;
      fetch(ctx.config.urls.status, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          var anyPending = false;
          ((data && data.images) || []).forEach(function (img) {
            updateBadge(img.image_id, img.analysis_status);
            if (img.analysis_status === 'pending' || img.analysis_status === 'processing') {
              anyPending = true;
            }
          });
          if (!anyPending || attempts >= 12) { window.clearInterval(timer); }
        })
        .catch(function () { if (attempts >= 12) { window.clearInterval(timer); } });
    }, 5000);
  }

  function updateBadge(imageId, status) {
    if (!imageId) { return; }
    var row = document.querySelector('.qameraai-browse-row[data-image-id="' + imageId + '"]');
    if (!row) { return; }
    var b = row.querySelector('.qameraai-badge');
    if (b && status) { b.textContent = status; }
  }

  function buildRow(ctx, image) {
    var row = document.createElement('div');
    row.className = 'qameraai-browse-row card mb-2';
    row.setAttribute('data-image-id', image.image_id);

    var header = document.createElement('div');
    header.className = 'qameraai-browse-head card-header';
    header.appendChild(thumb(image.thumbnail_url, ctx));
    var meta = document.createElement('span');
    meta.className = 'qameraai-browse-meta';
    meta.appendChild(badge(image.analysis_status));
    meta.appendChild(countChip('📦', image.packshot_count));
    meta.appendChild(countChip('🎬', image.session_count));
    header.appendChild(meta);
    row.appendChild(header);

    var body = document.createElement('div');
    body.className = 'qameraai-browse-body';
    body.style.display = 'none';
    row.appendChild(body);

    var expanded = false;
    var sessionsLoaded = false;
    header.addEventListener('click', function () {
      expanded = !expanded;
      body.style.display = expanded ? 'block' : 'none';
      if (expanded && !sessionsLoaded) {
        sessionsLoaded = true;
        renderPackshotStrip(ctx, body, image.packshots || []);
        loadSessions(ctx, body, image.image_id);
      }
    });

    return row;
  }

  function renderPackshotStrip(ctx, body, packshots) {
    var wrap = document.createElement('div');
    wrap.className = 'qameraai-strip';
    var title = document.createElement('h4');
    title.textContent = t(ctx, 'packshots', 'Packshots');
    wrap.appendChild(title);
    var strip = document.createElement('div');
    strip.className = 'qameraai-thumbs';
    if (packshots.length === 0) {
      strip.appendChild(textDiv('text-muted', t(ctx, 'none', 'None')));
    }
    packshots.forEach(function (p) {
      // output_index 0: a packshot-generation job emits exactly one image
      // output (the cutout), so the generated packshot is always its job's
      // output 0. ThumbnailSourcer makes the same single-output assumption.
      // If packshot jobs ever emit multiple outputs this must map asset→index.
      strip.appendChild(assetCell(ctx, p.thumbnail_url, p.importable ? { job_id: p.generated_by_job_id, output_index: 0 } : null));
    });
    wrap.appendChild(strip);
    body.appendChild(wrap);
  }

  function loadSessions(ctx, body, imageId) {
    var wrap = document.createElement('div');
    wrap.className = 'qameraai-strip';
    var title = document.createElement('h4');
    title.textContent = t(ctx, 'sessions', 'Photo-shoot sessions');
    wrap.appendChild(title);
    var strip = document.createElement('div');
    strip.className = 'qameraai-thumbs';
    strip.appendChild(textDiv('text-muted', t(ctx, 'loading', 'Loading…')));
    wrap.appendChild(strip);
    body.appendChild(wrap);

    var url = ctx.config.urls.sessionsTemplate.replace('__IMAGE__', encodeURIComponent(imageId));
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        strip.innerHTML = '';
        if (data && data.sessions_error) {
          strip.appendChild(textDiv('text-muted', t(ctx, 'sessions_unavailable', 'Sessions unavailable — the API key is missing the jobs read scope.')));
          return;
        }
        var sessions = (data && data.sessions) || [];
        if (sessions.length === 0) {
          strip.appendChild(textDiv('text-muted', t(ctx, 'none', 'None')));
        }
        sessions.forEach(function (s) {
          strip.appendChild(assetCell(ctx, s.url, { job_id: s.job_id, output_index: s.output_index }));
        });
        if (data && data.sessions_truncated) {
          wrap.appendChild(alertDiv('alert-warning', t(ctx, 'recent_only', 'Showing recent sessions only.')));
        }
      })
      .catch(function () {
        strip.innerHTML = '';
        strip.appendChild(textDiv('text-danger', t(ctx, 'sessions_failed', 'Could not load sessions.')));
      });
  }

  function buildOrphans(ctx, orphans) {
    var row = document.createElement('div');
    row.className = 'qameraai-browse-row card mb-2';
    var header = document.createElement('div');
    header.className = 'card-header';
    header.textContent = t(ctx, 'synthesized', 'Synthesized / unmatched packshots');
    row.appendChild(header);
    var body = document.createElement('div');
    body.className = 'qameraai-browse-body card-body';
    var strip = document.createElement('div');
    strip.className = 'qameraai-thumbs';
    orphans.forEach(function (p) {
      // output_index 0: see renderPackshotStrip — single-output packshot job.
      strip.appendChild(assetCell(ctx, p.thumbnail_url, p.importable ? { job_id: p.generated_by_job_id, output_index: 0 } : null));
    });
    body.appendChild(strip);
    row.appendChild(body);
    return row;
  }

  /* ---------------- Add-to-gallery ---------------- */

  function assetCell(ctx, url, importTarget) {
    var cell = document.createElement('div');
    cell.className = 'qameraai-thumb-cell';
    cell.appendChild(thumb(url, ctx));

    if (importTarget && importTarget.job_id) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-sm btn-outline-primary qameraai-import-btn';
      btn.textContent = t(ctx, 'add_to_gallery', 'Add to product gallery');
      btn.addEventListener('click', function () { importOutput(ctx, btn, importTarget); });
      cell.appendChild(btn);
    }
    return cell;
  }

  function importOutput(ctx, btn, target) {
    btn.disabled = true;
    btn.textContent = t(ctx, 'importing', 'Importing…');

    var body = new FormData();
    body.append('_token', ctx.config.token.import);
    body.append('job_id', target.job_id);
    body.append('output_index', target.output_index);

    fetch(ctx.config.urls.importOutput, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var imported = data && data.ok === true
          && (data.state === 'imported' || (data.imported && data.imported.length));
        var already = data && data.state === 'already_imported';
        if (imported || already) {
          btn.textContent = already ? t(ctx, 'already_imported', 'Already imported') : t(ctx, 'imported', 'Imported ✓');
          btn.classList.add('btn-success');
          if (imported) { showImportedNotice(ctx); }
          return;
        }
        // Surface the server reason instead of a blank "Import failed".
        btn.textContent = reasonMessage(ctx, data && data.reason);
        btn.disabled = false;
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = t(ctx, 'import_failed', 'Import failed');
      });
  }

  // The PS "Images" tab is a separate core component we cannot refresh from
  // here, so the appended gallery image only shows after a page reload. Tell
  // the operator once per page.
  function showImportedNotice(ctx) {
    var notices = document.getElementById('qameraai-browse-notices');
    if (!notices || document.getElementById('qameraai-import-notice')) { return; }
    var note = alertDiv('alert-success', t(ctx, 'imported_reload', 'Image added to the gallery — reload the page to see it in the Images tab.'));
    note.id = 'qameraai-import-notice';
    notices.appendChild(note);
  }

  function reasonMessage(ctx, reason) {
    var map = {
      product_not_registered: t(ctx, 'reason_not_registered', 'Product not synced to Qamera yet'),
      packshot_not_accepted: t(ctx, 'reason_not_accepted', 'Packshot not accepted yet'),
      not_completed: t(ctx, 'reason_not_completed', 'Job not completed'),
      invalid_product_ref: t(ctx, 'import_failed', 'Import failed'),
      output_not_found: t(ctx, 'import_failed', 'Import failed'),
      api_error: t(ctx, 'reason_api_error', 'Qamera API error'),
      invalid_csrf: t(ctx, 'import_failed', 'Import failed'),
      bad_request: t(ctx, 'import_failed', 'Import failed')
    };
    return (reason && map[reason]) || t(ctx, 'import_failed', 'Import failed');
  }

  /* ---------------- DOM helpers ---------------- */

  function thumb(url, ctx) {
    if (!url) {
      var ph = document.createElement('span');
      ph.className = 'qameraai-thumb qameraai-thumb-placeholder';
      ph.textContent = t(ctx, 'no_preview', 'no preview');
      return ph;
    }
    var a = document.createElement('a');
    a.href = url;
    a.className = 'qameraai-thumb-link';
    a.setAttribute('data-fancybox', 'qameraai-gallery');
    a.target = '_blank';
    a.rel = 'noopener';
    var img = document.createElement('img');
    img.className = 'qameraai-thumb';
    img.src = url;
    img.loading = 'lazy';
    a.appendChild(img);
    return a;
  }

  function badge(status) {
    var span = document.createElement('span');
    span.className = 'badge qameraai-badge badge-secondary';
    span.textContent = status || 'pending';
    return span;
  }

  function countChip(icon, n) {
    var span = document.createElement('span');
    span.className = 'qameraai-count';
    span.textContent = icon + ' ' + (n || 0);
    return span;
  }

  function textDiv(cls, text) {
    var d = document.createElement('div');
    d.className = cls;
    d.textContent = text;
    return d;
  }

  function alertDiv(kind, text) {
    var d = document.createElement('div');
    d.className = 'alert ' + kind;
    d.setAttribute('role', 'alert');
    d.textContent = text;
    return d;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
