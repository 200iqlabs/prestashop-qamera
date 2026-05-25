## Decision summary

| # | Decyzja | Wybór |
|---|---|---|
| 1 | Format `qamera_product_ref` | **`"ps:{id_shop}:{id_product}"`** (subject to open question — patrz niżej) |
| 2 | UNIQUE constraint na `qamera_product_ref` | Zostaje (ref jest deterministyczny z `(id_shop, id_product)`, UNIQUE działa jako defense-in-depth) |
| 3 | Język snapshotu metadanych | `Configuration::get('PS_LANG_DEFAULT', null, null, $idShop)` — default lang shopu |
| 4 | Zakres multi-shop dla pojedynczego wywołania hooka | Tylko bieżący `Context::getContext()->shop->id` (jeden wiersz per call) |
| 5 | Pola PS → snapshot mapping | `Product::name` → `display_name_snapshot`, `Product::reference` → `sku_snapshot`, `Product::description_short` → `description_snapshot` (truncate 5000) |
| 6 | Hook `actionProductDelete` | **Out of scope** — osobny change w przyszłości |
| 7 | Zachowanie `actionProductUpdate` przy `status='error'` | Refresh snapshotu, status pozostaje `error`. Reset do `pending` to manual operator action (przyszły change z UI w karcie produktu) |
| 8 | Co kiedy `actionProductAdd` strzela dla produktu, który już ma wiersz (rzadkie — recovery scenarios) | `INSERT … ON DUPLICATE KEY UPDATE` traktowane jak update — refresh snapshotu, status / qamera_product_id nietknięte |
| 9 | Container wiring | `ProductSnapshotWriter` jako Symfony service (`config/services.yml`), wstrzyknięty do `qameraai.php` przez `$this->get(ProductSnapshotWriter::class)` w hookach |
| 10 | Logowanie błędów | `PrestaShopLogger::addLog($msg, severity=2, errorCode=null, 'QameraAi-Module', $idObject=$idProduct, allowDuplicate=true)` |

## 1. Format `qamera_product_ref`

Wymagania:
- Deterministyczny — ten sam PS produkt zawsze daje ten sam ref (idempotency by-ref upstream wymaga stabilności)
- Unique w obrębie PS install — `(id_shop, id_product)` mogą się powtarzać tylko między różnymi PS instalacjami, ale jedna PS instalacja = jedno `installation_id` upstream, więc kolizji nie ma
- ASCII-safe — upstream `ProductRefSchema = z.string().min(1).max(200)`, bez constraintów na znaki, ale konserwatywnie trzymamy `[a-z0-9:_-]`
- Max 200 znaków (limit upstream) — wybrane formaty są <30 znaków nawet dla `id_product=999999999`

Rozważone opcje:

| Opcja | Przykład | Plus | Minus |
|---|---|---|---|
| **A. `ps:{id_shop}:{id_product}`** | `ps:1:42` | Platform-prefix daje czytelność w logach upstream, multi-shop disambiguation jasna | Trzy człony nieco verbose |
| B. `{id_shop}-{id_product}` | `1-42` | Krótkie | Bez prefixu — kolizje z hipotetycznymi innymi platformami (Magento itp.) gdyby kiedyś dzielili installation, choć obecnie nie dzielą |
| C. `(string) $idProduct` | `42` | Najkrótsze | Łamie się przy multi-shop (`id_shop=1, id_product=42` ≠ `id_shop=2, id_product=42`); UNIQUE constraint i tak by to wykrył ale to późna detekcja |
| D. `{shop_uuid_or_url}/products/{id_product}` | `qamera.local/products/42` | Self-documenting | Wymaga rezolwowania URL shopu, niestabilne przy zmianie domeny |

**Rekomendacja: A** — `"ps:{id_shop}:{id_product}"`. Tania w czytaniu, jednoznaczna, future-proof na wypadek gdyby Qamera kiedyś dzieliła installations między platformami.

**Open question dla użytkownika** — patrz na końcu tego dokumentu.

## 2. UNIQUE constraint na `qamera_product_ref`

Tabela już ma `UNIQUE KEY qamera_product_link_ref (qamera_product_ref)` z Phase 1. Zachowujemy:
- Ref jest deterministyczny → naturalnie unikalny.
- UNIQUE chroni przed regresjami w `ProductRefBuilder` (np. ktoś usuwa `id_shop` z formatu i wprowadza kolizje).
- `INSERT … ON DUPLICATE KEY` wykorzystuje **(id_product, id_shop)** UNIQUE jako klucz dedupe — to ten klucz, na którym zależy.

Nie zmieniamy nic w istniejących UNIQUE keys.

## 3. Język snapshotu

PS produkty mają multi-language `name`, `description_short`, `description`. Musimy wybrać jeden język per snapshot row.

Opcje:
- **A. Default language shopu** (`Configuration::get('PS_LANG_DEFAULT', null, null, $idShop)`) — stabilne, niezależne od bieżącego admina
- B. Język bieżącego admina (`Context::getContext()->language->id`) — niespójne między adminami pracującymi w różnych językach
- C. Wszystkie języki, JSON-encoded — overkill dla snapshotu, upstream `display_name` to single string

**Rekomendacja: A.** Default language shopu daje deterministyczny snapshot niezależny od tego, kto klika.

Edge case: produkt nie ma tłumaczenia w default lang (zwykle nie zdarza się w PS, bo wymuszone). Fallback: `Product::name` z pierwszego dostępnego języka, surowo cast do string, log warning.

## 4. Multi-shop scope per hook call

PS hook `actionProductAdd/Update` strzela raz per zapis w BO. `Context::getContext()->shop->id` daje shop, w którym admin obecnie pracuje. Produkt w PS multistore może być przypisany do wielu shopów (`Product::getAssociatedShops()`).

Opcje:
- **A. Tylko bieżący shop** — jeden insert per hook call, najprostsze, w 95% kontekstów PS = single-shop
- B. Wszystkie associated shops — pętla po `getAssociatedShops()`, N insertów per call

**Rekomendacja: A.** Powody:
- CLAUDE.md mówi: "Multistore is single-key (one API key per install). Per-shop credentials are a v2 follow-up." — czyli upstream installation_id jest globalny, więc i tak wszystkie shopy lecą do tej samej Qamera installation
- Operator który modyfikuje produkt w kontekście jednego shopu zwykle modyfikuje go też w pozostałych (kolejne zapisy = kolejne hook calle = kolejne wiersze)
- Pełna replikacja "all shops" przyniosłaby skomplikowanie bez czytelnej korzyści — multi-shop = follow-up, nie tu

Konsekwencja: jeśli produkt jest przypisany do shop_id=1 i shop_id=2, ale admin edytuje go tylko w kontekście shop=1, wiersz powstanie tylko dla `(id_product=X, id_shop=1)`. Wiersz dla shop=2 pojawi się dopiero gdy admin zapisze ten produkt w kontekście shop=2. Akceptowalne — `actionProductUpdate` strzela także przy "Save and stay" w obrębie shopu, więc edycje są wychwytywane.

## 5. Mapping PS fields → snapshot columns

| Snapshot column | PS source | Notes |
|---|---|---|
| `display_name_snapshot` | `Product::name[$idLangDefault]` | Required, NOT NULL. Cast to string. Max 500 (zgodne z upstream `ProductMetadataSchema.display_name.max(500)`). |
| `sku_snapshot` | `Product::reference` (single string, not per-language) | NULL jeśli `reference` jest puste. Max 100 (upstream limit). |
| `description_snapshot` | `Product::description_short[$idLangDefault]` | NULL jeśli puste. Truncate do 5000 znaków (upstream limit). HTML zostawiamy as-is — sanityzacja nie jest naszą rolą tutaj, robi to Phase 3 przy wysyłce. |

Pominięte:
- `Product::description` (long) — w 95% wypadków za długie i niepotrzebne dla `product_metadata`
- `Product::ean13`, `Product::upc`, ceny, weight — out of scope dla "kim jest ten produkt"; potencjalnie wleciałyby w `extra: Record<string, unknown>` w przyszłej iteracji

## 6. Hook `actionProductDelete`

**Nie dodajemy w tym change.** Powody:
- Wymagałby decyzji, co zrobić z istniejącym wierszem (soft-delete? hard-delete? log only?)
- Wymagałby kontaktu z upstream `DELETE /plugin/products/{ref}` — to network I/O, którego ten change unikamy
- Klean follow-up: `add-product-delete-hook` po Phase 3, gdy mamy działający sync flow

Konsekwencja: produkt skasowany w PS zostawia osierocony wiersz w `qamera_product_link`. Akceptowalne — Phase 3 / Phase 4 będą musiały to obsłużyć podczas reconciliation (`Product::existsInDatabase($idProduct)`).

## 7. Zachowanie `actionProductUpdate` przy `status='error'`

Scenariusz: poprzednia próba rejestracji (w Phase 3) skończyła się błędem (np. upstream zwrócił 422 na `product_metadata.display_name` za długą). Wiersz ma `status='error'`, `last_error_message='display_name exceeds 500 chars'`. Operator edytuje produkt, skraca nazwę, zapisuje.

Opcje:
- **A. Tylko refresh snapshotu, status zostaje `error`** — operator musi explicit "retry" (button w karcie produktu, Phase 4)
- B. Auto-reset do `pending` przy każdym update — ryzykuje retry-spam dla persistent errorów (np. źle skonfigurowany API key)

**Rekomendacja: A.** Konserwatywne. Faktyczna logika "retry" wymaga oddzielnego UI / cronu — nie ten change.

## 8. Insert vs update — atomicity

Hook `actionProductAdd` może w teorii odpalić dla produktu, który już ma wiersz (recovery z bugu, restore z backupu, dziwne migracje). Używamy `INSERT … ON DUPLICATE KEY UPDATE`:

```sql
INSERT INTO ps_qamera_product_link
  (id_product, id_shop, qamera_product_ref, display_name_snapshot, sku_snapshot, description_snapshot, status, created_at, updated_at)
VALUES (:id_product, :id_shop, :ref, :name, :sku, :desc, 'pending', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  display_name_snapshot = VALUES(display_name_snapshot),
  sku_snapshot = VALUES(sku_snapshot),
  description_snapshot = VALUES(description_snapshot),
  updated_at = NOW();
```

- `qamera_product_id`, `status`, `last_error_message`, `last_synced_at` **nie są dotykane** w klauzuli UPDATE — chronimy stan z downstream Phase 3.
- `created_at` ustawia się tylko na INSERT (klauzula UPDATE nie nadpisuje).
- Klucz dedupe: `UNIQUE(id_product, id_shop)`, nie `qamera_product_ref` (oba dają ten sam efekt bo ref jest deterministyczny z tej pary, ale `(id_product, id_shop)` jest semantyczne).

Hook `actionProductUpdate` używa tego samego query — dzięki temu jeden code path obsługuje też scenariusz "toggle był OFF przy Add, później ON i Update".

## 9. Container wiring

`config/services.yml`:

```yaml
QameraAi\Module\Sync\ProductRefBuilder:
    public: true

QameraAi\Module\Sync\ProductSnapshotWriter:
    public: true
    arguments:
        $db: '@=service("doctrine.dbal.default_connection")'  # OR via Db::getInstance() singleton
        $tablePrefix: '%qameraai.db_prefix%'
        $refBuilder: '@QameraAi\Module\Sync\ProductRefBuilder'
        $logger: '@=service("logger")'
```

Pytanie open: `Db::getInstance()` vs Doctrine DBAL connection. PS 8/9 oba mają. `Configuration` używa starego `Db::getInstance()`, więc spójność z resztą Phase 1 → idziemy w to. Doctrine DBAL byłby cleaner dla testów, ale wymaga dodatkowej decyzji o transakcjach.

**Wybór:** `Db::getInstance()` (singleton, używany w `Installer`). Testy używają `Db::setInstanceForTesting()` lub mock'a singletona (PHPUnit setUp/tearDown).

Hook delegation w `qameraai.php`:

```php
public function hookActionProductAdd(array $params): void
{
    if (!(bool) Configuration::get('QAMERAAI_AUTO_REGISTER_PRODUCTS')) {
        return;
    }
    /** @var Product|null $product */
    $product = $params['product'] ?? null;
    if (!$product instanceof Product) {
        return;
    }
    try {
        $this->get(ProductSnapshotWriter::class)->upsertFromProduct($product);
    } catch (\Throwable $e) {
        PrestaShopLogger::addLog(
            sprintf('[QameraAi] product snapshot write failed for id_product=%d: %s', $product->id, $e->getMessage()),
            2,
            null,
            'QameraAi-Module',
            (int) $product->id,
            true
        );
    }
}
```

## 10. Logging błędów

Format zgodny z PS conventions:
- Severity 2 (warning) — bookkeeping failure to nie crisis
- Context label `QameraAi-Module` — filtrowanie w logu BO
- `id_object` = `id_product` — operator widzi w logu, który produkt nie ma zapisanego snapshotu
- Wyjątek formatowany `get_class($e) . ': ' . $e->getMessage()` (bez stack trace — to nie crash)

Bez logowania success'ów — tabela bookkeepingu sama jest source of truth, log byłby spam.

## Test plan (high-level)

Pełna lista checklistowa idzie do `tasks.md`. Tu high-level:

- **Unit**:
  - `ProductRefBuilder` — dla `(id_shop=1, id_product=42)` zwraca `"ps:1:42"`; dla `id_shop=0` (przed inicjalizacją shop context) rzuca `InvalidArgumentException`
  - `ProductSnapshotWriter::upsertFromProduct` — INSERT na nowym, UPDATE na istniejącym z status=registered (status nietknięty), UPDATE na error (status nietknięty), exception swallowing przy DB failure
- **Integration** (PS bootstrapped, real Db):
  - install + 2× upgrade na świeżej DB → schema ma nowe kolumny, idempotency OK
  - hook flow: toggle=OFF → no row, toggle=ON → row z statusem pending; edycja produktu z toggle=ON → ten sam row, updated_at się zmienia
- **Manual smoke** (operator):
  - http://localhost:8080/admin-dev → włącz toggle → dodaj produkt → phpMyAdmin → wiersz istnieje, qamera_product_ref = `ps:{id_shop}:{id_product}`, status=pending

## Open question dla użytkownika

**Format `qamera_product_ref`** — z opcji A/B/C/D w sekcji 1, rekomenduję A (`"ps:{id_shop}:{id_product}"`). Czy akceptujesz, czy wolisz inną wersję? Jeśli akceptujesz, idziemy do `specs/`.
