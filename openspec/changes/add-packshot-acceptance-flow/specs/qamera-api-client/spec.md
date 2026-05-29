<!-- DRAFT — finalize against the live API once the prerequisite fixes deploy. -->

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

`QameraApiClient` SHALL expose `acceptJob(string $id): JobDto` and `rejectJob(string $id): JobDto`, issuing `POST /jobs/{id}/accept` and `POST /jobs/{id}/reject` respectively, decoding the returned `JobDto` (which carries `voting`/`votingAt`). Failures SHALL raise the existing typed `ApiException` hierarchy; a `422` whose `ErrorEnvelope.code` is `packshot_not_approved` SHALL remain inspectable by the caller.

#### Scenario: accept posts to the accept endpoint
- **WHEN** `acceptJob('j1')` is called
- **THEN** the client issues `POST /jobs/j1/accept` and returns the decoded `JobDto`

#### Scenario: reject posts to the reject endpoint
- **WHEN** `rejectJob('j1')` is called
- **THEN** the client issues `POST /jobs/j1/reject` and returns the decoded `JobDto`
