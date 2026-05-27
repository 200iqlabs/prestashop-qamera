<?php

// phpcs:ignoreFile -- This file intentionally declares PrestaShop's
// global classes (Db, Product, Configuration, PrestaShopLogger,
// PrestaShopDatabaseException, Context) without a namespace so that
// unit tests can resolve the same `use Db;` / `use Product;` imports
// that production code uses. The PSR-12 "must be in a namespace" rule
// does not apply here; CI passes the file path explicitly so the
// phpcs.xml.dist exclude-pattern alone is not enough.

declare(strict_types=1);

/**
 * Minimal PrestaShop class stubs so unit tests can be executed without
 * booting the full PS core. Loaded from tests/bootstrap.php only when
 * the real PS classes are absent — production CI matrix always picks
 * the real PS classes when available.
 */

if (!class_exists(\PrestaShopDatabaseException::class)) {
    class PrestaShopDatabaseException extends \RuntimeException
    {
    }
}

if (!class_exists(\PrestaShopException::class)) {
    class PrestaShopException extends \RuntimeException
    {
    }
}

if (!class_exists(\Db::class)) {
    abstract class Db
    {
        /** @return bool|int */
        abstract public function execute(string $sql, bool $useCache = true);

        /**
         * @return array<int, array<string, mixed>>|false
         */
        abstract public function executeS(string $sql, bool $array = true, bool $useCache = true);

        /**
         * @return array<string, mixed>|false
         */
        abstract public function getRow(string $sql, bool $useCache = true);

        public function escape(string $string, bool $htmlOk = false, bool $boolReplace = false): string
        {
            // Same semantics as PS's quoted-string escape for tests:
            // backslash + single-quote so the generated SQL stays valid.
            return str_replace(["\\", "'"], ["\\\\", "\\'"], $string);
        }
    }
}

if (!class_exists(\Product::class)) {
    class Product
    {
        public int $id = 0;

        /** @var array<int, string>|string */
        public $name = '';

        public string $reference = '';

        /** @var array<int, string>|string */
        public $description_short = '';
    }
}

if (!class_exists(\Configuration::class)) {
    class Configuration
    {
        /** @var array<string, mixed> */
        public static array $values = [];

        /**
         * @param mixed $defaultValue
         * @return mixed
         */
        public static function get(
            string $key,
            ?int $idLang = null,
            ?int $idShopGroup = null,
            ?int $idShop = null,
            $defaultValue = false
        ) {
            $compositeKey = $key . ':' . ($idShop ?? '');
            if (array_key_exists($compositeKey, self::$values)) {
                return self::$values[$compositeKey];
            }
            return self::$values[$key] ?? $defaultValue;
        }
    }
}

if (!class_exists(\PrestaShopLogger::class)) {
    class PrestaShopLogger
    {
        /** @var list<array<string, mixed>> */
        public static array $logs = [];

        public static function addLog(
            string $message,
            int $severity = 1,
            ?int $errorCode = null,
            ?string $objectType = null,
            ?int $objectId = null,
            bool $allowDuplicate = false
        ): bool {
            self::$logs[] = compact('message', 'severity', 'errorCode', 'objectType', 'objectId', 'allowDuplicate');
            return true;
        }
    }
}

if (!class_exists(\Image::class)) {
    class Image
    {
        /**
         * Per-product cover row injected by tests. Keyed by id_product →
         * either `false` (no cover) or an associative array shaped like
         * PS core: `['id_image' => int, 'cover' => 1, ...]`.
         *
         * @var array<int, array{id_image:int, cover:int}|false>
         */
        public static array $covers = [];

        /**
         * Per-product image lists injected by tests. Keyed by
         * id_product → ordered list of associative arrays each shaped
         * `['id_image' => int, 'cover' => 0|1, 'position' => int]`.
         *
         * @var array<int, list<array{id_image:int, cover:int, position:int}>>
         */
        public static array $images = [];

        /** @return array{id_image:int, cover:int}|false */
        public static function getCover(int $idProduct)
        {
            return self::$covers[$idProduct] ?? false;
        }

        /**
         * @return list<array{id_image:int, cover:int, position:int}>
         */
        public static function getImages(int $idLang, int $idProduct): array
        {
            return self::$images[$idProduct] ?? [];
        }
    }
}

if (!class_exists(\Context::class)) {
    class Context
    {
        public ?\stdClass $shop = null;

        private static ?Context $instance = null;

        public static function getContext(): self
        {
            if (self::$instance === null) {
                self::$instance = new self();
                self::$instance->shop = (object) ['id' => 1];
            }
            return self::$instance;
        }
    }
}
