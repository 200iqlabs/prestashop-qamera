# Design — verify-packshot-acceptance-smoke

## Context

`add-packshot-acceptance-flow` (Phase 4.4) is implemented, merged (PR #26), unit-green 400/400, and archived (`openspec/changes/archive/2026-05-29-add-packshot-acceptance-flow/`). Its delta specs are synced into `openspec/specs/{packshot-acceptance,qamera-api-client,qamera-bo-ui}`. The only unfinished work was §9 — operator-driven, credit-spending end-to-end smoke against the live Qamera AI backend — which could not run because of two blockers: the flag was OFF upstream, and gated photo-shoots failed `MISSING_CATALOG_ENTRY`. The 2026-05-29 smoke advanced — but did not fully close — the diagnosis of the second blocker. The plugin-side catalog registration (`fix-packshot-catalog-registration`, landed + archived) is proven to unblock **stage-1** packshot jobs. The **photo-shoot** resolve, however, behaved inconsistently: product 32 resolved to the raw-image asset and completed; product 31 resolved to the accepted stage-1 job id (not an asset) and failed `MISSING_CATALOG_ENTRY` — both with an accepted packshot. `product_packshots` rows are upstream/invisible from the plugin, and product 32's pass may hinge on a manual raw-image registration done during the smoke. This change carves the verification out of the archived 4.4 change so it is a tracked, checkable artifact — re-scoped to determine whether the **landed** catalog-registration fix ALONE (no manual registration) closes §9.3, and to capture/raise the upstream resolve inconsistency.

This is a **verification-only** change: no plugin source is touched. The behavior under test is already specified and shipped.

## Goals / Non-Goals

**Goals:**
- Make the §9 e2e verification a first-class artifact with auditable evidence per scenario.
- Name the two external prerequisites explicitly as gating steps so a "skip" is impossible to do silently.
- Provide a runbook the operator can execute in one sitting once upstream is ready.

**Non-Goals:**
- Any plugin code change. The submitter already correctly omits `packshot_asset_id` on the photo-shoot branch (`PackshotJobSubmitter`).
- A plugin-side workaround for the upstream resolve bug (a friendlier `MISSING_CATALOG_ENTRY` flash) — deferred to a separate `harden-*` change if desired.
- Fixing anything upstream. The resolve fix + flag flip happen in `qamera-ai/saas-platform`; this change only consumes them.

## Decisions

### D1: Verification-only capability, not a modified one — RESOLVED → new `packshot-acceptance-smoke`
The behavior is already specced by `packshot-acceptance`/`qamera-api-client`/`qamera-bo-ui`. Re-opening those as MODIFIED would imply a behavior change (there is none) and pollute their archived deltas. A dedicated thin capability whose requirement is "the flow is proven e2e" keeps the verification auditable without restating shipped requirements. Alternative considered — leaving §9 in the archived change's `tasks.md` — rejected: archived tasks are not surfaced by `openspec status`/`list` and would never be revisited.

### D2: Prerequisites are gating TASKS, not assumptions — RESOLVED (one external, one internal-landed)
The two §0 prerequisites are modeled as explicit, checkable tasks (mirroring how `add-packshot-acceptance-flow` modeled its §0). One is external — the `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` flip (confirmed ON 2026-05-29). The other was originally framed as "the upstream resolve fix"; the 2026-05-29 run reframed it — the **internal, now-landed** `fix-packshot-catalog-registration` (the plugin creates the `product_packshots` row before submit) is proven for stage-1, but photo-shoot sufficiency is unconfirmed because the upstream resolve was observed inconsistent (31→job_id fail, 32→raw-image success). The gated scenarios (9.3 especially) cannot be declared green before the clean re-run proves the landed code alone completes a photo-shoot; the runbook blocks rather than producing a misleading result.

### D3: Evidence is recorded inline against each scenario — RESOLVED
Each smoke task records the concrete artefacts it produced (stage-1 `job.id`, webhook `delivery_id`, the `POST /plugin/packshots` result, the asset id the backend resolved for the photo-shoot, screenshot/log refs). This turns "checked the box" into an auditable trail and lets a later reader confirm 9.3 succeeded via the plugin's own registration (no manual `POST /plugin/packshots`) and note which asset the resolve used (raw image vs accepted output — the secondary upstream finding).

## Risks / Trade-offs

- **A deployed build predates `fix-packshot-catalog-registration`** → no `product_packshots` row is created and 9.3 stays blocked (`MISSING_CATALOG_ENTRY`). Mitigation: D2 keeps it explicitly BLOCKED with the recorded reason; the change stays open (not archived) until it genuinely passes on a build with the fix.
- **Credit spend on the live account** → smoke generates real jobs. Mitigation: minimal product set (one product is enough for 9.1–9.4); operator-driven, never automated/CI.
- **Flag ON changes behavior for all plugin photo-shoots on that account** → not a smoke artifact but a real cutover. Mitigation: out of scope here; coordinate via saas-platform release notes (already the documented cutover path).

## Migration Plan

Not applicable — no deploy, no schema, no rollback. The "deploy" is the upstream flag flip + resolve fix, owned by saas-platform. This change is archived only once all smoke scenarios pass with recorded evidence.

## Open Questions

- **Should the photo-shoot source the accepted stage-1 output asset, or the raw product-image asset?** The 2026-05-29 pass resolved to the **raw image** asset, so the acceptance vote does not currently influence the session source. This is a saas-platform design/behavior question (related to bidirectional materials sync), out of scope for this verification — flagged so it is not lost.
