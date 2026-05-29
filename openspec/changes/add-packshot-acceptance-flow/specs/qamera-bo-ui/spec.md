<!-- DRAFT — finalize wording/scenarios after the prerequisite fixes deploy. -->

## ADDED Requirements

### Requirement: Dedicated "Packshots — review" back-office view

The module SHALL expose a back-office view listing `ps_qamera_packshot_review` rows with `voting='pending'`, each showing the preview image (`asset_url`), the localized product name, and Accept / Reject affordances. Accept/Reject SHALL invoke the vote path (`QameraApiClient::acceptJob`/`rejectJob` keyed on `qamera_job_id`) and, on success, remove the row from the pending list. The view is the voting surface only — it does not submit jobs.

#### Scenario: Pending packshots are listed with vote affordances
- **GIVEN** two `ps_qamera_packshot_review` rows with `voting='pending'`
- **THEN** the view renders both with a thumbnail, product name, and ✓/✗ controls

#### Scenario: Accepting removes the row from the pending list
- **WHEN** the operator accepts a row and the API returns 2xx
- **THEN** the row's local `voting` becomes `accepted` and it no longer appears in the pending list

### Requirement: Products grid splits Generate into two gated actions

The Products grid SHALL offer two actions per row:
- **Generate packshot** (stage 1) — enabled iff `qamera_asset_id` is present AND `analysis_status='described'` (the existing Generate-readiness gate);
- **Generate photo-shoot** (stage 4) — enabled iff the row's `product_ref` has at least one `ps_qamera_packshot_review` row with `voting='accepted'` (the grid JOINs the review table for this signal).

A row with a pending (un-accepted) packshot SHALL render "Generate photo-shoot" disabled with a hint to accept a packshot first. The gate is enforced client-side regardless of the server `PLUGIN_PHOTO_SHOOT_GATE_ENABLED` flag.

#### Scenario: Synced+described product can generate a packshot
- **GIVEN** a row with `qamera_asset_id` set and `analysis_status='described'`
- **THEN** "Generate packshot" is enabled

#### Scenario: Accepted packshot enables photo-shoot
- **GIVEN** a row whose `product_ref` has a `ps_qamera_packshot_review` row `voting='accepted'`
- **THEN** "Generate photo-shoot" is enabled

#### Scenario: Pending packshot keeps photo-shoot disabled with a hint
- **GIVEN** a row whose only review state is `voting='pending'`
- **THEN** "Generate photo-shoot" is disabled with a hint to generate+accept a packshot first
