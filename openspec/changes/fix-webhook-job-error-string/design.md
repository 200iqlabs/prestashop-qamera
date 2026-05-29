# Design — fix-webhook-job-error-string

## Context

`fix-webhook-payload-contract` (#22) rewrote the inbound handler against the documented `WebhookPayload` shape, where `job.error` is typed as the `Error` object (`{code, message_i18n, retryable}`) in `plugin-v1.yaml`. The live smoke contradicted the doc: for plugin-job validation/generation failures the worker emits `job.error` as a **flat string**. The OpenAPI `Error` object is still what `GET /jobs/{id}` returns (REST DTO), so the two surfaces differ — the webhook wire is looser than the REST contract. The plugin must therefore be defensive on the webhook side rather than trust the OpenAPI object shape.

## Decision

### D1 — Tolerate both shapes in `jobErrorMessage()`, string first

```php
$error = $job['error'] ?? null;
if (is_string($error)) {
    return $error !== '' ? $error : null;   // real wire shape (smoke-confirmed)
}
if (!is_array($error)) {
    return null;                            // null / number / unexpected
}
// … existing object handling (message_i18n → message → code) unchanged …
```

String branch goes first because it is the shape actually observed in production. The object branch is retained verbatim so the REST-DTO shape (and any future server alignment to the documented object) keeps working — no regression. Truncation to the `last_error_message` TEXT column is intentionally NOT done here; `PackshotJobUpdater` already owns persistence-side truncation, and `jobErrorMessage()` is a pure extractor.

Rejected alternative: switch entirely to string-only. The REST `GET /jobs` path and the documented contract both still use the object; a one-way change would regress those and any test fixtures that encode the object shape.

## Out of scope

Renegotiating the server wire shape, locale handling for the string case (a flat string has no i18n map — returned verbatim), and any UI surfacing of the message.
