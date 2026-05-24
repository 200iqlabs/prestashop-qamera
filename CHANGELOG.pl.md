# Lista zmian

Wszystkie istotne zmiany w module Qamera AI dla PrestaShop są opisane w tym pliku.

Format zgodny z [Keep a Changelog](https://keepachangelog.com/pl/1.1.0/), a projekt stosuje [Semantic Versioning](https://semver.org/lang/pl/).

Tłumaczenia: [english](CHANGELOG.md) · [українська](CHANGELOG.uk.md)

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

[1.0.0]: https://github.com/200iqlabs/prestashop-qamera/releases/tag/v1.0.0
