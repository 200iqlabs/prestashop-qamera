## Why

The plugin-side of `add-packshot-acceptance-flow` (Phase 4.4) shipped, merged (PR #26), and was archived (`2026-05-29-add-packshot-acceptance-flow`) with unit coverage 400/400 — but its §9 operator-driven end-to-end smoke (generate → review → accept → photo-shoot) was never executed and stayed unchecked. Two blockers prevented it: the `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` flag was OFF upstream, and a gated `job_type='photo_shoot'` failed `MISSING_CATALOG_ENTRY`. The 2026-05-29 smoke advanced the diagnosis but did not fully close it: the plugin-side catalog registration (`fix-packshot-catalog-registration`, archived `2026-05-29` — `PackshotJobSubmitter` registers the product via `POST /plugin/packshots` before submit) is PROVEN to unblock **stage-1 packshot** jobs, yet the **photo-shoot** resolve behaved inconsistently across two products on the same backend — product 32 resolved to the raw-image asset and completed, product 31 resolved to the accepted stage-1 **job id** (not an asset) and failed, despite both having an accepted packshot. `product_packshots` rows are upstream/invisible from the plugin, and product 32's pass may hinge on a manual raw-image registration done during that smoke. Archiving the implementation change closed the code work but left the e2e verification gate open; this change tracks that gate as a first-class, checkable artifact — and now scopes §9.3 to determine whether the landed plugin registration **alone** (no manual `POST /plugin/packshots`) yields a completing photo-shoot, and to capture/raise the upstream resolve inconsistency.

## What Changes

- **No plugin code changes.** The plugin-side flow is correct and already specced under the `packshot-acceptance` capability (`Subject` omits `packshot_asset_id` on the photo-shoot branch; client-side gate enforced regardless of the server flag). This change adds **verification requirements + an executable smoke runbook**, not behavior.
- **New `packshot-acceptance-smoke` capability**: a single requirement asserting the two-stage pipeline is proven end-to-end against the live backend with the gate ON, with scenarios mirroring the archived §9.1–9.4.
- **Explicit prerequisites** captured as gating tasks (not silently assumed): the external `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` ON for the `pracownia-qamery-ai` install, and the internal (landed) `fix-packshot-catalog-registration` build deployed so the plugin creates the `product_packshots` row before submit.
- **Smoke evidence** (job ids, webhook deliveries, screenshots/log lines) recorded against each scenario so the verification is auditable, not just a checkbox.

## Capabilities

### New Capabilities

- `packshot-acceptance-smoke`: end-to-end operator verification of the packshot → accept → photo-shoot pipeline against the live Qamera AI backend with the photo-shoot gate enabled — proving the already-shipped `packshot-acceptance` plugin code works in production, not just in unit tests.

### Modified Capabilities

<!-- None — no plugin requirement changes. The behavior under test is already
     specified by the `packshot-acceptance`, `qamera-api-client`, and
     `qamera-bo-ui` capabilities synced from add-packshot-acceptance-flow. -->

## Impact

- **Code**: none. Verification-only change.
- **Dependencies** (hard prerequisites):
  - External — `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` flipped ON for the `pracownia-qamery-ai` account (confirmed 2026-05-29).
  - Internal (LANDED) — `fix-packshot-catalog-registration` deployed: the plugin creates the `product_packshots` row via `POST /plugin/packshots` before submit (proven sufficient for stage-1; photo-shoot sufficiency is what §9.3 must establish given the observed upstream resolve inconsistency).
- **Runtime**: operator-driven, credit-spending smoke against `https://qamera.ai/api/v1/plugin` on the live container (main checkout, not a worktree — per CLAUDE.md the bind-mount is the only path that resolves `QameraAi\Module\…`). Never in CI.
- **Secondary finding to carry upstream** (not fixed here): the successful resolve used the **raw product-image asset**, not the accepted stage-1 **output** asset — so today the acceptance vote does not influence which packshot the session sources. Whether it should is a saas-platform design question; surfacing `MISSING_CATALOG_ENTRY` as a friendlier flash would be a separate `harden-*` change if wanted.
