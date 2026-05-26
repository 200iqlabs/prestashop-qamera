## Why

Po Fazie 2 plugin trzyma wiersze w `qamera_product_link` z `status='pending'` i `qamera_product_id=NULL` — czyli wie "ten produkt PS istnieje", ale nigdy nie powiedział o nim upstreamowi. Bookkeeping to martwy ciężar dopóki ktoś nie zacznie tych produktów rejestrować w Qamera AI. Upstream API nie ma `POST /products` — produkt powstaje wyłącznie jako side-effect `POST /images` (lub `POST /packshots`) z polem `product_metadata` (potwierdzone podczas rozpoznania w Fazie 2 — patrz `design.md` Fazy 2 archive'owanej w `openspec/changes/archive/2026-05-25-add-product-sync-hooks/design.md`; pełna upstream-side dokumentacja `docs/knowledge/plugin-product-catalog.md` żyje w osobnym repo `qamera-ai/saas-platform`, do którego ten plugin nie ma bezpośredniego dostępu). Czas to wpiąć: pierwszy upload zdjęcia produktu → `registerImage` z `product_metadata` → upstream cascading tworzy produkt → my dostajemy `qamera_product_id`, ustawiamy `status='registered'`.

Wycelowanie pierwszego image-sync w *zdjęcie produktu* (nie packshot) daje:
- naturalny trigger po stronie operatora (dodanie obrazu produktu w BO PS to akcja, którą operator i tak robi przy katalogowaniu)
- minimalny footprint w UI (Faza 3 nie wymaga nowej karty produktu — to Faza 4)
- czyste następstwo po Fazie 2 (te same wiersze `qamera_product_link`, ten sam toggle, ten sam writer dla `last_error_message`/`last_synced_at`)

Packshoty (`POST /packshots`) zostawiamy świadomie poza zakresem — to Faza 4, kiedy doda się karta "Qamera AI" w produkcie z przyciskami generowania.

## What Changes

- **Hook `actionProductImage` / `actionWatermark`** — gdy toggle `QAMERAAI_AUTO_REGISTER_PRODUCTS=1` i wiersz `qamera_product_link` ma `status IN ('pending', 'error')`: enqueue rejestracji obrazu. Konkretny hook (lub kombinacja) — design.md po rozpoznaniu PS 9 hooks dla obrazów produktów.
- **Service `ProductImageSyncService`** — orkiestruje cykl: (1) pobierz wiersz `qamera_product_link` dla produktu, (2) wybierz "primary" obraz produktu (cover/first), (3) zdobądź upstream-dostępny URL tego obrazu (presigned upload + PUT vs publiczny URL sklepu — design.md), (4) zawołaj `QameraApiClient::registerImage` z `product_metadata` z wiersza snapshotu, (5) zapisz `qamera_product_id`, `status='registered'`, `last_synced_at=NOW()` na sukces, lub `status='error'`, `last_error_message=...` na porażkę.
- **Wymiana `RegisterImageRequest` DTO** — obecnie ma tylko `product_ref`, `source_url`, `title`. Faza 3 dodaje pole `product_metadata` (obiekt z `display_name`, `sku?`, `description?`) zgodne z upstream `ProductMetadataSchema`. To **MODYFIKACJA** istniejącego DTO + endpointu klienta — bez breaking-change'a, bo Faza 1 nigdy nie wołała `registerImage` w produkcji.
- **Maszyna stanów `qamera_product_link.status`** — Faza 2 określiła trzy stany; Faza 3 dodaje **przejścia**: `pending → registered` (sukces upstream), `pending → error` (porażka — np. 422 validation, 5xx po retries), `error → pending` (manual reset operator z BO — out of scope tego changu, ale spec to dopuszcza), `error → registered` (operator naprawił przyczynę, kolejna próba przeszła). `registered` jest stanem terminalnym dla *rejestracji* — kolejne obrazy nie wymagają już `product_metadata`.
- **Cron / async** — pierwsza iteracja Fazy 3 wykonuje rejestrację **synchronicznie** w hooku (z full network I/O); design.md zdecyduje czy potrzebny jest deferred queue (cron) dla bezpieczeństwa BO save action. Aktualnie spec Fazy 2 mówi "BO save MUST always succeed" — to ogranicza synchroniczność. Patrz design.md.
- **Brak zmian w schema DB** — wszystkie pola dla state-machine już są (`qamera_product_id`, `status`, `last_error_message`, `last_synced_at`).
- **Brak BO UI** — Faza 3 jest niewidzialna dla operatora poza wpisami w logach BO i ewentualną zmianą `status` widoczną w phpMyAdmin. UI w karcie produktu (Faza 4).

## Capabilities

### New Capabilities

- `product-image-sync`: orkiestracja synchronizacji obrazów produktów PS → Qamera AI Plugin API. Definiuje: trigger (który hook PS), wybór "primary" obrazu, mechanizm udostępnienia obrazu upstreamowi (presigned vs URL), wołanie `registerImage` z `product_metadata`, mapowanie odpowiedzi na `qamera_product_link` (success/error), retry policy (ile prób, jakie błędy retry-owalne), idempotency (oparta o idempotency-key z `QameraApiClient`).

### Modified Capabilities

- `product-sync-bookkeeping`: dodanie wymagań dla **przejść stanów** (`pending → registered`, `pending → error`, `error → registered`). Faza 2 zdefiniowała tylko że stany istnieją; Faza 3 definiuje *kto* i *kiedy* je przesuwa. Snapshot writer pozostaje nietknięty.
- `qamera-api-client`: rozszerzenie `RegisterImageRequest` o pole `product_metadata: ProductMetadata` (DTO). Dodanie wymagania, że klient SHALL akceptować `product_metadata` jako optional pole payloadu zgodne z upstream `ProductMetadataSchema` (display_name ≤500, sku ≤100, description ≤5000).

## Impact

- **Code (new)**
  - `src/Sync/ProductImageSyncService.php` — orkiestrator (interface + implementation, testable)
  - `src/Api/Dto/ProductMetadata.php` — value object dla payloadu `product_metadata` (mieszka razem z `RegisterImageRequest` i resztą DTO klienta API, żeby był reusable dla `RegisterPackshotRequest` w Fazie 4)
  - `src/Sync/PrimaryImageResolver.php` — wybiera "primary" image dla produktu PS (cover, fallback first by position)
  - `src/Sync/ImageUploadStrategy.php` — interface dla strategii (presigned vs publiczny URL); implementacje
  - `tests/Unit/Sync/ProductImageSyncServiceTest.php` — pokrywa state transitions, error mapping, retry semantics
- **Code (modified)**
  - `src/Api/Dto/RegisterImageRequest.php` — dodanie `?ProductMetadata $productMetadata` w konstruktorze + payload
  - `src/Api/Dto/ProductMetadata.php` (nowy) — value object współdzielony przez `RegisterImageRequest` i `RegisterPackshotRequest` w przyszłości
  - `qameraai.php` — rejestracja nowego hooka (np. `actionProductImage`); delegacja do `ProductImageSyncService`; toggle-gate + swallow-throw zgodnie z kontraktem Fazy 2
  - `src/Install/Installer.php` — dodanie nowego hooka do `self::HOOKS`
  - `config/services.yml` — rejestracja serwisów Fazy 3
- **DB**: brak zmian schematu — wszystkie kolumny są z Fazy 2.
- **External services**: pierwsze realne wywołania na `POST /images` (relative do base URL `https://qamera.ai/api/v1/plugin`). Smoke test wymaga konta plugin — credentials z `CLAUDE.md` (NIE skopiowane do żadnego tracked artifactu; trzymane wyłącznie w pliku CLAUDE.md i odczytywane ad-hoc przez operatora). Dopiero ten change uruchamia pole `qamera_product_id`.
- **Dependencies**: brak nowych.
- **Compatibility**: BO save action MUSI nadal działać przy padzie upstreama (5xx, network down). To definiuje czy rejestracja idzie synchronicznie w hooku (z full try/catch i swallow) czy asynchronicznie w cronie. **Open question — design.md.**
- **Docs**: README Phase plan → Phase 2 = "Done", Phase 3 = "In progress" przy starcie; CHANGELOG zostanie ruszony przy mergu Fazy 3 ([1.2.0] entry).
