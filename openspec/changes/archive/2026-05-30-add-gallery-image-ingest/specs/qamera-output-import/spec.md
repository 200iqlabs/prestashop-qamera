## ADDED Requirements

### Requirement: A single job output can be imported from the browse view

The module SHALL expose a per-output "Add to product gallery" import keyed on `(qamera_job_id, output_index)`, triggered from the product-detail Qamera browse accordion, reusing the existing ledger, fresh-fetch, placement, and idempotency machinery. The single-output import SHALL apply the same eligibility rules as the per-job action — the output MUST be MIME type `image/*`, a `photo_shoot` job output is eligible unconditionally, and a `packshot` job output is eligible only when its review row is `voting='accepted'` — and SHALL place only the targeted output, leaving the job's other outputs untouched.

#### Scenario: Importing one session image places only that output

- **GIVEN** a completed `photo_shoot` job with three `image/*` outputs and no ledger rows
- **WHEN** the operator triggers "Add to product gallery" on the second session image (output_index 1) in the browse view
- **THEN** the module fetches the job fresh, downloads output_index 1, appends it as a new `ps_image` at end-of-gallery without setting cover or applying the watermark
- **AND** inserts a ledger row for `(qamera_job_id, 1)` only, leaving outputs 0 and 2 unimported

#### Scenario: Per-output import is idempotent

- **GIVEN** output_index 1 of a job already has a `ps_qamera_imported_output` ledger row
- **WHEN** the operator triggers the per-output import for that same output
- **THEN** no new `ps_image` is created and the action reports already-imported

#### Scenario: Gallery-origin asset is not importable

- **WHEN** the browse view displays a product/main image or a packshot ingested from a gallery image (no backing job output)
- **THEN** no per-output import action is offered, because the asset is not a job output and originates from the gallery

#### Scenario: Pending generated packshot output is not importable

- **GIVEN** a generated packshot whose review row is `voting='pending'`
- **WHEN** a per-output import is requested for it
- **THEN** the import is rejected with a "packshot not accepted" reason and no `ps_image` is written
