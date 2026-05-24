## MODIFIED Requirements

### Requirement: Configuration page persists module credentials and defaults

The back-office configuration page SHALL persist five settings via `Configuration::updateValue` — API base URL, API key, webhook secret, auto-register-products toggle, and sync batch size — and SHALL render their current values on each load. The page MUST be reachable from the module's `getContent` redirect and via the Symfony route `_qameraai_admin_configuration`. The page SHALL ALSO expose a **functional** "Test connection" button that, when clicked, posts to a dedicated admin route (`_qameraai_admin_test_connection`) which calls `QameraApiClient::me()` and renders the resulting `account_name`, `credits_balance`, `subscription_plan`, `installation.platform`, and `installation.status` in a results panel inline on the page. The Test Connection action MUST NOT modify any stored configuration; only the existing Save submission does. The button's POST SHALL be CSRF-protected by Symfony's default form token.

#### Scenario: First-time configuration

- **WHEN** an administrator submits the form with non-empty API key and webhook secret values
- **THEN** the `QAMERAAI_API_KEY` and `QAMERAAI_WEBHOOK_SECRET` configuration keys are stored with the submitted values, the page reloads, and the secrets render in masked form

#### Scenario: API base URL persists across reloads

- **WHEN** the administrator changes the API base URL and saves
- **THEN** subsequent visits to the configuration page show the updated URL in the input field

#### Scenario: Submit without changing masked secrets

- **WHEN** the administrator submits the form without editing the masked-secret fields
- **THEN** the previously saved secrets remain unchanged in `Configuration` storage

#### Scenario: Auto-register toggle persists boolean

- **WHEN** the administrator toggles auto-register-products and submits
- **THEN** `QAMERAAI_AUTO_REGISTER_PRODUCTS` stores `'1'` or `'0'` matching the checkbox state

#### Scenario: Test connection happy path

- **WHEN** the administrator clicks Test Connection with valid stored credentials
- **THEN** the results panel renders the account name, credits balance, subscription plan, installation platform, and installation status from `/me`

#### Scenario: Test connection auth failure

- **WHEN** the stored API key is invalid and the administrator clicks Test Connection
- **THEN** the results panel renders the localized `message_i18n.en` (or PL/UK when matching) from the `AuthException`'s envelope, and no configuration value is altered

#### Scenario: Test connection does not overwrite stored secrets

- **WHEN** the administrator types a new API key into the form, then clicks Test Connection (instead of Save)
- **THEN** the stored `QAMERAAI_API_KEY` value is NOT replaced — the masked stored value is still what `Test Connection` exercised, not the typed-but-unsaved value
