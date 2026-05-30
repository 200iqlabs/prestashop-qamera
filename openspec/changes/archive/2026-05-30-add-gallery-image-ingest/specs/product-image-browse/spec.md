## ADDED Requirements

### Requirement: Per-image browse view on the product-detail Qamera tab

The module SHALL display, for the current product, one accordion row per Qamera product image, each showing the image thumbnail, its analysis status, and the counts of its packshots and photo-shoot session images. Rows SHALL be expandable to reveal the packshots and session images.

#### Scenario: Product images listed as accordion rows

- **WHEN** the operator opens the Qamera tab on a product known to Qamera
- **THEN** the module fetches the product detail and renders one collapsed row per image showing thumbnail, analysis badge, packshot count, and session-image count

#### Scenario: Expanding a row reveals packshots and session images

- **WHEN** the operator expands an image row
- **THEN** the module shows a strip of that image's packshots and a strip of its photo-shoot session images, each as a thumbnail openable in a lightbox

#### Scenario: Product not yet in Qamera

- **WHEN** the product has no Qamera record
- **THEN** the module shows an empty-state inviting the operator to ingest gallery images

### Requirement: Packshots grouped under their source image

The module SHALL associate each packshot with its source image by matching the packshot's `source_image_id` to the image's id, and SHALL display the packshot under that image.

#### Scenario: Packshot appears under its source image

- **WHEN** a packshot's `source_image_id` equals an image's id
- **THEN** that packshot is shown in the expanded strip for that image

### Requirement: Session images resolved from photo-shoot jobs lazily

The module SHALL obtain photo-shoot session images from job outputs, mapping each photo-shoot job to an image via `job.packshotAssetId` → packshot `assetId` → packshot `sourceImageId`. The jobs walk SHALL run only when a row is expanded, page through jobs up to a bounded cap, filter client-side by product and `photo_shoot` type, and surface a notice when the cap is reached before exhaustion.

#### Scenario: Session images shown on expand

- **WHEN** the operator expands an image row that has photo-shoot session outputs
- **THEN** the module walks jobs (lazily), maps photo-shoot job outputs back to the image, and renders them as session-image thumbnails

#### Scenario: Jobs walk respects the cap

- **WHEN** the bounded jobs cap is reached before all jobs are paged
- **THEN** the module shows the session images found so far and a "showing recent sessions" notice

### Requirement: Every displayed object renders a thumbnail

The module SHALL render a thumbnail for every product image, packshot, and session image, sourcing it as follows: a session image from its signed job-output URL; a product image from its local PrestaShop file resolved from the `ps:<shop>:<prod>:image:<id>` external_ref; an ingested packshot (no generating job) from its source image's local thumbnail; a generated packshot from its generating job's output URL; a synthesized image (no PrestaShop origin) from a related packshot's thumbnail, falling back to a labelled placeholder.

#### Scenario: Session image thumbnail

- **WHEN** a session image is displayed
- **THEN** its thumbnail is the signed URL from the job output

#### Scenario: Product image thumbnail from local file

- **WHEN** a product image whose external_ref encodes a PrestaShop image id is displayed
- **THEN** its thumbnail is the local PrestaShop image file

#### Scenario: Generated packshot thumbnail from its job

- **WHEN** a packshot with a generating job is displayed
- **THEN** its thumbnail is the output URL of that generating job

#### Scenario: Synthesized image without local file

- **WHEN** an image has no PrestaShop origin and therefore no local file
- **THEN** the module shows a related packshot's thumbnail, or a labelled placeholder if none exists

### Requirement: Truncated upstream arrays are surfaced, not hidden

When the product-detail response indicates its embedded images or packshots were truncated, the module SHALL render the returned rows and display a notice that some rows are not shown.

#### Scenario: Truncation notice shown

- **WHEN** the product-detail response sets `imagesTruncated` or `packshotsTruncated`
- **THEN** the module renders the returned rows and shows a "truncated — some not shown" notice

### Requirement: Generated assets can be added to the product gallery, gallery-origin assets cannot

The module SHALL offer, per displayed generated asset (photo-shoot session image, or generated packshot), an action to add that asset back into the PrestaShop product gallery, delegating to the per-output import machinery. The module SHALL NOT offer this action for assets that originate from the product gallery — the product/main image and packshots ingested from gallery images — to avoid re-importing an asset into the gallery it came from.

#### Scenario: Session image offers add-to-gallery

- **WHEN** a photo-shoot session image is displayed
- **THEN** the module offers an action to add it to the product gallery

#### Scenario: Ingested packshot offers no add-to-gallery

- **WHEN** a packshot ingested from a gallery image (no generating job) is displayed
- **THEN** the module offers no add-to-gallery action for it, since it already originates from the gallery

#### Scenario: Generated packshot honors acceptance before import

- **WHEN** the operator adds a generated packshot to the gallery
- **THEN** the import proceeds only if the packshot is accepted, otherwise it is rejected with a not-accepted reason

### Requirement: Browse requires only read scope

The module SHALL render the browse view using read access and SHALL NOT require `plugin.catalog:write` to display images, packshots, or session images.

#### Scenario: Read-only operator can browse

- **WHEN** the installation holds `plugin.catalog:read` but not `plugin.catalog:write`
- **THEN** the browse view renders normally while ingest actions remain blocked
