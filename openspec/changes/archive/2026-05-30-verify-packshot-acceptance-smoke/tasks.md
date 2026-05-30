# Tasks — verify-packshot-acceptance-smoke

> Verification-only. No plugin code changes. Operator-driven, credit-spending
> smoke against the live container on the **main checkout** (not a worktree —
> per CLAUDE.md the bind-mount is the only path that resolves `QameraAi\Module\…`).
> Record evidence (job ids, delivery ids, resolved asset id, screenshot/log refs)
> inline as each box is checked.

> **Status 2026-05-30 — COMPLETE.** Clean §9.3 re-run executed on a pristine `main`
> (module `1.8.0`, PS-registered == on-disk after the #28 merge) on FRESH product 33,
> NO manual registration: §2 generate, §3 accept, §4 photo-shoot all GREEN; §5.1 reject
> green; §5.2 resolved by-design (the gate disables the action client-side, so the 422
> is unreachable by clicking — covered by unit tests). The cloudflared Quick Tunnel was
> re-established (host `message-safari-lat-stephanie.trycloudflare.com`, `ps_shop_url` row 3).
> A side-find (`fix-jobs-history-import-refresh`, PR #29 merged) fell out of the run: the
> jobs-history poll didn't surface the "Download to shop" affordance without a full reload.
> Teardown (rozbrój) performed at close-out.

## 0. Prerequisites (gate)

- [x] 0.1 (external — saas-platform) `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` confirmed **ON** for `pracownia-qamery-ai` (2026-05-29): post-flip §9.3 returned `MISSING_CATALOG_ENTRY`, not the flag-OFF `422 invalid_input` — proving the resolve path ran.
- [x] 0.2 (internal — LANDED + now PROVEN sufficient for photo-shoot on a pristine build) `fix-packshot-catalog-registration` (archived `2026-05-29`) wired: `PackshotJobSubmitter` calls `registerPackshot()` (`POST /plugin/packshots`) before job submit (`:244`). Verified on a pristine `main` install (module `1.8.0`, PS-registered == on-disk after the #28 merge) on a FRESH product 33 with NO manual registration → §9.3 completed (jobs 29/30). The 2026-05-29 photo-shoot inconsistency is resolved by the upstream resolve fix (saas-platform, deployed between 05-29 and 05-30, operator-confirmed — product 31's day-old `1c5a14b0` photo-shoot also completed today). Plugin side needs no change.

## 1. Environment readiness

- [x] 1.1 Live container up (`qameraai-ps`); `ps_qamera_packshot_review` table + `AdminQameraAiPackshotReview` tab present (review rows exist, BO renders them). NB: PS-registered version `1.7.0` while on-disk is `1.8.0-WIP` — clean re-run needs a pristine install where these match.
- [x] 1.2 BO Configuration carries a valid `QAMERAAI_API_KEY` + `QAMERAAI_WEBHOOK_SECRET` for `pracownia-qamery-ai` (live jobs completed + signed webhooks accepted on 2026-05-29 prove both).
- [x] 1.3 Test product used in the prior run: `id_product=32`, `product_ref=ps:1:32`, `analysis_status='described'`. (Clean re-run will use a NEW fresh product per the runbook.)

## 2. Smoke — stage 1: generate + review (§9.1) — VERIFIED (prior run 2026-05-29)

- [x] 2.1 "Generate packshot" → stage-1 jobs completed: `290fe4b4-…` (job 20) and `1a2e8b58-…` (job 21) on `ps:1:32`.
- [x] 2.2 `job.completed(job_type='packshot')` webhooks delivered + accepted (200): delivery `9b795314-…` (290fe4b4) and `8741bccd-…` (1a2e8b58).
- [x] 2.3 `ps_qamera_packshot_review` rows appeared with `voting='pending'` + preview from `outputs[0].url` (review rows 6 `1a2e8b58`, 7 `290fe4b4`; signed supabase `outputs/<job>.jpeg` URLs recorded in `asset_url`).

## 3. Smoke — accept + gate unlock (§9.2) — VERIFIED (prior run 2026-05-29)

- [x] 3.1 Approve → `POST /jobs/{id}/accept` 2xx; cards left the pending queue.
- [x] 3.2 Local rows `voting='accepted'` with `voting_at` set (row 6 @ 18:07:12, row 7 @ 18:07:04).
- [x] 3.3 "Generate photo-shoot" enabled on the grid afterwards (photo-shoot jobs 22–24 were subsequently submittable for `ps:1:32`).

## 4. Smoke — gated photo-shoot (§9.3) — DEFERRED to clean checkout (see header runbook)

> Run on a **fresh** product and perform **no manual `POST /plugin/packshots`** — the point is to prove the plugin's own pre-submit `registerPackshot()` (landed) creates the catalog row. (The 2026-05-29 product-32 pass used a manual registration; this re-run must not.)

- [x] 4.1 "Generate photo-shoot" on fresh product 33 (`ps:1:33`) → submitted as `job_type='photo_shoot'`, `packshot_asset_id` omitted (submitter `:270`). Session = 2 images → 2 local jobs (29 `09e1d524`, 30 `397e0a62`). NO manual `POST /plugin/packshots` performed.
- [x] 4.2 VERIFIED — both photo-shoot jobs **completed** with outputs (webhooks `c475b6bd` 07:26:34Z + `fe8701fc` 07:27:55Z), NO `MISSING_CATALOG_ENTRY`, NO `packshot_not_approved`. Pre-flight `registerPackshot()` worked (the stage-1 packshot job 26 itself never errored on catalog). **Resolved `packshot_asset_id = c5fc83d2…` = the accepted stage-1 packshot's JOB id** (the operator's accepted vote, review row 9) — so the session now sources from the ACCEPTED packshot, NOT the raw product-image asset. This UPDATES the 2026-05-29 secondary finding (product 32 had resolved to the raw image): the upstream resolve fix (deployed by saas-platform between 05-29 and 05-30, confirmed by operator) now keys photo-shoot on the accepted packshot. **Transient observed:** a jobs-history-refresh poll briefly recorded `"Plugin asset c5fc83d2 did not reach terminal state within 120000ms"` on each sub-job before completion — a backend intermediate-state message (NOT in plugin code), cleared once the job completed within ~6 min. Worth flagging to saas-platform as a noisy non-fatal poll snapshot, not a §9.3 blocker.

## 5. Smoke — reject + drift flashes (§9.4)

- [x] 5.1 Reject path VERIFIED (prior run 2026-05-29): packshot `5f5028cc-…` (review row 4, `ps:1:31`) rejected → `voting='rejected'` @ 16:48:09, row left the pending queue.
- [x] 5.2 VERIFIED BY DESIGN — the `packshot_not_approved` 422 is NOT reachable by clicking: on a product without an accepted packshot the "Generate photo-shoot" action is **disabled client-side** (the gate), so the operator never submits an un-approved photo-shoot (operator-confirmed 2026-05-30: "przycisk jest nieaktywny"). The server-side 422 is a drift defense (e.g. upstream rejects after a local accept) covered by `PhotoShootSubmitErrorClassifier` unit tests; the disabled button IS the intended UX, so no raw error can surface from a click. (Flag-OFF `invalid_input` branch is no longer reproducible — gate is ON.)

## 6. Close-out — DEFERRED

- [x] 6.1 §2–§5 all green/resolved with recorded evidence on a pristine build (module 1.8.0) → archiving this change.
- [x] 6.2 `project_packshot_acceptance_flow_contract` / `project_photoshoot_resolve_returns_jobid` memories updated to reflect the corrected diagnosis (catalog-registration is the §9.3 unblocker; resolve uses the raw image asset). To be re-touched with the final clean-run outcome.
