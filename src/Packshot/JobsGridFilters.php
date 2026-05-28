<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

/**
 * Filter / paginate inputs for `PackshotJobRepository::listForGrid`.
 * `status` of `null` means "all statuses". `idLang` drives the
 * `ps_product_lang` join so the grid surfaces the operator's locale.
 */
final class JobsGridFilters
{
    public function __construct(
        public readonly ?string $status,
        public readonly int $idLang,
        public readonly int $limit = 50,
        public readonly int $offset = 0,
    ) {
        if ($status !== null && !in_array($status, PackshotJobRow::STATUSES, true)) {
            throw new \InvalidArgumentException(
                'status must be null or one of: ' . implode(',', PackshotJobRow::STATUSES)
            );
        }
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('limit must be 1..200');
        }
        if ($offset < 0) {
            throw new \InvalidArgumentException('offset must be >= 0');
        }
        if ($idLang < 1) {
            throw new \InvalidArgumentException('id_lang must be >= 1');
        }
    }
}
