## Why

Faza 1 zbudowała `QameraApiClient` z jedną metodą per upstream endpoint, ale **nigdy nie odpaliła tych metod przeciw realnemu serwerowi** poza `/me`. Testy używały mockowanych payloadów, które autor wymyślił, nie tych których upstream żąda. Faza-3 smoke (`/opsx:apply add-product-image-sync`) jako pierwsza dotknęła `/assets/upload` i `/images` na produkcji — i oba kontrakty są rozjechane z upstream zod schemami z `qamera-ai/saas-platform/apps/web/app/api/v1/plugin/_lib/server/schemas.ts`:

| Endpoint | Stan klienta vs upstream |
|---|---|
| `GET /me` | ✅ Zgodne |
| `POST /assets/upload` | ❌ Klient wysyła pusty body. Upstream żąda `{mode: "presigned", filename, content_type, size_bytes}`. Response DTO też niekompletny — brak `bucket`, `storage_path`, `upload_token`; `expires_at` jest non-null w PHP DTO ale nullable w upstream (null dla trybu multipart). |
| `POST /images` | ❌ Klient wysyła **single object** `{product_ref, source_url, …}`. Upstream żąda **bulk wrappera** `{images: [{external_ref, product_ref, asset_id, product_metadata?}]}`. Pole `source_url` nie istnieje upstream — używają `asset_id` (UUID). Brakuje wymaganego `external_ref`. Response: nasz DTO single object `{id, productRef, sourceUrl, status}`; upstream zwraca `{results: [{external_ref, product_id, image_id, status: 'created'|'existing'}]}`. |
| `POST /packshots` | ❌ Najprawdopodobniej ten sam wzorzec co `/images` (bulk wrapper + `asset_id` + `external_ref`). Klient ma single-object `RegisterPackshotRequest`. Nie zsmoke'owane — wyjdzie w Fazie 4. |
| Pozostałe endpointy | ❓ Nie zweryfikowane (`listAiModels`, `listProducts`, `submitJob`, `getJob`, `listJobs`, `getProduct`, `deleteProduct`, `getPricing`, `listPresets`, etc.) — audit poza zakresem tej iteracji, ale OK będę musiał odpalić smoke przed Fazą 4. |

Konsekwencja: **PR #9 (`add-product-image-sync`) nie da się zsmoke'ować** dopóki ten fix nie wjedzie. Bez `/assets/upload` poprawnego payload-u nie ma uploadu obrazu. Bez `/images` bulk wrappera + `asset_id` nie ma rejestracji produktu upstream. Faza-3 unit testy są zielone bo zmockowane — ale realny ruch leci na ścianę 400 `invalid_input`.

Decyzja scope-u (uzgodniona z operatorem): naprawiamy **tylko** te trzy endpointy które Phase 3 / Phase 4 dotykają (`/assets/upload`, `/images`, `/packshots`). Pozostałe pola minowe (`/jobs`, `/products`, listy katalogu) zostają **w follow-upie** — żeby ten change był reviewable i smoke'owalny w jednym tchu, nie żeby przerobił całą powierzchnię klienta na raz.

## What Changes

- **`POST /assets/upload` — fix body + response DTO.** `QameraApiClient::requestUpload()` przyjmuje teraz `(string $filename, string $contentType, int $sizeBytes)` zamiast bez-argumentowego wywołania; serializuje `{mode: "presigned", filename, content_type, size_bytes}`. `PresignedUploadResponse` zyskuje `bucket: string`, `storagePath: string`, `?string $uploadToken`, a `uploadUrl` + `expiresAt` zmieniają się na `?string` (nullable — `null` w trybie multipart, którego klient nie wspiera, ale walidator nie może przebijać upstreama).
- **`POST /images` — bulk wrapper + nowy DTO + bulk response.** `RegisterImageRequest` traci pola `source_url`, `title`. Zyskuje wymagane `external_ref` (caller-supplied stable id; w plugin module będzie to ten sam `qamera_product_ref` co `product_ref`, ALE dla obrazu — `qamera_image_ref` jeśli operator chce per-image identyfikator, na razie reusujemy `product_ref:image_id` jako konwencję) i `asset_id` (UUID z `requestUpload` response). `QameraApiClient::registerImage($request)` opakowuje pojedynczy request jako `{images: [<single>]}` i parsuje response `{results: [<single>]}` → wyciąga pierwszy item jako `ImageResponse`. Nowy `ImageResponse` ma: `externalRef, productId, imageId, status: 'created'|'existing'`. Backward compat nie istnieje — Phase 1 nigdy nie wywołał tej metody na produkcji.
- **`POST /packshots` — symetria do `/images`.** `RegisterPackshotRequest` zyskuje `external_ref`, `asset_id`; traci `source_url`. `QameraApiClient::registerPackshot()` wrappuje + unwrappuje analogicznie. Nowy `PackshotResponse` ma kształt `RegisterPackshotResult` z upstreamu.
- **Contract test fixtures.** Nowy `tests/Contract/QameraApiContractTest.php` ładuje fixturey JSON (skopiowane jako snapshot z upstream zod) i waliduje że requesty wychodzące z klienta + odpowiedzi które klient parsuje matchują pełen shape. Bez ładowania zod w PHP — to są zamrożone snapshoty z `qamera-ai/saas-platform/.../schemas.ts` aktualne na 2026-05-26. Każdy snapshot ma komentarz z hash-em git ref upstream skąd został wzięty.
- **Faza 3 (`add-product-image-sync`) zostanie zrebase'owana** po mergu tego changu. `PresignedImageUploadStrategy::uploadImage` zmieni signaturę (przyjmie metadata, nie tylko ścieżkę), bo musi przekazać `filename`/`content_type`/`size_bytes` do `requestUpload`. `ProductImageSyncService` przerobi DTO budowy.

## Capabilities

### Modified Capabilities

- `qamera-api-client`: **major rewrite trzech endpointów**. Dodaje wymagania dla `requestUpload` (4-polowy discriminated union body, 7-polowy response DTO z nullable fields), `registerImage` (bulk wrapper + `external_ref` + `asset_id` zamiast `source_url`), `registerPackshot` (symetria). Dodaje wymaganie dla contract-test snapshotów. Phase-3 spec `qamera-api-client` (modyfikujący `product_metadata` opcjonalność) zostanie z PR #9 zachowany po rebase — te dwa zestawy zmian są ortogonalne.

## Impact

- **Code (modified)**
  - `src/Api/QameraApiClient.php` — sygnatury `requestUpload`, `registerImage`, `registerPackshot`; `registerImage` i `registerPackshot` opakowują single w bulk + unwrappują response
  - `src/Api/Dto/PresignedUploadResponse.php` — dodatkowe pola, nullable typy
  - `src/Api/Dto/RegisterImageRequest.php` — `external_ref`, `asset_id`; usunięte `source_url`, `title`
  - `src/Api/Dto/RegisterPackshotRequest.php` — `external_ref`, `asset_id`; usunięte `source_url`
  - `src/Api/Dto/ImageResponse.php` — refactor: `externalRef`, `productId`, `imageId`, `status`
  - `src/Api/Dto/PackshotResponse.php` — refactor analogicznie
- **Code (new)**
  - `tests/Contract/Fixtures/*.json` — zamrożone snapshoty zod (z hash-em upstream)
  - `tests/Contract/QameraApiContractTest.php` — walidacja shape requestów i responses przeciw fixturom
- **Tests (modified)**
  - `tests/Unit/Api/QameraApiClientTest.php` — istniejące casey muszą się przepisać pod nowe sygnatury (`requestUpload(arg, arg, arg)`, bulk-wrap dla images/packshots)
- **DB**: zero zmian schematu.
- **External services**: po mergu, ten klient pierwszy raz faktycznie zda smoke przeciw `/assets/upload` i `/images`. Walidacja: smoke skrypt w `tests/Smoke/` (untracked, nie commitowany — patrz blocker §11 z `add-product-image-sync`).
- **Dependencies**: brak nowych.
- **Compatibility**: **breaking change na publicznej sygnaturze klienta**. Akceptowalne bo `QameraApiClient` ma w produkcji wyłącznie jednego konsumenta (`TestConnectionController` woła tylko `me()`) plus testy. Phase 3 (PR #9) jest jedynym kolejnym konsumentem i będzie zrebase'owany.
- **Docs**: README phase plan zostawiamy bez zmian (fix nie przesuwa faz). CHANGELOG `[1.2.0]` zostaje zaktualizowany w PR #9 — tu nie ruszamy.

## Out of scope (świadomie)

- **Audit pozostałych endpointów** (`/jobs*`, `/products*`, `/orders*`, list-endpointy katalogu, `/pricing`, `/installations/[id]/rotate-hmac`, `/webhooks/[delivery_id]/replay`, `/jobs/[id]/refresh-url`). Te zostaną pokryte gdy któraś z przyszłych faz ich dotknie — z osobnym change'em per dotknięty endpoint, idąc tym samym wzorcem.
- **Konwersja całego klienta na generated-from-OpenAPI**. Upstream nie publikuje OpenAPI spec poza repo; rozważymy gdy/jeśli takie OpenAPI powstanie. Na razie ręczne DTO + contract snapshoty.
- **Multipart upload mode** w `/assets/upload`. Plugin używa tylko trybu presigned (decyzja 2 z `add-product-image-sync/design.md`). Multipart pomijamy aż do faktycznej potrzeby.
- **Authentication via `Authorization: Bearer`**. Upstream akceptuje `X-Api-Key`; klient już go używa; nic do zmiany.
