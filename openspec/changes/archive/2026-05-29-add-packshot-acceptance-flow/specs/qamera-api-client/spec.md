<!-- Finalized 2026-05-29: prerequisites fix-webhook-payload-contract (#22),
     fix-packshot-asset-id-mismatch (#21), fix-packshot-catalog-registration (#25),
     fix-webhook-job-error-string (#24) all merged; contract runtime-confirmed
     (accept/reject = 204; outputs[0].url = signed preview, smoke product 31). -->

## ADDED Requirements

### Requirement: Submit request carries an optional job_type and an optional packshot_asset_id

`SubmitJobRequest` SHALL expose an optional `jobType` (string, e.g. `packshot` | `photo_shoot`); `toPayload()` SHALL emit `job_type` only when set (absent → upstream default `photo_shoot`). `Subject.packshotAssetId` SHALL become nullable; `toPayload()` SHALL omit `packshot_asset_id` when null.

The submitter SHALL enforce the upstream constraints: when `jobType='packshot'`, every `Subject` SHALL carry a non-null `packshotAssetId` (the source `qamera_asset_id`) AND `autoRegisterPackshot=true`; when `jobType='photo_shoot'`, subjects SHALL omit `packshotAssetId` and `autoRegisterPackshot` (the backend resolves the accepted packshot from `product_ref`).

#### Scenario: packshot submission sends source asset_id and auto_register
- **WHEN** a `job_type='packshot'` request is built for a synced product
- **THEN** the body carries `job_type='packshot'`, each subject has `packshot_asset_id=<qamera_asset_id>` and `auto_register_packshot=true`

#### Scenario: photo_shoot submission omits packshot_asset_id
- **WHEN** a `job_type='photo_shoot'` request is built
- **THEN** the body carries `job_type='photo_shoot'` and no `packshot_asset_id` on its subjects

### Requirement: Client exposes job accept and reject

`QameraApiClient` SHALL expose `acceptJob(string $id): void` and `rejectJob(string $id): void`, issuing `POST /jobs/{id}/accept` and `POST /jobs/{id}/reject` respectively. The endpoints return **`204 No Content`** (verified against `plugin-v1.yaml` — pure metadata, no body), so the methods decode nothing and simply return on a 2xx; the caller updates the local `ps_qamera_packshot_review.voting` itself. Failures SHALL raise the existing typed `ApiException` hierarchy. In particular a **`409 job_not_completed`** (voting is only allowed on a `completed` job) SHALL surface as the typed exception — naturally avoided since review rows are born from a `job.completed` delivery, but handled defensively. A `422` whose `ErrorEnvelope.code` is `packshot_not_approved` SHALL remain inspectable by the caller.

#### Scenario: accept posts to the accept endpoint and returns on 204
- **WHEN** `acceptJob('j1')` is called and the API returns `204`
- **THEN** the client issues `POST /jobs/j1/accept` and returns without error (no body decoded)

#### Scenario: reject posts to the reject endpoint and returns on 204
- **WHEN** `rejectJob('j1')` is called and the API returns `204`
- **THEN** the client issues `POST /jobs/j1/reject` and returns without error

#### Scenario: voting a non-completed job raises the typed 409
- **WHEN** `acceptJob('j1')` is called and the API returns `409` `job_not_completed`
- **THEN** the client raises the typed `ApiException` (no local voting change)
