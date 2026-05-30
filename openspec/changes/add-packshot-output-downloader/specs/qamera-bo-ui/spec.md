## ADDED Requirements

### Requirement: Jobs history grid exposes a per-row Download-to-shop action

The Jobs history grid SHALL render a "Download to shop" action per row whose state follows the output-import eligibility rules. For a `status='completed'` row with at least one `image/*` output the action SHALL be:

- **active** when `job_type='photo_shoot'`, or when `job_type='packshot'` and the row's review record (`ps_qamera_packshot_review`, by `qamera_job_id`) is `voting='accepted'`;
- **absent or disabled** with an explanatory hint when `job_type='packshot'` and the review is `pending`/`rejected`/absent (the operator must accept in the Packshots view first);
- **absent** when the row is not completed or has no image output;
- **a terminal "imported ✓" indicator** (linking to or naming the created `id_image`) when every image output of the row already has a ledger row.

Clicking the active action SHALL POST to a dedicated BO import endpoint and, on success, update the row in place to the imported state without a full page reload. The action SHALL NOT be rendered in the Packshots review view (that view lists only `pending` rows, from which accepted packshots have already departed). The action JS and Twig live in the shared `jobs_history.*` assets.

#### Scenario: Active action on a completed photo-shoot row

- **GIVEN** a Jobs history row `job_type='photo_shoot'`, `status='completed'` with an image output and no ledger rows
- **WHEN** the grid renders
- **THEN** the row shows an active "Download to shop" action

#### Scenario: Disabled action on a pending packshot row

- **GIVEN** a completed `job_type='packshot'` row whose review is `voting='pending'`
- **WHEN** the grid renders
- **THEN** the row shows no active import action and a hint to accept the packshot in the Packshots view first

#### Scenario: Imported row shows terminal state

- **GIVEN** a completed image row whose every image output has a ledger row
- **WHEN** the grid renders
- **THEN** the row shows an "imported ✓" indicator naming the created image, not an active action

#### Scenario: In-place update after import

- **GIVEN** an active "Download to shop" action on a row
- **WHEN** the operator clicks it and the import succeeds
- **THEN** the row updates in place to the imported state without a full page reload
