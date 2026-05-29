/* global window, document, fetch */
/**
 * add-packshot-acceptance-flow — "Packshots — review" client code.
 * Vanilla JS, no build step (CLAUDE.md "no Node, no TypeScript, no React").
 *
 * Each ✓/✗ button POSTs { job_id, decision, _token } to the vote endpoint.
 * On a 2xx the card is removed from the pending queue (the vote also flipped
 * the server + local voting). On failure the card stays and a short inline
 * message is shown so the operator can retry.
 *
 * Activation: the template injects
 *   window.QameraAiPackshotReview = { voteUrl, csrfToken, i18n };
 * before this script loads.
 */
(function () {
  'use strict';

  function init() {
    var config = window.QameraAiPackshotReview || {};
    var voteUrl = config.voteUrl || '';
    var csrfToken = config.csrfToken || '';
    var i18n = config.i18n || {};
    if (!voteUrl) { return; }

    document.querySelectorAll('.js-qameraai-vote').forEach(function (btn) {
      btn.addEventListener('click', function (evt) {
        evt.preventDefault();
        vote(btn, voteUrl, csrfToken, i18n);
      });
    });
  }

  function vote(btn, voteUrl, csrfToken, i18n) {
    var jobId = btn.getAttribute('data-job-id');
    var decision = btn.getAttribute('data-decision');
    if (!jobId || !decision) { return; }

    var card = btn.closest('.qameraai-review-card');
    var feedback = card ? card.querySelector('.qameraai-vote-feedback') : null;
    setCardBusy(card, true);
    if (feedback) { feedback.textContent = ''; feedback.className = 'qameraai-vote-feedback small px-3 pb-2'; }

    var body = new window.URLSearchParams();
    body.set('job_id', jobId);
    body.set('decision', decision);
    body.set('_token', csrfToken);

    fetch(voteUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: body.toString()
    })
      .then(function (res) {
        return res.json().catch(function () { return null; }).then(function (payload) {
          return { ok: res.ok, payload: payload };
        });
      })
      .then(function (result) {
        if (result.ok && result.payload && result.payload.ok) {
          // Voted — drop the card from the pending queue.
          removeCard(card);
          return;
        }
        var msg = (result.payload && result.payload.error)
          ? result.payload.error
          : (i18n.error || 'Vote failed');
        showError(feedback, msg);
        setCardBusy(card, false);
      })
      .catch(function () {
        showError(feedback, i18n.error || 'Vote failed');
        setCardBusy(card, false);
      });
  }

  function setCardBusy(card, busy) {
    if (!card) { return; }
    card.querySelectorAll('.js-qameraai-vote').forEach(function (b) {
      if (busy) {
        b.setAttribute('disabled', 'disabled');
      } else {
        b.removeAttribute('disabled');
      }
    });
  }

  function removeCard(card) {
    if (!card) { return; }
    card.parentNode.removeChild(card);

    var grid = document.getElementById('qameraai-review-grid');
    if (grid && grid.querySelectorAll('.qameraai-review-card').length === 0) {
      window.location.reload();
    }
  }

  function showError(feedback, message) {
    if (!feedback) { return; }
    feedback.className = 'qameraai-vote-feedback small px-3 pb-2 text-danger';
    feedback.textContent = message;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
