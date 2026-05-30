# packshot-acceptance-smoke Specification

## Purpose
TBD - created by archiving change verify-packshot-acceptance-smoke. Update Purpose after archive.
## Requirements
### Requirement: Prerequisites are confirmed before the gated smoke runs

The smoke SHALL NOT be considered started until two prerequisites are confirmed for the `pracownia-qamery-ai` install:

- **External (saas-platform):** `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` is ON (server env + per-account), so a `job_type='photo_shoot'` that omits `packshot_asset_id` is resolved from `product_ref` rather than rejected as `invalid_input`.
- **Internal (this repo, LANDED):** the plugin's catalog registration (`fix-packshot-catalog-registration`, archived `2026-05-29`) is deployed — `PackshotJobSubmitter` calls `registerPackshot()` (`POST /plugin/packshots`, ref `ps:s:p:packshot:src`) before submitting, enrolling the product image as a `product_packshots` row. This is PROVEN necessary-and-sufficient for **stage-1 packshot** jobs (the #25 smoke on product 31 completed via auto-registration). Its sufficiency for the **photo-shoot** resolve is NOT yet confirmed: on 2026-05-29 the upstream `product_ref`→packshot resolve behaved inconsistently — product 32 resolved to the raw-image asset (`7f056763`) and completed, while product 31 resolved to the accepted stage-1 **job id** (`1c5a14b0`, not an asset) and failed `MISSING_CATALOG_ENTRY`, despite both having an accepted packshot. `product_packshots` rows live upstream and are invisible from the plugin, and product 32's pass may hinge on a manual `POST /plugin/packshots` of the raw-image asset performed during that smoke. The clean re-run (this change) is what determines whether the landed plugin registration ALONE yields a completing photo-shoot.

If either prerequisite is unmet, the smoke is BLOCKED and the blocker SHALL be recorded (not silently skipped).

#### Scenario: Both prerequisites confirmed unblocks the smoke
- **WHEN** the operator confirms the flag is ON and the plugin's catalog-registration build is deployed for `pracownia-qamery-ai`
- **THEN** the gated scenarios (photo-shoot generation) may be executed

#### Scenario: A missing prerequisite blocks and is recorded
- **WHEN** the flag is OFF, or the deployed build predates `fix-packshot-catalog-registration` so no `product_packshots` row is created
- **THEN** the gated scenarios remain BLOCKED and the specific unmet prerequisite is recorded as the reason

### Requirement: The two-stage packshot pipeline is proven end-to-end against the live backend

The packshot → review → accept → photo-shoot pipeline SHALL be verified against `https://qamera.ai/api/v1/plugin` on the live container with the gate ON, exercising the archived `add-packshot-acceptance-flow` §9.1–9.4. Evidence (job ids, webhook delivery ids, the resolved asset id, screenshots or log lines) SHALL be recorded against each scenario so the verification is auditable.

#### Scenario: Stage-1 packshot completion lands in the review queue (§9.1)
- **GIVEN** a synced, `described` product
- **WHEN** the operator triggers "Generate packshot" and the upstream `job.completed(job_type='packshot')` webhook is delivered
- **THEN** a `ps_qamera_packshot_review` row appears in "Packshots — review" with `voting='pending'` and a preview from `outputs[0].url`

#### Scenario: Accept cascades upstream and unlocks photo-shoot (§9.2)
- **WHEN** the operator clicks Approve and `POST /jobs/{id}/accept` returns 2xx
- **THEN** the local row flips to `voting='accepted'`, the row leaves the pending queue, and the product's "Generate photo-shoot" action becomes enabled on the grid

#### Scenario: Gated photo-shoot succeeds with no manual registration (§9.3)
- **GIVEN** the accepted packshot, the flag ON, and a build that includes `fix-packshot-catalog-registration` — and NO manual `POST /plugin/packshots` performed by the operator
- **WHEN** the operator triggers "Generate photo-shoot" (the submission omits `packshot_asset_id`)
- **THEN** the plugin's own pre-submit `registerPackshot()` has created the `product_packshots` row, the backend resolves it per `product_ref`, and the `job.completed(job_type='photo_shoot')` webhook is delivered (no `MISSING_CATALOG_ENTRY`, no `packshot_not_approved`)
- **AND** the resolved `packshot_asset_id` from the completed-job payload is recorded (noting whether it is the raw product-image asset or the accepted stage-1 output asset — see the secondary finding in `project_photoshoot_resolve_returns_jobid`)

#### Scenario: Reject path and 422 drift are surfaced as friendly flashes (§9.4)
- **WHEN** the operator rejects a pending packshot, OR a photo-shoot submit returns a gated 422 (`packshot_not_approved` / gate-disabled `invalid_input`)
- **THEN** the reject removes the row from the queue, and the 422 is shown as a localized actionable flash (never a raw error)

