# Журнал змін

У цьому файлі задокументовано всі суттєві зміни в модулі Qamera AI для PrestaShop.

Формат відповідає [Keep a Changelog](https://keepachangelog.com/uk/1.1.0/), проєкт дотримується [Semantic Versioning](https://semver.org/lang/uk/).

Переклади: [english](CHANGELOG.md) · [polski](CHANGELOG.pl.md)

## [Unreleased]

### Додано — тестова інфраструктура

- **Інтеграційний рівень тестування з бутом ядра PS9** у `tests/Integration/`. Новий bootstrap (`tests/Integration/bootstrap.php`) піднімає справжнє ядро `AdminKernel` PrestaShop 9 усередині dev-контейнера, відкриває скомпільований Symfony-контейнер, фіксує контекст крамниці на shop 1, перенаправляє `QAMERAAI_API_BASE_URL` на зарезервований RFC 2606 хост `http://qamera-test.invalid` (будь-яке забуте перевизначення API-клієнта падає на DNS, а не витікає реальними обліковими даними) і прибирає залишки записів із префіксом `TEST-` після перерваних попередніх запусків. Suite запускається через `vendor/bin/phpunit -c phpunit.integration.xml.dist` із `failOnSkipped="true"`.
- **Фабрики фікстур** — `ProductFactory`, `ImageFactory`, `BookkeepingFactory` — і базовий клас `IntegrationTestCase` з помічниками `rebindContainerService` / `setConfigurationOverride`, що автоматично відновлюють стан. Маркер на тест (`bin2hex(random_bytes(4))`) керує адресним DELETE у tearDown, тож тести не залежать від порядку виконання.
- **Покриття регресій Фази 3 (smoke)** — три нові тести (`ProductImageSyncIntegrationTest`, `PrimaryImageResolverIntegrationTest`, `IdempotencyKeyGeneratorIntegrationTest`) відтворюють три класи помилок, які виявив операторський smoke Фази 3 (семантика `Db::getRow` з авто-LIMIT 1, розв’язання константи `_PS_PRODUCT_IMG_DIR_`, фолбек автозавантажувача `Uuid::uuid7`/`uuid4`). Кожен підтверджено наживо в CI: помилку навмисно повторно вводили scratch-комітом і спостерігали падіння відповідного тесту перед revert.
- **Нова робота CI** `Integration (PS9 kernel)` паралельно до матриці static-analysis (PHP 8.1/8.2/8.3). Піднімає PS9 + MySQL 8 через інлайновий `.ci/docker-compose.integration.yml`, виконує `prestashop:module install qameraai`, запускає suite. Активна лише для PR-ів з того ж репозиторію.

### Змінено — тестова дисципліна

- `phpunit.xml.dist` більше не містить testsuite `integration` і блок `<groups><exclude><group>integration</group></exclude></groups>` — стандартне `vendor/bin/phpunit` тепер запускає тільки unit + contract.
- Робота static-analysis у CI явно викликає `vendor/bin/phpunit --testsuite=unit,contract`, щоб контракт був задокументований у файлі workflow.

### Рефакторинг — внутрішній

- Дедуп-кеш `(idProduct, idImage)` у `ProductImageSyncService` тепер ін’єктується як сервіс `InMemoryDedupCache`, а не як приватне поле-масив. Без зміни поведінки; інтеграційні тести можуть підміняти кеш на кожен тест через контейнер.
- `IdempotencyKeyGenerator` виставляє метод `protected hasUuid7(): bool` поверх `method_exists(Uuid::class, 'uuid7')`, щоб інтеграційний suite міг підкласувати клас і примусово пройти продакшен-гілку фолбеку `uuid4`.

### Нотатки операторам

- Жодних змін, видимих користувачу. Жодних нових поверхонь у BO. Жодних нових HTTP-ендпоїнтів. Чиста тестова інфраструктура і один внутрішній рефакторинг.
- Новий інтеграційний suite запускається лише в dev-контейнері; не входить у стандартне `vendor/bin/phpunit`, тож швидкість локального inner-loop не змінюється.

## [1.3.0] — 2026-05-27

Фаза 4.1 — приймання та верифікація вхідних вебхуків. Модуль тепер відкриває storefront-ендпоїнт, який автентифікує доставки з `qamera.ai` через HMAC-SHA256 (з підтримкою 48-годинного вікна dual-sign під час ротації секрету апстрімом — кілька `v1=` у заголовку), застосовує вікно replay ±300 с у минуле / 60 с у майбутнє, дедуплікує за `X-Qamera-Delivery-Id` через нову таблицю `qamera_webhook_delivery` і зберігає кожну прийняту доставку як субстрат для Фази 4.2. PrestaShop 8.0–9.x, PHP 8.1+.

### Додано

- **Маршрут storefront** `POST /module/qameraai/webhook` (`controllers/front/webhook.php`, клас `QameraaiWebhookModuleFrontController`). Без автентифікації за дизайном — верифікація HMAC Є автентифікацією. Звільнено від CSRF. Читає сирий вхід з `php://input` один раз, делегує framework-free ядру оркестрації, видає JSON через `http_response_code()` + `echo` + `exit`. Обходить Smarty та шаблонний рушій PS, тож тіло відповіді — байт-точне.
- **`QameraAi\Module\Webhook\WebhookRequestHandler`** — framework-free оркестрація: метод → налаштований секрет → парсинг заголовка підпису → delivery-id присутній → розмір body + декодування JSON → збіг delivery_id body/заголовка → формат event_type → верифікація HMAC → вікно replay → запис у репозиторій.
- **`HmacVerifier`** обчислює `hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret)` і порівнює через `hash_equals()` (без `===` / `strcmp` / `strncmp` для байтів підпису). Ітерує всі кандидати `v1=` без раннього виходу — час залежить лише від їхньої кількості.
- **`SignatureHeaderParser`** парсить `t=<unix>,v1=<hex>[,v1=<hex>…]` у типізований об'єкт значення `ParsedSignature`; кидає `MalformedSignatureException` для кожного зіпсованого варіанту.
- **`ReplayGuard`** відхиляє доставки з підписаним часом поза `[now-300s, now+60s]`. Асиметрія вікна навмисна (годинники на спільних хостингах PS частіше "відстають", ніж "поспішають").
- **`WebhookDeliveryRepository`** зберігає прийняті доставки одним `INSERT … ON DUPLICATE KEY UPDATE delivery_id=delivery_id`; результат (accepted vs duplicate) читає з `Db::Affected_Rows()` (1 = новий рядок, 0 = no-op update). На дублюючій гілці один додатковий `SELECT` дістає оригінальний `received_at`, щоб обробник міг залогувати його у warning згідно зі специфікацією.
- **Нова таблиця `{prefix}qamera_webhook_delivery`** — PK на `delivery_id VARCHAR(64)`, вторинний індекс на `(event_type, received_at)`. Створюється в `Installer::createSchema()` та `upgrade/upgrade-1.3.0.php` (логує помилки SQL з severity 3, якщо `CREATE TABLE` падає на старших MariaDB з Antelope row-format).
- **`PrestaShopLoggerAdapter`** маршрутизує структуровані лог-рядки (`info` / `warning` / `error`) у наявний канал `QameraAiModule` через спільний `PrestaShopLoggerWrapper`. Коди відмов (`missing_signature`, `signature_mismatch`, `replay_window`, `body_too_large`, `secret_not_configured`, …) — перекладні XLIFF-етикетки в en/pl/uk для Фази 4.2.

### Поведінка

- **Контракт ACK.** `200 {"status":"ok"}` для accept, `200 {"status":"duplicate"}` для дублювання, `400` для зіпсованого підпису / вікна replay / body / event_type / неузгодженості delivery-id / занадто великого body, `401` для відсутнього підпису, `405` для методу, відмінного від `POST`, `500` для відмови репозиторію АБО відсутнього серверного секрету. Дублікати та відхилення обходять диспетч — гілки відхилень НІКОЛИ не зберігають рядок (anti-DoS).
- **Толерантність multi-`v1=`.** Доставка автентична, якщо БУДЬ-ЯКЕ `v1=` у заголовку збігається з локально обчисленим HMAC — підтримує 48-годинне вікно dual-sign ротації апстріму без локального "попереднього секрету".
- **Обмеження розміру body.** `WebhookRequestHandler::MAX_BODY_BYTES = 65536`. Payload-и > 64 KiB відхиляються до `json_decode`, щоб запобігти OOM-DoS воркерів PHP-FPM через необмежене body, підписане витеклим секретом.
- **Логи.** Прийняті доставки логуються на `info` з `delivery_id` і `event_type`; дублікати на `warning` з оригінальним `received_at`; відхилення на `error` зі структурованим кодом причини. Лог-рядки ніколи не містять значення секрету, обчисленого HMAC чи повного body (параметризовані тести покривають усі гілки відхилень).
- **Активація оператором.** Після деплою оператор встановлює `callback_url` у панелі Qamera AI на `https://<shop>/module/qameraai/webhook` (або legacy `index.php?fc=module&module=qameraai&controller=webhook`). Webhook-секрет вставляє в БО → Модулі → Qamera AI → Конфігурація → Webhook secret.

### Свідомі відстрочки (Фаза 4.2 або пізніше)

- **Без диспетчу.** Перевірені доставки лише зберігаються; Фаза 4.2 (`add-webhook-event-dispatch`) споживає рядки як свою вхідну чергу.
- **Без локального "попереднього секрету".** Вікно dual-sign в апстрімі Є хендоффом ротації; коротка усвідомлена недоступність для доставок зі старим секретом після вставлення нового — навмисна.
- **Без UI replay у БО.** Оператор використовує апстрімовий `/installations/{id}/replay/{delivery_id}`.
- **Без налаштовуваного алгоритму HMAC.** SHA-256 — єдиний у контракті апстріму.
- **Без персистенції `status='rejected'`.** Неавтентифікований ендпоїнт, що зберігає кожен невалідний запит, був би вектором fill-the-table DoS.

### Операційні нотатки

- **Apache `setEnvIf`** може знадобитися на стеках, які зрізають кастомні заголовки (`HTTP_X_QAMERA_SIGNATURE` / `HTTP_X_QAMERA_DELIVERY_ID`). Секція README "Phase 4.1 — Webhook handler" містить готовий сніппет.
- **NTP.** Сплеск відхилень `replay_window` зазвичай означає дрейф годинника — `timedatectl status` на Linux-хостах — перше місце для перевірки.

## [1.2.0] — 2026-05-26

Фаза 3 — перша синхронізація з upstream: завантаження зображення товару в бек-офісі тепер реєструє цей товар у Qamera AI Plugin API. Рядки `qamera_product_link` із Фази 2 нарешті починають заповнювати колонку `qamera_product_id`. PrestaShop 8.0–9.x, PHP 8.1+.

### Додано

- **Обробник хука `actionWatermark`.** PS 8/9 спрацьовує `actionWatermark` після завантаження зображення товару (у PS 9 більше немає `actionProductImage`). Обробник є тригером синхронізації з upstream. Гейтиться існуючим перемикачем `QAMERAAI_AUTO_REGISTER_PRODUCTS`; той самий контракт swallow-throw + лог severity 2, що й для хуків снапшоту з Фази 2 — збереження в БО завжди завершується успіхом незалежно від стану upstream.
- **`QameraAi\Module\Sync\ProductImageSyncService`** — оркеструє повний потік: читає рядок bookkeeping, обирає "primary" зображення (cover виграє в підказки з хука), запитує presigned upload, PUT-ить байти зображення, викликає `POST /images` з `product_metadata` (каскадне створення) або без нього (шлях bare-image для рядків `registered`), і записує результат. In-memory дедуплікація за `(id_product, id_image)`, щоб bulk-regenerate у PS не запускав повторні виклики до upstream.
- **`QameraAi\Module\Sync\PrimaryImageResolver`** — ланцюжок cover → підказка з хука → перше за position. Повертає `id_image` як int (не екземпляр PS `Image`), щоб решта пайплайну залишалася незалежною від форми масивів PS.
- **`QameraAi\Module\Sync\PresignedImageUploadStrategy`** — обгортає `QameraApiClient::requestUpload` + сирий PUT на виділеному Guzzle-клієнті (окремому від клієнта API, тож таймаути / заголовки для PUT можуть відрізнятися від автентифікованого JSON-трафіку). Один раз оновлює presigned URL, якщо він уже прострочений (clock drift).
- **`QameraAi\Module\Api\Dto\ProductMetadata`** — value object для payload-у `product_metadata` upstream. У конструкторі дотримує upstream-обмеження розміру (`display_name ≤ 500`, `sku ≤ 100`, `description ≤ 5000`), щоб виклики не могли побудувати недійсний payload у runtime. Живе поруч з іншими DTO, тож майбутній `RegisterPackshotRequest` зможе його повторно використати.
- **`RegisterImageRequest` приймає `?ProductMetadata`.** Новий опціональний параметр конструктора в останній позиції; payload повністю опускає `product_metadata`, коли null (ключ відсутній, не `null`).
- **`ImageResponse.productId`.** Нове опціональне поле з UUID товару, який upstream повертає у відповідях каскадного створення.

### Поведінка

- **Переходи стану `qamera_product_link.status`.** Фаза 3 фактично рухає стан-машину: `pending → registered` при успішному каскадному створенні, `pending → error` при будь-якій невдачі в upload / PUT / register, `error → registered` при наступній успішній повторній спробі. На рядку `registered` подальші зображення оновлюють лише `last_synced_at` — `qamera_product_id` ніколи не перезаписується.
- **Санітизоване `last_error_message`.** Типи виключень upstream відображаються на детерміновані повідомлення для оператора: `Upstream validation: …`, `API credentials invalid (HTTP 401). Check API key in module configuration.`, `Rate limit exceeded — try again later. (HTTP 429)`, `Upstream server error (HTTP 5xx) after retries. Try again later.`, `Network error reaching Qamera AI: …`, та `Unexpected: <Class>: <message>` для решти. Завжди обрізане до 500 символів.
- **Відсутність рядка bookkeeping — no-op.** Якщо оператор увімкнув перемикач після створення товару, наступне завантаження зображення не знаходить рядка `qamera_product_link` і логує info-severity діагностику без реєстрації. Наступний `actionProductSave` створить рядок, і наступне завантаження зображення зареєструє товар нормально.

### Змінено

- **`QameraApiClient` більше не `final`.** Знято, щоб юніт-тести могли мокати клієнта. Клієнт усе ще має лише один продакшн-шлях викликів; ніщо інше не покладається на закритість класу.

### Відомі обмеження

- Ручний retry `error → pending` з БО ще не підключено — оператори перезапускають через завантаження іншого зображення або чекають на UI вкладки товару з Фази 4.
- Виявлення регресії `registered → error` (раніше-успішний рядок, який має повторно синхронізуватися) також належить до Фази 4 — потребує прогону рекреконсиляції в cron-і.
- Multistore: `actionWatermark` спрацьовує лише в контексті активного магазину, як у Фазі 2. Cross-shop fan-out — фолоу-ап.

## [1.1.0] — 2026-05-25

Фаза 2 — локальний bookkeeping: модуль тепер записує локальний снапшот кожного товару, який оператор зберігає в бек-офісі. Жодних звернень до API Qamera AI ще не відбувається — це з'явиться у Фазі 3 (image-sync). PrestaShop 8.0–9.x, PHP 8.1+.

### Додано

- **Обробник хука `actionProductSave`.** Спрацьовує і для `Product::add()`, і для `Product::update()` у PS 8/9 — основна точка входу для нових товарів. Застарілий хук `actionProductAdd` у PS 9 диспатчиться лише з `ProductDuplicator`, тож реєстрація `actionProductSave` була необхідна, щоб покрити створення товарів у БО.
- **Нові колонки в `ps_qamera_product_link`.** Шість нових колонок: `display_name_snapshot VARCHAR(500) NOT NULL`, `sku_snapshot VARCHAR(100) NULL`, `description_snapshot TEXT NULL`, `status ENUM('pending','registered','error') NOT NULL DEFAULT 'pending'`, `last_error_message TEXT NULL`, `last_synced_at DATETIME NULL`. Наявна колонка `qamera_product_id` була послаблена з `NOT NULL` до `NULL` — вона лишається порожньою, поки upstream-реєстрація у Фазі 3 не завершиться успіхом.
- **Ідемпотентна міграція схеми.** `Installer::createSchema` опитує `INFORMATION_SCHEMA.COLUMNS` і виконує `ALTER` лише для відсутніх або невідповідних колонок, тому повторні інсталяції/оновлення з Фази 1 є безпечними. Невдала перевірка зараз перериває інсталяцію замість того, щоб мовчки лишити стару схему.
- **`QameraAi\Module\Sync\ProductSnapshotWriter`** — один `INSERT … ON DUPLICATE KEY UPDATE` з ключем `UNIQUE(id_product, id_shop)`. Гілка UPDATE оновлює лише колонки снапшоту та `updated_at`; `status`, `qamera_product_id`, `last_error_message`, `last_synced_at`, `qamera_product_ref`, `created_at` зберігаються між апсертами, тож стан синхронізації нижче за течією ніколи не регресує.
- **`QameraAi\Module\Sync\ProductRefBuilder`** — детермінований `qamera_product_ref` у форматі `ps:{id_shop}:{id_product}`. Multistore-safe (різні магазини дають різні refи); відхиляє некоректні id.

### Поведінка

- Bookkeeping у хуках обмежений існуючим перемикачем `QAMERAAI_AUTO_REGISTER_PRODUCTS` (за замовчуванням OFF з Фази 1). Перемикач OFF — це справжній no-op.
- Будь-який `\Throwable` від writerа ловиться у хуку і логується через `PrestaShopLogger::addLog` із severity 2 та `object_type='QameraAiModule'`. Збереження товару в БО завжди завершується успіхом з погляду оператора, незалежно від стану bookkeepingу.
- Снапшот читається у мові магазину за замовчуванням (`Configuration::get('PS_LANG_DEFAULT', null, null, $idShop)`); якщо переклад відсутній, writer падає до першого непорожнього значення і записує попередження.

### Змінено

- **Без впливу на upstream API.** Поверхня `QameraApiClient`, ендпоінти `/plugin/*` та обробник вебхуків цим релізом не зачіпаються.

### Відомі обмеження

- Створення нового товару й далі вимагає дії "Зберегти" в БО; осиротілі рядки після `Product::delete()` поки не прибираються (`actionProductDelete` з'явиться в наступному change).
- Рядки зі `status='error'` оновлюють снапшот при редагуванні, але не повторюють синхронізацію автоматично — ручний retry оператора з'явиться разом із UI у вкладці товару (Фаза 4).

## [1.0.0] — 2026-05-24

Перший випуск. Додає зберігання облікових даних, інсталяційний життєвий цикл модуля та протестований HTTP-клієнт до Qamera AI Plugin API. PrestaShop 8.0–9.x, PHP 8.1+.

### Додано

- **Сторінку конфігурації в бек-офісі** у розділі *Покращити → Qamera AI*. Зберігає базовий URL API, API-ключ, секрет вебхука, перемикач автоматичної реєстрації нових товарів та розмір пакета синхронізації. Секрети рендеряться замаскованими; надсилання форми без редагування замаскованого поля не змінює збереженого значення.
- **Інсталятор модуля** — створює дві таблиці MySQL (`qamera_product_link`, `qamera_packshot_link`), реєструє чотири хуки PrestaShop (`actionProductAdd`, `actionProductUpdate`, `displayAdminProductsExtra`, `displayBackOfficeHeader`) і висіває п'ять значень за замовчуванням конфігурації. Деінсталяція скасовує кожен крок.
- **Типізований HTTP-клієнт до Qamera AI Plugin API.** Один метод на кожен використовуваний ендпоінт (`me`, читання каталогу, реєстрація зображення та пакшоту, presigned upload, submit job, читання товарів). Автентифікація, повторні спроби, генерування idempotency-key на записах і декодування конвертів помилок вбудовані — викликаючий код ніколи не бачить сирого винятку Guzzle.
- **Кнопку перевірки з'єднання** на сторінці конфігурації. Виконує POST до окремого адмінського ендпоінта з захистом CSRF, викликає `GET /me` зі збереженими обліковими даними і відмальовує результат прямо на сторінці (назва облікового запису, баланс кредитів, тариф підписки, платформа та статус інсталяції). Форма збереження не зачіпається.
- **Польські та українські переклади** рядків бек-офісу.
- **CI-матрицю** на PHP 8.1 / 8.2 / 8.3 (PHPCS PSR-12, PHPStan рівень 5 із завантаженим ядром PrestaShop через `_PS_ROOT_DIR_`, PHPUnit).

### Відомі обмеження

- Обробники хуків — порожні заглушки; синхронізація товарів, потоки сабмітів пакшотів та обробка вебхуків з'являться у наступних фазах.
- Мультимагазин працює на одному ключі (один API-ключ на інсталяцію). Облікові дані на магазин — follow-up у v2.
- Сторінка конфігурації дає змогу редагувати секрети, але не дозволяє ротувати HMAC вебхука — це відбувається у панелі Qamera AI.

[1.2.0]: https://github.com/200iqlabs/prestashop-qamera/releases/tag/v1.2.0
[1.1.0]: https://github.com/200iqlabs/prestashop-qamera/releases/tag/v1.1.0
[1.0.0]: https://github.com/200iqlabs/prestashop-qamera/releases/tag/v1.0.0
