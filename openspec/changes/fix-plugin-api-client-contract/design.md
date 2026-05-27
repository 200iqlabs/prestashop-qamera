## Context

Phase-3 smoke wykrył jedno mismatch (`/assets/upload` body shape). Pełny audyt (general-purpose subagent porównał 15 metod `QameraApiClient.php` z `qamera-ai/saas-platform/apps/web/app/api/v1/plugin/_lib/server/schemas.ts` + route handlers per endpoint) wyłapał że **14 z 15 metod jest zerwane**. `proposal.md` listuje pełną macierz. Jedyne działające: `GET /me` (sprawdzone w smoke) i `DELETE /products/{idOrRef}` (brak body, dispatch tolerancyjny na każdym 2xx).

Operator wybrał scope opcję "jeden mega-change: regeneruj cały klient" (vs "tylko hot-path", "dwa changey", "per-endpoint"). Konsekwencja: ten change rewrite'uje 14 metod naraz, plus 6+ nowych sub-DTO, plus parametryzuje `sendList` po wrapper key, plus pełen zestaw contract-test fixtures snapshotów z upstream zod.

Rozmiar zmiany jest duży (~1500 LOC), ale skoncentrowany na jednym capability (`qamera-api-client`) i jednym katalogu (`src/Api/`). PR #9 (Phase 3) sit-time po mergu fixu = czas na rebase wg pkt 5 niżej.

## Goals / Non-Goals

**Goals:**
- 14 metod klienta SHALL serializować/deserializować shape matchujący aktualny `schemas.ts`. Smoke przeciw real upstream SHALL przechodzić end-to-end dla: `/me`, `/assets/upload`, `/images` (single bulk), `/packshots` (single bulk), `/ai-models`, `/sceneries`, `/presets`, `/aspect-ratios`, `/pricing`.
- `sendList` SHALL przyjmować wrapper key jako parametr; każda list-method klienta SHALL podawać swój klucz.
- Contract test SHALL walidować shape dla każdego endpointu w scope-ie przeciw zamrożonemu JSON fixture z `_commit` header capturowanym z `saas-platform` repo.
- Wszystkie istniejące unit testy (`QameraApiClientTest`, `JsonDecoderTest`) SHALL przejść po regenerze sygnatur i DTO. Casey które zakładają stare nazwy pól (`source_url`, `previewUrl`, `title`, `resultUrls`, …) SHALL być usunięte.

**Non-Goals:**
- Pokrycie 11 nieobsługiwanych endpointów upstream (lista w `proposal.md §F`). Każdy doda się gdy faza tego wymaga.
- Multipart upload mode dla `/assets/upload`. Plugin używa tylko presigned.
- Generated client z OpenAPI / `zod → PHP`. Upstream nie publikuje OpenAPI; ten temat wraca przy Fazie 5.
- Webhook handling (separate from `/webhooks/{delivery_id}/replay`).
- Wsparcie wielu API key per shop (multistore). Dalej single key per install (Phase-1 decision).

## Decisions

### 1. Single-in / bulk-pod-spodem dla `/images` i `/packshots`

| Opcja | Plus | Minus |
|---|---|---|
| **A. Single-in API, klient wrappuje** | Caller (Phase 3 service) widzi prosty `registerImage($single) → ImageResponse`. Nie musi się przejmować bulkiem. | Klient ma logikę pakowania/rozpakowywania. Bulk-with-partial-error nie wyraźnie ergonomiczne. |
| B. Bulk-in natywnie | Spójność z upstreamem. Łatwy do rozszerzenia. | Wszystkie callery wrappują pojedyncze itemy. |
| C. Oba | Maksymalna elastyczność. | Dwie metody do utrzymania, jedna kontrakt. |

**Wybór: A.** Phase 3 i 4 są single-image / single-packshot per hook invocation. Bulk będzie miało sens dopiero przy cron-resync (przyszła faza); wtedy dodajemy `registerImages(array<Request>)` jako drugi entry-point.

Implementacja: `registerImage($request)` woła `dispatch('POST', '/images', ['images' => [$request->toPayload()]])`, czyta `['results']`, asserts `count($results) === 1`, decoduje pierwszy item jako `ImageResponse`. Empty `results` LUB size > 1 → `ValidationException` z komunikatem identyfikującym nieoczekiwany rozmiar (np. `"unexpected results size: 2, expected 1"`). NIE używamy `ValidationException::malformedResponse('results[0]')`, bo jego komunikat ("missing required field …") jest mylący dla przypadku "too many". Implementacja MOŻE dodać dedykowany factory `ValidationException::unexpectedResultsSize(int $got, int $expected)` — to detail implementacji, nie kontraktu.

Świadomie wybieramy **throw** zamiast "take first and log warning". Wysłaliśmy bulk-of-1, upstream gwarantuje bulk-of-1; każdy inny rozmiar to bug po stronie serwera, nie sytuacja do zamiatania pod dywan.

### 2. `external_ref` w `RegisterImageRequest`/`RegisterPackshotRequest` — caller-supplied

Upstream `RegisterImageSchema` wymaga `external_ref: string(1..200)` — stable identifier per `(installation, external_ref)` używany do idempotency lookup ("repeat z tym samym `external_ref` zwraca `status:'existing'`"). Caller decyduje co to jest.

W Phase 3 service to będzie najprawdopodobniej `qamera_product_ref + ':' + id_image` (np. `ps:1:42:99`) — żeby resize-thumbnails resfire'owały hooka w to samo `external_ref`. Phase 3 spec to udokumentuje w jego własnym change'u (`add-product-image-sync`). W tym change'u tylko definiujemy że klient akceptuje `external_ref` jako required string(1..200) bez interpretacji.

### 3. Wrapper key parametryzacja w `sendList`

Każdy list endpoint upstream ma inny wrapper key — `ai_models`, `sceneries`, `presets`, `aspect_ratios`, `pricing`, `jobs`, `items` (dla `/products`). Hard-coded `items` w Phase-1 łamie 6 endpointów.

Nowa sygnatura:
```php
private function sendList(string $method, string $path, string $wrapperKey, string $elementClass): array
```

Każda list-method klienta podaje swój klucz. Dla `/pricing` (które zwraca `{pricing:[…], currency:"credits"}`) NIE używamy `sendList` — `getPricing` parsuje response do `Pricing` DTO który ma list pricing + currency field.

### 4. `/jobs` POST session-lifecycle shape — pełen regen DTO

Upstream `SubmitJobRequestSchema` zawiera zagnieżdżone `session_config` i `subjects[]`. Drzewo:

```
SubmitJobRequest
├── session_config: SessionConfig { aspect_ratio, model_id?, scenery_id?, preset_id?, suggestions? }
├── subjects: Subject[]
│   └── Subject { packshot_asset_id, product_label, product_ref, images_count, ai_model, reference_asset_ids?, provider_settings?, product_name?, product_specific_category? }
├── callback_url?
├── external_metadata?
└── priority?
```

Response:
```
SubmitJobResponse {
  order_id,
  status,
  subjects: SubmitJobResponseSubject[]
}
SubmitJobResponseSubject { product_ref, job_ids[] }
```

PHP odzwierciedlamy 1:1. `SessionConfig` i `Subject` jako osobne `final` DTO w `src/Api/Dto/`. Konstruktor `SubmitJobRequest` przyjmuje `SessionConfig + array<Subject> + optional<…>`. Walidacja: `subjects` 1..100 (upstream `.max(100)`), `session_config.aspect_ratio` musi być wartością ze `AspectRatioSchema` allowlist (1:1, 4:5, 9:16, 16:9, 3:4 — re-exposed w PHP jako `SessionConfig::ALLOWED_ASPECT_RATIOS`). Pełna lista deviacji od pierwszego szkicu specu (gdzie błędnie podaliśmy 1..1000, `?array<string> suggestions`, `?string priority` itd.) udokumentowana w `tasks.md §20`.

`getJob`/`listJobs` parsują `JobDto` z 16 pól. `outputs: JobOutput[]` jako sub-DTO. Status enum: `pending|in_progress|completed|failed|retry_pending|cancelled|expired`.

### 5. Phase-3 PR #9 rebase strategy

Po mergu tego fixu w main, PR #9 (`add-product-image-sync`) wymaga rebase. Konflikty:

- **`src/Api/QameraApiClient.php`** — Phase 3 nie modyfikuje (drop final), fix robi pełny rewrite. **Resolution:** fix wygrywa, drop-final z Phase 3 jest zachowany (klient `class QameraApiClient` bez `final` po obu mergach).
- **`src/Api/Dto/RegisterImageRequest.php`** — Phase 3 dodaje `?ProductMetadata`, fix usuwa `source_url`/`title` i dodaje `external_ref`/`asset_id`. **Resolution:** zachować obie zmiany — final DTO ma `external_ref`, `product_ref`, `asset_id`, `?ProductMetadata`.
- **`src/Api/Dto/ImageResponse.php`** — Phase 3 dodaje `?productId`, fix robi pełny refactor (`externalRef`, `productId`, `imageId`, `status`). **Resolution:** fix wygrywa, Phase 3 zmiana wpada do śmietnika (`productId` już jest).
- **`src/Sync/PresignedImageUploadStrategy.php`** (Phase 3-only) — sygnatura `uploadImage(localPath)` zmienia się na `uploadImage(localPath, filename, contentType, sizeBytes)` żeby przekazać metadane do `requestUpload`. Plik Phase-3-only — Phase 3 fix-up w rebase.
- **`src/Sync/ProductImageSyncService.php`** (Phase 3-only) — build `RegisterImageRequest` z `external_ref` + `asset_id` (z `requestUpload` response) zamiast `source_url`. Phase 3 fix-up w rebase.
- **`openspec/changes/add-product-image-sync/specs/qamera-api-client/spec.md`** — Phase 3 delta na `product_metadata` zostaje, ale tekst requirementów Reference'ujący `source_url` musi się zaktualizować pod `asset_id`. Phase 3 fix-up w rebase.

Phase 3 owner zdecyduje czy rebase robimy w tej samej sesji co merge fixu, czy później. Spec niczego o tym nie mówi.

### 6. Contract test approach — snapshot vs generated

| Opcja | Plus | Minus |
|---|---|---|
| **A. Zamrożone JSON snapshots** | PHP-only, brak Node dependency. Łatwo dodać do CI. Wykrywa nieoczekiwane zmiany w upstream tak długo jak operator ręcznie odświeża snapshot przy każdej zmianie. | Snapshot driftuje od upstream zod. Wymaga dyscypliny. |
| B. Live zod via `pnpm tsx` w CI | Zawsze aktualne. | Wymaga Node w CI matrixie. Cross-repo dependency. Slow CI. |
| C. Generated PHP fixtures z `zod-to-json-schema` | Programowo świeże. | Wymaga build steps po stronie saas-platform repo i synchronizacji. |

**Wybór: A.** Smoke wyłapuje drift natychmiastowo (HTTP 400 invalid_input → testy padają, łapiemy w PR). Snapshot to "ostatni zwery­fikowany shape" — primary purpose to wyłapanie regresji po naszej stronie (np. ktoś usunie pole), nie po upstream.

Format snapshotu (JSON):
```json
{
  "_source": "qamera-ai/saas-platform:apps/web/app/api/v1/plugin/_lib/server/schemas.ts",
  "_commit": "<git rev short>",
  "_captured_at": "2026-05-26",
  "_note": "<short note about endpoint behavior, np. wrapper key, nullability semantics>",
  "request": { /* przykład valid request body */ },
  "response_2xx": { /* przykład valid 2xx body */ },
  "response_4xx": { /* przykład envelope dla typowego błędu, gdzie ma sens */ }
}
```

Fixtury per endpoint w scope-ie (15 plików): `me`, `assets-upload`, `assets-upload-multipart-response` (osobny wariant pod nullable upload fields), `images`, `packshots`, `ai-models`, `sceneries`, `presets`, `aspect-ratios`, `pricing`, `jobs-submit`, `jobs-get`, `jobs-list`, `products-list`, `products-detail`.

### 7. JsonDecoder — extra-field tolerance + nested DTO

JsonDecoder już ignoruje unknown server-side keys (forward compat). To pozostaje. Nowość: zagnieżdżone DTO (`SessionConfig` w `SubmitJobRequest`, `Subject[]` w `SubmitJobRequest`, `JobOutput[]` w `JobDto`, `Installation` w `MeResponse` — `Installation` już jest).

JsonDecoder już wspiera nested via reflection (`$param->getType()->getName()` na class-string). Lists-of-objects (np. `subjects: Subject[]`) muszą używać `#[ArrayOf(Subject::class)]` attribute na ctor param (Phase-1 mechanism). Nowe DTO dodają te attributes gdzie trzeba.

### 8. `MeResponse.installation.scopes` — minor add

`InstallationInfo` DTO dziś nie ma `scopes: array<string>`. Upstream zwraca `installation.scopes: ['plugin.assets:upload', 'plugin.catalog:write', …]`. Phase-1 nie używa scopes do niczego, ale dodać żeby DTO matchował kontrakt. Brak breaking impact (tylko `TestConnectionController` woła `/me`, on patrzy na `account_name`/`credits_balance` — nie ruszamy).

### 9. Pricing DTO przerobiony z flat na list-with-currency

Upstream `/pricing` zwraca:
```json
{
  "pricing": [
    {"job_type":"packshot","provider":"openai","model":"gpt-image-1","credit_cost":5},
    ...
  ],
  "currency": "credits"
}
```

Phase-1 `Pricing` DTO ma `creditsPerImage`, `creditsPerPackshot`, `monthlyQuota?` — wszystkie wymyślone. Nowy:
```php
final class Pricing {
  public function __construct(
    /** @var PricingEntry[] */
    public readonly array $entries,
    public readonly string $currency,
  ) {}
}

final class PricingEntry {
  public readonly string $jobType;
  public readonly string $provider;
  public readonly string $model;
  public readonly int $creditCost;
}
```

Brak konsumentów Phase-1 dla `getPricing` — bezpiecznie breakować.

## Risks / Trade-offs

| # | Ryzyko | Mitigation |
|---|---|---|
| 1 | Duży PR (~1500 LOC) → ciężki review | Scope coherent (jedno capability, jeden katalog). Code change to głównie `final class XDto` rewrites + matching unit tests — repetitive, łatwy do scrollowania. Contract test fixtures dokumentują shape inline. |
| 2 | Snapshot fixtures driftują od upstream zod | Każdy ma `_commit` field — operator weryfikuje przed mergem. Mid-term: rozważyć generated approach (decyzja 6 opcja C). |
| 3 | `/jobs` POST session-lifecycle DTO są skomplikowane (zagnieżdżone subjects[]) → łatwo o bug w JsonDecoder reflection | Dedicated unit testy per sub-DTO. Contract test fixture dla `jobs-submit` z pełnym subjects+session_config. |
| 4 | Phase 3 PR #9 rebase będzie bolesny | Decyzja 5 listuje konkretne konflikty. Każdy ma znany resolution. Sit-time PR #9 po mergu fixu: 1-2 dni. |
| 5 | Pozostałe 9 niezaimplementowanych endpointów upstream może mieć więcej rozjazdów wewnątrz (np. session lifecycle ma wpływ na `/orders/{id}`) | Out of scope. Każdy doda się z własnym specem gdy faza go potrzebuje. |
| 6 | Phase-1 unit testy mogą zakładać shape który już od dawna nie istnieje | Każdy test będzie przejrzany; case'y zakładające wymyślone pola wpadają do śmietnika. Coverage zostanie utrzymane (TDD per task w `tasks.md`). |
| 7 | `external_ref` semantyka nie jest stabilna; Phase 3 może zmienić swoją konwencję | Spec definiuje że klient akceptuje `external_ref: string(1..200)` bez interpretacji. Phase 3 service definiuje swoją konwencję w jego własnym change. |
| 8 | Smoke wymaga aktualnych credentiali (API key + webhook HMAC) | Credentials żyją wyłącznie w PS BO Configuration store (`QAMERAAI_API_KEY`, `QAMERAAI_WEBHOOK_SECRET`) — wpisywane przez operatora z panelu Qamera AI per `CLAUDE.md` „Credentials for smoke testing". Smoke nie modyfikuje stanu upstream invasively (tylko POST /assets/upload + POST /images z `external_ref='smoke-…'`). Cleanup ręczny po smoke (`deleteProduct`). |
