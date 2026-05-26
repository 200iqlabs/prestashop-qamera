## Context

Faza 2 doprowadziła plugin do stanu: każdy zapis produktu w BO PS zostawia wiersz `qamera_product_link` z `status='pending'`, `qamera_product_id=NULL`, snapshot metadanych. Upstream nic o tych produktach nie wie — `POST /products` nie istnieje, produkty powstają tylko jako side-effect `POST /images` lub `POST /packshots` z polem `product_metadata` (kontrakt rozpoznany podczas Fazy 2; pełna dokumentacja `plugin-product-catalog.md` żyje w upstream repo `qamera-ai/saas-platform`, niedostępnym z tego repo — patrz `openspec/changes/archive/2026-05-25-add-product-sync-hooks/design.md` po decyzję Fazy 2).

Faza 3 podpina **pierwszy upload obrazu produktu** jako trigger rejestracji upstreamowej. Operator dodaje obraz w BO → plugin pobiera wiersz `qamera_product_link`, robi presigned upload do Qamera storage, woła `POST /images` z `product_metadata` z wiersza, zapisuje `qamera_product_id` + `status='registered'`.

Phase plan z README to potwierdza: "Phase 2 — Core flow (Qamera API client, per-product sync, webhook handler, 'Qamera AI' product tab)". Bookkeeping + image-sync to **core flow**; webhook handler i product-tab UI to kolejne changes (Faza 3 i 4 w nomenklaturze tasków).

## Goals / Non-Goals

**Goals:**

- Pierwszy upload obrazu produktu w PS BO **automatycznie** rejestruje produkt upstream (gdy toggle `QAMERAAI_AUTO_REGISTER_PRODUCTS=1`).
- Cykl life: `qamera_product_link.status` przechodzi `pending → registered` na sukces, `pending → error` na porażkę z czytelnym `last_error_message`.
- Idempotency: drugie zapisanie tego samego obrazu (np. dodanie kolejnego po sukcesie pierwszego) **nie** woła upstream ponownie z `product_metadata`. Jeśli `qamera_product_id` jest wypełnione, kolejne obrazy lecą bez metadanych (czyste `POST /images` z `product_ref`).
- BO save action MUSI zakończyć się sukcesem niezależnie od stanu upstream — wszystkie `\Throwable` z hooka są łapane i logowane (zachowanie z Fazy 2).
- 100% test coverage state-transitions w `ProductImageSyncService` (mockowany klient + writer).

**Non-Goals:**

- **Packshots** — `POST /packshots` zostaje na Fazę 4 (karta "Qamera AI" w produkcie z przyciskami generowania).
- **Manual retry UI** — `error → pending` reset będzie w Fazie 4 (UI w karcie produktu). Faza 3 tylko zostawia `last_error_message` operatorowi do podglądu w phpMyAdmin / logu BO.
- **Bulk backfill** — Faza 3 nie sweeps istniejących `pending` wierszy w cronie. Trigger to wyłącznie hook upload. Wiersze które już istniały bez triggera obrazu (od Fazy 2) zostaną zarejestrowane dopiero gdy ktoś doda/zmieni obraz.
- **Webhook handler** — webhook od Qamera AI o jobie się zakończył (osobny change). Faza 3 wysyła image-register i czyta synchronous response — to wystarczy do ustawienia `qamera_product_id`.
- **Async processing / Symfony Messenger** — patrz decyzja 1 niżej (świadomie sync).
- **Wsparcie shopów na localhoscie via publiczny URL** — presigned upload działa wszędzie, więc nie potrzebujemy fallbacku (patrz decyzja 2).
- **Multi-shop replication** — wybrany został `actionWatermark`, który strzela per shop context (jak `actionProductSave` w Fazie 2). Replikacja do innych associated shopów nadal jest follow-up jak w Fazie 2.

## Decisions

### 1. Sync rejestracja w hooku (nie cron/async)

| Opcja | Plus | Minus |
|---|---|---|
| **A. Sync w hooku + swallow-throw** | Najprostsze. Operator widzi sukces/porażkę natychmiast w logach BO. Nie ma queue table do utrzymywania. Bug-feedback loop najkrótszy. | Network I/O w hot path BO save action. Timeout PS (domyślnie 30s+) chroni przed zawieszeniem, ale wolny upstream spowalnia save. |
| B. Cron sweep | BO save 100% niezależne od upstream. | Wymaga infra (cron entry, lock-file, command). Operator nie widzi sukcesu/porażki natychmiast. To realnie scope Fazy 4 (UI w produkcie powinno wyświetlać status z cron sweepu). |
| C. Sync z aggressive timeout | Kompromis. | Złożone (custom Guzzle config), operator dostaje "timeout" jako error nawet gdy upstream by się w końcu odpowiedział. |

**Wybór: A.** Spec Fazy 2 mówi "BO save MUST always succeed" — to definiuje *swallow-throw*, nie *brak network I/O*. Sync w hooku z catch-all (zgodnie z `writeProductSnapshot` z Fazy 2) spełnia kontrakt: save zawsze konczy się sukcesem, a network failure idzie do `status='error'` + log. Faza 4 zaadresuje "natychmiastową widoczność statusu operatorowi" przez UI w karcie produktu czytające `last_error_message`. Cron sweep to legitimate optimization — ale **po** Faza 4 (już z UI), nie zamiast.

Konsekwencja: Guzzle timeout w `QameraApiClient` (Faza 1) MUSI być rozsądny (sprawdzić — domyślnie 30s?). Jeśli jest za długi, design narzuca obniżenie w osobnym change'u.

### 2. Presigned upload + PUT (nie publiczny URL sklepu)

| Opcja | Plus | Minus |
|---|---|---|
| **A. Presigned upload** | Działa na localhost / dev / behind firewall. Nie ujawnia URL-i sklepu w upstream metadanych (privacy). Faza 1 już zaimplementowała `QameraApiClient::requestUpload()` zwracające `PresignedUploadResponse`. | Dodatkowy HTTP roundtrip (`POST /assets/upload` → PUT → `POST /images`). |
| B. Publiczny URL sklepu | Jeden roundtrip mniej. Działa naturalnie z PS image URL-ami. | Nie zadziała na localhost (najczęstszy dev case). Wymaga że sklep ma publicznie dostępne URL-e (większość ma, ale niektóre staging za auth). Upstream musi pobrać → load na sklepie. |
| C. Hybrid | Działa wszędzie. | Złożone, dwie ścieżki testowe, race conditions na fallbacku. |

**Wybór: A.** Presigned upload jest jedyną opcją, która działa na localnym docker stacku (gdzie smoke test się odbywa). Plus Faza 1 już ma `requestUpload()` w kliencie — nie ma nawet kosztu implementacji nowego endpointu. Trzy roundtripy (request upload, PUT, register image) są w pełni acceptable bo to dzieje się raz per produkt (cachowanie `qamera_product_id`).

### 3. Trigger hook: `actionWatermark`

PS 9 nie ma hooka `actionProductImage`. Po uploadzie obrazu produktu fire'uje się **`actionWatermark`** (`classes/ImageManager.php:877` w PS 9.0) z paramami:

```php
['id_image' => int, 'id_product' => int]
```

Hook nazywa się tak historycznie (PS używał go do nakładania watermarku), ale jest to *de facto* "image was uploaded for product" hook. Adobe, Mailchimp i inne moduły go używają w ten sam sposób.

Alternatywy:
- `actionProductSave` (z Fazy 2) + lazy check czy produkt ma obraz — false-positives na każdym save bez zmian obrazu; spam.
- Cover set hook — nie istnieje jako osobny hook w PS 9.
- Hook na nowy `ProductImageUploader` z Symfony — to wewnętrzny adapter, hook'a publicznego brak.

**Wybór: `actionWatermark`.** Instalator dodaje go do `self::HOOKS`. Hook handler w `qameraai.php` deleguje do `ProductImageSyncService::syncOnImageAdded($idProduct, $idImage)`.

### 4. Stan i transitions w `qamera_product_link`

Faza 2 zdefiniowała ENUM `('pending', 'registered', 'error')` ale tylko `pending` jest ustawiane przez writer. Faza 3 dodaje:

```
pending --(registerImage 2xx + qamera_product_id w response)--> registered
pending --(registerImage 4xx/5xx po retries, exhaustion, OR upload failure)--> error
error   --(operator zmienił obraz lub metadane, kolejny actionWatermark, registerImage 2xx)--> registered
registered --(kolejny obraz; brak product_metadata w request)--> registered  [no-op for status]
```

Co konkretnie zapisuje serwis przy każdej transition:

| From | To | Updated columns |
|------|-----|---|
| `pending` | `registered` | `status='registered'`, `qamera_product_id=<from response>`, `last_synced_at=NOW()`, `last_error_message=NULL` |
| `pending` | `error` | `status='error'`, `last_error_message=<sanitized>`, `last_synced_at=NOW()`, `qamera_product_id` zostaje NULL |
| `error` | `registered` | jak `pending → registered` (czyści `last_error_message` na NULL) |
| `registered` | `registered` | `last_synced_at=NOW()` — re-upload obrazu odświeża timestamp, status nie zmienia |

`error → pending` (manual reset) i `registered → error` (regressja po sukcesie) — **out of scope Fazy 3**. Pierwszy to Faza 4 (UI). Drugi to corner case który będzie zaadresowany w cron sweep / reconciliation w przyszłej iteracji.

### 5. Wybór "primary" obrazu

PS może mieć N obrazów per produkt. Który wysyłamy z `product_metadata` przy pierwszej rejestracji?

| Opcja | Plus | Minus |
|---|---|---|
| **A. Cover image** (`Image::getCover($idProduct)`) | Operator świadomie ustawił jako "główne" zdjęcie produktu. Większość sklepów ma cover. | Może być NULL jeśli operator nie ustawił. |
| B. First by position | Zawsze istnieje (skoro hook strzela). | Mniej "deliberate". |
| C. `$params['id_image']` z hooka | To **dokładnie** ten obraz który operator dodał. | Operator może dodać sub-obraz przed cover — wtedy registracja idzie z sub-image jako "primary" co jest mylące dla upstream. |

**Wybór: A z fallbackiem na C.** Helper `PrimaryImageResolver::resolve($idProduct, $hintIdImage)`:

1. Spróbuj `Image::getCover($idProduct)` — jeśli zwraca obraz, użyj.
2. Inaczej fall back na `$hintIdImage` z hooka.
3. Inaczej (rzadkie — hook strzela bez `id_image`?) — `Image::getImages($idProduct)` pierwszy by position.
4. Jeśli zero — wczesny return, log "no image to upload", **nie** ustawiaj `status='error'` (to nie błąd Qamera, to brak danych).

### 6. Idempotency

Trzy warstwy:

1. **HTTP level** — `QameraApiClient::registerImage` już generuje idempotency-key (Faza 1, `src/Api/Internal/IdempotencyKeyGenerator.php`). Retries upstream nie tworzą duplikatów.
2. **State level** — przed wywołaniem `registerImage`, serwis czyta wiersz `qamera_product_link`. Jeśli `status='registered'` i `qamera_product_id IS NOT NULL`, **NIE** dodaje `product_metadata` do requestu (kolejne obrazy lecą jako bare `POST /images` z `product_ref`). To zgodne z upstream "produkt już istnieje, dodaj kolejny obraz".
3. **Hook level** — `actionWatermark` może strzelić wielokrotnie dla tego samego obrazu (np. PS robi resize i fires hook dla każdego thumbnail). Service używa `(id_product, id_image)` jako deduplication key in-memory podczas requestu (proste runtime cache w property — nie persistent).

### 7. Mapping błędów upstream → `last_error_message`

`QameraApiClient` (Faza 1) rzuca typed exceptions: `ValidationException` (422), `AuthException` (401), `NotFoundException` (404), `RateLimitException` (429), `ServerException` (5xx po retries), `TransportException` (connection/timeout).

`ProductImageSyncService` łapie wszystkie i mapuje do `last_error_message`:

```
ValidationException     → "Upstream validation: " + first error code + ": " + first error message (max 500 chars)
AuthException           → "API credentials invalid (HTTP 401). Check API key in module configuration."
NotFoundException       → "Upstream returned 404. Possible causes: (a) installation inactive — check status in Qamera AI panel; (b) product_ref not found upstream and product_metadata was omitted from the request (expected only on the registered → registered path)."
RateLimitException      → "Rate limit exceeded — try again later. (HTTP 429)"
ServerException         → "Upstream server error (HTTP 5xx) after retries. Try again later."
TransportException      → "Network error reaching Qamera AI: " + e->getMessage() (max 500)
\Throwable (other)      → "Unexpected: " + get_class(e) + ": " + e->getMessage() (max 500)
```

`last_error_message` jest TEXT NULL — limit 500 chars to konserwatywny w UI BO (przyszła karta produktu).

### 8. ProductMetadata DTO

Współdzielony value object dla payloadu `product_metadata` w `RegisterImageRequest` (i przyszłym `RegisterPackshotRequest`):

```php
final class ProductMetadata
{
    public function __construct(
        public readonly string $displayName,        // ≤500
        public readonly ?string $sku = null,        // ≤100
        public readonly ?string $description = null, // ≤5000
    ) {}

    public function toPayload(): array { ... }
}
```

Walidacja max-długości — w konstruktorze (`InvalidArgumentException`). Upstream `ProductMetadataSchema` to lustra; jeśli upstream zaostrzy limity, zmiana lokalna w jednym miejscu.

Lokalizacja: `src/Api/Dto/ProductMetadata.php` (z innymi DTO klienta, nie `src/Sync/`).

## Risks / Trade-offs

| # | Ryzyko | Mitigation |
|---|---|---|
| 1 | Sync upstream call w BO save spowalnia operatora (presigned + PUT + registerImage = ~3 roundtripy) | Akceptowalne — upload obrazu w PS już jest "ciężki" (resize, watermark, thumbs); +3 HTTP roundtripy do Qamera są w tym samym rzędzie. Mierzymy w smoke test (`tasks.md §10`); jeśli p95 > 5s, follow-up change podnosi do cron sweep. |
| 2 | Operator nie widzi statusu rejestracji bez zaglądania do logów BO / phpMyAdmin | Akceptowalne dla Fazy 3 — UI w karcie produktu to Faza 4. CHANGELOG flaguje "Known limitation". |
| 3 | `Image::getCover` zwraca `false` dla produktów bez cover (legacy PS lub szybko klikający operator) | Fallback chain w `PrimaryImageResolver` (decyzja 5). Plus testy. |
| 4 | Presigned URL ma TTL (na ogół 5-15 min) — race condition gdy PUT się zawiesi | `QameraApiClient::requestUpload` zwraca też `expires_at`; jeśli `now() > expires_at`, request nowego presigned URL przed PUT. To w `ImageUploadStrategy`. |
| 5 | PS uploaduje wiele rozmiarów (cart-default, home-default, large, etc.) — wszystkie strzelają `actionWatermark`. Spam upstream callów. | Hook-level idempotency (decyzja 6.3) + sprawdzenie `Image::isCover` lub porównanie `$id_image` z cover image-em (bardziej deliberate). Tylko cover triggeruje upstream call; inne size'y log "skipping non-cover image" i return. |
| 6 | `product_metadata` ze snapshotu Fazy 2 może być stary (operator zmienił nazwę po `actionProductSave` ale przed obrazem). | Snapshot writer Fazy 2 odświeża się przy każdym save — w praktyce między `actionProductSave` a `actionWatermark` jest sekundy, nie godziny. Akceptowalne. Re-sync z fresh metadata to Faza 4. |
| 7 | Pierwszy realny smoke test wywołań przeciw `https://qamera.ai/api/v1/plugin` może odsłonić bugi Fazy 1 (auth, retry, idempotency) nieujawnione w mocku | Dlatego operator-driven smoke z credentialami z `CLAUDE.md` jest **wymagany** przed merge (`tasks.md §10`). Mock'owane testy nie wystarczą. |
| 8 | Symfony container resolution w hooku CLI failed silnie w Fazie 2 — może powtórzyć się dla nowych serwisów | Lesson z Fazy 2: hook MUSI mieć fallback gdy `$this->get()` zwraca null. Implementuję ten sam pattern co Faza 2 — `try { writer = $this->get(...) } catch { build manually }` to overkill, ale logujemy `Error: Call to a member function ... on null` jasno. |
