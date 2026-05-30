## ADDED Requirements

### Requirement: Product-detail page hosts a Qamera tab with ingest and browse

The module SHALL render a "Qamera" tab on the PrestaShop product-detail page via the `displayAdminProductsExtra` hook, hosting both the gallery ingest picker and the per-image browse accordion for that product. The tab's CSS/JS bundle SHALL be injected through `displayBackOfficeHeader` on the product-edit screen.

#### Scenario: Qamera tab appears on product edit

- **WHEN** the operator opens a product in the PrestaShop back office
- **THEN** a "Qamera" tab is present rendering the ingest picker and the browse accordion for that product

#### Scenario: Tab bundle loads only where needed

- **WHEN** the back-office product-edit screen is rendered
- **THEN** the module injects its tab CSS/JS bundle for that screen
- **AND** does not inject it on unrelated back-office screens

### Requirement: Browse rows expose a gallery-import action for generated assets only

Within the Qamera tab browse accordion, the module SHALL offer an "Add to product gallery" action on photo-shoot session images and on generated packshots, and SHALL NOT offer it for assets whose origin is the product gallery (the product/main image and packshots ingested from gallery images). Already-imported outputs SHALL surface as already-imported rather than as an eligible action.

#### Scenario: Generated session image is importable

- **WHEN** a photo-shoot session image is displayed in an expanded row
- **THEN** the module offers an "Add to product gallery" action for it

#### Scenario: Gallery-origin asset offers no import

- **WHEN** the displayed asset is the product/main image or a packshot ingested from a gallery image
- **THEN** the module does not offer an "Add to product gallery" action for it

#### Scenario: Already-imported output reflects state

- **WHEN** a session image has already been imported into the gallery
- **THEN** the action surfaces as already-imported and does not create a duplicate
