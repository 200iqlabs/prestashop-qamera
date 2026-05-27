# Lista zmian

Wszystkie istotne zmiany w module Qamera AI dla PrestaShop są opisane w tym pliku.

Format zgodny z [Keep a Changelog](https://keepachangelog.com/pl/1.1.0/), a projekt stosuje [Semantic Versioning](https://semver.org/lang/pl/).

Tłumaczenia: [english](CHANGELOG.md) · [українська](CHANGELOG.uk.md)

## [1.3.0] — 2026-05-27

Faza 4.1 — odbiór i weryfikacja przychodzących webhooków. Moduł udostępnia teraz endpoint storefront, który uwierzytelnia dostawy z `qamera.ai` przez HMAC-SHA256 (z obsługą 48-godzinnego okna dual-sign przy rotacji sekretu po stronie upstreamu — wielokrotne `v1=` w nagłówku), wymusza okno replay ±300 s w przeszłość / 60 s w przyszłość, deduplikuje po `X-Qamera-Delivery-Id` przez nową tabelę `qamera_webhook_delivery` i zapisuje każdą zaakceptowaną dostawę jako substrat dla Fazy 4.2. PrestaShop 8.0–9.x, PHP 8.1+.

### Dodano

- **Trasa storefront** `POST /module/qameraai/webhook` (`controllers/front/webhook.php`, klasa `QameraaiWebhookModuleFrontController`). Bez uwierzytelniania z definicji — weryfikacja HMAC JEST uwierzytelnieniem. Wyłączona ochrona CSRF. Czyta surowe wejście z `php://input` raz, deleguje do framework-free rdzenia orkiestracji, emituje JSON przez `http_response_code()` + `echo` + `exit`. Pomija Smarty i silnik szablonów PS, dzięki czemu odpowiedź jest dokładnie taka, jaką zwrócił handler.
- **`QameraAi\Module\Webhook\WebhookRequestHandler`** — framework-free orkiestracja: method → secret skonfigurowany → parse nagłówka podpisu → delivery-id obecne → body w rozmiarze + dekodowanie JSON → zgodność delivery_id body/nagłówka → format event_type → weryfikacja HMAC → okno replay → zapis w repozytorium.
- **`HmacVerifier`** liczy `hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret)` i porównuje przez `hash_equals()` (brak `===` / `strcmp` / `strncmp` na bajtach podpisu). Iteruje wszystkie kandydatury `v1=` bez early break — czas zależy tylko od ich liczby.
- **`SignatureHeaderParser`** parsuje `t=<unix>,v1=<hex>[,v1=<hex>…]` na obiekt wartości `ParsedSignature`; wyrzuca `MalformedSignatureException` dla każdej zniekształconej ścieżki.
- **`ReplayGuard`** odrzuca dostawy ze znacznikiem czasu poza `[now-300s, now+60s]`. Asymetria okna jest celowa (zegary współdzielonych hostów PS częściej "zostają w tyle" niż "biegną do przodu").
- **`WebhookDeliveryRepository`** zapisuje zaakceptowane dostawy jednym `INSERT … ON DUPLICATE KEY UPDATE delivery_id=delivery_id`; rezultat (accepted vs duplicate) odczytuje z `Db::Affected_Rows()` (1 = nowy wiersz, 0 = no-op update). Na ścieżce duplicate jeden dodatkowy `SELECT` pobiera oryginalny `received_at`, żeby handler mógł zalogować go w warningu zgodnie ze specem.
- **Nowa tabela `{prefix}qamera_webhook_delivery`** — PK na `delivery_id VARCHAR(64)`, dodatkowy index na `(event_type, received_at)`. Tworzona w `Installer::createSchema()` i `upgrade/upgrade-1.3.0.php` (loguje błędy SQL na severity 3, jeśli `CREATE TABLE` padnie na starszych MariaDB z Antelope row-format).
- **`PrestaShopLoggerAdapter`** kieruje strukturalne linie logu (`info` / `warning` / `error`) do istniejącego kanału `QameraAiModule` przez współdzielony `PrestaShopLoggerWrapper`. Kody odrzuceń (`missing_signature`, `signature_mismatch`, `replay_window`, `body_too_large`, `secret_not_configured`, …) są tłumaczalnymi etykietami XLIFF w en/pl/uk pod Fazę 4.2.

### Zachowanie

- **Kontrakt ACK.** `200 {"status":"ok"}` na accept, `200 {"status":"duplicate"}` na zduplikowane potwierdzenie, `400` na zniekształcony podpis / okno replay / body / event_type / niezgodność delivery-id / body za duże, `401` na brak podpisu, `405` na metodę inną niż `POST`, `500` na błąd repozytorium ALBO brak sekretu po stronie serwera. Duplikaty i odrzucenia omijają dispatch — ścieżki odrzucenia NIGDY nie zapisują wiersza (anti-DoS).
- **Tolerancja multi-`v1=`.** Dostawa jest autentyczna, jeśli JAKAKOLWIEK wartość `v1=` w nagłówku zgadza się z lokalnie policzonym HMAC — wspiera 48-godzinne okno dual-sign rotacji upstreamu bez lokalnego przechowywania "poprzedniego sekretu".
- **Cap rozmiaru body.** `WebhookRequestHandler::MAX_BODY_BYTES = 65536`. Payloady > 64 KiB są odrzucane przed `json_decode`, żeby zapobiec OOM-DoS workerów PHP-FPM przez nieograniczone body podpisane wyciekłym sekretem.
- **Logi.** Zaakceptowane dostawy logują się na `info` z `delivery_id` i `event_type`; duplikaty na `warning` z dodatkowym oryginalnym `received_at`; odrzucenia na `error` ze strukturalnym kodem przyczyny. Linie logu nigdy nie zawierają wartości sekretu, policzonego HMAC ani pełnego body (sparametryzowane testy unitowe dla wszystkich ścieżek odrzucenia).
- **Aktywacja przez operatora.** Po deploy operator ustawia `callback_url` w panelu Qamera AI na `https://<shop>/module/qameraai/webhook` (lub legacy `index.php?fc=module&module=qameraai&controller=webhook`). Webhook secret wkleja w BO → Moduły → Qamera AI → Konfiguracja → Webhook secret.

### Świadome odłożenia (Faza 4.2 lub później)

- **Brak dispatchu.** Zweryfikowane dostawy są tylko zapisywane; Faza 4.2 (`add-webhook-event-dispatch`) konsumuje wiersze jako swoją kolejkę wejściową.
- **Brak lokalnego "poprzedniego sekretu".** Okno dual-sign po stronie upstreamu JEST handoffem rotacji; krótka świadoma niedostępność dla dostaw podpisanych starym sekretem po wklejeniu nowego jest zamierzona.
- **Brak UI replay w BO.** Operator korzysta z upstreamowego `/installations/{id}/replay/{delivery_id}`.
- **Brak konfigurowalnego algorytmu HMAC.** SHA-256 to jedyny algorytm w kontrakcie upstreamu.
- **Brak persystencji `status='rejected'`.** Nieautoryzowany endpoint zapisujący każdy nieprawidłowy request byłby wektorem fill-the-table DoS.

### Wskazówki operacyjne

- **`setEnvIf` w Apache** może być potrzebne na stackach, które obcinają niestandardowe nagłówki (`HTTP_X_QAMERA_SIGNATURE` / `HTTP_X_QAMERA_DELIVERY_ID`). Sekcja README "Phase 4.1 — Webhook handler" zawiera gotowy snippet.
- **NTP.** Skok odrzuceń `replay_window` zazwyczaj oznacza drift zegara — `timedatectl status` na Linuksie to pierwsze miejsce do sprawdzenia.

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
