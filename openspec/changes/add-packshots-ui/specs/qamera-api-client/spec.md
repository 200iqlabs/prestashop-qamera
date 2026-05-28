## ADDED Requirements

### Requirement: listMannequinModels wraps GET /models

`QameraApiClient::listMannequinModels(): array` SHALL GET `/models` and decode the response body's
`models` wrapper key into an array of `MannequinModel` DTOs. The endpoint follows the same retry policy,
header builder, and error mapping as the other reference-data list endpoints (`listAiModels`,
`listSceneries`, `listPresets`, `listAspectRatios`).

`MannequinModel` SHALL carry at least: `id` (string), `name` (string), `thumbnail` (string|null), `source`
(string — `'marketplace'` or `'account'`), `status` (string), `createdAt` (string ISO8601).

#### Scenario: GET /models returns mannequin DTOs

- **GIVEN** upstream returns `{"models":[{"id":"m1","name":"Studio Model","thumbnail":"https://.../m1.jpg","source":"marketplace","status":"active","created_at":"2026-05-01T10:00:00Z"}]}`
- **WHEN** the client calls `listMannequinModels()`
- **THEN** the result is `[MannequinModel{id:'m1', name:'Studio Model', thumbnail:'https://.../m1.jpg', source:'marketplace', status:'active', createdAt:'2026-05-01T10:00:00Z'}]`

#### Scenario: Wrong wrapper key surfaces malformed-response error

- **GIVEN** upstream returns `{"items":[...]}` for `GET /models`
- **WHEN** the client calls `listMannequinModels()`
- **THEN** the client throws `ValidationException` identifying `models` as the missing key

### Requirement: Reference data is served through a TTL cache decorator keyed by API key hash

A `CachedReferenceClient` decorator SHALL wrap the six reference-data methods (`listAiModels`,
`listSceneries`, `listMannequinModels`, `listPresets`, `listAspectRatios`, `getPricing`) of
`QameraApiClient`. Cache entries SHALL be keyed by
`qameraai:ref:<endpoint>:<sha256(api_key)[0:16]>`. TTL per endpoint:

| Endpoint           | TTL    |
|--------------------|--------|
| `/ai-models`       | 300s   |
| `/sceneries`       | 300s   |
| `/models`          | 300s   |
| `/presets`         | 300s   |
| `/aspect-ratios`   | 3600s  |
| `/pricing`         | 300s   |

Backing store: `\Cache::getInstance()` with a filesystem fallback under
`_PS_CACHE_DIR_ . 'qameraai/reference/'`. The cache decorator SHALL NOT be used by tests that exercise
`QameraApiClient` directly — those still hit the underlying client via `MockHandler`. The cache decorator
SHALL be the only injection point used by BO controllers for reference data.

Cache invalidation on API key rotation is out of scope for v1; stale entries TTL out within at most 1 hour
(the `/aspect-ratios` ceiling). No security impact — cached values contain public reference data scoped to
the previous account.

#### Scenario: Two calls within TTL hit the cache

- **GIVEN** `listAiModels()` is called once and returns a response
- **WHEN** it is called again 100s later with the same API key
- **THEN** the second call returns the cached response
- **AND** no HTTP request is made

#### Scenario: Different API keys do not share cache

- **GIVEN** the decorator caches a response under API key A
- **WHEN** the same endpoint is called with API key B
- **THEN** an HTTP request is made and a new cache entry is stored

#### Scenario: TTL expiry triggers refresh

- **GIVEN** a cached `/pricing` response was stored 301s ago
- **WHEN** `getPricing()` is called
- **THEN** a fresh HTTP request is made and the cache is updated
