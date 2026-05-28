/* Qamera AI — generate form client-side helpers.
 *
 * Plain ES5/ES6, no build step, no NPM. Loaded via a <script> tag with
 * data-* attributes carrying the cost endpoint URL + i18n strings.
 *
 * Behaviour:
 *   - On change of ai_model / images_count, fetch /generate/cost and
 *     update the cost display (or fall back to "unavailable").
 *   - Debounce the fetch so dragging the images_count number input
 *     doesn't fire 30 requests per second.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script) { return; }
  var costUrl = script.dataset.costUrl;
  var subjectCount = parseInt(script.dataset.subjectCount, 10) || 1;
  var unavailableLabel = script.dataset.unavailableLabel || 'unavailable';
  var creditsLabel = script.dataset.creditsLabel || 'credits';

  var aiModel = document.getElementById('qameraai-ai-model');
  var imagesCount = document.getElementById('qameraai-images-count');
  var costValue = document.getElementById('qameraai-cost-value');
  if (!aiModel || !imagesCount || !costValue) { return; }

  var pending = null;

  function setCost(text) {
    costValue.textContent = text;
  }

  function fetchCost() {
    var model = aiModel.value;
    var images = parseInt(imagesCount.value, 10) || 1;
    if (!model) {
      setCost('—');
      return;
    }

    var url = costUrl + '?ai_model=' + encodeURIComponent(model)
      + '&images_count=' + images
      + '&subjects=' + subjectCount;

    if (pending) { pending.abort(); }
    pending = new AbortController();

    fetch(url, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      signal: pending.signal
    })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (json && typeof json.cost === 'number') {
          setCost(json.cost + ' ' + creditsLabel);
        } else {
          setCost(unavailableLabel);
        }
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') { return; }
        setCost(unavailableLabel);
      });
  }

  var debounceTimer = null;
  function schedule() {
    if (debounceTimer) { clearTimeout(debounceTimer); }
    debounceTimer = setTimeout(fetchCost, 250);
  }

  aiModel.addEventListener('change', schedule);
  imagesCount.addEventListener('input', schedule);
  imagesCount.addEventListener('change', schedule);

  // Prime once on load so the operator sees a number before touching anything.
  schedule();
})();
