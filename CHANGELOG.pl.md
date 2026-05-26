# Lista zmian

Wszystkie istotne zmiany w module Qamera AI dla PrestaShop są opisane w tym pliku.

Format zgodny z [Keep a Changelog](https://keepachangelog.com/pl/1.1.0/), a projekt stosuje [Semantic Versioning](https://semver.org/lang/pl/).

Tłumaczenia: [english](CHANGELOG.md) · [українська](CHANGELOG.uk.md)

## [1.2.0] — 2026-05-26

Faza 3 — pierwsza synchronizacja upstream: wgrywanie zdjęcia produktu w back-office rejestruje teraz ten produkt po stronie Qamera AI Plugin API. Wiersze `qamera_product_link` z Fazy 2 wreszcie zaczynają wypełniać kolumnę `qamera_product_id`. PrestaShop 8.0–9.x, PHP 8.1+.

### Dodano

- **Handler hooka `actionWatermark`.** PS 8/9 wystrzeliwują `actionWatermark` po uploadzie obrazu produktu (PS 9 nie ma już `actionProductImage`). Handler jest triggerem dla synchronizacji upstream. Gated na istniejącym togglu `QAMERAAI_AUTO_REGISTER_PRODUCTS`; ten sam kontrakt swallow-throw + log severity 2 co hooki snapshotowe z Fazy 2 — zapis w BO zawsze kończy się sukcesem, niezależnie od stanu upstreamu.
- **`QameraAi\Module\Sync\ProductImageSyncService`** — orkiestruje pełny przepływ: czyta wiersz bookkeepingu, wybiera "primary" obraz (cover wygrywa z hint-image z hooka), pobiera presigned upload, PUT-uje bajty obrazu, woła `POST /images` z `product_metadata` (cascade-create) lub bez (ścieżka bare-image dla wierszy `registered`), zapisuje wynik. Dedup in-memory po `(id_product, id_image)`, żeby bulk-regenerate w PS nie wystrzelił wielokrotnych wywołań upstream.
- **`QameraAi\Module\Sync\PrimaryImageResolver`** — łańcuch cover → hint z hooka → pierwszy by position. Zwraca `id_image` jako int (nie instancję PS `Image`), żeby reszta pipeline'u została niezależna od kształtu tablic PS.
- **`QameraAi\Module\Sync\PresignedImageUploadStrategy`** — opakowuje `QameraApiClient::requestUpload` + surowy PUT na dedykowanym kliencie Guzzle (osobnym od klienta API, więc timeouts/nagłówki dla PUT mogą się różnić od autentykowanego ruchu JSON). Odświeża presigned URL raz, jeśli już wygasł (clock drift).
- **`QameraAi\Module\Api\Dto\ProductMetadata`** — value object dla payloadu `product_metadata` upstreama. Wymusza upstream-owe limity rozmiarów (`display_name ≤ 500`, `sku ≤ 100`, `description ≤ 5000`) w konstruktorze, żeby callery nie zbudowały nieprawidłowego payloadu w runtime. Mieszka obok innych DTO, więc przyszły `RegisterPackshotRequest` może go reużyć.
- **`RegisterImageRequest` akceptuje `?ProductMetadata`.** Nowy opcjonalny parametr konstruktora na ostatniej pozycji; payload całkowicie pomija `product_metadata`, gdy null (klucz nieobecny, nie `null`).
- **`ImageResponse.productId`.** Nowe opcjonalne pole eksponujące UUID produktu nadane przez upstream w odpowiedziach cascade-create.

### Zachowanie

- **Przejścia stanów `qamera_product_link.status`.** Faza 3 faktycznie napędza maszynę stanów: `pending → registered` przy udanym cascade-create, `pending → error` przy dowolnej porażce w upload / PUT / register, `error → registered` przy ponownej próbie kończącej się sukcesem. Na wierszu `registered` kolejne obrazy bumpują tylko `last_synced_at` — `qamera_product_id` nigdy nie jest nadpisywany.
- **Sanityzowany `last_error_message`.** Typy wyjątków upstreamu mapują się na deterministyczne komunikaty dla operatora: `Upstream validation: …`, `API credentials invalid (HTTP 401). Check API key in module configuration.`, `Rate limit exceeded — try again later. (HTTP 429)`, `Upstream server error (HTTP 5xx) after retries. Try again later.`, `Network error reaching Qamera AI: …` oraz `Unexpected: <Class>: <message>` dla całej reszty. Zawsze ucinany do 500 znaków.
- **Brak wiersza bookkeepingu — no-op.** Jeśli operator włączył toggle po utworzeniu produktu, kolejny upload obrazu nie znajduje wiersza `qamera_product_link` i loguje diagnostykę severity-info bez rejestracji. Następny `actionProductSave` utworzy wiersz, a następny upload obrazu zarejestruje produkt normalnie.

### Zmieniono

- **`QameraApiClient` nie jest już `final`.** Zdjęte, żeby testy jednostkowe mogły zmockować klienta. Klient nadal ma tylko jedną ścieżkę produkcyjną wywołań; nic innego nie polega na zamknięciu klasy.

### Znane ograniczenia

- Ręczny retry `error → pending` z BO nie jest jeszcze wpięty — operatorzy retryują przez upload kolejnego obrazu lub czekają na UI karty produktu z Fazy 4.
- Detekcja regresji `registered → error` (wcześniej-udany wiersz, który powinien re-syncnąć) to również teren Fazy 4 — wymaga przebiegu rekoncyliacji w cronie.
- Multistore: `actionWatermark` strzela tylko w kontekście aktywnego shopu, jak w Fazie 2. Fan-out cross-shop to follow-up.

## [1.1.0] — 2026-05-25

Faza 2 — lokalny bookkeeping: moduł zapisuje teraz lokalny snapshot każdego produktu, który operator zapisze w back-office. Żadne wywołania API Qamera AI jeszcze się nie dzieją — to pojawi się w Fazie 3 (image-sync). PrestaShop 8.0–9.x, PHP 8.1+.

### Dodano

- **Handler hooka `actionProductSave`.** Wystrzeliwany przez `Product::add()` i `Product::update()` w PS 8/9 — jest to główne wejście dla nowotworzonych produktów. Legacy hook `actionProductAdd` w PS 9 jest dispatchowany tylko przez `ProductDuplicator`, więc rejestracja `actionProductSave` była konieczna, aby pokryć tworzenie produktów w BO.
- **Nowe kolumny w `ps_qamera_product_link`.** Sześć nowych kolumn: `display_name_snapshot VARCHAR(500) NOT NULL`, `sku_snapshot VARCHAR(100) NULL`, `description_snapshot TEXT NULL`, `status ENUM('pending','registered','error') NOT NULL DEFAULT 'pending'`, `last_error_message TEXT NULL`, `last_synced_at DATETIME NULL`. Istniejąca kolumna `qamera_product_id` została poluzowana z `NOT NULL` na `NULL` — pozostaje pusta dopóki rejestracja upstream w Fazie 3 nie powiedzie się.
- **Idempotentna migracja schematu.** `Installer::createSchema` introspektuje `INFORMATION_SCHEMA.COLUMNS` i wykonuje `ALTER` tylko dla brakujących lub niepasujących kolumn, dzięki czemu kolejne instalacje i aktualizacje z Fazy 1 są bezpieczne. Niepowodzenie introspekcji przerywa teraz instalację zamiast cicho zostawiać stary schemat.
- **`QameraAi\Module\Sync\ProductSnapshotWriter`** — pojedyncze `INSERT … ON DUPLICATE KEY UPDATE` kluczowane na `UNIQUE(id_product, id_shop)`. Klauzula UPDATE odświeża wyłącznie kolumny snapshotu i `updated_at`; `status`, `qamera_product_id`, `last_error_message`, `last_synced_at`, `qamera_product_ref`, `created_at` pozostają nietknięte, więc stan downstream sync nigdy się nie cofa.
- **`QameraAi\Module\Sync\ProductRefBuilder`** — deterministyczny `qamera_product_ref` w formacie `ps:{id_shop}:{id_product}`. Multistore-safe (różne sklepy dają różne refy); odrzuca nieprawidłowe id.

### Zachowanie

- Bookkeeping w hookach jest bramkowany istniejącym przełącznikiem `QAMERAAI_AUTO_REGISTER_PRODUCTS` (domyślnie OFF od Fazy 1). Przełącznik OFF to prawdziwy no-op.
- Każdy `\Throwable` z writera jest łapany w hooku i logowany przez `PrestaShopLogger::addLog` z severity 2 i `object_type='QameraAiModule'`. Zapis produktu w BO zawsze kończy się sukcesem z punktu widzenia operatora, niezależnie od stanu bookkeepingu.
- Snapshot czytany jest w domyślnym języku sklepu (`Configuration::get('PS_LANG_DEFAULT', null, null, $idShop)`); gdy brak tłumaczenia, writer wraca do pierwszej dostępnej wartości i loguje ostrzeżenie.

### Zmieniono

- **Brak wpływu na API upstream.** Powierzchnia `QameraApiClient`, endpointy `/plugin/*` i handler webhooków nie są tym wydaniem dotknięte.

### Znane ograniczenia

- Tworzenie nowego produktu nadal wymaga akcji "Zapisz" w BO; osierocone wiersze z `Product::delete()` nie są jeszcze sprzątane (`actionProductDelete` pojawi się w kolejnym change).
- Wiersze ze `status='error'` odświeżają snapshot przy edycji, ale nie auto-retryują — retry sterowany przez operatora pojawi się z UI w karcie produktu (Faza 4).

## [1.0.0] — 2026-05-24

Pierwsze wydanie. Wprowadza przechowywanie poświadczeń, instalowalny cykl życia modułu oraz przetestowanego klienta HTTP do Qamera AI Plugin API. PrestaShop 8.0–9.x, PHP 8.1+.

### Dodano

- **Stronę konfiguracyjną w back-office** pod *Ulepszanie → Qamera AI*. Przechowuje bazowy URL API, klucz API, sekret webhooka, przełącznik automatycznej rejestracji nowych produktów oraz rozmiar partii synchronizacji. Sekrety renderują się zamaskowane; wysłanie formularza bez edycji zamaskowanego pola nie nadpisuje zapisanej wartości.
- **Instalator modułu** — tworzy dwie tabele MySQL (`qamera_product_link`, `qamera_packshot_link`), rejestruje cztery hooki PrestaShop (`actionProductAdd`, `actionProductUpdate`, `displayAdminProductsExtra`, `displayBackOfficeHeader`) i zasiewa pięć wartości domyślnych konfiguracji. Odinstalowanie odwraca każdy z tych kroków.
- **Typowany klient HTTP do Qamera AI Plugin API.** Jedna metoda na każdy używany endpoint (`me`, odczyty katalogu, rejestracja obrazu i pakshotu, presigned upload, submit job, odczyty produktów). Uwierzytelnianie, retry, generowanie idempotency-key na zapisach oraz dekodowanie kopert błędów są wbudowane — wywołujący nigdy nie widzi surowego wyjątku Guzzle.
- **Przycisk testowania połączenia** na stronie konfiguracji. Wykonuje POST do osobnego adminowego endpointu z ochroną CSRF, woła `GET /me` zapisanymi poświadczeniami i renderuje wynik bezpośrednio na stronie (nazwa konta, saldo kredytów, plan subskrypcji, platforma i status instalacji). Formularz zapisu nie jest tym dotykany.
- **Polskie i ukraińskie tłumaczenia** stringów back-office.
- **Matryca CI** na PHP 8.1 / 8.2 / 8.3 (PHPCS PSR-12, PHPStan poziom 5 z załadowanym core'em PrestaShop przez `_PS_ROOT_DIR_`, PHPUnit).

### Znane ograniczenia

- Handlery hooków to puste szkielety; synchronizacja produktów, przepływy submitów pakshotów oraz obsługa webhooków pojawią się w kolejnych fazach.
- Multistore działa na jednym kluczu (jeden klucz API na instalację). Poświadczenia per-sklep to follow-up w v2.
- Strona konfiguracji pozwala edytować sekrety, ale nie pozwala na rotację HMAC webhooka — to operacja po stronie panelu Qamera AI.

[1.2.0]: https://github.com/200iqlabs/prestashop-qamera/releases/tag/v1.2.0
[1.1.0]: https://github.com/200iqlabs/prestashop-qamera/releases/tag/v1.1.0
[1.0.0]: https://github.com/200iqlabs/prestashop-qamera/releases/tag/v1.0.0
