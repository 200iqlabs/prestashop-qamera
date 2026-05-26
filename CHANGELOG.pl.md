# Lista zmian

Wszystkie istotne zmiany w module Qamera AI dla PrestaShop są opisane w tym pliku.

Format zgodny z [Keep a Changelog](https://keepachangelog.com/pl/1.1.0/), a projekt stosuje [Semantic Versioning](https://semver.org/lang/pl/).

Tłumaczenia: [english](CHANGELOG.md) · [українська](CHANGELOG.uk.md)

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

[1.1.0]: https://github.com/200iqlabs/prestashop-qamera/releases/tag/v1.1.0
[1.0.0]: https://github.com/200iqlabs/prestashop-qamera/releases/tag/v1.0.0
