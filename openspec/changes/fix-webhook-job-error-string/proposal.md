## Why

The 1.6.0 webhook-contract smoke (2026-05-29, live `qamera.ai`) proved that a real `job.failed` delivery sends **`job.error` as a plain STRING** — e.g.

```
"job": { …, "error": "PLUGIN_JOB_MISSING_CATALOG_ENTRY: asset_id … has no matching product_packshots row …" }
```

not the object `{code, message_i18n, retryable}` the plugin assumed when `fix-webhook-payload-contract` (#22) was specced. `PayloadExtractor::jobErrorMessage()` (`src/Webhook/Event/Handler/PayloadExtractor.php:112`) does `if (!is_array($error)) return null;`, so for the real string shape it returns `null` and `ps_qamera_packshot_job.last_error_message` is left **NULL on every real failure**. Confirmed across 4 live failed deliveries (all NULL).

This is a pure observability regression: the BO Jobs view and any future acceptance-flow error surfacing show no reason for a failed packshot/photo-shoot job. It must be fixed before `add-packshot-acceptance-flow`, whose review/gate UX relies on surfacing why a job failed.

## What Changes

- `PayloadExtractor::jobErrorMessage()` SHALL tolerate **both** wire shapes for `payload.job.error`:
  - a non-empty **string** → return it verbatim (truncation to the column's TEXT capacity stays with the persisting `PackshotJobUpdater`),
  - an **object** `{message_i18n|message|code}` → the existing locale-preference extraction (unchanged),
  - anything else (null/empty/number) → `null` (unchanged).
- No new event type, no schema change, no new endpoint. One method gains a string branch; the webhook-handler spec scenario that hard-codes the object shape is generalized.

## Capabilities

### Modified Capabilities

- `webhook-handler`: the "Job-event handlers update the local packshot_job table by qamera_job_id" requirement is clarified so `last_error_message` is derived from `payload.job.error` whether it arrives as a string or an object.

## Impact

- **Code**: `src/Webhook/Event/Handler/PayloadExtractor.php` (`jobErrorMessage` gains a string branch). No change to `PackshotJobUpdater` (it already truncates/persists whatever message string it is handed).
- **Tests**: new unit cases for string `job.error`, object `job.error` (regression), and absent/empty.
- **Risk**: minimal — additive branch, existing object path byte-for-byte unchanged.
- **Depends on**: nothing (sits on current `main`, post #22). Is a **prerequisite of** `add-packshot-acceptance-flow`.
- **Out of scope**: changing the upstream wire shape (string vs object is the server's; the plugin tolerates both), and surfacing the message in any new UI (acceptance-flow owns that).
