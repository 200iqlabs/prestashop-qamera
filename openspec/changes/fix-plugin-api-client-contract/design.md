## Context

Phase-3 smoke (`/opsx:apply add-product-image-sync` → operator smoke § 11 z `tasks.md`) wykrył że `QameraApiClient::requestUpload()` wysyła pusty body, a upstream odrzuca z `code=invalid_input` żądając `{mode: "presigned", filename, content_type, size_bytes}`. Po inspekcji `qamera-ai/saas-platform/apps/web/app/api/v1/plugin/_lib/server/schemas.ts` wyszło że:

- `/assets/upload` body to discriminated union (`mode: "presigned" | "multipart"`); presigned wymaga 4 pól, response ma 7 pól, dwa są nullable
- `/images` to **bulk-only** (`POST /images` przyjmuje `{images: Array<RegisterImageSchema>}`, max 100); zwraca `{results: Array<RegisterImageResultSchema>}`
- `RegisterImageSchema` ma `external_ref` (caller-supplied stable id, wymagane, max 200), `product_ref`, opcjonalny `product_metadata`, oraz `asset_id: UUID` (nie `source_url`)
- `/packshots` zachowuje się analogicznie

Phase-1 client surface w `src/Api/QameraApiClient.php` ma signatury które nie odpowiadają temu kontraktowi. Phase-2 nie ruszał klienta. Phase-3 (PR #9) próbował go użyć i dlatego ujawnił rozjazd. Operator zdecydował (1 z 4 opcji w turze konsultacyjnej) że fix wjeżdża w osobnym change'u off main, a Phase-3 rebase'uje się na to po mergu.

## Goals / Non-Goals

**Goals:**
- `QameraApiClient::requestUpload(filename, contentType, sizeBytes)` SHALL hit `/assets/upload` z payloadem akceptowanym przez upstream (mode=presigned, 4 fields).
- `QameraApiClient::registerImage($request)` SHALL hit `/images` z bulk wrapperem, nawet dla pojedynczego obrazu (klient sam wrapuje). Caller widzi single-image API z punktu widzenia ergonomii (DI), klient pod spodem mówi bulk.
- `QameraApiClient::registerPackshot($request)` SHALL działać symetrycznie do `registerImage`.
- `tests/Contract/QameraApiContractTest.php` SHALL walidować że JSON wysyłany przez klienta matchuje pełen shape upstream zod (przez snapshot fixtures z hash-em git ref upstream, weryfikowane PHPUnit-em przeciw skopiowanym oczekiwanym kształtom).
- Wszystkie istniejące unit testy (`QameraApiClientTest`) muszą przejść po update sygnatur.

**Non-Goals:**
- **Wsparcie multipart upload mode** w `/assets/upload`. Plugin używa tylko presigned.
- **Bulk API dla wielu obrazów na raz**. Klient dalej eksponuje `registerImage($single)`; pod spodem wrapuje + unwrapuje. Bulk będzie potrzebne dopiero gdy plugin zacznie reaktywne re-syncowanie kilku produktów na raz (przyszła faza, prawdopodobnie kombinacja z cronem).
- **Naprawa pozostałych endpointów** (`/jobs*`, `/products*`, listy katalogu, …). To follow-up.
- **Automatyczna walidacja przez live `pnpm tsx` na upstream zod**. Contract test używa zamrożonych snapshotów (JSON fixtures z hash-em git ref). Jeśli upstream zod się zmieni, snapshoty wymagają ręcznego update (z OpenSpec change kontekstem).
- **Migracja Phase-3 spec content w tym change'u**. PR #9 ma własny delta na `qamera-api-client` (`product_metadata` jako optional na RegisterImageSchema); zostanie zachowany po rebase.

## Decisions

### 1. Klient eksponuje single-image API, nawet jeśli upstream jest bulk-only

| Opcja | Plus | Minus |
|---|---|---|
| **A. Single-in, bulk-pod-spodem** (klient wrapuje) | Caller w `ProductImageSyncService` widzi prosty `registerImage($request) → ImageResponse`. Nie musi się przejmować bulkiem. | Klient ma logikę pakowania/rozpakowywania. Jeśli bulk-with-error-on-item-3 zdarzy się w przyszłości, single-in API nie potrafi tego ergonomicznie wyrazić. |
| B. Bulk-in API natywnie | Spójność z upstreamem. Łatwy do rozszerzenia. | Wszystkie callery muszą wrapować pojedyncze obrazy w listę. Phase 3 (PR #9) musiałby przerobić signatury wewnętrzne. |
| C. Oba | Maksymalna elastyczność. | Dwie metody do utrzymania, jedna kontrakt. |

**Wybór: A.** Phase 3 i Phase 4 są single-image / single-packshot per `actionWatermark` invocation. Bulk będzie miało sens dopiero przy cron-resync (przyszła faza), wtedy można dodać `registerImages(array<Request>)` jako drugi entry-point obok single. Na razie A.

Konsekwencja implementacji: jeśli upstream zwróci `{results: []}` (zero), klient rzuca `ValidationException::malformedResponse('results[0]')`. Jeśli zwróci więcej niż 1 (nie powinien — wysłaliśmy 1), klient bierze pierwszy i loguje warning (defensive).

### 2. `external_ref` w `RegisterImageRequest` — kto generuje?

Upstream `RegisterImageSchema` wymaga `external_ref: string(1..200)`. To stable identifier per `(installation, external_ref)` — upstream używa do idempotency lookup ("repeat call with same external_ref returns status: 'existing'"). Caller (Phase 3 service) musi go wygenerować.

Decyzja: **caller dostarcza `external_ref`**. Klient go NIE generuje. Dla Phase 3 service to będzie najprawdopodobniej `qamera_product_ref + ':' + id_image` (np. `ps:1:42:99`), żeby ten sam upload obrazu re-fired (np. resize thumbnails) trafiał w to samo `external_ref` i upstream odpowiadał `status='existing'`. Phase 3 service decyduje co dokładnie wstawia — spec tego dokumentuje w jego osobnym change'u.

### 3. `PresignedUploadResponse` — które pola są nullable?

Upstream:
```ts
{
  asset_id: string,         // always
  bucket: string,           // always
  storage_path: string,     // always
  upload_url: string | null,   // null in multipart mode
  upload_token: string | null, // null in multipart mode
  expires_at: string | null,   // null in multipart mode (no signed URL → no TTL)
}
```

W PHP DTO odzwierciedlamy nullability 1:1 — `?string $uploadUrl`, `?string $uploadToken`, `?string $expiresAt`. Pomimo że klient woła tylko mode=presigned (gdzie wszystkie 3 są wypełnione), DTO MUSI być uczciwe — inaczej PHPStan i unit testy będą fałszować to co serwer może zwrócić.

`PresignedImageUploadStrategy` (Phase 3) MUSI sprawdzić że `uploadUrl !== null` przed PUT-em (defensive — gdyby ktoś kiedyś wywołał z `mode=multipart`, dostaniemy NPE).

### 4. Contract test — snapshot vs generated

| Opcja | Plus | Minus |
|---|---|---|
| **A. Zamrożone JSON snapshots** | PHP-only, brak Node dependency. Łatwo dodać do CI. Wykrywa nieoczekiwane zmiany w upstream tak długo jak operator ręcznie odświeża snapshot przy każdej zmianie. | Snapshot driftuje od upstream zod. Wymaga dyscypliny ("zmieniasz zod → odśwież snapshot"). |
| B. Live zod via `pnpm tsx` w CI | Zawsze aktualne. | Wymaga Node w CI matrixie. Cross-repo dependency. Slow CI (Node bootstrap). |
| C. Generated PHP fixtures z `zod-to-json-schema` | Programowo świeże. | Wymaga build steps po stronie saas-platform repo i synchronizacji. |

**Wybór: A.** Phase 3 smoke wyłapie drift natychmiastowo (HTTP 400 invalid_input). Snapshot to "ostatni zwery­fikowany shape" — primary purpose to wyłapanie regresji po naszej stronie (np. ktoś usunie pole), nie po upstream. Snapshoty żyją w `tests/Contract/Fixtures/`, każdy z header-komentarzem w JSON-ie ze ścieżką źródłową i git ref.

Format snapshotu (JSON):
```json
{
  "_source": "qamera-ai/saas-platform:apps/web/app/api/v1/plugin/_lib/server/schemas.ts",
  "_commit": "<git rev short>",
  "_captured_at": "2026-05-26",
  "request": { /* example valid POST /assets/upload presigned request body */ },
  "response": { /* example valid 201 response body */ }
}
```

### 5. Phase 3 rebase strategy

Po mergu tego fixu w main, Phase 3 (PR #9) wymaga rebase. Konflikty na:
- `src/Api/QameraApiClient.php` — Phase 3 nie modyfikuje, czysty
- `src/Api/Dto/RegisterImageRequest.php` — **konflikt**: Phase 3 dodaje `?ProductMetadata $productMetadata`, fix usuwa `source_url`/`title` i dodaje `external_ref`/`asset_id`. Resolution: zachować obie zmiany — final DTO ma `external_ref`, `product_ref`, `asset_id`, `?ProductMetadata`.
- `src/Api/Dto/ImageResponse.php` — Phase 3 dodaje `?productId`; fix robi pełny refactor (`externalRef`, `productId`, `imageId`, `status`). Resolution: fix wygrywa, Phase 3 zmiana wpada do śmietnika (productId już jest).
- `src/Sync/PresignedImageUploadStrategy.php` (tylko Phase 3): sygnatura `uploadImage(localPath)` zmienia się na `uploadImage(localPath, filename, contentType, sizeBytes)` żeby przekazać metadane do `requestUpload`. Plik Phase-3-only — Phase 3 fix-up w rebase.
- `src/Sync/ProductImageSyncService.php` (Phase 3-only): build `RegisterImageRequest` z `external_ref` + `asset_id` zamiast `source_url`. Phase 3 fix-up w rebase.
- `openspec/changes/add-product-image-sync/specs/qamera-api-client/spec.md` — Phase 3 delta na `product_metadata` zostaje, ale tekst requiremnetów Reference'ujący `source_url` musi się zaktualizować pod `asset_id`. Phase 3 fix-up w rebase.

Phase 3 owner (ten sam człowiek co tu — operator Paweł) zdecyduje czy rebase robimy w tej samej sesji co merge fixu, czy później. Spec niczego o tym nie mówi.

## Risks / Trade-offs

| # | Ryzyko | Mitigation |
|---|---|---|
| 1 | Pozostałe endpointy klienta też są niezgodne i nie wiemy ile | Smoke przeciw `/me` przeszedł — przynajmniej auth + retry + envelope-decoding działają end-to-end. Pełen audit reszty po Fazie 4. |
| 2 | Snapshot fixtures driftują od upstream zod | Każdy snapshot ma `_commit` field — operator weryfikuje przed mergem nowych change'ów. Mid-term: rozważyć generated approach (decyzja 4 opcja C). |
| 3 | Klient single-in-bulk-out tracimy widoczność błędów per-item | Phase 3 jest single-image — bulk się nie zdarzy. Gdy bulk wejdzie (cron resync), dodajemy `registerImages(array)` z bulk-error reporting. Symetrycznie packshots. |
| 4 | Phase 3 rebase będzie bolesny | Decyzja 5 listuje konkretne konflikty. Każdy ma znany resolution. PR #9 sit-time po mergu fixu: 1-2 dni max (zależnie od operatora). |
| 5 | `external_ref` semantyka nie jest jeszcze stabilna w Phase 3 callerze | Phase 3 spec dokumentuje to w jego własnym change'u. Tutaj definiujemy że klient go akceptuje jako required string(1..200) bez interpretacji — caller's job. |
| 6 | Phase 1 testy `QameraApiClientTest` muszą się przepisać i mogą się przy okazji okazać niezgodne z resztą upstream | Reading existing test casey przed update — jeśli któryś case zakłada coś co upstream odrzuca, wyłapuje fixują się tutaj. Out-of-scope endpointy (np. submitJob) testy zostają z mockowanymi payloadami niezweryfikowanymi przeciw upstream — explicit TODO marker w teście. |
