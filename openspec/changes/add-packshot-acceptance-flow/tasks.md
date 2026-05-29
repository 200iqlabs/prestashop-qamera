# Tasks — add-packshot-acceptance-flow

> **READY for `/opsx:apply`** (finalized 2026-05-29). All prerequisites merged and the
> runtime contract confirmed (see design.md "Resolved…"). Section 0 is satisfied.

## 0. Prerequisites confirmed (gate) — ✅ DONE

- [x] 0.1 `fix-packshot-asset-id-mismatch` (#21) merged; **`fix-packshot-catalog-registration` (#25)** merged + smoked — a stage-1 `job_type='packshot'` submit now completes (the plugin registers the input packshot first; no `generation_failed` / `MISSING_CATALOG_ENTRY`). Proven on product 31.
- [x] 0.2 `fix-webhook-payload-contract` (#22) + `fix-webhook-job-error-string` (#24) merged; a real `job.completed(job_type='packshot')` was accepted (200) and `outputs[0].url` confirmed = signed Supabase preview (`type='image/jpeg'`), persisted into `output_url`.

## 1. API client (qamera-api-client)

- [x] 1.1 `SubmitJobRequest` + optional `?string $jobType` (last ctor param); `toPayload()` emits `job_type` when set.
- [x] 1.2 `Subject.packshotAssetId` now `?string`; `toPayload()` omits `packshot_asset_id` when null.
- [x] 1.3 `QameraApiClient::acceptJob/rejectJob(string $id): void` → `POST /jobs/{id}/accept|reject` via `dispatch` (204, no decode); `409` → `ValidationException` (the typed 400/409/422 mapping). Tests: accept/reject endpoints + 204 + 409 + job_type emit + null-asset omit. (QameraApiClientTest 50/50; full unit 328/328; PHPCS clean.)

## 2. Schema (packshot-acceptance)

- [x] 2.1 `ps_qamera_packshot_review` table (`Installer::createSchema()` + `dropSchema()` + idempotent `upgrade-1.7.0.php`); keyed on `qamera_job_id`, columns per design D1 (`asset_url` TEXT, `voting` ENUM default 'pending', `voting_at` NULL, `generated_at`). No FK — matched via parsed `product_ref`. `(product_ref, voting)` index backs the gate. Module bumped 1.6.0 → 1.7.0.
- [x] 2.2 Review entity + lookup (`src/Packshot/Acceptance/`): `PackshotReviewRow` (voting consts) + `PackshotReviewRepository` — `upsertFromWebhook` (INSERT pending, ON DUP refreshes asset_url/generated_at only — never reverts voting), `listPending` (locale JOIN, newest-first), `setVoting`, `hasAcceptedForProductRef`, `findByJobId`. (PackshotReviewRepositoryTest 10/10; full unit 384/384; PHPCS clean.)

## 3. Submitter branch (packshot-jobs)

- [ ] 3.1 Branch the submit path: packshot (job_type='packshot', auto_register=true, source asset_id) vs photo_shoot (job_type='photo_shoot', omit asset_id + auto_register). **Scope the `registerPackshot('…:packshot:src')` pre-flight added by #25 to the packshot branch ONLY** — photo_shoot must NOT register an input packshot (it relies on the upstream accepted-packshot resolution). Update `PackshotJobSubmitterTest` accordingly.
- [ ] 3.2 Photo-shoot eligibility = has a local `voting='accepted'` review row for the product_ref.

## 4. Webhook branch (webhook-event-dispatch / packshot-acceptance)

- [ ] 4.1 `JobCompletedHandler`: if `payload.job.job_type==='packshot'` → upsert review row (voting='pending', asset_url=`outputs[0].url`, product matched via parsed `product_ref`); else existing synced path.

## 5. Vote + gate (packshot-acceptance)

- [ ] 5.1 Vote service: accept/reject → `acceptJob`/`rejectJob`, update local `voting`/`voting_at` on 2xx; leave pending on `ApiException`.
- [ ] 5.2 422 `packshot_not_approved` handling: detect via `ErrorEnvelope.code`, flash `messageFor(locale)` + "accept a packshot first".

## 6. BO UI (qamera-bo-ui)

- [ ] 6.1 Dedicated "Packshots — review" controller + Twig + JS (thumbnail grid, ✓/✗, AJAX vote).
- [ ] 6.2 Products grid: relabel Generate → "Generate packshot"; add "Generate photo-shoot" action gated on accepted-packshot JOIN; disabled hint.

## 7. Tests

- [ ] 7.1 DTO: `SubmitJobRequest` job_type emit; `Subject` nullable asset_id omit; `acceptJob`/`rejectJob` request shape.
- [ ] 7.2 Webhook branch: packshot completion → pending review row; photo_shoot completion → no review row.
- [ ] 7.3 Gate: accepted unlocks photo-shoot; pending/none disables; 422 friendly flash.
- [ ] 7.4 Submitter branch: packshot sends auto_register+asset_id; photo_shoot omits both.

## 8. Static analysis + lint

- [ ] 8.1 PHPCS / PHPStan-L5 / PHPUnit green across 8.1/8.2/8.3.

## 9. Smoke (operator-driven)

- [ ] 9.1 Generate packshot → webhook → row appears in "Packshots — review" (pending) with preview.
- [ ] 9.2 Accept → `/jobs/{id}/accept` 2xx → local voting='accepted' → "Generate photo-shoot" enables on the grid.
- [ ] 9.3 Generate photo-shoot → succeeds (backend resolves accepted packshot per product_ref).
- [ ] 9.4 Reject path; and 422 drift path surfaces a friendly flash.
