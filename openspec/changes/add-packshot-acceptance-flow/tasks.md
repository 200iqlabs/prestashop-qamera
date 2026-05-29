# Tasks — add-packshot-acceptance-flow

> **IMPLEMENTED** (2026-05-29). Tasks 1–8 complete on branch `add-packshot-acceptance-flow`
> (commits 4e7b125 · 8271ac0 · 8920835 · 724b72b · 9b6a2c9 · 58084ea). Full unit suite
> 398/398 green on PHP 8.1/8.2/8.3; PHPCS clean. Only §9 (operator smoke) remains, which
> also needs the upstream `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` flag flipped ON.

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

- [x] 3.1 Branched the submit path on `SubmitFormInput.jobType` (new field, validated `packshot`|`photo_shoot`, default `packshot`): packshot sends `job_type='packshot'` + `packshot_asset_id` + `auto_register_packshot=true` + the `:packshot:src` pre-flight register; photo_shoot sends `job_type='photo_shoot'` and OMITS all three (Subject asset_id/auto_register null → dropped by `toPayload()`; no input register). Local mirror row gets a stage-tagged `:photoshoot:`/`:packshot:` external_ref. Wired `PackshotReviewRepository` into the submitter via services.yml + new `FakePackshotReviewRepository`; updated `PackshotJobSubmitterTest` (+2 photo_shoot tests, job_type assert) and `SubmitWebhookEndToEndTest` ctor.
- [x] 3.2 Photo-shoot eligibility = `reviewRepository->hasAcceptedForProductRef($productRef)` (replaces `canGenerate()` on that branch); DB failure surfaces as a structured `SubmitResult`, not a 500. (Submitter+e2e 17/17; full unit 386/386; PHPCS clean.)

## 4. Webhook branch (webhook-event-dispatch / packshot-acceptance)

- [x] 4.1 `JobCompletedHandler` branches on `payload.job.job_type`: `'packshot'` → after the job mirror, calls new `PackshotReviewWriter::recordPending` (voting='pending', asset_url=`outputs[0].url`, canonical `ps:shop:product` from the parsed ref, `generated_at=now`); `photo_shoot`/untyped → mirror only, no review row. Writer wired into the manual webhook graph (`controllers/front/webhook.php`) + new `FakePackshotReviewWriter`; handler ctor + `JobCompletedHandlerTest` updated (+3 cases: packshot records / photo_shoot skips / untyped skips). (Handler 9/9; full unit 389/389; PHPCS clean.)

## 5. Vote + gate (packshot-acceptance)

- [x] 5.1 `PackshotVoteService::accept/reject` — calls `acceptJob`/`rejectJob` FIRST, flips local `voting`/`voting_at` ONLY on 2xx; an `ApiException` (e.g. 409) propagates before the local write so the row stays `pending`. Wired in services.yml.
- [x] 5.2 `PhotoShootSubmitErrorClassifier` (pure) → `PhotoShootSubmitError` discriminator: `packshot_not_approved`→`KIND_NOT_APPROVED`, `invalid_input`→`KIND_GATE_DISABLED` (flag-OFF cutover signal), else→`KIND_OTHER`; carries the server's `messageFor(locale)`. Controller (task 6) composes the localized flash. (Vote+classifier 7/7; full unit 396/396; PHPCS clean.)

## 6. BO UI (qamera-bo-ui)

- [x] 6.1 `PackshotReviewController` (`indexAction` lists pending via `listPending`; `voteAction` POST/AJAX → `PackshotVoteService`, CSRF-guarded, JSON `{ok,voting}` / 400 / 502 / 500) + `packshot_review.html.twig` (thumbnail cards, ✓/✗) + `packshot_review.js` (AJAX vote, removes card on 2xx). Routes added; new `AdminQameraAiPackshotReview` tab in `Installer` + idempotent tab creation in `upgrade-1.7.0.php`.
- [x] 6.2 Products grid: relabeled Generate → "Generate packshot"; added gated "Generate photo-shoot" action (enabled iff `acceptedRefsIn` batch-JOIN says the product_ref has an accepted review row; disabled with hint otherwise). `GenerateFormController` carries `job_type` through show/submit, uses photo-shoot eligibility filter on show, and on a photo_shoot 422 runs `PhotoShootSubmitErrorClassifier` → friendly flash. `generate_form.twig` adaptive heading + hidden `job_type`; grid JS click-guard extended. (Full unit 398/398; PHPCS clean.)

## 7. Tests

- [x] 7.1 DTO: covered by `QameraApiClientTest` (job_type emit, null packshot_asset_id omit, accept/reject endpoints + 204 + 409).
- [x] 7.2 Webhook branch: covered by `JobCompletedHandlerTest` (packshot → pending review row; photo_shoot + untyped → no review row).
- [x] 7.3 Gate: 422 friendly-flash mapping covered by `PhotoShootSubmitErrorClassifierTest`; accepted-packshot JOIN gate covered by `PackshotReviewRepositoryTest::acceptedRefsIn*`. Grid enable/disable rendering itself is Twig-level → exercised by §9 smoke.
- [x] 7.4 Submitter branch: covered by `PackshotJobSubmitterTest` (packshot sends auto_register+asset_id+input-register; photo_shoot omits all three) + `PackshotVoteServiceTest`.

## 8. Static analysis + lint

- [x] 8.1 PHPCS clean (src/ tests/) + PHPUnit **398/398** green on **8.1, 8.2, 8.3** (verified in-container). PHPStan-L5 runs in CI only — it requires the PrestaShop core (`_PS_ROOT_DIR_` / `ps-module-extension.neon`), unavailable in the standalone docker runner per CLAUDE.md; the new `src/Packshot/Acceptance/*` follows the same `Db`-repository shape already passing CI.

## 9. Smoke (operator-driven)

- [ ] 9.1 Generate packshot → webhook → row appears in "Packshots — review" (pending) with preview.
- [ ] 9.2 Accept → `/jobs/{id}/accept` 2xx → local voting='accepted' → "Generate photo-shoot" enables on the grid.
- [ ] 9.3 Generate photo-shoot → succeeds (backend resolves accepted packshot per product_ref).
- [ ] 9.4 Reject path; and 422 drift path surfaces a friendly flash.
