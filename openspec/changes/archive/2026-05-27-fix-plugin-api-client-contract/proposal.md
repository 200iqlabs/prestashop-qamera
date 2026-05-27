## Why

Phase-3 smoke (`/opsx:apply add-product-image-sync`, PR #9) ujawnił że `QameraApiClient::requestUpload()` wysyła pusty body do upstream, który żąda discriminated union `{mode:"presigned", filename, content_type, size_bytes}`. Po inspekcji `qamera-ai/saas-platform/apps/web/app/api/v1/plugin/_lib/server/schemas.ts` (commit z 2026-05-26, ref `<TBD §1.3>`) wyszło że **rozjazd to nie 3 endpointy, tylko prawie cała powierzchnia klienta**. Pełen audyt 15 metod `QameraApiClient`:

| Stan | Endpointy | Liczność |
|---|---|---|
| ✅ Działa | `GET /me`, `DELETE /products/{idOrRef}` | 2 |
| ❌ Pusty/single body zamiast bulk+discriminator | `POST /assets/upload`, `POST /images`, `POST /packshots` | 3 |
| ❌ `sendList` zakłada wrapper `{items:[…]}` ale upstream używa per-endpoint key (`ai_models`, `sceneries`, `presets`, `aspect_ratios`, `pricing`, `jobs`) + element DTO wymyślony | `GET /ai-models`, `GET /sceneries`, `GET /presets`, `GET /aspect-ratios`, `GET /pricing`, `GET /jobs` | 6 |
| ❌ Pre-lifecycle shape (klient predates 2026-05-22 BREAKING) | `POST /jobs` | 1 |
| ❌ `resultUrls:string[]` zamiast `outputs:JobOutput[]` (+ 12 pól missing) | `GET /jobs/{id}` | 1 |
| ❌ Element DTO pola wymyślone (`title`/`status` vs `display_name`/`external_ref`/`source_metadata`/…) | `GET /products`, `GET /products/{idOrRef}` | 2 |

**Net: 14 z 15 metod klienta wymaga przepisania.** Tylko `/me` (które smoke przeszedł w Phase-3 review) i `DELETE /products` (które nie ma body) są funkcjonalne. Phase-1 client został zbudowany przeciw zgadywanym shape-om — testy zmockowały to co autor wymyślił, nie to czego serwer żąda.

Decyzja scope-u (uzgodniona z operatorem, opcja "jeden mega-change: regeneruj cały klient"): naprawiamy **14 zerwanych metod naraz** w jednym PR-ze (`me()` dostaje tylko `installation.scopes` add, `deleteProduct()` zostaje bez zmian — ale obie są częścią tego samego rewrite-pass na poziomie modułu), alignment do aktualnego `schemas.ts`. Jeden coherent breaking change, jeden round review, jeden smoke. Wszystkie kolejne fazy (3, 4, sesje, jobs, cron-resync) budują na solidnym kliencie zamiast łatać kolejne endpointy.

## What Changes

### A. `POST /assets/upload`, `POST /images`, `POST /packshots` — body + DTO rewrite

`requestUpload($filename, $contentType, $sizeBytes)` wysyła `{mode:"presigned", filename, content_type, size_bytes}` (discriminator + 3 wymagane pola). `PresignedUploadResponse` zyskuje `bucket`, `storagePath`, `?uploadToken`; `uploadUrl`/`expiresAt` stają się nullable.

`registerImage($request)` opakowuje pojedynczy request jako `{images:[<single>]}` (upstream przyjmuje tylko bulk wrapper, 1..100). `RegisterImageRequest` traci `source_url`/`title`, zyskuje wymagane `external_ref` (caller-supplied stable id, 1..200) i `asset_id` (UUID z `requestUpload`). Response parsowany jako `{results:[<single>]}` → wyciągamy `ImageResponse{externalRef, productId, imageId, status:'created'|'existing'}`.

`registerPackshot` analogicznie — `{packshots:[…]}` wrapper, `external_ref`+`asset_id`+optional `source_image_ref`, response `{results:[…]}` → `PackshotResponse{externalRef, productId, packshotId, status}`.

### B. List endpointy — wrapper key + element DTO regen

`QameraApiClient::sendList` zostaje sparametryzowany na nazwę wrapper-key (dziś jest hard-coded `items`). Każdy list endpoint dostaje regenerowany element DTO matchujący upstream zod:

| Endpoint | Wrapper key | Element DTO (nowe pola) |
|---|---|---|
| `GET /ai-models` | `ai_models` | `AiModelDto{id, provider, model, outputType, supportedAspectRatios[], baseCreditCost}` — drop wymyślone `name`/`description` |
| `GET /sceneries` | `sceneries` | `SceneryDto{id, name, thumbnail, voting, status, source, createdAt}` — `thumbnail` nie `previewUrl` |
| `GET /presets` | `presets` | `PresetDto{id, slug, name, descriptionI18n, creditCost, outputType, isFree, coverUrl, quantityGuidelines, qualityGuidelines, gallery}` — drop wymyślone `category` |
| `GET /aspect-ratios` | `aspect_ratios` | `AspectRatioDto{value, label, default}` — drop wymyślone `id`/`ratio` |
| `GET /pricing` | `pricing` + `currency` | Zwraca **listę** `PricingEntryDto{jobType, provider, model, creditCost}`, nie flat object — całość `Pricing` DTO przerobiona z flat na list-with-currency |
| `GET /jobs` | `jobs` + `nextCursor` | `JobDto` (16 pól, patrz pkt C) + filtry rozszerzone o `createdAfter`/`createdBefore` |
| `GET /products` | `items` + `nextCursor` (zachowane!) | `ProductListItem{id, externalRef, displayName, sku, description, sourceMetadata, imageCount, packshotCount, deletedAt, createdAt, updatedAt}` — wymyślone `title`/`status` znikają; filtry rozszerzone o `ref`/`includeDeleted`, drop wymyślone `status` |

### C. `/jobs` POST + GET — pełen session-lifecycle alignment

`POST /jobs` upstream predates wymaga session-lifecycle shape:
```
SubmitJobRequest = {
  session_config: { aspect_ratio, model_id?, scenery_id?, preset_id?, suggestions? },
  subjects: [{ packshot_asset_id, product_label, product_ref, images_count, ai_model, reference_asset_ids?, provider_settings?, product_name?, product_specific_category? }],
  callback_url?, external_metadata?, priority?
}
```
Response: `{order_id, status, subjects:[{product_ref, job_ids[]}]}`.

`SubmitJobRequest` PHP DTO + `SubmitJobResponse` DTO przerobione od podstaw. `SessionConfig`, `Subject` jako sub-DTO. `JobResponse` dla `getJob`/`listJobs` rozszerzone do pełnych 16 pól (`orderId, jobType, provider, model, unitCost, attemptCount, outputs[JobOutput], error, externalMetadata, packshotAssetId, productLabel, productRef, voting, votingAt, createdAt, updatedAt, completedAt`). `JobOutput{url, type, width, height, …}` jako sub-DTO.

### D. `/me` minor patch + `/products/{idOrRef}` rewrite

`InstallationInfo` zyskuje `scopes: array<string>` (brakujące dziś). `ProductResponse` (dla `getProduct`) przerobiony do `ProductDetailResponse{id, externalRef, displayName, sku, description, sourceMetadata, deletedAt, createdAt, updatedAt, images: ProductImageDto[], imagesTruncated, packshots: ProductPackshotDto[], packshotsTruncated}` — drop wymyślone `title`/`status`, dodaj zagnieżdżone `images`/`packshots` listy z dwoma nowymi DTO.

### E. Contract test infra + snapshot fixtures dla całej powierzchni

`tests/Contract/Fixtures/<endpoint>.fixture.json` z header (`_source`, `_commit`, `_captured_at`) per endpoint który dotykamy. Fixtury obejmują request body (gdzie POST) + response body (przykład 2xx) + przykład 4xx envelope dla kluczowych endpointów. `tests/Contract/QameraApiContractTest.php` waliduje że klient produkuje requesty matchujące fixturom i parsuje response matchujące fixturom.

### F. Out of scope (świadomie) — 11 endpointów upstream które klient w ogóle nie ma

`POST /jobs/batch`, `POST /jobs/{id}/accept`, `POST /jobs/{id}/reject`, `GET /jobs/{id}/refresh-url`, `GET /orders/{id}`, `POST /orders/{id}/clone`, `GET /packshots` (lista), `GET /packshots/{idOrRef}`, `GET /models` (osobny od `/ai-models`), `POST /installations/{id}/rotate-hmac`, `POST /webhooks/{delivery_id}/replay`.

Każdy dopiszemy gdy konkretna faza ich potrzebuje. `/installations/.../rotate-hmac` zostaje jednoznacznie blocked operatorem (sekret rotuje się tylko w panelu Qamera AI per Phase-1 decision); pozostałe to follow-up scope.

## Capabilities

### Modified Capabilities

- `qamera-api-client`: **major regenerate całej powierzchni**. 14 z 15 metod się zmienia (sygnatury + DTO). Wszystkie 14 element-DTO list-endpointów przerobione. `sendList` parametryzowany. Dodane: `SessionConfig`, `Subject`, `JobOutput`, `PricingEntry`, `ProductImageDto`, `ProductPackshotDto` jako sub-DTO. Dodane contract-test fixtures dla pełnej powierzchni. `MeResponse.installation.scopes` dodane. PR #9 (`add-product-image-sync`) delta na ten sam capability (`product_metadata` jako optional na `RegisterImage`) zostanie zachowany po rebase.

## Impact

- **Code (modified)** — niemal wszystko pod `src/Api/`:
  - `src/Api/QameraApiClient.php` — 14 sygnatur metod, parametryzacja `sendList`, dodatkowa walidacja per metoda
  - `src/Api/Dto/*.php` — wszystkie 16+ istniejących DTO regenerowane; struktura katalogu utrzymana
- **Code (new)**:
  - `src/Api/Dto/SessionConfig.php`, `Subject.php`, `JobOutput.php`, `PricingEntry.php`, `ProductImageDto.php`, `ProductPackshotDto.php`, `OrderSubject.php` (dla submit response) — sub-DTO które pojawiają się tylko w upstream
  - `tests/Contract/Fixtures/*.fixture.json` — pełen zestaw 15 snapshotów (po jednym per endpoint w scope-ie + osobny `assets-upload-multipart-response` dla nullable upload fields)
  - `tests/Contract/QameraApiContractTest.php` — runner walidujący kontrakt
- **Tests (modified)**:
  - `tests/Unit/Api/QameraApiClientTest.php` — większość casey wymaga update pod nowe sygnatury
  - `tests/Unit/Api/Internal/JsonDecoderTest.php` — może wymagać dodatkowych casey dla zagnieżdżonych DTO
- **DB**: zero zmian schematu.
- **External services**: po mergu klient pierwszy raz robi end-to-end smoke przeciw `/assets/upload`, `/images`, `/jobs` POST/GET, list endpointom katalogu i `/products` GET. Smoke skrypt w `tests/Smoke/` (untracked).
- **Dependencies**: brak nowych.
- **Compatibility**: **breaking change publicznej sygnatury klienta**. Wszystkie 14 zmienionych metod. Pre-merge konsumenci klienta to: `TestConnectionController` (woła tylko `me()` — OK, drop `installation.scopes` add nie breaks). Nikt jeszcze nie woła reszty na produkcji. PR #9 (Phase 3) konsumuje `requestUpload`/`registerImage` — rebase wg `design.md §5`. Dobry timing — przed Fazą 4 (która chce `registerPackshot` i list endpointów dla catalog drop-downów) cały klient jest stabilny.
- **Docs**: README phase plan bez zmian. `CHANGELOG [1.2.0]` w PR #9 zostanie zaktualizowany przy archive Phase-3 żeby uwzględnić ten fix (uzgodnić timing przy archive).

## Out of scope (świadomie)

- 9 nieobsługiwanych przez klienta endpointów upstream (lista w §F) — follow-up scope per faza.
- Multipart upload mode w `/assets/upload` — plugin używa tylko presigned (decyzja 2 z `add-product-image-sync/design.md`).
- Generated client (`zod` → PHP DTO) — upstream nie publikuje OpenAPI; rozważymy przy Fazie 5 (marketplace prep).
- Webhook handler — `webhooks/{delivery_id}/replay` to konsument **dla** webhook'ów upstream, plugin sam przyjmuje webhooks; oddzielny temat.
