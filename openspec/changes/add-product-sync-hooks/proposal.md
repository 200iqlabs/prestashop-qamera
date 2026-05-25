## Why

Phase 1 zostawił `hookActionProductAdd` / `hookActionProductUpdate` jako puste stuby (`qameraai.php:80-92`) i tabelę `qamera_product_link`, której nikt nigdy nie zapisuje. Plugin nie wie, które produkty PS w ogóle istnieją z punktu widzenia Qamera — w efekcie nawet po dodaniu API klienta w Phase 2 nie da się zrobić żadnego "sync wszystkich produktów" bez ponownego skanowania całego katalogu PS.

Po rozpoznaniu upstream API (`docs/knowledge/plugin-product-catalog.md` w `qamera-ai/saas-platform`) potwierdziliśmy dwie rzeczy istotne dla zakresu tego changu:

1. **`POST /plugin/products` nie istnieje.** Produkt po stronie Qamera powstaje **wyłącznie** jako side-effect `POST /plugin/images` lub `POST /plugin/packshots` z polem `product_metadata`. Każda próba "zarejestrowania samego produktu" wymagałaby uploadu jakiegoś asseta — czego w tym changu robić nie chcemy (to scope Phase 3).
2. **Toggle `QAMERAAI_AUTO_REGISTER_PRODUCTS`** istnieje od Phase 1 (default OFF). Komentarz w stubach hooków wskazuje go jako trigger dla tej funkcjonalności.

Dlatego ten change wprowadza tylko **lokalny bookkeeping** — kiedy operator w PS dodaje / aktualizuje produkt, plugin zapisuje snapshot jego metadanych do `qamera_product_link` z `status='pending'`. Późniejszy change w Phase 3 (`add-product-image-sync`) odczyta te wiersze przy okazji pierwszego uploadu zdjęcia produktu i dopiero **wtedy** zawoła `registerImage` z `product_metadata`, co cascading-utworzy produkt upstream. Bookkeeping rozdzielony od network I/O daje: zero spowolnień w BO, zero retry logic w hookach, czyste granice odpowiedzialności między changeami.

## What Changes

- **Hook `actionProductAdd`** — gdy `QAMERAAI_AUTO_REGISTER_PRODUCTS=1`, wstawia wiersz do `qamera_product_link` z `status='pending'`, `qamera_product_id=NULL`, snapshotem nazwy / SKU / opisu z encji `Product` w bieżącym kontekście (`id_shop`, język domyślny shopu). Gdy toggle OFF — no-op (zachowane Phase 1 zachowanie).
- **Hook `actionProductUpdate`** — gdy toggle ON i wiersz `qamera_product_link` istnieje: aktualizuje snapshot metadanych + ustawia `updated_at`. Jeśli wiersz nie istnieje (np. toggle był OFF przy `add` i potem włączony) — wstawia go tak jak `actionProductAdd`. Status nie jest dotykany przez `actionProductUpdate`: pozostawiamy `registered` na `registered` (tylko snapshot odświeżamy), `error` na `error` (operator musi rozwiązać przyczynę).
- **Service `ProductSnapshotWriter`** — jedna klasa wykonująca obie operacje (insert / update) na tabeli `qamera_product_link`. Hooki w `qameraai.php` delegują do niej; logika `id_shop` resolution + DB upsert + exception swallowing siedzi tam (testowalność).
- **Reguła "produkt PS zapisuje się zawsze"** — wszystkie wyjątki `PrestaShopException`, `\Throwable` z poziomu `ProductSnapshotWriter` są łapane w hooku i logowane przez `PrestaShopLogger::addLog` z severity 2 (warning). Awaria zapisu bookkeepingu **nigdy** nie blokuje zapisu produktu w BO.
- **Migracja schematu `qamera_product_link`** — wymagana, bo obecna struktura zakłada, że Qamera ID jest znane w momencie insertu (`qamera_product_id CHAR(36) NOT NULL`, `qamera_product_ref` UNIQUE NOT NULL). Lazy bookkeeping potrzebuje stanu "pending":
  - `qamera_product_id` → `CHAR(36) NULL` (wypełniane dopiero w Phase 3 po pierwszym `registerImage`)
  - `qamera_product_ref` → pozostaje `NOT NULL` ale generowany lokalnie z `id_shop:id_product` (format ustala design)
  - nowe kolumny: `display_name_snapshot VARCHAR(500) NOT NULL`, `sku_snapshot VARCHAR(100) NULL`, `description_snapshot TEXT NULL`, `status ENUM('pending','registered','error') NOT NULL DEFAULT 'pending'`, `last_error_message TEXT NULL`, `last_synced_at DATETIME NULL`
  - migracja idempotentna w `Installer::createSchema` (`ALTER TABLE … ADD COLUMN IF NOT EXISTS`, `MODIFY COLUMN qamera_product_id … NULL`); rollback w `dropSchema` jak dotąd `DROP TABLE`
- **Brak nowych dependencies, brak nowych endpointów upstream, brak callów sieciowych** w hot path hooków.

## Capabilities

### Modified Capabilities

- `prestashop-module-bootstrap` — schemat `qamera_product_link` zyskuje nullable `qamera_product_id` + kolumny statusu / snapshotu; lifecycle install/upgrade musi migrować istniejące instalacje (puste DB w MVP, ale CI sprawdza idempotencję `createSchema`).

### New Capabilities

- `product-sync-bookkeeping` — kontrakt: które zmiany w katalogu PS skutkują zapisem do `qamera_product_link`, jaki ma format `qamera_product_ref`, jak hooki reagują na awarię DB, jak status pomiędzy `pending`/`registered`/`error` przechodzi (w tym changu tylko `pending` — pozostałe stany ustawia downstream Phase 3, ale spec definiuje całą maszynę dla integralności).

## Impact

- **Code (new)**
  - `src/Sync/ProductSnapshotWriter.php` — service wstawiający / aktualizujący wiersz w `qamera_product_link`
  - `src/Sync/ProductRefBuilder.php` (mały helper) — generuje deterministyczny `qamera_product_ref` z `(id_shop, id_product)`
  - `tests/Unit/Sync/ProductSnapshotWriterTest.php` — pokrywa: insert nowego pending, update istniejącego, no-op przy `registered`, swallowing wyjątków DB
- **Code (modified)**
  - `qameraai.php` — `hookActionProductAdd` / `hookActionProductUpdate` delegują do `ProductSnapshotWriter`, opakowane w try/catch z logiem
  - `src/Install/Installer.php` — `createSchema` rozszerzony o migracje `ALTER TABLE` dla istniejących kolumn + dodanie nowych; sygnatura `dropSchema` bez zmian (kasuje całość tabeli)
  - `config/services.yml` — rejestracja `ProductSnapshotWriter` w container, wstrzyknięcie `Db::getInstance()` i `_DB_PREFIX_` (lub przez param)
- **DB**
  - `ALTER TABLE ps_qamera_product_link MODIFY COLUMN qamera_product_id CHAR(36) NULL`
  - `ALTER TABLE ps_qamera_product_link ADD COLUMN display_name_snapshot VARCHAR(500) NOT NULL`
  - `ALTER TABLE ps_qamera_product_link ADD COLUMN sku_snapshot VARCHAR(100) NULL`
  - `ALTER TABLE ps_qamera_product_link ADD COLUMN description_snapshot TEXT NULL`
  - `ALTER TABLE ps_qamera_product_link ADD COLUMN status ENUM('pending','registered','error') NOT NULL DEFAULT 'pending'`
  - `ALTER TABLE ps_qamera_product_link ADD COLUMN last_error_message TEXT NULL`
  - `ALTER TABLE ps_qamera_product_link ADD COLUMN last_synced_at DATETIME NULL`
  - Unikalność: pozostawiamy `(id_product, id_shop)` jako klucz lokalny, `qamera_product_ref` traci `UNIQUE NOT NULL` constraint (lub: jest stable + computable, więc UNIQUE może zostać — design rozstrzygnie)
- **Compatibility:** brak zmian w API client, brak zmian w endpointach upstream; migracja w `createSchema` jest idempotentna i przechodzi również na świeżej instalacji (NULL `qamera_product_id` na nowych wierszach nie kłóci się z niczym dalej, bo Phase 3 jest jedynym konsumentem).
- **Dependencies:** brak nowych. PSR-12, PHPStan level 5, PHPUnit już są.
- **External services:** żadne. Ten change nie dotyka `https://qamera.ai/api/v1/plugin`. Smoke przed mergem to ręczne dodanie / edycja produktu w `http://localhost:8080/admin-dev` przy toggle ON, sprawdzenie wpisu w phpMyAdmin.
- **Docs:** `README.md` — Phase plan dostaje notatkę "Phase 2 — Core flow (in progress, bookkeeping)" zamiast "Pending"; sam fakt że plugin wciąż nie wysyła nic do Qamera trzeba podkreślić w CHANGELOG. `docs/decisions/` nie ruszamy — duża decyzja o lazy bookkeeping mieści się w spec'u tej capability.
