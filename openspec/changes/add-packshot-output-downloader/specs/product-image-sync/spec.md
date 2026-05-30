## MODIFIED Requirements

### Requirement: Primary image resolution prefers cover image with a deterministic fallback chain

The module SHALL select the image to upload using `PrimaryImageResolver::resolve(int $idProduct, ?int $hintIdImage): ?int` (returns the resolved image id, NOT a PrestaShop `Image` instance — PS's `Image::getCover` and `Image::getImages` return associative arrays, so the resolver returns the `id_image` int extracted from those arrays). The resolver SHALL try in order: (1) `Image::getCover($idProduct)` if it returns a non-empty array, take its `id_image`; (2) the `$hintIdImage` from the hook params if it points to a valid image for that product; (3) the first image returned by `Image::getImages($idLang, $idProduct)` ordered by position, where `$idLang` is the shop's default language id resolved via `Configuration::get('PS_LANG_DEFAULT', null, null, $idShop)` — same convention as the Phase-2 snapshot writer. If all three return nothing, the resolver SHALL return null and the sync service SHALL early-return without touching the bookkeeping row's status (a missing image is not an error of the upstream sync, it is missing input).

The sync service SHALL NOT upload an image that is recorded in the `ps_qamera_imported_output` ledger (an image of Qamera origin, written into the gallery by the output-import flow). When the resolved primary `id_image` is present in that ledger, the sync service SHALL treat it as no eligible input and skip the upload, identical to the null-resolution early-return (bookkeeping row status untouched). This prevents a generated scene that was imported into the gallery from being re-uploaded to Qamera as a source image for the next generation. This exclusion is belt-and-suspenders over the existing registered-with-asset re-sync no-op: it also covers the narrow case of a product whose bookkeeping row is registered but lacks a stored asset (recovery path), where the re-sync no-op does not apply.

#### Scenario: Product with a cover image

- **GIVEN** product 42 has three images and image 100 is set as cover
- **WHEN** `PrimaryImageResolver::resolve(42, 99)` is called (hint pointing to image 99, a non-cover image)
- **THEN** the resolver returns `100` (cover image id wins over hint)

#### Scenario: Product without cover, hint valid

- **GIVEN** product 42 has two images and no cover is set; the operator just uploaded image 99
- **WHEN** `PrimaryImageResolver::resolve(42, 99)` is called
- **THEN** the resolver returns `99` (hint fallback)

#### Scenario: Product with no images

- **WHEN** `PrimaryImageResolver::resolve(42, null)` is called and the product has zero images
- **THEN** the resolver returns `null` and the sync service skips the registration entirely without changing the bookkeeping row

#### Scenario: Resolved primary image is a Qamera-imported scene

- **GIVEN** product 42's only image is `id_image=200`, written into the gallery by the output-import flow and recorded in `ps_qamera_imported_output`
- **WHEN** an `actionWatermark` sync resolves the primary image to `200`
- **THEN** the sync service skips the upload (no `registerImage` call) and leaves the bookkeeping row status unchanged, exactly as for a null resolution
