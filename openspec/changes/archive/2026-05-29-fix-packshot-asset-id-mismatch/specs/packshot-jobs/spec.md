## ADDED Requirements

### Requirement: Subject.packshot_asset_id is sourced from the upstream storage asset id

When `PackshotJobSubmitter` builds a `Subject` for a synced product, it SHALL set `Subject.packshotAssetId` from the link's `qameraAssetId` (the storage `asset_id` minted by `requestUpload()` and persisted by `ProductImageSyncService`), NOT from any logical image id. This is the identifier the upstream uses to resolve the source upload; sending anything else causes `generation_failed / "No source upload found"`.

`SyncedProductLink` SHALL expose the value via a `qameraAssetId: ?string` accessor (renamed from the former `qameraImageId`). The submitter's eligibility filter already drops links where `canGenerate()` is false; because `canGenerate()` now requires a non-empty `qameraAssetId`, no `Subject` SHALL ever be built with an empty `packshot_asset_id`.

#### Scenario: Submitter sends the storage asset id, not the logical image id

- **GIVEN** a synced `SyncedProductLink` for shop=1, product=42 with `qameraAssetId='asset-uuid'` and `analysisStatus='described'`
- **WHEN** `PackshotJobSubmitter` builds the outgoing `Subject` for that product
- **THEN** the `Subject.packshot_asset_id` in the `POST /jobs` body equals `'asset-uuid'`

#### Scenario: A link without a storage asset id is not submitted

- **GIVEN** a `SyncedProductLink` whose `qameraAssetId` is NULL (e.g. migrated, awaiting re-sync)
- **WHEN** the operator's bulk form includes that product
- **THEN** the link fails the `canGenerate()` eligibility check and is counted as skipped — no `Subject` is built and no empty `packshot_asset_id` is sent upstream
